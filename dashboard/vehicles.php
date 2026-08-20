<?php
/**
 * Inserate: eine Zeile je Fahrzeug, Bild links, Daten in der Mitte,
 * Preis und Aktionen rechts. Zwei Ansichten genügen: aktiv und verkauft.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Core\Session;
use App\Integration\ChannelRegistry;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\ChannelSyncService;
use App\Service\ListingService;

$dealershipId = require_dealership();

// ------------------------------------------------------------- Aktionen
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $action = (string) ($_POST['action'] ?? '');
    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
    $vehicle = VehicleRepository::find($vehicleId, $dealershipId);
    $view = (string) ($_POST['view'] ?? 'active');

    if ($vehicle === null) {
        Session::flash('danger', t('vehicles.not_found'));
        redirect('dashboard/vehicles.php');
    }

    if ($action === 'pause') {
        // Pausieren nimmt das Inserat offline: der Kanaleintrag wird
        // stillgelegt, die Daten bleiben vollstaendig erhalten.
        Database::update('vehicles', $vehicleId, ['status' => 'paused', 'updated_at' => Database::now()]);
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        Database::update('listings', (int) $listing['id'], ['status' => 'paused', 'updated_at' => Database::now()]);
        Database::run(
            "UPDATE channel_listings SET status = 'inactive', updated_at = :now WHERE listing_id = :lid",
            ['now' => Database::now(), 'lid' => (int) $listing['id']]
        );
        ActivityLogger::log((int) $currentUser['id'], 'vehicle.paused', "Inserat #{$vehicleId} pausiert", 'vehicle', $vehicleId, $dealershipId);
        Session::flash('success', t('vehicles.paused_done'));
    } elseif ($action === 'resume') {
        // Zurueckholen: wieder online, mit demselben Text und denselben Bildern
        Database::update('vehicles', $vehicleId, ['status' => 'published', 'updated_at' => Database::now()]);
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        Database::update('listings', (int) $listing['id'], [
            'status'       => 'published',
            'published_at' => $listing['published_at'] ?? Database::now(),
            'updated_at'   => Database::now(),
        ]);
        Database::run(
            "UPDATE channel_listings SET status = 'active', updated_at = :now WHERE listing_id = :lid",
            ['now' => Database::now(), 'lid' => (int) $listing['id']]
        );
        ActivityLogger::log((int) $currentUser['id'], 'vehicle.resumed', "Inserat #{$vehicleId} wieder online", 'vehicle', $vehicleId, $dealershipId);
        Session::flash('success', t('vehicles.resumed_done'));
    } elseif ($action === 'delete') {
        VehicleRepository::delete($vehicleId, $dealershipId);
        ActivityLogger::log((int) $currentUser['id'], 'vehicle.deleted', "Fahrzeug #{$vehicleId} gelöscht", 'vehicle', $vehicleId, $dealershipId);
        Session::flash('success', t('vehicle.deleted'));
    }

    redirect('dashboard/vehicles.php' . ($view !== 'published' ? '?view=' . urlencode($view) : ''));
}

// --------------------------------------------------------------- Ansicht
$view = (string) ($_GET['view'] ?? 'published');
if (!in_array($view, ['published', 'draft', 'paused'], true)) {
    $view = 'published';
}
$search = trim((string) ($_GET['q'] ?? ''));

$all = VehicleRepository::listWithMeta($dealershipId, '', $search);

/** Ordnet jedes Fahrzeug einer der drei Ansichten zu. */
$bucketOf = static function (array $vehicle): string {
    return match ((string) $vehicle['status']) {
        'published' => 'published',
        'paused'    => 'paused',
        default     => 'draft',
    };
};

$vehicles = array_values(array_filter($all, static fn(array $v): bool => $bucketOf($v) === $view));
$counts = ['published' => 0, 'draft' => 0, 'paused' => 0];
foreach ($all as $vehicle) {
    $counts[$bucketOf($vehicle)]++;
}

// Kanal-Zuordnung und Abgleichsstand
$channelsByVehicle = ChannelSyncService::channelsByVehicle($dealershipId);
$remoteOnly = ChannelSyncService::remoteOnly($dealershipId);
$hasChannels = ChannelSyncService::hasConnectedChannel($dealershipId);
$lastSync = ChannelSyncService::lastSyncedAt($dealershipId);
$isStale = ChannelSyncService::isStale($dealershipId);

/** Kurzname eines Kanals für die Marker. */
function channel_short_name(string $key): string
{
    $channel = ChannelRegistry::get($key);
    return $channel !== null ? $channel['name'] : $key;
}

