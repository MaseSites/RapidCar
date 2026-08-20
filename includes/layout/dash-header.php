<?php
/**
 * Dashboard-Layout: HTML-Kopf, Sidebar (§19), Topbar (§20).
 * Erwartet: $pageTitle, $activeNav (dashboard|vehicles|listings|leads|social|settings|profile)
 * Muss NACH includes/auth.php eingebunden werden ($currentUser verfügbar).
 */

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Auth\AuthService;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Service\SettingsService;

$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';
$flashes = Session::pullFlashes();

$dealershipId = AuthService::dealershipId();

// Sidebar-Zähler: neue Anfragen
$newLeadsCount = 0;
if ($dealershipId !== null) {
    $newLeadsCount = (int) Database::scalar(
        "SELECT COUNT(*) FROM leads WHERE dealership_id = :did AND status = 'new'",
        ['did' => $dealershipId]
    );
}

// Ungelesene Benachrichtigungen
$unreadNotifications = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL',
    ['uid' => (int) $currentUser['id']]
);

$aiMode = SettingsService::aiMode();
$isDemoUser = (int) ($currentUser['is_demo'] ?? 0) === 1;
$creditBalance = $dealershipId !== null ? \App\Service\CreditService::balance($dealershipId) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | RapidCar</title>
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link rel="stylesheet" href="<?= asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('css/dashboard.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset('icons/favicon.svg') ?>">
</head>
<body data-label-cancel="<?= e(t('common.cancel')) ?>"
      data-label-confirm="<?= e(t('common.confirm')) ?>"
      data-label-delete="<?= e(t('common.delete')) ?>">
