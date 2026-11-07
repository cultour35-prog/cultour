<?php
/**
 * GCash checkout for the mobile app (Bearer token, tourists).
 * GET  ?booking_id=  → starts/refreshes the intent and returns the checkout summary
 * POST { booking_id, action: pay | pay_at_office | reference, payment_reference? }
 *
 * "pay" is the in-app equivalent of scanning the website's checkout QR (tourist/pay-scan.php).
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/payments.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
ensure_booking_payment_schema();

$in = api_input();
$bookingId = (int) ($in['booking_id'] ?? $in['id'] ?? 0);

$load = static function () use ($bookingId, $user): ?array {
    $st = db()->prepare(
        "SELECT b.*, g.hourly_rate, u.first_name AS guide_first, u.last_name AS guide_last
         FROM bookings b
         JOIN tour_guide_profiles g ON g.id = b.guide_id
         JOIN users u ON u.id = g.user_id
         WHERE b.id=? AND b.tourist_id=? LIMIT 1"
    );
    $st->execute([$bookingId, (int) $user['id']]);
    return $st->fetch() ?: null;
};

$summary = static function (array $b): array {
    $status = (string) ($b['payment_status'] ?? 'unpaid');
    return [
        'booking_id' => (int) $b['id'],
        'booking_code' => (string) $b['booking_code'],
        'booking_status' => (string) $b['status'],
        'payment_status' => $status,
        'reference' => (string) ($b['payment_reference'] ?? ''),
        'amount' => (float) ($b['payment_amount'] ?? 0),
        'amount_label' => format_money((float) ($b['payment_amount'] ?? 0)),
        'guide_amount' => isset($b['guide_amount']) && $b['guide_amount'] !== null && $b['guide_amount'] !== '' ? (float) $b['guide_amount'] : null,
        'guide_amount_label' => isset($b['guide_amount']) && $b['guide_amount'] !== null && $b['guide_amount'] !== '' ? format_money($b['guide_amount']) : null,
        'accommodation_amount' => isset($b['accommodation_amount']) && $b['accommodation_amount'] !== null && $b['accommodation_amount'] !== '' ? (float) $b['accommodation_amount'] : null,
        'accommodation_amount_label' => isset($b['accommodation_amount']) && $b['accommodation_amount'] !== null && $b['accommodation_amount'] !== '' ? format_money($b['accommodation_amount']) : null,
        'transportation_amount' => isset($b['transportation_amount']) && $b['transportation_amount'] !== null && $b['transportation_amount'] !== '' ? (float) $b['transportation_amount'] : null,
        'transportation_amount_label' => isset($b['transportation_amount']) && $b['transportation_amount'] !== null && $b['transportation_amount'] !== '' ? format_money($b['transportation_amount']) : null,
        'pay_to' => defined('GCASH_ACCOUNT_LABEL') ? GCASH_ACCOUNT_LABEL : 'CulTour GCash',
        'guide_name' => trim(($b['guide_first'] ?? '') . ' ' . ($b['guide_last'] ?? '')),
        'date_label' => format_date($b['booking_date']) . ' · ' . format_time_12h($b['start_time']),
        'party_size' => (int) $b['party_size'],
        'can_pay' => !in_array($status, ['paid', 'pending_review', 'refunded'], true)
            && in_array((string) $b['status'], ['pending', 'approved'], true),
    ];
};

$booking = $load();
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (($booking['payment_status'] ?? '') !== 'paid') {
        $start = booking_start_gcash_payment($booking, $booking);
        if (!$start['ok'] && !in_array((string) ($booking['payment_status'] ?? ''), ['pending_review', 'refunded'], true)) {
            json_response(['ok' => false, 'message' => $start['message']], 400);
        }
        $booking = $load() ?? $booking;
    }
    json_response(['ok' => true, 'data' => $summary($booking)]);
}

$action = (string) ($in['action'] ?? '');
if ($action === 'pay') {
    if (!in_array((string) $booking['status'], ['pending', 'approved'], true) && ($booking['payment_status'] ?? '') !== 'paid') {
        json_response(['ok' => false, 'message' => 'This booking cannot be paid right now.'], 400);
    }
    if (trim((string) ($booking['payment_intent_id'] ?? '')) === '') {
        $start = booking_start_gcash_payment($booking, $booking);
        if (!$start['ok']) {
            json_response(['ok' => false, 'message' => $start['message']], 400);
        }
        $booking = $load() ?? $booking;
    }
    $res = payment_complete_scan($booking);
} elseif ($action === 'pay_at_office') {
    $res = booking_mark_payment($booking, 'pay_at_office');
} elseif ($action === 'reference') {
    $res = booking_submit_gcash_reference($booking, (string) ($in['payment_reference'] ?? ''));
} else {
    json_response(['ok' => false, 'message' => 'Unknown payment action.'], 400);
}

if (empty($res['ok'])) {
    json_response(['ok' => false, 'message' => (string) ($res['message'] ?? 'Payment failed.')], 400);
}
$booking = $load() ?? $booking;
json_response(['ok' => true, 'message' => (string) $res['message'], 'data' => $summary($booking)]);
