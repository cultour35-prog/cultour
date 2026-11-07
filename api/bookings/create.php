<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
$in = api_input();
$quoteOnly = (string) ($in['action'] ?? '') === 'quote';
$guideId = (int) ($in['guide_id'] ?? 0);

$check = validate_booking_request(
    (int) $user['id'],
    $guideId,
    (string) ($in['booking_date'] ?? ''),
    (string) ($in['start_time'] ?? '9:00 AM'),
    $in['party_size'] ?? null,
    $in['itinerary_id'] ?? null,
    $in['destination_id'] ?? null
);
if (!$check['ok']) {
    json_response(['ok' => false, 'message' => $check['message']], 400);
}

$acc = booking_parse_accessibility($in, $_FILES, true);
if (!$acc['ok']) {
    json_response([
        'ok' => false,
        'message' => $acc['message'],
        'website_url' => rtrim(APP_URL, '/') . '/tourist/itineraries.php',
    ], 400);
}

$stay = [
    'accommodation_business_id' => null,
    'accommodation_service_id' => null,
    'accommodation_declined' => 0,
    'transportation_business_id' => null,
    'transportation_service_id' => null,
    'transportation_declined' => 0,
];
if (!empty($check['itinerary_id'])) {
    ensure_itinerary_items_schema();
    $savedStay = itinerary_row_by_id((int) $check['itinerary_id']);
    if (is_array($savedStay)) {
        if (!array_key_exists('accommodation_lines', $in)) {
            $savedLines = stay_lines_list($savedStay['accommodation_lines'] ?? null);
            if (is_array($savedLines) && $savedLines !== []) {
                $in['accommodation_lines'] = $savedLines;
            }
        }
        if (!array_key_exists('transportation_lines', $in)) {
            $savedRide = stay_lines_list($savedStay['transportation_lines'] ?? null);
            if (is_array($savedRide) && $savedRide !== []) {
                $in['transportation_lines'] = $savedRide;
            }
        }
    }
    $stay = stay_ride_parse($in);
    if (!$stay['ok']) {
        json_response(['ok' => false, 'message' => $stay['message']], 400);
    }
    [$stockStart, $stockEnd] = booking_resolve_tour_span((int) $check['itinerary_id'], (string) $check['date']);
    $stockMsg = stay_ride_room_stock_message(
        $stay,
        $stockStart,
        $stockEnd,
        null,
        (int) $check['party'],
        (int) $check['itinerary_id']
    );
    if ($stockMsg !== null) {
        json_response(['ok' => false, 'message' => $stockMsg], 400);
    }
    if (!$quoteOnly) {
        stay_ride_save_itinerary((int) $check['itinerary_id'], (int) $user['id'], $stay);
    }
}

ensure_booking_payment_schema();
[$tourStart, $tourEnd] = booking_resolve_tour_span(
    $check['itinerary_id'] ? (int) $check['itinerary_id'] : null,
    (string) $check['date']
);
$charge = booking_charge_for_stay(
    $stay,
    (int) $check['party'],
    $tourStart,
    $tourEnd,
    (float) ($check['guide']['hourly_rate'] ?? 0),
    $check['itinerary_id'] ? (int) $check['itinerary_id'] : null
);
if (!$charge['ok']) {
    json_response(['ok' => false, 'message' => $charge['message']], 400);
}
if ($quoteOnly) {
    json_response([
        'ok' => true,
        'guide_amount' => $charge['guide_amount'],
        'accommodation_amount' => $charge['accommodation_amount'],
        'transportation_amount' => $charge['transportation_amount'],
        'payment_amount' => $charge['payment_amount'],
        'guide_amount_label' => format_money($charge['guide_amount']),
        'accommodation_amount_label' => format_money($charge['accommodation_amount']),
        'transportation_amount_label' => format_money($charge['transportation_amount']),
        'payment_amount_label' => format_money($charge['payment_amount']),
    ]);
}
$code = booking_code();
$stay['accommodation_lines'] = $charge['accommodation_lines'] ?? [];
$stay['transportation_lines'] = $charge['transportation_lines'] ?? [];
if (!empty($check['itinerary_id'])) {
    stay_ride_save_itinerary((int) $check['itinerary_id'], (int) $user['id'], $stay);
}
$bid = booking_insert_pending([
    'booking_code' => $code,
    'tourist_id' => $user['id'],
    'guide_id' => $guideId,
    'itinerary_id' => $check['itinerary_id'],
    'destination_id' => $check['destination_id'],
    'booking_date' => $check['date'],
    'tour_start_date' => $tourStart,
    'tour_end_date' => $tourEnd,
    'start_time' => $check['time'],
    'party_size' => $check['party'],
    'notes' => $in['notes'] ?? null,
    'accommodation_business_id' => $stay['accommodation_business_id'],
    'accommodation_service_id' => $stay['accommodation_service_id'],
    'accommodation_declined' => (int) $stay['accommodation_declined'],
    'accommodation_units' => $charge['accommodation_units'],
    'accommodation_extra_guests' => $charge['accommodation_extra_guests'],
    'accommodation_amount' => $charge['accommodation_amount'],
    'accommodation_lines' => !empty($charge['accommodation_lines'])
        ? json_encode($charge['accommodation_lines'], JSON_UNESCAPED_UNICODE)
        : null,
    'transportation_business_id' => $stay['transportation_business_id'],
    'transportation_service_id' => $stay['transportation_service_id'],
    'transportation_declined' => (int) $stay['transportation_declined'],
    'transportation_units' => $charge['transportation_units'],
    'transportation_extra_guests' => $charge['transportation_extra_guests'],
    'transportation_amount' => $charge['transportation_amount'],
    'transportation_lines' => !empty($charge['transportation_lines'])
        ? json_encode($charge['transportation_lines'], JSON_UNESCAPED_UNICODE)
        : null,
    'guide_amount' => $charge['guide_amount'],
    'payment_amount' => $charge['payment_amount'],
    'status' => 'pending',
    'payment_status' => 'unpaid',
]);
booking_save_accessibility($bid, $acc);
$booking = db()->prepare('SELECT * FROM bookings WHERE id=?');
$booking->execute([$bid]);
$booking = $booking->fetch();

$guideUserId = (int) ($check['guide']['guide_user_id'] ?? $check['guide']['user_id'] ?? 0);
if ($guideUserId) {
    notify_user(
        $guideUserId,
        'booking_request',
        'New booking request',
        'Booking ' . $code . booking_accessibility_notify_line($acc),
        APP_URL . '/tourguide/bookings.php'
    );
}

json_response([
    'ok' => true,
    'booking_code' => $code,
    'booking' => [
        'id' => $bid,
        'booking_code' => $code,
        'status' => (string) ($booking['status'] ?? 'pending'),
        'payment_status' => (string) ($booking['payment_status'] ?? 'unpaid'),
    ],
]);
