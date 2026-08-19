<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Abstraktion des KI-Anbieters (§27/§73).
 *
 * Implementierungen:
 *  - MockProvider:   Demo-Modus, deterministisch und klar gekennzeichnet
 *  - OpenAiProvider: Live-Modus über die OpenAI-API (Vision + strukturierte Antworten)
 *
 * Der Anbieter kann ausgetauscht werden, ohne die Anwendung umzubauen.
 */
interface AIProviderInterface
{
    /** 'mock' | 'live' — wird in jeder Antwort mitgegeben (§72). */
    public function mode(): string;

    /**
     * Text-Vervollständigung (Inseratstexte, Antwortvorschläge).
     *
     * @param array<string, mixed> $context Strukturierter Kontext
     * @return array{text: string, title: string, mode: string, confidence: ?int}
     */
    public function complete(string $task, array $context): array;

    /**
     * Bildanalyse eines einzelnen Bildes (§73).
     *
     * @return array{
     *   quality_score: ?int,
     *   vehicle_detected: ?bool,
     *   vehicle_type: ?string,
     *   blur_detected: ?bool,
     *   recommended_as_main_image: bool,
     *   mode: string
     * }
     */
    public function analyzeImage(string $absolutePath, array $context = []): array;

    /**
     * Fahrzeugerkennung aus mehreren Bildern.
     *
     * Liefert pro Feld einen Wert mit Sicherheit und, falls die Zuordnung
     * nicht eindeutig ist, die in Frage kommenden Alternativen. Damit kann die
     * Oberfläche bei Unsicherheit eine Auswahlliste anbieten.
     *
     * @param array<int, string> $absolutePaths
     * @return array{
     *   detected: bool,
     *   label: ?string,
     *   confidence: ?int,
     *   fields: array<string, array{value: mixed, confidence: ?int, alternatives: array<int, string>}>,
     *   note: string,
     *   mode: string
     * }
     */
    public function detectVehicle(array $absolutePaths): array;

    /**
     * Liest ein Fahrzeugdokument (Kaufvertrag, Fahrzeugausweis, Serviceheft)
     * und überträgt die dort schriftlich festgehaltenen Angaben.
     *
     * Gleiche Ergebnisstruktur wie detectVehicle(). Das Dokument selbst wird
     * vom Aufrufer nach der Auswertung gelöscht und nie veröffentlicht.
     *
     * @return array{detected: bool, label: ?string, confidence: ?int, fields: array<string, array{value: mixed, confidence: ?int, alternatives: array<int, string>}>, note: string, mode: string}
     */
    public function extractDocument(string $absolutePath): array;

    /**
     * Wertet den bereits ausgelesenen Text eines Dokuments aus.
     * Günstiger als der Bildweg und deshalb der bevorzugte Weg bei PDFs.
     *
     * @return array{detected: bool, label: ?string, confidence: ?int, fields: array<string, array{value: mixed, confidence: ?int, alternatives: array<int, string>}>, note: string, mode: string}
     */
    public function extractDocumentText(string $documentText): array;

    /**
     * Stellt das Fahrzeug frei und gibt ein PNG mit durchsichtigem Hintergrund
     * zurück. Genau ein Aufruf je Foto; der Hintergrundwechsel danach läuft
     * ohne KI.
     *
     * @return string Binärdaten des PNG
     */
    public function cutoutImage(string $absolutePath): string;
}
