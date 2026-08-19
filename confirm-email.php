<?php
/**
 * Eigene Seite fuer die Bestaetigungspflicht (§11).
 *
 * Wer sich registriert oder unbestaetigt anmelden will, landet hier statt
 * bei einer beilaeufigen Mitteilung. Die Adresse kommt aus der Session,
 * nie aus der URL. Von hier laesst sich der Link erneut anfordern,
 * gedrosselt und ohne zu verraten, ob eine fremde Adresse existiert.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Auth\EmailVerification;
use App\Auth\RateLimiter;
use App\Core\Session;

$email = (string) Session::get('verify_email', '');

// Ohne Kontext oder ohne Pflicht gibt es hier nichts zu tun.
if ($email === '' || !EmailVerification::isEnabled()) {
    redirect('login.php');
}

// Banner-Zustand: gerade verschickt, Versand gescheitert, oder neutral.
$state = (string) Session::get('verify_state', '');
Session::remove('verify_state');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = AuthService::findByLogin($email);
    if ($user !== null && $user['email_verified_at'] !== null) {
        // Inzwischen bestaetigt, etwa in einem anderen Tab.
        Session::remove('verify_email');
        Session::flash('success', 'Deine E-Mail-Adresse ist bestätigt. Du kannst dich anmelden.');
        redirect('login.php');
    }
    if (RateLimiter::tooManyAttempts('verify_resend', $email)) {
        $state = 'throttled';
    } elseif ($user !== null) {
        RateLimiter::hit('verify_resend', $email);
        try {
            $state = EmailVerification::send((int) $user['id'], $email) ? 'sent' : 'failed';
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Bestaetigungsmail fehlgeschlagen: ' . $e->getMessage());
            $state = 'failed';
        }
    } else {
        // Konto unbekannt: gleiche Anzeige wie beim Erfolg, sonst liesse
        // sich hier abfragen, welche Adressen registriert sind.
        $state = 'sent';
    }
}

$pageTitle = 'E-Mail bestätigen';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div style="text-align:center; margin-bottom:14px; color:var(--accent, #1d4fd7);">
            <?= icon('mail', 44) ?>
        </div>
        <h1 style="text-align:center">Bitte bestätige deine E-Mail-Adresse</h1>
        <p class="lead" style="text-align:center">
            Wir haben eine E-Mail an<br>
            <strong><?= e($email) ?></strong><br>
            gesendet. Öffne den Link darin, um dein Konto zu aktivieren.
        </p>

        <?php if ($state === 'sent'): ?>
            <div class="alert alert-success">Eine neue E-Mail ist unterwegs. Auch die Links aus früheren E-Mails bleiben gültig.</div>
        <?php elseif ($state === 'failed'): ?>
            <div class="alert alert-danger">Der Versand hat gerade nicht geklappt. Bitte versuche es in ein paar Minuten noch einmal.</div>
        <?php elseif ($state === 'throttled'): ?>
            <div class="alert alert-warning">Du hast gerade erst eine E-Mail angefordert. Bitte warte einen Moment und schau auch im Spam-Ordner nach.</div>
        <?php endif; ?>

        <div class="alert alert-info">
            Keine E-Mail bekommen? Schau im Spam-Ordner nach. Der Link ist 48 Stunden gültig.
        </div>

        <form method="post" action="<?= base_url('confirm-email.php') ?>">
            <?= App\Core\Csrf::field() ?>
            <button type="submit" class="btn btn-primary btn-block">E-Mail erneut senden</button>
        </form>
        <p style="text-align:center; margin-top:12px">
            <a href="<?= base_url('login.php') ?>">Zur Anmeldung</a>
        </p>
    </div>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
