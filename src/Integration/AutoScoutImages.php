<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;

/**
 * Bild-Upload zur AutoScout24-API.
 *
 * Bilder werden vorab hochgeladen (POST /customers/{id}/images) und liefern
 * eine Bild-ID zurück, die anschliessend im Inserat referenziert wird.
 */
final class AutoScoutImages
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Lädt eine Bilddatei hoch und gibt die AutoScout24-Bild-ID zurück.
     */
    public static function upload(int $dealershipId, string $absolutePath): string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Bilddatei nicht gefunden: ' . basename($absolutePath));
        }
        $size = (int) filesize($absolutePath);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Bilddatei ist leer oder zu gross: ' . basename($absolutePath));
        }

        $info = @getimagesize($absolutePath);
        if ($info === false) {
            throw new RuntimeException('Datei ist kein gültiges Bild: ' . basename($absolutePath));
        }
        $mime = (string) ($info['mime'] ?? 'image/jpeg');
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('Nur JPG und PNG können übertragen werden.');
        }

        $binary = file_get_contents($absolutePath);
        if ($binary === false) {
            throw new RuntimeException('Bilddatei konnte nicht gelesen werden.');
        }

        $customerId = AutoScoutService::customerId($dealershipId);
        if ($customerId === null) {
            throw new RuntimeException('Es ist keine AutoScout24-Verbindung hinterlegt.');
        }

        $response = AutoScoutClient::request(
            $dealershipId,
            'POST',
            '/customers/' . rawurlencode($customerId) . '/images',
            null,
            $binary,
            $mime
        );

        $data = $response['data'];
        $imageId = null;
        if (is_array($data)) {
            $imageId = $data['id'] ?? $data['imageId'] ?? null;
        }
        if (!is_string($imageId) || $imageId === '') {
            throw new RuntimeException('AutoScout24 hat keine Bild-ID zurückgegeben.');
        }

        return $imageId;
    }

    /**
     * Lädt mehrere Bilder hoch und liefert die IDs in der Reihenfolge der Dateien.
     *
     * @param array<int, string> $absolutePaths
     * @return array{ids: array<int, string>, errors: array<int, string>}
     */
    public static function uploadMany(int $dealershipId, array $absolutePaths): array
    {
        $ids = [];
        $errors = [];
        foreach ($absolutePaths as $path) {
            try {
                $ids[] = self::upload($dealershipId, $path);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return ['ids' => $ids, 'errors' => $errors];
    }
}
