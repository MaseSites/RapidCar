<?php
/**
 * Aktivitätsprotokoll (§52): alle Aktionen, filterbar.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;

require_super_admin();

$actionFilter = trim((string) ($_GET['action'] ?? ''));
$userFilter = (int) ($_GET['user'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = [];
$params = [];
if ($actionFilter !== '') {
    $where[] = 'a.action LIKE :action';
    $params['action'] = '%' . $actionFilter . '%';
}
if ($userFilter > 0) {
    $where[] = 'a.user_id = :uid';
    $params['uid'] = $userFilter;
}
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) Database::scalar("SELECT COUNT(*) FROM activity_logs a {$whereSql}", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$logs = Database::fetchAll(
    "SELECT a.*, u.first_name, u.last_name, d.name AS dealership_name
     FROM activity_logs a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN dealerships d ON d.id = a.dealership_id
     {$whereSql}
     ORDER BY a.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$pageTitle = 'Aktivitätsprotokoll';
$activeNav = 'activity';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="card mb-3">
    <div class="card-body" style="padding:16px 20px">
        <form method="get" action="<?= base_url('admin/activity.php') ?>" class="flex gap-1" style="flex-wrap:wrap">
            <input class="form-control" style="width:240px" type="text" name="action"
                   value="<?= e($actionFilter) ?>" placeholder="Aktion filtern… (z.B. user.login)">
            <input class="form-control" style="width:140px" type="number" name="user"
                   value="<?= $userFilter > 0 ? $userFilter : '' ?>" placeholder="Benutzer-ID">
            <button class="btn btn-primary" type="submit">Filtern</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Protokoll <span class="text-muted">(<?= number_format($total, 0, '.', "'") ?>)</span></h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Zeit</th><th>Benutzer</th><th>Autohaus</th><th>Aktion</th><th>Beschreibung</th><th>IP</th></tr>
            </thead>
            <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:36px">Keine Einträge gefunden.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-muted" style="white-space:nowrap"><?= e(format_datetime((string) $log['created_at'])) ?></td>
                        <td>
                            <?php if ($log['user_id'] !== null): ?>
                                <a href="<?= base_url('admin/user.php?id=' . (int) $log['user_id']) ?>">
                                    <?= e(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: ('User ' . (int) $log['user_id'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($log['dealership_name'] ?? '-') ?></td>
                        <td><span class="badge badge-neutral"><?= e($log['action']) ?></span></td>
                        <td><?= e($log['description']) ?></td>
                        <td class="text-muted text-xs"><?= e($log['ip_address'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <div class="card-body flex-between" style="padding:14px 20px">
            <span class="text-sm text-muted">Seite <?= $page ?> von <?= $pages ?></span>
            <div class="flex gap-1">
                <?php $query = http_build_query(array_filter(['action' => $actionFilter ?: null, 'user' => $userFilter ?: null])); ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-sm" href="?<?= $query ?>&page=<?= $page - 1 ?>"><?= icon('chevron-left', 14) ?> Zurück</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-secondary btn-sm" href="?<?= $query ?>&page=<?= $page + 1 ?>">Weiter <?= icon('chevron-right', 14) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
