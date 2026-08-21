<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * Übersetzt ein RapidCar-Fahrzeug in das Payload-Format der
 * AutoScout24 Listing-Creation-API.
 *
 * Grundsatz: Es werden keine Identifikatoren geraten. Marke, Modell und
 * Aufzählungswerte werden über die Referenz-API aufgelöst. Was sich nicht
 * eindeutig auflösen lässt, wird weggelassen und in "unresolved" gemeldet,
 * damit der Händler es vor dem Aktivieren prüfen kann.
 */
final class AutoScoutMapper
{
    /** Codes, die die Dokumentation direkt vorgibt. */
    private const VEHICLE_TYPE_CAR = 'C';
    private const OFFER_TYPE_USED  = 'U';
    private const OFFER_TYPE_NEW   = 'N';

    /**
     * @param array<string, mixed> $vehicle  Zeile aus vehicles
     * @param array<string, mixed> $listing  Zeile aus listings
     * @param array<int, string>   $features Ausstattungsliste (Freitext)
     * @param array<int, string>   $imageIds Bereits hochgeladene AutoScout24-Bild-IDs
     *
     * @return array{payload: array<string, mixed>, unresolved: array<int, string>, missing: array<int, string>}
     */
    public static function build(
        int $dealershipId,
        array $vehicle,
        array $listing,
        array $features = [],
        array $imageIds = [],
        bool $activate = false,
        string $currency = 'CHF'
    ): array {
        $unresolved = [];
        $missing = [];
        $payload = [
            'vehicleType' => self::VEHICLE_TYPE_CAR,
            'offerType'   => self::offerType($dealershipId, $vehicle),
        ];

        // ------------------------------------------------------ Marke und Modell
        $makeName = trim((string) ($vehicle['make'] ?? ''));
        $modelName = trim((string) ($vehicle['model'] ?? ''));

        if ($makeName === '') {
            $missing[] = 'Marke';
        } else {
            $makeId = AutoScoutReferences::findMakeId($dealershipId, $makeName);
            if ($makeId !== null) {
                $payload['make'] = $makeId;
            } else {
                $unresolved[] = 'Marke "' . $makeName . '" konnte in der AutoScout24-Markenliste nicht eindeutig gefunden werden.';
            }
        }

        if ($modelName === '') {
            $missing[] = 'Modell';
        } elseif (isset($payload['make'])) {
            $modelId = AutoScoutReferences::findModelId($dealershipId, $makeName, $modelName);
            if ($modelId !== null) {
                $payload['model'] = $modelId;
            } else {
                $unresolved[] = 'Modell "' . $modelName . '" konnte bei der Marke "' . $makeName . '" nicht eindeutig zugeordnet werden.';
            }
        }

        if (!empty($vehicle['variant'])) {
            $payload['modelVersion'] = mb_substr((string) $vehicle['variant'], 0, 100);
        }

        // ------------------------------------------------------------- Eckdaten
        $firstRegistration = self::toApiMonth((string) ($vehicle['first_registration'] ?? ''), $vehicle['year'] ?? null);
        if ($firstRegistration !== null) {
            $payload['firstRegistrationDate'] = $firstRegistration;
        } else {
            $missing[] = 'Erstzulassung';
        }

        if ($vehicle['mileage'] !== null && $vehicle['mileage'] !== '') {
            $payload['mileage'] = (int) $vehicle['mileage'];
        } else {
            $missing[] = 'Kilometerstand';
        }

        // Leistung wird in kW erwartet
        $powerKw = null;
        if (!empty($vehicle['power_kw'])) {
            $powerKw = (int) $vehicle['power_kw'];
        } elseif (!empty($vehicle['power_hp'])) {
            $powerKw = (int) round((int) $vehicle['power_hp'] * 0.7355);
        }
        if ($powerKw !== null && $powerKw > 0) {
            $payload['power'] = $powerKw;
        } else {
            $missing[] = 'Leistung';
        }

        if (!empty($vehicle['displacement_ccm'])) {
            $payload['cylinderCapacity'] = (int) $vehicle['displacement_ccm'];
        }
        if (!empty($vehicle['doors'])) {
            $payload['doorCount'] = (int) $vehicle['doors'];
        }
        if (!empty($vehicle['seats'])) {
            $payload['seatCount'] = (int) $vehicle['seats'];
        }
        if (!empty($vehicle['vin'])) {
            $payload['vin'] = (string) $vehicle['vin'];
        }
        if (!empty($vehicle['color'])) {
            $payload['bodyColorName'] = mb_substr((string) $vehicle['color'], 0, 60);
        }

        // ------------------------------------------------------------- Getriebe
        // Die Dokumentation nennt "M" für Handschaltung. Alle weiteren Codes
        // werden über die Referenz-API aufgelöst, nicht geraten.
        $transmission = (string) ($vehicle['transmission'] ?? '');
        if ($transmission === 'manual') {
            $payload['transmission'] = 'M';
        } elseif ($transmission !== '') {
            $resolved = AutoScoutReferences::findReferenceId(
                $dealershipId,
                'Transmission',
                self::transmissionNames($transmission)
            );
            if ($resolved !== null) {
                $payload['transmission'] = $resolved;
            } else {
                $unresolved[] = 'Getriebeart konnte nicht auf einen AutoScout24-Wert abgebildet werden.';
            }
        }

        // -------------------------------------------------------------- Antrieb
        if (!empty($vehicle['drivetrain'])) {
            $resolved = AutoScoutReferences::findReferenceId(
                $dealershipId,
                'Drivetrain',
                self::drivetrainNames((string) $vehicle['drivetrain'])
            );
            if ($resolved !== null) {
                $payload['drivetrain'] = $resolved;
            } else {
                $unresolved[] = 'Antriebsart konnte nicht auf einen AutoScout24-Wert abgebildet werden.';
            }
        }

        // ----------------------------------------------------------- Treibstoff
        if (!empty($vehicle['fuel_type'])) {
            $resolved = AutoScoutReferences::findReferenceId(
                $dealershipId,
                'FuelType',
                self::fuelNames((string) $vehicle['fuel_type'])
            );
            if ($resolved !== null) {
                $payload['primaryFuelType'] = $resolved;
            } else {
                $unresolved[] = 'Treibstoffart konnte nicht auf einen AutoScout24-Wert abgebildet werden.';
            }
        }

        // ---------------------------------------------------------------- Preis
        if (!empty($vehicle['price'])) {
            $payload['prices'] = [
                'public' => [
                    'price'    => (float) $vehicle['price'],
                    'currency' => $currency,
                ],
            ];
        } else {
            $missing[] = 'Preis';
        }

        // ---------------------------------------------------- Text und Highlights
        $description = trim((string) ($listing['description'] ?? $vehicle['description'] ?? ''));
        if ($description !== '') {
            $payload['description'] = mb_substr($description, 0, 4000);
        } else {
            $missing[] = 'Beschreibung';
        }

        if ($features !== []) {
            // Ausstattung: jeder Eintrag wird ueber die Referenzliste in die
            // Nummer der Schnittstelle uebersetzt. Nur so erscheint sie im
            // Inserat als richtige Ausstattung und nicht bloss als Text.
            $equipmentIds = [];
            $unmatched = [];
            foreach ($features as $feature) {
                $resolved = AutoScoutReferences::findReferenceId($dealershipId, 'Equipment', self::equipmentNames($feature));
                if ($resolved !== null) {
                    $equipmentIds[(int) $resolved] = true;
                } else {
                    $unmatched[] = $feature;
                }
            }
            if ($equipmentIds !== []) {
                $payload['equipment'] = array_values(array_map('intval', array_keys($equipmentIds)));
            }
            if ($unmatched !== []) {
                $unresolved[] = count($unmatched) . ' Ausstattungspunkte kennt AutoScout24 nicht: '
                    . implode(', ', array_slice($unmatched, 0, 6))
                    . (count($unmatched) > 6 ? ' und weitere' : '');
            }

            // Zusaetzlich die ersten Punkte als Blickfang oben im Inserat
            $payload['highlights'] = array_values(array_slice(
                array_map(static fn(string $f): string => mb_substr($f, 0, 60), $features),
                0,
                5
            ));
        }

        // --------------------------------------------------------------- Bilder
        if ($imageIds !== []) {
            $payload['images'] = array_map(static fn(string $id): array => ['id' => $id], $imageIds);
        }

        // ------------------------------------------------- Aufbau und Zustand
        //
        // Achtung: Radstand, Anhaengelast, Nutzlast, Gesamtgewicht sowie
        // Laenge/Breite/Hoehe sind laut Schnittstelle fuer Personenwagen
        // NICHT erlaubt. Sie werden deshalb bewusst nicht gesendet, obwohl
        // sie im Fahrzeug stehen: sie fuehren sonst zur Ablehnung.
        $bodyType = trim((string) ($vehicle['body_type'] ?? ''));
        if ($bodyType !== '') {
            $resolved = AutoScoutReferences::findReferenceId($dealershipId, 'BodyType', self::bodyTypeNames($bodyType));
            if ($resolved !== null) {
                $payload['bodyType'] = (int) $resolved;
            } else {
                $unresolved[] = 'Aufbau konnte nicht auf einen AutoScout24-Wert abgebildet werden.';
            }
        }

        // Ganze Zahlen, die die Schnittstelle fuer Personenwagen zulaesst
        foreach ([
            'cylinders'       => 'cylinderCount',
            'gears'           => 'gearCount',
            'previous_owners' => 'previousOwnerCount',
            'co2_emission'    => 'co2Emissions',
            'weight_empty_kg' => 'emptyWeight',
        ] as $column => $apiField) {
            $value = $vehicle[$column] ?? null;
            if ($value !== null && $value !== '' && (int) $value > 0) {
                $payload[$apiField] = (int) $value;
            }
        }

        // Verbrauch: eine Kommastelle, wie die Schnittstelle es erwartet
        if (!empty($vehicle['consumption']) && (float) $vehicle['consumption'] > 0) {
            $payload['consumption'] = ['combined' => round((float) $vehicle['consumption'], 1)];
        }

        // Energieetikette und Abgasnorm ueber die Referenzliste
        $energyClass = trim((string) ($vehicle['energy_class'] ?? ''));
        if ($energyClass !== '') {
            $resolved = AutoScoutReferences::findReferenceId($dealershipId, 'EfficiencyClass', [$energyClass]);
            if ($resolved !== null) {
                $payload['efficiencyClass'] = (int) $resolved;
            }
        }
        $euroNorm = trim((string) ($vehicle['euro_norm'] ?? ''));
        if ($euroNorm !== '') {
            $resolved = AutoScoutReferences::findReferenceId($dealershipId, 'EuEmissionStandard', self::euroNormNames($euroNorm));
            if ($resolved !== null) {
                $payload['euEmissionStandard'] = (string) $resolved;
            }
        }

        // Innenfarbe: die Schnittstelle kennt nur Grundfarben als Nummer
        $interior = trim((string) ($vehicle['interior_color'] ?? ''));
        if ($interior !== '') {
            $resolved = AutoScoutReferences::findReferenceId($dealershipId, 'InteriorColor', [$interior]);
            if ($resolved !== null) {
                $payload['upholsteryColor'] = (int) $resolved;
            }
        }

        // Ja/Nein-Angaben
        if (($vehicle['metallic'] ?? null) !== null && $vehicle['metallic'] !== '') {
            $payload['isMetallic'] = ((int) $vehicle['metallic']) === 1;
        }
        if (($vehicle['is_import'] ?? null) !== null && $vehicle['is_import'] !== '') {
            $payload['isEUReimport'] = ((int) $vehicle['is_import']) === 1;
        }
        // Unfallfrei ist die Umkehrung von "hatte einen Unfall"
        if (($vehicle['accident_free'] ?? null) !== null && $vehicle['accident_free'] !== '') {
            $payload['condition'] = ['hadAccident' => ((int) $vehicle['accident_free']) !== 1];
        }

        // Letzte Fahrzeugpruefung
        $lastMfk = self::toApiMonth((string) ($vehicle['last_mfk'] ?? ''), null);
        if ($lastMfk !== null) {
            $payload['lastTechnicalServiceDate'] = $lastMfk;
        }

        // ------------------------------------------------------- Veröffentlichung
        $payload['publication'] = [
            'status'   => $activate ? 'Active' : 'Inactive',
            'channels' => [['id' => 'AS24']],
        ];

        // Eigene Referenz, damit das Inserat später wiedererkannt wird
        if (!empty($vehicle['id'])) {
            $payload['offerReferenceId'] = 'VAI-' . (int) $vehicle['id'];
        }

        return ['payload' => $payload, 'unresolved' => $unresolved, 'missing' => $missing];
    }

