<?php
/**
 * Gespeicherten Social-Post auf Instagram veröffentlichen.
 * Braucht eine echte Instagram-Verbindung; sonst klare Fehlermeldung (§72).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Integration\InstagramService;
use App\Service\ActivityLogger;

$dealershipId = require_dealership();
guard_demo_mode();
// Veroeffentlichen auf Instagram gehoert zu RapidCar Plus.
guard_subscription($dealershipId, 'instagram');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$postId = (int) ($input['post_id'] ?? 0);
if ($postId <= 0) {
    json_response(false, null, 'Kein Post angegeben.', 422);
}

$isTest = InstagramService::isTestMode($dealershipId);

try {
    $mediaId = InstagramService::publish($dealershipId, $postId);
} catch (\Throwable $e) {
    json_response(false, null, $e->getMessage(), 422);
}

ActivityLogger::log(
    (int) $currentUser['id'],
    'social.post_published',
    $isTest
        ? "Social-Post #{$postId} testweise veröffentlicht ({$mediaId}), nicht an Instagram gesendet"
        : "Social-Post #{$postId} auf Instagram veröffentlicht (Media {$mediaId})",
    'social_post',
    $postId,
    $dealershipId
);

json_response(true, ['post_id' => $postId, 'media_id' => $mediaId, 'test_mode' => $isTest]);
