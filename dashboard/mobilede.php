<?php
/**
 * mobile.de: Verbindung herstellen, prüfen, trennen.
 *
 * Der Händler gibt die Zugangsdaten seines mobile.de-API-Benutzers ein
 * (Freischaltung durch mobile.de erforderlich). Sie werden gegen die echte
 * Seller-API geprüft und verschlüsselt gespeichert. Hat das Konto mehrere
 * Verkäuferkonten, wählt der Händler eines aus.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Core\Session;
use App\Integration\AutoScoutAuthException;
use App\Integration\MobileDeService;

$dealershipId = require_dealership();

$error = null;
$sellers = [];
$formUsername = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    if (!AuthService::isDealerAdmin() && !AuthService::isSuperAdmin()) {
        Session::flash('danger', t('autoscout.only_admin'));
        redirect('dashboard/mobilede.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $username = trim((string) ($_POST['md_username'] ?? ''));
    $password = (string) ($_POST['md_password'] ?? '');
    $formUsername = $username;

    try {
        if ($action === 'verify') {
            $sellers = MobileDeService::verifyCredentials($username, $password);
            if ($sellers === []) {
                $error = 'mobile.de hat kein Verkäuferkonto zu diesen Zugangsdaten geliefert.';
            } elseif (count($sellers) === 1) {
                MobileDeService::connect(
                    $dealershipId,
                    $username,
                    $password,
                    $sellers[0]['id'],
                    $sellers[0]['name'],
                    (int) $currentUser['id']
                );
                Session::flash('success', 'mobile.de ist verbunden.');
                redirect('dashboard/mobilede.php');
            }
            // Mehrere Konten: Auswahl unten anzeigen; die Zugangsdaten
            // kommen beim naechsten Schritt erneut mit (nie in der Session).
        }

        if ($action === 'choose') {
            $sellerId = (string) ($_POST['seller_id'] ?? '');
            $sellerName = (string) ($_POST['seller_name'] ?? '');
            if ($sellerId === '') {
                $error = 'Bitte ein Verkäuferkonto wählen.';
            } else {
                MobileDeService::connect($dealershipId, $username, $password, $sellerId, $sellerName, (int) $currentUser['id']);
                Session::flash('success', 'mobile.de ist verbunden.');
                redirect('dashboard/mobilede.php');
            }
        }

        if ($action === 'test') {
            $result = MobileDeService::testConnection($dealershipId);
            Session::flash($result['ok'] ? 'success' : 'danger', $result['message']);
            redirect('dashboard/mobilede.php');
        }

        if ($action === 'disconnect') {
            MobileDeService::disconnect($dealershipId, (int) $currentUser['id']);
            Session::flash('success', 'Verbindung getrennt.');
            redirect('dashboard/mobilede.php');
        }
    } catch (AutoScoutAuthException $e) {
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$isConnected = MobileDeService::isConnected($dealershipId);
$integration = App\Core\Database::fetch(
    'SELECT * FROM integrations WHERE dealership_id = :d AND provider = :p',
    ['d' => $dealershipId, 'p' => MobileDeService::PROVIDER]
);

$pageTitle = 'mobile.de verbinden';
$activeNav = 'channels';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1>mobile.de</h1>
        <div class="sub">Inserate direkt zu mobile.de übertragen (Seller-API).</div>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($isConnected): ?>
    <div class="card mb-3">
        <div class="card-header"><h2>Verbunden</h2></div>
        <div class="card-body">
            <p class="text-sm mb-2">
                <span class="badge badge-success">verbunden</span>
                <?= e((string) ($integration['account_name'] ?? '')) ?>
            </p>
            <div class="flex gap-1">
                <form method="post">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="test">
                    <button class="btn btn-secondary" type="submit"><?= icon('refresh', 15) ?> Verbindung prüfen</button>
                </form>
                <form method="post" data-confirm="mobile.de wirklich trennen? Bestehende Inserate auf der Börse bleiben unberührt.">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="disconnect">
                    <button class="btn btn-danger" type="submit"><?= icon('x', 15) ?> Trennen</button>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card mb-3" style="max-width:560px">
        <div class="card-header"><h2>Mit mobile.de verbinden</h2></div>
        <div class="card-body">
            <p class="text-sm text-secondary mb-2">
                Du brauchst einen von mobile.de freigeschalteten API-Benutzer
                (im Händlerbereich beantragen). Die Zugangsdaten werden gegen die
                echte Schnittstelle geprüft und verschlüsselt gespeichert.
            </p>
            <?php if ($sellers !== [] && count($sellers) > 1): ?>
                <form method="post">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="choose">
                    <input type="hidden" name="md_username" value="<?= e($formUsername) ?>">
                    <input type="hidden" name="md_password" value="<?= e((string) ($_POST['md_password'] ?? '')) ?>">
                    <div class="form-group">
                        <label class="form-label">Verkäuferkonto wählen</label>
                        <?php foreach ($sellers as $seller): ?>
                            <label class="form-check">
                                <input type="radio" name="seller_id" value="<?= e($seller['id']) ?>"
                                       data-name="<?= e($seller['name']) ?>">
                                <span><?= e($seller['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <input type="hidden" name="seller_name" id="mdSellerName" value="">
                    </div>
                    <button class="btn btn-primary" type="submit">Verbinden</button>
                </form>
                <script>
                document.querySelectorAll('input[name="seller_id"]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        document.getElementById('mdSellerName').value = radio.dataset.name || '';
                    });
                });
                </script>
            <?php else: ?>
                <form method="post">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="verify">
                    <div class="form-group">
                        <label class="form-label">API-Benutzername</label>
                        <input class="form-control" type="text" name="md_username" value="<?= e($formUsername) ?>" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">API-Passwort</label>
                        <input class="form-control" type="password" name="md_password" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= icon('link', 15) ?> Prüfen und verbinden</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card" style="max-width:560px">
        <div class="card-body text-sm text-secondary">
            Noch kein API-Zugang? Den beantragst du bei mobile.de im Händlerbereich
            (Stichwort Seller-API bzw. Schnittstellenpartner). Ohne Freischaltung
            lehnt mobile.de jede Anmeldung ab; hier wird nichts vorgetäuscht.
        </div>
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
