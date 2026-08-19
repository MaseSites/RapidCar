<?php
/**
 * Schlankes Testskript — kein Composer, kein PHPUnit (Shared-Hosting-tauglich).
 *
 * Aufruf:  php tests/run.php
 * Testet: Validator, Encryption, ListingScoreEngine, Sicherheitsfilter (§43),
 *         Bewertungs-Helfer, Lang-Fallback.
 */

declare(strict_types=1);

define('RAPIDCAR', true);
define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/includes/autoload.php';
require BASE_PATH . '/includes/functions.php';
require BASE_PATH . '/includes/icons.php';

use App\AI\AILeadService;
use App\Core\Config;
use App\Core\Encryption;
use App\Core\Lang;
use App\Core\Validator;
use App\Integration\AutoScoutClient;
use App\Integration\AutoScoutService;
use App\Integration\ChannelRegistry;
use App\Service\CreditService;
use App\Service\ListingScoreEngine;

$passed = 0;
$failed = 0;

function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        echo "  ✗ {$name}\n";
    }
}

Config::load();

// ---------------------------------------------------------------- Validator
echo "Validator\n";
$v = new Validator(['email' => 'kein-email', 'name' => '', 'age' => '17']);
$v->required('name', 'Name')->email('email', 'E-Mail')->integer('age', 'Alter')->range('age', 'Alter', 18, 99);
check('erkennt fehlende Pflichtfelder', isset($v->errors()['name']));
check('erkennt ungültige E-Mail', isset($v->errors()['email']));
check('erkennt Bereichsverletzung', isset($v->errors()['age']));

$v2 = new Validator(['email' => 'test@example.com', 'pw' => 'geheim123', 'pw2' => 'geheim123']);
$v2->email('email', 'E-Mail')->minLength('pw', 'Passwort', 8)->matches('pw2', 'pw', 'Wiederholung');
check('akzeptiert gültige Eingaben', $v2->passes());

// --------------------------------------------------------------- Encryption
echo "Encryption (AES-256-GCM)\n";
if ((string) Config::get('app.key', '') === '') {
    Config::set('app.key', Encryption::generateKey());
}
$secret = 'autoscout-token-äöü-12345';
$encrypted = Encryption::encrypt($secret);
check('verschlüsselt (kein Klartext)', !str_contains($encrypted, 'autoscout'));
check('entschlüsselt korrekt', Encryption::decrypt($encrypted) === $secret);
$tampered = base64_encode(substr((string) base64_decode($encrypted, true), 0, -1) . 'X');
try {
    Encryption::decrypt($tampered);
    check('erkennt manipulierte Daten', false);
} catch (\RuntimeException) {
    check('erkennt manipulierte Daten', true);
}

// ------------------------------------------------------- ListingScoreEngine
echo "ListingScoreEngine (regelbasiert, §32–§35)\n";
$emptyResult = ListingScoreEngine::evaluate([], [], [], []);
check('leeres Inserat → niedriger Score', $emptyResult['total'] < 30);
check('leeres Inserat → kritische Empfehlungen', count($emptyResult['recommendations']) >= 3);

$goodVehicle = [
    'make' => 'BMW', 'model' => 'M4', 'variant' => 'Competition', 'year' => 2023,
    'first_registration' => '03.2023', 'mileage' => 12900, 'price' => 0,
    'power_hp' => 510, 'power_kw' => 375, 'displacement_ccm' => 2993,
    'transmission' => 'automatic', 'drivetrain' => 'rwd', 'fuel_type' => 'petrol',
    'color' => 'Blau', 'interior_color' => 'Schwarz', 'doors' => 2, 'seats' => 4, 'vin' => 'X',
];
$goodListing = [
    'title' => 'BMW M4 Competition – 510 PS Traumzustand',
    'description' => str_repeat('Sehr gepflegtes Fahrzeug mit Head-up Display und Harman Kardon. ', 12),
];
$goodImages = array_fill(0, 8, ['width' => 1600, 'height' => 1000, 'is_main' => 0]);
$goodImages[0]['is_main'] = 1;
$goodResult = ListingScoreEngine::evaluate($goodVehicle, $goodListing, $goodImages, ['Head-up Display', 'Harman Kardon']);
check('vollständiges Inserat → hoher Foto-Score', ($goodResult['scores']['photos'] ?? 0) >= 90);
check('vollständiges Inserat → hoher Titel-Score', ($goodResult['scores']['title'] ?? 0) >= 80);
check('Preis 0 → Preis-Score 0 + Empfehlung', $goodResult['scores']['price'] === 0);
check('deterministisch (2 Läufe identisch)', $goodResult === ListingScoreEngine::evaluate($goodVehicle, $goodListing, $goodImages, ['Head-up Display', 'Harman Kardon']));

// Preis ohne Vergleichsdaten → null (Ehrlichkeitsregel §72)
$pricedVehicle = $goodVehicle;
$pricedVehicle['price'] = 89900;
$pricedVehicle['make'] = 'MarkeOhneVergleich' . bin2hex(random_bytes(3));
$pricedResult = ListingScoreEngine::evaluate($pricedVehicle, $goodListing, $goodImages, []);
check('Preis ohne Vergleichsdaten → null statt erfundener Zahl', $pricedResult['scores']['price'] === null);

// ------------------------------------------------- Sicherheitsfilter (§43)
echo "AILeadService-Sicherheitsfilter (§43)\n";
$unsafe = 'Guten Tag. Wir geben Ihnen gerne 10% Rabatt auf das Fahrzeug. Das Fahrzeug ist unfallfrei, das garantieren wir. Eine Probefahrt ist jederzeit möglich.';
$filtered = AILeadService::applySafetyFilter($unsafe);
check('entfernt Rabatt-Zusagen', stripos($filtered, 'rabatt') === false);
check('entfernt Unfallfrei-/Garantie-Zusagen', stripos($filtered, 'unfallfrei') === false && stripos($filtered, 'garantieren') === false);
check('behält unbedenkliche Sätze', str_contains($filtered, 'Probefahrt'));

// ----------------------------------------------------------- Helferfunktionen
echo "Bewertungs-Helfer (§33, SVG-Pfeile)\n";
check('Score 95: excellent + SVG', rating_class(95) === 'rating-excellent' && str_contains(rating_arrow(95), '<svg') && str_contains(rating_arrow(95), 'rating-excellent'));
check('Score 80: good', rating_class(80) === 'rating-good' && str_contains(rating_arrow(80), 'rating-good'));
check('Score 60: warning', rating_class(60) === 'rating-warning' && str_contains(rating_arrow(60), 'rating-warning'));
check('Score 40: bad', rating_class(40) === 'rating-bad' && str_contains(rating_arrow(40), 'rating-bad'));
check('Score 10: critical', rating_class(10) === 'rating-critical' && str_contains(rating_arrow(10), 'rating-critical'));
check('Preisformat CH', format_price(89900) === "CHF 89'900");

echo "Verkaufsattraktivität (§23)\n";
$attr1 = ListingScoreEngine::attractiveness(92, 8, true);
$attr2 = ListingScoreEngine::attractiveness(92, 8, true);
check('deterministisch bei gleichem Input', $attr1 === $attr2);
check('hoher Score + Fotos + Preis: Hoch', $attr1['label'] === 'Hoch');
$attrLow = ListingScoreEngine::attractiveness(20, 0, false);
check('schwaches Inserat: Niedrig', $attrLow['label'] === 'Niedrig');
check('Wertebereich 0 bis 100', $attr1['value'] >= 0 && $attr1['value'] <= 100 && $attrLow['value'] >= 0);

echo "Icon-System\n";
check('icon() liefert SVG ohne Emoji', str_starts_with(icon('car'), '<svg') && str_contains(icon('car'), 'stroke="currentColor"'));
check('unbekanntes Icon: Fallback statt Fehler', str_starts_with(icon('gibt-es-nicht'), '<svg'));

echo "Lang und Mehrsprachigkeit\n";
check('unbekannter Schlüssel: Schlüssel selbst (nie leer)', t('nicht.vorhanden.xyz') === 'nicht.vorhanden.xyz');
check('Platzhalter-Ersetzung', str_contains(t('auth.login.throttled', ['minutes' => 7]), '7'));

$germanDashboard = t('dash.today.title');
Lang::setLocale('en');
check('Englisch wird geladen', t('dash.today.title') === 'What should I do today?');
Lang::setLocale('fr');
check('Französisch wird geladen', str_contains(t('dash.today.title'), 'aujourd'));
Lang::setLocale('it');
check('Italienisch wird geladen', str_contains(t('dash.today.title'), 'oggi'));
Lang::setLocale('en');
check('Fallback auf Deutsch bei fehlendem Schlüssel', t('nav.imprint') !== 'nav.imprint');
Lang::setLocale('de');
check('Rückkehr zu Deutsch', t('dash.today.title') === $germanDashboard);
check('4 Sprachen verfügbar', count(Lang::AVAILABLE) === 4 && isset(Lang::AVAILABLE['de'], Lang::AVAILABLE['en'], Lang::AVAILABLE['fr'], Lang::AVAILABLE['it']));
check('unbekannte Sprache wird abgelehnt', !Lang::isSupported('xx') && Lang::isSupported('fr'));

// Alle Sprachdateien müssen ladbar sein und Schlüssel liefern
$allLoadable = true;
foreach (array_keys(Lang::AVAILABLE) as $locale) {
    $strings = require BASE_PATH . '/lang/' . $locale . '.php';
    if (!is_array($strings) || $strings === []) {
        $allLoadable = false;
    }
}
check('alle Sprachdateien laden sauber', $allLoadable);

