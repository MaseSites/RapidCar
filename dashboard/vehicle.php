<?php
/**
 * Fahrzeug-Detailseite:
 *  - Grosse Bilddarstellung (§62) + Bildverwaltung (§25)
 *  - KI-Erkennung (§26/§28) mit Feldstatus (§30)
 *  - Fahrzeugformular (§29)
 *  - Inserat-Score + Verbesserungen (§32–§35)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\AI\AIVehicleService;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\ListingService;

$dealershipId = require_dealership();

$vehicleId = (int) ($_GET['id'] ?? 0);
$vehicle = VehicleRepository::find($vehicleId, $dealershipId);
if ($vehicle === null) {
    http_response_code(404);
    require BASE_PATH . '/errors/404.php';
    exit;
}

$error = null;

// ---------------------------------------------------------------- Formular-POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        VehicleRepository::delete($vehicleId, $dealershipId);
        ActivityLogger::log((int) $currentUser['id'], 'vehicle.deleted', "Fahrzeug #{$vehicleId} gelöscht", 'vehicle', $vehicleId, $dealershipId);
        Session::flash('success', t('vehicle.deleted'));
        redirect('dashboard/vehicles.php');
    }

    if ($action === 'publish') {
        // Inserat sicherstellen; fehlen Titel oder Beschreibung, erzeugt sie
        // der Generator aus den Fahrzeugdaten, damit ein Klick genuegt.
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        if (($listing['title'] ?? null) === null || ($listing['description'] ?? null) === null) {
            try {
                $generated = \App\AI\AIListingService::generate($vehicleId);
                Database::update('listings', (int) $listing['id'], [
                    'title'       => $listing['title'] ?? ($generated['title'] !== '' ? $generated['title'] : null),
                    'description' => $listing['description'] ?? ($generated['description'] !== '' ? $generated['description'] : null),
                    'updated_at'  => Database::now(),
                ]);
                $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Inserats-Text konnte nicht erzeugt werden: ' . $e->getMessage());
            }
        }
        if (($listing['title'] ?? null) === null || ($listing['description'] ?? null) === null) {
            Session::flash('danger', t('editor.publish_incomplete'));
            redirect('dashboard/listing-editor.php?id=' . $vehicleId);
        }

        try {
            \App\Service\CreditService::consumeForListing($dealershipId, (int) $listing['id'], (int) $currentUser['id']);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_CREDITS') {
                Session::flash('warning', t('credits.insufficient'));
                redirect('dashboard/credits.php');
            }
            Session::flash('danger', $e->getMessage());
            redirect('dashboard/vehicle.php?id=' . $vehicleId);
        }

        Database::update('listings', (int) $listing['id'], [
            'status'       => 'published',
            'published_at' => Database::now(),
            'updated_at'   => Database::now(),
        ]);
        Database::update('vehicles', $vehicleId, ['status' => 'published', 'updated_at' => Database::now()]);
        ActivityLogger::log((int) $currentUser['id'], 'listing.published', "Inserat #{$listing['id']} veröffentlicht", 'listing', (int) $listing['id'], $dealershipId);

        // Gewaehlte Kanaele bedienen (nur wirklich verbundene)
        $selectedChannels = array_map('strval', (array) ($_POST['channels'] ?? []));
        if (in_array(\App\Integration\AutoScoutService::PROVIDER, $selectedChannels, true)
            && \App\Integration\AutoScoutService::isConnected($dealershipId)) {
            try {
                \App\Integration\AutoScoutPublisher::push($dealershipId, $vehicleId, false, (int) $currentUser['id']);
            } catch (\Throwable $e) {
                Session::flash('warning', 'AutoScout24: ' . $e->getMessage());
            }
        }
        // Im Testbetrieb werden die uebrigen gewaehlten Kanaele nur lokal
        // vermerkt. An eine echte Plattform geht dabei nichts.
        $testRun = \App\Integration\ChannelRegistry::testChannelEnabled()
            && \App\Integration\ChannelRegistry::status($dealershipId, \App\Integration\ChannelRegistry::TEST_PROVIDER) === 'connected';
        foreach ($selectedChannels as $chKey) {
            if ($chKey === \App\Integration\AutoScoutService::PROVIDER
                && \App\Integration\AutoScoutService::isConnected($dealershipId)) {
                continue;   // wurde oben schon wirklich uebertragen
            }
            if (!$testRun && $chKey !== \App\Integration\ChannelRegistry::TEST_PROVIDER) {
                continue;
            }
            try {
                \App\Integration\TestChannelPublisher::push($dealershipId, $vehicleId, $chKey);
            } catch (\Throwable $e) {
                Session::flash('warning', $chKey . ': ' . $e->getMessage());
            }
        }
        redirect('dashboard/published.php?id=' . $vehicleId);
    }

    if ($action === 'save') {
        $v = new Validator($_POST);
        $v->maxLength('make', 'Marke', 100)
          ->maxLength('model', 'Modell', 100)
          ->maxLength('variant', 'Variante', 150)
          ->integer('year', 'Baujahr')->range('year', 'Baujahr', 1900, (int) date('Y') + 1)
          ->maxLength('first_registration', 'Erstzulassung', 7)
          ->integer('mileage', 'Kilometerstand')->range('mileage', 'Kilometerstand', 0, 5000000)
          ->numeric('price', 'Preis')
          ->integer('power_hp', 'PS')->range('power_hp', 'PS', 0, 3000)
          ->integer('power_kw', 'kW')->range('power_kw', 'kW', 0, 2500)
          ->integer('displacement_ccm', 'Hubraum')->range('displacement_ccm', 'Hubraum', 0, 20000)
          ->in('transmission', 'Getriebe', ['', 'manual', 'automatic', 'semi_automatic'])
          ->in('drivetrain', 'Antrieb', ['', 'fwd', 'rwd', 'awd'])
          ->in('fuel_type', 'Treibstoff', ['', 'petrol', 'diesel', 'electric', 'hybrid', 'plug_in_hybrid', 'gas'])
          ->maxLength('color', 'Farbe', 80)
          ->integer('doors', 'Türen')->range('doors', 'Türen', 0, 9)
          ->integer('seats', 'Sitze')->range('seats', 'Sitze', 0, 12)
          ->integer('previous_owners', 'Vorhalter')->range('previous_owners', 'Vorhalter', 0, 50)
          ->maxLength('vin', 'VIN', 30)
          ->maxLength('listing_title', 'Titel', 120);

        if ($v->fails()) {
            $error = $v->firstError();
        } else {
            $toNullableInt = static fn(string $value): ?int => $value === '' ? null : (int) $value;
            $toNullableStr = static fn(string $value): ?string => $value === '' ? null : $value;
            $priceRaw = str_replace(["'", ' '], '', $v->value('price'));
            $listingTitle = trim(mb_substr((string) ($_POST['listing_title'] ?? ''), 0, 120));
            $listingDescription = trim(mb_substr((string) ($_POST['listing_description'] ?? ''), 0, 10000));

            $update = [
                'make'               => $toNullableStr($v->value('make')),
                'model'              => $toNullableStr($v->value('model')),
                'variant'            => $toNullableStr($v->value('variant')),
                'year'               => $toNullableInt($v->value('year')),
                'first_registration' => $toNullableStr($v->value('first_registration')),
                'mileage'            => $toNullableInt($v->value('mileage')),
                'price'              => $priceRaw === '' ? null : (float) $priceRaw,
                'power_hp'           => $toNullableInt($v->value('power_hp')),
                'power_kw'           => $toNullableInt($v->value('power_kw')),
                'displacement_ccm'   => $toNullableInt($v->value('displacement_ccm')),
                'transmission'       => $toNullableStr($v->value('transmission')),
                'drivetrain'         => $toNullableStr($v->value('drivetrain')),
                'fuel_type'          => $toNullableStr($v->value('fuel_type')),
                'color'              => $toNullableStr($v->value('color')),
                'doors'              => $toNullableInt($v->value('doors')),
                'seats'              => $toNullableInt($v->value('seats')),
                'previous_owners'    => $toNullableInt($v->value('previous_owners')),
                'vin'                => $toNullableStr($v->value('vin')),
                // Der Inseratstext liegt beim Inserat; hier nur gespiegelt,
                // damit Exporte auf das Fahrzeug weiterhin einen Text finden.
                'description'        => $listingDescription !== '' ? $listingDescription : null,
                'status'             => (string) $vehicle['status'], // Status steuern Veröffentlichen und Zurückziehen
                'updated_at'         => Database::now(),
            ];

            // Vom Benutzer geänderte Felder → Status 'manuell' (§30)
            foreach ($update as $field => $newValue) {
                if (in_array($field, ['updated_at', 'status', 'description'], true)) {
                    continue;
                }
                $oldValue = $vehicle[$field] ?? null;
                if ((string) ($oldValue ?? '') !== (string) ($newValue ?? '')) {
                    AIVehicleService::setFieldStatus($vehicleId, $field, 'manual');
                }
            }

            // PS und kW gehören zusammen: fehlt eine Angabe, wird sie berechnet
            if ($update['power_hp'] !== null && $update['power_kw'] === null) {
                $update['power_kw'] = (int) round($update['power_hp'] / 1.35962);
            } elseif ($update['power_kw'] !== null && $update['power_hp'] === null) {
                $update['power_hp'] = (int) round($update['power_kw'] * 1.35962);
            }

            Database::update('vehicles', $vehicleId, $update);

            // Ausstattung: eine freie Liste, jeder Eintrag eine Zeile
            $features = array_values(array_unique(array_filter(
                array_map(
                    static fn(string $f): string => mb_substr(trim($f), 0, 100),
                    (array) ($_POST['features'] ?? [])
                ),
                static fn(string $f): bool => $f !== ''
            )));
            $features = array_slice($features, 0, 100);
            VehicleRepository::replaceFeatures($vehicleId, $features);

            // Inseratstext sichern und Score neu berechnen.
            //
            // Der Text der KI enthält Platzhalter für die Fahrzeugdaten. Solange
            // der Händler den Text nicht selbst umschreibt, wird er hier mit den
            // neuen Werten gefüllt: ändert sich der Kilometerstand, ändert sich
            // auch der Text, ohne dass die KI noch einmal laufen muss.
            $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
            $freshVehicle = VehicleRepository::find($vehicleId, $dealershipId) ?? [];

            $titleTemplate = (string) ($listing['title_template'] ?? '');
            $descriptionTemplate = (string) ($listing['description_template'] ?? '');

            // Hat der Händler den Text von Hand geändert, gilt seiner. Erkennbar
            // daran, dass er nicht mehr dem entspricht, was die Vorlage ergibt.
            $oldTitleRendered = \App\Service\ListingTemplate::render($titleTemplate, $vehicle);
            $oldTextRendered = \App\Service\ListingTemplate::render($descriptionTemplate, $vehicle);

            $titleFromTemplate = $titleTemplate !== '' && $listingTitle === $oldTitleRendered;
            $textFromTemplate = $descriptionTemplate !== '' && $listingDescription === $oldTextRendered;

            $listingUpdate = [
                'title'       => $listingTitle !== '' ? $listingTitle : null,
                'description' => $listingDescription !== '' ? $listingDescription : null,
                'updated_at'  => Database::now(),
            ];

            if ($titleFromTemplate) {
                $listingUpdate['title'] = \App\Service\ListingTemplate::render($titleTemplate, $freshVehicle) ?: null;
            } else {
                $listingUpdate['title_template'] = null;   // eigener Text hat Vorrang
            }
            if ($textFromTemplate) {
                $listingUpdate['description'] = \App\Service\ListingTemplate::render($descriptionTemplate, $freshVehicle) ?: null;
            } else {
                $listingUpdate['description_template'] = null;
            }

            Database::update('listings', (int) $listing['id'], $listingUpdate);
            ListingService::recalculate((int) $listing['id']);

            ActivityLogger::log((int) $currentUser['id'], 'vehicle.updated', "Fahrzeug #{$vehicleId} aktualisiert", 'vehicle', $vehicleId, $dealershipId);

            // Ist das Inserat bereits online, geht der neue Stand beim
            // Speichern von selbst an alle Kanaele.
            if ((string) $vehicle['status'] === 'published') {
                // Der Stand geht an jeden verbundenen Kanal. Kanaele ohne
                // Verbindung werden uebersprungen, nicht vorgetaeuscht.
                $pushed = [];
                $failed = [];
                // Im Testbetrieb werden auch die Testeintraege nachgezogen,
                // damit sich der Ablauf vollstaendig durchspielen laesst.
                $testRun = \App\Integration\ChannelRegistry::testChannelEnabled()
                    && \App\Integration\ChannelRegistry::status($dealershipId, \App\Integration\ChannelRegistry::TEST_PROVIDER) === 'connected';
                $listingRow = \App\Service\ListingService::ensureForVehicle($vehicleId, $dealershipId);
                $testProviders = $testRun
                    ? array_column(Database::fetchAll(
                        'SELECT provider FROM channel_listings WHERE listing_id = :lid',
                        ['lid' => (int) $listingRow['id']]
                    ), 'provider')
                    : [];

                foreach (\App\Integration\ChannelRegistry::all() as $chKey => $channel) {
                    if (($channel['type'] ?? '') !== 'marketplace') {
                        continue;
                    }
                    $connected = \App\Integration\ChannelRegistry::status($dealershipId, $chKey) === 'connected';
                    if (!$connected && !in_array($chKey, $testProviders, true)) {
                        continue;
                    }
                    try {
                        if ($connected && $chKey === \App\Integration\AutoScoutService::PROVIDER) {
                            \App\Integration\AutoScoutPublisher::push($dealershipId, $vehicleId, false, (int) $currentUser['id']);
                        } elseif ($connected && $chKey !== \App\Integration\ChannelRegistry::TEST_PROVIDER) {
                            continue;   // fuer die uebrigen Kanaele gibt es noch keine Uebertragung
                        } else {
                            \App\Integration\TestChannelPublisher::push($dealershipId, $vehicleId, $chKey);
                        }
                        $pushed[] = (string) $channel['name'];
                    } catch (\Throwable $e) {
                        $failed[] = (string) $channel['name'] . ': ' . $e->getMessage();
                    }
                }

                ActivityLogger::log((int) $currentUser['id'], 'listing.updated', "Inserat #{$vehicleId} aktualisiert, Kanaele: " . (count($pushed) ?: 0), 'vehicle', $vehicleId, $dealershipId);

                if ($failed !== []) {
                    Session::flash('warning', implode(' | ', $failed));
                } elseif ($pushed !== []) {
                    Session::flash('success', t('vehicle.updated_channels', ['channels' => implode(', ', $pushed)]));
                } else {
                    Session::flash('success', t('vehicle.updated_local'));
                }
                redirect('dashboard/vehicle.php?id=' . $vehicleId);
            }

            Session::flash('success', t('vehicle.saved'));
            redirect('dashboard/vehicle.php?id=' . $vehicleId);
        }
    }
}

// ------------------------------------------------------------------ Seitendaten
$maxImages = App\Service\ImageService::maxImagesPerVehicle();

// Verkaufsplattformen fuers Veroeffentlichen-Fenster: verbundene anklickbar,
// alle uebrigen ausgegraut
// Testbetrieb: erkennbar am verbundenen Testkanal. Dann laesst sich das
// Veroeffentlichen auf allen Kanaelen durchspielen, ohne dass etwas an eine
// echte Plattform geht.
$isTestRun = App\Integration\ChannelRegistry::testChannelEnabled()
    && App\Integration\ChannelRegistry::status($dealershipId, App\Integration\ChannelRegistry::TEST_PROVIDER) === 'connected';

$publishChannels = [];
foreach (App\Integration\ChannelRegistry::all() as $chKey => $channel) {
    if (($channel['type'] ?? '') !== 'marketplace') {
        continue;
    }
    $isConnected = App\Integration\ChannelRegistry::status($dealershipId, $chKey) === 'connected';
    $publishChannels[$chKey] = [
        'name'      => (string) $channel['name'],
        'connected' => $isConnected,
        // Im Testbetrieb sind alle Kanaele waehlbar. Gesendet wird dabei
        // nichts: der Eintrag entsteht nur in der eigenen Datenbank.
        'testable'  => !$isConnected && $isTestRun,
    ];
}
$isVehiclePublished = (string) $vehicle['status'] === 'published';
$images = VehicleRepository::images($vehicleId);
$features = VehicleRepository::features($vehicleId);
$fieldStatuses = AIVehicleService::fieldStatuses($vehicleId);
$listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
$score = ListingService::latestScore((int) $listing['id']);
$recommendations = ListingService::openRecommendations((int) $listing['id']);

$vehicleName = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? '')) ?: t('vehicle.new');

// AutoScout24-Status dieses Fahrzeugs
$autoscoutConnected = \App\Integration\AutoScoutService::isConnected($dealershipId);
$mobiledeConnected = \App\Integration\MobileDeService::isConnected($dealershipId);
$mobiledeAdId = $mobiledeConnected
    ? \App\Integration\MobileDePublisher::externalIdForVehicle($dealershipId, $vehicleId)
    : null;
$autoscoutListingId = $autoscoutConnected
    ? \App\Integration\AutoScoutPublisher::externalIdForVehicle($dealershipId, $vehicleId)
    : null;

$mainImage = null;
foreach ($images as $image) {
    if ((int) $image['is_main'] === 1) {
        $mainImage = $image;
        break;
    }
}
$mainImage ??= $images[0] ?? null;

/** Feldstatus-Badge (§30). */
function field_status_badge(array $statuses, string $field): string
{
    $entry = $statuses[$field] ?? null;
    if ($entry === null) {
        return '';
    }
    return match ($entry['status']) {
        'detected'  => '<span class="field-status detected">' . t('ai.field.detected') . ($entry['confidence'] !== null ? ' (' . $entry['confidence'] . '%)' : '') . '</span>',
        'uncertain' => '<span class="field-status uncertain">' . t('ai.field.uncertain')
            . ($entry['confidence'] !== null ? ' (' . $entry['confidence'] . '%)' : '') . '</span>',
        default     => '<span class="field-status manual">' . t('ai.field.manual') . '</span>',
    };
}

