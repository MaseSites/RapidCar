<?php
/**
 * „Demo ansehen" (§64): meldet den Besucher am Demo-Konto an.
 * Das Demo-Konto ist mit is_demo=1 markiert; Schreiboperationen sind
 * über guard_demo_mode() deaktiviert.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;

$demoUser = Database::fetch(
    "SELECT * FROM users WHERE is_demo = 1 AND is_active = 1 ORDER BY id LIMIT 1"
);

if ($demoUser === null) {
    Session::flash('warning', 'Der Demo-Modus ist auf dieser Installation nicht eingerichtet (Demo-Daten wurden bei der Installation nicht geladen).');
    redirect('index.php');
}

Session::regenerate();
Session::set('user_id', (int) $demoUser['id']);
ActivityLogger::log(
    (int) $demoUser['id'],
    'user.demo_login',
    'Demo-Modus gestartet',
    'user',
    (int) $demoUser['id'],
    $demoUser['dealership_id'] !== null ? (int) $demoUser['dealership_id'] : null
);
Session::flash('info', 'Demo-Modus aktiv. Änderungen sind deaktiviert.');
redirect('dashboard/');
