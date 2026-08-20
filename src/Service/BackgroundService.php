<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use RuntimeException;

/**
 * Hintergründe für freigestellte Fahrzeugfotos.
 *
 * Wichtig: Hier arbeitet keine KI. Das Freistellen kostet einen einzigen
 * KI-Aufruf je Foto (siehe OpenAiProvider::cutoutImage). Danach wird der
 * Zuschnitt gespeichert und jeder Hintergrundwechsel ist reine Bildmontage
 * mit GD, beliebig oft und ohne weitere Kosten.
 */
final class BackgroundService
{
    /**
     * Vier kuratierte Studiohintergruende, als echte Bilder mitgeliefert
     * (assets/backgrounds, ohne externe Abrufe erzeugt).
     */
    public const TEMPLATES = [
        'studio_light' => ['label' => 'Studio hell',   'file' => 'assets/backgrounds/studio-light.jpg'],
        'studio_dark'  => ['label' => 'Studio dunkel', 'file' => 'assets/backgrounds/studio-dark.jpg'],
        'showroom'     => ['label' => 'Garage',   'file' => 'assets/backgrounds/showroom.jpg'],
        'asphalt'      => ['label' => 'Abendstrasse',  'file' => 'assets/backgrounds/asphalt.jpg'],
    ];

    /** Kennung für „unverändert lassen". */
    public const KEY_ORIGINAL = '';

    /** Eigene Hintergründe tragen dieses Präfix plus ihre ID. */
    public const OWN_PREFIX = 'own:';

    /**
     * Auswählbare Hintergründe.
     *
     * Mit Spyne sind das die Studio-Hintergründe des Kontos: der Dienst setzt
     * das Fahrzeug samt Boden und Schatten selbst hinein, mitgelieferte Bilder
     * wären dort nur ein schlechterer Ersatz. Ohne Spyne bleiben die eigenen
     * Vorlagen.
     *
     * @return array<string, array{label: string, file: string, scene: bool}>
     */
    public static function templates(): array
    {
        if (\App\Integration\SpyneService::isConfigured()) {
            $scenes = [];
            foreach (\App\Integration\SpyneService::scenes() as $id => $scene) {
                if ($scene['unavailable']) {
                    continue;   // Spyne kennt diese Kennung nicht
                }
                $preview = $scene['preview'];
                // Relative Pfade zeigen auf /uploads (selbst erzeugte Vorschau)
                if ($preview !== '' && !preg_match('#^https?://#i', $preview)) {
                    $preview = upload_url($preview);
                }
                $scenes[$id] = ['label' => $scene['label'], 'file' => $preview, 'scene' => true, 'theme' => $scene['theme']];
            }
            return $scenes;
        }

        $templates = [];
        foreach (self::TEMPLATES as $key => $template) {
            $templates[$key] = $template + ['scene' => false];
        }
        return $templates;
    }

    /** Läuft der Hintergrundwechsel über Spyne? */
    public static function usesSpyne(): bool
    {
        return \App\Integration\SpyneService::isConfigured();
    }

    public static function isTemplate(string $key): bool
    {
        return isset(self::templates()[$key]);
    }

    /**
     * Anzeigename eines Hintergrunds. Unbekannte Schluessel liefern einen
     * leeren Text, damit nie ein technischer Wert in der Oberflaeche landet.
     */
    public static function label(string $key, int $dealershipId): string
    {
        $templates = self::templates();
        if (isset($templates[$key])) {
            return (string) $templates[$key]['label'];
        }
        $ownId = self::ownId($key);
        if ($ownId === null) {
            return '';
        }
        $own = self::ownBackground($ownId, $dealershipId);
        return $own === null ? '' : (string) $own['name'];
    }

