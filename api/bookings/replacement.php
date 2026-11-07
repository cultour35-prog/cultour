<?php
/**
 * Replacement-guide pop-up for the mobile app (Bearer token).
 * GET  → { ok, popup: null | {...} }
 * POST → action = guide_accept | guide_decline (offer_id)
 *                 tourist_accept | tourist_other | tourist_move (booking_id, new_start)
 */
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
$role = (int) $user['role_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    booking_replacement_sweep();
    json_response(['ok' => true, 'popup' => booking_replacement_popup_data($user)]);
}

$in = api_input();
$action = (string) ($in['action'] ?? '');

if ($role === ROLE_TOUR_GUIDE && in_array($action, ['guide_accept', 'guide_decline'], true)) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([(int) $user['id']]);
    $res = booking_replacement_guide_respond(
        (int) ($in['offer_id'] ?? 0),
        (int) $gp->fetchColumn(),
        $action === 'guide_accept'
    );
    json_response($res, $res['ok'] ? 200 : 400);
}

if ($role === ROLE_TOURIST && in_array($action, ['tourist_accept', 'tourist_other', 'tourist_move'], true)) {
    $st = db()->prepare('SELECT * FROM bookings WHERE id=? AND tourist_id=? LIMIT 1');
    $st->execute([(int) ($in['booking_id'] ?? $in['id'] ?? 0), (int) $user['id']]);
    $booking = $st->fetch();
    if (!$booking) {
        json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
    }
    if ($action === 'tourist_accept') {
        $res = booking_replacement_tourist_accept($booking, (int) $user['id']);
    } elseif ($action === 'tourist_other') {
        $res = booking_replacement_tourist_other($booking);
    } else {
        $res = booking_replacement_move_dates($booking, (string) ($in['new_start'] ?? ''));
    }
    json_response($res, $res['ok'] ? 200 : 400);
}

json_response(['ok' => false, 'message' => 'That action is not available.'], 400);
