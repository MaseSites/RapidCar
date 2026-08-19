<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Integration\AutoScoutListings;
use App\Integration\AutoScoutService;
use App\Integration\ChannelRegistry;

/**
 * Gleicht den lokalen Fahrzeugbestand mit den verbundenen Kanälen ab.
 *
 * Es werden ausschliesslich echte API-Antworten verarbeitet. Kanäle ohne
 * Verbindung werden übersprungen und im Bericht ehrlich als solche gemeldet;
 * es wird kein Zustand erfunden.
 */
final class ChannelSyncService
{
    /** Nach dieser Zeit gilt der Bestand als veraltet (Sekunden). */
    public const STALE_AFTER = 900;

    /**
     * Synchronisiert alle verbundenen Kanäle eines Autohauses.
     *
     * @return array{
     *   synced: array<int, string>,
     *   skipped: array<int, string>,
     *   errors: array<int, string>,
     *   matched: int,
     *   remote_only: int,
     *   changed: bool
     * }
     */
    public static function syncAll(int $dealershipId, ?int $userId = null): array
    {
        $report = [
            'synced'      => [],
            'skipped'     => [],
            'errors'      => [],
            'matched'     => 0,
            'remote_only' => 0,
            'changed'     => false,
        ];

        foreach (ChannelRegistry::all() as $key => $channel) {
            $status = ChannelRegistry::status($dealershipId, $key);

            if ($status !== 'connected') {
                $report['skipped'][] = $channel['name'];
                continue;
            }

            try {
                $result = match ($key) {
                    AutoScoutService::PROVIDER => self::syncAutoScout($dealershipId),
                    // Weitere Kanäle folgen, sobald deren APIs angebunden sind.
                    default => null,
                };

                if ($result === null) {
                    $report['skipped'][] = $channel['name'];
                    continue;
                }

                $report['synced'][] = $channel['name'];
                $report['matched'] += $result['matched'];
                $report['remote_only'] += $result['remote_only'];
                $report['changed'] = $report['changed'] || $result['changed'];
            } catch (\Throwable $e) {
                Logger::error('Kanal-Sync fehlgeschlagen (' . $key . '): ' . $e->getMessage());
                $report['errors'][] = $channel['name'] . ': ' . $e->getMessage();
            }
        }

        Database::run(
            'UPDATE dealerships SET channels_synced_at = :t WHERE id = :id',
            ['t' => Database::now(), 'id' => $dealershipId]
        );

        if ($report['synced'] !== []) {
            ActivityLogger::log(
                $userId,
                'channels.synced',
                'Kanäle abgeglichen: ' . implode(', ', $report['synced'])
                . ' (' . $report['matched'] . ' zugeordnet, ' . $report['remote_only'] . ' nur extern)',
                'integration',
                null,
                $dealershipId
            );
        }

        return $report;
    }

    /**
     * Holt den Inseratsbestand von AutoScout24 und ordnet ihn lokalen
     * Fahrzeugen zu (über die beim Übertragen gesetzte Referenz VAI-<id>).
     *
     * @return array{matched: int, remote_only: int, changed: bool}
     */
    private static function syncAutoScout(int $dealershipId): array
    {
        $response = AutoScoutListings::all($dealershipId);
        $listings = self::extractList($response);

        $now = Database::now();
        $seen = [];
        $matched = 0;
        $remoteOnly = 0;
        $changed = false;

        foreach ($listings as $remote) {
            if (!is_array($remote)) {
                continue;
            }
            $externalId = self::firstString($remote, ['id', 'listingId']);
            if ($externalId === null) {
                continue;
            }
            $seen[] = $externalId;

            $referenceId = self::firstString($remote, ['offerReferenceId', 'crossReferenceId']);
            $vehicleId = self::resolveVehicleId($dealershipId, $referenceId);

            $snapshot = [
                'reference_id' => $referenceId,
                'title'        => self::buildTitle($remote),
                'price'        => self::extractPrice($remote),
                'currency'     => self::extractCurrency($remote),
                'status'       => self::extractStatus($remote),
                'url'          => self::extractUrl($remote),
                'vehicle_id'   => $vehicleId,
                'fetched_at'   => $now,
            ];

            if (self::storeSnapshot($dealershipId, AutoScoutService::PROVIDER, $externalId, $snapshot)) {
                $changed = true;
            }

            if ($vehicleId !== null) {
                $matched++;
                if (self::linkLocalListing($dealershipId, $vehicleId, $externalId, $snapshot['status'])) {
                    $changed = true;
                }
            } else {
                $remoteOnly++;
            }
        }

        // Verschwundene Inserate entfernen
        $removed = self::removeMissing($dealershipId, AutoScoutService::PROVIDER, $seen);
        if ($removed > 0) {
            $changed = true;
        }

        AutoScoutService::touchSync($dealershipId);

        return ['matched' => $matched, 'remote_only' => $remoteOnly, 'changed' => $changed];
    }