echo "Guthaben (CreditService)\n";
$packages = CreditService::packages();
check('5 Pakete definiert', count($packages) === 5);
check('1 Inserat kostet 10 CHF', $packages['single']['credits'] === 1 && $packages['single']['price'] === 10.0);
check('5 Inserate kosten 40 CHF', $packages['small']['credits'] === 5 && $packages['small']['price'] === 40.0);
check('10 Inserate kosten 70 CHF', $packages['medium']['credits'] === 10 && $packages['medium']['price'] === 70.0);
check('50 und 100 Inserate vorhanden', $packages['large']['credits'] === 50 && $packages['xlarge']['credits'] === 100);

$cheaperPerUnit = true;
$previous = PHP_FLOAT_MAX;
foreach ($packages as $package) {
    $perUnit = $package['price'] / $package['credits'];
    if ($perUnit > $previous) {
        $cheaperPerUnit = false;
    }
    $previous = $perUnit;
}
check('grössere Pakete sind pro Inserat günstiger', $cheaperPerUnit);
check('unbekanntes Paket: null', CreditService::package('gibt-es-nicht') === null);
check('ohne Zahlungsanbieter: paymentConfigured false', CreditService::paymentConfigured() === false);

echo "Kanäle: Region, Suche und Zugangsdaten\n";
check('Schweizer Händler bekommt mobile.de nicht angeboten',
    !isset(ChannelRegistry::forCountry('CH')['mobile_de']));
check('Schweizer Händler sieht AutoScout24 und tutti.ch',
    isset(ChannelRegistry::forCountry('CH')['autoscout24'])
    && isset(ChannelRegistry::forCountry('CH')['tutti']));
check('deutscher Händler bekommt mobile.de angeboten',
    isset(ChannelRegistry::forCountry('DE')['mobile_de']));
check('deutscher Händler bekommt car4you nicht angeboten',
    !isset(ChannelRegistry::forCountry('DE')['car4you']));
check('soziale Netzwerke gelten überall',
    isset(ChannelRegistry::forCountry('CH')['instagram'])
    && isset(ChannelRegistry::forCountry('DE')['instagram'])
    && isset(ChannelRegistry::forCountry('IT')['tiktok']));
check('ohne Land bleibt die volle Liste',
    count(ChannelRegistry::forCountry(null)) === count(ChannelRegistry::all()));
check('ausgeblendete Kanäle sind abrufbar',
    ChannelRegistry::outsideCountry('CH') !== []
    && array_key_exists('mobile_de', ChannelRegistry::outsideCountry('CH')));

// Zugangsdaten je Kanal: verschlüsselt, Geheimnis bleibt verborgen
App\Integration\ChannelCredentials::save('__test_channel', [
    'client_id'     => 'id-123',
    'client_secret' => 'sehr-geheim',
    'auth_url'      => 'https://example.invalid/auth',
]);
$storedRaw = (string) App\Service\SettingsService::get('channel_credentials.__test_channel', '');
check('Zugangsdaten liegen verschlüsselt in der Datenbank',
    $storedRaw !== '' && !str_contains($storedRaw, 'sehr-geheim'));
check('Werte lassen sich wieder lesen',
    App\Integration\ChannelCredentials::value('__test_channel', 'client_id') === 'id-123');
check('hinterlegtes Geheimnis wird gemeldet, nicht ausgegeben',
    App\Integration\ChannelCredentials::hasSecret('__test_channel') === true
    && !array_key_exists('client_secret', array_diff_key(
        App\Integration\ChannelCredentials::stored('__test_channel'),
        ['client_secret' => null]
    )));

// Leeres Geheimnis darf das gespeicherte nicht löschen
App\Integration\ChannelCredentials::save('__test_channel', [
    'client_id'     => 'id-456',
    'client_secret' => '',
]);
check('leeres Feld behält das gespeicherte Geheimnis',
    App\Integration\ChannelCredentials::hasSecret('__test_channel') === true
    && App\Integration\ChannelCredentials::value('__test_channel', 'client_id') === 'id-456');

App\Service\SettingsService::set('channel_credentials.__test_channel', '');
check('Testzugangsdaten wurden entfernt',
    App\Integration\ChannelCredentials::stored('__test_channel') === []);

echo "Ausstattungskatalog\n";
check('Katalog ist nach Gruppen sortiert',
    count(App\Service\FeatureCatalog::GROUPS) >= 4);
check('Katalog enthält übliche Merkmale',
    in_array('Navigationssystem', App\Service\FeatureCatalog::all(), true)
    && in_array('Anhängerkupplung', App\Service\FeatureCatalog::all(), true));
check('keine doppelten Merkmale',
    count(App\Service\FeatureCatalog::all()) === count(array_unique(App\Service\FeatureCatalog::all())));

$featureSplit = App\Service\FeatureCatalog::split(['Navigationssystem', 'Eigene Sonderausstattung', '  ', 'Sitzheizung vorne']);
check('bekannte Merkmale werden erkannt',
    $featureSplit['known'] === ['Navigationssystem', 'Sitzheizung vorne']);
check('eigene Einträge bleiben erhalten',
    $featureSplit['custom'] === ['Eigene Sonderausstattung']);
check('leere Zeilen werden verworfen',
    count($featureSplit['known']) + count($featureSplit['custom']) === 3);

echo "Hintergründe\n";
check('Vorlagen sind vorhanden',
    count(App\Service\BackgroundService::TEMPLATES) >= 4);
check('Vorlage wird erkannt',
    App\Service\BackgroundService::isTemplate('studio_light') === true
    && App\Service\BackgroundService::isTemplate('gibtsnicht') === false);
check('eigener Hintergrund wird an der Kennung erkannt',
    App\Service\BackgroundService::ownId('own:42') === 42
    && App\Service\BackgroundService::ownId('studio_light') === null
    && App\Service\BackgroundService::ownId('own:0') === null);

// Bildmontage ohne KI: Zuschnitt auf Vorlage setzen
$cutDir = BASE_PATH . '/uploads/__test_bg';
if (!is_dir($cutDir)) {
    mkdir($cutDir, 0755, true);
}
$cutFile = $cutDir . '/cut.png';
$cut = imagecreatetruecolor(200, 120);
imagesavealpha($cut, true);
imagefill($cut, 0, 0, imagecolorallocatealpha($cut, 0, 0, 0, 127));
imagefilledellipse($cut, 100, 60, 120, 60, imagecolorallocate($cut, 20, 40, 80));
imagepng($cut, $cutFile);
imagedestroy($cut);

$composed = App\Service\BackgroundService::compose($cutFile, 'studio_dark', 1, '__test_bg');
$composedAbs = App\Service\ImageService::uploadPath($composed);
check('Montage erzeugt eine Bilddatei', is_file($composedAbs));
$composedInfo = @getimagesize($composedAbs);
check('Montage behält die Masse des Zuschnitts',
    $composedInfo !== false && $composedInfo[0] === 200 && $composedInfo[1] === 120);
check('Montage ist ein JPEG ohne Transparenz',
    $composedInfo !== false && $composedInfo['mime'] === 'image/jpeg');

// Der Hintergrund muss sichtbar sein, wo der Zuschnitt durchsichtig war
$check = imagecreatefromjpeg($composedAbs);
$corner = imagecolorsforindex($check, imagecolorat($check, 3, 3));
$middle = imagecolorsforindex($check, imagecolorat($check, 100, 60));
imagedestroy($check);
check('durchsichtige Stellen zeigen den Hintergrund',
    $corner['red'] < 90 && $corner['green'] < 90 && $corner['blue'] < 100);
check('das Fahrzeug bleibt sichtbar',
    abs($middle['red'] - 20) < 40 && abs($middle['blue'] - 80) < 45);

foreach (glob($cutDir . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($cutDir);
check('Testbilder wurden entfernt', !is_dir($cutDir));

echo "Fotos: Hauptbild und Nebenbilder\n";
check('Höchstzahl kommt aus der Konfiguration',
    App\Service\ImageService::maxImagesPerVehicle() === (int) Config::get('uploads.max_images_per_vehicle', 20));
check('Höchstzahl ist mindestens 1',
    App\Service\ImageService::maxImagesPerVehicle() >= 1);

Config::set('uploads.max_images_per_vehicle', 0);
check('unbrauchbare Einstellung fällt auf den Standard zurück',
    App\Service\ImageService::maxImagesPerVehicle() === App\Service\ImageService::DEFAULT_MAX_IMAGES);
Config::set('uploads.max_images_per_vehicle', 20);
check('Standard sind 20 Fotos', App\Service\ImageService::DEFAULT_MAX_IMAGES === 20);

// Reihenfolge: das Hauptbild steht vorn, unabhängig von der Sortierung
$imgNow = App\Core\Database::now();
$imgDealership = App\Core\Database::insert('dealerships', [
    'name' => '__test_bilder', 'country' => 'CH', 'currency' => 'CHF',
    'language' => 'de', 'credits' => 0, 'created_at' => $imgNow, 'updated_at' => $imgNow,
]);
$imgVehicle = App\Core\Database::insert('vehicles', [
    'dealership_id' => $imgDealership, 'status' => 'draft',
    'created_at' => $imgNow, 'updated_at' => $imgNow,
]);
$imgIds = [];
foreach ([0, 1, 2] as $position) {
    $imgIds[] = App\Core\Database::insert('vehicle_images', [
        'vehicle_id' => $imgVehicle,
        'file_path'  => 'test/bild-' . $position . '.jpg',
        'sort_order' => $position,
        'is_main'    => $position === 0 ? 1 : 0,
        'created_at' => $imgNow,
    ]);
}

$ordered = App\Repository\VehicleRepository::images($imgVehicle);
check('ohne Änderung führt das erste Foto',
    (int) $ordered[0]['id'] === $imgIds[0] && (int) $ordered[0]['is_main'] === 1);

// Drittes Foto zum Hauptbild machen, so wie es die Oberfläche tut
App\Core\Database::run('UPDATE vehicle_images SET is_main = 0 WHERE vehicle_id = :v', ['v' => $imgVehicle]);
App\Core\Database::update('vehicle_images', $imgIds[2], ['is_main' => 1]);

$ordered = App\Repository\VehicleRepository::images($imgVehicle);
check('gewähltes Hauptbild steht vorn', (int) $ordered[0]['id'] === $imgIds[2]);
check('Nebenbilder behalten ihre Reihenfolge',
    (int) $ordered[1]['id'] === $imgIds[0] && (int) $ordered[2]['id'] === $imgIds[1]);
check('es gibt genau ein Hauptbild',
    (int) App\Core\Database::scalar(
        'SELECT COUNT(*) FROM vehicle_images WHERE vehicle_id = :v AND is_main = 1',
        ['v' => $imgVehicle]
    ) === 1);
check('alle Fotos gehören zu einem einzigen Fahrzeug', count($ordered) === 3);

App\Core\Database::run('DELETE FROM vehicle_images WHERE vehicle_id = :v', ['v' => $imgVehicle]);
App\Core\Database::run('DELETE FROM vehicles WHERE id = :v', ['v' => $imgVehicle]);
App\Core\Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $imgDealership]);
check('Testdaten wurden wieder entfernt',
    (int) App\Core\Database::scalar("SELECT COUNT(*) FROM dealerships WHERE name = '__test_bilder'") === 0);

