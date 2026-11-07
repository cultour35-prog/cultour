<?php
/**
 * Admin popup: the same ticket, PWD, note, and itinerary cards as the phone scan page.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/booking_detail_cards.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_ADMIN) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$id = (int) ($_GET['id'] ?? 0);
$booking = booking_detail_fetch($id);
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}

$certUrl = '';
if (booking_is_pwd($booking) && booking_fitness_certificate_url($booking)) {
    $certUrl = (string) booking_fitness_certificate_url($booking);
}

json_response([
    'ok' => true,
    'code' => (string) $booking['booking_code'],
    'status' => (string) $booking['status'],
    'html' => booking_detail_cards_html($booking, $certUrl),
]);
