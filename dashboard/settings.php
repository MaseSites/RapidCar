<?php
/**
 * Einstellungen: Autohaus-Profil (§15), Integrationen (§16/§17/§39),
 * Datenexport & Löschung (§69).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Auth\AuthService;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;
use App\Integration\AutoScoutService;
use App\Integration\InstagramService;
use App\Service\ActivityLogger;
use App\Service\ImageService;

$dealershipId = require_dealership();
$dealership = Database::fetch('SELECT * FROM dealerships WHERE id = :id', ['id' => $dealershipId]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_style') {
        $tone = (string) ($_POST['listing_tone'] ?? '');
        $titleStyle = (string) ($_POST['listing_title_style'] ?? '');
        Database::update('dealerships', $dealershipId, [
            'listing_tone'   => isset(App\Service\ListingStyle::TONES[$tone]) ? $tone : App\Service\ListingStyle::DEFAULT_TONE,
            'listing_sample' => mb_substr(trim((string) ($_POST['listing_sample'] ?? '')), 0, 3000) ?: null,
            'listing_title_style' => isset(App\Service\ListingStyle::TITLE_STYLES[$titleStyle])
                ? $titleStyle
                : App\Service\ListingStyle::DEFAULT_TITLE_STYLE,
            'listing_title_sample' => mb_substr(trim((string) ($_POST['listing_title_sample'] ?? '')), 0, 160) ?: null,
            'updated_at'     => Database::now(),
        ]);
        Session::flash('success', t('settings.style_saved'));
        redirect('dashboard/settings.php#style');
    }

    if ($action === 'save_dealership') {
        if (!AuthService::isDealerAdmin() && !AuthService::isSuperAdmin()) {
            Session::flash('danger', t('demo.readonly'));
            redirect('dashboard/settings.php');
        }
        $v = new Validator($_POST);
        $v->required('name', 'Autohausname')->maxLength('name', 'Autohausname', 190)
          ->in('currency', 'Währung', ['CHF', 'EUR'])
          ->in('language', 'Sprache', ['de', 'fr', 'it', 'en']);

        if ($v->fails()) {
            Session::flash('danger', (string) $v->firstError());
        } else {
            // Nur Angaben, die auch irgendwo erscheinen: Name, Ort und
            // Telefon stehen in der Inseratsvorschau, das Logo auf den
            // Social-Media-Beiträgen, Währung und Sprache steuern die Ausgabe.
            $data = [
                'name'       => $v->value('name'),
                'zip'        => mb_substr($v->value('zip'), 0, 20),
                'city'       => mb_substr($v->value('city'), 0, 120),
                'phone'      => mb_substr($v->value('phone'), 0, 50),
                'currency'   => $v->value('currency') ?: 'CHF',
                'language'   => $v->value('language') ?: 'de',
                'updated_at' => Database::now(),
            ];
            if (!empty($_FILES['logo']['name'])) {
                try {
                    $result = ImageService::processUpload($_FILES['logo'], 'logos/' . $dealershipId);
                    $data['logo_path'] = $result['card'];
                } catch (\RuntimeException $e) {
                    Session::flash('danger', 'Logo: ' . $e->getMessage());
                    redirect('dashboard/settings.php');
                }
            }
            Database::update('dealerships', $dealershipId, $data);
            ActivityLogger::log((int) $currentUser['id'], 'dealership.updated', 'Autohaus-Profil aktualisiert', 'dealership', $dealershipId, $dealershipId);
            Session::flash('success', t('settings.saved'));
        }
        redirect('dashboard/settings.php');
    }

    if ($action === 'export_data') {
        // Datenexport (§69): alle Daten des Autohauses als JSON
        $export = [
            'exported_at' => date('c'),
            'dealership'  => $dealership,
            'vehicles'    => Database::fetchAll('SELECT * FROM vehicles WHERE dealership_id = :d', ['d' => $dealershipId]),
            'listings'    => Database::fetchAll('SELECT * FROM listings WHERE dealership_id = :d', ['d' => $dealershipId]),
            'leads'       => Database::fetchAll('SELECT * FROM leads WHERE dealership_id = :d', ['d' => $dealershipId]),
            'social_posts' => Database::fetchAll('SELECT * FROM social_posts WHERE dealership_id = :d', ['d' => $dealershipId]),
            'tasks'       => Database::fetchAll('SELECT * FROM tasks WHERE dealership_id = :d', ['d' => $dealershipId]),
        ];
        ActivityLogger::log((int) $currentUser['id'], 'dealership.data_exported', 'Datenexport erstellt', 'dealership', $dealershipId, $dealershipId);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rapidcar-export-' . date('Y-m-d') . '.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$autoscout = AutoScoutService::status($dealershipId);
$autoscoutRow = AutoScoutService::integrationRow($dealershipId);
$instagram = InstagramService::status($dealershipId);

$pageTitle = t('sidebar.settings');
$activeNav = 'settings';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head"><h1><?= t('settings.title') ?></h1></div>

<!-- ============================================ Autohaus-Profil (§15) -->
<div class="card mb-3" id="dealership">
    <div class="card-header"><h2><?= t('settings.dealership') ?></h2></div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-2"><?= t('settings.dealership_lead') ?></p>
        <form method="post" enctype="multipart/form-data">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="save_dealership">
            <div class="flex-center gap-2 mb-2">
                <?php if (!empty($dealership['logo_path'])): ?>
                    <img src="<?= e(upload_url((string) $dealership['logo_path'])) ?>" alt="Logo" style="height:52px;border-radius:10px">
                <?php endif; ?>
                <div class="form-group" style="margin:0;flex:1">
                    <label class="form-label"><?= t('settings.logo') ?></label>
                    <input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= t('auth.dealership_name') ?></label>
                    <input class="form-control" type="text" name="name" value="<?= e($dealership['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('auth.phone') ?></label>
                    <input class="form-control" type="tel" name="phone" value="<?= e($dealership['phone'] ?? '') ?>">
                </div>
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
                    <div class="form-hint"><?= t('settings.language_hint') ?></div>
                </div>
            </div>
            <button class="btn btn-primary" type="submit"><?= t('common.save') ?></button>
        </form>
    </div>
</div>

<!-- ==================================== Schreibstil der Inserate -->
<div class="card mb-3" id="style">
    <div class="card-header"><h2><?= t('settings.style') ?></h2></div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-2"><?= t('settings.style_lead') ?></p>
        <form method="post">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="save_style">

            <div class="form-group">
                <label class="form-label"><?= t('settings.title_style') ?></label>
                <div class="tone-grid">
                    <?php $currentTitleStyle = App\Service\ListingStyle::titleStyleKey($dealershipId); ?>
                    <?php foreach (App\Service\ListingStyle::TITLE_STYLES as $styleKey => $style): ?>
                        <label class="tone-option <?= $styleKey === $currentTitleStyle ? 'is-active' : '' ?>">
                            <input type="radio" name="listing_title_style" value="<?= e($styleKey) ?>"
                                   <?= $styleKey === $currentTitleStyle ? 'checked' : '' ?>>
                            <span class="tone-name"><?= e($style['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="text-xs text-muted mt-1"><?= t('settings.title_style_hint') ?></div>
            </div>

            <div class="form-group">
                <label class="form-label"><?= t('settings.title_sample') ?></label>
                <input class="form-control" type="text" name="listing_title_sample" maxlength="160"
                       placeholder="<?= e(t('settings.title_sample_placeholder')) ?>"
                       value="<?= e(App\Service\ListingStyle::titleSample($dealershipId)) ?>">
                <div class="text-xs text-muted mt-1"><?= t('settings.title_sample_hint') ?></div>
            </div>

            <div class="form-group">
                <label class="form-label"><?= t('settings.style_tone') ?></label>
                <div class="tone-grid">
                    <?php $currentTone = App\Service\ListingStyle::toneKey($dealershipId); ?>
                    <?php foreach (App\Service\ListingStyle::TONES as $toneKey => $tone): ?>
                        <label class="tone-option <?= $toneKey === $currentTone ? 'is-active' : '' ?>">
                            <input type="radio" name="listing_tone" value="<?= e($toneKey) ?>"
                                   <?= $toneKey === $currentTone ? 'checked' : '' ?>>
                            <span class="tone-name"><?= e($tone['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?= t('settings.style_sample') ?></label>
                <textarea class="form-control" name="listing_sample" rows="7"
                          placeholder="<?= e(t('settings.style_sample_placeholder')) ?>"><?= e(App\Service\ListingStyle::sample($dealershipId)) ?></textarea>
                <div class="text-xs text-muted mt-1"><?= t('settings.style_sample_hint') ?></div>
            </div>

            <button class="btn btn-primary" type="submit"><?= t('common.save') ?></button>
        </form>
    </div>
</div>

<!-- ============================================ Kanäle -->
<div class="card mb-3" id="integrations">
    <div class="card-header">
        <h2><?= t("channels.title") ?></h2>
        <a class="btn btn-secondary btn-sm" href="<?= base_url("dashboard/channels.php") ?>"><?= t("channels.title") ?></a>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-2"><?= t("channels.lead") ?></p>
        <div class="flex gap-1" style="flex-wrap:wrap">
            <?php foreach (\App\Integration\ChannelRegistry::overview($dealershipId) as $channel): ?>
                <span class="badge <?= $channel["status"] === "connected" ? "badge-success" : "badge-neutral" ?>">
                    <span class="status-dot <?= $channel["status"] === "connected" ? "green" : ($channel["status"] === "not_configured" ? "gray" : "yellow") ?>"></span>
                    <?= e($channel["name"]) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============================================ Daten und Datenschutz -->
<div class="card mb-3">
    <div class="card-header"><h2><?= t('settings.privacy') ?></h2></div>
    <div class="card-body flex-between" style="flex-wrap:wrap;gap:14px">
        <div>
            <div class="fw-600"><?= t('settings.export') ?></div>
            <div class="text-sm text-muted"><?= t('settings.export_text') ?></div>
        </div>
        <form method="post">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="export_data">
            <button class="btn btn-secondary" type="submit"><?= icon('download', 15) ?> <?= t('settings.export_button') ?></button>
        </form>
    </div>
    <div class="card-body flex-between" style="border-top:1px solid var(--border);flex-wrap:wrap;gap:14px">
        <div>
            <div class="fw-600"><?= t('settings.delete_account') ?></div>
            <div class="text-sm text-muted"><?= t('settings.delete_text') ?></div>
        </div>
        <a class="btn btn-secondary" href="<?= base_url('contact.php') ?>"><?= t('nav.contact') ?></a>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
