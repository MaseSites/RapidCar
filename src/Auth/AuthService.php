<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;

/**
 * Registrierung, Login, Logout und aktueller Benutzer (§9–§12).
 * Rollen (§45): super_admin | dealer_admin | dealer_user
 */
final class AuthService
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_DEALER_ADMIN = 'dealer_admin';
    public const ROLE_DEALER_USER = 'dealer_user';

    /** @var array<string, mixed>|null */
    private static ?array $cachedUser = null;

    // -----------------------------------------------------------------------
    // Registrierung
    // -----------------------------------------------------------------------

    /**
     * Legt Benutzer + Autohaus an.
     *
     * @return int Neue Benutzer-ID
     */
    public static function register(
        string $firstName,
        string $lastName,
        string $email,
        string $password,
        string $dealershipName,
        string $phone,
        string $country
    ): int {
        Database::beginTransaction();
        try {
            $now = Database::now();

            $dealershipId = Database::insert('dealerships', [
                'name'       => $dealershipName,
                'phone'      => $phone,
                'country'    => $country,
                'currency'   => 'CHF',
                'language'   => \App\Core\Lang::locale(),
                'credits'    => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $userId = Database::insert('users', [
                'dealership_id' => $dealershipId,
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'email'         => mb_strtolower(trim($email)),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'phone'         => $phone,
                'country'       => $country,
                'role'          => self::ROLE_DEALER_ADMIN,
                'is_active'     => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            Database::commit();

            // Startguthaben: ein Gratis-Inserat zum Testen
            try {
                \App\Service\CreditService::grant(
                    $dealershipId,
                    \App\Service\CreditService::WELCOME_CREDITS,
                    \App\Service\CreditService::REASON_WELCOME,
                    'Startguthaben bei Registrierung',
                    $userId
                );
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Startguthaben konnte nicht gutgeschrieben werden: ' . $e->getMessage());
            }

            ActivityLogger::log($userId, 'user.registered', "Benutzer registriert ({$email})", 'user', $userId, $dealershipId);
            return $userId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public static function emailExists(string $email): bool
    {
        return Database::scalar(
                'SELECT COUNT(*) FROM users WHERE email = :email',
                ['email' => mb_strtolower(trim($email))]
            ) > 0;
    }

    // -----------------------------------------------------------------------
    // Login / Logout
    // -----------------------------------------------------------------------

    /**
     * Sucht einen Benutzer anhand von E-Mail ODER Benutzername.
     * Beides wird ohne Rücksicht auf Gross- und Kleinschreibung verglichen.
     *
     * @return array<string, mixed>|null
     */
    public static function findByLogin(string $login): ?array
    {
        $needle = mb_strtolower(trim($login));
        if ($needle === '') {
            return null;
        }
        return Database::fetch(
            'SELECT * FROM users WHERE LOWER(email) = :login OR LOWER(username) = :login',
            ['login' => $needle]
        );
    }

    public static function usernameExists(string $username): bool
    {
        return Database::scalar(
                'SELECT COUNT(*) FROM users WHERE LOWER(username) = :u',
                ['u' => mb_strtolower(trim($username))]
            ) > 0;
    }

    /**
     * Versucht den Login mit E-Mail oder Benutzername. Gibt bei Erfolg den
     * Benutzer zurück, sonst null.
     * Rate-Limiting muss vom Aufrufer geprüft werden (RateLimiter).
     *
     * @return array<string, mixed>|null
     */
    public static function attempt(string $login, string $password): ?array
    {
        $user = self::findByLogin($login);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        if ((int) $user['is_active'] !== 1) {
            return null;
        }

        // Hash-Upgrade, falls sich der Standardalgorithmus geändert hat
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', (int) $user['id'], [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        self::establishSession((int) $user['id']);
        Database::update('users', (int) $user['id'], ['last_login_at' => Database::now()]);
        ActivityLogger::log(
            (int) $user['id'],
            'user.login',
            'Anmeldung erfolgreich',
            'user',
            (int) $user['id'],
            $user['dealership_id'] !== null ? (int) $user['dealership_id'] : null
        );

        return $user;
    }

    private static function establishSession(int $userId): void
    {
        Session::regenerate(); // Session-Fixation verhindern
        Session::set('user_id', $userId);
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            ActivityLogger::log(
                (int) $user['id'],
                'user.logout',
                'Abmeldung',
                'user',
                (int) $user['id'],
                $user['dealership_id'] !== null ? (int) $user['dealership_id'] : null
            );
        }
        self::$cachedUser = null;
        Session::destroy();
    }

    // -----------------------------------------------------------------------
    // Aktueller Benutzer
    // -----------------------------------------------------------------------

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $userId = Session::get('user_id');
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }
        $user = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => (int) $userId]);
        if ($user === null || (int) $user['is_active'] !== 1) {
            Session::remove('user_id');
            return null;
        }
        self::$cachedUser = $user;
        return $user;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user !== null ? (int) $user['id'] : null;
    }

    public static function dealershipId(): ?int
    {
        $user = self::user();
        if ($user === null || $user['dealership_id'] === null) {
            return null;
        }
        return (int) $user['dealership_id'];
    }

    public static function role(): ?string
    {
        $user = self::user();
        return $user !== null ? (string) $user['role'] : null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === self::ROLE_SUPER_ADMIN;
    }

    public static function isDealerAdmin(): bool
    {
        return self::role() === self::ROLE_DEALER_ADMIN;
    }

    public static function fullName(): string
    {
        $user = self::user();
        if ($user === null) {
            return '';
        }
        return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    }

    /** Kurzname wie „Max M." (§20). */
    public static function shortName(): string
    {
        $user = self::user();
        if ($user === null) {
            return '';
        }
        $last = (string) ($user['last_name'] ?? '');
        return trim(($user['first_name'] ?? '') . ' ' . ($last !== '' ? mb_substr($last, 0, 1) . '.' : ''));
    }
}
