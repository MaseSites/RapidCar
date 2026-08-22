<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use App\Core\Encryption;
use App\Core\Logger;
use App\Service\ActivityLogger;
use RuntimeException;

/**
 * Ricardo-Anbindung.
 *
 * Der Ablauf unterscheidet sich von den anderen Kanaelen: Ricardo vergibt
 * einen Partnerschluessel an den Betreiber, nicht an den einzelnen Haendler.
 * Damit holt die Anwendung eine voruebergehende Kennung, schickt den Haendler
 * zur Freigabe auf ricardo.ch und tauscht die Kennung danach gegen ein
 * dauerhaftes Zugriffstoken.
 *
 *   1. CreateTemporaryCredential          voruebergehende Kennung
 *   2. Haendler gibt auf ricardo.ch frei  Freigabeseite
 *   3. CreateTokenCredential              dauerhaftes Token
 *   4. RefreshTokenCredential             Erneuerung
 *
 * Der Haendler gibt dabei nie ein Passwort an uns weiter.
 */
final class RicardoService
{
    public const PROVIDER = 'ricardo';

    private const SERVICE_SECURITY = 'SecurityService';

    /** Pfad der Freigabeseite auf ricardo.ch. */
    private const VALIDATION_PATH = '/apiconnect/login/saveinfo/saveinfo';

    // ------------------------------------------------------------------
    // Partnerschluessel des Betreibers
    // ------------------------------------------------------------------

    public static function hasPartnerCredentials(): bool
    {
        return self::partnerKey() !== '' && self::partnerSecret() !== '';
    }

