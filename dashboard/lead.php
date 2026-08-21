<?php
/**
 * Lead-Detailseite (§41): Nachrichtenverlauf, Lead-Score, Status, Aktionen.
 * KI-Antwortassistent (§42) mit Sicherheitsregel (§43): Entwürfe müssen
 * vom Händler geprüft und bestätigt werden.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;

$dealershipId = require_dealership();

$leadId = (int) ($_GET['id'] ?? 0);
$lead = Database::fetch(
    'SELECT l.*, v.make, v.model, v.variant, v.price,
            (SELECT vi.thumb_path FROM vehicle_images vi WHERE vi.vehicle_id = v.id ORDER BY vi.is_main DESC, vi.sort_order LIMIT 1) AS thumb
     FROM leads l LEFT JOIN vehicles v ON v.id = l.vehicle_id
     WHERE l.id = :id AND l.dealership_id = :did',
    ['id' => $leadId, 'did' => $dealershipId]
);
if ($lead === null) {
    http_response_code(404);
    require BASE_PATH . '/errors/404.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'send_message') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            Session::flash('danger', 'Die Nachricht darf nicht leer sein.');
        } else {
            Database::insert('messages', [
                'lead_id'     => $leadId,
                'direction'   => 'outbound',
                'sender_name' => \App\Auth\AuthService::fullName(),
                'body'        => mb_substr($body, 0, 5000),
                'created_at'  => Database::now(),
            ]);
            if ((string) $lead['status'] === 'new') {
                Database::update('leads', $leadId, ['status' => 'active', 'updated_at' => Database::now()]);
            } else {
                Database::update('leads', $leadId, ['updated_at' => Database::now()]);
            }
            ActivityLogger::log((int) $currentUser['id'], 'lead.reply_sent', "Antwort auf Anfrage #{$leadId} gespeichert", 'lead', $leadId, $dealershipId);
            Session::flash('success', 'Antwort gespeichert. (Hinweis: Der E-Mail-Versand an den Kunden erfolgt gemäss Mail-Konfiguration.)');
        }
    } elseif ($action === 'set_status') {
        $status = (string) ($_POST['status'] ?? '');
        if (in_array($status, ['new', 'active', 'test_drive', 'won', 'lost'], true)) {
            Database::update('leads', $leadId, ['status' => $status, 'updated_at' => Database::now()]);
            ActivityLogger::log((int) $currentUser['id'], 'lead.status_changed', "Anfrage #{$leadId}: Status → {$status}", 'lead', $leadId, $dealershipId);
            Session::flash('success', 'Status aktualisiert.');
        }
    } elseif ($action === 'create_task') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            Database::insert('tasks', [
                'dealership_id' => $dealershipId,
                'user_id'       => (int) $currentUser['id'],
                'lead_id'       => $leadId,
                'vehicle_id'    => $lead['vehicle_id'] !== null ? (int) $lead['vehicle_id'] : null,
                'title'         => mb_substr($title, 0, 255),
                'due_at'        => ($_POST['due_at'] ?? '') !== '' ? (string) $_POST['due_at'] . ' 09:00:00' : null,
                'status'        => 'open',
                'created_at'    => Database::now(),
                'updated_at'    => Database::now(),
            ]);
            Session::flash('success', 'Aufgabe erstellt.');
        }
    }
    redirect('dashboard/lead.php?id=' . $leadId);
}

$messages = Database::fetchAll('SELECT * FROM messages WHERE lead_id = :lid ORDER BY id', ['lid' => $leadId]);
$tasks = Database::fetchAll(
    "SELECT * FROM tasks WHERE lead_id = :lid AND status = 'open' ORDER BY id DESC",
    ['lid' => $leadId]
);
$vehicleName = trim(($lead['make'] ?? '') . ' ' . ($lead['model'] ?? '')) ?: '-';

$pageTitle = (string) $lead['customer_name'];
$activeNav = 'leads';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div class="flex-center gap-2">
        <span class="avatar avatar-lg"><?= e(initials((string) $lead['customer_name'])) ?></span>
        <div>
            <h1><?= e($lead['customer_name']) ?></h1>
            <div class="sub"><?= t('lead.interested_in') ?>: <strong><?= e($vehicleName) ?></strong>
                <?= $lead['price'] !== null ? '· ' . format_price($lead['price']) : '' ?></div>
        </div>
    </div>
    <a class="btn btn-secondary" href="<?= base_url('dashboard/leads.php') ?>"><?= icon('chevron-left', 15) ?> <?= t('lead.all_leads') ?></a>
</div>

<div class="split split-3-2">
    <!-- Nachrichtenverlauf -->
    <div class="card">
        <div class="card-header"><h2><?= t('lead.conversation') ?></h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;max-height:460px;overflow-y:auto">
            <?php if ($messages === []): ?>
                <p class="text-muted text-center"><?= t('lead.no_messages') ?></p>
            <?php endif; ?>
            <?php foreach ($messages as $message): ?>
                <div style="max-width:85%;<?= $message['direction'] === 'outbound' ? 'align-self:flex-end' : '' ?>">
                    <div style="background:<?= $message['direction'] === 'outbound' ? 'var(--primary-soft)' : 'var(--bg)' ?>;border-radius:14px;padding:12px 15px;font-size:14px;white-space:pre-line"><?= e($message['body']) ?></div>
                    <div class="text-xs text-muted mt-1" style="<?= $message['direction'] === 'outbound' ? 'text-align:right' : '' ?>">
                        <?= e($message['sender_name'] ?? '') ?> · <?= e(time_ago((string) $message['created_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-body" style="border-top:1px solid var(--border)">
            <div id="aiDraftNote" class="alert alert-warning" style="display:none"></div>
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="send_message">
                <div class="form-group">
                    <textarea class="form-control" name="body" id="replyBody" rows="4" placeholder="<?= t('lead.reply_placeholder') ?>"></textarea>
                </div>
                <div class="flex gap-1" style="flex-wrap:wrap">
                    <button class="btn btn-primary" type="submit"><?= icon('send', 15) ?> <?= t('lead.reply') ?></button>
                    <button class="btn btn-secondary" type="button" id="aiDraftBtn">
                        <?= icon('edit', 15) ?> <?= t('lead.ai_draft') ?>
                    </button>
                </div>
                <div class="text-xs text-muted mt-1">
                    <?= t('lead.ai_notice') ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Seitenleiste -->
    <div>
        <?php if ($lead['thumb'] !== null): ?>
            <div class="card mb-3" style="overflow:hidden">
                <img src="<?= e(upload_url((string) $lead['thumb'])) ?>" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover">
                <div class="card-body" style="padding:14px 18px">
                    <div class="fw-600"><?= e($vehicleName) ?></div>
                    <?php if ($lead['vehicle_id'] !== null): ?>
                        <a class="text-sm" href="<?= base_url('dashboard/vehicle.php?id=' . (int) $lead['vehicle_id']) ?>"><?= t('lead.open_vehicle') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header"><h2><?= t('lead.details') ?></h2></div>
            <div class="card-body">
                <?php if ($lead['score'] !== null): ?>
                    <div class="flex-between mb-2">
                        <span class="text-secondary"><?= t('lead.score') ?></span>
                        <span class="fw-700" style="font-size:18px"><?= (int) $lead['score'] ?>/100</span>
                    </div>
                    <div class="score-bar mb-2"><div class="fill <?= rating_class((int) $lead['score']) ?>" style="width:<?= (int) $lead['score'] ?>%"></div></div>
                <?php endif; ?>
                <table class="table">
                    <?php if (!empty($lead['customer_email'])): ?>
                        <tr><td class="text-muted"><?= t('auth.email') ?></td><td><a href="mailto:<?= e($lead['customer_email']) ?>"><?= e($lead['customer_email']) ?></a></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($lead['customer_phone'])): ?>
                        <tr><td class="text-muted"><?= t('auth.phone') ?></td><td><a href="tel:<?= e($lead['customer_phone']) ?>"><?= e($lead['customer_phone']) ?></a></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted"><?= t('lead.source') ?></td><td><?= e($lead['source'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted"><?= t('lead.received') ?></td><td><?= e(format_datetime((string) $lead['created_at'])) ?></td></tr>
                </table>

                <form method="post" class="mt-2">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <label class="form-label"><?= t('common.status') ?></label>
                    <div class="flex gap-1">
                        <select class="form-control" name="status">
                            <option value="new" <?= $lead['status'] === 'new' ? 'selected' : '' ?>><?= t('leads.status.new') ?></option>
                            <option value="active" <?= $lead['status'] === 'active' ? 'selected' : '' ?>><?= t('leads.status.active') ?></option>
                            <option value="test_drive" <?= $lead['status'] === 'test_drive' ? 'selected' : '' ?>><?= t('leads.status.test_drive') ?></option>
                            <option value="won" <?= $lead['status'] === 'won' ? 'selected' : '' ?>><?= t('leads.status.won') ?></option>
                            <option value="lost" <?= $lead['status'] === 'lost' ? 'selected' : '' ?>><?= t('leads.status.lost') ?></option>
                        </select>
                        <button class="btn btn-secondary" type="submit">OK</button>
                    </div>
                </form>

                <!-- Schnellaktionen (§41) -->
                <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
                    <?php if (!empty($lead['customer_phone'])): ?>
                        <a class="btn btn-secondary btn-sm" href="tel:<?= e($lead['customer_phone']) ?>"><?= icon('phone', 14) ?> <?= t('lead.call') ?></a>
                    <?php endif; ?>
                    <?php if ((string) $lead['status'] !== 'test_drive' && (string) $lead['status'] !== 'won'): ?>
                        <form method="post">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="status" value="test_drive">
                            <button class="btn btn-secondary btn-sm" type="submit"><?= icon('calendar', 14) ?> <?= t('lead.test_drive') ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ((string) $lead['status'] !== 'won'): ?>
                        <form method="post" data-confirm="<?= t('lead.mark_sold_confirm') ?>" data-confirm-tone="success">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="status" value="won">
                            <button class="btn btn-secondary btn-sm" type="submit"><?= icon('check', 14) ?> <?= t('lead.mark_sold') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= t('lead.tasks') ?></h2></div>
            <div class="card-body">
                <?php foreach ($tasks as $task): ?>
                    <div class="flex-between mb-1 text-sm">
                        <span class="flex-center gap-1"><?= icon('check-square', 14) ?> <?= e($task['title']) ?></span>
                        <?php if ($task['due_at'] !== null): ?>
                            <span class="text-muted text-xs"><?= e(format_date((string) $task['due_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <form method="post" class="mt-2">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="create_task">
                    <div class="form-group">
                        <input class="form-control" type="text" name="title" placeholder="z.B. Probefahrt Samstag 10:00 vorbereiten" required>
                    </div>
                    <div class="flex gap-1">
                        <input class="form-control" type="date" name="due_at" style="width:auto">
                        <button class="btn btn-secondary" type="submit"><?= t('lead.create_task') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$leadIdJs = (int) $leadId;
$pageScripts = <<<HTML
<script>
(function () {
    document.getElementById('aiDraftBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        apiFetch('api/leads/draft-reply.php', { method: 'POST', body: { lead_id: {$leadIdJs} } }).then(function (res) {
            btn.disabled = false;
            if (!res.success) { showToast(res.error, 'danger'); return; }
            document.getElementById('replyBody').value = res.data.draft;
            var note = document.getElementById('aiDraftNote');
            note.style.display = 'flex';
            note.textContent = res.data.note;
        });
    });
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
