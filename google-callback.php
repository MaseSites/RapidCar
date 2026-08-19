<?php
/**
 * Rücksprung von Google: Konto anlegen oder anmelden.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Auth\AuthService;
use App\Auth\GoogleAuth;
use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;

if (!GoogleAuth::isConfigured()) {
    redirect('login.php');
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
if ($code === '' || $state === '') {
    Session::flash('warning', 'Die Google-Anmeldung wurde abgebrochen.');
    redirect('login.php');
}

try {
    $result = GoogleAuth::handleCallback($code, $state);
} catch (\Throwable $e) {
    Session::flash('danger', $e->getMessage());
    redirect('login.php');
}

$user = $result['user'];

// Session aufbauen wie beim normalen Login
Session::regenerate();
Session::set('user_id', (int) $user['id']);
Database::update('users', (int) $user['id'], ['last_login_at' => Database::now()]);
ActivityLogger::log(
    (int) $user['id'],
    $result['created'] ? 'user.registered_google' : 'user.login_google',
    $result['created'] ? 'Registrierung über Google' : 'Anmeldung über Google',
    'user',
    (int) $user['id'],
    $user['dealership_id'] !== null ? (int) $user['dealership_id'] : null
);

if ($result['created']) {
    Session::flash('success', t('auth.google.welcome'));
}

if ((string) $user['role'] === AuthService::ROLE_SUPER_ADMIN) {
    redirect('admin/');
}
if ($user['onboarding_completed_at'] === null) {
    redirect('dashboard/onboarding.php');
}
redirect('dashboard/');
