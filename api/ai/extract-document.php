<?php
/**
 * Fahrzeugdokument auswerten (Kaufvertrag, Fahrzeugausweis, Serviceheft).
 *
 * Sparsamer Ablauf:
 *  1. PDF: Text wird lokal ausgelesen (pdftotext oder reines PHP), kostenlos.
 *  2. Der Text wird regelbasiert nach Schlüsselwörtern durchsucht, kostenlos.
 *  3. Nur wenn dabei zu wenig herauskommt, geht etwas an die KI, und zwar
 *     bevorzugt der kurze Text statt des ganzen Bildes.
 *
 * Das Dokument wird nach der Auswertung SOFORT gelöscht und nie ins Inserat
 * übernommen: Kaufverträge enthalten personenbezogene Daten.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIService;
use App\AI\AIVehicleService;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\DocumentParser;
use App\Service\ImageService;
use App\Service\PdfTextExtractor;

$dealershipId = require_dealership();
guard_demo_mode();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
if (VehicleRepository::find($vehicleId, $dealershipId) === null) {
    json_response(false, null, 'Inserat nicht gefunden.', 404);
}
if (!isset($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(false, null, 'Es wurde kein Dokument übermittelt.', 422);
}

$upload = $_FILES['document'];
$maxBytes = ((int) App\Core\Config::get('uploads.max_file_size_mb', 12)) * 1024 * 1024;
if ((int) $upload['size'] > $maxBytes) {
    json_response(false, null, 'Die Datei ist zu gross.', 422);
}
if (!is_uploaded_file($upload['tmp_name'])) {
    json_response(false, null, 'Ungültiger Upload.', 422);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) ?: '';
$isPdf = $mime === 'application/pdf';

$tempPaths = [];   // wird am Ende restlos entfernt
$documentText = '';
$source = '';

try {
    if ($isPdf) {
        // PDF in einen kurzlebigen Ordner legen und Text lokal auslesen
        $dir = BASE_PATH . '/uploads/documents/' . $vehicleId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            json_response(false, null, 'Arbeitsverzeichnis konnte nicht angelegt werden.', 500);
        }
        $pdfPath = $dir . '/doc-' . bin2hex(random_bytes(8)) . '.pdf';
        if (!move_uploaded_file($upload['tmp_name'], $pdfPath)) {
            json_response(false, null, 'Das Dokument konnte nicht gespeichert werden.', 500);
        }
        $tempPaths[] = $pdfPath;

        $documentText = PdfTextExtractor::extract($pdfPath);
        $source = 'pdf';

        if (trim($documentText) === '') {
            // Eingescanntes PDF ohne Textebene
            foreach ($tempPaths as $path) {
                @unlink($path);
            }
            json_response(false, null,
                'Dieses PDF enthält keinen auslesbaren Text, es ist vermutlich ein reiner Scan. '
                . 'Bitte ein Foto des Dokuments hochladen, dann wird es als Bild ausgewertet.', 422);
        }
    } else {
        // Bild: erst normal verarbeiten (validiert und kodiert neu)
        try {
            $processed = ImageService::processUpload($upload, 'documents/' . $vehicleId);
        } catch (\RuntimeException $e) {
            json_response(false, null, $e->getMessage(), 422);
        }
        foreach (['full', 'card', 'thumb'] as $variant) {
            $tempPaths[] = ImageService::uploadPath($processed[$variant]);
        }
        $source = 'image';
    }

    // ------------------------------------------------ Schritt 1: ohne Kosten
    $fields = [];
    $note = '';
    if ($documentText !== '') {
        $parsed = DocumentParser::parse($documentText);
        $fields = $parsed['fields'];
        $note = $parsed['note'];
    }

    // ------------------- Schritt 2: KI nur, wenn zu wenig gefunden wurde
    $usedAi = false;
    // Die KI springt nur ein, wenn Guthaben vorhanden ist
    $mayUseAi = \App\Service\CreditService::hasCredits($dealershipId);
    if (count($fields) < 3 && $mayUseAi) {
        if (!AIService::isLiveReady()) {
            if ($fields === []) {
                json_response(false, null,
                    'Aus dem Dokument liessen sich keine Angaben lesen. Für die Auswertung per KI '
                    . 'wird ein hinterlegter OpenAI-Schlüssel benötigt.', 422);
            }
        } else {
            try {
                $detection = $documentText !== ''
                    // Der Text kostet einen Bruchteil eines Bildes
                    ? AIService::provider()->extractDocumentText($documentText)
                    : AIService::provider()->extractDocument($tempPaths[0]);
                // Regelbasierte Treffer haben Vorrang: sie stammen direkt aus dem Dokument
                $fields = $fields + $detection['fields'];
                $note = trim($note . ' ' . ($detection['note'] ?? ''));
                $usedAi = true;
            } catch (\Throwable $e) {
                if ($fields === []) {
                    json_response(false, null, $e->getMessage(), 502);
                }
            }
        }
    }

    // Unplausible Werte aussortieren, bevor sie ins Inserat wandern
    $checked = \App\Service\FieldPlausibility::check($fields);
    $fields = $checked['fields'];
    if ($checked['notes'] !== []) {
        $note = trim($note . ' ' . implode(' ', $checked['notes']));
    }

    $applied = AIVehicleService::applyToEmptyFields($vehicleId, $fields);
} finally {
    // In jedem Fall entfernen: erfolgreich, abgebrochen oder fehlgeschlagen
    foreach ($tempPaths as $path) {
        @unlink($path);
    }
}

ActivityLogger::log(
    (int) $currentUser['id'],
    'vehicle.document_extracted',
    'Dokument (' . $source . ') ausgewertet und gelöscht, '
    . count($fields) . ' Felder, KI: ' . ($usedAi ? 'ja' : 'nein'),
    'vehicle',
    $vehicleId,
    $dealershipId
);

json_response(true, [
    'detection' => [
        'detected'   => $fields !== [],
        'label'      => null,
        'confidence' => null,
        'fields'     => $fields,
        'note'       => $note,
        'mode'       => $usedAi ? 'live' : 'local',
    ],
    'applied'       => $applied,
    'used_ai'       => $usedAi,
    'document_kept' => false,
]);
