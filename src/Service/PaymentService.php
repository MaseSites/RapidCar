<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\CaBundle;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Service\SettingsService;
use RuntimeException;

/**
 * Zahlungsanbieter-Anbindung für den Guthaben-Kauf.
 *
 * Aktuell wird Stripe Checkout unterstützt: Der Server erstellt eine
 * Checkout-Session und leitet den Käufer zur Stripe-Kasse weiter. Nach der
 * Zahlung bestätigt Stripe über den Webhook, erst dann wird das Guthaben
 * gutgeschrieben. Ohne hinterlegten Schlüssel bleibt der bisherige Weg aktiv:
 * Bestellung wird erfasst und vom Betreiber im Admin freigegeben. Es wird
 * keine Zahlung vorgetäuscht (§72).
 */
final class PaymentService
{
    private const STRIPE_API = 'https://api.stripe.com/v1';
    private const TIMEOUT_SECONDS = 30;

    public static function provider(): string
    {
        return strtolower(trim((string) Config::get('payment.provider', '')));
    }

    public static function isStripeReady(): bool
    {
        return self::provider() === 'stripe'
            && trim((string) Config::get('payment.api_key', '')) !== '';
    }

    /**
     * Erstellt eine Stripe-Checkout-Session für eine offene Bestellung.
     *
     * @return string Weiterleitungs-URL zur Stripe-Kasse
     */
    /** Zahlungsarten, die eine Konfiguration fest vorgeben darf. */
    public const PAYMENT_METHODS = ['card', 'twint', 'klarna', 'paypal', 'link'];

    /**
     * @param string|null $method 'card' oder 'twint'. Bei 'card' zeigt die
     *                            Stripe-Kasse automatisch auch Apple Pay und
     *                            Google Pay an, wenn das Geraet sie kann.
     *                            null = Stripe zeigt alles, was im Konto
     *                            freigeschaltet ist.
     */
    public static function createStripeCheckout(int $orderId, string $successUrl, string $cancelUrl, ?string $method = null): string
    {
        if (!self::isStripeReady()) {
            throw new RuntimeException('Stripe ist nicht konfiguriert.');
        }
        $order = Database::fetch('SELECT * FROM credit_orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null || (string) $order['status'] !== 'pending') {
            throw new RuntimeException('Die Bestellung wurde nicht gefunden oder ist bereits abgeschlossen.');
        }

        $label = (int) $order['credits'] === 1
            ? '1 Inserat'
            : $order['credits'] . ' Inserate';

        $params = [
            'mode'                      => 'payment',
            'success_url'               => $successUrl,
            'cancel_url'                => $cancelUrl,
            'client_reference_id'       => (string) $orderId,
            'line_items[0][quantity]'   => '1',
            'line_items[0][price_data][currency]'                  => strtolower((string) $order['currency']),
            'line_items[0][price_data][unit_amount]'               => (string) (int) round(((float) $order['price']) * 100),
            'line_items[0][price_data][product_data][name]'        => 'RapidCar Guthaben: ' . $label,
            'metadata[order_id]'        => (string) $orderId,

            // Kunde in Stripe anlegen: Grundlage fuer Rechnungen, Steuer
            // und die Kaufhistorie im Stripe-Dashboard.
            'customer_creation'         => 'always',
        ];

        // E-Mail des Kaeufers vorbefuellen: weniger Tipparbeit an der Kasse,
        // Beleg und Rechnung kommen automatisch an die richtige Adresse.
        $buyerEmail = self::orderBuyerEmail($order);
        if ($buyerEmail !== '') {
            $params['customer_email'] = $buyerEmail;
        }

        // Rechnung je Kauf (Stripe Invoicing). Kostenlos fuer Checkout-Kaeufe;
        // die PDF haengt an der Stripe-Quittung und liegt im Dashboard.
        if (filter_var(Config::get('payment.invoices', true), FILTER_VALIDATE_BOOL)) {
            $params['invoice_creation[enabled]'] = 'true';
        }

        // Stripe Tax: Mehrwertsteuer automatisch berechnen und ausweisen.
        // Erst einschalten, wenn Stripe Tax im Dashboard eingerichtet ist,
        // sonst lehnt Stripe die Session ab.
        if (filter_var(Config::get('payment.automatic_tax', false), FILTER_VALIDATE_BOOL)) {
            $params['automatic_tax[enabled]'] = 'true';
            $params['billing_address_collection'] = 'required';
        }

        // Zahlarten der Kasse. Reihenfolge der Entscheidung:
        //   1. payment.methods aus der Konfiguration, wenn gesetzt
        //   2. sonst automatisch: Karte, plus TWINT sobald es im Stripe-Konto
        //      freigeschaltet ist. Klarna und anderes, was Stripe von sich aus
        //      dazuschaltet, erscheint damit bewusst nicht.
        // Apple Pay und Google Pay laufen ueber 'card' und kommen von selbst,
        // je nachdem, was Geraet und Browser des Kaeufers koennen.
        $configured = Config::get('payment.methods', []);
        $pinned = [];
        if (is_array($configured)) {
            foreach ($configured as $entry) {
                $entry = strtolower(trim((string) $entry));
                if (in_array($entry, self::PAYMENT_METHODS, true)) {
                    $pinned[] = $entry;
                }
            }
        }
        if ($pinned === []) {
            $pinned = self::autoMethods();
        }
        if ($method !== null && in_array($method, self::PAYMENT_METHODS, true)) {
            $pinned = [$method];
        }
        foreach (array_values(array_unique($pinned)) as $i => $entry) {
            $params['payment_method_types[' . $i . ']'] = $entry;
        }

        // Idempotenz je Bestellung: Doppelklick oder Netzwiederholung erzeugt
        // dieselbe Kasse noch einmal, nie eine zweite.
        $response = self::stripeRequest('POST', '/checkout/sessions', $params, 'rapidcar-order-' . $orderId);

        $url = (string) ($response['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Stripe hat keine Kassen-URL geliefert.');
        }

        Database::update('credit_orders', $orderId, [
            'provider_ref' => (string) ($response['id'] ?? ''),
            'updated_at'   => Database::now(),
        ]);

        return $url;
    }

