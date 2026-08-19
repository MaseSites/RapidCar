<?php
/**
 * KI-Antwortentwurf (§42/§43): liefert einen Vorschlag mit Sicherheitsfilter.
 * Der Entwurf wird NICHT gesendet — nur ins Antwortfeld übernommen.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIException;
use App\AI\AILeadService;
use App\Core\Database;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$leadId = (int) ($input['lead_id'] ?? 0);

$lead = Database::fetch(
    'SELECT id FROM leads WHERE id = :id AND dealership_id = :did',
    ['id' => $leadId, 'did' => $dealershipId]
);
if ($lead === null) {
    json_response(false, null, 'Anfrage nicht gefunden.', 404);
}

try {
    $result = AILeadService::draftReply($leadId);
    json_response(true, $result);
} catch (AIException $e) {
    json_response(false, null, $e->getMessage(), 422);
}
