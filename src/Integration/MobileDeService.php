<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use App\Core\Encryption;
use App\Service\ActivityLogger;
use RuntimeException;

/**
 * mobile.de je Autohaus: Zugangsdaten, Verbindung, Status.
 *
 * Jedes Autohaus nutzt seinen eigenen API-Benutzer, den mobile.de nach
 * Beantragung freischaltet (Haendlerkonto vorausgesetzt). Die Zugangsdaten
 * liegen verschluesselt in der Datenbank (§58), niemals im Klartext.
 */
final class MobileDeService
{
    public const PROVIDER = 'mobile_de';

    /**
     * Prueft Zugangsdaten gegen die API und liefert die Verkaeuferkonten.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function verifyCredentials(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new AutoScoutAuthException('Bitte Benutzername und Passwort eingeben.');
        }

        $result = MobileDeClient::request('GET', '/seller-api/sellers', $username, $password);
        if ($result['status'] === 401 || $result['status'] === 403) {
            throw new AutoScoutAuthException('mobile.de hat die Zugangsdaten abgelehnt. Ist der API-Benutzer freigeschaltet?');
        }
        if ($result['status'] >= 400) {
            throw new RuntimeException('mobile.de hat mit HTTP ' . $result['status'] . ' geantwortet.');
        }

        $sellers = [];
        $rows = $result['data']['sellers'] ?? ($result['data']['values'] ?? $result['data'] ?? []);
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['mobileSellerId'] ?? ($row['sellerId'] ?? ($row['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $name = (string) ($row['companyName'] ?? ($row['name'] ?? ('Verkäufer ' . $id)));
            $customer = (string) ($row['customerNumber'] ?? '');
            $sellers[] = [
                'id'   => $id,
                'name' => $name . ($customer !== '' ? ' (Kundennummer ' . $customer . ')' : ''),
            ];
        }
        return $sellers;
    }

    /** Stellt die Verbindung her; bei mehreren Verkaeuferkonten entscheidet $sellerId. */
    public static function connect(
        int $dealershipId,
        string $username,
        string $password,
        string $sellerId,
        string $sellerName,
        ?int $userId = null,
        bool $viaPlatform = false
    ): void {
        // Ueber den Betreiber-Zugang wird bewusst KEIN Passwort des Haendlers
        // gespeichert; die Zugangsdaten kommen dann aus der Verwaltung.
        $encoded = json_encode($viaPlatform ? [
            'mode'      => 'platform',
            'seller_id' => $sellerId,
        ] : [
            'username'  => trim($username),
            'password'  => $password,
            'seller_id' => $sellerId,
        ], JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Zugangsdaten konnten nicht verarbeitet werden.');
        }

        $now = Database::now();
        $encrypted = Encryption::encrypt($encoded);
        $existing = Database::fetch(
            'SELECT id FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        $tokenData = ['access_token' => $encrypted, 'refresh_token' => null, 'expires_at' => null, 'updated_at' => $now];
        if ($existing !== null) {
            Database::update('integration_tokens', (int) $existing['id'], $tokenData);
        } else {
            Database::insert('integration_tokens', $tokenData + [
                'dealership_id' => $dealershipId,
                'provider'      => self::PROVIDER,
                'created_at'    => $now,
            ]);
        }

        self::markConnected($dealershipId, $sellerName !== '' ? $sellerName : ('Verkäufer ' . $sellerId));
        ActivityLogger::log(
            $userId,
            'integration.mobilede_connected',
            'mobile.de-Verbindung hergestellt (' . $sellerId . ')',
            'integration',
            null,
            $dealershipId
        );
    }

    // -----------------------------------------------------------------------
    // Betreiber-Zugang (bei mobile.de "Transfer Service Provider"): ein
    // Zugang, der im Namen mehrerer Verkaeufer inseriert. Der Haendler gibt
    // dann kein eigenes Passwort heraus, er waehlt nur sein Verkaeuferkonto.
    // -----------------------------------------------------------------------

    public static function hasPlatformCredentials(): bool
    {
        return self::platformUsername() !== '' && self::platformPassword() !== '';
    }

    /**
     * Benutzername des Betreiber-Zugangs. Die Verwaltung hat Vorrang vor der
     * Konfigurationsdatei: so laesst er sich eintragen, ohne je eine Datei
     * auf dem Server anzufassen.
     */
    public static function platformUsername(): string
    {
        $stored = trim((string) (\App\Service\SettingsService::get('mobilede_platform_username') ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        return trim((string) \App\Core\Config::get('channels.mobile_de.platform_username', ''));
    }

    private static function platformPassword(): string
    {
        $stored = (string) (\App\Service\SettingsService::get('mobilede_platform_password') ?? '');
        if ($stored !== '') {
            try {
                return Encryption::decrypt($stored);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Der gespeicherte mobile.de-Betreiberzugang liess sich nicht entschlüsseln.');
                return '';
            }
        }
        return (string) \App\Core\Config::get('channels.mobile_de.platform_password', '');
    }

    /** Legt den Betreiber-Zugang verschluesselt ab. Leeres Passwort loescht ihn. */
    public static function storePlatformCredentials(string $username, string $password): void
    {
        \App\Service\SettingsService::set('mobilede_platform_username', trim($username));
        \App\Service\SettingsService::set(
            'mobilede_platform_password',
            $password === '' ? '' : Encryption::encrypt($password)
        );
    }

    public static function platformFromDatabase(): bool
    {
        return trim((string) (\App\Service\SettingsService::get('mobilede_platform_username') ?? '')) !== '';
    }

    /**
     * Verkaeuferkonten, die ueber den Betreiber-Zugang erreichbar sind und
     * noch keinem anderen Konto gehoeren.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function availablePlatformSellers(int $dealershipId): array
    {
        if (!self::hasPlatformCredentials()) {
            return [];
        }
        $sellers = self::verifyCredentials(self::platformUsername(), self::platformPassword());
        return array_values(array_filter(
            $sellers,
            fn(array $seller): bool => self::dealershipUsingSeller((string) $seller['id'], $dealershipId) === null
        ));
    }

    /** Welches Konto nutzt dieses Verkaeuferkonto bereits? */
    public static function dealershipUsingSeller(string $sellerId, ?int $exceptDealershipId = null): ?int
    {
        $rows = Database::fetchAll(
            'SELECT dealership_id, access_token FROM integration_tokens WHERE provider = :p',
            ['p' => self::PROVIDER]
        );
        foreach ($rows as $row) {
            $owner = (int) $row['dealership_id'];
            if ($exceptDealershipId !== null && $owner === $exceptDealershipId) {
                continue;
            }
            try {
                $decoded = json_decode((string) Encryption::decrypt((string) $row['access_token']), true);
            } catch (\Throwable $e) {
                continue;
            }
            if (is_array($decoded) && (string) ($decoded['seller_id'] ?? '') === $sellerId) {
                return $owner;
            }
        }
        return null;
    }

    /**
     * Verbindet ein Konto ueber den Betreiber-Zugang. Es wird kein Passwort
     * des Haendlers gespeichert, nur die Nummer seines Verkaeuferkontos.
     */
    public static function connectViaPlatform(
        int $dealershipId,
        string $sellerId,
        ?string $sellerName = null,
        ?int $userId = null
    ): void {
        if (!self::hasPlatformCredentials()) {
            throw new RuntimeException('Es ist kein Betreiber-Zugang für mobile.de hinterlegt.');
        }
        $known = false;
        foreach (self::verifyCredentials(self::platformUsername(), self::platformPassword()) as $seller) {
            if ((string) $seller['id'] === $sellerId) {
                $known = true;
                $sellerName = $sellerName ?? (string) $seller['name'];
                break;
            }
        }
        if (!$known) {
            throw new RuntimeException('Dieses Verkäuferkonto ist über den Betreiber-Zugang nicht erreichbar.');
        }
        if (self::dealershipUsingSeller($sellerId, $dealershipId) !== null) {
            throw new RuntimeException('Dieses Verkäuferkonto ist bereits mit einem anderen Konto verbunden.');
        }
        self::connect($dealershipId, '', '', $sellerId, $sellerName, $userId, true);
    }

    /**
     * @return array{username: string, password: string, seller_id: string}|null
     */
    public static function credentials(int $dealershipId): ?array
    {
        $row = Database::fetch(
            'SELECT access_token FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        if ($row === null || (string) $row['access_token'] === '') {
            return null;
        }
        $decoded = json_decode((string) Encryption::decrypt((string) $row['access_token']), true);
        if (!is_array($decoded)) {
            return null;
        }

        // Ueber den Betreiber-Zugang verbunden: die Zugangsdaten kommen aus
        // der Verwaltung, gespeichert ist nur das Verkaeuferkonto.
        if (($decoded['mode'] ?? '') === 'platform') {
            if (!self::hasPlatformCredentials()) {
                return null;
            }
            return [
                'username'  => self::platformUsername(),
                'password'  => self::platformPassword(),
                'seller_id' => (string) ($decoded['seller_id'] ?? ''),
            ];
        }

        if ((string) ($decoded['username'] ?? '') === '') {
            return null;
        }
        return [
            'username'  => (string) $decoded['username'],
            'password'  => (string) ($decoded['password'] ?? ''),
            'seller_id' => (string) ($decoded['seller_id'] ?? ''),
        ];
    }

    public static function isConnected(int $dealershipId): bool
    {
        return self::status($dealershipId) === 'connected' && self::credentials($dealershipId) !== null;
    }

    public static function status(int $dealershipId): string
    {
        $row = Database::fetch(
            'SELECT status FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        return $row === null ? 'disconnected' : (string) $row['status'];
    }

    public static function markConnected(int $dealershipId, ?string $accountName): void
    {
        $now = Database::now();
        $existing = Database::fetch(
            'SELECT id FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        $data = ['status' => 'connected', 'account_name' => $accountName, 'connected_at' => $now, 'updated_at' => $now];
        if ($existing !== null) {
            Database::update('integrations', (int) $existing['id'], $data);
        } else {
            Database::insert('integrations', $data + [
                'dealership_id' => $dealershipId,
                'provider'      => self::PROVIDER,
                'created_at'    => $now,
            ]);
        }
    }

    public static function markError(int $dealershipId): void
    {
        $existing = Database::fetch(
            'SELECT id FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        if ($existing !== null) {
            Database::update('integrations', (int) $existing['id'], [
                'status' => 'error', 'updated_at' => Database::now(),
            ]);
        }
    }

    public static function disconnect(int $dealershipId, ?int $userId = null): void
    {
        Database::run(
            'DELETE FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        $existing = Database::fetch(
            'SELECT id FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        if ($existing !== null) {
            Database::update('integrations', (int) $existing['id'], [
                'status' => 'disconnected', 'account_name' => null, 'updated_at' => Database::now(),
            ]);
        }
        ActivityLogger::log($userId, 'integration.mobilede_disconnected', 'mobile.de-Verbindung getrennt', 'integration', null, $dealershipId);
    }

    /** @return array{ok: bool, message: string} */
    public static function testConnection(int $dealershipId): array
    {
        $credentials = self::credentials($dealershipId);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'Keine Verbindung hinterlegt.'];
        }
        try {
            $result = MobileDeClient::request(
                'GET',
                '/seller-api/sellers/' . rawurlencode($credentials['seller_id']),
                $credentials['username'],
                $credentials['password']
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($result['status'] >= 400) {
            self::markError($dealershipId);
            return ['ok' => false, 'message' => 'mobile.de hat mit HTTP ' . $result['status'] . ' geantwortet.'];
        }
        return ['ok' => true, 'message' => 'Verbindung in Ordnung.'];
    }
}
