<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/qr.php';
$user = require_api_user();
ensure_booking_payment_schema();
$in = api_input();
$id = (int) ($in['booking_id'] ?? $in['id'] ?? 0);
$action = (string) ($in['action'] ?? '');
if ($id < 1 || $action === '') {
    json_response(['ok' => false, 'message' => 'booking_id and action required'], 400);
}

$booking = db()->prepare('SELECT * FROM bookings WHERE id=?');
$booking->execute([$id]);
$booking = $booking->fetch();
if (!$booking) {
    json_response(['ok' => false, 'message' => 'Booking not found'], 404);
}

$role = (int) $user['role_id'];
if ($role === ROLE_TOUR_GUIDE) {
    $g = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $g->execute([$user['id']]);
    $gid = (int) $g->fetchColumn();
    if ($gid !== (int) $booking['guide_id'] && $gid !== (int) ($booking['original_guide_id'] ?? 0)) {
        json_response(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    if ($gid !== (int) $booking['guide_id'] && in_array($action, ['approve', 'decline', 'complete', 'pay_at_office', 'withdraw', 'cancel'], true)) {
        json_response(['ok' => false, 'message' => 'This booking is no longer assigned to you'], 403);
    }
    ensure_booking_payment_schema();

    if ($action === 'approve') {
        $res = booking_guide_approve($booking, $user);
        json_response($res, $res['ok'] ? 200 : 409);
    }
    if ($action === 'decline') {
        $preset = (string) ($in['reason_preset'] ?? $in['reason'] ?? '');
        $detail = (string) ($in['reason_detail'] ?? '');
        // If client sends free-text only as reason, treat as Other
        if ($preset !== '' && !in_array($preset, booking_decline_reason_presets(), true)) {
            $detail = $preset;
            $preset = 'Other';
        }
        $res = booking_guide_decline($booking, $preset, $detail);
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'complete') {
        $res = booking_guide_complete($booking, $gid);
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'pay_at_office') {
        $res = booking_mark_payment($booking, 'pay_at_office');
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'withdraw') {
        $res = booking_guide_withdraw(
            $booking,
            (string) ($in['from_date'] ?? $in['from'] ?? ''),
            (string) ($in['reason_preset'] ?? $in['reason'] ?? ''),
            (string) ($in['reason_detail'] ?? '')
        );
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'cancel') {
        $preset = (string) ($in['reason_preset'] ?? $in['reason'] ?? '');
        $detail = (string) ($in['reason_detail'] ?? '');
        if ($preset !== '' && !in_array($preset, booking_withdraw_reason_presets(), true)) {
            $detail = $preset;
            $preset = 'Other';
        }
        $res = booking_guide_cancel_approved($booking, $preset, $detail);
        json_response($res, $res['ok'] ? 200 : 400);
    }
    json_response(['ok' => false, 'message' => 'Invalid action for this booking'], 400);
}

if ($role === ROLE_TOURIST) {
    if ((int) $booking['tourist_id'] !== (int) $user['id']) {
        json_response(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    if ($action === 'cancel') {
        $res = booking_tourist_cancel($booking, (string) ($in['refund_number'] ?? ''), refund_opts_from($in));
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'replacement_accept') {
        $res = booking_replacement_tourist_accept($booking, (int) $user['id']);
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'replacement_other') {
        $res = booking_replacement_tourist_other($booking);
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'replacement_move') {
        $res = booking_replacement_move_dates($booking, (string) ($in['new_start'] ?? ''));
        json_response($res, $res['ok'] ? 200 : 400);
    }
    if ($action === 'rate') {
        $res = booking_tourist_rate(
            $booking,
            (int) $user['id'],
            $in['rating'] ?? 5,
            (string) ($in['comment'] ?? '')
        );
        json_response($res, $res['ok'] ? 200 : 400);
    }
    json_response(['ok' => false, 'message' => 'Invalid action for this booking'], 400);
}

if ($role === ROLE_ADMIN) {
    if ($action === 'assign_replacement') {
        $res = booking_assign_replacement(
            $booking,
            (int) ($in['replacement_guide_id'] ?? $in['guide_id'] ?? 0),
            (int) $user['id']
        );
        json_response($res, $res['ok'] ? 200 : 400);
    }
    json_response(['ok' => false, 'message' => 'Invalid action for this booking'], 400);
}

json_response(['ok' => false, 'message' => 'Forbidden'], 403);
