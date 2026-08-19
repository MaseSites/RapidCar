<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\CaBundle;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use RuntimeException;

/**
 * Registrierung und Anmeldung über ein Google-Konto (OAuth 2.0 / OpenID Connect).
 *
 * Ohne hinterlegte Zugangsdaten (google.client_id / google.client_secret)
 * erscheint der Knopf gar nicht erst: nichts wird vorgetäuscht (§72).
 * Die E-Mail gilt als bestätigt, wenn Google sie als verifiziert meldet.
 */
final class GoogleAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const TIMEOUT_SECONDS = 30;

    public static function isConfigured(): bool
    {
        return trim((string) Config::get('google.client_id', '')) !== ''
            && trim((string) Config::get('google.client_secret', '')) !== '';
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) Config::get('google.redirect_uri', ''));
        return $configured !== '' ? $configured : base_url('google-callback.php');
    }

    /** Baut die Google-Anmelde-URL und legt den Zustandswert in die Session. */
    public static function authUrl(): string
    {
        $state = bin2hex(random_bytes(24));
        Session::set('google_oauth_state', $state);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => (string) Config::get('google.client_id', ''),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Verarbeitet den Rücksprung von Google: Code einlösen, Profil abrufen,
     * Konto anlegen oder anmelden.
     *
     * @return array{user: array<string, mixed>, created: bool}
     */
    public static function handleCallback(string $code, string $state): array
    {
        $expected = (string) Session::get('google_oauth_state');
        Session::remove('google_oauth_state');
        if ($expected === '' || !hash_equals($expected, $state)) {
            throw new RuntimeException('Der Anmeldevorgang ist abgelaufen. Bitte erneut versuchen.');
        }

        $token = self::post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => (string) Config::get('google.client_id', ''),
            'client_secret' => (string) Config::get('google.client_secret', ''),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google hat die Anmeldung abgelehnt.');
        }

        $profile = self::get(self::USERINFO_URL, $accessToken);
        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));
        if ($email === '') {
            throw new RuntimeException('Google hat keine E-Mail-Adresse geliefert.');
        }
        $emailVerified = (bool) filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        $existing = Database::fetch('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        if ($existing !== null) {
            if ((int) $existing['is_active'] !== 1) {
                throw new RuntimeException('Dieses Konto ist deaktiviert.');
            }
            // Google bestätigt die Adresse: offene Verifizierung gilt als erledigt
            if ($existing['email_verified_at'] === null && $emailVerified) {
                Database::update('users', (int) $existing['id'], ['email_verified_at' => Database::now()]);
                $existing['email_verified_at'] = Database::now();
            }
            return ['user' => $existing, 'created' => false];
        }

        // Neues Konto: Autohaus-Name aus dem Anzeigenamen, Passwort zufällig.
        // Anmeldung läuft künftig über Google; ein Passwort lässt sich über
        // "Passwort vergessen" jederzeit setzen.
        $firstName = trim((string) ($profile['given_name'] ?? '')) ?: 'Google';
        $lastName = trim((string) ($profile['family_name'] ?? '')) ?: 'Konto';
        $dealershipName = trim((string) ($profile['name'] ?? '')) ?: $email;

        $userId = AuthService::register(
            $firstName,
            $lastName,
            $email,
            bin2hex(random_bytes(24)),
            $dealershipName,
            '',
            'CH'
        );
        if ($emailVerified) {
            Database::update('users', $userId, ['email_verified_at' => Database::now()]);
        }

        $user = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            throw new RuntimeException('Das Konto konnte nicht angelegt werden.');
        }
        return ['user' => $user, 'created' => true];
    }

    /** @return array<string, mixed> */
    private static function post(string $url, array $fields): array
    {
        return self::request($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);
    }

    /** @return array<string, mixed> */
    private static function get(string $url, string $bearerToken): array
    {
        return self::request($url, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
        ]);
    }

    /** @return array<string, mixed> */
    private static function request(string $url, array $options): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für die Google-Anmeldung benötigt.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, CaBundle::applyTo($options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('Google nicht erreichbar: ' . $curlError);
            throw new RuntimeException('Google ist gerade nicht erreichbar.');
        }
        $decoded = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            Logger::error('Google-OAuth-Fehler', ['status' => $status]);
            throw new RuntimeException('Die Google-Anmeldung ist fehlgeschlagen (HTTP ' . $status . ').');
        }
        return $decoded;
    }
}