echo "Anmeldung mit E-Mail oder Benutzername\n";
$loginNow = App\Core\Database::now();
$loginUserId = App\Core\Database::insert('users', [
    'dealership_id' => null,
    'first_name'    => 'Test',
    'last_name'     => 'Login',
    'email'         => '__test_login@example.invalid',
    'username'      => '__TestLogin',
    'password_hash' => password_hash('GeheimesTestpasswort', PASSWORD_DEFAULT),
    'country'       => 'CH',
    'role'          => App\Auth\AuthService::ROLE_DEALER_USER,
    'is_active'     => 1,
    'created_at'    => $loginNow,
    'updated_at'    => $loginNow,
]);

check('Anmeldung per E-Mail findet den Benutzer',
    (App\Auth\AuthService::findByLogin('__test_login@example.invalid')['id'] ?? null) === $loginUserId);
check('Anmeldung per Benutzername findet den Benutzer',
    (App\Auth\AuthService::findByLogin('__TestLogin')['id'] ?? null) === $loginUserId);
check('Gross- und Kleinschreibung spielt keine Rolle',
    (App\Auth\AuthService::findByLogin('__testlogin')['id'] ?? null) === $loginUserId);
check('unbekannte Angabe ergibt keinen Treffer',
    App\Auth\AuthService::findByLogin('__gibt_es_nicht') === null);
check('leere Angabe ergibt keinen Treffer',
    App\Auth\AuthService::findByLogin('   ') === null);
check('vergebener Benutzername wird erkannt',
    App\Auth\AuthService::usernameExists('__testlogin') === true
    && App\Auth\AuthService::usernameExists('__frei') === false);

// Konten ohne Benutzername dürfen nicht über einen leeren Namen auffindbar sein
$withoutUsername = App\Core\Database::scalar(
    'SELECT COUNT(*) FROM users WHERE username IS NULL'
);
check('Konten ohne Benutzername bleiben über die E-Mail erreichbar',
    (int) $withoutUsername >= 0 && App\Auth\AuthService::findByLogin('') === null);

App\Core\Database::run('DELETE FROM users WHERE id = :id', ['id' => $loginUserId]);
check('Testkonto wurde wieder entfernt',
    (int) App\Core\Database::scalar(
        "SELECT COUNT(*) FROM users WHERE email = '__test_login@example.invalid'"
    ) === 0);

echo "Hintergruende (BackgroundService)\n";
check('vier kuratierte Vorlagen', count(App\Service\BackgroundService::TEMPLATES) === 4);
foreach (App\Service\BackgroundService::TEMPLATES as $tplKey => $tpl) {
    if (!is_file(BASE_PATH . '/' . $tpl['file'])) {
        check('Vorlagendatei fehlt: ' . $tplKey, false);
    }
}
check('alle Vorlagendateien vorhanden', true);

$bgNow = App\Core\Database::now();
$bgDealership = App\Core\Database::insert('dealerships', [
    'name' => '__test_bgfav', 'country' => 'CH', 'currency' => 'CHF',
    'language' => 'de', 'credits' => 0, 'created_at' => $bgNow, 'updated_at' => $bgNow,
]);
check('Favorit setzen', App\Service\BackgroundService::toggleFavorite($bgDealership, 'studio_dark') === true);
check('Favorit ist gespeichert', App\Service\BackgroundService::favorites($bgDealership) === ['studio_dark']);
check('Favorit entfernen', App\Service\BackgroundService::toggleFavorite($bgDealership, 'studio_dark') === false);
check('Liste wieder leer', App\Service\BackgroundService::favorites($bgDealership) === []);
try {
    App\Service\BackgroundService::toggleFavorite($bgDealership, 'gibtsnicht');
    check('unbekannter Schluessel wird abgelehnt', false);
} catch (\RuntimeException) {
    check('unbekannter Schluessel wird abgelehnt', true);
}
try {
    App\Service\BackgroundService::toggleFavorite($bgDealership, 'own:999999');
    check('fremder eigener Hintergrund wird abgelehnt', false);
} catch (\RuntimeException) {
    check('fremder eigener Hintergrund wird abgelehnt', true);
}
App\Core\Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $bgDealership]);
check('Testdaten entfernt',
    (int) App\Core\Database::scalar("SELECT COUNT(*) FROM dealerships WHERE name = '__test_bgfav'") === 0);

echo "Dokumente ohne KI auslesen\n";
$contract = "KAUFVERTRAG\nMarke: Skoda    Modell: Octavia RS\n"
    . "Fahrgestellnummer: TMBJJ7NE0G0123456\nErstzulassung: 03.2021\n"
    . "Kilometerstand: 68 500 km\nAnzahl Halter: 2\n"
    . "Hubraum: 1984 ccm    Leistung kW: 180\n"
    . "Treibstoff: Benzin   Getriebe: Automat (DSG)\n"
    . "Farbe: Rennblau metallic\nKaufpreis: CHF 24 900.00";
$parsed = App\Service\DocumentParser::parse($contract);
$parsedFields = $parsed['fields'];
check('Fahrgestellnummer erkannt', ($parsedFields['vin']['value'] ?? null) === 'TMBJJ7NE0G0123456');
check('Erstzulassung im Format MM.JJJJ', ($parsedFields['first_registration']['value'] ?? null) === '03.2021');
check('Kilometerstand mit Tausendertrenner', ($parsedFields['mileage']['value'] ?? null) === 68500);
check('Anzahl Halter erkannt', ($parsedFields['previous_owners']['value'] ?? null) === 2);
check('Preis erkannt', ($parsedFields['price']['value'] ?? null) === 24900.0);
check('Treibstoff aus Begriff abgeleitet', ($parsedFields['fuel_type']['value'] ?? null) === 'petrol');
check('Getriebe aus Begriff abgeleitet', ($parsedFields['transmission']['value'] ?? null) === 'automatic');
check('mindestens zehn Felder ohne KI', count($parsedFields) >= 10);

$leer = App\Service\DocumentParser::parse('Guten Tag, anbei die Unterlagen. Freundliche Grüsse');
check('ohne Feldbezeichnungen wird nichts erfunden', $leer['fields'] === []);

// Unsinnige Werte werden nicht uebernommen
$unsinn = App\Service\DocumentParser::parse("Kilometerstand: keine Angabe\nErstzulassung: unbekannt");
check('unlesbare Werte bleiben leer', !isset($unsinn['fields']['mileage']));

echo "Schreibstil der Inserate\n";
check('vier Tonarten vorhanden', count(App\Service\ListingStyle::TONES) === 4);
check('Standardton ist gültig', isset(App\Service\ListingStyle::TONES[App\Service\ListingStyle::DEFAULT_TONE]));

$styleNow = App\Core\Database::now();
$styleDealership = App\Core\Database::insert('dealerships', [
    'name' => '__test_stil', 'country' => 'CH', 'currency' => 'CHF',
    'language' => 'de', 'credits' => 0, 'created_at' => $styleNow, 'updated_at' => $styleNow,
]);
check('ohne Einstellung gilt der Standardton',
    App\Service\ListingStyle::toneKey($styleDealership) === App\Service\ListingStyle::DEFAULT_TONE);
App\Core\Database::update('dealerships', $styleDealership, [
    'listing_tone'   => 'premium',
    'listing_sample' => 'Ein sehr gepflegtes Fahrzeug aus erster Hand.',
]);
check('gewählter Ton wird gelesen', App\Service\ListingStyle::toneKey($styleDealership) === 'premium');
$styleText = App\Service\ListingStyle::instruction($styleDealership);
check('Anweisung enthält den Ton', str_contains($styleText, 'gehoben'));
check('Anweisung enthält den Beispieltext', str_contains($styleText, 'erster Hand'));
check('Beispiel wird ausdrücklich nur als Vorbild genutzt',
    str_contains($styleText, 'KEINE Angaben daraus'));
