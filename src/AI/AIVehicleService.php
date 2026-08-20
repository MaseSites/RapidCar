<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Database;

/**
 * KI-Fahrzeugerkennung (§26/§28/§30).
 *
 * Mock-Modus: liefert plausible Beispieldaten aus einem Fahrzeug-Pool
 * (zufällige Auswahl mit stimmigen technischen Daten). Das Ergebnis ist
 * klar als Demo-Modus gekennzeichnet; vorhandene Feldwerte werden nie
 * überschrieben, jeder übernommene Wert erhält einen Feldstatus (§30).
 *
 * Live-Modus: Der Provider analysiert die Bilder und liefert dieselbe
 * Ergebnisstruktur mit echten Werten und Konfidenzen.
 */
final class AIVehicleService
{
    /** Ab dieser Sicherheit gilt ein Feld als eindeutig erkannt. */
    public const CERTAIN_THRESHOLD = 80;

    /**
     * Fahrzeug-Pool für den Demo-Modus (§28): in sich stimmige Datensätze.
     * price_new = Neupreisbasis für die plausible Preisableitung.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEMO_POOL = [
        ['make' => 'VW', 'model' => 'Golf', 'variant' => 'GTI', 'power_hp' => 245, 'power_kw' => 180, 'displacement_ccm' => 1984, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'fwd', 'doors' => 5, 'seats' => 5, 'price_new' => 48000, 'colors' => ['Pure White', 'Kings Red Metallic', 'Deep Black Perleffekt']],
        ['make' => 'BMW', 'model' => 'M4', 'variant' => 'Competition', 'power_hp' => 510, 'power_kw' => 375, 'displacement_ccm' => 2993, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'rwd', 'doors' => 2, 'seats' => 4, 'price_new' => 118000, 'colors' => ['Portimao Blau', 'Saphirschwarz', 'Alpinweiss']],
        ['make' => 'Audi', 'model' => 'RS6', 'variant' => 'Avant', 'power_hp' => 600, 'power_kw' => 441, 'displacement_ccm' => 3996, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'awd', 'doors' => 5, 'seats' => 5, 'price_new' => 155000, 'colors' => ['Nardograu', 'Mythosschwarz', 'Daytonagrau']],
        ['make' => 'Porsche', 'model' => '911', 'variant' => 'Carrera S', 'power_hp' => 450, 'power_kw' => 331, 'displacement_ccm' => 2981, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'rwd', 'doors' => 2, 'seats' => 4, 'price_new' => 165000, 'colors' => ['GT-Silber', 'Carmineweiss', 'Tiefschwarz']],
        ['make' => 'Mercedes-Benz', 'model' => 'C 63', 'variant' => 'AMG', 'power_hp' => 476, 'power_kw' => 350, 'displacement_ccm' => 3982, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'rwd', 'doors' => 4, 'seats' => 5, 'price_new' => 110000, 'colors' => ['Obsidianschwarz', 'Designo Selenitgrau', 'Brillantblau']],
        ['make' => 'Tesla', 'model' => 'Model 3', 'variant' => 'Long Range', 'power_hp' => 498, 'power_kw' => 366, 'displacement_ccm' => null, 'fuel_type' => 'electric', 'transmission' => 'automatic', 'drivetrain' => 'awd', 'doors' => 4, 'seats' => 5, 'price_new' => 55000, 'colors' => ['Pearl White', 'Midnight Silver', 'Deep Blue Metallic']],
        ['make' => 'Skoda', 'model' => 'Octavia', 'variant' => 'RS', 'power_hp' => 245, 'power_kw' => 180, 'displacement_ccm' => 1984, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'fwd', 'doors' => 5, 'seats' => 5, 'price_new' => 46000, 'colors' => ['Race-Blau', 'Candy-Weiss', 'Magic-Schwarz']],
        ['make' => 'Mini', 'model' => 'Cooper S', 'variant' => 'Countryman', 'power_hp' => 178, 'power_kw' => 131, 'displacement_ccm' => 1998, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'drivetrain' => 'fwd', 'doors' => 5, 'seats' => 5, 'price_new' => 42000, 'colors' => ['British Racing Green', 'Chili Red', 'Midnight Black']],
        ['make' => 'Toyota', 'model' => 'RAV4', 'variant' => 'Hybrid Style', 'power_hp' => 222, 'power_kw' => 163, 'displacement_ccm' => 2487, 'fuel_type' => 'hybrid', 'transmission' => 'automatic', 'drivetrain' => 'awd', 'doors' => 5, 'seats' => 5, 'price_new' => 52000, 'colors' => ['Platinum White', 'Attitude Black', 'Silver Metallic']],
        ['make' => 'Ford', 'model' => 'Mustang', 'variant' => 'GT Fastback', 'power_hp' => 450, 'power_kw' => 331, 'displacement_ccm' => 5038, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'drivetrain' => 'rwd', 'doors' => 2, 'seats' => 4, 'price_new' => 68000, 'colors' => ['Race Red', 'Shadow Black', 'Grabber Blue']],
    ];

    /**
     * Erzeugt einen plausiblen Zufalls-Datensatz aus dem Pool:
     * Baujahr aus den letzten 6 Jahren, Kilometerstand und Preis passend zum Alter.
     *
     * @return array<string, mixed>
     */
    private static function randomDemoVehicle(): array
    {
        $base = self::DEMO_POOL[random_int(0, count(self::DEMO_POOL) - 1)];

        $currentYear = (int) date('Y');
        $age = random_int(0, 5);
        $year = $currentYear - $age;
        $month = random_int(1, $age === 0 ? max(1, (int) date('n')) : 12);

        // Laufleistung: 8'000 bis 18'000 km pro Jahr, auf 100er gerundet
        $kmPerYear = random_int(8000, 18000);
        $mileage = (int) (round(max(1500, $age * $kmPerYear + random_int(0, 4000)) / 100) * 100);

        // Preis: jährlicher Wertverlust 9 bis 13%, auf 100er gerundet
        $price = (float) $base['price_new'];
        for ($i = 0; $i < $age; $i++) {
            $price *= 1 - random_int(9, 13) / 100;
        }
        $price = (int) (round($price / 100) * 100);

        $fields = $base;
        unset($fields['price_new'], $fields['colors']);
        $fields['year'] = $year;
        $fields['first_registration'] = sprintf('%02d.%d', $month, $year);
        $fields['mileage'] = $mileage;
        $fields['price'] = $price;
        $fields['color'] = $base['colors'][random_int(0, count($base['colors']) - 1)];

        return array_filter($fields, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Analysiert die Bilder eines Fahrzeugs und gibt einen Erkennungsvorschlag zurück.
     *
     * @return array{
     *   mode: string,
     *   detected: bool,
     *   label: ?string,
     *   confidence: ?int,
     *   fields: array<string, mixed>,
     *   note: string
     * }
     */
    public static function detectFromImages(int $vehicleId): array
    {
        $imageCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM vehicle_images WHERE vehicle_id = :vid',
            ['vid' => $vehicleId]
        );

        if (AIService::isMock()) {
            if ($imageCount === 0) {
                return [
                    'mode'       => 'mock',
                    'detected'   => false,
                    'label'      => null,
                    'confidence' => null,
                    'fields'     => [],
                    'note'       => 'Keine Bilder vorhanden. Bitte zuerst Fotos hochladen.',
                ];
            }
            $demo = self::randomDemoVehicle();

            // Gleiches Format wie im Live-Modus, damit die Oberfläche nicht
            // unterscheiden muss: Wert, Sicherheit und mögliche Alternativen.
            $fields = [];
            foreach ($demo as $field => $value) {
                $fields[$field] = [
                    'value'        => $value,
                    'confidence'   => random_int(70, 97),
                    'alternatives' => self::demoAlternatives($field, $demo),
                ];
            }

            return [
                'mode'       => 'mock',
                'detected'   => true,
                'label'      => trim($demo['make'] . ' ' . $demo['model'] . ' ' . ($demo['variant'] ?? '')),
                'confidence' => random_int(82, 97),
                'fields'     => $fields,
                'note'       => 'KI-Modul derzeit im Demo-Modus: zufällige Beispieldaten, keine echte Erkennung. '
                    . 'Es werden nur leere Felder gefüllt, jeder Wert bleibt überschreibbar.',
            ];
        }

        // Live-Modus: alle Bilder an den Anbieter geben
        if ($imageCount === 0) {
            throw new AIException('Keine Bilder zur Analyse vorhanden.');
        }

        $images = Database::fetchAll(
            'SELECT file_path, card_path FROM vehicle_images
             WHERE vehicle_id = :vid ORDER BY is_main DESC, sort_order',
            ['vid' => $vehicleId]
        );
        $paths = [];
        foreach ($images as $image) {
            // Mittlere Grösse bevorzugen: reicht zur Erkennung und spart Übertragung
            $relative = (string) ($image['card_path'] ?? $image['file_path']);
            $absolute = BASE_PATH . '/uploads/' . $relative;
            if (is_file($absolute)) {
                $paths[] = $absolute;
            }
        }

        $result = AIService::provider()->detectVehicle($paths);

        return [
            'mode'       => $result['mode'],
            'detected'   => $result['detected'],
            'label'      => $result['label'],
            'confidence' => $result['confidence'],
            'fields'     => $result['fields'],
            'note'       => $result['note'],
        ];
    }

    /**
     * Plausible Alternativen für den Demo-Modus, damit die Auswahlliste auch
     * ohne KI-Anbindung sichtbar ist.
     *
     * @param array<string, mixed> $demo
     * @return array<int, string>
     */
    private static function demoAlternatives(string $field, array $demo): array
    {
        $model = (string) ($demo['model'] ?? '');

        return match ($field) {
            'model' => match ($model) {
                'M4'        => ['M3', 'M2'],
                'Golf'      => ['Polo', 'T-Roc'],
                '911'       => ['718 Cayman', '718 Boxster'],
                'RS6'       => ['RS7', 'S6'],
                'C 63'      => ['C 43', 'E 63'],
                'Model 3'   => ['Model Y'],
                'Octavia'   => ['Superb'],
                'Cooper S'  => ['Cooper', 'John Cooper Works'],
                'RAV4'      => ['C-HR', 'Highlander'],
                'Mustang'   => ['Mustang Mach-E'],
                default     => [],
            },
            'color' => ['Schwarz Metallic', 'Weiss', 'Grau Metallic'],
            default => [],
        };
    }

    /**
     * Übernimmt Erkennungsdaten in LEERE Fahrzeugfelder und setzt den
     * Feldstatus (§30). Vorhandene Werte werden nie überschrieben.
     *
     * @param array<string, mixed> $fields
     * @return array<int, string> Übernommene Feldnamen
     */
    public static function applyToEmptyFields(int $vehicleId, array $fields, string $status = 'uncertain', ?int $confidence = null): array
    {
        $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id = :id', ['id' => $vehicleId]);
        if ($vehicle === null) {
            return [];
        }

        // Alle Felder, die die Erkennung liefert. Frueher stand hier eine
        // Handliste der ersten 18 Felder: alles Neuere wurde still verworfen.
        $allowed = OpenAiProvider::FIELDS;

        // Ja/Nein-Angaben kommen als Text und landen als 1/0 in der Datenbank.
        $booleanFields = array_keys(array_filter(
            OpenAiProvider::ENUM_FIELDS,
            static fn(array $values): bool => $values === ['ja', 'nein']
        ));

        $applied = [];
        $update = [];
        $perField = [];

        foreach ($fields as $field => $entry) {
            if (!in_array($field, $allowed, true)) {
                continue;
            }

            // Zwei Formate: einfacher Wert (Demo) oder Objekt mit Sicherheit
            // und Alternativen (Live-Erkennung).
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $value = $entry['value'];
                $fieldConfidence = isset($entry['confidence']) ? (int) $entry['confidence'] : $confidence;
                $alternatives = is_array($entry['alternatives'] ?? null) ? array_values($entry['alternatives']) : [];
            } else {
                $value = $entry;
                $fieldConfidence = $confidence;
                $alternatives = [];
            }

            if ($value === null || $value === '') {
                continue;
            }

            $isBoolean = in_array($field, $booleanFields, true);
            if ($isBoolean) {
                $value = strtolower(trim((string) $value)) === 'ja' ? 1 : 0;
            }

            $current = $vehicle[$field] ?? null;
            // Bei Ja/Nein-Feldern ist 0 eine Antwort (Nein), kein leeres Feld.
            $isEmpty = $isBoolean
                ? ($current === null || $current === '')
                : ($current === null || $current === '' || $current === 0);
            if ($isEmpty) {
                $update[$field] = $value;
                $applied[] = $field;
                $perField[$field] = ['confidence' => $fieldConfidence, 'alternatives' => $alternatives];
            }
        }

        // PS und kW gehören zusammen: kommt nur eine Angabe aus dem
        // Dokument, wird die andere berechnet statt leer zu bleiben.
        if (isset($update['power_hp']) && !isset($update['power_kw']) && empty($vehicle['power_kw'])) {
            $update['power_kw'] = (int) round((int) $update['power_hp'] / 1.35962);
            $applied[] = 'power_kw';
        } elseif (isset($update['power_kw']) && !isset($update['power_hp']) && empty($vehicle['power_hp'])) {
            $update['power_hp'] = (int) round((int) $update['power_kw'] * 1.35962);
            $applied[] = 'power_hp';
        }

        if ($update !== []) {
            $update['updated_at'] = Database::now();
            Database::update('vehicles', $vehicleId, $update);

            foreach ($applied as $field) {
                // Berechnete Felder (etwa das Gegenstück zu PS oder kW) haben
                // keinen eigenen Eintrag; sie erben die Sicherheit des Feldes.
                $fieldConfidence = $perField[$field]['confidence'] ?? $confidence;
                $alternatives = $perField[$field]['alternatives'] ?? [];

                // Unsicher, wenn die KI selbst unsicher ist oder Alternativen nennt
                $fieldStatus = $status;
                if ($status !== 'manual') {
                    $fieldStatus = ($alternatives !== [] || ($fieldConfidence !== null && $fieldConfidence < self::CERTAIN_THRESHOLD))
                        ? 'uncertain'
                        : 'detected';
                }

                self::setFieldStatus($vehicleId, $field, $fieldStatus, $fieldConfidence, $alternatives);
            }
        }
        return $applied;
    }

