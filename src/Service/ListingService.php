<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

/**
 * Inserat-Verwaltung: Erstellen, Aktualisieren, Score-Berechnung und
 * Persistierung der Empfehlungen (§31–§35).
 */
final class ListingService
{
    /** Holt das Inserat eines Fahrzeugs oder legt einen Entwurf an. */
    public static function ensureForVehicle(int $vehicleId, int $dealershipId): array
    {
        $listing = Database::fetch(
            'SELECT * FROM listings WHERE vehicle_id = :vid AND dealership_id = :did',
            ['vid' => $vehicleId, 'did' => $dealershipId]
        );
        if ($listing !== null) {
            return $listing;
        }

        $now = Database::now();
        $id = Database::insert('listings', [
            'vehicle_id'    => $vehicleId,
            'dealership_id' => $dealershipId,
            'status'        => 'draft',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return Database::fetch('SELECT * FROM listings WHERE id = :id', ['id' => $id]) ?? [];
    }

    /**
     * Berechnet den Score neu und persistiert Score + Empfehlungen.
     * Gibt das Bewertungsergebnis der Engine zurück.
     */
    public static function recalculate(int $listingId): array
    {
        $listing = Database::fetch('SELECT * FROM listings WHERE id = :id', ['id' => $listingId]);
        if ($listing === null) {
            return ['total' => 0, 'scores' => [], 'details' => [], 'recommendations' => [], 'engine' => 'rules'];
        }

        $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id = :id', ['id' => (int) $listing['vehicle_id']]) ?? [];
        $images = Database::fetchAll(
            'SELECT * FROM vehicle_images WHERE vehicle_id = :vid ORDER BY is_main DESC, sort_order',
            ['vid' => (int) $listing['vehicle_id']]
        );
        $features = array_map(
            static fn(array $row): string => (string) $row['feature'],
            Database::fetchAll(
                'SELECT feature FROM vehicle_features WHERE vehicle_id = :vid',
                ['vid' => (int) $listing['vehicle_id']]
            )
        );

        $result = ListingScoreEngine::evaluate($vehicle, $listing, $images, $features);

        // Score persistieren (immer neuester Eintrag zählt)
        Database::insert('listing_scores', [
            'listing_id'        => $listingId,
            'total_score'       => $result['total'],
            'photos_score'      => $result['scores']['photos'],
            'title_score'       => $result['scores']['title'],
            'description_score' => $result['scores']['description'],
            'price_score'       => $result['scores']['price'],
            'data_score'        => $result['scores']['data'],
            'engine'            => $result['engine'],
            'details'           => json_encode($result['details'], JSON_UNESCAPED_UNICODE),
            'created_at'        => Database::now(),
        ]);

        // Alte, ungelöste Empfehlungen ersetzen
        Database::run('DELETE FROM listing_recommendations WHERE listing_id = :id AND is_resolved = 0', ['id' => $listingId]);
        foreach ($result['recommendations'] as $rec) {
            Database::insert('listing_recommendations', [
                'listing_id'   => $listingId,
                'category'     => $rec['category'],
                'severity'     => $rec['severity'],
                'message'      => $rec['message'],
                'action_label' => $rec['action_label'] ?? null,
                'created_at'   => Database::now(),
            ]);
        }

        return $result;
    }

    /** Neuester Score eines Inserats oder null. */
    public static function latestScore(int $listingId): ?array
    {
        return Database::fetch(
            'SELECT * FROM listing_scores WHERE listing_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $listingId]
        );
    }

    /** Offene Empfehlungen eines Inserats. */
    public static function openRecommendations(int $listingId): array
    {
        return Database::fetchAll(
            "SELECT * FROM listing_recommendations
             WHERE listing_id = :id AND is_resolved = 0
             ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END",
            ['id' => $listingId]
        );
    }
}