/**
 * Auswahlliste, wenn die Erkennung nicht eindeutig war.
 *
 * Der erkannte Wert steht oben, darunter die Alternativen der KI. Über
 * "Eigene Eingabe" bleibt jederzeit ein freier Text möglich, damit die
 * Auswahl den Händler nie einschränkt.
 */
function field_alternatives(array $statuses, string $field, mixed $currentValue): string
{
    $entry = $statuses[$field] ?? null;
    if ($entry === null || ($entry['alternatives'] ?? []) === []) {
        return '';
    }

    $current = (string) ($currentValue ?? '');
    $options = array_values(array_unique(array_filter(
        array_merge([$current], $entry['alternatives']),
        static fn(string $option): bool => trim($option) !== ''
    )));

    $html = '<div class="field-choice" data-field="' . e($field) . '">'
        . '<div class="field-choice-label">' . icon('help', 12) . ' ' . t('ai.field.choose') . '</div>'
        . '<select class="form-control field-choice-select">';
    foreach ($options as $option) {
        $selected = strcasecmp($option, $current) === 0 ? ' selected' : '';
        $html .= '<option value="' . e($option) . '"' . $selected . '>' . e($option) . '</option>';
    }
    $html .= '<option value="__custom__">' . e(t('ai.field.custom')) . '</option>'
        . '</select></div>';

    return $html;
}

