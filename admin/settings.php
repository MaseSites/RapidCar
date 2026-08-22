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
    if ($action === 'spyne_options') {
        $plate = (string) ($_POST['spyne_plate'] ?? 'off');
        if (!in_array($plate, ['off', 'white', 'logo'], true)) {
            $plate = 'off';
        }
        SettingsService::set('spyne_plate', $plate);
        $bannerUrl = trim((string) ($_POST['spyne_banner_url'] ?? ''));
        if ($bannerUrl !== '' && !preg_match('#^https://#i', $bannerUrl)) {
            Session::flash('danger', 'Die Banner-Adresse muss mit https:// beginnen.');
            redirect('admin/settings.php');
        }
        SettingsService::set('spyne_banner_url', $bannerUrl);
        ActivityLogger::log((int) $currentUser['id'], 'admin.spyne_options', 'Spyne-Optionen gespeichert');
        Session::flash('success', 'Spyne-Optionen gespeichert.');
        redirect('admin/settings.php');
    }

    if ($action === 'spyne_scene_bulk') {
        $bulkTheme = trim((string) ($_POST['scene_theme'] ?? ''));
        $lines = preg_split('/\r?\n/', (string) ($_POST['scene_bulk'] ?? '')) ?: [];
        $scenes = json_decode((string) SettingsService::get('spyne_scenes'), true);
        $scenes = is_array($scenes) ? $scenes : [];
        $added = 0;
        foreach ($lines as $line) {
            // Erlaubt: "12345 = Name", "12345: Name", "12345 Name" oder nur "12345"
            if (!preg_match('/^\s*([0-9A-Za-z_-]{1,40})\s*(?:[=:,;\t]|\s)?\s*(.*)$/', trim($line), $m)) {
                continue;
            }
            $sceneId = trim($m[1]);
            if ($sceneId === '') {
                continue;
            }
            $rest = trim($m[2]);
            // Optionale Vorschau-Adresse als letzter Teil: "Kennung = Name = https://bild"
            $preview = '';
            if (preg_match('#^(.*?)[=:,;\t]\s*(https://\S+)\s*$#', $rest, $pm)) {
                $rest = trim($pm[1]);
                $preview = trim($pm[2]);
            }
            $scenes[$sceneId] = [
                'label'   => $rest !== '' ? $rest : $sceneId,
                'preview' => $preview,
                'theme'   => $bulkTheme,
            ];
            $added++;
        }
        if ($added > 0) {
            SettingsService::set('spyne_scenes', (string) json_encode($scenes));
            ActivityLogger::log((int) $currentUser['id'], 'admin.spyne_scene_bulk', $added . ' Spyne-Hintergruende uebernommen');
            Session::flash('success', $added . ' Hintergründe übernommen.');
        } else {
            Session::flash('danger', 'Keine gültigen Zeilen gefunden. Format: Kennung = Name (eine je Zeile).');
        }
        redirect('admin/settings.php');
    }

    if ($action === 'spyne_scene_add') {
        $sceneId = trim((string) ($_POST['scene_id'] ?? ''));
        $sceneLabel = trim((string) ($_POST['scene_label'] ?? ''));
        if ($sceneId === '' || !preg_match('/^[0-9a-zA-Z_-]{1,40}$/', $sceneId)) {
            Session::flash('danger', 'Die Kennung darf nur Zahlen und Buchstaben enthalten.');
        } else {
            $scenes = json_decode((string) SettingsService::get('spyne_scenes'), true);
            $scenes = is_array($scenes) ? $scenes : [];
            $scenes[$sceneId] = ['label' => $sceneLabel !== '' ? $sceneLabel : $sceneId, 'preview' => ''];
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

    if ($action === 'as24_platform') {
        // Betreiber-Zugang fuer AutoScout24. Er wird verschluesselt in der
        // Datenbank abgelegt, damit niemand eine Datei auf dem Server
        // anfassen muss. Ein leeres Feld loescht den Zugang.
        $as24User = trim((string) ($_POST['as24_platform_username'] ?? ''));
        $as24Pass = (string) ($_POST['as24_platform_password'] ?? '');

        if ($as24User === '') {
            \App\Integration\AutoScoutService::storePlatformCredentials('', '');
            ActivityLogger::log((int) $currentUser['id'], 'admin.as24_platform_cleared', 'AutoScout24-Plattformzugang entfernt');
            Session::flash('info', 'Der Plattform-Zugang wurde entfernt. Händler verbinden sich wieder mit eigenen Zugangsdaten.');
        } elseif ($as24Pass === '') {
            Session::flash('warning', 'Bitte auch das Passwort eingeben. Ohne Passwort lässt sich der Zugang nicht prüfen.');
        } else {
            // Erst pruefen, dann speichern: ein falscher Zugang wird nie abgelegt.
            try {
                $as24Customers = \App\Integration\AutoScoutService::verifyCredentials($as24User, $as24Pass);
                \App\Integration\AutoScoutService::storePlatformCredentials($as24User, $as24Pass);
                ActivityLogger::log((int) $currentUser['id'], 'admin.as24_platform_set', 'AutoScout24-Plattformzugang hinterlegt');
                Session::flash('success', 'Plattform-Zugang gespeichert. ' . count($as24Customers) . ' Kundennummern sind darüber erreichbar.');
            } catch (\Throwable $e) {
                Session::flash('danger', 'AutoScout24 hat den Zugang abgelehnt: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'mde_platform') {
        // Betreiber-Zugang fuer mobile.de, gleiches Verfahren wie bei
        // AutoScout24: pruefen, dann verschluesselt in der Datenbank ablegen.
        $mdeUser = trim((string) ($_POST['mde_platform_username'] ?? ''));
        $mdePass = (string) ($_POST['mde_platform_password'] ?? '');

        if ($mdeUser === '') {
            \App\Integration\MobileDeService::storePlatformCredentials('', '');
            ActivityLogger::log((int) $currentUser['id'], 'admin.mde_platform_cleared', 'mobile.de-Betreiberzugang entfernt');
            Session::flash('info', 'Der Betreiber-Zugang wurde entfernt. Händler verbinden sich wieder mit eigenen Zugangsdaten.');
        } elseif ($mdePass === '') {
            Session::flash('warning', 'Bitte auch das Passwort eingeben. Ohne Passwort lässt sich der Zugang nicht prüfen.');
        } else {
            try {
                $mdeSellers = \App\Integration\MobileDeService::verifyCredentials($mdeUser, $mdePass);
                \App\Integration\MobileDeService::storePlatformCredentials($mdeUser, $mdePass);
                ActivityLogger::log((int) $currentUser['id'], 'admin.mde_platform_set', 'mobile.de-Betreiberzugang hinterlegt');
                Session::flash('success', 'Betreiber-Zugang gespeichert. ' . count($mdeSellers) . ' Verkäuferkonten sind darüber erreichbar.');
            } catch (\Throwable $e) {
                Session::flash('danger', 'mobile.de hat den Zugang abgelehnt: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'ricardo_partner') {
        // Partnerschluessel von Ricardo. Er gehoert dem Betreiber, nicht dem
        // einzelnen Haendler, und wird verschluesselt abgelegt.
        $ricKey = trim((string) ($_POST['ricardo_partner_key'] ?? ''));
        $ricSecret = (string) ($_POST['ricardo_partner_secret'] ?? '');
        $ricCategory = trim((string) ($_POST['ricardo_category_id'] ?? ''));

        if ($ricCategory !== '' && preg_match('/^\d{1,10}$/', $ricCategory) !== 1) {
            Session::flash('warning', 'Die Kategorie muss eine Nummer sein.');
        } else {
            SettingsService::set('ricardo_category_id', $ricCategory);

            if ($ricKey === '') {
                \App\Integration\RicardoService::storePartnerCredentials('', '');
                ActivityLogger::log((int) $currentUser['id'], 'admin.ricardo_partner_cleared', 'Ricardo-Partnerschlüssel entfernt');
                Session::flash('info', 'Der Partnerschlüssel wurde entfernt.');
            } elseif ($ricSecret === '' && !\App\Integration\RicardoService::hasPartnerCredentials()) {
                Session::flash('warning', 'Bitte auch das Partner-Passwort eingeben.');
            } else {
                if ($ricSecret !== '') {
                    \App\Integration\RicardoService::storePartnerCredentials($ricKey, $ricSecret);
                }
                ActivityLogger::log((int) $currentUser['id'], 'admin.ricardo_partner_set', 'Ricardo-Partnerschlüssel hinterlegt');
                Session::flash('success', 'Ricardo-Partnerschlüssel gespeichert.');
            }
        }
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
                <?php $spyneScenes = \App\Integration\SpyneService::scenes(); ?>
                <div class="mb-2">
                    <?php foreach ($spyneScenes as $sceneId => $scene): ?>
                        <?php
                        $previewUrl = $scene['preview'];
                        if ($previewUrl !== '' && !str_starts_with($previewUrl, 'http')) {
                            $previewUrl = upload_url($previewUrl);
                        }
                        ?>
                        <div style="display:inline-flex;align-items:center;gap:8px;margin:0 8px 8px 0;padding:6px 10px;border:1px solid var(--border);border-radius:14px" data-scene-chip="<?= e((string) $sceneId) ?>">
                            <?php if ($previewUrl !== ''): ?>
                                <img src="<?= e($previewUrl) ?>" alt="" data-scene-preview style="width:52px;height:34px;object-fit:cover;border-radius:8px">
                            <?php else: ?>
                                <span data-scene-preview class="text-xs text-muted" style="width:52px;text-align:center">ohne<br>Bild</span>
                            <?php endif; ?>
                            <span>
                                <span class="text-sm fw-600"><?= e($scene['label']) ?></span>
                                <span class="text-xs text-muted"><?= e((string) $sceneId) ?></span>
                            </span>
                            <button class="btn btn-secondary btn-sm" type="button" data-scene-generate="<?= e((string) $sceneId) ?>" title="Setzt ein Beispielauto in diesen Hintergrund (eine Spyne-Verarbeitung)">
                                Vorschau erzeugen
                            </button>
                            <form method="post" style="display:inline">
                                <?= App\Core\Csrf::field() ?>
                                <input type="hidden" name="action" value="spyne_scene_remove">
                                <input type="hidden" name="scene_id" value="<?= e((string) $sceneId) ?>">
                                <button class="icon-btn" type="submit" title="Entfernen" style="width:22px;height:22px"><?= icon('x', 12) ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
                <script>
                (function () {
                    var csrf = document.querySelector('input[name="_csrf"]').value;
                    function call(body) {
                        return fetch('<?= base_url('api/admin/spyne-preview.php') ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify(body)
                        }).then(function (r) { return r.json(); });
                    }
                    document.querySelectorAll('[data-scene-generate]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var scene = btn.dataset.sceneGenerate;
                            btn.disabled = true;
                            btn.textContent = 'Spyne arbeitet...';
                            call({ action: 'start', scene: scene }).then(function (res) {
                                if (!res.success) { btn.textContent = res.error || 'Fehler'; return; }
                                var tries = 0;
                                (function poll() {
                                    if (++tries > 45) { btn.textContent = 'Zeit abgelaufen'; return; }
                                    setTimeout(function () {
                                        call({ action: 'status', scene: scene, job: res.data.job }).then(function (st) {
                                            if (st.success && st.data && st.data.pending) { poll(); return; }
                                            if (!st.success) { btn.textContent = st.error || 'Fehler'; return; }
                                            var chip = document.querySelector('[data-scene-chip="' + scene + '"]');
                                            var holder = chip.querySelector('[data-scene-preview]');
                                            var img = document.createElement('img');
                                            img.src = st.data.preview + '?v=' + Date.now();
                                            img.style.cssText = 'width:52px;height:34px;object-fit:cover;border-radius:8px';
                                            holder.replaceWith(img);
                                            btn.remove();
                                        });
                                    }, 4000);
                                })();
                            });
                        });
                    });
                })();
                </script>
                <?php
                $spynePlate = (string) (\App\Service\SettingsService::get('spyne_plate') ?? 'off');
                $spyneBanner = (string) (\App\Service\SettingsService::get('spyne_banner_url') ?? '');
                ?>
                <form method="post" class="mb-2" style="padding:12px 14px;border:1px solid var(--border);border-radius:12px">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="spyne_options">
                    <div class="form-group">
                        <label class="form-label">Kennzeichen auf den Fotos</label>
                        <label class="form-check"><input type="radio" name="spyne_plate" value="off" <?= $spynePlate === 'off' ? 'checked' : '' ?>> <span>Unverändert lassen</span></label>
                        <label class="form-check"><input type="radio" name="spyne_plate" value="white" <?= $spynePlate === 'white' ? 'checked' : '' ?>> <span>Weiss überdecken</span></label>
                        <label class="form-check"><input type="radio" name="spyne_plate" value="logo" <?= $spynePlate === 'logo' ? 'checked' : '' ?>> <span>Logo des Autohauses aufs Kennzeichen setzen (ohne Logo: weiss)</span></label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Banner auf dem Foto <span class="optional">(optional)</span></label>
                        <input class="form-control" type="url" name="spyne_banner_url" value="<?= e($spyneBanner) ?>" placeholder="https://... (Bildadresse, z.B. ein Logo-Banner)">
                        <div class="form-hint">Spyne legt dieses Bild auf jedes verarbeitete Foto. Leer lassen = kein Banner.</div>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> Optionen speichern</button>
                </form>

                <form method="post" class="mb-2">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="spyne_scene_bulk">
                    <div class="form-group">
                        <label class="form-label">Thema dieser Liste <span class="optional">(optional, z.B. Drehscheibe)</span></label>
                        <input class="form-control" type="text" name="scene_theme" placeholder="Drehscheibe">
                        <div class="form-hint">Hintergründe mit Thema erscheinen in der Auswahl gruppiert, je Thema erst 4 mit "Mehr anzeigen".</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Viele auf einmal übernehmen</label>
                        <textarea class="form-control" name="scene_bulk" rows="4" placeholder="75282 = Studio hell&#10;85879 = Showroom dunkel&#10;91234"></textarea>
                        <div class="form-hint">Eine Kennung je Zeile, wahlweise mit Name und Bildadresse: Kennung = Name = https://bild. Oder nach dem Übernehmen je Eintrag "Vorschau erzeugen" drücken.</div>
                    </div>
                    <button class="btn btn-secondary" type="submit"><?= icon('plus', 15) ?> Liste übernehmen</button>
                </form>

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

<div class="card mb-3" id="ricardopartner">
    <div class="card-header">
        <h2>Ricardo: Partnerschlüssel</h2>
        <?php if (\App\Integration\RicardoService::hasPartnerCredentials()): ?>
            <span class="badge badge-success"><?= icon('check', 13) ?> Hinterlegt</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-2">
            Ricardo vergibt den Schlüssel einmalig an dich als Anbieter, nicht an
            die einzelnen Händler. Damit verbinden sich deine Kunden mit einem
            Klick: sie geben die Verbindung auf ricardo.ch frei, ihr Passwort
            bleibt bei ihnen. Den Zugang beantragst du über den Kundendienst für
            gewerbliche Verkäufer.
        </p>
        <form method="post">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="ricardo_partner">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Partnerschlüssel</label>
                    <input class="form-control" type="text" name="ricardo_partner_key" autocomplete="off"
                           value="<?= e(\App\Integration\RicardoService::partnerKey()) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Partner-Passwort</label>
                    <input class="form-control" type="password" name="ricardo_partner_secret" autocomplete="new-password"
                           placeholder="<?= \App\Integration\RicardoService::hasPartnerCredentials() ? 'Unverändert lassen oder neu eingeben' : '' ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Kategorie für Fahrzeuge</label>
                <input class="form-control" type="text" name="ricardo_category_id" inputmode="numeric"
                       value="<?= e((string) (SettingsService::get('ricardo_category_id') ?? '')) ?>"
                       placeholder="Nummer der Ricardo-Kategorie">
                <p class="form-hint">
                    Ricardo vergibt die Nummern selbst. Sie wird hier eingetragen statt
                    geraten; ohne sie lehnt Ricardo den Artikel ab.
                </p>
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> Speichern</button>
        </form>
    </div>
</div>

<div class="card mb-3" id="mdeplatform">
    <div class="card-header">
        <h2>mobile.de: Betreiber-Zugang</h2>
        <?php if (\App\Integration\MobileDeService::hasPlatformCredentials()): ?>
            <span class="badge badge-success"><?= icon('check', 13) ?> Hinterlegt</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-2">
            mobile.de nennt das einen Transfer Service Provider: ein Zugang, der im
            Namen mehrerer Verkäufer inseriert. Deine Kunden wählen dann nur noch ihr
            Verkäuferkonto, ohne eigenes Passwort. Ohne Zugang meldet sich jeder Kunde
            mit seinen eigenen mobile.de-Daten an.
        </p>
        <form method="post">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="mde_platform">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Benutzername</label>
                    <input class="form-control" type="text" name="mde_platform_username" autocomplete="off"
                           value="<?= e(\App\Integration\MobileDeService::platformUsername()) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Passwort</label>
                    <input class="form-control" type="password" name="mde_platform_password" autocomplete="new-password"
                           placeholder="<?= \App\Integration\MobileDeService::hasPlatformCredentials() ? 'Unverändert lassen oder neu eingeben' : '' ?>">
                </div>
            </div>
            <p class="form-hint" style="margin-top:-4px">
                Der Zugang wird zuerst bei mobile.de geprüft und nur bei Erfolg verschlüsselt gespeichert.
                Benutzername leeren und speichern entfernt ihn wieder.
            </p>
            <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> Speichern und prüfen</button>
        </form>
    </div>
</div>

<div class="card mb-3" id="as24platform">
    <div class="card-header">
        <h2>AutoScout24: Plattform-Zugang</h2>
        <?php if (\App\Integration\AutoScoutService::hasPlatformCredentials()): ?>
            <span class="badge badge-success"><?= icon('check', 13) ?> Hinterlegt</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-2">
            Mit einem Plattform-Zugang verbinden sich deine Kunden mit einem Klick:
            Sie wählen nur noch ihre Kundennummer, ohne eigenes Passwort einzugeben.
            Ohne Zugang meldet sich jeder Kunde mit seinen eigenen AutoScout24-Daten an.
            Beides funktioniert, der Zugang macht es nur bequemer.
        </p>
        <form method="post">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="as24_platform">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Benutzername</label>
                    <input class="form-control" type="text" name="as24_platform_username" autocomplete="off"
                           value="<?= e(\App\Integration\AutoScoutService::platformUsername()) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Passwort</label>
                    <input class="form-control" type="password" name="as24_platform_password" autocomplete="new-password"
                           placeholder="<?= \App\Integration\AutoScoutService::hasPlatformCredentials() ? 'Unverändert lassen oder neu eingeben' : '' ?>">
                </div>
            </div>
            <p class="form-hint" style="margin-top:-4px">
                Der Zugang wird zuerst bei AutoScout24 geprüft und nur bei Erfolg verschlüsselt gespeichert.
                Benutzername leeren und speichern entfernt ihn wieder.
            </p>
            <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> Speichern und prüfen</button>
        </form>
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
                    <td>
                        <?= \App\Integration\AutoScoutService::hasPlatformCredentials()
                            ? '<span class="badge badge-success">Hinterlegt</span>'
                            : '<span class="badge badge-neutral">Ohne, Händler nutzen eigene Zugangsdaten</span>' ?>
                        <a class="text-sm" href="#as24platform" style="margin-left:8px">Ändern</a>
                    </td>
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
