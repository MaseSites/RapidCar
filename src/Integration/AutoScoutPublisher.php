<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use App\Core\Logger;
use App\Repository\VehicleRepository;
use App\Service\ActivityLogger;
use App\Service\ListingService;
use RuntimeException;

/**
 * Überträgt ein Fahrzeug als Inserat zu AutoScout24.
 *
 * Ablauf gemäss API-Dokumentation:
 *   1. Bilder vorab hochladen (liefert Bild-IDs)
 *   2. Inserat anlegen (Status Inactive) oder bestehendes aktualisieren
 *   3. Auf ausdrücklichen Wunsch aktivieren (Status Active)
 *
 * Sicherheitsprinzip: Neue Inserate werden immer inaktiv angelegt. Erst ein
 * bewusster zweiter Schritt schaltet sie öffentlich.
 */
final class AutoScoutPublisher
{
    /**
     * Überträgt ein Fahrzeug.
     *
     * @return array{listing_id: string, created: bool, unresolved: array<int,string>, missing: array<int,string>, image_errors: array<int,string>, activated: bool}
     */
    public static function push(int $dealershipId, int $vehicleId, bool $activate = false, ?int $userId = null): array
    {
        if (!AutoScoutService::isConnected($dealershipId)) {
            throw new RuntimeException('Es ist keine aktive AutoScout24-Verbindung hinterlegt.');
        }

        $vehicle = VehicleRepository::find($vehicleId, $dealershipId);
        if ($vehicle === null) {
            throw new RuntimeException('Fahrzeug nicht gefunden.');
        }

        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);
        $features = VehicleRepository::features($vehicleId);
        $images = VehicleRepository::images($vehicleId);

        $dealership = Database::fetch('SELECT currency FROM dealerships WHERE id = :id', ['id' => $dealershipId]);
        $currency = (string) ($dealership['currency'] ?? 'CHF');

        // -------------------------------------------------------- 1. Bilder
        $imagePaths = [];
        foreach ($images as $image) {
            $absolute = BASE_PATH . '/uploads/' . $image['file_path'];
            if (is_file($absolute)) {
                $imagePaths[] = $absolute;
            }
        }
        $uploaded = AutoScoutImages::uploadMany($dealershipId, array_slice($imagePaths, 0, 30));

        // ------------------------------------------------------- 2. Payload
        $mapped = AutoScoutMapper::build(
            $dealershipId,
            $vehicle,
            $listing,
            $features,
            $uploaded['ids'],
            $activate,
            $currency
        );

        if ($mapped['missing'] !== []) {
            throw new RuntimeException(
                'Für die Übertragung fehlen Pflichtangaben: ' . implode(', ', $mapped['missing']) . '.'
            );
        }

        // -------------------------------------- 3. Anlegen oder aktualisieren
        $externalId = self::externalListingId($dealershipId, (int) $listing['id']);
        $created = false;

        if ($externalId !== null) {
            try {
                AutoScoutListings::update($dealershipId, $externalId, $mapped['payload']);
            } catch (RuntimeException $e) {
                // Inserat existiert extern nicht mehr: neu anlegen
                Logger::warning('AutoScout24-Aktualisierung fehlgeschlagen, lege neu an: ' . $e->getMessage());
                $externalId = null;
            }
        }

        if ($externalId === null) {
            $response = AutoScoutListings::create($dealershipId, $mapped['payload']);
            $externalId = self::extractListingId($response);
            if ($externalId === null) {
                throw new RuntimeException('AutoScout24 hat keine Inserats-ID zurückgegeben.');
            }
            $created = true;
            self::storeExternalListingId($dealershipId, (int) $listing['id'], $externalId);
        }

        AutoScoutService::touchSync($dealershipId);
        ActivityLogger::log(
            $userId,
            $created ? 'autoscout.listing_created' : 'autoscout.listing_updated',
            'Fahrzeug #' . $vehicleId . ' zu AutoScout24 übertragen (Inserat ' . $externalId . ')',
            'vehicle',
            $vehicleId,
            $dealershipId
        );

