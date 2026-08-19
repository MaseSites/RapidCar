<?php
/**
 * Benutzerverwaltung (§47, §48): Liste, Suche, Filter.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;

require_super_admin();

$search = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['filter'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.email LIKE :q3 OR d.name LIKE :q4)';
    $like = '%' . $search . '%';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
}
switch ($filter) {
    case 'active':
        $where[] = 'u.is_active = 1';
        break;
    case 'inactive':
        $where[] = 'u.is_active = 0';
        break;
    case 'verified':
        $where[] = 'u.email_verified_at IS NOT NULL';
        break;
    case 'unverified':
        $where[] = 'u.email_verified_at IS NULL';
        break;
}
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) Database::scalar(
    "SELECT COUNT(*) FROM users u LEFT JOIN dealerships d ON d.id = u.dealership_id {$whereSql}",
    $params
);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$users = Database::fetchAll(
    "SELECT u.*, d.name AS dealership_name
     FROM users u LEFT JOIN dealerships d ON d.id = u.dealership_id
     {$whereSql}
     ORDER BY u.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$pageTitle = 'Benutzer';
$activeNav = 'users';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="card mb-3">
    <div class="card-body" style="padding:16px 20px">
        <form method="get" action="<?= base_url('admin/users.php') ?>" class="flex gap-1" style="flex-wrap:wrap">
            <input class="form-control" style="flex:1;min-width:220px" type="text" name="q"
                   value="<?= e($search) ?>" placeholder="Benutzer suchen… (Name, E-Mail, Autohaus)">
            <select class="form-control" style="width:auto" name="filter">
                <option value="">Alle</option>
                <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Aktiv</option>
                <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Deaktiviert</option>
                <option value="verified" <?= $filter === 'verified' ? 'selected' : '' ?>>Verifiziert</option>
                <option value="unverified" <?= $filter === 'unverified' ? 'selected' : '' ?>>Nicht verifiziert</option>
            </select>
            <button class="btn btn-primary" type="submit"><?= t('common.search') ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Benutzer <span class="text-muted">(<?= $total ?>)</span></h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Autohaus</th>
                    <th>Registrierung</th>
                    <th><?= t('common.status') ?></th>
                    <th>Letzter Login</th>
                    <th>Rolle</th>
                    <th><?= t('common.actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:36px">Keine Benutzer gefunden.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="text-muted">#<?= (int) $user['id'] ?></td>
                        <td>
                            <div class="flex-center gap-1">
                                <span class="avatar" style="width:30px;height:30px;font-size:11px"><?= e(initials($user['first_name'] . ' ' . $user['last_name'])) ?></span>
                                <span class="fw-600"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></span>
                            </div>
                        </td>
                        <td><?= e($user['email']) ?></td>
                        <td><?= e($user['dealership_name'] ?? '-') ?></td>
                        <td class="text-muted"><?= e(format_date((string) $user['created_at'])) ?></td>
                        <td>
                            <?php if ((int) $user['is_active'] === 1): ?>
                                <span class="badge badge-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Deaktiviert</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= $user['last_login_at'] !== null ? e(time_ago((string) $user['last_login_at'])) : '-' ?></td>
                        <td><span class="badge badge-neutral"><?= e($user['role']) ?></span></td>
                        <td>
                            <a class="btn btn-secondary btn-sm" href="<?= base_url('admin/user.php?id=' . (int) $user['id']) ?>">Öffnen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <div class="card-body flex-between" style="padding:14px 20px">
            <span class="text-sm text-muted">Seite <?= $page ?> von <?= $pages ?></span>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-sm" href="?q=<?= urlencode($search) ?>&filter=<?= urlencode($filter) ?>&page=<?= $page - 1 ?>"><?= icon('chevron-left', 14) ?> Zurück</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-secondary btn-sm" href="?q=<?= urlencode($search) ?>&filter=<?= urlencode($filter) ?>&page=<?= $page + 1 ?>">Weiter <?= icon('chevron-right', 14) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
