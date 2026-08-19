<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\CaBundle;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
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
    public static function createStripeCheckout(int $orderId, string $successUrl, string $cancelUrl): string
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

        $response = self::stripeRequest('POST', '/checkout/sessions', [
            'mode'                      => 'payment',
            'success_url'               => $successUrl,
            'cancel_url'                => $cancelUrl,
            'client_reference_id'       => (string) $orderId,
            'line_items[0][quantity]'   => '1',
            'line_items[0][price_data][currency]'                  => strtolower((string) $order['currency']),
            'line_items[0][price_data][unit_amount]'               => (string) (int) round(((float) $order['price']) * 100),
            'line_items[0][price_data][product_data][name]'        => 'RapidCar Guthaben: ' . $label,
            'metadata[order_id]'        => (string) $orderId,
        ]);

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
    private static function stripeRequest(string $method, string $path, array $fields = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für Zahlungen benötigt.');
        }
        $apiKey = trim((string) Config::get('payment.api_key', ''));

        $ch = curl_init(self::STRIPE_API . $path);
        curl_setopt_array($ch, CaBundle::applyTo([
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]));

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
