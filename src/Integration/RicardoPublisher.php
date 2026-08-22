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
 * Stellt ein Fahrzeug als Artikel bei Ricardo ein.
 *
 * Ricardo ist ein Marktplatz mit Festpreis oder Auktion, kein reines
 * Fahrzeugportal. Ein Inserat ist dort ein Artikel in einer Kategorie, mit
 * Preis, Laufzeit, Zahlungs- und Versandangaben. Fahrzeuge werden deshalb
 * als Festpreisartikel eingestellt: eine Auktion wuerde bedeuten, dass das
 * Fahrzeug am Ende zu einem Preis weggeht, den niemand bestimmt hat.
 */
final class RicardoPublisher
{
    private const MAX_IMAGES = 10;

    /** Laufzeit in Tagen. Ricardo verlaengert nicht von selbst. */
    private const DURATION_DAYS = 30;

    /**
     * @return array{article_id: string, created: bool, image_errors: array<int, string>}
     */
    public static function push(int $dealershipId, int $vehicleId, ?int $userId = null): array
    {
        if (!RicardoService::isConnected($dealershipId)) {
            throw new RuntimeException('Es ist keine aktive Ricardo-Verbindung hinterlegt.');
        }
        $token = RicardoService::token($dealershipId);
        if ($token === null) {
            throw new RuntimeException('Das Ricardo-Zugriffstoken fehlt. Bitte die Verbindung erneuern.');
        }

        $vehicle = VehicleRepository::find($vehicleId, $dealershipId);
        if ($vehicle === null) {
            throw new RuntimeException('Fahrzeug nicht gefunden.');
        }
        $listing = ListingService::ensureForVehicle($vehicleId, $dealershipId);

        // ------------------------------------ 1. Pflichtangaben zuerst pruefen
        $missing = self::missingFields($vehicle, $listing, $dealershipId);
        if ($missing !== []) {
            throw new RuntimeException(
                'Für die Übertragung zu Ricardo fehlen Angaben: ' . implode(', ', $missing) . '.'
            );
        }

        // ---------------------------------------------------------- 2. Bilder
        $pictures = [];
        $imageErrors = [];
        foreach (array_slice(VehicleRepository::images($vehicleId), 0, self::MAX_IMAGES) as $index => $image) {
            $path = (string) ($image['composed_path'] ?? '') !== ''
                ? (string) $image['composed_path']
                : (string) ($image['file_path'] ?? '');
            $absolute = BASE_PATH . '/uploads/' . $path;
            if ($path === '' || !is_file($absolute)) {
                continue;
            }
            $binary = @file_get_contents($absolute);
            if ($binary === false) {
                $imageErrors[] = basename($path) . ': nicht lesbar';
                continue;
            }
            // Ricardo nimmt die Bilder als Base64 im Artikel entgegen.
            $pictures[] = [
                'PictureIndex' => $index + 1,
                'Picture'      => base64_encode($binary),
            ];
        }

        // -------------------------------------------------------- 3. Artikel
        $existing = self::channelRow($dealershipId, (int) $listing['id']);
        $articleId = $existing !== null ? (string) ($existing['external_id'] ?? '') : '';
        $created = false;

        $article = self::mapVehicle($vehicle, $listing, $vehicleId, $pictures, $dealershipId);

        if ($articleId !== '') {
            // Ricardo kennt kein vollstaendiges Ersetzen: ein laufender Artikel
            // wird beendet und neu eingestellt.
            try {
                RicardoClient::call('SellService', 'CloseArticle', [
                    'closeArticleParameter' => [
                        'TokenCredentialKey' => $token['token'],
                        'ArticleId'          => $articleId,
                    ],
                ]);
            } catch (\Throwable $e) {
                Logger::warning('Ricardo: alter Artikel liess sich nicht beenden: ' . $e->getMessage());
            }
            $articleId = '';
        }

        $result = RicardoClient::call('SellService', 'InsertArticle', [
            'insertArticleParameter' => [
                'TokenCredentialKey' => $token['token'],
                'ArticleInformation' => $article,
            ],
        ]);

        $articleId = (string) ($result['ArticleId'] ?? ($result['InsertedArticleId'] ?? ''));
        if ($articleId === '') {
            self::rememberError($dealershipId, (int) $listing['id'], 'Ricardo hat keine Artikelnummer geliefert.');
            throw new RuntimeException('Ricardo hat keine Artikelnummer geliefert.');
        }
        $created = true;

        self::rememberSuccess($dealershipId, (int) $listing['id'], $articleId);
        ActivityLogger::log(
            $userId,
            'ricardo.article_created',
            'Fahrzeug #' . $vehicleId . ' zu Ricardo übertragen (Artikel ' . $articleId . ')',
            'vehicle',
            $vehicleId,
            $dealershipId
        );

        return ['article_id' => $articleId, 'created' => $created, 'image_errors' => $imageErrors];
    }

