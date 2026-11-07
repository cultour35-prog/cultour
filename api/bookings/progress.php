<?php
/**
 * Check-in progress for one booking (Bearer token): the same data as the web "View details" modal.
 * GET ?booking_id=
 * Photo URLs need the same Bearer header (stop-checkin-photo.php accepts it).
 */
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
ensure_booking_stop_checkin_schema();
$id = (int) ($_GET['booking_id'] ?? $_GET['id'] ?? 0);
$st = db()->prepare(
    "SELECT b.*, i.title AS itinerary_title,
            CONCAT(gu.first_name,' ',gu.last_name) AS guide_name,
            CONCAT(tu.first_name,' ',tu.last_name) AS tourist_name
     FROM bookings b
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     LEFT JOIN tour_guide_profiles gp ON gp.id = b.guide_id
     LEFT JOIN users gu ON gu.id = gp.user_id
     LEFT JOIN users tu ON tu.id = b.tourist_id
     WHERE b.id=? LIMIT 1"
);
$st->execute([$id]);
$booking = $st->fetch();
if (!$booking || !booking_user_can_view_stop_checkin($user, $booking)) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}

json_response(['ok' => true, 'data' => booking_progress_payload($booking)]);
