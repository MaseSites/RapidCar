<?php
/**
 * Selbstprüfung der Installation.
 *
 * Zeigt, ob PHP, Datenbank, Schreibrechte und Schema stimmen, und benennt
 * konkret, was fehlt. Gedacht für die Einrichtung auf einem Server und für
 * den Fall, dass eine Seite mit Fehler 500 antwortet.
 *
 * Aufruf im Browser:  https://deine-domain.tld/systemcheck.php?key=<app.key>
 * Der Schlüssel steht in config/config.php unter app.key.
 *
 * Es werden keine Passwörter, Schlüssel oder Kundendaten ausgegeben.
 * Nach der Einrichtung darf diese Datei gelöscht werden.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

// -------------------------------------------------------------- Zugangsschutz
// Ohne den Schlüssel aus der Konfiguration bleibt die Seite verschlossen:
// die Ausgabe verrät sonst den inneren Aufbau der Installation.
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $expected = (string) Config::get('app.key', '');
    $given = (string) ($_GET['key'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit('Kein Zugriff. Aufruf mit ?key=<app.key aus config/config.php>.');
    }
}

/** @var array<int, array{ok: bool, label: string, detail: string, fix: string}> $results */
$results = [];

function check(string $label, bool $ok, string $detail = '', string $fix = ''): void
{
    global $results;
    $results[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail, 'fix' => $fix];
}

// ------------------------------------------------------------------- PHP
check('PHP-Version', PHP_VERSION_ID >= 80100, PHP_VERSION, 'Mindestens PHP 8.1 einstellen.');

foreach (['pdo', 'gd', 'curl', 'mbstring', 'fileinfo', 'openssl', 'json'] as $ext) {
    check('Erweiterung ' . $ext, extension_loaded($ext), '', 'In den PHP-Einstellungen aktivieren.');
}

