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

        $tokens = TokenStore::get($dealershipId, self::PROVIDER);
        if ($tokens === null || (string) ($tokens['access_token'] ?? '') === '') {
            throw new \RuntimeException('Instagram ist nicht verbunden. Bitte zuerst unter Kanäle verbinden.');
        }
        $accessToken = (string) $tokens['access_token'];
        $api = rtrim(ChannelCredentials::value(self::PROVIDER, 'api_url'), '/');
        if ($api === '') {
            $api = 'https://graph.facebook.com/v21.0';
        }

        // Verknuepftes Instagram-Business-Konto der Facebook-Seite ermitteln
        $accounts = self::request('GET', $api . '/me/accounts', [
            'fields'       => 'instagram_business_account,name',
            'access_token' => $accessToken,
        ]);
        $igUserId = null;
        foreach (($accounts['data'] ?? []) as $page) {
            if (!empty($page['instagram_business_account']['id'])) {
                $igUserId = (string) $page['instagram_business_account']['id'];
                break;
            }
        }
        if ($igUserId === null) {
            throw new \RuntimeException(
                'Mit dem verbundenen Konto ist kein Instagram-Business-Konto verknüpft. '
                . 'Im Meta Business Manager die Instagram-Verknüpfung der Seite prüfen.'
            );
        }

        // Bild-URL muss oeffentlich erreichbar sein
        $imageUrl = base_url('uploads/' . ltrim((string) $post['image_path'], '/'));

        $container = self::request('POST', $api . '/' . $igUserId . '/media', [
            'image_url'    => $imageUrl,
            'caption'      => (string) ($post['caption'] ?? ''),
            'access_token' => $accessToken,
        ]);
        $creationId = (string) ($container['id'] ?? '');
        if ($creationId === '') {
            throw new \RuntimeException('Meta hat keinen Medien-Container angelegt.');
        }

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
