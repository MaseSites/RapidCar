<?php
/**
 * Überträgt ein Fahrzeug zu mobile.de bzw. entfernt es dort.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Session;
use App\Integration\MobileDePublisher;
use App\Integration\MobileDeService;
use App\Repository\VehicleRepository;

$dealershipId = require_dealership();
guard_demo_mode();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('dashboard/channels.php');
}

$vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'push');
$returnTo = 'dashboard/vehicle.php?id=' . $vehicleId;

if (VehicleRepository::find($vehicleId, $dealershipId) === null) {
    Session::flash('danger', 'Fahrzeug nicht gefunden.');
    redirect('dashboard/vehicles.php');
}

if (!MobileDeService::isConnected($dealershipId)) {
    Session::flash('warning', 'Zuerst mobile.de verbinden.');
    redirect('dashboard/mobilede.php');
}

try {
    if ($action === 'remove') {
        MobileDePublisher::remove($dealershipId, $vehicleId, (int) $currentUser['id']);
        Session::flash('success', 'Inserat von mobile.de entfernt.');
        redirect($returnTo);
    }

    $result = MobileDePublisher::push($dealershipId, $vehicleId, (int) $currentUser['id']);
    Session::flash(
        'success',
        $result['created']
            ? 'Zu mobile.de übertragen (Inserat ' . $result['ad_id'] . ').'
            : 'Auf mobile.de aktualisiert (Inserat ' . $result['ad_id'] . ').'
    );
    if ($result['image_errors'] !== []) {
        Session::flash('warning', 'Bilder mit Problemen: ' . implode(' | ', array_slice($result['image_errors'], 0, 5)));
    }
} catch (\Throwable $e) {
    Session::flash('danger', $e->getMessage());
}

redirect($returnTo);
