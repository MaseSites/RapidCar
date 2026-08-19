<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;

/**
 * Statistiken der AutoScout24-API (Aufrufe, Kontakte je Inserat).
 */
final class AutoScoutStatistics
{
    /** @return array<string, mixed> */
    public static function forCustomer(int $dealershipId): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request($dealershipId, 'GET', "/statistics/{$customerId}/listings");
        return is_array($response['data']) ? $response['data'] : [];
    }

    /** @return array<string, mixed> */
    public static function forListing(int $dealershipId, string $listingId): array
    {
        $customerId = self::customerId($dealershipId);
        $response = AutoScoutClient::request(
            $dealershipId,
            'GET',
            "/statistics/{$customerId}/listings/" . rawurlencode($listingId)
        );
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
