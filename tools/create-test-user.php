<?php
/**
 * Legt einen Testzugang mit Beispieldaten an.
 *
 * Der Zugang ist ein ganz normales Konto: Hochladen, Bearbeiten und
 * Veröffentlichen funktionieren wirklich. Er hängt an einem eigenen Autohaus,
 * damit die echten Daten unberührt bleiben.
 *
 * Zusätzlich entstehen Beispielfotos in testdaten/fotos, die sich im
 * Hochladen-Fenster auswählen lassen. So lässt sich der Ablauf durchspielen,
 * ohne eigene Bilder zu haben.
 *
 * Aufruf:  php tools/create-test-user.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/database/seeds.php';

use App\Core\Database;
use App\Service\CreditService;
use App\Service\ListingService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile.\n");
}

const TEST_LOGIN    = 'testuser';
const TEST_EMAIL    = 'test@rapidcar.local';
// Nur fuer den oertlichen Betrieb gedacht. Auf einem oeffentlichen Server
// darf dieser Zugang nicht bestehen bleiben; das Passwort laesst sich
// ueber die Umgebungsvariable RAPIDCAR_TEST_PASSWORD ueberschreiben.
define('TEST_PASSWORD', getenv('RAPIDCAR_TEST_PASSWORD') ?: 'Test4Vehicle');
const TEST_CREDITS  = 25;

$now = Database::now();

// ----------------------------------------------------- Bestehendes aufräumen
// Auch nach einer Umbenennung der Domain findet das Skript den alten Zugang
$existing = Database::fetch(
    'SELECT * FROM users WHERE email = :e OR username = :u',
    ['e' => TEST_EMAIL, 'u' => TEST_LOGIN]
);
if ($existing !== null) {
    $oldDealership = (int) $existing['dealership_id'];
    foreach (Database::fetchAll('SELECT id FROM vehicles WHERE dealership_id = :d', ['d' => $oldDealership]) as $row) {
        $dir = BASE_PATH . '/uploads/vehicles/' . (int) $row['id'];
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
    foreach (['credit_transactions', 'credit_orders', 'social_posts', 'leads', 'listings', 'vehicles', 'users'] as $table) {
        Database::run("DELETE FROM {$table} WHERE dealership_id = :d", ['d' => $oldDealership]);
    }
    Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $oldDealership]);
    echo "Alter Testzugang entfernt.\n";
}

// ------------------------------------------------------------- Autohaus
$dealershipId = Database::insert('dealerships', [
    'name'       => 'Testgarage',
    'zip'        => '8000',
    'city'       => 'Zürich',
    'phone'      => '+41 44 111 22 33',
    'country'    => 'CH',
    'currency'   => 'CHF',
    'language'   => 'de',
    'credits'    => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);

// -------------------------------------------------------------- Benutzer
$userId = Database::insert('users', [
    'dealership_id'           => $dealershipId,
    'username'                => TEST_LOGIN,
    'first_name'              => 'Test',
    'last_name'               => 'Nutzer',
    'email'                   => TEST_EMAIL,
    'password_hash'           => password_hash(TEST_PASSWORD, PASSWORD_DEFAULT),
    'role'                    => 'dealer_admin',
    'is_active'               => 1,
    'is_demo'                 => 0,   // kein Schreibschutz: Hochladen soll gehen
    'email_verified_at'       => $now,
    'onboarding_completed_at' => $now,
    'created_at'              => $now,
    'updated_at'              => $now,
]);

CreditService::grant($dealershipId, TEST_CREDITS, CreditService::REASON_ADMIN, 'Guthaben für den Testzugang', $userId);

// ------------------------------------------------------------ Testkanal
// Damit sich Veroeffentlichen und Aktualisieren vollstaendig durchspielen
// lassen, ohne ein Konto bei einer echten Plattform zu brauchen.
App\Service\SettingsService::set('testchannel_enabled', '1');
Database::insert('integrations', [
    'dealership_id' => $dealershipId,
    'provider'      => App\Integration\ChannelRegistry::TEST_PROVIDER,
    'status'        => 'connected',
    'connected_at'  => $now,
    'created_at'    => $now,
    'updated_at'    => $now,
]);

// ------------------------------------------------------ Beispielfahrzeuge
$samples = [
    [
        'make' => 'Volkswagen', 'model' => 'Golf', 'variant' => 'GTI Clubsport',
        'year' => 2023, 'first_registration' => '05.2023', 'mileage' => 14200,
        'price' => 46900.0, 'power_hp' => 300, 'power_kw' => 221, 'displacement_ccm' => 1984,
        'transmission' => 'automatic', 'drivetrain' => 'fwd', 'fuel_type' => 'petrol',
        'color' => 'Kings Red', 'interior_color' => 'Schwarz', 'doors' => 5, 'seats' => 5,
        'previous_owners' => 1, 'status' => 'published',
        'features' => ['Panoramadach', 'Navigationssystem', 'Rückfahrkamera', 'LED-Scheinwerfer', 'Sportsitze'],
        'colors' => ['#8d1b1b', '#2b1010'],
    ],
    [
        'make' => 'Skoda', 'model' => 'Octavia', 'variant' => 'Combi RS',
        'year' => 2022, 'first_registration' => '09.2022', 'mileage' => 38400,
        'price' => 34500.0, 'power_hp' => 245, 'power_kw' => 180, 'displacement_ccm' => 1984,
        'transmission' => 'automatic', 'drivetrain' => 'fwd', 'fuel_type' => 'petrol',
        'color' => 'Race Blau', 'interior_color' => 'Schwarz', 'doors' => 5, 'seats' => 5,
        'previous_owners' => 2, 'status' => 'ready',
        'features' => ['Anhängerkupplung', 'Sitzheizung', 'Einparkhilfe', 'Dachreling'],
        'colors' => ['#1d3f7a', '#0f1c33'],
    ],
    [
        'make' => 'Tesla', 'model' => 'Model 3', 'variant' => 'Long Range',
        'year' => 2024, 'first_registration' => '02.2024', 'mileage' => 9100,
        'price' => 42900.0, 'power_hp' => 498, 'power_kw' => 366, 'displacement_ccm' => null,
        'transmission' => 'automatic', 'drivetrain' => 'awd', 'fuel_type' => 'electric',
        'color' => 'Perlweiss', 'interior_color' => 'Weiss', 'doors' => 4, 'seats' => 5,
        'previous_owners' => 1, 'status' => 'draft',
        'features' => ['Panoramadach', 'Wärmepumpe', 'Autopilot'],
        'colors' => ['#e8eaee', '#9aa2ad'],
    ],
];

$views = ['Front', 'Heck', 'Seite', 'Innenraum'];

foreach ($samples as $sample) {
    $vehicleId = Database::insert('vehicles', [
        'dealership_id'      => $dealershipId,
        'created_by'         => $userId,
        'make'               => $sample['make'],
        'model'              => $sample['model'],
        'variant'            => $sample['variant'],
        'year'               => $sample['year'],
        'first_registration' => $sample['first_registration'],
        'mileage'            => $sample['mileage'],
        'price'              => $sample['price'],
        'power_hp'           => $sample['power_hp'],
        'power_kw'           => $sample['power_kw'],
        'displacement_ccm'   => $sample['displacement_ccm'],
        'transmission'       => $sample['transmission'],
        'drivetrain'         => $sample['drivetrain'],
        'fuel_type'          => $sample['fuel_type'],
        'color'              => $sample['color'],
        'interior_color'     => $sample['interior_color'],
        'doors'              => $sample['doors'],
        'seats'              => $sample['seats'],
        'previous_owners'    => $sample['previous_owners'],
        'status'             => $sample['status'],
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);

    foreach ($sample['features'] as $feature) {
        Database::insert('vehicle_features', [
            'vehicle_id' => $vehicleId,
            'feature'    => $feature,
            'source'     => 'manual',
            'created_at' => $now,
        ]);
    }

    foreach ($views as $index => $label) {
        $paths = rapidcar_generate_placeholder_image(
            $vehicleId,
            $sample['make'] . ' ' . $sample['model'],
            $label,
            $sample['colors'][0],
            $sample['colors'][1],
            $index
        );
        if ($paths === null) {
            continue;
        }
        Database::insert('vehicle_images', [
            'vehicle_id'    => $vehicleId,
            'file_path'     => $paths['full'],
            'thumb_path'    => $paths['thumb'],
            'card_path'     => $paths['card'],
            'original_name' => 'test-' . mb_strtolower($label) . '.png',
            'width'         => 1600,
            'height'        => 1000,
            'file_size'     => $paths['size'],
            'sort_order'    => $index,
            'is_main'       => $index === 0 ? 1 : 0,
            'created_at'    => $now,
        ]);
    }

    $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
    if ($sample['status'] !== 'draft') {
        Database::update('listings', (int) $listing['id'], [
            'title'        => $sample['make'] . ' ' . $sample['model'] . ' ' . $sample['variant'],
            'description'  => 'Beispieltext für den Testzugang. Über den Knopf "Aktualisieren" oder den '
                . 'Inserats-Generator lässt sich hier ein echter Text erzeugen.',
            'status'       => $sample['status'] === 'published' ? 'published' : 'ready',
            'published_at' => $sample['status'] === 'published' ? $now : null,
            'updated_at'   => $now,
        ]);
    }
    ListingService::recalculate((int) $listing['id']);
}

// ------------------------------------------------- Fotos zum Hochladen
// Diese Dateien liegen ausserhalb der Anwendung und lassen sich im
// Hochladen-Fenster ganz normal auswählen.
$photoDir = BASE_PATH . '/testdaten/fotos';
if (!is_dir($photoDir)) {
    @mkdir($photoDir, 0775, true);
}
foreach (glob($photoDir . '/*.jpg') ?: [] as $old) {
    @unlink($old);
}

$photoSets = [
    ['Audi RS3 Sportback', ['#26313f', '#0d1218']],
    ['BMW M2 Coupe',       ['#123a52', '#07161f']],
    ['Porsche Cayman GTS', ['#4a4f57', '#1b1d21']],
];
$created = 0;
foreach ($photoSets as $setIndex => [$name, $colors]) {
    foreach ($views as $viewIndex => $label) {
        $paths = rapidcar_generate_placeholder_image(
            900000 + $setIndex,          // eigener Ordner, gehört zu keinem Fahrzeug
            $name,
            $label,
            $colors[0],
            $colors[1],
            $viewIndex
        );
        if ($paths === null) {
            continue;
        }
        $source = BASE_PATH . '/uploads/' . $paths['full'];
        $target = $photoDir . '/' . str_replace(' ', '-', mb_strtolower($name))
            . '-' . mb_strtolower($label) . '.jpg';
        $image = @imagecreatefrompng($source);
        if ($image !== false) {
            imagejpeg($image, $target, 88);
            imagedestroy($image);
            $created++;
        }
    }
    // Der Zwischenordner wird nicht gebraucht
    $tempDir = BASE_PATH . '/uploads/vehicles/' . (900000 + $setIndex);
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempDir);
}

echo "\nTestzugang bereit\n";
echo "-----------------\n";
echo "Benutzername: " . TEST_LOGIN . "\n";
echo "E-Mail:       " . TEST_EMAIL . "\n";
echo "Passwort:     " . TEST_PASSWORD . "\n";
echo "Autohaus:     Testgarage (#{$dealershipId})\n";
echo "Guthaben:     " . TEST_CREDITS . " Inserate\n";
echo "Fahrzeuge:    " . count($samples) . " mit je " . count($views) . " Bildern\n";
echo "Beispielfotos: {$created} Dateien in testdaten/fotos\n";
echo "Testkanal:    verbunden, sendet nichts an eine echte Plattform
";