<?php
// Abo-Stand einmal ermitteln: er steuert das Pro-Zeichen und die Sperren.
$hasPlus = $dealershipId !== null && \App\Service\SubscriptionService::isActive($dealershipId);
?>
<div class="app">
    <aside class="sidebar">
        <?php // Kopf der Seitenleiste: Wortmarke, darunter das Pro-Zeichen ?>
        <div class="sidebar-brand">
            <span class="brand-word sidebar-brand-name">Rapid<span>Car</span></span>
            <?php if ($hasPlus): ?>
                <span class="pro-mark" title="RapidCar Plus ist aktiv">Pro</span>
            <?php endif; ?>
        </div>
        <nav>
            <a class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= base_url('dashboard/') ?>">
                <?= icon('dashboard') ?> <?= t('sidebar.dashboard') ?>
            </a>
            <div class="nav-section-label"><?= t('sidebar.section_listings') ?></div>
            <a class="nav-item <?= $activeNav === 'create' ? 'active' : '' ?>" href="<?= base_url('dashboard/create-vehicle.php') ?>">
                <?= icon('plus') ?> <?= t('sidebar.create_listing') ?>
            </a>
            <a class="nav-item <?= $activeNav === 'vehicles' ? 'active' : '' ?>" href="<?= base_url('dashboard/vehicles.php') ?>">
                <?= icon('car') ?> <?= t('sidebar.vehicles') ?>
            </a>
            <div class="nav-section-label"><?= t('sidebar.section_accounts') ?></div>
            <a class="nav-item <?= $activeNav === 'channels' ? 'active' : '' ?>" href="<?= base_url('dashboard/channels.php') ?>">
                <?= icon('link') ?> <?= t('sidebar.channels') ?>
            </a>
            <div class="nav-section-label"><?= t('sidebar.section_social') ?></div>
            <a class="nav-item <?= $activeNav === 'social' ? 'active' : '' ?>" href="<?= base_url('dashboard/social.php') ?>">
                <?= icon('instagram') ?> <?= t('sidebar.posts') ?>
            </a>
            <div class="nav-section-label"><?= t('sidebar.section_manage') ?></div>
            <a class="nav-item <?= $activeNav === 'credits' ? 'active' : '' ?>" href="<?= base_url('dashboard/credits.php') ?>">
                <?= icon('tag') ?> <?= t('sidebar.credits') ?>
            </a>
            <a class="nav-item <?= $activeNav === 'subscription' ? 'active' : '' ?>" href="<?= base_url('dashboard/subscription.php') ?>">
                <?= icon('star') ?> Abo
                <?php if (!$hasPlus): ?><span class="pro-badge">Pro</span><?php endif; ?>
            </a>
            <a class="nav-item <?= $activeNav === 'details' ? 'active' : '' ?>" href="<?= base_url('dashboard/details.php') ?>">
                <?= icon('user') ?> Angaben
            </a>
            <a class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>" href="<?= base_url('dashboard/settings.php') ?>">
                <?= icon('settings') ?> <?= t('sidebar.settings') ?>
            </a>
            <?php if (AuthService::isSuperAdmin()): ?>
                <div class="nav-section-label"><?= t('sidebar.section_platform') ?></div>
                <a class="nav-item" href="<?= base_url('admin/') ?>">
                    <?= icon('shield') ?> <?= t('sidebar.admin') ?>
                </a>
            <?php endif; ?>
        </nav>
        <div class="bottom">
            <a class="nav-item" href="<?= base_url('contact.php') ?>">
                <?= icon('help') ?> <?= t('sidebar.help') ?>
            </a>
            <div class="dropdown" style="width:100%">
                <button class="nav-item" type="button" data-dropdown="langMenu" style="width:100%;border:none;background:none;cursor:pointer;font-family:inherit;font-size:14px;text-align:left">
                    <?= icon('globe') ?> <?= e(\App\Core\Lang::localeName()) ?>
                    <span style="margin-left:auto"><?= icon('chevron-down', 14) ?></span>
                </button>
                <div class="dropdown-menu" id="langMenu" style="bottom:calc(100% + 6px);top:auto;left:0;right:auto;min-width:170px">
                    <?php foreach (\App\Core\Lang::AVAILABLE as $code => $label): ?>
                        <a href="<?= base_url('language.php?set=' . $code . '&return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard/')) ?>">
                            <?php if ($code === \App\Core\Lang::locale()): ?><?= icon('check', 14) ?><?php else: ?><span style="width:14px"></span><?php endif; ?>
                            <?= e($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <a class="nav-item <?= $activeNav === 'profile' ? 'active' : '' ?>" href="<?= base_url('dashboard/profile.php') ?>">
                <?= icon('user') ?> <?= t('sidebar.profile') ?>
            </a>
            <form method="post" action="<?= base_url('logout.php') ?>">
                <?= Csrf::field() ?>
                <button class="nav-item" type="submit">
                    <?= icon('logout') ?> <?= t('auth.logout') ?>
                </button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop"></div>

    <div class="main">
        <header class="topbar">
            <div class="flex-center gap-1">
                <button class="icon-btn menu-btn" data-sidebar-toggle aria-label="Menü"><?= icon('menu', 20) ?></button>
                <div class="page-title"><?= e($pageTitle) ?></div>
            </div>
            <div class="topbar-actions">
                <?php if ($isDemoUser): ?>
                    <span class="badge badge-info" title="Im Demo-Konto sind Änderungen deaktiviert.">Demo</span>
                <?php endif; ?>
                <?php /* Im Normalbetrieb steht hier nichts: dass die KI läuft,
                          zeigt sich an den Ergebnissen. Nur die Demo wird
                          gekennzeichnet, damit niemand echte Erkennung erwartet. */ ?>
                <?php if ($aiMode === 'mock'): ?>
                    <span class="badge badge-warning" title="<?= t('ai.mode.mock') ?>"><?= t('ai.badge.test') ?></span>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="icon-btn" data-dropdown="notifMenu" aria-label="<?= t('header.notifications') ?>">
                        <?= icon('bell', 19) ?>
                        <?php if ($unreadNotifications > 0): ?><span class="dot"></span><?php endif; ?>
                    </button>
                    <div class="dropdown-menu" id="notifMenu" style="min-width:290px">
                        <div class="menu-header fw-600"><?= t('header.notifications') ?></div>
                        <?php
                        $notifications = Database::fetchAll(
                            'SELECT * FROM notifications WHERE user_id = :uid ORDER BY id DESC LIMIT 6',
                            ['uid' => (int) $currentUser['id']]
                        );
                        if ($notifications === []): ?>
                            <div class="menu-header">Keine Benachrichtigungen.</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <a href="<?= $notification['link'] !== null ? e(base_url((string) $notification['link'])) : '#' ?>">
                                    <div>
                                        <div class="fw-600 text-sm"><?= e($notification['title']) ?></div>
                                        <div class="text-xs text-muted"><?= e(time_ago((string) $notification['created_at'])) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php // Guthaben immer im Blick; ein Klick fuehrt zum Kauf. ?>
                <?php if ($dealershipId !== null): ?>
                    <a class="credit-chip" href="<?= base_url('dashboard/credits.php') ?>"
                       title="<?= t('sidebar.credits') ?>">
                        <?= icon('tag', 14) ?>
                        <span><?= (int) $creditBalance ?></span>
                    </a>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="user-chip" data-dropdown="userMenu">
                        <span class="avatar"><?= e(initials(AuthService::fullName())) ?></span>
                        <span class="user-name-label"><?= e(AuthService::shortName()) ?></span>
                        <?= icon('chevron-down', 14) ?>
                    </button>
                    <div class="dropdown-menu" id="userMenu">
                        <a href="<?= base_url('dashboard/profile.php') ?>"><?= icon('user', 15) ?> <?= t('header.my_profile') ?></a>
                        <a href="<?= base_url('dashboard/settings.php') ?>"><?= icon('settings', 15) ?> <?= t('header.settings') ?></a>
                        <a href="<?= base_url('dashboard/settings.php#dealership') ?>"><?= icon('building', 15) ?> <?= t('header.dealership') ?></a>
                        <div class="divider"></div>
                        <form method="post" action="<?= base_url('logout.php') ?>">
                            <?= Csrf::field() ?>
                            <button type="submit"><?= icon('logout', 15) ?> <?= t('auth.logout') ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="content">
<?php if ($flashes !== []): ?>
<div class="toast-container">
    <?php foreach ($flashes as $flash): ?>
        <div class="toast <?= e($flash['type']) ?>" data-server="1"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
