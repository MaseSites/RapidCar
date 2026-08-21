<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;

/**
 * Inserate über die AutoScout24 Listing-Creation-API verwalten.
 * Endpunkte gemäss offizieller Dokumentation.
 */
final class AutoScoutListings
{
    /** @return array<string, mixed> */
    public static function all(int $dealershipId): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request($dealershipId, 'GET', "/customers/{$customerId}/listings");
        return is_array($response['data']) ? $response['data'] : [];
    }

    /** @return array<string, mixed> */
    public static function get(int $dealershipId, string $listingId): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request($dealershipId, 'GET', "/customers/{$customerId}/listings/" . rawurlencode($listingId));
        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Erstellt ein Inserat.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> Antwort inklusive der neuen Inserats-ID
     */
    public static function create(int $dealershipId, array $payload): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request(
            $dealershipId,
            'POST',
            "/customers/{$customerId}/listings",
            $payload,
            null,
            null,
            self::testHeaders()
        );
        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Im Probebetrieb wird das Inserat bei AutoScout24 zwar gespeichert, aber
     * nicht veroeffentlicht. So laesst sich die Anbindung pruefen, ohne dass
     * echte Inserate auf der Plattform erscheinen.
     *
     * Eingeschaltet mit autoscout.test_mode in der Konfiguration.
     *
     * @return array<int, string>
     */
    public static function testHeaders(): array
    {
        return self::isTestMode() ? ['X-Testmode: true'] : [];
    }

    public static function isTestMode(): bool
    {
        return filter_var(\App\Core\Config::get('autoscout.test_mode', false), FILTER_VALIDATE_BOOL);
    }

    /** @param array<string, mixed> $payload */
    public static function update(int $dealershipId, string $listingId, array $payload): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request(
            $dealershipId,
            'PUT',
            "/customers/{$customerId}/listings/" . rawurlencode($listingId),
            $payload,
            null,
            null,
            self::testHeaders()
        );
        return is_array($response['data']) ? $response['data'] : [];
    }

    /** Teilaktualisierung, z.B. nur Preis oder Veröffentlichungsstatus. */
    public static function patch(int $dealershipId, string $listingId, array $payload): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request(
            $dealershipId,
            'PATCH',
            "/customers/{$customerId}/listings/" . rawurlencode($listingId),
            $payload,
            null,
            null,
            self::testHeaders()
        );
        return is_array($response['data']) ? $response['data'] : [];
    }

    public static function delete(int $dealershipId, string $listingId): void
    {
        $customerId = self::customerId($dealershipId);
        // Ein Probeinserat ist nur mit derselben Kopfzeile erreichbar,
        // sonst laesst es sich nicht wieder entfernen.
        AutoScoutClient::request(
            $dealershipId,
            'DELETE',
            "/customers/{$customerId}/listings/" . rawurlencode($listingId),
            null,
            null,
            null,
            self::testHeaders()
        );
    }

    /** Veröffentlichungsstatus setzen: Active oder Inactive. */
    public static function setPublication(int $dealershipId, string $listingId, bool $active): array
    {
        return self::patch($dealershipId, $listingId, [
            'publication' => [
                'status'   => $active ? 'Active' : 'Inactive',
                'channels' => [['id' => 'AS24']],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function exportStatus(int $dealershipId): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request($dealershipId, 'GET', "/customers/{$customerId}/listings/export-status");
        return is_array($response['data']) ? $response['data'] : [];
    }

    private static function customerId(int $dealershipId): string
    {
        $customerId = AutoScoutService::customerId($dealershipId);
        if ($customerId === null) {
            throw new RuntimeException('Es ist keine AutoScout24-Verbindung hinterlegt.');
        }
        return rawurlencode($customerId);
    }
}
