<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Database;
use App\Core\Encryption;

/**
 * Verschlüsselte Token-Ablage (§58): AES-256-GCM, niemals Klartext,
 * niemals im Browser. Eine Zeile pro (Autohaus, Provider).
 */
final class TokenStore
{
    public static function save(
        int $dealershipId,
        string $provider,
        string $accessToken,
        ?string $refreshToken,
        ?int $expiresInSeconds
    ): void {
        $now = Database::now();
        $expiresAt = $expiresInSeconds !== null
            ? date('Y-m-d H:i:s', time() + $expiresInSeconds)
            : null;

        $data = [
            'access_token'  => Encryption::encrypt($accessToken),
            'refresh_token' => $refreshToken !== null ? Encryption::encrypt($refreshToken) : null,
            'expires_at'    => $expiresAt,
            'updated_at'    => $now,
        ];

        $existing = Database::fetch(
            'SELECT id FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => $provider]
        );
        if ($existing !== null) {
            Database::update('integration_tokens', (int) $existing['id'], $data);
        } else {
            Database::insert('integration_tokens', $data + [
                'dealership_id' => $dealershipId,
                'provider'      => $provider,
                'created_at'    => $now,
            ]);
        }
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?string}|null
     */
    public static function get(int $dealershipId, string $provider): ?array
    {
        $row = Database::fetch(
            'SELECT * FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => $provider]
        );
        if ($row === null || $row['access_token'] === null) {
            return null;
        }
        try {
            return [
                'access_token'  => Encryption::decrypt((string) $row['access_token']),
                'refresh_token' => $row['refresh_token'] !== null ? Encryption::decrypt((string) $row['refresh_token']) : null,
                'expires_at'    => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            ];
        } catch (\Throwable) {
            return null; // Schlüssel geändert → Token unbrauchbar
        }
    }

    public static function delete(int $dealershipId, string $provider): void
    {
        Database::run(
            'DELETE FROM integration_tokens WHERE dealership_id = :did AND provider = :p',
            ['did' => $dealershipId, 'p' => $provider]
        );
    }

    public static function isExpired(?string $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }
        return strtotime($expiresAt) <= time();
    }
}
