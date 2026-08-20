<?php
/**
 * Freistellen und Hintergrundwechsel.
 *
 * Ablauf:
 *  - action=cutout:  EIN KI-Aufruf je Foto, Ergebnis wird als PNG gespeichert.
 *  - action=apply:   reine Bildmontage aus Zuschnitt und Hintergrund, ohne KI.
 *  - action=reset:   zurück zum Originalfoto.
 *
 * Der Zuschnitt bleibt erhalten, deshalb kostet jeder weitere
 * Hintergrundwechsel nichts mehr.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIService;
use App\Core\Database;
use App\Service\ActivityLogger;
use App\Service\BackgroundService;
use App\Service\ImageService;

$dealershipId = require_dealership();
guard_demo_mode();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// Freistellen kann pro Foto ueber eine Minute dauern; das PHP-Zeitlimit
// von 30 Sekunden wuerde die Anfrage mitten in der Arbeit abbrechen.
@set_time_limit(300);

$action = (string) ($input['action'] ?? '');
$imageId = (int) ($input['image_id'] ?? 0);

$image = Database::fetch(
    'SELECT vi.* FROM vehicle_images vi
     INNER JOIN vehicles v ON v.id = vi.vehicle_id
     WHERE vi.id = :id AND v.dealership_id = :did',
    ['id' => $imageId, 'did' => $dealershipId]
);
if ($image === null) {
    json_response(false, null, 'Foto nicht gefunden.', 404);
}

$vehicleId = (int) $image['vehicle_id'];

/** Erzeugt Karten- und Vorschaugrösse neu und speichert die Pfade. */
function store_display_variants(array $image, string $relativeJpeg): array
{
    $paths = ImageService::rebuildVariants($relativeJpeg);
    App\Core\Database::update('vehicle_images', (int) $image['id'], [
        'composed_path' => $relativeJpeg,
        'card_path'     => $paths['card'],
        'thumb_path'    => $paths['thumb'],
    ]);
    return $paths;
}

