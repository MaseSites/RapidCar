<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

/**
 * Regelbasierte Inserat-Bewertung (§32–§35).
 *
 * WICHTIG (§72): Diese Engine berechnet deterministisch aus ECHTEN Daten —
 * keine Zufallszahlen, keine vorgetäuschte KI. Die UI kennzeichnet die
 * Ergebnisse als „Regelbasierte Bewertung — KI im Demo-Modus".
 * Im Live-Modus (§54) kann AIScoreService dieselbe Ergebnisstruktur liefern.
 *
 * Ergebnisstruktur:
 * [
 *   'total'  => int,
 *   'scores' => ['photos'=>?int,'title'=>?int,'description'=>?int,'price'=>?int,'data'=>?int],
 *   'details' => [bereich => Begründungstext],
 *   'recommendations' => [['category','severity','message','action_label'], …],
 *   'engine' => 'rules',
 * ]
 */
final class ListingScoreEngine
{
    private const WEIGHTS = [
        'photos'      => 0.30,
        'title'       => 0.15,
        'description' => 0.20,
        'price'       => 0.15,
        'data'        => 0.20,
    ];

    private const MIN_PRICE_COMPARABLES = 3;

    /**
     * Bewertet ein Inserat anhand von Fahrzeug, Bildern und Vergleichsdaten.
     *
     * @param array<string, mixed> $vehicle  Zeile aus vehicles
     * @param array<string, mixed> $listing  Zeile aus listings (title, description)
     * @param array<int, array<string, mixed>> $images Zeilen aus vehicle_images
     * @param array<int, string> $features   Ausstattungsliste
     */
    public static function evaluate(array $vehicle, array $listing, array $images, array $features): array
    {
        $details = [];
        $recommendations = [];

        $photos = self::scorePhotos($images, $details, $recommendations);
        $title = self::scoreTitle((string) ($listing['title'] ?? ''), $vehicle, $details, $recommendations);
        $description = self::scoreDescription((string) ($listing['description'] ?? ''), $features, $details, $recommendations);
        $data = self::scoreData($vehicle, $details, $recommendations);
        $price = self::scorePrice($vehicle, $details, $recommendations);

        $scores = [
            'photos'      => $photos,
            'title'       => $title,
            'description' => $description,
            'price'       => $price,
            'data'        => $data,
        ];

        // Gewichteter Durchschnitt; fehlende Teilwerte (null) werden herausgerechnet
        $weightSum = 0.0;
        $weighted = 0.0;
        foreach ($scores as $key => $value) {
            if ($value !== null) {
                $weighted += $value * self::WEIGHTS[$key];
                $weightSum += self::WEIGHTS[$key];
            }
        }
        $total = $weightSum > 0 ? (int) round($weighted / $weightSum) : 0;

        return [
            'total'           => $total,
            'scores'          => $scores,
            'details'         => $details,
            'recommendations' => $recommendations,
            'engine'          => 'rules',
        ];
    }

