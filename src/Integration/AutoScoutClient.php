<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\CaBundle;
use App\Core\Config;
use App\Core\Logger;
use RuntimeException;

/**
 * HTTP-Client für die AutoScout24 Listing-Creation-API.
 *
 * Authentifizierung: HTTP Basic Auth mit den Zugangsdaten des Händlerkontos
 * (siehe AutoScout24-Dokumentation). Es gibt keinen OAuth-Fluss und keine
 * plattformweiten Client-Credentials: Jeder Händler verbindet sein eigenes Konto.
 */
final class AutoScoutClient
{
    public const DEFAULT_BASE_URL = 'https://listing-creation.api.autoscout24.com';

    private const TIMEOUT_SECONDS = 30;

    public static function baseUrl(): string
    {
        $configured = trim((string) Config::get('autoscout.api_url', ''));
        return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Anfrage mit explizit übergebenen Zugangsdaten (z.B. zur Prüfung vor dem Speichern).
     *
     * @param array<string, mixed>|null $jsonBody
     * @return array{status: int, data: mixed, raw: string}
     */
    public static function requestWith(
        string $username,
        string $password,
        string $method,
        string $path,
        ?array $jsonBody = null,
        ?string $binaryBody = null,
        ?string $contentType = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für die AutoScout24-Anbindung benötigt.');
        }

        $url = self::baseUrl() . '/' . ltrim($path, '/');
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $username . ':' . $password,
        ];
        // Zertifikatsprüfung bleibt aktiv; die Zertifikatsliste wird bei Bedarf
        // ausdrücklich mitgegeben (siehe CaBundle).
        $options = CaBundle::applyTo($options);

        if ($binaryBody !== null) {
            $headers[] = 'Content-Type: ' . ($contentType ?? 'application/octet-stream');
            $options[CURLOPT_POSTFIELDS] = $binaryBody;
        } elseif ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('AutoScout24 nicht erreichbar', ['path' => $path, 'error' => $curlError]);
            throw new RuntimeException(self::describeTransportError($curlError));
        }

        $raw = (string) $response;
        $data = json_decode($raw, true);

        if ($status === 401 || $status === 403) {
            throw new AutoScoutAuthException(
                $status === 403
                    ? 'AutoScout24 kennt das Konto, erlaubt aber keinen Zugriff auf die Schnittstelle (HTTP 403). '
                        . 'Vermutlich ist der Schnittstellen-Zugang für dieses Konto nicht freigeschaltet.'
                    : 'AutoScout24 hat Benutzername oder Passwort nicht akzeptiert (HTTP 401).',
                $status
            );
        }
        if ($status >= 400) {
            Logger::error('AutoScout24-API-Fehler', ['path' => $path, 'status' => $status]);
            throw new RuntimeException(self::describeError($status, $data, $raw));
        }

        return ['status' => $status, 'data' => $data, 'raw' => $raw];
    }

    /**
     * Anfrage mit den gespeicherten Zugangsdaten eines Autohauses.
     *
     * @param array<string, mixed>|null $jsonBody
     * @return array{status: int, data: mixed, raw: string}
     */
    public static function request(
        int $dealershipId,
        string $method,
        string $path,
        ?array $jsonBody = null,
        ?string $binaryBody = null,
        ?string $contentType = null
    ): array {
        $credentials = AutoScoutService::credentials($dealershipId);
        if ($credentials === null) {
            throw new RuntimeException('Es ist keine AutoScout24-Verbindung hinterlegt.');
        }
        return self::requestWith(
            $credentials['username'],
            $credentials['password'],
            $method,
            $path,
            $jsonBody,
            $binaryBody,
            $contentType
        );
    }

    /**
     * Übersetzt Verbindungsfehler in eine Meldung, die sagt, was zu tun ist.
     * Der häufigste Fall auf frisch installierten Servern ist eine fehlende
     * Zertifikatsliste.
     */
    private static function describeTransportError(string $curlError): string
    {
        $lower = mb_strtolower($curlError);
        $isCertificateProblem = str_contains($lower, 'certificate')
            || str_contains($lower, 'ssl')
            || str_contains($lower, 'cafile');

        if ($isCertificateProblem) {
            return 'Die gesicherte Verbindung zu AutoScout24 konnte nicht geprüft werden. '
                . CaBundle::troubleshootingHint()
                . ' (Technische Meldung: ' . $curlError . ')';
        }

        return 'AutoScout24 ist nicht erreichbar: ' . $curlError;
    }

    /** Baut aus der API-Antwort eine lesbare Fehlermeldung. */
    private static function describeError(int $status, mixed $data, string $raw): string
    {
        $details = [];

        if (is_array($data)) {
            foreach (['message', 'title', 'detail', 'error'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $details[] = $data[$key];
                }
            }
            // Validierungsfehler pro Feld
            if (isset($data['errors']) && is_array($data['errors'])) {
                foreach ($data['errors'] as $field => $messages) {
                    $text = is_array($messages) ? implode(', ', array_map('strval', $messages)) : (string) $messages;
                    $details[] = (is_string($field) ? $field . ': ' : '') . $text;
                }
            }
        }

        if ($details === []) {
            $details[] = mb_substr(trim($raw), 0, 300);
        }

        return 'AutoScout24 hat die Anfrage abgelehnt (HTTP ' . $status . '): ' . implode(' | ', array_filter($details));
    }
}
