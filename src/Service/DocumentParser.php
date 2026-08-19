<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Liest Fahrzeugdaten aus dem Text eines Kaufvertrags oder Fahrzeugausweises.
 *
 * Arbeitet rein regelbasiert über Schlüsselwörter: keine KI, keine Kosten,
 * kein Datenversand. Was sich nicht eindeutig zuordnen lässt, bleibt leer;
 * es wird nichts geraten.
 *
 * Erst wenn hier zu wenig herauskommt, fragt der Aufrufer die KI, und zwar
 * nur mit dem bereits gelesenen Text statt mit dem ganzen Bild. Das kostet
 * einen Bruchteil.
 */
final class DocumentParser
{
    /**
     * Schlüsselwörter je Feld. Der erste Treffer gewinnt, deshalb stehen die
     * eindeutigsten Begriffe vorn.
     *
     * @var array<string, array<int, string>>
     */
    private const LABELS = [
        'vin'                => ['fahrgestellnummer', 'fahrgestell-nr', 'fahrgestell nr', 'fin', 'vin', 'chassisnummer', 'rahmennummer'],
        'first_registration' => ['erstzulassung', 'erste inverkehrsetzung', 'inverkehrsetzung', '1. inverkehrsetzung', 'erstinverkehrsetzung', 'erstzul'],
        'mileage'            => ['kilometerstand', 'km-stand', 'km stand', 'laufleistung', 'tachostand', 'kilometer'],
        'previous_owners'    => ['vorbesitzer', 'vorhalter', 'anzahl halter', 'halterzahl', 'anzahl der halter', 'anzahl vorbesitzer'],
        'price'              => ['kaufpreis', 'verkaufspreis', 'preis', 'gesamtpreis'],
        'make'               => ['marke', 'hersteller', 'fabrikmarke'],
        'model'              => ['modell', 'typ', 'handelsbezeichnung'],
        // Nur echte Beschriftungen, keine blossen Einheiten: sonst greift
        // 'kW' mitten in "150 kW / 204 PS" und liest die PS-Zahl als kW.
        'power_kw'           => ['leistung kw', 'leistung in kw', 'motorleistung kw', 'nennleistung'], 
        'power_hp'           => ['leistung ps', 'leistung in ps', 'pferdestaerken'], 
        'displacement_ccm'   => ['hubraum', 'zylinderinhalt'],
        'color'              => ['farbe', 'lackierung', 'aussenfarbe'],
        'year'               => ['baujahr', 'modelljahr'],
        'seats'              => ['sitzplätze', 'sitze', 'anzahl sitzplätze'],
        'doors'              => ['türen', 'anzahl türen'],
    ];

    /** Treibstoff und Getriebe erkennen wir an Begriffen im Text. */
    private const FUEL_WORDS = [
        'petrol'         => ['benzin', 'otto', 'super bleifrei', 'essence'],
        'diesel'         => ['diesel'],
        'electric'       => ['elektro', 'strom', 'bev', 'électrique'],
        'plug_in_hybrid' => ['plug-in', 'plugin', 'steckdosenhybrid'],
        'hybrid'         => ['hybrid'],
        'gas'            => ['erdgas', 'autogas', 'lpg', 'cng'],
    ];

    private const TRANSMISSION_WORDS = [
        'automatic'      => ['automat', 'automatik', 'dsg', 'tiptronic', 's tronic', 'pdk', 'wandler'],
        'semi_automatic' => ['halbautomat', 'sequenziell'],
        'manual'         => ['schaltgetriebe', 'handschalt', 'manuell', 'manuelle'],
    ];

    /**
     * Wertet den Dokumenttext aus.
     *
     * @return array{fields: array<string, array{value: mixed, confidence: int, alternatives: array<int, string>}>, note: string}
     */
    public static function parse(string $text): array
    {
        $fields = [];
        $lower = mb_strtolower($text);

        foreach (self::LABELS as $field => $labels) {
            $value = self::findByLabel($text, $lower, $labels, $field);
            if ($value === null) {
                continue;
            }
            $fields[$field] = [
                'value'        => $value,
                // Ein beschriftetes Feld im Dokument ist eine sehr sichere Quelle
                'confidence'   => 92,
                'alternatives' => [],
            ];
        }

        // Leistung steht auf Papieren fast immer als "150 kW / 204 PS".
        // Diese Schreibweise wird direkt gelesen, damit nichts vertauscht wird.
        if (preg_match('/(\d{2,4})\s*kw\s*[\/(]?\s*(\d{2,4})\s*ps/iu', $text, $power) === 1) {
            $fields['power_kw'] = ['value' => (int) $power[1], 'confidence' => 94, 'alternatives' => []];
            $fields['power_hp'] = ['value' => (int) $power[2], 'confidence' => 94, 'alternatives' => []];
        } elseif (preg_match('/(\d{2,4})\s*ps\s*[\/(]?\s*(\d{2,4})\s*kw/iu', $text, $power) === 1) {
            $fields['power_hp'] = ['value' => (int) $power[1], 'confidence' => 94, 'alternatives' => []];
            $fields['power_kw'] = ['value' => (int) $power[2], 'confidence' => 94, 'alternatives' => []];
        }

        // Treibstoff und Getriebe über Begriffe im gesamten Text
        foreach (['fuel_type' => self::FUEL_WORDS, 'transmission' => self::TRANSMISSION_WORDS] as $field => $map) {
            foreach ($map as $code => $words) {
                foreach ($words as $word) {
                    if (str_contains($lower, $word)) {
                        $fields[$field] = ['value' => $code, 'confidence' => 85, 'alternatives' => []];
                        break 2;
                    }
                }
            }
        }

        // Unplausible Kombinationen aussortieren, bevor etwas uebernommen wird
        $checked = FieldPlausibility::check($fields);
        $fields = $checked['fields'];

        $note = $fields === []
            ? 'Im Dokument wurden keine bekannten Feldbezeichnungen gefunden.'
            : count($fields) . ' Angaben direkt aus dem Dokument gelesen.';

        return ['fields' => $fields, 'note' => $note];
    }

