<?php
/**
 * Admin-Einstellungen (§54): KI-Modus (Mock/Live) und Plattform-Infos.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Service\ActivityLogger;
use App\Service\SettingsService;

require_super_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'spyne_scene_add') {
        $sceneId = trim((string) ($_POST['scene_id'] ?? ''));
        $sceneLabel = trim((string) ($_POST['scene_label'] ?? ''));
        if ($sceneId === '' || !preg_match('/^[0-9a-zA-Z_-]{1,40}$/', $sceneId)) {
            Session::flash('danger', 'Die Kennung darf nur Zahlen und Buchstaben enthalten.');
        } else {
            $scenes = json_decode((string) SettingsService::get('spyne_scenes'), true);
            $scenes = is_array($scenes) ? $scenes : [];
            $scenes[$sceneId] = $sceneLabel !== '' ? $sceneLabel : $sceneId;
            SettingsService::set('spyne_scenes', (string) json_encode($scenes));
            ActivityLogger::log((int) $currentUser['id'], 'admin.spyne_scene_added', 'Spyne-Hintergrund hinzugefuegt: ' . $sceneId);
            Session::flash('success', 'Hintergrund gespeichert.');
        }
        redirect('admin/settings.php');
    }

    if ($action === 'spyne_scene_remove') {
        $sceneId = trim((string) ($_POST['scene_id'] ?? ''));
        $scenes = json_decode((string) SettingsService::get('spyne_scenes'), true);
        $scenes = is_array($scenes) ? $scenes : [];
        unset($scenes[$sceneId]);
        SettingsService::set('spyne_scenes', (string) json_encode($scenes));
        ActivityLogger::log((int) $currentUser['id'], 'admin.spyne_scene_removed', 'Spyne-Hintergrund entfernt: ' . $sceneId);
        Session::flash('success', 'Hintergrund entfernt.');
        redirect('admin/settings.php');
    }

    if ($action === 'ai_mode') {
        $mode = ($_POST['ai_mode'] ?? 'mock') === 'live' ? 'live' : 'mock';

        if ($mode === 'live' && (string) Config::get('ai.api_key', '') === '') {
            Session::flash('warning', 'Live-Modus nicht aktiviert: In der Konfiguration ist kein AI_API_KEY hinterlegt. Der Mock-Modus bleibt aktiv (§72: keine vorgetäuschten Funktionen).');
        } else {
            SettingsService::set('ai_mode', $mode);
            ActivityLogger::log((int) $currentUser['id'], 'admin.ai_mode_changed', 'KI-Modus geändert zu: ' . $mode);
            Session::flash('success', 'KI-Modus: ' . ($mode === 'live' ? 'Live' : 'Mock') . '.');
        }
    }
    redirect('admin/settings.php');
}

$aiMode = SettingsService::aiMode();
$aiKeyConfigured = \App\AI\OpenAiProvider::isConfigured();
$aiUrlConfigured = (string) Config::get('ai.api_url', '') !== '';
$aiLiveReady = \App\AI\AIService::isLiveReady();
$aiModel = \App\AI\OpenAiProvider::model();

$pageTitle = 'Einstellungen';
$activeNav = 'settings';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Spyne-Hintergründe</h2></div>
        <div class="card-body">
            <?php if (!\App\Integration\SpyneService::isConfigured()): ?>
                <p class="text-sm text-muted">Spyne ist nicht eingerichtet (background.api_key in der Konfiguration).</p>
            <?php else: ?>
                <p class="text-sm text-secondary mb-2">
                    Diese Hintergründe stehen beim Fotos-Freistellen zur Wahl. Wichtig:
                    Es funktionieren nur Kennungen, die deinem Spyne-Konto zugeordnet sind.
                    Du findest sie in der Spyne-Konsole (console.spyne.ai) bei den
                    Hintergründen, oder du bekommst sie von deinem Spyne-Ansprechpartner.
                </p>
                <?php $spyneScenes = \App\Integration\SpyneService::backgrounds(); ?>
                <div class="mb-2">
                    <?php foreach ($spyneScenes as $sceneId => $sceneLabel): ?>
                        <form method="post" style="display:inline-flex;align-items:center;gap:6px;margin:0 8px 8px 0;padding:6px 10px;border:1px solid var(--border);border-radius:999px">
                            <?= App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="spyne_scene_remove">
                            <input type="hidden" name="scene_id" value="<?= e((string) $sceneId) ?>">
                            <span class="text-sm fw-600"><?= e($sceneLabel) ?></span>
                            <span class="text-xs text-muted"><?= e((string) $sceneId) ?></span>
                            <button class="icon-btn" type="submit" title="Entfernen" style="width:22px;height:22px"><?= icon('x', 12) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="flex gap-1" style="flex-wrap:wrap;align-items:flex-end">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="spyne_scene_add">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Kennung (von Spyne)</label>
                        <input class="form-control" type="text" name="scene_id" placeholder="z.B. 923" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Anzeigename</label>
                        <input class="form-control" type="text" name="scene_label" placeholder="z.B. Studio hell">
                    </div>
                    <button class="btn btn-primary" type="submit"><?= icon('plus', 15) ?> Hinzufügen</button>
                </form>
            <?php endif; ?>
        </div>
</div>

<div class="card mb-3">
        <div class="card-header"><h2>KI-Modus (§54)</h2></div>
        <div class="card-body">
            <p class="text-secondary mb-2">
                Im <strong>Mock-Modus</strong> liefern alle KI-Dienste regelbasierte bzw. gekennzeichnete
                Demo-Ergebnisse. Im <strong>Live-Modus</strong> wird die konfigurierte KI-API verwendet.
            </p>
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="ai_mode">
                <label class="form-check mb-1">
                    <input type="radio" name="ai_mode" value="mock" <?= $aiMode === 'mock' ? 'checked' : '' ?>>
                    <span><strong>Mock</strong>: Testantworten, klar als Demo gekennzeichnet</span>
                </label>
                <label class="form-check mb-2">
                    <input type="radio" name="ai_mode" value="live" <?= $aiMode === 'live' ? 'checked' : '' ?> <?= $aiKeyConfigured ? '' : 'disabled' ?>>
                    <span><strong>Live</strong>: Bildanalyse und Texte über OpenAI
                        <?php if ($aiKeyConfigured): ?>
                            <br><span class="text-muted text-sm"><?= t('ai.model.active', ['model' => e($aiModel)]) ?></span>
                        <?php else: ?>
                            <br><span class="text-muted text-sm">Nicht verfügbar: In der Konfiguration ist kein <code>ai.api_key</code> hinterlegt.</span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php if ($aiMode === 'live' && !$aiLiveReady): ?>
                    <div class="alert alert-warning"><?= icon('alert', 16) ?> <span><?= t('ai.live_not_ready') ?></span></div>
                <?php endif; ?>
                <button class="btn btn-primary" type="submit"><?= t('common.save') ?></button>

                <div class="alert alert-info mt-2" style="margin-bottom:0">
                    <?= icon('info', 16) ?>
                    <div class="text-sm">
                        Der Schlüssel wird ausschliesslich serverseitig in <code>config/config.php</code>
                        hinterlegt und nie im Browser verwendet:
                        <div class="mt-1"><code>'ai' =&gt; ['api_key' =&gt; 'sk-...', 'model' =&gt; '<?= e(\App\AI\OpenAiProvider::DEFAULT_MODEL) ?>']</code></div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Konfigurationsübersicht</h2></div>
        <div class="card-body">
            <p class="text-secondary text-sm mb-2">
                Diese Werte werden serverseitig in <code>/config/config.php</code> (oder <code>.env</code>) gepflegt,
                niemals im Frontend (§55). Hier nur der Status:
            </p>
            <table class="table">
                <tr>
                    <td>KI-API-URL</td>
                    <td><?= $aiUrlConfigured ? '<span class="badge badge-success">Konfiguriert</span>' : '<span class="badge badge-neutral">Fehlt</span>' ?></td>
                </tr>
                <tr>
                    <td>KI-API-Key</td>
                    <td><?= $aiKeyConfigured ? '<span class="badge badge-success">Konfiguriert</span>' : '<span class="badge badge-neutral">Fehlt</span>' ?></td>
                </tr>
                <tr>
                    <td>AutoScout24 Plattform-Zugang</td>
                    <td><?= \App\Integration\AutoScoutService::hasPlatformCredentials()
                        ? '<span class="badge badge-success">Hinterlegt</span>'
                        : '<span class="badge badge-neutral">Ohne, Händler nutzen eigene Zugangsdaten</span>' ?></td>
                </tr>
                <tr>
                    <td>Instagram-Zugangsdaten</td>
                    <td><?= \App\Integration\InstagramService::isConfigured() ? '<span class="badge badge-success">Konfiguriert</span>' : '<span class="badge badge-neutral">Fehlen</span>' ?></td>
                </tr>
                <tr>
                    <td>Mail-Treiber</td>
                    <td><span class="badge badge-neutral"><?= e((string) Config::get('mail.driver', 'log')) ?></span></td>
                </tr>
                <tr>
                    <td>Datenbank</td>
                    <td><span class="badge badge-neutral"><?= e(Database::driver()) ?></span></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
