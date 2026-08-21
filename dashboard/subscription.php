<?php
/**
 * RapidCar Plus: Abo abschliessen, Stand ansehen, kündigen.
 *
 * Freigeschaltet wird ausschliesslich nach bestätigter Zahlung durch
 * Stripe. Ohne eingerichteten Zahlungsanbieter wird kein Abo vorgetäuscht.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Core\Session;
use App\Service\PaymentService;
use App\Service\SubscriptionService;

$dealershipId = require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    if (!AuthService::isDealerAdmin() && !AuthService::isSuperAdmin()) {
        Session::flash('danger', 'Nur die Verwaltung des Kontos kann das Abo ändern.');
        redirect('dashboard/subscription.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'subscribe') {
        if (!PaymentService::isStripeReady()) {
            Session::flash('danger', 'Die Online-Zahlung ist im Moment nicht verfügbar. Bitte später erneut versuchen.');
            redirect('dashboard/subscription.php');
        }
        try {
            $url = PaymentService::createSubscriptionCheckout(
                $dealershipId,
                (string) ($currentUser['email'] ?? ''),
                base_url('dashboard/subscription.php?status=success'),
                base_url('dashboard/subscription.php?status=cancelled')
            );
        } catch (\Throwable $e) {
            Session::flash('danger', $e->getMessage());
            redirect('dashboard/subscription.php');
        }
        header('Location: ' . $url);
        exit;
    }

    if ($action === 'cancel') {
        // Kündigung zum Monatsende: bis dahin bleibt alles nutzbar.
        SubscriptionService::cancel($dealershipId, date('Y-m-d H:i:s', strtotime('+1 month')), (int) $currentUser['id']);
        Session::flash('info', 'Das Abo ist gekündigt. Die Funktionen bleiben bis zum Ende des bezahlten Monats nutzbar. Bitte kündige zusätzlich in Stripe, damit keine weitere Zahlung ausgelöst wird.');
        redirect('dashboard/subscription.php');
    }
}

if ((string) ($_GET['status'] ?? '') === 'success') {
    Session::flash('success', 'Danke! Sobald Stripe die Zahlung bestätigt hat, sind alle Plus-Funktionen frei. Das dauert meist nur Sekunden.');
    redirect('dashboard/subscription.php');
}

$isActive = SubscriptionService::isActive($dealershipId);
$endsAt = SubscriptionService::endsAt($dealershipId);
$stripeReady = PaymentService::isStripeReady();

$pageTitle = 'Abo';
$activeNav = 'subscription';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<!-- Nach Kundenvorlage: dunkles Banner oben, darunter zweispaltig die
     Vorteile links und die Preiskarte rechts. -->
<div class="pl">

    <!-- ------------------------------------------------------- Banner -->
    <div class="pl-hero">
        <div class="pl-hero-copy">
            <h1>Mehr Power. Mehr Präsenz.<br><span>RapidCar Plus</span></h1>
            <p>Entdecke alle Premium-Funktionen und bringe deine Inserate auf das nächste Level.</p>
            <div class="pl-hero-facts">
                <span><?= icon('refresh', 15) ?> Monatlich kündbar</span>
                <span><?= icon('lock', 15) ?> Sicher bezahlen</span>
                <span><?= icon('activity', 15) ?> Sofort aktiv</span>
            </div>
        </div>
        <div class="pl-hero-art" aria-hidden="true">
            <span class="pl-orbit pl-orbit-a"></span>
            <span class="pl-orbit pl-orbit-b"></span>
            <span class="pl-art-cube"><?= icon('star', 54) ?></span>
            <span class="pl-art-bubble pl-art-bubble-a"><?= icon('chart', 17) ?></span>
            <span class="pl-art-bubble pl-art-bubble-b"><?= icon('activity', 17) ?></span>
            <span class="pl-art-bubble pl-art-bubble-c"><?= icon('image', 17) ?></span>
        </div>
    </div>

    <div class="pl-grid">
        <div class="pl-main">
            <h2 class="pl-section-title">Deine Vorteile mit RapidCar Plus</h2>

            <div class="pl-benefits">
                <?php
                $plBenefits = [
                    ['image', 'Studio-Hintergründe per KI', 'Entferne oder ersetze Hintergründe automatisch.'],
                    ['star', 'Schatten und Glanz', 'Verleihe deinen Bildern perfekten Glanz.'],
                    ['shield', 'Kennzeichen abdecken oder branden', 'Schütze deine Daten oder werbe für deine Marke.'],
                    ['check-square', 'Logo im Bild platzieren', 'Stärke deine Marke mit deinem Logo.'],
                    ['camera', 'Alle Fotos auf einmal', 'Lade, bearbeite und verwalte alle Bilder gleichzeitig.'],
                    ['edit', 'Instagram-Beiträge erstellen', 'Erstelle ansprechende Beiträge automatisch.'],
                    ['instagram', 'Direkt auf Instagram veröffentlichen', 'Veröffentliche deine Inserate mit nur einem Klick.'],
                    ['upload', 'Eigene Hintergründe', 'Lade eigene Hintergründe hoch und nutze sie.'],
                ];
                ?>
                <?php foreach ($plBenefits as [$plIcon, $plTitle, $plText]): ?>
                    <div class="pl-benefit">
                        <span class="pl-benefit-icon"><?= icon($plIcon, 19) ?></span>
                        <div>
                            <div class="pl-benefit-title"><?= e($plTitle) ?></div>
                            <div class="pl-benefit-text"><?= e($plText) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pl-trust">
                <div class="pl-trust-item">
                    <span class="pl-trust-icon"><?= icon('shield', 17) ?></span>
                    <div>
                        <strong>Sicher &amp; zuverlässig</strong>
                        <span>Deine Daten sind bei uns sicher. SSL-verschlüsselt &amp; DSGVO-konform.</span>
                    </div>
                </div>
                <div class="pl-trust-item">
                    <span class="pl-trust-icon"><?= icon('refresh', 17) ?></span>
                    <div>
                        <strong>Jederzeit kündbar</strong>
                        <span>Kündige dein Abo jederzeit mit nur einem Klick.</span>
                    </div>
                </div>
                <div class="pl-trust-item">
                    <span class="pl-trust-icon"><?= icon('help', 17) ?></span>
                    <div>
                        <strong>Unterstützung</strong>
                        <span>Unser Support-Team ist für dich da.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- --------------------------------------------------- Preiskarte -->
        <div class="pl-side">
            <div class="pl-price-card">
                <div class="pl-price-name">RapidCar Plus</div>
                <div class="pl-price-figure">
                    <?= number_format(SubscriptionService::PRICE, 2, '.', "'") ?>
                    <span>CHF</span>
                </div>
                <div class="pl-price-period">pro Monat, monatlich kündbar</div>

                <ul class="pl-price-checks">
                    <li><?= icon('check', 14) ?> Alle Premium-Funktionen inklusive</li>
                    <li><?= icon('check', 14) ?> Keine Anzeigen</li>
                    <li><?= icon('check', 14) ?> Priorisierter Support</li>
                    <li><?= icon('check', 14) ?> Regelmässige Updates</li>
                </ul>

                <div class="pl-price-divider"></div>

                <?php if ($isActive): ?>
                    <div class="plus-active-badge"><?= icon('check', 14) ?> Aktiv</div>
                    <?php if ($endsAt !== null): ?>
                        <p class="pl-price-note">Gekündigt. Nutzbar bis <?= e(format_datetime($endsAt)) ?>.</p>
                    <?php else: ?>
                        <p class="pl-price-note">Die Abrechnung läuft über Stripe.</p>
                        <form method="post" data-confirm="Abo wirklich kündigen? Die Funktionen bleiben bis zum Ende des bezahlten Monats nutzbar.">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-secondary btn-block" type="submit">Kündigen</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="pl-price-note">Deine Zahlung ist sicher und verschlüsselt.</p>
                    <form method="post">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="subscribe">
                        <button class="btn btn-primary btn-block btn-lg" type="submit" <?= $stripeReady ? '' : 'disabled' ?>>
                            Jetzt abonnieren
                        </button>
                        <?php if (!$stripeReady): ?>
                            <div class="form-hint" style="margin-top:8px">Die Online-Zahlung ist im Moment nicht verfügbar.</div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
