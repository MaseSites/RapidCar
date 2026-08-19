<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Config;
use App\Core\Encryption;
use App\Core\Logger;
use App\Service\SettingsService;

/**
 * Zugangsdaten der Kanäle (OAuth-Client je Plattform).
 *
 * Sie lassen sich im Admin-Bereich hinterlegen, damit für eine neue Plattform
 * keine Datei mehr angefasst werden muss. Gespeichert wird verschlüsselt in
 * der Tabelle settings; die Konfigurationsdatei hat weiterhin Vorrang, damit
 * bestehende Installationen unverändert weiterlaufen.
 *
 * Das Geheimnis wird nie zurückgegeben oder angezeigt (§50/§55), nur ob es
 * hinterlegt ist.
 */
final class ChannelCredentials
{
    public const FIELDS = ['client_id', 'client_secret', 'redirect_uri', 'auth_url', 'token_url', 'api_url', 'scopes'];

    /** Felder, die nie an die Oberfläche zurückgehen. */
    public const SECRET_FIELDS = ['client_secret'];

    private static function settingKey(string $channel): string
    {
        return 'channel_credentials.' . $channel;
    }

    /**
     * Gespeicherte Werte eines Kanals.
     *
     * @return array<string, string>
     */
    public static function stored(string $channel): array
    {
        $raw = (string) SettingsService::get(self::settingKey($channel), '');
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode(Encryption::decrypt($raw), true);
        } catch (\Throwable $e) {
            Logger::warning('Kanal-Zugangsdaten konnten nicht entschlüsselt werden: ' . $channel);
            return [];
        }
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /**
     * Wert eines Feldes: Konfigurationsdatei zuerst, dann Datenbank.
     */
    public static function value(string $channel, string $field): string
    {
        $prefix = match ($channel) {
            'autoscout24' => 'autoscout',
            'instagram'   => 'instagram',
            default       => 'channels.' . $channel,
        };
        $fromConfig = (string) Config::get($prefix . '.' . $field, '');
        if ($fromConfig !== '') {
            return $fromConfig;
        }
        $stored = self::stored($channel)[$field] ?? '';
        if ($stored !== '') {
            return $stored;
        }

        // Die Rücksprungadresse ergibt sich aus der eigenen Domain. So muss der
        // Betreiber nur Kennung und Geheimnis der Plattform-App eintragen.
        if ($field === 'redirect_uri') {
            return base_url('api/channels/callback.php?channel=' . $channel);
        }
        return '';
    }

    /**
     * Speichert die Zugangsdaten. Leere Geheimnisfelder lassen den bisherigen
     * Wert stehen, damit ein Formular ohne angezeigtes Geheimnis funktioniert.
     *
     * @param array<string, string> $values
     */
    public static function save(string $channel, array $values): void
    {
        $current = self::stored($channel);
        $next = [];
        foreach (self::FIELDS as $field) {
            $incoming = trim((string) ($values[$field] ?? ''));
            if ($incoming === '' && in_array($field, self::SECRET_FIELDS, true)) {
                $incoming = $current[$field] ?? '';
            }
            if ($incoming !== '') {
                $next[$field] = $incoming;
            }
        }

        $payload = $next === [] ? '' : Encryption::encrypt(json_encode($next, JSON_UNESCAPED_SLASHES));
        SettingsService::set(self::settingKey($channel), $payload);
    }

    /** Ist ein Geheimnis hinterlegt, ohne es preiszugeben? */
    public static function hasSecret(string $channel): bool
    {
        return self::value($channel, 'client_secret') !== '';
    }
}
