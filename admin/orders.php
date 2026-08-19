<?php
/**
 * Admin: Bestellverlauf aller Konten.
 *
 * Reine Ansicht: Zahlungen laufen vollstaendig ueber Stripe, gutgeschrieben
 * wird automatisch beim Zahlungseingang. Es gibt nichts freizugeben.
 * Einzig die manuelle Gutschrift (Kulanz) bleibt als Werkzeug.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;
use App\Service\CreditService;

require_super_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $adminId = (int) $currentUser['id'];

    if ($action === 'grant') {
        $dealershipId = (int) ($_POST['dealership_id'] ?? 0);
        $amount = (int) ($_POST['amount'] ?? 0);
        if ($dealershipId > 0 && $amount > 0 && $amount <= 1000) {
            CreditService::grant($dealershipId, $amount, CreditService::REASON_ADMIN, 'Manuelle Gutschrift', $adminId);
            ActivityLogger::log($adminId, 'admin.credits_granted', "{$amount} Inserat-Guthaben an Autohaus #{$dealershipId} gutgeschrieben", 'dealership', $dealershipId);
            Session::flash('success', t('credits.granted', ['count' => $amount]));
        } else {
            Session::flash('danger', 'Ungültige Eingabe.');
        }
    }
    redirect('admin/orders.php');
}

$orders = Database::fetchAll(
    'SELECT o.*, d.name AS dealership_name, d.credits AS current_credits
     FROM credit_orders o
     INNER JOIN dealerships d ON d.id = o.dealership_id
     ORDER BY o.id DESC
     LIMIT 100'
);

$dealerships = Database::fetchAll('SELECT id, name, credits FROM dealerships ORDER BY name');

$pageTitle = t('credits.orders');
$activeNav = 'orders';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('credits.orders') ?></h1>
        <div class="sub">Zahlungen laufen automatisch über Stripe. Diese Seite ist nur der Verlauf.</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2><?= t('credits.orders') ?></h2></div>
    <?php if ($orders === []): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= icon('inbox', 22) ?></div>
            <p><?= t('credits.history.empty') ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th><th><?= t('common.date') ?></th><th><?= t('settings.dealership') ?></th>
                        <th><?= t('credits.balance_unit') ?></th><th><?= t('common.price') ?></th>
                        <th><?= t('common.status') ?></th><th>Bezahlt am</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td class="text-muted">#<?= (int) $order['id'] ?></td>
                            <td class="text-muted" style="white-space:nowrap"><?= e(format_datetime((string) $order['created_at'])) ?></td>
                            <td>
                                <div class="fw-600"><?= e($order['dealership_name']) ?></div>
                                <div class="text-xs text-muted"><?= t('credits.balance') ?>: <?= (int) $order['current_credits'] ?></div>
                            </td>
                            <td class="fw-600"><?= (int) $order['credits'] ?></td>
                            <td><?= e((string) $order['currency']) ?> <?= number_format((float) $order['price'], 0, '.', "'") ?></td>
                            <td>
                                <?php
                                $status = (string) $order['status'];
                                $badge = match ($status) {
                                    'paid'      => 'badge-success',
                                    'cancelled' => 'badge-neutral',
                                    default     => 'badge-warning',
                                };
                                $label = match ($status) {
                                    'paid'      => t('credits.order.paid'),
                                    'cancelled' => t('credits.order.cancelled'),
                                    default     => t('credits.order.pending'),
                                };
                                ?>
                                <span class="badge <?= $badge ?>"><?= e($label) ?></span>
                            </td>
                            <td class="text-muted" style="white-space:nowrap">
                                <?= $order['paid_at'] !== null ? e(format_datetime((string) $order['paid_at'])) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2><?= t('credits.reason.admin') ?></h2></div>
    <div class="card-body">
        <form method="post" class="flex gap-1" style="flex-wrap:wrap;align-items:flex-end">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="grant">
            <div class="form-group" style="margin:0;min-width:240px">
                <label class="form-label"><?= t('settings.dealership') ?></label>
                <select class="form-control" name="dealership_id" required>
                    <?php foreach ($dealerships as $dealership): ?>
                        <option value="<?= (int) $dealership['id'] ?>">
                            <?= e($dealership['name']) ?> (<?= (int) $dealership['credits'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;width:140px">
                <label class="form-label"><?= t('credits.balance_unit') ?></label>
                <input class="form-control" type="number" name="amount" min="1" max="1000" value="1" required>
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('plus', 15) ?> <?= t('credits.reason.admin') ?></button>
        </form>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
