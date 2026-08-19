<?php
/**
 * Gleicht alle verbundenen Kanäle ab.
 *
 * Antwortet als JSON (für den Aktualisieren-Knopf) oder leitet zurück,
 * wenn die Anfrage aus einem Formular kommt.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Session;
use App\Service\ChannelSyncService;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, null, 'Nur POST erlaubt.', 405);
}

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0;

if (!ChannelSyncService::hasConnectedChannel($dealershipId)) {
    if ($wantsJson) {
        json_response(true, ['synced' => [], 'changed' => false, 'connected' => false]);
    }
    Session::flash('info', t('channels.sync_no_channels'));
    redirect('dashboard/vehicles.php');
}

$report = ChannelSyncService::syncAll($dealershipId, (int) $currentUser['id']);

if ($wantsJson) {
    json_response(true, [
        'connected'   => true,
        'synced'      => $report['synced'],
        'errors'      => $report['errors'],
        'matched'     => $report['matched'],
        'remote_only' => $report['remote_only'],
        'changed'     => $report['changed'],
        'last_sync'   => ChannelSyncService::lastSyncedAt($dealershipId),
    ]);
}

if ($report['errors'] !== []) {
    Session::flash('danger', implode(' | ', $report['errors']));
}
if ($report['synced'] !== []) {
    Session::flash('success', t('channels.sync_done', [
        'channels' => implode(', ', $report['synced']),
        'matched'  => $report['matched'],
    ]));
}
redirect('dashboard/vehicles.php');
