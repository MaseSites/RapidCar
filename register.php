<?php
/**
 * Registrierung (§9, §10).
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

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (RateLimiter::tooManyAttempts('register', $ip)) {
        $errors['_general'] = 'Zu viele Registrierungsversuche. Bitte warte '
            . RateLimiter::retryAfterMinutes('register', $ip) . ' Minuten.';
    } else {
        $v = new Validator($_POST);
        $v->required('first_name', 'Vorname')->maxLength('first_name', 'Vorname', 100)
          ->required('last_name', 'Nachname')->maxLength('last_name', 'Nachname', 100)
          ->required('email', 'E-Mail')->email('email', 'E-Mail')->maxLength('email', 'E-Mail', 190)
          ->required('password', 'Passwort')->minLength('password', 'Passwort', 8)
          ->matches('password_confirm', 'password', 'Die Passwort-Wiederholung')
          ->required('dealership_name', 'Name des Autohauses')->maxLength('dealership_name', 'Name des Autohauses', 190)
          ->required('phone', 'Telefonnummer')->maxLength('phone', 'Telefonnummer', 50)
          ->required('country', 'Land')->in('country', 'Land', ['CH', 'DE', 'AT', 'LI', 'FR', 'IT'])
          ->checked('terms', 'Bitte stimme der Datenschutzerklärung und den AGB zu.');

        if ($v->passes() && AuthService::emailExists($v->value('email'))) {
            $v->addError('email', 'Diese E-Mail-Adresse ist bereits registriert.');
        }

        if ($v->fails()) {
            $errors = $v->errors();
            store_old_input($_POST);
            RateLimiter::hit('register', $ip);
        } else {
            // Schlaegt das Anlegen fehl, bekommt der Besucher eine klare
            // Meldung statt einer weissen Fehlerseite. Die Einzelheiten
            // landen im Protokoll, nicht auf dem Bildschirm.
            try {
                $userId = AuthService::register(
                    $v->value('first_name'),
                    $v->value('last_name'),
                    $v->value('email'),
                    (string) $_POST['password'],
                    $v->value('dealership_name'),
                    $v->value('phone'),
                    $v->value('country')
                );
            } catch (\Throwable $e) {
                \App\Core\Logger::error('Registrierung fehlgeschlagen: ' . $e->getMessage());
                store_old_input($_POST);
                RateLimiter::hit('register', $ip);
                $errors['_general'] = t('auth.register.failed');
                $userId = 0;
            }

            if ($userId > 0) {
                clear_old_input();
                RateLimiter::clear('register', $ip);

                // Mit Bestaetigungspflicht gibt es keinen Weg an der E-Mail
                // vorbei: kein automatisches Einloggen, auch nicht wenn der
                // Versand scheitert. Die Anmeldeseite verschickt bei Bedarf
                // einen frischen Link.
                if (EmailVerification::isEnabled()) {
                    $mailSent = false;
                    try {
                        $mailSent = EmailVerification::send($userId, $v->value('email'));
                    } catch (\Throwable $e) {
                        \App\Core\Logger::error('Bestaetigungsmail fehlgeschlagen: ' . $e->getMessage());
                    }
                    if ($mailSent) {
                        Session::flash('info', 'Fast geschafft: Wir haben dir eine E-Mail geschickt. Bitte bestätige deine Adresse, danach kannst du dich anmelden.');
                    } else {
                        // Ehrlich bleiben: kein "pruefe dein Postfach", wenn
                        // nichts angekommen sein kann.
                        Session::flash('info', 'Dein Konto wurde angelegt, aber die Bestätigungs-E-Mail konnte gerade nicht verschickt werden. Melde dich in ein paar Minuten an, dann senden wir automatisch einen neuen Link.');
                    }
                    redirect('login.php');
                }

                // Ohne Bestaetigungspflicht: direkt einloggen und ins Onboarding
                if (AuthService::attempt($v->value('email'), (string) $_POST['password'])) {
                    redirect('dashboard/onboarding.php');
                }
                Session::flash('info', t('auth.register.done'));
                redirect('login.php');
            }
        }
    }
}

$pageTitle = t('auth.register.title');
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card wide">
        <h1><?= t('auth.register.title') ?></h1>
        <p class="lead"><?= t('auth.register.lead') ?></p>

        <?php if (isset($errors['_general'])): ?>
            <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= base_url('register.php') ?>" novalidate>
            <?= App\Core\Csrf::field() ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="first_name"><?= t('auth.first_name') ?></label>
                    <input class="form-control" type="text" id="first_name" name="first_name" value="<?= old('first_name') ?>" required>
                    <?php if (isset($errors['first_name'])): ?><div class="form-error"><?= e($errors['first_name']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="last_name"><?= t('auth.last_name') ?></label>
                    <input class="form-control" type="text" id="last_name" name="last_name" value="<?= old('last_name') ?>" required>
                    <?php if (isset($errors['last_name'])): ?><div class="form-error"><?= e($errors['last_name']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="email"><?= t('auth.email') ?></label>
                <input class="form-control" type="email" id="email" name="email" value="<?= old('email') ?>" required>
                <?php if (isset($errors['email'])): ?><div class="form-error"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="password"><?= t('auth.password') ?></label>
                    <input class="form-control" type="password" id="password" name="password" minlength="8" required>
                    <?php if (isset($errors['password'])): ?><div class="form-error"><?= e($errors['password']) ?></div>
                    <?php else: ?><div class="form-hint"><?= t('auth.min_chars', ['count' => 8]) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password_confirm"><?= t('auth.password_confirm') ?></label>
                    <input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="8" required>
                    <?php if (isset($errors['password_confirm'])): ?><div class="form-error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="dealership_name"><?= t('auth.dealership_name') ?></label>
                <input class="form-control" type="text" id="dealership_name" name="dealership_name" value="<?= old('dealership_name') ?>" required>
                <?php if (isset($errors['dealership_name'])): ?><div class="form-error"><?= e($errors['dealership_name']) ?></div><?php endif; ?>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="phone"><?= t('auth.phone') ?></label>
                    <input class="form-control" type="tel" id="phone" name="phone" value="<?= old('phone') ?>" required>
                    <?php if (isset($errors['phone'])): ?><div class="form-error"><?= e($errors['phone']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="country"><?= t('auth.country') ?></label>
                    <select class="form-control" id="country" name="country" required>
                        <?php
                        $countries = ['CH' => 'Schweiz', 'DE' => 'Deutschland', 'AT' => 'Österreich', 'LI' => 'Liechtenstein', 'FR' => 'Frankreich', 'IT' => 'Italien'];
                        $oldCountry = App\Core\Session::get('_old_input', [])['country'] ?? 'CH';
                        foreach ($countries as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $oldCountry === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="terms" value="1">
                    <span><?= t('auth.terms') ?> <a href="<?= base_url('privacy.php') ?>" target="_blank"><?= t('nav.privacy') ?></a></span>
                </label>
                <?php if (isset($errors['terms'])): ?><div class="form-error"><?= e($errors['terms']) ?></div><?php endif; ?>
            </div>
            <button class="btn btn-accent btn-block btn-lg" type="submit"><?= t('auth.register.submit') ?></button>
        </form>
        <?php if (App\Auth\GoogleAuth::isConfigured()): ?>
            <div class="auth-divider"><span><?= t('auth.or') ?></span></div>
            <a class="btn btn-secondary btn-block" href="<?= base_url('google-login.php') ?>">
                <svg width="17" height="17" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.5 13.2l7.9 6.2C12.3 13.5 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.7 6c4.5-4.2 6.9-10.3 6.9-17.7z"/><path fill="#FBBC05" d="M10.4 28.6c-.5-1.5-.8-3-.8-4.6s.3-3.1.8-4.6l-7.9-6.2C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.8l7.9-6.2z"/><path fill="#34A853" d="M24 48c6.3 0 11.6-2.1 15.6-5.8l-7.7-6c-2.1 1.4-4.8 2.3-7.9 2.3-6.3 0-11.7-4-13.6-9.9l-7.9 6.2C6.5 42.6 14.6 48 24 48z"/></svg>
                <?= t('auth.google.continue') ?>
            </a>
        <?php endif; ?>
        <div class="auth-links">
            <a href="<?= base_url('login.php') ?>"><?= t('auth.register.has_account') ?></a>
        </div>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
