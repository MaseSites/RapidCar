<?php
/**
 * Erzeugt Vorschaubilder für Spyne-Hintergründe (nur Betreiber).
 *
 * Je Hintergrund wird EIN Beispielfoto durch Spyne verarbeitet und das
 * Ergebnis als Vorschau gespeichert. Das kostet je Hintergrund eine
 * Spyne-Verarbeitung; angestossen wird deshalb nur auf Klick im Admin.
 *
 * Asynchron wie der Hintergrundwechsel: start stösst an, status holt ab.
 * Shared Hosting kappt lange Anfragen, der Server wartet nie selbst.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Integration\SpyneService;
use App\Service\SettingsService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}
App\Core\Csrf::validate();

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = (string) ($input['action'] ?? '');
$sceneId = trim((string) ($input['scene'] ?? ''));

$scenes = SpyneService::scenes();
if ($sceneId === '' || !isset($scenes[$sceneId])) {
    json_response(false, null, 'Unbekannter Hintergrund.', 422);
}
if (!SpyneService::isConfigured()) {
    json_response(false, null, 'Spyne ist nicht eingerichtet.', 422);
}

if ($action === 'start') {
    // Beispielfoto: das neueste vorhandene Fahrzeugfoto der Plattform
    $sample = Database::fetch(
        "SELECT file_path FROM vehicle_images WHERE file_path != '' ORDER BY id DESC LIMIT 20"
    );
    $samplePath = null;
    foreach (Database::fetchAll(
        "SELECT file_path FROM vehicle_images WHERE file_path != '' ORDER BY id DESC LIMIT 20"
    ) as $row) {
        if (is_file(BASE_PATH . '/uploads/' . $row['file_path'])) {
            $samplePath = (string) $row['file_path'];
            break;
        }
    }
    if ($samplePath === null) {
        json_response(false, null, 'Kein Beispielfoto vorhanden. Bitte zuerst ein Fahrzeugfoto hochladen.', 422);
    }

    try {
        $job = SpyneService::submitJob(upload_url($samplePath), $sceneId, 'Vorschau-' . $sceneId);
    } catch (\Throwable $e) {
        json_response(false, null, $e->getMessage(), 422);
    }
    json_response(true, ['job' => $job]);
}

if ($action === 'status') {
    $job = (string) ($input['job'] ?? '');
    if (!preg_match('/^[a-f0-9-]{8,64}$/i', $job)) {
        json_response(false, null, 'Unbekannter Auftrag.', 422);
    }
    try {
        $binary = SpyneService::checkJob($job);
    } catch (\Throwable $e) {
        json_response(false, null, $e->getMessage(), 422);
    }
    if ($binary === null) {
        json_response(true, ['pending' => true, 'job' => $job]);
    }

    // Verkleinert speichern: die Vorschau muss nur die Kachel fuellen
    $dir = BASE_PATH . '/uploads/backgrounds';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        json_response(false, null, 'Vorschau-Ordner konnte nicht angelegt werden.', 500);
    }
    $relative = 'backgrounds/spyne-' . preg_replace('/[^0-9A-Za-z_-]/', '', $sceneId) . '.jpg';
    $source = @imagecreatefromstring($binary);
    if ($source !== false) {
        $width = imagesx($source);
        $height = imagesy($source);
        $targetWidth = 480;
        $targetHeight = (int) round($height * $targetWidth / max(1, $width));
        $small = imagescale($source, $targetWidth, $targetHeight);
        imagejpeg($small !== false ? $small : $source, BASE_PATH . '/uploads/' . $relative, 82);
        imagedestroy($source);
        if ($small !== false) {
            imagedestroy($small);
        }
    } else {
        file_put_contents(BASE_PATH . '/uploads/' . $relative, $binary);
    }

    // Vorschau am Szenen-Eintrag speichern (Format {label, preview})
    $stored = json_decode((string) SettingsService::get('spyne_scenes'), true);
    $stored = is_array($stored) ? $stored : [];
    $label = $scenes[$sceneId]['label'];
    $stored[$sceneId] = ['label' => $label, 'preview' => $relative];
    SettingsService::set('spyne_scenes', (string) json_encode($stored));

    json_response(true, ['done' => true, 'preview' => upload_url($relative)]);
}

json_response(false, null, 'Unbekannte Aktion.', 422);
