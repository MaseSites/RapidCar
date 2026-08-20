<?php
/**
 * Onboarding nach dem ersten Login (§14–§16):
 *   1. Willkommen
 *   2. Autohaus-Profil (§15)
 *   3. AutoScout24-Verbindung (§16) → Abschluss
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;
use App\Integration\ChannelRegistry;
use App\Service\ActivityLogger;
use App\Service\ImageService;

$dealershipId = require_dealership();

// Bereits abgeschlossen → Dashboard
if ($currentUser['onboarding_completed_at'] !== null) {
    redirect('dashboard/');
}

$dealership = Database::fetch('SELECT * FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
// Privatkonten sehen ihre eigenen Worte, kein "Autohaus".
$isPrivate = ($dealership['account_type'] ?? 'dealer') === 'private';
$step = max(1, min(3, (int) ($_GET['step'] ?? 1)));

// Eine Privatperson hat nichts auszufuellen: Name, Telefon und E-Mail
// stehen seit der Registrierung fest. Der Profilschritt entfaellt.
if ($isPrivate && $step === 2) {
    redirect('dashboard/onboarding.php?step=3');
}
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $postStep = (int) ($_POST['step'] ?? 0);

    if ($postStep === 2) {
        $v = new Validator($_POST);
        $nameLabel = $isPrivate ? 'Anzeigename' : 'Autohausname';
        $v->required('name', $nameLabel)->maxLength('name', $nameLabel, 190)
          ->maxLength('address', 'Adresse', 255)
          ->maxLength('city', 'Ort', 120)
          ->maxLength('zip', 'PLZ', 20)
          ->maxLength('phone', 'Telefonnummer', 50)
          ->email('email', 'E-Mail')
          ->url('website', 'Website')
          ->maxLength('instagram', 'Instagram', 190)
          ->in('currency', 'Währung', ['CHF', 'EUR'])
          ->in('language', 'Sprache', ['de', 'fr', 'it', 'en']);

        if ($v->fails()) {
            $error = $v->firstError();
            $step = 2;
        } else {
            $data = [
                'name'          => $v->value('name'),
                'address'       => $v->value('address'),
                'zip'           => $v->value('zip'),
                'city'          => $v->value('city'),
                'phone'         => $v->value('phone'),
                'email'         => $v->value('email'),
                'website'       => $v->value('website'),
                'instagram'     => $v->value('instagram'),
                'opening_hours' => mb_substr((string) ($_POST['opening_hours'] ?? ''), 0, 1000),
                'currency'      => $v->value('currency') ?: 'CHF',
                'language'      => $v->value('language') ?: 'de',
                'updated_at'    => Database::now(),
            ];

            // Logo-Upload (optional)
            if (!empty($_FILES['logo']['name'])) {
                try {
                    $result = ImageService::processUpload($_FILES['logo'], 'logos/' . $dealershipId);
                    $data['logo_path'] = $result['card'];
                } catch (\RuntimeException $e) {
                    $error = 'Logo: ' . $e->getMessage();
                    $step = 2;
                }
            }

            if ($error === null) {
                Database::update('dealerships', $dealershipId, $data);
                ActivityLogger::log((int) $currentUser['id'], 'dealership.updated', 'Autohaus-Profil eingerichtet', 'dealership', $dealershipId, $dealershipId);
                redirect('dashboard/onboarding.php?step=3');
            }
        }
    } elseif ($postStep === 3) {
        // Onboarding abschliessen
        Database::update('users', (int) $currentUser['id'], [
            'onboarding_completed_at' => Database::now(),
            'updated_at'              => Database::now(),
        ]);
        ActivityLogger::log((int) $currentUser['id'], 'user.onboarding_completed', 'Onboarding abgeschlossen', 'user', (int) $currentUser['id'], $dealershipId);
        Session::flash('success', t('onboarding.done'));
        redirect('dashboard/');
    }
}

$allChannels = ChannelRegistry::overview($dealershipId);
// Im Onboarding nur die wichtigsten Kanäle zeigen
$onboardingChannels = array_intersect_key($allChannels, array_flip(['autoscout24', 'mobile_de', 'instagram']));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('onboarding.welcome') ?> | RapidCar</title>
<link rel="stylesheet" href="<?= asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('css/dashboard.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset('icons/favicon.svg') ?>">
</head>
<body>
<div class="onboarding-wrap">
    <div class="onboarding-card">
        <div class="onboarding-steps">
            <span class="<?= $step >= 1 ? 'done' : '' ?>"></span>
            <?php if (!$isPrivate): ?><span class="<?= $step >= 2 ? 'done' : '' ?>"></span><?php endif; ?>
            <span class="<?= $step >= 3 ? 'done' : '' ?>"></span>
        </div>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h1 style="font-size:26px;letter-spacing:-.5px"><?= t('onboarding.welcome') ?></h1>
            <p class="text-secondary mt-1 mb-3" style="font-size:15.5px">
                Schön, dass du da bist, <?= e($currentUser['first_name']) ?>!<br>
                <?= t('onboarding.welcome_text') ?>
            </p>
            <a class="btn btn-accent btn-lg btn-block" href="?step=<?= $isPrivate ? 3 : 2 ?>"><?= t('onboarding.start') ?></a>

        <?php elseif ($step === 2): ?>
            <h1 style="font-size:22px"><?= $isPrivate ? 'Dein Profil' : t('onboarding.profile_title') ?></h1>
            <p class="text-secondary mt-1 mb-3"><?= $isPrivate
                ? 'Diese Angaben erscheinen in deinen Inseraten. Alles lässt sich später ändern.'
                : t('onboarding.profile_lead') ?></p>
            <form method="post" action="?step=2" enctype="multipart/form-data" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="step" value="2">
                <div class="form-group">
                    <label class="form-label"><?= $isPrivate ? 'Anzeigename' : t('auth.dealership_name') ?></label>
                    <input class="form-control" type="text" name="name" value="<?= e($dealership['name'] ?? '') ?>" required>
                </div>
                <?php if (!$isPrivate): ?>
                <div class="form-group">
                    <label class="form-label">Logo <span class="optional">(optional, JPG/PNG)</span></label>
                    <input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label"><?= t('settings.address') ?></label>
                    <input class="form-control" type="text" name="address" value="<?= e($dealership['address'] ?? '') ?>" placeholder="Strasse Nr.">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('settings.zip') ?></label>
                        <input class="form-control" type="text" name="zip" value="<?= e($dealership['zip'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('settings.city') ?></label>
                        <input class="form-control" type="text" name="city" value="<?= e($dealership['city'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('auth.phone') ?></label>
                        <input class="form-control" type="tel" name="phone" value="<?= e($dealership['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('auth.email') ?></label>
                        <input class="form-control" type="email" name="email" value="<?= e($dealership['email'] ?? '') ?>">
                    </div>
                </div>
                <?php if (!$isPrivate): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Website <span class="optional">(optional)</span></label>
                        <input class="form-control" type="url" name="website" value="<?= e($dealership['website'] ?? '') ?>" placeholder="https://">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Instagram <span class="optional">(optional)</span></label>
                        <input class="form-control" type="text" name="instagram" value="<?= e($dealership['instagram'] ?? '') ?>" placeholder="@deinautohaus">
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$isPrivate): ?>
                <div class="form-group">
                    <label class="form-label">Öffnungszeiten <span class="optional">(optional)</span></label>
                    <textarea class="form-control" name="opening_hours" rows="2" placeholder="Mo bis Fr 08:00 bis 18:00&#10;Sa 09:00 bis 16:00"><?= e($dealership['opening_hours'] ?? '') ?></textarea>
                </div>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('settings.currency') ?></label>
                        <select class="form-control" name="currency">
                            <option value="CHF" <?= ($dealership['currency'] ?? 'CHF') === 'CHF' ? 'selected' : '' ?>>CHF</option>
                            <option value="EUR" <?= ($dealership['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('common.language') ?></label>
                        <select class="form-control" name="language">
                            <?php foreach (\App\Core\Lang::AVAILABLE as $code => $label): ?>
                                <option value="<?= $code ?>" <?= ($dealership['language'] ?? 'de') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn btn-accent btn-lg btn-block" type="submit"><?= t('onboarding.save_continue') ?></button>
            </form>

        <?php else: ?>
            <h1 style="font-size:22px"><?= t('onboarding.channels_title') ?></h1>
            <p class="text-secondary mt-1 mb-3"><?= t('onboarding.channels_lead') ?></p>

            <div style="display:flex;flex-direction:column;gap:10px" class="mb-3">
                <?php foreach ($onboardingChannels as $channel): ?>
                    <div class="integration-card">
                        <div class="logo-box"><?= icon($channel['icon'], 18) ?></div>
                        <div class="body">
                            <h3><?= e($channel['name']) ?></h3>
                            <div class="status">
                                <?php if ($channel['status'] === 'connected'): ?>
                                    <span class="status-dot green"></span> <?= t('channels.status.connected') ?>
                                <?php elseif ($channel['status'] === 'not_configured'): ?>
                                    <span class="status-dot gray"></span> <?= t('channels.status.not_configured') ?>
                                <?php else: ?>
                                    <span class="status-dot yellow"></span> <?= t('channels.status.disconnected') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($channel['status'] === 'disconnected'): ?>
                            <a class="btn btn-primary btn-sm" href="<?= base_url('api/channels/connect.php?channel=' . urlencode($channel['key'])) ?>">
                                <?= t('channels.connect') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-info">
                <?= icon('info', 16) ?>
                <span><?= t('channels.prepared') ?></span>
            </div>

            <form method="post" action="?step=3">
                <?= Csrf::field() ?>
                <input type="hidden" name="step" value="3">
                <button class="btn btn-accent btn-lg btn-block" type="submit"><?= t('onboarding.finish') ?></button>
            </form>
            <p class="text-center text-sm text-muted mt-2"><?= t('onboarding.skip_hint') ?></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
