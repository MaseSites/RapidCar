<?php
/**
 * Erfolgsseite nach dem Veröffentlichen: Hauptbild, Bestätigung und die
 * beiden nächsten Schritte.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';

use App\Repository\VehicleRepository;

$dealershipId = require_dealership();

$vehicleId = (int) ($_GET['id'] ?? 0);
$vehicle = VehicleRepository::find($vehicleId, $dealershipId);
if ($vehicle === null || (string) $vehicle['status'] !== 'published') {
    redirect('dashboard/vehicles.php');
}

$images = VehicleRepository::images($vehicleId);
$mainImage = $images[0] ?? null;
$vehicleName = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? ''));

$pageTitle = t('publish.success_title');
$activeNav = 'vehicles';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="published-wrap">
    <div class="published-card">
        <?php if ($mainImage !== null): ?>
            <img class="published-photo" src="<?= e(upload_url((string) ($mainImage['card_path'] ?? $mainImage['file_path']))) ?>" alt="<?= e($vehicleName) ?>">
        <?php endif; ?>
        <div class="published-check"><?= icon('check', 26) ?></div>
        <h1><?= t('publish.success_title') ?></h1>
        <p class="text-secondary">
            <?= e($vehicleName !== '' ? $vehicleName : t('vehicles.unnamed')) ?>
            <?= $vehicle['price'] !== null ? ' · ' . format_price($vehicle['price']) : '' ?>
        </p>
        <div class="published-actions">
            <a class="btn btn-secondary btn-lg" href="<?= base_url('dashboard/vehicles.php') ?>">
                <?= t('publish.to_all') ?>
            </a>
            <a class="btn btn-secondary btn-lg" href="<?= base_url('dashboard/create-vehicle.php') ?>">
                <?= icon('plus', 16) ?> <?= t('publish.create_new') ?>
            </a>
            <a class="btn btn-primary btn-lg" href="<?= base_url('dashboard/social.php?vehicle=' . $vehicleId) ?>">
                <?= icon('instagram', 16) ?> <?= t('publish.instagram_post') ?>
            </a>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
