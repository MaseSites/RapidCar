<?php
/**
 * OAuth-Callback eines Kanals: Code gegen Tokens tauschen, verschlüsselt
 * speichern und die Verbindung markieren.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;
use App\Core\Session;
use App\Integration\ChannelRegistry;
use App\Integration\InstagramService;
use App\Integration\TokenStore;
use App\Service\ActivityLogger;

require_dealership();

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$oauthError = (string) ($_GET['error'] ?? '');

$saved = Session::get('channel_oauth_state');
Session::remove('channel_oauth_state');

if ($oauthError !== '') {
    Session::flash('danger', 'Die Verbindung wurde abgelehnt: ' . $oauthError);
    redirect('dashboard/channels.php');
}
if (!is_array($saved) || $code === '' || !hash_equals((string) ($saved['state'] ?? ''), $state)) {
    Session::flash('danger', 'Ungültige Antwort. Bitte die Verbindung erneut starten.');
    redirect('dashboard/channels.php');
}

$channelKey = (string) $saved['channel'];
$dealershipId = (int) $saved['dealership_id'];
$channel = ChannelRegistry::get($channelKey);

try {
    $tokens = ChannelRegistry::client($channelKey)->exchangeCode($code);
    TokenStore::save($dealershipId, $channelKey, $tokens['access_token'], $tokens['refresh_token'], $tokens['expires_in']);

    $now = Database::now();
    $existing = ChannelRegistry::integrationRow($dealershipId, $channelKey);
    $data = ['status' => 'connected', 'connected_at' => $now, 'updated_at' => $now];
    if ($existing !== null) {
        Database::update('integrations', (int) $existing['id'], $data);
    } else {
        Database::insert('integrations', $data + [
            'dealership_id' => $dealershipId,
            'provider'      => $channelKey,
            'created_at'    => $now,
        ]);
    }

    // Instagram braucht zwei Schritte mehr: das kurzlebige Token gegen ein
    // langlebiges tauschen und den Kontonamen merken. Ohne das waere die
    // Verbindung nach einer Stunde tot.
    if ($channelKey === InstagramService::PROVIDER) {
        InstagramService::completeConnection($dealershipId);
    }

    ActivityLogger::log(
        (int) $currentUser['id'],
        'channel.connected',
        'Kanal verbunden: ' . $channelKey,
        'integration',
        null,
        $dealershipId
    );
    Session::flash('success', ($channel['name'] ?? $channelKey) . ': ' . t('channels.status.connected'));
} catch (\RuntimeException $e) {
    Session::flash('danger', $e->getMessage());
}

redirect('dashboard/channels.php');
