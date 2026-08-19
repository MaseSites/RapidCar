<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

/**
 * Schreibstil der Inseratstexte, einstellbar je Autohaus.
 *
 * Der gewählte Ton und ein optionaler Beispieltext fliessen in die
 * Texterzeugung ein. Der Beispieltext ist dabei das stärkere Signal: Er zeigt
 * dem Modell Satzbau, Länge und Ansprache des Hauses.
 */
final class ListingStyle
{
    /** @var array<string, array{label: string, instruction: string}> */
    public const TONES = [
        'sales' => [
            'label'       => 'Verkaufsstark',
            'min_chars'   => 650,
            'instruction' => 'Schreibe verkaufsstark und begeisternd, aber sachlich korrekt. '
                . 'Stelle die stärksten Argumente an den Anfang und sprich den Leser direkt an.',
        ],
        'factual' => [
            'label'       => 'Sachlich',
            'min_chars'   => 650,
            'instruction' => 'Schreibe nüchtern und sachlich. Fakten in klarer Reihenfolge, '
                . 'keine Werbesprache, keine Ausrufezeichen.',
        ],
        'premium' => [
            'label'       => 'Gehoben',
            'min_chars'   => 650,
            'instruction' => 'Schreibe gehoben und zurückhaltend elegant, wie für ein Premiumhaus. '
                . 'Ruhige, längere Sätze, hochwertige Wortwahl, keine Superlative.',
        ],
        'short' => [
            'label'       => 'Kurz und knapp',
            'min_chars'   => 320,
            'instruction' => 'Fasse dich sehr kurz. Wenige, kurze Sätze und eine knappe '
                . 'Aufzählung der wichtigsten Merkmale.',
        ],
    ];

    public const DEFAULT_TONE = 'sales';

    /**
     * Titelstil. Der Titel ist die Überschrift des Inserats und wird in
     * Trefferlisten oft abgeschnitten, darum bleibt er kurz.
     *
     * @var array<string, array{label: string, instruction: string, extras: int}>
     */
    public const TITLE_STYLES = [
        'compact' => [
            'label'  => 'Kurz mit Bestwerten',
            'extras' => 2,
            'instruction' => 'Beginne mit Marke, Modell und Variante. Hänge danach höchstens '
                . 'zwei besonders starke Angaben an, jeweils mit einem senkrechten Strich '
                . 'getrennt, zum Beispiel "Lamborghini Huracan STO | 1. Hand | 640 PS". '
                . 'Nimm nur Angaben aus der Liste der erlaubten Zusätze. Gibt es dort '
                . 'nichts, bleibt es beim Fahrzeugnamen.',
        ],
        'plain' => [
            'label'  => 'Nur Fahrzeugname',
            'extras' => 0,
            'instruction' => 'Der Titel besteht ausschliesslich aus Marke, Modell und Variante. '
                . 'Keine weiteren Angaben, keine Zusätze.',
        ],
        'rich' => [
            'label'  => 'Name mit drei Bestwerten',
            'extras' => 3,
            'instruction' => 'Beginne mit Marke, Modell und Variante und hänge bis zu drei '
                . 'starke Angaben an, jeweils mit einem senkrechten Strich getrennt. '
                . 'Nimm nur Angaben aus der Liste der erlaubten Zusätze.',
        ],
    ];

    public const DEFAULT_TITLE_STYLE = 'compact';

    /** Längere Titel schneiden die Portale in der Trefferliste ab. */
    public const TITLE_MAX_LENGTH = 70;

