<?php
/**
 * Kontaktformular. Die Nachricht geht an die Betreiberadresse: entweder an
 * mail.contact aus der Konfiguration oder an das erste Betreiberkonto.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

use App\Auth\RateLimiter;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\Validator;

$sent = false;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $v = new Validator($_POST);
    $v->required('name', 'Name')->maxLength('name', 'Name', 150)
      ->required('email', 'E-Mail')->email('email', 'E-Mail')
      ->required('message', 'Nachricht')->maxLength('message', 'Nachricht', 5000);

    if ($v->fails()) {
        $error = $v->firstError();
        store_old_input($_POST);
    } elseif (RateLimiter::tooManyAttempts('contact', $ip)) {
        $error = 'Zu viele Nachrichten. Bitte versuche es später erneut.';
    } else {
        RateLimiter::hit('contact', $ip);
        $to = trim((string) Config::get('mail.contact', ''));
        if ($to === '') {
            // Ohne festen Eintrag geht die Nachricht an den Betreiber.
            $stmt = Database::connection()->query(
                "SELECT email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"
            );
            $to = (string) ($stmt->fetchColumn() ?: '');
        }
        if ($to === '') {
            $to = (string) Config::get('mail.from', '');
        }
        if ($to !== '') {
            Mailer::send(
                $to,
                'Kontaktanfrage von ' . $v->value('name'),
                '<p><strong>Name:</strong> ' . e($v->value('name')) . '</p>'
                . '<p><strong>E-Mail:</strong> ' . e($v->value('email')) . '</p>'
                . '<p>' . nl2br(e($v->value('message'))) . '</p>'
            );
        }
        clear_old_input();
        $sent = true;
    }
}

$pageTitle = 'Kontakt';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card wide">
        <h1>Kontakt</h1>
        <?php if ($sent): ?>
            <div class="alert alert-success">Vielen Dank! Deine Nachricht wurde übermittelt. Wir melden uns so schnell wie möglich.</div>
        <?php else: ?>
            <p class="lead">Fragen zu RapidCar? Schreib uns eine Nachricht.</p>
            <?php if ($error !== null): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= base_url('contact.php') ?>" novalidate>
                <?= App\Core\Csrf::field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" type="text" id="name" name="name" value="<?= old('name') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="email">E-Mail</label>
                        <input class="form-control" type="email" id="email" name="email" value="<?= old('email') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="message">Nachricht</label>
                    <textarea class="form-control" id="message" name="message" rows="6" required><?= old('message') ?></textarea>
                </div>
                <button class="btn btn-primary btn-block" type="submit">Nachricht senden</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
