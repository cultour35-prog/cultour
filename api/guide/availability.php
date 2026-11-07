<?php
/**
 * Guide weekly hours and blocked dates (Bearer).
 * GET → schedule. POST { action: weekly, days:[{day,on,start,end}] } | { action: block, block_date, reason } | { action: unblock, id }
 */
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Tour guides only'], 403);
}
$g = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$g->execute([(int) $user['id']]);
$gid = (int) $g->fetchColumn();
if ($gid < 1) {
    json_response(['ok' => false, 'message' => 'Guide profile not found.'], 404);
}
$dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

$payload = static function () use ($gid, $dayLabels): array {
    $by = guide_weekly_availability_by_day($gid);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $end = (string) ($by[$i]['end'] ?? '');
        $days[] = [
            'day' => $i,
            'label' => $dayLabels[$i],
            'on' => !empty($by[$i]['on']),
            'start' => (string) ($by[$i]['start'] ?? ''),
            'end' => $end,
            'cutoff' => guide_booking_cutoff_display($end) ?: '',
        ];
    }
    $blocks = db()->prepare('SELECT id, block_date, reason FROM guide_calendar_blocks WHERE guide_id=? ORDER BY block_date');
    $blocks->execute([$gid]);
    $list = [];
    foreach ($blocks->fetchAll() ?: [] as $b) {
        $list[] = [
            'id' => (int) $b['id'],
            'date' => (string) $b['block_date'],
            'label' => format_date($b['block_date']),
            'reason' => (string) ($b['reason'] ?? ''),
        ];
    }
    return ['has_schedule' => guide_has_weekly_availability($gid), 'days' => $days, 'blocks' => $list];
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => true, 'data' => $payload()]);
}

$in = api_input();
$action = (string) ($in['action'] ?? '');

if ($action === 'weekly') {
    $post = ['day' => [], 'day_start' => [], 'day_end' => []];
    foreach ((array) ($in['days'] ?? []) as $d) {
        $i = (int) ($d['day'] ?? -1);
        if ($i < 0 || $i > 6 || empty($d['on'])) {
            continue;
        }
        $post['day'][] = (string) $i;
        $post['day_start'][$i] = (string) ($d['start'] ?? '');
        $post['day_end'][$i] = (string) ($d['end'] ?? '');
    }
    $result = guide_save_weekly_availability_from_post($gid, $post, $dayLabels);
    if (!empty($result['errors'])) {
        json_response(['ok' => false, 'message' => implode(' ', $result['errors']), 'data' => $payload()], 400);
    }
    $msg = ($result['saved'] ?? 0) < 1
        ? 'Availability cleared. Select at least one day so tourists can book you.'
        : 'Weekly availability saved. Pending requests still need Accept or Decline on Bookings.';
    json_response(['ok' => true, 'message' => $msg, 'data' => $payload()]);
}

if ($action === 'block') {
    $blockDate = normalize_tour_date((string) ($in['block_date'] ?? ''));
    if ($blockDate === null) {
        json_response(['ok' => false, 'message' => 'Choose a valid date to block.'], 400);
    }
    $reason = trim((string) ($in['reason'] ?? ''));
    db()->prepare('INSERT INTO guide_calendar_blocks (guide_id, block_date, reason) VALUES (?,?,?)')
        ->execute([$gid, $blockDate, $reason !== '' ? $reason : 'Unavailable']);
    json_response(['ok' => true, 'message' => 'Date blocked.', 'data' => $payload()]);
}

if ($action === 'unblock') {
    db()->prepare('DELETE FROM guide_calendar_blocks WHERE id=? AND guide_id=?')->execute([(int) ($in['id'] ?? 0), $gid]);
    json_response(['ok' => true, 'message' => 'Date unblocked.', 'data' => $payload()]);
}

json_response(['ok' => false, 'message' => 'Unknown action'], 400);
