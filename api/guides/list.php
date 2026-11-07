<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/booking.php';
$user = require_api_user();
$in = api_input();

$date = trim((string) ($in['booking_date'] ?? $in['date'] ?? ''));
$date = normalize_tour_date($date) ?? '';
$timeRaw = trim((string) ($in['start_time'] ?? $in['time'] ?? ''));
$time = $timeRaw === '' || $timeRaw === '—' ? null : (normalize_time_input($timeRaw) ?: null);
$itineraryId = (int) ($in['itinerary_id'] ?? 0);
$viewerId = (int) ($user['id'] ?? 0);

$stmt = db()->query(
    "SELECT g.id, g.user_id, g.bio, g.languages, g.years_experience, g.hourly_rate, g.avg_rating,
            (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.guide_id = g.id) AS review_count,
            u.first_name, u.last_name, u.avatar, CONCAT(u.first_name,' ',u.last_name) AS full_name
     FROM tour_guide_profiles g
     JOIN users u ON u.id = g.user_id
     WHERE g.approval_status='approved' AND u.status='active'
     ORDER BY g.avg_rating DESC, u.first_name ASC"
);
$byId = [];
foreach ($stmt->fetchAll() ?: [] as $r) {
    if (!guide_profile_is_complete($r)) {
        continue;
    }
    if ($viewerId > 0 && booking_is_own_guide($viewerId, (int) $r['id'])) {
        continue;
    }
    $byId[(int) $r['id']] = $r;
}

$out = [];
if ($date !== '') {
    $endDate = $date;
    if ($itineraryId > 0) {
        [$spanStart, $spanEnd] = booking_resolve_tour_span($itineraryId, $date);
        if ($spanEnd) {
            $endDate = (string) $spanEnd;
        }
    }
    $ranked = tourist_book_guides_list(
        $date,
        $time,
        $endDate !== '' ? $endDate : $date,
        $itineraryId > 0 ? $itineraryId : null,
        null,
        $viewerId
    );
    foreach ($ranked as $pick) {
        $id = (int) ($pick['id'] ?? 0);
        if ($id < 1 || !isset($byId[$id])) {
            continue;
        }
        $row = $byId[$id];
        $row['available'] = !empty($pick['ok']);
        $row['hint'] = $pick['hint'] ?? null;
        $out[] = api_decorate_place($row, 'avatar');
    }
} else {
    foreach ($byId as $row) {
        $row['available'] = true;
        $row['hint'] = 'Set a tour date to check this guide’s availability.';
        $out[] = api_decorate_place($row, 'avatar');
    }
}

json_response(['ok' => true, 'data' => $out]);
