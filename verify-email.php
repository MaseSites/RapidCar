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
            <h1>Link ungültig oder abgelaufen</h1>
            <p class="lead">Der Bestätigungslink ist nicht mehr gültig. Bitte melde dich an, um einen neuen Link anzufordern.</p>
            <a class="btn btn-primary btn-block" href="<?= base_url('login.php') ?>">Zum Login</a>
        <?php endif; ?>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
