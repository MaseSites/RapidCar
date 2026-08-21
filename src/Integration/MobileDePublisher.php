<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\ListingService;
use RuntimeException;

/**
 * Überträgt ein Fahrzeug als Inserat zu mobile.de (Seller-API).
 *
 * Ablauf: Bilder hochladen, Inserat anlegen oder aktualisieren, Bilder
 * zuordnen. mobile.de prüft die Pflichtfelder selbst; fehlt etwas, kommt
 * die Meldung der Börse unverändert beim Nutzer an, nichts wird geraten.
 */
final class MobileDePublisher
{
    private const MAX_IMAGES = 15;

    /**
     * @return array{ad_id: string, created: bool, image_errors: array<int, string>}
     */
    public static function push(int $dealershipId, int $vehicleId, ?int $userId = null): array
    {
        $credentials = MobileDeService::credentials($dealershipId);
        if ($credentials === null || !MobileDeService::isConnected($dealershipId)) {
            throw new RuntimeException('Es ist keine aktive mobile.de-Verbindung hinterlegt.');
        }

        $vehicle = VehicleRepository::find($vehicleId, $dealershipId);
        if ($vehicle === null) {
            throw new RuntimeException('Fahrzeug nicht gefunden.');
        }
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);

        // ------------------------------------ 1. Pflichtangaben zuerst pruefen
        //
        // Vor dem Bild-Upload: sonst liegen bei fehlenden Angaben verwaiste
        // Bilder im mobile.de-Konto, die dort niemand mehr zuordnen kann.
        $currency = (string) (Database::scalar(
            'SELECT currency FROM dealerships WHERE id = :id',
            ['id' => $dealershipId]
        ) ?? 'EUR');
        $missing = self::missingFields($vehicle, $listing, $currency);
        if ($missing !== []) {
            throw new RuntimeException(
                'Für die Übertragung zu mobile.de fehlen Angaben: ' . implode(', ', $missing) . '.'
            );
        }

        $payload = self::mapVehicle($vehicle, $listing, $vehicleId, VehicleRepository::features($vehicleId));

        $basePath = '/seller-api/sellers/' . rawurlencode($credentials['seller_id']) . '/ads';
        $existing = self::channelRow($dealershipId, (int) $listing['id']);
        $adId = $existing !== null ? (string) ($existing['external_id'] ?? '') : '';
        $created = false;

        // ------------------------------- 2. Anzeige anlegen oder aktualisieren
        //
        // Zuerst die Anzeige, dann die Bilder: die Kennung wird sofort
        // gespeichert. Frueher lief der Bild-Schritt dazwischen, und ein
        // Abbruch dort liess die Kennung verlorengehen, sodass beim naechsten
        // Versuch eine zweite Anzeige entstand.
        if ($adId !== '') {
            $result = MobileDeClient::request('PUT', $basePath . '/' . rawurlencode($adId), $credentials['username'], $credentials['password'], $payload);
            if ($result['status'] === 404) {
                $adId = ''; // auf der Boerse geloescht: neu anlegen
            } elseif ($result['status'] >= 400) {
                self::rememberError($dealershipId, (int) $listing['id'], $result);
                throw new RuntimeException(self::errorText($result));
            }
        }
        if ($adId === '') {
            $result = MobileDeClient::request('POST', $basePath, $credentials['username'], $credentials['password'], $payload);
            if ($result['status'] >= 400) {
                self::rememberError($dealershipId, (int) $listing['id'], $result);
                throw new RuntimeException(self::errorText($result));
            }
            // Die neue Kennung steht in der Location-Kopfzeile
            $adId = trim((string) basename((string) parse_url($result['location'], PHP_URL_PATH)));
            if ($adId === '') {
                $adId = (string) ($result['data']['mobileAdId'] ?? ($result['data']['id'] ?? ''));
            }
            if ($adId === '') {
                throw new RuntimeException('mobile.de hat keine Inserats-Kennung geliefert.');
            }
            $created = true;
            // Sofort sichern, noch vor den Bildern
            self::rememberSuccess($dealershipId, (int) $listing['id'], $adId);
        }