$driver = (string) Config::get('db.driver', 'sqlite');
check(
    'Datenbanktreiber ' . $driver,
    extension_loaded($driver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite'),
    '',
    'Die passende PDO-Erweiterung aktivieren.'
);

// --------------------------------------------------------------- Verzeichnisse
foreach (['storage', 'storage/logs', 'uploads'] as $dir) {
    $path = BASE_PATH . '/' . $dir;
    $exists = is_dir($path);
    check(
        'Ordner ' . $dir,
        $exists && is_writable($path),
        $exists ? (is_writable($path) ? 'beschreibbar' : 'nicht beschreibbar') : 'fehlt',
        $exists ? 'Schreibrechte setzen (755, Eigentümer der Webbenutzer).' : 'Ordner anlegen.'
    );
}

// ------------------------------------------------------------------ Adressen
$configuredUrl = (string) Config::get('app.url', '');
$requestHost = $isCli ? '' : (string) ($_SERVER['HTTP_HOST'] ?? '');
$configuredHost = $configuredUrl !== '' ? (string) parse_url($configuredUrl, PHP_URL_HOST) : '';
$urlOk = $configuredUrl === '' || $isCli || strcasecmp($configuredHost, explode(':', $requestHost)[0]) === 0;
check(
    'Adresse app.url',
    $urlOk,
    $configuredUrl !== '' ? $configuredUrl : '(leer, wird aus der Anfrage abgeleitet)',
    'In config/config.php app.url auf https://' . ($requestHost !== '' ? $requestHost : 'deine-domain.tld') . ' setzen.'
);

check(
    'Fehleranzeige aus',
    (bool) Config::get('app.debug', false) === false,
    (bool) Config::get('app.debug', false) ? 'debug ist an' : 'debug ist aus',
    'In config/config.php app.debug auf false setzen.'
);

check(
    'Zeitzone',
    date_default_timezone_get() !== 'UTC' || (string) Config::get('app.timezone', '') === 'UTC',
    date_default_timezone_get(),
    'In config/config.php app.timezone setzen, z.B. Europe/Zurich.'
);

check(
    'Sichere Verbindung',
    $isCli || \App\Core\Session::isHttps(),
    $isCli ? '(Kommandozeile)' : (\App\Core\Session::isHttps() ? 'https' : 'http'),
    'Zertifikat in Plesk aktivieren und die Domain auf https umleiten.'
);

check(
    'Verschlüsselungsschlüssel gesetzt',
    trim((string) Config::get('app.key', '')) !== '',
    '',
    'app.key in config/config.php eintragen (der Installer erzeugt ihn).'
);

// ------------------------------------------------------------------ Datenbank
$dbOk = false;
try {
    Database::scalar('SELECT 1');
    $dbOk = true;
    check('Datenbankverbindung', true, $driver);
} catch (\Throwable $e) {
    check('Datenbankverbindung', false, $e->getMessage(), 'Zugangsdaten in config/config.php pruefen.');
}

// -------------------------------------------------------------------- Schema
if ($dbOk) {
    /** Spalten einer Tabelle, treiberunabhaengig. */
    $columnsOf = static function (string $table) use ($driver): array {
        try {
            if ($driver === 'sqlite') {
                return array_map(
                    static fn(array $r): string => (string) $r['name'],
                    Database::fetchAll("PRAGMA table_info({$table})")
                );
            }
            return array_map(
                static fn(array $r): string => (string) ($r['Field'] ?? ''),
                Database::fetchAll("SHOW COLUMNS FROM {$table}")
            );
        } catch (\Throwable) {
            return [];
        }
    };

    // Das erwartet die Anwendung im aktuellen Stand
    $expectedSchema = [
        'dealerships' => ['id', 'name', 'credits', 'listing_tone', 'listing_sample',
                          'listing_title_style', 'listing_title_sample', 'channels_synced_at'],
        'users'       => ['id', 'dealership_id', 'email', 'username', 'password_hash',
                          'role', 'is_active', 'is_demo', 'email_verified_at'],
        'vehicles'    => ['id', 'dealership_id', 'make', 'model', 'previous_owners', 'status'],
        'vehicle_images' => ['id', 'vehicle_id', 'file_path', 'cutout_path', 'composed_path', 'background_key'],
        'vehicle_field_status' => ['id', 'vehicle_id', 'field_name', 'status', 'alternatives'],
        'listings'    => ['id', 'vehicle_id', 'title', 'description', 'title_template', 'description_template'],
        'listing_scores' => ['id', 'listing_id', 'total_score'],
        'credit_orders' => ['id', 'dealership_id', 'status', 'provider_ref'],
        'credit_transactions' => ['id', 'dealership_id', 'delta', 'reason'],
        'social_posts' => ['id', 'dealership_id', 'status', 'image_ids', 'stat_views', 'stat_likes'],
        'backgrounds' => ['id', 'dealership_id', 'file_path'],
        'background_favorites' => ['id', 'dealership_id', 'bg_key'],
        'channel_listings' => ['id', 'listing_id', 'provider', 'external_id'],
        'channel_remote_listings' => ['id', 'dealership_id', 'provider', 'external_id'],
        'settings'    => ['setting_key', 'setting_value'],
        'activity_logs' => ['id', 'user_id', 'action'],
        'notifications' => ['id', 'user_id'],
        'leads'       => ['id', 'dealership_id', 'status'],
        'messages'    => ['id'],
        'tasks'       => ['id'],
        'documents'   => ['id'],
        'integrations' => ['id', 'dealership_id', 'provider', 'status'],
        'integration_tokens' => ['id'],
        'social_templates' => ['id'],
        'subscriptions' => ['id'],
        'login_attempts' => ['id'],
        'password_resets' => ['id'],
        'email_verifications' => ['id'],
        'listing_recommendations' => ['id', 'listing_id'],
        'vehicle_features' => ['id', 'vehicle_id', 'feature'],
    ];

    $missingTables = [];
    $missingColumns = [];
    foreach ($expectedSchema as $table => $columns) {
        $actual = $columnsOf($table);
        if ($actual === []) {
            $missingTables[] = $table;
            continue;
        }
        $missing = array_diff($columns, $actual);
        if ($missing !== []) {
            $missingColumns[$table] = $missing;
        }
    }

    check(
        'Alle Tabellen vorhanden',
        $missingTables === [],
        $missingTables === [] ? count($expectedSchema) . ' Tabellen' : 'fehlt: ' . implode(', ', $missingTables),
        'database/schema.' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql einspielen.'
    );

    $columnDetail = [];
    foreach ($missingColumns as $table => $cols) {
        $columnDetail[] = $table . ': ' . implode(', ', $cols);
    }
    check(
        'Alle Spalten vorhanden',
        $missingColumns === [],
        $columnDetail === [] ? 'vollstaendig' : implode(' | ', $columnDetail),
        'Seite neu laden: die Migrationen ergaenzen fehlende Spalten selbst. '
        . 'Bleibt es dabei, storage/logs pruefen.'
    );

    $version = Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = :k',
        ['k' => 'schema_version']
    );
    check('Schema-Version', $version !== null, (string) ($version ?? 'unbekannt'), '');
}

// ------------------------------------------------------------------ E-Mail
$mailDriver = (string) Config::get('mail.driver', 'mail');
$mailAvailable = function_exists('mail')
    && !in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
check(
    'E-Mail-Versand',
    $mailDriver !== 'log' && ($mailDriver !== 'mail' || $mailAvailable),
    $mailDriver === 'log'
        ? 'log (kein Versand, Bestaetigung abgeschaltet)'
        : ($mailDriver === 'mail' && !$mailAvailable ? 'mail (aber mail() ist gesperrt)' : $mailDriver),
    $mailDriver === 'log'
        ? 'In config/config.php mail.driver auf mail setzen, damit Bestaetigungen ankommen.'
        : 'mail() ist auf diesem Server gesperrt. In config/config.php mail.driver auf smtp setzen und die Zugangsdaten des Postfachs eintragen.'
);

$wanted = (bool) Config::get('features.email_verification', false);
$effective = \App\Auth\EmailVerification::isEnabled();
check(
    'Bestaetigung der E-Mail-Adresse',
    $wanted === $effective,
    $effective ? 'eingeschaltet' : ($wanted ? 'gewuenscht, aber ohne Versand nicht moeglich' : 'ausgeschaltet'),
    'Ohne echten Mailversand bleibt die Bestaetigung aus, sonst kaeme niemand hinein.'
);

