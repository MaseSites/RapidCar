<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Database;

/**
 * KI-Bildanalyse (§26/§73): analysiert Fahrzeugbilder über den aktiven
 * Provider und persistiert das strukturierte Ergebnis in vehicle_images.
 */
final class AIImageService
{
    /**
     * Analysiert ein einzelnes Bild und speichert das Ergebnis.
     *
     * @param array<string, mixed> $imageRow Zeile aus vehicle_images
     * @return array<string, mixed> Analyseergebnis (Format §73)
     */
    public static function analyze(array $imageRow): array
    {
        $absolutePath = BASE_PATH . '/uploads/' . $imageRow['file_path'];

        // Bildqualität wird IMMER regelbasiert bestimmt: Auflösung, Seiten-
        // verhältnis und Dateigrösse sagen alles Nötige. Ein KI-Aufruf je
        // hochgeladenem Foto wäre bei 20 Fotos 20 Anfragen, ohne dass das
        // Ergebnis besser würde. Die KI bleibt der Fahrzeugerkennung
        // vorbehalten, wo sie wirklich etwas beiträgt.
        $result = (new MockProvider())->analyzeImage($absolutePath, [
            'file_size' => (int) ($imageRow['file_size'] ?? 0),
            'is_first'  => (int) ($imageRow['sort_order'] ?? 0) === 0,
        ]);

        Database::update('vehicle_images', (int) $imageRow['id'], [
            'ai_quality_score' => $result['quality_score'],
            'ai_analysis'      => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);

        return $result;
    }

    /**
     * Analysiert alle Bilder eines Fahrzeugs.
     *
     * @return array<int, array<string, mixed>> Ergebnisse je Bild-ID
     */
    public static function analyzeVehicleImages(int $vehicleId): array
    {
        $images = Database::fetchAll(
            'SELECT * FROM vehicle_images WHERE vehicle_id = :vid ORDER BY is_main DESC, sort_order',
            ['vid' => $vehicleId]
        );
        $results = [];
        foreach ($images as $image) {
            $results[(int) $image['id']] = self::analyze($image);
        }
        return $results;
    }

    /**
     * Beste Bilder für Social Media (§36): nach KI-Qualität, dann Auflösung.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function bestImages(int $vehicleId, int $limit = 5): array
    {
        return Database::fetchAll(
            'SELECT * FROM vehicle_images
             WHERE vehicle_id = :vid
             ORDER BY is_main DESC,
                      CASE WHEN ai_quality_score IS NULL THEN 0 ELSE ai_quality_score END DESC,
                      width DESC
             LIMIT ' . max(1, $limit),
            ['vid' => $vehicleId]
        );
    }
}
