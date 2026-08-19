<?php
/**
 * Globale Hilfsfunktionen (bewusst klein gehalten).
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Core\Config;
use App\Core\Lang;
use App\Core\Session;

/** HTML-Escaping für jede Ausgabe (XSS-Schutz). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Übersetzung. */
function t(string $key, array $replacements = []): string
{
    return Lang::get($key, $replacements);
}

/**
 * Basis-URL der Anwendung (ohne abschliessenden Slash) + optionaler Pfad.
 * Nutzt app.url aus der Konfiguration; Fallback: aus dem Request ableiten.
 */
/**
 * @param bool $keepExtension .php in der Adresse belassen. Noetig fuer
 *                            OAuth-Ruecksprungadressen: die muessen exakt so
 *                            lauten, wie sie beim Anbieter registriert sind.
 */
function base_url(string $path = '', bool $keepExtension = false): string
{
    static $base = null;
    if ($base === null) {
        $configured = (string) Config::get('app.url', '');

        // Eine hinterlegte Adresse, die auf den eigenen Rechner zeigt, taugt
        // auf einem Server nicht: alle Links wuerden dorthin fuehren. In dem
        // Fall gilt die Adresse, unter der die Anfrage tatsaechlich ankam.
        if ($configured !== '' && ($_SERVER['HTTP_HOST'] ?? '') !== '') {
            $configuredHost = strtolower((string) parse_url($configured, PHP_URL_HOST));
            $requestHost = strtolower(explode(':', (string) $_SERVER['HTTP_HOST'])[0]);
            $isLocal = $configuredHost === 'localhost'
                || str_starts_with($configuredHost, '127.')
                || $configuredHost === '::1'
                || str_ends_with($configuredHost, '.local')
                || str_ends_with($configuredHost, '.test');
            if ($isLocal && $configuredHost !== $requestHost) {
                $configured = '';
            }
        }

        if ($configured !== '') {
            // Kommt die Anfrage ueber HTTPS, die Konfiguration nennt aber http,
            // wuerden alle Links den Besucher aus der sicheren Verbindung
            // hinauswerfen. Das Schema der Anfrage gilt.
            if (str_starts_with($configured, 'http://') && \App\Core\Session::isHttps()) {
                $configured = 'https://' . substr($configured, 7);
            }
            $base = rtrim($configured, '/');
        } else {
            $scheme = \App\Core\Session::isHttps() ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // Wurzelpfad relativ zum Document-Root bestimmen
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            foreach (['/dashboard', '/admin', '/api', '/install', '/errors'] as $sub) {
                if (str_ends_with($scriptDir, $sub)) {
                    $scriptDir = substr($scriptDir, 0, -strlen($sub));
                    break;
                }
                // API-Unterordner: /api/auth usw.
                if (($pos = strpos($scriptDir, $sub . '/')) !== false) {
                    $scriptDir = substr($scriptDir, 0, $pos);
                    break;
                }
            }
            $base = $scheme . '://' . $host . rtrim($scriptDir, '/');
        }
    }
    // Saubere Adressen: kein sichtbares .php. Die .htaccess bildet die
    // endungslosen Pfade intern wieder auf die Dateien ab. Abschaltbar
    // fuer Server ohne mod_rewrite (app.pretty_urls = false).
    static $pretty = null;
    if ($pretty === null) {
        $pretty = (bool) filter_var(Config::get('app.pretty_urls', true), FILTER_VALIDATE_BOOL);
    }
    if ($pretty && !$keepExtension && $path !== '') {
        $path = preg_replace('#(^|/)index\.php(?=$|\?)#', '$1', $path) ?? $path;
        $path = preg_replace('#\.php(?=$|\?)#', '', $path) ?? $path;
    }

    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** URL zu statischen Assets. */
function asset(string $path): string
{
    // Versionsnummer aus dem Aenderungszeitpunkt: Nach jeder Aenderung laedt
    // der Browser die Datei neu, sonst haelt er sie beliebig lange im Cache.
    // Ohne diese Nummer sahen Seiten nach Design-Aenderungen zerschossen aus.
    $file = BASE_PATH . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return base_url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

/** URL zu hochgeladenen Dateien. */
function upload_url(string $path): string
{
    return base_url('uploads/' . ltrim($path, '/'));
}

/** Redirect + Exit. */
function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : base_url($path)));
    exit;
}

