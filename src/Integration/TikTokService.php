<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Config;

/**
 * TikTok-Integration (vorbereitet).
 *
 * Struktur und OAuth-Fluss sind angelegt; sämtliche Endpunkte und
 * Zugangsdaten stammen aus der Konfiguration (channels.tiktok.*).
 * Ohne Konfiguration meldet der Kanal ehrlich "Nicht konfiguriert".
 */
final class TikTokService
{
    public const PROVIDER = 'tiktok';

    public static function client(): OAuth2Client
    {
        return ChannelRegistry::client(self::PROVIDER);
    }

    public static function isConfigured(): bool
    {
        return self::client()->isConfigured();
    }

    public static function status(int $dealershipId): string
    {
        return ChannelRegistry::status($dealershipId, self::PROVIDER);
    }

    /**
     * Video-Veröffentlichung. Wird mit der echten TikTok-Content-Posting-API
     * implementiert; bis dahin klare Fehlermeldung statt vorgetäuschtem Erfolg.
     */
    public static function publishVideo(int $dealershipId, string $absoluteVideoPath, string $caption): never
    {
        throw new \RuntimeException(
            'Die TikTok-Veröffentlichung ist vorbereitet, aber noch nicht mit der TikTok-API verbunden.'
        );
    }

    /** Fehlende Konfigurationswerte für ehrliche Hinweise in der Oberfläche. */
    public static function missingConfig(): array
    {
        return self::client()->missingConfig();
    }

    public static function apiUrl(): string
    {
        return rtrim((string) Config::get('channels.tiktok.api_url', ''), '/');
    }
}
