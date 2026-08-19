<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * Drosselung von Login-/Reset-Versuchen (§10) pro Schlüssel (E-Mail oder IP).
 * Persistiert in der Tabelle login_attempts — funktioniert auch hinter Load-Balancern.
 */
final class RateLimiter
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 900; // 15 Minuten

    /** true, wenn der Schlüssel aktuell gesperrt ist. */
    public static function tooManyAttempts(string $action, string $key): bool
    {
        return self::attempts($action, $key) >= self::MAX_ATTEMPTS;
    }

    public static function attempts(string $action, string $key): int
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE action = :action AND attempt_key = :k AND created_at > :since',
            ['action' => $action, 'k' => self::normalize($key), 'since' => $since]
        );
    }

    public static function hit(string $action, string $key): void
    {
        Database::insert('login_attempts', [
            'action'      => $action,
            'attempt_key' => self::normalize($key),
            'created_at'  => Database::now(),
        ]);
        self::gc();
    }

    public static function clear(string $action, string $key): void
    {
        Database::run(
            'DELETE FROM login_attempts WHERE action = :action AND attempt_key = :k',
            ['action' => $action, 'k' => self::normalize($key)]
        );
    }

    /** Verbleibende Sperrzeit in Minuten (aufgerundet). */
    public static function retryAfterMinutes(string $action, string $key): int
    {
        $oldest = Database::scalar(
            'SELECT MIN(created_at) FROM login_attempts WHERE action = :action AND attempt_key = :k',
            ['action' => $action, 'k' => self::normalize($key)]
        );
        if (!is_string($oldest)) {
            return 1;
        }
        $expiresAt = strtotime($oldest) + self::WINDOW_SECONDS;
        return max(1, (int) ceil(($expiresAt - time()) / 60));
    }

    /** Alte Einträge gelegentlich aufräumen. */
    private static function gc(): void
    {
        if (random_int(1, 20) === 1) {
            $cutoff = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS * 2);
            Database::run('DELETE FROM login_attempts WHERE created_at < :cutoff', ['cutoff' => $cutoff]);
        }
    }

    private static function normalize(string $key): string
    {
        return mb_strtolower(trim($key));
    }
}
