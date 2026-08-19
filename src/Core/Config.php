<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Zentrale Konfiguration.
 *
 * Quelle 1: /config/config.php (vom Installer erzeugt, gibt ein Array zurück)
 * Quelle 2: /.env (optional, überschreibt Werte, falls das Hosting .env erlaubt)
 *
 * Zugriff per Punktnotation: Config::get('db.driver')
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $file = BASE_PATH . '/config/config.php';
        if (is_file($file)) {
            $config = require $file;
            if (is_array($config)) {
                self::$data = $config;
            }
        }

        self::applyEnvFile(BASE_PATH . '/.env');
    }

    /** Liest eine optionale .env-Datei und überschreibt bekannte Schlüssel. */
    private static function applyEnvFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        $map = [
            'DB_DRIVER'                => 'db.driver',
            'DB_HOST'                  => 'db.host',
            'DB_PORT'                  => 'db.port',
            'DB_NAME'                  => 'db.name',
            'DB_USER'                  => 'db.user',
            'DB_PASSWORD'              => 'db.password',
            'DB_SQLITE_PATH'           => 'db.sqlite_path',
            'APP_URL'                  => 'app.url',
            'APP_KEY'                  => 'app.key',
            'APP_DEBUG'                => 'app.debug',
            'AI_MODE'                  => 'ai.mode',
            'AI_API_KEY'               => 'ai.api_key',
            'AI_API_URL'               => 'ai.api_url',
            'AI_MODEL'                 => 'ai.model',
            'AUTOSCOUT_CLIENT_ID'      => 'autoscout.client_id',
            'AUTOSCOUT_CLIENT_SECRET'  => 'autoscout.client_secret',
            'AUTOSCOUT_REDIRECT_URI'   => 'autoscout.redirect_uri',
            'AUTOSCOUT_API_URL'        => 'autoscout.api_url',
            'AUTOSCOUT_AUTH_URL'       => 'autoscout.auth_url',
            'AUTOSCOUT_TOKEN_URL'      => 'autoscout.token_url',
            'AUTOSCOUT_SCOPES'         => 'autoscout.scopes',
            'INSTAGRAM_CLIENT_ID'      => 'instagram.client_id',
            'INSTAGRAM_CLIENT_SECRET'  => 'instagram.client_secret',
            'INSTAGRAM_REDIRECT_URI'   => 'instagram.redirect_uri',
            'MAIL_DRIVER'              => 'mail.driver',
            'MAIL_HOST'                => 'mail.host',
            'MAIL_PORT'                => 'mail.port',
            'MAIL_USERNAME'            => 'mail.username',
            'MAIL_PASSWORD'            => 'mail.password',
            'MAIL_FROM'                => 'mail.from',
            'MAIL_FROM_NAME'           => 'mail.from_name',
            'MAIL_ENCRYPTION'          => 'mail.encryption',
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");
            if (isset($map[$key])) {
                self::set($map[$key], $value);
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $segments = explode('.', $key);
        $value = self::$data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        self::load();
        $segments = explode('.', $key);
        $ref = &self::$data;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    /** Erzwingt Neuladen (z.B. nachdem der Installer config.php geschrieben hat). */
    public static function reload(): void
    {
        self::$loaded = false;
        self::$data = [];
        self::load();
    }

    public static function isInstalled(): bool
    {
        // Die Konfiguration allein entscheidet. Die Sperrdatei unter
        // /storage diente nur dem Installer und darf fehlen: /storage
        // wird bei einer Bereitstellung bewusst nie mitgeliefert.
        return is_file(BASE_PATH . '/config/config.php');
    }
}
