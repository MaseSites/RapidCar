<?php
/**
 * Ricardo verbinden.
 *
 * Der Haendler gibt kein Passwort an uns weiter: er wird zu ricardo.ch
 * geschickt, gibt dort die Verbindung frei und kommt zurueck. Erst danach
 * holt die Anwendung das Zugriffstoken.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Core\Database;
use App\Core\Session;
use App\Integration\RicardoService;

$dealershipId = require_dealership();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    if (!AuthService::isDealerAdmin() && !AuthService::isSuperAdmin()) {
        Session::flash('danger', t('autoscout.only_admin'));
        redirect('dashboard/ricardo.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'begin') {
            // Kennung holen und den Haendler zur Freigabe schicken
            $begin = RicardoService::beginConnection();
            Session::set('ricardo_pending', $begin['key']);
            header('Location: ' . $begin['url']);
            exit;
        }

        if ($action === 'finish') {
            $pending = (string) (Session::get('ricardo_pending') ?? '');
            if ($pending === '') {
                Session::flash('warning', 'Die Freigabe ist abgelaufen. Bitte noch einmal beginnen.');
                redirect('dashboard/ricardo.php');
            }
            RicardoService::completeConnection($dealershipId, $pending, (int) $currentUser['id']);
            Session::remove('ricardo_pending');
            Session::flash('success', 'Ricardo ist verbunden.');
            redirect('dashboard/ricardo.php');
        }

        if ($action === 'test') {
            $result = RicardoService::testConnection($dealershipId);
            Session::flash($result['ok'] ? 'success' : 'danger', $result['message']);
            redirect('dashboard/ricardo.php');
        }

        if ($action === 'disconnect') {
            RicardoService::disconnect($dealershipId, (int) $currentUser['id']);
            Session::remove('ricardo_pending');
            Session::flash('success', 'Verbindung getrennt.');
            redirect('dashboard/ricardo.php');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$hasPartner = RicardoService::hasPartnerCredentials();
$isConnected = RicardoService::isConnected($dealershipId);
$pendingKey = (string) (Session::get('ricardo_pending') ?? '');
$canManage = AuthService::isDealerAdmin() || AuthService::isSuperAdmin();
$integration = Database::fetch(
    'SELECT * FROM integrations WHERE dealership_id = :d AND provider = :p',
    ['d' => $dealershipId, 'p' => RicardoService::PROVIDER]
);

$pageTitle = 'Ricardo verbinden';
$activeNav = 'channels';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1>Ricardo</h1>
        <div class="sub">Fahrzeuge als Festpreis-Artikel auf ricardo.ch einstellen.</div>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger mb-3"><?= icon('alert-triangle', 16) ?> <?= e($error) ?></div>
<?php endif; ?>

<?php if (!$hasPartner): ?>
    <div class="card" style="max-width:640px">
        <div class="card-body">
            <div class="alert alert-info" style="margin-bottom:0">
                <?= icon('info', 16) ?>
                <span>
                    Für Ricardo fehlt noch der Partnerschlüssel. Den beantragt der
                    Betreiber einmalig bei Ricardo, danach kannst du dich hier mit
                    einem Klick verbinden.
                </span>
            </div>
        </div>
    </div>

<?php elseif ($isConnected): ?>
    <div class="card mb-3" style="max-width:640px">
        <div class="card-header">
            <h2>Verbunden</h2>
            <span class="badge badge-success"><?= icon('check', 13) ?> aktiv</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">
                Konto: <strong><?= e((string) ($integration['account_name'] ?? 'unbekannt')) ?></strong>
            </p>
            <div class="flex gap-1" style="flex-wrap:wrap">
                <?php if ($canManage): ?>
                    <form method="post">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="test">
                        <button class="btn btn-secondary" type="submit"><?= icon('refresh', 15) ?> Verbindung prüfen</button>
                    </form>
                    <form method="post" data-confirm="Verbindung zu Ricardo wirklich trennen?">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="disconnect">
                        <button class="btn btn-danger" type="submit">Trennen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="split split-3-2">
        <div class="card">
            <div class="card-header"><h2>Verbinden</h2></div>
            <div class="card-body">
                <?php if ($canManage): ?>
                    <?php if ($pendingKey === ''): ?>
                        <p class="text-secondary mb-3">
                            Ein Klick führt dich zu ricardo.ch. Dort meldest du dich mit
                            deinem gewohnten Konto an und gibst die Verbindung frei.
                            Danach kommst du hierher zurück.
                        </p>
                        <form method="post">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="begin">
                            <button class="btn btn-primary btn-lg" type="submit">
                                <?= icon('external-link', 16) ?> Bei Ricardo freigeben
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="text-secondary mb-3">
                            Sobald du die Verbindung auf ricardo.ch freigegeben hast,
                            schliessen wir sie hier ab.
                        </p>
                        <form method="post">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="finish">
                            <button class="btn btn-primary btn-lg" type="submit">
                                <?= icon('check', 16) ?> Freigabe abschliessen
                            </button>
                        </form>
                        <form method="post" class="mt-2">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="begin">
                            <button class="btn btn-secondary btn-sm" type="submit">Noch einmal beginnen</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-warning"><?= icon('info', 16) ?> <span><?= t('autoscout.only_admin') ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-pad">
            <h3 style="font-size:15px" class="mb-2">Gut zu wissen</h3>
            <ul class="text-secondary text-sm" style="margin:0 0 0 18px;line-height:1.9">
                <li>Fahrzeuge gehen als Festpreis-Artikel hinaus, nicht als Auktion.</li>
                <li>Die Laufzeit beträgt 30 Tage; danach lässt sich das Fahrzeug erneut übertragen.</li>
                <li>Bis zu 10 Bilder je Fahrzeug.</li>
                <li>Die Fahrzeugdaten stehen in der Beschreibung, eigene Felder gibt es bei Ricardo nicht.</li>
            </ul>
            <div class="alert alert-info mt-2" style="margin-bottom:0">
                <?= icon('lock', 16) ?>
                <span class="text-sm">Dein Ricardo-Passwort brauchen wir nie.</span>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
