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

<div class="page-head">
    <div>
        <h1>RapidCar Plus</h1>
        <div class="sub">Die Werkzeuge für Fotos und Instagram.</div>
    </div>
</div>

<div class="card mb-3" style="max-width:640px">
    <div class="card-header">
        <h2>
            <?= number_format(SubscriptionService::PRICE, 2, '.', "'") ?>
            <?= e(SubscriptionService::CURRENCY) ?> pro Monat
        </h2>
        <?php if ($isActive): ?>
            <span class="badge badge-success"><?= icon('check', 13) ?> aktiv</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <ul class="plus-features">
            <?php foreach (SubscriptionService::FEATURES as $feature): ?>
                <li><?= icon('check', 15) ?> <span><?= e($feature) ?></span></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($isActive): ?>
            <p class="text-sm text-secondary mb-2">
                <?php if ($endsAt !== null): ?>
                    Gekündigt. Nutzbar noch bis <?= e(format_datetime($endsAt)) ?>.
                <?php else: ?>
                    Monatlich kündbar. Die Abrechnung läuft über Stripe.
                <?php endif; ?>
            </p>
            <?php if ($endsAt === null): ?>
                <form method="post" data-confirm="Abo wirklich kündigen? Die Funktionen bleiben bis zum Ende des bezahlten Monats nutzbar.">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button class="btn btn-secondary" type="submit">Kündigen</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-sm text-secondary mb-2">
                Ohne Abo bleibt alles andere nutzbar: Fahrzeuge anlegen, Inserate
                erzeugen und auf die Verkaufsplattformen stellen.
            </p>
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="subscribe">
                <button class="btn btn-primary btn-lg" type="submit" <?= $stripeReady ? '' : 'disabled' ?>>
                    <?= icon('check', 16) ?> Für <?= number_format(SubscriptionService::PRICE, 2, '.', "'") ?> <?= e(SubscriptionService::CURRENCY) ?> im Monat freischalten
                </button>
                <?php if (!$stripeReady): ?>
                    <div class="form-hint" style="margin-top:8px">Die Online-Zahlung ist im Moment nicht verfügbar.</div>
                <?php endif; ?>
            </form>
            <div class="climate-note" style="margin-top:14px">
                <?= icon('activity', 15) ?>
                <span><?= t('credits.climate_note') ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
