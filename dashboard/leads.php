<?php
/**
 * Anfragen-Übersicht (§40).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;

$dealershipId = require_dealership();

$statusFilter = (string) ($_GET['status'] ?? '');
$where = 'l.dealership_id = :did';
$params = ['did' => $dealershipId];
if (in_array($statusFilter, ['new', 'active', 'test_drive', 'won', 'lost'], true)) {
    $where .= ' AND l.status = :status';
    $params['status'] = $statusFilter;
}

$leads = Database::fetchAll(
    "SELECT l.*, v.make, v.model,
            (SELECT m.created_at FROM messages m WHERE m.lead_id = l.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
            (SELECT m.body FROM messages m WHERE m.lead_id = l.id ORDER BY m.id DESC LIMIT 1) AS last_message
     FROM leads l
     LEFT JOIN vehicles v ON v.id = l.vehicle_id
     WHERE {$where}
     ORDER BY CASE l.status WHEN 'new' THEN 0 ELSE 1 END, l.updated_at DESC",
    $params
);

function lead_status_badge(string $status): string
{
    return match ($status) {
        'new'        => '<span class="badge badge-danger">' . t('leads.status.new') . '</span>',
        'active'     => '<span class="badge badge-info">' . t('leads.status.active') . '</span>',
        'test_drive' => '<span class="badge badge-warning">' . t('leads.status.test_drive') . '</span>',
        'won'        => '<span class="badge badge-success">' . t('leads.status.won') . '</span>',
        'lost'       => '<span class="badge badge-neutral">' . t('leads.status.lost') . '</span>',
        default      => '<span class="badge badge-neutral">' . e($status) . '</span>',
    };
}

$pageTitle = t('sidebar.leads');
$activeNav = 'leads';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('leads.title') ?></h1>
        <div class="sub"><?= t('leads.count', ['count' => count($leads)]) ?></div>
    </div>
    <form method="get">
        <select class="form-control" name="status" onchange="this.form.submit()" style="width:auto">
            <option value=""><?= t('vehicles.all_status') ?></option>
            <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>><?= t('leads.status.new') ?></option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>><?= t('leads.status.active') ?></option>
            <option value="test_drive" <?= $statusFilter === 'test_drive' ? 'selected' : '' ?>><?= t('leads.status.test_drive') ?></option>
            <option value="won" <?= $statusFilter === 'won' ? 'selected' : '' ?>><?= t('leads.status.won') ?></option>
            <option value="lost" <?= $statusFilter === 'lost' ? 'selected' : '' ?>><?= t('leads.status.lost') ?></option>
        </select>
    </form>
</div>

<div class="card">
    <?php if ($leads === []): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= icon('message', 22) ?></div>
            <h3><?= t('leads.empty.title') ?></h3>
            <p><?= t('leads.empty.text') ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th><?= t('leads.col.customer') ?></th><th><?= t('leads.col.vehicle') ?></th><th><?= t('common.status') ?></th><th><?= t('lead.score') ?></th><th><?= t('leads.col.last_message') ?></th><th><?= t('common.actions') ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td>
                                <div class="flex-center gap-1">
                                    <span class="avatar" style="width:32px;height:32px;font-size:12px"><?= e(initials((string) $lead['customer_name'])) ?></span>
                                    <div>
                                        <div class="fw-600"><?= e($lead['customer_name']) ?></div>
                                        <div class="text-xs text-muted"><?= e(mb_substr((string) ($lead['last_message'] ?? ''), 0, 46)) ?><?= mb_strlen((string) ($lead['last_message'] ?? '')) > 46 ? '…' : '' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= e(trim(($lead['make'] ?? '') . ' ' . ($lead['model'] ?? '')) ?: '-') ?></td>
                            <td><?= lead_status_badge((string) $lead['status']) ?></td>
                            <td>
                                <?php if ($lead['score'] !== null): ?>
                                    <span class="fw-600"><?= (int) $lead['score'] ?></span><span class="text-muted">/100</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= $lead['last_message_at'] !== null ? e(time_ago((string) $lead['last_message_at'])) : '-' ?></td>
                            <td><a class="btn btn-secondary btn-sm" href="<?= base_url('dashboard/lead.php?id=' . (int) $lead['id']) ?>"><?= t('common.open') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