$pageTitle = $vehicleName;
$activeNav = 'vehicles';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($vehicleName) ?></h1>
        <div class="sub">
            <?= $vehicle['price'] !== null ? format_price($vehicle['price']) . ' · ' : '' ?>
            <?= $vehicle['mileage'] !== null ? format_km($vehicle['mileage']) . ' · ' : '' ?>
            <span class="badge badge-neutral"><?= e(vehicle_status_label((string) $vehicle['status'])) ?></span>
        </div>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= base_url('dashboard/vehicles.php') ?>">‹ <?= t('dash.today.all_vehicles') ?></a>
        <a class="btn btn-accent" href="<?= base_url('dashboard/listing-editor.php?id=' . $vehicleId) ?>"><?= icon('eye', 15) ?> <?= t('vehicle.preview') ?></a>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<!-- ================================================== Bilder (§62, §25) -->
<div class="split split-3-2 mb-3">
    <div id="photoColumn">
        <div class="vehicle-hero">
            <?php if ($mainImage !== null): ?>
                <img id="heroImage" data-image-id="<?= (int) $mainImage['id'] ?>"
                     src="<?= e(upload_url((string) ($mainImage['card_path'] ?? $mainImage['file_path']))) ?>" alt="<?= e($vehicleName) ?>">
            <?php else: ?>
                <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);flex-direction:column;gap:8px">
                    <?= icon('camera', 34) ?> <?= t('vehicle.no_images') ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if (count($images) > 1): ?>
            <div class="gallery-strip">
                <?php foreach ($images as $index => $image): ?>
                    <img src="<?= e(upload_url((string) ($image['thumb_path'] ?? $image['file_path']))) ?>"
                         data-card="<?= e(upload_url((string) ($image['card_path'] ?? $image['file_path']))) ?>"
                         data-image-id="<?= (int) $image['id'] ?>"
                         class="<?= $image === $mainImage ? 'active' : '' ?>"
                         onclick="showHeroImage(this)"
                         alt="">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card card-pad" id="photoCard">
        <div class="flex-between mb-2">
            <h3 style="font-size:15.5px"><?= t('vehicle.manage_images') ?></h3>
            <span class="badge badge-neutral"><?= t('create.counter', ['count' => count($images), 'max' => $maxImages]) ?></span>
        </div>
        <?php if (count($images) < $maxImages): ?>
            <div class="dropzone" id="dropzone" style="padding:22px 16px">
                <div class="text-sm flex-center gap-1" style="justify-content:center"><?= icon('upload', 16) ?> <?= t('vehicle.more_photos') ?></div>
                <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none">
            </div>
        <?php endif; ?>
        <?php
        // Die rechte Spalte soll nicht laenger werden als das Bild links.
        // Wie viele Fotos offen liegen, misst das Skript; ohne Skript sind
        // die ersten vier sichtbar.
        $visibleImages = 4;
        ?>
        <div class="upload-grid is-clipped" id="imageGrid"
             style="grid-template-columns:repeat(auto-fill,minmax(110px,1fr))">
            <?php foreach ($images as $imageIndex => $image): ?>
                <div class="upload-item <?= (int) $image['is_main'] === 1 ? 'is-main' : '' ?> <?= $imageIndex >= $visibleImages ? 'is-overflow' : '' ?>"
                     data-image-id="<?= (int) $image['id'] ?>"
                     data-cutout="<?= (string) ($image['cutout_path'] ?? '') !== '' ? '1' : '0' ?>"
                     <?php if ((string) ($image['spyne_job'] ?? '') !== ''): ?>
                        data-spyne-job="<?= e((string) $image['spyne_job']) ?>"
                        data-spyne-scene="<?= e((string) ($image['spyne_scene'] ?? '')) ?>"
                     <?php endif; ?>>
                    <img src="<?= e(upload_url((string) ($image['thumb_path'] ?? $image['file_path']))) ?>" alt="">
                    <?php if ((int) $image['is_main'] === 1): ?><span class="main-tag"><?= t('vehicle.main_image') ?></span><?php endif; ?>
                    <?php if ($image['ai_quality_score'] !== null): ?>
                        <span class="main-tag" style="top:auto;bottom:8px;left:8px;background:rgba(0,0,0,.6)" title="Bildqualität (regelbasiert, Demo-Modus)">Q<?= (int) $image['ai_quality_score'] ?></span>
                    <?php endif; ?>
                    <?php if ((string) ($image['background_key'] ?? '') !== ''): ?>
                        <span class="bg-tag" title="<?= e(t('background.changed')) ?>"><?= icon('image', 11) ?></span>
                    <?php endif; ?>
                    <div class="item-actions">
                        <button type="button" onclick="imageAction('set_main', <?= (int) $image['id'] ?>)" title="<?= t('vehicle.set_main') ?>"><?= icon('star', 13) ?></button>
                        <button type="button" onclick="imageAction('delete', <?= (int) $image['id'] ?>)" title="<?= e(t('common.delete')) ?>"><?= icon('trash', 13) ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="imageGridHome"></div>

        <?php if ($images !== []): ?>
            <dialog class="bg-dialog" id="allImagesDialog">
                <div class="feature-dialog-head">
                    <strong><?= t('vehicle.manage_images') ?></strong>
                    <button class="icon-btn" type="button" id="allImagesClose" aria-label="<?= e(t('common.close')) ?>">
                        <?= icon('x', 18) ?>
                    </button>
                </div>
                <div class="bg-dialog-body">
                    <div class="text-sm text-secondary mb-2"><?= t('create.main_hint') ?></div>
                    <div id="allImagesBody"></div>
                </div>
            </dialog>
        <?php endif; ?>

        <?php if ($images !== []): ?>
            <?php
            // Der Schalter gilt fuer ALLE Fotos. Er ist an, wenn jedes Foto
            // einen gesetzten Hintergrund traegt.
            $allReplaced = !array_filter($images, static fn(array $i): bool => (string) ($i['background_key'] ?? '') === '');
            ?>
            <div class="bg-global mt-2" id="bgGlobal">
                <label class="switch">
                    <input type="checkbox" id="bgAll" <?= $allReplaced ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                    <span class="switch-label"><?= t('background.replace') ?></span>
                </label>
                <div class="text-xs text-muted mt-1" id="bgAllHint"><?= t('background.all_hint') ?></div>
                <?php $cutoutProvider = App\Service\CutoutService::providerName(); ?>
                <?php if ($cutoutProvider !== ''): ?>
                    <div class="text-xs text-muted"><?= t('background.via_service', ['name' => $cutoutProvider]) ?></div>
                <?php endif; ?>

                <?php
                $ownBackgrounds = App\Service\BackgroundService::ownBackgrounds($dealershipId);
                $favoriteKeys = App\Service\BackgroundService::favorites($dealershipId);
                $bgThumb = static function (string $key, string $label, string $imageUrl) use ($favoriteKeys): string {
                    $isFav = in_array($key, $favoriteKeys, true);
                    // Spyne-Szenen haben kein Vorschaubild: dann steht der
                    // Name auf einer ruhigen Flaeche statt eines leeren Rahmens.
                    $style = $imageUrl !== '' ? ' style="background-image:url(' . e($imageUrl) . ')"' : '';
                    $sceneClass = $imageUrl === '' ? ' is-scene' : '';
                    return '<div class="bg-thumb' . ($isFav ? ' is-fav' : '') . '" data-bg="' . e($key) . '">'
                        . '<button type="button" class="bg-thumb-pick' . $sceneClass . '"' . $style . ' title="' . e($label) . '">'
                        . ($imageUrl === '' ? icon('image', 18) : '') . '</button>'
                        . '<button type="button" class="bg-thumb-fav" title="Favorit">' . icon('star', 13) . '</button>'
                        . '<span class="bg-thumb-label">' . e($label) . '</span>'
                        . '</div>';
                };
                ?>
                <?php $bgTemplates = App\Service\BackgroundService::templates(); ?>
                <div id="bgChoices" <?= $allReplaced ? '' : 'style="display:none"' ?>>
                    <?php
                    // Der aktuell gesetzte Hintergrund, fuer die Anzeige am Knopf
                    $activeBackground = '';
                    foreach ($images as $image) {
                        $key = (string) ($image['background_key'] ?? '');
                        if ($key !== '') {
                            $activeBackground = App\Service\BackgroundService::label($key, $dealershipId);
                            break;
                        }
                    }
                    ?>
                    <!-- Fenster: Empfohlen | Eigene | Favoriten -->
                    <dialog class="bg-dialog" id="bgDialog">
                        <div class="feature-dialog-head">
                            <strong><?= t('background.library') ?></strong>
                            <button class="icon-btn" type="button" id="bgDialogClose" aria-label="<?= e(t('common.close')) ?>"><?= icon('x', 18) ?></button>
                        </div>
                        <div class="bg-tabs">
                            <button type="button" class="bg-tab is-active" data-tab="recommended"><?= t('background.tab_recommended') ?></button>
                            <?php // Mit Spyne gibt es keine eigenen Hintergruende: die liegen
                                  // ausschliesslich im Spyne-Konto des Betreibers, eine
                                  // Upload-Schnittstelle bietet Spyne nicht an. ?>
                            <?php if (!App\Service\BackgroundService::usesSpyne()): ?>
                                <button type="button" class="bg-tab" data-tab="own"><?= t('background.tab_own') ?></button>
                            <?php endif; ?>
                            <button type="button" class="bg-tab" data-tab="favorites"><?= t('background.tab_favorites') ?></button>
                        </div>
                        <?php if (App\Service\BackgroundService::usesSpyne()): ?>
                            <?php
                            // Vorbelegung der Haken aus den Betreiber-Einstellungen
                            $spynePlateDefault = (string) (\App\Service\SettingsService::get('spyne_plate') ?? 'off');
                            $spyneBannerDefault = trim((string) (\App\Service\SettingsService::get('spyne_banner_url') ?? ''));
                            $hasDealerLogo = trim((string) (App\Core\Database::scalar(
                                'SELECT logo_path FROM dealerships WHERE id = :d', ['d' => $dealershipId]
                            ) ?: '')) !== '';
                            ?>
                            <div class="bg-options">
                                <label class="form-check">
                                    <input type="checkbox" id="bgOptPlate" <?= $spynePlateDefault === 'logo' || $spynePlateDefault === 'white' ? 'checked' : '' ?>>
                                    <span><?= $hasDealerLogo ? t('background.opt_plate_logo') : t('background.opt_plate_white') ?></span>
                                </label>
                                <?php if ($spyneBannerDefault !== ''): ?>
                                    <label class="form-check">
                                        <input type="checkbox" id="bgOptBanner" checked>
                                        <span><?= t('background.opt_banner') ?></span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="bg-dialog-body">
                            <div data-pane="recommended">
                                <?php
                                // Nach Thema gruppieren; ohne Thema unter "Weitere"
                                $bgGroups = [];
                                foreach ($bgTemplates as $key => $template) {
                                    $theme = trim((string) ($template['theme'] ?? ''));
                                    $bgGroups[$theme][(string) $key] = $template;
                                }
                                ksort($bgGroups);
                                $bgThumbUrl = static fn(array $t): string => $t['file'] === '' ? ''
                                    : (str_starts_with($t['file'], 'http') ? $t['file'] : base_url($t['file']));
                                ?>
                                <?php foreach ($bgGroups as $bgTheme => $bgGroup): ?>
                                    <?php if (count($bgGroups) > 1 || $bgTheme !== ''): ?>
                                        <div class="bg-group-title"><?= e($bgTheme !== '' ? $bgTheme : 'Weitere') ?></div>
                                    <?php endif; ?>
                                    <div class="bg-grid">
                                        <?php $bgPos = 0; ?>
                                        <?php foreach ($bgGroup as $key => $template): ?>
                                            <?php $bgHidden = $bgPos >= 4 ? ' data-bg-extra="1" hidden' : ''; $bgPos++; ?>
                                            <?= str_replace('<div class="bg-thumb', '<div' . $bgHidden . ' class="bg-thumb', $bgThumb((string) $key, $template['label'], $bgThumbUrl($template))) ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($bgGroup) > 4): ?>
                                        <button type="button" class="btn btn-secondary btn-sm bg-group-more" data-bg-more>
                                            Mehr anzeigen (<?= count($bgGroup) - 4 ?>)
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($bgTemplates === []): ?>
                                    <div class="text-sm text-muted"><?= t('background.no_scenes') ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!App\Service\BackgroundService::usesSpyne()): ?>
                            <div class="bg-grid" data-pane="own" style="display:none">
                                <button type="button" class="bg-thumb bg-thumb-more" id="bgUploadBtn">
                                    <span class="bg-thumb-pick" style="display:flex;align-items:center;justify-content:center"><?= icon('upload', 18) ?></span>
                                    <span class="bg-thumb-label"><?= t('background.upload_own') ?></span>
                                </button>
                                <?php foreach ($ownBackgrounds as $own): ?>
                                    <?= $bgThumb('own:' . (int) $own['id'], (string) $own['name'], upload_url((string) ($own['thumb_path'] ?? $own['file_path']))) ?>
                                <?php endforeach; ?>
                                <input type="file" id="bgUploadInput" accept="image/jpeg,image/png,image/webp" style="display:none">
                            </div>
                            <?php endif; ?>
                            <div class="bg-grid" data-pane="favorites" style="display:none">
                                <?php
                                $renderedFavorites = 0;
                                foreach ($favoriteKeys as $favKey) {
                                    if (isset($bgTemplates[$favKey])) {
                                        echo $bgThumb(
                                            $favKey,
                                            $bgTemplates[$favKey]['label'],
                                            $bgTemplates[$favKey]['file'] === '' ? '' : (str_starts_with($bgTemplates[$favKey]['file'], 'http') ? $bgTemplates[$favKey]['file'] : base_url($bgTemplates[$favKey]['file']))
                                        );
                                        $renderedFavorites++;
                                        continue;
                                    }
                                    $favOwnId = App\Service\BackgroundService::ownId($favKey);
                                    foreach ($ownBackgrounds as $own) {
                                        if ($favOwnId === (int) $own['id']) {
                                            echo $bgThumb($favKey, (string) $own['name'], upload_url((string) ($own['thumb_path'] ?? $own['file_path'])));
                                            $renderedFavorites++;
                                        }
                                    }
                                }
                                ?>
                                <div class="text-sm text-muted" data-empty-favorites <?= $renderedFavorites > 0 ? 'style="display:none"' : '' ?>>
                                    <?= t('background.no_favorites') ?>
                                </div>
                            </div>
                        </div>
                    </dialog>
                </div>
            </div>

            <!-- Beide Knoepfe in einer Zeile: das spart der Spalte eine Reihe -->
            <div class="photo-actions mt-2">
                <button class="btn btn-secondary" type="button" id="allImagesBtn"
                        <?= count($images) > $visibleImages ? '' : 'hidden' ?>>
                    <?= icon('image', 15) ?> <span><?= t('vehicle.all_images', ['count' => count($images)]) ?></span>
                </button>
                <button class="btn btn-secondary" type="button" id="bgPick" <?= $allReplaced ? '' : 'hidden' ?>>
                    <?= icon('image', 15) ?>
                    <span id="bgPickLabel"><?= $activeBackground !== ''
                        ? e(t('background.current', ['name' => $activeBackground]))
                        : t('background.choose') ?></span>
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- ============================================== Dokument auswerten (§26) -->
<div class="card mb-3">
    <div class="card-header">
        <h2><?= t('document.title') ?></h2>
        <span class="badge badge-neutral"><?= t('document.badge') ?></span>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-2"><?= t('document.lead') ?></p>

        <div class="doc-drop" id="docDrop">
            <?= icon('file-text', 22) ?>
            <div>
                <div class="fw-600" style="color:var(--text)"><?= t('document.drop') ?></div>
                <div class="text-sm"><?= t('document.drop_hint') ?></div>
            </div>
            <input type="file" id="docInput" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none">
        </div>

        <div class="alert alert-info mt-2" style="margin-bottom:0">
            <?= icon('shield', 16) ?>
            <span class="text-sm"><?= t('document.privacy') ?></span>
        </div>

        <div id="docResult" class="mt-2"></div>
    </div>
