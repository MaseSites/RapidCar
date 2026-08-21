<?php
/**
 * Neues Fahrzeug (§25): Fotos zuerst, Drag & Drop.
 * Nach dem ersten Upload startet automatisch die Fotoanalyse (§26).
 * Im Demo-Modus füllt die Erkennung leere Felder mit Beispieldaten (§28/§30).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\ImageService;

$dealershipId = require_dealership();
$maxImages = ImageService::maxImagesPerVehicle();

// „Ohne Fotos fortfahren": Entwurf direkt anlegen
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create_empty') {
    guard_demo_mode();
    $vehicleId = VehicleRepository::createDraft($dealershipId, (int) $currentUser['id']);
    ActivityLogger::log((int) $currentUser['id'], 'vehicle.created', "Fahrzeug #{$vehicleId} erstellt", 'vehicle', $vehicleId, $dealershipId);
    redirect('dashboard/vehicle.php?id=' . $vehicleId);
}

$pageTitle = t('vehicles.add');
$activeNav = 'create';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="content-narrow" style="margin:0 auto">

    <!-- Fortschritt des Assistenten -->
    <ol class="wizard-steps" id="wizardSteps">
        <li class="is-active" data-step="1"><span class="num">1</span> <?= t('create.step_photos') ?></li>
        <li data-step="2"><span class="num">2</span> <?= t('create.step_document') ?></li>
        <li data-step="3"><span class="num">3</span> <?= t('create.step_ready') ?></li>
    </ol>

    <!-- ------------------------------------------------ Schritt 1: Fotos -->
    <section class="card card-pad wizard-pane" data-pane="1">
        <h1 class="wizard-title"><?= t('create.title') ?></h1>
        <p class="text-secondary text-sm mb-2"><?= t('create.lead') ?></p>

        <div class="dropzone" id="dropzone">
            <span class="dz-icon"><?= icon('camera', 30) ?></span>
            <div class="fw-600" style="color:var(--text)"><?= t('create.dropzone') ?></div>
            <div class="text-sm mt-1"><?= t('create.dropzone_hint') ?></div>
            <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none">
        </div>

        <div class="flex-between mt-2" id="imageBar" style="display:none">
            <div class="text-sm text-secondary" id="mainHint"><?= t('create.main_hint') ?></div>
            <div class="badge badge-neutral" id="imageCounter"><?= t('create.counter', ['count' => 0, 'max' => $maxImages]) ?></div>
        </div>

        <div id="analysisNote" class="alert alert-info mt-2" style="display:none">
            <?= icon('info', 16) ?>
            <span id="analysisText"><?= t('create.analysis') ?></span>
        </div>

        <div id="detectionResult" class="alert alert-success mt-2" style="display:none"></div>

        <div class="upload-grid" id="uploadGrid"></div>

        <div class="flex-between mt-3">
            <form method="post">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="create_empty">
                <button class="btn btn-ghost" type="submit"><?= t('create.skip') ?></button>
            </form>
            <button class="btn btn-primary btn-lg" type="button" id="toStep2" disabled>
                <?= t('common.next') ?> <?= icon('chevron-right', 15) ?>
            </button>
        </div>
    </section>

    <!-- -------------------------------------------- Schritt 2: Dokument -->
    <section class="card card-pad wizard-pane" data-pane="2" hidden>
        <h1 class="wizard-title"><?= t('create.doc_title') ?></h1>
        <p class="text-secondary text-sm mb-2"><?= t('document.lead') ?></p>

        <div class="doc-drop" id="wizDocDrop">
            <?= icon('file-text', 22) ?>
            <div>
                <div class="fw-600" style="color:var(--text)"><?= t('document.drop') ?></div>
                <div class="text-sm"><?= t('document.drop_hint') ?></div>
            </div>
            <input type="file" id="wizDocInput" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none">
        </div>

        <div class="alert alert-info mt-2">
            <?= icon('shield', 16) ?>
            <span class="text-sm"><?= t('document.privacy') ?></span>
        </div>

        <div id="wizDocResult" class="alert alert-success mt-2" style="display:none"></div>

        <div class="flex-between mt-3">
            <button class="btn btn-ghost" type="button" id="skipDoc"><?= t('create.doc_skip') ?></button>
            <button class="btn btn-primary btn-lg" type="button" id="toStep3">
                <?= t('common.next') ?> <?= icon('chevron-right', 15) ?>
            </button>
        </div>
    </section>

    <!-- ------------------------------------ Schritt 3: Text wird erzeugt -->
    <section class="card card-pad wizard-pane" data-pane="3" hidden>
        <h1 class="wizard-title"><?= t('create.finish_title') ?></h1>

        <div class="wizard-finish" id="wizardFinish">
            <div class="wizard-spinner" id="wizardSpinner"></div>
            <div class="fw-600" id="wizardStatus"><?= t('create.writing') ?></div>
            <div class="text-sm text-muted mt-1" id="wizardSub"><?= t('create.writing_hint') ?></div>
        </div>

        <div id="wizardDone" style="display:none">
            <div class="published-check" style="margin:0 auto 14px"><?= icon('check', 26) ?></div>
            <div class="text-center">
                <div class="fw-600" id="wizardTitle"></div>
                <p class="text-sm text-secondary mt-1" id="wizardExcerpt" style="max-width:520px;margin:8px auto 0"></p>
            </div>
            <div class="flex-center mt-3">
                <a class="btn btn-primary btn-lg" id="wizardOpen" href="#">
                    <?= t('create.open_listing') ?> <?= icon('chevron-right', 15) ?>
                </a>
            </div>
        </div>
    </section>
</div>

<?php
$js = static fn(string $key, array $r = []): string => json_encode(t($key, $r), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$jsUploading    = $js('create.uploading');
$jsUploadFailed = $js('create.upload_failed');
$jsMainImage    = $js('vehicle.main_image');
$jsDetecting    = $js('create.detecting');
$jsOpening      = $js('create.opening');
$jsDetected     = $js('create.detected', ['label' => '{LABEL}']);
$jsFieldsFilled = $js('create.fields_filled', ['count' => '{COUNT}']);
$jsCounter      = $js('create.counter', ['count' => '{COUNT}', 'max' => '{MAX}']);
$jsLimit        = $js('create.limit_reached', ['max' => '{MAX}', 'skipped' => '{SKIPPED}']);
$jsSetMain      = $js('vehicle.set_main');
$jsMainChanged  = $js('create.main_changed');
$jsDelete       = $js('common.delete');
$jsDeleteImage  = $js('vehicle.delete_image');
$jsIconStar     = json_encode(icon('star', 13), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$jsIconTrash    = json_encode(icon('trash', 13), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$jsMaxImages    = (int) $maxImages;
$pageScripts = <<<HTML
<script>
(function () {
    var MAX_IMAGES = {$jsMaxImages};
    // Klammer für diesen Hochladevorgang: Alle Fotos landen dadurch an einem
    // einzigen Inserat, auch wenn sie gleichzeitig unterwegs sind.
    var batch = 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    var vehicleId = 0;
    var detectionStarted = false;
    var pendingUploads = 0;
    var acceptedCount = 0;   // hochgeladen oder gerade unterwegs
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('fileInput');
    var grid = document.getElementById('uploadGrid');
    var imageBar = document.getElementById('imageBar');
    var counter = document.getElementById('imageCounter');
    var nextBtn = document.getElementById('toStep2');
    var analysisNote = document.getElementById('analysisNote');
    var analysisText = document.getElementById('analysisText');
    var detectionResult = document.getElementById('detectionResult');
    var baseUrl = document.querySelector('meta[name="base-url"]').content.replace(/\/$/, '');

    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
    dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('dragover'); });
    dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', function () { handleFiles(fileInput.files); fileInput.value = ''; });

    function handleFiles(files) {
        var list = Array.prototype.slice.call(files);
        var free = MAX_IMAGES - acceptedCount;
        if (list.length > free) {
            // Was über dem Limit liegt, wird gar nicht erst gesendet: ehrliche Meldung statt stiller Verlust.
            showToast(
                {$jsLimit}.replace('{MAX}', String(MAX_IMAGES)).replace('{SKIPPED}', String(list.length - Math.max(free, 0))),
                'warning'
            );
            list = list.slice(0, Math.max(free, 0));
        }
        if (list.length === 0) { return; }

        // Solange kein Fahrzeug besteht, wird genau EIN Foto gesendet. Erst wenn
        // dessen Antwort die Fahrzeug-ID liefert, folgen die übrigen. Sonst legt
        // jeder gleichzeitige Upload sein eigenes Fahrzeug an und aus 20 Fotos
        // würden 20 Inserate.
        if (vehicleId === 0) {
            uploadFile(list[0], function () {
                list.slice(1).forEach(function (file) { uploadFile(file); });
            });
            return;
        }
        list.forEach(function (file) { uploadFile(file); });
    }

    function updateCounter() {
        imageBar.style.display = acceptedCount > 0 ? 'flex' : 'none';
        counter.textContent = {$jsCounter}
            .replace('{COUNT}', String(acceptedCount))
            .replace('{MAX}', String(MAX_IMAGES));
        dropzone.style.display = acceptedCount >= MAX_IMAGES ? 'none' : '';
    }

    /** Zeichnet eine Kachel: Hauptbild-Markierung plus Aktionen. */
    function renderItem(item, img) {
        item.dataset.imageId = String(img.id);
        item.classList.toggle('is-main', !!img.is_main);
        item.innerHTML = '<img src="' + img.thumb_url + '" alt="">'
            + (img.is_main ? '<span class="main-tag">' + {$jsMainImage} + '</span>' : '')
            + (img.quality !== null && img.quality !== undefined ? '<span class="quality-tag">Q' + img.quality + '</span>' : '')
            + '<div class="item-actions">'
            + '<button type="button" data-act="set_main" title="' + escapeHtml({$jsSetMain}) + '">' + {$jsIconStar} + '</button>'
            + '<button type="button" data-act="delete" title="' + escapeHtml({$jsDelete}) + '">' + {$jsIconTrash} + '</button>'
            + '</div>';
    }

    // Ein Klick-Zuhörer für das ganze Gitter: die Kacheln entstehen erst nachträglich.
    grid.addEventListener('click', function (e) {
        var button = e.target.closest('button[data-act]');
        if (!button) { return; }
        var item = button.closest('.upload-item');
        var imageId = parseInt(item.dataset.imageId || '0', 10);
        if (!imageId) { return; }
        // Loeschen fragt im weichen Fenster nach
        if (button.dataset.act === 'delete') {
            window.softConfirm({$jsDeleteImage}, 'danger').then(function (yes) {
                if (yes) { runImageAction(button, item, imageId); }
            });
            return;
        }
        runImageAction(button, item, imageId);
    });

    function runImageAction(button, item, imageId) {
        button.disabled = true;

        apiFetch('api/vehicles/image-actions.php', {
            method: 'POST',
            body: { action: button.dataset.act, image_id: imageId }
        }).then(function (res) {
            button.disabled = false;
            if (!res.success) {
                showToast(res.error || {$jsUploadFailed}, 'danger');
                return;
            }
            if (button.dataset.act === 'delete') {
                item.remove();
                acceptedCount--;
                updateCounter();
                // Ohne Hauptbild-Markierung im Gitter: das erste Bild ist nachgerückt.
                if (!grid.querySelector('.upload-item.is-main')) {
                    markMain(grid.querySelector('.upload-item'));
                }
                if (acceptedCount === 0) {
                    nextBtn.disabled = true;
                }
                return;
            }
            markMain(item);
            showToast({$jsMainChanged}, 'success');
        });
    }

    /** Verschiebt die Hauptbild-Markierung auf genau eine Kachel. */
    function markMain(target) {
        Array.prototype.forEach.call(grid.querySelectorAll('.upload-item'), function (el) {
            var tag = el.querySelector('.main-tag');
            if (el === target) {
                el.classList.add('is-main');
                if (!tag) {
                    var span = document.createElement('span');
                    span.className = 'main-tag';
                    span.textContent = {$jsMainImage};
                    el.appendChild(span);
                }
            } else {
                el.classList.remove('is-main');
                if (tag) { tag.remove(); }
            }
        });
    }

    function uploadFile(file, onReady) {
        var item = document.createElement('div');
        item.className = 'upload-item';
        item.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:12px">' + {$jsUploading} + '</div>';
        grid.appendChild(item);
        analysisNote.style.display = 'flex';
        pendingUploads++;
        acceptedCount++;
        updateCounter();

        var formData = new FormData();
        formData.append('image', file);
        formData.append('vehicle_id', String(vehicleId));
        formData.append('batch', batch);

        apiFetch('api/vehicles/upload-image.php', { method: 'POST', body: formData }).then(function (res) {
            pendingUploads--;
            if (!res.success) {
                item.remove();
                acceptedCount--;
                updateCounter();
                showToast(res.error || {$jsUploadFailed}, 'danger');
                if (pendingUploads === 0 && acceptedCount === 0) {
                    analysisNote.style.display = 'none';
                }
                return;
            }
            vehicleId = res.data.vehicle_id;
            renderItem(item, res.data.image);
            nextBtn.disabled = false;

            // Die übrigen Fotos erst jetzt anstossen: sie kennen nun die Fahrzeug-ID.
            if (typeof onReady === 'function') { onReady(); }

            // Sobald alle laufenden Uploads fertig sind: automatische Erkennung starten
            if (pendingUploads === 0 && !detectionStarted) {
                startDetection();
            }
        });
    }

    function startDetection() {
        detectionStarted = true;
        analysisText.innerHTML = escapeHtml({$jsDetecting});
        apiFetch('api/ai/detect-vehicle.php', {
            method: 'POST',
            body: { vehicle_id: vehicleId, apply: 1 }
        }).then(function (res) {
            analysisNote.style.display = 'none';
            if (!res.success) {
                showToast(res.error || {$jsUploadFailed}, 'danger');
                detectionStarted = false;
                return;
            }
            var d = res.data.detection;
            if (d.detected) {
                var headline = {$jsDetected}.replace('{LABEL}', d.label || '')
                    + (d.confidence !== null ? ' (' + d.confidence + '%)' : '');
                var filled = {$jsFieldsFilled}.replace('{COUNT}', String(res.data.applied.length));
                detectionResult.style.display = 'flex';
                detectionResult.innerHTML = '<div>'
                    + '<div style="font-weight:600">' + escapeHtml(headline) + '</div>'
                    + '<div class="text-sm" style="margin-top:3px">' + escapeHtml(filled) + '</div>'
                    + '<div class="text-xs" style="margin-top:3px;opacity:.75">' + escapeHtml(d.note || '') + '</div>'
                    + '</div>';
            }
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // ------------------------------------------------ Ablauf des Assistenten
    // Schritt 1 Fotos, Schritt 2 Dokument, Schritt 3 Text erzeugen.
    // Der Text entsteht bewusst erst zum Schluss, wenn Fotos UND Dokument
    // ausgewertet sind: nur dann kennt er alle Angaben.
    function showStep(step) {
        document.querySelectorAll('.wizard-pane').forEach(function (pane) {
            pane.hidden = parseInt(pane.dataset.pane, 10) !== step;
        });
        document.querySelectorAll('#wizardSteps li').forEach(function (li) {
            var n = parseInt(li.dataset.step, 10);
            li.classList.toggle('is-active', n === step);
            li.classList.toggle('is-done', n < step);
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.getElementById('toStep2').addEventListener('click', function () {
        if (!vehicleId) { return; }
        showStep(2);
    });

    // Dokument im Assistenten: derselbe sparsame Weg wie auf der Inseratseite
    var wizDocDrop = document.getElementById('wizDocDrop');
    var wizDocInput = document.getElementById('wizDocInput');
    var wizDocResult = document.getElementById('wizDocResult');
    var docDone = false;

    if (wizDocDrop) {
        wizDocDrop.addEventListener('click', function () { wizDocInput.click(); });
        wizDocDrop.addEventListener('dragover', function (e) { e.preventDefault(); wizDocDrop.classList.add('dragover'); });
        wizDocDrop.addEventListener('dragleave', function () { wizDocDrop.classList.remove('dragover'); });
        wizDocDrop.addEventListener('drop', function (e) {
            e.preventDefault();
            wizDocDrop.classList.remove('dragover');
            if (e.dataTransfer.files.length) { readDocument(e.dataTransfer.files[0]); }
        });
        wizDocInput.addEventListener('change', function () {
            if (wizDocInput.files.length) { readDocument(wizDocInput.files[0]); }
            wizDocInput.value = '';
        });
    }

    function readDocument(file) {
        if (!vehicleId) { return; }
        wizDocDrop.classList.add('is-busy');
        var formData = new FormData();
        formData.append('document', file);
        formData.append('vehicle_id', String(vehicleId));
        apiFetch('api/ai/extract-document.php', { method: 'POST', body: formData }).then(function (res) {
            wizDocDrop.classList.remove('is-busy');
            if (!res.success) {
                showToast(res.error || {$jsUploadFailed}, 'danger');
                return;
            }
            docDone = true;
            wizDocResult.style.display = 'flex';
            wizDocResult.textContent = {$jsFieldsFilled}.replace('{COUNT}', String(res.data.applied.length));
        });
    }

    document.getElementById('skipDoc').addEventListener('click', function () { finish(); });
    document.getElementById('toStep3').addEventListener('click', function () { finish(); });

    // Letzter Schritt: Titel und Beschreibung im Stil des Autohauses schreiben
    function finish() {
        showStep(3);
        apiFetch('api/ai/generate-listing.php', {
            method: 'POST',
            body: { vehicle_id: vehicleId, save: 1 }
        }).then(function (res) {
            var target = baseUrl + '/dashboard/vehicle.php?id=' + vehicleId;

            if (res.success) {
                // Fertig: das Inserat oeffnet sich von selbst
                document.getElementById('wizardStatus').textContent = {$jsOpening};
                document.getElementById('wizardSub').textContent = res.data.title || '';
                window.location.replace(target);
                return;
            }

            // Ohne Text geht es trotzdem weiter: die Felder sind gefuellt.
            // Der Grund wird ehrlich genannt, statt still weiterzuleiten.
            document.getElementById('wizardFinish').style.display = 'none';
            document.getElementById('wizardDone').style.display = 'block';
            document.getElementById('wizardOpen').href = target;
            document.getElementById('wizardTitle').textContent = res.error || '';
        });
    }

})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
