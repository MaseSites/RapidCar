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
use App\Core\Database;
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

    // Ohne eingerichtetes Stripe gibt es keinen Kauf: eine manuelle
    // Freigabe existiert nicht mehr, und vorgetaeuscht wird nichts
    // (Paragraf 72). Die eben angelegte Bestellung wird storniert.
    CreditService::cancelOrder($orderId);
    Session::flash('danger', t('credits.payment_unavailable'));
    redirect('dashboard/credits.php');
}

$statusParam = (string) ($_GET['status'] ?? '');
if ($statusParam === 'success') {
    // Nicht auf den Webhook warten: die offenen Bestellungen der letzten
    // Stunden direkt bei Stripe nachpruefen und sofort gutschreiben.
    $credited = false;
    foreach (Database::fetchAll(
        "SELECT id FROM credit_orders
         WHERE dealership_id = :d AND status = 'pending' AND provider_ref IS NOT NULL
         ORDER BY id DESC LIMIT 3",
        ['d' => $dealershipId]
    ) as $openOrder) {
        if (PaymentService::confirmOrder((int) $openOrder['id'])) {
            $credited = true;
        }
    }
    if ($credited) {
        Session::flash('success', t('credits.purchase_success'));
    } else {
        // Ehrlich: noch keine Bestaetigung von Stripe. Der Webhook
        // schreibt gut, sobald sie eintrifft.
        Session::flash('info', t('credits.purchase_pending'));
    }
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

<div class="topup">
    <div class="topup-main">
        <h1 class="topup-title">Guthaben aufladen</h1>
        <p class="topup-sub">Ein Guthaben veröffentlicht ein Inserat. Erstellen und Bearbeiten sind kostenlos.</p>

        <!-- ------------------------------------------------ 1. Paket -->
        <div class="topup-step">1. Paket wählen</div>
        <div class="topup-packages">
            <?php foreach ($packages as $key => $package): ?>
                <?php
                $credits = (int) $package['credits'];
                $saving = $credits * $singlePrice - $package['price'];
                $percent = $credits > 1 ? (int) round($saving / ($credits * $singlePrice) * 100) : 0;
                ?>
                <label class="topup-card">
                    <input type="radio" name="pkg_choice" value="<?= e($key) ?>"
                           data-credits="<?= $credits ?>"
                           data-price-label="<?= e($package['currency'] . ' ' . $fmt($package['price'])) ?>"
                           data-saving-label="<?= $saving > 0 ? e('- ' . $package['currency'] . ' ' . $fmt2($saving)) : '' ?>"
                           <?= $key === $defaultKey ? 'checked' : '' ?>>
                    <span class="topup-card-check"><?= icon('check', 12) ?></span>
                    <span class="topup-card-amount"><?= $credits ?> <?= $credits === 1 ? t('pricing.unit_one') : t('pricing.unit_many') ?></span>
                    <span class="topup-card-price"><?= e($package['currency']) ?> <?= $fmt($package['price']) ?></span>
                    <span class="topup-card-unit"><?= e($package['currency']) ?> <?= $fmt2($package['price'] / max(1, $credits)) ?> pro Inserat</span>
                    <?php if ($percent > 0): ?>
                        <span class="topup-card-chip">-<?= $percent ?>%</span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>

        <!-- ------------------------------------------------ 2. Zahlung -->
        <div class="topup-step">2. Zahlung</div>
        <div class="topup-payinfo">
            <div class="topup-payinfo-text">
                Die Zahlungsart wählst du im nächsten Schritt sicher bei Stripe.
            </div>
            <div class="topup-paybadges">
                <span>Karte</span><span>TWINT</span><span>Apple Pay</span><span>Google Pay</span><span>PayPal</span>
            </div>
        </div>

        <!-- ------------------------------------------------ Vertrauen -->
        <div class="topup-trust">
            <div class="topup-trust-item">
                <span class="topup-trust-icon"><?= icon('shield', 16) ?></span>
                <div><strong>Sichere Zahlung</strong><span>SSL-verschlüsselt über Stripe</span></div>
            </div>
            <div class="topup-trust-item">
                <span class="topup-trust-icon"><?= icon('clock', 16) ?></span>
                <div><strong>Sofort verfügbar</strong><span>Guthaben in Echtzeit</span></div>
            </div>
            <div class="topup-trust-item">
                <span class="topup-trust-icon"><?= icon('x', 16) ?></span>
                <div><strong>Kein Abo</strong><span>Einmalige Zahlung</span></div>
            </div>
        </div>
    </div>

    <!-- ---------------------------------------------------- Rechte Spalte -->
    <div class="topup-side">
        <div class="topup-balance">
            <div class="topup-balance-label"><?= t('credits.balance') ?></div>
            <?php $topupBalance = (int) \App\Service\CreditService::balance($dealershipId); ?>
            <div class="topup-balance-figure">
                <?= $topupBalance ?>
                <span><?= $topupBalance === 1 ? t('pricing.unit_one') : t('pricing.unit_many') ?></span>
            </div>
            <span class="topup-balance-icon"><?= icon('tag', 34) ?></span>
        </div>

        <div class="topup-summary">
            <div class="topup-summary-row">
                <span>Paket</span>
                <span id="sumPackage"></span>
            </div>
            <div class="topup-summary-row">
                <span>Preis</span>
                <span id="sumPrice"></span>
            </div>
            <div class="topup-summary-row" id="sumSavingRow" hidden>
                <span>Ersparnis zum Einzelkauf</span>
                <span class="topup-summary-saving" id="sumSaving"></span>
            </div>
            <div class="topup-summary-divider"></div>
            <div class="topup-summary-get">Du erhältst</div>
            <div class="topup-summary-figure" id="sumGet"></div>

            <form method="post" action="<?= base_url('dashboard/credits.php') ?>" id="creditBuyForm">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="package" id="payPackage" value="<?= e($defaultKey) ?>">
                <button class="btn btn-primary btn-block btn-lg" type="submit" id="creditBuyBtn" <?= $stripeReady ? '' : 'disabled' ?>>
                    Weiter zur Zahlung <?= icon('lock', 15) ?>
                </button>
                <?php if (!$stripeReady): ?>
                    <div class="form-hint" style="margin-top:8px"><?= t('credits.payment_unavailable') ?></div>
                <?php endif; ?>
            </form>
            <div class="topup-summary-note">
                Sichere Abwicklung über Stripe. Es gilt die
                <a href="<?= base_url('privacy.php') ?>">Datenschutzerklärung</a>.
            </div>
        </div>

        <div class="topup-why">
            <div class="topup-why-title">Warum Guthaben?</div>
            <ul>
                <li><?= icon('check', 14) ?> Schnell und unkompliziert Inserate schalten</li>
                <li><?= icon('check', 14) ?> Kein Abo, keine Verpflichtung</li>
                <li><?= icon('check', 14) ?> Volle Kontrolle über die Ausgaben</li>
                <li><?= icon('check', 14) ?> Guthaben läuft nie ab</li>
            </ul>
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
    var packageField = document.getElementById('payPackage');
    var unitOne = document.querySelector('.topup-card-amount') ? null : null;

    function paint() {
        var pick = document.querySelector('input[name="pkg_choice"]:checked');
        if (!pick) { return; }
        var credits = parseInt(pick.dataset.credits, 10);
        var word = credits === 1 ? 'Inserat' : 'Inserate';
        document.getElementById('sumPackage').textContent = credits + ' ' + word;
        document.getElementById('sumPrice').textContent = pick.dataset.priceLabel;
        document.getElementById('sumGet').textContent = credits + ' ' + word;
        var savingRow = document.getElementById('sumSavingRow');
        if (pick.dataset.savingLabel) {
            savingRow.hidden = false;
            document.getElementById('sumSaving').textContent = pick.dataset.savingLabel;
        } else {
            savingRow.hidden = true;
        }
        packageField.value = pick.value;
    }

    radios.forEach(function (radio) { radio.addEventListener('change', paint); });
    paint();

    // Doppelklick-Schutz: nach dem Absenden sperrt der Knopf
    document.getElementById('creditBuyForm').addEventListener('submit', function () {
        document.getElementById('creditBuyBtn').disabled = true;
    });
})();
</script>
HTML;

require BASE_PATH . '/includes/layout/dash-footer.php';
?>
