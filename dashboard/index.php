<?php
/**
 * Dashboard: eine Übersichtsseite, die auf einen Blick zeigt, wo das
 * Autohaus steht. Oben die Kennzahlen, darunter Verlauf, nächster Schritt
 * und die zuletzt bearbeiteten Inserate, ganz unten Anfragen, Qualität
 * und Kanäle. Gerechnet wird nur mit echten Daten.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;
use App\Integration\ChannelRegistry;
use App\Service\CreditService;

$dealershipId = require_dealership();

if ($currentUser['onboarding_completed_at'] === null) {
    redirect('dashboard/onboarding.php');
}

// ------------------------------------------------------------------ Kennzahlen
$vehicleCount = (int) Database::scalar(
    "SELECT COUNT(*) FROM vehicles WHERE dealership_id = :did AND status != 'archived'",
    ['did' => $dealershipId]
);
$publishedCount = (int) Database::scalar(
    "SELECT COUNT(*) FROM listings WHERE dealership_id = :did AND status = 'published'",
    ['did' => $dealershipId]
);
$draftCount = max(0, $vehicleCount - $publishedCount);
$credits = CreditService::balance($dealershipId);

$newLeads = (int) Database::scalar(
    "SELECT COUNT(*) FROM leads WHERE dealership_id = :did AND status = 'new'",
    ['did' => $dealershipId]
);

// ------------------------------------------------- Verlauf der letzten Monate
// Die Monatsgrenzen entstehen in PHP und nicht in SQL: SQLite und MySQL
// schreiben Datumsfunktionen unterschiedlich.
$months = [];
for ($back = 5; $back >= 0; $back--) {
    $key = date('Y-m', strtotime("first day of -{$back} month"));
    $months[$key] = ['label' => date('M', strtotime($key . '-01')), 'count' => 0];
}
$since = date('Y-m-d', strtotime('first day of -5 month'));
$createdRows = Database::fetchAll(
    "SELECT created_at FROM vehicles WHERE dealership_id = :did AND created_at >= :since",
    ['did' => $dealershipId, 'since' => $since . ' 00:00:00']
);
foreach ($createdRows as $row) {
    $key = substr((string) $row['created_at'], 0, 7);
    if (isset($months[$key])) {
        $months[$key]['count']++;
    }
}
$monthMax = max(1, max(array_column($months, 'count')));

// --------------------------------------------------------- Qualität (Ø Score)
$avgScore = Database::scalar(
    "SELECT AVG(s.total_score) FROM listing_scores s
     INNER JOIN listings l ON l.id = s.listing_id
     WHERE l.dealership_id = :did
       AND s.id = (SELECT MAX(s2.id) FROM listing_scores s2 WHERE s2.listing_id = l.id)",
    ['did' => $dealershipId]
);
$avgScore = $avgScore === null ? null : (int) round((float) $avgScore);

// ------------------------------------------------------- Nächster Schritt
// Die dringendste offene Empfehlung, sonst der Einstieg ins erste Inserat.
$topIssue = Database::fetch(
    "SELECT r.message, r.action_label, r.severity, v.id AS vehicle_id, v.make, v.model
     FROM listing_recommendations r
     INNER JOIN listings l ON l.id = r.listing_id
     INNER JOIN vehicles v ON v.id = l.vehicle_id
     WHERE l.dealership_id = :did AND r.is_resolved = 0
       AND v.status NOT IN ('sold', 'archived')
     ORDER BY CASE r.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, r.id
     LIMIT 1",
    ['did' => $dealershipId]
);

// ------------------------------------------------- Zuletzt bearbeitete Inserate
$recentVehicles = Database::fetchAll(
    "SELECT v.id, v.make, v.model, v.variant, v.price, v.status,
            (SELECT s.total_score FROM listing_scores s
             INNER JOIN listings l2 ON l2.id = s.listing_id
             WHERE l2.vehicle_id = v.id ORDER BY s.id DESC LIMIT 1) AS score,
            (SELECT i.thumb_path FROM vehicle_images i
             WHERE i.vehicle_id = v.id ORDER BY i.is_main DESC, i.id LIMIT 1) AS thumb
     FROM vehicles v
     WHERE v.dealership_id = :did AND v.status != 'archived'
     ORDER BY v.updated_at DESC, v.id DESC
     LIMIT 5",
    ['did' => $dealershipId]
);

// ----------------------------------------------------------- Letzte Anfragen
$recentLeads = Database::fetchAll(
    "SELECT le.id, le.customer_name, le.status, le.created_at,
            v.make, v.model
     FROM leads le
     LEFT JOIN vehicles v ON v.id = le.vehicle_id
     WHERE le.dealership_id = :did
     ORDER BY le.created_at DESC
     LIMIT 4",
    ['did' => $dealershipId]
);

// ------------------------------------------------------------------- Kanäle
$connectedChannels = [];
$totalChannels = 0;
foreach (ChannelRegistry::all() as $chKey => $channel) {
    $totalChannels++;
    if (ChannelRegistry::status($dealershipId, $chKey) === 'connected') {
        $connectedChannels[] = (string) $channel['name'];
    }
}

$pageTitle = t('sidebar.dashboard');
$activeNav = 'dashboard';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('dash.hello', ['name' => e((string) ($currentUser['first_name'] ?? ''))]) ?></h1>
        <div class="sub"><?= t('dash.lead') ?></div>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= base_url('dashboard/vehicles.php') ?>"><?= t('sidebar.vehicles') ?></a>
        <a class="btn btn-primary" href="<?= base_url('dashboard/create-vehicle.php') ?>">
            <?= icon('plus', 15) ?> <?= t('sidebar.create_listing') ?>
        </a>
    </div>
</div>

<!-- ------------------------------------------------------------ Kennzahlen -->
<div class="kpi-grid mb-3">
    <a class="kpi-card kpi-accent" href="<?= base_url('dashboard/vehicles.php') ?>">
        <div class="label"><?= t('dash.kpi.vehicles') ?></div>
        <div class="value"><?= $vehicleCount ?></div>
        <div class="trend"><?= $draftCount ?> <?= t('dash.area.drafts') ?></div>
    </a>
    <a class="kpi-card" href="<?= base_url('dashboard/vehicles.php?status=published') ?>">
        <div class="label"><?= t('dash.kpi.published') ?></div>
        <div class="value"><?= $publishedCount ?></div>
        <div class="trend text-muted"><?= t('dash.of_total', ['count' => $vehicleCount]) ?></div>
    </a>
    <a class="kpi-card" href="<?= base_url('dashboard/leads.php') ?>">
        <div class="label"><?= t('dash.kpi.new_leads') ?></div>
        <div class="value"><?= $newLeads ?></div>
        <div class="trend text-muted"><?= t('sidebar.leads') ?></div>
    </a>
    <a class="kpi-card" href="<?= base_url('dashboard/credits.php') ?>">
        <div class="label"><?= t('dash.kpi.credits') ?></div>
        <div class="value"><?= $credits ?></div>
        <div class="trend text-muted"><?= t('credits.per_listing') ?></div>
    </a>
</div>

<!-- ------------------------------------- Verlauf, nächster Schritt, Inserate -->
<div class="dash-row mb-3">

    <div class="card card-pad">
        <div class="flex-between mb-2">
            <h2 class="card-title"><?= t('dash.chart.title') ?></h2>
            <span class="text-xs text-muted"><?= t('dash.chart.range') ?></span>
        </div>
        <div class="bar-chart">
            <?php foreach ($months as $month): ?>
                <div class="bar-col">
                    <div class="bar-track">
                        <div class="bar-fill" style="height:<?= (int) round($month['count'] / $monthMax * 100) ?>%"></div>
                    </div>
                    <div class="bar-value"><?= $month['count'] ?></div>
                    <div class="bar-label"><?= e($month['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card card-pad dash-next">
        <h2 class="card-title mb-2"><?= t('dash.next.title') ?></h2>
        <?php if ($topIssue !== null): ?>
            <div class="next-name"><?= e(trim(($topIssue['make'] ?? '') . ' ' . ($topIssue['model'] ?? ''))) ?></div>
            <p class="next-text"><?= e((string) $topIssue['message']) ?></p>
            <a class="btn btn-primary btn-block mt-2" href="<?= base_url('dashboard/vehicle.php?id=' . (int) $topIssue['vehicle_id']) ?>">
                <?= e((string) ($topIssue['action_label'] ?: t('dash.today.optimize'))) ?>
            </a>
        <?php elseif ($vehicleCount === 0): ?>
            <p class="next-text"><?= t('dash.next.empty_first') ?></p>
            <a class="btn btn-primary btn-block mt-2" href="<?= base_url('dashboard/create-vehicle.php') ?>">
                <?= t('sidebar.create_listing') ?>
            </a>
        <?php else: ?>
            <p class="next-text"><?= t('dash.next.all_clear') ?></p>
            <a class="btn btn-secondary btn-block mt-2" href="<?= base_url('dashboard/social.php') ?>">
                <?= t('sidebar.posts') ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="card card-pad">
        <div class="flex-between mb-2">
            <h2 class="card-title"><?= t('dash.recent.title') ?></h2>
            <a class="text-xs" href="<?= base_url('dashboard/vehicles.php') ?>"><?= t('common.all') ?></a>
        </div>
        <?php if ($recentVehicles === []): ?>
            <p class="text-sm text-muted"><?= t('dash.recent.empty') ?></p>
        <?php else: ?>
            <ul class="mini-list">
                <?php foreach ($recentVehicles as $item): ?>
                    <li>
                        <a href="<?= base_url('dashboard/vehicle.php?id=' . (int) $item['id']) ?>">
                            <?php if (!empty($item['thumb'])): ?>
                                <img src="<?= e(upload_url((string) $item['thumb'])) ?>" alt="">
                            <?php else: ?>
                                <span class="mini-thumb-empty"><?= icon('car', 15) ?></span>
                            <?php endif; ?>
                            <span class="mini-body">
                                <span class="mini-name"><?= e(trim(($item['make'] ?? '') . ' ' . ($item['model'] ?? ''))) ?: t('vehicle.new') ?></span>
                                <span class="mini-meta"><?= $item['price'] !== null ? format_price($item['price']) : '-' ?></span>
                            </span>
                            <?php if ($item['score'] !== null): ?>
                                <span class="mini-score"><?= (int) $item['score'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<!-- ------------------------------------------ Anfragen, Qualität und Kanäle -->
<div class="dash-row-2">

    <div class="card card-pad">
        <div class="flex-between mb-2">
            <h2 class="card-title"><?= t('dash.leads.title') ?></h2>
            <a class="text-xs" href="<?= base_url('dashboard/leads.php') ?>"><?= t('common.all') ?></a>
        </div>
        <?php if ($recentLeads === []): ?>
            <p class="text-sm text-muted"><?= t('dash.leads.empty') ?></p>
        <?php else: ?>
            <ul class="mini-list">
                <?php foreach ($recentLeads as $lead): ?>
                    <li>
                        <a href="<?= base_url('dashboard/lead.php?id=' . (int) $lead['id']) ?>">
                            <span class="avatar-initials"><?= e(mb_strtoupper(mb_substr((string) $lead['customer_name'], 0, 2))) ?></span>
                            <span class="mini-body">
                                <span class="mini-name"><?= e((string) $lead['customer_name']) ?></span>
                                <span class="mini-meta"><?= e(trim(($lead['make'] ?? '') . ' ' . ($lead['model'] ?? ''))) ?: t('leads.no_vehicle') ?></span>
                            </span>
                            <span class="badge badge-neutral"><?= e(lead_status_label((string) $lead['status'])) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card card-pad dash-quality">
        <h2 class="card-title mb-2"><?= t('dash.kpi.avg_score') ?></h2>
        <?php if ($avgScore === null): ?>
            <p class="text-sm text-muted"><?= t('dash.kpi.no_score') ?></p>
        <?php else: ?>
            <div class="donut" style="--value:<?= $avgScore ?>">
                <div class="donut-hole">
                    <span class="donut-value"><?= $avgScore ?></span>
                    <span class="donut-unit">/100</span>
                </div>
            </div>
            <div class="text-sm text-muted"><?= t('ai.score.rule_based') ?></div>
        <?php endif; ?>
    </div>

    <div class="card card-pad">
        <div class="flex-between mb-2">
            <h2 class="card-title"><?= t('sidebar.channels') ?></h2>
            <a class="text-xs" href="<?= base_url('dashboard/channels.php') ?>"><?= t('common.all') ?></a>
        </div>
        <div class="channel-count"><?= count($connectedChannels) ?><small>/<?= $totalChannels ?></small></div>
        <div class="text-sm text-muted mb-2"><?= t('dash.channels.connected') ?></div>
        <?php if ($connectedChannels === []): ?>
            <a class="btn btn-secondary btn-block" href="<?= base_url('dashboard/channels.php') ?>">
                <?= t('dash.channels.connect') ?>
            </a>
        <?php else: ?>
            <div class="flex gap-1" style="flex-wrap:wrap">
                <?php foreach ($connectedChannels as $name): ?>
                    <span class="badge badge-success"><span class="status-dot green"></span> <?= e($name) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
