<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Mailer;
use App\Service\ActivityLogger;

/**
 * Passwort zurücksetzen (§13): einmaliger Link, Token nur als Hash gespeichert.
 */
final class PasswordReset
{
    private const EXPIRY_MINUTES = 60;

    /**
     * Erstellt Token und versendet den Reset-Link.
     * Gibt immer still zurück — keine Auskunft, ob die E-Mail existiert.
     */
    public static function request(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $user = Database::fetch('SELECT id, is_active FROM users WHERE email = :email', ['email' => $email]);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        Database::run('DELETE FROM password_resets WHERE user_id = :uid', ['uid' => (int) $user['id']]);
        Database::insert('password_resets', [
            'user_id'    => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60),
            'created_at' => Database::now(),
        ]);

        $link = base_url('reset-password.php?token=' . $token);
        $body = '<p>Du hast das Zurücksetzen deines Passworts angefordert.</p>'
            . '<p><a href="' . e($link) . '">Neues Passwort setzen</a></p>'
            . '<p>Der Link ist ' . self::EXPIRY_MINUTES . ' Minuten gültig. '
            . 'Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.</p>';

        Mailer::send($email, 'RapidCar: Passwort zurücksetzen', $body);
    }

    /** Prüft ein Token; gibt die Benutzer-ID zurück oder null. */
    public static function validateToken(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = Database::fetch(
            'SELECT * FROM password_resets WHERE token_hash = :h',
            ['h' => hash('sha256', $token)]
        );
        if ($row === null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return (int) $row['user_id'];
    }

    /** Setzt das neue Passwort und entwertet das Token. */
    public static function complete(string $token, string $newPassword): bool
    {
        $userId = self::validateToken($token);
        if ($userId === null) {
            return false;
        }
        Database::update('users', $userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at'    => Database::now(),
        ]);
        Database::run('DELETE FROM password_resets WHERE user_id = :uid', ['uid' => $userId]);
        ActivityLogger::log($userId, 'user.password_reset', 'Passwort zurückgesetzt', 'user', $userId);
        return true;
    }
}
