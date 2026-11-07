<?php
/**
 * Tourist sends a GCash number or QR for a refund the guide triggered.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOURIST);

$back = (string) ($_POST['return'] ?? '');
if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//')) {
    $back = APP_URL . '/tourist/bookings.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', 'Invalid or expired form. Please try again.');
    redirect($back);
}

$id = (int) ($_POST['booking_id'] ?? 0);
$st = db()->prepare('SELECT * FROM bookings WHERE id=? AND tourist_id=?');
$st->execute([$id, (int) $user['id']]);
$booking = $st->fetch();
if (!$booking) {
    flash('error', 'Booking not found.');
    redirect($back);
}

$res = refund_submit_details($booking, (int) $user['id'], (string) ($_POST['refund_number'] ?? ''), refund_opts_from($_POST));
flash($res['ok'] ? 'success' : 'error', $res['message']);
redirect($back);
