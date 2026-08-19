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
            'offerType'   => self::OFFER_TYPE_USED,
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
            // Freitext-Ausstattung als Highlights: die numerischen equipment-IDs
            // werden bewusst nicht geraten.
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
