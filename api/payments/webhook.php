<?php
/**
 * GCash / payment provider webhook.
 * Demo & future PayMongo-style callbacks land here.
 *
 * Headers:
 *   Content-Type: application/json
 *   X-CulTour-Signature: hmac-sha256(raw_body, PAYMENT_WEBHOOK_SECRET)
 *
 * Body example:
 * {
 *   "event": "gcash.paid",
 *   "intent_id": "gcash_…",
 *   "amount": 1500,
 *   "reference": "GCASH-ABCD1234"
 * }
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'POST only'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_X_CULTOUR_SIGNATURE']
    ?? $_SERVER['HTTP_X_PAYMONGO_SIGNATURE']
    ?? '';

if (!payment_verify_signature($raw, $sig)) {
    json_response(['ok' => false, 'message' => 'Invalid webhook signature'], 401);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'message' => 'Invalid JSON'], 400);
}

$result = payment_apply_webhook($payload);
$status = $result['ok'] ? 200 : 422;
json_response([
    'ok' => $result['ok'],
    'message' => $result['message'],
    'booking' => $result['booking'] ? [
        'id' => (int) $result['booking']['id'],
        'booking_code' => $result['booking']['booking_code'],
        'payment_status' => $result['booking']['payment_status'] ?? null,
        'payment_reference' => $result['booking']['payment_reference'] ?? null,
    ] : null,
], $status);