    public static function titleStyleKey(int $dealershipId): string
    {
        $row = Database::fetch('SELECT listing_title_style FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
        $style = (string) ($row['listing_title_style'] ?? '');
        return isset(self::TITLE_STYLES[$style]) ? $style : self::DEFAULT_TITLE_STYLE;
    }

    public static function titleSample(int $dealershipId): string
    {
        $row = Database::fetch('SELECT listing_title_sample FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
        return trim((string) ($row['listing_title_sample'] ?? ''));
    }

    /** Wie viele Zusätze der gewählte Stil zulässt. */
    public static function titleExtras(int $dealershipId): int
    {
        return (int) self::TITLE_STYLES[self::titleStyleKey($dealershipId)]['extras'];
    }

    /**
     * Starke Angaben für den Titel, ausschliesslich aus echten Fahrzeugdaten.
     *
     * Die Auswahl passiert hier in PHP und nicht im Modell: So kann nichts
     * erfunden werden, und Demo- und Live-Modus liefern denselben Titel.
     * Aufgenommen wird nur, was ein Käufer wirklich als Vorzug liest.
     *
     * @param array<string, mixed> $vehicle
     * @param array<int, string>   $features
     * @return array<int, string>
     */
    public static function titleHighlights(array $vehicle, array $features = []): array
    {
        $out = [];

        if ((int) ($vehicle['previous_owners'] ?? 0) === 1) {
            $out[] = '1. Hand';
        }

        $mileage = $vehicle['mileage'] ?? null;
        if ($mileage !== null && (int) $mileage <= 1000) {
            $out[] = 'Neuzustand';
        } elseif ($mileage !== null && (int) $mileage <= 30000) {
            $out[] = number_format((int) $mileage, 0, '.', "'") . ' km';
        }

        $year = (int) ($vehicle['year'] ?? 0);
        if ($year > 0 && ((int) date('Y')) - $year <= 2) {
            $out[] = 'Jahrgang ' . $year;
        }

        $power = (int) ($vehicle['power_hp'] ?? 0);
        if ($power >= 300) {
            $out[] = $power . ' PS';
        }

        if ((string) ($vehicle['drivetrain'] ?? '') === 'awd') {
            $out[] = 'Allrad';
        }

        if ((string) ($vehicle['fuel_type'] ?? '') === 'electric') {
            $out[] = 'Elektro';
        }

        // Aus der Ausstattung nur Merkmale, die als Kaufargument taugen
        $strong = ['Panoramadach', 'Keramikbremse', 'Standheizung', 'Anhängerkupplung'];
        foreach ($features as $feature) {
            foreach ($strong as $needle) {
                if (mb_stripos((string) $feature, $needle) !== false) {
                    $out[] = (string) $feature;
                    break;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Setzt den Titel regelbasiert zusammen: Fahrzeugname plus so viele
     * Bestwerte, wie der Stil erlaubt und die Länge zulässt.
     *
     * @param array<string, mixed> $vehicle
     * @param array<int, string>   $highlights
     */
    public static function composeTitle(array $vehicle, array $highlights, int $maxExtras): string
    {
        $name = trim(implode(' ', array_filter([
            (string) ($vehicle['make'] ?? ''),
            (string) ($vehicle['model'] ?? ''),
            (string) ($vehicle['variant'] ?? ''),
        ])));
        if ($name === '') {
            $name = 'Fahrzeug';
        }

        $title = $name;
        $used = 0;
        foreach ($highlights as $highlight) {
            if ($used >= $maxExtras) {
                break;
            }
            $candidate = $title . ' | ' . $highlight;
            if (mb_strlen($candidate) > self::TITLE_MAX_LENGTH) {
                continue;
            }
            $title = $candidate;
            $used++;
        }

        return $title;
    }

    /**
     * Baut die Titelanweisung für die Texterzeugung.
     *
     * @param array<int, string> $highlights Erlaubte Zusätze aus echten Daten
     */
    public static function titleInstruction(int $dealershipId, array $highlights = []): string
    {
        $instruction = self::TITLE_STYLES[self::titleStyleKey($dealershipId)]['instruction']
            . ' Der Titel bleibt unter ' . self::TITLE_MAX_LENGTH . ' Zeichen und wiederholt keine '
            . 'Angabe doppelt. Keine Werbefloskeln, keine Ausrufezeichen.';

        $instruction .= "\n\nErlaubte Zusätze für den Titel"
            . ($highlights === [] ? ': keine, nimm nur den Fahrzeugnamen.' : ":\n- " . implode("\n- ", $highlights));

        $sample = self::titleSample($dealershipId);
        if ($sample !== '') {
            $instruction .= "\n\nSo sehen die Titel dieses Autohauses aus. Übernimm den Aufbau, "
                . "aber KEINE Angaben daraus:\n" . mb_substr($sample, 0, 200);
        }

        return $instruction;
    }

    public static function toneKey(int $dealershipId): string
    {
        $row = Database::fetch('SELECT listing_tone FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
        $tone = (string) ($row['listing_tone'] ?? '');
        return isset(self::TONES[$tone]) ? $tone : self::DEFAULT_TONE;
    }

    public static function sample(int $dealershipId): string
    {
        $row = Database::fetch('SELECT listing_sample FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
        return trim((string) ($row['listing_sample'] ?? ''));
    }

    /**
     * Baut die Stilanweisung für die Texterzeugung.
     * Ohne Beispieltext bleibt es bei der Tonvorgabe.
     */
    /** Mindestlänge der Beschreibung im gewählten Ton. */
    public static function minChars(int $dealershipId): int
    {
        return (int) (self::TONES[self::toneKey($dealershipId)]['min_chars'] ?? 650);
    }

    public static function instruction(int $dealershipId): string
    {
        $instruction = self::TONES[self::toneKey($dealershipId)]['instruction'];

        // Diese Vorgaben decken sich mit der Bewertung des Inserats: Länge,
        // Absätze und genannte Ausstattung sind dort die Kriterien. Ohne sie
        // schreibt die KI Texte, die ihre eigene Bewertung nicht besteht.
        $instruction .= ' Die Beschreibung ist mindestens ' . self::minChars($dealershipId)
            . ' Zeichen lang und in mehrere Absätze gegliedert, getrennt durch eine Leerzeile.'
            . ' Nenne mindestens drei der aufgeführten Ausstattungsmerkmale wörtlich so,'
            . ' wie sie in der Liste stehen, eingebettet in vollständige Sätze.';

        $sample = self::sample($dealershipId);
        if ($sample !== '') {
            $instruction .= "\n\nOrientiere dich an diesem Beispiel des Autohauses. Übernimm "
                . "Satzbau, Länge, Ansprache und Aufbau, aber KEINE Angaben daraus: alle "
                . "Fahrzeugdaten kommen ausschliesslich aus dem aktuellen Inserat.\n\n"
                . '"""' . "\n" . mb_substr($sample, 0, 2000) . "\n" . '"""';
        }

        return $instruction;
    }
}
