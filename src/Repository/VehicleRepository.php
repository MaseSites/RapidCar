<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;

/**
 * Fahrzeug-Datenzugriff mit erzwungener Mandantentrennung (§57):
 * Jede Methode verlangt die dealership_id — kein Zugriff auf fremde Fahrzeuge.
 */
final class VehicleRepository
{
    public const STATUSES = ['draft', 'ready', 'published', 'paused', 'reserved', 'sold', 'archived'];

    /** @return array<string, mixed>|null */
    public static function find(int $vehicleId, int $dealershipId): ?array
    {
        return Database::fetch(
            'SELECT * FROM vehicles WHERE id = :id AND dealership_id = :did',
            ['id' => $vehicleId, 'did' => $dealershipId]
        );
    }

    /**
     * Liste mit Thumbnail und aktuellem Score (§23).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listWithMeta(int $dealershipId, string $statusFilter = '', string $search = ''): array
    {
        $where = ['v.dealership_id = :did'];
        $params = ['did' => $dealershipId];

        if (in_array($statusFilter, self::STATUSES, true)) {
            $where[] = 'v.status = :status';
            $params['status'] = $statusFilter;
        }
        if ($search !== '') {
            $where[] = '(v.make LIKE :q1 OR v.model LIKE :q2 OR v.variant LIKE :q3)';
            $like = '%' . $search . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }

        return Database::fetchAll(
            'SELECT v.*,
                    (SELECT vi.thumb_path FROM vehicle_images vi
                     WHERE vi.vehicle_id = v.id ORDER BY vi.is_main DESC, vi.sort_order LIMIT 1) AS thumb,
                    (SELECT COUNT(*) FROM vehicle_images vi WHERE vi.vehicle_id = v.id) AS image_count,
                    (SELECT ls.total_score FROM listing_scores ls
                     INNER JOIN listings l ON l.id = ls.listing_id
                     WHERE l.vehicle_id = v.id ORDER BY ls.id DESC LIMIT 1) AS score
             FROM vehicles v
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY v.updated_at DESC',
            $params
        );
    }

    /** Legt einen leeren Entwurf an. */
    public static function createDraft(int $dealershipId, int $userId): int
    {
        $now = Database::now();
        return Database::insert('vehicles', [
            'dealership_id' => $dealershipId,
            'created_by'    => $userId,
            'status'        => 'draft',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function images(int $vehicleId): array
    {
        return Database::fetchAll(
            // Das Hauptbild steht immer vorn, danach die Nebenbilder in ihrer Reihenfolge.
            'SELECT * FROM vehicle_images WHERE vehicle_id = :vid ORDER BY is_main DESC, sort_order, id',
            ['vid' => $vehicleId]
        );
    }

    /** @return array<int, string> */
    public static function features(int $vehicleId): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['feature'],
            Database::fetchAll('SELECT feature FROM vehicle_features WHERE vehicle_id = :vid ORDER BY id', ['vid' => $vehicleId])
        );
    }

    /** Ersetzt die Ausstattungsliste. @param array<int, string> $features */
    public static function replaceFeatures(int $vehicleId, array $features, string $source = 'manual'): void
    {
        Database::run('DELETE FROM vehicle_features WHERE vehicle_id = :vid', ['vid' => $vehicleId]);
        $now = Database::now();
        foreach (array_slice($features, 0, 60) as $feature) {
            $feature = trim($feature);
            if ($feature === '') {
                continue;
            }
            Database::insert('vehicle_features', [
                'vehicle_id' => $vehicleId,
                'feature'    => mb_substr($feature, 0, 150),
                'source'     => $source,
                'created_at' => $now,
            ]);
        }
    }

    /** Löscht Fahrzeug inkl. Bilddateien (DB-Kaskade übernimmt Datensätze). */
    public static function delete(int $vehicleId, int $dealershipId): bool
    {
        $vehicle = self::find($vehicleId, $dealershipId);
        if ($vehicle === null) {
            return false;
        }
        foreach (self::images($vehicleId) as $image) {
            \App\Service\ImageService::deleteVariants(
                $image['file_path'] ?? null,
                $image['card_path'] ?? null,
                $image['thumb_path'] ?? null
            );
        }
        Database::run(
            'DELETE FROM vehicles WHERE id = :id AND dealership_id = :did',
            ['id' => $vehicleId, 'did' => $dealershipId]
        );
        return true;
    }
}
