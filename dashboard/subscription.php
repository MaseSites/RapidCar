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

<div class="plus-page">
    <div class="plus-layout">

        <!-- ------------------------------------------------ Die Preiskarte -->
        <div class="plus-price-card">
            <div class="plus-price-brand">RapidCar <span>Plus</span></div>
            <div class="plus-price-figure">
                <?= number_format(SubscriptionService::PRICE, 2, '.', "'") ?>
                <span class="plus-price-currency"><?= e(SubscriptionService::CURRENCY) ?></span>
            </div>
            <div class="plus-price-period">pro Monat, monatlich kündbar</div>

            <?php if ($isActive): ?>
                <div class="plus-active-badge"><?= icon('check', 14) ?> Aktiv</div>
                <?php if ($endsAt !== null): ?>
                    <p class="plus-price-note">Gekündigt. Nutzbar bis <?= e(format_datetime($endsAt)) ?>.</p>
                <?php else: ?>
                    <p class="plus-price-note">Die Abrechnung läuft über Stripe.</p>
                    <form method="post" data-confirm="Abo wirklich kündigen? Die Funktionen bleiben bis zum Ende des bezahlten Monats nutzbar.">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn-secondary btn-block" type="submit">Kündigen</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <form method="post">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="subscribe">
                    <button class="btn btn-primary btn-lg btn-block" type="submit" <?= $stripeReady ? '' : 'disabled' ?>>
                        Freischalten
                    </button>
                    <?php if (!$stripeReady): ?>
                        <div class="form-hint" style="margin-top:8px">Die Online-Zahlung ist im Moment nicht verfügbar.</div>
                    <?php endif; ?>
                </form>
                <p class="plus-price-note">Ohne Abo bleibt alles andere nutzbar.</p>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------------------ Die Leistungen -->
        <div class="plus-benefits">
            <h2>Enthalten</h2>
            <div class="plus-benefit-grid">
                <?php foreach (SubscriptionService::benefits() as $benefit): ?>
                    <div class="plus-benefit">
                        <span class="plus-benefit-check"><?= icon('check', 14) ?></span>
                        <div>
                            <div class="plus-benefit-title"><?= e($benefit['title']) ?></div>
                            <div class="plus-benefit-text"><?= e($benefit['text']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <details class="plus-planned">
                <summary>In Arbeit, noch nicht enthalten</summary>
                <ul>
                    <?php foreach (SubscriptionService::planned() as $planned): ?>
                        <li><?= e($planned) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>

    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
