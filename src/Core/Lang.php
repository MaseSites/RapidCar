<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Übersetzungsschicht.
 *
 * Deutsch ist die Referenzsprache und dient als Fallback: fehlt ein Schlüssel
 * in der aktiven Sprache, wird der deutsche Text ausgegeben (nie ein roher
 * Schlüssel). Sprachauflösung: Session > Benutzer > Autohaus > Standard.
 */
final class Lang
{
    public const DEFAULT_LOCALE = 'de';
    public const SESSION_KEY = 'locale';

    /** Verfügbare Sprachen mit Anzeigenamen. */
    public const AVAILABLE = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'it' => 'Italiano',
    ];

    /** @var array<string, string> */
    private static array $strings = [];
    /** @var array<string, string> */
    private static array $fallback = [];
    private static string $locale = self::DEFAULT_LOCALE;
    private static bool $loaded = false;

    /** Ermittelt die aktive Sprache aus Session, Benutzer oder Autohaus. */
    public static function resolve(): void
    {
        $locale = Session::get(self::SESSION_KEY);
        if (self::isSupported($locale)) {
            self::setLocale((string) $locale);
            return;
        }

        try {
            $userId = Session::get('user_id');
            if ($userId !== null) {
                $row = Database::fetch(
                    'SELECT u.language AS user_language, d.language AS dealership_language
                     FROM users u LEFT JOIN dealerships d ON d.id = u.dealership_id
                     WHERE u.id = :id',
                    ['id' => (int) $userId]
                );
                if ($row !== null) {
                    $preferred = $row['user_language'] ?? $row['dealership_language'] ?? null;
                    if (self::isSupported($preferred)) {
                        self::setLocale((string) $preferred);
                        return;
                    }
                }
            }
        } catch (\Throwable) {
            // Datenbank noch nicht bereit: Standardsprache verwenden
        }

        self::setLocale(self::DEFAULT_LOCALE);
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && isset(self::AVAILABLE[$locale]);
    }

    public static function setLocale(string $locale): void
    {
        if (!self::isSupported($locale) || $locale === self::$locale) {
            return;
        }
        self::$locale = $locale;
        self::$loaded = false;
    }

    /** Sprache für die aktuelle Sitzung merken. */
    public static function switchTo(string $locale): bool
    {
        if (!self::isSupported($locale)) {
            return false;
        }
        Session::set(self::SESSION_KEY, $locale);
        self::setLocale($locale);
        return true;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function localeName(?string $locale = null): string
    {
        $locale ??= self::$locale;
        return self::AVAILABLE[$locale] ?? $locale;
    }

    /**
     * Übersetzung mit optionalen Platzhaltern: t('greeting', ['name' => 'Max'])
     * Reihenfolge: aktive Sprache, dann Deutsch, dann der Schlüssel selbst.
     *
     * @param array<string, string|int|float> $replacements
     */
    public static function get(string $key, array $replacements = []): string
    {
        self::load();
        $text = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($replacements as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }
        return $text;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (self::$fallback === []) {
            self::$fallback = self::loadFile(self::DEFAULT_LOCALE);
        }
        self::$strings = self::$locale === self::DEFAULT_LOCALE
            ? self::$fallback
            : self::loadFile(self::$locale);
    }

    /** @return array<string, string> */
    private static function loadFile(string $locale): array
    {
        $file = BASE_PATH . '/lang/' . $locale . '.php';
        if (!is_file($file)) {
            return [];
        }
        $strings = require $file;
        return is_array($strings) ? $strings : [];
    }
}