    // -----------------------------------------------------------------------
    // Ablage
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $snapshot @return bool true, wenn sich etwas geändert hat */
    private static function storeSnapshot(int $dealershipId, string $provider, string $externalId, array $snapshot): bool
    {
        $existing = Database::fetch(
            'SELECT * FROM channel_remote_listings
             WHERE dealership_id = :did AND provider = :p AND external_id = :eid',
            ['did' => $dealershipId, 'p' => $provider, 'eid' => $externalId]
        );

        if ($existing === null) {
            Database::insert('channel_remote_listings', $snapshot + [
                'dealership_id' => $dealershipId,
                'provider'      => $provider,
                'external_id'   => $externalId,
            ]);
            return true;
        }

        $changed = false;
        foreach (['title', 'price', 'status', 'vehicle_id'] as $field) {
            if ((string) ($existing[$field] ?? '') !== (string) ($snapshot[$field] ?? '')) {
                $changed = true;
                break;
            }
        }
        Database::update('channel_remote_listings', (int) $existing['id'], $snapshot);
        return $changed;
    }

    /** Verknüpft ein lokales Inserat mit der externen Inserats-ID. */
    private static function linkLocalListing(int $dealershipId, int $vehicleId, string $externalId, ?string $status): bool
    {
        $listing = Database::fetch(
            'SELECT id FROM listings WHERE vehicle_id = :vid AND dealership_id = :did',
            ['vid' => $vehicleId, 'did' => $dealershipId]
        );
        if ($listing === null) {
            return false;
        }

        $now = Database::now();
        $existing = Database::fetch(
            'SELECT * FROM channel_listings WHERE listing_id = :lid AND provider = :p',
            ['lid' => (int) $listing['id'], 'p' => AutoScoutService::PROVIDER]
        );

        $data = [
            'external_id' => $externalId,
            'status'      => $status !== null ? mb_strtolower($status) : 'inactive',
            'synced_at'   => $now,
            'updated_at'  => $now,
        ];

        if ($existing === null) {
            Database::insert('channel_listings', $data + [
                'dealership_id' => $dealershipId,
                'listing_id'    => (int) $listing['id'],
                'provider'      => AutoScoutService::PROVIDER,
                'created_at'    => $now,
            ]);
            return true;
        }

        $changed = (string) $existing['external_id'] !== $externalId
            || (string) $existing['status'] !== (string) $data['status'];
        Database::update('channel_listings', (int) $existing['id'], $data);
        return $changed;
    }

    /** @param array<int, string> $seenExternalIds */
    private static function removeMissing(int $dealershipId, string $provider, array $seenExternalIds): int
    {
        $rows = Database::fetchAll(
            'SELECT id, external_id FROM channel_remote_listings WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => $provider]
        );
        $removed = 0;
        foreach ($rows as $row) {
            if (!in_array((string) $row['external_id'], $seenExternalIds, true)) {
                Database::run('DELETE FROM channel_remote_listings WHERE id = :id', ['id' => (int) $row['id']]);
                $removed++;
            }
        }
        return $removed;
    }

