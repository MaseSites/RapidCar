<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\CaBundle;
use App\Core\Logger;
use RuntimeException;

/**
 * mobile.de Seller-API: HTTP-Grundgeruest.
 *
 * Die API arbeitet mit HTTP Basic Auth (Zugangsdaten kommen von mobile.de
 * nach Freischaltung des API-Benutzers) und eigenen Medientypen.
 * Dokumentation: https://services.mobile.de/docs/seller-api.html
 */
final class MobileDeClient
{
    public const MEDIA_TYPE = 'application/vnd.de.mobile.api+json';

    private const TIMEOUT_SECONDS = 45;

    public static function baseUrl(): string
    {
        $configured = trim((string) \App\Core\Config::get('channels.mobile_de.api_url', ''));
        return $configured !== '' ? rtrim($configured, '/') : 'https://services.mobile.de';
    }

    /**
     * JSON-Anfrage mit Basic Auth.
     *
     * @param array<string, mixed>|null $json
     * @return array{status: int, data: array<string, mixed>|null, location: string}
     */
    public static function request(
        string $method,
        string $path,
        string $username,
        string $password,
        ?array $json = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für mobile.de benötigt.');
        }

        $headers = ['Accept: ' . self::MEDIA_TYPE];
        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERPWD        => $username . ':' . $password,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: ' . self::MEDIA_TYPE;
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        return self::execute(self::baseUrl() . $path, $options);
    }

    /**
     * Laedt ein Bild hoch und liefert die Referenz-Adresse fuer das Inserat.
     */
    public static function uploadImage(string $username, string $password, string $absolutePath): string
    {
        $binary = @file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Bild konnte nicht gelesen werden.');
        }
        $mime = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';

        $result = self::execute(self::baseUrl() . '/seller-api/images', [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERPWD        => $username . ':' . $password,
            CURLOPT_POSTFIELDS     => $binary,
            CURLOPT_HTTPHEADER     => ['Content-Type: ' . $mime, 'Accept: ' . self::MEDIA_TYPE],
        ]);

        if ($result['status'] >= 400) {
            throw new RuntimeException('mobile.de hat das Bild abgelehnt (HTTP ' . $result['status'] . ').');
        }
        // Die Referenz steht als ref im Koerper oder als Location-Kopfzeile
        $ref = (string) ($result['data']['ref'] ?? ($result['data']['url'] ?? $result['location']));
        if ($ref === '') {
            throw new RuntimeException('mobile.de hat keine Bildreferenz geliefert.');
        }
        return $ref;
    }

    /**
     * @param array<int, mixed> $options
     * @return array{status: int, data: array<string, mixed>|null, location: string}
     */
    private static function execute(string $url, array $options): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, CaBundle::applyTo($options));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('mobile.de nicht erreichbar: ' . $curlError);
            throw new RuntimeException('mobile.de ist nicht erreichbar.');
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(\S+)/mi', $rawHeaders, $m)) {
            $location = trim($m[1]);
        }

        $decoded = json_decode($body, true);
        return [
            'status'   => $status,
            'data'     => is_array($decoded) ? $decoded : null,
            'location' => $location,
        ];
    }
}
