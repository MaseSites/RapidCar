<?php
/**
 * Preise: Inserat-Pakete. Abgerechnet wird pro veröffentlichtem Inserat,
 * kein Abonnement. Erstellen und Vorschau sind immer kostenlos.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Auth\AuthService;
use App\Service\CreditService;

$packages = CreditService::packages();
$isLoggedIn = AuthService::check();

$pageTitle = t('nav.pricing');
require BASE_PATH . '/includes/layout/public-header.php';
?>
<section class="section">
    <h1 class="section-title"><?= t('nav.pricing') ?></h1>
    <p class="section-sub"><?= t('credits.lead') ?></p>

    <?php
    $cheapestPerUnit = min(array_map(
        static fn(array $p): float => $p['price'] / $p['credits'],
        $packages
    ));
    ?>
    <div class="price-wall">
        <?php foreach ($packages as $key => $package):
            $perUnit = $package['price'] / $package['credits'];
            $isFeatured = $key === 'medium';
            $isCheapest = abs($perUnit - $cheapestPerUnit) < 0.001;
            $target = $isLoggedIn
                ? base_url('dashboard/credits.php')
                : base_url('register.php');
            ?>
            <div class="price-card <?= $isFeatured ? 'featured' : '' ?>">
                <?php if ($isFeatured): ?><div class="featured-tag"><?= t('pricing.popular') ?></div><?php endif; ?>
                <div class="price-amount"><?= $package['credits'] ?></div>
                <div class="price-unit"><?= $package['credits'] === 1 ? t('pricing.unit_one') : t('pricing.unit_many') ?></div>
                <div class="price"><?= e($package['currency']) ?> <?= number_format($package['price'], 0, '.', "'") ?></div>
                <div class="price-per">
                    <?= e($package['currency']) ?> <?= number_format($perUnit, 2) ?> <?= t('pricing.per_listing') ?>
                    <?php if ($isCheapest): ?><span class="price-best"><?= t('pricing.best_value') ?></span><?php endif; ?>
                </div>
                <a class="btn <?= $isFeatured ? 'btn-primary' : 'btn-secondary' ?> btn-block btn-sm" href="<?= $target ?>">
                    <?= $isLoggedIn ? t('credits.buy') : t('home.hero.start') ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="price-includes">
        <span class="price-includes-label"><?= t('pricing.includes') ?></span>
        <?php foreach (['feature.score.title', 'feature.generator.title', 'feature.social.title', 'feature.leads.title'] as $featureKey): ?>
            <span class="price-includes-item"><?= icon('check', 13) ?> <?= t($featureKey) ?></span>
        <?php endforeach; ?>
    </div>

    <p class="price-note"><?= t('credits.free_test') ?></p>
</section>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
