<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use RuntimeException;

/**
 * Grenzen der KI-Nutzung je Fahrzeug.
 *
 * Ein Guthaben zahlt EIN Fahrzeug, nicht beliebig viele Analysen. Ohne
 * Grenze liesse sich dasselbe Inserat als Werkbank missbrauchen: Fotos
 * austauschen, erneut erkennen lassen, die Daten von Hand woanders
 * eintragen. Deshalb:
 *
 *   - Die Fahrzeugerkennung laeuft je Fahrzeug genau einmal.
 *   - Dokumente werden je Fahrzeug bis zu dreimal ausgewertet (Ausweis,
 *     Kaufvertrag, Serviceheft sind verschiedene Papiere).
 *   - Texte lassen sich beliebig oft neu erzeugen: sie arbeiten mit den
 *     bereits bezahlten Daten und kosten keine neue Erkennung.
 */
final class AiUsageService
{
    /** Fahrzeugerkennung je Fahrzeug. */
    public const MAX_DETECTIONS = 1;

    /** Dokumente je Fahrzeug. */
    public const MAX_DOCUMENTS = 3;

    public static function detectionsUsed(int $vehicleId): int
    {
        return (int) Database::scalar('SELECT ai_detections FROM vehicles WHERE id = :id', ['id' => $vehicleId]);
    }

    public static function documentsUsed(int $vehicleId): int
    {
        return (int) Database::scalar('SELECT ai_documents FROM vehicles WHERE id = :id', ['id' => $vehicleId]);
    }

    public static function canDetect(int $vehicleId): bool
    {
        return self::detectionsUsed($vehicleId) < self::MAX_DETECTIONS;
    }

    public static function canReadDocument(int $vehicleId): bool
    {
        return self::documentsUsed($vehicleId) < self::MAX_DOCUMENTS;
    }

    /** Zaehlt eine Erkennung. Nach dem Aufruf ist sie verbraucht. */
    public static function countDetection(int $vehicleId): void
    {
        Database::run(
            'UPDATE vehicles SET ai_detections = ai_detections + 1, updated_at = :t WHERE id = :id',
            ['t' => Database::now(), 'id' => $vehicleId]
        );
    }

    public static function countDocument(int $vehicleId): void
    {
        Database::run(
            'UPDATE vehicles SET ai_documents = ai_documents + 1, updated_at = :t WHERE id = :id',
            ['t' => Database::now(), 'id' => $vehicleId]
        );
    }

    /**
     * Stellt sicher, dass dieses Fahrzeug bezahlt ist. Belastet beim ersten
     * KI-Schritt ein Guthaben; jeder weitere Schritt am selben Fahrzeug ist
     * frei. Wirft INSUFFICIENT_CREDITS, wenn nichts mehr da ist.
     */
    public static function ensureCharged(int $dealershipId, int $vehicleId, ?int $userId = null): void
    {
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        CreditService::consumeForListing($dealershipId, (int) $listing['id'], $userId);
    }
}
