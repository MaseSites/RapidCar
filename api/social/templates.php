<?php
/**
 * Gestaltungsvorlagen fuer Social-Posts: auflisten, speichern, umbenennen,
 * loeschen. Eine Vorlage gehoert immer genau einem Konto; fremde Vorlagen
 * sind weder sicht- noch aenderbar.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;

$dealershipId = require_dealership();

/** Alle Vorlagen des Kontos, alphabetisch. */
function template_list(int $dealershipId): array
{
    $rows = Database::fetchAll(
        'SELECT id, name, settings FROM post_templates WHERE dealership_id = :d ORDER BY name',
        ['d' => $dealershipId]
    );
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $decoded = json_decode((string) $row['settings'], true);
        $row['settings'] = is_array($decoded) ? $decoded : [];
    }
    return $rows;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_response(true, ['templates' => template_list($dealershipId)]);
}

guard_demo_mode();

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = (string) ($input['action'] ?? '');
$now = Database::now();

if ($action === 'save') {
    $name = trim(mb_substr((string) ($input['name'] ?? ''), 0, 80));
    if ($name === '') {
        json_response(false, null, 'Bitte einen Namen für die Vorlage angeben.', 422);
    }
    $settings = $input['settings'] ?? null;
    if (!is_array($settings)) {
        json_response(false, null, 'Keine Einstellungen übermittelt.', 422);
    }
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE);
    if ($encoded === false || strlen($encoded) > 8000) {
        json_response(false, null, 'Die Einstellungen sind zu umfangreich.', 422);
    }

    // Gleicher Name ueberschreibt die bestehende Vorlage, statt Duplikate
    // anzuhaeufen.
    $existing = Database::fetch(
        'SELECT id FROM post_templates WHERE dealership_id = :d AND name = :n',
        ['d' => $dealershipId, 'n' => $name]
    );
    if ($existing !== null) {
        Database::update('post_templates', (int) $existing['id'], [
            'settings'   => $encoded,
            'updated_at' => $now,
        ]);
    } else {
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM post_templates WHERE dealership_id = :d',
            ['d' => $dealershipId]
        );
        if ($count >= 30) {
            json_response(false, null, 'Höchstens 30 Vorlagen möglich. Bitte zuerst eine löschen.', 422);
        }
        Database::insert('post_templates', [
            'dealership_id' => $dealershipId,
            'name'          => $name,
            'settings'      => $encoded,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
    json_response(true, ['templates' => template_list($dealershipId)]);
}

if ($action === 'rename' || $action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    $owned = Database::fetch(
        'SELECT id FROM post_templates WHERE id = :i AND dealership_id = :d',
        ['i' => $id, 'd' => $dealershipId]
    );
    if ($owned === null) {
        json_response(false, null, 'Vorlage nicht gefunden.', 404);
    }

    if ($action === 'delete') {
        Database::run('DELETE FROM post_templates WHERE id = :i', ['i' => $id]);
    } else {
        $name = trim(mb_substr((string) ($input['name'] ?? ''), 0, 80));
        if ($name === '') {
            json_response(false, null, 'Bitte einen Namen angeben.', 422);
        }
        $clash = Database::fetch(
            'SELECT id FROM post_templates WHERE dealership_id = :d AND name = :n AND id != :i',
            ['d' => $dealershipId, 'n' => $name, 'i' => $id]
        );
        if ($clash !== null) {
            json_response(false, null, 'Eine Vorlage mit diesem Namen gibt es schon.', 422);
        }
        Database::update('post_templates', $id, ['name' => $name, 'updated_at' => $now]);
    }
    json_response(true, ['templates' => template_list($dealershipId)]);
}

json_response(false, null, 'Unbekannte Aktion.', 422);
