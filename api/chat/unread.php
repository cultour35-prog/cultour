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
} elseif ($role === ROLE_BUSINESS) {
    require_once __DIR__ . '/../../includes/payments.php';
    $bizSt = db()->prepare(
        'SELECT b.id, bt.name FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id WHERE b.user_id=? LIMIT 1'
    );
    $bizSt->execute([$uid]);
    $bizRow = $bizSt->fetch();
    if ($bizRow && business_catalog_mode((string) ($bizRow['name'] ?? '')) === 'packages') {
        $pendingBookings = package_unseen_booking_count((int) $bizRow['id']);
    }
}

$notif = live_header_notif_payload($uid, live_notif_return_url());

$rejectNotice = null;
if ($role === ROLE_TOURIST && function_exists('revenue_ensure_schema')) {
    try {
        revenue_ensure_schema();
        $rejectSt = db()->prepare(
            "SELECT r.id, r.rejection_reason, r.itinerary_id, b.name AS business_name
             FROM restaurant_reservations r
             JOIN businesses b ON b.id = r.business_id
             JOIN itinerary_items ii ON ii.id = r.itinerary_item_id AND ii.business_id = r.business_id
             WHERE r.tourist_id=? AND r.status='rejected'
             ORDER BY r.id DESC LIMIT 1"
        );
        $rejectSt->execute([$uid]);
        $rejectRow = $rejectSt->fetch() ?: null;
        if ($rejectRow) {
            $reason = trim((string) ($rejectRow['rejection_reason'] ?? ''));
            $rejectNotice = [
                'id' => (int) $rejectRow['id'],
                'itinerary_id' => (int) $rejectRow['itinerary_id'],
                'business_name' => (string) $rejectRow['business_name'],
                'reason' => $reason !== '' ? $reason : 'No reason was given.',
                'url' => APP_URL . '/tourist/itineraries.php?id=' . (int) $rejectRow['itinerary_id'],
            ];
        }
    } catch (Throwable $e) {
        $rejectNotice = null;
    }
}

json_response([
    'ok' => true,
    'messages' => $messages,
    'notifications' => $notif['unread'],
    'pending_bookings' => $pendingBookings,
    'notif_html' => $notif['html'],
    'notif_version' => $notif['version'],
    'reject_notice' => $rejectNotice,
]);
