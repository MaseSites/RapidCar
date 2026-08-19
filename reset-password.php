<?php
/**
 * Neues Passwort setzen (§13) — über einmaligen Token-Link.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

use App\Auth\PasswordReset;
use App\Core\Session;
use App\Core\Validator;

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$userId = PasswordReset::validateToken($token);
$error = null;

if ($userId === null) {
    $pageTitle = 'Link ungültig';
    require BASE_PATH . '/includes/layout/public-header.php';
    ?>
    <div class="auth-wrap">
        <div class="auth-card">
            <h1>Link ungültig oder abgelaufen</h1>
            <p class="lead">Bitte fordere einen neuen Link zum Zurücksetzen an.</p>
            <a class="btn btn-primary btn-block" href="<?= base_url('forgot-password.php') ?>">Neuen Link anfordern</a>
        </div>
    </div>
    <?php
    require BASE_PATH . '/includes/layout/public-footer.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $v = new Validator($_POST);
    $v->required('password', 'Passwort')->minLength('password', 'Passwort', 8)
      ->matches('password_confirm', 'password', 'Die Passwort-Wiederholung');

    if ($v->fails()) {
        $error = $v->firstError();
    } elseif (PasswordReset::complete($token, (string) $_POST['password'])) {
        Session::flash('success', 'Dein Passwort wurde geändert. Du kannst dich jetzt einloggen.');
        redirect('login.php');
    } else {
        $error = 'Der Link ist nicht mehr gültig. Bitte fordere einen neuen an.';
    }
}

$pageTitle = 'Neues Passwort setzen';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1>Neues Passwort setzen</h1>
        <p class="lead">Wähle ein sicheres Passwort mit mindestens 8 Zeichen.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= base_url('reset-password.php') ?>" novalidate>
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="form-group">
                <label class="form-label" for="password">Neues Passwort</label>
                <input class="form-control" type="password" id="password" name="password" minlength="8" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label" for="password_confirm"><?= t('auth.password_confirm') ?></label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="8" required>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Passwort ändern</button>
        </form>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
