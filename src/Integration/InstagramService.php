<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;

/**
 * Instagram-Integrationsschicht (§39).
 * Vorbereitet für die Meta/Instagram-API — sämtliche Endpunkte und Credentials
 * kommen aus der Konfiguration. Ohne Konfiguration: Status „Nicht konfiguriert" (§72).
 */
final class InstagramService
{
    public const PROVIDER = 'instagram';

    public static function client(): OAuth2Client
    {
        // Gleiche Quelle wie alle Kanäle: was der Betreiber im Admin-Bereich
        // hinterlegt, gilt genauso wie ein Eintrag in der Konfigurationsdatei.
        // Der Händler selbst muss nichts eintragen, er verbindet nur sein Konto.
        return ChannelRegistry::client(self::PROVIDER);
    }

    public static function isConfigured(): bool
    {
        return self::client()->isConfigured();
    }

    /** 'not_configured' | 'disconnected' | 'connected' */
    public static function status(int $dealershipId): string
    {
        if (!self::isConfigured()) {
            return 'not_configured';
        }
        $row = Database::fetch(
            'SELECT status FROM integrations WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => self::PROVIDER]
        );
        return $row !== null ? (string) $row['status'] : 'disconnected';
    }

    /**
     * Nach dem Verbinden: das kurzlebige Token gegen ein langlebiges tauschen
     * und den Kontonamen merken.
     *
     * Ohne diesen Schritt waere die Verbindung nach etwa einer Stunde tot,
     * die Oberflaeche wuerde aber weiter "verbunden" anzeigen.
     */
    public static function completeConnection(int $dealershipId): void
    {
        $tokens = TokenStore::get($dealershipId, self::PROVIDER);
        $shortLived = (string) ($tokens['access_token'] ?? '');
        if ($shortLived === '') {
            return;
        }

        $secret = ChannelCredentials::value(self::PROVIDER, 'client_secret');
        $accessToken = $shortLived;
        $expiresIn = null;

        if ($secret !== '') {
            try {
                $exchanged = self::request('GET', self::apiBase() . '/access_token', [
                    'grant_type'   => 'ig_exchange_token',
                    'client_secret' => $secret,
                    'access_token' => $shortLived,
                ]);
                if ((string) ($exchanged['access_token'] ?? '') !== '') {
                    $accessToken = (string) $exchanged['access_token'];
                    $expiresIn = isset($exchanged['expires_in']) ? (int) $exchanged['expires_in'] : null;
                    TokenStore::save($dealershipId, self::PROVIDER, $accessToken, null, $expiresIn);
                }
            } catch (\Throwable $e) {
                // Ohne Tausch bleibt das kurzlebige Token; der Fehler steht im Protokoll.
                \App\Core\Logger::warning('Instagram: Tausch in ein langlebiges Token fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // Konto ermitteln und merken, damit der Haendler sieht, was verbunden ist
        try {
            $me = self::request('GET', self::apiBase() . '/me', [
                'fields'       => 'user_id,username',
                'access_token' => $accessToken,
            ]);
            $username = (string) ($me['username'] ?? '');
            $userId = (string) ($me['user_id'] ?? ($me['id'] ?? ''));
            if ($userId !== '') {
                Database::run(
                    'UPDATE integrations SET account_name = :n, external_id = :e, updated_at = :t
                     WHERE dealership_id = :d AND provider = :p',
                    [
                        'n' => $username !== '' ? '@' . $username : null,
                        'e' => $userId,
                        't' => Database::now(),
                        'd' => $dealershipId,
                        'p' => self::PROVIDER,
                    ]
                );
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Instagram: Konto konnte nicht ermittelt werden: ' . $e->getMessage());
        }
    }

    /**
     * Erneuert das Token, wenn es bald ablaeuft. Ein langlebiges Token gilt
     * 60 Tage und laesst sich jederzeit verlaengern, solange es gueltig ist.
     */
    public static function refreshIfNeeded(int $dealershipId): void
    {
        $tokens = TokenStore::get($dealershipId, self::PROVIDER);
        $accessToken = (string) ($tokens['access_token'] ?? '');
        $expiresAt = (string) ($tokens['expires_at'] ?? '');
        if ($accessToken === '' || $expiresAt === '') {
            return;
        }
        // Ab sieben Tagen vor Ablauf erneuern
        if (strtotime($expiresAt) > time() + 7 * 86400) {
            return;
        }
        try {
            $refreshed = self::request('GET', self::apiBase() . '/refresh_access_token', [
                'grant_type'   => 'ig_refresh_token',
                'access_token' => $accessToken,
            ]);
            if ((string) ($refreshed['access_token'] ?? '') !== '') {
                TokenStore::save(
                    $dealershipId,
                    self::PROVIDER,
                    (string) $refreshed['access_token'],
                    null,
                    isset($refreshed['expires_in']) ? (int) $refreshed['expires_in'] : null
                );
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Instagram: Token konnte nicht erneuert werden: ' . $e->getMessage());
        }
    }

    /** Basisadresse der Instagram-Schnittstelle. */
    private static function apiBase(): string
    {
        $api = rtrim(ChannelCredentials::value(self::PROVIDER, 'api_url'), '/');
        return $api !== '' ? $api : 'https://graph.instagram.com';
    }

    /**
     * Testbetrieb: Das Autohaus hat den Testkanal verbunden und Instagram
     * nicht. Dann lässt sich das Veröffentlichen durchspielen, ohne dass
     * etwas bei Instagram landet. Der Post wird als Testveröffentlichung
     * gekennzeichnet.
     */
    public static function isTestMode(int $dealershipId): bool
    {
        if (self::status($dealershipId) === 'connected') {
            return false;   // echte Verbindung geht immer vor
        }
        if (!ChannelRegistry::testChannelEnabled()) {
            return false;
        }
        return ChannelRegistry::status($dealershipId, ChannelRegistry::TEST_PROVIDER) === 'connected';
    }

    /**
     * Veröffentlicht einen gespeicherten Post über die Meta-Graph-API.
     *
     * Ablauf: verknüpftes Instagram-Business-Konto ermitteln, Medien-Container
     * mit Bild-URL und Text anlegen, Container veröffentlichen. Das Bild muss
     * dafür öffentlich erreichbar sein (auf dem Webserver der Fall; auf
     * localhost lehnt Meta die URL ab und die Meldung sagt das ehrlich).
     *
     * @return string Meta-Medien-ID
     */
    public static function publish(int $dealershipId, int $socialPostId): string
    {
        @set_time_limit(180);

        $post = Database::fetch(
            'SELECT * FROM social_posts WHERE id = :id AND dealership_id = :did',
            ['id' => $socialPostId, 'did' => $dealershipId]
        );
        if ($post === null) {
            throw new \RuntimeException('Der Post wurde nicht gefunden.');
        }

        // Testbetrieb: nichts geht an Instagram, der Post wird nur hier
        // als veröffentlicht geführt und ist als Test gekennzeichnet.
        if (self::isTestMode($dealershipId)) {
            $now = Database::now();
            $mediaId = 'TEST-' . str_pad((string) $socialPostId, 6, '0', STR_PAD_LEFT);
            Database::update('social_posts', $socialPostId, [
                'status'       => 'published',
                'platform'     => 'instagram_test',
                'published_at' => $now,
                'updated_at'   => $now,
            ]);
            return $mediaId;
        }
        if ((string) ($post['image_path'] ?? '') === '') {
            throw new \RuntimeException('Der Post hat kein Bild.');
        }

        // Laeuft das Token bald ab, wird es zuerst erneuert.
        self::refreshIfNeeded($dealershipId);

        $tokens = TokenStore::get($dealershipId, self::PROVIDER);
        if ($tokens === null || (string) ($tokens['access_token'] ?? '') === '') {
            throw new \RuntimeException('Instagram ist nicht verbunden. Bitte zuerst unter Kanäle verbinden.');
        }
        $accessToken = (string) $tokens['access_token'];
        $api = self::apiBase();

        // Nummer des Instagram-Kontos: beim Verbinden gemerkt, sonst jetzt
        // nachholen.
        $igUserId = (string) (Database::scalar(
            'SELECT external_id FROM integrations WHERE dealership_id = :d AND provider = :p',
            ['d' => $dealershipId, 'p' => self::PROVIDER]
        ) ?? '');
        if ($igUserId === '') {
            $me = self::request('GET', $api . '/me', [
                'fields'       => 'user_id,username',
                'access_token' => $accessToken,
            ]);
            $igUserId = (string) ($me['user_id'] ?? ($me['id'] ?? ''));
        }
        if ($igUserId === '') {
            throw new \RuntimeException(
                'Das verbundene Konto liefert keine Instagram-Kennung. '
                . 'Bitte die Verbindung unter Kanäle trennen und neu herstellen.'
            );
        }

        // Instagram laedt das Bild selbst herunter: die Adresse muss von
        // aussen erreichbar sein, und nur JPEG wird angenommen.
        $imagePath = ltrim((string) $post['image_path'], '/');
        $imageUrl = base_url('uploads/' . $imagePath);
        if (!str_ends_with(strtolower($imagePath), '.jpg') && !str_ends_with(strtolower($imagePath), '.jpeg')) {
            throw new \RuntimeException(
                'Instagram nimmt nur JPEG an. Bitte den Beitrag neu speichern, '
                . 'damit das Bild im richtigen Format abgelegt wird.'
            );
        }

        $container = self::request('POST', $api . '/' . $igUserId . '/media', [
            'image_url'    => $imageUrl,
            'caption'      => (string) ($post['caption'] ?? ''),
            'access_token' => $accessToken,
        ]);
        $creationId = (string) ($container['id'] ?? '');
        if ($creationId === '') {
            throw new \RuntimeException('Instagram hat keinen Medien-Container angelegt.');
        }

        // Warten, bis Instagram das Bild geholt und verarbeitet hat. Ohne das
        // scheitert das Veroeffentlichen mit einer unverstaendlichen Meldung.
        self::awaitContainer($api, $creationId, $accessToken);

        $published = self::request('POST', $api . '/' . $igUserId . '/media_publish', [
            'creation_id'  => $creationId,
            'access_token' => $accessToken,
        ]);
        $mediaId = (string) ($published['id'] ?? '');
        if ($mediaId === '') {
            throw new \RuntimeException('Die Veröffentlichung wurde von Meta nicht bestätigt.');
        }

        Database::update('social_posts', $socialPostId, [
            'status'       => 'published',
            'published_at' => Database::now(),
            'updated_at'   => Database::now(),
        ]);

        return $mediaId;
    }

    /**
     * Wartet, bis der Medien-Container fertig ist. Instagram holt das Bild
     * selbst ab; das dauert je nach Groesse einige Sekunden.
     */
    private static function awaitContainer(string $api, string $creationId, string $accessToken): void
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $state = self::request('GET', $api . '/' . $creationId, [
                'fields'       => 'status_code,status',
                'access_token' => $accessToken,
            ]);
            $code = (string) ($state['status_code'] ?? '');

            if ($code === 'FINISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new \RuntimeException(
                    'Instagram konnte das Bild nicht verarbeiten'
                    . ((string) ($state['status'] ?? '') !== '' ? ': ' . (string) $state['status'] : '.')
                );
            }
            sleep(3);
        }
        throw new \RuntimeException(
            'Instagram hat das Bild nicht rechtzeitig verarbeitet. Bitte in einigen Minuten erneut versuchen.'
        );
    }

    /** @return array<string, mixed> */
    private static function request(string $method, string $url, array $params): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Die PHP-Erweiterung cURL wird für Instagram benötigt.');
        }
        $ch = curl_init($method === 'GET' ? $url . '?' . http_build_query($params) : $url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($params);
        }
        curl_setopt_array($ch, \App\Core\CaBundle::applyTo($options));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            \App\Core\Logger::error('Meta nicht erreichbar: ' . $curlError);
            throw new \RuntimeException('Instagram ist gerade nicht erreichbar.');
        }
        $decoded = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            \App\Core\Logger::error('Meta-Fehler', ['status' => $status]);
            throw new \RuntimeException(
                'Instagram hat die Anfrage abgelehnt'
                . ($message !== '' ? ': ' . $message : ' (HTTP ' . $status . ').')
            );
        }
        return $decoded;
    }
}