    // -----------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $images */
    private static function scorePhotos(array $images, array &$details, array &$recs): int
    {
        $count = count($images);
        if ($count === 0) {
            $details['photos'] = 'Keine Fotos vorhanden.';
            $recs[] = [
                'category' => 'photos', 'severity' => 'critical',
                'message'  => 'Es sind noch keine Fotos vorhanden. Inserate mit Fotos erhalten deutlich mehr Anfragen.',
                'action_label' => 'Fotos hochladen',
            ];
            return 0;
        }

        // Anzahl: bis 6 Fotos je 10 Punkte (max. 60)
        $score = min(60, $count * 10);

        // Auflösung: Durchschnittsbreite
        $widths = array_map(static fn(array $img): int => (int) ($img['width'] ?? 0), $images);
        $avgWidth = array_sum($widths) / max(1, count($widths));
        if ($avgWidth >= 1200) {
            $score += 20;
        } elseif ($avgWidth >= 800) {
            $score += 10;
        } else {
            $recs[] = [
                'category' => 'photos', 'severity' => 'warning',
                'message'  => 'Die Bildauflösung ist niedrig. Fotos mit mindestens 1200 Pixel Breite wirken deutlich professioneller.',
                'action_label' => 'Bessere Fotos hochladen',
            ];
        }

        // Hauptbild gesetzt
        $hasMain = false;
        foreach ($images as $img) {
            if ((int) ($img['is_main'] ?? 0) === 1) {
                $hasMain = true;
                break;
            }
        }
        if ($hasMain) {
            $score += 10;
        }

        // Ausreichende Abdeckung (aussen/innen braucht erfahrungsgemäss >= 6 Bilder)
        if ($count >= 8) {
            $score += 10;
        } elseif ($count < 6) {
            $missing = 6 - $count;
            $recs[] = [
                'category' => 'photos', 'severity' => $count < 3 ? 'critical' : 'warning',
                'message'  => $missing === 1
                    ? 'Ein weiteres Foto würde das Inserat vervollständigen (z.B. Innenraum oder Heckansicht).'
                    : $missing . ' wichtige Fotos fehlen (z.B. Innenraum, Heck, Details).',
                'action_label' => 'Foto hinzufügen',
            ];
        }

        $score = min(100, $score);
        $details['photos'] = $count . ' Foto(s), Ø ' . (int) $avgWidth . 'px Breite'
            . ($hasMain ? ', Hauptbild gesetzt' : ', kein Hauptbild');
        return $score;
    }

    /** @param array<string, mixed> $vehicle */
    private static function scoreTitle(string $title, array $vehicle, array &$details, array &$recs): int
    {
        $title = trim($title);
        if ($title === '') {
            $details['title'] = 'Kein Titel vorhanden.';
            $recs[] = [
                'category' => 'title', 'severity' => 'critical',
                'message'  => 'Das Inserat hat noch keinen Titel.',
                'action_label' => 'Titel erstellen',
            ];
            return 0;
        }

        $score = 40;
        $lower = mb_strtolower($title);
        $make = mb_strtolower((string) ($vehicle['make'] ?? ''));
        $model = mb_strtolower((string) ($vehicle['model'] ?? ''));

        if ($make !== '' && str_contains($lower, $make)) {
            $score += 15;
        }
        if ($model !== '' && str_contains($lower, $model)) {
            $score += 15;
        }

        $len = mb_strlen($title);
        if ($len >= 20 && $len <= 70) {
            $score += 20;
        } elseif ($len > 70) {
            $recs[] = [
                'category' => 'title', 'severity' => 'info',
                'message'  => 'Der Titel ist sehr lang und wird auf Plattformen möglicherweise abgeschnitten.',
                'action_label' => 'Titel kürzen',
            ];
        } else {
            $recs[] = [
                'category' => 'title', 'severity' => 'info',
                'message'  => 'Ein aussagekräftigerer Titel (20 bis 70 Zeichen) mit Variante oder Highlight wirkt attraktiver.',
                'action_label' => 'Titel optimieren',
            ];
        }

        // Zusatzinfo (PS, Variante, Ausstattungs-Highlight)
        $variant = mb_strtolower((string) ($vehicle['variant'] ?? ''));
        if (($variant !== '' && str_contains($lower, $variant))
            || preg_match('/\d{3}\s?(ps|kw)/i', $title) === 1) {
            $score += 10;
        }

        $details['title'] = $len . ' Zeichen';
        return min(100, $score);
    }

