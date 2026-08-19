<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AES-256-GCM-Verschlüsselung für sensible Werte (z.B. Integration-Tokens, §58).
 * Schlüssel: app.key aus der Konfiguration (vom Installer generiert).
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) { // 12 IV + 16 Tag + min. 1 Byte
            throw new RuntimeException('Ungültige verschlüsselte Daten.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        return $plaintext;
    }

    /** Erzeugt einen neuen zufälligen Anwendungsschlüssel (für den Installer). */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function key(): string
    {
        $encoded = (string) Config::get('app.key', '');
        if ($encoded === '') {
            throw new RuntimeException('Kein Anwendungsschlüssel (app.key) konfiguriert.');
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Ungültiger Anwendungsschlüssel.');
        }
        return $key;
    }
}