// ------------------------------------------------------------ Titel des Inserats
$titleVehicle = [
    'make' => 'Lamborghini', 'model' => 'Huracan', 'variant' => 'STO',
    'previous_owners' => 1, 'mileage' => 8200, 'year' => (int) date('Y') - 1,
    'power_hp' => 640, 'drivetrain' => 'rwd', 'fuel_type' => 'petrol',
];
$titleHighlights = App\Service\ListingStyle::titleHighlights($titleVehicle, ['Keramikbremse', 'Sitzheizung']);
check('Bestwerte kommen aus echten Daten', in_array('1. Hand', $titleHighlights, true)
    && in_array('640 PS', $titleHighlights, true));
check('schwache Merkmale kommen nicht in den Titel', !in_array('Sitzheizung', $titleHighlights, true));

$weakHighlights = App\Service\ListingStyle::titleHighlights([
    'make' => 'Fiat', 'model' => 'Punto', 'previous_owners' => 4,
    'mileage' => 180000, 'year' => 2009, 'power_hp' => 75,
]);
check('ohne gute Daten gibt es keine Zusaetze', $weakHighlights === []);

$compact = App\Service\ListingStyle::composeTitle($titleVehicle, $titleHighlights, 2);
check('Titel beginnt mit dem Fahrzeugnamen', str_starts_with($compact, 'Lamborghini Huracan STO'));
check('Titel nimmt hoechstens zwei Zusaetze', substr_count($compact, '|') === 2);
check('Titel bleibt kurz', mb_strlen($compact) <= App\Service\ListingStyle::TITLE_MAX_LENGTH);

$plain = App\Service\ListingStyle::composeTitle($titleVehicle, $titleHighlights, 0);
check('Stil ohne Zusaetze liefert nur den Namen', $plain === 'Lamborghini Huracan STO');

check('drei Titelstile vorhanden', count(App\Service\ListingStyle::TITLE_STYLES) === 3);
check('Standard-Titelstil ist gueltig',
    isset(App\Service\ListingStyle::TITLE_STYLES[App\Service\ListingStyle::DEFAULT_TITLE_STYLE]));
check('ohne Einstellung gilt der Standard-Titelstil',
    App\Service\ListingStyle::titleStyleKey($styleDealership) === App\Service\ListingStyle::DEFAULT_TITLE_STYLE);
App\Core\Database::update('dealerships', $styleDealership, [
    'listing_title_style'  => 'plain',
    'listing_title_sample' => 'Porsche 911 Carrera | 1. Hand',
]);
check('gewaehlter Titelstil wird gelesen',
    App\Service\ListingStyle::titleStyleKey($styleDealership) === 'plain');
check('Titelstil ohne Zusaetze erlaubt keine', App\Service\ListingStyle::titleExtras($styleDealership) === 0);
$titleText = App\Service\ListingStyle::titleInstruction($styleDealership, $titleHighlights);
check('Titelanweisung nennt die erlaubten Zusaetze', str_contains($titleText, '1. Hand'));
check('Titelanweisung nennt den Beispieltitel', str_contains($titleText, 'Porsche 911 Carrera'));
check('Beispieltitel wird nur als Vorbild genutzt', str_contains($titleText, 'KEINE Angaben daraus'));

App\Core\Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $styleDealership]);

// Ohne Guthaben darf keine Anfrage an OpenAI hinausgehen
$aiGateNow = App\Core\Database::now();
$aiGateDealer = App\Core\Database::insert('dealerships', [
    'name' => '__test_ki_sperre', 'country' => 'CH', 'currency' => 'CHF',
    'language' => 'de', 'credits' => 0, 'created_at' => $aiGateNow, 'updated_at' => $aiGateNow,
]);
echo "Plausibilitaet der erkannten Daten
";
$plausible = static function (array $values): array {
    $out = [];
    foreach ($values as $key => $value) {
        $out[$key] = ['value' => $value, 'confidence' => 90, 'alternatives' => []];
    }
    return App\Service\FieldPlausibility::check($out);
};

$swapped = $plausible(['power_hp' => 180, 'power_kw' => 245]);
check('vertauschte PS und kW werden getauscht',
    (int) $swapped['fields']['power_hp']['value'] === 245 && (int) $swapped['fields']['power_kw']['value'] === 180);

$ccmYear = $plausible(['year' => 1984, 'displacement_ccm' => 1984, 'first_registration' => '05.2023']);
check('Hubraum landet nicht im Baujahr', (int) $ccmYear['fields']['year']['value'] === 2023);

$tooFar = $plausible(['year' => 2023, 'mileage' => 1420000]);
check('unmoeglicher Kilometerstand wird verworfen', !isset($tooFar['fields']['mileage']));

check('kurze Fahrgestellnummer wird verworfen', !isset($plausible(['vin' => 'WVWZZZ1KZAW1234'])['fields']['vin']));
check('Fahrgestellnummer mit O wird verworfen', !isset($plausible(['vin' => 'WVWZZZ1KZAWO23456'])['fields']['vin']));
check('saubere Angaben bleiben unveraendert',
    (int) $plausible(['power_kw' => 180, 'power_hp' => 245])['fields']['power_hp']['value'] === 245);

$powerLine = App\Service\DocumentParser::parse("Leistung: 180 kW / 245 PS
Hubraum: 1984 ccm");
check('Leistungszeile wird richtig zugeordnet',
    (int) $powerLine['fields']['power_kw']['value'] === 180 && (int) $powerLine['fields']['power_hp']['value'] === 245);

echo "Platzhalter im Inseratstext
";
$tplVehicle = ['mileage' => 30000, 'power_hp' => 300, 'price' => 46900.0, 'year' => 2023];
$tplText = 'Mit {{mileage}} km und {{power_hp}} PS, Preis {{price}}, Baujahr {{year}}.';
check('Platzhalter werden gefuellt',
    str_contains(App\Service\ListingTemplate::render($tplText, $tplVehicle), "30'000 km"));
check('doppelte Einheit wird geschluckt',
    !str_contains(App\Service\ListingTemplate::render($tplText, $tplVehicle), 'km km'));
$tplVehicle['mileage'] = 15000;
check('geaenderter Wert erscheint im Text',
    str_contains(App\Service\ListingTemplate::render($tplText, $tplVehicle), "15'000 km"));
check('leere Angabe hinterlaesst keinen Platzhalter',
    !str_contains(App\Service\ListingTemplate::render('Farbe {{color}}.', []), '{{'));


echo "KI nur mit Guthaben\n";
check('ohne Guthaben keine KI', CreditService::hasCredits($aiGateDealer) === false);
CreditService::grant($aiGateDealer, 1, CreditService::REASON_ADMIN, 'Test', null);
check('mit Guthaben ist die KI frei', CreditService::hasCredits($aiGateDealer) === true);
App\Core\Database::run('DELETE FROM credit_transactions WHERE dealership_id = :d', ['d' => $aiGateDealer]);
App\Core\Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $aiGateDealer]);

$permissionsSource = file_get_contents(BASE_PATH . '/includes/permissions.php');
check('Sperre prueft das Guthaben', str_contains($permissionsSource, 'guard_ai_credits'));
foreach (['detect-vehicle', 'generate-listing'] as $aiEndpoint) {
    $endpointSource = file_get_contents(BASE_PATH . '/api/ai/' . $aiEndpoint . '.php');
    check($aiEndpoint . ': ohne Guthaben gesperrt', str_contains($endpointSource, 'guard_ai_credits('));
}
$documentSource = file_get_contents(BASE_PATH . '/api/ai/extract-document.php');
check('Dokumentauswertung fragt die KI nur mit Guthaben',
    str_contains($documentSource, 'CreditService::hasCredits($dealershipId)'));


echo "Kostenbremse der KI\n";
check('Standardmodell ist das günstige',
    App\AI\OpenAiProvider::DEFAULT_MODEL === 'gpt-4o-mini');
check('Bilddetailgrad standardmässig sparsam',
    App\AI\OpenAiProvider::imageDetail() === 'low');
$detailSaved = (string) Config::get('ai.image_detail', 'low');
Config::set('ai.image_detail', 'quatsch');
check('unbrauchbare Einstellung fällt auf sparsam zurück',
    App\AI\OpenAiProvider::imageDetail() === 'low');
Config::set('ai.image_detail', $detailSaved);

// Die Erkennung darf nicht am Detailgrad sparen: kleine Typschilder wie "STO"
// sind in der verkleinerten Fassung nicht mehr lesbar.
check('Erkennung liest Fotos in voller Auflösung',
    App\AI\OpenAiProvider::detectionDetail() === 'high');
$detectionSaved = (string) Config::get('ai.detection_detail', 'high');
Config::set('ai.detection_detail', 'quatsch');
check('unbrauchbare Einstellung fällt auf genau zurück',
    App\AI\OpenAiProvider::detectionDetail() === 'high');
Config::set('ai.detection_detail', 'low');
check('bewusst sparsamer Detailgrad wird respektiert',
    App\AI\OpenAiProvider::detectionDetail() === 'low');
Config::set('ai.detection_detail', $detectionSaved);

check('Erkennung nutzt das stärkere Modell',
    App\AI\OpenAiProvider::DEFAULT_VISION_MODEL !== App\AI\OpenAiProvider::DEFAULT_MODEL);

// Höchstzahl der Bilder je Erkennung: private Konstante über Reflection prüfen
$providerRef = new ReflectionClass(App\AI\OpenAiProvider::class);
$maxImagesConst = $providerRef->getReflectionConstant('MAX_IMAGES');
check('höchstens drei Bilder je Erkennung',
    $maxImagesConst !== false && $maxImagesConst->getValue() === 3);