    /**
     * Verarbeitet den Stripe-Webhook `checkout.session.completed`.
     * Die Signatur wird geprüft, bevor irgendetwas gutgeschrieben wird.
     *
     * @return int|null Abgeschlossene Bestell-ID oder null, wenn nichts zu tun war
     */
    public static function handleStripeWebhook(string $payload, string $signatureHeader): ?int
    {
        $secret = trim((string) Config::get('payment.webhook_secret', ''));
        if ($secret === '') {
            throw new RuntimeException('Es ist kein Webhook-Secret hinterlegt (payment.webhook_secret).');
        }
        if (!self::verifyStripeSignature($payload, $signatureHeader, $secret)) {
            throw new RuntimeException('Die Webhook-Signatur ist ungültig.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new RuntimeException('Der Webhook-Inhalt konnte nicht gelesen werden.');
        }
        if ((string) ($event['type'] ?? '') !== 'checkout.session.completed') {
            return null;
        }

        $session = $event['data']['object'] ?? [];
        $orderId = (int) ($session['metadata']['order_id'] ?? ($session['client_reference_id'] ?? 0));
        if ($orderId <= 0) {
            throw new RuntimeException('Der Webhook enthält keine Bestellnummer.');
        }
        if ((string) ($session['payment_status'] ?? '') !== 'paid') {
            return null;
        }

        $order = Database::fetch('SELECT * FROM credit_orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            throw new RuntimeException('Bestellung #' . $orderId . ' wurde nicht gefunden.');
        }
        if ((string) $order['status'] !== 'pending') {
            return null; // bereits verarbeitet: Webhooks können mehrfach eintreffen
        }

        CreditService::completeOrder($orderId, null, false);
        Logger::info('Stripe-Zahlung verbucht für Bestellung #' . $orderId);
        return $orderId;
    }

    /**
     * Prüft die Stripe-Signatur (t=...,v1=... im Header Stripe-Signature).
     * Toleranz: 5 Minuten gegen wiederholtes Einspielen alter Ereignisse.
     */
    public static function verifyStripeSignature(string $payload, string $header, string $secret): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs(time() - $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    /**
     * Automatische Zahlarten: Karte immer, TWINT sobald das Stripe-Konto es
     * anbietet. Die Abfrage bei Stripe wird sechs Stunden zwischengespeichert,
     * damit nicht jeder Kauf eine zusaetzliche Anfrage kostet.
     *
     * @return array<int, string>
     */
    private static function autoMethods(): array
    {
        $cached = SettingsService::get('stripe_auto_methods');
        if ($cached !== null) {
            $entry = json_decode($cached, true);
            if (is_array($entry)
                && time() - (int) ($entry['checked_at'] ?? 0) < 6 * 3600
                && is_array($entry['methods'] ?? null)
            ) {
                return $entry['methods'];
            }
        }

        $methods = ['card'];
        $overview = self::methodOverview();
        if (($overview['twint'] ?? '') === 'on') {
            $methods[] = 'twint';
        }
        if ($overview !== []) {
            // Nur speichern, wenn Stripe geantwortet hat: eine leere Antwort
            // (nicht erreichbar) soll den naechsten Versuch nicht blockieren.
            SettingsService::set('stripe_auto_methods', (string) json_encode([
                'checked_at' => time(),
                'methods'    => $methods,
            ]));
        }
        return $methods;
    }

    /**
     * Fragt bei Stripe ab, welche Zahlarten das Konto anbietet.
     * Fuer die Selbstpruefung: zeigt dem Betreiber, was im Stripe-Dashboard
     * noch freizuschalten ist (z.B. TWINT). Gibt [] zurueck, wenn Stripe
     * nicht eingerichtet oder nicht erreichbar ist.
     *
     * @return array<string, string> Zahlart => 'on' | 'off'
     */
    public static function methodOverview(): array
    {
        if (!self::isStripeReady()) {
            return [];
        }
        try {
            $response = self::stripeRequest('GET', '/payment_method_configurations');
        } catch (\Throwable) {
            return [];
        }
        $config = $response['data'][0] ?? null;
        if (!is_array($config)) {
            return [];
        }
        $overview = [];
        foreach (['card', 'twint', 'klarna', 'paypal', 'link', 'apple_pay', 'google_pay'] as $methodKey) {
            if (isset($config[$methodKey]['display_preference']['value'])) {
                $overview[$methodKey] = (string) $config[$methodKey]['display_preference']['value'];
            }
        }
        return $overview;
    }

    /** E-Mail des Bestellers: erst der ausloesende Nutzer, sonst der Mandant. */
    private static function orderBuyerEmail(array $order): string
    {
        $userId = (int) ($order['created_by'] ?? 0);
        if ($userId > 0) {
            $email = (string) (Database::scalar(
                'SELECT email FROM users WHERE id = :id',
                ['id' => $userId]
            ) ?: '');
            if ($email !== '') {
                return $email;
            }
        }
        return (string) (Database::scalar(
            'SELECT email FROM dealerships WHERE id = :id',
            ['id' => (int) ($order['dealership_id'] ?? 0)]
        ) ?: '');
    }

    /**
     * @param string|null $idempotencyKey Gleicher Schluessel = gleiche Antwort:
     *                                    ein doppelt abgeschickter Kauf erzeugt
     *                                    keine zweite Kasse.
     */
    private static function stripeRequest(string $method, string $path, array $fields = [], ?string $idempotencyKey = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für Zahlungen benötigt.');
        }
        $apiKey = trim((string) Config::get('payment.api_key', ''));

        $ch = curl_init(self::STRIPE_API . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => array_merge([
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ], $idempotencyKey !== null ? ['Idempotency-Key: ' . $idempotencyKey] : []),
        ];
        if ($method !== 'GET' || $fields !== []) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($ch, CaBundle::applyTo($options));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('Stripe nicht erreichbar: ' . $curlError);
            throw new RuntimeException('Der Zahlungsanbieter ist nicht erreichbar.');
        }
        $decoded = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            Logger::error('Stripe-Fehler', ['status' => $status]);
            throw new RuntimeException(
                'Der Zahlungsanbieter hat die Anfrage abgelehnt'
                . ($message !== '' ? ': ' . $message : ' (HTTP ' . $status . ').')
            );
        }
        return $decoded;
    }
}