    /**
     * Sucht den Wert hinter einer Beschriftung.
     * Berücksichtigt "Beschriftung: Wert" und "Beschriftung Wert" in derselben Zeile.
     */
    private static function findByLabel(string $text, string $lower, array $labels, string $field): mixed
    {
        foreach ($labels as $label) {
            $position = 0;
            while (($found = mb_strpos($lower, $label, $position)) !== false) {
                $position = $found + mb_strlen($label);

                // Rest der Zeile ab der Beschriftung
                $lineEnd = mb_strpos($text, "\n", $position);
                $rest = $lineEnd === false
                    ? mb_substr($text, $position)
                    : mb_substr($text, $position, $lineEnd - $position);

                $rest = ltrim($rest, " \t:.…-–—|");
                $value = self::castValue($field, $rest);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** Wandelt den gefundenen Textrest in einen sauberen Feldwert. */
    private static function castValue(string $field, string $rest): mixed
    {
        $rest = trim($rest);
        if ($rest === '') {
            return null;
        }

        return match ($field) {
            'vin' => self::matchOne($rest, '/\b([A-HJ-NPR-Z0-9]{17})\b/i'),

            'first_registration' => self::firstRegistration($rest),

            'mileage', 'displacement_ccm' => self::integerValue($rest, 1, 5000000),

            'previous_owners', 'seats', 'doors' => self::integerValue($rest, 0, 99),

            'year' => self::integerValue($rest, 1900, (int) date('Y') + 1),

            'power_kw' => self::integerValue($rest, 1, 2500),
            'power_hp' => self::integerValue($rest, 1, 3000),

            'price' => self::priceValue($rest),

            // Textfelder: erstes sinnvolles Wort bis Wortende, ohne Folgebeschriftung
            'make', 'model', 'color' => self::textValue($rest),

            default => null,
        };
    }

    private static function matchOne(string $subject, string $pattern): ?string
    {
        return preg_match($pattern, $subject, $m) === 1 ? strtoupper($m[1]) : null;
    }

    private static function firstRegistration(string $rest): ?string
    {
        // 03.2023 | 03/2023 | 15.03.2023 | 2023-03-15
        if (preg_match('/\b(\d{1,2})[.\/](\d{4})\b/', $rest, $m) === 1) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . '.' . $m[2];
        }
        if (preg_match('/\b\d{1,2}[.\/](\d{1,2})[.\/](\d{4})\b/', $rest, $m) === 1) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . '.' . $m[2];
        }
        if (preg_match('/\b(\d{4})-(\d{2})-\d{2}\b/', $rest, $m) === 1) {
            return $m[2] . '.' . $m[1];
        }
        return null;
    }

    private static function integerValue(string $rest, int $min, int $max): ?int
    {
        // Erste Zahl, Tausendertrenner erlaubt
        if (preg_match("/\b(\d{1,3}(?:['’.\s]\d{3})+|\d+)\b/u", $rest, $m) !== 1) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $m[1]) ?? '';
        if ($digits === '') {
            return null;
        }
        $value = (int) $digits;
        return $value >= $min && $value <= $max ? $value : null;
    }

    private static function priceValue(string $rest): ?float
    {
        if (preg_match("/\b(\d{1,3}(?:['’.\s]\d{3})+|\d{3,})(?:[.,](\d{2}))?\b/u", $rest, $m) !== 1) {
            return null;
        }
        $whole = preg_replace('/\D/', '', $m[1]) ?? '';
        if ($whole === '') {
            return null;
        }
        $value = (float) $whole;
        return $value >= 100 && $value <= 10000000 ? $value : null;
    }

    private static function textValue(string $rest): ?string
    {
        // Bis zur nächsten Beschriftung oder zu langen Lücke abschneiden
        $value = preg_split('/\s{2,}|[:|]/u', $rest)[0] ?? '';
        $value = trim($value);
        // Zahlenkolonnen und Rauschen aussortieren
        if ($value === '' || mb_strlen($value) > 40 || preg_match('/^[\d.,\s]+$/', $value) === 1) {
            return null;
        }
        return $value;
    }
}
