<?php
/**
 * Eigenes Profil: Stammdaten + Passwortänderung.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Core\Lang;
use App\Core\Session;
use App\Core\Validator;
use App\Service\ActivityLogger;

require_dealership();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_profile') {
        $v = new Validator($_POST);
        $v->required('first_name', t('auth.first_name'))->maxLength('first_name', t('auth.first_name'), 100)
          ->required('last_name', t('auth.last_name'))->maxLength('last_name', t('auth.last_name'), 100)
          ->maxLength('phone', t('auth.phone'), 50);
        if ($v->fails()) {
            Session::flash('danger', (string) $v->firstError());
        } else {
            $language = (string) ($_POST['language'] ?? '');
            Database::update('users', (int) $currentUser['id'], [
                'first_name' => $v->value('first_name'),
                'last_name'  => $v->value('last_name'),
                'phone'      => $v->value('phone'),
                'language'   => Lang::isSupported($language) ? $language : null,
                'updated_at' => Database::now(),
            ]);
            // Sitzungssprache an die neue Auswahl anpassen
            if (Lang::isSupported($language)) {
                Lang::switchTo($language);
            } else {
                Session::remove(Lang::SESSION_KEY);
            }
            Session::flash('success', t('profile.saved'));
        }
    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) $currentUser['password_hash'])) {
            Session::flash('danger', t('profile.current_password') . ': ' . t('auth.login.failed'));
        } elseif (mb_strlen($new) < 8) {
            Session::flash('danger', t('auth.min_chars', ['count' => 8]));
        } elseif ($new !== $confirm) {
            Session::flash('danger', t('profile.new_password_confirm'));
        } else {
            Database::update('users', (int) $currentUser['id'], [
                'password_hash' => password_hash($new, PASSWORD_DEFAULT),
                'updated_at'    => Database::now(),
            ]);
            ActivityLogger::log((int) $currentUser['id'], 'user.password_changed', 'Passwort geändert', 'user', (int) $currentUser['id']);
            Session::flash('success', t('profile.password_changed'));
        }
    }
    redirect('dashboard/profile.php');
}

$pageTitle = t('sidebar.profile');
$activeNav = 'profile';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head"><h1><?= t('profile.title') ?></h1></div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <div class="card-header"><h2><?= t('profile.master_data') ?></h2></div>
        <div class="card-body">
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="save_profile">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('auth.first_name') ?></label>
                        <input class="form-control" type="text" name="first_name" value="<?= e($currentUser['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('auth.last_name') ?></label>
                        <input class="form-control" type="text" name="last_name" value="<?= e($currentUser['last_name']) ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('auth.email') ?></label>
                    <input class="form-control" type="email" value="<?= e($currentUser['email']) ?>" disabled>
                    <div class="form-hint"><?= t('profile.email_locked') ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('auth.phone') ?></label>
                    <input class="form-control" type="tel" name="phone" value="<?= e($currentUser['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('profile.language') ?></label>
                    <select class="form-control" name="language">
                        <option value=""><?= t('profile.language_default') ?></option>
                        <?php foreach (Lang::AVAILABLE as $code => $label): ?>
                            <option value="<?= $code ?>" <?= ($currentUser['language'] ?? '') === $code ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint"><?= t('profile.language_hint') ?></div>
                </div>
                <button class="btn btn-primary" type="submit"><?= t('common.save') ?></button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= t('profile.change_password') ?></h2></div>
        <div class="card-body">
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label"><?= t('profile.current_password') ?></label>
                    <input class="form-control" type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('profile.new_password') ?></label>
                    <input class="form-control" type="password" name="new_password" minlength="8" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('profile.new_password_confirm') ?></label>
                    <input class="form-control" type="password" name="new_password_confirm" minlength="8" required>
                </div>
                <button class="btn btn-primary" type="submit"><?= t('profile.change_password') ?></button>
            </form>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
