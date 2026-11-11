<?php
/**
 * Guide suggested tours (Bearer). Same template rules as tourguide/itineraries.php.
 * GET → { published[], drafts[], draft_max } or ?id= → itinerary payload
 * POST { action, ... }
 */
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Tour guides only'], 403);
}
ensure_itinerary_items_schema();
ensure_place_schedule_schema();
$g = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$g->execute([(int) $user['id']]);
$gid = (int) $g->fetchColumn();
if ($gid < 1) {
    json_response(['ok' => false, 'message' => 'Guide profile not found.'], 404);
}
$uid = (int) $user['id'];

function api_guide_owns_itin(int $iid, int $gid): bool
{
    $own = db()->prepare('SELECT id FROM itineraries WHERE id=? AND created_by_guide_id=? AND is_suggested=1');
    $own->execute([$iid, $gid]);
    return (bool) $own->fetch();
}

function api_guide_blank_id(int $gid): int
{
    $stmt = db()->prepare(
        "SELECT i.id FROM itineraries i
         LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.id
         WHERE i.created_by_guide_id = ? AND i.is_suggested = 1
           AND (TRIM(COALESCE(i.title,'')) = '' OR LOWER(TRIM(i.title)) = 'unnamed')
         GROUP BY i.id HAVING COUNT(ii.id) = 0
         ORDER BY i.created_at DESC LIMIT 1"
    );
    $stmt->execute([$gid]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function api_guide_prune_blank(int $gid, int $keepId): void
{
    $stmt = db()->prepare(
        "SELECT i.id FROM itineraries i
         LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.id
         LEFT JOIN bookings b ON b.itinerary_id = i.id
         WHERE i.created_by_guide_id = ? AND i.is_suggested = 1
           AND (TRIM(COALESCE(i.title,'')) = '' OR LOWER(TRIM(i.title)) = 'unnamed')
         GROUP BY i.id HAVING COUNT(ii.id) = 0 AND COUNT(b.id) = 0"
    );
    $stmt->execute([$gid]);
    foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
        if ($id !== $keepId) {
            db()->prepare('DELETE FROM itineraries WHERE id=? AND created_by_guide_id=?')->execute([$id, $gid]);
        }
    }
}

function api_guide_itin_out(int $iid): array
{
    $row = mobile_itinerary_payload($iid);
    $row['published'] = $row ? itinerary_is_published_suggested($row) : false;
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        if (!api_guide_owns_itin($id, $gid)) {
            json_response(['ok' => false, 'message' => 'Itinerary not found'], 404);
        }
        json_response(['ok' => true, 'data' => api_guide_itin_out($id)]);
    }
    $mine = db()->prepare(
        'SELECT i.* FROM itineraries i
         WHERE i.created_by_guide_id=? AND i.is_suggested=1 AND ' . itinerary_sql_is_saved_tour('i') . '
         ORDER BY i.created_at DESC'
    );
    $mine->execute([$gid]);
    $published = [];
    $drafts = [];
    foreach ($mine->fetchAll() ?: [] as $r) {
        $r['stops'] = itinerary_items_with_places((int) $r['id']);
        $r['day_count'] = itinerary_logical_day_count($r);
        if (itinerary_is_published_suggested($r)) {
            $published[] = $r;
        } elseif (!empty($r['kept_as_draft'])) {
            $drafts[] = $r;
        }
    }
    json_response(['ok' => true, 'data' => ['published' => $published, 'drafts' => $drafts, 'draft_max' => itinerary_max_drafts('guide')]]);
}

$in = api_input();
$action = (string) ($in['action'] ?? '');
$iid = (int) ($in['itinerary_id'] ?? $in['id'] ?? 0);

if ($action === 'create') {
    $reuse = api_guide_blank_id($gid);
    if ($reuse > 0) {
        api_guide_prune_blank($gid, $reuse);
        json_response(['ok' => true, 'data' => api_guide_itin_out($reuse)]);
    }
    $meetup = normalize_time_input((string) ($in['meetup_time'] ?? '8:00 AM')) ?? '08:00';
    $title = trim((string) ($in['title'] ?? '')) ?: 'Unnamed';
    db()->prepare(
        'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, created_by_guide_id, status, kept_as_draft)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([$uid, $title, trim((string) ($in['description'] ?? '')), null, null, $meetup, 1, $gid, 'draft', 0]);
    $newId = (int) db()->lastInsertId();
    api_guide_prune_blank($gid, $newId);
    json_response(['ok' => true, 'data' => api_guide_itin_out($newId)], 201);
}

if ($action === 'auto_generate') {
    $meetup = normalize_time_input((string) ($in['meetup_time'] ?? '8:00 AM')) ?? '08:00';
    [$startDate, $endDate] = itinerary_dates_for_autogen($in);
    $title = trim((string) ($in['title'] ?? '')) ?: 'Ormoc auto tour';
    try {
        $result = auto_generate_itinerary_for_tourist(
            $uid,
            $title,
            trim((string) ($in['description'] ?? '')),
            $startDate,
            (string) $endDate,
            $meetup,
            null,
            ['guide_profile_id' => $gid]
        );
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage() ?: 'Could not auto-generate a tour.'], 400);
    }
    json_response(['ok' => true, 'warning' => $result['warning'] ?? null, 'data' => api_guide_itin_out((int) $result['id'])]);
}

