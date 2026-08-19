<?php
/**
 * Passwort vergessen (§13): E-Mail eingeben → Reset-Link.
 * Antwortet immer gleich — keine Auskunft, ob eine E-Mail existiert.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Auth\PasswordReset;
use App\Auth\RateLimiter;
use App\Core\Validator;

if (AuthService::check()) {
    redirect('dashboard/');
}

$sent = false;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $v = new Validator($_POST);
    $v->required('email', 'E-Mail')->email('email', 'E-Mail');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($v->fails()) {
        $error = $v->firstError();
    } elseif (RateLimiter::tooManyAttempts('password_reset', $ip)) {
        $error = 'Zu viele Anfragen. Bitte warte ' . RateLimiter::retryAfterMinutes('password_reset', $ip) . ' Minuten.';
    } else {
        RateLimiter::hit('password_reset', $ip);
        PasswordReset::request($v->value('email'));
        $sent = true;
    }
}

$pageTitle = 'Passwort zurücksetzen';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1>Passwort vergessen?</h1>
        <?php if ($sent): ?>
            <div class="alert alert-success">Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir einen Link zum Zurücksetzen gesendet. Der Link ist 60 Minuten gültig.</div>
            <div class="auth-links">
                <a href="<?= base_url('login.php') ?>">Zurück zum Login</a>
            </div>
        <?php else: ?>
            <p class="lead">Gib deine E-Mail-Adresse ein. Wir senden dir einen Link zum Zurücksetzen.</p>
            <?php if ($error !== null): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= base_url('forgot-password.php') ?>" novalidate>
                <?= App\Core\Csrf::field() ?>
                <div class="form-group">
                    <label class="form-label" for="email"><?= t('auth.email') ?></label>
                    <input class="form-control" type="email" id="email" name="email" required autofocus>
                </div>
                <button class="btn btn-primary btn-block" type="submit">Reset-Link senden</button>
            </form>
            <div class="auth-links">
                <a href="<?= base_url('login.php') ?>">Zurück zum Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
