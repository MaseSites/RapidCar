<?php
/**
 * Überträgt ein Fahrzeug zu AutoScout24 bzw. schaltet das Inserat aktiv oder inaktiv.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Session;
use App\Integration\AutoScoutPublisher;
use App\Integration\AutoScoutService;
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

if (!AutoScoutService::isConnected($dealershipId)) {
    Session::flash('warning', t('autoscout.not_connected'));
    redirect('dashboard/autoscout.php');
}

try {
    if ($action === 'activate' || $action === 'deactivate') {
        $active = $action === 'activate';
        AutoScoutPublisher::setActive($dealershipId, $vehicleId, $active, (int) $currentUser['id']);
        Session::flash('success', $active ? t('autoscout.activated') : t('autoscout.deactivated'));
        redirect($returnTo);
    }

    $result = AutoScoutPublisher::push($dealershipId, $vehicleId, false, (int) $currentUser['id']);

    Session::flash(
        'success',
        $result['created']
            ? t('autoscout.pushed', ['id' => $result['listing_id']])
            : t('autoscout.updated', ['id' => $result['listing_id']])
    );

    // Offene Punkte ehrlich melden statt stillschweigend weglassen
    if ($result['unresolved'] !== []) {
        Session::flash('warning', t('autoscout.unresolved') . ' ' . implode(' | ', $result['unresolved']));
    }
    if ($result['image_errors'] !== []) {
        Session::flash('warning', t('autoscout.image_errors') . ' ' . implode(' | ', $result['image_errors']));
    }
} catch (\RuntimeException $e) {
    Session::flash('danger', $e->getMessage());
}

redirect($returnTo);
