<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use RuntimeException;

/**
 * Testkanal: nimmt Inserate entgegen, damit sich das Veröffentlichen und
 * Aktualisieren vollständig durchspielen lässt.
 *
 * Es wird nichts an eine echte Plattform gesendet. Der Eintrag entsteht nur
 * in der eigenen Datenbank und ist überall als Testkanal gekennzeichnet.
 */
final class TestChannelPublisher
{
    /**
     * Legt den Kanaleintrag an oder frischt ihn auf.
     *
     * @param string|null $provider Kanal, unter dem der Testeintrag steht.
     *                              Ohne Angabe der Testkanal selbst.
     */
    public static function push(int $dealershipId, int $vehicleId, ?string $provider = null): string
    {
        $provider ??= ChannelRegistry::TEST_PROVIDER;
        $listing = Database::fetch(
            'SELECT id FROM listings WHERE vehicle_id = :vid AND dealership_id = :did',
            ['vid' => $vehicleId, 'did' => $dealershipId]
        );
        if ($listing === null) {
            throw new RuntimeException('Für dieses Fahrzeug gibt es noch kein Inserat.');
        }

        $now = Database::now();
        $existing = Database::fetch(
            'SELECT * FROM channel_listings WHERE listing_id = :lid AND provider = :p',
            ['lid' => (int) $listing['id'], 'p' => $provider]
        );

        $externalId = $existing !== null
            ? (string) $existing['external_id']
            : 'TEST-' . str_pad((string) $vehicleId, 5, '0', STR_PAD_LEFT);

        $data = [
            'external_id' => $externalId,
            'status'      => 'active',
            'synced_at'   => $now,
            'updated_at'  => $now,
        ];

        if ($existing === null) {
            Database::insert('channel_listings', $data + [
                'dealership_id' => $dealershipId,
                'listing_id'    => (int) $listing['id'],
                'provider'      => $provider,
                'created_at'    => $now,
            ]);
        } else {
            Database::update('channel_listings', (int) $existing['id'], $data);
        }

        return $externalId;
    }
}
