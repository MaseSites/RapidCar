<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Database;

/**
 * Social-Media-Dienst (§36–§37): Caption-Vorschlag und Bildauswahl.
 * Mock-Modus: regelbasierte Caption aus echten Fahrzeugdaten (Demo-gekennzeichnet).
 */
final class AISocialService
{
    /**
     * @return array{caption: string, mode: string}
     */
    public static function generateCaption(int $vehicleId): array
    {
        $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id = :id', ['id' => $vehicleId]);
        if ($vehicle === null) {
            throw new AIException('Fahrzeug nicht gefunden.');
        }

        if (!AIService::isMock()) {
            $result = AIService::provider()->complete('social_caption', ['vehicle' => $vehicle]);
            return ['caption' => $result['text'], 'mode' => 'live'];
        }

        $name = strtoupper(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? '')));
        $lines = ['NEW IN', $name !== '' ? $name : 'NEUUES FAHRZEUG'];
        if (!empty($vehicle['power_hp'])) {
            $lines[] = (int) $vehicle['power_hp'] . ' PS';
        }
        if (!empty($vehicle['mileage'])) {
            $lines[] = number_format((float) $vehicle['mileage'], 0, '.', "'") . ' KM';
        }
        if (!empty($vehicle['price'])) {
            $lines[] = 'CHF ' . number_format((float) $vehicle['price'], 0, '.', "'");
        }
        $hashtags = array_filter([
            !empty($vehicle['make']) ? '#' . strtolower(str_replace(['-', ' '], '', (string) $vehicle['make'])) : null,
            !empty($vehicle['model']) ? '#' . strtolower(str_replace(['-', ' '], '', (string) $vehicle['model'])) : null,
            '#autohaus', '#carsofinstagram',
        ]);

        return [
            'caption' => implode("\n", $lines) . "\n\n" . implode(' ', $hashtags),
            'mode'    => 'mock',
        ];
    }
}