switch ($action) {
    case 'cutout':
        if ((string) ($image['cutout_path'] ?? '') !== ''
            && is_file(ImageService::uploadPath((string) $image['cutout_path']))) {
            json_response(true, ['already' => true, 'cutout' => upload_url((string) $image['cutout_path'])]);
        }
        try {
            $binary = App\Service\CutoutService::cutout(
                ImageService::uploadPath((string) $image['file_path'])
            );
        } catch (\Throwable $e) {
            json_response(false, null, $e->getMessage(), 422);
        }

        $relative = 'vehicles/' . $vehicleId . '/cut-' . bin2hex(random_bytes(8)) . '.png';
        $absolute = ImageService::uploadPath($relative);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        if (file_put_contents($absolute, $binary) === false) {
            json_response(false, null, 'Der Zuschnitt konnte nicht gespeichert werden.', 500);
        }

        Database::update('vehicle_images', $imageId, ['cutout_path' => $relative]);
        ActivityLogger::log((int) $currentUser['id'], 'image.cutout', "Foto #{$imageId} freigestellt", 'vehicle', $vehicleId, $dealershipId);
        json_response(true, ['already' => false, 'cutout' => upload_url($relative)]);

    case 'spyne_status':
        // Holt das Ergebnis eines frueher angestossenen Spyne-Auftrags ab.
        $key = (string) ($input['background'] ?? '');
        $job = (string) ($input['job'] ?? '');
        // Ohne Angabe: der gemerkte Auftrag des Fotos (nach Seitenwechsel)
        if ($job === '') {
            $job = (string) ($image['spyne_job'] ?? '');
            $key = $key !== '' ? $key : (string) ($image['spyne_scene'] ?? '');
        }
        if (!preg_match('/^[a-f0-9-]{8,64}$/i', $job)) {
            json_response(false, null, 'Unbekannter Auftrag.', 422);
        }
        if (!BackgroundService::isTemplate($key)) {
            json_response(false, null, 'Unbekannter Hintergrund.', 422);
        }
        try {
            $binary = App\Integration\SpyneService::checkJob($job);
        } catch (\Throwable $e) {
            json_response(false, null, $e->getMessage(), 422);
        }
        if ($binary === null) {
            json_response(true, ['pending' => true, 'job' => $job]);
        }

        $relative = 'vehicles/' . $vehicleId . '/sp-' . bin2hex(random_bytes(8)) . '.jpg';
        $target = ImageService::uploadPath($relative);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        if (file_put_contents($target, $binary) === false) {
            json_response(false, null, 'Das Ergebnis konnte nicht gespeichert werden.', 500);
        }
        $paths = store_display_variants($image, $relative);
        Database::update('vehicle_images', $imageId, [
            'background_key' => $key,
            'spyne_job'      => null,
            'spyne_scene'    => null,
        ]);
        ActivityLogger::log(
            (int) $currentUser['id'],
            'image.background',
            "Foto #{$imageId} über Spyne gesetzt",
            'vehicle',
            $vehicleId,
            $dealershipId
        );
        json_response(true, [
            'card_url'  => upload_url($paths['card']),
            'thumb_url' => upload_url($paths['thumb']),
        ]);

    case 'apply':
        $key = (string) ($input['background'] ?? '');
        if ($key === BackgroundService::KEY_ORIGINAL) {
            json_response(false, null, 'Es wurde kein Hintergrund gewählt.', 422);
        }
        if (!BackgroundService::isTemplate($key) && BackgroundService::ownId($key) === null) {
            json_response(false, null, 'Unbekannter Hintergrund.', 422);
        }

        // Spyne macht Freistellen, Retusche und Hintergrund in einem Durchlauf.
        // Ein getrennter Zuschnitt entfällt dort, weil der Dienst Boden und
        // Schatten selbst setzt. Eigene Hintergrundbilder kennt Spyne nicht:
        // dafür bleibt der eigene Weg zuständig.
        if (BackgroundService::usesSpyne() && BackgroundService::ownId($key) === null) {
            // Nur anstossen und sofort antworten: Shared Hosting kappt
            // Anfragen nach wenigen Sekunden, Spyne braucht aber bis zu
            // zwei Minuten. Die Oberflaeche fragt per spyne_status nach.
            // Kennzeichen und Banner: die Haken im Fenster haben Vorrang,
            // ohne Haken gelten die Betreiber-Einstellungen (z.B. im
            // Anlege-Assistenten, der keine Haken zeigt).
            $plateSetting = (string) (\App\Service\SettingsService::get('spyne_plate') ?? 'off');
            $plateWanted = isset($input['plate_logo'])
                ? (int) $input['plate_logo'] === 1
                : $plateSetting !== 'off';
            $plate = '0';
            if ($plateWanted) {
                $logoPath = (string) (App\Core\Database::scalar(
                    'SELECT logo_path FROM dealerships WHERE id = :d',
                    ['d' => $dealershipId]
                ) ?: '');
                // Logo des Autohauses; ohne Logo weisse Flaeche statt leerem Link.
                $plate = ($plateSetting === 'logo' && $logoPath !== '') ? upload_url($logoPath) : '1';
            }
            $spyneOptions = ['plate' => $plate];
            $bannerUrl = trim((string) (\App\Service\SettingsService::get('spyne_banner_url') ?? ''));
            $bannerWanted = isset($input['banner']) ? (int) $input['banner'] === 1 : true;
            if ($bannerUrl !== '' && $bannerWanted) {
                $spyneOptions['banner_url'] = $bannerUrl;
            }

            try {
                $job = App\Integration\SpyneService::submitJob(
                    upload_url((string) $image['file_path']),
                    $key,
                    'Fahrzeug-' . $vehicleId,
                    $spyneOptions
                );
            } catch (\Throwable $e) {
                json_response(false, null, $e->getMessage(), 422);
            }
            // Auftrag am Foto merken: die Verarbeitung dauert Minuten und
            // soll einen Seitenwechsel ueberleben.
            Database::update('vehicle_images', $imageId, ['spyne_job' => $job, 'spyne_scene' => $key]);
            json_response(true, ['pending' => true, 'job' => $job]);
        }
        if (false) {
            json_response(true, [
                'card_url'  => upload_url($paths['card']),
                'thumb_url' => upload_url($paths['thumb']),
            ]);
        }
        // Fehlt der Zuschnitt, wird er hier einmalig erstellt. Jeder weitere
        // Hintergrundwechsel nutzt ihn dann ohne neue Freistellung.
        $cutout = (string) ($image['cutout_path'] ?? '');
        if ($cutout === '' || !is_file(ImageService::uploadPath($cutout))) {
            try {
                $binary = App\Service\CutoutService::cutout(
                    ImageService::uploadPath((string) $image['file_path'])
                );
            } catch (\Throwable $e) {
                json_response(false, null, $e->getMessage(), 422);
            }
            $cutout = 'vehicles/' . $vehicleId . '/cut-' . bin2hex(random_bytes(8)) . '.png';
            $cutTarget = ImageService::uploadPath($cutout);
            if (!is_dir(dirname($cutTarget))) {
                mkdir(dirname($cutTarget), 0755, true);
            }
            if (file_put_contents($cutTarget, $binary) === false) {
                json_response(false, null, 'Der Zuschnitt konnte nicht gespeichert werden.', 500);
            }
            Database::update('vehicle_images', $imageId, ['cutout_path' => $cutout]);
            ActivityLogger::log((int) $currentUser['id'], 'image.cutout', "Foto #{$imageId} freigestellt", 'vehicle', $vehicleId, $dealershipId);
        }

        try {
            $relative = BackgroundService::compose(
                ImageService::uploadPath($cutout),
                $key,
                $dealershipId,
                'vehicles/' . $vehicleId
            );
            $paths = store_display_variants($image, $relative);
        } catch (\Throwable $e) {
            json_response(false, null, $e->getMessage(), 422);
        }

        Database::update('vehicle_images', $imageId, ['background_key' => $key]);
        json_response(true, [
            'thumb_url' => upload_url($paths['thumb']),
            'card_url'  => upload_url($paths['card']),
        ]);

    case 'reset':
        try {
            $paths = ImageService::rebuildVariants((string) $image['file_path']);
        } catch (\Throwable $e) {
            json_response(false, null, $e->getMessage(), 422);
        }
        // Zusammengesetztes Bild entfernen, Zuschnitt behalten: sonst müsste
        // beim nächsten Hintergrund erneut die KI bemüht werden.
        ImageService::deleteVariants($image['composed_path'] ?? null);
        Database::update('vehicle_images', $imageId, [
            'card_path'      => $paths['card'],
            'thumb_path'     => $paths['thumb'],
            'composed_path'  => null,
            'background_key' => null,
        ]);
        json_response(true, [
            'thumb_url' => upload_url($paths['thumb']),
            'card_url'  => upload_url($paths['card']),
        ]);

    default:
        json_response(false, null, 'Unbekannte Aktion.', 422);
}
