<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Config;
use App\Core\Database;

/**
 * Zentrale Übersicht aller Verkaufs- und Social-Kanäle.
 *
 * Jeder Kanal ist vollständig konfigurationsgetrieben: Ohne hinterlegte
 * Zugangsdaten meldet er ehrlich "Nicht konfiguriert". Es werden keine
 * Endpunkte erfunden und keine Verbindungen vorgetäuscht.
 *
 * Ein neuer Kanal wird hier eingetragen und in der Konfiguration unter
 * dem gleichen Schlüssel mit Zugangsdaten versehen.
 */
final class ChannelRegistry
{
    public const TYPE_MARKETPLACE = 'marketplace';
    public const TYPE_SOCIAL      = 'social';

    /**
     * @return array<string, array{name: string, type: string, icon: string, region: string, note: string}>
     */
    /** Schluessel des Testkanals. */
    public const TEST_PROVIDER = 'testchannel';

    /**
     * Ist der Testkanal freigeschaltet? Er wird nur fuer Testkonten
     * eingeschaltet und sendet nichts an eine echte Plattform.
     */
    public static function testChannelEnabled(): bool
    {
        return \App\Service\SettingsService::get('testchannel_enabled', '0') === '1';
    }

    public static function all(): array
    {
        $channels = [
            // ------------------------------------------------ Verkaufsplattformen
            'autoscout24' => [
                'name'   => 'AutoScout24',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'car',
                'region' => 'CH, DE, AT',
                'note'   => 'Händlerkonto erforderlich. Die API wird über einen Partnervertrag freigeschaltet.',
            ],
            'mobile_de' => [
                'name'   => 'mobile.de',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'car',
                'region' => 'DE',
                'note'   => 'Händlerkonto und API-Zugang von mobile.de erforderlich.',
            ],
            'car4you' => [
                'name'   => 'car4you',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'car',
                'region' => 'CH',
                'note'   => 'Händlerkonto erforderlich.',
            ],
            'autolina' => [
                'name'   => 'Autolina',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'car',
                'region' => 'CH',
                'note'   => 'Händlerkonto erforderlich.',
            ],
            'tutti' => [
                'name'   => 'tutti.ch',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'tag',
                'region' => 'CH',
                'note'   => 'Gewerbliches Konto erforderlich.',
            ],
            'ricardo' => [
                'name'   => 'Ricardo',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'tag',
                'region' => 'CH',
                'note'   => 'Gewerbliches Konto erforderlich.',
            ],
            'kleinanzeigen' => [
                'name'   => 'Kleinanzeigen',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'tag',
                'region' => 'DE',
                'note'   => 'Gewerbliches Konto erforderlich.',
            ],
            'facebook_marketplace' => [
                'name'   => 'Facebook Marketplace',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'share',
                'region' => 'International',
                'note'   => 'Meta Business-Konto erforderlich.',
            ],

            // ------------------------------------------------ Soziale Netzwerke
            'instagram' => [
                'name'   => 'Instagram',
                'type'   => self::TYPE_SOCIAL,
                'icon'   => 'instagram',
                'region' => 'International',
                'note'   => 'Instagram Business-Konto und Meta-App erforderlich.',
            ],
        ];

        // Der Testkanal steht nur Testkonten offen. Er nimmt Inserate
        // entgegen, damit sich der Ablauf durchspielen laesst, und sendet
        // dabei nichts an eine echte Plattform.
        if (self::testChannelEnabled()) {
            $channels[self::TEST_PROVIDER] = [
                'name'   => 'Testkanal',
                'type'   => self::TYPE_MARKETPLACE,
                'icon'   => 'shield',
                'region' => 'Test',
                'note'   => 'Nur zum Ausprobieren. Inserate bleiben hier und gehen an keine echte Plattform.',
            ];
        }

        return $channels;
    }

    /** @return array<string, array<string, string>> */
    public static function byType(string $type): array
    {
        return array_filter(self::all(), static fn(array $c): bool => $c['type'] === $type);
    }

