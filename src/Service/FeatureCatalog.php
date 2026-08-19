<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ausstattungskatalog.
 *
 * Statt eines Textfeldes wählt der Händler aus fertigen Feldern. Das spart
 * Tippen, verhindert Schreibvarianten desselben Merkmals und lässt sich
 * später sauber auf die Merkmalslisten der Plattformen abbilden.
 * Eigene Einträge bleiben trotzdem möglich.
 */
final class FeatureCatalog
{
    /** @var array<string, array<int, string>> Gruppe zu Merkmalen */
    public const GROUPS = [
        'Komfort' => [
            'Klimaanlage', 'Klimaautomatik', 'Zwei-Zonen-Klimaautomatik', 'Sitzheizung vorne',
            'Sitzheizung hinten', 'Sitzbelüftung', 'Massagesitze', 'Elektrische Sitzverstellung',
            'Memory-Sitze', 'Lederausstattung', 'Alcantara', 'Panoramadach', 'Schiebedach',
            'Standheizung', 'Elektrische Heckklappe', 'Keyless Go', 'Ambientebeleuchtung',
            'Armlehne', 'Multifunktionslenkrad', 'Lenkradheizung',
        ],
        'Assistenz und Sicherheit' => [
            'Einparkhilfe hinten', 'Einparkhilfe vorne und hinten', 'Rückfahrkamera',
            '360-Grad-Kamera', 'Parkassistent', 'Tempomat', 'Adaptiver Tempomat',
            'Spurhalteassistent', 'Spurwechselassistent', 'Totwinkelassistent',
            'Notbremsassistent', 'Verkehrszeichenerkennung', 'Müdigkeitserkennung',
            'Head-up-Display', 'Nachtsichtassistent', 'ABS', 'ESP', 'Isofix',
            'Reifendruckkontrolle',
        ],
        'Multimedia' => [
            'Navigationssystem', 'Apple CarPlay', 'Android Auto', 'Bluetooth', 'DAB-Radio',
            'Soundsystem', 'Premium-Soundsystem', 'Freisprecheinrichtung', 'USB-Anschluss',
            'Induktive Ladeschale', 'Digitales Cockpit', 'Sprachsteuerung', 'WLAN-Hotspot',
        ],
        'Aussen und Technik' => [
            'LED-Scheinwerfer', 'Matrix-LED', 'Laserlicht', 'Xenon', 'Nebelscheinwerfer',
            'Anhängerkupplung', 'Dachreling', 'Leichtmetallfelgen', 'Sportfahrwerk',
            'Luftfederung', 'Adaptives Fahrwerk', 'Sportauspuff', 'Allradantrieb',
            'Sperrdifferential', 'Getönte Scheiben', 'Regensensor', 'Lichtsensor',
            'Elektrische Spiegel', 'Beheizbare Frontscheibe', 'Winterreifen',
        ],
        'Elektro und Hybrid' => [
            'Schnellladefunktion', 'Typ-2-Ladekabel', 'CCS-Anschluss', 'Wärmepumpe',
            'Vorklimatisierung', 'Rekuperation einstellbar', 'Ladekabel Haushaltssteckdose',
        ],
    ];

    /** @return array<int, string> Alle Merkmale in einer flachen Liste */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Trennt gespeicherte Merkmale in bekannte und eigene Einträge.
     *
     * @param array<int, string> $features
     * @return array{known: array<int, string>, custom: array<int, string>}
     */
    public static function split(array $features): array
    {
        $catalog = self::all();
        $known = [];
        $custom = [];
        foreach ($features as $feature) {
            $feature = trim($feature);
            if ($feature === '') {
                continue;
            }
            if (in_array($feature, $catalog, true)) {
                $known[] = $feature;
            } else {
                $custom[] = $feature;
            }
        }
        return ['known' => $known, 'custom' => $custom];
    }
}
