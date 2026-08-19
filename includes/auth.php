<?php
/**
 * Guard für geschützte Seiten: erfordert eingeloggten Benutzer.
 * Einbinden NACH bootstrap.php.
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Auth\AuthService;

if (!AuthService::check()) {
    \App\Core\Session::flash('info', 'Bitte melde dich an, um fortzufahren.');
    redirect('login.php');
}

/** @var array<string, mixed> $currentUser Für alle geschützten Seiten verfügbar. */
$currentUser = AuthService::user();
