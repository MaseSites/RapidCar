<?php
/**
 * Guthaben: ruhige zweispaltige Kaufseite.
 *
 * Links erklärt stille Typografie, wie Guthaben funktionieren. Rechts steht
 * genau eine Kaufkarte mit den Paketen als Auswahlzeilen, Summe und Knopf.
 *
 * Ist Stripe hinterlegt, führt der Kauf zur echten Kasse. Ohne Zahlungsanbieter
 * läuft eine ausdrücklich als Testzahlung gekennzeichnete Abwicklung: Das
 * Guthaben wird sofort gutgeschrieben, es wird kein Geld belastet, und die
 * eingegebenen Kartendaten werden nirgends gespeichert (§72).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Session;
use App\Service\ActivityLogger;
use App\Service\CreditService;
use App\Service\PaymentService;

$dealershipId = require_dealership();

$stripeReady = PaymentService::isStripeReady();
$packages = CreditService::packages();

// ---------------------------------------------------------------- Kauf-POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $packageKey = (string) ($_POST['package'] ?? '');
    $package = CreditService::package($packageKey);
    if ($package === null) {
        Session::flash('danger', t('credits.unknown_package'));
        redirect('dashboard/credits.php');
    }

    $orderId = CreditService::createOrder($dealershipId, $packageKey, (int) $currentUser['id']);
    ActivityLogger::log(
        (int) $currentUser['id'],
        'credits.order_created',
        'Guthaben-Bestellung #' . $orderId . ' erfasst (' . $packageKey . ')',
        'credit_order',
        $orderId,
        $dealershipId
    );

    if ($stripeReady) {
        // Keine Vorauswahl der Zahlungsart: die Stripe-Kasse zeigt alles,
        // was im Stripe-Konto freigeschaltet ist (Karte, TWINT, Apple Pay
        // und Google Pay je nach Geraet).
        try {
            $checkoutUrl = PaymentService::createStripeCheckout(
                $orderId,
                base_url('dashboard/credits.php?status=success'),
                base_url('dashboard/credits.php?status=cancelled')
            );
        } catch (\Throwable $e) {
            CreditService::cancelOrder($orderId);
            Session::flash('danger', $e->getMessage());
            redirect('dashboard/credits.php');
        }
        header('Location: ' . $checkoutUrl);
        exit;
    }

    // Ohne Zahlungsanbieter wird nichts gutgeschrieben: die Bestellung
    // bleibt offen, bis der Betreiber den Zahlungseingang im Admin bestaetigt.
    // Einen Kauf ohne Zahlung gibt es nicht (Paragraf 72).
    Session::flash('info', t('credits.order_recorded'));
    redirect('dashboard/credits.php');
}

$statusParam = (string) ($_GET['status'] ?? '');
if ($statusParam === 'success') {
    Session::flash('success', t('credits.purchase_success'));
    redirect('dashboard/credits.php');
}

$history = CreditService::history($dealershipId, 3);
$reasonLabels = [
    CreditService::REASON_PURCHASE => t('credits.reason.purchase'),
    CreditService::REASON_PUBLISH  => t('credits.reason.publish'),
    CreditService::REASON_WELCOME  => t('credits.reason.welcome'),
    CreditService::REASON_ADMIN    => t('credits.reason.admin'),
    CreditService::REASON_REFUND   => t('credits.reason.refund'),
];

// Basis der Ersparnisrechnung ist der Einzelpreis
$singlePrice = $packages['single']['price'] ?? 10.0;
$defaultKey = isset($packages['medium']) ? 'medium' : array_key_first($packages);

$fmt = static fn(float $value): string => number_format($value, 0, '.', "'");
$fmt2 = static fn(float $value): string => number_format($value, 2, '.', "'");

$pageTitle = t('sidebar.credits');
$activeNav = 'credits';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="credit-page">
    <div class="credit-layout">

        <!-- ------------------------------------------------ Links: Einstieg -->
        <div class="credit-copy-intro">
            <div class="credit-kicker"><?= t('sidebar.credits') ?></div>
            <h1><?= t('credits.headline') ?></h1>
        </div>

        <!-- ---------------------------------------------- Rechts: Kaufkarte -->
        <div class="credit-buy">
            <div class="credit-buy-title"><?= t('credits.choose_package') ?></div>


            <div class="credit-options">
                <?php foreach ($packages as $key => $package): ?>
                    <?php
                    $credits = (int) $package['credits'];
                    $saving = $credits * $singlePrice - $package['price'];
                    $percent = $credits > 1 ? (int) round($saving / ($credits * $singlePrice) * 100) : 0;
                    ?>
                    <label class="credit-option">
                        <input type="radio" name="pkg_choice" value="<?= e($key) ?>"
                               data-credits="<?= $credits ?>"
                               data-price-label="<?= e($package['currency'] . ' ' . $fmt($package['price'])) ?>"
                               data-saving="<?= $saving > 0 ? e($package['currency'] . ' ' . $fmt2($saving)) : '' ?>"
                               <?= $key === $defaultKey ? 'checked' : '' ?>>
                        <span class="credit-option-dot"></span>
                        <span class="credit-option-body">
                            <span class="credit-option-name">
                                <?= $credits ?> <?= $credits === 1 ? t('pricing.unit_one') : t('pricing.unit_many') ?>
                            </span>
                            <span class="credit-option-unit">
                                <?= t('credits.per_unit', ['price' => $package['currency'] . ' ' . $fmt2($package['price'] / max(1, $credits))]) ?>
                            </span>
                        </span>
                        <span class="credit-option-right">
                            <span class="credit-option-price"><?= e($package['currency']) ?> <?= $fmt($package['price']) ?></span>
                            <?php if ($percent > 0): ?>
                                <span class="credit-option-save"><?= t('credits.percent_off', ['percent' => $percent]) ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="credit-sum">
                <div class="credit-sum-row">
                    <span><?= t('credits.total') ?></span>
                    <strong id="creditTotal"></strong>
                </div>
            </div>

            <?php // Direkt zur Stripe-Kasse, kein Zwischenschritt. Das Paket
                  // schreibt das Skript beim Absenden aus der Auswahl um. ?>
            <form method="post" action="<?= base_url('dashboard/credits.php') ?>" id="creditBuyForm">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="package" id="payPackage" value="<?= e($defaultKey) ?>">
                <button class="btn btn-primary btn-block btn-lg credit-cta" type="submit" id="creditBuyBtn">
                    <?= t('credits.buy') ?> · <span id="creditCtaAmount"></span>
                </button>
                <?php if (!$stripeReady): ?>
                    <div class="form-hint" style="margin-top:8px"><?= t('credits.order_notice') ?></div>
                <?php endif; ?>
            </form>

            <div class="climate-note">
                <?= icon('activity', 15) ?>
                <span><?= t('credits.climate_note') ?></span>
            </div>

        </div>

        <!-- ------------------------------------ Links: leise Randangaben -->
        <div class="credit-copy-points">
            <div class="credit-facts">
                <span><?= t('credits.fact_free') ?></span>
                <span><?= t('credits.fact_no_expiry') ?></span>
            </div>
            <?php if ($history !== []): ?>
                <div class="credit-recent">
                    <div class="credit-recent-label"><?= t('credits.recent') ?></div>
                    <?php foreach ($history as $entry): ?>
                        <div class="credit-recent-row">
                            <span class="credit-recent-delta <?= (int) $entry['delta'] > 0 ? 'is-plus' : '' ?>">
                                <?= (int) $entry['delta'] > 0 ? '+' : '' ?><?= (int) $entry['delta'] ?>
                            </span>
                            <span class="credit-recent-reason"><?= e($reasonLabels[(string) $entry['reason']] ?? (string) $entry['reason']) ?></span>
                            <span class="credit-recent-time"><?= e(time_ago((string) $entry['created_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$jsEach = json_encode(t('credits.dialog_credits', ['count' => '{COUNT}']), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$pageScripts = <<<HTML
<script>
(function () {
    var radios = document.querySelectorAll('input[name="pkg_choice"]');
    if (!radios.length) { return; }

    var total = document.getElementById('creditTotal');
    var ctaAmount = document.getElementById('creditCtaAmount');
    var packageField = document.getElementById('payPackage');

    function selected() {
        return document.querySelector('input[name="pkg_choice"]:checked');
    }

    // Summe und Knopfbetrag laufen mit der Auswahl mit
    function paint() {
        var pick = selected();
        if (!pick) { return; }
        [total, ctaAmount].forEach(function (el) { el.classList.add('is-swapping'); });
        setTimeout(function () {
            total.textContent = pick.dataset.priceLabel;
            ctaAmount.textContent = pick.dataset.priceLabel;
            [total, ctaAmount].forEach(function (el) { el.classList.remove('is-swapping'); });
        }, 120);
        packageField.value = pick.value;
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', paint);
    });
    // Erstbefuellung ohne Ueberblendung
    (function () {
        var pick = selected();
        total.textContent = pick.dataset.priceLabel;
        ctaAmount.textContent = pick.dataset.priceLabel;
        packageField.value = pick.value;
    })();

    // Doppelklick-Schutz: nach dem Absenden sperrt der Knopf
    document.getElementById('creditBuyForm').addEventListener('submit', function () {
        document.getElementById('creditBuyBtn').disabled = true;
    });
})();
</script>
HTML;

require BASE_PATH . '/includes/layout/dash-footer.php';
?>