        return [
            'listing_id'   => $externalId,
            'created'      => $created,
            'unresolved'   => $mapped['unresolved'],
            'missing'      => $mapped['missing'],
            'image_errors' => $uploaded['errors'],
            'activated'    => $activate,
        ];
    }

    /** Schaltet ein bereits übertragenes Inserat aktiv oder inaktiv. */
    public static function setActive(int $dealershipId, int $vehicleId, bool $active, ?int $userId = null): string
    {
        $listing = Database::fetch(
            'SELECT id FROM listings WHERE vehicle_id = :vid AND dealership_id = :did',
            ['vid' => $vehicleId, 'did' => $dealershipId]
        );
        if ($listing === null) {
            throw new RuntimeException('Inserat nicht gefunden.');
        }

        $externalId = self::externalListingId($dealershipId, (int) $listing['id']);
        if ($externalId === null) {
            throw new RuntimeException('Dieses Fahrzeug wurde noch nicht zu AutoScout24 übertragen.');
        }

        AutoScoutListings::setPublication($dealershipId, $externalId, $active);
        ActivityLogger::log(
            $userId,
            $active ? 'autoscout.listing_activated' : 'autoscout.listing_deactivated',
            'AutoScout24-Inserat ' . $externalId . ($active ? ' aktiviert' : ' deaktiviert'),
            'vehicle',
            $vehicleId,
            $dealershipId
        );

        return $externalId;
    }

    /** Entfernt das Inserat bei AutoScout24. */
    public static function remove(int $dealershipId, int $vehicleId, ?int $userId = null): void
    {
        $listing = Database::fetch(
            'SELECT id FROM listings WHERE vehicle_id = :vid AND dealership_id = :did',
            ['vid' => $vehicleId, 'did' => $dealershipId]
        );
        if ($listing === null) {
            return;
        }
        $externalId = self::externalListingId($dealershipId, (int) $listing['id']);
        if ($externalId === null) {
            return;
        }

        AutoScoutListings::delete($dealershipId, $externalId);
        Database::run(
            'DELETE FROM channel_listings WHERE listing_id = :lid AND provider = :p',
            ['lid' => (int) $listing['id'], 'p' => AutoScoutService::PROVIDER]
        );
        ActivityLogger::log(
            $userId,
            'autoscout.listing_deleted',
            'AutoScout24-Inserat ' . $externalId . ' gelöscht',
            'vehicle',
            $vehicleId,
            $dealershipId
        );
    }

    /** Externe Inserats-ID eines lokalen Inserats. */
    public static function externalListingId(int $dealershipId, int $listingId): ?string
    {
        $row = Database::fetch(
            'SELECT external_id FROM channel_listings
             WHERE listing_id = :lid AND provider = :p AND dealership_id = :did',
            ['lid' => $listingId, 'p' => AutoScoutService::PROVIDER, 'did' => $dealershipId]
        );
        return $row !== null && $row['external_id'] !== null ? (string) $row['external_id'] : null;
    }

    /** Externe Inserats-ID zu einem Fahrzeug (für die Oberfläche). */
    public static function externalIdForVehicle(int $dealershipId, int $vehicleId): ?string
    {
        $row = Database::fetch(
            'SELECT cl.external_id FROM channel_listings cl
             INNER JOIN listings l ON l.id = cl.listing_id
             WHERE l.vehicle_id = :vid AND cl.provider = :p AND cl.dealership_id = :did',
            ['vid' => $vehicleId, 'p' => AutoScoutService::PROVIDER, 'did' => $dealershipId]
        );
        return $row !== null && $row['external_id'] !== null ? (string) $row['external_id'] : null;
    }

    private static function storeExternalListingId(int $dealershipId, int $listingId, string $externalId): void
    {
        $now = Database::now();
        $existing = Database::fetch(
            'SELECT id FROM channel_listings WHERE listing_id = :lid AND provider = :p',
            ['lid' => $listingId, 'p' => AutoScoutService::PROVIDER]
        );
        if ($existing !== null) {
            Database::update('channel_listings', (int) $existing['id'], [
                'external_id' => $externalId,
                'updated_at'  => $now,
            ]);
            return;
        }
        Database::insert('channel_listings', [
            'dealership_id' => $dealershipId,
            'listing_id'    => $listingId,
            'provider'      => AutoScoutService::PROVIDER,
            'external_id'   => $externalId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /** @param array<string, mixed> $response */
    private static function extractListingId(array $response): ?string
    {
        foreach (['id', 'listingId'] as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && $response[$key] !== '') {
                return $response[$key];
            }
        }
        return null;
    }
}
