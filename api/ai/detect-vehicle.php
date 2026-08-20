<?php
/**
 * KI-Fahrzeugerkennung (§26/§28): liefert einen Erkennungsvorschlag.
 * Mit apply=1 werden die Vorschlagswerte in LEERE Felder übernommen (§30).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIException;
use App\AI\AIVehicleService;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$vehicleId = (int) ($input['vehicle_id'] ?? 0);
$apply = !empty($input['apply']);

$vehicle = VehicleRepository::find($vehicleId, $dealershipId);
if ($vehicle === null) {
    json_response(false, null, 'Fahrzeug nicht gefunden.', 404);
}

// Ohne Guthaben geht keine Anfrage an die KI
guard_ai_credits($dealershipId);

// Ein Guthaben zahlt EIN Fahrzeug. Die Erkennung laeuft deshalb je
// Fahrzeug genau einmal: sonst liesse sich dasselbe Inserat als Werkbank
// missbrauchen, indem man Fotos austauscht, erneut erkennen laesst und
// die Daten von Hand woanders eintraegt.
if (!\App\Service\AiUsageService::canDetect($vehicleId)) {
    json_response(
        false,
        ['already_detected' => true],
        'Dieses Fahrzeug wurde bereits erkannt. Die Angaben lassen sich von Hand ändern, '
        . 'und Texte kannst du beliebig oft neu erzeugen. Für ein anderes Fahrzeug bitte '
        . 'ein neues Inserat anlegen.',
        409
    );
}


try {
    $detection = AIVehicleService::detectFromImages($vehicleId);
} catch (AIException $e) {
    json_response(false, null, $e->getMessage(), 422);
}

// Unplausible Werte aussortieren, bevor sie ins Inserat wandern
$checked = \App\Service\FieldPlausibility::check($detection['fields'] ?? []);
$detection['fields'] = $checked['fields'];
if ($checked['notes'] !== []) {
    $detection['note'] = trim(($detection['note'] ?? '') . ' ' . implode(' ', $checked['notes']));
}

// Bezahlt und gezaehlt wird erst eine ERFOLGREICHE Erkennung. Ein graues
// Foto oder ein Fehlschlag kostet nichts und verbraucht den Versuch nicht:
// bessere Fotos hochladen und nochmal geht immer.
$isDemoAccount = (int) ($currentUser['is_demo'] ?? 0) === 1;
if ($detection['detected']) {
    if (!$isDemoAccount) {
        try {
            \App\Service\AiUsageService::ensureCharged($dealershipId, $vehicleId, (int) $currentUser['id']);
        } catch (\RuntimeException $e) {
            json_response(false, null, t('ai.no_credits'), 402);
        }
    }
    \App\Service\AiUsageService::countDetection($vehicleId);
}

$applied = [];
if ($apply && $detection['detected'] && $detection['fields'] !== []) {
    guard_demo_mode();
    $applied = AIVehicleService::applyToEmptyFields(
        $vehicleId,
        $detection['fields'],
        'detected',
        $detection['confidence']
    );
    ActivityLogger::log(
        (int) $currentUser['id'],
        'ai.detection_applied',
        'KI-Erkennungsvorschlag übernommen (' . $detection['mode'] . ') für Fahrzeug #' . $vehicleId,
        'vehicle',
        $vehicleId,
        $dealershipId
    );


    // Sichtbare Ausstattung übernehmen, ohne vorhandene Einträge zu verlieren
    $detectedFeatures = array_filter(array_map('strval', (array) ($detection['features'] ?? [])));
    if ($detectedFeatures !== []) {
        $existing = \App\Repository\VehicleRepository::features($vehicleId);
        $merged = array_values(array_unique(array_merge($existing, $detectedFeatures)));
        \App\Repository\VehicleRepository::replaceFeatures($vehicleId, $merged);
    }

    // Der Inseratstext entsteht bewusst NICHT hier: An dieser Stelle sind erst
    // die Fotodaten bekannt. Der Assistent erzeugt ihn im letzten Schritt,
    // wenn auch die Angaben aus dem Dokument vorliegen.
    if ($applied !== []) {
        $listing = \App\Service\ListingService::ensureForVehicle($vehicleId, $dealershipId);
        \App\Service\ListingService::recalculate((int) $listing['id']);
    }
}

json_response(true, [
    'detection' => $detection,
    'applied'   => $applied,
]);
