<?php
/**
 * Kanäle: Verkaufsplattformen und soziale Netzwerke.
 *
 * Jeder Kanal zeigt seinen echten Zustand. Ohne hinterlegte Zugangsdaten
 * steht dort "Nicht konfiguriert"; es wird keine Verbindung vorgetäuscht.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Session;
use App\Integration\ChannelRegistry;
use App\Service\ActivityLogger;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $channelKey = (string) ($_POST['channel'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (ChannelRegistry::exists($channelKey) && $action === 'disconnect') {
        ChannelRegistry::disconnect($dealershipId, $channelKey);
        ActivityLogger::log(
            (int) $currentUser['id'],
            'channel.disconnected',
            'Kanal getrennt: ' . $channelKey,
            'integration',
            null,
            $dealershipId
        );
        Session::flash('success', t('channels.disconnect') . ': ' . (ChannelRegistry::get($channelKey)['name'] ?? $channelKey));
    }
    redirect('dashboard/channels.php');
}

// Region des Autohauses: Kanäle, die dort nicht nutzbar sind, stehen
// standardmässig nicht in der Liste. Ein Schweizer Händler braucht mobile.de
// nicht zu sehen. Über "Alle Regionen" bleiben sie trotzdem erreichbar.
$dealership = App\Core\Database::fetch('SELECT country FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
$country = strtoupper((string) ($dealership['country'] ?? ''));
$showAllRegions = ($_GET['regions'] ?? '') === 'all';

$channels = ChannelRegistry::overview($dealershipId);
if (!$showAllRegions && $country !== '') {
    $allowed = ChannelRegistry::forCountry($country);
    $channels = array_filter(
        $channels,
        static fn(array $c): bool => isset($allowed[$c['key']]) || $c['status'] === 'connected'
    );
}
$hiddenByRegion = count(ChannelRegistry::all()) - count(ChannelRegistry::forCountry($country));

$marketplaces = array_filter($channels, static fn(array $c): bool => $c['type'] === ChannelRegistry::TYPE_MARKETPLACE);
$socials = array_filter($channels, static fn(array $c): bool => $c['type'] === ChannelRegistry::TYPE_SOCIAL);
$connectedCount = count(array_filter($channels, static fn(array $c): bool => $c['status'] === 'connected'));

/** Rendert eine Kanal-Karte. */
function render_channel_card(array $channel): void
{
    $status = $channel['status'];
    ?>
    <div class="integration-card" data-search="<?= e(mb_strtolower($channel['name'] . ' ' . $channel['region'] . ' ' . $channel['note'])) ?>">
        <div class="logo-box"><?= icon($channel['icon'], 20) ?></div>
        <div class="body">
            <h3><?= e($channel['name']) ?> <span class="text-xs text-muted fw-600"><?= e($channel['region']) ?></span></h3>
            <div class="status">
                <?php if ($status === 'connected'): ?>
                    <span class="status-dot green"></span> <?= t('channels.status.connected') ?>
                    <?php if (!empty($channel['account_name'])): ?>
                        <span class="text-muted"><?= e((string) $channel['account_name']) ?></span>
                    <?php endif; ?>
                <?php elseif ($status === 'not_configured'): ?>
                    <span class="status-dot gray"></span> <?= t('channels.status.not_configured') ?>
                    <span class="text-muted text-xs"><?= t('channels.not_configured_hint') ?></span>
                <?php else: ?>
                    <span class="status-dot yellow"></span> <?= t('channels.status.disconnected') ?>
                <?php endif; ?>
            </div>
            <div class="text-xs text-muted mt-1"><?= e($channel['note']) ?></div>
        </div>
        <div class="flex gap-1" style="flex-wrap:wrap">
            <?php if ($status === 'connected'): ?>
                <?php if (isset(ChannelRegistry::CONNECT_PAGES[$channel['key']])): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= e(ChannelRegistry::connectUrl($channel['key'])) ?>">
                        <?= icon('settings', 14) ?> <?= t('common.edit') ?>
                    </a>
                <?php else: ?>
                    <form method="post" data-confirm="<?= t('channels.disconnect') ?>?">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="channel" value="<?= e($channel['key']) ?>">
                        <input type="hidden" name="action" value="disconnect">
                        <button class="btn btn-danger btn-sm" type="submit"><?= t('channels.disconnect') ?></button>
                    </form>
                <?php endif; ?>
            <?php elseif ($status === 'disconnected' || $status === 'error'): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(ChannelRegistry::connectUrl($channel['key'])) ?>">
                    <?= t('channels.connect') ?>
                </a>
            <?php else: ?>
                <button class="btn btn-secondary btn-sm" type="button" disabled title="<?= t('channels.prepared') ?>">
                    <?= t('channels.connect') ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$pageTitle = t('channels.title');
$activeNav = 'channels';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('channels.title') ?></h1>
        <div class="sub"><?= t('channels.lead') ?></div>
    </div>
    <div class="text-sm text-secondary"><?= t('channels.connected_count', ['count' => $connectedCount, 'total' => count($channels)]) ?></div>
</div>

<div class="channel-toolbar">
    <div class="feature-search" style="flex:1;padding:0;border:0;background:none">
        <?= icon('search', 15) ?>
        <input type="text" class="form-control" id="channelSearch" autocomplete="off"
               placeholder="<?= e(t('channels.search')) ?>">
    </div>
    <?php if ($hiddenByRegion > 0): ?>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('dashboard/channels.php' . ($showAllRegions ? '' : '?regions=all')) ?>">
            <?= icon($showAllRegions ? 'eye' : 'globe', 14) ?>
            <?= $showAllRegions
                ? t('channels.region_only', ['country' => e($country)])
                : t('channels.region_all', ['count' => $hiddenByRegion]) ?>
        </a>
    <?php endif; ?>
</div>
<div class="text-sm text-muted mb-3" id="channelNoMatch" style="display:none"><?= t('channels.no_match') ?></div>

<div class="card mb-3">
    <div class="card-header">
        <h2><?= t('channels.marketplaces') ?></h2>
        <span class="text-sm text-muted"><?= count($marketplaces) ?></span>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($marketplaces as $channel): ?>
            <?php render_channel_card($channel); ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= t('channels.social') ?></h2>
        <span class="text-sm text-muted"><?= count($socials) ?></span>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($socials as $channel): ?>
            <?php render_channel_card($channel); ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="alert alert-info mt-3">
    <?= icon('info', 16) ?>
    <span><?= t('channels.prepared') ?></span>
</div>

<?php
$jsNoMatch = json_encode(t('channels.no_match'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$pageScripts = <<<HTML
<script>
(function () {
    // Suche läuft rein im Browser: keine zusätzliche Anfrage an den Server.
    var search = document.getElementById('channelSearch');
    var cards = document.querySelectorAll('.integration-card');
    var noMatch = document.getElementById('channelNoMatch');
    if (!search) { return; }

    search.addEventListener('input', function () {
        var needle = search.value.trim().toLowerCase();
        var hits = 0;
        Array.prototype.forEach.call(cards, function (card) {
            var hit = needle === '' || (card.dataset.search || '').indexOf(needle) !== -1;
            card.style.display = hit ? '' : 'none';
            if (hit) { hits++; }
        });
        noMatch.style.display = hits === 0 ? '' : 'none';
        // Leere Abschnitte ausblenden, damit keine leeren Karten stehen bleiben.
        Array.prototype.forEach.call(document.querySelectorAll('.card'), function (section) {
            var inner = section.querySelectorAll('.integration-card');
            if (inner.length === 0) { return; }
            var visible = Array.prototype.filter.call(inner, function (c) { return c.style.display !== 'none'; });
            section.style.display = visible.length ? '' : 'none';
        });
    });
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
