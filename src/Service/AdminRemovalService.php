<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use RuntimeException;

/**
 * Endgültiges Löschen durch den Betreiber (§69): Konten, Mandanten und
 * Fahrzeuge samt aller Dateien auf der Platte. Die Datenbank räumt über
 * ihre Fremdschlüssel selbst auf; dieser Dienst kümmert sich zusätzlich
 * um Fotos, Dokumente und Logos, die sonst verwaist liegen blieben.
 */
final class AdminRemovalService
{
    /**
     * Löscht ein Fahrzeug mit Inserat, Bildern und Dateien.
     */
    public static function removeVehicle(int $vehicleId): void
    {
        $paths = [];
        foreach (Database::fetchAll(
            'SELECT file_path, cutout_path, composed_path, thumb_path, card_path
             FROM vehicle_images WHERE vehicle_id = :id',
            ['id' => $vehicleId]
        ) as $row) {
            foreach ($row as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }
        foreach (Database::fetchAll(
            'SELECT file_path FROM documents WHERE vehicle_id = :id',
            ['id' => $vehicleId]
        ) as $row) {
            if (is_string($row['file_path']) && $row['file_path'] !== '') {
                $paths[] = $row['file_path'];
            }
        }

        Database::run('DELETE FROM vehicles WHERE id = :id', ['id' => $vehicleId]);

        self::deleteFiles($paths);
        self::deleteDirectory(BASE_PATH . '/uploads/vehicles/' . $vehicleId);
    }

    /**
     * Löscht ein Benutzerkonto. Ist es das letzte Konto seines Mandanten,
     * verschwindet der ganze Mandant mit allen Fahrzeugen und Dateien.
     * Betreiberkonten und das eigene Konto sind tabu.
     */
    public static function removeUser(int $userId, int $actingAdminId): void
    {
        if ($userId === $actingAdminId) {
            throw new RuntimeException('Das eigene Konto kann nicht gelöscht werden.');
        }
        $user = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            throw new RuntimeException('Konto nicht gefunden.');
        }
        if ((string) $user['role'] === 'super_admin') {
            throw new RuntimeException('Betreiberkonten können hier nicht gelöscht werden.');
        }

        $dealershipId = $user['dealership_id'] !== null ? (int) $user['dealership_id'] : 0;
        $isLastUser = $dealershipId > 0
            && (int) Database::scalar(
                'SELECT COUNT(*) FROM users WHERE dealership_id = :d',
                ['d' => $dealershipId]
            ) === 1;

        if ($isLastUser) {
            self::removeDealership($dealershipId);
            return;
        }

        Database::run('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    /**
     * Löscht einen Mandanten (Autohaus oder Verkäuferprofil) restlos:
     * alle Konten, Fahrzeuge, Inserate, Posts, Dateien.
     */
    public static function removeDealership(int $dealershipId): void
    {
        $vehicleIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            Database::fetchAll('SELECT id FROM vehicles WHERE dealership_id = :d', ['d' => $dealershipId])
        );
        foreach ($vehicleIds as $vehicleId) {
            self::removeVehicle($vehicleId);
        }

        $paths = [];
        $logo = Database::scalar('SELECT logo_path FROM dealerships WHERE id = :d', ['d' => $dealershipId]);
        if (is_string($logo) && $logo !== '') {
            $paths[] = $logo;
        }
        foreach (Database::fetchAll(
            'SELECT image_path FROM social_posts WHERE dealership_id = :d',
            ['d' => $dealershipId]
        ) as $row) {
            if (is_string($row['image_path']) && $row['image_path'] !== '') {
                $paths[] = $row['image_path'];
            }
        }
        foreach (Database::fetchAll(
            'SELECT file_path FROM backgrounds WHERE dealership_id = :d',
            ['d' => $dealershipId]
        ) as $row) {
            if (is_string($row['file_path']) && $row['file_path'] !== '') {
                $paths[] = $row['file_path'];
            }
        }

        // Konten zuerst: users.dealership_id steht sonst auf SET NULL,
        // und die Konten blieben als Waisen zurück.
        Database::run('DELETE FROM users WHERE dealership_id = :d', ['d' => $dealershipId]);
        Database::run('DELETE FROM dealerships WHERE id = :d', ['d' => $dealershipId]);

        self::deleteFiles($paths);
        self::deleteDirectory(BASE_PATH . '/uploads/logos/' . $dealershipId);
    }

    /** @param array<int, string> $paths relative Pfade unterhalb von /uploads */
    private static function deleteFiles(array $paths): void
    {
        $root = realpath(BASE_PATH . '/uploads');
        if ($root === false) {
            return;
        }
        foreach (array_unique($paths) as $path) {
            $full = realpath(BASE_PATH . '/uploads/' . ltrim($path, '/'));
            // Nur innerhalb von /uploads: ein manipulierten Pfad in der
            // Datenbank darf nie ausserhalb loeschen.
            if ($full !== false && str_starts_with($full, $root) && is_file($full)) {
                @unlink($full);
            }
        }
    }

    private static function deleteDirectory(string $dir): void
    {
        $root = realpath(BASE_PATH . '/uploads');
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root) || !is_dir($real)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }
}
