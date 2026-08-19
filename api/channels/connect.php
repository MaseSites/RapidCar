<?php
/**
 * Startet den OAuth-Fluss eines Kanals.
 *
 * Ohne hinterlegte Zugangsdaten wird nichts vorgetäuscht: Der Benutzer erhält
 * einen klaren Hinweis, welche Konfigurationswerte fehlen.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Session;
use App\Integration\ChannelRegistry;

$dealershipId = require_dealership();
guard_demo_mode();

$channelKey = (string) ($_GET['channel'] ?? '');
$channel = ChannelRegistry::get($channelKey);

if ($channel === null) {
    Session::flash('danger', 'Unbekannter Kanal.');
    redirect('dashboard/channels.php');
}

// Der Testkanal braucht kein OAuth: er verbindet sich sofort und nimmt
// Inserate nur lokal entgegen.
if ($channelKey === ChannelRegistry::TEST_PROVIDER) {
    $existing = App\Core\Database::fetch(
        'SELECT id FROM integrations WHERE dealership_id = :d AND provider = :p',
        ['d' => $dealershipId, 'p' => $channelKey]
    );
    $now = App\Core\Database::now();
    if ($existing === null) {
        App\Core\Database::insert('integrations', [
            'dealership_id' => $dealershipId,
            'provider'      => $channelKey,
            'status'        => 'connected',
            'connected_at'  => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    } else {
        App\Core\Database::update('integrations', (int) $existing['id'], [
            'status'       => 'connected',
            'connected_at' => $now,
            'updated_at'   => $now,
        ]);
    }
    App\Service\ActivityLogger::log(
        (int) $currentUser['id'],
        'channel.connected',
        'Testkanal verbunden',
        'integration',
        null,
        $dealershipId
    );
    Session::flash('success', $channel['name'] . ': ' . t('channels.test_connected'));
    redirect('dashboard/channels.php');
}

$client = ChannelRegistry::client($channelKey);
if (!$client->isConfigured()) {
    Session::flash(
        'warning',
        $channel['name'] . ': ' . t('channels.not_configured_hint')
        . ' (' . implode(', ', $client->missingConfig()) . ')'
    );
    redirect('dashboard/channels.php');
}

try {
    $state = bin2hex(random_bytes(24));
    Session::set('channel_oauth_state', ['state' => $state, 'channel' => $channelKey, 'dealership_id' => $dealershipId]);
    header('Location: ' . $client->authorizationUrl($state));
    exit;
} catch (\RuntimeException $e) {
    Session::flash('danger', $e->getMessage());
    redirect('dashboard/channels.php');
}
