<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\CaBundle;
use App\Core\Config;
use App\Core\Logger;

/**
 * Live-Betrieb über die OpenAI-API.
 *
 * Fahrzeugerkennung: Die Fahrzeugbilder gehen an ein Modell mit Bildverständnis.
 * Die Antwort wird über "Structured Outputs" erzwungen, damit jedes Feld
 * verlässlich mit Wert, Sicherheit und möglichen Alternativen zurückkommt.
 * Ist ein Feld nicht eindeutig, liefert das Modell die in Frage kommenden
 * Möglichkeiten, die die Oberfläche als Auswahlliste anbietet.
 *
 * Es wird nichts geraten: Was das Modell nicht erkennt, bleibt leer.
 */
final class OpenAiProvider implements AIProviderInterface
{
    public const DEFAULT_API_URL = 'https://api.openai.com/v1';
    public const DEFAULT_MODEL   = 'gpt-4o-mini';
    /** Für die Fahrzeugerkennung auf Fotos: genauer als das Textmodell. */
    public const DEFAULT_VISION_MODEL = 'gpt-5.5';

    /**
     * Höchstzahl Bilder pro Analyse. Drei Fotos zeigen in aller Regel alles,
     * was für Marke, Modell und Variante nötig ist. Jedes weitere Bild kostet
     * zusätzlich, ohne die Erkennung merklich zu verbessern.
     */
    private const MAX_IMAGES = 3;

    private const TIMEOUT_SECONDS = 90;

    /** Modell für das Freistellen; Bildbearbeitung braucht mehr Zeit als Text. */
    public const IMAGE_MODEL = 'gpt-image-1';
    private const IMAGE_TIMEOUT_SECONDS = 180;

    /** Felder, die das Modell bestimmen soll. */
    public const FIELDS = [
        // Kern
        'make', 'model', 'variant', 'year', 'first_registration', 'mileage',
        'price', 'power_hp', 'power_kw', 'displacement_ccm', 'transmission',
        'drivetrain', 'fuel_type', 'color', 'doors', 'seats',
        'vin', 'previous_owners',
        // Technik und Aufbau
        'body_type', 'condition_state', 'cylinders', 'engine_layout', 'gears',
        // Energie
        'consumption', 'co2_emission', 'energy_class', 'euro_norm',
        // Masse und Gewichte
        'length_mm', 'width_mm', 'height_mm',
        'weight_empty_kg', 'weight_total_kg', 'payload_kg',
        // Papiere und Zustand
        'type_certificate', 'license_category', 'is_import', 'is_tuned',
        'is_race_car', 'is_accessible', 'has_mfk', 'accident_free',
        'has_warranty', 'warranty_months', 'warranty_note',
    ];

    /** Felder mit fester Auswahl: das Modell muss einen dieser Codes liefern. */
    public const ENUM_FIELDS = [
        'transmission'    => ['manual', 'automatic', 'semi_automatic'],
        'drivetrain'      => ['fwd', 'rwd', 'awd'],
        'fuel_type'       => ['petrol', 'diesel', 'electric', 'hybrid', 'plug_in_hybrid', 'gas'],
        'body_type'       => ['coupe', 'limousine', 'kombi', 'suv', 'cabriolet', 'kleinwagen', 'van', 'pickup'],
        'condition_state' => ['new', 'used', 'oldtimer', 'demo'],
        'engine_layout'   => ['reihe', 'v', 'boxer', 'w', 'rotationskolben'],
        'energy_class'    => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
        // Ja/Nein-Angaben kommen als Text, damit "unbekannt" moeglich bleibt
        'is_import'       => ['ja', 'nein'],
        'is_tuned'        => ['ja', 'nein'],
        'is_race_car'     => ['ja', 'nein'],
        'is_accessible'   => ['ja', 'nein'],
        'has_mfk'         => ['ja', 'nein'],
        'accident_free'   => ['ja', 'nein'],
        'has_warranty'    => ['ja', 'nein'],
    ];

    public function mode(): string
    {
        return 'live';
    }

