<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Titel und Beschreibung als Vorlage mit Platzhaltern.
 *
 * Die KI schreibt den Text einmal und setzt für Fahrzeugdaten Platzhalter
 * wie {{mileage}} ein. Beim Speichern wird die Vorlage mit den aktuellen
 * Werten gefüllt. Ändert der Händler später den Kilometerstand, stimmt der
 * Text sofort wieder, ohne dass die KI erneut laufen muss.
 *
 * Schreibt der Händler den Text von Hand um, verliert die Vorlage ihre
 * Gültigkeit: sein Text gilt dann unverändert.
 */
final class ListingTemplate
{
    /**
     * Platzhalter und wie sie aus den Fahrzeugdaten entstehen.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        'make'               => 'Marke',
        'model'              => 'Modell',
        'variant'            => 'Variante',
        'year'               => 'Baujahr',
        'first_registration' => 'Erstzulassung',
        'mileage'            => 'Kilometerstand',
        'price'              => 'Preis',
        'power_hp'           => 'PS',
        'power_kw'           => 'kW',
        'displacement_ccm'   => 'Hubraum',
        'color'              => 'Farbe',
        'doors'              => 'Türen',
        'seats'              => 'Sitze',
        'previous_owners'    => 'Vorhalter',
    ];

    /**
     * Ersetzt alle Platzhalter durch die aktuellen Werte des Fahrzeugs.
     *
     * @param array<string, mixed> $vehicle
     */
    public static function render(string $template, array $vehicle): string
    {
        if ($template === '') {
            return '';
        }

        // Die Platzhalter bringen ihre Einheit selbst mit. Schreibt die KI
        // trotzdem noch eine dahinter, wird sie hier mitgeschluckt.
        $units = [
            'mileage'          => 'km',
            'power_hp'         => 'PS',
            'power_kw'         => 'kW',
            'displacement_ccm' => '(?:ccm|cm3|cm³)',
        ];
        $text = $template;
        foreach ($units as $field => $unit) {
            $text = preg_replace(
                '/\{\{' . $field . '\}\}\s*' . $unit . '/iu',
                '{{' . $field . '}}',
                $text
            ) ?? $text;
        }

        $replacements = [];
        foreach (array_keys(self::PLACEHOLDERS) as $field) {
            $replacements['{{' . $field . '}}'] = self::format($field, $vehicle[$field] ?? null);
        }

        $text = strtr($text, $replacements);

        // Platzhalter ohne Wert hinterlassen keine Lücken oder doppelte Zeichen
        $text = preg_replace('/\{\{[a-z_]+\}\}/', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/ ([,.;:])/', '$1', $text) ?? $text;

        return trim($text);
    }

    /** Formatiert einen Wert so, wie er im Text stehen soll. */
    private static function format(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field) {
            'mileage'          => number_format((float) $value, 0, '.', "'") . ' km',
            'price'            => format_price($value),
            'power_hp'         => (string) (int) $value . ' PS',
            'power_kw'         => (string) (int) $value . ' kW',
            'displacement_ccm' => number_format((float) $value, 0, '.', "'") . ' ccm',
            default            => (string) $value,
        };
    }

    /**
     * Enthält der Text mindestens einen Platzhalter?
     * Nur dann lohnt es sich, ihn als Vorlage zu behalten.
     */
    public static function hasPlaceholders(string $text): bool
    {
        return preg_match('/\{\{[a-z_]+\}\}/', $text) === 1;
    }

    /** Liste der Platzhalter für die Anweisung an die KI. */
    public static function promptList(): string
    {
        $lines = [];
        foreach (self::PLACEHOLDERS as $field => $label) {
            $lines[] = '{{' . $field . '}} für ' . $label;
        }
        return implode(', ', $lines);
    }
}