// Bildbewertung darf nie einen KI-Aufruf ausloesen: sie laeuft regelbasiert
$imageServiceSource = file_get_contents(BASE_PATH . '/src/AI/AIImageService.php');
check('Fotobewertung ohne KI-Aufruf',
    !str_contains($imageServiceSource, 'AIService::provider()->analyzeImage'));
check('Fotobewertung nutzt die regelbasierte Auswertung',
    str_contains($imageServiceSource, 'new MockProvider()'));

echo "Instagram: Betreiber richtet ein, Händler verbindet\n";
$igSavedId = (string) Config::get('instagram.client_id', '');
$igSavedSecret = (string) Config::get('instagram.client_secret', '');
Config::set('instagram.client_id', '');
Config::set('instagram.client_secret', '');
check('ohne App-Daten des Betreibers: nicht konfiguriert',
    App\Integration\InstagramService::isConfigured() === false);
check('Rücksprungadresse leitet sich von der Domain ab',
    str_contains(App\Integration\ChannelCredentials::value('instagram', 'redirect_uri'), 'callback.php?channel=instagram'));
check('nur Kennung und Geheimnis fehlen',
    App\Integration\InstagramService::client()->missingConfig() === ['client_id', 'client_secret']);

Config::set('instagram.client_id', 'test-app-id');
Config::set('instagram.client_secret', 'test-app-geheim');
check('mit App-Daten: für alle Händler nutzbar',
    App\Integration\InstagramService::isConfigured() === true);
check('Händler ohne Verbindung: getrennt, nicht verbunden',
    App\Integration\InstagramService::status(999999) === 'disconnected');
Config::set('instagram.client_id', $igSavedId);
Config::set('instagram.client_secret', $igSavedSecret);

echo "Adressen der Anwendung"; echo "\n";
// Eine hinterlegte localhost-Adresse darf auf einem Server nicht dazu fuehren,
// dass alle Links dorthin zeigen. Die Pruefung steckt in base_url().
$functionsSource = file_get_contents(BASE_PATH . '/includes/functions.php');
check('base_url erkennt oertliche Adressen',
    str_contains($functionsSource, 'str_starts_with($configuredHost, ' . "'127.'" . ')'));
check('base_url vergleicht mit der Anfrage',
    str_contains($functionsSource, '$configuredHost !== $requestHost'));
check('Weiterleitungen bauen ueber base_url',
    str_contains($functionsSource, 'base_url($path)'));
check('Adressen ohne Konfiguration entstehen aus der Anfrage',
    str_contains($functionsSource, 'HTTP_HOST'));

check('base_url hebt http auf https, wenn die Anfrage sicher ist',
    str_contains($functionsSource, 'str_starts_with($configured, ' . "'http://'" . ')'));

echo "
"; echo "Serverbetrieb"; echo "
";

// Die Vorgaben muessen auf einen echten Server passen, nicht auf einen
// Entwicklungsrechner. Sonst schreibt eine frische Installation Mails ins
// Protokoll statt sie zu verschicken.
$mailerSource = file_get_contents(BASE_PATH . '/src/Core/Mailer.php');
check('Mailversand ist die Vorgabe, nicht das Protokoll',
    str_contains($mailerSource, "Config::get('mail.driver', 'mail')"));
check('Absender entsteht aus der eigenen Domain',
    str_contains($mailerSource, 'private static function fromAddress'));
check('kein noreply@localhost mehr im Versand',
    !str_contains($mailerSource, "noreply@localhost'"));

$sampleSource = file_get_contents(BASE_PATH . '/config/config.sample.php');
$sample = require BASE_PATH . '/config/config.sample.php';
check('Beispielkonfiguration ohne feste Adresse', ($sample['app']['url'] ?? 'x') === '');
check('Beispielkonfiguration ohne Fehleranzeige', ($sample['app']['debug'] ?? true) === false);
check('Beispielkonfiguration mit Zeitzone', ($sample['app']['timezone'] ?? '') !== '');
check('Beispielkonfiguration kennt die https-Weiterleitung',
    array_key_exists('force_https', $sample['app'] ?? []));
check('Beispielkonfiguration verschickt Mails', ($sample['mail']['driver'] ?? '') === 'mail');
check('Beispielkonfiguration nennt MySQL', ($sample['db']['driver'] ?? '') === 'mysql');
check('Beispielkonfiguration hat einen Kontaktempfaenger',
    array_key_exists('contact', $sample['mail'] ?? []));

$bootstrapSource = file_get_contents(BASE_PATH . '/includes/bootstrap.php');
check('Zeitzone wird gesetzt', str_contains($bootstrapSource, 'date_default_timezone_set'));
check('Ordner werden bei Bedarf angelegt', str_contains($bootstrapSource, "BASE_PATH . '/storage/logs'"));
check('https laesst sich erzwingen', str_contains($bootstrapSource, "app.force_https"));
check('HSTS nur ueber https', str_contains($bootstrapSource, 'Strict-Transport-Security'));

$sessionSource = file_get_contents(BASE_PATH . '/src/Core/Session.php');
check('https hinter einem Proxy wird erkannt',
    str_contains($sessionSource, 'HTTP_X_FORWARDED_SSL') && str_contains($sessionSource, 'SERVER_PORT'));

$installerSource = file_get_contents(BASE_PATH . '/install/index.php');
check('Installer prueft den Mailversand', str_contains($installerSource, '$canSendMail'));
check('Installer schaltet die Bestaetigung nur mit Mailversand ein',
    str_contains($installerSource, "'email_verification' => $canSendMail"));

$databaseSource = file_get_contents(BASE_PATH . '/src/Core/Database.php');
check('Datenbankverbindung hat ein Zeitlimit', str_contains($databaseSource, 'PDO::ATTR_TIMEOUT'));

// Faellt die Datenbank aus, ist das ein Serverzustand: Besucher bekommen
// eine ehrliche Wartungsseite, keine anonyme Fehlermeldung. Und eine leere
// Datenbank heilt sich beim naechsten Aufruf selbst.
check('eigener Fehlertyp fuer die Datenbank',
    class_exists('App\Core\DatabaseUnavailableException'));
check('Datenbankausfall wirft den eigenen Typ',
    str_contains($databaseSource, 'throw new DatabaseUnavailableException'));
check('MySQL bekommt einen festen sql_mode',
    str_contains($databaseSource, 'MYSQL_ATTR_INIT_COMMAND'));

$bootstrapSource2 = file_get_contents(BASE_PATH . '/includes/bootstrap.php');
check('Datenbankausfall zeigt die Wartungsseite',
    str_contains($bootstrapSource2, "errors/503.php"));
check('Wartungsseite vorhanden', is_file(BASE_PATH . '/errors/503.php'));
check('Wartungsseite antwortet mit 503',
    str_contains((string) file_get_contents(BASE_PATH . '/errors/503.php'), 'http_response_code(503)'));

$migratorSource = file_get_contents(BASE_PATH . '/src/Core/Migrator.php');
check('leere Datenbank heilt sich selbst',
    str_contains($migratorSource, 'healEmptyDatabase'));
check('Heilung laeuft vor den Migrationen',
    strpos($migratorSource, 'self::healEmptyDatabase()') < strpos($migratorSource, 'self::installedVersion()'));
check('Existenzpruefung ohne SHOW mit Platzhalter',
    str_contains($migratorSource, 'information_schema.tables')
    && str_contains($migratorSource, 'information_schema.columns')
    && !str_contains($migratorSource, 'SHOW COLUMNS FROM {$table} LIKE'));
check('Heilung protokolliert den Neuaufbau',
    str_contains($migratorSource, 'Das Schema wurde neu angelegt'));

$installerSource2 = file_get_contents(BASE_PATH . '/install/index.php');
check('gesperrter Installer zeigt den Datenbankzustand',
    str_contains($installerSource2, 'Datenbank NICHT erreichbar'));

echo "
"; echo "Bestaetigungspflicht"; echo "
";

// Wer die Bestaetigung verlangt, darf keinen Weg daran vorbei lassen und
// keinen Versand vortaeuschen, der nicht stattfand.
$verificationSource = file_get_contents(BASE_PATH . '/src/Auth/EmailVerification.php');
check('send() meldet den tatsaechlichen Versand',
    str_contains($verificationSource, 'public static function send(int $userId, string $email): bool')
    && str_contains($verificationSource, 'return Mailer::send('));

$registerSource = file_get_contents(BASE_PATH . '/register.php');
check('Registrierung loggt mit Pflicht niemanden automatisch ein',
    strpos($registerSource, 'EmailVerification::isEnabled()') !== false
    && strpos($registerSource, 'EmailVerification::isEnabled()') < strpos($registerSource, 'AuthService::attempt('));
check('gescheiterter Versand wird ehrlich gemeldet',
    str_contains($registerSource, "'verify_state', \$mailSent ? 'sent' : 'failed'")
    && str_contains((string) file_get_contents(BASE_PATH . '/confirm-email.php'), 'Der Versand hat gerade nicht geklappt'));

// Die Pflicht hat eine eigene Seite. Die Adresse laeuft ueber die Session,
// nie ueber die URL, und aeltere Links bleiben gueltig, bis einer benutzt wird.
$confirmSource = (string) file_get_contents(BASE_PATH . '/confirm-email.php');
check('eigene Bestaetigungsseite vorhanden', is_file(BASE_PATH . '/confirm-email.php'));
check('Registrierung fuehrt auf die Bestaetigungsseite',
    str_contains($registerSource, "redirect('confirm-email.php')"));
check('Anmeldung fuehrt Unbestaetigte auf die Bestaetigungsseite',
    str_contains((string) file_get_contents(BASE_PATH . '/login.php'), "redirect('confirm-email.php')"));
