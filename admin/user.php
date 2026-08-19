<?php
/**
 * Benutzerprofil im Admin (§49) + Admin-Aktionen (§50):
 * aktivieren/deaktivieren, Rolle ändern, Passwort-Reset auslösen,
 * Autohaus/Fahrzeuge ansehen, Aktivitätsprotokoll.
 * Passwörter werden NIEMALS angezeigt (§50).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Auth\PasswordReset;
use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;

require_super_admin();

$userId = (int) ($_GET['id'] ?? 0);
$user = Database::fetch(
    'SELECT u.*, d.name AS dealership_name, d.id AS d_id
     FROM users u LEFT JOIN dealerships d ON d.id = u.dealership_id
     WHERE u.id = :id',
    ['id' => $userId]
);
if ($user === null) {
    http_response_code(404);
    require BASE_PATH . '/errors/404.php';
    exit;
}

// ------------------------------------------------------------ Admin-Aktionen
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $adminId = (int) $currentUser['id'];

    // Selbstschutz: eigenes Konto nicht deaktivieren/herabstufen
    $isSelf = $userId === $adminId;

    switch ($action) {
        case 'deactivate':
            if ($isSelf) {
                Session::flash('danger', 'Das eigene Konto kann nicht deaktiviert werden.');
                break;
            }
            Database::update('users', $userId, ['is_active' => 0, 'updated_at' => Database::now()]);
            ActivityLogger::log($adminId, 'admin.user_deactivated', "Benutzer #{$userId} deaktiviert", 'user', $userId);
            Session::flash('success', 'Benutzer deaktiviert.');
            break;

        case 'activate':
            Database::update('users', $userId, ['is_active' => 1, 'updated_at' => Database::now()]);
            ActivityLogger::log($adminId, 'admin.user_activated', "Benutzer #{$userId} aktiviert", 'user', $userId);
            Session::flash('success', 'Benutzer aktiviert.');
            break;

        case 'change_role':
            $newRole = (string) ($_POST['role'] ?? '');
            if ($isSelf) {
                Session::flash('danger', 'Die eigene Rolle kann nicht geändert werden.');
                break;
            }
            if (!in_array($newRole, [AuthService::ROLE_SUPER_ADMIN, AuthService::ROLE_DEALER_ADMIN, AuthService::ROLE_DEALER_USER], true)) {
                Session::flash('danger', 'Ungültige Rolle.');
                break;
            }
            Database::update('users', $userId, ['role' => $newRole, 'updated_at' => Database::now()]);
            ActivityLogger::log($adminId, 'admin.role_changed', "Rolle von Benutzer #{$userId} geändert zu {$newRole}", 'user', $userId);
            Session::flash('success', 'Rolle geändert.');
            break;

        case 'delete':
            try {
                \App\Service\AdminRemovalService::removeUser($userId, $adminId);
                ActivityLogger::log($adminId, 'admin.user_deleted', "Benutzer #{$userId} endgueltig geloescht", 'user', $userId);
                Session::flash('success', 'Konto endgültig gelöscht.');
                redirect('admin/users.php');
            } catch (\Throwable $e) {
                Session::flash('danger', $e->getMessage());
            }
            break;

        case 'password_reset':
            PasswordReset::request((string) $user['email']);
            ActivityLogger::log($adminId, 'admin.password_reset_triggered', "Passwort-Reset für Benutzer #{$userId} ausgelöst", 'user', $userId);
            Session::flash('success', 'Passwort-Reset-Link wurde versendet (bzw. im Mail-Log protokolliert).');
            break;
    }
    redirect('admin/user.php?id=' . $userId);
}

// Fahrzeuge des Autohauses
$vehicles = [];
if ($user['dealership_id'] !== null) {
    $vehicles = Database::fetchAll(
        'SELECT id, make, model, price, status, created_at FROM vehicles WHERE dealership_id = :did ORDER BY id DESC LIMIT 10',
        ['did' => (int) $user['dealership_id']]
    );
}

// Aktivitätsprotokoll dieses Benutzers
$activities = Database::fetchAll(
    'SELECT * FROM activity_logs WHERE user_id = :uid ORDER BY id DESC LIMIT 15',
    ['uid' => $userId]
);

$pageTitle = $user['first_name'] . ' ' . $user['last_name'];
$activeNav = 'users';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="page-head">
    <div class="flex-center gap-2">
        <span class="avatar avatar-lg"><?= e(initials($user['first_name'] . ' ' . $user['last_name'])) ?></span>
        <div>
            <h1><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h1>
            <div class="sub">
                <?php if ((int) $user['is_active'] === 1): ?>
                    <span class="badge badge-success">Aktiv</span>
                <?php else: ?>
                    <span class="badge badge-danger">Deaktiviert</span>
                <?php endif; ?>
                <span class="badge badge-neutral"><?= e($user['role']) ?></span>
                <?php if ((int) ($user['is_demo'] ?? 0) === 1): ?>
                    <span class="badge badge-info">Demo-Konto</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <a class="btn btn-secondary" href="<?= base_url('admin/users.php') ?>"><?= icon('chevron-left', 14) ?> Zur Benutzerliste</a>
</div>

<div class="grid-2">
    <div>
        <div class="card mb-3">
            <div class="card-header"><h2>Profil</h2></div>
            <div class="card-body">
                <table class="table">
                    <tr><td class="text-muted" style="width:170px">E-Mail</td><td><?= e($user['email']) ?></td></tr>
                    <tr><td class="text-muted">Telefon</td><td><?= e($user['phone'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Land</td><td><?= e($user['country'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Autohaus</td><td><?= e($user['dealership_name'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Registriert</td><td><?= e(format_datetime((string) $user['created_at'])) ?></td></tr>
                    <tr><td class="text-muted">Letzter Login</td><td><?= $user['last_login_at'] !== null ? e(format_datetime((string) $user['last_login_at'])) : '-' ?></td></tr>
                    <tr><td class="text-muted">E-Mail verifiziert</td><td><?= $user['email_verified_at'] !== null ? e(format_datetime((string) $user['email_verified_at'])) : 'Nein' ?></td></tr>
                    <tr><td class="text-muted">Onboarding</td><td><?= $user['onboarding_completed_at'] !== null ? 'Abgeschlossen' : 'Offen' ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Aktionen</h2></div>
            <div class="card-body flex gap-1" style="flex-wrap:wrap">
                <?php if ((int) $user['is_active'] === 1): ?>
                    <form method="post" data-confirm="Diesen Benutzer wirklich deaktivieren?">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="deactivate">
                        <button class="btn btn-danger btn-sm" type="submit">Deaktivieren</button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="activate">
                        <button class="btn btn-primary btn-sm" type="submit">Aktivieren</button>
                    </form>
                <?php endif; ?>
                <form method="post" data-confirm="Passwort-Reset-Link an diesen Benutzer senden?" data-confirm-tone="success">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="password_reset">
                    <button class="btn btn-secondary btn-sm" type="submit">Passwort-Reset auslösen</button>
                </form>
                <form method="post" class="flex gap-1">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="change_role">
                    <select class="form-control" name="role" style="width:auto;padding:7px 10px;font-size:13px">
                        <option value="dealer_user" <?= $user['role'] === 'dealer_user' ? 'selected' : '' ?>>dealer_user</option>
                        <option value="dealer_admin" <?= $user['role'] === 'dealer_admin' ? 'selected' : '' ?>>dealer_admin</option>
                        <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : '' ?>>super_admin</option>
                    </select>
                    <button class="btn btn-secondary btn-sm" type="submit">Rolle ändern</button>
                </form>
            </div>
        </div>
    </div>

    <div>
        <div class="card mb-3">
            <div class="card-header">
                <h2>Fahrzeuge des Autohauses</h2>
                <?php if ($user['dealership_id'] !== null): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= base_url('admin/vehicles.php?dealership=' . (int) $user['dealership_id']) ?>">Alle</a>
                <?php endif; ?>
            </div>
            <?php if ($vehicles === []): ?>
                <div class="empty-state"><p>Keine Fahrzeuge vorhanden.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Fahrzeug</th><th>Preis</th><th>Status</th><th>Erstellt</th></tr></thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr>
                                    <td class="fw-600"><?= e(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) ?: 'Unbenannt') ?></td>
                                    <td><?= format_price($vehicle['price']) ?></td>
                                    <td><span class="badge badge-neutral"><?= e(vehicle_status_label((string) $vehicle['status'])) ?></span></td>
                                    <td class="text-muted"><?= e(format_date((string) $vehicle['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2>Aktivitätsprotokoll</h2></div>
            <?php if ($activities === []): ?>
                <div class="empty-state"><p>Keine Aktivitäten.</p></div>
            <?php else: ?>
                <?php foreach ($activities as $log): ?>
                    <div class="reco-card">
                        <div class="body">
                            <div class="title text-sm"><span class="badge badge-neutral"><?= e($log['action']) ?></span></div>
                            <div class="msg"><?= e($log['description']) ?></div>
                        </div>
                        <span class="text-xs text-muted" style="white-space:nowrap"><?= e(format_datetime((string) $log['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2>E-Mails an dieses Konto</h2></div>
    <div class="card-body">
        <?php
        $sentEmails = Database::fetchAll(
            'SELECT * FROM sent_emails WHERE recipient = :r ORDER BY id DESC LIMIT 25',
            ['r' => mb_strtolower((string) $user['email'])]
        );
        ?>
        <?php if ($sentEmails === []): ?>
            <p class="text-sm text-muted">Noch keine E-Mails an dieses Konto versendet.</p>
        <?php else: ?>
            <p class="text-sm text-secondary mb-2">Alle automatisch versendeten E-Mails, neueste zuerst (maximal 25).</p>
            <?php foreach ($sentEmails as $sentEmail): ?>
                <details class="mail-log-entry">
                    <summary>
                        <span class="badge <?= (int) $sentEmail['was_sent'] === 1 ? 'badge-success' : 'badge-danger' ?>">
                            <?= (int) $sentEmail['was_sent'] === 1 ? 'versendet' : 'fehlgeschlagen' ?>
                        </span>
                        <span class="fw-600 text-sm"><?= e((string) $sentEmail['subject']) ?></span>
                        <span class="text-xs text-muted"><?= e(format_datetime((string) $sentEmail['created_at'])) ?></span>
                    </summary>
                    <div class="mail-log-body text-sm">
                        <?= nl2br(e(trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $sentEmail['body']))))) ?>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ((string) $user['role'] !== App\Auth\AuthService::ROLE_SUPER_ADMIN && (int) $user['id'] !== (int) $currentUser['id']): ?>
<div class="card mb-3" style="border-color:#f2c1bd">
    <div class="card-header"><h2 style="color:var(--danger)">Konto löschen</h2></div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-2">
            Löscht das Konto endgültig. Ist es das letzte Konto seines
            Autohauses, verschwinden auch alle Fahrzeuge, Inserate und Fotos.
            Das lässt sich nicht rückgängig machen.
        </p>
        <form method="post" onsubmit="return confirm('Dieses Konto endgültig löschen? Das lässt sich nicht rückgängig machen.');">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <button class="btn btn-danger" type="submit"><?= icon('trash', 15) ?> Endgültig löschen</button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
