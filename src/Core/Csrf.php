<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF-Schutz: Token pro Session, Prüfung für jedes POST-Formular
 * und jeden schreibenden API-Aufruf (Header X-CSRF-Token).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): bool
    {
        $expected = Session::get(self::SESSION_KEY);
        return is_string($expected)
            && is_string($token)
            && $token !== ''
            && hash_equals($expected, $token);
    }

    /** Prüft POST-Feld oder X-CSRF-Token-Header; bricht mit 403 ab, wenn ungültig. */
    public static function verifyRequest(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::validate(is_string($token) ? $token : null)) {
            http_response_code(403);
            if (self::wantsJson()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Ungültiges Sicherheitstoken. Bitte Seite neu laden.']);
            } else {
                require BASE_PATH . '/errors/403.php';
            }
            exit;
        }
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json')
            || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
    }
}
