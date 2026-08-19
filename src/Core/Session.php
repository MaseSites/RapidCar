<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gehärtete Session-Verwaltung (§10):
 * HttpOnly, SameSite=Lax, Secure bei HTTPS, ID-Regeneration, Idle-Timeout.
 */
final class Session
{
    private const IDLE_TIMEOUT = 7200;      // 2 Stunden Inaktivität
    private const REGENERATE_INTERVAL = 900; // ID alle 15 Minuten erneuern

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('rapidcar_session');
        session_start();

        $now = time();

        // Idle-Timeout: zu alte Sessions verwerfen
        if (isset($_SESSION['_last_activity']) && ($now - (int) $_SESSION['_last_activity']) > self::IDLE_TIMEOUT) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;

        // Periodische ID-Regeneration gegen Fixation
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = $now;
        } elseif (($now - (int) $_SESSION['_created']) > self::REGENERATE_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['_created'] = $now;
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /** Flash-Nachricht setzen (überlebt genau einen Request). */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function pullFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // Plesk und die meisten Hoster stellen einen nginx davor. Dann kommt
        // die Anfrage intern als http an, und nur diese Kopfzeilen verraten,
        // dass der Besucher ueber HTTPS gekommen ist.
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