        // ---------------------------------------------------------- 3. Bilder
        $imageRefs = [];
        $imageErrors = [];
        $imagePaths = [];
        foreach (array_slice(VehicleRepository::images($vehicleId), 0, self::MAX_IMAGES) as $image) {
            $path = (string) ($image['composed_path'] ?? '') !== ''
                ? (string) $image['composed_path']
                : (string) $image['file_path'];
            $absolute = BASE_PATH . '/uploads/' . $path;
            if (!is_file($absolute)) {
                continue;
            }
            $imagePaths[] = $path;
            try {
                $imageRefs[] = MobileDeClient::uploadImage($credentials['username'], $credentials['password'], $absolute);
            } catch (\Throwable $e) {
                $imageErrors[] = basename($path) . ': ' . $e->getMessage();
            }
        }

        // Schlagen ALLE Bilder fehl, obwohl welche vorhanden sind, waere die
        // Anzeige ohne Bilder online. Das wird gemeldet statt stillschweigend
        // hingenommen.
        if ($imagePaths !== [] && $imageRefs === []) {
            self::rememberError($dealershipId, (int) $listing['id'], ['status' => 0, 'raw' => implode(' ', $imageErrors)]);
            throw new RuntimeException(
                'Die Anzeige wurde übertragen, aber kein einziges Bild: ' . implode(' ', $imageErrors)
            );
        }

        if ($imageRefs !== []) {
            $imageResult = MobileDeClient::request(
                'PUT',
                $basePath . '/' . rawurlencode($adId) . '/images',
                $credentials['username'],
                $credentials['password'],
                ['images' => array_map(static fn(string $ref): array => ['ref' => $ref], $imageRefs)]
            );
            if ($imageResult['status'] >= 400) {
                $imageErrors[] = 'Bilderzuordnung: HTTP ' . $imageResult['status'];
            }
        }

        self::rememberSuccess($dealershipId, (int) $listing['id'], $adId);
        ActivityLogger::log(
            $userId,
            'integration.mobilede_pushed',
            'Fahrzeug #' . $vehicleId . ' zu mobile.de übertragen (' . ($created ? 'neu' : 'aktualisiert') . ')',
            'vehicle',
            $vehicleId,
            $dealershipId
        );

