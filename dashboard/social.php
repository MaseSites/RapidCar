<?php
/**
 * Social-Media-Generator (§36–§38):
 * Fahrzeug wählen → beste Bilder → Template → Canvas-Vorschau → Speichern.
 * Veröffentlichen erfordert eine echte Instagram-Verbindung (§39/§72).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\AI\AIImageService;
use App\AI\AISocialService;
use App\Core\Database;
use App\Integration\InstagramService;
use App\Repository\VehicleRepository;

$dealershipId = require_dealership();
$dealership = Database::fetch('SELECT * FROM dealerships WHERE id = :id', ['id' => $dealershipId]);

// ------------------------------------------------------------ Post löschen
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_post') {
    require_once BASE_PATH . '/includes/csrf.php';
    guard_demo_mode();

    $postId = (int) ($_POST['post_id'] ?? 0);
    $post = Database::fetch(
        'SELECT * FROM social_posts WHERE id = :id AND dealership_id = :did',
        ['id' => $postId, 'did' => $dealershipId]
    );
    if ($post === null) {
        App\Core\Session::flash('danger', t('social.post_not_found'));
        redirect('dashboard/social.php');
    }

    // Das erzeugte Bild gehört nur zu diesem Post und wird mitgelöscht.
    $imagePath = (string) ($post['image_path'] ?? '');
    if ($imagePath !== '') {
        @unlink(BASE_PATH . '/uploads/' . ltrim($imagePath, '/'));
    }
    Database::run('DELETE FROM social_posts WHERE id = :id', ['id' => $postId]);

    App\Service\ActivityLogger::log(
        (int) $currentUser['id'],
        'social.post_deleted',
        "Social-Post #{$postId} gelöscht",
        'social_post',
        $postId,
        $dealershipId
    );
    App\Core\Session::flash('success', t('social.post_deleted'));
    redirect('dashboard/social.php');
}

$selectedVehicleId = (int) ($_GET['vehicle'] ?? 0);
$selectedVehicle = $selectedVehicleId > 0 ? VehicleRepository::find($selectedVehicleId, $dealershipId) : null;

$templates = Database::fetchAll(
    'SELECT * FROM social_templates WHERE is_system = 1 OR dealership_id = :did ORDER BY is_system DESC, id',
    ['did' => $dealershipId]
);

$instagramStatus = InstagramService::status($dealershipId);
$instagramTestMode = InstagramService::isTestMode($dealershipId);

$pageTitle = t('sidebar.social');
$activeNav = 'social';
require BASE_PATH . '/includes/layout/dash-header.php';

// ---------------------------------------------------------------------------
if ($selectedVehicle === null):
    // Oben: Inserate ohne veroeffentlichten Post. Unten: veroeffentlichte Posts.
    $vehicles = VehicleRepository::listWithMeta($dealershipId);
    $publishedPosts = Database::fetchAll(
        "SELECT sp.*, v.make, v.model FROM social_posts sp
         LEFT JOIN vehicles v ON v.id = sp.vehicle_id
         WHERE sp.dealership_id = :did AND sp.status = 'published'
         ORDER BY sp.id DESC LIMIT 24",
        ['did' => $dealershipId]
    );
    $publishedVehicleIds = array_map(
        static fn(array $post): int => (int) $post['vehicle_id'],
        $publishedPosts
    );
    $unpostedVehicles = array_filter(
        $vehicles,
        static fn(array $vehicle): bool => (int) $vehicle['image_count'] > 0
            && !in_array((int) $vehicle['id'], $publishedVehicleIds, true)
    );
?>
<div class="page-head">
    <div>
        <h1><?= t('social.title') ?></h1>
        <div class="sub"><?= t('social.lead') ?></div>
    </div>
    <div class="flex-center gap-1">
        <span class="text-sm text-secondary"><?= t('settings.instagram') ?>:</span>
        <?php if ($instagramStatus === 'connected'): ?>
            <span class="badge badge-success"><?= t('integration.connected') ?></span>
        <?php elseif ($instagramStatus === 'not_configured'): ?>
            <span class="badge badge-neutral"><?= t('integration.not_configured') ?></span>
        <?php else: ?>
            <span class="badge badge-warning"><?= t('integration.not_connected') ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Oben: noch ohne Post -->
<div class="card mb-3">
    <div class="card-header"><h2><?= t('social.unposted') ?></h2></div>
    <?php if ($unpostedVehicles === []): ?>
        <div class="empty-state"><p><?= t('social.all_posted') ?></p></div>
    <?php else: ?>
        <div class="card-body">
            <div class="grid-4">
                <?php foreach ($unpostedVehicles as $vehicle): ?>
                    <div class="social-card">
                        <div class="social-card-media">
                            <?php if ($vehicle['thumb'] !== null): ?>
                                <img src="<?= e(upload_url((string) $vehicle['thumb'])) ?>" alt="">
                            <?php else: ?>
                                <span class="social-card-empty"><?= icon('camera', 22) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="social-card-body">
                            <div class="social-card-name"><?= e(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) ?: t('vehicles.unnamed')) ?></div>
                            <div class="social-card-meta"><?= format_price($vehicle['price']) ?></div>
                            <a class="btn btn-primary btn-sm btn-block mt-1" href="<?= base_url('dashboard/social.php?vehicle=' . (int) $vehicle['id']) ?>">
                                <?= icon('instagram', 14) ?> <?= t('social.create_post') ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Unten: veroeffentlichte Posts mit Kennzahlen -->
<div class="card">
    <div class="card-header">
        <h2><?= t('social.posted') ?></h2>
        <?php if ($instagramStatus !== 'connected'): ?>
            <span class="text-xs text-muted"><?= t('social.stats_note') ?></span>
        <?php endif; ?>
    </div>
    <?php if ($publishedPosts === []): ?>
        <div class="empty-state"><p><?= t('social.none_posted') ?></p></div>
    <?php else: ?>
        <div class="card-body">
            <div class="grid-4">
                <?php foreach ($publishedPosts as $post): ?>
                    <div class="social-card is-done">
                        <div class="social-card-media">
                            <?php if ($post['image_path'] !== null): ?>
                                <img src="<?= e(upload_url((string) $post['image_path'])) ?>" alt="">
                            <?php else: ?>
                                <span class="social-card-empty"><?= icon('instagram', 22) ?></span>
                            <?php endif; ?>
                            <?php $postImages = json_decode((string) ($post['image_ids'] ?? '[]'), true); ?>
                            <?php if (is_array($postImages) && count($postImages) > 1): ?>
                                <span class="social-card-badge"><?= icon('image', 12) ?> <?= count($postImages) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="social-card-body">
                            <div class="flex-between" style="gap:8px">
                                <div class="social-card-name"><?= e(trim(($post['make'] ?? '') . ' ' . ($post['model'] ?? '')) ?: 'Post') ?></div>
                                <form method="post" data-confirm="<?= e(t('social.post_delete_confirm')) ?>" style="margin:0">
                                    <?= App\Core\Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                    <button class="btn btn-ghost btn-sm listing-delete" type="submit"
                                            title="<?= e(t('common.delete')) ?>"><?= icon('trash', 14) ?></button>
                                </form>
                            </div>
                            <div class="social-stats">
                                <span title="<?= e(t('social.stat.views')) ?>"><?= icon('eye', 13) ?> <?= $post['stat_views'] !== null ? number_format((int) $post['stat_views'], 0, '.', "'") : '-' ?></span>
                                <span title="<?= e(t('social.stat.likes')) ?>"><?= icon('star', 13) ?> <?= $post['stat_likes'] !== null ? number_format((int) $post['stat_likes'], 0, '.', "'") : '-' ?></span>
                                <span title="<?= e(t('social.stat.comments')) ?>"><?= icon('message', 13) ?> <?= $post['stat_comments'] !== null ? number_format((int) $post['stat_comments'], 0, '.', "'") : '-' ?></span>
                                <span title="<?= e(t('social.stat.saves')) ?>"><?= icon('download', 13) ?> <?= $post['stat_saves'] !== null ? number_format((int) $post['stat_saves'], 0, '.', "'") : '-' ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
// ---------------------------------------------------------------------------
else:
    // Generator für gewähltes Fahrzeug
    $bestImages = AIImageService::bestImages($selectedVehicleId, 8);
    // Faellt die KI aus (etwa abgelehnter Schluessel), stirbt die Seite
    // nicht: der Text bleibt leer und laesst sich von Hand schreiben.
    try {
        $caption = AISocialService::generateCaption($selectedVehicleId);
    } catch (\Throwable $e) {
        \App\Core\Logger::warning('Beitragstext konnte nicht erzeugt werden: ' . $e->getMessage());
        $caption = ['caption' => '', 'mode' => 'manual'];
    }
    $vehicleName = trim(($selectedVehicle['make'] ?? '') . ' ' . ($selectedVehicle['model'] ?? '') . ' ' . ($selectedVehicle['variant'] ?? ''));
?>
<div class="page-head">
    <div>
        <h1><?= t('social.create') ?></h1>
        <div class="sub"><?= e($vehicleName) ?></div>
    </div>
    <a class="btn btn-secondary" href="<?= base_url('dashboard/social.php') ?>"><?= icon('chevron-left', 15) ?> <?= t('common.back') ?></a>
</div>

<div class="grid-2" style="align-items:start">
    <div>
        <div class="card mb-3">
            <div class="card-header">
                <h2><?= t('social.best_images') ?></h2>
                <span class="badge badge-warning" title="Reihenfolge: regelbasierte Bildqualität"><?= t('ai.badge.mock') ?></span>
            </div>
            <div class="card-body">
                <p class="text-sm text-muted mb-2"><?= t('social.best_images_hint') ?></p>

                <!-- Diashow: mehrere Bilder statt eines einzelnen -->
                <div class="post-option">
                    <label class="switch">
                        <input type="checkbox" id="slideshowToggle">
                        <span class="switch-track"></span>
                        <span class="switch-label"><?= t('social.slideshow') ?></span>
                    </label>
                    <div class="text-xs text-muted mt-1" id="slideshowHint"><?= t('social.slideshow_hint') ?></div>
                </div>

                <div class="upload-grid" id="imagePickGrid" style="grid-template-columns:repeat(auto-fill,minmax(100px,1fr))">
                    <?php foreach ($bestImages as $index => $image): ?>
                        <div class="upload-item image-pick <?= $index === 0 ? 'is-main' : '' ?>"
                             data-image-id="<?= (int) $image['id'] ?>"
                             data-card="<?= e(upload_url((string) ($image['card_path'] ?? $image['file_path']))) ?>"
                             style="cursor:pointer">
                            <img src="<?= e(upload_url((string) ($image['thumb_path'] ?? $image['file_path']))) ?>" alt="">
                            <span class="pick-order" hidden></span>
                            <?php if ($image['ai_quality_score'] !== null): ?>
                                <span class="main-tag" style="top:auto;bottom:6px;left:6px;background:rgba(0,0,0,.6)">Q<?= (int) $image['ai_quality_score'] ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h2><?= t('social.template') ?></h2></div>
            <div class="card-body">
                <div class="flex gap-1" style="flex-wrap:wrap">
                    <?php foreach ($templates as $index => $template): ?>
                        <button type="button" class="btn btn-secondary btn-sm template-pick <?= $index === 0 ? 'active' : '' ?>"
                                data-template="<?= e((string) $template['template_key']) ?>"
                                data-config="<?= e((string) $template['config']) ?>">
                            <?= e((string) $template['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= t('social.caption') ?></h2></div>
            <div class="card-body">
                <textarea class="form-control" id="captionInput" rows="7"><?= e($caption['caption']) ?></textarea>
                <div class="text-xs text-muted mt-1">Automatisch erstellt (<?= $caption['mode'] === 'mock' ? 'regelbasiert' : 'KI' ?>), frei anpassbar.</div>
            </div>
        </div>
    </div>

    <div>
        <div class="card card-pad mb-3">
            <h3 class="mb-2" style="font-size:15.5px"><?= t('social.preview') ?></h3>
            <canvas id="postCanvas" width="1080" height="1080"
                    style="width:100%;border-radius:14px;border:1px solid var(--border)"></canvas>

            <!-- Bearbeitet wird im eigenen Fenster: Bild frei verschieben und
                 skalieren (lila Eckpunkte), Texte direkt im Bild tippen. -->
            <dialog id="postEditor" class="post-editor-dialog">
                <div class="flex-between mb-2">
                    <h3 style="font-size:15.5px">Bild bearbeiten</h3>
                    <button class="btn btn-primary btn-sm" type="button" id="editorClose">Fertig</button>
                </div>
                <div class="editor-toolbar">
                    <div class="editor-tool">
                        <span class="editor-tool-label">Schrift</span>
                        <div class="font-picker" id="fontPicker">
                            <button type="button" class="font-picker-btn" id="fontPickerBtn"
                                    aria-haspopup="listbox" aria-expanded="false">
                                <span id="fontPickerLabel">Modern</span>
                                <?= icon('chevron-down', 14) ?>
                            </button>
                            <div class="font-picker-menu" id="fontPickerMenu" role="listbox" hidden>
                                <button type="button" role="option" data-font="sans">Modern</button>
                                <button type="button" role="option" data-font="serif">Klassisch</button>
                                <button type="button" role="option" data-font="condensed">Schmal</button>
                                <button type="button" role="option" data-font="rounded">Rund</button>
                                <button type="button" role="option" data-font="mono">Technisch</button>
                            </div>
                        </div>
                    </div>
                    <div class="editor-tool editor-tool-size">
                        <span class="editor-tool-label">Schriftgrösse</span>
                        <div class="size-slider">
                            <span class="size-a" aria-hidden="true">A</span>
                            <input type="range" id="fontScale" min="70" max="140" value="100" step="5"
                                   aria-label="Schriftgrösse">
                            <span class="size-a size-a-big" aria-hidden="true">A</span>
                        </div>
                    </div>
                    <div class="editor-tool editor-tool-reset">
                        <button class="btn btn-secondary btn-sm" type="button" id="resetImage">
                            <?= icon('refresh', 13) ?> Zurücksetzen
                        </button>
                    </div>
                </div>
                <div class="post-edit-wrap" id="postEditWrap">
                    <canvas id="postEditCanvas" width="1080" height="1080"></canvas>
                    <input type="text" id="inlineTextInput" class="inline-text-input" autocomplete="off" style="display:none">
                    <button type="button" id="inlineTextDelete" class="inline-text-delete" style="display:none"
                            title="Text entfernen"><?= icon('trash', 14) ?></button>
                </div>
                <div class="text-xs text-muted mt-1">
                    Bild ziehen zum Verschieben, lila Eckpunkte ziehen zum Vergrössern.
                    Auf einen Text klicken und direkt tippen.
                </div>
            </dialog>
            <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
                <button class="btn btn-secondary" type="button" id="editImageBtn"><?= icon('image', 15) ?> Bild bearbeiten</button>
                <button class="btn btn-primary" type="button" id="saveBtn"><?= t('common.save') ?></button>
                <?php if (!$hasPlus): ?>
                    <a class="btn btn-secondary is-locked" href="<?= base_url('dashboard/subscription.php') ?>">
                        Veröffentlichen
                        <span class="pro-badge is-shimmer">Pro</span>
                    </a>
                <?php elseif ($instagramStatus === 'connected'): ?>
                    <button class="btn btn-accent" type="button" id="publishBtn">Veröffentlichen</button>
                <?php elseif ($instagramTestMode): ?>
                    <button class="btn btn-accent" type="button" id="publishBtn"
                            title="<?= e(t('social.test_publish_hint')) ?>">
                        <?= t('social.test_publish') ?>
                    </button>
                <?php else: ?>
                    <button class="btn btn-secondary" type="button" disabled
                            title="Instagram ist <?= $instagramStatus === 'not_configured' ? 'nicht konfiguriert' : 'nicht verbunden' ?>. Der Post wird lokal gespeichert.">
                        Veröffentlichen (Instagram <?= $instagramStatus === 'not_configured' ? 'nicht konfiguriert' : 'nicht verbunden' ?>)
                    </button>
                <?php endif; ?>
            </div>
            <div class="text-xs text-muted mt-1">
                <?= $instagramTestMode
                    ? t('social.test_publish_hint')
                    : 'Gespeicherte Posts bleiben lokal, bis eine Instagram-Verbindung eingerichtet ist. Es wird nichts vorgetäuscht.' ?>
            </div>
        </div>
    </div>
</div>

<?php
$jsData = json_encode([
    'vehicleId'  => $selectedVehicleId,
    'name'       => mb_strtoupper($vehicleName),
    'powerHp'    => $selectedVehicle['power_hp'] !== null ? (int) $selectedVehicle['power_hp'] . ' PS' : null,
    'mileage'    => $selectedVehicle['mileage'] !== null ? number_format((float) $selectedVehicle['mileage'], 0, '.', "'") . ' KM' : null,
    'price'      => $selectedVehicle['price'] !== null ? 'CHF ' . number_format((float) $selectedVehicle['price'], 0, '.', "'") : null,
    'dealer'     => (string) ($dealership['name'] ?? ''),
    'logo'       => !empty($dealership['logo_path']) ? upload_url((string) $dealership['logo_path']) : null,
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG);

$pageScripts = <<<HTML
<script>
(function () {
    var data = {$jsData};
    var canvas = document.getElementById('postCanvas');
    var currentImage = null;
    var currentImageId = null;
    var currentTemplate = null;
    var logoImage = null;

    if (data.logo) {
        logoImage = new Image();
        logoImage.crossOrigin = 'anonymous';
        logoImage.src = data.logo;
        logoImage.onload = render;
    }

    // Bild-Auswahl. Ohne Diashow gilt genau ein Bild, mit Diashow eine
    // Reihenfolge; das erste Bild ist immer das, was die Vorschau zeigt.
    var picks = document.querySelectorAll('.image-pick');
    var slideshow = document.getElementById('slideshowToggle');
    var selection = [];

    function paintSelection() {
        picks.forEach(function (el) {
            var id = parseInt(el.dataset.imageId, 10);
            var position = selection.indexOf(id);
            el.classList.toggle('is-main', position === 0);
            el.classList.toggle('is-picked', position > 0);
            var order = el.querySelector('.pick-order');
            if (order) {
                order.hidden = !(slideshow.checked && position >= 0);
                order.textContent = position + 1;
            }
        });
        if (selection.length > 0) {
            var lead = document.querySelector('.image-pick[data-image-id="' + selection[0] + '"]');
            if (lead) { loadImage(lead.dataset.card, selection[0]); }
        }
    }

    picks.forEach(function (el) {
        el.addEventListener('click', function () {
            var id = parseInt(el.dataset.imageId, 10);
            if (!slideshow.checked) {
                selection = [id];
            } else {
                var position = selection.indexOf(id);
                if (position >= 0) {
                    selection.splice(position, 1);
                } else {
                    selection.push(id);
                }
                if (selection.length === 0) { selection = [id]; }
            }
            paintSelection();
        });
    });

    slideshow.addEventListener('change', function () {
        if (!slideshow.checked && selection.length > 1) {
            selection = selection.slice(0, 1);
        }
        paintSelection();
    });

    // Template-Auswahl
    var templateBtns = document.querySelectorAll('.template-pick');
    templateBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            templateBtns.forEach(function (b) { b.classList.remove('btn-primary'); b.classList.add('btn-secondary'); });
            btn.classList.remove('btn-secondary'); btn.classList.add('btn-primary');
            currentTemplate = JSON.parse(btn.dataset.config);
            currentTemplate.key = btn.dataset.template;
            render();
        });
    });

    function loadImage(src, id) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () { currentImage = img; currentImageId = id; render(); };
        img.src = src;
    }

    // Schriftfamilien zur Auswahl. Nur systemseitig vorhandene, damit die
    // Vorschau auf jedem Rechner dem gespeicherten Bild entspricht.
    var FONTS = {
        sans:      'Arial, Helvetica, sans-serif',
        serif:     'Georgia, "Times New Roman", serif',
        condensed: '"Arial Narrow", "Helvetica Neue", Arial, sans-serif',
        rounded:   '"Trebuchet MS", "Segoe UI", Verdana, sans-serif',
        mono:      '"Courier New", Consolas, monospace'
    };

    // Frei aenderbare Texte. Was hier steht, wird gezeichnet.
    var texts = {
        badge:  'NEW IN',
        name:   data.name,
        facts:  [data.powerHp, data.mileage, data.price].filter(Boolean).join('   ·   '),
        dealer: data.dealer
    };
    // Wo die Texte liegen: fuer das Anklicken im Bild.
    var textBoxes = {};
    // Verschiebung jedes Textes gegenueber seinem Platz in der Vorlage.
    var textPos = {};

    function posOf(key) {
        if (!textPos[key]) { textPos[key] = { dx: 0, dy: 0 }; }
        return textPos[key];
    }

    var fontKey = 'sans';
    var fontScale = 1;

    // Freie Transformation des Fotos: Position und Groesse in Bildpunkten
    // der 1080er-Flaeche. null bedeutet: passend einsetzen (cover).
    var imgT = null;

    function ensureTransform() {
        if (!currentImage) { return; }
        if (imgT && imgT.img === currentImage) { return; }
        var base = Math.max(1080 / currentImage.width, 700 / currentImage.height);
        imgT = {
            img: currentImage,
            scale: base,
            x: (1080 - currentImage.width * base) / 2,
            y: (700 - currentImage.height * base) / 2
        };
    }

    var editDialog = document.getElementById('postEditor');
    var editCanvas = document.getElementById('postEditCanvas');
    var editWrap = document.getElementById('postEditWrap');
    var inlineInput = document.getElementById('inlineTextInput');
    var HANDLE = 26;   // Kantenlaenge der Eckpunkte in Bildpunkten

    function fontFamily() {
        // Vorlage gibt die Grundschrift vor, die Auswahl gewinnt.
        if (fontKey === 'sans' && currentTemplate && currentTemplate.font === 'serif') {
            return FONTS.serif;
        }
        return FONTS[fontKey] || FONTS.sans;
    }

    /** Zeichnet den Beitrag auf eine Flaeche; editMode zeigt Rahmen und Eckpunkte. */
    function paintScene(target, editMode) {
        if (!currentTemplate) { return; }
        var c = target.getContext('2d');
        var W = 1080, H = 1080;
        c.clearRect(0, 0, W, H);
        c.fillStyle = currentTemplate.bg || '#111';
        c.fillRect(0, 0, W, H);

        if (currentImage) {
            ensureTransform();
            var iw = currentImage.width * imgT.scale;
            var ih = currentImage.height * imgT.scale;
            c.save();
            c.beginPath();
            c.rect(0, 0, W, 700);
            c.clip();
            c.drawImage(currentImage, imgT.x, imgT.y, iw, ih);
            c.restore();

            var grad = c.createLinearGradient(0, 520, 0, 700);
            grad.addColorStop(0, 'rgba(0,0,0,0)');
            grad.addColorStop(1, currentTemplate.bg || '#111');
            c.fillStyle = grad;
            c.fillRect(0, 520, W, 180);
        }

        var accent = currentTemplate.accent || '#fff';
        var textColor = currentTemplate.text || '#fff';
        var fontName = fontFamily();
        c.textAlign = 'center';
        textBoxes = {};

        function drawText(key, value, weight, size, color, y, spacing, alpha) {
            if (!value) { return; }
            var pos = posOf(key);
            var tx = W / 2 + pos.dx, ty = y + pos.dy;
            var px = Math.round(size * fontScale);
            c.font = weight + ' ' + px + 'px ' + fontName;
            c.letterSpacing = spacing || '0px';
            c.fillStyle = color;
            c.globalAlpha = alpha || 1;
            c.fillText(value, tx, ty, W - 100);
            c.globalAlpha = 1;
            var width = Math.min(W - 100, c.measureText(value).width);
            textBoxes[key] = { x: tx - width / 2, y: ty - px, w: width, h: px * 1.3, size: px, weight: weight, color: color };
            c.letterSpacing = '0px';
        }

        drawText('badge', texts.badge, '700', 34, accent, 790, '8px');
        drawText('name', texts.name, '800', texts.name.length > 24 ? 46 : 58, textColor, 860);
        drawText('facts', texts.facts, '600', 34, accent, 930);
        drawText('dealer', texts.dealer, '500', 26, textColor, 1010, '0px', 0.85);

        if (logoImage && logoImage.complete && logoImage.naturalWidth > 0) {
            var lh = 64, lw = logoImage.width * (lh / logoImage.height);
            c.drawImage(logoImage, W - lw - 36, 36, lw, lh);
        }

        c.fillStyle = accent;
        c.fillRect(W / 2 - 40, 745, 80, 4);

        // ---------------- Bearbeitungsrahmen: lila Kontur mit vier Eckpunkten
        if (editMode && currentImage) {
            var rx = imgT.x, ry = imgT.y;
            var rw = currentImage.width * imgT.scale, rh = currentImage.height * imgT.scale;
            c.save();
            c.strokeStyle = '#7c3aed';
            c.lineWidth = 3;
            c.setLineDash([10, 7]);
            c.strokeRect(rx, ry, rw, rh);
            c.setLineDash([]);
            imageCorners(rx, ry, rw, rh).forEach(function (pt) {
                c.fillStyle = '#7c3aed';
                c.fillRect(pt.x - HANDLE / 2, pt.y - HANDLE / 2, HANDLE, HANDLE);
                c.strokeStyle = '#fff';
                c.lineWidth = 3;
                c.strokeRect(pt.x - HANDLE / 2, pt.y - HANDLE / 2, HANDLE, HANDLE);
            });
            c.restore();
        }
    }

    function imageCorners(x, y, w, h) {
        return [
            { x: x, y: y }, { x: x + w, y: y },
            { x: x, y: y + h }, { x: x + w, y: y + h }
        ];
    }

    function render() {
        paintScene(canvas, false);
        if (editDialog.open) {
            paintScene(editCanvas, true);
        }
    }

    // ------------------------------------------------ Fenster oeffnen/schliessen
    document.getElementById('editImageBtn').addEventListener('click', function () {
        editDialog.showModal();
        render();
    });
    document.getElementById('editorClose').addEventListener('click', function () {
        commitInlineText();
        editDialog.close();
        render();
    });

    // ------------------------------------------------ Mauskoordinaten der Flaeche
    function toCanvasPoint(event) {
        var rect = editCanvas.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) * (1080 / rect.width),
            y: (event.clientY - rect.top) * (1080 / rect.height)
        };
    }

    // ------------------------------------------------ Bild ziehen und skalieren
    var mode = null;         // 'move' | 'scale' | 'text'
    var anchor = null;       // fester Gegenpunkt beim Skalieren
    var grabDX = 0, grabDY = 0;
    var textKey = null;      // welcher Text gerade gezogen wird
    var textMoved = false;
    var textStart = null;

    editCanvas.addEventListener('pointerdown', function (event) {
        commitInlineText();
        var pt = toCanvasPoint(event);

        // 1. Eckpunkte zuerst: Skalieren um den gegenueberliegenden Punkt
        if (currentImage) {
            ensureTransform();
            var rw = currentImage.width * imgT.scale, rh = currentImage.height * imgT.scale;
            var corners = imageCorners(imgT.x, imgT.y, rw, rh);
            for (var k = 0; k < corners.length; k++) {
                if (Math.abs(pt.x - corners[k].x) <= HANDLE && Math.abs(pt.y - corners[k].y) <= HANDLE) {
                    mode = 'scale';
                    anchor = corners[3 - k];   // Gegenueberliegende Ecke bleibt stehen
                    editCanvas.setPointerCapture(event.pointerId);
                    return;
                }
            }
        }

        // 2. Texte: kurzer Klick tippt, Ziehen verschiebt. preventDefault ist
        // noetig, sonst zieht der Klick den Fokus sofort wieder auf die
        // Zeichenflaeche und das Eingabefeld schliesst sich ungewollt.
        for (var key in textBoxes) {
            var box = textBoxes[key];
            if (pt.x >= box.x - 24 && pt.x <= box.x + box.w + 24
                && pt.y >= box.y - 14 && pt.y <= box.y + box.h + 14) {
                event.preventDefault();
                mode = 'text';
                textKey = key;
                textMoved = false;
                textStart = { x: pt.x, y: pt.y, dx: posOf(key).dx, dy: posOf(key).dy };
                editCanvas.setPointerCapture(event.pointerId);
                return;
            }
        }

        // 3. Bildflaeche: verschieben
        if (currentImage) {
            var w = currentImage.width * imgT.scale, h = currentImage.height * imgT.scale;
            if (pt.x >= imgT.x && pt.x <= imgT.x + w && pt.y >= imgT.y && pt.y <= imgT.y + h && pt.y <= 700) {
                mode = 'move';
                grabDX = pt.x - imgT.x;
                grabDY = pt.y - imgT.y;
                editCanvas.style.cursor = 'grabbing';
                editCanvas.setPointerCapture(event.pointerId);
            }
        }
    });

    editCanvas.addEventListener('pointermove', function (event) {
        if (!mode) { return; }
        var pt = toCanvasPoint(event);
        if (mode === 'text') {
            var pos = posOf(textKey);
            pos.dx = textStart.dx + (pt.x - textStart.x);
            pos.dy = textStart.dy + (pt.y - textStart.y);
            if (Math.abs(pt.x - textStart.x) + Math.abs(pt.y - textStart.y) > 6) {
                textMoved = true;
            }
            render();
            return;
        }
        if (!currentImage) { return; }
        if (mode === 'move') {
            imgT.x = pt.x - grabDX;
            imgT.y = pt.y - grabDY;
        } else if (mode === 'scale') {
            // Breite vom festen Gegenpunkt bis zur Maus bestimmt die Groesse
            var newW = Math.max(120, Math.abs(pt.x - anchor.x));
            var newScale = newW / currentImage.width;
            var base = Math.max(1080 / currentImage.width, 700 / currentImage.height);
            newScale = Math.max(base * 0.25, Math.min(base * 6, newScale));
            var newH = currentImage.height * newScale;
            imgT.scale = newScale;
            // Der Gegenpunkt bleibt stehen; die gezogene Ecke folgt der Maus
            imgT.x = pt.x > anchor.x ? anchor.x : anchor.x - currentImage.width * newScale;
            imgT.y = pt.y > anchor.y ? anchor.y : anchor.y - newH;
        }
        render();
    });

    ['pointerup', 'pointercancel'].forEach(function (type) {
        editCanvas.addEventListener(type, function () {
            if (mode === 'text' && !textMoved && textKey !== null) {
                openInlineText(textKey);   // kurzer Klick: direkt tippen
            }
            mode = null;
            textKey = null;
            editCanvas.style.cursor = 'default';
        });
    });

    // ------------------------------------------------ Text direkt im Bild tippen
    var editingKey = null;

    var inlineDelete = document.getElementById('inlineTextDelete');

    function openInlineText(key) {
        var box = textBoxes[key];
        if (!box) { return; }
        editingKey = key;
        var rect = editCanvas.getBoundingClientRect();
        var ratio = rect.width / 1080;
        var left = Math.max(0, (box.x - 30) * ratio);
        var width = Math.min(1040, box.w + 120) * ratio;
        inlineInput.value = texts[key];
        inlineInput.style.display = 'block';
        inlineInput.style.left = left + 'px';
        inlineInput.style.top = (box.y - 6) * ratio + 'px';
        inlineInput.style.width = width + 'px';
        inlineDelete.style.display = 'flex';
        inlineDelete.style.left = Math.min(rect.width - 40, left + width + 8) + 'px';
        inlineDelete.style.top = (box.y - 6) * ratio + 'px';
        inlineInput.style.fontSize = Math.max(13, box.size * ratio * 0.9) + 'px';
        // Fokus erst nach dem laufenden Klick, sonst geht er sofort verloren
        window.setTimeout(function () {
            inlineInput.focus();
            inlineInput.select();
        }, 0);
    }

    function commitInlineText() {
        if (editingKey === null) { return; }
        editingKey = null;
        inlineInput.style.display = 'none';
        inlineDelete.style.display = 'none';
    }

    // Muelleimer: der angeklickte Text verschwindet aus dem Bild
    inlineDelete.addEventListener('pointerdown', function (event) {
        // vor dem Fokusverlust des Eingabefelds zuschlagen
        event.preventDefault();
        if (editingKey !== null) {
            texts[editingKey] = '';
        }
        commitInlineText();
        render();
    });

    inlineInput.addEventListener('input', function () {
        if (editingKey === null) { return; }
        texts[editingKey] = inlineInput.value;
        render();   // der Text aendert sich live im Bild
    });
    inlineInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === 'Escape') {
            commitInlineText();
        }
    });
    inlineInput.addEventListener('blur', commitInlineText);

    // ------------------------------------------------ Werkzeuge
    // Eigenes Dropdown: jeder Eintrag zeigt sich in seiner eigenen Schrift
    var fontPicker = document.getElementById('fontPicker');
    var fontPickerBtn = document.getElementById('fontPickerBtn');
    var fontPickerMenu = document.getElementById('fontPickerMenu');
    var fontPickerLabel = document.getElementById('fontPickerLabel');

    fontPickerMenu.querySelectorAll('[data-font]').forEach(function (option) {
        option.style.fontFamily = FONTS[option.dataset.font] || FONTS.sans;
        option.addEventListener('click', function () {
            fontKey = option.dataset.font;
            fontPickerLabel.textContent = option.textContent;
            fontPickerLabel.style.fontFamily = option.style.fontFamily;
            fontPickerMenu.querySelectorAll('[data-font]').forEach(function (o) {
                o.classList.toggle('is-active', o === option);
            });
            closeFontPicker();
            render();
        });
    });
    fontPickerMenu.querySelector('[data-font="sans"]').classList.add('is-active');

    function closeFontPicker() {
        fontPickerMenu.hidden = true;
        fontPickerBtn.setAttribute('aria-expanded', 'false');
    }
    fontPickerBtn.addEventListener('click', function () {
        var open = fontPickerMenu.hidden;
        fontPickerMenu.hidden = !open;
        fontPickerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    editDialog.addEventListener('pointerdown', function (event) {
        if (!fontPicker.contains(event.target)) {
            closeFontPicker();
        }
    });
    var fontScaleInput = document.getElementById('fontScale');
    function updateSliderFill() {
        var min = parseInt(fontScaleInput.min, 10), max = parseInt(fontScaleInput.max, 10);
        var pct = ((parseInt(fontScaleInput.value, 10) - min) / (max - min)) * 100;
        fontScaleInput.style.setProperty('--fill', pct + '%');
    }
    updateSliderFill();
    fontScaleInput.addEventListener('input', function () {
        fontScale = parseInt(this.value, 10) / 100;
        updateSliderFill();
        render();
    });
    // Alles auf Anfang: Bild, Texte, Positionen, Schrift
    document.getElementById('resetImage').addEventListener('click', function () {
        imgT = null;
        textPos = {};
        texts = {
            badge:  'NEW IN',
            name:   data.name,
            facts:  [data.powerHp, data.mileage, data.price].filter(Boolean).join('   ·   '),
            dealer: data.dealer
        };
        fontKey = 'sans';
        fontScale = 1;
        fontScaleInput.value = 100;
        updateSliderFill();
        fontPickerLabel.textContent = 'Modern';
        fontPickerLabel.style.fontFamily = '';
        fontPickerMenu.querySelectorAll('[data-font]').forEach(function (o) {
            o.classList.toggle('is-active', o.dataset.font === 'sans');
        });
        render();
    });

    window.socialRender = render;


    document.getElementById('saveBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        var imageData = canvas.toDataURL('image/png');
        apiFetch('api/social/save-post.php', {
            method: 'POST',
            body: {
                vehicle_id: data.vehicleId,
                template_key: currentTemplate ? currentTemplate.key : null,
                caption: document.getElementById('captionInput').value,
                image_data: imageData,
                image_ids: selection.length ? selection : (currentImageId ? [currentImageId] : [])
            }
        }).then(function (res) {
            btn.disabled = false;
            if (res.success) {
                showToast('Post lokal gespeichert.', 'success');
                setTimeout(function () { window.location = 'social.php'; }, 900);
            } else {
                showToast(res.error || 'Speichern fehlgeschlagen.', 'danger');
            }
        });
    });

    // Auf Instagram veroeffentlichen: erst speichern, dann ueber die Meta-API posten
    var igPublishBtn = document.getElementById('publishBtn');
    if (igPublishBtn) {
        igPublishBtn.addEventListener('click', function () {
            igPublishBtn.disabled = true;
            var imageData = canvas.toDataURL('image/png');
            apiFetch('api/social/save-post.php', {
                method: 'POST',
                body: {
                    vehicle_id: data.vehicleId,
                    template_key: currentTemplate ? currentTemplate.key : null,
                    caption: document.getElementById('captionInput').value,
                    image_data: imageData,
                    image_ids: selection.length ? selection : (currentImageId ? [currentImageId] : [])
                }
            }).then(function (res) {
                if (!res.success) {
                    igPublishBtn.disabled = false;
                    showToast(res.error || 'Speichern fehlgeschlagen.', 'danger');
                    return;
                }
                apiFetch('api/social/publish-post.php', {
                    method: 'POST',
                    body: { post_id: res.data.post_id }
                }).then(function (pub) {
                    igPublishBtn.disabled = false;
                    if (pub.success) {
                        showToast('Auf Instagram veröffentlicht.', 'success');
                        setTimeout(function () { window.location = 'social.php'; }, 900);
                    } else {
                        showToast(pub.error || 'Veröffentlichung fehlgeschlagen.', 'danger');
                    }
                });
            });
        });
    }


    // Initial: erstes Bild + erstes Template
    var firstPick = document.querySelector('.image-pick');
    var firstTemplate = document.querySelector('.template-pick');
    if (firstTemplate) { firstTemplate.click(); }
    if (firstPick) {
        selection = [parseInt(firstPick.dataset.imageId, 10)];
        paintSelection();
    }
})();
</script>
HTML;
endif;

require BASE_PATH . '/includes/layout/dash-footer.php';
?>
