<?php
/**
 * Cheap live fingerprint for the Flutter app (Bearer).
 * Poll every few seconds — same idea as the website unread/map/booking polls.
 */
require_once __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
$uid = (int) $user['id'];
$role = (int) $user['role_id'];

$unreadMessages = 0;
if (in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true) && function_exists('unread_messages')) {
    $unreadMessages = unread_messages($uid);
}
$unreadNotifications = function_exists('unread_notifications') ? unread_notifications($uid) : 0;

$pendingBookings = 0;
if ($role === ROLE_TOURIST) {
    $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE tourist_id=? AND status='pending'");
    $st->execute([$uid]);
    $pendingBookings = (int) $st->fetchColumn();
} elseif ($role === ROLE_TOUR_GUIDE && function_exists('guide_pending_booking_count')) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([$uid]);
    $gid = (int) $gp->fetchColumn();
    if ($gid > 0) {
        $pendingBookings = guide_pending_booking_count($gid);
    }
}

$stamp = static function (string $sql, array $params = []): string {
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        return md5(json_encode($st->fetchAll(PDO::FETCH_NUM) ?: []));
    } catch (Throwable $e) {
        return '0';
    }
};

if ($role === ROLE_TOUR_GUIDE) {
    $gid = $gid ?? 0;
    if ($gid < 1) {
        $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
        $gp->execute([$uid]);
        $gid = (int) $gp->fetchColumn();
    }
    $bookingsVersion = $stamp(
        'SELECT id, status, payment_status FROM bookings WHERE guide_id=? OR original_guide_id=? ORDER BY id',
        [$gid, $gid]
    );
} else {
    $bookingsVersion = $stamp(
        'SELECT id, status, payment_status FROM bookings WHERE tourist_id=? ORDER BY id',
        [$uid]
    );
}

$itinerariesVersion = $stamp(
    'SELECT id, title, start_date, meetup_time FROM itineraries WHERE user_id=? AND is_suggested=0 ORDER BY id',
    [$uid]
);
$favoritesVersion = $stamp('SELECT destination_id FROM favorites WHERE user_id=? ORDER BY destination_id', [$uid]);
$notificationsVersion = $stamp(
    'SELECT id, is_read FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 80',
    [$uid]
);
$chatVersion = $stamp(
    'SELECT COALESCE(MAX(m.id),0), SUM(CASE WHEN m.sender_id<>? AND m.is_seen=0 THEN 1 ELSE 0 END)
     FROM conversations c
     LEFT JOIN messages m ON m.conversation_id=c.id
     WHERE c.tourist_id=? OR c.guide_id=?',
    [$uid, $uid, $uid]
);
$announcementsVersion = $stamp(
    "SELECT id, kind, title FROM announcements WHERE is_published=1 ORDER BY id DESC LIMIT 20"
);
$placesVersion = $stamp(
    "SELECT id, is_featured, status FROM destinations WHERE status='published' ORDER BY id"
);
$businessesVersion = $stamp(
    "SELECT id, status, approval_status FROM businesses WHERE status='active' AND approval_status='approved' ORDER BY id"
);

$userVersion = md5(implode('|', [
    (string) ($user['status'] ?? ''),
    (string) ($user['email'] ?? ''),
    (string) ($user['first_name'] ?? ''),
    (string) ($user['last_name'] ?? ''),
    (string) ($user['avatar'] ?? ''),
]));

$homeVersion = md5($favoritesVersion . $itinerariesVersion . $bookingsVersion . $announcementsVersion . $placesVersion);
$exploreVersion = md5($placesVersion . $businessesVersion);

$version = md5(implode('|', [
    (string) $unreadMessages,
    (string) $unreadNotifications,
    (string) $pendingBookings,
    $bookingsVersion,
    $itinerariesVersion,
    $favoritesVersion,
    $notificationsVersion,
    $chatVersion,
    $announcementsVersion,
    $placesVersion,
    $businessesVersion,
    $userVersion,
]));

$since = trim((string) ($_GET['since'] ?? ''));
if ($since !== '' && hash_equals($version, $since)) {
    json_response([
        'ok' => true,
        'changed' => false,
        'version' => $version,
    ]);
}

json_response([
    'ok' => true,
    'changed' => true,
    'version' => $version,
    'unread_messages' => $unreadMessages,
    'unread_notifications' => $unreadNotifications,
    'pending_bookings' => $pendingBookings,
    'bookings' => $bookingsVersion,
    'itineraries' => $itinerariesVersion,
    'favorites' => $favoritesVersion,
    'notifications' => $notificationsVersion,
    'chat' => $chatVersion,
    'announcements' => $announcementsVersion,
    'places' => $placesVersion,
    'businesses' => $businessesVersion,
    'explore' => $exploreVersion,
    'home' => $homeVersion,
    'user' => $userVersion,
    'status' => (string) ($user['status'] ?? ''),
]);
