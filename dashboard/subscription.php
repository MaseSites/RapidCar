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

<!-- Vollbild: eine grosse, dunkle Flaeche mit Lichtern, mittig die
     Botschaft, darunter die Vorteile mit Haken und der Kaufknopf. -->
<div class="plus-hero">
    <div class="plus-hero-glow plus-hero-glow-a" aria-hidden="true"></div>
    <div class="plus-hero-glow plus-hero-glow-b" aria-hidden="true"></div>
    <svg class="plus-hero-lines" aria-hidden="true" viewBox="0 0 1200 320" preserveAspectRatio="none">
        <path d="M0 250 C 300 120, 600 320, 1200 140" stroke="rgba(168,85,247,.25)" stroke-width="2" fill="none"/>
        <path d="M0 290 C 350 180, 700 340, 1200 190" stroke="rgba(124,58,237,.16)" stroke-width="2" fill="none"/>
        <path d="M0 210 C 280 80, 640 280, 1200 90" stroke="rgba(255,255,255,.06)" stroke-width="1.5" fill="none"/>
    </svg>

    <div class="plus-hero-inner">
        <div class="plus-hero-kicker">RapidCar</div>
        <h1 class="plus-hero-title">Entdecke jetzt <span>RapidCar&nbsp;Plus</span></h1>
        <div class="plus-hero-price">
            <?= number_format(SubscriptionService::PRICE, 2, '.', "'") ?> <?= e(SubscriptionService::CURRENCY) ?>
            pro Monat, monatlich kündbar
        </div>

        <ul class="plus-hero-list">
            <?php foreach (SubscriptionService::benefits() as $benefit): ?>
                <li>
                    <span class="plus-hero-check"><?= icon('check', 14) ?></span>
                    <span><?= e($benefit['title']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($isActive): ?>
            <div class="plus-active-badge plus-hero-active"><?= icon('check', 14) ?> Aktiv</div>
            <?php if ($endsAt !== null): ?>
                <p class="plus-hero-note">Gekündigt. Nutzbar bis <?= e(format_datetime($endsAt)) ?>.</p>
            <?php else: ?>
                <p class="plus-hero-note">Die Abrechnung läuft über Stripe.</p>
                <form method="post" data-confirm="Abo wirklich kündigen? Die Funktionen bleiben bis zum Ende des bezahlten Monats nutzbar.">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button class="btn btn-secondary" type="submit">Kündigen</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="subscribe">
                <button class="plus-hero-cta" type="submit" <?= $stripeReady ? '' : 'disabled' ?>>
                    Jetzt kaufen
                </button>
                <?php if (!$stripeReady): ?>
                    <div class="plus-hero-note">Die Online-Zahlung ist im Moment nicht verfügbar.</div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <details class="plus-planned plus-hero-planned">
            <summary>In Arbeit, noch nicht enthalten</summary>
            <ul>
                <?php foreach (SubscriptionService::planned() as $planned): ?>
                    <li><?= e($planned) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
