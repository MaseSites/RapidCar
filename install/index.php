<?php
/**
 * Installations-Assistent (§65).
 *
 * Schritte:
 *   1. Umgebungsprüfung (PHP, Extensions, Schreibrechte)
 *   2. Datenbank konfigurieren + Verbindung testen
 *   3. Schema importieren
 *   4. Admin-Konto (super_admin) erstellen
 *   5. Demo-Daten (optional) + Abschluss
 *
 * Nach Abschluss sperrt sich der Installer selbst (storage/installed.lock).
 */

declare(strict_types=1);

define('RAPIDCAR', true);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/includes/autoload.php';
require_once BASE_PATH . '/includes/functions.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Encryption;
use App\Core\Session;

error_reporting(E_ALL);
ini_set('display_errors', '0');

Session::start();

// ---------------------------------------------------------------------------
// Selbstsperre: bereits installiert?
// ---------------------------------------------------------------------------
$lockFile = BASE_PATH . '/storage/installed.lock';
if (is_file($lockFile)) {
    install_render_locked();
    exit;
}

$step = max(1, min(5, (int) ($_GET['step'] ?? 1)));
$errors = [];

/** @var array<string, mixed> $state */
$state = $_SESSION['install'] ?? [];

// ---------------------------------------------------------------------------
// POST-Verarbeitung
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::validate(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $errors[] = 'Ungültiges Sicherheitstoken. Bitte erneut versuchen.';
    } else {
        switch ((int) ($_POST['step'] ?? 0)) {
            // ---------------------------------------------------- Schritt 2: DB
            case 2:
                $driver = ($_POST['driver'] ?? '') === 'sqlite' ? 'sqlite' : 'mysql';
                $db = [
                    'driver'   => $driver,
                    'host'     => trim((string) ($_POST['db_host'] ?? 'localhost')),
                    'port'     => trim((string) ($_POST['db_port'] ?? '3306')),
                    'name'     => trim((string) ($_POST['db_name'] ?? '')),
                    'user'     => trim((string) ($_POST['db_user'] ?? '')),
                    'password' => (string) ($_POST['db_password'] ?? ''),
                ];
                $test = install_test_connection($db);
                if ($test !== true) {
                    $errors[] = $test;
                } else {
                    $state['db'] = $db;
                    $_SESSION['install'] = $state;
                    header('Location: ?step=3');
                    exit;
                }
                break;

            // ------------------------------------------------ Schritt 3: Schema
            case 3:
                if (!isset($state['db'])) {
                    header('Location: ?step=2');
                    exit;
                }
                $result = install_import_schema($state['db']);
                if ($result !== true) {
                    $errors[] = $result;
                } else {
                    $state['schema_done'] = true;
                    $_SESSION['install'] = $state;
                    header('Location: ?step=4');
                    exit;
                }
                break;

            // ------------------------------------------------- Schritt 4: Admin
            case 4:
                if (empty($state['schema_done'])) {
                    header('Location: ?step=3');
                    exit;
                }
                $firstName = trim((string) ($_POST['first_name'] ?? ''));
                $lastName = trim((string) ($_POST['last_name'] ?? ''));
                $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                $password = (string) ($_POST['password'] ?? '');
                $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

                if ($firstName === '' || $lastName === '') {
                    $errors[] = 'Vor- und Nachname sind erforderlich.';
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
                }
                if (mb_strlen($password) < 10) {
                    $errors[] = 'Das Admin-Passwort muss mindestens 10 Zeichen lang sein.';
                }
                if ($password !== $passwordConfirm) {
                    $errors[] = 'Die Passwörter stimmen nicht überein.';
                }
                if ($errors === []) {
                    $state['admin'] = [
                        'first_name' => $firstName,
                        'last_name'  => $lastName,
                        'email'      => $email,
                        // Nur den Hash in der Session halten, nie das Klartext-Passwort
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ];
                    $_SESSION['install'] = $state;
                    header('Location: ?step=5');
                    exit;
                }
                break;

            // --------------------------------------------- Schritt 5: Abschluss
            case 5:
                if (empty($state['admin']) || empty($state['db'])) {
                    header('Location: ?step=1');
                    exit;
                }
                $withDemo = !empty($_POST['demo_data']);
                $result = install_finalize($state, $withDemo);
                if ($result !== true) {
                    $errors[] = $result;
                } else {
                    unset($_SESSION['install']);
                    install_render_success();
                    exit;
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------

/** @return array<int, array{label: string, ok: bool, required: bool, hint: string}> */
function install_environment_checks(): array
{
    $checks = [];
    $checks[] = [
        'label' => 'PHP-Version ≥ 8.0 (aktuell: ' . PHP_VERSION . ')',
        'ok' => PHP_VERSION_ID >= 80000, 'required' => true,
        'hint' => 'Bitte PHP 8.0 oder neuer aktivieren (bei Hosttime im Control Panel wählbar).',
    ];
    foreach ([
        ['pdo', 'PDO', true, 'Datenbankzugriff.'],
        ['openssl', 'OpenSSL', true, 'Verschlüsselung der Integration-Tokens.'],
        ['mbstring', 'Multibyte String (mbstring)', true, 'Umlaute und Textverarbeitung.'],
        ['fileinfo', 'Fileinfo', true, 'Sichere Prüfung hochgeladener Dateien.'],
        ['gd', 'GD (Bildverarbeitung)', true, 'Bild-Neukodierung und Thumbnails.'],
        ['curl', 'cURL', false, 'Externe APIs (AutoScout24, KI), erst für Live-Integrationen nötig.'],
    ] as [$ext, $label, $required, $hint]) {
        $checks[] = ['label' => $label, 'ok' => extension_loaded($ext), 'required' => $required, 'hint' => $hint];
    }
    $hasMysql = extension_loaded('pdo_mysql');
    $hasSqlite = extension_loaded('pdo_sqlite');
    $checks[] = [
        'label' => 'PDO-Treiber: ' . implode(', ', array_filter([$hasMysql ? 'MySQL' : null, $hasSqlite ? 'SQLite' : null])) ?: 'keiner',
        'ok' => $hasMysql || $hasSqlite, 'required' => true,
        'hint' => 'Mindestens pdo_mysql (Produktion) oder pdo_sqlite (lokal) wird benötigt.',
    ];
    foreach ([
        ['/config', 'Schreibrecht: /config'],
        ['/storage', 'Schreibrecht: /storage'],
        ['/uploads', 'Schreibrecht: /uploads'],
    ] as [$dir, $label]) {
        $checks[] = [
            'label' => $label, 'ok' => is_writable(BASE_PATH . $dir), 'required' => true,
            'hint' => 'Bitte Verzeichnisrechte anpassen (z.B. 755/775 per FTP).',
        ];
    }
    return $checks;
}

/** @param array<string, string> $db */
function install_test_connection(array $db): true|string
{
    try {
        if ($db['driver'] === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                return 'Die PHP-Extension pdo_sqlite ist nicht verfügbar.';
            }
            $path = BASE_PATH . '/storage/database.sqlite';
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return true;
        }
        if (!extension_loaded('pdo_mysql')) {
            return 'Die PHP-Extension pdo_mysql ist nicht verfügbar. Für die lokale Entwicklung kann SQLite gewählt werden.';
        }
        if ($db['name'] === '' || $db['user'] === '') {
            return 'Bitte Datenbankname und Benutzer angeben.';
        }
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
        new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return true;
    } catch (PDOException $e) {
        return 'Verbindung fehlgeschlagen: ' . $e->getMessage();
    }
}

/** @param array<string, string> $db */
function install_open_pdo(array $db): PDO
{
    if ($db['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . BASE_PATH . '/storage/database.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    return new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** @param array<string, string> $db */
function install_import_schema(array $db): true|string
{
    $file = BASE_PATH . '/database/schema.' . ($db['driver'] === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
    if (!is_file($file)) {
        return 'Schema-Datei fehlt: ' . basename($file);
    }
    $sql = (string) file_get_contents($file);

    try {
        $pdo = install_open_pdo($db);
        // Anweisungen einzeln ausführen (PDO::exec mit Multi-Statements ist unzuverlässig)
        $statements = install_split_sql($sql);
        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        return true;
    } catch (PDOException $e) {
        return 'Schema-Import fehlgeschlagen: ' . $e->getMessage();
    }
}

/** @return array<int, string> */
function install_split_sql(string $sql): array
{
    // Kommentarzeilen entfernen, dann an Semikolon am Zeilenende trennen
    $lines = preg_split('/\r?\n/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $clean[] = $line;
    }
    return array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $clean)) ?: []));
}

/** @param array<string, mixed> $state */
function install_finalize(array $state, bool $withDemo): true|string
{
    $db = $state['db'];
    $admin = $state['admin'];

    // 1. Konfigurationsdatei schreiben
    $appKey = Encryption::generateKey();
    $scheme = Session::isHttps() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = preg_replace('#/install/?$#', '', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $appUrl = $scheme . '://' . $host . rtrim(str_replace('\\', '/', (string) $basePath), '/');

    // Kann der Server E-Mails verschicken? Nur dann wird die Bestaetigung
    // der Adresse eingeschaltet, sonst sperrt sich der erste Nutzer aus.
    $canSendMail = function_exists('mail')
        && !in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    $mailDomain = strtolower(explode(':', $host)[0]);

    $config = [
        'app' => [
            'url'   => $appUrl,
            'key'   => $appKey,
            'debug' => false,
            'name'  => 'RapidCar',
            'timezone' => date_default_timezone_get() ?: 'Europe/Zurich',
            // Weiterleitung auf https uebernimmt normalerweise der Hoster.
            'force_https' => false,
        ],
        'db' => [
            'driver'      => $db['driver'],
            'host'        => $db['host'],
            'port'        => $db['port'],
            'name'        => $db['name'],
            'user'        => $db['user'],
            'password'    => $db['password'],
            'sqlite_path' => BASE_PATH . '/storage/database.sqlite',
        ],
        'mail' => [
            'driver' => $canSendMail ? 'mail' : 'log',
            'host' => '', 'port' => 587, 'username' => '', 'password' => '',
            'encryption' => 'tls', 'from' => 'noreply@' . $mailDomain, 'from_name' => 'RapidCar',
            'contact' => $admin['email'],
        ],
        'features' => ['email_verification' => $canSendMail],
        'ai' => ['mode' => 'mock', 'api_url' => '', 'api_key' => '', 'model' => ''],
        'autoscout' => [
            'client_id' => '', 'client_secret' => '', 'redirect_uri' => '',
            'api_url' => '', 'auth_url' => '', 'token_url' => '', 'scopes' => '',
        ],
        'instagram' => [
            'client_id' => '', 'client_secret' => '', 'redirect_uri' => '',
            'auth_url' => '', 'token_url' => '', 'api_url' => '', 'scopes' => '',
        ],
        'uploads' => ['max_file_size_mb' => 12, 'max_images_per_vehicle' => 20],
    ];

    $configPhp = "<?php\n\n// Von RapidCar-Installer erzeugt am " . date('d.m.Y H:i')
        . "\n// Sensible Daten. Datei niemals öffentlich zugänglich machen.\n\nreturn "
        . var_export($config, true) . ";\n";

    if (@file_put_contents(BASE_PATH . '/config/config.php', $configPhp, LOCK_EX) === false) {
        return 'Konfigurationsdatei konnte nicht geschrieben werden (/config/config.php).';
    }

    // 2. Admin-Konto anlegen
    try {
        $pdo = install_open_pdo($db);
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role, is_active, email_verified_at, onboarding_completed_at, created_at, updated_at)
             VALUES (:fn, :ln, :email, :hash, :role, 1, :now, :now2, :now3, :now4)'
        );
        $stmt->execute([
            'fn' => $admin['first_name'], 'ln' => $admin['last_name'],
            'email' => $admin['email'], 'hash' => $admin['password_hash'],
            'role' => 'super_admin',
            'now' => $now, 'now2' => $now, 'now3' => $now, 'now4' => $now,
        ]);
    } catch (PDOException $e) {
        return 'Admin-Konto konnte nicht angelegt werden: ' . $e->getMessage();
    }

    // 3. Demo-Daten (optional) — nutzt jetzt die frisch geschriebene Konfiguration
    if ($withDemo) {
        try {
            Config::reload();
            require_once BASE_PATH . '/database/seeds.php';
            rapidcar_run_seeds();
        } catch (\Throwable $e) {
            return 'Demo-Daten konnten nicht angelegt werden: ' . $e->getMessage();
        }
    }

    // 4. Selbstsperre
    if (@file_put_contents(BASE_PATH . '/storage/installed.lock', date('c'), LOCK_EX) === false) {
        return 'Sperrdatei konnte nicht geschrieben werden (/storage/installed.lock).';
    }
    return true;
}

// ---------------------------------------------------------------------------
// Darstellung
// ---------------------------------------------------------------------------

function install_render_header(int $step): void
{
    $steps = ['Umgebung', 'Datenbank', 'Schema', 'Admin-Konto', 'Abschluss'];
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RapidCar Installation</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: "Segoe UI", system-ui, sans-serif; background: #f6f7f9; color: #1a1d23; min-height: 100vh; padding: 40px 16px; }
    .wrap { max-width: 640px; margin: 0 auto; }
    .logo { text-align: center; font-size: 26px; font-weight: 800; margin-bottom: 8px; }
    .logo span { color: #667085; }
    .subtitle { text-align: center; color: #5b6472; margin-bottom: 32px; }
    .steps { display: flex; gap: 6px; margin-bottom: 24px; }
    .steps div { flex: 1; text-align: center; font-size: 12px; padding: 8px 4px; border-radius: 8px; background: #e8eaef; color: #5b6472; }
    .steps div.active { background: #2563eb; color: #fff; font-weight: 600; }
    .steps div.done { background: #d1fae5; color: #065f46; }
    .card { background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 4px 24px rgba(16,24,40,.06); }
    h1 { font-size: 20px; margin-bottom: 20px; }
    .check { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f1f4; }
    .check:last-child { border-bottom: none; }
    .check .dot { font-size: 16px; line-height: 1.4; }
    .check .hint { font-size: 12px; color: #8a93a2; margin-top: 2px; }
    .ok { color: #059669; } .fail { color: #dc2626; } .warn { color: #d97706; }
    label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
    input[type=text], input[type=email], input[type=password], select {
        width: 100%; padding: 11px 14px; border: 1px solid #d6dae1; border-radius: 10px; font-size: 15px; background: #fff;
    }
    input:focus, select:focus { outline: 2px solid #2563eb33; border-color: #2563eb; }
    .btn { display: inline-block; width: 100%; margin-top: 24px; background: #1a1d23; color: #fff; border: none; padding: 13px; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; }
    .btn:hover { background: #2d323c; }
    .btn[disabled] { background: #c3c8d1; cursor: not-allowed; }
    .errors { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
    .info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: 10px; padding: 12px 16px; margin: 14px 0; font-size: 13px; line-height: 1.5; }
    .checkbox-row { display: flex; gap: 10px; align-items: flex-start; margin-top: 16px; font-size: 14px; }
    .checkbox-row input { margin-top: 3px; }
    .success-icon { font-size: 52px; text-align: center; margin-bottom: 12px; }
    p { line-height: 1.6; color: #3d4450; }
    code { background: #f0f1f4; padding: 1px 6px; border-radius: 5px; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="logo">Vehicle<span>AI</span></div>
    <div class="subtitle">Installation</div>
    <div class="steps">
        <?php foreach ($steps as $i => $label): ?>
            <div class="<?= ($i + 1) === $step ? 'active' : (($i + 1) < $step ? 'done' : '') ?>"><?= ($i + 1) ?>. <?= $label ?></div>
        <?php endforeach; ?>
    </div>
    <div class="card">
    <?php
}

function install_render_footer(): void
{
    echo '</div></div></body></html>';
}

/** @param array<int, string> $errors */
function install_render_errors(array $errors): void
{
    if ($errors !== []) {
        echo '<div class="errors">';
        foreach ($errors as $error) {
            echo '<div>' . htmlspecialchars($error) . '</div>';
        }
        echo '</div>';
    }
}

function install_render_locked(): void
{
    install_render_header(5);
    ?>
    
    <h1 style="text-align:center">Installation bereits abgeschlossen</h1>
    <p style="text-align:center">Der Installer ist gesperrt. Aus Sicherheitsgründen sollte das Verzeichnis
    <code>/install</code> vom Server gelöscht werden.</p>
    <?php
    // Kurzer Zustandsbericht ohne Einzelheiten: hilft, wenn die Seite nach
    // einem Umzug Fehler wirft. Zugangsdaten oder Tabellen werden nicht genannt.
    $dbState = 'Datenbank erreichbar';
    $dbHint = '';
    try {
        $usersReady = false;
        if (\App\Core\Database::driver() === 'sqlite') {
            $usersReady = \App\Core\Database::scalar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'"
            ) !== false;
        } else {
            $usersReady = (int) \App\Core\Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'users'"
            ) > 0;
        }
        if (!$usersReady) {
            $dbState = 'Datenbank erreichbar, aber leer';
            $dbHint = 'Beim naechsten Seitenaufruf legt die Anwendung das Schema selbst neu an. Konten muessen danach neu erstellt werden.';
        }
    } catch (\Throwable) {
        $dbState = 'Datenbank NICHT erreichbar';
        $dbHint = 'Zugangsdaten in config/config.php pruefen. Einzelheiten zeigt systemcheck.php?key=&lt;app.key&gt;.';
    }
    ?>
    <div class="info"><strong><?= $dbState ?></strong><?= $dbHint !== '' ? '<br>' . $dbHint : '' ?></div>
    <a class="btn" href="../index.php">Zur Startseite</a>
    <?php
    install_render_footer();
}

function install_render_success(): void
{
    install_render_header(5);
    ?>
    
    <h1 style="text-align:center">Installation abgeschlossen</h1>
    <p>RapidCar ist einsatzbereit. Aus Sicherheitsgründen bitte jetzt das Verzeichnis
    <code>/install</code> vom Server <strong>löschen</strong>.</p>
    <div class="info">Anmeldung mit dem soeben erstellten Admin-Konto über die Login-Seite.
    Der Admin-Bereich ist unter <code>/admin</code> erreichbar.</div>
    <a class="btn" href="../login.php">Zum Login</a>
    <?php
    install_render_footer();
}

// ---------------------------------------------------------------------------
// Schritte rendern
// ---------------------------------------------------------------------------
install_render_header($step);
install_render_errors($errors);

if ($step === 1) {
    $checks = install_environment_checks();
    $allOk = true;
    foreach ($checks as $check) {
        if ($check['required'] && !$check['ok']) {
            $allOk = false;
        }
    }
    ?>
    <h1>Umgebungsprüfung</h1>
    <?php foreach ($checks as $check): ?>
        <div class="check">
            <div class="dot <?= $check['ok'] ? 'ok' : ($check['required'] ? 'fail' : 'warn') ?>">
                <?= $check['ok'] ? 'OK' : ($check['required'] ? 'Fehlt' : 'Optional') ?>
            </div>
            <div>
                <div><?= htmlspecialchars($check['label']) ?></div>
                <?php if (!$check['ok']): ?>
                    <div class="hint"><?= htmlspecialchars($check['hint']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($allOk): ?>
        <a class="btn" href="?step=2">Weiter zur Datenbank</a>
    <?php else: ?>
        <button class="btn" disabled>Bitte zuerst die markierten Punkte beheben</button>
        <a class="btn" style="background:#e8eaef;color:#3d4450;margin-top:10px" href="?step=1">Erneut prüfen</a>
    <?php endif; ?>
    <?php
} elseif ($step === 2) {
    $hasMysql = extension_loaded('pdo_mysql');
    $hasSqlite = extension_loaded('pdo_sqlite');
    $db = $state['db'] ?? [];
    ?>
    <h1>Datenbank konfigurieren</h1>
    <form method="post" action="?step=2">
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="2">
        <label>Datenbank-Typ</label>
        <select name="driver" id="driver" onchange="document.getElementById('mysql-fields').style.display = this.value === 'mysql' ? 'block' : 'none'">
            <?php if ($hasMysql): ?>
                <option value="mysql" <?= ($db['driver'] ?? 'mysql') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (Produktion)</option>
            <?php endif; ?>
            <?php if ($hasSqlite): ?>
                <option value="sqlite" <?= ($db['driver'] ?? ($hasMysql ? 'mysql' : 'sqlite')) === 'sqlite' ? 'selected' : '' ?>>SQLite (lokale Entwicklung/Demo)</option>
            <?php endif; ?>
        </select>
        <div id="mysql-fields" style="display: <?= ($db['driver'] ?? ($hasMysql ? 'mysql' : 'sqlite')) === 'mysql' ? 'block' : 'none' ?>">
            <label>Host</label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($db['host'] ?? 'localhost') ?>">
            <label>Port</label>
            <input type="text" name="db_port" value="<?= htmlspecialchars($db['port'] ?? '3306') ?>">
            <label>Datenbankname</label>
            <input type="text" name="db_name" value="<?= htmlspecialchars($db['name'] ?? '') ?>">
            <label>Benutzer</label>
            <input type="text" name="db_user" value="<?= htmlspecialchars($db['user'] ?? '') ?>">
            <label>Passwort</label>
            <input type="password" name="db_password" value="">
            <div class="info">Die Zugangsdaten finden sich im Hosting-Control-Panel (z.B. Plesk, Bereich Datenbanken).</div>
        </div>
        <button class="btn" type="submit">Verbindung testen &amp; weiter</button>
    </form>
    <?php
} elseif ($step === 3) {
    if (!isset($state['db'])) {
        header('Location: ?step=2');
        exit;
    }
    ?>
    <h1>Schema importieren</h1>
    <p>Die Datenbanktabellen (<?= $state['db']['driver'] === 'sqlite' ? 'SQLite' : 'MySQL' ?>) werden jetzt angelegt.
    Bestehende Tabellen bleiben unangetastet (<code>CREATE TABLE IF NOT EXISTS</code>).</p>
    <form method="post" action="?step=3">
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="3">
        <button class="btn" type="submit">Tabellen jetzt anlegen</button>
    </form>
    <?php
} elseif ($step === 4) {
    if (empty($state['schema_done'])) {
        header('Location: ?step=3');
        exit;
    }
    ?>
    <h1>Admin-Konto erstellen</h1>
    <p>Dieses Konto erhält die Rolle <code>super_admin</code> und verwaltet die Plattform über <code>/admin</code>.</p>
    <form method="post" action="?step=4">
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="4">
        <label>Vorname</label>
        <input type="text" name="first_name" required>
        <label>Nachname</label>
        <input type="text" name="last_name" required>
        <label>E-Mail</label>
        <input type="email" name="email" required>
        <label>Passwort (mind. 10 Zeichen)</label>
        <input type="password" name="password" minlength="10" required>
        <label>Passwort wiederholen</label>
        <input type="password" name="password_confirm" minlength="10" required>
        <button class="btn" type="submit">Weiter</button>
    </form>
    <?php
} elseif ($step === 5) {
    if (empty($state['admin'])) {
        header('Location: ?step=4');
        exit;
    }
    ?>
    <h1>Installation abschliessen</h1>
    <p>Konfiguration schreiben, Admin-Konto anlegen und Installer sperren.</p>
    <form method="post" action="?step=5">
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="5">
        <div class="checkbox-row">
            <input type="checkbox" name="demo_data" id="demo_data" value="1" checked>
            <label for="demo_data" style="margin:0;font-weight:400">Demo-Daten installieren
                (5 Beispiel-Fahrzeuge mit generierten Platzhalterbildern, empfohlen für die erste Demo)</label>
        </div>
        <button class="btn" type="submit">Installation abschliessen</button>
    </form>
    <?php
}

install_render_footer();