    public static function ownId(string $key): ?int
    {
        if (!str_starts_with($key, self::OWN_PREFIX)) {
            return null;
        }
        $id = (int) substr($key, strlen(self::OWN_PREFIX));
        return $id > 0 ? $id : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function ownBackgrounds(int $dealershipId): array
    {
        return Database::fetchAll(
            'SELECT * FROM backgrounds WHERE dealership_id = :d ORDER BY id DESC',
            ['d' => $dealershipId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function ownBackground(int $id, int $dealershipId): ?array
    {
        return Database::fetch(
            'SELECT * FROM backgrounds WHERE id = :id AND dealership_id = :d',
            ['id' => $id, 'd' => $dealershipId]
        );
    }

    /**
     * Setzt den freigestellten Zuschnitt vor den gewählten Hintergrund.
     *
     * @param string $cutoutPath Absoluter Pfad zum PNG mit Transparenz
     * @param string $key        Vorlagenschlüssel oder "own:<id>"
     * @param int    $dealershipId
     * @return string Relativer Pfad des erzeugten Bildes unterhalb von /uploads
     */
    public static function compose(string $cutoutPath, string $key, int $dealershipId, string $targetSubDir): string
    {
        if (!function_exists('imagecreatefrompng')) {
            throw new RuntimeException('Die PHP-Erweiterung GD wird für den Hintergrundwechsel benötigt.');
        }
        if (!is_file($cutoutPath)) {
            throw new RuntimeException('Der Zuschnitt wurde nicht gefunden.');
        }

        $cutout = @imagecreatefrompng($cutoutPath);
        if ($cutout === false) {
            throw new RuntimeException('Der Zuschnitt konnte nicht gelesen werden.');
        }
        $width = imagesx($cutout);
        $height = imagesy($cutout);

        $canvas = self::canvas($key, $dealershipId, $width, $height);

        imagealphablending($canvas, true);
        imagecopy($canvas, $cutout, 0, 0, 0, 0, $width, $height);
        imagedestroy($cutout);

        $relative = rtrim($targetSubDir, '/') . '/bg-' . bin2hex(random_bytes(8)) . '.jpg';
        $absolute = ImageService::uploadPath($relative);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            throw new RuntimeException('Der Zielordner konnte nicht angelegt werden.');
        }

        imagejpeg($canvas, $absolute, 88);
        imagedestroy($canvas);

        return $relative;
    }

    /** Erzeugt die Hintergrundfläche: Farbverlauf oder eigenes Bild. */
    private static function canvas(string $key, int $dealershipId, int $width, int $height): \GdImage
    {
        $ownId = self::ownId($key);
        if ($ownId !== null) {
            $row = self::ownBackground($ownId, $dealershipId);
            if ($row === null) {
                throw new RuntimeException('Der gewählte Hintergrund gehört nicht zu diesem Autohaus.');
            }
            $path = ImageService::uploadPath((string) $row['file_path']);
            $own = self::loadImage($path);
            if ($own === null) {
                throw new RuntimeException('Der eigene Hintergrund konnte nicht gelesen werden.');
            }
            $canvas = imagecreatetruecolor($width, $height);
            // Seitenverhältnis erhalten und mittig zuschneiden, damit nichts verzerrt.
            $ownWidth = imagesx($own);
            $ownHeight = imagesy($own);
            $scale = max($width / $ownWidth, $height / $ownHeight);
            $newWidth = (int) round($ownWidth * $scale);
            $newHeight = (int) round($ownHeight * $scale);
            $offsetX = (int) round(($newWidth - $width) / 2);
            $offsetY = (int) round(($newHeight - $height) / 2);
            imagecopyresampled($canvas, $own, -$offsetX, -$offsetY, 0, 0, $newWidth, $newHeight, $ownWidth, $ownHeight);
            imagedestroy($own);
            return $canvas;
        }

        $template = self::TEMPLATES[$key] ?? self::TEMPLATES['studio_light'];
        $file = BASE_PATH . '/' . $template['file'];
        $bg = self::loadImage($file);
        if ($bg === null) {
            throw new RuntimeException('Die Hintergrund-Vorlage wurde nicht gefunden.');
        }
        $canvas = imagecreatetruecolor($width, $height);
        $bgWidth = imagesx($bg);
        $bgHeight = imagesy($bg);
        $scale = max($width / $bgWidth, $height / $bgHeight);
        $newWidth = (int) round($bgWidth * $scale);
        $newHeight = (int) round($bgHeight * $scale);
        $offsetX = (int) round(($newWidth - $width) / 2);
        $offsetY = (int) round(($newHeight - $height) / 2);
        imagecopyresampled($canvas, $bg, -$offsetX, -$offsetY, 0, 0, $newWidth, $newHeight, $bgWidth, $bgHeight);
        imagedestroy($bg);
        return $canvas;
    }

    // -------------------------------------------------------------- Favoriten

    /** @return array<int, string> Favorisierte Hintergrund-Schluessel */
    public static function favorites(int $dealershipId): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['bg_key'],
            Database::fetchAll(
                'SELECT bg_key FROM background_favorites WHERE dealership_id = :d ORDER BY id DESC',
                ['d' => $dealershipId]
            )
        );
    }

    /** Merkt oder entfernt einen Favoriten. Liefert den neuen Zustand. */
    public static function toggleFavorite(int $dealershipId, string $key): bool
    {
        if (!self::isTemplate($key) && self::ownId($key) === null) {
            throw new RuntimeException('Unbekannter Hintergrund.');
        }
        $ownId = self::ownId($key);
        if ($ownId !== null && self::ownBackground($ownId, $dealershipId) === null) {
            throw new RuntimeException('Der Hintergrund gehört nicht zu diesem Autohaus.');
        }
        $existing = Database::fetch(
            'SELECT id FROM background_favorites WHERE dealership_id = :d AND bg_key = :k',
            ['d' => $dealershipId, 'k' => $key]
        );
        if ($existing !== null) {
            Database::run('DELETE FROM background_favorites WHERE id = :id', ['id' => (int) $existing['id']]);
            return false;
        }
        Database::insert('background_favorites', [
            'dealership_id' => $dealershipId,
            'bg_key'        => $key,
            'created_at'    => Database::now(),
        ]);
        return true;
    }

    private static function loadImage(string $path): ?\GdImage
    {
        if (!is_file($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        $image = match ((string) $info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
        return $image === false ? null : $image;
    }
}
