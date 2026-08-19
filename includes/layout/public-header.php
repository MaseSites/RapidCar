<?php
/**
 * Öffentlicher Seitenkopf (§8). Erwartet optional: $pageTitle.
 */

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Auth\AuthService;
use App\Core\Session;

$pageTitle = $pageTitle ?? 'RapidCar';
$isLoggedIn = AuthService::check();
$flashes = Session::pullFlashes();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | RapidCar</title>
<meta name="description" content="<?= e(t('home.hero.lead')) ?>">
<?php /* Erst wenn JavaScript läuft, darf etwas ausgeblendet starten. Ohne
         JavaScript bleibt die Seite vollständig sichtbar. */ ?>
<script>document.documentElement.className += ' js';</script>
<link rel="stylesheet" href="<?= asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('css/public.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset('icons/favicon.svg') ?>">
</head>
<body data-label-cancel="<?= e(t('common.cancel')) ?>"
      data-label-confirm="<?= e(t('common.confirm')) ?>"
      data-label-delete="<?= e(t('common.delete')) ?>">
<header class="site-header">
    <div class="inner">
        <a class="logo" href="<?= base_url('index.php') ?>"><?= brand_logo(27) ?></a>
        <nav class="site-nav">
            <a href="<?= base_url('features.php') ?>"><?= t('nav.features') ?></a>
            <a href="<?= base_url('pricing.php') ?>"><?= t('nav.pricing') ?></a>
            <a href="<?= base_url('about.php') ?>"><?= t('nav.about') ?></a>
            <a href="<?= base_url('contact.php') ?>"><?= t('nav.contact') ?></a>
        </nav>
        <div class="header-actions">
            <div class="dropdown">
                <button class="btn btn-ghost btn-sm" type="button" data-dropdown="publicLangMenu">
                    <?= e(App\Core\Lang::locale()) === 'de' ? 'DE' : e(strtoupper(App\Core\Lang::locale())) ?>
                    <?= icon('chevron-down', 13) ?>
                </button>
                <div class="dropdown-menu" id="publicLangMenu" style="min-width:160px">
                    <?php foreach (App\Core\Lang::AVAILABLE as $code => $label): ?>
                        <a href="<?= base_url('language.php?set=' . $code . '&return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/')) ?>">
                            <?php if ($code === App\Core\Lang::locale()): ?><?= icon('check', 14) ?><?php else: ?><span style="width:14px"></span><?php endif; ?>
                            <?= e($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($isLoggedIn): ?>
                <a class="btn btn-primary btn-sm" href="<?= base_url('dashboard/') ?>"><?= t('nav.to_dashboard') ?></a>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm" href="<?= base_url('login.php') ?>"><?= t('nav.login') ?></a>
                <a class="btn btn-primary btn-sm" href="<?= base_url('register.php') ?>"><?= t('nav.register') ?></a>
            <?php endif; ?>
        </div>
        <button class="nav-toggle" aria-label="Menü öffnen" onclick="document.getElementById('mobileMenu').classList.toggle('open')">
            <?= icon('menu', 22) ?>
        </button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="<?= base_url('features.php') ?>"><?= t('nav.features') ?></a>
        <a href="<?= base_url('pricing.php') ?>"><?= t('nav.pricing') ?></a>
        <a href="<?= base_url('about.php') ?>"><?= t('nav.about') ?></a>
        <a href="<?= base_url('contact.php') ?>"><?= t('nav.contact') ?></a>
        <?php if ($isLoggedIn): ?>
            <a class="btn btn-primary btn-block" href="<?= base_url('dashboard/') ?>">Zum Dashboard</a>
        <?php else: ?>
            <a href="<?= base_url('login.php') ?>"><?= t('nav.login') ?></a>
            <a class="btn btn-primary btn-block" href="<?= base_url('register.php') ?>"><?= t('nav.register') ?></a>
        <?php endif; ?>
    </div>
</header>
<?php if ($flashes !== []): ?>
<div class="toast-container">
    <?php foreach ($flashes as $flash): ?>
        <div class="toast <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
</div>
<script>
    setTimeout(function () {
        var el = document.querySelector('.toast-container');
        if (el) { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 400); }
    }, 5000);
</script>
<?php endif; ?>
