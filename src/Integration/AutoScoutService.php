<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use App\Core\Encryption;
use App\Service\ActivityLogger;
use RuntimeException;

/**
 * AutoScout24-Verbindung eines Autohauses.
 *
 * Die API verwendet HTTP Basic Auth mit den Zugangsdaten des Händlerkontos.
 * Diese werden AES-256-GCM-verschlüsselt in integration_tokens abgelegt und
 * niemals im Browser oder in Logs ausgegeben.
 */
final class AutoScoutService
{
    public const PROVIDER = 'autoscout24';

    /** Verbindungsart: ein Zugang der Plattform für alle Autohäuser. */
    public const MODE_PLATFORM = 'platform';

    /** Verbindungsart: jedes Autohaus hinterlegt eigene Zugangsdaten. */
    public const MODE_OWN = 'own';

    /**
     * Hat der Plattform-Betreiber einen eigenen Schnittstellen-Zugang hinterlegt?
     *
     * Die AutoScout24-API ist für beide Modelle ausgelegt: Ein Zugang kann
     * stellvertretend für mehrere Kunden arbeiten (GET /customers liefert alle
     * Kunden, für die der Zugang berechtigt ist). Ist ein Plattform-Zugang
     * konfiguriert, muss ein Autohaus nur noch seine Kundennummer wählen und
     * gibt kein eigenes Passwort ein.
     */
    public static function hasPlatformCredentials(): bool
    {
        return self::platformUsername() !== '' && self::platformPassword() !== '';
    }

