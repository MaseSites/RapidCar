<?php
/**
 * Fahrzeugliste zum Abholen durch eine Verkaufsplattform.
 *
 * Ohne Anmeldung erreichbar, aber nur mit gueltiger Unterschrift in der
 * Adresse: die Plattformen holen die Datei selbstaendig ab und koennen sich
 * dabei nicht anmelden.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Service\VehicleFeedService;

$dealershipId = (int) ($_GET['d'] ?? 0);
$token = (string) ($_GET['t'] ?? '');

if ($dealershipId <= 0 || !VehicleFeedService::isValidToken($dealershipId, $token)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht gefunden.';
    exit;
}

$csv = VehicleFeedService::build($dealershipId);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: inline; filename="fahrzeuge.csv"');
header('Cache-Control: public, max-age=900');
header('Content-Length: ' . strlen($csv));
echo $csv;
