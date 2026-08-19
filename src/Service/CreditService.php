<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;

/**
 * Guthaben für Inserat-Veröffentlichungen.
 *
 * Grundregel: Fahrzeuge anlegen, Inserate erstellen, bearbeiten und in der
 * Vorschau prüfen ist kostenlos. Erst die Veröffentlichung eines Inserats
 * verbraucht genau ein Guthaben, und zwar einmalig pro Inserat.
 */
final class CreditService
{
    public const REASON_PURCHASE = 'purchase';
    public const REASON_PUBLISH  = 'publish';
    public const REASON_WELCOME  = 'welcome';
    public const REASON_ADMIN    = 'admin';
    public const REASON_REFUND   = 'refund';

    /** Startguthaben für neue Autohäuser (ein Gratis-Inserat). */
    public const WELCOME_CREDITS = 1;

    /**
     * Verfügbare Pakete.
     *
     * @return array<string, array{credits: int, price: float, currency: string}>
     */
    public static function packages(): array
    {
        return [
            'single' => ['credits' => 1,   'price' => 10.0,  'currency' => 'CHF'],
            'small'  => ['credits' => 5,   'price' => 40.0,  'currency' => 'CHF'],
            'medium' => ['credits' => 10,  'price' => 70.0,  'currency' => 'CHF'],
            'large'  => ['credits' => 50,  'price' => 300.0, 'currency' => 'CHF'],
            'xlarge' => ['credits' => 100, 'price' => 500.0, 'currency' => 'CHF'],
        ];
    }

    /** @return array{credits: int, price: float, currency: string}|null */
    public static function package(string $key): ?array
    {
        return self::packages()[$key] ?? null;
    }

    /**
     * Ist ein Zahlungsanbieter konfiguriert?
     * Ohne Anbieter wird keine Zahlung vorgetäuscht: Bestellungen bleiben offen,
     * bis der Betreiber sie freigibt (oder ein klar benannter Testkauf erfolgt).
     */
    public static function paymentConfigured(): bool
    {
        return (string) Config::get('payment.provider', '') !== ''
            && (string) Config::get('payment.api_key', '') !== '';
    }

    // -----------------------------------------------------------------------
    // Kontostand
    // -----------------------------------------------------------------------

    public static function balance(int $dealershipId): int
    {
        $value = Database::scalar(
            'SELECT credits FROM dealerships WHERE id = :id',
            ['id' => $dealershipId]
        );
        return $value === false || $value === null ? 0 : (int) $value;
    }

    public static function hasCredits(int $dealershipId, int $amount = 1): bool
    {
        return self::balance($dealershipId) >= $amount;
    }

    // -----------------------------------------------------------------------
    // Buchungen
    // -----------------------------------------------------------------------

    /**
     * Schreibt Guthaben gut und protokolliert die Bewegung.
     *
     * @return int Neuer Kontostand
     */
    public static function grant(
        int $dealershipId,
        int $amount,
        string $reason,
        ?string $description = null,
        ?int $userId = null,
        ?int $orderId = null
    ): int {
        if ($amount <= 0) {
            throw new RuntimeException('Gutschrift muss positiv sein.');
        }
        return self::book($dealershipId, $amount, $reason, $description, $userId, $orderId, null);
    }

    /**
     * Verbraucht ein Guthaben für die Veröffentlichung eines Inserats.
     * Wirft eine Ausnahme, wenn das Guthaben nicht reicht.
     */
    public static function consumeForListing(int $dealershipId, int $listingId, ?int $userId = null): int
    {
        $listing = Database::fetch(
            'SELECT * FROM listings WHERE id = :id AND dealership_id = :did',
            ['id' => $listingId, 'did' => $dealershipId]
        );
        if ($listing === null) {
            throw new RuntimeException('Inserat nicht gefunden.');
        }
        // Einmalige Belastung pro Inserat: erneutes Veröffentlichen ist gratis
        if ((int) ($listing['credit_charged'] ?? 0) === 1) {
            return self::balance($dealershipId);
        }
        if (!self::hasCredits($dealershipId, 1)) {
            throw new RuntimeException('INSUFFICIENT_CREDITS');
        }

        $balance = self::book(
            $dealershipId,
            -1,
            self::REASON_PUBLISH,
            'Inserat #' . $listingId,
            $userId,
            null,
            $listingId
        );
        Database::update('listings', $listingId, ['credit_charged' => 1]);
        return $balance;
    }

