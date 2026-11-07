<?php
/**
 * Tour-day check-in for guides (Bearer token). Same rules as tourguide/checkin.php.
 * GET  → today's board
 * POST { action: start_tour | checkin | skip_stop | end_tour, booking_id, itinerary_item_id?,
 *        reason_preset?, reason_detail?, visit_date? } + `photo` file for checkin (multipart)
 */
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Tour guides only'], 403);
}
ensure_booking_payment_schema();
ensure_booking_stop_checkin_schema();
$gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$gp->execute([(int) $user['id']]);
$gid = (int) $gp->fetchColumn();
if ($gid < 1) {
    json_response(['ok' => false, 'message' => 'Your tour guide profile is not ready yet.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => true, 'data' => guide_checkin_board_payload($gid)]);
}

$in = api_input();
$st = db()->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');
$st->execute([(int) ($in['booking_id'] ?? 0)]);
$booking = $st->fetch();
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}
$itemId = (int) ($in['itinerary_item_id'] ?? 0);
$action = (string) ($in['action'] ?? 'checkin');

if ($action === 'start_tour') {
    $res = booking_tour_start_submit($booking, $gid);
} elseif ($action === 'start_stop') {
    $res = booking_stop_start_submit($booking, $gid, $itemId);
} elseif ($action === 'skip_stop') {
    $res = booking_stop_skip_submit($booking, $gid, $itemId, (string) ($in['reason_preset'] ?? ''), (string) ($in['reason_detail'] ?? ''));
} elseif ($action === 'end_tour') {
    $res = booking_guide_end_day($booking, $gid, (string) ($in['visit_date'] ?? ''));
} else {
    $file = $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'name' => '', 'tmp_name' => ''];
    $res = booking_stop_checkin_submit(
        $booking,
        $gid,
        $itemId,
        is_array($file) ? $file : [],
        (string) ($in['ticket_scan'] ?? '')
    );
}

json_response(
    ['ok' => (bool) $res['ok'], 'message' => (string) $res['message'], 'data' => guide_checkin_board_payload($gid)],
    $res['ok'] ? 200 : 400
);
