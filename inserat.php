<?php
/**
 * Oeffentliche Fahrzeugseite.
 *
 * Verkaufsplattformen verlangen fuer jedes Fahrzeug eine erreichbare
 * Adresse. Diese Seite ist ohne Anmeldung sichtbar, aber nur fuer
 * veroeffentlichte Fahrzeuge: Entwuerfe bleiben privat.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Core\Database;
use App\Repository\VehicleRepository;

$vehicleId = (int) ($_GET['id'] ?? 0);
$vehicle = $vehicleId > 0
    ? Database::fetch(
        "SELECT * FROM vehicles WHERE id = :id AND status = 'published'",
        ['id' => $vehicleId]
    )
    : null;

if ($vehicle === null) {
    http_response_code(404);
    require BASE_PATH . '/errors/404.php';
    exit;
}

$dealership = Database::fetch(
    'SELECT name, city, zip, country, phone FROM dealerships WHERE id = :id',
    ['id' => (int) $vehicle['dealership_id']]
) ?? [];

$listing = Database::fetch(
    'SELECT title, description FROM listings WHERE vehicle_id = :v',
    ['v' => $vehicleId]
) ?? [];

$images = VehicleRepository::images($vehicleId);
$features = VehicleRepository::features($vehicleId);

$name = trim((string) ($vehicle['make'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''));
$title = trim((string) ($listing['title'] ?? ''));
if ($title === '') {
    $title = trim($name . ' ' . (string) ($vehicle['variant'] ?? ''));
}

$pageTitle = $title !== '' ? $title : 'Fahrzeug';
require BASE_PATH . '/includes/layout/public-header.php';
?>

<div class="content-page">
    <h1><?= e($title) ?></h1>
    <?php if ((string) ($vehicle['variant'] ?? '') !== ''): ?>
        <p class="text-muted" style="margin-top:-8px"><?= e((string) $vehicle['variant']) ?></p>
    <?php endif; ?>

    <?php if ($images !== []): ?>
        <div class="pub-gallery">
            <?php foreach (array_slice($images, 0, 12) as $image): ?>
                <?php
                $path = (string) ($image['composed_path'] ?? '') !== ''
                    ? (string) $image['composed_path']
                    : (string) ($image['card'] ?? $image['file_path'] ?? '');
                ?>
                <?php if ($path !== ''): ?>
                    <img src="<?= e(upload_url($path)) ?>" alt="<?= e($title) ?>" loading="lazy">
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($vehicle['price'] !== null && (float) $vehicle['price'] > 0): ?>
        <p class="pub-price"><?= e(format_price($vehicle['price'])) ?></p>
    <?php endif; ?>

    <h2>Fahrzeugdaten</h2>
    <table class="table">
        <?php
        $rows = [
            'Marke'            => (string) ($vehicle['make'] ?? ''),
            'Modell'           => (string) ($vehicle['model'] ?? ''),
            'Inverkehrsetzung' => (string) ($vehicle['first_registration'] ?? ''),
            'Kilometer'        => $vehicle['mileage'] !== null ? format_km($vehicle['mileage']) : '',
            'Leistung'         => $vehicle['power_hp'] !== null ? ((int) $vehicle['power_hp'] . ' PS') : '',
            'Treibstoff'       => (string) ($vehicle['fuel_type'] ?? '') !== '' ? t('fuel.' . (string) $vehicle['fuel_type']) : '',
            'Getriebe'         => (string) ($vehicle['transmission'] ?? '') !== '' ? t('transmission.' . (string) $vehicle['transmission']) : '',
            'Farbe'            => (string) ($vehicle['color'] ?? ''),
            'Türen'            => $vehicle['doors'] !== null ? (string) (int) $vehicle['doors'] : '',
            'Sitze'            => $vehicle['seats'] !== null ? (string) (int) $vehicle['seats'] : '',
        ];
        ?>
        <?php foreach ($rows as $label => $value): ?>
            <?php if (trim((string) $value) !== ''): ?>
                <tr><th style="text-align:left;width:220px"><?= e($label) ?></th><td><?= e((string) $value) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
    </table>

    <?php if (trim((string) ($listing['description'] ?? '')) !== ''): ?>
        <h2>Beschreibung</h2>
        <p><?= nl2br(e((string) $listing['description'])) ?></p>
    <?php endif; ?>

    <?php if ($features !== []): ?>
        <h2>Ausstattung</h2>
        <ul class="pub-features">
            <?php foreach ($features as $feature): ?>
                <li><?= e($feature) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>Anbieter</h2>
    <p>
        <?= e((string) ($dealership['name'] ?? '')) ?><br>
        <?php if ((string) ($dealership['zip'] ?? '') !== '' || (string) ($dealership['city'] ?? '') !== ''): ?>
            <?= e(trim((string) ($dealership['zip'] ?? '') . ' ' . (string) ($dealership['city'] ?? ''))) ?><br>
        <?php endif; ?>
        <?php if ((string) ($dealership['phone'] ?? '') !== ''): ?>
            <a href="tel:<?= e((string) $dealership['phone']) ?>"><?= e((string) $dealership['phone']) ?></a>
        <?php endif; ?>
    </p>
</div>

<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