    /**
     * Feldstatus setzen (§30): detected | uncertain | manual.
     *
     * @param array<int, string> $alternatives Mögliche Werte bei Unsicherheit
     */
    public static function setFieldStatus(
        int $vehicleId,
        string $field,
        string $status,
        ?int $confidence = null,
        array $alternatives = []
    ): void {
        $now = Database::now();
        $encoded = $alternatives !== [] ? json_encode(array_values($alternatives), JSON_UNESCAPED_UNICODE) : null;

        $existing = Database::fetch(
            'SELECT id FROM vehicle_field_status WHERE vehicle_id = :vid AND field_name = :f',
            ['vid' => $vehicleId, 'f' => $field]
        );
        $data = [
            'status'       => $status,
            'confidence'   => $confidence,
            'alternatives' => $encoded,
            'updated_at'   => $now,
        ];

        if ($existing !== null) {
            Database::update('vehicle_field_status', (int) $existing['id'], $data);
        } else {
            Database::insert('vehicle_field_status', $data + [
                'vehicle_id' => $vehicleId,
                'field_name' => $field,
            ]);
        }
    }

    /** @return array<string, array{status: string, confidence: ?int, alternatives: array<int, string>}> */
    public static function fieldStatuses(int $vehicleId): array
    {
        $rows = Database::fetchAll(
            'SELECT field_name, status, confidence, alternatives FROM vehicle_field_status WHERE vehicle_id = :vid',
            ['vid' => $vehicleId]
        );
        $map = [];
        foreach ($rows as $row) {
            $alternatives = [];
            if (!empty($row['alternatives'])) {
                $decoded = json_decode((string) $row['alternatives'], true);
                if (is_array($decoded)) {
                    $alternatives = array_values(array_filter(array_map('strval', $decoded)));
                }
            }
            $map[(string) $row['field_name']] = [
                'status'       => (string) $row['status'],
                'confidence'   => $row['confidence'] !== null ? (int) $row['confidence'] : null,
                'alternatives' => $alternatives,
            ];
        }
        return $map;
    }
}
