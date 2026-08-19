<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Prüft erkannte Fahrzeugdaten auf Plausibilität, bevor sie ins Inserat gehen.
 *
 * Beim Ablesen von Fotos und Dokumenten passieren typische Verwechslungen:
 * die kW-Zahl landet bei den PS, der Hubraum beim Baujahr, ein Kilometerstand
 * bekommt eine Stelle zu viel. Diese Prüfung fängt genau solche Fälle ab.
 *
 * Grundsatz: Im Zweifel lieber leer lassen als falsch übernehmen. Was
 * entfernt oder korrigiert wurde, wird als Hinweis zurückgegeben.
 */
final class FieldPlausibility
{
    /** Umrechnung kW zu PS. */
    private const HP_PER_KW = 1.35962;

    /** Grenzen, ausserhalb derer ein Wert nicht aus einem Fahrzeugpapier stammt. */
    private const LIMITS = [
        'power_hp'         => [15, 2000],
        'power_kw'         => [10, 1500],
        'displacement_ccm' => [400, 9000],
        'mileage'          => [0, 2000000],
        'doors'            => [2, 7],
        'seats'            => [1, 9],
        'previous_owners'  => [0, 30],
    ];

    /**
     * @param array<string, array{value: mixed, confidence: int|null, alternatives: array<int, string>}> $fields
     * @return array{fields: array<string, array<string, mixed>>, notes: array<int, string>}
     */
    public static function check(array $fields): array
    {
        $notes = [];
        $value = static fn(string $key) => $fields[$key]['value'] ?? null;
        $drop = static function (string $key, string $why) use (&$fields, &$notes): void {
            if (isset($fields[$key])) {
                unset($fields[$key]);
                $notes[] = $why;
            }
        };

        // ------------------------------------------------- harte Wertgrenzen
        foreach (self::LIMITS as $field => [$min, $max]) {
            $current = $value($field);
            if ($current === null || !is_numeric($current)) {
                continue;
            }
            if ((float) $current < $min || (float) $current > $max) {
                $drop($field, sprintf('%s (%s) liegt ausserhalb des möglichen Bereichs und wurde weggelassen.', $field, (string) $current));
            }
        }

        // ------------------------------------------------------ Baujahr
        $maxYear = (int) date('Y') + 1;
        $year = $value('year');
        if ($year !== null && ((int) $year < 1950 || (int) $year > $maxYear)) {
            $drop('year', 'Das Baujahr war unplausibel und wurde weggelassen.');
            $year = null;
        }

        // Klassiker: Der Hubraum wird als Baujahr gelesen (1984 ccm, 1984)
        $ccm = $value('displacement_ccm');
        if ($year !== null && $ccm !== null && (int) $year === (int) $ccm) {
            $drop('year', 'Baujahr und Hubraum waren identisch, das Baujahr stammte vermutlich aus der Hubraumangabe.');
            $year = null;
        }

        // Erstzulassung schlägt das Baujahr: sie steht so im Fahrzeugausweis
        $registration = $value('first_registration');
        if (is_string($registration) && preg_match('/(\d{4})$/', $registration, $m) === 1) {
            $registrationYear = (int) $m[1];
            if ($registrationYear >= 1950 && $registrationYear <= $maxYear) {
                if ($year === null) {
                    // Ohne Baujahr ist das Jahr der Erstzulassung die beste Angabe
                    $fields['year'] = ['value' => $registrationYear, 'confidence' => 80, 'alternatives' => []];
                } elseif (abs((int) $year - $registrationYear) > 2) {
                    $fields['year']['value'] = $registrationYear;
                    $notes[] = 'Das Baujahr passte nicht zur Erstzulassung und wurde daraus übernommen.';
                }
            } else {
                $drop('first_registration', 'Die Erstzulassung war unplausibel und wurde weggelassen.');
            }
        }

        // ------------------------------------------------------ Leistung
        $hp = $value('power_hp');
        $kw = $value('power_kw');
        if ($hp !== null && $kw !== null) {
            $hp = (int) $hp;
            $kw = (int) $kw;
            if ($hp < $kw) {
                // PS sind immer die groessere Zahl: die Werte sind vertauscht
                $fields['power_hp']['value'] = $kw;
                $fields['power_kw']['value'] = $hp;
                $notes[] = 'PS und kW waren vertauscht und wurden getauscht.';
                [$hp, $kw] = [$kw, $hp];
            }
            $expected = $kw * self::HP_PER_KW;
            if (abs($hp - $expected) > $expected * 0.12) {
                // kW steht im Fahrzeugausweis, die PS werden daraus gerechnet
                $fields['power_hp']['value'] = (int) round($expected);
                $notes[] = 'Die PS-Angabe passte nicht zur kW-Angabe und wurde daraus berechnet.';
            }
        }

        // --------------------------------------------------- Kilometerstand
        $mileage = $value('mileage');
        $yearForCheck = $fields['year']['value'] ?? null;
        if ($mileage !== null && $yearForCheck !== null) {
            $age = max(1, ((int) date('Y')) - (int) $yearForCheck);
            if ((int) $mileage / $age > 80000) {
                $drop('mileage', 'Der Kilometerstand war für das Alter des Fahrzeugs unplausibel hoch und wurde weggelassen.');
            }
        }

        // ------------------------------------------------------------- VIN
        $vin = $value('vin');
        if (is_string($vin) && $vin !== '') {
            $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vin) ?? '');
            // 17 Stellen, ohne I, O und Q: so ist die Fahrgestellnummer genormt
            if (strlen($clean) !== 17 || preg_match('/[IOQ]/', $clean) === 1) {
                $drop('vin', 'Die Fahrgestellnummer war nicht eindeutig lesbar und wurde weggelassen.');
            } else {
                $fields['vin']['value'] = $clean;
            }
        }

        return ['fields' => $fields, 'notes' => $notes];
    }
}
