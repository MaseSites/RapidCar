<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Der Feldkatalog des Fahrzeugformulars.
 *
 * Eine Quelle fuer alles: das Formular zeichnet seine Boxen daraus, der
 * Speicher-Handler baut daraus Pruefung und Update. Neue Felder werden
 * hier eingetragen und erscheinen damit ueberall, statt an drei Stellen
 * nachgezogen werden zu muessen.
 *
 * Typen:
 *   text    freie Eingabe
 *   number  ganze Zahl mit min/max
 *   decimal Kommazahl (z.B. Verbrauch)
 *   price   Betrag, Tausendertrennzeichen erlaubt
 *   month   MM.JJJJ (Inverkehrsetzung, letzte MFK)
 *   select  feste Auswahl aus options
 *   tri     Ja/Nein/unbekannt (nullable TINYINT)
 *   check   Haken, 1 oder null
 */
final class VehicleFields
{
    public const TRANSMISSIONS = [
        'manual'         => 'Handschaltung',
        'automatic'      => 'Automatik',
        'semi_automatic' => 'Halbautomatik',
    ];

    public const DRIVETRAINS = [
        'fwd' => 'Frontantrieb',
        'rwd' => 'Heckantrieb',
        'awd' => 'Allrad',
    ];

    public const FUEL_TYPES = [
        'petrol'         => 'Benzin',
        'diesel'         => 'Diesel',
        'electric'       => 'Elektro',
        'hybrid'         => 'Hybrid',
        'plug_in_hybrid' => 'Plug-in-Hybrid',
        'gas'            => 'Gas',
    ];

    public const BODY_TYPES = [
        'limousine'  => 'Limousine',
        'kombi'      => 'Kombi',
        'coupe'      => 'Coupé',
        'suv'        => 'SUV / Geländewagen',
        'cabriolet'  => 'Cabriolet',
        'kleinwagen' => 'Kleinwagen',
        'van'        => 'Van / Kleinbus',
        'pickup'     => 'Pick-up',
    ];

    public const CONDITIONS = [
        'used'     => 'Occasion',
        'new'      => 'Neu',
        'demo'     => 'Vorführfahrzeug',
        'oldtimer' => 'Oldtimer',
    ];

    public const ENGINE_LAYOUTS = [
        'reihe'           => 'Reihe',
        'v'               => 'V',
        'boxer'           => 'Boxer',
        'w'               => 'W',
        'rotationskolben' => 'Rotationskolben',
    ];

