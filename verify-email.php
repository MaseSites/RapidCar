<?php
/**
 * E-Mail-Verifizierung (§11): Bestätigungslink aus der E-Mail.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Auth\EmailVerification;
use App\Core\Session;

$token = (string) ($_GET['token'] ?? '');
$userId = $token !== '' ? EmailVerification::verify($token) : null;

$pageTitle = 'E-Mail bestätigen';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <?php if ($userId !== null): ?>
            <h1>E-Mail bestätigt</h1>
            <p class="lead">Dein Konto ist jetzt aktiviert. Du kannst dich einloggen.</p>
            <a class="btn btn-primary btn-block" href="<?= base_url('login.php') ?>"><?= t('auth.login.title') ?></a>
        <?php else: ?>
            <h1>Dieser Link ist nicht mehr gültig</h1>
            <p class="lead">Vielleicht wurde deine Adresse schon bestätigt, etwa über einen Link aus einer anderen E-Mail. Versuche einfach, dich anzumelden: Ist noch etwas offen, senden wir dir automatisch einen neuen Link.</p>
            <a class="btn btn-primary btn-block" href="<?= base_url('login.php') ?>">Zur Anmeldung</a>
        <?php endif; ?>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