if (!api_guide_owns_itin($iid, $gid)) {
    json_response(['ok' => false, 'message' => 'Itinerary not found'], 404);
}

if ($action === 'publish') {
    $title = trim((string) ($in['title'] ?? ''));
    if ($title !== '') {
        db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND created_by_guide_id=?')
            ->execute([mb_substr($title, 0, 120), $iid, $gid]);
    }
    $row = itinerary_row_by_id($iid);
    if (itinerary_is_placeholder_title($row['title'] ?? null)) {
        json_response(['ok' => false, 'message' => 'Give this tour a name before saving it for tourists.'], 400);
    }
    $cnt = db()->prepare('SELECT COUNT(*) FROM itinerary_items WHERE itinerary_id=?');
    $cnt->execute([$iid]);
    if ((int) $cnt->fetchColumn() < 1) {
        json_response(['ok' => false, 'message' => 'Add at least one stop before saving this suggested tour.'], 400);
    }
    db()->prepare(
        "UPDATE itineraries SET status='active', kept_as_draft=0, start_date=NULL, end_date=NULL
         WHERE id=? AND created_by_guide_id=? AND is_suggested=1"
    )->execute([$iid, $gid]);
    json_response(['ok' => true, 'message' => 'Suggested tour saved — tourists can now see and use it.', 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'save_draft') {
    if (itinerary_guard_new_draft($gid, 'guide', $iid) !== null) {
        json_response(['ok' => false, 'message' => 'You have reached the draft limit (' . itinerary_max_drafts('guide') . '). Delete a draft first.'], 400);
    }
    db()->prepare("UPDATE itineraries SET kept_as_draft=1, status='draft' WHERE id=? AND created_by_guide_id=?")->execute([$iid, $gid]);
    json_response(['ok' => true, 'message' => 'Tour saved as draft.', 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'update_title') {
    $title = trim((string) ($in['title'] ?? '')) ?: 'Unnamed';
    db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND created_by_guide_id=?')->execute([mb_substr($title, 0, 120), $iid, $gid]);
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'update_meetup') {
    $meetup = normalize_time_input((string) ($in['meetup_time'] ?? ''));
    if ($meetup === null) {
        json_response(['ok' => false, 'message' => 'Enter a valid meetup time (e.g. 8:00 AM).'], 400);
    }
    $dayCount = !empty($in['is_multiday']) ? max(2, min(7, (int) ($in['day_count'] ?? 2))) : 1;
    db()->prepare(
        'UPDATE itineraries SET meetup_time=?, start_date=NULL, end_date=NULL, template_day_count=? WHERE id=? AND created_by_guide_id=?'
    )->execute([$meetup, $dayCount, $iid, $gid]);
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'auto_regenerate') {
    $meetup = normalize_time_input((string) ($in['meetup_time'] ?? '8:00 AM')) ?? '08:00';
    $post = $in;
    if (empty($post['is_multiday'])) {
        $savedDays = itinerary_template_day_count(itinerary_row_by_id($iid));
        if ($savedDays > 1) {
            $post['is_multiday'] = '1';
            $post['day_count'] = (string) $savedDays;
        }
    }
    [$startDate, $endDate, $dayCount] = itinerary_dates_for_autogen($post);
    $row = itinerary_row_by_id($iid);
    $genTitle = trim((string) ($row['title'] ?? ''));
    if ($genTitle === '' || strcasecmp($genTitle, 'Unnamed') === 0) {
        $genTitle = $dayCount > 1 ? ('Ormoc ' . $dayCount . '-day tour') : 'Ormoc day tour';
    }
    $onlyDay = max(0, (int) ($in['day_number'] ?? 0));
    $opts = ['guide_profile_id' => $gid];
    if ($onlyDay > 0) {
        $onlyDay = max(1, min($dayCount, $onlyDay));
        $opts['only_day'] = $onlyDay;
        $opts['day_meetup'] = [$onlyDay => $meetup];
    }
    try {
        $result = auto_generate_itinerary_for_tourist(
            $uid,
            $genTitle,
            (string) ($row['description'] ?? ''),
            $startDate,
            (string) $endDate,
            $meetup,
            $iid,
            $opts
        );
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage() ?: 'Could not auto-generate this tour.'], 400);
    }
    json_response(['ok' => true, 'warning' => $result['warning'] ?? null, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'reorder_stops') {
    $order = $in['order'] ?? [];
    if (is_string($order)) {
        $order = json_decode($order, true) ?: [];
    }
    $err = is_array($order) ? itinerary_reorder_stops_and_reflow($iid, $order) : 'Invalid stop order.';
    if ($err !== null) {
        json_response(['ok' => false, 'message' => $err], 400);
    }
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'delete') {
    db()->prepare('DELETE FROM itineraries WHERE id=? AND created_by_guide_id=? AND is_suggested=1')->execute([$iid, $gid]);
    json_response(['ok' => true]);
}

if ($action === 'remove_stop') {
    db()->prepare('DELETE FROM itinerary_items WHERE id=? AND itinerary_id=?')->execute([(int) ($in['item_id'] ?? 0), $iid]);
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'clear_stops') {
    db()->prepare('DELETE FROM itinerary_items WHERE itinerary_id=?')->execute([$iid]);
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'update_stop_stay') {
    $err = itinerary_update_stop_stay_and_reflow_day($iid, (int) ($in['item_id'] ?? 0), (int) ($in['visit_minutes'] ?? 60));
    if ($err !== null) {
        json_response(['ok' => false, 'message' => $err], 400);
    }
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

if ($action === 'add_stop') {
    $ref = parse_itinerary_stop_ref($in);
    if (!$ref) {
        json_response(['ok' => false, 'message' => 'Choose a destination or business to add.'], 400);
    }
    $place = itinerary_place_coords($ref);
    if (!$place) {
        json_response(['ok' => false, 'message' => 'That place is not available.'], 404);
    }
    $noHoursMsg = itinerary_place_unavailable_message($ref, $place);
    if ($noHoursMsg !== null) {
        json_response(['ok' => false, 'message' => $noHoursMsg], 400);
    }
    $itinRow = itinerary_row_by_id($iid);
    $dayCount = itinerary_template_day_count($itinRow);
    $dayNum = max(1, min($dayCount, (int) ($in['day_number'] ?? 1)));
    $visitDate = $dayCount > 1 ? itinerary_template_visit_date($dayNum) : null;
    $dup = itinerary_duplicate_stop_status($iid, $ref, $visitDate);
    if (!empty($dup['adjacent'])) {
        json_response(['ok' => false, 'message' => 'You can’t add ' . ($dup['name'] ?? 'this place') . ' as the next stop — it’s already the last stop on this day. Add a different place in between.'], 400);
    }
    if (!empty($dup['exists']) && empty($in['allow_duplicate'])) {
        json_response(['ok' => false, 'duplicate' => true, 'message' => ($dup['name'] ?? 'That place') . ' is already on this tour.'], 409);
    }
    $itinForHours = $itinRow;
    $itinForHours['start_date'] = $visitDate;
    $prev = itinerary_prev_leg_context($iid, $itinRow, $visitDate);
    $place['opening_hours'] = null;
    $hasCoords = $place['lat'] != 0.0 || $place['lng'] != 0.0;
    $travelMins = !empty($prev['skip_travel'])
        ? 0
        : ($hasCoords ? itin_osrm_travel_minutes($prev['lat'], $prev['lng'], $place['lat'], $place['lng']) : 45);
    $visitMins = (int) ($in['visit_minutes'] ?? 60);
    if ($visitMins < 15) {
        json_response(['ok' => false, 'message' => 'Visit time must be at least 15 minutes.'], 400);
    }
    $visitMins = min($visitMins, ITIN_MAX_VISIT_MINUTES);
    $limitMsg = itinerary_visit_limit_message((string) ($place['name'] ?? ''), $place['visit_limit_minutes'] ?? null, $visitMins);
    if ($limitMsg !== null) {
        json_response(['ok' => false, 'message' => $limitMsg], 400);
    }
    $minMsg = itinerary_visit_min_message((string) ($place['name'] ?? ''), $place['min_visit_minutes'] ?? null, $visitMins);
    if ($minMsg !== null) {
        json_response(['ok' => false, 'message' => $minMsg], 400);
    }
    $newMins = itin_time_to_minutes(trim((string) ($in['start_time'] ?? '')));
    if ($newMins === null) {
        $newMins = !empty($prev['skip_travel'])
            ? (int) ($prev['suggest_mins'] ?? $prev['leave_mins'] ?? 8 * 60)
            : itin_suggest_arrival((int) $prev['leave_mins'], $travelMins);
    }
    $endMins = itin_compute_end($newMins, $visitMins);
    $blockMsg = itinerary_hard_block_message($itinForHours, $prev, $travelMins, $newMins, $endMins, $place);
    if ($blockMsg !== null) {
        json_response(['ok' => false, 'message' => $blockMsg], 400);
    }
    $max = db()->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM itinerary_items WHERE itinerary_id=?');
    $max->execute([$iid]);
    db()->prepare(
        'INSERT INTO itinerary_items
         (itinerary_id, destination_id, business_id, sort_order, visit_date, start_time, end_time, travel_minutes, visit_minutes)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $iid, $ref['destination_id'], $ref['business_id'], (int) $max->fetchColumn() + 1, $visitDate,
        itin_minutes_to_time($newMins), itin_minutes_to_time($endMins), $travelMins, $visitMins,
    ]);
    json_response(['ok' => true, 'data' => api_guide_itin_out($iid)]);
}

json_response(['ok' => false, 'message' => 'Unknown action'], 400);