    /**
     * Benutzername des Betreiber-Zugangs. Die Verwaltung hat Vorrang vor der
     * Konfigurationsdatei: so laesst sich der Zugang eintragen, ohne je eine
     * Datei auf dem Server anzufassen.
     */
    public static function platformUsername(): string
    {
        $stored = trim((string) (\App\Service\SettingsService::get('autoscout_platform_username') ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        return trim((string) \App\Core\Config::get('autoscout.platform_username', ''));
    }

    /** Passwort des Betreiber-Zugangs, in der Datenbank verschluesselt abgelegt. */
    private static function platformPassword(): string
    {
        $stored = (string) (\App\Service\SettingsService::get('autoscout_platform_password') ?? '');
        if ($stored !== '') {
            try {
                return \App\Core\Encryption::decrypt($stored);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Der gespeicherte AutoScout24-Plattformzugang liess sich nicht entschlüsseln.');
                return '';
            }
        }
        return (string) \App\Core\Config::get('autoscout.platform_password', '');
    }

    /**
     * Legt den Betreiber-Zugang verschluesselt in der Datenbank ab.
     * Ein leeres Passwort loescht den gespeicherten Zugang.
     */
    public static function storePlatformCredentials(string $username, string $password): void
    {
        $username = trim($username);
        \App\Service\SettingsService::set('autoscout_platform_username', $username);
        \App\Service\SettingsService::set(
            'autoscout_platform_password',
            $password === '' ? '' : \App\Core\Encryption::encrypt($password)
        );
    }

    /** Kommt der Zugang aus der Verwaltung oder noch aus der Datei? */
    public static function platformFromDatabase(): bool
    {
        return trim((string) (\App\Service\SettingsService::get('autoscout_platform_username') ?? '')) !== '';
    }

    /**
     * Kunden, die über den Plattform-Zugang erreichbar sind.
     *
     * @return array<int, array{id: string, sellId: ?string, canSetMiaRequestedTier: bool}>
     */
    public static function platformCustomers(): array
    {
        if (!self::hasPlatformCredentials()) {
            throw new RuntimeException('Es ist kein Plattform-Zugang für AutoScout24 hinterlegt.');
        }
        return self::verifyCredentials(self::platformUsername(), self::platformPassword());
    }

    /**
     * Welches Autohaus nutzt diese Kundennummer bereits?
     *
     * Schützt im Plattform-Modus davor, dass ein Autohaus die Kundennummer
     * eines anderen belegt und dadurch dessen Inserate sehen könnte.
     */
    public static function dealershipUsingCustomer(string $customerId, ?int $exceptDealershipId = null): ?int
    {
        $rows = Database::fetchAll(
            'SELECT dealership_id, access_token FROM integration_tokens WHERE provider = :p',
            ['p' => self::PROVIDER]
        );

        foreach ($rows as $row) {
            $dealershipId = (int) $row['dealership_id'];
            if ($exceptDealershipId !== null && $dealershipId === $exceptDealershipId) {
                continue;
            }
            if ($row['access_token'] === null) {
                continue;
            }
            try {
                $decoded = json_decode(Encryption::decrypt((string) $row['access_token']), true);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($decoded) && (string) ($decoded['customer_id'] ?? '') === $customerId) {
                return $dealershipId;
            }
        }
        return null;
    }

    /**
     * Kundennummern des Plattform-Zugangs, die noch keinem anderen Autohaus
     * zugeordnet sind.
     *
     * @return array<int, array{id: string, sellId: ?string, canSetMiaRequestedTier: bool}>
     */
    public static function availablePlatformCustomers(int $dealershipId): array
    {
        $available = [];
        foreach (self::platformCustomers() as $customer) {
            if (self::dealershipUsingCustomer($customer['id'], $dealershipId) === null) {
                $available[] = $customer;
            }
        }
        return $available;
    }

    /**
     * Verbindet ein Autohaus über den Plattform-Zugang: es wird nur die
     * Kundennummer gespeichert, kein Passwort des Autohauses.
     */
    public static function connectViaPlatform(
        int $dealershipId,
        string $customerId,
        ?string $sellId = null,
        ?int $userId = null
    ): void {
        if (!self::hasPlatformCredentials()) {
            throw new RuntimeException('Es ist kein Plattform-Zugang für AutoScout24 hinterlegt.');
        }

        // Prüfen, dass die Kundennummer über den Plattform-Zugang erreichbar ist
        $allowed = false;
        foreach (self::platformCustomers() as $customer) {
            if ($customer['id'] === $customerId) {
                $allowed = true;
                $sellId ??= $customer['sellId'];
                break;
            }
        }
        if (!$allowed) {
            throw new RuntimeException('Diese Kundennummer ist über den Plattform-Zugang nicht erreichbar.');
        }

        // Fremde Zuordnung ausschliessen: eine Kundennummer gehört genau einem Autohaus
        $takenBy = self::dealershipUsingCustomer($customerId, $dealershipId);
        if ($takenBy !== null) {
            throw new RuntimeException(
                'Diese Kundennummer ist bereits einem anderen Autohaus zugeordnet. '
                . 'Bitte den Plattform-Betreiber kontaktieren.'
            );
        }

        self::storeConnection($dealershipId, [
            'mode'        => self::MODE_PLATFORM,
            'customer_id' => $customerId,
            'sell_id'     => $sellId,
        ], $customerId, $sellId, $userId);
    }

    /**
     * Prüft Zugangsdaten gegen die echte API und liefert die verfügbaren Kunden.
     *
     * @return array<int, array{id: string, sellId: ?string, canSetMiaRequestedTier: bool}>
     */
    public static function verifyCredentials(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            throw new RuntimeException('Benutzername und Passwort sind erforderlich.');
        }

        $response = AutoScoutClient::requestWith($username, $password, 'GET', '/customers');
        $data = $response['data'];

        if (!is_array($data) || !isset($data['customers']) || !is_array($data['customers'])) {
            throw new RuntimeException('Unerwartete Antwort von AutoScout24 beim Abruf der Kundenliste.');
        }

        $customers = [];
        foreach ($data['customers'] as $customer) {
            if (!is_array($customer) || !isset($customer['id'])) {
                continue;
            }
            $customers[] = [
                'id'                     => (string) $customer['id'],
                'sellId'                 => isset($customer['sellId']) ? (string) $customer['sellId'] : null,
                'canSetMiaRequestedTier' => (bool) ($customer['canSetMiaRequestedTier'] ?? false),
            ];
        }

        if ($customers === []) {
            throw new RuntimeException('Das Konto ist gültig, aber es sind keine Kunden hinterlegt. Bitte AutoScout24 kontaktieren.');
        }

        return $customers;
    }

    /**
     * Prüft die Eingabe auf typische Stolpersteine, bevor sie an die API geht.
     * Es wird nichts stillschweigend verändert: der Benutzer entscheidet.
     *
     * @return array<int, string> Hinweise, leer wenn nichts auffällt
     */
    public static function inputWarnings(string $username, string $password): array
    {
        $warnings = [];

        if ($username !== trim($username)) {
            $warnings[] = 'Der Benutzername enthält ein Leerzeichen am Anfang oder Ende. '
                . 'Beim Kopieren rutscht das leicht mit hinein.';
        }
        if ($password !== trim($password)) {
            $warnings[] = 'Das Passwort enthält ein Leerzeichen am Anfang oder Ende. '
                . 'Beim Kopieren rutscht das leicht mit hinein.';
        }
        if (str_contains($username, '@')) {
            $warnings[] = 'Der Benutzername sieht nach einer E-Mail-Adresse aus. '
                . 'Für die Schnittstelle wird häufig ein eigener Benutzername verwendet, nicht die E-Mail-Adresse.';
        }

        return $warnings;
    }

    /**
     * Speichert die geprüften Zugangsdaten verschlüsselt und markiert die
     * Verbindung als aktiv.
     */
    public static function connect(
        int $dealershipId,
        string $username,
        string $password,
        string $customerId,
        ?string $sellId = null,
        ?int $userId = null
    ): void {
        self::storeConnection($dealershipId, [
            'mode'        => self::MODE_OWN,
            'username'    => $username,
            'password'    => $password,
            'customer_id' => $customerId,
            'sell_id'     => $sellId,
        ], $customerId, $sellId, $userId);
    }

    /**
     * Legt die Verbindungsdaten verschlüsselt ab und markiert die Verbindung.
     *
     * @param array<string, mixed> $payload
     */
    private static function storeConnection(
        int $dealershipId,
        array $payload,
        string $customerId,
        ?string $sellId,
        ?int $userId
    ): void {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Zugangsdaten konnten nicht verarbeitet werden.');
        }

        $now = Database::now();
        $encrypted = Encryption::encrypt($encoded);

        $existingToken = Database::fetch(
            'SELECT id FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        $tokenData = ['access_token' => $encrypted, 'refresh_token' => null, 'expires_at' => null, 'updated_at' => $now];

        if ($existingToken !== null) {
            Database::update('integration_tokens', (int) $existingToken['id'], $tokenData);
        } else {
            Database::insert('integration_tokens', $tokenData + [
                'dealership_id' => $dealershipId,
                'provider'      => self::PROVIDER,
                'created_at'    => $now,
            ]);
        }

        // Anzeigename: Kundennummer, niemals ein Benutzername mit Passwortbezug
        $accountName = 'Kunde ' . $customerId . ($sellId !== null && $sellId !== '' ? ' (Sell-ID ' . $sellId . ')' : '');
        self::markConnected($dealershipId, $accountName);

        ActivityLogger::log(
            $userId,
            'integration.autoscout_connected',
            'AutoScout24-Verbindung hergestellt (' . $accountName . ', '
                . ($payload['mode'] === self::MODE_PLATFORM ? 'Plattform-Zugang' : 'eigener Zugang') . ')',
            'integration',
            null,
            $dealershipId
        );
    }

    /**
     * Wirksame Zugangsdaten eines Autohauses.
     *
     * Im Plattform-Modus stammen Benutzername und Passwort aus der
     * Server-Konfiguration; gespeichert ist beim Autohaus nur die Kundennummer.
     *
     * @return array{username: string, password: string, customer_id: string, sell_id: ?string, mode: string}|null
     */
    public static function credentials(int $dealershipId): ?array
    {
        $stored = self::storedConnection($dealershipId);
        if ($stored === null) {
            return null;
        }

        $mode = (string) ($stored['mode'] ?? self::MODE_OWN);

        if ($mode === self::MODE_PLATFORM) {
            if (!self::hasPlatformCredentials()) {
                return null; // Plattform-Zugang wurde entfernt
            }
            return [
                'username'    => self::platformUsername(),
                'password'    => self::platformPassword(),
                'customer_id' => (string) $stored['customer_id'],
                'sell_id'     => isset($stored['sell_id']) ? (string) $stored['sell_id'] : null,
                'mode'        => self::MODE_PLATFORM,
            ];
        }

        if (!isset($stored['username'], $stored['password'])) {
            return null;
        }

        return [
            'username'    => (string) $stored['username'],
            'password'    => (string) $stored['password'],
            'customer_id' => (string) $stored['customer_id'],
            'sell_id'     => isset($stored['sell_id']) ? (string) $stored['sell_id'] : null,
            'mode'        => self::MODE_OWN,
        ];
    }

    /**
     * Rohe, entschlüsselte Verbindungsdaten.
     *
     * @return array<string, mixed>|null
     */
    private static function storedConnection(int $dealershipId): ?array
    {
        $row = Database::fetch(
            'SELECT access_token FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        if ($row === null || $row['access_token'] === null) {
            return null;
        }

        try {
            $decoded = json_decode(Encryption::decrypt((string) $row['access_token']), true);
        } catch (\Throwable) {
            return null; // Anwendungsschlüssel geändert: Verbindung muss neu hergestellt werden
        }

        if (!is_array($decoded) || !isset($decoded['customer_id'])) {
            return null;
        }
        return $decoded;
    }

    /** Verbindungsart eines Autohauses: platform, own oder null. */
    public static function connectionMode(int $dealershipId): ?string
    {
        $stored = self::storedConnection($dealershipId);
        return $stored !== null ? (string) ($stored['mode'] ?? self::MODE_OWN) : null;
    }

    public static function customerId(int $dealershipId): ?string
    {
        return self::credentials($dealershipId)['customer_id'] ?? null;
    }

    /** Ist eine Verbindung hinterlegt? */
    public static function isConnected(int $dealershipId): bool
    {
        return self::credentials($dealershipId) !== null
            && self::status($dealershipId) === 'connected';
    }

    /**
     * Verbindungsstatus: 'connected' | 'disconnected' | 'error'
     * Anders als bei OAuth-Kanälen gibt es kein 'not_configured':
     * jeder Händler verbindet sein eigenes AutoScout24-Konto.
     */
    public static function status(int $dealershipId): string
    {
        $row = self::integrationRow($dealershipId);
        if ($row === null) {
            return 'disconnected';
        }
        return (string) $row['status'];
    }

    /** @return array<string, mixed>|null */
    public static function integrationRow(int $dealershipId): ?array
    {
        return Database::fetch(
            'SELECT * FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
    }

    public static function markConnected(int $dealershipId, ?string $accountName): void
    {
        $now = Database::now();
        $existing = self::integrationRow($dealershipId);
        $data = [
            'status'       => 'connected',
            'account_name' => $accountName,
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
        $existing = self::integrationRow($dealershipId);
        if ($existing !== null) {
            Database::update('integrations', (int) $existing['id'], [
                'status'     => 'error',
                'updated_at' => Database::now(),
            ]);
        }
    }

    public static function touchSync(int $dealershipId): void
    {
        $existing = self::integrationRow($dealershipId);
        if ($existing !== null) {
            Database::update('integrations', (int) $existing['id'], [
                'last_sync_at' => Database::now(),
                'updated_at'   => Database::now(),
            ]);
        }
    }

    public static function disconnect(int $dealershipId, ?int $userId = null): void
    {
        Database::run(
            'DELETE FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        $existing = self::integrationRow($dealershipId);
        if ($existing !== null) {
            Database::update('integrations', (int) $existing['id'], [
                'status'       => 'disconnected',
                'account_name' => null,
                'updated_at'   => Database::now(),
            ]);
        }
        ActivityLogger::log($userId, 'integration.autoscout_disconnected', 'AutoScout24-Verbindung getrennt', 'integration', null, $dealershipId);
    }

    /**
     * Verbindungstest gegen die echte API.
     *
     * @return array{ok: bool, message: string, customers: int}
     */
    public static function testConnection(int $dealershipId): array
    {
        $credentials = self::credentials($dealershipId);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'Es ist keine Verbindung hinterlegt.', 'customers' => 0];
        }

        try {
            $customers = self::verifyCredentials($credentials['username'], $credentials['password']);
            $found = false;
            foreach ($customers as $customer) {
                if ($customer['id'] === $credentials['customer_id']) {
                    $found = true;
                    break;
                }
            }
            self::markConnected($dealershipId, self::integrationRow($dealershipId)['account_name'] ?? null);

            if (!$found) {
                return [
                    'ok'        => false,
                    'message'   => 'Die Zugangsdaten sind gültig, aber die gespeicherte Kundennummer ist nicht mehr verfügbar. Bitte neu verbinden.',
                    'customers' => count($customers),
                ];
            }
            return [
                'ok'        => true,
                'message'   => 'Verbindung aktiv. Kundennummer ' . $credentials['customer_id'] . ' ist erreichbar.',
                'customers' => count($customers),
            ];
        } catch (RuntimeException $e) {
            self::markError($dealershipId);
            return ['ok' => false, 'message' => $e->getMessage(), 'customers' => 0];
        }
    }
}
