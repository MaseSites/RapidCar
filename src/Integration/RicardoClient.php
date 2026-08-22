<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\CaBundle;
use App\Core\Logger;
use RuntimeException;

/**
 * Transport zur Ricardo-Schnittstelle.
 *
 * Ricardo bietet seine Dienste als JSON-Endpunkte an, je Dienst eine eigene
 * Adresse nach dem Muster
 *
 *     https://{host}/ricardoapi/{Dienst}.Json.svc/{Methode}
 *
 * Jede Anfrage ist ein POST mit einem JSON-Rumpf. Die Antwort enthaelt das
 * Ergebnis unter einem Schluessel, der nach der Methode benannt ist.
 */
final class RicardoClient
{
    private const TIMEOUT_SECONDS = 45;

    /** Adresse der Schnittstelle. Aus der Konfiguration, sonst die uebliche. */
    public static function host(): string
    {
        $configured = trim((string) ChannelCredentials::value(RicardoService::PROVIDER, 'api_url'));
        if ($configured !== '') {
            return rtrim(preg_replace('#^https?://#', '', $configured) ?? '', '/');
        }
        return 'ws.ricardo.ch';
    }

    /**
     * Ruft eine Methode eines Dienstes auf.
     *
     * @param array<string, mixed> $params Rumpf der Anfrage
     * @return array<string, mixed> Antwort als Feld
     */
    public static function call(string $service, string $method, array $params = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für Ricardo benötigt.');
        }

        $url = 'https://' . self::host() . '/ricardoapi/' . $service . '.Json.svc/' . $method;
        $body = json_encode($params === [] ? new \stdClass() : $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, CaBundle::applyTo([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('Ricardo nicht erreichbar: ' . $curlError);
            throw new RuntimeException('Ricardo ist gerade nicht erreichbar.');
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            Logger::error('Ricardo: unlesbare Antwort', ['status' => $status]);
            throw new RuntimeException('Ricardo hat unverständlich geantwortet (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            throw new RuntimeException(self::errorText($decoded, $status));
        }

        // Ricardo verpackt das Ergebnis unter einem Schluessel, der nach der
        // Methode benannt ist. Das wird hier ausgepackt, damit die Aufrufer
        // einheitlich arbeiten koennen.
        foreach ([$method . 'Result', $method . 'ResultObject'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }
        return $decoded;
    }

    /** @param array<string, mixed> $decoded */
    private static function errorText(array $decoded, int $status): string
    {
        foreach (['ErrorMessage', 'Message', 'ExceptionMessage'] as $key) {
            if (!empty($decoded[$key])) {
                return 'Ricardo hat die Anfrage abgelehnt: ' . (string) $decoded[$key];
            }
        }
        return 'Ricardo hat die Anfrage abgelehnt (HTTP ' . $status . ').';
    }
}
