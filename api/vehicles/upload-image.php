<?php
/**
 * Bild-Upload (§25/§59): validiert, kodiert neu, erzeugt drei Grössen.
 * vehicle_id=0 → legt zuerst einen Fahrzeug-Entwurf an (Workflow §75).
 * Antwort: JSON-Envelope {success, data, error}.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIImageService;
use App\Core\Database;
use App\Core\Session;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\ImageService;

$dealershipId = require_dealership();
guard_demo_mode();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
$batch = preg_replace('/[^a-zA-Z0-9-]/', '', (string) ($_POST['batch'] ?? '')) ?? '';

// Fahrzeug prüfen bzw. Entwurf anlegen
if ($vehicleId > 0) {
    $vehicle = VehicleRepository::find($vehicleId, $dealershipId);
    if ($vehicle === null) {
        json_response(false, null, 'Inserat nicht gefunden.', 404);
    }
} else {
    // Werden mehrere Fotos gleichzeitig hochgeladen, kennt noch keines eine
    // Fahrzeug-ID. Ohne Klammer entstünde pro Foto ein eigenes Inserat. Die
    // Kennung des Hochladevorgangs hält sie zusammen: PHP serialisiert
    // Zugriffe auf die Sitzung, deshalb legt nur die erste Anfrage an.
    $known = Session::get('upload_batch_' . $batch);
    if ($batch !== '' && is_numeric($known) && VehicleRepository::find((int) $known, $dealershipId) !== null) {
        $vehicleId = (int) $known;
    } else {
        $vehicleId = VehicleRepository::createDraft($dealershipId, (int) $currentUser['id']);
        if ($batch !== '') {
            Session::set('upload_batch_' . $batch, (string) $vehicleId);
        }
        ActivityLogger::log((int) $currentUser['id'], 'vehicle.created', "Inserat #{$vehicleId} erstellt", 'vehicle', $vehicleId, $dealershipId);
    }
}

// Limit pro Fahrzeug: ein Hauptbild plus Nebenbilder
$maxImages = ImageService::maxImagesPerVehicle();
$currentCount = (int) Database::scalar(
    'SELECT COUNT(*) FROM vehicle_images WHERE vehicle_id = :vid',
    ['vid' => $vehicleId]
);
if ($currentCount >= $maxImages) {
    json_response(false, null, "Mehr als {$maxImages} Fotos sind pro Fahrzeug nicht möglich.", 422);
}

if (!isset($_FILES['image'])) {
    json_response(false, null, 'Keine Datei übermittelt.', 422);
}

try {
    $processed = ImageService::processUpload($_FILES['image'], 'vehicles/' . $vehicleId);
} catch (\RuntimeException $e) {
    json_response(false, null, $e->getMessage(), 422);
}

$isFirst = $currentCount === 0;
$imageId = Database::insert('vehicle_images', [
    'vehicle_id'    => $vehicleId,
    'file_path'     => $processed['full'],
    'card_path'     => $processed['card'],
    'thumb_path'    => $processed['thumb'],
    'original_name' => $processed['original_name'],
    'width'         => $processed['width'],
    'height'        => $processed['height'],
    'file_size'     => $processed['size'],
    'sort_order'    => $currentCount,
    'is_main'       => $isFirst ? 1 : 0,
    'created_at'    => Database::now(),
]);

// KI-Bildanalyse (§26): im Mock-Modus regelbasierte Qualitätsheuristik
$imageRow = Database::fetch('SELECT * FROM vehicle_images WHERE id = :id', ['id' => $imageId]);
$analysis = null;
try {
    $analysis = $imageRow !== null ? AIImageService::analyze($imageRow) : null;
} catch (\Throwable $e) {
    \App\Core\Logger::warning('Bildanalyse fehlgeschlagen: ' . $e->getMessage());
}

Database::update('vehicles', $vehicleId, ['updated_at' => Database::now()]);

json_response(true, [
    'vehicle_id' => $vehicleId,
    'count'      => $currentCount + 1,
    'max'        => $maxImages,
    'image'      => [
        'id'        => $imageId,
        'thumb_url' => upload_url($processed['thumb']),
        'card_url'  => upload_url($processed['card']),
        'is_main'   => $isFirst,
        'quality'   => $analysis['quality_score'] ?? null,
        'ai_mode'   => $analysis['mode'] ?? null,
    ],
]);