    /**
     * Partnerschluessel. Die Verwaltung hat Vorrang vor der
     * Konfigurationsdatei, damit niemand eine Datei auf dem Server
     * anfassen muss.
     */
    public static function partnerKey(): string
    {
        $stored = trim((string) (\App\Service\SettingsService::get('ricardo_partner_key') ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        return trim((string) ChannelCredentials::value(self::PROVIDER, 'client_id'));
    }

    private static function partnerSecret(): string
    {
        $stored = (string) (\App\Service\SettingsService::get('ricardo_partner_secret') ?? '');
        if ($stored !== '') {
            try {
                return Encryption::decrypt($stored);
            } catch (\Throwable $e) {
                Logger::warning('Der gespeicherte Ricardo-Partnerschlüssel liess sich nicht entschlüsseln.');
                return '';
            }
        }
        return (string) ChannelCredentials::value(self::PROVIDER, 'client_secret');
    }

    /** Legt den Partnerschluessel verschluesselt ab. Leer loescht ihn. */
    public static function storePartnerCredentials(string $key, string $secret): void
    {
        \App\Service\SettingsService::set('ricardo_partner_key', trim($key));
        \App\Service\SettingsService::set(
            'ricardo_partner_secret',
            $secret === '' ? '' : Encryption::encrypt($secret)
        );
    }

    public static function partnerFromDatabase(): bool
    {
        return trim((string) (\App\Service\SettingsService::get('ricardo_partner_key') ?? '')) !== '';
    }

    // ------------------------------------------------------------------
    // Verbindung eines Haendlers
    // ------------------------------------------------------------------

    /**
     * Schritt 1: voruebergehende Kennung holen und die Adresse der
     * Freigabeseite bauen.
     *
     * @return array{key: string, url: string}
     */
    public static function beginConnection(): array
    {
        if (!self::hasPartnerCredentials()) {
            throw new RuntimeException('Es ist kein Ricardo-Partnerschlüssel hinterlegt.');
        }

        $result = RicardoClient::call(self::SERVICE_SECURITY, 'CreateTemporaryCredential', [
            'createTemporaryCredentialParameter' => [
                'PartnerKey'      => self::partnerKey(),
                'PartnerPassword' => self::partnerSecret(),
            ],
        ]);

        $key = (string) ($result['TemporaryCredentialKey'] ?? ($result['CredentialKey'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Ricardo hat keine Kennung für die Freigabe geliefert.');
        }

        return [
            'key' => $key,
            'url' => 'https://www.ricardo.ch' . self::VALIDATION_PATH
                . '?TemporaryCredentialKey=' . rawurlencode($key)
                . '&PartnerKey=' . rawurlencode(self::partnerKey()),
        ];
    }

    /**
     * Schritt 2: nach der Freigabe das dauerhafte Token holen und ablegen.
     */
    public static function completeConnection(int $dealershipId, string $temporaryKey, ?int $userId = null): void
    {
        if ($temporaryKey === '') {
            throw new RuntimeException('Die Freigabe ist unvollständig.');
        }

        $result = RicardoClient::call(self::SERVICE_SECURITY, 'CreateTokenCredential', [
            'createTokenCredentialParameter' => [
                'TemporaryCredentialKey' => $temporaryKey,
                'PartnerKey'             => self::partnerKey(),
                'PartnerPassword'        => self::partnerSecret(),
            ],
        ]);

        $token = (string) ($result['TokenCredentialKey'] ?? ($result['CredentialKey'] ?? ''));
        if ($token === '') {
            throw new RuntimeException(
                'Ricardo hat kein Zugriffstoken geliefert. Wurde die Freigabe wirklich bestätigt?'
            );
        }

        $customerId = (string) ($result['CustomerId'] ?? '');
        $nickname = (string) ($result['Nickname'] ?? ($result['CustomerNickname'] ?? ''));

        self::storeToken($dealershipId, $token, $customerId);
        self::markConnected($dealershipId, $nickname !== '' ? $nickname : ('Konto ' . $customerId), $customerId);

        ActivityLogger::log(
            $userId,
            'ricardo.connected',
            'Ricardo-Verbindung hergestellt' . ($nickname !== '' ? ' (' . $nickname . ')' : ''),
            'integration',
            null,
            $dealershipId
        );
    }

    /** Erneuert das Token. Ricardo gibt dabei ein neues zurueck. */
    public static function refreshToken(int $dealershipId): void
    {
        $token = self::token($dealershipId);
        if ($token === null) {
            return;
        }
        try {
            $result = RicardoClient::call(self::SERVICE_SECURITY, 'RefreshTokenCredential', [
                'refreshTokenCredentialParameter' => [
                    'TokenCredentialKey' => $token['token'],
                    'PartnerKey'         => self::partnerKey(),
                    'PartnerPassword'    => self::partnerSecret(),
                ],
            ]);
            $fresh = (string) ($result['TokenCredentialKey'] ?? ($result['CredentialKey'] ?? ''));
            if ($fresh !== '') {
                self::storeToken($dealershipId, $fresh, $token['customer_id']);
            }
        } catch (\Throwable $e) {
            Logger::warning('Ricardo: Token konnte nicht erneuert werden: ' . $e->getMessage());
        }
    }

    /** @return array{token: string, customer_id: string}|null */
    public static function token(int $dealershipId): ?array
    {
        $row = Database::fetch(
            'SELECT access_token FROM integration_tokens WHERE dealership_id = :d AND provider = :p',
            ['d' => $dealershipId, 'p' => self::PROVIDER]
        );
        if ($row === null || (string) $row['access_token'] === '') {
            return null;
        }
        try {
            $decoded = json_decode(Encryption::decrypt((string) $row['access_token']), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($decoded) || (string) ($decoded['token'] ?? '') === '') {
            return null;
        }
        return [
            'token'       => (string) $decoded['token'],
            'customer_id' => (string) ($decoded['customer_id'] ?? ''),
        ];
    }

    private static function storeToken(int $dealershipId, string $token, string $customerId): void
    {
        $encoded = json_encode(['token' => $token, 'customer_id' => $customerId], JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Das Zugriffstoken konnte nicht verarbeitet werden.');
        }
        $now = Database::now();
        $existing = Database::fetch(
            'SELECT id FROM integration_tokens WHERE dealership_id = :d AND provider = :p',
            ['d' => $dealershipId, 'p' => self::PROVIDER]
        );
        $data = [
            'access_token'  => Encryption::encrypt($encoded),
            'refresh_token' => null,
            'expires_at'    => null,
            'updated_at'    => $now,
        ];
        if ($existing !== null) {
            Database::update('integration_tokens', (int) $existing['id'], $data);
        } else {
            Database::insert('integration_tokens', $data + [
                'dealership_id' => $dealershipId,
                'provider'      => self::PROVIDER,
                'created_at'    => $now,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    public static function isConnected(int $dealershipId): bool
    {
        return self::status($dealershipId) === 'connected' && self::token($dealershipId) !== null;
    }

    /** 'not_configured' | 'disconnected' | 'connected' | 'error' */
    public static function status(int $dealershipId): string
    {
        if (!self::hasPartnerCredentials()) {
            return 'not_configured';
        }
        $row = Database::fetch(
            'SELECT status FROM integrations WHERE dealership_id = :d AND provider = :p',
            ['d' => $dealershipId, 'p' => self::PROVIDER]
        );
        return $row !== null ? (string) $row['status'] : 'disconnected';
    }

    public static function markConnected(int $dealershipId, ?string $accountName, string $customerId = ''): void
    {
        $now = Database::now();
        $existing = Database::fetch(
            'SELECT id FROM integrations WHERE dealership_id = :d AND provider = :p',
            ['d' => $dealershipId, 'p' => self::PROVIDER]
        );
        $data = [
            'status'       => 'connected',
            'account_name' => $accountName,
            'external_id'  => $customerId !== '' ? $customerId : null,
            'connected_at' => $now,
            'updated_at'   => $now,
        ];
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
        Database::run(
            'UPDATE integrations SET status = :s, updated_at = :t WHERE dealership_id = :d AND provider = :p',
            ['s' => 'error', 't' => Database::now(), 'd' => $dealershipId, 'p' => self::PROVIDER]
        );
    }

    public static function disconnect(int $dealershipId, ?int $userId = null): void
    {
        Database::run(
            'DELETE FROM integration_tokens WHERE dealership_id = :d AND provider = :p',
            ['d' => $dealershipId, 'p' => self::PROVIDER]
        );
        Database::run(
            'UPDATE integrations SET status = :s, account_name = NULL, external_id = NULL, updated_at = :t
             WHERE dealership_id = :d AND provider = :p',
            ['s' => 'disconnected', 't' => Database::now(), 'd' => $dealershipId, 'p' => self::PROVIDER]
        );
        ActivityLogger::log($userId, 'ricardo.disconnected', 'Ricardo-Verbindung getrennt', 'integration', null, $dealershipId);
    }

    /** Prueft die Verbindung mit einem leichten Aufruf. */
    public static function testConnection(int $dealershipId): array
    {
        $token = self::token($dealershipId);
        if ($token === null) {
            return ['ok' => false, 'message' => 'Es ist keine Ricardo-Verbindung hinterlegt.'];
        }
        try {
            RicardoClient::call('CustomerService', 'GetCustomerInformation', [
                'getCustomerInformationParameter' => ['TokenCredentialKey' => $token['token']],
            ]);
            return ['ok' => true, 'message' => 'Die Verbindung zu Ricardo funktioniert.'];
        } catch (\Throwable $e) {
            self::markError($dealershipId);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
