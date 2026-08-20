<?php
/**
 * Eigenen Hintergrund hochladen (§25/§59).
 * Gehört dem Autohaus und steht danach für alle Inserate zur Auswahl.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Service\BackgroundService;
use App\Service\ImageService;

$dealershipId = require_dealership();
guard_demo_mode();
guard_subscription($dealershipId, 'background');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}
if (!isset($_FILES['background'])) {
    json_response(false, null, 'Es wurde keine Datei übermittelt.', 422);
}

$existing = count(BackgroundService::ownBackgrounds($dealershipId));
if ($existing >= 20) {
    json_response(false, null, 'Mehr als 20 eigene Hintergründe sind nicht möglich.', 422);
}

try {
    $processed = ImageService::processUpload($_FILES['background'], 'backgrounds/' . $dealershipId);
} catch (\RuntimeException $e) {
    json_response(false, null, $e->getMessage(), 422);
}

$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '') {
    $name = $processed['original_name'];
}

$id = Database::insert('backgrounds', [
    'dealership_id' => $dealershipId,
    'name'          => mb_substr($name, 0, 120),
    'file_path'     => $processed['full'],
    'created_at'    => Database::now(),
]);

json_response(true, [
    'id'        => $id,
    'key'       => BackgroundService::OWN_PREFIX . $id,
    'name'      => mb_substr($name, 0, 120),
    'thumb_url' => upload_url($processed['thumb']),
]);
