<?php
/**
 * Score-Neuberechnung (§32): regelbasierte Engine (bzw. Live-KI, §54).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIScoreService;
use App\Core\Database;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$listingId = (int) ($input['listing_id'] ?? 0);

$listing = Database::fetch(
    'SELECT * FROM listings WHERE id = :id AND dealership_id = :did',
    ['id' => $listingId, 'did' => $dealershipId]
);
if ($listing === null) {
    json_response(false, null, 'Inserat nicht gefunden.', 404);
}

$result = AIScoreService::evaluate($listingId);
json_response(true, $result);
