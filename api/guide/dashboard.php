<?php
/**
 * Guide home (Bearer): stats, upcoming tours and ratings.
 */
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Tour guides only'], 403);
}
require_once __DIR__ . '/../../includes/live.php';
ensure_booking_payment_schema();
$p = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
$p->execute([(int) $user['id']]);
$profile = $p->fetch() ?: [];
$gid = (int) ($profile['id'] ?? 0);
if ($gid < 1) {
    json_response(['ok' => false, 'message' => 'Guide profile not found.'], 404);
}

$s = db()->prepare("SELECT COUNT(*) FROM bookings WHERE guide_id=? AND status='approved' AND booking_date >= CURDATE()");
$s->execute([$gid]);
$upcomingCount = (int) $s->fetchColumn();
$s = db()->prepare("SELECT COUNT(*) FROM bookings WHERE guide_id=? AND status='completed'");
$s->execute([$gid]);
$completedCount = (int) $s->fetchColumn();

$u = db()->prepare(
    "SELECT b.id, b.booking_date, b.start_time, b.status, b.booking_code, b.party_size,
            b.guide_id, b.original_guide_id, b.replacement_from_date, b.needs_replacement,
            b.tour_start_date, b.tour_end_date, b.itinerary_id,
            i.title AS itinerary_title, i.start_date AS itin_start, i.end_date AS itin_end,
            CONCAT(tu.first_name,' ',tu.last_name) AS tourist_name
     FROM bookings b
     JOIN users tu ON tu.id = b.tourist_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     WHERE (b.guide_id=? OR b.original_guide_id=?) AND b.status = 'approved'
       AND DATE(COALESCE(b.tour_end_date, i.end_date, b.tour_start_date, i.start_date, b.booking_date)) >= CURDATE()
     ORDER BY COALESCE(b.tour_start_date, i.start_date, b.booking_date), b.start_time
     LIMIT 12"
);
$u->execute([$gid, $gid]);
$upcoming = [];
foreach ($u->fetchAll() ?: [] as $row) {
    $keep = false;
    foreach (booking_remaining_visit_dates($row) as $d) {
        if (booking_guide_covers_date($row, $gid, $d)) {
            $keep = true;
            break;
        }
    }
    $tone = booking_guide_schedule_tone($row, $gid);
    if (!$keep || $tone === 'completed') {
        continue;
    }
    $start = !empty($row['itin_start']) ? (string) $row['itin_start'] : (string) $row['booking_date'];
    $end = !empty($row['itin_end']) ? (string) $row['itin_end'] : $start;
    $days = itinerary_day_count($start, $end);
    $upcoming[] = [
        'booking_id' => (int) $row['id'],
        'booking_code' => (string) $row['booking_code'],
        'title' => trim((string) ($row['itinerary_title'] ?? '')) ?: ('Tour with ' . ($row['tourist_name'] ?? 'tourist')),
        'tourist' => (string) ($row['tourist_name'] ?? ''),
        'date_label' => format_date($start) . ($days > 1 ? ' – ' . format_date($end) . ' · ' . $days . ' days' : ' · Day 1'),
        'meetup' => format_time_12h($row['start_time'] ?? null),
        'tone' => $tone,
        'tone_label' => booking_guide_schedule_tone_label($tone),
    ];
    if (count($upcoming) >= 6) {
        break;
    }
}

$ratings = [];
foreach (live_guide_ratings_rows($gid) as $r) {
    $ratings[] = [
        'rating_id' => (int) $r['rating_id'],
        'rating' => (int) $r['rating'],
        'comment' => (string) ($r['comment'] ?? ''),
        'rated_at' => (string) ($r['rated_at'] ?? ''),
        'tourist' => (string) ($r['tourist_name'] ?? ''),
        'booking_id' => (int) $r['booking_id'],
        'booking_code' => (string) $r['booking_code'],
        'title' => (string) $r['_title'],
        'date_label' => format_date($r['booking_date'] ?? null),
    ];
}

json_response([
    'ok' => true,
    'data' => [
        'stats' => [
            'pending' => guide_pending_booking_count($gid),
            'upcoming' => $upcomingCount,
            'completed' => $completedCount,
            'rating' => (string) ($profile['avg_rating'] ?? '0.00'),
            'total_tours' => (int) ($profile['total_tours'] ?? 0),
        ],
        'has_schedule' => guide_has_weekly_availability($gid),
        'approval_status' => (string) ($profile['approval_status'] ?? ''),
        'upcoming' => $upcoming,
        'ratings' => $ratings,
    ],
]);
