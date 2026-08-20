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

        $payload = self::mapVehicle($vehicle, $listing, $vehicleId);

        // ---------------------------------------------------------- Bilder
        $imageRefs = [];
        $imageErrors = [];
        foreach (array_slice(VehicleRepository::images($vehicleId), 0, self::MAX_IMAGES) as $image) {
            $path = (string) ($image['composed_path'] ?? '') !== ''
                ? (string) $image['composed_path']
                : (string) $image['file_path'];
            $absolute = BASE_PATH . '/uploads/' . $path;
            if (!is_file($absolute)) {
                continue;
            }
            try {
                $imageRefs[] = MobileDeClient::uploadImage($credentials['username'], $credentials['password'], $absolute);
            } catch (\Throwable $e) {
                $imageErrors[] = basename($path) . ': ' . $e->getMessage();
            }
        }

        // --------------------------------------------- Anlegen/Aktualisieren
        $existing = self::channelRow($dealershipId, (int) $listing['id']);
        $adId = $existing !== null ? (string) ($existing['external_id'] ?? '') : '';
        $basePath = '/seller-api/sellers/' . rawurlencode($credentials['seller_id']) . '/ads';
        $created = false;

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
     * Fahrzeugdaten in das Format der Seller-API. Nur was vorhanden ist:
     * mobile.de prueft die Pflichtfelder und meldet Fehlendes selbst.
     *
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $listing
     * @return array<string, mixed>
     */
    private static function mapVehicle(array $vehicle, array $listing, int $vehicleId): array
    {
        $payload = [
            'vehicleClass'   => 'Car',
            'internalNumber' => 'RC-' . $vehicleId,
            'condition'      => 'USED',
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
        if ((int) ($vehicle['power_kw'] ?? 0) > 0) {
            $payload['power'] = (int) $vehicle['power_kw'];
        }
        $fuelMap = [
            'petrol' => 'PETROL', 'diesel' => 'DIESEL', 'electric' => 'ELECTRICITY',
            'hybrid' => 'HYBRID', 'lpg' => 'LPG', 'cng' => 'CNG',
        ];
        $fuel = strtolower((string) ($vehicle['fuel_type'] ?? ''));
        if (isset($fuelMap[$fuel])) {
            $payload['fuel'] = $fuelMap[$fuel];
        }
        $gearboxMap = [
            'manual' => 'MANUAL_GEAR', 'automatic' => 'AUTOMATIC_GEAR', 'semi_automatic' => 'SEMIAUTOMATIC_GEAR',
        ];
        $gearbox = strtolower((string) ($vehicle['transmission'] ?? ''));
        if (isset($gearboxMap[$gearbox])) {
            $payload['gearbox'] = $gearboxMap[$gearbox];
        }
        if ((string) ($vehicle['vin'] ?? '') !== '') {
            $payload['vin'] = (string) $vehicle['vin'];
        }

        $price = (float) ($vehicle['price'] ?? 0);
        if ($price > 0) {
            // mobile.de rechnet in Euro. Ein CHF-Betrag wuerde dort als
            // Euro-Betrag erscheinen und den Preis verfaelschen.
            $payload['price'] = [
                'consumerPriceGross' => number_format($price, 2, '.', ''),
                'type'               => 'FIXED',
                'currency'           => 'EUR',
            ];
        }

        $text = trim((string) ($listing['description'] ?? ''));
        if ($text !== '') {
            // Die Boerse erlaubt einfachen Text; Zeilenumbrueche bleiben.
            $payload['description'] = mb_substr($text, 0, 6000);
        }

        return $payload;
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