    // -----------------------------------------------------------------------
    // Auswertung der API-Antwort (defensiv, da Feldnamen variieren können)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    private static function extractList(array $response): array
    {
        foreach (['listings', 'items', 'data', 'results'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }
        // Bereits eine Liste?
        if ($response !== [] && array_is_list($response)) {
            return $response;
        }
        return [];
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private static function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key]))) {
                $value = trim((string) $data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $remote */
    private static function buildTitle(array $remote): ?string
    {
        // Die API liefert Marke und Modell als numerische Referenzen. Ein
        // sprechender Titel wird nur übernommen, wenn er wirklich vorhanden ist.
        $title = self::firstString($remote, ['title', 'modelVersion']);
        if ($title !== null) {
            return mb_substr($title, 0, 180);
        }
        return null;
    }

    /** @param array<string, mixed> $remote */
    private static function extractPrice(array $remote): ?float
    {
        $price = $remote['prices']['public']['price'] ?? $remote['price'] ?? null;
        return is_numeric($price) ? (float) $price : null;
    }

    /** @param array<string, mixed> $remote */
    private static function extractCurrency(array $remote): ?string
    {
        $currency = $remote['prices']['public']['currency'] ?? $remote['currency'] ?? null;
        return is_string($currency) && $currency !== '' ? mb_substr($currency, 0, 3) : null;
    }

    /** @param array<string, mixed> $remote */
    private static function extractStatus(array $remote): ?string
    {
        $status = $remote['publication']['status'] ?? $remote['status'] ?? null;
        return is_string($status) && $status !== '' ? mb_substr($status, 0, 20) : null;
    }

    /** @param array<string, mixed> $remote */
    private static function extractUrl(array $remote): ?string
    {
        $channels = $remote['publication']['channels'] ?? null;
        if (is_array($channels)) {
            foreach ($channels as $channel) {
                if (is_array($channel) && isset($channel['url']) && is_string($channel['url'])) {
                    return mb_substr($channel['url'], 0, 500);
                }
            }
        }
        return null;
    }

    /** Löst die lokale Fahrzeug-ID aus der Referenz "VAI-<id>" auf. */
    private static function resolveVehicleId(int $dealershipId, ?string $referenceId): ?int
    {
        if ($referenceId === null || preg_match('/^VAI-(\d+)$/i', $referenceId, $m) !== 1) {
            return null;
        }
        $vehicleId = (int) $m[1];
        $exists = Database::scalar(
            'SELECT COUNT(*) FROM vehicles WHERE id = :id AND dealership_id = :did',
            ['id' => $vehicleId, 'did' => $dealershipId]
        );
        return (int) $exists > 0 ? $vehicleId : null;
    }

    // -----------------------------------------------------------------------
    // Abfragen für die Oberfläche
    // -----------------------------------------------------------------------

    /**
     * Kanal-Zuordnung je Fahrzeug: [vehicleId => [provider => status]]
     *
     * @return array<int, array<string, string>>
     */
    public static function channelsByVehicle(int $dealershipId): array
    {
        $rows = Database::fetchAll(
            'SELECT l.vehicle_id, cl.provider, cl.status
             FROM channel_listings cl
             INNER JOIN listings l ON l.id = cl.listing_id
             WHERE cl.dealership_id = :did AND cl.external_id IS NOT NULL',
            ['did' => $dealershipId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['vehicle_id']][(string) $row['provider']] = (string) ($row['status'] ?? 'inactive');
        }
        return $map;
    }

    /**
     * Inserate, die auf einem Kanal existieren, aber kein lokales Fahrzeug haben.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function remoteOnly(int $dealershipId): array
    {
        return Database::fetchAll(
            'SELECT * FROM channel_remote_listings
             WHERE dealership_id = :did AND vehicle_id IS NULL
             ORDER BY provider, external_id',
            ['did' => $dealershipId]
        );
    }

    public static function lastSyncedAt(int $dealershipId): ?string
    {
        $value = Database::scalar(
            'SELECT channels_synced_at FROM dealerships WHERE id = :id',
            ['id' => $dealershipId]
        );
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function isStale(int $dealershipId): bool
    {
        $last = self::lastSyncedAt($dealershipId);
        if ($last === null) {
            return true;
        }
        return (time() - (int) strtotime($last)) > self::STALE_AFTER;
    }

    /** Gibt es überhaupt einen verbundenen Kanal? */
    public static function hasConnectedChannel(int $dealershipId): bool
    {
        foreach (array_keys(ChannelRegistry::all()) as $key) {
            if (ChannelRegistry::status($dealershipId, $key) === 'connected') {
                return true;
            }
        }
        return false;
    }
}
