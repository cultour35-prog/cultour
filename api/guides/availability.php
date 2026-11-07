<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
$in = api_input();
$guideId = (int) ($in['guide_id'] ?? 0);
$date = (string) ($in['booking_date'] ?? $in['date'] ?? '');
$time = normalize_time_input($in['start_time'] ?? $in['time'] ?? '9:00 AM') ?? '09:00';
$itineraryId = (int) ($in['itinerary_id'] ?? 0);
if ($guideId < 1 || $date === '') {
    json_response(['ok' => false, 'message' => 'guide_id and booking_date required'], 400);
}
$msg = guide_booking_availability_message(
    $guideId,
    $date,
    $time,
    null,
    $itineraryId > 0 ? $itineraryId : null
);
json_response([
    'ok' => true,
    'available' => $msg === null,
    'message' => $msg,
]);
