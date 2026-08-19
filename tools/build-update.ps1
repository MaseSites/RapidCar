# Baut ein Update-Paket fuer den Server.
#
#   .\tools\build-update.ps1                  Alle Code-Dateien (Vollabgleich)
#   .\tools\build-update.ps1 -Seit abc1234    Nur Dateien, die sich seit dem
#                                             angegebenen Commit geaendert haben
#
# Was NIE ins Paket kommt, damit ein Update auf dem Server nichts zerstoert:
#   - config/           die Konfiguration des Servers (Schluessel!) bleibt seine
#   - storage/          Datenbank und Protokolle
#   - uploads/          Fotos der Kunden
#   - install/, tests/, tools/, testdaten/, .git/
#
# Das Zip wird mit dem Windows-bsdtar gepackt: Linux-unzip verlangt
# Schraegstriche als Pfadtrenner.

param(
    [string]$Seit = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$zip = Join-Path $root 'rapidcar-deploy.zip'
$stage = Join-Path $env:TEMP ('rapidcar-update-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stage | Out-Null

$ausschluss = '^(config/|storage/|uploads/|install/|tests/|tools/|testdaten/|\.git/)|(^|/)config\.php$|\.sqlite$|\.zip$'

if ($Seit -ne '') {
    # Nur geaenderte Dateien seit dem angegebenen Commit
    $dateien = git diff --name-only --diff-filter=ACMR "$Seit..HEAD" | Where-Object { $_ -notmatch $ausschluss }
    if (-not $dateien) {
        Remove-Item $stage -Recurse -Force
        Write-Host "Keine Aenderungen seit $Seit. Kein Paket gebaut."
        exit 0
    }
} else {
    # Vollabgleich: alle versionierten Dateien
    $dateien = git ls-files | Where-Object { $_ -notmatch $ausschluss }
}

foreach ($f in $dateien) {
    $quelle = Join-Path $root ($f -replace '/', '\')
    if (-not (Test-Path $quelle)) { continue }
    $ziel = Join-Path $stage ($f -replace '/', '\')
    $ordner = Split-Path $ziel -Parent
    if (-not (Test-Path $ordner)) { New-Item -ItemType Directory -Path $ordner -Force | Out-Null }
    Copy-Item $quelle $ziel
}

if (Test-Path $zip) { [System.IO.File]::Delete($zip) }
& "$env:SystemRoot\System32\tar.exe" -a -cf $zip -C $stage '.'
Remove-Item $stage -Recurse -Force

$anzahl = ($dateien | Measure-Object).Count
$stand = (git rev-parse --short HEAD).Trim()
Write-Host "rapidcar-deploy.zip gebaut: $anzahl Dateien, Stand $stand"
Write-Host "Enthaelt weder config noch storage noch uploads: einfach entpacken und ueberschreiben."
