<?php
/**
 * Guide popup: the same ticket, PWD, note, and itinerary cards as the admin booking popup.
 * Only bookings assigned to this guide (current or original) are returned.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/booking_detail_cards.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$profile = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$gid = (int) $profile->fetchColumn();
if ($gid < 1) {
    json_response(['ok' => false, 'message' => 'Guide profile not found.'], 404);
}

$id = (int) ($_GET['id'] ?? 0);
$booking = booking_detail_fetch($id);
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}
$owns = (int) ($booking['guide_id'] ?? 0) === $gid
    || (int) ($booking['original_guide_id'] ?? 0) === $gid;
if (!$owns) {
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
