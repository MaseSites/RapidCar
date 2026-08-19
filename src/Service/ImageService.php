<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use RuntimeException;

/**
 * Sichere Bildverarbeitung (§59):
 *  - MIME- und Bildvalidierung serverseitig (fileinfo + getimagesize)
 *  - Grössenlimit aus der Konfiguration
 *  - Zufällige Dateinamen
 *  - Neukodierung über GD: entfernt eingebettete Payloads und Metadaten
 *  - Drei Grössen: full (max. 1920), card (800), thumb (320)
 */
final class ImageService
{
    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_DIMENSION_FULL = 1920;

    /** Rückfallwert, wenn die Konfiguration nichts vorgibt. */
    public const DEFAULT_MAX_IMAGES = 20;

    /** Absoluter Pfad zu einer Datei unterhalb von /uploads. */
    public static function uploadPath(string $relativePath): string
    {
        return BASE_PATH . '/uploads/' . ltrim(str_replace('..', '', $relativePath), '/');
    }

    /**
     * Wie viele Bilder ein Fahrzeug haben darf. Ein Bild davon ist das
     * Hauptbild, alle weiteren sind Nebenbilder.
     */
    public static function maxImagesPerVehicle(): int
    {
        $configured = (int) Config::get('uploads.max_images_per_vehicle', self::DEFAULT_MAX_IMAGES);
        return $configured > 0 ? $configured : self::DEFAULT_MAX_IMAGES;
    }

    /**
     * Validiert und speichert ein hochgeladenes Bild.
     *
     * @param array{tmp_name: string, name: string, size: int, error: int} $file $_FILES-Eintrag
     * @param string $subDir Unterordner in /uploads, z.B. "vehicles/12"
     * @return array{full: string, card: string, thumb: string, width: int, height: int, size: int, original_name: string}
     * @throws RuntimeException bei ungültigen Dateien
     */
    public static function processUpload(array $file, string $subDir): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $maxBytes = ((int) Config::get('uploads.max_file_size_mb', 12)) * 1024 * 1024;
        if ((int) $file['size'] > $maxBytes) {
            throw new RuntimeException('Die Datei ist zu gross (max. ' . Config::get('uploads.max_file_size_mb', 12) . ' MB).');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Ungültiger Upload.');
        }

        // Echten MIME-Typ prüfen (nicht die Dateiendung)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('Nur JPG-, PNG- und WebP-Bilder sind erlaubt.');
        }

        // Bildvalidierung
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || $info[0] < 200 || $info[1] < 150) {
            throw new RuntimeException('Die Datei ist kein gültiges Bild oder zu klein (min. 200×150 Pixel).');
        }

        $source = self::openImage($file['tmp_name'], $mime);

        // Zielverzeichnis
        $subDir = trim(str_replace(['..', '\\'], ['', '/'], $subDir), '/');
        $dir = BASE_PATH . '/uploads/' . $subDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
        }

        $baseName = bin2hex(random_bytes(16));
        $srcW = imagesx($source);
        $srcH = imagesy($source);

        // Neukodierung in drei Grössen (immer JPEG → einheitlich, metadatenfrei)
        $paths = [];
        foreach ([
            'full'  => self::MAX_DIMENSION_FULL,
            'card'  => 800,
            'thumb' => 320,
        ] as $variant => $maxW) {
            $scale = min(1.0, $maxW / max(1, $srcW));
            $w = max(1, (int) round($srcW * $scale));
            $h = max(1, (int) round($srcH * $scale));

            $canvas = imagecreatetruecolor($w, $h);
            // Weisser Hintergrund für PNG/WebP mit Transparenz
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

            $relPath = $subDir . '/' . $baseName . ($variant === 'full' ? '' : '-' . $variant) . '.jpg';
            if (!imagejpeg($canvas, BASE_PATH . '/uploads/' . $relPath, 84)) {
                imagedestroy($canvas);
                imagedestroy($source);
                throw new RuntimeException('Bild konnte nicht gespeichert werden.');
            }
            imagedestroy($canvas);
            $paths[$variant] = $relPath;
        }
        imagedestroy($source);

        $fullPath = BASE_PATH . '/uploads/' . $paths['full'];
        $finalSize = (int) @filesize($fullPath);
        $finalInfo = @getimagesize($fullPath);

        return [
            'full'          => $paths['full'],
            'card'          => $paths['card'],
            'thumb'         => $paths['thumb'],
            'width'         => $finalInfo !== false ? (int) $finalInfo[0] : 0,
            'height'        => $finalInfo !== false ? (int) $finalInfo[1] : 0,
            'size'          => $finalSize,
            'original_name' => self::sanitizeName((string) $file['name']),
        ];
    }

    /**
     * Erzeugt Karten- und Vorschaugrösse zu einem bereits gespeicherten Bild neu.
     * Wird nach dem Hintergrundwechsel gebraucht, damit die Ansicht das neue
     * Bild zeigt.
     *
     * @return array{card: string, thumb: string}
     */
    public static function rebuildVariants(string $relativeFullPath): array
    {
        $absolute = self::uploadPath($relativeFullPath);
        $info = @getimagesize($absolute);
        if ($info === false) {
            throw new RuntimeException('Das Bild konnte nicht gelesen werden.');
        }

        $source = self::openImage($absolute, (string) $info['mime']);
        $srcW = imagesx($source);
        $srcH = imagesy($source);

        $baseName = preg_replace('/\.[a-z0-9]+$/i', '', $relativeFullPath) ?? $relativeFullPath;
        $paths = [];
        foreach (['card' => 800, 'thumb' => 320] as $variant => $maxW) {
            $scale = min(1.0, $maxW / max(1, $srcW));
            $w = max(1, (int) round($srcW * $scale));
            $h = max(1, (int) round($srcH * $scale));

            $canvas = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

            $relPath = $baseName . '-' . $variant . '.jpg';
            if (!imagejpeg($canvas, self::uploadPath($relPath), 84)) {
                imagedestroy($canvas);
                imagedestroy($source);
                throw new RuntimeException('Bild konnte nicht gespeichert werden.');
            }
            imagedestroy($canvas);
            $paths[$variant] = $relPath;
        }
        imagedestroy($source);

        return $paths;
    }

    /** Löscht alle Varianten eines Bildes. */
    public static function deleteVariants(?string ...$relPaths): void
    {
        foreach ($relPaths as $relPath) {
            if ($relPath === null || $relPath === '') {
                continue;
            }
            $full = BASE_PATH . '/uploads/' . str_replace('..', '', $relPath);
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }

    private static function openImage(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
        if ($image === false) {
            throw new RuntimeException('Das Bild konnte nicht verarbeitet werden.');
        }
        return $image;
    }

    private static function sanitizeName(string $name): string
    {
        $name = preg_replace('/[^\w.\- ]/u', '', $name) ?? '';
        return mb_substr($name, 0, 255);
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei überschreitet die maximale Grösse.',
            UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei hochgeladen.',
            default => 'Upload fehlgeschlagen (Code ' . $code . ').',
        };
    }
}
