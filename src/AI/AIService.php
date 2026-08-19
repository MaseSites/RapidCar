<?php

declare(strict_types=1);

namespace App\AI;

use App\Service\SettingsService;

/**
 * Zentrale KI-Fassade (§27): liefert den aktiven Anbieter gemäss KI-Modus (§54).
 *
 * Live-Modus setzt einen hinterlegten Schlüssel voraus. Fehlt er, bleibt die
 * Anwendung im Demo-Modus, statt einen nicht vorhandenen Dienst vorzutäuschen.
 */
final class AIService
{
    private static ?AIProviderInterface $provider = null;

    public static function provider(): AIProviderInterface
    {
        if (self::$provider === null) {
            self::$provider = self::isLiveReady() ? new OpenAiProvider() : new MockProvider();
        }
        return self::$provider;
    }

    /** Ist der Live-Modus eingeschaltet und auch tatsächlich einsatzbereit? */
    public static function isLiveReady(): bool
    {
        return SettingsService::isAiLive() && OpenAiProvider::isConfigured();
    }

    public static function mode(): string
    {
        return self::provider()->mode();
    }

    public static function isMock(): bool
    {
        return self::mode() === 'mock';
    }

    /** Name des aktiven Modells für die Anzeige. */
    public static function activeModel(): ?string
    {
        return self::isLiveReady() ? OpenAiProvider::model() : null;
    }

    /** Nur für Tests. */
    public static function setProvider(?AIProviderInterface $provider): void
    {
        self::$provider = $provider;
    }
}
