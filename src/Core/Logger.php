<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Einfaches Datei-Logging nach /storage/logs/.
 * Keine sensiblen Daten (Passwörter, Tokens) loggen.
 */
final class Logger
{
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_ERROR = 'ERROR';

    public static function info(string $message, array $context = []): void
    {
        self::write(self::LEVEL_INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write(self::LEVEL_WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write(self::LEVEL_ERROR, $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return; // Logging darf die Anwendung niemals zum Absturz bringen
        }

        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
