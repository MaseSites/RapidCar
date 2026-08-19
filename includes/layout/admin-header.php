<?php
/**
 * Admin-Layout (§44): eigene Sidebar für den Plattform-Betreiber.
 * Erwartet: $pageTitle, $activeNav (dashboard|users|vehicles|activity|settings)
 * Muss NACH require_super_admin() eingebunden werden.
 */

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Auth\AuthService;
use App\Core\Csrf;
use App\Core\Session;

$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';
$flashes = Session::pullFlashes();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | RapidCar Admin</title>
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link rel="stylesheet" href="<?= asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('css/dashboard.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset('icons/favicon.svg') ?>">
</head>
<body data-label-cancel="<?= e(t('common.cancel')) ?>"
      data-label-confirm="<?= e(t('common.confirm')) ?>"
      data-label-delete="<?= e(t('common.delete')) ?>">
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <a class="logo" href="<?= base_url('admin/') ?>"><?= brand_logo(25) ?></a>
            <div class="text-xs text-muted mt-1">Admin-Bereich</div>
        </div>
        <nav>
            <a class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= base_url('admin/') ?>">
                <?= icon('dashboard') ?> Plattformübersicht
            </a>
            <a class="nav-item <?= $activeNav === 'users' ? 'active' : '' ?>" href="<?= base_url('admin/users.php') ?>">
                <?= icon('users') ?> Benutzer
            </a>
            <a class="nav-item <?= $activeNav === 'vehicles' ? 'active' : '' ?>" href="<?= base_url('admin/vehicles.php') ?>">
                <?= icon('car') ?> Fahrzeuge
            </a>
            <a class="nav-item <?= $activeNav === 'orders' ? 'active' : '' ?>" href="<?= base_url('admin/orders.php') ?>">
                <?= icon('tag') ?> <?= t('credits.orders') ?>
                <?php
                $pendingOrders = (int) \App\Core\Database::scalar(
                    "SELECT COUNT(*) FROM credit_orders WHERE status = 'pending'"
                );
                ?>
                <?php if ($pendingOrders > 0): ?><span class="count"><?= $pendingOrders ?></span><?php endif; ?>
            </a>
            <a class="nav-item <?= $activeNav === 'activity' ? 'active' : '' ?>" href="<?= base_url('admin/activity.php') ?>">
                <?= icon('activity') ?> Aktivitätsprotokoll
            </a>
            <a class="nav-item <?= $activeNav === 'channels' ? 'active' : '' ?>" href="<?= base_url('admin/channels.php') ?>">
                <?= icon('link') ?> Kanäle
            </a>
            <a class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>" href="<?= base_url('admin/settings.php') ?>">
                <?= icon('settings') ?> Einstellungen
            </a>
        </nav>
        <div class="bottom">
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
                <span class="badge badge-info">super_admin</span>
                <div class="user-chip">
                    <span class="avatar"><?= e(initials(AuthService::fullName())) ?></span>
                    <span class="user-name-label"><?= e(AuthService::shortName()) ?></span>
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