        return ['ad_id' => $adId, 'created' => $created, 'image_errors' => $imageErrors];
    }

    /** Entfernt das Inserat von mobile.de. */
    public static function remove(int $dealershipId, int $vehicleId, ?int $userId = null): void
    {
        $credentials = MobileDeService::credentials($dealershipId);
        if ($credentials === null) {
            throw new RuntimeException('Es ist keine mobile.de-Verbindung hinterlegt.');
        }
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        $row = self::channelRow($dealershipId, (int) $listing['id']);
        $adId = $row !== null ? (string) ($row['external_id'] ?? '') : '';
        if ($adId === '') {
            return;
        }
        $result = MobileDeClient::request(
            'DELETE',
            '/seller-api/sellers/' . rawurlencode($credentials['seller_id']) . '/ads/' . rawurlencode($adId),
            $credentials['username'],
            $credentials['password']
        );
        if ($result['status'] >= 400 && $result['status'] !== 404) {
            throw new RuntimeException(self::errorText($result));
        }
        Database::update('channel_listings', (int) $row['id'], [
            'status' => 'inactive', 'external_id' => null, 'updated_at' => Database::now(),
        ]);
        ActivityLogger::log($userId, 'integration.mobilede_removed', 'Fahrzeug #' . $vehicleId . ' von mobile.de entfernt', 'vehicle', $vehicleId, $dealershipId);
    }

    /** Kennung des Inserats bei mobile.de, falls uebertragen. */
    public static function externalIdForVehicle(int $dealershipId, int $vehicleId): ?string
    {
        $listing = Database::fetch(
            'SELECT id FROM listings WHERE vehicle_id = :v AND dealership_id = :d',
            ['v' => $vehicleId, 'd' => $dealershipId]
        );
        if ($listing === null) {
            return null;
        }
        $row = self::channelRow($dealershipId, (int) $listing['id']);
        $id = $row !== null ? (string) ($row['external_id'] ?? '') : '';
        return $id !== '' ? $id : null;
    }

    /**
     * Grundfarben, die mobile.de kennt (refdata/colors). Alles andere wird
     * nur als Herstellerbezeichnung mitgegeben.
     */
    private const COLORS = [
        'schwarz' => 'BLACK', 'grau' => 'GREY', 'anthrazit' => 'GREY', 'silber' => 'SILVER',
        'weiss' => 'WHITE', 'weiß' => 'WHITE', 'beige' => 'BEIGE', 'braun' => 'BROWN',
        'rot' => 'RED', 'gruen' => 'GREEN', 'grün' => 'GREEN', 'blau' => 'BLUE',
        'violett' => 'PURPLE', 'lila' => 'PURPLE', 'gold' => 'GOLD',
        'orange' => 'ORANGE', 'gelb' => 'YELLOW',
    ];

    /** Aufbau zu den Fahrzeugkategorien aus refdata/classes/Car/categories. */
    private const CATEGORIES = [
        'limousine'  => 'Limousine',
        'kombi'      => 'EstateCar',
        'coupe'      => 'SportsCar',
        'suv'        => 'OffRoad',
        'pickup'     => 'OffRoad',
        'cabriolet'  => 'Cabrio',
        'kleinwagen' => 'SmallCar',
        'van'        => 'Van',
    ];

    /**
     * Ausstattung: mobile.de fuehrt sie als einzelne Ja/Nein-Felder. Nur
     * Zuordnungen, die in der Schnittstellenbeschreibung nachweisbar sind;
     * geraten wird nichts.
     */
    private const FEATURES = [
        'sitzheizung vorne'            => 'electricHeatedSeats',
        'sitzheizung'                  => 'electricHeatedSeats',
        'sitzheizung hinten'           => 'electricHeatedRearSeats',
        'sitzbelüftung'                => 'ventilatedSeats',
        'massagesitze'                 => 'massageSeats',
        'elektrische sitzverstellung'  => 'electricAdjustableSeats',
        'elektrisch verstellbarer sitz' => 'electricAdjustableSeats',
        'memory-sitze'                 => 'memorySeats',
        'panoramadach'                 => 'panoramicGlassRoof',
        'schiebedach'                  => 'sunroof',
        'standheizung'                 => 'auxiliaryHeating',
        'elektrische heckklappe'       => 'electricTailgate',
        'keyless go'                   => 'keylessEntry',
        'schlüsselloser zugang'        => 'keylessEntry',
        'ambientebeleuchtung'          => 'ambientLighting',
        'armlehne'                     => 'armRest',
        'multifunktionslenkrad'        => 'multifunctionalWheel',
        'lenkradheizung'               => 'heatedSteeringWheel',
        'spurhalteassistent'           => 'laneDepartureWarning',
        'spurhalte-assistent'          => 'laneDepartureWarning',
        'totwinkelassistent'           => 'blindSpotMonitor',
        'totwinkel-assistent'          => 'blindSpotMonitor',
        'notbremsassistent'            => 'collisionAvoidance',
        'bremsassistent'               => 'collisionAvoidance',
        'verkehrszeichenerkennung'     => 'trafficSignRecognition',
        'verkehrszeichenassistent'     => 'trafficSignRecognition',
        'müdigkeitserkennung'          => 'fatigueWarningSystem',
        'head-up-display'              => 'headUpDisplay',
        'nachtsichtassistent'          => 'nightVisionAssist',
        'abs'                          => 'abs',
        'esp'                          => 'esp',
        'isofix'                       => 'isofix',
        'reifendruckkontrolle'         => 'tirePressureMonitoring',
        'navigationssystem'            => 'navigationSystem',
        'apple carplay'                => 'carplay',
        'android auto'                 => 'androidAuto',
        'bluetooth'                    => 'bluetooth',
        'bluetooth-schnittstelle'      => 'bluetooth',
        'soundsystem'                  => 'soundSystem',
        'premium-soundsystem'          => 'soundSystem',
        'freisprecheinrichtung'        => 'handsFreePhoneSystem',
        'usb-anschluss'                => 'usb',
        'induktive ladeschale'         => 'wirelessCharging',
        'digitales cockpit'            => 'digitalCockpit',
        'sprachsteuerung'              => 'voiceControl',
        'wlan-hotspot'                 => 'wifiHotspot',
        'nebelscheinwerfer'            => 'frontFogLights',
        'dachreling'                   => 'roofRails',
        'gepäckträger'                 => 'roofRails',
        'leichtmetallfelgen'           => 'alloyWheels',
        'alufelgen'                    => 'alloyWheels',
        'sportfahrwerk'                => 'performanceHandlingSystem',
        'luftfederung'                 => 'airSuspension',
        'adaptives fahrwerk'           => 'dynamicChassisControl',
        'getönte scheiben'             => 'tintedWindows',
        'regensensor'                  => 'automaticRainSensor',
        'lichtsensor'                  => 'lightSensor',
        'elektrische spiegel'          => 'electricExteriorMirrors',
        'beheizbare frontscheibe'      => 'heatedWindshield',
        'winterreifen'                 => 'winterTires',
        'wärmepumpe'                   => 'heatPump',
        'elektrische fensterheber'     => 'electricWindows',
        'zentralverriegelung'          => 'centralLocking',
        'alarmanlage'                  => 'alarmSystem',
        'diebstahlsicherung'           => 'immobilizer',
        'servolenkung'                 => 'powerAssistedSteering',
        'sportsitze'                   => 'sportSeats',
        'stopp-start-system'           => 'startStopSystem',
        'start-stopp-system'           => 'startStopSystem',
        'partikelfilter'               => 'particulateFilterDiesel',
        'tempomat'                     => 'speedLimiter',
        'anhängerkupplung fix'         => 'trailerAssist',
    ];

    /**
     * Angaben, ohne die mobile.de das Inserat sicher ablehnt oder die es
     * verfaelschen wuerden. Wird vor dem Bild-Upload geprueft.
     *
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $listing
     * @return array<int, string>
     */
    private static function missingFields(array $vehicle, array $listing, string $currency): array
    {
        $missing = [];
        if (trim((string) ($vehicle['make'] ?? '')) === '') {
            $missing[] = 'Marke';
        }
        if (trim((string) ($vehicle['model'] ?? '')) === '') {
            $missing[] = 'Modell';
        }
        if ((float) ($vehicle['price'] ?? 0) <= 0) {
            $missing[] = 'Preis';
        }

        // mobile.de rechnet in Euro. Ein Franken-Betrag wuerde dort als
        // Euro-Betrag erscheinen und den Preis verfaelschen. Umgerechnet wird
        // bewusst nicht: ein erfundener Kurs waere ein falscher Preis.
        if ($currency !== '' && strtoupper($currency) !== 'EUR') {
            $missing[] = 'ein Preis in Euro (im Konto ist ' . strtoupper($currency)
                . ' eingestellt, mobile.de rechnet in Euro)';
        }
        return $missing;
    }

    /**
     * Fahrzeugdaten in das Format der Seller-API.
     *
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $listing
     * @param array<int, string>   $features
     * @return array<string, mixed>
     */
    private static function mapVehicle(array $vehicle, array $listing, int $vehicleId, array $features = []): array
    {
        $payload = [
            'vehicleClass'   => 'Car',
            'internalNumber' => 'RC-' . $vehicleId,
            // Nur USED und NEW sind zulaessig (refdata/conditions). Vorfuehr-
            // und Oldtimerfahrzeuge gelten dort als gebraucht.
            'condition'      => ((string) ($vehicle['condition_state'] ?? '')) === 'new' ? 'NEW' : 'USED',
        ];

        if ((string) ($vehicle['make'] ?? '') !== '') {
            $payload['make'] = mb_strtoupper((string) $vehicle['make']);
        }
        if ((string) ($vehicle['model'] ?? '') !== '') {
            $payload['model'] = (string) $vehicle['model'];
        }
        $description = trim(((string) ($vehicle['model'] ?? '')) . ' ' . ((string) ($vehicle['variant'] ?? '')));
        if ($description !== '') {
            $payload['modelDescription'] = mb_substr($description, 0, 80);
        }

        // ------------------------------------------------- Aufbau und Zustand
        $bodyType = (string) ($vehicle['body_type'] ?? '');
        if (isset(self::CATEGORIES[$bodyType])) {
            $payload['category'] = self::CATEGORIES[$bodyType];
        }
        if ((int) ($vehicle['mileage'] ?? 0) > 0) {
            $payload['mileage'] = (int) $vehicle['mileage'];
        }
        if ((string) ($vehicle['first_registration'] ?? '') !== '') {
            // Unser Format MM.JJJJ, mobile.de verlangt JJJJMM
            if (preg_match('/^(\d{2})\.(\d{4})$/', (string) $vehicle['first_registration'], $m)) {
                $payload['firstRegistration'] = $m[2] . $m[1];
            } elseif (preg_match('/^(\d{4})-(\d{2})$/', (string) $vehicle['first_registration'], $m)) {
                $payload['firstRegistration'] = $m[1] . $m[2];
            }
        }
        if ((int) ($vehicle['previous_owners'] ?? 0) > 0) {
            $payload['numberOfPreviousOwners'] = (int) $vehicle['previous_owners'];
        }
        if (($vehicle['accident_free'] ?? null) !== null && $vehicle['accident_free'] !== '') {
            $payload['accidentDamaged'] = ((int) $vehicle['accident_free']) !== 1;
        }
        if (((int) ($vehicle['has_warranty'] ?? 0)) === 1) {
            $payload['warranty'] = true;
        }

        // -------------------------------------------------------- Antrieb
        $powerKw = (int) ($vehicle['power_kw'] ?? 0);
        if ($powerKw <= 0 && (int) ($vehicle['power_hp'] ?? 0) > 0) {
            // mobile.de erwartet Kilowatt; aus PS umgerechnet, statt die
            // Leistung ganz wegzulassen.
            $powerKw = (int) round(((int) $vehicle['power_hp']) / 1.35962);
        }
        if ($powerKw > 0) {
            $payload['power'] = $powerKw;
        }
        foreach ([
            'displacement_ccm' => 'cubicCapacity',
            'cylinders'        => 'cylinder',
            'gears'            => 'numberOfGears',
            'seats'            => 'seats',
            'weight_empty_kg'  => 'weight',
        ] as $column => $apiField) {
            if ((int) ($vehicle[$column] ?? 0) > 0) {
                $payload[$apiField] = (int) $vehicle[$column];
            }
        }
        $doors = (int) ($vehicle['doors'] ?? 0);
        if ($doors > 0) {
            $payload['doors'] = $doors <= 3 ? 'TWO_OR_THREE' : 'FOUR_OR_FIVE';
        }

        $fuelMap = [
            'petrol' => 'PETROL', 'diesel' => 'DIESEL', 'electric' => 'ELECTRICITY',
            'hybrid' => 'HYBRID', 'plug_in_hybrid' => 'HYBRID', 'gas' => 'LPG',
            'lpg' => 'LPG', 'cng' => 'CNG',
        ];
        $fuel = strtolower((string) ($vehicle['fuel_type'] ?? ''));
        if (isset($fuelMap[$fuel])) {
            $payload['fuel'] = $fuelMap[$fuel];
        }
        if ($fuel === 'plug_in_hybrid') {
            $payload['hybridPlugin'] = true;
        }
        $gearboxMap = [
            'manual' => 'MANUAL_GEAR', 'automatic' => 'AUTOMATIC_GEAR', 'semi_automatic' => 'SEMIAUTOMATIC_GEAR',
        ];
        $gearbox = strtolower((string) ($vehicle['transmission'] ?? ''));
        if (isset($gearboxMap[$gearbox])) {
            $payload['gearbox'] = $gearboxMap[$gearbox];
        }
        $euroNorm = preg_replace('/[^0-9]/', '', (string) ($vehicle['euro_norm'] ?? ''));
        if ($euroNorm !== '' && $euroNorm !== null) {
            $payload['emissionClass'] = 'EURO' . $euroNorm;
        }

        // ---------------------------------------------------------- Farben
        $color = trim((string) ($vehicle['color'] ?? ''));
        if ($color !== '') {
            $payload['manufacturerColorName'] = mb_substr($color, 0, 60);
            $basic = self::basicColor($color);
            if ($basic !== null) {
                $payload['exteriorColor'] = $basic;
            }
        }
        $interior = self::basicColor((string) ($vehicle['interior_color'] ?? ''));
        if ($interior !== null) {
            $payload['interiorColor'] = $interior;
        }
        if (((int) ($vehicle['metallic'] ?? 0)) === 1) {
            $payload['metallic'] = true;
        }
        if ((string) ($vehicle['vin'] ?? '') !== '') {
            $payload['vin'] = (string) $vehicle['vin'];
        }

        // ------------------------------------------------------ Ausstattung
        foreach ($features as $feature) {
            $key = mb_strtolower(trim((string) $feature));
            if (isset(self::FEATURES[$key])) {
                $payload[self::FEATURES[$key]] = true;
            }
        }
        // Klimatisierung ist ein Auswahlfeld, kein Ja/Nein
        $climate = self::climatisation($features);
        if ($climate !== null) {
            $payload['climatisation'] = $climate;
        }
        foreach ($features as $feature) {
            if (mb_strtolower(trim((string) $feature)) === 'lederausstattung') {
                $payload['interiorType'] = 'LEATHER';
            }
        }
        if ($features !== []) {
            $payload['highlights'] = array_values(array_slice(
                array_map(static fn(string $f): string => mb_substr($f, 0, 40), $features),
                0,
                5
            ));
        }

        // ----------------------------------------------------------- Preis
        $price = (float) ($vehicle['price'] ?? 0);
        if ($price > 0) {
            // Die Waehrung wurde vorher geprueft: hier steht immer ein
            // Euro-Betrag.
            $payload['price'] = [
                'consumerPriceGross' => number_format($price, 2, '.', ''),
                'type'               => 'FIXED',
                'currency'           => 'EUR',
            ];
        }

        $text = trim((string) ($listing['description'] ?? ''));
        if ($text !== '') {
            $payload['description'] = mb_substr($text, 0, 6000);
        }

        return $payload;
    }

    /** Grundfarbe aus einer freien Farbbezeichnung, sonst null. */
    private static function basicColor(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        foreach (self::COLORS as $word => $code) {
            if (str_contains($value, $word)) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Klimatisierung aus der Ausstattungsliste (refdata/climatisations).
     *
     * @param array<int, string> $features
     */
    private static function climatisation(array $features): ?string
    {
        $lower = array_map(static fn(string $f): string => mb_strtolower(trim($f)), $features);
        foreach ($lower as $feature) {
            if (str_contains($feature, 'zwei-zonen') || str_contains($feature, '2-zonen')) {
                return 'AUTOMATIC_CLIMATISATION_2_ZONES';
            }
        }
        foreach ($lower as $feature) {
            if (str_contains($feature, 'klimaautomatik') || str_contains($feature, 'automatische klimaanlage')) {
                return 'AUTOMATIC_CLIMATISATION';
            }
        }
        foreach ($lower as $feature) {
            if (str_contains($feature, 'klimaanlage')) {
                return 'MANUAL_CLIMATISATION';
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private static function channelRow(int $dealershipId, int $listingId): ?array
    {
        return Database::fetch(
            'SELECT * FROM channel_listings WHERE dealership_id = :d AND listing_id = :l AND provider = :p',
            ['d' => $dealershipId, 'l' => $listingId, 'p' => MobileDeService::PROVIDER]
        );
    }

    private static function rememberSuccess(int $dealershipId, int $listingId, string $adId): void
    {
        $now = Database::now();
        $row = self::channelRow($dealershipId, $listingId);
        $data = [
            'external_id' => $adId, 'status' => 'active', 'last_error' => null,
            'synced_at' => $now, 'updated_at' => $now,
        ];
        if ($row !== null) {
            Database::update('channel_listings', (int) $row['id'], $data);
        } else {
            Database::insert('channel_listings', $data + [
                'dealership_id' => $dealershipId,
                'listing_id'    => $listingId,
                'provider'      => MobileDeService::PROVIDER,
                'created_at'    => $now,
            ]);
        }
    }

    /** @param array{status: int, data: array<string, mixed>|null, location: string} $result */
    private static function rememberError(int $dealershipId, int $listingId, array $result): void
    {
        $now = Database::now();
        $row = self::channelRow($dealershipId, $listingId);
        $data = ['status' => 'error', 'last_error' => mb_substr(self::errorText($result), 0, 1000), 'updated_at' => $now];
        if ($row !== null) {
            Database::update('channel_listings', (int) $row['id'], $data);
        } else {
            Database::insert('channel_listings', $data + [
                'dealership_id' => $dealershipId,
                'listing_id'    => $listingId,
                'provider'      => MobileDeService::PROVIDER,
                'created_at'    => $now,
            ]);
        }
    }

    /** @param array{status: int, data: array<string, mixed>|null, location: string} $result */
    private static function errorText(array $result): string
    {
        $details = [];
        foreach ((array) ($result['data']['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $message = (string) ($error['message'] ?? '');
                $field = (string) ($error['key'] ?? ($error['field'] ?? ''));
                if ($message !== '') {
                    $details[] = ($field !== '' ? $field . ': ' : '') . $message;
                }
            }
        }
        $suffix = $details !== [] ? ' ' . implode(' | ', array_slice($details, 0, 5)) : '';
        return 'mobile.de hat das Inserat abgelehnt (HTTP ' . $result['status'] . ').' . $suffix;
    }
}