    /**
     * Ländercodes, für die ein Kanal in Frage kommt.
     * Leere Liste bedeutet: überall verfügbar (soziale Netzwerke).
     *
     * @return array<int, string>
     */
    public static function countries(string $key): array
    {
        $region = (string) (self::get($key)['region'] ?? '');
        if ($region === '' || stripos($region, 'International') !== false) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $region))));
    }

    /**
     * Kanäle, die im angegebenen Land überhaupt nutzbar sind.
     *
     * Ein Schweizer Händler soll mobile.de gar nicht erst angeboten bekommen:
     * Das Konto liesse sich dort nicht sinnvoll nutzen und die Liste bleibt
     * kurz und übersichtlich.
     *
     * @return array<string, array<string, string>>
     */
    public static function forCountry(?string $country): array
    {
        $country = strtoupper(trim((string) $country));
        if ($country === '') {
            return self::all();
        }
        return array_filter(
            self::all(),
            static function (array $channel, string $key) use ($country): bool {
                $countries = self::countries($key);
                return $countries === [] || in_array($country, $countries, true);
            },
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** Kanäle, die es nur ausserhalb des eigenen Landes gibt. */
    public static function outsideCountry(?string $country): array
    {
        $inside = self::forCountry($country);
        return array_diff_key(self::all(), $inside);
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string, string>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * OAuth-Client eines Kanals aus der Konfiguration.
     * Konfigurationsschlüssel: channels.<key>.client_id usw.
     */
    public static function client(string $key): OAuth2Client
    {
        // Werte kommen aus der Konfigurationsdatei oder, wenn dort nichts
        // steht, aus den im Admin hinterlegten Zugangsdaten.
        return new OAuth2Client(
            ChannelCredentials::value($key, 'client_id'),
            ChannelCredentials::value($key, 'client_secret'),
            ChannelCredentials::value($key, 'redirect_uri'),
            ChannelCredentials::value($key, 'auth_url'),
            ChannelCredentials::value($key, 'token_url'),
            ChannelCredentials::value($key, 'scopes')
        );
    }

    /**
     * Kanäle, die der Händler selbst mit eigenen Zugangsdaten verbindet und
     * für die daher keine plattformweite Konfiguration nötig ist.
     */
    public const SELF_SERVICE = ['autoscout24', 'mobile_de'];

    /** Eigene Verbindungsseite statt generischem OAuth-Redirect. */
    public const CONNECT_PAGES = [
        'autoscout24' => 'dashboard/autoscout.php',
        'mobile_de'   => 'dashboard/mobilede.php',
    ];

    public static function isConfigured(string $key): bool
    {
        // AutoScout24 nutzt HTTP Basic Auth mit den Zugangsdaten des Händlers:
        // Es sind keine plattformweiten Client-Credentials erforderlich.
        if (in_array($key, self::SELF_SERVICE, true) || $key === self::TEST_PROVIDER) {
            return true;
        }
        return self::client($key)->isConfigured();
    }

    /**
     * Verbindungsstatus eines Kanals für ein Autohaus.
     * 'not_configured' | 'disconnected' | 'connected' | 'error'
     */
    public static function status(int $dealershipId, string $key): string
    {
        if ($key === AutoScoutService::PROVIDER) {
            return AutoScoutService::status($dealershipId);
        }
        if (!self::isConfigured($key)) {
            return 'not_configured';
        }
        $row = Database::fetch(
            'SELECT status FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => $key]
        );
        return $row !== null ? (string) $row['status'] : 'disconnected';
    }

    /** Ziel-URL für den Verbinden-Knopf eines Kanals. */
    public static function connectUrl(string $key): string
    {
        if (isset(self::CONNECT_PAGES[$key])) {
            return base_url(self::CONNECT_PAGES[$key]);
        }
        return base_url('api/channels/connect.php?channel=' . urlencode($key));
    }

    /** @return array<string, mixed>|null */
    public static function integrationRow(int $dealershipId, string $key): ?array
    {
        return Database::fetch(
            'SELECT * FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => $key]
        );
    }

    /** Alle Kanäle mit ihrem aktuellen Status für ein Autohaus. */
    public static function overview(int $dealershipId): array
    {
        $result = [];
        foreach (self::all() as $key => $channel) {
            $result[$key] = $channel + [
                'key'          => $key,
                'status'       => self::status($dealershipId, $key),
                'account_name' => self::integrationRow($dealershipId, $key)['account_name'] ?? null,
            ];
        }
        return $result;
    }

    public static function disconnect(int $dealershipId, string $key): void
    {
        TokenStore::delete($dealershipId, $key);
        $row = self::integrationRow($dealershipId, $key);
        if ($row !== null) {
            Database::update('integrations', (int) $row['id'], [
                'status'       => 'disconnected',
                'account_name' => null,
                'updated_at'   => Database::now(),
            ]);
        }
    }
}
