<?php
/**
 * Bild-Aktionen (§25): Hauptbild setzen, löschen, sortieren.
 * POST JSON: {action: 'set_main'|'delete'|'sort', image_id?, order?: [ids]}
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
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
$action = (string) ($input['action'] ?? '');

/** Bild laden und Mandanten-Zugehörigkeit prüfen. */
function load_owned_image(int $imageId, int $dealershipId): array
{
    $image = App\Core\Database::fetch(
        'SELECT vi.* FROM vehicle_images vi
         INNER JOIN vehicles v ON v.id = vi.vehicle_id
         WHERE vi.id = :id AND v.dealership_id = :did',
        ['id' => $imageId, 'did' => $dealershipId]
    );
    if ($image === null) {
        json_response(false, null, 'Bild nicht gefunden.', 404);
    }
    return $image;
}

switch ($action) {
    case 'set_main':
        $image = load_owned_image((int) ($input['image_id'] ?? 0), $dealershipId);
        Database::run('UPDATE vehicle_images SET is_main = 0 WHERE vehicle_id = :vid', ['vid' => (int) $image['vehicle_id']]);
        Database::update('vehicle_images', (int) $image['id'], ['is_main' => 1]);
        json_response(true, ['image_id' => (int) $image['id']]);

    case 'delete':
        $image = load_owned_image((int) ($input['image_id'] ?? 0), $dealershipId);
        ImageService::deleteVariants(
            $image['file_path'] ?? null,
            $image['card_path'] ?? null,
            $image['thumb_path'] ?? null
        );
        Database::run('DELETE FROM vehicle_images WHERE id = :id', ['id' => (int) $image['id']]);
        // Falls Hauptbild gelöscht: erstes verbleibendes Bild wird Hauptbild
        if ((int) $image['is_main'] === 1) {
            $next = Database::fetch(
                'SELECT id FROM vehicle_images WHERE vehicle_id = :vid ORDER BY sort_order LIMIT 1',
                ['vid' => (int) $image['vehicle_id']]
            );
            if ($next !== null) {
                Database::update('vehicle_images', (int) $next['id'], ['is_main' => 1]);
            }
        }
        json_response(true, null);

    case 'sort':
        $order = $input['order'] ?? [];
        if (!is_array($order) || $order === []) {
            json_response(false, null, 'Keine Sortierreihenfolge übermittelt.', 422);
        }
        foreach (array_values($order) as $position => $imageId) {
            $image = load_owned_image((int) $imageId, $dealershipId);
            Database::update('vehicle_images', (int) $image['id'], ['sort_order' => $position]);
        }
        json_response(true, null);

    default:
        json_response(false, null, 'Unbekannte Aktion.', 422);
}
