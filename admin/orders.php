<?php
/**
 * Admin: Guthaben-Bestellungen freigeben und Guthaben gutschreiben.
 *
 * Solange kein Zahlungsanbieter angebunden ist, gibt der Betreiber das
 * Guthaben hier manuell frei. Es wird keine Zahlung vorgetäuscht.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
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

    if ($action === 'mark_paid') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        try {
            CreditService::completeOrder($orderId, $adminId);
            ActivityLogger::log($adminId, 'admin.order_paid', "Bestellung #{$orderId} als bezahlt markiert, Guthaben gutgeschrieben", 'credit_order', $orderId);
            Session::flash('success', 'Bestellung freigegeben und Guthaben gutgeschrieben.');
        } catch (\RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
        }
    } elseif ($action === 'cancel') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        CreditService::cancelOrder($orderId);
        ActivityLogger::log($adminId, 'admin.order_cancelled', "Bestellung #{$orderId} storniert", 'credit_order', $orderId);
        Session::flash('success', 'Bestellung storniert.');
    } elseif ($action === 'grant') {
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
     ORDER BY CASE o.status WHEN :pending THEN 0 ELSE 1 END, o.id DESC
     LIMIT 100',
    ['pending' => 'pending']
);

$dealerships = Database::fetchAll('SELECT id, name, credits FROM dealerships ORDER BY name');

$pageTitle = t('credits.orders');
$activeNav = 'orders';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('credits.orders') ?></h1>
        <div class="sub">
            <?php if (!CreditService::paymentConfigured()): ?>
                <?= t('credits.payment_not_connected') ?>
            <?php endif; ?>
        </div>
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
                        <th><?= t('common.status') ?></th><th><?= t('common.actions') ?></th>
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
                            <td>
                                <?php if ($status === 'pending'): ?>
                                    <div class="flex gap-1">
                                        <form method="post" data-confirm="Bestellung freigeben und Guthaben gutschreiben?" data-confirm-tone="success">
                                            <?= App\Core\Csrf::field() ?>
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 14) ?> Freigeben</button>
                                        </form>
                                        <form method="post">
                                            <?= App\Core\Csrf::field() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit"><?= t('common.cancel') ?></button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
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
