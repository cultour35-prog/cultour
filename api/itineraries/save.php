<?php
/**
 * Tourist itinerary maker (Bearer token).
 * POST JSON: { action, ... }
 */
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
ensure_itinerary_items_schema();

$in = api_input();
$action = (string) ($in['action'] ?? '');
$uid = (int) $user['id'];

function api_itin_payload(int $id): array
{
    return mobile_itinerary_payload($id);
}

function api_itin_owned(int $id, int $uid): bool
{
    if ($id < 1) {
        return false;
    }
    $own = db()->prepare('SELECT id FROM itineraries WHERE id=? AND user_id=? AND is_suggested=0');
    $own->execute([$id, $uid]);
    return (bool) $own->fetch();
}

function api_itin_locked(int $id): void
{
    if ($id > 0 && itinerary_schedule_is_locked($id)) {
        json_response(['ok' => false, 'message' => 'This tour is booked and can no longer be edited.'], 409);
    }
}

if ($action === 'create') {
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') {
        $title = 'Unnamed';
    }
    $meetupRaw = trim((string) ($in['meetup_time'] ?? ''));
    $meetup = $meetupRaw === '' ? null : (normalize_time_input($meetupRaw) ?? null);
    [$startDate, $endDate, $createDays] = itinerary_resolve_dates_from_post($in);
    if ($startDate !== null) {
        if ($meetup === null) {
            json_response(['ok' => false, 'message' => 'Enter a meetup time before setting a tour date.'], 400);
        }
        $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
        if ($pastMsg !== null) {
            json_response(['ok' => false, 'message' => $pastMsg], 400);
        }
    }
    if ($title !== '' && itinerary_is_placeholder_title($title) && $startDate === null) {
        $reuseId = itinerary_find_blank_draft_id($uid);
        if ($reuseId > 0) {
            json_response(['ok' => true, 'data' => api_itin_payload($reuseId)]);
        }
    }
    db()->prepare(
        'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, status)
         VALUES (?,?,?,?,?,?,0,?)'
    )->execute([$uid, $title, trim((string) ($in['description'] ?? '')), $startDate, $endDate, $meetup, 'draft']);
    $iid = (int) db()->lastInsertId();
    if ($createDays > 1) {
        itinerary_apply_logical_day_count($iid, $createDays);
    }
    json_response(['ok' => true, 'data' => api_itin_payload($iid)], 201);
}

if ($action === 'clone') {
    $srcId = (int) ($in['id'] ?? $in['itinerary_id'] ?? 0);
    $src = db()->prepare('SELECT * FROM itineraries WHERE id=?');
    $src->execute([$srcId]);
    $srcRow = $src->fetch();
    if (!$srcRow || !itinerary_is_published_suggested($srcRow)) {
        json_response(['ok' => false, 'message' => 'Suggested tour not found'], 404);
    }
    try {
        $newId = clone_itinerary_for_tourist($srcId, $uid);
        json_response(['ok' => true, 'data' => api_itin_payload($newId)]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage() ?: 'Could not copy that tour.'], 400);
    }
}

if ($action === 'auto_generate') {
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') {
        $title = 'Ormoc auto tour';
    }
    $meetupRaw = trim((string) ($in['meetup_time'] ?? '8:00 AM'));
    $meetup = normalize_time_input($meetupRaw) ?? '08:00';
    [$startDate, $endDate, $genDays, $usedDefault] = itinerary_dates_for_autogen($in);
    if (!$usedDefault) {
        $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
        if ($pastMsg !== null) {
            json_response(['ok' => false, 'message' => $pastMsg], 400);
        }
    }
    try {
        $result = auto_generate_itinerary_for_tourist(
            $uid,
            $title,
            trim((string) ($in['description'] ?? '')),
            $startDate,
            (string) $endDate,
            $meetup,
            null,
            ['keep_undated' => !empty($usedDefault), 'day_meetup' => itinerary_posted_day_meetup_times($in)]
        );
        json_response([
            'ok' => true,
            'warning' => $result['warning'] ?? null,
            'data' => api_itin_payload((int) $result['id']),
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage() ?: 'Could not auto-generate a tour.'], 400);
    }
}