$pageTitle = t('vehicles.title');
$activeNav = 'vehicles';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('vehicles.title') ?></h1>
        <div class="sub">
            <?= t('vehicles.count', ['count' => count($vehicles)]) ?>
            <?php if ($hasChannels): ?>
                <span class="text-muted" id="lastSyncLabel">
                    <?= t('channels.last_sync') ?>:
                    <?= $lastSync !== null ? e(time_ago($lastSync)) : t('channels.never_synced') ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="flex gap-1" style="flex-wrap:wrap">
        <?php if ($hasChannels): ?>
            <form method="post" action="<?= base_url('api/channels/sync.php') ?>" id="syncForm">
                <?= App\Core\Csrf::field() ?>
                <button class="btn btn-secondary" type="submit" id="syncBtn">
                    <?= icon('refresh', 15) ?> <?= t('channels.refresh') ?>
                </button>
            </form>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= base_url('dashboard/create-vehicle.php') ?>">
            <?= icon('plus', 15) ?> <?= t('vehicles.add') ?>
        </a>
    </div>
</div>

<!-- Zwei Ansichten und ein Suchfeld: mehr braucht die Liste nicht -->
<div class="list-toolbar">
    <div class="segmented">
        <a class="<?= $view === 'published' ? 'is-active' : '' ?>" href="<?= base_url('dashboard/vehicles.php') ?>">
            <?= t('status.published') ?><span class="segmented-count"><?= $counts['published'] ?></span>
        </a>
        <a class="<?= $view === 'draft' ? 'is-active' : '' ?>" href="<?= base_url('dashboard/vehicles.php?view=draft') ?>">
            <?= t('status.draft') ?><span class="segmented-count"><?= $counts['draft'] ?></span>
        </a>
        <a class="<?= $view === 'paused' ? 'is-active' : '' ?>" href="<?= base_url('dashboard/vehicles.php?view=paused') ?>">
            <?= t('status.paused') ?><span class="segmented-count"><?= $counts['paused'] ?></span>
        </a>
    </div>
    <div class="search-field">
        <?= icon('search', 16) ?>
        <input type="text" id="listFilter" value="<?= e($search) ?>"
               placeholder="<?= e(t('vehicles.search')) ?>" autocomplete="off">
    </div>
</div>

