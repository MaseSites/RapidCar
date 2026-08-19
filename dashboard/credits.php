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
        // Echte Zahlung: Gutschrift erst nach Bestätigung durch Stripe
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

    // Testzahlung: Die Kartendaten werden nur auf Vollständigkeit geprüft und
    // danach verworfen. Gespeichert wird ausschliesslich die Bestellung.
    $cardNumber = preg_replace('/\D+/', '', (string) ($_POST['card_number'] ?? '')) ?? '';
    $holder = trim((string) ($_POST['card_holder'] ?? ''));
    $expiry = trim((string) ($_POST['card_expiry'] ?? ''));
    $cvc = preg_replace('/\D+/', '', (string) ($_POST['card_cvc'] ?? '')) ?? '';

    if ($holder === '' || strlen($cardNumber) < 12 || !preg_match('/^\d{2}\s*\/\s*\d{2}$/', $expiry) || strlen($cvc) < 3) {
        CreditService::cancelOrder($orderId);
        Session::flash('danger', t('credits.card_incomplete'));
        redirect('dashboard/credits.php');
    }
    unset($cardNumber, $cvc, $expiry, $holder, $_POST['card_number'], $_POST['card_cvc']);

    CreditService::completeOrder($orderId, (int) $currentUser['id'], true);
    Session::flash('success', t('credits.test_purchase_done', ['count' => (int) $package['credits']]));
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

            <button class="btn btn-primary btn-block btn-lg credit-cta" type="button" id="creditBuyBtn">
                <?= t('credits.buy') ?> · <span id="creditCtaAmount"></span>
            </button>

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

<!-- Kasse: bei hinterlegtem Stripe geht es direkt weiter, sonst Testzahlung -->
<dialog class="bg-dialog" id="payDialog">
    <form method="post" action="<?= base_url('dashboard/credits.php') ?>" autocomplete="off">
        <?= App\Core\Csrf::field() ?>
        <input type="hidden" name="package" id="payPackage" value="">

        <div class="feature-dialog-head">
            <strong><?= t('checkout.title') ?></strong>
            <button class="icon-btn" type="button" id="payClose" aria-label="<?= e(t('common.close')) ?>"><?= icon('x', 18) ?></button>
        </div>

        <div class="bg-dialog-body">
            <div class="pay-summary">
                <span id="paySummaryCredits"></span>
                <strong id="paySummaryPrice"></strong>
            </div>

            <?php if ($stripeReady): ?>
                <p class="text-sm text-secondary"><?= t('credits.stripe_lead') ?></p>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom:16px">
                    <?= icon('info', 16) ?>
                    <span class="text-sm"><?= t('credits.test_notice') ?></span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cardHolder"><?= t('credits.card_holder') ?></label>
                    <input class="form-control" type="text" id="cardHolder" name="card_holder"
                           placeholder="Max Muster" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cardNumber"><?= t('credits.card_number') ?></label>
                    <input class="form-control" type="text" id="cardNumber" name="card_number"
                           inputmode="numeric" maxlength="23" placeholder="4242 4242 4242 4242" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="cardExpiry"><?= t('credits.card_expiry') ?></label>
                        <input class="form-control" type="text" id="cardExpiry" name="card_expiry"
                               maxlength="5" placeholder="12/29" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cardCvc"><?= t('credits.card_cvc') ?></label>
                        <input class="form-control" type="text" id="cardCvc" name="card_cvc"
                               inputmode="numeric" maxlength="4" placeholder="123" required>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="feature-dialog-foot" style="justify-content:flex-end">
            <button class="btn btn-primary btn-lg" type="submit">
                <?= icon('check', 16) ?>
                <?= $stripeReady ? t('credits.to_payment') : t('credits.pay_test') ?>
            </button>
        </div>
    </form>
</dialog>

<?php
$jsEach = json_encode(t('credits.dialog_credits', ['count' => '{COUNT}']), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$pageScripts = <<<HTML
<script>
(function () {
    var dialog = document.getElementById('payDialog');
    var radios = document.querySelectorAll('input[name="pkg_choice"]');
    if (!dialog || !radios.length) { return; }

    var total = document.getElementById('creditTotal');
    var ctaAmount = document.getElementById('creditCtaAmount');

    function selected() {
        return document.querySelector('input[name="pkg_choice"]:checked');
    }

    // Summe, Ersparnis und Knopfbetrag laufen mit der Auswahl mit
    function paint() {
        var pick = selected();
        if (!pick) { return; }
        [total, ctaAmount].forEach(function (el) { el.classList.add('is-swapping'); });
        setTimeout(function () {
            total.textContent = pick.dataset.priceLabel;
            ctaAmount.textContent = pick.dataset.priceLabel;
            [total, ctaAmount].forEach(function (el) { el.classList.remove('is-swapping'); });
        }, 120);
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', paint);
    });
    // Erstbefuellung ohne Ueberblendung
    (function () {
        var pick = selected();
        total.textContent = pick.dataset.priceLabel;
        ctaAmount.textContent = pick.dataset.priceLabel;
    })();

    document.getElementById('creditBuyBtn').addEventListener('click', function () {
        var pick = selected();
        if (!pick) { return; }
        document.getElementById('payPackage').value = pick.value;
        document.getElementById('paySummaryCredits').textContent =
            {$jsEach}.replace('{COUNT}', pick.dataset.credits);
        document.getElementById('paySummaryPrice').textContent = pick.dataset.priceLabel;
        dialog.showModal();
    });

    var close = function () { dialog.close(); };
    document.getElementById('payClose').addEventListener('click', close);
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) { close(); }
    });

    // Kartennummer und Ablaufdatum beim Tippen lesbar gruppieren
    var number = document.getElementById('cardNumber');
    if (number) {
        number.addEventListener('input', function () {
            var digits = number.value.replace(/\D+/g, '').slice(0, 19);
            number.value = digits.replace(/(.{4})/g, '\$1 ').trim();
        });
    }
    var expiry = document.getElementById('cardExpiry');
    if (expiry) {
        expiry.addEventListener('input', function () {
            var digits = expiry.value.replace(/\D+/g, '').slice(0, 4);
            expiry.value = digits.length > 2 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
        });
    }
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
