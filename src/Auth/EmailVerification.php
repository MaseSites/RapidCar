<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Service\ActivityLogger;

/**
 * E-Mail-Verifizierung (§11).
 * Konfigurierbar: features.email_verification = true|false.
 * Tokens werden nur als Hash gespeichert.
 */
final class EmailVerification
{
    private const EXPIRY_HOURS = 48;

    public static function isEnabled(): bool
    {
        $enabled = (bool) filter_var(Config::get('features.email_verification', false), FILTER_VALIDATE_BOOL);
        // Mit dem Treiber "log" landen Mails nur in einer Protokolldatei und
        // erreichen niemanden. Die Pflicht wuerde jeden aussperren, deshalb
        // greift sie erst, wenn echter Versand (mail/smtp) eingerichtet ist.
        $driver = strtolower((string) Config::get('mail.driver', 'mail'));
        $canDeliver = $driver !== 'log';
        // Manche Hoster sperren mail(). Dann kaeme ebenfalls nichts an.
        if ($driver === 'mail') {
            $canDeliver = function_exists('mail')
                && !in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        }
        return $enabled && $canDeliver;
    }

    /**
     * Erstellt ein Token und versendet den Verifizierungslink.
     * Gibt zurueck, ob der Versand tatsaechlich geklappt hat: wer die
     * Bestaetigung verlangt, darf keinen Erfolg vortaeuschen (Paragraf 72).
     */
    public static function send(int $userId, string $email): bool
    {
        $token = bin2hex(random_bytes(32));
        $now = Database::now();

        Database::run('DELETE FROM email_verifications WHERE user_id = :uid', ['uid' => $userId]);
        Database::insert('email_verifications', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::EXPIRY_HOURS * 3600),
            'created_at' => $now,
        ]);

        $link = base_url('verify-email.php?token=' . $token);
        // Der Link steht zusätzlich als Klartext im Text: Mail-Programme ohne
        // HTML (und das lokale Log) zeigen ihn sonst nicht an.
        $body = '<p>Willkommen bei RapidCar!</p>'
            . '<p>Bitte bestätige deine E-Mail-Adresse über folgenden Link:</p>'
            . '<p><a href="' . e($link) . '">E-Mail bestätigen</a></p>'
            . '<p>' . e($link) . '</p>'
            . '<p>Der Link ist ' . self::EXPIRY_HOURS . ' Stunden gültig.</p>';

        return Mailer::send($email, 'RapidCar: E-Mail-Adresse bestätigen', $body);
    }

    /** Verifiziert das Token; gibt die Benutzer-ID zurück oder null. */
    public static function verify(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = Database::fetch(
            'SELECT * FROM email_verifications WHERE token_hash = :h',
            ['h' => hash('sha256', $token)]
        );
        if ($row === null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $userId = (int) $row['user_id'];
        Database::update('users', $userId, ['email_verified_at' => Database::now()]);
        Database::run('DELETE FROM email_verifications WHERE user_id = :uid', ['uid' => $userId]);
        ActivityLogger::log($userId, 'user.email_verified', 'E-Mail-Adresse bestätigt', 'user', $userId);

        return $userId;
    }
}
