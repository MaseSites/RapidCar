<?php
/**
 * Social-Post speichern (§36–§37): Canvas-PNG + Caption + Template.
 * Status: 'saved' (lokal) — Veröffentlichung nur mit echter Instagram-Verbindung (§72).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;

$dealershipId = require_dealership();
guard_demo_mode();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(false, null, 'Ungültige Anfrage.', 422);
}

$vehicleId = (int) ($input['vehicle_id'] ?? 0);
if (VehicleRepository::find($vehicleId, $dealershipId) === null) {
    json_response(false, null, 'Fahrzeug nicht gefunden.', 404);
}

$templateKey = mb_substr(trim((string) ($input['template_key'] ?? '')), 0, 50);
$caption = mb_substr((string) ($input['caption'] ?? ''), 0, 3000);
$imageData = (string) ($input['image_data'] ?? '');
$imageIds = is_array($input['image_ids'] ?? null) ? array_map('intval', $input['image_ids']) : [];

// Canvas-PNG validieren und speichern
$imagePath = null;
if (str_starts_with($imageData, 'data:image/png;base64,')) {
    $binary = base64_decode(substr($imageData, strlen('data:image/png;base64,')), true);
    if ($binary === false || strlen($binary) > 8 * 1024 * 1024) {
        json_response(false, null, 'Ungültige oder zu grosse Bilddaten.', 422);
    }
    // Serverseitig als PNG verifizieren + neu kodieren
    $image = @imagecreatefromstring($binary);
    if ($image === false) {
        json_response(false, null, 'Bilddaten konnten nicht verarbeitet werden.', 422);
    }
    $dir = BASE_PATH . '/uploads/social/' . $dealershipId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        json_response(false, null, 'Speicherverzeichnis konnte nicht erstellt werden.', 500);
    }
    $relPath = 'social/' . $dealershipId . '/post-' . bin2hex(random_bytes(8)) . '.png';
    imagepng($image, BASE_PATH . '/uploads/' . $relPath, 6);
    imagedestroy($image);
    $imagePath = $relPath;
}

$now = Database::now();
$postId = Database::insert('social_posts', [
    'dealership_id' => $dealershipId,
    'vehicle_id'    => $vehicleId,
    'template_key'  => $templateKey !== '' ? $templateKey : null,
    'platform'      => 'instagram',
    'caption'       => $caption,
    'image_path'    => $imagePath,
    'image_ids'     => json_encode($imageIds),
    'status'        => 'saved',
    'created_at'    => $now,
    'updated_at'    => $now,
]);

ActivityLogger::log((int) $currentUser['id'], 'social.post_saved', "Social-Post #{$postId} gespeichert", 'social_post', $postId, $dealershipId);

json_response(true, ['post_id' => $postId, 'status' => 'saved']);
