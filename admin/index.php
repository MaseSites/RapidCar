<?php
/**
 * Admin-Dashboard (§46): Plattformübersicht + Plattformstatus (§53).
 * Ausschliesslich für super_admin (§44/§45).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Config;
use App\Core\Database;
use App\Integration\AutoScoutService;
use App\Service\SettingsService;

require_super_admin();

// ------------------------------------------------------------ Kennzahlen (§46)
$userCount = (int) Database::scalar('SELECT COUNT(*) FROM users');
$activeDealers = (int) Database::scalar(
    "SELECT COUNT(DISTINCT dealership_id) FROM users WHERE is_active = 1 AND dealership_id IS NOT NULL"
);
$vehicleCount = (int) Database::scalar('SELECT COUNT(*) FROM vehicles');
$activeListings = (int) Database::scalar("SELECT COUNT(*) FROM listings WHERE status = 'published'");
$newRegistrations = (int) Database::scalar(
    'SELECT COUNT(*) FROM users WHERE created_at > :since',
    ['since' => date('Y-m-d H:i:s', time() - 7 * 86400)]
);

// -------------------------------------------------------- Plattformstatus (§53)
$dbOnline = true; // wenn diese Seite lädt, ist die DB erreichbar
$aiMode = SettingsService::aiMode();
// Plattform-Zugang ist optional: Ohne ihn verbinden sich Händler mit eigenen Zugangsdaten.
$autoscoutPlatform = AutoScoutService::hasPlatformCredentials();
$autoscoutConnected = (int) Database::scalar(
    "SELECT COUNT(*) FROM integrations WHERE provider = 'autoscout24' AND status = 'connected'"
);
$mailDriver = (string) Config::get('mail.driver', 'log');
$storageWritable = is_writable(BASE_PATH . '/uploads') && is_writable(BASE_PATH . '/storage');

// Letzte Aktivitäten
$recentActivity = Database::fetchAll(
    'SELECT a.*, u.first_name, u.last_name
     FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT 8'
);

$pageTitle = 'Plattformübersicht';
$activeNav = 'dashboard';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="kpi-grid cols-5">
    <div class="kpi-card">
        <div class="label">Benutzer</div>
        <div class="value"><?= $userCount ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Aktive Händler</div>
        <div class="value"><?= $activeDealers ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Fahrzeuge</div>
        <div class="value"><?= number_format($vehicleCount, 0, '.', "'") ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Aktive Inserate</div>
        <div class="value"><?= number_format($activeListings, 0, '.', "'") ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Neue Registrierungen <span class="text-muted">(7 Tage)</span></div>
        <div class="value"><?= $newRegistrations ?></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Plattformstatus</h2></div>
        <div class="card-body" style="padding-top:8px">
            <div class="reco-card" style="padding-left:0;padding-right:0">
                <span class="status-dot <?= $autoscoutConnected > 0 ? 'green' : ($autoscoutPlatform ? 'yellow' : 'gray') ?>"></span>
                <div class="body">
                    <div class="title">AutoScout24 API</div>
                    <div class="msg">
                        <?= $autoscoutPlatform
                            ? 'Plattform-Zugang hinterlegt'
                            : 'Kein Plattform-Zugang: Händler verbinden sich mit eigenen Zugangsdaten' ?>,
                        <?= $autoscoutConnected ?> verbundene Autohäuser
                    </div>
                </div>
            </div>
            <div class="reco-card" style="padding-left:0;padding-right:0">
                <span class="status-dot <?= $aiMode === 'live' ? 'green' : 'yellow' ?>"></span>
                <div class="body">
                    <div class="title">KI</div>
                    <div class="msg"><?= $aiMode === 'live' ? 'Live-Modus' : 'Mock-Modus (§54)' ?></div>
                </div>
                <a class="btn btn-secondary btn-sm" href="<?= base_url('admin/settings.php') ?>">Ändern</a>
            </div>
            <div class="reco-card" style="padding-left:0;padding-right:0">
                <span class="status-dot green"></span>
                <div class="body">
                    <div class="title">Datenbank</div>
                    <div class="msg">Online (<?= e(Database::driver()) ?>)</div>
                </div>
            </div>
            <div class="reco-card" style="padding-left:0;padding-right:0">
                <span class="status-dot <?= $mailDriver === 'log' ? 'yellow' : 'green' ?>"></span>
                <div class="body">
                    <div class="title">E-Mail</div>
                    <div class="msg"><?= $mailDriver === 'log' ? 'Log-Modus (Mails werden nur protokolliert)' : 'Treiber: ' . e($mailDriver) ?></div>
                </div>
            </div>
            <div class="reco-card" style="padding-left:0;padding-right:0">
                <span class="status-dot <?= $storageWritable ? 'green' : 'red' ?>"></span>
                <div class="body">
                    <div class="title">Storage</div>
                    <div class="msg"><?= $storageWritable ? 'Beschreibbar' : 'Schreibrechte fehlen!' ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Letzte Aktivitäten</h2>
            <a class="btn btn-secondary btn-sm" href="<?= base_url('admin/activity.php') ?>">Alle ansehen</a>
        </div>
        <?php if ($recentActivity === []): ?>
            <div class="empty-state"><p>Noch keine Aktivitäten.</p></div>
        <?php else: ?>
            <?php foreach ($recentActivity as $log): ?>
                <div class="reco-card">
                    <div class="body">
                        <div class="title text-sm">
                            <?= e(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System') ?>
                            <span class="badge badge-neutral"><?= e($log['action']) ?></span>
                        </div>
                        <div class="msg"><?= e($log['description']) ?></div>
                    </div>
                    <span class="text-xs text-muted"><?= e(time_ago((string) $log['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
