<?php
/**
 * Live tourist guide-availability poll for the Book a tour guide carousel.
 * GET itinerary_id&start_date&meetup_time&day_count|end_date&since=
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/booking.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$itinId = (int) ($_GET['itinerary_id'] ?? 0);
if ($itinId < 1) {
    json_response(['ok' => false, 'message' => 'itinerary_id required'], 400);
}

$own = db()->prepare('SELECT id, start_date, end_date, meetup_time, template_day_count FROM itineraries WHERE id=? AND user_id=? AND is_suggested=0');
$own->execute([$itinId, (int) $user['id']]);
$itin = $own->fetch();
if (!$itin) {
    json_response(['ok' => false, 'message' => 'Itinerary not found'], 404);
}

$startDate = normalize_tour_date((string) ($_GET['start_date'] ?? ''))
    ?: normalize_tour_date($itin['start_date'] ?? null);
$rawMeetup = trim((string) ($_GET['meetup_time'] ?? ''));
if ($rawMeetup === '') {
    $rawMeetup = trim((string) ($itin['meetup_time'] ?? ''));
}
$meetup = $rawMeetup !== '' ? (normalize_time_input($rawMeetup) ?? null) : null;

$endDate = normalize_tour_date((string) ($_GET['end_date'] ?? ''));
$dayCount = (int) ($_GET['day_count'] ?? 0);
$visitDates = itinerary_visit_dates_list($itinId, $itin);
if ($endDate === null && $visitDates) {
    $endDate = $visitDates[count($visitDates) - 1];
}
if ($endDate === null && $startDate !== null && $dayCount >= 2) {
    $endDate = itinerary_date_for_day($startDate, min(itinerary_max_days(), $dayCount));
}
if ($endDate === null && $startDate !== null) {
    $endDate = normalize_tour_date($itin['end_date'] ?? null) ?: $startDate;
}

$guides = tourist_book_guides_list(
    $startDate,
    $meetup,
    $endDate,
    $itinId,
    null,
    (int) $user['id']
);

$version = hash('sha256', json_encode($guides, JSON_UNESCAPED_UNICODE));
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
    'guides' => $guides,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'meetup_time' => $meetup ? (format_time_12h($meetup) ?: $meetup) : '',
]);
