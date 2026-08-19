<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Demo-Modus (§28/§54): deterministische Ergebnisse aus echten Daten,
 * klar als 'mock' gekennzeichnet (§72). Keine Zufallswerte.
 */
final class MockProvider implements AIProviderInterface
{
    public function mode(): string
    {
        return 'mock';
    }

    public function complete(string $task, array $context): array
    {
        // Die Domänendienste (AIListingService, AILeadService, …) erzeugen im
        // Mock-Modus regelbasierte Texte selbst. Diese Methode liefert nur den
        // Rahmen, falls sie direkt aufgerufen wird.
        return [
            'title'      => '',
            'text'       => '',
            'mode'       => 'mock',
            'confidence' => null,
        ];
    }

    /**
     * Fahrzeugerkennung im Demo-Modus.
     *
     * Die Auswahl der Beispieldaten übernimmt AIVehicleService, damit der
     * Demo-Modus ohne Anbieter auskommt. Hier wird nur signalisiert, dass
     * keine echte Erkennung stattfindet.
     */
    public function detectVehicle(array $absolutePaths): array
    {
        return [
            'detected'   => false,
            'label'      => null,
            'confidence' => null,
            'fields'     => [],
            'features'   => [],
            'note'       => 'KI-Modul im Demo-Modus: keine echte Bilderkennung.',
            'mode'       => 'mock',
        ];
    }

    /**
     * Im Demo-Modus wird kein Dokument ausgewertet. Es wird auch nichts
     * erfunden: Der Aufrufer erhält eine klare Absage.
     */
    public function extractDocument(string $absolutePath): array
    {
        throw new AIException(
            'Die Dokumentauswertung braucht einen hinterlegten OpenAI-Schlüssel. '
            . 'Im Demo-Modus werden keine Werte erfunden.'
        );
    }

    /** Ohne Anbieter wird kein Text ausgewertet und nichts erfunden. */
    public function extractDocumentText(string $documentText): array
    {
        throw new AIException(
            'Die Dokumentauswertung per KI braucht einen hinterlegten OpenAI-Schlüssel. '
            . 'Im Demo-Modus werden keine Werte erfunden.'
        );
    }

    /** Freistellen ist ohne Anbieter nicht möglich. */
    public function cutoutImage(string $absolutePath): string
    {
        throw new AIException(
            'Das Freistellen braucht einen hinterlegten OpenAI-Schlüssel. '
            . 'Im Demo-Modus wird kein Bild verändert.'
        );
    }

    public function analyzeImage(string $absolutePath, array $context = []): array
    {
        // Regelbasierte Qualitätsheuristik aus echten Bildeigenschaften:
        // Auflösung und Seitenverhältnis — ehrlich, ohne erfundene Erkennung.
        $qualityScore = null;
        $info = @getimagesize($absolutePath);
        if ($info !== false) {
            [$width, $height] = $info;
            $score = 40;
            if ($width >= 1600) {
                $score += 35;
            } elseif ($width >= 1200) {
                $score += 28;
            } elseif ($width >= 800) {
                $score += 15;
            }
            $ratio = $height > 0 ? $width / $height : 0;
            if ($ratio >= 1.2 && $ratio <= 1.9) {
                $score += 15; // klassisches Querformat für Fahrzeugbilder
            }
            if (($context['file_size'] ?? 0) > 150_000) {
                $score += 10; // ausreichende Detaildichte
            }
            $qualityScore = min(100, $score);
        }

        return [
            'quality_score'             => $qualityScore,
            // Ehrlich: im Demo-Modus findet keine echte Erkennung statt
            'vehicle_detected'          => null,
            'vehicle_type'              => null,
            'blur_detected'             => null,
            'recommended_as_main_image' => ($context['is_first'] ?? false) === true,
            'mode'                      => 'mock',
        ];
    }
}