    /** @param array<int, string> $features */
    private static function scoreDescription(string $description, array $features, array &$details, array &$recs): int
    {
        $description = trim($description);
        if ($description === '') {
            $details['description'] = 'Keine Beschreibung vorhanden.';
            $recs[] = [
                'category' => 'description', 'severity' => 'critical',
                'message'  => 'Das Inserat hat noch keine Beschreibung.',
                'action_label' => 'Beschreibung erstellen',
            ];
            return 0;
        }

        $score = 20;
        $len = mb_strlen($description);
        if ($len >= 200) {
            $score += 30;
        }
        if ($len >= 600) {
            $score += 15;
        }
        if ($len < 200) {
            $recs[] = [
                'category' => 'description', 'severity' => 'warning',
                'message'  => 'Die Beschreibung ist sehr kurz. Mindestens 200 Zeichen mit Zustand, Historie und Highlights wirken vertrauenswürdiger.',
                'action_label' => 'Beschreibung erweitern',
            ];
        }

        // Struktur: Absätze oder Aufzählungen
        if (str_contains($description, "\n")) {
            $score += 10;
        }

        // Ausstattung erwähnt?
        $mentioned = 0;
        $lower = mb_strtolower($description);
        foreach ($features as $feature) {
            if ($feature !== '' && str_contains($lower, mb_strtolower($feature))) {
                $mentioned++;
            }
        }
        // Verlangt werden drei Merkmale, aber nie mehr als das Fahrzeug hat:
        // sonst bliebe ein Inserat mit zwei Merkmalen dauerhaft unter 100.
        $expected = min(3, count($features));
        if ($expected === 0) {
            // Ohne erfasste Ausstattung kann der Text nichts nennen. Das ist
            // kein Mangel des Textes, sondern der Fahrzeugdaten.
            $score += 25;
            $recs[] = [
                'category' => 'data', 'severity' => 'info',
                'message'  => 'Für dieses Fahrzeug ist noch keine Ausstattung erfasst. Eingetragene Merkmale machen die Beschreibung überzeugender.',
                'action_label' => 'Ausstattung ergänzen',
            ];
        } elseif ($mentioned >= $expected) {
            $score += 25;
        } elseif (count($features) >= 3) {
            $recs[] = [
                'category' => 'description', 'severity' => 'info',
                'message'  => 'Die Ausstattung könnte in der Beschreibung stärker hervorgehoben werden.',
                'action_label' => 'Beschreibung optimieren',
            ];
            $score += 10;
        }

        $details['description'] = $len . ' Zeichen, ' . $mentioned . ' Ausstattungsmerkmal(e) erwähnt';
        return min(100, $score);
    }

    /** @param array<string, mixed> $vehicle */
    private static function scoreData(array $vehicle, array &$details, array &$recs): int
    {
        // Die wichtigsten Fahrzeugfelder (§29); VIN ist optional und zählt halb
        $requiredFields = [
            'make', 'model', 'year', 'first_registration', 'mileage', 'price',
            'power_hp', 'transmission', 'fuel_type', 'color', 'doors', 'seats',
        ];
        $optionalFields = ['variant', 'power_kw', 'displacement_ccm', 'drivetrain', 'interior_color', 'vin'];

        $filled = 0;
        $missing = [];
        foreach ($requiredFields as $field) {
            $value = $vehicle[$field] ?? null;
            if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
                $filled++;
            } else {
                $missing[] = $field;
            }
        }
        $optionalFilled = 0;
        foreach ($optionalFields as $field) {
            $value = $vehicle[$field] ?? null;
            if ($value !== null && $value !== '') {
                $optionalFilled++;
            }
        }

        $score = (int) round(
            ($filled / count($requiredFields)) * 80
            + ($optionalFilled / count($optionalFields)) * 20
        );

        if ($missing !== []) {
            $labels = [
                'make' => 'Marke', 'model' => 'Modell', 'year' => 'Baujahr',
                'first_registration' => 'Erstzulassung', 'mileage' => 'Kilometerstand',
                'price' => 'Preis', 'power_hp' => 'Leistung (PS)', 'transmission' => 'Getriebe',
                'fuel_type' => 'Treibstoff', 'color' => 'Farbe', 'doors' => 'Türen', 'seats' => 'Sitze',
            ];
            $names = array_map(static fn(string $f): string => $labels[$f] ?? $f, array_slice($missing, 0, 4));
            $recs[] = [
                'category' => 'data', 'severity' => count($missing) > 3 ? 'critical' : 'warning',
                'message'  => 'Fehlende Fahrzeugdaten: ' . implode(', ', $names)
                    . (count($missing) > 4 ? ' u.a.' : '') . '.',
                'action_label' => 'Daten ergänzen',
            ];
        }

