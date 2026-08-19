<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Findet die Zertifikatsliste (CA-Bundle) für ausgehende HTTPS-Verbindungen.
 *
 * Hintergrund: Ist in der php.ini weder curl.cainfo noch openssl.cafile
 * gesetzt, kann PHP die Zertifikatskette nicht prüfen und meldet
 * "unable to get local issuer certificate". Statt die Prüfung abzuschalten
 * (das würde die übertragenen Zugangsdaten angreifbar machen), wird hier eine
 * vorhandene Zertifikatsliste gesucht und explizit übergeben.
 *
 * Reihenfolge:
 *   1. Konfigurationswert http.ca_bundle
 *   2. Eigene Ablage /storage/cacert.pem
 *   3. Einstellung aus der php.ini (dann übernimmt cURL das selbst)
 *   4. Bekannte Systempfade (Linux, macOS, Git für Windows)
 */
final class CaBundle
{
    private static ?string $resolved = null;
    private static bool $checked = false;

    /**
     * Pfad zur Zertifikatsliste oder null, wenn cURL bereits selbst eine kennt.
     */
    public static function path(): ?string
    {
        if (self::$checked) {
            return self::$resolved;
        }
        self::$checked = true;

        // 1. Ausdrücklich konfiguriert
        $configured = trim((string) Config::get('http.ca_bundle', ''));
        if ($configured !== '' && is_readable($configured)) {
            self::$resolved = $configured;
            return self::$resolved;
        }

        // 2. Im Projekt hinterlegt
        $own = BASE_PATH . '/storage/cacert.pem';
        if (is_readable($own)) {
            self::$resolved = $own;
            return self::$resolved;
        }

        // 3. PHP kennt bereits eine: cURL nutzt sie automatisch
        foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
            $value = (string) ini_get($setting);
            if ($value !== '' && is_readable($value)) {
                self::$resolved = null;
                return null;
            }
        }

        // 4. Bekannte Systempfade
        foreach (self::candidatePaths() as $candidate) {
            if (is_readable($candidate)) {
                self::$resolved = $candidate;
                return self::$resolved;
            }
        }

        self::$resolved = null;
        return null;
    }

    /** Ist überhaupt eine Zertifikatsprüfung möglich? */
    public static function isAvailable(): bool
    {
        if (self::path() !== null) {
            return true;
        }
        foreach (['curl.cainfo', 'openssl.cafile', 'openssl.capath'] as $setting) {
            $value = (string) ini_get($setting);
            if ($value !== '' && (is_readable($value) || is_dir($value))) {
                return true;
            }
        }
        // Auf Linux findet cURL das Systembundle in der Regel selbst
        return DIRECTORY_SEPARATOR === '/';
    }

    /**
     * Setzt die Zertifikatsoptionen auf einem cURL-Handle.
     * Die Prüfung bleibt in jedem Fall aktiv.
     *
     * @param array<int, mixed> $options
     * @return array<int, mixed>
     */
    public static function applyTo(array $options): array
    {
        $options[CURLOPT_SSL_VERIFYPEER] = true;
        $options[CURLOPT_SSL_VERIFYHOST] = 2;

        $path = self::path();
        if ($path !== null) {
            $options[CURLOPT_CAINFO] = $path;
        }
        return $options;
    }

    /** Klartext-Hinweis für Fehlermeldungen. */
    public static function troubleshootingHint(): string
    {
        return 'Auf diesem Server ist keine Zertifikatsliste hinterlegt, '
            . 'deshalb lässt sich die Echtheit der Gegenstelle nicht prüfen. '
            . 'Abhilfe: in der php.ini den Wert curl.cainfo auf eine cacert.pem setzen '
            . 'oder die Datei als storage/cacert.pem im Projekt ablegen.';
    }

    /** @return array<int, string> */
    private static function candidatePaths(): array
    {
        return [
            // Linux und BSD
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
            '/etc/pki/tls/cacert.pem',
            '/etc/ssl/cert.pem',
            '/usr/local/share/certs/ca-root-nss.crt',
            // Git für Windows
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files (x86)\\Git\\mingw32\\etc\\ssl\\certs\\ca-bundle.crt',
            // Verbreitete Windows-Stacks
            'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
            'C:\\laragon\\bin\\php\\cacert.pem',
            // Neben der PHP-Installation
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'cacert.pem',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl'
                . DIRECTORY_SEPARATOR . 'cacert.pem',
        ];
    }
}
