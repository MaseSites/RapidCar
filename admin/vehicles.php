<?php
/**
 * Admin: alle Fahrzeuge aller Händler (§51) mit Filtern.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;

require_super_admin();

// Inserat samt Fahrzeug, Bildern und Dateien endgueltig loeschen
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_vehicle') {
    \App\Core\Csrf::validate();
    $deleteId = (int) ($_POST['vehicle_id'] ?? 0);
    if ($deleteId > 0) {
        \App\Service\AdminRemovalService::removeVehicle($deleteId);
        \App\Service\ActivityLogger::log((int) $currentUser['id'], 'admin.vehicle_deleted', "Fahrzeug #{$deleteId} endgueltig geloescht", 'vehicle', $deleteId);
        \App\Core\Session::flash('success', 'Fahrzeug und Inserat endgültig gelöscht.');
    }
    redirect('admin/vehicles.php');
}

$dealershipFilter = (int) ($_GET['dealership'] ?? 0);
$makeFilter = trim((string) ($_GET['make'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($dealershipFilter > 0) {
    $where[] = 'v.dealership_id = :did';
    $params['did'] = $dealershipFilter;
}
if ($makeFilter !== '') {
    $where[] = 'v.make LIKE :make';
    $params['make'] = '%' . $makeFilter . '%';
}
if (in_array($statusFilter, ['draft', 'ready', 'published', 'reserved', 'sold', 'archived'], true)) {
    $where[] = 'v.status = :status';
    $params['status'] = $statusFilter;
}
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) Database::scalar("SELECT COUNT(*) FROM vehicles v {$whereSql}", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$vehicles = Database::fetchAll(
    "SELECT v.*, d.name AS dealership_name,
            (SELECT ls.total_score FROM listing_scores ls
             INNER JOIN listings l ON l.id = ls.listing_id
             WHERE l.vehicle_id = v.id ORDER BY ls.id DESC LIMIT 1) AS score,
            (SELECT vi.thumb_path FROM vehicle_images vi
             WHERE vi.vehicle_id = v.id ORDER BY vi.is_main DESC, vi.sort_order ASC LIMIT 1) AS thumb
     FROM vehicles v
     INNER JOIN dealerships d ON d.id = v.dealership_id
     {$whereSql}
     ORDER BY v.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$dealerships = Database::fetchAll('SELECT id, name FROM dealerships ORDER BY name');

$pageTitle = 'Fahrzeuge (alle Händler)';
$activeNav = 'vehicles';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="card mb-3">
    <div class="card-body" style="padding:16px 20px">
        <form method="get" action="<?= base_url('admin/vehicles.php') ?>" class="flex gap-1" style="flex-wrap:wrap">
            <select class="form-control" style="width:auto" name="dealership">
                <option value="0">Alle Händler</option>
                <?php foreach ($dealerships as $dealership): ?>
                    <option value="<?= (int) $dealership['id'] ?>" <?= $dealershipFilter === (int) $dealership['id'] ? 'selected' : '' ?>>
                        <?= e($dealership['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input class="form-control" style="width:170px" type="text" name="make" value="<?= e($makeFilter) ?>" placeholder="Marke…">
            <select class="form-control" style="width:auto" name="status">
                <option value="">Alle Status</option>
                <?php foreach (['draft', 'ready', 'published', 'reserved', 'sold', 'archived'] as $status): ?>
                    <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(vehicle_status_label($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Filtern</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Fahrzeuge <span class="text-muted">(<?= $total ?>)</span></h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Bild</th><th>Fahrzeug</th><th>Händler</th><th>Preis</th>
                    <th>Score</th><th>Status</th><th>Erstellt</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($vehicles === []): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:36px">Keine Fahrzeuge gefunden.</td></tr>
                <?php endif; ?>
                <?php foreach ($vehicles as $vehicle): ?>
                    <tr>
                        <td>
                            <?php if ($vehicle['thumb'] !== null): ?>
                                <img class="vehicle-thumb" src="<?= e(upload_url((string) $vehicle['thumb'])) ?>" alt="">
                            <?php else: ?>
                                <div class="vehicle-thumb placeholder"><?= icon('image', 18) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-600"><?= e(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) ?: 'Unbenannt') ?></div>
                            <div class="text-xs text-muted"><?= e($vehicle['variant'] ?? '') ?></div>
                        </td>
                        <td><?= e($vehicle['dealership_name']) ?></td>
                        <td><?= format_price($vehicle['price']) ?></td>
                        <td>
                            <?php if ($vehicle['score'] !== null): ?>
                                <span class="rating-arrow <?= rating_class((int) $vehicle['score']) ?>"><?= rating_arrow((int) $vehicle['score']) ?></span>
                                <?= (int) $vehicle['score'] ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-neutral"><?= e(vehicle_status_label((string) $vehicle['status'])) ?></span></td>
                        <td class="text-muted"><?= e(format_date((string) $vehicle['created_at'])) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Dieses Fahrzeug samt Inserat und Fotos endgültig löschen?');">
                                <?= App\Core\Csrf::field() ?>
                                <input type="hidden" name="action" value="delete_vehicle">
                                <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit" title="Endgültig löschen"><?= icon('trash', 14) ?></button>
                            </form>
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
                <?php
                $query = http_build_query(array_filter([
                    'dealership' => $dealershipFilter ?: null,
                    'make' => $makeFilter !== '' ? $makeFilter : null,
                    'status' => $statusFilter !== '' ? $statusFilter : null,
                ]));
                ?>
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