check('Adresse kommt aus der Session, nicht aus der URL',
    str_contains($confirmSource, "Session::get('verify_email'")
    && !str_contains($confirmSource, '\$_GET[' . chr(39) . 'email'));
check('erneut senden ist gedrosselt',
    str_contains($confirmSource, "tooManyAttempts('verify_resend'"));
check('unbekannte Adresse verraet sich nicht',
    str_contains($confirmSource, 'gleiche Anzeige wie beim Erfolg'));
check('aeltere Links bleiben gueltig',
    str_contains($verificationSource, 'expires_at < :now')
    // In send() darf kein pauschales Loeschen mehr stehen; das Aufraeumen
    // aller Links gehoert allein in verify().
    && substr_count(
        substr($verificationSource, 0, (int) strpos($verificationSource, 'public static function verify')),
        "DELETE FROM email_verifications WHERE user_id = :uid'"
    ) === 0);

$loginSource = file_get_contents(BASE_PATH . '/login.php');
check('Anmeldung weist unbestaetigte Konten ab',
    str_contains($loginSource, "email_verified_at'] === null")
    && str_contains($loginSource, 'AuthService::logout()'));
check('Anmeldung schickt gedrosselt einen frischen Link',
    str_contains($loginSource, "verify_resend"));

echo "
"; echo "Kontoarten (Privat und Autohaus)"; echo "
";

// Die Plattform steht auch Privatpersonen offen. Die Wahl steht als
// Umschalter in der Registrierung, das Datenmodell traegt die Kontoart.
$migratorSource2 = file_get_contents(BASE_PATH . '/src/Core/Migrator.php');
check('Schema-Version 15', str_contains($migratorSource2, 'CURRENT_VERSION = 15'));
check('Migration legt die Kontoart an', str_contains($migratorSource2, "'account_type'"));
check('MySQL-Schema kennt die Kontoart',
    str_contains((string) file_get_contents(BASE_PATH . '/database/schema.mysql.sql'), 'account_type'));
check('SQLite-Schema kennt die Kontoart',
    str_contains((string) file_get_contents(BASE_PATH . '/database/schema.sqlite.sql'), 'account_type'));

$authSource = file_get_contents(BASE_PATH . '/src/Auth/AuthService.php');
check('Registrierung im Service kennt die Kontoart',
    str_contains($authSource, '$accountType') && str_contains($authSource, "'account_type' => \$accountType"));

$registerSource2 = file_get_contents(BASE_PATH . '/register.php');
check('Umschalter Privat oder Autohaus vorhanden',
    str_contains($registerSource2, 'account-switch')
    && str_contains($registerSource2, '>Privat<') && str_contains($registerSource2, '>Autohaus<'));
check('Firmenname nur fuer Autohaeuser Pflicht',
    str_contains($registerSource2, "if (\$accountType === 'dealer') {"));
check('Privatkonto heisst wie die Person',
    str_contains($registerSource2, "trim(\$v->value('first_name') . ' ' . \$v->value('last_name'))"));

$onboardingSource = file_get_contents(BASE_PATH . '/dashboard/onboarding.php');
check('Onboarding spricht Privatkonten richtig an',
    str_contains($onboardingSource, "'Anzeigename'") && str_contains($onboardingSource, '$isPrivate'));
check('Einstellungen sprechen Privatkonten richtig an',
    str_contains((string) file_get_contents(BASE_PATH . '/dashboard/settings.php'), "'Verkäuferprofil'"));

// Privatkonten sehen keine Autohaus-Felder: kein Logo, keine
// Oeffnungszeiten, keine Website. Der Umschalter ist eine weiche Pille.
$onboardingSource2 = file_get_contents(BASE_PATH . '/dashboard/onboarding.php');
foreach (['Logo', 'Website', 'ffnungszeiten'] as $feld) {
    $pos = strpos($onboardingSource2, $feld . ' <span');
    check('Onboarding blendet ' . $feld . ' fuer Privat aus',
        $pos !== false
        && strrpos(substr($onboardingSource2, 0, (int) $pos), 'if (!$isPrivate)') !== false);
}
check('Einstellungen blenden das Logo fuer Privat aus',
    substr_count((string) file_get_contents(BASE_PATH . '/dashboard/settings.php'), 'if (!$isPrivate)') >= 1);
check('Inseratvorschau sagt Verkaeufer statt Autohaus',
    str_contains((string) file_get_contents(BASE_PATH . '/dashboard/listing-editor.php'), "'Verkäufer'"));
$cssSource = file_get_contents(BASE_PATH . '/assets/css/public.css');
check('Umschalter ist eine Pille',
    substr_count($cssSource, 'border-radius: 999px') >= 2);

echo "
"; echo "Bereitstellung ohne Datenbank im Paket"; echo "
";

// Ein Update darf nie wieder Daten ueberschreiben: das Paket enthaelt
// keine Datenbank mehr. Beim ersten Aufruf baut die Anwendung Schema,
// Grunddaten und das Betreiberkonto aus der Konfiguration selbst auf.
$configSource = file_get_contents(BASE_PATH . '/src/Core/Config.php');
check('installiert heisst: Konfiguration vorhanden',
    str_contains($configSource, "return is_file(BASE_PATH . '/config/config.php');")
    && !str_contains($configSource, "installed.lock')"));

$migratorSource3 = file_get_contents(BASE_PATH . '/src/Core/Migrator.php');
check('Heilung legt Grunddaten an', str_contains($migratorSource3, 'seedBaseline'));
check('Betreiberkonto entsteht aus der Konfiguration',
    str_contains($migratorSource3, "Config::get('operator'")
    && str_contains($migratorSource3, "'password_hash'"));
check('nur der Hash, nie das Passwort',
    str_contains($migratorSource3, 'Nur der Hash'));
check('fehlender operator-Block wird gemeldet',
    str_contains($migratorSource3, 'kein operator-Block'));

// Der Weg einer Privatperson bleibt kurz: kein Profilschritt im
// Onboarding, keine zweite Bestaetigungsmail direkt nach der Registrierung.
$onboardingSource3 = file_get_contents(BASE_PATH . '/dashboard/onboarding.php');
check('Privatpersonen ueberspringen den Profilschritt',
    str_contains($onboardingSource3, 'if ($isPrivate && $step === 2)'));
check('Startknopf fuehrt Privatpersonen zu den Kanaelen',
    str_contains($onboardingSource3, '$isPrivate ? 3 : 2'));

$authSource2 = file_get_contents(BASE_PATH . '/src/Auth/AuthService.php');
check('Kontaktadresse der Person landet am Mandanten',
    str_contains($authSource2, "$accountType === 'private' ? mb_strtolower(trim(\$email)) : null"));

$loginSource2 = file_get_contents(BASE_PATH . '/login.php');
check('keine zweite Mail kurz nach der Registrierung',
    str_contains($loginSource2, 'MAX(created_at) FROM email_verifications')
    && str_contains($loginSource2, '$recentlySent'));

check('Einstellungen sagen Anzeigename fuer Privat',
    str_contains((string) file_get_contents(BASE_PATH . '/dashboard/settings.php'),
        "$isPrivate ? 'Anzeigename'"));









echo "Spyne\n";
$ccSavedProvider = (string) Config::get('background.provider', '');
$ccSavedKey = (string) Config::get('background.api_key', '');
$ccSavedScenes = Config::get('background.scenes', []);

Config::set('background.provider', '');
Config::set('background.api_key', '');
check('ohne Zugang ist Spyne aus', App\Integration\SpyneService::isConfigured() === false);
check('ohne Spyne bleiben die eigenen Vorlagen',
    count(App\Service\BackgroundService::templates()) === count(App\Service\BackgroundService::TEMPLATES));

Config::set('background.provider', 'spyne');
Config::set('background.api_key', 'TESTSCHLUESSEL');
check('mit Zugang ist Spyne an', App\Integration\SpyneService::isConfigured() === true);
check('Hintergrundwechsel laeuft ueber Spyne', App\Service\BackgroundService::usesSpyne() === true);

Config::set('background.scenes', ['923' => 'Studio hell', '924' => 'Showroom']);
$ccScenes = App\Integration\SpyneService::backgrounds();
check('Szenen kommen aus der Konfiguration', count($ccScenes) === 2);
check('Szene wird erkannt', App\Integration\SpyneService::isBackground('923'));
check('fremde Kennung ist keine Szene', App\Integration\SpyneService::isBackground('gibt-es-nicht') === false);

$ccTemplates = App\Service\BackgroundService::templates();
check('Szenen ersetzen die mitgelieferten Bilder', count($ccTemplates) === 2);
check('Szene hat kein eigenes Bild', ($ccTemplates['923']['file'] ?? 'x') === '');
check('Szene ist als Szene gekennzeichnet', ($ccTemplates['923']['scene'] ?? false) === true);
check('Anzeigename kommt aus der Konfiguration',
    App\Service\BackgroundService::label('923', 1) === 'Studio hell');
check('Szene gilt als waehlbarer Hintergrund',
    App\Service\BackgroundService::isTemplate('923') === true);
check('alte Vorlage ist mit Spyne nicht mehr waehlbar',
    App\Service\BackgroundService::isTemplate('studio_light') === false);

// Spyne holt die Fotos selbst ab: auf einem lokalen Pfad muss der Dienst
// ehrlich abbrechen statt still etwas anderes zu tun.
try {
    App\Integration\SpyneService::compose('C:/lokal/foto.jpg', '923', 'Test');
    check('lokaler Pfad wird abgelehnt', false);
} catch (\RuntimeException $e) {
    check('lokaler Pfad wird abgelehnt', str_contains($e->getMessage(), 'öffentlich erreichbar'));
}

