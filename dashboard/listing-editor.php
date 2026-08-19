<?php
/**
 * Vorschau des Inserats (§31).
 *
 * Reine Ansicht: So sieht das Inserat für Interessenten aus. Geändert wird
 * nichts, veröffentlicht wird nichts. Beides läuft über die Inseratseite.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Core\Database;
use App\Repository\VehicleRepository;
use App\Service\ListingService;

$dealershipId = require_dealership();

$vehicleId = (int) ($_GET['id'] ?? 0);
$vehicle = VehicleRepository::find($vehicleId, $dealershipId);
if ($vehicle === null) {
    http_response_code(404);
    require BASE_PATH . '/errors/404.php';
    exit;
}

$listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
$dealership = Database::fetch('SELECT * FROM dealerships WHERE id = :id', ['id' => $dealershipId]);

$images = VehicleRepository::images($vehicleId);
$features = VehicleRepository::features($vehicleId);

$mainImage = null;
foreach ($images as $image) {
    if ((int) $image['is_main'] === 1) {
        $mainImage = $image;
        break;
    }
}
$mainImage ??= $images[0] ?? null;

$vehicleName = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? '')) ?: t('vehicle.new');
$isPublished = (string) $listing['status'] === 'published';

// Technische Daten der Vorschau, in der Reihenfolge, die Käufer zuerst suchen
$specs = [
    t('field.first_registration') => $vehicle['first_registration'] !== null
        ? (string) $vehicle['first_registration']
        : ($vehicle['year'] !== null ? (string) (int) $vehicle['year'] : null),
    t('field.mileage')      => $vehicle['mileage'] !== null ? format_km($vehicle['mileage']) : null,
    t('field.power_hp')     => $vehicle['power_hp'] !== null ? (int) $vehicle['power_hp'] . ' PS' : null,
    t('field.fuel_type')    => $vehicle['fuel_type'] !== null ? t('fuel.' . (string) $vehicle['fuel_type']) : null,
    t('field.transmission') => $vehicle['transmission'] !== null ? t('transmission.' . (string) $vehicle['transmission']) : null,
    t('field.drivetrain')   => $vehicle['drivetrain'] !== null ? t('drivetrain.' . (string) $vehicle['drivetrain']) : null,
    t('field.color')        => $vehicle['color'] !== null ? (string) $vehicle['color'] : null,
    t('field.doors')        => $vehicle['doors'] !== null ? (string) (int) $vehicle['doors'] : null,
    t('field.seats')        => $vehicle['seats'] !== null ? (string) (int) $vehicle['seats'] : null,
    t('field.previous_owners') => $vehicle['previous_owners'] !== null ? (string) (int) $vehicle['previous_owners'] : null,
];
$specs = array_filter($specs, static fn(?string $value): bool => $value !== null && $value !== '');

$pageTitle = t('editor.title') . ': ' . $vehicleName;
$activeNav = 'vehicles';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1><?= t('editor.title') ?></h1>
        <div class="sub">
            <?= t('editor.preview_lead') ?>

        </div>
    </div>
    <a class="btn btn-secondary" href="<?= base_url('dashboard/vehicle.php?id=' . $vehicleId) ?>">
        <?= icon('chevron-left', 15) ?> <?= t('editor.to_vehicle') ?>
    </a>
</div>

<div class="preview-sheet">
    <div class="card" style="overflow:hidden">
        <?php if ($mainImage !== null): ?>
            <img class="preview-hero" id="previewHero"
                 src="<?= e(upload_url((string) ($mainImage['card_path'] ?? $mainImage['file_path']))) ?>" alt="">
            <?php if (count($images) > 1): ?>
                <div class="preview-strip">
                    <?php foreach ($images as $image): ?>
                        <img src="<?= e(upload_url((string) ($image['thumb_path'] ?? $image['file_path']))) ?>"
                             data-card="<?= e(upload_url((string) ($image['card_path'] ?? $image['file_path']))) ?>"
                             class="<?= $image === $mainImage ? 'active' : '' ?>" alt="">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="preview-hero preview-hero-empty">
                <?= icon('camera', 30) ?> <?= t('vehicle.no_images') ?>
            </div>
        <?php endif; ?>

        <div class="card-body">
            <h2 class="preview-title"><?= e($listing['title'] ?? $vehicleName) ?></h2>
            <div class="preview-price"><?= format_price($vehicle['price']) ?></div>

            <?php if ($specs !== []): ?>
                <dl class="preview-specs">
                    <?php foreach ($specs as $label => $value): ?>
                        <div>
                            <dt><?= e($label) ?></dt>
                            <dd><?= e($value) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>

            <div class="preview-text">
                <?= nl2br(e((string) ($listing['description'] ?? t('editor.no_description')))) ?>
            </div>

            <?php if ($features !== []): ?>
                <div class="preview-block">
                    <h3><?= t('field.features') ?></h3>
                    <div class="flex gap-1" style="flex-wrap:wrap">
                        <?php foreach ($features as $feature): ?>
                            <span class="badge badge-neutral"><?= e($feature) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="preview-block preview-dealer">
                <h3><?= (($dealership['account_type'] ?? 'dealer') === 'private') ? 'Verkäufer' : t('header.dealership') ?></h3>
                <div class="fw-600"><?= e((string) ($dealership['name'] ?? '')) ?></div>
                <div class="text-sm text-secondary">
                    <?= e(trim(((string) ($dealership['zip'] ?? '')) . ' ' . ((string) ($dealership['city'] ?? '')))) ?>
                    <?php if (!empty($dealership['phone'])): ?>
                        · <?= e((string) $dealership['phone']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<HTML
<script>
(function () {
    // Bildwechsel in der Vorschau, wie es ein Interessent auch könnte
    var hero = document.getElementById('previewHero');
    document.querySelectorAll('.preview-strip img').forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            if (!hero) { return; }
            hero.src = thumb.dataset.card;
            document.querySelectorAll('.preview-strip img').forEach(function (other) {
                other.classList.remove('active');
            });
            thumb.classList.add('active');
        });
    });
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
