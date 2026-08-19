<?php
/**
 * Zentraler Einstiegspunkt — wird von jeder Seite als Erstes eingebunden.
 *
 * Verantwortlich für:
 *  - Konstanten und Autoloading
 *  - Fehlerbehandlung (§67: keine PHP-Fehler für Endnutzer)
 *  - Konfiguration, Session, Hilfsfunktionen
 *  - Weiterleitung zum Installer, solange die Anwendung nicht installiert ist
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    define('RAPIDCAR', true);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/includes/autoload.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/icons.php';

use App\Core\Config;
use App\Core\Logger;
use App\Core\Session;

// ---------------------------------------------------------------------------
// Fehlerbehandlung: intern loggen, extern neutrale Fehlerseite (§67)
// ---------------------------------------------------------------------------
error_reporting(E_ALL);

Config::load();
$debug = (bool) filter_var(Config::get('app.debug', false), FILTER_VALIDATE_BOOL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-errors.log');

set_exception_handler(static function (\Throwable $e) use ($debug): void {
    Logger::error(get_class($e) . ': ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    } elseif (is_file(BASE_PATH . '/errors/500.php')) {
        require BASE_PATH . '/errors/500.php';
    } else {
        echo 'Technischer Fehler.';
    }
    exit;
});

register_shutdown_function(static function () use ($debug): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::error('Fataler Fehler: ' . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
        if (!$debug && !headers_sent() && is_file(BASE_PATH . '/errors/500.php')) {
            http_response_code(500);
            require BASE_PATH . '/errors/500.php';
        }
    }
});

// ---------------------------------------------------------------------------
// Sicherheits-Header
// ---------------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    // Seiten enthalten ihr Skript inline und aendern sich mit jedem
    // Speichern. Ohne diese Angabe zeigen manche Browser nach dem
    // Zurueckspringen eine alte Fassung samt altem Skript.
    header('Cache-Control: no-store, must-revalidate');
}

// ---------------------------------------------------------------------------
// Installation prüfen: ohne Konfiguration → Installer (ausser im Installer selbst)
// ---------------------------------------------------------------------------
$isInstallerRequest = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/install/');
if (!Config::isInstalled() && !$isInstallerRequest) {
    header('Location: ' . base_url('install/'));
    exit;
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
Session::start();

// ---------------------------------------------------------------------------
// Ausstehende Schema-Migrationen (idempotent, nur wenn nötig)
// ---------------------------------------------------------------------------
if (Config::isInstalled()) {
    \App\Core\Migrator::run();
}

// ---------------------------------------------------------------------------
// Sprache bestimmen: Session > Benutzer > Autohaus > Standard
// ---------------------------------------------------------------------------
\App\Core\Lang::resolve();