    public const ENERGY_CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /**
     * Die Boxen des Formulars, in Anzeige-Reihenfolge.
     * @return array<string, array{title: string, fields: array<string, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        $energy = [];
        foreach (self::ENERGY_CLASSES as $klass) {
            $energy[$klass] = $klass;
        }

        return [
            'merkmale' => [
                'title'  => 'Fahrzeug-Merkmale',
                'fields' => [
                    'make'           => ['label' => 'Marke', 'type' => 'text', 'max' => 100, 'required' => true],
                    'model'          => ['label' => 'Modell', 'type' => 'text', 'max' => 100],
                    'variant'        => ['label' => 'Version', 'type' => 'text', 'max' => 150],
                    'body_type'      => ['label' => 'Aufbau', 'type' => 'select', 'options' => self::BODY_TYPES, 'required' => true],
                    'transmission'   => ['label' => 'Getriebe', 'type' => 'select', 'options' => self::TRANSMISSIONS],
                    'drivetrain'     => ['label' => 'Antrieb', 'type' => 'select', 'options' => self::DRIVETRAINS],
                    'fuel_type'      => ['label' => 'Treibstoff', 'type' => 'select', 'options' => self::FUEL_TYPES],
                    'color'          => ['label' => 'Fahrzeugfarbe', 'type' => 'text', 'max' => 80, 'required' => true],
                    'interior_color' => ['label' => 'Innenfarbe', 'type' => 'text', 'max' => 80],
                    'metallic'       => ['label' => 'Métalisé', 'type' => 'check'],
                ],
            ],
            'zustand' => [
                'title'  => 'Zustand',
                'fields' => [
                    'condition_state'    => ['label' => 'Fahrzeugzustand', 'type' => 'select', 'options' => self::CONDITIONS, 'required' => true],
                    'year'               => ['label' => 'Baujahr', 'type' => 'number', 'min' => 1900, 'max' => 2100],
                    'first_registration' => ['label' => 'Inverkehrsetzung', 'type' => 'month', 'required' => true],
                    'mileage'            => ['label' => 'Kilometer', 'type' => 'number', 'min' => 0, 'max' => 5000000, 'required' => true],
                    'previous_owners'    => ['label' => 'Vorhalter', 'type' => 'number', 'min' => 0, 'max' => 50],
                    'last_mfk'           => ['label' => 'Letzte MFK', 'type' => 'month'],
                    'has_mfk'            => ['label' => 'Ab MFK', 'type' => 'tri'],
                    'accident_free'      => ['label' => 'Unfallfrei', 'type' => 'tri'],
                    'has_warranty'       => ['label' => 'Garantie', 'type' => 'tri'],
                    'warranty_months'    => ['label' => 'Garantie (Monate)', 'type' => 'number', 'min' => 0, 'max' => 120],
                    'warranty_note'      => ['label' => 'Garantie-Beschreibung', 'type' => 'text', 'max' => 190],
                ],
            ],
            'preis' => [
                'title'  => 'Preis',
                'fields' => [
                    'price'     => ['label' => 'Verkaufspreis (CHF)', 'type' => 'price', 'required' => true],
                    'new_price' => ['label' => 'Neupreis (CHF)', 'type' => 'price'],
                ],
            ],
            'technik' => [
                'title'  => 'Technische Daten',
                'fields' => [
                    'doors'              => ['label' => 'Türen', 'type' => 'number', 'min' => 0, 'max' => 9],
                    'seats'              => ['label' => 'Sitze', 'type' => 'number', 'min' => 0, 'max' => 12],
                    'power_hp'           => ['label' => 'PS', 'type' => 'number', 'min' => 0, 'max' => 3000],
                    'power_kw'           => ['label' => 'kW', 'type' => 'number', 'min' => 0, 'max' => 2500],
                    'displacement_ccm'   => ['label' => 'Hubraum (ccm)', 'type' => 'number', 'min' => 0, 'max' => 20000],
                    'cylinders'          => ['label' => 'Zylinder', 'type' => 'number', 'min' => 0, 'max' => 16],
                    'engine_layout'      => ['label' => 'Bauart des Motors', 'type' => 'select', 'options' => self::ENGINE_LAYOUTS],
                    'gears'              => ['label' => 'Gänge', 'type' => 'number', 'min' => 0, 'max' => 12],
                    'consumption'        => ['label' => 'Verbrauch (l/100 km)', 'type' => 'decimal', 'min' => 0, 'max' => 60],
                    'co2_emission'       => ['label' => 'CO2 (g/km)', 'type' => 'number', 'min' => 0, 'max' => 900],
                    'energy_class'       => ['label' => 'Energieetikette', 'type' => 'select', 'options' => $energy],
                    'euro_norm'          => ['label' => 'Abgasnorm', 'type' => 'text', 'max' => 30],
                    'wheelbase_mm'       => ['label' => 'Radstand (mm)', 'type' => 'number', 'min' => 0, 'max' => 6000],
                    'length_mm'          => ['label' => 'Länge (mm)', 'type' => 'number', 'min' => 0, 'max' => 15000],
                    'width_mm'           => ['label' => 'Breite (mm)', 'type' => 'number', 'min' => 0, 'max' => 4000],
                    'height_mm'          => ['label' => 'Höhe (mm)', 'type' => 'number', 'min' => 0, 'max' => 5000],
                    'weight_empty_kg'    => ['label' => 'Leergewicht (kg)', 'type' => 'number', 'min' => 0, 'max' => 10000],
                    'weight_total_kg'    => ['label' => 'Gesamtgewicht (kg)', 'type' => 'number', 'min' => 0, 'max' => 20000],
                    'payload_kg'         => ['label' => 'Nutzlast (kg)', 'type' => 'number', 'min' => 0, 'max' => 10000],
                    'towing_capacity_kg' => ['label' => 'Anhängelast gebremst (kg)', 'type' => 'number', 'min' => 0, 'max' => 10000],
                    'type_certificate'   => ['label' => 'Typengenehmigung', 'type' => 'text', 'max' => 30],
                    'vin'                => ['label' => 'Fahrgestellnummer', 'type' => 'text', 'max' => 30],
                    'stamm_number'       => ['label' => 'Stammnummer', 'type' => 'text', 'max' => 30],
                    'license_category'   => ['label' => 'Fahrzeugart laut Ausweis', 'type' => 'text', 'max' => 60],
                ],
            ],
            'eigenschaften' => [
                'title'  => 'Eigenschaften',
                'fields' => [
                    'is_accessible' => ['label' => 'Behindertengerecht', 'type' => 'check'],
                    'is_import'     => ['label' => 'Direkt-/Parallelimport', 'type' => 'check'],
                    'is_race_car'   => ['label' => 'Rennwagen', 'type' => 'check'],
                    'is_tuned'      => ['label' => 'Tuning', 'type' => 'check'],
                ],
            ],
        ];
    }

    /**
     * Pflichtfelder fuers Veroeffentlichen: ohne sie nimmt keine
     * Verkaufsplattform das Inserat an.
     * @return array<string, string> feldname => Beschriftung
     */
    public static function requiredForPublish(): array
    {
        $required = [];
        foreach (self::all() as $name => $definition) {
            if (($definition['required'] ?? false) === true) {
                $required[$name] = (string) $definition['label'];
            }
        }
        return $required;
    }

    /**
     * Alle Felder flach: name => Definition.
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $flat = [];
        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $name => $definition) {
                $flat[$name] = $definition;
            }
        }
        return $flat;
    }
}
