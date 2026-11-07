<?php
/**
 * Refund details for the mobile app (Bearer token).
 * GET  → { ok, pending: [{ id, booking_code, amount_label, note }], saved: { number, qr_url } }
 * POST → booking_id, refund_number and/or refund_qr (file), refund_save, refund_use_saved_qr
 */
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}
ensure_booking_refund_schema();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $saved = refund_saved_destination($uid);
    $pending = array_map(static fn ($b) => [
        'id' => (int) $b['id'],
        'booking_code' => (string) $b['booking_code'],
        'amount_label' => format_money(refund_amount_of($b)),
        'note' => (string) ($b['refund_note'] ?? ''),
    ], refund_tourist_awaiting($uid));
    json_response([
        'ok' => true,
        'pending' => $pending,
        'saved' => [
            'number' => $saved['number'],
            'qr_url' => api_upload_url($saved['qr'] !== '' ? $saved['qr'] : null),
        ],
    ]);
}

$in = api_input();
$id = (int) ($in['booking_id'] ?? 0);
$st = db()->prepare('SELECT * FROM bookings WHERE id=? AND tourist_id=?');
$st->execute([$id, $uid]);
$booking = $st->fetch();
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found'], 404);
}
$res = refund_submit_details($booking, $uid, (string) ($in['refund_number'] ?? ''), refund_opts_from($in));
json_response($res, $res['ok'] ? 200 : 400);
