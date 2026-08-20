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
    $caption = AISocialService::generateCaption($selectedVehicleId);
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
                <span class="badge badge-warning" title="Reihenfolge: regelbasierte Bildqualität (KI im Demo-Modus)"><?= t('ai.badge.mock') ?></span>
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
                <div class="text-xs text-muted mt-1">Automatisch erstellt (<?= $caption['mode'] === 'mock' ? 'regelbasiert, Demo-Modus' : 'KI' ?>), frei anpassbar.</div>
            </div>
        </div>
    </div>

    <div>
        <div class="card card-pad mb-3">
            <div class="flex-between mb-2">
                <h3 style="font-size:15.5px"><?= t('social.preview') ?> (1080x1080)</h3>
                <button class="btn btn-secondary btn-sm" type="button" id="regenerateBtn"><?= icon('refresh', 14) ?> <?= t('social.regenerate') ?></button>
            </div>
            <div class="post-tools">
                <div class="post-tool">
                    <label class="form-label">Schrift</label>
                    <select class="form-control" id="fontSelect">
                        <option value="sans">Modern (serifenlos)</option>
                        <option value="serif">Klassisch (Serifen)</option>
                        <option value="condensed">Schmal</option>
                        <option value="rounded">Rund</option>
                        <option value="mono">Technisch</option>
                    </select>
                </div>
                <div class="post-tool">
                    <label class="form-label">Schriftgrösse</label>
                    <input type="range" id="fontScale" min="70" max="140" value="100" step="5">
                </div>
                <div class="post-tool">
                    <label class="form-label">Bildausschnitt</label>
                    <input type="range" id="imgZoom" min="100" max="250" value="100" step="5">
                    <div class="form-hint">Bild mit der Maus verschieben</div>
                </div>
                <div class="post-tool">
                    <button class="btn btn-secondary btn-sm" type="button" id="resetImage">Ausschnitt zurücksetzen</button>
                </div>
            </div>
            <canvas id="postCanvas" width="1080" height="1080"
                    style="width:100%;border-radius:14px;border:1px solid var(--border);cursor:grab"
                    title="Auf einen Text klicken, um ihn zu ändern. Bild ziehen, um den Ausschnitt zu wählen."></canvas>
            <div class="text-xs text-muted mt-1">
                Auf einen Text im Bild klicken, um ihn zu ändern. Das Foto lässt sich ziehen und mit dem Regler vergrössern.
            </div>
            <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
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
    var ctx = canvas.getContext('2d');
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

    var view = { zoom: 1, offsetX: 0, offsetY: 0 };   // Bildausschnitt
    var fontKey = 'sans';
    var fontScale = 1;

    function fontFamily() {
        // Vorlage gibt die Grundschrift vor, die Auswahl gewinnt.
        if (fontKey === 'sans' && currentTemplate && currentTemplate.font === 'serif') {
            return FONTS.serif;
        }
        return FONTS[fontKey] || FONTS.sans;
    }

    function render() {
        if (!currentTemplate) { return; }
        var W = 1080, H = 1080;
        ctx.clearRect(0, 0, W, H);
        ctx.fillStyle = currentTemplate.bg || '#111';
        ctx.fillRect(0, 0, W, H);

        // Fahrzeugbild mit waehlbarem Ausschnitt
        if (currentImage) {
            var imgH = 700;
            var base = Math.max(W / currentImage.width, imgH / currentImage.height);
            var scale = base * view.zoom;
            var sw = W / scale, sh = imgH / scale;
            // Verschiebung in Bildpunkten, begrenzt auf das Vorhandene
            var maxX = Math.max(0, (currentImage.width - sw) / 2);
            var maxY = Math.max(0, (currentImage.height - sh) / 2);
            var offX = Math.max(-maxX, Math.min(maxX, view.offsetX));
            var offY = Math.max(-maxY, Math.min(maxY, view.offsetY));
            var sx = (currentImage.width - sw) / 2 + offX;
            var sy = (currentImage.height - sh) / 2 + offY;
            ctx.drawImage(currentImage, sx, sy, sw, sh, 0, 0, W, imgH);

            var grad = ctx.createLinearGradient(0, imgH - 180, 0, imgH);
            grad.addColorStop(0, 'rgba(0,0,0,0)');
            grad.addColorStop(1, currentTemplate.bg || '#111');
            ctx.fillStyle = grad;
            ctx.fillRect(0, imgH - 180, W, 180);
        }

        var accent = currentTemplate.accent || '#fff';
        var textColor = currentTemplate.text || '#fff';
        var fontName = fontFamily();
        ctx.textAlign = 'center';
        textBoxes = {};

        /** Zeichnet einen Text und merkt sich seine Flaeche zum Anklicken. */
        function drawText(key, value, weight, size, color, y, spacing, alpha) {
            if (!value) { return; }
            var px = Math.round(size * fontScale);
            ctx.font = weight + ' ' + px + 'px ' + fontName;
            ctx.letterSpacing = spacing || '0px';
            ctx.fillStyle = color;
            ctx.globalAlpha = alpha || 1;
            ctx.fillText(value, W / 2, y, W - 100);
            ctx.globalAlpha = 1;
            var width = Math.min(W - 100, ctx.measureText(value).width);
            textBoxes[key] = { x: (W - width) / 2, y: y - px, w: width, h: px * 1.3 };
            ctx.letterSpacing = '0px';
        }

        drawText('badge', texts.badge, '700', 34, accent, 790, '8px');
        drawText('name', texts.name, '800', texts.name.length > 24 ? 46 : 58, textColor, 860);
        drawText('facts', texts.facts, '600', 34, accent, 930);
        drawText('dealer', texts.dealer, '500', 26, textColor, 1010, '0px', 0.85);

        if (logoImage && logoImage.complete && logoImage.naturalWidth > 0) {
            var lh = 64, lw = logoImage.width * (lh / logoImage.height);
            ctx.drawImage(logoImage, W - lw - 36, 36, lw, lh);
        }

        ctx.fillStyle = accent;
        ctx.fillRect(W / 2 - 40, 745, 80, 4);
    }

    // ---------------------------------------------- Texte im Bild aendern
    var LABELS = { badge: 'Kopfzeile', name: 'Fahrzeug', facts: 'Eckdaten', dealer: 'Absender' };

    canvas.addEventListener('click', function (event) {
        var rect = canvas.getBoundingClientRect();
        var x = (event.clientX - rect.left) * (1080 / rect.width);
        var y = (event.clientY - rect.top) * (1080 / rect.height);
        for (var key in textBoxes) {
            var box = textBoxes[key];
            if (x >= box.x && x <= box.x + box.w && y >= box.y && y <= box.y + box.h) {
                var value = window.prompt(LABELS[key] + ' ändern:', texts[key]);
                if (value !== null) {
                    texts[key] = value;
                    render();
                }
                return;
            }
        }
    });

    // ---------------------------------------------- Bild ziehen und zoomen
    var dragging = false, dragStartX = 0, dragStartY = 0, startOffX = 0, startOffY = 0;

    canvas.addEventListener('pointerdown', function (event) {
        if (!currentImage) { return; }
        var rect = canvas.getBoundingClientRect();
        var y = (event.clientY - rect.top) * (1080 / rect.height);
        if (y > 700) { return; }   // unterhalb des Fotos liegt der Textbereich
        dragging = true;
        canvas.style.cursor = 'grabbing';
        canvas.setPointerCapture(event.pointerId);
        dragStartX = event.clientX;
        dragStartY = event.clientY;
        startOffX = view.offsetX;
        startOffY = view.offsetY;
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!dragging) { return; }
        var rect = canvas.getBoundingClientRect();
        var factor = (1080 / rect.width) / view.zoom;
        view.offsetX = startOffX - (event.clientX - dragStartX) * factor;
        view.offsetY = startOffY - (event.clientY - dragStartY) * factor;
        render();
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
        canvas.addEventListener(type, function () {
            dragging = false;
            canvas.style.cursor = 'grab';
        });
    });

    document.getElementById('fontSelect').addEventListener('change', function () {
        fontKey = this.value;
        render();
    });
    document.getElementById('fontScale').addEventListener('input', function () {
        fontScale = parseInt(this.value, 10) / 100;
        render();
    });
    document.getElementById('imgZoom').addEventListener('input', function () {
        view.zoom = parseInt(this.value, 10) / 100;
        render();
    });
    document.getElementById('resetImage').addEventListener('click', function () {
        view = { zoom: 1, offsetX: 0, offsetY: 0 };
        document.getElementById('imgZoom').value = 100;
        render();
    });

    window.socialRender = render;

    document.getElementById('regenerateBtn').addEventListener('click', render);

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
