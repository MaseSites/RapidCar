<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Logger;
use RuntimeException;

/**
 * Generischer OAuth2-Authorization-Code-Client.
 *
 * WICHTIG (§16, §72): Es werden KEINE Endpunkte erfunden. Sämtliche URLs,
 * Scopes und Credentials stammen aus der Konfiguration. Ohne vollständige
 * Konfiguration meldet isConfigured() false und die UI zeigt
 * „Nicht konfiguriert" an.
 */
final class OAuth2Client
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $authUrl,
        private readonly string $tokenUrl,
        private readonly string $scopes = ''
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->redirectUri !== ''
            && $this->authUrl !== ''
            && $this->tokenUrl !== '';
    }

    /** @return array<int, string> Fehlende Konfigurationswerte (für ehrliche Fehlermeldungen). */
    public function missingConfig(): array
    {
        $missing = [];
        foreach ([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'auth_url' => $this->authUrl,
            'token_url' => $this->tokenUrl,
        ] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /** Authorize-URL für die Weiterleitung des Benutzers. */
    public function authorizationUrl(string $state): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OAuth-Integration ist nicht konfiguriert.');
        }
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
        ];
        if ($this->scopes !== '') {
            $params['scope'] = $this->scopes;
        }
        return $this->authUrl . (str_contains($this->authUrl, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Tauscht den Authorization-Code gegen Tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>}
     */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>}
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Extension cURL wird für OAuth benötigt.');
        }

        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, \App\Core\CaBundle::applyTo([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('OAuth-Token-Request fehlgeschlagen', ['error' => $curlError]);
            throw new RuntimeException('Verbindung zum Token-Endpunkt fehlgeschlagen.');
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || $status >= 400 || !isset($data['access_token'])) {
            Logger::error('OAuth-Token-Antwort ungültig', ['status' => $status]);
            throw new RuntimeException('Der Token-Endpunkt hat die Anfrage abgelehnt (HTTP ' . $status . ').');
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            'raw'           => $data,
        ];
    }
}