    /** Zentrale Buchung: Kontostand aktualisieren und Bewegung protokollieren. */
    private static function book(
        int $dealershipId,
        int $delta,
        string $reason,
        ?string $description,
        ?int $userId,
        ?int $orderId,
        ?int $listingId
    ): int {
        Database::beginTransaction();
        try {
            // Bedingtes, atomares Update statt Lesen und Zurueckschreiben:
            // zwei gleichzeitige Veroeffentlichungen koennten sonst dasselbe
            // letzte Guthaben verbrauchen. Reicht der Stand nicht, aendert
            // die Datenbank keine Zeile, und die Buchung schlaegt ehrlich fehl.
            $affected = Database::run(
                'UPDATE dealerships
                 SET credits = credits + :d, updated_at = :t
                 WHERE id = :id AND credits + :d2 >= 0',
                ['d' => $delta, 'd2' => $delta, 't' => Database::now(), 'id' => $dealershipId]
            )->rowCount();
            if ($affected === 0) {
                throw new RuntimeException('INSUFFICIENT_CREDITS');
            }
            $new = (int) Database::scalar(
                'SELECT credits FROM dealerships WHERE id = :id',
                ['id' => $dealershipId]
            );
            Database::insert('credit_transactions', [
                'dealership_id' => $dealershipId,
                'delta'         => $delta,
                'balance_after' => $new,
                'reason'        => $reason,
                'description'   => $description,
                'listing_id'    => $listingId,
                'order_id'      => $orderId,
                'user_id'       => $userId,
                'created_at'    => Database::now(),
            ]);

            Database::commit();
            return $new;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function history(int $dealershipId, int $limit = 25): array
    {
        return Database::fetchAll(
            'SELECT * FROM credit_transactions WHERE dealership_id = :did ORDER BY id DESC LIMIT ' . max(1, $limit),
            ['did' => $dealershipId]
        );
    }

    // -----------------------------------------------------------------------
    // Bestellungen
    // -----------------------------------------------------------------------

    /** Erstellt eine Bestellung im Status "offen". */
    public static function createOrder(int $dealershipId, string $packageKey, ?int $userId = null): int
    {
        $package = self::package($packageKey);
        if ($package === null) {
            throw new RuntimeException('Unbekanntes Paket.');
        }
        $now = Database::now();
        return Database::insert('credit_orders', [
            'dealership_id' => $dealershipId,
            'package_key'   => $packageKey,
            'credits'       => $package['credits'],
            'price'         => $package['price'],
            'currency'      => $package['currency'],
            'status'        => 'pending',
            'created_by'    => $userId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /**
     * Markiert eine Bestellung als bezahlt und schreibt das Guthaben gut.
     * Wird vom Betreiber im Admin ausgelöst oder beim klar benannten Testkauf.
     */
    public static function completeOrder(int $orderId, ?int $userId = null, bool $isTestPurchase = false): void
    {
        $order = Database::fetch('SELECT * FROM credit_orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            throw new RuntimeException('Bestellung nicht gefunden.');
        }
        if ((string) $order['status'] === 'paid') {
            return;
        }

        // Nur der Wechsel pending -> paid schreibt gut. Das bedingte Update
        // verhindert, dass zwei gleichzeitig eintreffende Webhooks dieselbe
        // Bestellung doppelt verbuchen.
        $switched = Database::run(
            "UPDATE credit_orders SET status = 'paid', paid_at = :p, updated_at = :u
             WHERE id = :id AND status = 'pending'",
            ['p' => Database::now(), 'u' => Database::now(), 'id' => $orderId]
        )->rowCount();
        if ($switched === 0) {
            return;
        }

        $description = ($isTestPurchase ? 'Testkauf ohne Zahlung: ' : '')
            . $order['credits'] . ' Inserate, Bestellung #' . $orderId;

        self::grant(
            (int) $order['dealership_id'],
            (int) $order['credits'],
            self::REASON_PURCHASE,
            $description,
            $userId,
            $orderId
        );
    }

    public static function cancelOrder(int $orderId): void
    {
        Database::update('credit_orders', $orderId, [
            'status'     => 'cancelled',
            'updated_at' => Database::now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function orders(int $dealershipId, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT * FROM credit_orders WHERE dealership_id = :did ORDER BY id DESC LIMIT ' . max(1, $limit),
            ['did' => $dealershipId]
        );
    }
}
