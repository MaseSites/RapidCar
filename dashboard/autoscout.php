<?php
/**
 * AutoScout24: Verbindung herstellen, prüfen, trennen.
 *
 * Die API arbeitet mit HTTP Basic Auth. Der Händler gibt hier seine
 * AutoScout24-Zugangsdaten ein; diese werden gegen die echte API geprüft
 * (GET /customers) und anschliessend verschlüsselt gespeichert.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Core\Database;
use App\Core\Session;
use App\Integration\AutoScoutAuthException;
use App\Integration\AutoScoutClient;
use App\Integration\AutoScoutReferences;
use App\Integration\AutoScoutService;

$dealershipId = require_dealership();

$error = null;
$authFailed = false;
$inputWarnings = [];
$customers = [];
$formUsername = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    if (!AuthService::isDealerAdmin() && !AuthService::isSuperAdmin()) {
        Session::flash('danger', t('autoscout.only_admin'));
        redirect('dashboard/autoscout.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $username = trim((string) ($_POST['as24_username'] ?? ''));
    $password = (string) ($_POST['as24_password'] ?? '');
    $formUsername = $username;

    try {
        if ($action === 'platform_connect') {
            // Plattform-Zugang: nur Kundennummer wählen, kein eigenes Passwort
            AutoScoutService::connectViaPlatform(
                $dealershipId,
                (string) ($_POST['customer_id'] ?? ''),
                null,
                (int) $currentUser['id']
            );
            AutoScoutReferences::clearCache();
            Session::flash('success', t('autoscout.connected_success'));
            redirect('dashboard/autoscout.php');
        }

        if ($action === 'verify') {
            // Auffälligkeiten in der Eingabe merken, falls die Anmeldung scheitert
            $inputWarnings = AutoScoutService::inputWarnings(
                (string) ($_POST['as24_username'] ?? ''),
                $password
            );

            // Schritt 1: Zugangsdaten prüfen und Kundenliste holen
            $customers = AutoScoutService::verifyCredentials($username, $password);
            // Das Passwort wird verschluesselt zwischengelegt. Sitzungsdateien
            // liegen im Klartext auf der Platte; dort hat ein Passwort des
            // Kunden nichts verloren, auch nicht fuer zwei Minuten.
            Session::set('as24_pending', [
                'username'  => $username,
                'password'  => \App\Core\Encryption::encrypt($password),
                'customers' => $customers,
            ]);
            Session::flash('success', t('autoscout.credentials_ok', ['count' => count($customers)]));
            redirect('dashboard/autoscout.php?step=customer');
        }

        if ($action === 'select_customer') {
            // Schritt 2: Kunde auswählen und Verbindung speichern
            $pending = Session::get('as24_pending');
            if (!is_array($pending) || !isset($pending['username'], $pending['password'])) {
                Session::flash('warning', t('autoscout.session_expired'));
                redirect('dashboard/autoscout.php');
            }
            $customerId = (string) ($_POST['customer_id'] ?? '');
            $sellId = null;
            $valid = false;
            foreach ($pending['customers'] as $customer) {
                if ($customer['id'] === $customerId) {
                    $valid = true;
                    $sellId = $customer['sellId'] ?? null;
                    break;
                }
            }
            if (!$valid) {
                throw new RuntimeException(t('autoscout.invalid_customer'));
            }

            // Schlaegt die Entschluesselung fehl (etwa nach einem Schluessel-
            // wechsel), wird nicht mit leerem Passwort weitergemacht.
            try {
                $pendingPassword = \App\Core\Encryption::decrypt((string) $pending['password']);
            } catch (\Throwable $e) {
                Session::remove('as24_pending');
                Session::flash('warning', t('autoscout.session_expired'));
                redirect('dashboard/autoscout.php');
            }

            AutoScoutService::connect(
                $dealershipId,
                (string) $pending['username'],
                $pendingPassword,
                $customerId,
                $sellId,
                (int) $currentUser['id']
            );
            Session::remove('as24_pending');
            AutoScoutReferences::clearCache();
            Session::flash('success', t('autoscout.connected_success'));
            redirect('dashboard/autoscout.php');
        }

        if ($action === 'test') {
            $result = AutoScoutService::testConnection($dealershipId);
            Session::flash($result['ok'] ? 'success' : 'danger', $result['message']);
            redirect('dashboard/autoscout.php');
        }

        if ($action === 'disconnect') {
            AutoScoutService::disconnect($dealershipId, (int) $currentUser['id']);
            Session::remove('as24_pending');
            AutoScoutReferences::clearCache();
            Session::flash('success', t('autoscout.disconnected'));
            redirect('dashboard/autoscout.php');
        }
    } catch (AutoScoutAuthException $e) {
        $error = $e->getMessage();
        $authFailed = true;
    } catch (\RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$step = (string) ($_GET['step'] ?? '');
$pending = Session::get('as24_pending');
$isConnected = AutoScoutService::isConnected($dealershipId);
$connectionMode = AutoScoutService::connectionMode($dealershipId);

// Plattform-Zugang: Autohäuser müssen dann nur ihre Kundennummer wählen
$platformMode = AutoScoutService::hasPlatformCredentials();
$platformCustomers = [];
$platformError = null;
if ($platformMode && !$isConnected) {
    try {
        // Nur Kundennummern anbieten, die noch keinem anderen Autohaus gehören
        $platformCustomers = AutoScoutService::availablePlatformCustomers($dealershipId);
    } catch (\RuntimeException $e) {
        $platformError = $e->getMessage();
    }
}
$integration = AutoScoutService::integrationRow($dealershipId);
$credentials = AutoScoutService::credentials($dealershipId);
$canManage = AuthService::isDealerAdmin() || AuthService::isSuperAdmin();

// Übertragene Fahrzeuge
$pushedVehicles = [];
if ($isConnected) {
    $pushedVehicles = Database::fetchAll(
        "SELECT v.id, v.make, v.model, v.status, cl.external_id, cl.updated_at
         FROM channel_listings cl
         INNER JOIN listings l ON l.id = cl.listing_id
         INNER JOIN vehicles v ON v.id = l.vehicle_id
         WHERE cl.dealership_id = :did AND cl.provider = 'autoscout24'
         ORDER BY cl.updated_at DESC LIMIT 20",
        ['did' => $dealershipId]
    );
}

$pageTitle = 'AutoScout24';
$activeNav = 'channels';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1>AutoScout24</h1>
        <div class="sub">
            <?php if ($isConnected): ?>
                <span class="badge badge-success"><?= icon('check', 13) ?> <?= t('channels.status.connected') ?></span>
                <?php if ($integration !== null && !empty($integration['account_name'])): ?>
                    <span><?= e((string) $integration['account_name']) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge badge-warning"><?= t('channels.status.disconnected') ?></span>
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-secondary" href="<?= base_url('dashboard/channels.php') ?>">
        <?= icon('chevron-left', 15) ?> <?= t('channels.title') ?>
    </a>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger"><?= icon('alert', 16) ?> <span><?= e($error) ?></span></div>
<?php endif; ?>

<?php if ($authFailed): ?>
    <!-- Gezielte Hilfe, wenn AutoScout24 die Anmeldung ablehnt -->
    <div class="card mb-3" style="border-color:#fedf89">
        <div class="card-header" style="background:var(--warning-soft)">
            <h2><?= icon('help', 16) ?> <?= t('autoscout.rejected_title') ?></h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2"><?= t('autoscout.rejected_lead') ?></p>

            <?php if ($inputWarnings !== []): ?>
                <div class="alert alert-warning">
                    <?= icon('alert', 16) ?>
                    <div>
                        <strong><?= t('autoscout.rejected_check_input') ?></strong>
                        <ul style="margin:6px 0 0 18px">
                            <?php foreach ($inputWarnings as $warning): ?>
                                <li><?= e($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid-2" style="gap:16px">
                <div>
                    <h3 style="font-size:14.5px" class="mb-1"><?= t('autoscout.rejected_cause_1_title') ?></h3>
                    <p class="text-secondary text-sm"><?= t('autoscout.rejected_cause_1_text') ?></p>
                </div>
                <div>
                    <h3 style="font-size:14.5px" class="mb-1"><?= t('autoscout.rejected_cause_2_title') ?></h3>
                    <p class="text-secondary text-sm"><?= t('autoscout.rejected_cause_2_text') ?></p>
                </div>
                <div>
                    <h3 style="font-size:14.5px" class="mb-1"><?= t('autoscout.rejected_cause_3_title') ?></h3>
                    <p class="text-secondary text-sm"><?= t('autoscout.rejected_cause_3_text') ?></p>
                </div>
                <div>
                    <h3 style="font-size:14.5px" class="mb-1"><?= t('autoscout.rejected_next_title') ?></h3>
                    <p class="text-secondary text-sm"><?= t('autoscout.rejected_next_text') ?></p>
                </div>
            </div>

            <div class="alert alert-info mt-2" style="margin-bottom:0">
                <?= icon('info', 16) ?>
                <span class="text-sm"><?= t('autoscout.rejected_note') ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isConnected): ?>
    <!-- ==================================================== Verbunden -->
    <div class="split split-2-1">
        <div class="card">
            <div class="card-header"><h2><?= t('autoscout.connection') ?></h2></div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <td class="text-muted" style="width:200px"><?= t('common.status') ?></td>
                        <td><span class="badge badge-success"><?= icon('check', 13) ?> <?= t('channels.status.connected') ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?= t('autoscout.mode') ?></td>
                        <td>
                            <?php if ($connectionMode === AutoScoutService::MODE_PLATFORM): ?>
                                <span class="badge badge-info"><?= t('autoscout.mode_platform') ?></span>
                            <?php else: ?>
                                <span class="badge badge-neutral"><?= t('autoscout.mode_own') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($connectionMode !== AutoScoutService::MODE_PLATFORM): ?>
                        <tr>
                            <td class="text-muted"><?= t('autoscout.username') ?></td>
                            <td><?= e($credentials['username'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?= t('autoscout.password') ?></td>
                            <td class="text-muted"><?= t('autoscout.password_stored') ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><?= t('autoscout.customer_id') ?></td>
                        <td><code><?= e($credentials['customer_id'] ?? '-') ?></code></td>
                    </tr>
                    <?php if (!empty($credentials['sell_id'])): ?>
                        <tr>
                            <td class="text-muted"><?= t('autoscout.sell_id') ?></td>
                            <td><code><?= e((string) $credentials['sell_id']) ?></code></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><?= t('autoscout.connected_at') ?></td>
                        <td><?= e(format_datetime((string) ($integration['connected_at'] ?? ''))) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?= t('autoscout.last_sync') ?></td>
                        <td><?= $integration !== null && $integration['last_sync_at'] !== null ? e(format_datetime((string) $integration['last_sync_at'])) : '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?= t('autoscout.api_url') ?></td>
                        <td class="text-xs"><code><?= e(AutoScoutClient::baseUrl()) ?></code></td>
                    </tr>
                </table>

                <?php if ($canManage): ?>
                    <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
                        <form method="post">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="test">
                            <button class="btn btn-secondary" type="submit"><?= icon('refresh', 15) ?> <?= t('channels.test') ?></button>
                        </form>
                        <form method="post" data-confirm="<?= t('autoscout.disconnect_confirm') ?>">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="disconnect">
                            <button class="btn btn-danger" type="submit"><?= t('channels.disconnect') ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-pad">
            <h3 style="font-size:15px" class="mb-2"><?= t('autoscout.next_steps') ?></h3>
            <p class="text-secondary text-sm"><?= t('autoscout.push_hint') ?></p>
            <a class="btn btn-accent btn-block mt-2" href="<?= base_url('dashboard/vehicles.php') ?>">
                <?= icon('car', 15) ?> <?= t('vehicles.title') ?>
            </a>
        </div>
    </div>

    <?php if ($pushedVehicles !== []): ?>
        <div class="card mt-3">
            <div class="card-header"><h2><?= t('autoscout.transferred') ?></h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= t('leads.col.vehicle') ?></th>
                            <th><?= t('autoscout.listing_id') ?></th>
                            <th><?= t('common.status') ?></th>
                            <th><?= t('common.updated') ?></th>
                            <th><?= t('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pushedVehicles as $row): ?>
                            <tr>
                                <td class="fw-600"><?= e(trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''))) ?></td>
                                <td class="text-xs"><code><?= e((string) $row['external_id']) ?></code></td>
                                <td><span class="badge badge-neutral"><?= e(vehicle_status_label((string) $row['status'])) ?></span></td>
                                <td class="text-muted"><?= e(time_ago((string) $row['updated_at'])) ?></td>
                                <td>
                                    <a class="btn btn-secondary btn-sm" href="<?= base_url('dashboard/vehicle.php?id=' . (int) $row['id']) ?>">
                                        <?= t('common.open') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($step === 'customer' && is_array($pending) && !empty($pending['customers'])): ?>
    <!-- ============================================ Schritt 2: Kunde wählen -->
    <div class="card" style="max-width:620px">
        <div class="card-header">
            <h2><?= t('autoscout.select_customer') ?></h2>
            <span class="badge badge-success"><?= icon('check', 13) ?> <?= t('autoscout.step_credentials') ?></span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2"><?= t('autoscout.select_customer_hint') ?></p>
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="select_customer">
                <div style="display:flex;flex-direction:column;gap:10px">
                    <?php foreach ($pending['customers'] as $index => $customer): ?>
                        <label class="integration-card" style="cursor:pointer">
                            <input type="radio" name="customer_id" value="<?= e($customer['id']) ?>"
                                   <?= $index === 0 ? 'checked' : '' ?> style="accent-color:var(--primary)">
                            <div class="body">
                                <h3><?= t('autoscout.customer_id') ?>: <?= e($customer['id']) ?></h3>
                                <div class="status">
                                    <?php if (!empty($customer['sellId'])): ?>
                                        <span class="text-muted"><?= t('autoscout.sell_id') ?>: <?= e((string) $customer['sellId']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-accent btn-block mt-3" type="submit">
                    <?= icon('check', 15) ?> <?= t('autoscout.finish_connection') ?>
                </button>
            </form>
        </div>
    </div>

<?php elseif ($platformMode && $platformCustomers !== []): ?>
    <!-- =============== Plattform-Zugang: nur Kundennummer wählen -->
    <div class="split split-3-2">
        <div class="card">
            <div class="card-header">
                <h2><?= t('autoscout.platform_title') ?></h2>
                <span class="badge badge-success"><?= icon('check', 13) ?> <?= t('autoscout.platform_badge') ?></span>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-3"><?= t('autoscout.platform_lead') ?></p>
                <?php if ($canManage): ?>
                    <form method="post">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="platform_connect">
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <?php foreach ($platformCustomers as $index => $customer): ?>
                                <label class="integration-card" style="cursor:pointer">
                                    <input type="radio" name="customer_id" value="<?= e($customer['id']) ?>"
                                           <?= $index === 0 ? 'checked' : '' ?> style="accent-color:var(--primary)">
                                    <div class="body">
                                        <h3><?= t('autoscout.customer_id') ?>: <?= e($customer['id']) ?></h3>
                                        <?php if (!empty($customer['sellId'])): ?>
                                            <div class="status text-muted"><?= t('autoscout.sell_id') ?>: <?= e((string) $customer['sellId']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-accent btn-lg btn-block mt-3" type="submit">
                            <?= icon('check', 16) ?> <?= t('autoscout.finish_connection') ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning"><?= icon('info', 16) ?> <span><?= t('autoscout.only_admin') ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-pad">
            <h3 style="font-size:15px" class="mb-2"><?= t('autoscout.platform_why_title') ?></h3>
            <p class="text-secondary text-sm"><?= t('autoscout.platform_why_text') ?></p>
            <div class="alert alert-info mt-2" style="margin-bottom:0">
                <?= icon('lock', 16) ?>
                <span class="text-sm"><?= t('autoscout.platform_security') ?></span>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ======================================== Schritt 1: Zugangsdaten -->
    <?php if ($platformError !== null): ?>
        <div class="alert alert-warning">
            <?= icon('alert', 16) ?>
            <span><?= t('autoscout.platform_unavailable') ?> <?= e($platformError) ?></span>
        </div>
    <?php endif; ?>
    <div class="split split-3-2">
        <div class="card">
            <div class="card-header"><h2><?= t('autoscout.connect_title') ?></h2></div>
            <div class="card-body">
                <p class="text-secondary mb-3"><?= t('autoscout.connect_lead') ?></p>

                <?php if (!$canManage): ?>
                    <div class="alert alert-warning"><?= icon('info', 16) ?> <span><?= t('autoscout.only_admin') ?></span></div>
                <?php else: ?>
                    <form method="post" autocomplete="off">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="verify">
                        <div class="form-group">
                            <label class="form-label" for="as24_username"><?= t('autoscout.username') ?></label>
                            <input class="form-control" type="text" id="as24_username" name="as24_username"
                                   value="<?= e($formUsername) ?>" autocomplete="off" required>
                            <div class="form-hint"><?= t('autoscout.username_hint') ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="as24_password"><?= t('autoscout.password') ?></label>
                            <input class="form-control" type="password" id="as24_password" name="as24_password"
                                   autocomplete="new-password" required>
                            <div class="form-hint"><?= t('autoscout.password_hint') ?></div>
                        </div>
                        <button class="btn btn-accent btn-lg" type="submit">
                            <?= icon('link', 16) ?> <?= t('autoscout.verify_button') ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-pad">
            <h3 style="font-size:15px" class="mb-2"><?= t('autoscout.how_it_works') ?></h3>
            <ol class="text-secondary text-sm" style="padding-left:18px;display:flex;flex-direction:column;gap:8px">
                <li><?= t('autoscout.how_1') ?></li>
                <li><?= t('autoscout.how_2') ?></li>
                <li><?= t('autoscout.how_3') ?></li>
            </ol>
            <div class="alert alert-info mt-2" style="margin-bottom:0">
                <?= icon('lock', 16) ?>
                <span class="text-sm"><?= t('autoscout.security_note') ?></span>
            </div>
        </div>
    </div>

    <!-- Hinweis für Konten mit Social-Login -->
    <div class="card card-pad mt-3" style="border-color:#fedf89;background:var(--warning-soft)">
        <div class="flex gap-2" style="align-items:flex-start">
            <?= icon('alert', 20) ?>
            <div>
                <h3 style="font-size:15px;margin-bottom:6px"><?= t('autoscout.google_title') ?></h3>
                <p class="text-secondary text-sm mb-1"><?= t('autoscout.google_text') ?></p>
                <p class="text-sm mb-1"><strong><?= t('autoscout.google_solution') ?></strong></p>
                <p class="text-secondary text-sm" style="margin:0"><?= t('autoscout.google_where') ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
