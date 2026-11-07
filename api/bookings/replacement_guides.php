<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
ensure_booking_payment_schema();

$id = (int) ($_GET['booking_id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    json_response(['ok' => false, 'message' => 'booking_id required'], 400);
}

$booking = db()->prepare('SELECT * FROM bookings WHERE id=?');
$booking->execute([$id]);
$booking = $booking->fetch();
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found'], 404);
}

$role = (int) $user['role_id'];
$allowed = false;
if ($role === ROLE_TOURIST && (int) $booking['tourist_id'] === (int) $user['id']) {
    $allowed = true;
} elseif ($role === ROLE_ADMIN) {
    $allowed = true;
}
if (!$allowed) {
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}

$guides = booking_list_replacement_guides($booking);
$out = [];
foreach ($guides as $g) {
    $row = $g;
    $row['full_name'] = trim((string) (($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? '')));
    if ($row['full_name'] === '') {
        $row['full_name'] = (string) ($g['name'] ?? $g['full_name'] ?? 'Tour guide');
    }
    $out[] = api_decorate_place($row, 'avatar');
}

json_response(['ok' => true, 'data' => $out]);