        $details['data'] = $filled . '/' . count($requiredFields) . ' Pflichtfelder ausgefüllt';
        return $score;
    }

    /**
     * Preisbewertung gegen vergleichbare Fahrzeuge in der eigenen Datenbank.
     * Ehrlichkeitsregel (§72): zu wenige Vergleichsdaten → null statt erfundener Zahl.
     *
     * @param array<string, mixed> $vehicle
     */
    private static function scorePrice(array $vehicle, array &$details, array &$recs): ?int
    {
        $price = $vehicle['price'] !== null ? (float) $vehicle['price'] : 0.0;
        if ($price <= 0) {
            $details['price'] = 'Kein Preis angegeben.';
            $recs[] = [
                'category' => 'price', 'severity' => 'critical',
                'message'  => 'Es ist noch kein Preis hinterlegt.',
                'action_label' => 'Preis festlegen',
            ];
            return 0;
        }

        $comparables = self::comparablePrices($vehicle);
        if (count($comparables) < self::MIN_PRICE_COMPARABLES) {
            $details['price'] = 'Unzureichende Vergleichsdaten ('
                . count($comparables) . ' vergleichbare Fahrzeuge, benötigt: '
                . self::MIN_PRICE_COMPARABLES . ').';
            return null; // ehrlich: keine Bewertung möglich
        }

        sort($comparables);
        $median = $comparables[intdiv(count($comparables), 2)];
        if ($median <= 0) {
            $details['price'] = 'Unzureichende Vergleichsdaten.';
            return null;
        }

        $ratio = $price / $median;
        $deviation = abs($ratio - 1.0);
        $score = (int) round(max(20, 100 - $deviation * 200));

        if ($ratio > 1.15) {
            $recs[] = [
                'category' => 'price', 'severity' => $ratio > 1.3 ? 'critical' : 'warning',
                'message'  => 'Der Preis liegt ' . (int) round(($ratio - 1) * 100)
                    . '% über dem Median vergleichbarer Fahrzeuge ('
                    . number_format($median, 0, '.', "'") . ').',
                'action_label' => 'Preis analysieren',
            ];
        } elseif ($ratio < 0.8) {
            $recs[] = [
                'category' => 'price', 'severity' => 'info',
                'message'  => 'Der Preis liegt deutlich unter vergleichbaren Fahrzeugen. Möglicherweise verschenkst du Marge.',
                'action_label' => 'Preis analysieren',
            ];
        }

        $details['price'] = count($comparables) . ' Vergleichsfahrzeuge, Median '
            . number_format($median, 0, '.', "'");
        return min(100, $score);
    }

    /**
     * Verkaufsattraktivität (§23): deterministische Kennzahl aus Inserat-Score,
     * Fotoanzahl und Preisangabe. Mock-Modus, klar regelbasiert (§72).
     *
     * @return array{value: int, label: string}
     */
    public static function attractiveness(?int $score, int $imageCount, bool $hasPrice): array
    {
        $base = $score ?? 30;
        $bonus = min(15, $imageCount * 2) + ($hasPrice ? 5 : -10);
        $value = max(0, min(100, (int) round($base * 0.85 + $bonus)));
        $label = match (true) {
            $value >= 75 => 'Hoch',
            $value >= 50 => 'Mittel',
            default      => 'Niedrig',
        };
        return ['value' => $value, 'label' => $label];
    }

    /**
     * Vergleichspreise: gleiche Marke + Modell, Baujahr plus/minus 2, plattformweit.
     *
     * @param array<string, mixed> $vehicle
     * @return array<int, float>
     */
    private static function comparablePrices(array $vehicle): array
    {
        $make = (string) ($vehicle['make'] ?? '');
        $model = (string) ($vehicle['model'] ?? '');
        $year = (int) ($vehicle['year'] ?? 0);
        if ($make === '' || $model === '') {
            return [];
        }

        $params = [
            'make'  => $make,
            'model' => $model,
            'id'    => (int) ($vehicle['id'] ?? 0),
        ];
        $yearFilter = '';
        if ($year > 0) {
            $yearFilter = ' AND year BETWEEN :ymin AND :ymax';
            $params['ymin'] = $year - 2;
            $params['ymax'] = $year + 2;
        }

        $rows = Database::fetchAll(
            'SELECT price FROM vehicles
             WHERE make = :make AND model = :model AND id != :id
               AND price IS NOT NULL AND price > 0' . $yearFilter,
            $params
        );
        return array_map(static fn(array $row): float => (float) $row['price'], $rows);
    }
}
