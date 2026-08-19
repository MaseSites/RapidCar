<?php
/**
 * Stripe-Webhook: bestätigt bezahlte Checkout-Sessions.
 *
 * Kein CSRF und keine Session: Stripe ruft diesen Endpunkt von aussen auf.
 * Die Echtheit sichert die HMAC-Signatur im Header Stripe-Signature.
 * Ohne gültige Signatur wird nichts verbucht.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Logger;
use App\Service\PaymentService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Nur POST.');
}

$payload = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

try {
    $orderId = PaymentService::handleStripeWebhook($payload, $signature);
} catch (\Throwable $e) {
    Logger::error('Stripe-Webhook abgelehnt: ' . $e->getMessage());
    http_response_code(400);
    exit('Webhook abgelehnt.');
}

http_response_code(200);
echo $orderId !== null ? 'Bestellung ' . $orderId . ' verbucht.' : 'Nichts zu tun.';