    public static function isConfigured(): bool
    {
        return trim((string) Config::get('ai.api_key', '')) !== '';
    }

    /** Modell für Texte (Beschreibung, Antworten): günstig genügt. */
    public static function model(): string
    {
        $model = trim((string) Config::get('ai.model', ''));
        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Modell für die Bilderkennung. Hier lohnt sich das stärkere Modell:
     * Es unterscheidet Baureihen und Ausstattungsdetails deutlich zuverlässiger.
     * Da nur wenige, klein übertragene Bilder je Inserat anfallen, bleibt der
     * Aufpreis überschaubar.
     */
    public static function visionModel(): string
    {
        $model = trim((string) Config::get('ai.vision_model', ''));
        return $model !== '' ? $model : self::DEFAULT_VISION_MODEL;
    }

    /**
     * Detailgrad der Bildübertragung. 'low' schickt eine stark verkleinerte
     * Fassung und kostet je Bild rund ein Zehntel von 'high'. Für Marke,
     * Modell und Farbe reicht das; wer mehr Genauigkeit braucht, stellt
     * ai.image_detail auf 'high'.
     */
    public static function imageDetail(): string
    {
        return self::normalizeDetail((string) Config::get('ai.image_detail', 'low'), 'low');
    }

    /**
     * Detailgrad der Fahrzeugerkennung. Hier lohnt sich 'high': Typschilder
     * wie "STO" oder "Competition" sind klein, und in der verkleinerten
     * Fassung rät das Modell nur noch. Die Erkennung läuft einmal je
     * Fahrzeug, der Aufpreis fällt also nur einmal an.
     */
    public static function detectionDetail(): string
    {
        return self::normalizeDetail((string) Config::get('ai.detection_detail', 'high'), 'high');
    }

    private static function normalizeDetail(string $value, string $fallback): string
    {
        $detail = strtolower(trim($value));
        return in_array($detail, ['low', 'high', 'auto'], true) ? $detail : $fallback;
    }

    public static function apiUrl(): string
    {
        $url = trim((string) Config::get('ai.api_url', ''));
        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    // -----------------------------------------------------------------------
    // Fahrzeugerkennung
    // -----------------------------------------------------------------------

    public function detectVehicle(array $absolutePaths): array
    {
        $images = $this->prepareImages($absolutePaths);
        if ($images === []) {
            return [
                'detected'   => false,
                'label'      => null,
                'confidence' => null,
                'fields'     => [],
                'note'       => 'Keine auswertbaren Bilder vorhanden.',
                'mode'       => 'live',
            ];
        }

        $content = [[
            'type' => 'text',
            'text' => $this->detectionPrompt(),
        ]];
        foreach ($images as $image) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image, 'detail' => self::detectionDetail()]];
        }

        $response = $this->chat(
            [
                [
                    'role'    => 'system',
                    'content' => 'Du bist ein Fahrzeugexperte und analysierst Fotos von Fahrzeugen für ein '
                        . 'Autohaus. Fahrzeugdaten nennst du nur, wenn du sie auf den Bildern erkennst '
                        . 'oder sie feste Werksangaben der erkannten Baureihe sind. Rate niemals.',
                ],
                ['role' => 'user', 'content' => $content],
            ],
            $this->detectionSchema(),
            self::visionModel()
        );

