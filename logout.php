<?php
/**
 * Logout — nur per POST (CSRF-geschützt), damit kein fremder Link ausloggt.
 * GET zeigt eine minimale Bestätigung.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Auth\AuthService;
use App\Core\Csrf;
use App\Core\Session;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyRequest();
    AuthService::logout();
    Session::start();
    Session::flash('success', 'Du wurdest abgemeldet.');
    redirect('index.php');
}

if (!AuthService::check()) {
    redirect('index.php');
}

$pageTitle = t('auth.logout');
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1><?= t('auth.logout') ?></h1>
        <p class="lead">Möchtest du dich wirklich abmelden?</p>
        <form method="post" action="<?= base_url('logout.php') ?>">
            <?= Csrf::field() ?>
            <button class="btn btn-primary btn-block" type="submit"><?= t('auth.logout') ?></button>
        </form>
        <div class="auth-links">
            <a href="<?= base_url('dashboard/') ?>">Zurück zum Dashboard</a>
        </div>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
