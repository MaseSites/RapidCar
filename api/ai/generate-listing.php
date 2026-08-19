<?php
/**
 * Inserat-Generator (§31): Titel und Beschreibung aus allen vorhandenen
 * Fahrzeugdaten, im Schreibstil des Autohauses (Einstellungen).
 *
 * Läuft im letzten Schritt des Assistenten, wenn Fotos UND Dokument
 * ausgewertet sind. Der Text wird direkt ins Inserat geschrieben.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIException;
use App\AI\AIListingService;
use App\Core\Database;
use App\Repository\VehicleRepository;
use App\Service\ListingService;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$vehicleId = (int) ($input['vehicle_id'] ?? 0);
// Mit save=1 wandert der Text direkt ins Inserat (Assistent).
$save = !empty($input['save']);

if (VehicleRepository::find($vehicleId, $dealershipId) === null) {
    json_response(false, null, 'Inserat nicht gefunden.', 404);
}

// Ohne Guthaben geht keine Anfrage an die KI
guard_ai_credits($dealershipId);

try {
    $result = AIListingService::generate($vehicleId);
} catch (AIException $e) {
    json_response(false, null, $e->getMessage(), 422);
}

if ($save) {
    guard_demo_mode();
    $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
    $update = ['updated_at' => Database::now()];

    // Bestehende Texte werden nicht überschrieben: Handarbeit hat Vorrang.
    // Neben dem fertigen Text wird die Vorlage mit den Platzhaltern abgelegt,
    // damit spätere Änderungen an den Fahrzeugdaten im Text ankommen.
    if (($listing['title'] ?? null) === null && $result['title'] !== '') {
        $update['title'] = $result['title'];
        $update['title_template'] = $result['title_template'] ?? null;
    }
    if (($listing['description'] ?? null) === null && $result['description'] !== '') {
        $update['description'] = $result['description'];
        $update['description_template'] = $result['description_template'] ?? null;
    }
    if (count($update) > 1) {
        Database::update('listings', (int) $listing['id'], $update);
    }
    ListingService::recalculate((int) $listing['id']);
}

json_response(true, $result + ['saved' => $save]);