$iid = (int) ($in['itinerary_id'] ?? $in['id'] ?? 0);
if (in_array($action, [
    'update_title', 'update_meetup', 'add_stop', 'remove_stop', 'reorder_stops', 'update_stop_stay', 'delete', 'save_draft',
    'update_party_size', 'update_draft_guide', 'update_stay_ride', 'update_day_date', 'update_day_meetup', 'auto_regenerate', 'clear_stops',
], true)) {
    if (!api_itin_owned($iid, $uid)) {
        json_response(['ok' => false, 'message' => 'Itinerary not found'], 404);
    }
    if ($action !== 'delete') {
        api_itin_locked($iid);
    }
}

if ($action === 'save_draft') {
    db()->prepare("UPDATE itineraries SET kept_as_draft=1, status='draft' WHERE id=? AND user_id=?")
        ->execute([$iid, $uid]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_title') {
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') {
        $title = 'Unnamed';
    }
    if (strlen($title) > 120) {
        $title = substr($title, 0, 120);
    }
    db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND user_id=?')->execute([$title, $iid, $uid]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_meetup') {
    $meetupRaw = trim((string) ($in['meetup_time'] ?? ''));
    $meetup = $meetupRaw === '' ? null : (normalize_time_input($meetupRaw) ?? null);
    if ($meetupRaw !== '' && $meetup === null) {
        json_response(['ok' => false, 'message' => 'Enter a valid meetup time (e.g. 8:00 AM).'], 400);
    }
    if (!array_key_exists('is_multiday', $in)) {
        $curDays = itinerary_logical_day_count(itinerary_row_by_id($iid));
        if ($curDays > 1) {
            $in['is_multiday'] = 1;
            $in['day_count'] = $curDays;
        }
    }
    [$startDate, , $postedDayCount] = itinerary_resolve_dates_from_post($in);
    $dayCount = max(1, min(itinerary_max_days(), (int) $postedDayCount));
    if ($startDate === null) {
        $keep = db()->prepare('SELECT start_date FROM itineraries WHERE id=? AND user_id=?');
        $keep->execute([$iid, $uid]);
        if (normalize_tour_date($keep->fetchColumn() ?: null) !== null && empty($in['clear_date'])) {
            db()->prepare('UPDATE itineraries SET meetup_time=?, template_day_count=? WHERE id=? AND user_id=?')
                ->execute([$meetup, $dayCount, $iid, $uid]);
            json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
        }
        if (!empty($in['drop_later_stops'])) {
            itinerary_drop_stops_after_day($iid, $dayCount);
            db()->prepare('DELETE FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number>?')->execute([$iid, $dayCount]);
        }
        db()->prepare(
            'UPDATE itineraries SET meetup_time=?, start_date=NULL, end_date=NULL, template_day_count=? WHERE id=? AND user_id=?'
        )->execute([$meetup, $dayCount, $iid, $uid]);
        try {
            db()->prepare('UPDATE itinerary_day_meetups SET visit_date=NULL WHERE itinerary_id=?')->execute([$iid]);
        } catch (Throwable $e) {
            // ignore
        }
        json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
    }
    if ($startDate < date('Y-m-d')) {
        json_response(['ok' => false, 'message' => 'Choose a tour date that is today or later.'], 400);
    }
    if ($meetup !== null) {
        $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
        if ($pastMsg !== null) {
            json_response(['ok' => false, 'message' => $pastMsg], 400);
        }
    }
    db()->prepare('UPDATE itineraries SET meetup_time=? WHERE id=? AND user_id=?')->execute([$meetup, $iid, $uid]);
    if (!empty($in['drop_later_stops'])) {
        itinerary_drop_stops_after_day($iid, $dayCount);
    }
    itinerary_apply_logical_day_count($iid, $dayCount);
    $map = itinerary_day_dates_map($iid);
    if ($startDate !== ($map[1] ?? '')) {
        $set = itinerary_set_day_date($iid, 1, $startDate);
        if (!empty($set['error'])) {
            json_response(['ok' => false, 'message' => (string) $set['error']], 400);
        }
    } else {
        itinerary_sync_span_from_day_dates($iid);
    }
    $row = itinerary_row_by_id($iid);
    $tourDates = itinerary_visit_dates_list($iid, $row);
    $conflict = tourist_date_range_booking_conflict_message(
        $uid,
        $tourDates[0] ?? $startDate,
        $tourDates ? $tourDates[count($tourDates) - 1] : $startDate,
        $iid,
        null,
        $tourDates ?: null
    );
    if ($conflict !== null) {
        json_response(['ok' => false, 'message' => $conflict, 'data' => api_itin_payload($iid)], 400);
    }
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_day_date') {
    $set = itinerary_set_day_date($iid, (int) ($in['day_number'] ?? 1), trim((string) ($in['date'] ?? $in['visit_date'] ?? '')));
    if (!empty($set['error'])) {
        json_response(['ok' => false, 'message' => (string) $set['error']], 400);
    }
    json_response(['ok' => true, 'reordered' => !empty($set['reordered']), 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_day_meetup') {
    $dayNum = max(1, min(14, (int) ($in['day_number'] ?? 1)));
    $meetupTimeIn = trim((string) ($in['meetup_time'] ?? ''));
    if (!empty($in['time_only'])) {
        $err = itinerary_set_day_meetup_time($iid, $dayNum, $meetupTimeIn, !empty($in['allow_past']));
    } elseif (!empty($in['use_port']) || trim((string) ($in['stop_key'] ?? '')) === 'port') {
        $err = itinerary_set_day_meetup($iid, $dayNum, null, true, $meetupTimeIn !== '' ? $meetupTimeIn : null);
    } elseif (!empty($in['clear']) || !empty($in['reset'])) {
        $err = itinerary_set_day_meetup($iid, $dayNum, null, false);
    } else {
        $ref = parse_itinerary_stop_ref($in);
        if ($ref === null) {
            json_response(['ok' => false, 'message' => 'Choose a destination or business for the meetup.'], 400);
        }
        $err = itinerary_set_day_meetup($iid, $dayNum, $ref, false, $meetupTimeIn !== '' ? $meetupTimeIn : null);
    }
    if ($err !== null) {
        json_response(['ok' => false, 'message' => $err], 400);
    }
    $row = itinerary_row_by_id($iid);
    $visitDate = null;
    if (!empty($row['start_date'])) {
        $visitDate = itinerary_date_for_logical_day($iid, $dayNum, $row)
            ?: itinerary_date_for_day((string) $row['start_date'], $dayNum);
    }
    itinerary_reflow_day_times($iid, $visitDate);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_party_size') {
    $party = (int) ($in['party_size'] ?? 0);
    if ($party < 1 || $party > 20) {
        json_response(['ok' => false, 'message' => 'Enter the number of guests.'], 400);
    }
    db()->prepare('UPDATE itineraries SET party_size=? WHERE id=? AND user_id=?')->execute([$party, $iid, $uid]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_draft_guide') {
    $draftGuide = (int) ($in['guide_id'] ?? 0);
    if ($draftGuide > 0 && booking_is_own_guide($uid, $draftGuide)) {
        $draftGuide = 0;
    }
    if ($draftGuide > 0) {
        $gok = db()->prepare('SELECT id FROM tour_guide_profiles WHERE id=? LIMIT 1');
        $gok->execute([$draftGuide]);
        if (!$gok->fetch()) {
            $draftGuide = 0;
        }
    }
    db()->prepare('UPDATE itineraries SET draft_guide_id=? WHERE id=? AND user_id=?')
        ->execute([$draftGuide > 0 ? $draftGuide : null, $iid, $uid]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_stay_ride') {
    $stay = stay_ride_parse($in);
    if (!$stay['ok']) {
        json_response(['ok' => false, 'message' => $stay['message']], 400);
    }
    [$stockStart, $stockEnd] = booking_resolve_tour_span($iid, date('Y-m-d'));
    $partyRow = db()->prepare('SELECT party_size FROM itineraries WHERE id=?');
    $partyRow->execute([$iid]);
    $stockMsg = stay_ride_room_stock_message(
        $stay,
        $stockStart,
        $stockEnd,
        null,
        max(1, (int) $partyRow->fetchColumn()),
        $iid
    );
    if ($stockMsg !== null) {
        json_response(['ok' => false, 'message' => $stockMsg], 400);
    }
    stay_ride_save_itinerary($iid, $uid, $stay);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'clear_stops') {
    db()->prepare('DELETE FROM itinerary_items WHERE itinerary_id=?')->execute([$iid]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'auto_regenerate') {
    $meetupRaw = trim((string) ($in['meetup_time'] ?? ''));
    $meetup = $meetupRaw === '' ? null : (normalize_time_input($meetupRaw) ?? null);
    if ($meetupRaw !== '' && $meetup === null) {
        json_response(['ok' => false, 'message' => 'Enter a valid meetup time (e.g. 8:00 AM).'], 400);
    }
    $row = itinerary_row_by_id($iid);
    if ($meetup === null && !empty($row['meetup_time'])) {
        $meetup = (string) $row['meetup_time'];
    }
    $post = $in;
    if (trim((string) ($post['start_date'] ?? '')) === '') {
        $savedStart = normalize_tour_date($row['start_date'] ?? null);
        if ($savedStart !== null) {
            $post['start_date'] = $savedStart;
        }
    }
    if (empty($post['is_multiday'])) {
        $savedDays = itinerary_logical_day_count($row);
        if ($savedDays > 1) {
            $post['is_multiday'] = '1';
            $post['day_count'] = (string) $savedDays;
        }
    }
    [$startDate, $endDate, $dayCount, $usedDefault] = itinerary_dates_for_autogen($post);
    if (!$usedDefault) {
        $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
        if ($pastMsg !== null) {
            json_response(['ok' => false, 'message' => $pastMsg], 400);
        }
        $conflict = tourist_date_range_booking_conflict_message($uid, $startDate, $endDate, $iid);
        if ($conflict !== null) {
            json_response(['ok' => false, 'message' => $conflict], 400);
        }
    }
    $genTitle = trim((string) ($row['title'] ?? ''));
    if ($genTitle === '' || strcasecmp($genTitle, 'Unnamed') === 0) {
        $genTitle = $dayCount > 1 ? ('Ormoc ' . $dayCount . '-day tour') : 'Ormoc day tour';
    }
    $onlyDay = max(0, (int) ($in['day_number'] ?? 0));
    $opts = [
        'keep_undated' => !empty($usedDefault),
        'day_meetup' => itinerary_posted_day_meetup_times($in),
    ];
    if ($onlyDay > 0) {
        $onlyDay = max(1, min($dayCount, $onlyDay));
        $opts['only_day'] = $onlyDay;
        if ($meetup !== null) {
            $opts['day_meetup'] = [$onlyDay => $meetup];
        }
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
    json_response(['ok' => true, 'warning' => $result['warning'] ?? null, 'data' => api_itin_payload($iid)]);
}

if ($action === 'add_stop') {
    $ref = parse_itinerary_stop_ref($in);
    if (!$ref) {
        json_response(['ok' => false, 'message' => 'Choose a destination or business to add.'], 400);
    }
    $blockedStop = itinerary_stop_business_blocked((int) ($ref['business_id'] ?? 0));
    if ($blockedStop !== null) {
        json_response(['ok' => false, 'message' => $blockedStop], 400);
    }
    $place = itinerary_place_coords($ref);
    if (!$place) {
        json_response(['ok' => false, 'message' => 'That place is not available.'], 404);
    }
    $noHoursMsg = itinerary_place_unavailable_message($ref, $place);
    if ($noHoursMsg !== null) {
        json_response(['ok' => false, 'message' => $noHoursMsg], 400);
    }
    $itinRow = db()->prepare('SELECT * FROM itineraries WHERE id=?');
    $itinRow->execute([$iid]);
    $itinRow = $itinRow->fetch() ?: [];
    $dayCount = itinerary_logical_day_count($itinRow);
    $dayNum = max(1, (int) ($in['day_number'] ?? 1));
    if ($dayNum > $dayCount) {
        $dayNum = $dayCount;
    }
    $hasTourDate = !empty($itinRow['start_date']);
    $visitDate = $hasTourDate
        ? (itinerary_date_for_logical_day($iid, $dayNum, $itinRow)
            ?: (itinerary_date_for_day((string) $itinRow['start_date'], $dayNum)
                ?: (string) $itinRow['start_date']))
        : null;
    $dup = itinerary_duplicate_stop_status($iid, $ref, $visitDate);
    if (!empty($dup['adjacent'])) {
        json_response(['ok' => false, 'message' => 'You can’t add that place as the next stop — pick something else in between.'], 400);
    }
    if (!empty($dup['exists']) && empty($in['allow_duplicate'])) {
        json_response([
            'ok' => false,
            'duplicate' => true,
            'message' => ($dup['name'] ?? 'That place') . ' is already on this tour.',
        ], 409);
    }

    $itinForHours = $itinRow;
    $itinForHours['start_date'] = $visitDate;
    $prev = itinerary_prev_leg_context($iid, $itinRow, $visitDate);
    if (!$hasTourDate) {
        $place['opening_hours'] = null;
    }
    $hasCoords = $place['lat'] != 0.0 || $place['lng'] != 0.0;
    if (!empty($prev['skip_travel'])) {
        $travelMins = 0;
    } else {
        $travelMins = $hasCoords
            ? itin_osrm_travel_minutes($prev['lat'], $prev['lng'], $place['lat'], $place['lng'])
            : 45;
    }
    $visitMins = (int) ($in['visit_minutes'] ?? 60);
    if ($visitMins < 15) {
        json_response(['ok' => false, 'message' => 'Visit time must be at least 15 minutes.'], 400);
    }
    if ($visitMins > ITIN_MAX_VISIT_MINUTES) {
        $visitMins = ITIN_MAX_VISIT_MINUTES;
    }
    $limitMsg = itinerary_visit_limit_message((string) ($place['name'] ?? ''), $place['visit_limit_minutes'] ?? null, $visitMins);
    if ($limitMsg !== null) {
        json_response(['ok' => false, 'message' => $limitMsg], 400);
    }
    $minMsg = itinerary_visit_min_message((string) ($place['name'] ?? ''), $place['min_visit_minutes'] ?? null, $visitMins);
    if ($minMsg !== null) {
        json_response(['ok' => false, 'message' => $minMsg], 400);
    }
    $newTime = trim((string) ($in['start_time'] ?? ''));
    $newMins = itin_time_to_minutes($newTime);
    $suggestBase = !empty($prev['skip_travel'])
        ? (int) ($prev['suggest_mins'] ?? $prev['leave_mins'] ?? 8 * 60)
        : (int) $prev['leave_mins'];
    $suggestMins = !empty($prev['skip_travel'])
        ? $suggestBase
        : itin_suggest_arrival($suggestBase, $travelMins);
    if ($newMins === null) {
        $newMins = $suggestMins;
    }
    $endMins = itin_compute_end($newMins, $visitMins);
    $blockMsg = itinerary_hard_block_message($itinForHours, $prev, $travelMins, $newMins, $endMins, $place);
    if ($blockMsg !== null) {
        json_response(['ok' => false, 'message' => $blockMsg], 400);
    }
    $max = db()->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM itinerary_items WHERE itinerary_id=?');
    $max->execute([$iid]);
    $next = (int) $max->fetchColumn() + 1;
    db()->prepare(
        'INSERT INTO itinerary_items
         (itinerary_id, destination_id, business_id, sort_order, visit_date, start_time, end_time, travel_minutes, visit_minutes)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $iid,
        $ref['destination_id'],
        $ref['business_id'],
        $next,
        $visitDate,
        itin_minutes_to_time($newMins),
        itin_minutes_to_time($endMins),
        $travelMins,
        $visitMins,
    ]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'remove_stop') {
    db()->prepare('DELETE FROM itinerary_items WHERE id=? AND itinerary_id=?')
        ->execute([(int) ($in['item_id'] ?? 0), $iid]);
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
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
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'update_stop_stay') {
    $err = itinerary_update_stop_stay_and_reflow_day(
        $iid,
        (int) ($in['item_id'] ?? 0),
        (int) ($in['visit_minutes'] ?? 60)
    );
    if ($err !== null) {
        json_response(['ok' => false, 'message' => $err], 400);
    }
    json_response(['ok' => true, 'data' => api_itin_payload($iid)]);
}

if ($action === 'delete') {
    db()->prepare('DELETE FROM itineraries WHERE id=? AND user_id=?')->execute([$iid, $uid]);
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'message' => 'Unknown action'], 400);
