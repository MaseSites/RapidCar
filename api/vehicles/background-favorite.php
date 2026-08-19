<?php
/**
 * Hintergrund als Favorit merken oder entfernen.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Service\BackgroundService;

$dealershipId = require_dealership();
guard_demo_mode();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$key = (string) ($input['key'] ?? '');

try {
    $isFavorite = BackgroundService::toggleFavorite($dealershipId, $key);
} catch (\RuntimeException $e) {
    json_response(false, null, $e->getMessage(), 422);
}

json_response(true, ['key' => $key, 'favorite' => $isFavorite]);
