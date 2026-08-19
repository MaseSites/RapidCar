<?php
/**
 * Schnürt ein Paket zum Hochladen auf den Server.
 *
 * Enthalten ist alles, was die Anwendung zum Laufen braucht. Draussen bleiben
 * die örtliche Konfiguration mit den Schlüsseln, die Datenbank, hochgeladene
 * Fotos, Protokolle und alles, was nur zur Entwicklung gehört.
 *
 * Aufruf:  php tools/build-deploy.php
 * Ergebnis: rapidcar-deploy.zip im Projektordner
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile.\n");
}

$base = dirname(__DIR__);
$target = $base . '/rapidcar-deploy.zip';

if (!class_exists('ZipArchive')) {
    exit("Die PHP-Erweiterung zip wird benötigt.\n");
}

/** Diese Pfade gehören nicht auf den Server. */
$skipDirs = [
    '.git',
    'storage',      // Datenbank und Protokolle entstehen dort neu
    'uploads',      // Fahrzeugfotos des Betriebs
    'testdaten',    // Beispielfotos der Entwicklung
    'tests',        // Testlauf gehört nicht auf den Server
    'node_modules',
];
$skipFiles = [
    'config/config.php',   // enthält Schlüssel und Passwörter
    'rapidcar-deploy.zip',
];

$zip = new ZipArchive();
if (@is_file($target)) {
    @unlink($target);
}
if ($zip->open($target, ZipArchive::CREATE) !== true) {
    exit("Das Paket konnte nicht angelegt werden.\n");
}

$added = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));

    $firstSegment = explode('/', $relative)[0];
    if (in_array($firstSegment, $skipDirs, true) || in_array($relative, $skipFiles, true)) {
        continue;
    }
    if (str_ends_with($relative, '.bak') || str_ends_with($relative, '.sqlite')) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
        continue;
    }
    $zip->addFile($item->getPathname(), $relative);
    $added++;
}

// Leere Ordner mitgeben, die der Server zum Schreiben braucht
foreach (['storage', 'storage/logs', 'uploads'] as $dir) {
    $zip->addEmptyDir($dir);
    $zip->addFromString($dir . '/.gitkeep', '');
}
// Deren Schutzdateien wieder hineinlegen
foreach (['storage/.htaccess', 'uploads/.htaccess'] as $guard) {
    if (is_file($base . '/' . $guard)) {
        $zip->addFile($base . '/' . $guard, $guard);
    }
}

$zip->close();

$size = round(filesize($target) / 1024 / 1024, 1);
echo "Paket erstellt\n";
echo "--------------\n";
echo "Datei:   " . basename($target) . "\n";
echo "Dateien: {$added}\n";
echo "Groesse: {$size} MB\n\n";
echo "Nicht enthalten: config/config.php, storage/, uploads/, testdaten/, tests/\n";
echo "Anleitung: docs/plesk-deployment.md\n";