<?php if ($vehicles === []): ?>
    <div class="card">
        <div class="empty-state">
            <h3><?= t('vehicles.empty.title') ?></h3>
            <p><?= match ($view) {
                'paused' => t('vehicles.empty.paused'),
                'draft'  => t('vehicles.empty.draft'),
                default  => t('vehicles.empty.published'),
            } ?></p>
            <?php if ($view === 'draft'): ?>
                <a class="btn btn-primary" href="<?= base_url('dashboard/create-vehicle.php') ?>"><?= icon('plus', 15) ?> <?= t('vehicles.add') ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="listing-list" id="listingList">
        <?php foreach ($vehicles as $vehicle): ?>
            <?php
            $vehicleId = (int) $vehicle['id'];
            $score = $vehicle['score'] !== null ? (int) $vehicle['score'] : null;
            $vehicleChannels = $channelsByVehicle[$vehicleId] ?? [];
            $rowName = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
            $detailUrl = base_url('dashboard/vehicle.php?id=' . $vehicleId);
            ?>
            <article class="listing-row" data-name="<?= e(mb_strtolower($rowName . ' ' . ($vehicle['variant'] ?? ''))) ?>">
                <a class="listing-media" href="<?= $detailUrl ?>">
                    <?php if ($vehicle['thumb'] !== null): ?>
                        <img src="<?= e(upload_url((string) $vehicle['thumb'])) ?>" alt="">
                    <?php else: ?>
                        <span class="listing-media-empty"><?= icon('image', 20) ?></span>
                    <?php endif; ?>
                </a>

                <div class="listing-main">
                    <a class="listing-name" href="<?= $detailUrl ?>"><?= e($rowName !== '' ? $rowName : t('vehicles.unnamed')) ?></a>
                    <div class="listing-sub"><?= e((string) ($vehicle['variant'] ?? '')) ?></div>

                    <div class="listing-specs">
                        <?php if ($vehicle['mileage'] !== null): ?>
                            <span><?= icon('refresh', 13) ?> <?= e(format_km($vehicle['mileage'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($vehicle['first_registration'])): ?>
                            <span><?= icon('calendar', 13) ?> <?= e((string) $vehicle['first_registration']) ?></span>
                        <?php elseif ($vehicle['year'] !== null): ?>
                            <span><?= icon('calendar', 13) ?> <?= (int) $vehicle['year'] ?></span>
                        <?php endif; ?>
                        <?php if (!empty($vehicle['fuel_type'])): ?>
                            <span><?= icon('activity', 13) ?> <?= e(t('fuel.' . (string) $vehicle['fuel_type'])) ?></span>
                        <?php endif; ?>
                        <?php if ($vehicle['power_hp'] !== null): ?>
                            <span><?= icon('chart', 13) ?> <?= (int) $vehicle['power_hp'] ?> PS</span>
                        <?php endif; ?>
                    </div>

                    <div class="listing-tags">
                        <?php // Der Status steht schon in der gewaehlten Ansicht, hier nur die Kanaele ?>
                        <?php foreach ($vehicleChannels as $provider => $channelStatus): ?>
                            <span class="badge badge-neutral">
                                <span class="status-dot <?= $channelStatus === 'active' ? 'green' : 'gray' ?>"></span>
                                <?= e(channel_short_name($provider)) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="listing-side">
                    <div class="listing-price"><?= format_price($vehicle['price']) ?></div>
                    <?php if ($score !== null): ?>
                        <div class="listing-score"><?= t('dash.listing_score') ?> <strong><?= $score ?></strong></div>
                    <?php endif; ?>

                    <div class="listing-actions">
                        <a class="btn btn-secondary btn-sm" href="<?= base_url('dashboard/listing-editor.php?id=' . $vehicleId) ?>">
                            <?= icon('eye', 13) ?> <?= t('common.preview') ?>
                        </a>
                        <a class="btn btn-secondary btn-sm" href="<?= $detailUrl ?>">
                            <?= t('common.edit') ?> <?= icon('chevron-right', 13) ?>
                        </a>
                        <form method="post">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                            <input type="hidden" name="view" value="<?= e($view) ?>">
                            <?php if ($view === 'paused'): ?>
                                <input type="hidden" name="action" value="resume">
                                <button class="btn btn-secondary btn-sm" type="submit"><?= t('vehicles.resume') ?></button>
                            <?php elseif ($view === 'published'): ?>
                                <input type="hidden" name="action" value="pause">
                                <button class="btn btn-secondary btn-sm" type="submit"><?= t('vehicles.pause') ?></button>
                            <?php endif; ?>
                        </form>
                        <form method="post" data-confirm="<?= e(t('vehicle.delete.confirm')) ?>">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                            <input type="hidden" name="view" value="<?= e($view) ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="btn btn-ghost btn-sm listing-delete" type="submit" title="<?= e(t('common.delete')) ?>">
                                <?= icon('trash', 14) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <p class="text-sm text-muted" id="noMatch" style="display:none;text-align:center;padding:22px"><?= t('vehicles.no_match') ?></p>
    </div>
<?php endif; ?>

<?php if ($remoteOnly !== [] && $view === 'published'): ?>
    <!-- Inserate, die nur auf einem Kanal existieren -->
    <div class="card mt-3">
        <div class="card-header">
            <h2 class="card-title"><?= t('channels.remote_only_title') ?></h2>
            <span class="badge badge-warning"><?= count($remoteOnly) ?></span>
        </div>
        <div class="card-body">
            <p class="text-secondary text-sm mb-2"><?= t('channels.remote_only_hint') ?></p>
            <?php foreach ($remoteOnly as $remote): ?>
                <div class="remote-row">
                    <div>
                        <div class="fw-600 text-sm"><?= e($remote['title'] ?? t('vehicles.unnamed')) ?></div>
                        <div class="text-xs text-muted">
                            <?= e(channel_short_name((string) $remote['provider'])) ?> ·
                            <code><?= e((string) $remote['external_id']) ?></code>
                        </div>
                    </div>
                    <div class="flex-center gap-1">
                        <span class="fw-600 text-sm">
                            <?= $remote['price'] !== null ? format_price($remote['price'], (string) ($remote['currency'] ?? 'CHF')) : '-' ?>
                        </span>
                        <?php if (!empty($remote['url'])): ?>
                            <a class="btn btn-secondary btn-sm" href="<?= e((string) $remote['url']) ?>" target="_blank" rel="noopener">
                                <?= icon('external-link', 13) ?> <?= t('common.open') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$autoSync = $hasChannels && $isStale ? 'true' : 'false';
$jsSyncing = json_encode(t('channels.syncing'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$jsUpToDate = json_encode(t('channels.up_to_date'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$pageScripts = <<<HTML
<script>
(function () {
    // Sofort filtern, ohne Anfrage an den Server
    var input = document.getElementById('listFilter');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.listing-row'));
    var noMatch = document.getElementById('noMatch');
    if (input && rows.length) {
        input.addEventListener('input', function () {
            var needle = input.value.trim().toLowerCase();
            var visible = 0;
            rows.forEach(function (row) {
                var hit = needle === '' || row.dataset.name.indexOf(needle) !== -1;
                row.style.display = hit ? '' : 'none';
                if (hit) { visible++; }
            });
            if (noMatch) { noMatch.style.display = visible === 0 ? '' : 'none'; }
        });
    }

    var form = document.getElementById('syncForm');
    if (!form) { return; }
    var btn = document.getElementById('syncBtn');
    var original = btn.innerHTML;

    function runSync(silent) {
        if (!silent) {
            btn.disabled = true;
            btn.textContent = {$jsSyncing};
        }
        return apiFetch('api/channels/sync.php', { method: 'POST', body: {} }).then(function (res) {
            btn.disabled = false;
            btn.innerHTML = original;
            if (!res.success) {
                if (!silent) { showToast(res.error || 'Fehler', 'danger'); }
                return;
            }
            if (res.data.errors && res.data.errors.length) {
                showToast(res.data.errors.join(' | '), 'danger');
                return;
            }
            if (res.data.changed) {
                location.reload();
            } else if (!silent) {
                showToast({$jsUpToDate}, 'success');
            }
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        runSync(false);
    });

    if ({$autoSync}) {
        setTimeout(function () { runSync(true); }, 800);
    }
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
