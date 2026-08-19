<?php

declare(strict_types=1);

namespace App\AI;

use App\Service\ListingService;

/**
 * KI-Score-Dienst (§32–§35).
 * Mock-Modus: regelbasierte Engine (ListingScoreEngine über ListingService).
 * Live-Modus: kann später zusätzlich KI-Bewertungen einbeziehen — die
 * Ergebnisstruktur bleibt identisch, die UI muss nicht angepasst werden.
 */
final class AIScoreService
{
    /** @return array<string, mixed> Ergebnis der Score-Berechnung */
    public static function evaluate(int $listingId): array
    {
        // Regelbasierte Engine ist in beiden Modi die Basis; im Live-Modus
        // können Provider-Ergebnisse (Bildqualität, Texterkennung) einfliessen.
        return ListingService::recalculate($listingId);
    }
}
