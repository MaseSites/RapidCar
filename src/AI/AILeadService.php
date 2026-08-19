<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Database;

/**
 * KI-Antwortassistent für Anfragen (§42) mit hartem Sicherheitsfilter (§43):
 * Vorschläge enthalten NIEMALS Preisverhandlungen, Rabatte, Garantien,
 * Lieferzeiten, technische Behauptungen, Unfallfreiheits-Bestätigungen oder
 * Finanzierungszusagen. Jeder Entwurf muss vom Händler bestätigt werden.
 */
final class AILeadService
{
    /** Begriffe, die ein Antwortentwurf niemals zusichern darf (§43). */
    private const FORBIDDEN_PATTERNS = [
        '/\brabatt/i', '/\bnachlass/i', '/\bskonto/i',
        '/\bgarantie(?!frage)/i', '/\bgarantieren\b/i',
        '/\bunfallfrei/i',
        '/\blieferzeit/i', '/\bliefertermin/i',
        '/\bfinanzierung\szugesagt/i', '/\bkredit\b/i',
        '/\bpreis\ssenken/i', '/\bverhandel/i', '/\bletzter\spreis/i',
    ];

    /**
     * Erstellt einen Antwortvorschlag für die letzte Kundennachricht.
     *
     * @return array{draft: string, mode: string, note: string}
     */
    public static function draftReply(int $leadId): array
    {
        $lead = Database::fetch('SELECT * FROM leads WHERE id = :id', ['id' => $leadId]);
        if ($lead === null) {
            throw new AIException('Anfrage nicht gefunden.');
        }
        $lastMessage = Database::fetch(
            "SELECT * FROM messages WHERE lead_id = :lid AND direction = 'inbound' ORDER BY id DESC LIMIT 1",
            ['lid' => $leadId]
        );
        $vehicle = $lead['vehicle_id'] !== null
            ? Database::fetch('SELECT * FROM vehicles WHERE id = :id', ['id' => (int) $lead['vehicle_id']])
            : null;

        if (!AIService::isMock()) {
            $result = AIService::provider()->complete('lead_reply', [
                'lead' => $lead, 'message' => $lastMessage, 'vehicle' => $vehicle,
            ]);
            $draft = self::applySafetyFilter($result['text']);
            return ['draft' => $draft, 'mode' => 'live', 'note' => 'Bitte prüfen und bestätigen. Die KI versendet nie selbstständig.'];
        }

        $draft = self::buildTemplateReply($lead, $lastMessage, $vehicle);
        return [
            'draft' => self::applySafetyFilter($draft),
            'mode'  => 'mock',
            'note'  => 'Demo-Modus: regelbasierter Vorschlag. Bitte prüfen, anpassen und bestätigen. '
                . 'Preise, Rabatte und Zusagen bleiben immer Händler-Entscheidung.',
        ];
    }

    /**
     * Entfernt/blockiert unzulässige Zusagen (§43). Enthält ein Entwurf
     * verbotene Formulierungen, wird der betroffene Satz entfernt.
     */
    public static function applySafetyFilter(string $draft): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $draft) ?: [$draft];
        $safe = [];
        foreach ($sentences as $sentence) {
            $blocked = false;
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $sentence) === 1) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $safe[] = $sentence;
            }
        }
        return trim(implode(' ', $safe));
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed>|null $message
     * @param array<string, mixed>|null $vehicle
     */
    private static function buildTemplateReply(array $lead, ?array $message, ?array $vehicle): string
    {
        $nameParts = preg_split('/\s+/', trim((string) $lead['customer_name'])) ?: [];
        $lastName = count($nameParts) > 1 ? end($nameParts) : (string) $lead['customer_name'];
        $vehicleName = $vehicle !== null
            ? trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''))
            : 'dem Fahrzeug';

        $body = (string) ($message['body'] ?? '');
        $lower = mb_strtolower($body);

        $answer = '';
        if (str_contains($lower, 'verfügbar') || str_contains($lower, 'noch da')) {
            $available = $vehicle !== null && in_array((string) $vehicle['status'], ['published', 'ready'], true);
            $answer = $available
                ? 'Ja, das Fahrzeug ist aktuell noch verfügbar. Gerne können wir einen Termin für eine Besichtigung oder Probefahrt vereinbaren.'
                : 'Vielen Dank für Ihr Interesse. Den aktuellen Status des Fahrzeugs klären wir gerne kurzfristig für Sie ab.';
        } elseif (str_contains($lower, 'probefahrt')) {
            $answer = 'Eine Probefahrt ist selbstverständlich möglich. Teilen Sie uns gerne Ihren Wunschtermin mit, wir bestätigen ihn schnellstmöglich.';
        } elseif (str_contains($lower, 'historie') || str_contains($lower, 'service')) {
            $answer = 'Gerne stellen wir Ihnen die verfügbaren Unterlagen zum Fahrzeug bei einer Besichtigung vor.';
        } elseif (str_contains($lower, 'eintausch') || str_contains($lower, 'inzahlung')) {
            $answer = 'Eine Inzahlungnahme prüfen wir gerne individuell. Senden Sie uns dazu einige Angaben zu Ihrem Fahrzeug.';
        } else {
            $answer = 'Vielen Dank für Ihre Anfrage zu ' . $vehicleName . '. Gerne beantworten wir Ihre Fragen persönlich. Wann dürfen wir Sie kontaktieren?';
        }

        return 'Guten Tag ' . $lastName . ",\n\n"
            . 'vielen Dank für Ihre Anfrage. ' . $answer . "\n\n"
            . 'Freundliche Grüsse';
    }
}
