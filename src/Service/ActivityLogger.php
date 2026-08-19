<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;

/**
 * Aktivitätsprotokoll (§52, §68): jede relevante Aktion landet in activity_logs
 * und ist im Admin-Bereich einsehbar.
 */
final class ActivityLogger
{
    public static function log(
        ?int $userId,
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $dealershipId = null
    ): void {
        try {
            Database::insert('activity_logs', [
                'user_id'       => $userId,
                'dealership_id' => $dealershipId,
                'action'        => $action,
                'description'   => $description,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'ip_address'    => self::clientIp(),
                'created_at'    => Database::now(),
            ]);
        } catch (\Throwable $e) {
            // Protokollierung darf den Hauptprozess nie stoppen
            Logger::error('Aktivitätsprotokoll fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return substr($ip, 0, 45);
    }
}