check('Spyne ist der angezeigte Dienst', App\Service\CutoutService::providerName() === 'Spyne');
check('Freistellen laeuft ueber Spyne', App\Service\CutoutService::activeMethod() === 'spyne');

Config::set('background.provider', $ccSavedProvider);
Config::set('background.api_key', $ccSavedKey);
Config::set('background.scenes', $ccSavedScenes);


echo "Freistell-Dienste
";
$cutSavedProvider = (string) Config::get('background.provider', '');
$cutSavedApiKey = (string) Config::get('background.api_key', '');
Config::set('background.api_key', 'TESTSCHLUESSEL');

check('drei Dienste hinterlegt', count(App\Service\CutoutService::PROVIDERS) === 3);
check('Spyne ist dabei', isset(App\Service\CutoutService::PROVIDERS['spyne']));

// Spyne laeuft ueber einen eigenen Weg: der allgemeine Dienst-Aufruf ist
// dort bewusst nicht zustaendig, weil Hintergrund und Freistellen zusammen
// in einem Durchlauf passieren.
Config::set('background.provider', 'spyne');
check('Spyne nutzt nicht den allgemeinen Weg', App\Service\CutoutService::provider() === null);
check('Anbietername wird angezeigt', App\Service\CutoutService::providerName() === 'Spyne');
check('Spyne ist der aktive Weg', App\Service\CutoutService::activeMethod() === 'spyne');

Config::set('background.provider', 'photoroom');
check('PhotoRoom zeigt auf seine Schnittstelle',
    str_contains((string) App\Service\CutoutService::provider()['endpoint'], 'photoroom.com'));

Config::set('background.provider', 'gibt-es-nicht');
check('unbekannter Dienst faellt auf remove.bg zurueck',
    App\Service\CutoutService::provider()['key'] === 'removebg');

Config::set('background.provider', 'photoroom');
Config::set('background.api_url', 'https://eigene-adresse.example/segment');
check('eigene Adresse hat Vorrang',
    App\Service\CutoutService::provider()['endpoint'] === 'https://eigene-adresse.example/segment');
Config::set('background.api_url', '');

check('mit Dienst laeuft der Dienst, nicht das lokale Werkzeug',
    App\Service\CutoutService::activeMethod() === 'service');

Config::set('background.api_key', '');
check('ohne Schluessel gibt es keinen Dienst', App\Service\CutoutService::provider() === null);
Config::set('background.provider', $cutSavedProvider);
Config::set('background.api_key', $cutSavedApiKey);


echo "Freistellen (CutoutService)\n";
check('Werkzeugsuche stuerzt nicht ab',
    App\Service\CutoutService::localToolPath() === null || is_string(App\Service\CutoutService::localToolPath()));
$cutSavedKey = (string) Config::get('background.api_key', '');
Config::set('background.api_key', '');
$cutMethod = App\Service\CutoutService::activeMethod();
check('ohne Dienst und ohne Werkzeug: ehrlich "none" oder lokal',
    in_array($cutMethod, ['none', 'local'], true));
if ($cutMethod === 'none') {
    try {
        App\Service\CutoutService::cutout(BASE_PATH . '/assets/icons/favicon.svg');
        check('ohne Weg: klare Fehlermeldung statt Vortaeuschung', false);
    } catch (\RuntimeException $e) {
        check('ohne Weg: klare Fehlermeldung statt Vortaeuschung', str_contains($e->getMessage(), 'rembg'));
    }
}
// Freistellen darf nie ueber OpenAI laufen: das waere zu teuer
try {
    (new App\AI\OpenAiProvider('sk-test'))->cutoutImage(BASE_PATH . '/assets/icons/favicon.svg');
    check('Freistellen laeuft nicht ueber OpenAI', false);
} catch (App\AI\AIException $e) {
    check('Freistellen laeuft nicht ueber OpenAI', str_contains($e->getMessage(), 'nicht über OpenAI'));
}
Config::set('background.api_key', $cutSavedKey);

echo "Zahlung (Stripe-Anbindung)\n";
check('ohne Anbieter: Stripe nicht bereit', App\Service\PaymentService::isStripeReady() === false);

// Signaturpruefung: gueltig, falsch, abgelaufen
$whSecret = 'whsec_testgeheimnis';
$whPayload = '{"type":"checkout.session.completed"}';
$whTime = time();
$whSig = hash_hmac('sha256', $whTime . '.' . $whPayload, $whSecret);
check('gültige Webhook-Signatur wird angenommen',
    App\Service\PaymentService::verifyStripeSignature($whPayload, 't=' . $whTime . ',v1=' . $whSig, $whSecret) === true);
check('falsche Signatur wird abgelehnt',
    App\Service\PaymentService::verifyStripeSignature($whPayload, 't=' . $whTime . ',v1=' . str_repeat('0', 64), $whSecret) === false);
$oldTime = $whTime - 3600;
$oldSig = hash_hmac('sha256', $oldTime . '.' . $whPayload, $whSecret);
check('alte Ereignisse werden abgelehnt (Wiedereinspielen)',
    App\Service\PaymentService::verifyStripeSignature($whPayload, 't=' . $oldTime . ',v1=' . $oldSig, $whSecret) === false);
check('leerer Header wird abgelehnt',
    App\Service\PaymentService::verifyStripeSignature($whPayload, '', $whSecret) === false);

echo "Google-Anmeldung\n";
$googleSavedId = (string) Config::get('google.client_id', '');
$googleSavedSecret = (string) Config::get('google.client_secret', '');
Config::set('google.client_id', '');
Config::set('google.client_secret', '');
check('ohne Zugangsdaten: Google-Anmeldung aus', App\Auth\GoogleAuth::isConfigured() === false);
Config::set('google.client_id', 'test-id');
Config::set('google.client_secret', 'test-geheim');
check('mit Zugangsdaten: Google-Anmeldung an', App\Auth\GoogleAuth::isConfigured() === true);
check('Rücksprungadresse zeigt auf google-callback.php',
    str_contains(App\Auth\GoogleAuth::redirectUri(), 'google-callback.php'));
Config::set('google.client_id', $googleSavedId);
Config::set('google.client_secret', $googleSavedSecret);

echo "KI-Anbindung (OpenAI)\n";
// Der Schlüssel entscheidet, nicht die Einstellung: Ohne ihn bleibt alles im Demo-Modus.
$savedApiKey = (string) Config::get('ai.api_key', '');
Config::set('ai.api_key', '');
check('ohne Schlüssel: nicht konfiguriert',
    App\AI\OpenAiProvider::isConfigured() === false);
check('ohne Schlüssel bleibt der Demo-Modus aktiv',
    App\AI\AIService::isLiveReady() === false);
Config::set('ai.api_key', 'sk-test-nur-fuer-den-test');
check('mit Schlüssel gilt der Anbieter als konfiguriert',
    App\AI\OpenAiProvider::isConfigured() === true);
Config::set('ai.api_key', $savedApiKey);
check('Konfiguration bleibt unverändert',
    (string) Config::get('ai.api_key', '') === $savedApiKey);
check('Standard-Adresse zeigt auf OpenAI',
    str_contains(App\AI\OpenAiProvider::apiUrl(), 'api.openai.com'));
check('Modell mit Bildverständnis voreingestellt',
    App\AI\OpenAiProvider::model() !== '');

// Antwortschema: erzwingt Wert, Sicherheit und Alternativen je Feld
$providerReflection = new ReflectionClass(App\AI\OpenAiProvider::class);
$schemaMethod = $providerReflection->getMethod('detectionSchema');
$schemaMethod->setAccessible(true);
$schema = $schemaMethod->invoke(new App\AI\OpenAiProvider());

check('Schema ist strikt', ($schema['strict'] ?? null) === true);
check('alle Fahrzeugfelder sind Pflicht',
    $schema['schema']['properties']['fields']['required'] === App\AI\OpenAiProvider::FIELDS);
$makeSchema = $schema['schema']['properties']['fields']['properties']['make'];
check('jedes Feld verlangt Wert, Sicherheit und Alternativen',
    $makeSchema['required'] === ['value', 'confidence', 'alternatives']);
check('Auswahlfelder sind auf gültige Codes begrenzt',
    in_array('automatic', $schema['schema']['properties']['fields']['properties']['transmission']['properties']['value']['enum'], true)
    && in_array('petrol', $schema['schema']['properties']['fields']['properties']['fuel_type']['properties']['value']['enum'], true));

// Auswertung der Modellantwort
$mapMethod = $providerReflection->getMethod('mapDetection');
$mapMethod->setAccessible(true);
$mapped = $mapMethod->invoke(new App\AI\OpenAiProvider(), [
    'detected'   => true,
    'label'      => 'Porsche 911',
    'confidence' => 90,
    'note'       => 'Test',
    'fields'     => [
        'make'    => ['value' => 'Porsche', 'confidence' => 97, 'alternatives' => []],
        'variant' => ['value' => 'Carrera S', 'confidence' => 58, 'alternatives' => ['Carrera', 'Carrera S']],
        'year'    => ['value' => '2022', 'confidence' => 71, 'alternatives' => []],
        'mileage' => ['value' => null, 'confidence' => 0, 'alternatives' => []],
    ],
]);
check('nicht erkannte Felder werden weggelassen', !isset($mapped['fields']['mileage']));
check('Zahlen werden umgewandelt', $mapped['fields']['year']['value'] === 2022);
check('Alternativen ohne Dublette des eigenen Werts',
    $mapped['fields']['variant']['alternatives'] === ['Carrera']);
check('Sicherheit wird übernommen', $mapped['fields']['make']['confidence'] === 97);