// ------------------------------------------------- Verbindungen nach draussen
// Viele Hoster sperren ausgehende Verbindungen. Dann schlaegt nicht nur ein
// git clone fehl, sondern auch jeder Aufruf der KI. Ein kurzer Versuch je
// Ziel sagt, woran es liegt: Namensaufloesung, Port oder Zertifikat.
foreach ([
    'api.openai.com' => 'KI-Erkennung und Texte',
    'github.com'     => 'Bereitstellung ueber Git',
] as $probeHost => $purpose) {
    $ip = gethostbyname($probeHost);
    if ($ip === $probeHost) {
        check('Erreichbar: ' . $probeHost, false, 'Name nicht aufloesbar', 'Der Server hat keinen funktionierenden DNS. Hosttime fragen.');
        continue;
    }

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen('tcp://' . $ip, 443, $errno, $errstr, 5);
    if ($socket === false) {
        check(
            'Erreichbar: ' . $probeHost,
            false,
            'Port 443 blockiert (' . trim($errstr) . ')',
            'Hosttime bitten, ausgehende Verbindungen auf Port 443 freizugeben. Ohne sie laeuft ' . $purpose . ' nicht.'
        );
        continue;
    }
    fclose($socket);
    check('Erreichbar: ' . $probeHost, true, $ip . ':443 offen');
}

// ------------------------------------------------------------------- Ausgabe
$failed = array_filter($results, static fn(array $r): bool => !$r['ok']);

if ($isCli) {
    foreach ($results as $r) {
        printf("%s %-32s %s\n", $r['ok'] ? 'OK  ' : 'FEHL', $r['label'], $r['detail']);
        if (!$r['ok'] && $r['fix'] !== '') {
            printf("     -> %s\n", $r['fix']);
        }
    }
    printf("\n%d von %d in Ordnung\n", count($results) - count($failed), count($results));
    if ($recentErrors !== []) {
        echo "
Letzte Fehler im Protokoll:
";
        foreach ($recentErrors as $line) {
            echo '  ' . $line . "
";
        }
    }
    exit(count($failed) === 0 ? 0 : 1);
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Selbstpruefung | RapidCar</title>
<style>
    body { font-family: system-ui, sans-serif; background: #f6f7f9; color: #14181f;
           margin: 0; padding: 40px 20px; line-height: 1.5; }
    .wrap { max-width: 820px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .lead { color: #5b6472; font-size: 14px; margin: 0 0 24px; }
    .row { display: flex; gap: 14px; align-items: flex-start; padding: 12px 16px;
           background: #fff; border: 1px solid #e4e7ec; border-radius: 12px; margin-bottom: 8px; }
    .mark { flex: 0 0 auto; width: 22px; height: 22px; border-radius: 50%; color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; }
    .ok .mark { background: #0a9257; }
    .bad .mark { background: #d92d20; }
    .label { font-weight: 600; font-size: 14.5px; }
    .detail { color: #5b6472; font-size: 13px; overflow-wrap: anywhere; }
    .fix { color: #1d4fd7; font-size: 13px; margin-top: 4px; }
    .summary { padding: 16px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; }
    .summary.ok { background: #ecfdf3; color: #067647; }
    .summary.bad { background: #fef3f2; color: #b42318; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Selbstpruefung</h1>
    <p class="lead">Diese Seite pruefen, wenn etwas nicht laeuft. Nach der Einrichtung darf die Datei geloescht werden.</p>

    <div class="summary <?= $failed === [] ? 'ok' : 'bad' ?>">
        <?= $failed === []
            ? 'Alles in Ordnung: ' . count($results) . ' Pruefungen bestanden.'
            : count($failed) . ' von ' . count($results) . ' Pruefungen fehlgeschlagen.' ?>
    </div>

    <?php foreach ($results as $r): ?>
        <div class="row <?= $r['ok'] ? 'ok' : 'bad' ?>">
            <span class="mark"><?= $r['ok'] ? '&check;' : '!' ?></span>
            <div>
                <div class="label"><?= htmlspecialchars($r['label'], ENT_QUOTES) ?></div>
                <?php if ($r['detail'] !== ''): ?>
                    <div class="detail"><?= htmlspecialchars($r['detail'], ENT_QUOTES) ?></div>
                <?php endif; ?>
                <?php if (!$r['ok'] && $r['fix'] !== ''): ?>
                    <div class="fix"><?= htmlspecialchars($r['fix'], ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($recentErrors !== []): ?>
        <h2 style="font-size:16px;margin:28px 0 8px">Letzte Fehler im Protokoll</h2>
        <p class="lead" style="margin-bottom:12px">Hier steht der eigentliche Grund, wenn eine Seite mit Fehler 500 antwortet.</p>
        <?php foreach ($recentErrors as $line): ?>
            <div class="row bad">
                <span class="mark">!</span>
                <div class="detail" style="font-family:ui-monospace,monospace"><?= htmlspecialchars($line, ENT_QUOTES) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