</div>

<!-- ================================================== Fahrzeugformular (§29) -->
<form method="post" id="vehicleForm" action="<?= base_url('dashboard/vehicle.php?id=' . $vehicleId) ?>">
    <?= App\Core\Csrf::field() ?>
    <input type="hidden" name="action" value="save" id="vehicleFormAction">

    <!-- Inseratstext: Titel und Beschreibung, wie sie veroeffentlicht werden -->
    <div class="card mb-3">
        <div class="card-header"><h2><?= t('vehicle.listing_text') ?></h2></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label" for="listingTitle"><?= t('editor.field.title') ?></label>
                <input class="form-control" type="text" id="listingTitle" name="listing_title" maxlength="120"
                       value="<?= e((string) ($listing['title'] ?? '')) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="listingDescription"><?= t('field.description') ?></label>
                <textarea class="form-control" id="listingDescription" name="listing_description" rows="9"><?= e((string) ($listing['description'] ?? '')) ?></textarea>
                <p class="form-hint">
                    <?= ($listing['description'] ?? '') === null || trim((string) ($listing['description'] ?? '')) === ''
                        ? t('vehicle.listing_text_empty')
                        : t('vehicle.listing_text_hint') ?>
                </p>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h2><?= t('vehicle.data.title') ?></h2></div>
        <div class="card-body">
            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label"><?= t('field.make') ?> <?= field_status_badge($fieldStatuses, 'make') ?></label>
                    <input class="form-control" type="text" name="make" value="<?= e($vehicle['make'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'make', $vehicle['make'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.model') ?> <?= field_status_badge($fieldStatuses, 'model') ?></label>
                    <input class="form-control" type="text" name="model" value="<?= e($vehicle['model'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'model', $vehicle['model'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.variant') ?> <?= field_status_badge($fieldStatuses, 'variant') ?></label>
                    <input class="form-control" type="text" name="variant" value="<?= e($vehicle['variant'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'variant', $vehicle['variant'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.year') ?> <?= field_status_badge($fieldStatuses, 'year') ?></label>
                    <input class="form-control" type="number" name="year" min="1900" max="<?= (int) date('Y') + 1 ?>" value="<?= e($vehicle['year'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'year', $vehicle['year'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.first_registration') ?> <span class="optional">(MM.JJJJ)</span></label>
                    <input class="form-control" type="text" name="first_registration" placeholder="03.2023" value="<?= e($vehicle['first_registration'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'first_registration', $vehicle['first_registration'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.mileage') ?> <?= field_status_badge($fieldStatuses, 'mileage') ?></label>
                    <input class="form-control" type="number" name="mileage" min="0" value="<?= e($vehicle['mileage'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'mileage', $vehicle['mileage'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.price') ?> (CHF)</label>
                    <input class="form-control" type="text" name="price" value="<?= $vehicle['price'] !== null ? e(number_format((float) $vehicle['price'], 0, '.', '')) : '' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.power_hp') ?> <?= field_status_badge($fieldStatuses, 'power_hp') ?></label>
                    <input class="form-control" type="number" name="power_hp" min="0" value="<?= e($vehicle['power_hp'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'power_hp', $vehicle['power_hp'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.power_kw') ?></label>
                    <input class="form-control" type="number" name="power_kw" min="0" value="<?= e($vehicle['power_kw'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'power_kw', $vehicle['power_kw'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.displacement') ?></label>
                    <input class="form-control" type="number" name="displacement_ccm" min="0" value="<?= e($vehicle['displacement_ccm'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'displacement_ccm', $vehicle['displacement_ccm'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.transmission') ?> <?= field_status_badge($fieldStatuses, 'transmission') ?></label>
                    <select class="form-control" name="transmission">
                        <option value=""><?= t('common.select') ?></option>
                        <option value="manual" <?= ($vehicle['transmission'] ?? '') === 'manual' ? 'selected' : '' ?>><?= t('transmission.manual') ?></option>
                        <option value="automatic" <?= ($vehicle['transmission'] ?? '') === 'automatic' ? 'selected' : '' ?>><?= t('transmission.automatic') ?></option>
                        <option value="semi_automatic" <?= ($vehicle['transmission'] ?? '') === 'semi_automatic' ? 'selected' : '' ?>><?= t('transmission.semi_automatic') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.drivetrain') ?></label>
                    <select class="form-control" name="drivetrain">
                        <option value=""><?= t('common.select') ?></option>
                        <option value="fwd" <?= ($vehicle['drivetrain'] ?? '') === 'fwd' ? 'selected' : '' ?>><?= t('drivetrain.fwd') ?></option>
                        <option value="rwd" <?= ($vehicle['drivetrain'] ?? '') === 'rwd' ? 'selected' : '' ?>><?= t('drivetrain.rwd') ?></option>
                        <option value="awd" <?= ($vehicle['drivetrain'] ?? '') === 'awd' ? 'selected' : '' ?>><?= t('drivetrain.awd') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.fuel_type') ?> <?= field_status_badge($fieldStatuses, 'fuel_type') ?></label>
                    <select class="form-control" name="fuel_type">
                        <option value=""><?= t('common.select') ?></option>
                        <option value="petrol" <?= ($vehicle['fuel_type'] ?? '') === 'petrol' ? 'selected' : '' ?>><?= t('fuel.petrol') ?></option>
                        <option value="diesel" <?= ($vehicle['fuel_type'] ?? '') === 'diesel' ? 'selected' : '' ?>><?= t('fuel.diesel') ?></option>
                        <option value="electric" <?= ($vehicle['fuel_type'] ?? '') === 'electric' ? 'selected' : '' ?>><?= t('fuel.electric') ?></option>
                        <option value="hybrid" <?= ($vehicle['fuel_type'] ?? '') === 'hybrid' ? 'selected' : '' ?>><?= t('fuel.hybrid') ?></option>
                        <option value="plug_in_hybrid" <?= ($vehicle['fuel_type'] ?? '') === 'plug_in_hybrid' ? 'selected' : '' ?>><?= t('fuel.plug_in_hybrid') ?></option>
                        <option value="gas" <?= ($vehicle['fuel_type'] ?? '') === 'gas' ? 'selected' : '' ?>><?= t('fuel.gas') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.color') ?> <?= field_status_badge($fieldStatuses, 'color') ?></label>
                    <input class="form-control" type="text" name="color" value="<?= e($vehicle['color'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'color', $vehicle['color'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.doors') ?> <?= field_status_badge($fieldStatuses, 'doors') ?></label>
                    <input class="form-control" type="number" name="doors" min="0" max="9" value="<?= e($vehicle['doors'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'doors', $vehicle['doors'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.seats') ?></label>
                    <input class="form-control" type="number" name="seats" min="0" max="12" value="<?= e($vehicle['seats'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'seats', $vehicle['seats'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.previous_owners') ?> <?= field_status_badge($fieldStatuses, 'previous_owners') ?></label>
                    <input class="form-control" type="text" name="previous_owners" value="<?= e((string) ($vehicle['previous_owners'] ?? '')) ?>">
                    <?= field_alternatives($fieldStatuses, 'previous_owners', $vehicle['previous_owners'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('field.vin') ?> <span class="optional">(<?= t('common.optional') ?>)</span> <?= field_status_badge($fieldStatuses, 'vin') ?></label>
                    <input class="form-control" type="text" name="vin" value="<?= e($vehicle['vin'] ?? '') ?>">
                    <?= field_alternatives($fieldStatuses, 'vin', $vehicle['vin'] ?? '') ?>
                </div>
            </div>

            <div class="form-group">
                <div class="flex-between mb-1" style="align-items:baseline">
                    <label class="form-label" style="margin:0"><?= t('field.features') ?></label>
                    <span class="text-sm text-muted"><span id="featureCount"><?= count($features) ?></span> <?= t('field.features_selected') ?></span>
                </div>

                <!-- Freie Liste: eintippen, hinzufuegen, fertig. Die Eintraege
                     gehen als Liste in die Beschreibung. -->
                <div class="feature-add">
                    <input class="form-control" type="text" id="featureInput"
                           placeholder="<?= e(t('field.features_add_placeholder')) ?>" autocomplete="off">
                    <button class="btn btn-secondary" type="button" id="featureAddBtn">
                        <?= icon('plus', 14) ?> <?= t('field.features_add') ?>
                    </button>
                </div>

                <ul class="feature-list" id="featureList">
                    <?php foreach ($features as $feature): ?>
                        <li class="feature-item">
                            <span><?= e($feature) ?></span>
                            <button type="button" data-remove aria-label="<?= e(t('common.delete')) ?>"><?= icon('x', 13) ?></button>
                            <input type="hidden" name="features[]" value="<?= e($feature) ?>">
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

        </div>
    </div>
</form>

<!-- ============================== Score (Par. 32 bis 35): aufklappbar -->
<div class="card mb-3" id="score">
    <div class="card-body" style="padding-top:16px;padding-bottom:16px">
        <?php if ($score === null): ?>
            <p class="text-muted" style="margin:0"><?= t('score.not_yet') ?></p>
        <?php else: ?>
            <?php $total = (int) $score['total_score']; ?>
            <details class="score-fold">
                <summary>
                    <span class="score-fold-label"><?= t('score.total') ?></span>
                    <span class="score-track"><i class="<?= rating_class($total) ?>" style="--w:<?= $total ?>%"></i></span>
                    <span class="score-fold-value"><?= $total ?><small>/100</small></span>
                    <span class="score-fold-chevron"><?= icon('chevron-down', 15) ?></span>
                </summary>

                <div class="score-detail">
                    <?php
                    $sections = [
                        t('score.photos')      => $score['photos_score'],
                        t('score.title')       => $score['title_score'],
                        t('score.description') => $score['description_score'],
                        t('score.price')       => $score['price_score'],
                        t('score.data')        => $score['data_score'],
                    ];
                    $details = json_decode((string) ($score['details'] ?? '{}'), true) ?: [];
                    $detailKeys = [
                        t('score.photos') => 'photos', t('score.title') => 'title',
                        t('score.description') => 'description', t('score.price') => 'price',
                        t('score.data') => 'data',
                    ];
                    ?>
                    <?php foreach ($sections as $label => $value): ?>
                        <div class="score-detail-row" <?= isset($details[$detailKeys[$label]]) ? 'title="' . e((string) $details[$detailKeys[$label]]) . '"' : '' ?>>
                            <span class="name"><?= $label ?></span>
                            <?php if ($value !== null): ?>
                                <span class="score-track"><i class="<?= rating_class((int) $value) ?>" style="--w:<?= (int) $value ?>%"></i></span>
                                <span class="val"><?= (int) $value ?></span>
                            <?php else: ?>
                                <span class="score-track"><i style="--w:0%"></i></span>
                                <span class="val text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($recommendations !== []): ?>
                        <div class="score-reco">
                            <div class="score-reco-title"><?= count($recommendations) ?> <?= count($recommendations) === 1 ? 'Verbesserung' : 'Verbesserungen' ?></div>
                            <?php foreach ($recommendations as $rec): ?>
                                <div class="score-reco-row">
                                    <span class="status-dot <?= $rec['severity'] === 'critical' ? 'red' : ($rec['severity'] === 'warning' ? 'yellow' : 'gray') ?>"></span>
                                    <span class="msg"><?= e($rec['message']) ?></span>
                                    <?php if ($rec['category'] === 'title' || $rec['category'] === 'description'): ?>
                                        <a class="btn btn-secondary btn-sm" href="<?= base_url('dashboard/listing-editor.php?id=' . $vehicleId) ?>"><?= e($rec['action_label'] ?? t('dash.today.optimize')) ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-xs text-muted mt-2"><?= t('ai.score.rule_based') ?></div>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<!-- ================================================== AutoScout24 -->
<?php if ($autoscoutConnected): ?>
<div class="card mb-3">
    <div class="card-header">
        <h2>AutoScout24</h2>
        <?php if ($autoscoutListingId !== null): ?>
            <span class="badge badge-success"><?= icon('check', 13) ?> <?= t('autoscout.listing_id') ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($autoscoutListingId !== null): ?>
            <div class="flex-between mb-2" style="flex-wrap:wrap;gap:10px">
                <div>
                    <div class="text-sm text-muted"><?= t('autoscout.listing_id') ?></div>
                    <code class="text-xs"><?= e($autoscoutListingId) ?></code>
                </div>
                <div class="flex gap-1" style="flex-wrap:wrap">
                    <form method="post" action="<?= base_url('api/autoscout/publish.php') ?>">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                        <input type="hidden" name="action" value="push">
                        <button class="btn btn-secondary btn-sm" type="submit">
                            <?= icon('refresh', 14) ?> <?= t('common.updated') ?>
                        </button>
                    </form>
                    <form method="post" action="<?= base_url('api/autoscout/publish.php') ?>" data-confirm="<?= t('autoscout.activate_confirm') ?>" data-confirm-tone="success">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                        <input type="hidden" name="action" value="activate">
                        <button class="btn btn-accent btn-sm" type="submit">
                            <?= icon('check', 14) ?> <?= t('autoscout.activate') ?>
                        </button>
                    </form>
                    <form method="post" action="<?= base_url('api/autoscout/publish.php') ?>">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <button class="btn btn-secondary btn-sm" type="submit"><?= t('autoscout.deactivate') ?></button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <p class="text-secondary mb-2"><?= t('autoscout.push_hint') ?></p>
            <form method="post" action="<?= base_url('api/autoscout/publish.php') ?>" data-confirm="<?= t('autoscout.push_confirm') ?>" data-confirm-tone="success">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                <input type="hidden" name="action" value="push">
                <button class="btn btn-primary" type="submit">
                    <?= icon('external-link', 15) ?> <?= t('autoscout.push') ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ================================================== mobile.de -->
<?php if ($mobiledeConnected): ?>
<div class="card mb-3">
    <div class="card-header">
        <h2>mobile.de</h2>
        <?php if ($mobiledeAdId !== null): ?>
            <span class="badge badge-success"><?= icon('check', 13) ?> Inserat <?= e($mobiledeAdId) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($mobiledeAdId !== null): ?>
            <div class="flex gap-1" style="flex-wrap:wrap">
                <form method="post" action="<?= base_url('api/mobilede/publish.php') ?>">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                    <input type="hidden" name="action" value="push">
                    <button class="btn btn-secondary btn-sm" type="submit">
                        <?= icon('refresh', 14) ?> Aktualisieren
                    </button>
                </form>
                <form method="post" action="<?= base_url('api/mobilede/publish.php') ?>" data-confirm="Inserat wirklich von mobile.de entfernen?">
                    <?= App\Core\Csrf::field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                    <input type="hidden" name="action" value="remove">
                    <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash', 14) ?> Entfernen</button>
                </form>
            </div>
        <?php else: ?>
            <p class="text-secondary mb-2">Überträgt das Inserat mit Fotos und Beschreibung zu mobile.de. Die Börse prüft die Pflichtangaben und meldet Fehlendes zurück.</p>
            <form method="post" action="<?= base_url('api/mobilede/publish.php') ?>" data-confirm="Fahrzeug jetzt zu mobile.de übertragen?" data-confirm-tone="success">
                <?= App\Core\Csrf::field() ?>
                <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                <input type="hidden" name="action" value="push">
                <button class="btn btn-primary" type="submit">
                    <?= icon('external-link', 15) ?> Zu mobile.de übertragen
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================== Aktionsleiste: Speichern und Löschen -->
<div class="card mb-3">
    <div class="card-body flex-between" style="gap:12px;flex-wrap:wrap">
        <form method="post" data-confirm="<?= t('vehicle.delete.confirm') ?>" title="<?= e(t('vehicle.delete.text')) ?>">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <button class="btn btn-danger" type="submit"><?= icon('trash', 15) ?> <?= t('vehicle.delete.title') ?></button>
        </form>
        <div class="flex-center gap-2" style="flex-wrap:wrap">
            <?php if ($isVehiclePublished): ?>
                <span class="text-xs text-muted"><?= t('vehicle.save_updates') ?></span>
            <?php endif; ?>
            <button class="btn <?= $isVehiclePublished ? 'btn-primary' : 'btn-secondary' ?> btn-lg"
                    type="submit" form="vehicleForm"><?= icon('check', 16) ?> <?= t('common.save') ?></button>
            <?php if (!$isVehiclePublished): ?>
                <button class="btn btn-primary btn-lg" type="button" id="vehiclePublishBtn">
                    <?= icon('upload', 16) ?> <?= t('editor.publish') ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Veroeffentlichen: Kanalauswahl -->
<dialog class="publish-dialog" id="publishDialog">
    <div class="feature-dialog-head">
        <strong><?= t('publish.title') ?></strong>
        <button class="icon-btn" type="button" id="publishClose" aria-label="<?= e(t('common.close')) ?>"><?= icon('x', 18) ?></button>
    </div>
    <form method="post" action="<?= base_url('dashboard/vehicle.php?id=' . $vehicleId) ?>" id="publishForm">
        <?= App\Core\Csrf::field() ?>
        <input type="hidden" name="action" value="publish">
        <div class="publish-body">
            <p class="text-sm text-secondary" style="margin-bottom:12px"><?= t('publish.lead') ?></p>
            <label class="publish-channel is-fixed">
                <input type="checkbox" checked disabled>
                <span class="name">RapidCar</span>
                <span class="text-xs text-muted"><?= t('publish.always') ?></span>
            </label>
            <?php foreach ($publishChannels as $chKey => $channel): ?>
                <?php $selectable = $channel['connected'] || $channel['testable']; ?>
                <label class="publish-channel <?= $selectable ? '' : 'is-disabled' ?>">
                    <input type="checkbox" name="channels[]" value="<?= e($chKey) ?>"
                           <?= $selectable ? 'checked' : 'disabled' ?>>
                    <span class="name"><?= e($channel['name']) ?></span>
                    <?php if ($channel['connected']): ?>
                        <span class="badge badge-success"><span class="status-dot green"></span> <?= t('channels.status.connected') ?></span>
                    <?php elseif ($channel['testable']): ?>
                        <span class="badge badge-warning"><?= t('publish.test_only') ?></span>
                    <?php else: ?>
                        <span class="text-xs text-muted"><?= t('channels.status.disconnected') ?></span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="feature-dialog-foot" style="justify-content:space-between;align-items:center">
            <span class="text-sm text-muted"><?= t('publish.cost_note') ?></span>
            <button class="btn btn-primary btn-lg" type="submit"><?= icon('check', 16) ?> <?= t('editor.publish') ?></button>
        </div>
    </form>
</dialog>

<?php
$vehicleIdJs = (int) $vehicleId;
$js = static fn(string $key, array $r = []): string => json_encode(t($key, $r), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$jsDetected      = $js('create.detected', ['label' => '{LABEL}']);
$jsFieldsFilled  = $js('create.fields_filled', ['count' => '{COUNT}']);
$jsDeleteImage   = $js('vehicle.delete_image');
$jsBgCutting     = $js('background.cutting');
$jsBgCutoutDone  = $js('background.cutout_done');
$jsBgApplied     = $js('background.applied');
$jsBgCurrent     = $js('background.current', ['name' => '{NAME}']);
$jsBgResetDone   = $js('background.reset_done');$jsBgAllHint     = $js('background.all_hint');$jsBgChooseHint  = $js('background.choose_hint');
$jsDocReading    = $js('document.reading');
$jsDocDone       = $js('document.done', ['count' => '{COUNT}']);
$jsDocNothing    = $js('document.nothing');
$jsUploadFailed  = $js('create.upload_failed');
$jsBgSpyneWait   = $js('background.spyne_wait');
$jsBgSpyneSlow   = $js('background.spyne_slow');
$pageScripts = <<<HTML
<script>
(function () {
    var vehicleId = {$vehicleIdJs};

    // ---------------------------------------------------------- Bild-Upload
    // Ist die Höchstzahl erreicht, fehlt die Ablagefläche im HTML.
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('fileInput');
    if (dropzone && fileInput) {
        dropzone.addEventListener('click', function () { fileInput.click(); });
        dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
        dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('dragover'); });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            uploadFiles(e.dataTransfer.files);
        });
        fileInput.addEventListener('change', function () { uploadFiles(fileInput.files); fileInput.value = ''; });
    }

    function uploadFiles(files) {
        var remaining = files.length;
        Array.prototype.slice.call(files).forEach(function (file) {
            var formData = new FormData();
            formData.append('image', file);
            formData.append('vehicle_id', String(vehicleId));
            apiFetch('api/vehicles/upload-image.php', { method: 'POST', body: formData }).then(function (res) {
                if (!res.success) { showToast(res.error || {$jsUploadFailed}, 'danger'); }
                if (--remaining === 0) { location.reload(); }
            });
        });
    }

    // ---------------------------------------- Hintergrund fuer alle Fotos
    // Schalter an: die Auswahl erscheint SOFORT. Beim Klick auf einen
    // Hintergrund laeuft Foto fuer Foto: einmalig freistellen (nur beim ersten
    // Mal), dann montieren; jede Kachel aktualisiert sich live.
    // Schalter aus: alle Fotos kehren zum Original zurueck.
    var bgAll = document.getElementById('bgAll');
    var bgChoices = document.getElementById('bgChoices');
    var bgPick = document.getElementById('bgPick');
    var bgHint = document.getElementById('bgAllHint');
    var bgUploadInput = document.getElementById('bgUploadInput');
    var bgBusy = false;

    function bgImageIds() {
        return Array.prototype.map.call(
            document.querySelectorAll('.upload-item[data-image-id]'),
            function (el) { return parseInt(el.dataset.imageId, 10); }
        );
    }

    function bgSetHint(text) { if (bgHint) { bgHint.textContent = text; } }

    function bgMarkWorking(id, on) {
        var item = document.querySelector('.upload-item[data-image-id="' + id + '"]');
        if (item) { item.classList.toggle('is-working', on); }
    }

    // Nacheinander statt gleichzeitig: schont Server und API-Kontingent
    function bgRunSequential(ids, worker, done, stopOnError) {
        var index = 0;
        var failed = 0;
        (function next() {
            if (index >= ids.length) { done(failed); return; }
            var id = ids[index];
            index++;
            worker(id, index, ids.length, function (ok) {
                if (!ok) {
                    failed++;
                    // Scheitert der Hintergrund selbst, wuerden alle weiteren
                    // Fotos dieselbe Meldung erzeugen. Einmal reicht.
                    if (stopOnError) { done(failed); return; }
                }
                next();
            });
        })();
    }

    if (bgAll) {
        bgAll.addEventListener('change', function () {
            if (bgBusy) { bgAll.checked = !bgAll.checked; return; }

            if (bgAll.checked) {
                // Sofort die Auswahl zeigen; gearbeitet wird erst beim Anwenden
                bgChoices.style.display = '';
                bgPick.hidden = false;
                bgSetHint({$jsBgChooseHint});
                if (window.fitPhotoGrid) { window.fitPhotoGrid(); }
                return;
            }

            // Schalter aus: alle Originale wiederherstellen
            bgBusy = true;
            bgAll.disabled = true;
            bgChoices.style.display = 'none';
            bgPick.hidden = true;
            if (window.fitPhotoGrid) { window.fitPhotoGrid(); }
            bgRunSequential(bgImageIds(), function (id, pos, total, cb) {
                bgMarkWorking(id, true);
                bgSetHint({$jsBgResetDone} + ' (' + pos + '/' + total + ')');
                apiFetch('api/vehicles/image-background.php', {
                    method: 'POST',
                    body: { action: 'reset', image_id: id }
                }).then(function (res) {
                    bgMarkWorking(id, false);
                    if (res.success) { refreshImage(res.data, id); }
                    cb(!!res.success);
                });
            }, function () {
                bgBusy = false;
                bgAll.disabled = false;
                bgSetHint({$jsBgAllHint});
            });
        });

        // Klicks auf Hintergrund-Kacheln: anwenden oder favorisieren
        document.getElementById('bgChoices').addEventListener('click', function (e) {
            var fav = e.target.closest('.bg-thumb-fav');
            if (fav) {
                toggleFavorite(fav.closest('.bg-thumb').dataset.bg);
                return;
            }
            var pick = e.target.closest('.bg-thumb-pick');
            if (!pick) { return; }
            var holder = pick.closest('.bg-thumb');
            if (holder.id === 'bgUploadBtn') { if (bgUploadInput) bgUploadInput.click(); return; }
            var dialog = document.getElementById('bgDialog');
            if (dialog.open) { dialog.close(); }
            applyBackgroundToAll(holder.dataset.bg, holder);
        });

        // Der Knopf oeffnet die Bibliothek; die Kacheln liegen nur dort.
        document.getElementById('bgPick').addEventListener('click', function () {
            document.getElementById('bgDialog').showModal();
        });

        document.querySelectorAll('[data-bg-more]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var grid = btn.previousElementSibling;
                grid.querySelectorAll('[data-bg-extra]').forEach(function (tile) { tile.hidden = false; });
                btn.remove();
            });
        });
        document.getElementById('bgDialogClose').addEventListener('click', function () {
            document.getElementById('bgDialog').close();
        });
        document.getElementById('bgDialog').addEventListener('click', function (event) {
            if (event.target === event.currentTarget) { event.currentTarget.close(); }
        });

        // Reiter im Fenster
        document.querySelectorAll('.bg-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                document.querySelectorAll('.bg-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                document.querySelectorAll('.bg-dialog [data-pane]').forEach(function (pane) {
                    pane.style.display = pane.dataset.pane === tab.dataset.tab ? '' : 'none';
                });
            });
        });

        // Favorit umschalten; der Favoriten-Reiter wird direkt nachgefuehrt
        function toggleFavorite(key) {
            apiFetch('api/vehicles/background-favorite.php', {
                method: 'POST',
                body: { key: key }
            }).then(function (res) {
                if (!res.success) { showToast(res.error || {$jsUploadFailed}, 'danger'); return; }
                var favPane = document.querySelector('.bg-grid[data-pane="favorites"]');
                var sourceThumb = null;
                document.querySelectorAll('.bg-thumb[data-bg]').forEach(function (thumb) {
                    if (thumb.dataset.bg !== key) { return; }
                    if (!thumb.closest('[data-pane="favorites"]')) {
                        thumb.classList.toggle('is-fav', res.data.favorite);
                        sourceThumb = sourceThumb || thumb;
                    }
                });
                if (favPane) {
                    if (res.data.favorite && sourceThumb) {
                        favPane.insertBefore(sourceThumb.cloneNode(true), favPane.firstChild);
                    } else if (!res.data.favorite) {
                        favPane.querySelectorAll('.bg-thumb[data-bg]').forEach(function (thumb) {
                            if (thumb.dataset.bg === key) { thumb.remove(); }
                        });
                    }
                    var emptyNote = favPane.querySelector('[data-empty-favorites]');
                    if (emptyNote) {
                        emptyNote.style.display = favPane.querySelectorAll('.bg-thumb[data-bg]').length ? 'none' : '';
                    }
                }
            });
        }

        if (bgUploadInput) bgUploadInput.addEventListener('change', function () {
            if (!bgUploadInput.files.length) { return; }
            var formData = new FormData();
            formData.append('background', bgUploadInput.files[0]);
            bgUploadInput.value = '';
            apiFetch('api/vehicles/upload-background.php', { method: 'POST', body: formData }).then(function (res) {
                if (!res.success) { showToast(res.error || {$jsUploadFailed}, 'danger'); return; }
                // Neue Kachel unter "Eigene" einreihen und direkt anwenden
                var ownPane = document.querySelector('.bg-grid[data-pane="own"]');
                var favTemplate = document.querySelector('.bg-thumb-fav');
                if (ownPane && favTemplate) {
                    var thumb = document.createElement('div');
                    thumb.className = 'bg-thumb';
                    thumb.dataset.bg = res.data.key;
                    thumb.innerHTML = '<button type="button" class="bg-thumb-pick"></button>'
                        + '<button type="button" class="bg-thumb-fav" title="Favorit">' + favTemplate.innerHTML + '</button>'
                        + '<span class="bg-thumb-label"></span>';
                    thumb.querySelector('.bg-thumb-pick').style.backgroundImage = 'url(' + res.data.thumb_url + ')';
                    thumb.querySelector('.bg-thumb-label').textContent = res.data.name;
                    ownPane.insertBefore(thumb, ownPane.children[1] || null);
                }
                document.getElementById('bgDialog').close();
                applyBackgroundToAll(res.data.key, null);
            });
        });
    }


    /**
     * Fragt Spyne in Abstaenden nach dem Ergebnis eines Auftrags.
     * done(ok, timedOut) wird genau einmal aufgerufen.
     */
    function spynePoll(id, key, job, item, done) {
        var tries = 0;
        (function poll() {
            if (++tries > 240) {   // 240 x 15s = 60 Minuten
                bgMarkWorking(id, false);
                done(false, true);
                return;
            }
            setTimeout(function () {
                apiFetch('api/vehicles/image-background.php', {
                    method: 'POST',
                    body: { action: 'spyne_status', image_id: id, background: key, job: job }
                }).then(function (st) {
                    if (st.success && st.data && st.data.pending) { poll(); return; }
                    bgMarkWorking(id, false);
                    if (st.success) {
                        if (item) { item.dataset.cutout = '1'; }
                        refreshImage(st.data, id);
                    } else {
                        showToast(st.error || {$jsUploadFailed}, 'danger');
                    }
                    done(!!st.success, false);
                });
            }, 15000);
        })();
    }

    // Beim Oeffnen der Seite: Fotos mit noch laufendem Spyne-Auftrag
    // weiterverfolgen, damit ein Seitenwechsel nichts verliert.
    document.querySelectorAll('.upload-item[data-spyne-job]').forEach(function (item) {
        var id = parseInt(item.dataset.imageId, 10);
        bgMarkWorking(id, true);
        bgSetHint({$jsBgSpyneWait});
        spynePoll(id, item.dataset.spyneScene || '', item.dataset.spyneJob, item, function (ok, timedOut) {
            if (timedOut) { showToast({$jsBgSpyneSlow}, 'info'); }
        });
    });

    function applyBackgroundToAll(key, swatch) {
        if (bgBusy) { return; }
        bgBusy = true;
        if (swatch) { swatch.classList.add('is-busy'); }
        // Der Knopf zeigt, welcher Hintergrund gerade gesetzt ist
        var bgPickLabel = document.getElementById('bgPickLabel');
        var pickedName = swatch ? swatch.querySelector('.bg-thumb-label') : null;
        if (bgPickLabel && pickedName) {
            bgPickLabel.textContent = {$jsBgCurrent}.replace('{NAME}', pickedName.textContent.trim());
        }
        var ids = bgImageIds();
        bgRunSequential(ids, function (id, pos, total, cb) {
            bgMarkWorking(id, true);
            var item = document.querySelector('.upload-item[data-image-id="' + id + '"]');
            var needsCutout = !item || item.dataset.cutout !== '1';
            bgSetHint((needsCutout ? {$jsBgCutting} : {$jsBgApplied}) + ' (' + pos + '/' + total + ')');
            var optPlate = document.getElementById('bgOptPlate');
            var optBanner = document.getElementById('bgOptBanner');
            apiFetch('api/vehicles/image-background.php', {
                method: 'POST',
                body: {
                    action: 'apply', image_id: id, background: key,
                    plate_logo: optPlate ? (optPlate.checked ? 1 : 0) : undefined,
                    banner: optBanner ? (optBanner.checked ? 1 : 0) : undefined
                }
            }).then(function (res) {
                // Spyne arbeitet im Hintergrund: der Server hat nur
                // angestossen, hier wird alle paar Sekunden nachgefragt.
                if (res.success && res.data && res.data.pending) {
                    bgSetHint({$jsBgSpyneWait} + ' (' + pos + '/' + total + ')');
                    // Spyne braucht je Foto mehrere Minuten. Bis zu zwoelf
                    // Minuten wird nachgefragt; danach laeuft der Auftrag
                    // trotzdem weiter und wird beim naechsten Oeffnen der
                    // Seite abgeholt. Kein "Upload-Fehler" mehr.
                    spynePoll(id, key, res.data.job, item, function (ok, timedOut) {
                        if (timedOut) { showToast({$jsBgSpyneSlow}, 'info'); }
                        cb(ok);
                    });
                    return;
                }
                bgMarkWorking(id, false);
                if (res.success) {
                    if (item) { item.dataset.cutout = '1'; }
                    refreshImage(res.data, id);
                } else {
                    showToast(res.error || {$jsUploadFailed}, 'danger');
                }
                cb(!!res.success);
            });
        }, function (failed) {
            bgBusy = false;
            if (swatch) { swatch.classList.remove('is-busy'); }
            bgSetHint(failed === 0 ? {$jsBgApplied} : {$jsBgChooseHint});
            // Hintergrund von Spyne abgelehnt: Kachel verschwindet sofort,
            // damit niemand ein zweites Mal hineinlaeuft.
            if (failed > 0 && swatch) { swatch.remove(); }
        }, true);
    }

    /** Tauscht Vorschau und grosses Bild aus, ohne die Seite neu zu laden. */
    function refreshImage(data, imageId) {
        var stamp = '?v=' + Date.now();

        var item = document.querySelector('.upload-item[data-image-id="' + imageId + '"]');
        if (item) {
            var img = item.querySelector('img');
            if (img) { img.src = data.thumb_url + stamp; }
        }

        // Der Bildstreifen unter dem grossen Bild zeigt dieselben Fotos und
        // muss deshalb mitwandern, sonst stehen alte und neue nebeneinander.
        var strip = document.querySelector('.gallery-strip img[data-image-id="' + imageId + '"]');
        if (strip) {
            strip.src = data.thumb_url + stamp;
            // Mit Zeitstempel, sonst zeigt der Browser beim naechsten Klick
            // das alte Bild aus seinem Zwischenspeicher.
            strip.dataset.card = data.card_url + stamp;
        }

        // Das grosse Bild nur, wenn gerade dieses Foto dort steht
        var hero = document.getElementById('heroImage');
        if (hero && String(hero.dataset.imageId || '') === String(imageId)) {
            hero.src = data.card_url + stamp;
        }
    }

    /** Bildstreifen: gewaehltes Foto gross zeigen. */
    window.showHeroImage = function (thumb) {
        var hero = document.getElementById('heroImage');
        if (!hero) { return; }
        hero.src = thumb.dataset.card;
        hero.dataset.imageId = thumb.dataset.imageId || '';
        document.querySelectorAll('.gallery-strip img').forEach(function (img) {
            img.classList.remove('active');
        });
        thumb.classList.add('active');
    };

    // ------------------------------------------------------ Dokumentauswertung
    var docDrop = document.getElementById('docDrop');
    var docInput = document.getElementById('docInput');
    var docResult = document.getElementById('docResult');

    if (docDrop) {
        docDrop.addEventListener('click', function () { docInput.click(); });
        docDrop.addEventListener('dragover', function (e) { e.preventDefault(); docDrop.classList.add('dragover'); });
        docDrop.addEventListener('dragleave', function () { docDrop.classList.remove('dragover'); });
        docDrop.addEventListener('drop', function (e) {
            e.preventDefault();
            docDrop.classList.remove('dragover');
            if (e.dataTransfer.files.length) { readDocument(e.dataTransfer.files[0]); }
        });
        docInput.addEventListener('change', function () {
            if (docInput.files.length) { readDocument(docInput.files[0]); }
            docInput.value = '';
        });
    }

    function readDocument(file) {
        docDrop.classList.add('is-busy');
        docResult.innerHTML = '<div class="alert alert-info">' + escapeHtml({$jsDocReading}) + '</div>';

        var formData = new FormData();
        formData.append('document', file);
        formData.append('vehicle_id', String(vehicleId));

        apiFetch('api/ai/extract-document.php', { method: 'POST', body: formData }).then(function (res) {
            docDrop.classList.remove('is-busy');
            if (!res.success) {
                docResult.innerHTML = '';
                showToast(res.error || {$jsUploadFailed}, 'danger');
                return;
            }
            var applied = res.data.applied || [];
            if (applied.length === 0) {
                docResult.innerHTML = '<div class="alert alert-warning">'
                    + escapeHtml({$jsDocNothing}) + '</div>';
                return;
            }
            docResult.innerHTML = '<div class="alert alert-success">'
                + escapeHtml({$jsDocDone}.replace('{COUNT}', String(applied.length))) + '</div>';
            // Neu geladen, damit die übernommenen Werte samt Status im Formular stehen.
            setTimeout(function () { location.reload(); }, 900);
        });
    }

    // ----------------------------------------------------- Ausstattungsliste
    // Eine freie Liste: eintippen, hinzufuegen, per x wieder entfernen.
    // Gespeichert wird sie erst mit dem Formular.
    var featureList = document.getElementById('featureList');
    var featureInput = document.getElementById('featureInput');
    var featureCount = document.getElementById('featureCount');

    function featureValues() {
        return Array.prototype.map.call(
            featureList.querySelectorAll('input[name="features[]"]'),
            function (el) { return el.value.toLowerCase(); }
        );
    }

    function addFeature() {
        var value = featureInput.value.trim().slice(0, 100);
        if (value === '') { return; }
        if (featureValues().indexOf(value.toLowerCase()) !== -1) {
            featureInput.value = '';
            return;   // schon in der Liste
        }
        var item = document.createElement('li');
        item.className = 'feature-item';
        var label = document.createElement('span');
        label.textContent = value;
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('data-remove', '');
        remove.textContent = '\u00d7';
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'features[]';
        hidden.value = value;
        item.appendChild(label);
        item.appendChild(remove);
        item.appendChild(hidden);
        featureList.appendChild(item);
        featureInput.value = '';
        featureInput.focus();
        featureCount.textContent = String(featureList.children.length);
    }

    if (featureList) {
        document.getElementById('featureAddBtn').addEventListener('click', addFeature);
        featureInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();   // Enter fuegt hinzu statt zu speichern
                addFeature();
            }
        });
        featureList.addEventListener('click', function (e) {
            var remove = e.target.closest('[data-remove]');
            if (!remove) { return; }
            remove.closest('.feature-item').remove();
            featureCount.textContent = String(featureList.children.length);
        });
    }

    // -------------------------------------------------------- Bild-Aktionen
    function runImageAction(action, imageId) {
        apiFetch('api/vehicles/image-actions.php', {
            method: 'POST',
            body: { action: action, image_id: imageId }
        }).then(function (res) {
            if (res.success) { location.reload(); }
            else { showToast(res.error || {$jsUploadFailed}, 'danger'); }
        });
    }

    window.imageAction = function (action, imageId) {
        // Loeschen fragt im weichen Fenster nach, nicht im Browser-Hinweis
        if (action === 'delete') {
            window.softConfirm({$jsDeleteImage}, 'danger').then(function (yes) {
                if (yes) { runImageAction(action, imageId); }
            });
            return;
        }
        runImageAction(action, imageId);
    };

    // ------------------------------------------------- Alle Fotos im Fenster
    // Das Raster wandert ins Fenster und danach zurueck. So bleibt es das
    // einzige Raster, und Hauptbild, Loeschen und Freistellen wirken ueberall.
    var allImagesBtn = document.getElementById('allImagesBtn');
    var allImagesDialog = document.getElementById('allImagesDialog');
    if (allImagesBtn && allImagesDialog) {
        var imageGrid = document.getElementById('imageGrid');
        var gridHome = document.getElementById('imageGridHome');
        var allImagesBody = document.getElementById('allImagesBody');
        var photoColumn = document.getElementById('photoColumn');
        var photoCard = document.getElementById('photoCard');

        // Blendet so viele Fotos aus, dass die Spalte nicht tiefer reicht
        // als das Bild daneben. Der Rest bleibt ueber den Knopf erreichbar.
        var fitPhotoGrid = function () {
            if (imageGrid.parentNode === allImagesBody) { return; }
            var items = Array.prototype.slice.call(imageGrid.querySelectorAll('.upload-item'));
            if (items.length === 0 || photoColumn.offsetWidth === photoCard.offsetWidth) {
                // Schmales Fenster: die Spalten stehen untereinander
                allImagesBtn.hidden = true;
                items.forEach(function (item) { item.classList.remove('is-overflow'); });
                return;
            }
            items.forEach(function (item) { item.classList.remove('is-overflow'); });
            allImagesBtn.hidden = false;

            var index = items.length - 1;
            while (index >= 2 && photoCard.offsetHeight > photoColumn.offsetHeight) {
                items[index].classList.add('is-overflow');
                index--;
            }
            allImagesBtn.hidden = index === items.length - 1;
        };

        fitPhotoGrid();
        // Auch andere Teile der Seite duerfen neu ausmessen lassen,
        // etwa wenn der Hintergrund-Schalter Platz braucht.
        window.fitPhotoGrid = fitPhotoGrid;
        var fitTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(fitTimer);
            fitTimer = setTimeout(fitPhotoGrid, 120);
        });

        allImagesBtn.addEventListener('click', function () {
            allImagesBody.appendChild(imageGrid);
            imageGrid.classList.remove('is-clipped');
            allImagesDialog.showModal();
        });

        var closeAllImages = function () {
            imageGrid.classList.add('is-clipped');
            gridHome.parentNode.insertBefore(imageGrid, gridHome);
            allImagesDialog.close();
            fitPhotoGrid();
        };
        document.getElementById('allImagesClose').addEventListener('click', closeAllImages);
        allImagesDialog.addEventListener('click', function (event) {
            if (event.target === allImagesDialog) { closeAllImages(); }
        });
        // Escape schliesst das Fenster selbst; damit das Raster dabei
        // zuverlaessig zurueckwandert, wird die Taste hier abgefangen.
        allImagesDialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeAllImages();
            }
        });
        allImagesDialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeAllImages();
        });
        allImagesDialog.addEventListener('close', function () {
            if (imageGrid.parentNode === allImagesBody) {
                imageGrid.classList.add('is-clipped');
                gridHome.parentNode.insertBefore(imageGrid, gridHome);
                fitPhotoGrid();
            }
        });
    }

    // -------------------------------------------------------- KI-Erkennung
    // ------------------------------------------- Veroeffentlichen-Fenster
    var vehiclePublishBtn = document.getElementById('vehiclePublishBtn');
    var vehiclePublishDialog = document.getElementById('publishDialog');
    if (vehiclePublishBtn && vehiclePublishDialog) {
        vehiclePublishBtn.addEventListener('click', function () { vehiclePublishDialog.showModal(); });
        document.getElementById('publishClose').addEventListener('click', function () { vehiclePublishDialog.close(); });
        vehiclePublishDialog.addEventListener('click', function (event) {
            if (event.target === vehiclePublishDialog) { vehiclePublishDialog.close(); }
        });
    }

    // ------------------------------------------------ PS und kW gekoppelt
    // Wer eines der beiden Felder ausfuellt, bekommt das andere umgerechnet.
    // Nur das jeweils andere Feld wird geschrieben, nie das gerade bearbeitete.
    var hpInput = document.querySelector('input[name="power_hp"]');
    var kwInput = document.querySelector('input[name="power_kw"]');
    if (hpInput && kwInput) {
        var HP_PER_KW = 1.35962;
        hpInput.addEventListener('input', function () {
            var hp = parseFloat(hpInput.value);
            kwInput.value = isFinite(hp) && hp > 0 ? String(Math.round(hp / HP_PER_KW)) : '';
        });
        kwInput.addEventListener('input', function () {
            var kw = parseFloat(kwInput.value);
            hpInput.value = isFinite(kw) && kw > 0 ? String(Math.round(kw * HP_PER_KW)) : '';
        });
    }


    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