    /** Beendet den Artikel auf Ricardo. */
    public static function remove(int $dealershipId, int $vehicleId, ?int $userId = null): void
    {
        $token = RicardoService::token($dealershipId);
        $listing = Database::fetch(
            'SELECT id FROM listings WHERE vehicle_id = :v AND dealership_id = :d',
            ['v' => $vehicleId, 'd' => $dealershipId]
        );
        if ($token === null || $listing === null) {
            return;
        }
        $row = self::channelRow($dealershipId, (int) $listing['id']);
        $articleId = $row !== null ? (string) ($row['external_id'] ?? '') : '';
        if ($articleId === '') {
            return;
        }

        RicardoClient::call('SellService', 'CloseArticle', [
            'closeArticleParameter' => [
                'TokenCredentialKey' => $token['token'],
                'ArticleId'          => $articleId,
            ],
        ]);

        Database::run(
            'UPDATE channel_listings SET status = :s, external_id = NULL, updated_at = :t
             WHERE listing_id = :l AND provider = :p',
            ['s' => 'inactive', 't' => Database::now(), 'l' => (int) $listing['id'], 'p' => RicardoService::PROVIDER]
        );
        ActivityLogger::log($userId, 'ricardo.article_closed', 'Ricardo-Artikel ' . $articleId . ' beendet', 'vehicle', $vehicleId, $dealershipId);
    }

    /**
     * Angaben, ohne die Ricardo den Artikel sicher ablehnt.
     *
     * @param array<string, mixed> $vehicle
     * @param array<string, mixed> $listing
     * @return array<int, string>
     */
    private static function missingFields(array $vehicle, array $listing, int $dealershipId): array
    {
        $missing = [];
        $title = trim((string) ($listing['title'] ?? ''));
        if ($title === '' && trim((string) ($vehicle['make'] ?? '')) === '') {
            $missing[] = 'Titel oder Marke';
        }
        if (trim((string) ($listing['description'] ?? $vehicle['description'] ?? '')) === '') {
            $missing[] = 'Beschreibung';
        }
        if ((float) ($vehicle['price'] ?? 0) <= 0) {
            $missing[] = 'Preis';
        }
        if (self::categoryId($dealershipId) === '') {
            $missing[] = 'die Ricardo-Kategorie für Fahrzeuge (in den Einstellungen hinterlegen)';
        }
        return $missing;
    }

    /**
     * Kategorie, unter der Fahrzeuge eingestellt werden. Ricardo vergibt die
     * Nummern selbst; sie wird deshalb hinterlegt und nicht geraten.
     */
    private static function categoryId(int $dealershipId): string
    {
        return trim((string) (\App\Service\SettingsService::get('ricardo_category_id') ?? ''));
    }

