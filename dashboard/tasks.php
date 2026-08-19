<?php
/**
 * Aufgaben (§19): einfache Aufgabenverwaltung pro Autohaus.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Core\Session;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            Database::insert('tasks', [
                'dealership_id' => $dealershipId,
                'user_id'       => (int) $currentUser['id'],
                'title'         => mb_substr($title, 0, 255),
                'description'   => mb_substr((string) ($_POST['description'] ?? ''), 0, 2000),
                'due_at'        => ($_POST['due_at'] ?? '') !== '' ? (string) $_POST['due_at'] . ' 09:00:00' : null,
                'status'        => 'open',
                'created_at'    => Database::now(),
                'updated_at'    => Database::now(),
            ]);
            Session::flash('success', 'Aufgabe erstellt.');
        }
    } elseif ($action === 'toggle') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $task = Database::fetch(
            'SELECT * FROM tasks WHERE id = :id AND dealership_id = :did',
            ['id' => $taskId, 'did' => $dealershipId]
        );
        if ($task !== null) {
            Database::update('tasks', $taskId, [
                'status'     => (string) $task['status'] === 'open' ? 'done' : 'open',
                'updated_at' => Database::now(),
            ]);
        }
    } elseif ($action === 'delete') {
        Database::run(
            'DELETE FROM tasks WHERE id = :id AND dealership_id = :did',
            ['id' => (int) ($_POST['task_id'] ?? 0), 'did' => $dealershipId]
        );
    }
    redirect('dashboard/tasks.php');
}

$openTasks = Database::fetchAll(
    "SELECT t.*, v.make, v.model, l.customer_name FROM tasks t
     LEFT JOIN vehicles v ON v.id = t.vehicle_id
     LEFT JOIN leads l ON l.id = t.lead_id
     WHERE t.dealership_id = :did AND t.status = 'open'
     ORDER BY CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END, t.due_at",
    ['did' => $dealershipId]
);
$doneTasks = Database::fetchAll(
    "SELECT * FROM tasks WHERE dealership_id = :did AND status = 'done' ORDER BY updated_at DESC LIMIT 10",
    ['did' => $dealershipId]
);

$pageTitle = t('sidebar.tasks');
$activeNav = 'tasks';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('tasks.title') ?></h1>
        <div class="sub"><?= t('tasks.open_count', ['count' => count($openTasks)]) ?></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" class="flex gap-1" style="flex-wrap:wrap">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <input class="form-control" style="flex:1;min-width:220px" type="text" name="title" placeholder="<?= t('tasks.new_placeholder') ?>" required>
            <input class="form-control" style="width:auto" type="date" name="due_at">
            <button class="btn btn-primary" type="submit"><?= icon('plus', 15) ?> <?= t('tasks.create') ?></button>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2><?= t('tasks.open') ?></h2></div>
    <?php if ($openTasks === []): ?>
        <div class="empty-state"><div class="empty-icon"><?= icon('check-square', 22) ?></div><p><?= t('tasks.none_open') ?></p></div>
    <?php else: ?>
        <?php foreach ($openTasks as $task): ?>
            <div class="reco-card">
                <form method="post">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <button class="icon-btn" type="submit" title="<?= t('tasks.mark_done') ?>"><?= icon('check-square', 16) ?></button>
                </form>
                <div class="body">
                    <div class="title"><?= e($task['title']) ?></div>
                    <div class="msg text-xs">
                        <?php if ($task['make'] !== null): ?><?= e(trim($task['make'] . ' ' . ($task['model'] ?? ''))) ?> · <?php endif; ?>
                        <?php if ($task['customer_name'] !== null): ?><?= e($task['customer_name']) ?> · <?php endif; ?>
                        <?= $task['due_at'] !== null ? t('tasks.due') . ': ' . e(format_date((string) $task['due_at'])) : t('tasks.no_due') ?>
                    </div>
                </div>
                <form method="post" data-confirm="<?= t('tasks.delete_confirm') ?>">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <button class="icon-btn" type="submit" title="<?= t('common.delete') ?>"><?= icon('trash', 15) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($doneTasks !== []): ?>
<div class="card">
    <div class="card-header"><h2><?= t('tasks.done_recent') ?></h2></div>
    <?php foreach ($doneTasks as $task): ?>
        <div class="reco-card" style="opacity:.6">
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                <button class="icon-btn" type="submit" title="<?= t('tasks.reopen') ?>"><?= icon('refresh', 15) ?></button>
            </form>
            <div class="body"><div class="title" style="text-decoration:line-through"><?= e($task['title']) ?></div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
