<?php
/**
 * Unread counts for live sidebar/topbar badges (session auth).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/live.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}
if (user_is_suspended($user)) {
    json_response(['ok' => false, 'message' => 'Your account is suspended.', 'suspended' => true], 403);
}

$uid = (int) $user['id'];
$role = (int) $user['role_id'];

$messages = 0;
if (in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
    $messages = unread_messages($uid);
}

$pendingBookings = 0;
if ($role === ROLE_TOUR_GUIDE) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([$uid]);
    $gid = (int) $gp->fetchColumn();
    if ($gid > 0) {
        $pendingBookings = guide_pending_booking_count($gid);
    }
}

$notif = live_header_notif_payload($uid, live_notif_return_url());

json_response([
    'ok' => true,
    'messages' => $messages,
    'notifications' => $notif['unread'],
    'pending_bookings' => $pendingBookings,
    'notif_html' => $notif['html'],
    'notif_version' => $notif['version'],
]);
