<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Database;

/**
 * Globale Plattform-Einstellungen (Tabelle settings), u.a. KI-Modus (§54).
 */
final class SettingsService
{
    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::loadAll();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        $now = Database::now();
        $existing = Database::scalar('SELECT COUNT(*) FROM settings WHERE setting_key = :k', ['k' => $key]);
        if ((int) $existing > 0) {
            Database::run(
                'UPDATE settings SET setting_value = :v, updated_at = :t WHERE setting_key = :k',
                ['v' => $value, 't' => $now, 'k' => $key]
            );
        } else {
            Database::run(
                'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :t)',
                ['k' => $key, 'v' => $value, 't' => $now]
            );
        }
        self::$cache = null;
    }

    /**
     * KI-Modus (§54): 'mock' | 'live'.
     * DB-Einstellung hat Vorrang; Fallback auf Konfigurationsdatei.
     */
    public static function aiMode(): string
    {
        $mode = self::get('ai_mode') ?? (string) Config::get('ai.mode', 'mock');
        return $mode === 'live' ? 'live' : 'mock';
    }

    public static function isAiLive(): bool
    {
        return self::aiMode() === 'live';
    }

    private static function loadAll(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            foreach (Database::fetchAll('SELECT setting_key, setting_value FROM settings') as $row) {
                self::$cache[(string) $row['setting_key']] = $row['setting_value'] !== null ? (string) $row['setting_value'] : null;
            }
        } catch (\Throwable) {
            // Tabelle existiert evtl. noch nicht (Installation) — leerer Cache
        }
    }
}