    /**
     * Wandelt "MM.JJJJ" in das API-Format "JJJJ-MM".
     * Fällt auf den Januar des Baujahrs zurück, wenn nur das Jahr bekannt ist.
     */
    private static function toApiMonth(string $value, mixed $year): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})[.\/-](\d{4})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }
        if (!empty($year) && (int) $year > 1900) {
            return sprintf('%04d-01', (int) $year);
        }
        return null;
    }

    /**
     * Angebotsart aus dem Fahrzeugzustand. Ohne das wuerde jeder Neuwagen
     * als Occasion inseriert.
     *
     * @param array<string, mixed> $vehicle
     */
    private static function offerType(int $dealershipId, array $vehicle): string
    {
        $state = (string) ($vehicle['condition_state'] ?? '');
        if ($state === '' || $state === 'used') {
            return self::OFFER_TYPE_USED;
        }
        if ($state === 'new') {
            return self::OFFER_TYPE_NEW;
        }

        // Vorfuehrwagen und Oldtimer haben je nach Markt eigene Codes. Sie
        // werden aufgeloest statt geraten; ist der Wert unbekannt, bleibt es
        // bei Occasion, was immer zulaessig ist.
        $names = match ($state) {
            'demo'     => ['Vorführfahrzeug', 'Demonstration', 'Vorführwagen'],
            'oldtimer' => ['Oldtimer', 'Classic', 'Youngtimer'],
            default    => [],
        };
        $resolved = AutoScoutReferences::findReferenceId($dealershipId, 'OfferType', $names);
        return $resolved !== null ? (string) $resolved : self::OFFER_TYPE_USED;
    }

    /** @return array<int, string> */
    private static function bodyTypeNames(string $value): array
    {
        return match ($value) {
            'limousine'  => ['Limousine', 'Saloon', 'Sedan'],
            'kombi'      => ['Kombi', 'Station wagon', 'Estate'],
            'coupe'      => ['Coupé', 'Coupe'],
            'suv'        => ['SUV / Geländewagen / Pickup', 'SUV', 'Geländewagen', 'Off-Road'],
            'cabriolet'  => ['Cabrio / Roadster', 'Cabriolet', 'Cabrio', 'Convertible'],
            'kleinwagen' => ['Kleinwagen', 'Small Car', 'City Car'],
            'van'        => ['Van / Kleinbus', 'Van', 'Minibus'],
            'pickup'     => ['SUV / Geländewagen / Pickup', 'Pick-up', 'Pickup'],
            default      => [],
        };
    }

    /** @return array<int, string> */
    private static function euroNormNames(string $value): array
    {
        $digits = preg_replace('/[^0-9]/', '', $value);
        $names = [$value];
        if ($digits !== '' && $digits !== null) {
            $names[] = 'Euro ' . $digits;
            $names[] = 'Euro' . $digits;
            $names[] = $digits;
        }
        return $names;
    }

    /**
     * Schreibweisen einer Ausstattung. Die Liste bleibt bewusst knapp:
     * geraten wird nichts, nur naheliegende Synonyme werden geprueft.
     *
     * @return array<int, string>
     */
    private static function equipmentNames(string $feature): array
    {
        $feature = trim($feature);
        if ($feature === '') {
            return [];
        }
        $names = [$feature];

        // Verbreitete deutsche Kurzformen und ihre AutoScout24-Schreibweise
        $synonyms = [
            'navigationssystem'      => ['Navigationssystem', 'Navi'],
            'navi'                   => ['Navigationssystem'],
            'sitzheizung'            => ['Sitzheizung'],
            'rückfahrkamera'         => ['Rückfahrkamera', 'Kamera'],
            'klimaautomatik'         => ['Klimaautomatik', 'Klimaanlage'],
            'automatische klimaanlage' => ['Klimaautomatik'],
            'manuelle klimaanlage'   => ['Klimaanlage'],
            'anhängerkupplung fix'   => ['Anhängerkupplung'],
            'anhängerkupplung abnehmbar' => ['Anhängerkupplung'],
            'anhängerkupplung schwenkbar' => ['Anhängerkupplung'],
            'alufelgen'              => ['Leichtmetallfelgen', 'Alufelgen'],
            'led-scheinwerfer'       => ['LED-Scheinwerfer', 'LED-Tagfahrlicht'],
            'xenon-scheinwerfer'     => ['Xenonscheinwerfer', 'Xenon-Scheinwerfer'],
            'panoramadach'           => ['Panorama-Dach', 'Panoramadach'],
            'schiebedach'            => ['Schiebedach'],
            'lederausstattung'       => ['Lederausstattung'],
            'leder-sitze'            => ['Lederausstattung'],
            'stoff-sitze'            => ['Stoffausstattung'],
            'teilleder-sitze'        => ['Teilledersitze'],
            'tempomat'               => ['Tempomat'],
            'adaptiver tempomat'     => ['Abstandstempomat', 'Adaptive Cruise Control'],
            'einparkhilfe'           => ['Einparkhilfe'],
            'park-sensoren hinten'   => ['Einparkhilfe hinten', 'Einparkhilfe'],
            'park-sensoren vorne'    => ['Einparkhilfe vorne', 'Einparkhilfe'],
            'freisprecheinrichtung'  => ['Freisprecheinrichtung'],
            'apple carplay'          => ['Apple CarPlay'],
            'android auto'           => ['Android Auto'],
            'head-up-display'        => ['Head-up Display', 'Head-up-Display'],
            'standheizung'           => ['Standheizung'],
            'sportsitze'             => ['Sportsitze'],
            'sportfahrwerk'          => ['Sportfahrwerk'],
            'totwinkel-assistent'    => ['Totwinkel-Assistent'],
            'spurhalte-assistent'    => ['Spurhalteassistent'],
            'schlüsselloser zugang'  => ['Keyless Entry', 'Schlüssellose Zentralverriegelung'],
            'zentralverriegelung'    => ['Zentralverriegelung'],
            'elektrische fensterheber' => ['Elektrische Fensterheber'],
            'isofix'                 => ['Isofix'],
            'dab-radio'              => ['DAB-Radio', 'Digitalradio'],
            'bluetooth-schnittstelle' => ['Bluetooth'],
            'alarmanlage'            => ['Alarmanlage'],
            'nebelscheinwerfer'      => ['Nebelscheinwerfer'],
            'servolenkung'           => ['Servolenkung'],
            'start-stopp-system'     => ['Start-/Stopp-Automatik'],
            'stopp-start-system'     => ['Start-/Stopp-Automatik'],
        ];
        $key = mb_strtolower($feature);
        foreach ($synonyms[$key] ?? [] as $alias) {
            $names[] = $alias;
        }
        return $names;
    }

    /** @return array<int, string> */
    private static function transmissionNames(string $value): array
    {
        return match ($value) {
            'automatic'      => ['Automatik', 'Automatic', 'Automatikgetriebe'],
            'semi_automatic' => ['Halbautomatik', 'Semi-automatic', 'Semiautomatik'],
            'manual'         => ['Schaltgetriebe', 'Manual', 'Handschaltung'],
            default          => [],
        };
    }

    /** @return array<int, string> */
    private static function drivetrainNames(string $value): array
    {
        return match ($value) {
            'fwd'   => ['Vorderradantrieb', 'Front wheel drive', 'Front'],
            'rwd'   => ['Hinterradantrieb', 'Rear wheel drive', 'Rear'],
            'awd'   => ['Allradantrieb', 'All wheel drive', '4x4', 'Allrad'],
            default => [],
        };
    }

    /** @return array<int, string> */
    private static function fuelNames(string $value): array
    {
        return match ($value) {
            'petrol'         => ['Benzin', 'Petrol', 'Gasoline'],
            'diesel'         => ['Diesel'],
            'electric'       => ['Elektro', 'Electric', 'Elektrisch'],
            'hybrid'         => ['Hybrid', 'Hybrid (Benzin/Elektro)'],
            'plug_in_hybrid' => ['Plug-in-Hybrid', 'Plug-in Hybrid'],
            'gas'            => ['Gas', 'Autogas', 'LPG', 'Erdgas'],
            default          => [],
        };
    }
}