        return $this->mapDetection($response);
    }

    /**
     * Freistellen läuft bewusst NICHT über OpenAI: Bildbearbeitung dort ist
     * teuer. Zuständig ist CutoutService mit dem lokalen Werkzeug rembg oder
     * einem Freistell-Dienst.
     */
    public function cutoutImage(string $absolutePath): string
    {
        throw new AIException(
            'Das Freistellen läuft nicht über OpenAI. Bitte rembg installieren oder einen Freistell-Dienst hinterlegen.'
        );
    }

    /**
     * Liest ein Fahrzeugdokument (Kaufvertrag, Fahrzeugausweis, Serviceheft)
     * und übernimmt die dort schriftlich festgehaltenen Angaben.
     *
     * Anders als bei Fotos wird hier abgelesen und nicht geschätzt: Steht ein
     * Wert nicht im Dokument, bleibt er leer.
     *
     * @return array{detected: bool, label: string, confidence: int|null, fields: array<string, array{value: mixed, confidence: int, alternatives: array<int, string>}>, note: string, mode: string}
     */
    public function extractDocument(string $absolutePath): array
    {
        $images = $this->prepareImages([$absolutePath]);
        if ($images === []) {
            throw new AIException('Das Dokument konnte nicht gelesen werden.');
        }

        $response = $this->chat(
            [
                [
                    'role'    => 'system',
                    'content' => 'Du liest Fahrzeugdokumente und überträgst die dort schriftlich '
                        . 'festgehaltenen Angaben. Du liest ausschliesslich ab und schätzt nie. '
                        . 'Was im Dokument nicht steht, bleibt leer.',
                ],
                [
                    'role'    => 'user',
                    'content' => array_merge(
                        [['type' => 'text', 'text' => $this->documentPrompt()]],
                        array_map(
                            // Dokumente in voller Aufloesung: Zahlen auf Papieren
                            // sind klein und verwechseln sich sonst leicht.
                            static fn(string $url): array => ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => self::detectionDetail()]],
                            $images
                        )
                    ),
                ],
            ],
            $this->detectionSchema('vehicle_document'),
            self::visionModel()
        );

        return $this->mapDetection($response);
    }

    /**
     * Wertet ein PDF ohne Textebene aus (reiner Scan): das PDF geht als
     * Datei an das Modell, OpenAI setzt die Seiten selbst in Bilder um.
     * Teurer als der Textweg, deshalb nur als letzter Schritt.
     */
    public function extractDocumentPdf(string $absolutePath): array
    {
        $size = @filesize($absolutePath);
        if ($size === false || $size <= 0) {
            throw new AIException('Das Dokument konnte nicht gelesen werden.');
        }
        if ($size > 10 * 1024 * 1024) {
            throw new AIException('Das PDF ist zu gross für die KI-Auswertung (mehr als 10 MB). Bitte ein Foto der wichtigsten Seite hochladen.');
        }
        $binary = (string) file_get_contents($absolutePath);

        $response = $this->chat(
            [
                [
                    'role'    => 'system',
                    'content' => 'Du liest Fahrzeugdokumente und überträgst die dort schriftlich '
                        . 'festgehaltenen Angaben. Du liest ausschliesslich ab und schätzt nie. '
                        . 'Was im Dokument nicht steht, bleibt leer.',
                ],
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $this->documentPrompt()],
                        [
                            'type' => 'file',
                            'file' => [
                                'filename'  => 'fahrzeugdokument.pdf',
                                'file_data' => 'data:application/pdf;base64,' . base64_encode($binary),
                            ],
                        ],
                    ],
                ],
            ],
            $this->detectionSchema('vehicle_document'),
            self::visionModel()
        );

        return $this->mapDetection($response);
    }

    /**
     * Wertet den bereits ausgelesenen TEXT eines Dokuments aus.
     *
     * Deutlich günstiger als der Bildweg: Ein paar tausend Zeichen Text kosten
     * einen Bruchteil eines hochauflösenden Bildes.
     */
    public function extractDocumentText(string $documentText): array
    {
        $documentText = mb_substr(trim($documentText), 0, 12000);
        if ($documentText === '') {
            throw new AIException('Der Dokumenttext ist leer.');
        }

        $response = $this->chat(
            [
                [
                    'role'    => 'system',
                    'content' => 'Du liest Fahrzeugdokumente und überträgst die dort schriftlich '
                        . 'festgehaltenen Angaben. Du liest ausschliesslich ab und schätzt nie. '
                        . 'Was im Text nicht steht, bleibt leer.',
                ],
                [
                    'role'    => 'user',
                    'content' => $this->documentPrompt() . "

Dokumenttext:
" . $documentText,
                ],
            ],
            $this->detectionSchema('vehicle_document')
        );

        return $this->mapDetection($response);
    }

    private function documentPrompt(): string
    {
        $enums = [];
        foreach (self::ENUM_FIELDS as $field => $values) {
            $enums[] = $field . ': ' . implode(' | ', $values);
        }

        return "Das Bild zeigt ein Fahrzeugdokument, zum Beispiel einen Kaufvertrag, "
            . "einen Fahrzeugausweis oder ein Serviceheft.\n\n"
            . "Regeln:\n"
            . "1. Übertrage ausschliesslich Angaben, die im Dokument lesbar stehen.\n"
            . "2. Steht ein Feld nicht im Dokument, setze value auf null und confidence auf 0. Rate nie.\n"
            . "3. Ist eine Stelle schwer lesbar, trage die wahrscheinlichste Lesart in value ein "
            . "und alle anderen möglichen Lesarten in alternatives.\n"
            . "4. previous_owners ist die Anzahl der bisherigen Halter (Vorhalter) als Zahl.\n"
            . "5. Zahlenwerte als reine Ziffern ohne Einheit und ohne Tausendertrennzeichen. "
            . "Erstzulassung im Format MM.JJJJ.\n"
            . "6. Für diese Felder sind ausschliesslich folgende Codes erlaubt:\n   "
            . implode("\n   ", $enums) . "\n"
            . "7. Ordne die Zahlen ihrer Einheit zu, nicht ihrer Position. Typische "
            . "Fallen: \"150 kW / 204 PS\" bedeutet power_kw 150 und power_hp 204, "
            . "niemals umgekehrt. Eine Zahl mit ccm oder cm3 ist der Hubraum und "
            . "nie das Baujahr. Eine vierstellige Zahl neben km ist ein "
            . "Kilometerstand und kein Jahr.\n"
            . "8. Die Fahrgestellnummer hat genau 17 Stellen und enthaelt nie die "
            . "Buchstaben I, O oder Q. Bist du bei einer Stelle unsicher, trage die "
            . "wahrscheinlichste Lesart in value und die anderen in alternatives.\n"
            . "9. Uebernimm den Kilometerstand nur, wenn er ausdruecklich als solcher "
            . "beschriftet ist. Zahlen aus Rechnungsbetraegen oder Belegnummern sind "
            . "kein Kilometerstand.\n"
            . "10. note: kurzer Hinweis auf Deutsch, um welches Dokument es sich handelt "
            . "und welche Stellen schwer lesbar waren.\n"
            . "11. label: Fahrzeugbezeichnung laut Dokument.";
    }

    private function detectionPrompt(): string
    {
        $enums = [];
        foreach (self::ENUM_FIELDS as $field => $values) {
            $enums[] = $field . ': ' . implode(' | ', $values);
        }

        return "Analysiere die Fahrzeugfotos und bestimme die Fahrzeugdaten so genau wie möglich.\n\n"
            . "Regeln:\n"
            . "1. Gib für jedes Feld nur einen Wert an, wenn du ihn auf den Bildern erkennen kannst.\n"
            . "2. Ist ein Feld nicht erkennbar, setze value auf null und confidence auf 0.\n"
            . "3. Bist du unsicher zwischen mehreren Möglichkeiten, trage die wahrscheinlichste "
            . "in value ein und ALLE ernsthaft in Frage kommenden Möglichkeiten in alternatives. "
            . "Das ist besonders wichtig bei Modell und Variante, wo sich Baureihen ähneln.\n"
            . "4. Ausnahme zu Regel 1: Alle WERKSANGABEN der erkannten Baureihe und "
            . "Variante traegst du aus deinem Modellwissen ein, auch wenn sie auf keinem "
            . "Bild stehen. Das betrifft: Leistung (power_hp, power_kw), Hubraum "
            . "(displacement_ccm), Zylinderzahl (cylinders), Motorbauart (engine_layout), "
            . "Gangzahl (gears), Aufbau (body_type), Tueren und Sitze, Normverbrauch "
            . "(consumption), CO2-Ausstoss (co2_emission), Energieeffizienz "
            . "(energy_class), Abgasnorm (euro_norm), Laenge, Breite, Hoehe (length_mm, "
            . "width_mm, height_mm) sowie Leergewicht und Gesamtgewicht "
            . "(weight_empty_kg, weight_total_kg). Nutzlast (payload_kg) ist die Differenz "
            . "aus Gesamt- und Leergewicht. Gibt es die Baureihe mit mehreren Stufen (zum "
            . "Beispiel 600 und 640 PS), trage die wahrscheinlichste in value ein und die "
            . "uebrigen in alternatives. Bist du dir bei der Variante nicht sicher, lass "
            . "diese Felder leer statt zu raten.\n"
            . "4b. Diese Felder stehen NIE im Modellwissen und duerfen nur aus Bildern "
            . "oder Dokumenten kommen: Kilometerstand, Preis, Fahrgestellnummer, "
            . "Typenschein-Nummer (type_certificate), Anzahl Vorbesitzer, Unfallfreiheit "
            . "(accident_free), Garantie (has_warranty, warranty_months, warranty_note), "
            . "MFK (has_mfk), Import (is_import), Tuning (is_tuned), Rennwagen "
            . "(is_race_car), behindertengerecht (is_accessible). Ohne Beleg: null.\n"
            . "5. confidence ist eine Zahl von 0 bis 100 und beschreibt, wie sicher du dir bist.\n"
            . "6. Kilometerstand und Preis kannst du nur angeben, wenn sie auf einem Bild lesbar sind "
            . "(z.B. Tacho oder Preisschild). Sonst null.\n"
            . "7. Zahlenwerte als reine Ziffern ohne Einheit. Erstzulassung im Format MM.JJJJ.\n"
            . "8. Für diese Felder sind ausschliesslich folgende Codes erlaubt:\n   "
            . implode("\n   ", $enums) . "\n"
            . "9. features: eine moeglichst vollstaendige Ausstattungsliste. Sie besteht "
            . "aus zwei Quellen: (a) allem, was auf den Fotos SICHTBAR ist, etwa Felgen, "
            . "Panoramadach, Navigationssystem, Ledersitze, LED-Scheinwerfer, Rueckfahrkamera, "
            . "Anhaengerkupplung; und (b) der SERIENAUSSTATTUNG, die die erkannte Baureihe "
            . "und Variante ab Werk immer hat, aus deinem Modellwissen. Beispiel: Ein Huracan "
            . "STO hat serienmaessig Keramikbremsen und ein Carbon-Aerodynamikpaket. "
            . "Nimm KEINE aufpreispflichtigen Sonderausstattungen auf, die das konkrete "
            . "Fahrzeug haben koennte oder auch nicht. Jeder Eintrag kurz, auf Deutsch, "
            . "ohne Doppelungen. Bist du bei der Variante unsicher, nenne nur Sichtbares.
"
            . "10. note: kurzer Hinweis auf Deutsch, was du erkannt hast und wo du unsicher bist.";
    }

    /** JSON-Schema, das die Antwortstruktur erzwingt. */
    private function detectionSchema(string $name = 'vehicle_detection'): array
    {
        $fieldSchema = static function (?array $enumValues): array {
            $valueSchema = $enumValues !== null
                ? ['type' => ['string', 'null'], 'enum' => array_merge($enumValues, [null])]
                : ['type' => ['string', 'null']];

            return [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['value', 'confidence', 'alternatives'],
                'properties'           => [
                    'value'        => $valueSchema,
                    'confidence'   => ['type' => 'integer'],
                    'alternatives' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ];
        };

        $properties = [];
        foreach (self::FIELDS as $field) {
            $properties[$field] = $fieldSchema(self::ENUM_FIELDS[$field] ?? null);
        }

        return [
            'name'   => $name,
            'strict' => true,
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['detected', 'label', 'confidence', 'fields', 'features', 'note'],
                'properties'           => [
                    'detected'   => ['type' => 'boolean'],
                    'label'      => ['type' => ['string', 'null']],
                    'confidence' => ['type' => 'integer'],
                    'note'       => ['type' => 'string'],
                    'features'   => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'fields'     => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => self::FIELDS,
                        'properties'           => $properties,
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $response */
    private function mapDetection(array $response): array
    {
        $fields = [];
        $raw = is_array($response['fields'] ?? null) ? $response['fields'] : [];

        foreach (self::FIELDS as $field) {
            $entry = is_array($raw[$field] ?? null) ? $raw[$field] : [];
            $value = $entry['value'] ?? null;

            if ($value === null || $value === '') {
                continue; // Nicht erkannt: Feld bleibt leer
            }

            $alternatives = [];
            foreach ((array) ($entry['alternatives'] ?? []) as $alternative) {
                $alternative = trim((string) $alternative);
                if ($alternative !== '' && strcasecmp($alternative, (string) $value) !== 0) {
                    $alternatives[] = $alternative;
                }
            }

            $fields[$field] = [
                'value'        => $this->castValue($field, (string) $value),
                'confidence'   => isset($entry['confidence']) ? max(0, min(100, (int) $entry['confidence'])) : null,
                'alternatives' => array_values(array_unique($alternatives)),
            ];
        }

        // Sichtbare Ausstattung, wie vom Modell gemeldet
        $features = [];
        foreach ((array) ($response['features'] ?? []) as $feature) {
            $feature = trim((string) $feature);
            if ($feature !== '' && mb_strlen($feature) <= 60) {
                $features[] = $feature;
            }
        }

        return [
            'features'   => array_values(array_unique($features)),
            'detected'   => (bool) ($response['detected'] ?? false) && $fields !== [],
            'label'      => isset($response['label']) && $response['label'] !== null ? (string) $response['label'] : null,
            'confidence' => isset($response['confidence']) ? max(0, min(100, (int) $response['confidence'])) : null,
            'fields'     => $fields,
            'note'       => (string) ($response['note'] ?? ''),
            'mode'       => 'live',
        ];
    }

    /** Wandelt die Textantwort in den passenden Datentyp. */
    private function castValue(string $field, string $value): mixed
    {
        $numeric = ['year', 'mileage', 'power_hp', 'power_kw', 'displacement_ccm', 'doors', 'seats', 'previous_owners'];
        if (in_array($field, $numeric, true)) {
            $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
            return $digits === '' ? null : (int) $digits;
        }
        if ($field === 'price') {
            $digits = preg_replace('/[^0-9.]/', '', $value) ?? '';
            return $digits === '' ? null : (float) $digits;
        }
        return $value;
    }

    // -----------------------------------------------------------------------
    // Einzelbild-Analyse (§73)
    // -----------------------------------------------------------------------

    public function analyzeImage(string $absolutePath, array $context = []): array
    {
        $images = $this->prepareImages([$absolutePath]);
        if ($images === []) {
            throw new AIException('Bild konnte nicht gelesen werden.');
        }

        $response = $this->chat(
            [[
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Bewerte dieses Fahrzeugfoto für ein Inserat. '
                            . 'quality_score 0 bis 100 nach Bildqualität, Ausschnitt und Eignung als Inseratsbild. '
                            . 'vehicle_type nur angeben, wenn Marke und Modell klar erkennbar sind.',
                    ],
                    ['type' => 'image_url', 'image_url' => ['url' => $images[0], 'detail' => 'low']],
                ],
            ]],
            [
                'name'   => 'image_analysis',
                'strict' => true,
                'schema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required' => ['quality_score', 'vehicle_detected', 'vehicle_type', 'blur_detected', 'recommended_as_main_image'],
                    'properties' => [
                        'quality_score'             => ['type' => 'integer'],
                        'vehicle_detected'          => ['type' => 'boolean'],
                        'vehicle_type'              => ['type' => ['string', 'null']],
                        'blur_detected'             => ['type' => 'boolean'],
                        'recommended_as_main_image' => ['type' => 'boolean'],
                    ],
                ],
            ]
        );

        return [
            'quality_score'             => isset($response['quality_score']) ? max(0, min(100, (int) $response['quality_score'])) : null,
            'vehicle_detected'          => isset($response['vehicle_detected']) ? (bool) $response['vehicle_detected'] : null,
            'vehicle_type'              => isset($response['vehicle_type']) && $response['vehicle_type'] !== null
                ? (string) $response['vehicle_type'] : null,
            'blur_detected'             => isset($response['blur_detected']) ? (bool) $response['blur_detected'] : null,
            'recommended_as_main_image' => (bool) ($response['recommended_as_main_image'] ?? false),
            'mode'                      => 'live',
        ];
    }

    // -----------------------------------------------------------------------
    // Texterzeugung
    // -----------------------------------------------------------------------

    public function complete(string $task, array $context): array
    {
        $vehicle = $context['vehicle'] ?? [];
        $features = $context['features'] ?? [];

        $facts = [];
        foreach ($vehicle as $key => $value) {
            if ($value !== null && $value !== '' && !in_array($key, ['id', 'dealership_id', 'created_by', 'created_at', 'updated_at'], true)) {
                $facts[] = $key . ': ' . (is_scalar($value) ? (string) $value : '');
            }
        }

        $instruction = match ($task) {
            'listing_generation' => 'Schreibe Titel und Beschreibung für ein Fahrzeug-Inserat auf Deutsch. '
                . 'Die Beschreibung ist sachlich und gegliedert; Länge und Aufbau gibt der Schreibstil vor. '
                . 'STRENG: Verwende ausschliesslich die unten angegebenen Daten. Nenne KEINE '
                . 'technischen Angaben, die dort fehlen, insbesondere keine Motorbauart, '
                . 'Zylinderzahl, Verbrauchs- oder Beschleunigungswerte, keine Ausstattung und '
                . 'keine Historie. Erfinde nichts, auch keine Unfallfreiheit, Garantien, '
                . 'Servicehistorie oder Zusagen. Wenn eine Angabe fehlt, lasse sie weg, '
                . 'statt sie zu ergänzen. '
                . 'Schreibe ohne Gedankenstriche und ohne Emojis: statt eines Gedankenstrichs verwende Komma, Punkt oder Doppelpunkt. '
                . 'WICHTIG: Setze für JEDE der unten genannten Angaben den Platzhalter ein, niemals den Wert selbst, '
                . 'damit der Text mitwandert, wenn sich eine Angabe ändert. Verfügbare Platzhalter: '
                . \App\Service\ListingTemplate::promptList() . '. '
                . 'Beispiel: schreibe "mit {{mileage}} auf dem Tacho und {{power_hp}}" statt "mit 30\'000 km und 300 PS". '
                . 'Die Platzhalter bringen Einheit und Formatierung schon mit, schreibe also weder km noch PS noch CHF dahinter. '
                . 'Das gilt auch für Baujahr, Farbe und Erstzulassung: schreibe {{year}}, {{color}} und {{first_registration}} '
                . 'statt der Jahreszahl oder des Farbnamens. Nur für Angaben ohne Platzhalter, etwa Ausstattung, '
                . 'schreibst du den Wert aus.',
            'lead_reply' => 'Formuliere eine höfliche Antwort auf eine Kundenanfrage auf Deutsch. '
                . 'Mache keine Zusagen zu Preisen, Rabatten, Garantien, Lieferzeiten, Unfallfreiheit '
                . 'oder Finanzierung. Der Titel bleibt leer.',
            default => 'Formuliere einen kurzen, sachlichen Text auf Deutsch.',
        };

        // Titelstil des Autohauses samt der erlaubten Zusaetze
        $titleStyle = (string) ($context['title_style'] ?? '');
        if ($titleStyle !== '' && $task === 'listing_generation') {
            $instruction .= "

Titel:
" . $titleStyle;
        }

        // Schreibstil des Autohauses (Ton und optionaler Beispieltext)
        $style = (string) ($context['style'] ?? '');
        if ($style !== '' && $task === 'listing_generation') {
            $instruction .= "

Schreibstil der Beschreibung:
" . $style;
        }

        $userContent = $instruction . "\n\nFahrzeugdaten:\n" . implode("\n", $facts);
        if ($features !== []) {
            $userContent .= "\n\nAusstattung:\n- " . implode("\n- ", array_map('strval', $features));
        }
        if (isset($context['message']['body'])) {
            $userContent .= "\n\nKundennachricht:\n" . (string) $context['message']['body'];
        }

        $response = $this->chat(
            [['role' => 'user', 'content' => $userContent]],
            [
                'name'   => 'listing_text',
                'strict' => true,
                'schema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['title', 'text'],
                    'properties'           => [
                        'title' => ['type' => 'string'],
                        'text'  => ['type' => 'string'],
                    ],
                ],
            ]
        );

        return [
            'title'      => (string) ($response['title'] ?? ''),
            'text'       => (string) ($response['text'] ?? ''),
            'mode'       => 'live',
            'confidence' => null,
        ];
    }

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    /**
     * Anfrage an die Chat-Completions-Schnittstelle mit erzwungenem JSON-Schema.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $jsonSchema
     * @return array<string, mixed>
     */
    private function chat(array $messages, array $jsonSchema, ?string $model = null): array
    {
        $apiKey = trim((string) Config::get('ai.api_key', ''));
        if ($apiKey === '') {
            throw new AIException('Es ist kein OpenAI-Schlüssel hinterlegt (ai.api_key).');
        }
        if (!function_exists('curl_init')) {
            throw new AIException('Die PHP-Erweiterung cURL wird für die KI-Anbindung benötigt.');
        }
        // Der Aufruf darf laenger dauern als das PHP-Standardlimit von 30 Sekunden
        @set_time_limit(300);

        $payload = [
            'model'           => $model ?? self::model(),
            'messages'        => $messages,
            'response_format' => ['type' => 'json_schema', 'json_schema' => $jsonSchema],
        ];

        $ch = curl_init(self::apiUrl() . '/chat/completions');
        curl_setopt_array($ch, CaBundle::applyTo([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('OpenAI nicht erreichbar: ' . $curlError);
            throw new AIException('Die KI ist nicht erreichbar: ' . $curlError);
        }

        $decoded = json_decode((string) $raw, true);

        if ($status >= 400) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            Logger::error('OpenAI-Fehler', ['status' => $status]);
            throw new AIException(match (true) {
                $status === 401 => 'Der OpenAI-Schlüssel wurde abgelehnt. Bitte den Schlüssel in der Konfiguration prüfen.',
                $status === 429 => 'Das OpenAI-Kontingent ist erschöpft oder die Anfragen sind zu häufig.',
                default         => 'Die KI hat die Anfrage abgelehnt (HTTP ' . $status . ')'
                    . ($message !== '' ? ': ' . $message : '.'),
            });
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new AIException('Die KI hat keine auswertbare Antwort geliefert.');
        }

        $result = json_decode($content, true);
        if (!is_array($result)) {
            throw new AIException('Die Antwort der KI konnte nicht gelesen werden.');
        }

        return $result;
    }

    /**
     * Liest Bilder ein und wandelt sie in Data-URLs.
     * Bevorzugt die mittlere Grösse, um Übertragung und Kosten klein zu halten.
     *
     * @param array<int, string> $absolutePaths
     * @return array<int, string>
     */
    private function prepareImages(array $absolutePaths): array
    {
        $images = [];
        foreach (array_slice($absolutePaths, 0, self::MAX_IMAGES) as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $info = @getimagesize($path);
            if ($info === false) {
                continue;
            }
            $binary = file_get_contents($path);
            if ($binary === false) {
                continue;
            }
            $mime = (string) ($info['mime'] ?? 'image/jpeg');
            $images[] = 'data:' . $mime . ';base64,' . base64_encode($binary);
        }
        return $images;
    }
}
