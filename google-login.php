<?php
/**
 * Start der Google-Anmeldung: leitet zu Google weiter.
 * Ohne konfigurierte Zugangsdaten gibt es diesen Weg nicht.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Auth\AuthService;
use App\Auth\GoogleAuth;
use App\Core\Session;

if (AuthService::check()) {
    redirect('dashboard/');
}
if (!GoogleAuth::isConfigured()) {
    Session::flash('warning', 'Die Google-Anmeldung ist nicht eingerichtet.');
    redirect('login.php');
}

header('Location: ' . GoogleAuth::authUrl());
exit;
