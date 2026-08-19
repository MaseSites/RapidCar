<?php
/**
 * Guard für den Admin-Bereich (§44/§45).
 *
 * Der Admin-Bereich gibt sich Unbefugten nicht zu erkennen: wer nicht als
 * Betreiber angemeldet ist, bekommt dieselbe 404-Seite wie bei jeder
 * unbekannten Adresse. Keine Weiterleitung zur Anmeldung, kein 403, kein
 * Hinweis, dass es hier etwas gibt. Der Betreiber meldet sich normal über
 * /login an und wird von dort automatisch hierher geführt.
 *
 * Einbinden NACH bootstrap.php, statt includes/auth.php.
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Auth\AuthService;

if (!AuthService::check() || AuthService::role() !== AuthService::ROLE_SUPER_ADMIN) {
    http_response_code(404);
    require BASE_PATH . '/errors/404.php';
    exit;
}

/** @var array<string, mixed> $currentUser Für alle Admin-Seiten verfügbar. */
$currentUser = AuthService::user();
