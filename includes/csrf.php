<?php
/**
 * CSRF-Guard: bei POST-Requests Token prüfen.
 * Einbinden NACH bootstrap.php auf jeder Seite mit Formularverarbeitung.
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Core\Csrf;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyRequest();
}
