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
            // Mehrere Konten: Auswahl unten anzeigen. Die Zugangsdaten
            // werden verschluesselt in der Sitzung zwischengelegt. Frueher
            // standen sie als verstecktes Feld im Seitenquelltext, also im
            // Klartext im Browser und in jedem Zwischenspeicher.
            Session::set('mde_pending', [
                'username' => $username,
                'password' => \App\Core\Encryption::encrypt($password),
            ]);
        }

        if ($action === 'choose') {
            $sellerId = (string) ($_POST['seller_id'] ?? '');
            $sellerName = (string) ($_POST['seller_name'] ?? '');
            $pending = Session::get('mde_pending');
            if (!is_array($pending) || !isset($pending['username'], $pending['password'])) {
                Session::flash('warning', 'Die Anmeldung ist abgelaufen. Bitte noch einmal beginnen.');
                redirect('dashboard/mobilede.php');
            }
            try {
                $username = (string) $pending['username'];
                $password = \App\Core\Encryption::decrypt((string) $pending['password']);
            } catch (\Throwable $e) {
                Session::remove('mde_pending');
                Session::flash('warning', 'Die Anmeldung ist abgelaufen. Bitte noch einmal beginnen.');
                redirect('dashboard/mobilede.php');
            }
            if ($sellerId === '') {
                $error = 'Bitte ein Verkäuferkonto wählen.';
            } else {
                MobileDeService::connect($dealershipId, $username, $password, $sellerId, $sellerName, (int) $currentUser['id']);
                Session::remove('mde_pending');
                Session::flash('success', 'mobile.de ist verbunden.');
                redirect('dashboard/mobilede.php');
            }
        }

        if ($action === 'test') {
            $result = MobileDeService::testConnection($dealershipId);
            Session::flash($result['ok'] ? 'success' : 'danger', $result['message']);
            redirect('dashboard/mobilede.php');
        }

        if ($action === 'platform_connect') {
            // Verbindung ueber den Betreiber-Zugang: kein Passwort des Kunden
            MobileDeService::connectViaPlatform(
                $dealershipId,
                (string) ($_POST['seller_id'] ?? ''),
                (string) ($_POST['seller_name'] ?? ''),
                (int) $currentUser['id']
            );
            Session::flash('success', 'mobile.de ist verbunden.');
            redirect('dashboard/mobilede.php');
        }

        if ($action === 'request_activation') {
            // Der Kunde nennt nur seine mobile.de-Kundennummer. Den Rest
            // erledigt der Betreiber.
            $reqCustomer = trim(mb_substr((string) ($_POST['mde_customer_ref'] ?? ''), 0, 60));
            $reqCompany  = trim(mb_substr((string) ($_POST['mde_company'] ?? ''), 0, 190));
            $reqNote     = trim(mb_substr((string) ($_POST['mde_note'] ?? ''), 0, 500));

            if ($reqCustomer === '' && $reqCompany === '') {
                Session::flash('warning', 'Bitte die mobile.de-Kundennummer oder den Firmennamen angeben.');
                redirect('dashboard/mobilede.php');
            }

            \App\Service\ActivityLogger::log(
                (int) $currentUser['id'],
                'mobilede.activation_requested',
                'Freischaltung angefragt (Kundennummer: ' . ($reqCustomer !== '' ? $reqCustomer : 'ohne')
                    . ', Firma: ' . ($reqCompany !== '' ? $reqCompany : 'ohne') . ')',
                'dealership',
                $dealershipId,
                $dealershipId
            );

            $reqTo = trim((string) \App\Core\Config::get('mail.contact', ''));
            if ($reqTo === '') {
                $reqTo = trim((string) \App\Core\Config::get('mail.from', ''));
            }
            $reqSent = false;
            if ($reqTo !== '') {
                $reqSent = \App\Core\Mailer::send(
                    $reqTo,
                    'mobile.de: Freischaltung angefragt',
                    '<p>Ein Konto möchte mit mobile.de verbunden werden.</p>'
                    . '<p><strong>Konto:</strong> #' . $dealershipId . '</p>'
                    . '<p><strong>Angemeldet als:</strong> ' . e((string) ($currentUser['email'] ?? '')) . '</p>'
                    . '<p><strong>mobile.de-Kundennummer:</strong> ' . e($reqCustomer !== '' ? $reqCustomer : 'nicht angegeben') . '</p>'
                    . '<p><strong>Firma:</strong> ' . e($reqCompany !== '' ? $reqCompany : 'nicht angegeben') . '</p>'
                    . ($reqNote !== '' ? '<p><strong>Bemerkung:</strong> ' . nl2br(e($reqNote)) . '</p>' : '')
                );
            }
            Session::flash(
                'success',
                $reqSent
                    ? 'Danke, die Anfrage ist unterwegs. Wir melden uns, sobald die Verbindung steht.'
                    : 'Die Anfrage ist vermerkt. Bitte melde dich zusätzlich kurz bei uns, damit wir sie sicher sehen.'
            );
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

// Betreiber-Zugang: dann waehlt der Kunde nur sein Verkaeuferkonto.
$platformMode = MobileDeService::hasPlatformCredentials();
$platformSellers = [];
if ($platformMode && !$isConnected) {
    try {
        $platformSellers = MobileDeService::availablePlatformSellers($dealershipId);
    } catch (\Throwable $e) {
        // Ist der Betreiber-Zugang gerade nicht erreichbar, bleibt der
        // gewohnte Weg mit eigenen Zugangsdaten offen.
        $platformSellers = [];
    }
}
$dealership = App\Core\Database::fetch('SELECT name FROM dealerships WHERE id = :id', ['id' => $dealershipId]) ?? [];
$canManage = AuthService::isDealerAdmin() || AuthService::isSuperAdmin();
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
<?php elseif ($platformMode && $platformSellers !== []): ?>
    <!-- ============ Betreiber-Zugang: nur das Verkaeuferkonto waehlen -->
    <div class="card" style="max-width:640px">
        <div class="card-header">
            <h2>Verkäuferkonto wählen</h2>
            <span class="badge badge-success"><?= icon('check', 13) ?> Ohne eigenes Passwort</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-3">
                Wir sind bei mobile.de als Übertragungsdienst hinterlegt. Wähle einfach
                dein Verkäuferkonto, dein mobile.de-Passwort brauchen wir nicht.
            </p>
            <?php if ($canManage): ?>
                <form method="post">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="platform_connect">
                    <div class="form-group">
                        <?php foreach ($platformSellers as $index => $seller): ?>
                            <label class="form-check">
                                <input type="radio" name="seller_id" value="<?= e((string) $seller['id']) ?>"
                                       <?= $index === 0 ? 'checked' : '' ?>>
                                <span><?= e((string) ($seller['name'] ?? '')) ?> (<?= e((string) $seller['id']) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary btn-lg" type="submit">
                        <?= icon('check', 16) ?> Verbinden
                    </button>
                </form>
            <?php else: ?>
                <div class="alert alert-warning"><?= icon('info', 16) ?> <span><?= t('autoscout.only_admin') ?></span></div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($platformMode): ?>
    <!-- ====== Betreiber-Zugang da, dieses Konto aber noch nicht zugeordnet -->
    <div class="split split-3-2">
        <div class="card">
            <div class="card-header"><h2>Verbindung anfordern</h2></div>
            <div class="card-body">
                <p class="text-secondary mb-3">
                    Für dein Konto ist die Verbindung zu mobile.de noch nicht freigeschaltet.
                    Nenne uns deine mobile.de-Kundennummer, den Rest übernehmen wir.
                    Du gibst dabei kein Passwort heraus.
                </p>
                <?php if ($canManage): ?>
                    <form method="post">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="request_activation">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">mobile.de-Kundennummer</label>
                                <input class="form-control" type="text" name="mde_customer_ref" maxlength="60"
                                       placeholder="steht auf deiner mobile.de-Rechnung">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Firmenname bei mobile.de</label>
                                <input class="form-control" type="text" name="mde_company" maxlength="190"
                                       value="<?= e((string) ($dealership['name'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bemerkung <span class="optional">(optional)</span></label>
                            <textarea class="form-control" name="mde_note" rows="3" maxlength="500"></textarea>
                        </div>
                        <button class="btn btn-primary btn-lg" type="submit">
                            <?= icon('send', 16) ?> Verbindung anfordern
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning"><?= icon('info', 16) ?> <span><?= t('autoscout.only_admin') ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-pad">
            <h3 style="font-size:15px" class="mb-2">Wie es weitergeht</h3>
            <ol class="text-secondary text-sm" style="margin:0 0 0 18px;line-height:1.9">
                <li>Du schickst uns deine Kundennummer.</li>
                <li>Wir lassen dein Verkäuferkonto unserem Zugang zuordnen.</li>
                <li>Sobald das steht, erscheint es hier zur Auswahl.</li>
                <li>Ein Klick, und deine Inserate gehen automatisch hinaus.</li>
            </ol>
            <div class="alert alert-info mt-2" style="margin-bottom:0">
                <?= icon('lock', 16) ?>
                <span class="text-sm">Dein mobile.de-Passwort brauchen wir dafür nie.</span>
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