    /**
     * Fahrzeug als Ricardo-Artikel.
     *
     * @param array<string, mixed>            $vehicle
     * @param array<string, mixed>            $listing
     * @param array<int, array<string, mixed>> $pictures
     * @return array<string, mixed>
     */
    private static function mapVehicle(
        array $vehicle,
        array $listing,
        int $vehicleId,
        array $pictures,
        int $dealershipId
    ): array {
        $title = trim((string) ($listing['title'] ?? ''));
        if ($title === '') {
            $title = trim(
                (string) ($vehicle['make'] ?? '') . ' '
                . (string) ($vehicle['model'] ?? '') . ' '
                . (string) ($vehicle['variant'] ?? '')
            );
        }
        $description = trim((string) ($listing['description'] ?? $vehicle['description'] ?? ''));

        // Die wichtigsten Fahrzeugdaten stehen bei Ricardo im Text: eigene
        // Felder dafuer gibt es nicht.
        $facts = [];
        foreach ([
            'Inverkehrsetzung' => (string) ($vehicle['first_registration'] ?? ''),
            'Kilometer'        => $vehicle['mileage'] !== null ? number_format((float) $vehicle['mileage'], 0, '.', "'") . ' km' : '',
            'Leistung'         => $vehicle['power_hp'] !== null ? ((int) $vehicle['power_hp'] . ' PS') : '',
            'Treibstoff'       => (string) ($vehicle['fuel_type'] ?? '') !== '' ? t('fuel.' . (string) $vehicle['fuel_type']) : '',
            'Getriebe'         => (string) ($vehicle['transmission'] ?? '') !== '' ? t('transmission.' . (string) $vehicle['transmission']) : '',
            'Farbe'            => (string) ($vehicle['color'] ?? ''),
        ] as $label => $value) {
            if (trim($value) !== '') {
                $facts[] = $label . ': ' . $value;
            }
        }
        if ($facts !== []) {
            $description = implode("\n", $facts) . "\n\n" . $description;
        }

        $article = [
            'CategoryId'        => (int) self::categoryId($dealershipId),
            'ArticleTitle'      => mb_substr($title, 0, 60),
            'ArticleDescription' => mb_substr($description, 0, 20000),
            // Festpreis: eine Auktion wuerde den Preis dem Zufall ueberlassen.
            'StartPrice'        => (int) round((float) $vehicle['price']),
            'BuyNowPrice'       => (int) round((float) $vehicle['price']),
            'InitialQuantity'   => 1,
            'ArticleDuration'   => self::DURATION_DAYS,
            'StartDate'         => null,   // sofort
            'InternalReferences' => [
                ['InternalReferenceValue' => 'RC-' . $vehicleId],
            ],
        ];

        if ($pictures !== []) {
            $article['Pictures'] = $pictures;
            $article['MainPictureId'] = 1;
        }
        return $article;
    }

    /** @return array<string, mixed>|null */
    private static function channelRow(int $dealershipId, int $listingId): ?array
    {
        return Database::fetch(
            'SELECT * FROM channel_listings WHERE listing_id = :l AND provider = :p',
            ['l' => $listingId, 'p' => RicardoService::PROVIDER]
        );
    }

    private static function rememberSuccess(int $dealershipId, int $listingId, string $articleId): void
    {
        $now = Database::now();
        $existing = self::channelRow($dealershipId, $listingId);
        $data = [
            'external_id' => $articleId,
            'status'      => 'active',
            'last_error'  => null,
            'synced_at'   => $now,
            'updated_at'  => $now,
        ];
        if ($existing !== null) {
            Database::update('channel_listings', (int) $existing['id'], $data);
        } else {
            Database::insert('channel_listings', $data + [
                'dealership_id' => $dealershipId,
                'listing_id'    => $listingId,
                'provider'      => RicardoService::PROVIDER,
                'created_at'    => $now,
            ]);
        }
    }

    private static function rememberError(int $dealershipId, int $listingId, string $message): void
    {
        $now = Database::now();
        $existing = self::channelRow($dealershipId, $listingId);
        $data = [
            'status'     => 'error',
            'last_error' => mb_substr($message, 0, 500),
            'updated_at' => $now,
        ];
        if ($existing !== null) {
            Database::update('channel_listings', (int) $existing['id'], $data);
        } else {
            Database::insert('channel_listings', $data + [
                'dealership_id' => $dealershipId,
                'listing_id'    => $listingId,
                'provider'      => RicardoService::PROVIDER,
                'created_at'    => $now,
            ]);
        }
    }
}
