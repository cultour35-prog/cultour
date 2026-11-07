<?php
/**
 * Guide month calendar (Bearer). GET ?month=YYYY-MM
 * Returns { month, month_label, prev, next, today, dates: { 'Y-m-d': [event...] } }
 */
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Tour guides only'], 403);
}
ensure_booking_payment_schema();
ensure_itinerary_items_schema();
ensure_booking_stop_checkin_schema();
$g = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$g->execute([(int) $user['id']]);
$gid = (int) $g->fetchColumn();

$ym = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
$monthStart = $ym . '-01';
$monthTs = strtotime($monthStart . ' 12:00:00');
$rangeFrom = date('Y-m-d', strtotime($monthStart . ' -14 days'));
$rangeTo = date('Y-m-d', strtotime($monthStart . ' +1 month +14 days'));

$stmt = db()->prepare(
    "SELECT b.id, b.booking_code, b.booking_date, b.tour_start_date, b.tour_end_date, b.start_time, b.status, b.party_size, b.itinerary_id,
            b.guide_id, b.original_guide_id, b.replacement_from_date, b.needs_replacement, b.is_pwd,
            i.title AS itinerary_title, CONCAT(u.first_name,' ',u.last_name) AS tourist_name
     FROM bookings b
     JOIN users u ON u.id = b.tourist_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     WHERE (b.guide_id = ? OR b.original_guide_id = ?)
       AND b.status IN ('approved','completed')
       AND DATE(COALESCE(b.tour_start_date, i.start_date, b.booking_date)) <= ?
       AND DATE(COALESCE(b.tour_end_date, b.tour_start_date, i.end_date, i.start_date, b.booking_date)) >= ?
     ORDER BY COALESCE(b.tour_start_date, i.start_date, b.booking_date), b.start_time"
);
$stmt->execute([$gid, $gid, $rangeTo, $rangeFrom]);
$bookings = $stmt->fetchAll() ?: [];

$ended = [];
if ($bookings) {
    $ids = array_map(static fn ($b) => (int) $b['id'], $bookings);
    try {
        $st = db()->prepare('SELECT booking_id, visit_date FROM booking_day_ends WHERE booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $er) {
            $d = normalize_tour_date((string) ($er['visit_date'] ?? ''));
            if ($d !== null) {
                $ended[(int) $er['booking_id'] . '|' . $d] = true;
            }
        }
    } catch (Throwable $e) {
        $ended = [];
    }
}

$today = app_today();
$byDate = [];
foreach ($bookings as $b) {
    [$start, $end] = booking_effective_tour_span($b);
    $itinId = (int) ($b['itinerary_id'] ?? 0);
    $dates = $itinId > 0 ? itinerary_visit_dates_list($itinId, itinerary_row_by_id($itinId) ?: null) : [];
    if (!$dates) {
        for ($d = 1, $n = itinerary_day_count($start, $end); $d <= $n; $d++) {
            $x = itinerary_date_for_day($start, $d);
            if ($x !== null) {
                $dates[] = $x;
            }
        }
    }
    $dayCount = max(1, count($dates));
    $title = trim((string) ($b['itinerary_title'] ?? '')) ?: ('Tour with ' . trim((string) ($b['tourist_name'] ?? 'tourist')));
    $status = (string) $b['status'];
    $bid = (int) $b['id'];
    $covered = array_values(array_filter($dates, static fn ($d) => $d && booking_guide_covers_date($b, $gid, $d)));
    $endedCount = 0;
    $started = false;
    foreach ($covered as $d) {
        if ($status === 'completed' || isset($ended[$bid . '|' . $d])) {
            $endedCount++;
        }
        if ($d <= $today) {
            $started = true;
        }
    }
    if ($status === 'completed' || ($covered !== [] && $endedCount === count($covered))) {
        $tone = 'completed';
    } elseif ($started || $endedCount > 0) {
        $tone = 'ongoing';
    } else {
        $tone = 'upcoming';
    }
    foreach ($dates as $di => $date) {
        if (!$date || substr($date, 0, 7) !== $ym || !booking_guide_covers_date($b, $gid, $date)) {
            continue;
        }
        $byDate[$date][] = [
            'booking_id' => $bid,
            'booking_code' => (string) $b['booking_code'],
            'itinerary_id' => $itinId,
            'title' => $title,
            'day' => $di + 1,
            'day_count' => $dayCount,
            'status' => $status,
            'tone' => $tone,
            'day_ended' => $status === 'completed' || isset($ended[$bid . '|' . $date]),
            'tourist' => (string) ($b['tourist_name'] ?? ''),
            'time' => format_time_12h($b['start_time'] ?? null),
            'busy_label' => booking_busy_window_label(booking_busy_window_for_date($b, $date)),
            'party_size' => (int) ($b['party_size'] ?? 1),
            'is_pwd' => booking_is_pwd($b),
            'can_checkin' => $date === $today && $status === 'approved',
        ];
    }
}
ksort($byDate);

json_response([
    'ok' => true,
    'data' => [
        'month' => $ym,
        'month_label' => date('F Y', $monthTs),
        'first_dow' => (int) date('w', $monthTs),
        'days_in_month' => (int) date('t', $monthTs),
        'prev' => date('Y-m', strtotime($monthStart . ' -1 month')),
        'next' => date('Y-m', strtotime($monthStart . ' +1 month')),
        'today' => $today,
        'dates' => (object) $byDate,
    ],
]);
