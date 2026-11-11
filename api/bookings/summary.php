<?php
/**
 * Tourist booking summary: the same card the guide sees in View details.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/booking_detail_cards.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$id = (int) ($_GET['id'] ?? 0);
$booking = booking_detail_fetch($id);
if (!$booking || (int) ($booking['tourist_id'] ?? 0) !== (int) $user['id']) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}

json_response([
    'ok' => true,
    'code' => (string) ($booking['booking_code'] ?? ''),
    'status' => (string) ($booking['status'] ?? ''),
    'itinerary_id' => (int) ($booking['itinerary_id'] ?? 0),
    'html' => booking_tourist_summary_html($booking),
]);
