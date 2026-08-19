<?php
/**
 * Login (§12) mit Rate-Limiting (§10).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Auth\EmailVerification;
use App\Auth\RateLimiter;
use App\Core\Session;
use App\Core\Validator;

if (AuthService::check()) {
    redirect('dashboard/');
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Angemeldet wird mit E-Mail oder Benutzername.
    $v = new Validator($_POST);
    $v->required('login', t('auth.login.identifier'))->required('password', 'Passwort');

    $login = $v->value('login');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($v->fails()) {
        $error = $v->firstError();
    } elseif (RateLimiter::tooManyAttempts('login', $login) || RateLimiter::tooManyAttempts('login', $ip)) {
        $minutes = max(
            RateLimiter::retryAfterMinutes('login', $login),
            RateLimiter::retryAfterMinutes('login', $ip)
        );
        $error = t('auth.login.throttled', ['minutes' => $minutes]);
    } else {
        $user = AuthService::attempt($login, (string) $_POST['password']);
        if ($user === null) {
            RateLimiter::hit('login', $login);
            RateLimiter::hit('login', $ip);
            $error = t('auth.login.failed');
        } elseif (EmailVerification::isEnabled() && $user['email_verified_at'] === null) {
            AuthService::logout();
            // Keine Mail von selbst: nachgeschickt wird nur, wenn jemand auf
            // der Bestaetigungsseite ausdruecklich "E-Mail erneut senden"
            // drueckt. Die Links aus der Registrierung bleiben gueltig.
            Session::set('verify_email', (string) $user['email']);
            Session::set('verify_state', '');
            redirect('confirm-email.php');
        } else {
            RateLimiter::clear('login', $login);
            RateLimiter::clear('login', $ip);

            // Super-Admin → Admin-Bereich; Händler ohne Onboarding → Onboarding
            if ((string) $user['role'] === AuthService::ROLE_SUPER_ADMIN) {
                redirect('admin/');
            }
            if ($user['onboarding_completed_at'] === null) {
                redirect('dashboard/onboarding.php');
            }
            redirect('dashboard/');
        }
    }
    store_old_input($_POST);
}

$pageTitle = t('auth.login.title');
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1><?= t('auth.login.title') ?></h1>
        <p class="lead"><?= t('auth.login.lead') ?></p>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= base_url('login.php') ?>" novalidate>
            <?= App\Core\Csrf::field() ?>
            <div class="form-group">
                <label class="form-label" for="login"><?= t('auth.login.identifier') ?></label>
                <input class="form-control" type="text" id="login" name="login" value="<?= old('login') ?>"
                       autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><?= t('auth.password') ?></label>
                <input class="form-control" type="password" id="password" name="password" required>
            </div>
            <button class="btn btn-primary btn-block btn-lg" type="submit"><?= t('auth.login.submit') ?></button>
        </form>
        <?php if (App\Auth\GoogleAuth::isConfigured()): ?>
            <div class="auth-divider"><span><?= t('auth.or') ?></span></div>
            <a class="btn btn-secondary btn-block" href="<?= base_url('google-login.php') ?>">
                <svg width="17" height="17" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.5 13.2l7.9 6.2C12.3 13.5 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.7 6c4.5-4.2 6.9-10.3 6.9-17.7z"/><path fill="#FBBC05" d="M10.4 28.6c-.5-1.5-.8-3-.8-4.6s.3-3.1.8-4.6l-7.9-6.2C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.8l7.9-6.2z"/><path fill="#34A853" d="M24 48c6.3 0 11.6-2.1 15.6-5.8l-7.7-6c-2.1 1.4-4.8 2.3-7.9 2.3-6.3 0-11.7-4-13.6-9.9l-7.9 6.2C6.5 42.6 14.6 48 24 48z"/></svg>
                <?= t('auth.google.continue') ?>
            </a>
        <?php endif; ?>
        <div class="auth-links">
            <a href="<?= base_url('forgot-password.php') ?>"><?= t('auth.login.forgot') ?></a>
            <a href="<?= base_url('register.php') ?>"><?= t('auth.login.no_account') ?></a>
        </div>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
