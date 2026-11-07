<?php
/**
 * Tour Guides browse page retired — booking happens from itineraries modal.
 * Keep POST for legacy clients; GET redirects into itineraries book flow.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOURIST);

$destinationId = (int) ($_GET['destination_id'] ?? 0);
$itineraryId = (int) ($_GET['itinerary_id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $guideProfileId = (int) ($_POST['guide_id'] ?? 0);
    $check = validate_booking_request(
        (int) $user['id'],
        $guideProfileId,
        (string) ($_POST['booking_date'] ?? ''),
        (string) ($_POST['start_time'] ?? '9:00 AM'),
        $_POST['party_size'] ?? null,
        $_POST['itinerary_id'] ?? null,
        $_POST['destination_id'] ?? ($destinationId ?: null)
    );
    if (!$check['ok']) {
        flash('error', $check['message']);
        $backId = (int) ($check['itinerary_id'] ?? $itineraryId);
        $parts = [];
        if ($backId) {
            $parts[] = 'id=' . $backId;
        }
        $parts[] = 'book=1';
        if ($destinationId) {
            $parts[] = 'destination_id=' . $destinationId;
        }
        redirect(APP_URL . '/tourist/itineraries.php?' . implode('&', $parts));
    }

    $acc = booking_parse_accessibility();
    if (!$acc['ok']) {
        flash('error', $acc['message']);
        $backId = (int) ($check['itinerary_id'] ?? $itineraryId);
        $parts = [];
        if ($backId) {
            $parts[] = 'id=' . $backId;
        }
        $parts[] = 'review=1';
        if ($destinationId) {
            $parts[] = 'destination_id=' . $destinationId;
        }
        redirect(APP_URL . '/tourist/itineraries.php?' . implode('&', $parts));
    }

    ensure_booking_payment_schema();
    require_once __DIR__ . '/../includes/payments.php';
    $code = booking_code();
    [$tourStart, $tourEnd] = booking_resolve_tour_span(
        $check['itinerary_id'] ? (int) $check['itinerary_id'] : null,
        (string) $check['date']
    );
    db()->prepare(
        'INSERT INTO bookings (booking_code, tourist_id, guide_id, itinerary_id, destination_id, booking_date, tour_start_date, tour_end_date, start_time, party_size, notes, status, payment_status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $code,
        $user['id'],
        $guideProfileId,
        $check['itinerary_id'],
        $check['destination_id'],
        $check['date'],
        $tourStart,
        $tourEnd,
        $check['time'],
        $check['party'],
        trim($_POST['notes'] ?? ''),
        'pending',
        'unpaid',
    ]);
    $bookingId = (int) db()->lastInsertId();
    booking_save_accessibility($bookingId, $acc);

    $pay = booking_start_gcash_payment(
        ['id' => $bookingId, 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_intent_id' => null],
        $check['guide']
    );
    if ($pay['ok'] && !empty($pay['checkout_url'])) {
        redirect($pay['checkout_url']);
    }

    flash('success', 'Booking created (Code: ' . $code . '). Your tour guide accepts it after payment.');
    redirect(APP_URL . '/tourist/bookings.php');
}

$qs = [];
if ($itineraryId) {
    $qs[] = 'id=' . $itineraryId;
}
$qs[] = 'book=1';
if ($destinationId) {
    $qs[] = 'destination_id=' . $destinationId;
}
redirect(APP_URL . '/tourist/itineraries.php?' . implode('&', $qs));
