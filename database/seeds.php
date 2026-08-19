<?php
/**
 * Demo-Daten (§63): 5 Fahrzeuge, Demo-Autohaus, Demo-Benutzer, Leads,
 * System-Social-Templates und Grundeinstellungen.
 *
 * Demo-Bilder werden per GD als saubere Platzhalter generiert
 * (keine fremden Fotos — Urheberrecht). Aufruf über den Installer.
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Core\Database;
use App\Service\ListingService;

/**
 * Führt alle Seeds aus. Idempotent: bricht ab, wenn Demo-Daten existieren.
 */
function rapidcar_run_seeds(): void
{
    $existing = Database::scalar(
        'SELECT COUNT(*) FROM dealerships WHERE name = :name',
        ['name' => 'Demo Automobile AG']
    );
    if ((int) $existing > 0) {
        return;
    }

    $now = Database::now();

    // ------------------------------------------------------------ Demo-Autohaus
    $dealershipId = Database::insert('dealerships', [
        'name'          => 'Demo Automobile AG',
        'address'       => 'Musterstrasse 12',
        'zip'           => '8000',
        'city'          => 'Zürich',
        'country'       => 'CH',
        'phone'         => '+41 44 000 00 00',
        'email'         => 'info@demo-automobile.ch',
        'website'       => 'https://www.demo-automobile.ch',
        'instagram'     => '@demoautomobile',
        'opening_hours' => "Mo bis Fr 08:00 bis 18:00\nSa 09:00 bis 16:00",
        'currency'      => 'CHF',
        'language'      => 'de',
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    // ------------------------------------------------------------ Demo-Benutzer
    // §64: is_demo=1 → Schreiboperationen in der Anwendung deaktiviert
    Database::insert('users', [
        'dealership_id'           => $dealershipId,
        'first_name'              => 'Demo',
        'last_name'               => 'Benutzer',
        'email'                   => 'demo@rapidcar.local',
        'password_hash'           => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'role'                    => 'dealer_admin',
        'is_active'               => 1,
        'is_demo'                 => 1,
        'email_verified_at'       => $now,
        'onboarding_completed_at' => $now,
        'created_at'              => $now,
        'updated_at'              => $now,
    ]);

    // ------------------------------------------------------------ Fahrzeuge (§63)
    $vehicles = [
        [
            'make' => 'BMW', 'model' => 'M4', 'variant' => 'Competition',
            'year' => 2023, 'first_registration' => '03.2023', 'mileage' => 12900,
            'price' => 89900, 'power_hp' => 510, 'power_kw' => 375, 'displacement_ccm' => 2993,
            'transmission' => 'automatic', 'drivetrain' => 'rwd', 'fuel_type' => 'petrol',
            'color' => 'Frozen Portimao Blau', 'interior_color' => 'Schwarz/Leder Merino',
            'doors' => 2, 'seats' => 4, 'status' => 'published',
            'colors' => ['#1e3a8a', '#3b82f6'],
            'features' => ['M Carbon Schalensitze', 'Head-up Display', 'Harman Kardon', 'Laserlicht', 'M Drive Professional', 'Carbon Exterieurpaket'],
            'description' => "BMW M4 Competition in seltener Individual-Lackierung Frozen Portimao Blau.\n\nDas Fahrzeug befindet sich in einem hervorragenden Zustand und stammt aus erster Hand. Scheckheftgepflegt bei BMW, unfallfrei, Nichtraucherfahrzeug.\n\nHighlights: M Carbon Schalensitze, Head-up Display, Harman Kardon Soundsystem, Laserlicht und M Drive Professional.\n\nProbefahrt und Finanzierung nach Absprache möglich.",
            'images' => ['Frontansicht', 'Seitenansicht', 'Heckansicht', 'Innenraum', 'Cockpit', 'Felgen'],
        ],
        [
            'make' => 'Porsche', 'model' => '911', 'variant' => 'Carrera 4S',
            'year' => 2022, 'first_registration' => '06.2022', 'mileage' => 18500,
            'price' => 154900, 'power_hp' => 450, 'power_kw' => 331, 'displacement_ccm' => 2981,
            'transmission' => 'automatic', 'drivetrain' => 'awd', 'fuel_type' => 'petrol',
            'color' => 'GT-Silber Metallic', 'interior_color' => 'Bordeauxrot/Leder',
            'doors' => 2, 'seats' => 4, 'status' => 'published',
            'colors' => ['#52525b', '#a1a1aa'],
            'features' => ['Sport Chrono Paket', 'PASM', 'BOSE Surround', 'Panoramadach', 'Sportabgasanlage', '14-Wege Sportsitze'],
            'description' => "Porsche 911 Carrera 4S (992) mit Sport Chrono Paket und Sportabgasanlage.\n\nSchweizer Auslieferung, lückenlose Historie beim Porsche Zentrum. Ausstattung u.a. mit PASM Sportfahrwerk, BOSE Surround Sound, Panoramadach und 14-Wege Sportsitzen.\n\nEin gepflegter Elfer für Kenner, jederzeit besichtigungsbereit.",
            'images' => ['Frontansicht', 'Seitenansicht', 'Heckansicht', 'Innenraum', 'Cockpit'],
        ],
        [
            'make' => 'Audi', 'model' => 'RS6', 'variant' => 'Avant Performance',
            'year' => 2023, 'first_registration' => '09.2023', 'mileage' => 8200,
            'price' => 139800, 'power_hp' => 630, 'power_kw' => 463, 'displacement_ccm' => 3996,
            'transmission' => 'automatic', 'drivetrain' => 'awd', 'fuel_type' => 'petrol',
            'color' => 'Nardograu', 'interior_color' => 'Schwarz/Valcona-Leder',
            'doors' => 5, 'seats' => 5, 'status' => 'published',
            'colors' => ['#374151', '#6b7280'],
            'features' => ['Keramikbremse', 'Dynamikpaket plus', 'Bang & Olufsen', 'Matrix LED', 'Nachtsichtassistent', 'Standheizung', 'Panoramadach'],
            'description' => "Audi RS6 Avant Performance in Nardograu, der ultimative Alltagssportler.\n\n630 PS, Keramikbremsanlage, Dynamikpaket plus mit 305 km/h Höchstgeschwindigkeit. Vollausstattung inklusive Bang & Olufsen Advanced Soundsystem, Matrix LED-Scheinwerfer, Nachtsichtassistent und Panoramadach.\n\nErstbesitz, CH-Fahrzeug, Werksgarantie bis 09.2026.",
            'images' => ['Frontansicht', 'Seitenansicht', 'Heckansicht', 'Innenraum', 'Cockpit', 'Kofferraum'],
        ],
        [
            'make' => 'Mercedes-AMG', 'model' => 'GT', 'variant' => '63 S 4MATIC+',
            'year' => 2021, 'first_registration' => '11.2021', 'mileage' => 24600,
            'price' => 119500, 'power_hp' => 639, 'power_kw' => 470, 'displacement_ccm' => 3982,
            'transmission' => 'automatic', 'drivetrain' => 'awd', 'fuel_type' => 'petrol',
            'color' => 'Obsidianschwarz', 'interior_color' => 'Macchiatobeige/Nappa',
            'doors' => 5, 'seats' => 4, 'status' => 'ready',
            'colors' => ['#18181b', '#3f3f46'],
            'features' => ['AMG Aerodynamik-Paket', 'Burmester 3D', 'Fahrassistenz-Paket', 'Multibeam LED', 'AMG Performance Sitze'],
            'description' => "Mercedes-AMG GT 63 S 4MATIC+ Viertürer in Obsidianschwarz mit hellem Nappa-Interieur.\n\nGepflegter Zustand, Service neu, 4 Türen und 639 PS vereinen Alltag und Performance.",
            'images' => ['Frontansicht', 'Seitenansicht', 'Innenraum', 'Cockpit'],
        ],
        [
            'make' => 'Lamborghini', 'model' => 'Huracán', 'variant' => 'EVO Spyder',
            'year' => 2022, 'first_registration' => '04.2022', 'mileage' => 9800,
            'price' => 289000, 'power_hp' => 640, 'power_kw' => 471, 'displacement_ccm' => 5204,
            'transmission' => 'automatic', 'drivetrain' => 'awd', 'fuel_type' => 'petrol',
            'color' => 'Verde Mantis', 'interior_color' => 'Nero Ade/Alcantara',
            'doors' => 2, 'seats' => 2, 'status' => 'draft',
            'colors' => ['#15803d', '#4ade80'],
            'features' => ['Lifting System', 'Sensonum Soundsystem', 'Carbon Interieur'],
            'description' => '',
            'images' => ['Frontansicht', 'Seitenansicht'],
        ],
    ];

    $vehicleIds = [];
    foreach ($vehicles as $data) {
        $vehicleId = Database::insert('vehicles', [
            'dealership_id'      => $dealershipId,
            'make'               => $data['make'],
            'model'              => $data['model'],
            'variant'            => $data['variant'],
            'year'               => $data['year'],
            'first_registration' => $data['first_registration'],
            'mileage'            => $data['mileage'],
            'price'              => $data['price'],
            'power_hp'           => $data['power_hp'],
            'power_kw'           => $data['power_kw'],
            'displacement_ccm'   => $data['displacement_ccm'],
            'transmission'       => $data['transmission'],
            'drivetrain'         => $data['drivetrain'],
            'fuel_type'          => $data['fuel_type'],
            'color'              => $data['color'],
            'interior_color'     => $data['interior_color'],
            'doors'              => $data['doors'],
            'seats'              => $data['seats'],
            'description'        => $data['description'],
            'status'             => $data['status'],
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $vehicleIds[] = $vehicleId;

        foreach ($data['features'] as $feature) {
            Database::insert('vehicle_features', [
                'vehicle_id' => $vehicleId,
                'feature'    => $feature,
                'source'     => 'manual',
                'created_at' => $now,
            ]);
        }

        // Platzhalterbilder generieren
        foreach ($data['images'] as $index => $label) {
            $paths = rapidcar_generate_placeholder_image(
                $vehicleId,
                $data['make'] . ' ' . $data['model'],
                $label,
                $data['colors'][0],
                $data['colors'][1],
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
                'original_name' => 'demo-' . strtolower($label) . '.png',
                'width'         => 1600,
                'height'        => 1000,
                'file_size'     => $paths['size'],
                'sort_order'    => $index,
                'is_main'       => $index === 0 ? 1 : 0,
                'created_at'    => $now,
            ]);
        }

        // Inserat mit Titel/Beschreibung (ausser beim Entwurfs-Fahrzeug)
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        if ($data['status'] !== 'draft') {
            Database::update('listings', (int) $listing['id'], [
                'title'       => $data['make'] . ' ' . $data['model'] . ' ' . $data['variant']
                    . ', ' . $data['power_hp'] . ' PS',
                'description' => $data['description'],
                'status'      => $data['status'] === 'published' ? 'published' : 'ready',
                'published_at' => $data['status'] === 'published' ? $now : null,
                'updated_at'  => $now,
            ]);
        }
        ListingService::recalculate((int) $listing['id']);
    }

    // ------------------------------------------------------------ Demo-Leads (§40)
    $lead1 = Database::insert('leads', [
        'dealership_id'  => $dealershipId,
        'vehicle_id'     => $vehicleIds[0],
        'customer_name'  => 'Max Müller',
        'customer_email' => 'max.mueller@example.com',
        'customer_phone' => '+41 79 000 00 01',
        'status'         => 'new',
        'score'          => 87,
        'source'         => 'Website',
        'created_at'     => date('Y-m-d H:i:s', time() - 300),
        'updated_at'     => date('Y-m-d H:i:s', time() - 300),
    ]);
    Database::insert('messages', [
        'lead_id'     => $lead1,
        'direction'   => 'inbound',
        'sender_name' => 'Max Müller',
        'body'        => 'Guten Tag, ist das Fahrzeug noch verfügbar? Wäre eine Probefahrt am Samstag möglich?',
        'created_at'  => date('Y-m-d H:i:s', time() - 300),
    ]);

    $lead2 = Database::insert('leads', [
        'dealership_id'  => $dealershipId,
        'vehicle_id'     => $vehicleIds[1],
        'customer_name'  => 'Anna Meier',
        'customer_email' => 'anna.meier@example.com',
        'status'         => 'active',
        'score'          => 72,
        'source'         => 'Website',
        'created_at'     => date('Y-m-d H:i:s', time() - 3600),
        'updated_at'     => date('Y-m-d H:i:s', time() - 3600),
    ]);
    Database::insert('messages', [
        'lead_id'     => $lead2,
        'direction'   => 'inbound',
        'sender_name' => 'Anna Meier',
        'body'        => 'Hallo, gibt es zum 911 eine lückenlose Servicehistorie? Und wäre ein Eintausch meines Cayman möglich?',
        'created_at'  => date('Y-m-d H:i:s', time() - 3600),
    ]);
    Database::insert('messages', [
        'lead_id'     => $lead2,
        'direction'   => 'outbound',
        'sender_name' => 'Demo Automobile AG',
        'body'        => 'Guten Tag Frau Meier, vielen Dank für Ihre Anfrage. Ja, die Historie ist lückenlos dokumentiert. Einen Eintausch prüfen wir gerne. Können Sie uns einige Angaben zu Ihrem Cayman senden?',
        'created_at'  => date('Y-m-d H:i:s', time() - 3000),
    ]);

    // ------------------------------------------------------- Social-Templates (§38)
    $templates = [
        ['key' => 'luxury',  'name' => 'Luxury',  'config' => ['bg' => '#0d0d0f', 'accent' => '#c9a227', 'text' => '#ffffff', 'font' => 'serif',      'layout' => 'centered']],
        ['key' => 'minimal', 'name' => 'Minimal', 'config' => ['bg' => '#ffffff', 'accent' => '#111827', 'text' => '#111827', 'font' => 'sans-serif', 'layout' => 'clean']],
        ['key' => 'sport',   'name' => 'Sport',   'config' => ['bg' => '#111111', 'accent' => '#ef4444', 'text' => '#ffffff', 'font' => 'sans-serif', 'layout' => 'diagonal']],
        ['key' => 'modern',  'name' => 'Modern',  'config' => ['bg' => '#0f172a', 'accent' => '#38bdf8', 'text' => '#f8fafc', 'font' => 'sans-serif', 'layout' => 'split']],
        ['key' => 'classic', 'name' => 'Classic', 'config' => ['bg' => '#f5f0e8', 'accent' => '#7c2d12', 'text' => '#1c1917', 'font' => 'serif',      'layout' => 'framed']],
    ];
    foreach ($templates as $tpl) {
        Database::insert('social_templates', [
            'dealership_id' => null,
            'template_key'  => $tpl['key'],
            'name'          => $tpl['name'],
            'config'        => json_encode($tpl['config'], JSON_UNESCAPED_UNICODE),
            'is_system'     => 1,
            'created_at'    => $now,
        ]);
    }

    // ------------------------------------------------------------ Einstellungen
    Database::run(
        'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :t)',
        ['k' => 'ai_mode', 'v' => 'mock', 't' => $now]
    );
}

/**
 * Erzeugt ein Platzhalterbild (Verlauf + stilisierte Fahrzeug-Silhouette + Text)
 * in drei Grössen. Gibt relative Pfade (zu /uploads) zurück oder null.
 *
 * @return array{full: string, card: string, thumb: string, size: int}|null
 */
function rapidcar_generate_placeholder_image(
    int $vehicleId,
    string $vehicleName,
    string $viewLabel,
    string $colorFrom,
    string $colorTo,
    int $index
): ?array {
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $dir = BASE_PATH . '/uploads/vehicles/' . $vehicleId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }

    $width = 1600;
    $height = 1000;
    $img = imagecreatetruecolor($width, $height);

    // Verlauf
    [$r1, $g1, $b1] = sscanf(ltrim($colorFrom, '#'), '%02x%02x%02x');
    [$r2, $g2, $b2] = sscanf(ltrim($colorTo, '#'), '%02x%02x%02x');
    for ($y = 0; $y < $height; $y++) {
        $t = $y / $height;
        $color = imagecolorallocate(
            $img,
            (int) ($r1 + ($r2 - $r1) * $t),
            (int) ($g1 + ($g2 - $g1) * $t),
            (int) ($b1 + ($b2 - $b1) * $t)
        );
        imageline($img, 0, $y, $width, $y, $color);
    }

    // Stilisierte Fahrzeug-Silhouette
    $dark = imagecolorallocatealpha($img, 10, 10, 14, 40);
    $cx = (int) ($width / 2);
    $cy = (int) ($height * 0.62);
    $bodyW = (int) ($width * 0.56);
    $bodyH = (int) ($height * 0.16);
    // Karosserie
    imagefilledrectangle($img, $cx - (int)($bodyW / 2), $cy - $bodyH, $cx + (int)($bodyW / 2), $cy, $dark);
    // Kabine (Trapez)
    $cabW = (int) ($bodyW * 0.55);
    $cabH = (int) ($bodyH * 1.1);
    imagefilledpolygon($img, [
        $cx - (int)($cabW / 2), $cy - $bodyH,
        $cx - (int)($cabW / 2) + (int)($cabW * 0.18), $cy - $bodyH - $cabH,
        $cx + (int)($cabW / 2) - (int)($cabW * 0.12), $cy - $bodyH - $cabH,
        $cx + (int)($cabW / 2), $cy - $bodyH,
    ], $dark);
    // Räder
    $wheelR = (int) ($bodyH * 0.75);
    $wheelDark = imagecolorallocatealpha($img, 5, 5, 8, 20);
    imagefilledellipse($img, $cx - (int)($bodyW * 0.3), $cy, $wheelR * 2, $wheelR * 2, $wheelDark);
    imagefilledellipse($img, $cx + (int)($bodyW * 0.3), $cy, $wheelR * 2, $wheelR * 2, $wheelDark);
    $rim = imagecolorallocatealpha($img, 220, 220, 228, 60);
    imagefilledellipse($img, $cx - (int)($bodyW * 0.3), $cy, $wheelR, $wheelR, $rim);
    imagefilledellipse($img, $cx + (int)($bodyW * 0.3), $cy, $wheelR, $wheelR, $rim);

    // Beschriftung (Bitmap-Font, hochskaliert wäre pixelig — daher dezent klein)
    $white = imagecolorallocatealpha($img, 255, 255, 255, 25);
    $font = 5;
    $text = strtoupper($vehicleName);
    $textW = imagefontwidth($font) * strlen($text);
    imagestring($img, $font, (int) (($width - $textW) / 2), (int) ($height * 0.82), $text, $white);
    $sub = strtoupper($viewLabel) . ' - DEMO-BILD';
    $subW = imagefontwidth(3) * strlen($sub);
    imagestring($img, 3, (int) (($width - $subW) / 2), (int) ($height * 0.82) + 26, $sub, $white);

    // Speichern: full / card / thumb
    $baseName = 'demo-' . $vehicleId . '-' . $index . '-' . substr(bin2hex(random_bytes(6)), 0, 8);
    $relDir = 'vehicles/' . $vehicleId . '/';
    $paths = ['full' => $relDir . $baseName . '.png'];
    imagepng($img, BASE_PATH . '/uploads/' . $paths['full'], 6);

    foreach ([['card', 800, 500], ['thumb', 320, 200]] as [$sizeKey, $w, $h]) {
        $resized = imagecreatetruecolor($w, $h);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $w, $h, $width, $height);
        $paths[$sizeKey] = $relDir . $baseName . '-' . $sizeKey . '.png';
        imagepng($resized, BASE_PATH . '/uploads/' . $paths[$sizeKey], 6);
        imagedestroy($resized);
    }
    imagedestroy($img);

    $fileSize = (int) @filesize(BASE_PATH . '/uploads/' . $paths['full']);
    return ['full' => $paths['full'], 'card' => $paths['card'], 'thumb' => $paths['thumb'], 'size' => $fileSize];
}