/** Alte Formulareingabe nach Validierungsfehler. */
function old(string $field, string $default = ''): string
{
    $old = Session::get('_old_input', []);
    return e($old[$field] ?? $default);
}

function store_old_input(array $data): void
{
    unset($data['password'], $data['password_confirm'], $data['_csrf']);
    Session::set('_old_input', $data);
}

function clear_old_input(): void
{
    Session::remove('_old_input');
}

/** Preisformatierung im Schweizer Stil: 89'900. */
function format_price(int|float|string|null $amount, string $currency = 'CHF'): string
{
    if ($amount === null || $amount === '') {
        return '-';
    }
    return $currency . ' ' . number_format((float) $amount, 0, '.', "'");
}

/** Kilometerformatierung: 12'900 km. */
function format_km(int|float|string|null $km): string
{
    if ($km === null || $km === '') {
        return '-';
    }
    return number_format((float) $km, 0, '.', "'") . ' km';
}

/** Datum: 17.08.2026. */
function format_date(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '-';
    }
    $ts = strtotime($datetime);
    return $ts === false ? '-' : date('d.m.Y', $ts);
}

/** Datum + Zeit: 17.08.2026 17:42. */
function format_datetime(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '-';
    }
    $ts = strtotime($datetime);
    return $ts === false ? '-' : date('d.m.Y H:i', $ts);
}

/** Relative Zeit: „vor 5 Min.", „vor 2 Std.", sonst Datum. */
function time_ago(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '-';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '-';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'gerade eben';
    }
    if ($diff < 3600) {
        return 'vor ' . intdiv($diff, 60) . ' Min.';
    }
    if ($diff < 86400) {
        return 'vor ' . intdiv($diff, 3600) . ' Std.';
    }
    if ($diff < 604800) {
        return 'vor ' . intdiv($diff, 86400) . ' Tg.';
    }
    return format_date($datetime);
}

/** Initialen für Avatar-Anzeige: „Max Müller" → „MM". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
}

/** JSON-Antwort für API-Endpunkte (§ Patterns: einheitliches Envelope). */
function json_response(bool $success, mixed $data = null, ?string $error = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => $success, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** Score-Klasse für die Pfeil-/Farbbewertung (§33). */
function rating_class(int $score): string
{
    return match (true) {
        $score >= 90 => 'rating-excellent',
        $score >= 75 => 'rating-good',
        $score >= 55 => 'rating-warning',
        $score >= 35 => 'rating-bad',
        default      => 'rating-critical',
    };
}

/**
 * Pfeil-Bewertung (§33) als SVG-Icon mit Farbklasse.
 * Sehr gut: Doppelpfeil hoch, Gut: Pfeil hoch, Mittel: Pfeil rechts,
 * Schlecht: Pfeil runter, Kritisch: Doppelpfeil runter.
 */
function rating_arrow(int $score): string
{
    $name = match (true) {
        $score >= 90 => 'chevrons-up',
        $score >= 75 => 'arrow-up',
        $score >= 55 => 'arrow-right',
        $score >= 35 => 'arrow-down',
        default      => 'chevrons-down',
    };
    return icon($name, 14, 'rating-icon ' . rating_class($score));
}

/** Übersetzte Bezeichnung eines Fahrzeugstatus (§24). */
function vehicle_status_label(string $status): string
{
    return match ($status) {
        'draft', 'ready', 'published', 'paused', 'reserved', 'sold', 'archived' => t('status.' . $status),
        default => $status,
    };
}

/** Übersetzte Bezeichnung eines Lead-Status. */
function lead_status_label(string $status): string
{
    return match ($status) {
        'new', 'active', 'test_drive', 'won', 'lost' => t('leads.status.' . $status),
        default => $status,
    };
}
