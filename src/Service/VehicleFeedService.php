<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Repository\VehicleRepository;

/**
 * Fahrzeugliste als Datei zum Abholen.
 *
 * Plattformen ohne offene Schnittstelle holen sich die Fahrzeuge meist als
 * Datei ab, statt einzeln Anfragen entgegenzunehmen. Meta beschreibt dafuer
 * ein festes Feld-Format fuer Fahrzeugkataloge; es dient hier als Grundlage,
 * weil es das einzige oeffentlich dokumentierte ist. Andere Plattformen
 * bekommen dieselbe Adresse und koennen die Spalten zuordnen.
 *
 * Die Adresse traegt eine Unterschrift, damit niemand die Liste eines
 * fremden Kontos abrufen kann, und funktioniert ohne Anmeldung: die
 * Plattformen holen sie selbstaendig ab.
 */
final class VehicleFeedService
{
    /** Spalten in der Reihenfolge, in der sie in der Datei stehen. */
    public const COLUMNS = [
        'vehicle_id', 'title', 'description', 'url', 'make', 'model', 'trim',
        'year', 'mileage.value', 'mileage.unit', 'price', 'currency',
        'state_of_vehicle', 'exterior_color', 'interior_color', 'body_style',
        'transmission', 'fuel_type', 'drivetrain', 'vin', 'availability',
        'condition', 'image[0].url', 'address.city', 'address.postal_code',
        'address.country', 'dealer_name', 'dealer_phone',
    ];

    /** Aufbau zu den Werten, die Meta zulaesst. */
    private const BODY_STYLES = [
        'limousine'  => 'SEDAN',
        'kombi'      => 'WAGON',
        'coupe'      => 'COUPE',
        'suv'        => 'SUV',
        'cabriolet'  => 'CONVERTIBLE',
        'kleinwagen' => 'SMALL_CAR',
        'van'        => 'VAN',
        'pickup'     => 'PICKUP',
    ];

    private const FUEL_TYPES = [
        'petrol'         => 'PETROL',
        'diesel'         => 'DIESEL',
        'electric'       => 'ELECTRIC',
        'hybrid'         => 'HYBRID',
        'plug_in_hybrid' => 'PLUGIN_HYBRID',
        'gas'            => 'OTHER',
    ];

    private const DRIVETRAINS = ['fwd' => 'FWD', 'rwd' => 'RWD', 'awd' => 'AWD'];

    /** Unterschrift der Adresse: haengt am Anwendungsschluessel. */
    public static function token(int $dealershipId): string
    {
        $key = (string) Config::get('app.key', '');
        return substr(hash_hmac('sha256', 'vehicle-feed-' . $dealershipId, $key), 0, 32);
    }

    public static function isValidToken(int $dealershipId, string $token): bool
    {
        $expected = self::token($dealershipId);
        return $token !== '' && hash_equals($expected, $token);
    }

    /** Vollstaendige Adresse zum Weitergeben an eine Plattform. */
    public static function url(int $dealershipId): string
    {
        return base_url('feed/vehicles.php?d=' . $dealershipId . '&t=' . self::token($dealershipId));
    }

    /**
     * Baut die Datei als Text. Enthalten sind nur veroeffentlichte
     * Fahrzeuge: was nicht online ist, gehoert in keine Liste.
     */
    public static function build(int $dealershipId): string
    {
        $dealership = Database::fetch(
            'SELECT name, city, zip, country, phone, currency FROM dealerships WHERE id = :id',
            ['id' => $dealershipId]
        ) ?? [];

        $vehicles = Database::fetchAll(
            "SELECT * FROM vehicles WHERE dealership_id = :d AND status = 'published' ORDER BY id",
            ['d' => $dealershipId]
        );

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUMNS);

        foreach ($vehicles as $vehicle) {
            $row = self::row($vehicle, $dealership, $dealershipId);
            if ($row !== null) {
                fputcsv($handle, $row);
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Eine Zeile. Fehlt eine Pflichtangabe, bleibt das Fahrzeug draussen:
     * eine unvollstaendige Zeile wuerde die ganze Datei entwerten.
     *
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $dealership
     * @return array<int, string>|null
     */
    private static function row(array $vehicle, array $dealership, int $dealershipId): ?array
    {
        $vehicleId = (int) $vehicle['id'];
        $make = trim((string) ($vehicle['make'] ?? ''));
        $model = trim((string) ($vehicle['model'] ?? ''));
        $year = (int) ($vehicle['year'] ?? 0);
        $price = (float) ($vehicle['price'] ?? 0);

        if ($make === '' || $model === '' || $price <= 0) {
            return null;
        }

        $listing = Database::fetch(
            'SELECT title, description FROM listings WHERE vehicle_id = :v AND dealership_id = :d',
            ['v' => $vehicleId, 'd' => $dealershipId]
        ) ?? [];

        $title = trim((string) ($listing['title'] ?? ''));
        if ($title === '') {
            $title = trim($make . ' ' . $model . ' ' . (string) ($vehicle['variant'] ?? ''));
        }
        $description = trim((string) ($listing['description'] ?? $vehicle['description'] ?? ''));
        if ($description === '') {
            $description = $title;
        }

        $images = VehicleRepository::images($vehicleId);
        $firstImage = '';
        foreach ($images as $image) {
            $path = (string) ($image['composed_path'] ?? '') !== ''
                ? (string) $image['composed_path']
                : (string) ($image['file_path'] ?? '');
            if ($path !== '') {
                $firstImage = upload_url($path);
                break;
            }
        }

        return [
            'RC-' . $vehicleId,
            mb_substr($title, 0, 150),
            mb_substr(strip_tags($description), 0, 5000),
            base_url('inserat.php?id=' . $vehicleId),
            $make,
            $model,
            (string) ($vehicle['variant'] ?? ''),
            $year > 0 ? (string) $year : '',
            (string) (int) ($vehicle['mileage'] ?? 0),
            'KM',
            number_format($price, 2, '.', ''),
            (string) ($dealership['currency'] ?? 'CHF'),
            ((string) ($vehicle['condition_state'] ?? '')) === 'new' ? 'NEW' : 'USED',
            (string) ($vehicle['color'] ?? ''),
            (string) ($vehicle['interior_color'] ?? ''),
            self::BODY_STYLES[(string) ($vehicle['body_type'] ?? '')] ?? 'OTHER',
            self::transmission((string) ($vehicle['transmission'] ?? '')),
            self::FUEL_TYPES[(string) ($vehicle['fuel_type'] ?? '')] ?? 'OTHER',
            self::DRIVETRAINS[(string) ($vehicle['drivetrain'] ?? '')] ?? 'OTHER',
            (string) ($vehicle['vin'] ?? ''),
            'AVAILABLE',
            'GOOD',
            $firstImage,
            (string) ($dealership['city'] ?? ''),
            (string) ($dealership['zip'] ?? ''),
            (string) ($dealership['country'] ?? 'CH'),
            (string) ($dealership['name'] ?? ''),
            (string) ($dealership['phone'] ?? ''),
        ];
    }

    private static function transmission(string $value): string
    {
        return match ($value) {
            'automatic', 'semi_automatic' => 'AUTOMATIC',
            'manual'                      => 'MANUAL',
            default                       => 'OTHER',
        };
    }

    /** Anzahl der Fahrzeuge, die in der Datei landen wuerden. */
    public static function count(int $dealershipId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM vehicles
             WHERE dealership_id = :d AND status = 'published'
               AND make IS NOT NULL AND make != ''
               AND model IS NOT NULL AND model != ''
               AND price IS NOT NULL AND price > 0",
            ['d' => $dealershipId]
        );
    }
}