// Schwelle für die Auswahlliste
check('unsicheres Feld erzeugt Auswahl',
    App\AI\AIVehicleService::CERTAIN_THRESHOLD > 0 && App\AI\AIVehicleService::CERTAIN_THRESHOLD <= 100);

echo "Zertifikatsprüfung (CaBundle)\n";
check('Zertifikatsliste ist auffindbar', App\Core\CaBundle::isAvailable());
$caPath = App\Core\CaBundle::path();
check('gefundener Pfad ist lesbar oder cURL hat eine eigene',
    $caPath === null || is_readable($caPath));

$curlOptions = App\Core\CaBundle::applyTo([]);
check('Zertifikatsprüfung bleibt eingeschaltet',
    ($curlOptions[CURLOPT_SSL_VERIFYPEER] ?? null) === true
    && ($curlOptions[CURLOPT_SSL_VERIFYHOST] ?? null) === 2);
check('Prüfung wird nirgends abgeschaltet', (static function (): bool {
    foreach (['src/Integration', 'src/Core'] as $dir) {
        foreach (glob(BASE_PATH . '/' . $dir . '/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match('/SSL_VERIFYPEER\s*=>\s*(false|0)\b/', $code) === 1) {
                return false;
            }
        }
    }
    return true;
})());
check('Hinweistext nennt eine Lösung',
    str_contains(App\Core\CaBundle::troubleshootingHint(), 'curl.cainfo'));

echo "AutoScout24 (echte API-Struktur)\n";
check('Basis-URL zeigt auf die Listing-Creation-API',
    str_contains(AutoScoutClient::baseUrl(), 'listing-creation.api.autoscout24.com'));
check('AutoScout24 braucht keine Plattform-Zugangsdaten',
    ChannelRegistry::isConfigured('autoscout24') === true);
check('eigene Verbindungsseite statt OAuth-Redirect',
    str_contains(ChannelRegistry::connectUrl('autoscout24'), 'dashboard/autoscout.php'));
check('ohne Verbindung: nicht verbunden',
    AutoScoutService::status(999999) === 'disconnected' && AutoScoutService::credentials(999999) === null);
check('leere Zugangsdaten werden abgelehnt', (static function (): bool {
    try {
        AutoScoutService::verifyCredentials('', '');
        return false;
    } catch (\RuntimeException) {
        return true;
    }
})());
check('Test ohne Verbindung meldet ehrlich', AutoScoutService::testConnection(999999)['ok'] === false);

// Eingabeprüfung: erkennt typische Stolpersteine, ohne etwas zu verändern
check('Leerzeichen im Benutzernamen wird erkannt',
    AutoScoutService::inputWarnings(' user ', 'geheim') !== []);
check('Leerzeichen im Passwort wird erkannt',
    AutoScoutService::inputWarnings('user', 'geheim ') !== []);
check('E-Mail als Benutzername wird angemerkt',
    AutoScoutService::inputWarnings('max@example.com', 'geheim') !== []);
check('saubere Eingabe erzeugt keine Hinweise',
    AutoScoutService::inputWarnings('haendler123', 'geheim') === []);
check('Anmeldefehler ist eigener Typ',
    is_subclass_of(App\Integration\AutoScoutAuthException::class, RuntimeException::class));

echo "Mandantentrennung AutoScout24\n";
// Zwei Wegwerf-Autohäuser anlegen, damit echte Fremdschlüssel greifen
$makeTestDealership = static function (string $name): int {
    $now = App\Core\Database::now();
    return App\Core\Database::insert('dealerships', [
        'name'       => $name,
        'currency'   => 'CHF',
        'language'   => 'de',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};
$isolationA = $makeTestDealership('__test_isolation_a');
$isolationB = $makeTestDealership('__test_isolation_b');

AutoScoutService::connect($isolationA, 'zugang-a', 'geheim-a', 'CUST-AAA', null, null);
AutoScoutService::connect($isolationB, 'zugang-b', 'geheim-b', 'CUST-BBB', null, null);

check('jedes Autohaus behält seine eigene Kundennummer',
    AutoScoutService::customerId($isolationA) === 'CUST-AAA'
    && AutoScoutService::customerId($isolationB) === 'CUST-BBB');
check('Zugangsdaten bleiben getrennt',
    AutoScoutService::credentials($isolationA)['username'] === 'zugang-a'
    && AutoScoutService::credentials($isolationB)['username'] === 'zugang-b');
check('belegte Kundennummer wird dem richtigen Autohaus zugeordnet',
    AutoScoutService::dealershipUsingCustomer('CUST-AAA') === $isolationA);
check('fremde Kundennummer gilt für andere als belegt',
    AutoScoutService::dealershipUsingCustomer('CUST-AAA', $isolationB) === $isolationA);
check('eigene Kundennummer blockiert sich nicht selbst',
    AutoScoutService::dealershipUsingCustomer('CUST-AAA', $isolationA) === null);
check('Übernahme einer fremden Kundennummer wird abgewiesen', (static function () use ($isolationB): bool {
    try {
        AutoScoutService::connectViaPlatform($isolationB, 'CUST-AAA', null, null);
        return false;
    } catch (RuntimeException) {
        return true;
    }
})());

// Testdaten wieder entfernen (Kaskade räumt Verbindungen mit ab)
foreach ([$isolationA, $isolationB] as $isolationId) {
    App\Core\Database::run('DELETE FROM integration_tokens WHERE dealership_id = :d', ['d' => $isolationId]);
    App\Core\Database::run('DELETE FROM integrations WHERE dealership_id = :d', ['d' => $isolationId]);
    App\Core\Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $isolationId]);
}
check('Testdaten wurden wieder entfernt',
    (int) App\Core\Database::scalar(
        "SELECT COUNT(*) FROM dealerships WHERE name LIKE '__test_isolation%'"
    ) === 0);

// Mapper: Monatsformat und Leistungsumrechnung, ohne API-Zugriff prüfbar
$reflection = new ReflectionClass(App\Integration\AutoScoutMapper::class);
$toApiMonth = $reflection->getMethod('toApiMonth');
$toApiMonth->setAccessible(true);
check('Erstzulassung 03.2023 wird zu 2023-03', $toApiMonth->invoke(null, '03.2023', null) === '2023-03');
check('nur Baujahr: Januar als Rückfall', $toApiMonth->invoke(null, '', 2021) === '2021-01');
check('ohne Angabe: null', $toApiMonth->invoke(null, '', null) === null);

echo "Kanal-Abgleich (ChannelSyncService)\n";
$syncReflection = new ReflectionClass(App\Service\ChannelSyncService::class);

$extractList = $syncReflection->getMethod('extractList');
$extractList->setAccessible(true);
check('Liste unter "listings" wird erkannt',
    count($extractList->invoke(null, ['listings' => [['id' => 'a'], ['id' => 'b']]])) === 2);
check('blanke Liste wird erkannt',
    count($extractList->invoke(null, [['id' => 'a']])) === 1);
check('leere Antwort ergibt leere Liste',
    $extractList->invoke(null, ['foo' => 'bar']) === []);

$resolveVehicleId = $syncReflection->getMethod('resolveVehicleId');
$resolveVehicleId->setAccessible(true);
check('fremde Referenz wird nicht zugeordnet',
    $resolveVehicleId->invoke(null, 999999, 'EXTERN-77') === null);
check('fehlende Referenz wird nicht zugeordnet',
    $resolveVehicleId->invoke(null, 999999, null) === null);

$extractPrice = $syncReflection->getMethod('extractPrice');
$extractPrice->setAccessible(true);
check('Preis aus prices.public.price',
    $extractPrice->invoke(null, ['prices' => ['public' => ['price' => 88000]]]) === 88000.0);
check('fehlender Preis ergibt null',
    $extractPrice->invoke(null, []) === null);

$extractStatus = $syncReflection->getMethod('extractStatus');
$extractStatus->setAccessible(true);
check('Status aus publication.status',
    $extractStatus->invoke(null, ['publication' => ['status' => 'Active']]) === 'Active');

$extractUrl = $syncReflection->getMethod('extractUrl');
$extractUrl->setAccessible(true);
check('URL aus publication.channels',
    $extractUrl->invoke(null, ['publication' => ['channels' => [['id' => 'AS24', 'url' => 'https://x.test/a']]]]) === 'https://x.test/a');

check('ohne Kanal: nichts verbunden',
    App\Service\ChannelSyncService::hasConnectedChannel(999999) === false);
check('ohne Abgleich gilt der Stand als veraltet',
    App\Service\ChannelSyncService::isStale(999999) === true);

echo "Kanäle (ChannelRegistry)\n";
$channels = ChannelRegistry::all();
check('TikTok vorhanden', isset($channels['tiktok']) && $channels['tiktok']['type'] === ChannelRegistry::TYPE_SOCIAL);
check('AutoScout24 und mobile.de vorhanden', isset($channels['autoscout24'], $channels['mobile_de']));
check('Schweizer Plattformen vorhanden', isset($channels['car4you'], $channels['tutti'], $channels['ricardo']));
check('mindestens 8 Verkaufsplattformen', count(ChannelRegistry::byType(ChannelRegistry::TYPE_MARKETPLACE)) >= 8);
check('mindestens 4 soziale Netzwerke', count(ChannelRegistry::byType(ChannelRegistry::TYPE_SOCIAL)) >= 4);
check('ohne Zugangsdaten: nicht konfiguriert', ChannelRegistry::isConfigured('tiktok') === false);
check('unbekannter Kanal: exists false', ChannelRegistry::exists('gibt-es-nicht') === false);

// ---------------------------------------------------------------------------
echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit($failed > 0 ? 1 : 0);
