<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_itinerary_items_schema();
ensure_place_schedule_schema();
$pageTitle = 'Suggested Itineraries';
$pageHeading = 'Suggested Tours';
$pageSub = 'Build sample tours for tourists — same itinerary maker as visitors use';
$activeNav = 'itineraries';
$bodyClass = 'itin-page';
$guideHidePagehead = true;

$profile = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$gid = (int) $profile->fetchColumn();
if ($gid <= 0) {
    flash('error', 'Tour guide profile not found.');
    redirect(APP_URL . '/tourguide/dashboard.php');
}

$destinations = db()->query(
    "SELECT d.id, d.name, d.location, d.description, d.cover_image, d.entrance_fee, d.avg_rating,
            d.latitude, d.longitude, d.default_visit_minutes, d.visit_duration_minutes, d.opening_hours, c.name AS category_name
     FROM destinations d
     JOIN categories c ON c.id = d.category_id
     WHERE d.status='published'
     ORDER BY d.name"
)->fetchAll();
$businesses = db()->query(
    "SELECT b.id, b.name, b.address, b.description, b.cover_image, b.latitude, b.longitude,
            b.default_visit_minutes, b.visit_duration_minutes, bt.name AS type_name, " . business_itinerary_ready_columns() . "
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.approval_status='approved' AND b.status='active'
     ORDER BY b.name"
)->fetchAll();
$placeVisitLimits = [];
foreach ($destinations as $d) {
    $placeVisitLimits['d' . (int) $d['id']] = place_visit_limit($d['visit_duration_minutes'] ?? null);
}
foreach ($businesses as $b) {
    $placeVisitLimits['b' . (int) $b['id']] = place_visit_limit($b['visit_duration_minutes'] ?? null);
}
$destinations = array_values(array_filter($destinations, 'destination_itinerary_ready'));
$businesses = array_values(array_filter($businesses, 'business_itinerary_ready'));

$favoriteDestinations = [];
$favoriteDestIds = [];

function guide_owns_itin(int $iid, int $gid): bool
{
    $own = db()->prepare('SELECT id FROM itineraries WHERE id=? AND created_by_guide_id=? AND is_suggested=1');
    $own->execute([$iid, $gid]);
    return (bool) $own->fetch();
}

function guide_find_blank_suggested_id(int $guideId): int
{
    $stmt = db()->prepare(
        "SELECT i.id
         FROM itineraries i
         LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.id
         WHERE i.created_by_guide_id = ? AND i.is_suggested = 1
           AND (TRIM(COALESCE(i.title,'')) = '' OR LOWER(TRIM(i.title)) = 'unnamed')
         GROUP BY i.id
         HAVING COUNT(ii.id) = 0
         ORDER BY i.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$guideId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function guide_prune_blank_suggested(int $guideId, ?int $keepId = null): void
{
    $stmt = db()->prepare(
        "SELECT i.id
         FROM itineraries i
         LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.id
         LEFT JOIN bookings b ON b.itinerary_id = i.id
         WHERE i.created_by_guide_id = ? AND i.is_suggested = 1
           AND (TRIM(COALESCE(i.title,'')) = '' OR LOWER(TRIM(i.title)) = 'unnamed')
         GROUP BY i.id
         HAVING COUNT(ii.id) = 0 AND COUNT(b.id) = 0"
    );
    $stmt->execute([$guideId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach ($ids as $id) {
        if ($keepId !== null && $id === (int) $keepId) {
            continue;
        }
        db()->prepare('DELETE FROM itineraries WHERE id=? AND created_by_guide_id=?')->execute([$id, $guideId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $meetup = normalize_time_input($_POST['meetup_time'] ?? '8:00 AM') ?? '08:00';
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $title = 'Unnamed';
        }
        // Blank Unnamed drafts are not listed until renamed or filled — reuse one draft.
        if (itinerary_is_placeholder_title($title)) {
            $reuseId = guide_find_blank_suggested_id($gid);
            if ($reuseId > 0) {
                guide_prune_blank_suggested($gid, $reuseId);
                redirect(APP_URL . '/tourguide/itineraries.php?id=' . $reuseId);
            }
        }
        db()->prepare(
            'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, created_by_guide_id, status, kept_as_draft)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $user['id'],
            $title,
            trim($_POST['description'] ?? ''),
            null,
            null,
            $meetup,
            1,
            $gid,
            'draft',
            0,
        ]);
        $iid = (int) db()->lastInsertId();
        if (itinerary_is_placeholder_title($title)) {
            guide_prune_blank_suggested($gid, $iid);
        }
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'publish' && guide_owns_itin((int) ($_POST['itinerary_id'] ?? 0), $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            if (function_exists('mb_strlen') && mb_strlen($title) > 120) {
                $title = mb_substr($title, 0, 120);
            } elseif (strlen($title) > 120) {
                $title = substr($title, 0, 120);
            }
            db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND created_by_guide_id=?')
                ->execute([$title, $iid, $gid]);
        }
        $row = db()->prepare('SELECT title FROM itineraries WHERE id=? AND created_by_guide_id=?');
        $row->execute([$iid, $gid]);
        $row = $row->fetch() ?: [];
        if (itinerary_is_placeholder_title($row['title'] ?? null)) {
            flash('error', 'Give this tour a name before saving it for tourists.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        $stopCount = db()->prepare('SELECT COUNT(*) FROM itinerary_items WHERE itinerary_id=?');
        $stopCount->execute([$iid]);
        if ((int) $stopCount->fetchColumn() < 1) {
            flash('error', 'Add at least one stop before saving this suggested tour.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        db()->prepare(
            "UPDATE itineraries
             SET status='active', kept_as_draft=0, start_date=NULL, end_date=NULL
             WHERE id=? AND created_by_guide_id=? AND is_suggested=1"
        )->execute([$iid, $gid]);
        flash('success', 'Suggested tour saved — tourists can now see and use it.');
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'save_draft' && guide_owns_itin((int) ($_POST['itinerary_id'] ?? 0), $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        if (itinerary_guard_new_draft($gid, 'guide', $iid) !== null) {
            itinerary_reject_draft_limit(APP_URL . '/tourguide/itineraries.php?id=' . $iid, 'guide');
        }
        db()->prepare(
            "UPDATE itineraries SET kept_as_draft=1, status='draft' WHERE id=? AND created_by_guide_id=?"
        )->execute([$iid, $gid]);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'id' => $iid], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('success', 'Tour saved as draft.');
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'update_title' && guide_owns_itin((int) ($_POST['itinerary_id'] ?? 0), $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $title = 'Unnamed';
        }
        if (function_exists('mb_strlen') && mb_strlen($title) > 120) {
            $title = mb_substr($title, 0, 120);
        } elseif (strlen($title) > 120) {
            $title = substr($title, 0, 120);
        }
        db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND created_by_guide_id=?')
            ->execute([$title, $iid, $gid]);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'title' => $title, 'id' => $iid], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'auto_generate') {
        $meetup = normalize_time_input($_POST['meetup_time'] ?? '8:00 AM') ?? '08:00';
        // Date optional — defaults to tomorrow when blank (multi-day uses day_count)
        [$startDate, $endDate, $dayCount] = itinerary_dates_for_autogen($_POST);
        $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
        if ($pastMsg !== null) {
            flash('error', $pastMsg);
            redirect(APP_URL . '/tourguide/itineraries.php?add=1');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $title = 'Ormoc auto tour';
        }
        try {
            $result = auto_generate_itinerary_for_tourist(
                (int) $user['id'],
                $title,
                trim((string) ($_POST['description'] ?? '')),
                $startDate,
                (string) $endDate,
                $meetup,
                null,
                ['guide_profile_id' => $gid]
            );
            if (!empty($result['warning'])) {
                flash('warning', $result['warning']);
            } else {
                flash('success', 'Tour auto-generated with ' . (int) $result['stops'] . ' stop(s). Adjust times or reorder as needed.');
            }
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . (int) $result['id']);
        } catch (Throwable $e) {
            flash('error', $e->getMessage() ?: 'Could not auto-generate a tour.');
            redirect(APP_URL . '/tourguide/itineraries.php?add=1');
        }
    }


    if ($action === 'update_meetup' && guide_owns_itin((int) $_POST['itinerary_id'], $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $meetup = normalize_time_input($_POST['meetup_time'] ?? '');
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        $respondMeetup = static function (bool $ok, string $message = '', array $extra = []) use ($wantsJson, $iid): void {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array_merge(['ok' => $ok, 'message' => $message, 'id' => $iid], $extra), JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($message !== '') {
                flash($ok ? 'success' : 'error', $message);
            }
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        };
        if ($meetup === null) {
            $respondMeetup(false, 'Enter a valid meetup time (e.g. 8:00 AM).');
        }
        $isMulti = !empty($_POST['is_multiday']);
        $dayCount = 1;
        if ($isMulti) {
            $dayCount = (int) ($_POST['day_count'] ?? 2);
            if ($dayCount < 2) {
                $dayCount = 2;
            }
            if ($dayCount > 7) {
                $dayCount = 7;
            }
        }
        // Templates have no calendar tour date — only meetup + day structure.
        db()->prepare(
            'UPDATE itineraries SET meetup_time=?, start_date=NULL, end_date=NULL, template_day_count=? WHERE id=? AND created_by_guide_id=?'
        )->execute([$meetup, $dayCount, $iid, $gid]);
        $respondMeetup(true, '', [
            'start_date' => null,
            'end_date' => null,
            'day_count' => $dayCount,
            'is_multiday' => $dayCount > 1,
            'meetup_time' => format_time_12h($meetup) ?: $meetup,
        ]);
    }

    if ($action === 'auto_regenerate' && guide_owns_itin((int) ($_POST['itinerary_id'] ?? 0), $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $meetup = normalize_time_input($_POST['meetup_time'] ?? '8:00 AM') ?? '08:00';
        $postForDates = $_POST;
        if (empty($postForDates['is_multiday'])) {
            $saved = db()->prepare('SELECT template_day_count FROM itineraries WHERE id=? AND created_by_guide_id=?');
            $saved->execute([$iid, $gid]);
            $savedDays = itinerary_template_day_count($saved->fetch() ?: []);
            if ($savedDays > 1) {
                $postForDates['is_multiday'] = '1';
                $postForDates['day_count'] = (string) $savedDays;
            }
        }
        [$startDate, $endDate, $dayCount] = itinerary_dates_for_autogen($postForDates);
        $cur = db()->prepare('SELECT title, description FROM itineraries WHERE id=? AND created_by_guide_id=?');
        $cur->execute([$iid, $gid]);
        $curRow = $cur->fetch() ?: ['title' => '', 'description' => ''];
        $genTitle = trim((string) ($curRow['title'] ?? ''));
        if ($genTitle === '' || strcasecmp($genTitle, 'Unnamed') === 0) {
            $genTitle = $dayCount > 1 ? ('Ormoc ' . $dayCount . '-day tour') : 'Ormoc day tour';
        }
        try {
            $result = auto_generate_itinerary_for_tourist(
                (int) $user['id'],
                $genTitle,
                (string) ($curRow['description'] ?? ''),
                $startDate,
                (string) $endDate,
                $meetup,
                $iid,
                ['guide_profile_id' => $gid]
            );
            if (!empty($result['warning'])) {
                flash('warning', $result['warning']);
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage() ?: 'Could not auto-generate this tour.');
        }
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'reorder_stops' && guide_owns_itin((int) $_POST['itinerary_id'], $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $order = $_POST['order'] ?? [];
        if (is_string($order)) {
            $order = json_decode($order, true) ?: [];
        }
        $wantsJson = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $err = is_array($order) ? itinerary_reorder_stops_and_reflow($iid, $order) : 'Invalid stop order.';
        if ($err !== null) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $err], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('error', $err);
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        if ($wantsJson) {
            $itinMeta = db()->prepare('SELECT start_date FROM itineraries WHERE id=?');
            $itinMeta->execute([$iid]);
            $itinMeta = $itinMeta->fetch() ?: [];
            $startDisp = normalize_tour_date($itinMeta['start_date'] ?? null) ?: '';
            $fresh = itinerary_items_with_places($iid);
            $dayIdx = [];
            $stopsOut = [];
            $mapOut = [];
            foreach ($fresh as $it) {
                $day = $startDisp !== ''
                    ? itinerary_day_number($startDisp, $it['visit_date'] ?? $startDisp)
                    : 1;
                if (!isset($dayIdx[$day])) {
                    $dayIdx[$day] = 0;
                }
                $dayIdx[$day]++;
                $visit = (int) ($it['visit_minutes'] ?? 60);
                $travel = (int) ($it['travel_minutes'] ?? 0);
                $kind = (($it['stop_kind'] ?? '') === 'business') ? 'business' : 'destination';
                $stopsOut[] = [
                    'id' => (int) $it['id'],
                    'key' => itinerary_item_place_key($it),
                    'day' => $day,
                    'index' => $dayIdx[$day],
                    'name' => (string) ($it['stop_name'] ?? $it['destination_name'] ?? 'Place'),
                    'kind' => $kind,
                    'arrive' => format_time_12h(isset($it['start_time']) ? (string) $it['start_time'] : null),
                    'leave' => format_time_12h(isset($it['end_time']) ? (string) $it['end_time'] : null),
                    'visit' => $visit,
                    'travel' => $travel,
                ];
                if ($it['latitude'] !== null && $it['longitude'] !== null) {
                    $mapOut[] = [
                        'title' => $it['stop_name'] ?? $it['destination_name'] ?? 'Stop',
                        'lat' => (float) $it['latitude'],
                        'lng' => (float) $it['longitude'],
                        'day' => $day,
                    ];
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'reflowed' => true,
                'stops' => $stopsOut,
                'mapStops' => $mapOut,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('success', 'Stop order updated.');
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'delete' && guide_owns_itin((int) ($_POST['id'] ?? 0), $gid)) {
        $delId = (int) $_POST['id'];
        db()->prepare('DELETE FROM itineraries WHERE id=? AND created_by_guide_id=? AND is_suggested=1')->execute([$delId, $gid]);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'id' => $delId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('success', 'Itinerary deleted.');
        redirect(APP_URL . '/tourguide/itineraries.php');
    }

    if ($action === 'add_stop' && guide_owns_itin((int) $_POST['itinerary_id'], $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $ref = parse_itinerary_stop_ref($_POST);
        if (!$ref) {
            flash('error', 'Choose a destination or business to add.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }

        $place = itinerary_place_coords($ref);
        if (!$place) {
            flash('error', 'That place is not available.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        $noHoursMsg = itinerary_place_unavailable_message($ref, $place);
        if ($noHoursMsg !== null) {
            flash('error', $noHoursMsg);
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }

        $itinRow = db()->prepare('SELECT * FROM itineraries WHERE id=?');
        $itinRow->execute([$iid]);
        $itinRow = $itinRow->fetch() ?: [];
        $dayCount = itinerary_template_day_count($itinRow);
        $dayNum = (int) ($_POST['day_number'] ?? 1);
        if ($dayNum < 1) {
            $dayNum = 1;
        }
        if ($dayNum > $dayCount) {
            $dayNum = $dayCount;
        }
        // Undated templates use a fixed day-base so Day 1…N stay ordered without a tour date.
        $visitDate = $dayCount > 1 ? itinerary_template_visit_date($dayNum) : null;

        $dup = itinerary_duplicate_stop_status($iid, $ref, $visitDate);
        if (!empty($dup['adjacent'])) {
            flash('error', 'You can’t add ' . ($dup['name'] ?? 'this place') . ' as the next stop — it’s already the last stop on this day. Add a different place in between.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        $allowDuplicate = !empty($_POST['allow_duplicate']);
        if (!empty($dup['exists']) && !$allowDuplicate) {
            flash('error', ($dup['name'] ?? 'That place') . ' is already on this tour. Confirm to add it again.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }

        // Hours hard-block uses the stop’s visit day (skipped for undated templates)
        $itinForHours = $itinRow;
        $itinForHours['start_date'] = $visitDate;
        $prev = itinerary_prev_leg_context($iid, $itinRow, $visitDate);
        // No real weekday on templates — still enforce travel timing, not opening hours
        $place['opening_hours'] = null;

        $hasCoords = $place['lat'] != 0.0 || $place['lng'] != 0.0;
        if (!empty($prev['skip_travel'])) {
            // First stop on Day 2+ — new day, no travel-from-Port constraint
            $travelMins = 0;
        } else {
            $postedTravel = (int) ($_POST['travel_minutes'] ?? 0);
            $travelMins = $hasCoords
                ? itin_osrm_travel_minutes($prev['lat'], $prev['lng'], $place['lat'], $place['lng'])
                : ($postedTravel > 0 ? $postedTravel : 45);
        }

        $rawVisit = trim((string) ($_POST['visit_minutes'] ?? ''));
        if ($rawVisit === '' || !is_numeric($rawVisit)) {
            flash('error', 'Enter how many minutes the visit should take.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        $visitMins = (int) $rawVisit;
        if ($visitMins < 15) {
            flash('error', 'Visit time must be at least 15 minutes.');
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }
        if ($visitMins > ITIN_MAX_VISIT_MINUTES) {
            $visitMins = ITIN_MAX_VISIT_MINUTES;
        }
        $limitMsg = itinerary_visit_limit_message((string) ($place['name'] ?? ''), $place['visit_limit_minutes'] ?? null, $visitMins);
        if ($limitMsg !== null) {
            flash('error', $limitMsg);
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
        }

        $newTime = trim((string) ($_POST['start_time'] ?? ''));
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
            flash('error', $blockMsg);
            redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
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
        flash('success', 'Stop added to the route.');
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'remove_stop' && guide_owns_itin((int) $_POST['itinerary_id'], $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        db()->prepare('DELETE FROM itinerary_items WHERE id=? AND itinerary_id=?')
            ->execute([(int) $_POST['item_id'], $iid]);
        flash('success', 'Stop removed.');
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }

    if ($action === 'update_stop_stay' && guide_owns_itin((int) ($_POST['itinerary_id'] ?? 0), $gid)) {
        $iid = (int) $_POST['itinerary_id'];
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $err = itinerary_update_stop_stay_and_reflow_day(
            $iid,
            $itemId,
            (int) ($_POST['visit_minutes'] ?? 60)
        );
        if ($err !== null) {
            flash('error', $err);
        }
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $iid);
    }


    redirect(APP_URL . '/tourguide/itineraries.php');
}

$viewId = (int) ($_GET['id'] ?? 0);
$view = null;
$stops = [];
if ($viewId) {
    $s = db()->prepare('SELECT * FROM itineraries WHERE id=? AND created_by_guide_id=? AND is_suggested=1');
    $s->execute([$viewId, $gid]);
    $view = $s->fetch() ?: null;
    if ($view) {
        $stops = itinerary_items_with_places($viewId);
    }
}

// No tour selected → open (or create) an empty Unnamed draft (not listed until filled/renamed)
if (!$view) {
    $reuseId = guide_find_blank_suggested_id($gid);
    if ($reuseId > 0) {
        guide_prune_blank_suggested($gid, $reuseId);
        redirect(APP_URL . '/tourguide/itineraries.php?id=' . $reuseId);
    }
    $meetup = '08:00';
    db()->prepare(
        'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, created_by_guide_id, status, kept_as_draft)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        (int) $user['id'],
        'Unnamed',
        '',
        null,
        null,
        $meetup,
        1,
        $gid,
        'draft',
        0,
    ]);
    $newId = (int) db()->lastInsertId();
    guide_prune_blank_suggested($gid, $newId);
    redirect(APP_URL . '/tourguide/itineraries.php?id=' . $newId);
}

// Keep only one blank Unnamed draft around the current view
if ($view) {
    guide_prune_blank_suggested($gid, (int) $view['id']);
}

// Saved suggested tours only: renamed and/or with at least one stop
$mine = db()->prepare(
    'SELECT i.* FROM itineraries i
     WHERE i.created_by_guide_id=? AND i.is_suggested=1 AND ' . itinerary_sql_is_saved_tour('i') . '
     ORDER BY i.created_at DESC'
);
$mine->execute([$gid]);
$mine = $mine->fetchAll() ?: [];
$publishedMine = [];
$draftMine = [];
foreach ($mine as $r) {
    if (itinerary_is_published_suggested($r)) {
        $publishedMine[] = $r;
    } elseif (!empty($r['kept_as_draft'])) {
        $draftMine[] = $r;
    }
}
$draftMax = itinerary_max_drafts('guide');
$draftCount = count($draftMine);
$draftToggleLabel = $draftCount > 0
    ? ('View drafts (' . $draftCount . '/' . $draftMax . ')')
    : 'View drafts';
$viewIsListedDraft = $view && in_array((int) $view['id'], array_map(static fn($r) => (int) $r['id'], $draftMine), true);
$draftsAtLimit = $draftCount >= $draftMax;
$showDrafts = (string) ($_GET['drafts'] ?? '') === '1';
if (
    !$showDrafts
    && $view
    && (int) ($view['created_by_guide_id'] ?? 0) === $gid
    && (int) ($view['is_suggested'] ?? 0) === 1
    && !itinerary_is_published_suggested($view)
) {
    $qs = 'id=' . (int) $view['id'] . '&drafts=1';
    redirect(APP_URL . '/tourguide/itineraries.php?' . $qs);
}
$suggested = [];

$canEdit = $view && (int) ($view['created_by_guide_id'] ?? 0) === $gid && (int) ($view['is_suggested'] ?? 0) === 1;
$existingDestIds = array_values(array_filter(array_map('intval', array_column($stops, 'destination_id'))));
$existingBizIds = array_values(array_filter(array_map('intval', array_column($stops, 'business_id'))));

// Templates never keep a calendar tour date — strip leftovers, keep day structure.
if ($canEdit && $view && (!empty($view['start_date']) || !empty($view['end_date']))) {
    db()->prepare('UPDATE itineraries SET start_date=NULL, end_date=NULL WHERE id=? AND created_by_guide_id=?')
        ->execute([(int) $view['id'], $gid]);
    $view['start_date'] = null;
    $view['end_date'] = null;
}

$meetupDisplay = $view
    ? format_time_12h((string) ($view['meetup_time'] ?? '08:00:00'))
    : '8:00 AM';
$tourDateDisplay = '';
$tourEndDisplay = '';
$dayCountDisplay = $view ? itinerary_template_day_count($view) : 1;
$isMultidayTour = $dayCountDisplay > 1;
$templateDayBase = itinerary_template_day_base();
$stopsByDay = $view
    ? itinerary_group_stops_by_day(
        $stops,
        $isMultidayTour ? $templateDayBase : null,
        $isMultidayTour ? itinerary_date_for_day($templateDayBase, $dayCountDisplay) : null,
        (int) $view['id'],
        $view
    )
    : [];

$existingStopsForJs = [];
foreach ($stopsByDay as $g) {
    $dayNumJs = (int) $g['day'];
    foreach ($g['stops'] as $it) {
        $k = itinerary_item_place_key($it);
        if ($k === null) {
            continue;
        }
        $existingStopsForJs[] = [
            'id' => (int) $it['id'],
            'key' => $k,
            'day' => $dayNumJs,
            'name' => (string) ($it['stop_name'] ?? $it['destination_name'] ?? 'Place'),
        ];
    }
}

$prevLeg = $view
    ? itinerary_prev_leg_context((int) $view['id'], $view, $tourDateDisplay)
    : [
        'lat' => ormoc_port_coords()['lat'],
        'lng' => ormoc_port_coords()['lng'],
        'leave_mins' => 8 * 60,
        'label' => ormoc_port_coords()['title'],
    ];
$dayPrevsForJs = [];
$dayKeysForJs = [];
if ($view) {
    for ($d = 1; $d <= $dayCountDisplay; $d++) {
        $dDate = itinerary_date_for_day($tourDateDisplay, $d) ?: $tourDateDisplay;
        $ctx = itinerary_prev_leg_context((int) $view['id'], $view, $dDate);
        $dayPrevsForJs[(string) $d] = [
            'lat' => (float) $ctx['lat'],
            'lng' => (float) $ctx['lng'],
            'leaveMins' => (int) $ctx['leave_mins'],
            'label' => (string) $ctx['label'],
            'skipTravel' => !empty($ctx['skip_travel']),
            'suggestMins' => (int) ($ctx['suggest_mins'] ?? $ctx['leave_mins'] ?? 8 * 60),
        ];
        $dayKeysForJs[(string) $d] = itinerary_day_key($dDate);
    }
}

$itinDayKey = itinerary_day_key(!empty($view['start_date']) ? (string) $view['start_date'] : null);
$placesForJs = [];
$placeDetailBlurb = static function (?string $text, int $max = 200): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    if (strlen($text) > $max) {
        return rtrim(substr($text, 0, $max - 1)) . '…';
    }
    return $text;
};
foreach ($destinations as $d) {
    $resolved = resolve_place_opening_hours($d['opening_hours'] ?? null);
    $cover = !empty($d['cover_image']) ? (APP_URL . '/uploads/' . ltrim((string) $d['cover_image'], '/')) : null;
    $did = (int) $d['id'];
    $placesForJs['d:' . $did] = [
        'kind' => 'destination',
        'key' => 'd:' . $did,
        'lat' => $d['latitude'] !== null ? (float) $d['latitude'] : null,
        'lng' => $d['longitude'] !== null ? (float) $d['longitude'] : null,
        'name' => $d['name'],
        'subtitle' => (string) ($d['category_name'] ?? 'Destination'),
        'location' => (string) ($d['location'] ?? ''),
        'description' => $placeDetailBlurb($d['description'] ?? null),
        'cover' => $cover,
        'fee' => trim((string) ($d['entrance_fee'] ?? '')),
        'rating' => $d['avg_rating'] !== null ? (float) $d['avg_rating'] : null,
        'defaultVisit' => (int) ($d['default_visit_minutes'] ?? 60),
        'visitLimit' => place_visit_limit($d['visit_duration_minutes'] ?? null),
        'hours' => $resolved,
        'hoursLabel' => $resolved ? format_hours_slot_12h($resolved[$itinDayKey] ?? 'Closed') : null,
        'url' => APP_URL . '/tourist/destination.php?id=' . $did,
        'favorite' => false,
        'onTour' => in_array($did, $existingDestIds, true),
    ];
}
foreach ($businesses as $b) {
    $oh = $b['opening_hours'] ?? null;
    if (is_array($oh)) {
        $oh = json_encode($oh);
    }
    $resolved = resolve_place_opening_hours($oh);
    $cover = !empty($b['cover_image']) ? (APP_URL . '/uploads/' . ltrim((string) $b['cover_image'], '/')) : null;
    $bid = (int) $b['id'];
    $placesForJs['b:' . $bid] = [
        'kind' => 'business',
        'key' => 'b:' . $bid,
        'lat' => $b['latitude'] !== null ? (float) $b['latitude'] : null,
        'lng' => $b['longitude'] !== null ? (float) $b['longitude'] : null,
        'name' => $b['name'],
        'subtitle' => (string) ($b['type_name'] ?? 'Business'),
        'location' => (string) ($b['address'] ?? ''),
        'description' => $placeDetailBlurb($b['description'] ?? null),
        'cover' => $cover,
        'fee' => '',
        'rating' => null,
        'defaultVisit' => (int) ($b['default_visit_minutes'] ?? 60),
        'visitLimit' => place_visit_limit($b['visit_duration_minutes'] ?? null),
        'hours' => $resolved,
        'hoursLabel' => $resolved ? format_hours_slot_12h($resolved[$itinDayKey] ?? 'Closed') : null,
        'url' => APP_URL . '/tourist/business.php?id=' . $bid,
        'favorite' => false,
        'onTour' => in_array($bid, $existingBizIds, true),
    ];
}

$mapDayAnchor = $tourDateDisplay !== ''
    ? $tourDateDisplay
    : ($isMultidayTour ? itinerary_template_day_base() : null);
$mapStops = array_values(array_filter(
    array_map(static function ($it) use ($mapDayAnchor) {
        if ($it['latitude'] === null || $it['longitude'] === null) {
            return null;
        }
        $day = $mapDayAnchor
            ? itinerary_day_number($mapDayAnchor, $it['visit_date'] ?? $mapDayAnchor)
            : 1;
        return [
            'title' => $it['stop_name'] ?? $it['destination_name'] ?? 'Stop',
            'lat' => (float) $it['latitude'],
            'lng' => (float) $it['longitude'],
            'day' => $day,
        ];
    }, $stops)
));

$hasTourDate = false;
$isPublishedSuggested = $view && itinerary_is_published_suggested($view);
$hasItinContent = $canEdit && $view && (
    count($stops) > 0
    || !itinerary_is_placeholder_title($view['title'] ?? null)
);
// Leave-prompt: published or explicitly draft-saved counts as “safe to leave”
$keptAsDraft = $canEdit && $view && (
    $isPublishedSuggested || !empty($view['kept_as_draft'])
);

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php require __DIR__ . '/../includes/guide_shell_start.php'; ?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('warning')): ?><div class="alert alert-warning"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="itin-pagehead" data-itin-tutorial="guide" data-itin-tutorial-user="<?= (int) ($user['id'] ?? 0) ?>">
        <div class="itin-pagehead__titleblock">
          <div class="itin-pagehead__title-row">
            <h1 class="itin-pagehead__title">Suggested Tours</h1>
            <button type="button" class="itin-tutorial-replay" data-itin-tutorial-replay aria-label="Replay suggested tour tutorial">How to use</button>
              </div>
          <p class="itin-pagehead__sub">Build sample tours for tourists · Ormoc City</p>
              </div>
        <div class="itin-suggest-tabs" data-itin-chips>
          <form method="post" class="d-inline" data-itin-new-tour><?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="title" value="Unnamed">
            <input type="hidden" name="meetup_time" value="8:00 AM">
            <button type="submit" class="quick-chip">+ New tour</button>
            </form>
          <?php foreach ($publishedMine as $r): ?>
            <?php $chipTitle = trim((string) ($r['title'] ?? '')) !== '' ? (string) $r['title'] : 'Unnamed'; ?>
            <a class="quick-chip <?= $view && (int) $view['id'] === (int) $r['id'] ? 'is-active fw-semibold' : '' ?>"
               href="?id=<?= (int) $r['id'] ?>">
              <?= e($chipTitle) ?>
            </a>
          <?php endforeach; ?>
          </div>
      </div>

      <div class="row itin-builder">
        <div class="col-lg-4 itin-builder__col itin-builder__col--controls">
          <div class="glass p-3 p-md-4 h-100 itin-builder__card">
            <?php if (!$view): ?>
              <h3 class="panel-title mb-2">Your suggested tours</h3>
              <p class="text-muted mb-2">Click <strong>+ New tour</strong> to build a sample itinerary. Saved templates show above; drafts stay under View drafts.</p>
              <?php if (!empty($draftsAtLimit)): ?>
                <div class="alert alert-warning py-2 small"><?= e(itinerary_draft_limit_message('guide')) ?></div>
              <?php endif; ?>
              <div class="dropdown itin-draft-select" data-itin-tour="drafts">
                <button class="btn dropdown-toggle itin-draft-select__toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"<?= $draftMine ? '' : ' disabled' ?>>
                  <?= e($draftToggleLabel) ?>
                </button>
                <ul class="dropdown-menu itin-draft-dropdown">
                  <?php if ($draftMine): ?>
                    <?php foreach ($draftMine as $r): ?>
                      <?php itinerary_draft_menu_item($r, false); ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <li><span class="dropdown-item-text text-muted">No drafts yet</span></li>
                  <?php endif; ?>
                </ul>
              </div>
            <?php else: ?>
              <div class="itin-tour-head mb-2">
                <div class="itin-tour-head__row">
                  <?php if ($canEdit): ?>
                    <form method="post" class="itin-rename" id="itinRenameForm" data-itin-rename data-itin-tour="title"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_title">
                      <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                      <input type="text" class="form-control form-control-sm itin-rename__input" name="title" id="itinTourTitle"
                        value="<?= e($view['title'] !== '' ? $view['title'] : 'Unnamed') ?>"
                        maxlength="120" required aria-label="Tour name" placeholder="Tour name">
                      <div class="itin-date-feedback" data-itin-title-feedback hidden></div>
                </form>
                    <form method="post" class="itin-tour-head__save" id="itinPublishForm" data-itin-tour="save"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="publish">
                      <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                      <input type="hidden" name="title" id="itinPublishTitle" value="<?= e($view['title'] !== '' ? $view['title'] : 'Unnamed') ?>">
                      <button type="submit" class="btn btn-ct itin-tour-head__save-btn">
                        <?= $isPublishedSuggested ? 'Saved' : 'Save' ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <h3 class="panel-title mb-0 itin-tour-head__name"><?= e($view['title']) ?></h3>
                  <?php endif; ?>
              </div>
                <?php if (!empty($draftsAtLimit) && empty($viewIsListedDraft) && empty($isPublishedSuggested)): ?>
                  <div class="alert alert-warning py-2 small mb-2"><?= e(itinerary_draft_limit_message('guide')) ?></div>
                <?php endif; ?>
                <div class="itin-tour-actions">
                  <div class="dropdown itin-tour-actions__item itin-draft-select" data-itin-tour="drafts">
                    <button class="btn dropdown-toggle itin-draft-select__toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"<?= $draftMine ? '' : ' disabled' ?>>
                      <?= e($draftToggleLabel) ?>
                    </button>
                    <ul class="dropdown-menu itin-draft-dropdown">
                      <?php if ($draftMine): ?>
                        <?php foreach ($draftMine as $r): ?>
                          <?php itinerary_draft_menu_item($r, (int) $view['id'] === (int) $r['id']); ?>
            <?php endforeach; ?>
                      <?php else: ?>
                        <li><span class="dropdown-item-text text-muted">No drafts yet</span></li>
                      <?php endif; ?>
                    </ul>
          </div>
        </div>
              </div>
              <?php if (($itinDesc = itinerary_display_description($view['description'] ?? null)) !== ''): ?>
                <p class="small text-muted mb-2"><?= e($itinDesc) ?></p>
              <?php endif; ?>

              <?php if ($canEdit): ?><div class="itin-place-overlay-host" data-itin-rich-picker><?php endif; ?>
              <?php if ($canEdit): ?>
                <div class="itin-addstop-card mb-2">
                  <div class="fw-semibold mb-2">Add stop</div>
                  <form method="post" class="row g-2 align-items-end" id="itinAddStopForm" novalidate><?= csrf_field() ?>
                  <input type="hidden" name="action" value="add_stop">
                    <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                    <input type="hidden" name="travel_minutes" value="45">
                    <input type="hidden" name="allow_duplicate" value="0" id="itinAllowDuplicate">
                    <div class="col-12">
                      <label class="form-label small">Place</label>
                      <div class="itin-rich-picker">
                        <input type="hidden" name="stop_key" id="itinStopKey" value="" required>
                        <button type="button" class="itin-rich-picker__trigger" data-itin-rich-trigger data-itin-tour="place" aria-expanded="false" aria-haspopup="dialog">
                          <span class="itin-rich-picker__trigger-media" data-itin-rich-trigger-media></span>
                          <span class="itin-rich-picker__trigger-text">
                            <span class="itin-rich-picker__trigger-title" data-itin-rich-trigger-title>Choose a place…</span>
                            <span class="itin-rich-picker__trigger-sub" data-itin-rich-trigger-sub>Destinations or businesses</span>
                          </span>
                        </button>
                      </div>
                      <div class="form-text" data-itin-travel-hint>Switch Destinations / Businesses, then pick a place with photo and details.</div>
                      <div class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none" data-itin-travel-warn role="alert"></div>
                    </div>
                    <div class="col-12<?= $isMultidayTour ? '' : ' d-none' ?>" data-itin-add-day-wrap>
                      <label class="form-label small" for="itinAddDayNumber">Add to</label>
                      <select class="form-select" name="day_number" id="itinAddDayNumber" data-itin-add-day>
                        <?php
                          $addDayMax = $isMultidayTour ? max(2, (int) $dayCountDisplay) : 1;
                          for ($d = 1; $d <= $addDayMax; $d++):
                        ?>
                          <option value="<?= $d ?>">Day <?= $d ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>
                    <div class="col-12" data-itin-tour="visit-add">
                      <div class="row g-2 align-items-end">
                        <div class="col-8">
                          <label class="form-label small">Visit time</label>
                          <div class="itin-visit-clock-wrap">
                            <input type="text" class="form-control itin-visit-clock" data-itin-visit-clock
                                   inputmode="numeric" autocomplete="off" placeholder="--:--" maxlength="5"
                                   aria-label="Visit time hours and minutes">
                            <span class="itin-visit-clock__unit" data-itin-visit-unit></span>
                          </div>
                          <input type="hidden" name="visit_minutes" id="itinVisitMinutes" value="">
                        </div>
                        <div class="col-4">
                          <button class="btn btn-ct w-100" id="itinAddStopBtn" type="submit" disabled>Add</button>
                        </div>
                      </div>
                    </div>
                  </form>
                </div>
                        <?php endif; ?>

              <div class="itin-schedule-card mb-0">
                <div class="fw-semibold mb-1">Template meetup</div>
                <div class="small text-muted mb-1"><i class="bi bi-geo-alt-fill text-danger"></i> Starts at Ormoc City Port · no tour date (tourists set their own)</div>
                <?php if ($canEdit): ?>
                  <form method="post" data-itin-date-fields id="itinEditScheduleForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_meetup" id="itinEditAction">
                    <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                    <div class="row g-2">
                      <div class="col-12" data-itin-tour="meetup">
                        <label class="form-label small mb-0">Default meetup time</label>
                        <input type="time" class="form-control form-control-sm" name="meetup_time" value="<?= e(time_picker_value($meetupDisplay, '8:00 AM')) ?>" step="60" required>
                      </div>
                      <div class="col-12">
                        <div class="itin-multiday-row" data-itin-tour="multiday">
                          <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="is_multiday" value="1" id="itinEditMultiday" data-itin-multiday<?= $isMultidayTour ? ' checked' : '' ?>>
                            <label class="form-check-label small" for="itinEditMultiday">Multi-day</label>
                          </div>
                          <div class="itin-multiday-row__days<?= $isMultidayTour ? '' : ' d-none' ?>" data-itin-end-wrap>
                            <select class="form-select form-select-sm" name="day_count" data-itin-day-count aria-label="Number of days">
                              <?php for ($d = 2; $d <= 7; $d++): ?>
                                <option value="<?= $d ?>"<?= $dayCountDisplay === $d ? ' selected' : '' ?>><?= $d ?> days</option>
                              <?php endfor; ?>
                    </select>
                  </div>
                        </div>
                      </div>
                    </div>
                    <div class="itin-edit-actions mt-2">
                      <button type="submit" class="itin-edit-actions__btn btn btn-sm btn-ct" data-itin-auto-generate data-itin-tour="auto-generate">
                        Auto-generate
                      </button>
                    </div>
                </form>
                <?php else: ?>
                  <div class="small mt-1 mb-0">
                    <?php if ($isMultidayTour): ?>
                      <?= (int) $dayCountDisplay ?> days
                    <?php else: ?>
                      1 day
                    <?php endif; ?>
                    · Meetup <?= e($meetupDisplay) ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($canEdit): ?>
                <div class="itin-rich-picker__panel d-none" data-itin-rich-panel hidden>
                  <div class="itin-rich-picker__head">
                    <div class="itin-rich-picker__head-text">
                      <div class="itin-rich-picker__head-title">Choose a place</div>
                      <div class="itin-rich-picker__head-hint">Drag a place to Route stops</div>
                    </div>
                    <button type="button" class="itin-rich-picker__close" data-itin-rich-close aria-label="Close place picker">
                      <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                  </div>
                  <div class="itin-rich-picker__tabs" role="tablist">
                    <button type="button" class="itin-rich-picker__tab is-active" data-itin-rich-tab="destination" role="tab" aria-selected="true">Destinations</button>
                    <button type="button" class="itin-rich-picker__tab" data-itin-rich-tab="business" role="tab" aria-selected="false">Businesses</button>
                  </div>
                  <div class="itin-rich-picker__search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" class="form-control form-control-sm" placeholder="Search places…" data-itin-rich-search autocomplete="off">
                  </div>
                  <div class="itin-rich-picker__list" data-itin-rich-list role="listbox"></div>
                </div>
              </div>
              <?php endif; ?>

              <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-4 itin-builder__col itin-builder__col--stops">
          <div class="glass p-3 p-md-4 h-100 itin-builder__stops-card"<?= $view ? ' data-itin-tour="stops"' : '' ?><?= $canEdit ? ' data-itin-place-drop-root' : '' ?>>
            <?php if (!$view): ?>
              <h3 class="panel-title mb-2">Route stops</h3>
              <p class="text-muted mb-0">Open a tour to see its stop list here.</p>
            <?php else: ?>
              <div class="itin-plan-head mb-2">
                <div class="itin-plan-head__row">
                  <h3 class="panel-title mb-0"><?= $isMultidayTour ? 'Day-by-day plan' : 'Route stops' ?></h3>
                  <div class="itin-plan-head__actions">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/itinerary-share.php?id=<?= (int) $view['id'] ?>" target="_blank" rel="noopener">Share / PDF</a>
                    <?php if ($canEdit): ?>
                      <form method="post" class="d-inline" data-itin-confirm="delete"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($canEdit && count($stops) > 1): ?>
                  <div class="itin-plan-head__hint">Drag to reorder</div>
                <?php endif; ?>
              </div>
              <div class="itin-stop-list itin-stop-list--panel"<?= $isMultidayTour ? ' data-itin-day-scroll' : '' ?>>
                <?php foreach ($stopsByDay as $g): ?>
                  <div class="itin-day<?= $isMultidayTour ? '' : ' itin-day--single' ?>" data-itin-day-section="<?= (int) $g['day'] ?>">
                    <?php if ($isMultidayTour): ?>
                      <div class="itin-day__head">
                        <span class="itin-day__head-main" data-itin-day-toggle role="button" tabindex="0" aria-expanded="true" aria-label="Toggle Day <?= (int) $g['day'] ?>">
                          <i class="bi bi-chevron-down itin-day__chevron" aria-hidden="true"></i>
                          <strong><?= e($g['label']) ?></strong>
                        </span>
                      </div>
                    <?php endif; ?>
                    <?php if ((int) $g['day'] === 1): ?>
                      <div class="itin-item itin-item--meetup">
                  <div>
                          <div class="small text-muted">Start · <?= e($meetupDisplay) ?></div>
                          <div>Ormoc City Port</div>
                          <div class="small text-muted">Meetup point</div>
                  </div>
                      </div>
                    <?php endif; ?>
                    <?php if (!$g['stops']): ?>
                      <p class="small text-muted mb-0 mt-1"><?= $isMultidayTour ? 'No stops on this day yet.' : ('No stops yet' . ($canEdit ? ' — add a place on the left.' : '.')) ?></p>
                    <?php endif; ?>
                    <div class="itin-day__stops" data-itin-day="<?= (int) $g['day'] ?>"<?= $canEdit ? ' data-itin-sortable data-itin-id="' . (int) $view['id'] . '"' : '' ?>>
                      <?php foreach ($g['stops'] as $idx => $it): ?>
                        <?php
                          $arrive = format_time_12h(isset($it['start_time']) ? (string) $it['start_time'] : null);
                          $leave = format_time_12h(isset($it['end_time']) ? (string) $it['end_time'] : null);
                          $travel = isset($it['travel_minutes']) ? (int) $it['travel_minutes'] : null;
                          $visit = (int) ($it['visit_minutes'] ?? 60);
                          $stopLat = $it['latitude'] !== null ? (float) $it['latitude'] : '';
                          $stopLng = $it['longitude'] !== null ? (float) $it['longitude'] : '';
                          $stopKind = (($it['stop_kind'] ?? '') === 'business') ? 'business' : 'destination';
                          $stopCover = trim((string) ($it['cover_url'] ?? ''));
                          $stopLimitKey = !empty($it['business_id']) ? 'b' . (int) $it['business_id'] : 'd' . (int) ($it['destination_id'] ?? 0);
                          $stopLimit = $placeVisitLimits[$stopLimitKey] ?? null;
                        ?>
                        <div class="itin-item<?= $canEdit ? ' itin-item--draggable' : '' ?>"
                             data-id="<?= (int) $it['id'] ?>"
                             data-itin-stop
                             data-lat="<?= e((string) $stopLat) ?>"
                             data-lng="<?= e((string) $stopLng) ?>"
                             data-visit="<?= (int) $visit ?>"
                             data-visit-limit="<?= $stopLimit !== null ? (int) $stopLimit : '' ?>"
                             data-place-name="<?= e((string) ($it['stop_name'] ?? $it['destination_name'] ?? '')) ?>"
                             data-kind="<?= e($stopKind) ?>">
                          <div class="itin-item__main">
                            <div class="itin-item__body">
                              <span class="itin-item__thumb<?= $stopCover !== '' ? ' has-image' : '' ?>"<?= $stopCover !== '' ? ' style="background-image:url(\'' . e($stopCover) . '\')"' : '' ?> aria-hidden="true"></span>
                              <div class="itin-item__info">
                            <?php if ($canEdit): ?>
                              <div class="itin-item__actions">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-itin-edit-time>Edit</button>
                                <form method="post" data-itin-confirm="remove" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove_stop">
                                  <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                                  <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                  <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                  </form>
                              </div>
                            <?php endif; ?>
                              <div class="small text-muted">
                                <?php if ($canEdit): ?><i class="bi bi-grip-vertical itin-item__handle"></i><?php endif; ?>
                                Stop <span data-itin-stop-num><?= $idx + 1 ?></span>
                                · <?= $stopKind === 'business' ? 'Business' : 'Destination' ?><span data-itin-travel-wrap><?php if ($travel !== null && $travel > 0): ?> · ~<span data-itin-travel><?= (int) $travel ?></span> min drive<?php endif; ?></span>
                              </div>
                              <div><?= e($it['stop_name'] ?? $it['destination_name'] ?? 'Unknown place') ?></div>
                              <div class="small text-muted"><?= e($it['location'] ?? '') ?></div>
                              <div class="small itin-stop-times" data-itin-stop-times>
                                <span>Estimated time of arrival <span data-itin-arrive><?= e($arrive) ?></span> · Leave <span data-itin-leave><?= e($leave) ?></span></span>
                                <span class="itin-stop-times__stay">Stay <span data-itin-stay><?php
                                $stayH = intdiv($visit, 60);
                                $stayM = $visit % 60;
                                if ($stayH > 0 && $stayM > 0) {
                                    echo e($stayH . ' hr ' . $stayM . ' min');
                                } elseif ($stayH > 0) {
                                    echo e($stayH . ' hr');
                                } else {
                                    echo e($stayM . ' min');
                                }
                              ?></span></span>
                              </div>
                              </div>
                            </div>
                          </div>
                          <?php if ($canEdit): ?>
                            <form method="post" class="itin-edit-time d-none" data-itin-edit-time-form novalidate><?= csrf_field() ?>
                              <input type="hidden" name="action" value="update_stop_stay">
                              <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                              <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                              <label class="form-label small mb-0">Stay</label>
                              <div class="itin-edit-time__row">
                                <div class="itin-visit-clock-wrap">
                                  <input type="text" class="form-control form-control-sm itin-visit-clock" data-itin-visit-clock
                                         inputmode="numeric" autocomplete="off" placeholder="--:--" maxlength="5"
                                         aria-label="Stay hours and minutes"
                                         value="<?= e(sprintf('%02d:%02d', intdiv($visit, 60), $visit % 60)) ?>">
                                  <span class="itin-visit-clock__unit" data-itin-visit-unit><?= intdiv($visit, 60) < 1 ? 'mins' : (intdiv($visit, 60) === 1 ? 'hour' : 'hours') ?></span>
                                </div>
                                <input type="hidden" name="visit_minutes" value="<?= (int) $visit ?>">
                                <button type="submit" class="btn btn-sm btn-ct itin-edit-time__btn">Save</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary itin-edit-time__btn" data-itin-edit-time-cancel>Cancel</button>
                              </div>
                              <div class="form-text text-danger d-none" data-itin-visit-limit-warn></div>
                              <div class="form-text"><?= $stopLimit !== null ? 'Visit duration: up to ' . e(format_visit_duration_label((int) $stopLimit)) . '. ' : '' ?>Leave time updates; later stops this day re-adjust.</div>
                            </form>
                          <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
                    <?php if ($canEdit): ?>
                      <div class="itin-place-dropzone" data-itin-place-drop data-itin-day="<?= (int) $g['day'] ?>">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                        <span><?= $isMultidayTour ? 'Drop a place on Day ' . (int) $g['day'] : 'Drop a place here' ?></span>
            </div>
            <?php endif; ?>
                  </div>
                <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

        <div class="col-lg-4 itin-builder__col itin-builder__col--map">
          <div class="glass p-3 p-md-4 h-100 itin-builder__map-card" data-itin-tour="map">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <h3 class="panel-title mb-0">Route map</h3>
              <span class="small text-muted" data-itin-map-sub><?= $isMultidayTour ? 'Day 1 · Port → stops' : 'Road line from Port → your stops' ?></span>
    </div>
            <div id="itinRouteMap" class="ct-map ct-map--tall itin-builder__map"></div>
  </div>
</div>
      </div>

<?php if ($canEdit && $view): ?>
<div class="modal fade itin-leave-overlay" id="itinLeaveModal" tabindex="-1" aria-labelledby="itinLeaveModalLabel" aria-hidden="true" data-bs-backdrop="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinLeaveModalLabel">Save this template as a draft?</h2>
        <p class="itin-leave-card__text">You’ve added details to this suggested tour. Save it as a draft to keep working later (tourists won’t see it until you press Save), or discard it.</p>
      </div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-itin-leave-save>Save draft</button>
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--danger" data-itin-leave-discard>Discard itinerary</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal" data-itin-leave-keep>Keep editing</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade itin-leave-overlay" id="itinConfirmModal" tabindex="-1" aria-labelledby="itinConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinConfirmModalLabel">Are you sure?</h2>
        <p class="itin-leave-card__text" data-itin-confirm-text>This cannot be undone.</p>
      </div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--danger" data-itin-confirm-ok>Yes</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal" data-itin-confirm-cancel>Cancel</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade itin-leave-overlay" id="itinVisitDropModal" tabindex="-1" aria-labelledby="itinVisitDropLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinVisitDropLabel">Visit time</h2>
        <p class="itin-leave-card__text" data-itin-visit-drop-place>How long will tourists stay at this stop?</p>
        <div class="itin-leave-card__field">
          <label class="form-label small mb-1">How long is the visit?</label>
          <div class="itin-visit-clock-wrap">
            <input type="text" class="form-control itin-visit-clock" id="itinVisitDropClock" data-itin-visit-clock
                   inputmode="numeric" autocomplete="off" placeholder="--:--" maxlength="5"
                   aria-label="Visit time hours and minutes">
            <span class="itin-visit-clock__unit" data-itin-visit-unit></span>
          </div>
          <div class="form-text d-none" data-itin-visit-limit-hint></div>
          <div class="form-text text-danger d-none" data-itin-visit-limit-warn></div>
        </div>
      </div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-itin-visit-drop-ok>Add stop</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/map.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/map.js') ?: time() ?>"></script>
<script>
CulTourMap.renderItineraryRouteMap('itinRouteMap', <?= json_encode($mapStops, JSON_UNESCAPED_UNICODE) ?>, {
  dayCount: <?= (int) $dayCountDisplay ?>
});
<?php if (!empty($isMultidayTour) && $view): ?>
(function () {
  var list = document.querySelector('[data-itin-day-scroll]');
  var mapEl = document.getElementById('itinRouteMap');
  if (!list || !mapEl) return;

  var currentDay = '1';
  var lockFromChip = false;
  var lockTimer = null;

  function setMapDay(day) {
    var next = String(day || '1');
    if (next === currentDay) return;
    currentDay = next;
    if (typeof mapEl._ctSetDayFilter === 'function') {
      mapEl._ctSetDayFilter(next);
    } else {
      // Map init is async — retry briefly
      var tries = 0;
      var t = setInterval(function () {
        tries++;
        if (typeof mapEl._ctSetDayFilter === 'function') {
          mapEl._ctSetDayFilter(next);
          clearInterval(t);
        } else if (tries > 40) {
          clearInterval(t);
        }
      }, 50);
    }
  }

  // When user clicks a map day chip, don't fight scroll sync for a moment
  document.addEventListener('click', function (e) {
    var chip = e.target.closest('[data-itin-map-days] [data-day]');
    if (!chip) return;
    var day = chip.getAttribute('data-day');
    if (!day || day === 'all') {
      lockFromChip = true;
      currentDay = day || 'all';
      clearTimeout(lockTimer);
      lockTimer = setTimeout(function () { lockFromChip = false; }, 1200);
      return;
    }
    lockFromChip = true;
    currentDay = day;
    var section = list.querySelector('[data-itin-day-section="' + day + '"]');
    if (section) {
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    clearTimeout(lockTimer);
    lockTimer = setTimeout(function () { lockFromChip = false; }, 1200);
  });

  if (typeof IntersectionObserver === 'undefined') {
    list.addEventListener('scroll', function () {
      if (lockFromChip) return;
      var rootTop = list.getBoundingClientRect().top;
      var best = null;
      var bestDist = Infinity;
      list.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
        var day = sec.getAttribute('data-itin-day-section');
        var dist = Math.abs(sec.getBoundingClientRect().top - rootTop);
        if (dist < bestDist) {
          bestDist = dist;
          best = day;
        }
      });
      if (best) setMapDay(best);
    });
    return;
  }

  var ratios = {};
  var io = new IntersectionObserver(
    function (entries) {
      if (lockFromChip) return;
      entries.forEach(function (entry) {
        var day = entry.target.getAttribute('data-itin-day-section');
        if (!day) return;
        ratios[day] = entry.isIntersecting ? entry.intersectionRatio : 0;
      });
      var bestDay = null;
      var bestRatio = 0;
      Object.keys(ratios).forEach(function (day) {
        if (ratios[day] > bestRatio) {
          bestRatio = ratios[day];
          bestDay = day;
        }
      });
      // Prefer the topmost visible day when ratios are close
      if (bestRatio < 0.12) {
        var rootTop = list.getBoundingClientRect().top;
        var nearest = null;
        var nearestDist = Infinity;
        list.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
          var day = sec.getAttribute('data-itin-day-section');
          var rect = sec.getBoundingClientRect();
          if (rect.bottom <= rootTop + 8) return;
          var dist = Math.abs(rect.top - rootTop);
          if (dist < nearestDist) {
            nearestDist = dist;
            nearest = day;
          }
        });
        if (nearest) bestDay = nearest;
      }
      if (bestDay) setMapDay(bestDay);
    },
    {
      root: list,
      threshold: [0, 0.15, 0.35, 0.55, 0.75, 1],
      rootMargin: '-8% 0px -45% 0px'
    }
  );

  list.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
    io.observe(sec);
  });
})();

(function () {
  var itinId = <?= (int) $view['id'] ?>;
  var storageKey = 'cultour_guide_itin_day_collapsed_' + itinId;

  function loadMap() {
    try {
      return JSON.parse(sessionStorage.getItem(storageKey) || '{}') || {};
    } catch (err) {
      return {};
    }
  }
  function saveMap(map) {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(map || {}));
    } catch (err) {}
  }
  function setCollapsed(sec, collapsed) {
    if (!sec || sec.classList.contains('itin-day--single')) return;
    var day = sec.getAttribute('data-itin-day-section');
    sec.classList.toggle('is-collapsed', !!collapsed);
    var head = sec.querySelector('[data-itin-day-toggle]');
    if (head) head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    var map = loadMap();
    if (collapsed) map[String(day)] = 1;
    else delete map[String(day)];
    saveMap(map);
  }
  function applySaved() {
    var map = loadMap();
    document.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
      if (sec.classList.contains('itin-day--single')) return;
      var day = sec.getAttribute('data-itin-day-section');
      setCollapsed(sec, !!map[String(day)]);
    });
  }
  window.itinEnsureDayExpanded = function (day) {
    var sec = document.querySelector('[data-itin-day-section="' + String(day) + '"]');
    if (!sec) return;
    setCollapsed(sec, false);
  };
  window.itinApplyDayCollapsed = applySaved;

  document.addEventListener('click', function (e) {
    var head = e.target.closest('[data-itin-day-toggle]');
    if (!head) return;
    var sec = head.closest('[data-itin-day-section]');
    if (!sec || sec.classList.contains('itin-day--single')) return;
    e.preventDefault();
    setCollapsed(sec, !sec.classList.contains('is-collapsed'));
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var head = e.target.closest('[data-itin-day-toggle]');
    if (!head) return;
    var sec = head.closest('[data-itin-day-section]');
    if (!sec || sec.classList.contains('itin-day--single')) return;
    e.preventDefault();
    setCollapsed(sec, !sec.classList.contains('is-collapsed'));
  });

  applySaved();
})();
<?php endif; ?>
<?php if ($canEdit && $view): ?>
(function () {
  var cfg = {
    itinId: <?= (int) $view['id'] ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    hasContent: <?= $hasItinContent ? 'true' : 'false' ?>,
    keptAsDraft: <?= $keptAsDraft ? 'true' : 'false' ?>,
    dirty: false,
    existingStops: <?= json_encode($existingStopsForJs, JSON_UNESCAPED_UNICODE) ?>,
    meetupMins: <?= (int) (itin_time_to_minutes((string) ($view['meetup_time'] ?? '08:00')) ?? (8 * 60)) ?>,
    port: <?= json_encode([
        'lat' => (float) ormoc_port_coords()['lat'],
        'lng' => (float) ormoc_port_coords()['lng'],
    ], JSON_UNESCAPED_UNICODE) ?>
  };
  window.CulTourItinStops = cfg.existingStops || [];
  window.CulTourItinTiming = {
    meetupMins: cfg.meetupMins,
    port: cfg.port
  };
  var pendingUrl = null;
  var pendingForm = null;
  var bypass = false;
  var modalEl = document.getElementById('itinLeaveModal');
  var backdropEl = null;

  function shouldPrompt() {
    if (bypass) return false;
    if (cfg.dirty) return true;
    if (cfg.hasContent && !cfg.keptAsDraft) return true;
    return false;
  }

  function markDirty() {
    cfg.dirty = true;
  }

  function getBsModal() {
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
    return bootstrap.Modal.getOrCreateInstance(modalEl);
  }

  function ensureBackdrop() {
    if (backdropEl) return backdropEl;
    backdropEl = document.createElement('div');
    backdropEl.className = 'itin-leave-backdrop';
    backdropEl.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdropEl);
    backdropEl.addEventListener('click', closeLeave);
    return backdropEl;
  }

  function openLeave(url, form) {
    pendingUrl = url || null;
    pendingForm = form || null;
    var bs = getBsModal();
    if (bs) {
      bs.show();
      return;
    }
    // Fallback if Bootstrap JS is unavailable — still use the card UI, never window.confirm
    if (!modalEl) return;
    ensureBackdrop();
    backdropEl.classList.add('is-open');
    modalEl.classList.add('show', 'd-block');
    modalEl.style.display = 'block';
    modalEl.removeAttribute('aria-hidden');
    modalEl.setAttribute('aria-modal', 'true');
    document.body.classList.add('itin-leave-open');
  }

  function closeLeave() {
    var bs = getBsModal();
    if (bs) {
      bs.hide();
      return;
    }
    if (!modalEl) return;
    modalEl.classList.remove('show', 'd-block');
    modalEl.style.display = 'none';
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.removeAttribute('aria-modal');
    if (backdropEl) backdropEl.classList.remove('is-open');
    document.body.classList.remove('itin-leave-open');
  }

  function goPending() {
    bypass = true;
    if (pendingForm) {
      pendingForm.submit();
      return;
    }
    if (pendingUrl) {
      window.location.href = pendingUrl;
    }
  }

  function postAction(action, extra) {
    var body = new FormData();
    body.append('csrf_token', cfg.csrf);
    body.append('action', action);
    if (action === 'delete') body.append('id', String(cfg.itinId));
    else body.append('itinerary_id', String(cfg.itinId));
    if (extra) {
      Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
    }
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: body,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); });
  }

  document.querySelectorAll(
    '#itinRenameForm [name="title"], #itinEditScheduleForm input, #itinEditScheduleForm select, #itinAddStopForm input, #itinAddStopForm select'
  ).forEach(function (el) {
    el.addEventListener('change', markDirty);
    el.addEventListener('input', markDirty);
  });

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || !shouldPrompt()) return;
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    if (a.getAttribute('data-bs-toggle') === 'modal') return;
    if (a.closest('#itinLeaveModal') || a.closest('#itinConfirmModal')) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;
    try {
      var next = new URL(href, window.location.href);
      var cur = new URL(window.location.href);
      var sameItin = next.pathname === cur.pathname
        && (next.searchParams.get('id') || '') === String(cfg.itinId)
        && !next.searchParams.get('book');
      if (sameItin) return;
      // Leaving this tour builder page / switching tour
      if (next.origin !== cur.origin) return;
    } catch (err) {
      return;
    }
    e.preventDefault();
    openLeave(a.href, null);
  }, true);

  function isInternalItinSubmit(form) {
    if (!form || !(form instanceof HTMLFormElement)) return false;
    if (form.closest('#itinLeaveModal') || form.closest('#itinConfirmModal')) return true;
    if (form.id === 'itinRenameForm' || form.id === 'itinEditScheduleForm' || form.id === 'itinAddStopForm' || form.id === 'itinPublishForm') return true;
    var action = (form.querySelector('[name="action"]') || {}).value || '';
    return action === 'delete'
      || action === 'remove_stop'
      || action === 'update_stop_stay'
      || action === 'reorder_stops'
      || action === 'update_title'
      || action === 'update_meetup'
      || action === 'add_stop'
      || action === 'auto_regenerate'
      || action === 'auto_generate'
      || action === 'publish'
      || action === 'save_draft';
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    // Adding stops / saving schedule reloads this page — never treat as "leave site"
    if (isInternalItinSubmit(form)) {
      bypass = true;
      return;
    }
    if (!shouldPrompt()) return;
    e.preventDefault();
    openLeave(null, form);
  }, true);

  // No native beforeunload / "localhost says" dialogs — leave uses the custom modal only.

  var saveBtn = document.querySelector('[data-itin-leave-save]');
  var discardBtn = document.querySelector('[data-itin-leave-discard]');
  var keepBtn = document.querySelector('[data-itin-leave-keep]');
  if (keepBtn) {
    keepBtn.addEventListener('click', function () {
      pendingUrl = null;
      pendingForm = null;
      closeLeave();
    });
  }
  if (modalEl) {
    modalEl.addEventListener('hidden.bs.modal', function () {
      if (bypass) return;
      pendingUrl = null;
      pendingForm = null;
    });
  }
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      saveBtn.disabled = true;
      postAction('save_draft').then(function (data) {
        if (!data || !data.ok) throw new Error((data && data.message) || 'fail');
        cfg.keptAsDraft = true;
        cfg.dirty = false;
        closeLeave();
        goPending();
      }).catch(function (err) {
        saveBtn.disabled = false;
        saveBtn.textContent = (err && err.message && err.message !== 'fail')
          ? err.message
          : 'Could not save — try again';
        setTimeout(function () { saveBtn.textContent = 'Save draft'; }, 2800);
      });
    });
  }
  if (discardBtn) {
    discardBtn.addEventListener('click', function () {
      discardBtn.disabled = true;
      postAction('delete').then(function (data) {
        if (!data || !data.ok) throw new Error('fail');
        cfg.hasContent = false;
        cfg.dirty = false;
        cfg.keptAsDraft = false;
        closeLeave();
        if (!pendingUrl && !pendingForm) {
          pendingUrl = <?= json_encode(APP_URL . '/tourguide/itineraries.php') ?>;
        }
        goPending();
      }).catch(function () {
        discardBtn.disabled = false;
        discardBtn.textContent = 'Could not discard — try again';
        setTimeout(function () { discardBtn.textContent = 'Discard itinerary'; }, 2200);
      });
    });
  }
})();
(function () {
  var modalEl = document.getElementById('itinConfirmModal');
  if (!modalEl) return;
  var titleEl = document.getElementById('itinConfirmModalLabel');
  var textEl = modalEl.querySelector('[data-itin-confirm-text]');
  var okBtn = modalEl.querySelector('[data-itin-confirm-ok]');
  var pendingForm = null;
  var allowSubmit = false;
  var copy = {
    delete: {
      title: 'Delete this itinerary?',
      text: 'This tour and its stops will be removed. This cannot be undone.',
      ok: 'Delete'
    },
    remove: {
      title: 'Remove this stop?',
      text: 'This stop will be removed from your route.',
      ok: 'Remove'
    },
    duplicate: {
      title: 'Place already on this tour',
      text: 'You already added this stop. Add it again anyway?',
      ok: 'Proceed'
    },
    adjacent: {
      title: 'Same place twice in a row',
      text: 'You can’t put the same place as the next stop. Add a different place in between first.',
      ok: ''
    }
  };

  var pendingCallback = null;

  function getModal() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
    return bootstrap.Modal.getOrCreateInstance(modalEl);
  }

  function openConfirm(kind, form, opts) {
    opts = opts || {};
    var c = copy[kind] || copy.delete;
    if (titleEl) titleEl.textContent = opts.title || c.title;
    if (textEl) textEl.textContent = opts.text || c.text;
    var okLabel = opts.ok != null ? opts.ok : c.ok;
    if (okBtn) {
      if (okLabel) {
        okBtn.textContent = okLabel;
        okBtn.classList.remove('d-none');
      } else {
        okBtn.classList.add('d-none');
      }
    }
    pendingForm = form || null;
    pendingCallback = typeof opts.onProceed === 'function' ? opts.onProceed : null;
    var m = getModal();
    if (m) {
      m.show();
      return;
    }
    if (pendingCallback) {
      var cb = pendingCallback;
      pendingCallback = null;
      cb();
      return;
    }
    if (form) {
      allowSubmit = true;
      form.submit();
    }
  }

  window.CulTourItinConfirm = {
    open: openConfirm,
    copy: copy
  };

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var kind = form.getAttribute('data-itin-confirm');
    if (!kind) return;
    if (allowSubmit && pendingForm === form) {
      allowSubmit = false;
      pendingForm = null;
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    openConfirm(kind, form);
  }, true);

  if (okBtn) {
    okBtn.addEventListener('click', function () {
      var cb = pendingCallback;
      var form = pendingForm;
      pendingCallback = null;
      allowSubmit = !!form;
      var m = getModal();
      if (m) m.hide();
      if (cb) {
        cb();
        return;
      }
      if (!form) return;
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    });
  }
  modalEl.addEventListener('hidden.bs.modal', function () {
    if (!allowSubmit) {
      pendingForm = null;
      pendingCallback = null;
    }
    if (okBtn) okBtn.classList.remove('d-none');
  });
})();
<?php endif; ?>
(function () {
  function bindMultiday(root) {
    var toggle = root.querySelector('[data-itin-multiday]');
    var wrap = root.querySelector('[data-itin-end-wrap]');
    if (!toggle || !wrap) return;
    function sync() {
      // Only hide/show — never disable day_count (disabled fields are omitted from submit)
      wrap.classList.toggle('d-none', !toggle.checked);
    }
    toggle.addEventListener('change', sync);
    sync();
  }
  document.querySelectorAll('[data-itin-date-fields]').forEach(bindMultiday);
})();
(function () {
  var form = document.getElementById('itinEditScheduleForm');
  if (!form) return;
  var actionInput = document.getElementById('itinEditAction');
  var meetup = form.querySelector('[name="meetup_time"]');
  var dateEl = form.querySelector('[name="start_date"]');
  var multiEl = form.querySelector('[data-itin-multiday]');
  var dayCountEl = form.querySelector('[data-itin-day-count]');
  var addDayWrap = document.querySelector('[data-itin-add-day-wrap]');
  var addDaySel = document.querySelector('[data-itin-add-day]');
  var timer = null;
  var saving = false;
  var suppressReload = false;

  function hasTourDate() {
    return !!(dateEl && String(dateEl.value || '').trim());
  }

  function tomorrowYmd() {
    var d = new Date();
    d.setDate(d.getDate() + 1);
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function formatDayOption(dayNum, startYmd) {
    return 'Day ' + dayNum;
  }

  function syncAddDayPicker() {
    if (!addDayWrap || !addDaySel) return;
    var multiOn = !!(multiEl && multiEl.checked);
    addDayWrap.classList.toggle('d-none', !multiOn);
    var n = 1;
    if (multiOn) {
      n = parseInt(dayCountEl && dayCountEl.value ? dayCountEl.value : '2', 10);
      if (!n || n < 2) n = 2;
      if (n > 7) n = 7;
    }
    var prev = addDaySel.value;
    addDaySel.innerHTML = '';
    for (var d = 1; d <= n; d++) {
      var opt = document.createElement('option');
      opt.value = String(d);
      opt.textContent = formatDayOption(d);
      addDaySel.appendChild(opt);
    }
    if (prev && parseInt(prev, 10) <= n) addDaySel.value = prev;
    else addDaySel.value = '1';
  }

  function saveSchedule(opts) {
    opts = opts || {};
    if (saving || suppressReload) return;
    if (actionInput) actionInput.value = 'update_meetup';
    if (meetup && !String(meetup.value || '').trim()) return;

    var body = new FormData(form);
    body.set('action', 'update_meetup');

    saving = true;
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: body,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      saving = false;
      if (!data || !data.ok) {
        return;
      }
      syncAddDayPicker();
      if (opts.reload && !suppressReload) {
        window.location.reload();
      }
    }).catch(function () {
      saving = false;
    });
  }

  syncAddDayPicker();

  if (multiEl) {
    multiEl.addEventListener('change', function () {
      syncAddDayPicker();
      saveSchedule({ structureOnly: true, reload: true });
    });
  }
  if (dayCountEl) {
    dayCountEl.addEventListener('change', function () {
      syncAddDayPicker();
      saveSchedule({ structureOnly: true, reload: true });
    });
  }
  if (meetup) {
    meetup.addEventListener('change', function () { saveSchedule({}); });
    meetup.addEventListener('blur', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { saveSchedule({}); }, 150);
    });
    meetup.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        saveSchedule({});
      }
    });
  }

  var autoBtn = form.querySelector('[data-itin-auto-generate]');
  if (autoBtn) {
    autoBtn.addEventListener('click', function (e) {
      e.preventDefault();
      // Block in-flight schedule reloads from cancelling this submit
      suppressReload = true;
      if (actionInput) actionInput.value = 'auto_regenerate';
      if (typeof form.requestSubmit === 'function') form.requestSubmit(autoBtn);
      else form.submit();
    });
  }
})();
<?php if ($canEdit && $view): ?>
(function () {
  var form = document.getElementById('itinRenameForm');
  if (!form) return;
  var input = form.querySelector('[name="title"]');
  if (!input) return;
  var lastSaved = String(input.value || '').trim() || 'Unnamed';
  var timer = null;
  var itinId = form.querySelector('[name="itinerary_id"]');
  itinId = itinId ? itinId.value : '';

  function saveTitle() {
    var title = String(input.value || '').trim() || 'Unnamed';
    if (title === lastSaved) return;
    var body = new FormData(form);
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: body,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (!data || !data.ok) {
        var fb = form.querySelector('[data-itin-title-feedback]');
        if (fb) {
          fb.textContent = (data && data.message) ? data.message : 'Could not save the name.';
          fb.hidden = false;
        }
        return;
      }
      var titleFb = form.querySelector('[data-itin-title-feedback]');
      if (titleFb) titleFb.hidden = true;
      lastSaved = data.title || title;
      input.value = lastSaved;
      var pubTitle = document.getElementById('itinPublishTitle');
      if (pubTitle) pubTitle.value = lastSaved;
      var tabs = document.querySelector('.itin-suggest-tabs');
      var href = '?id=' + itinId;
      var chip = null;
      document.querySelectorAll('.itin-suggest-tabs a.quick-chip').forEach(function (a) {
        if (itinId && a.getAttribute('href') && a.getAttribute('href').indexOf('id=' + itinId) !== -1) {
          chip = a;
        }
      });
      var isPlaceholder = !lastSaved || String(lastSaved).toLowerCase() === 'unnamed';
      if (isPlaceholder) {
        if (chip) chip.remove();
        return;
      }
      if (chip) {
        chip.textContent = lastSaved;
        chip.classList.add('is-active', 'fw-semibold');
      } else if (tabs) {
        chip = document.createElement('a');
        chip.className = 'quick-chip is-active fw-semibold';
        chip.href = href;
        chip.textContent = lastSaved;
        var createForm = tabs.querySelector('form');
        if (createForm && createForm.nextSibling) {
          tabs.insertBefore(chip, createForm.nextSibling);
        } else {
          tabs.appendChild(chip);
        }
      }
    }).catch(function () {});
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(saveTitle, 500);
  });
  input.addEventListener('blur', function () {
    clearTimeout(timer);
    saveTitle();
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(timer);
      saveTitle();
      input.blur();
    }
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearTimeout(timer);
    saveTitle();
  });
})();
(function () {
  var pub = document.getElementById('itinPublishForm');
  var titleInput = document.getElementById('itinTourTitle');
  var pubTitle = document.getElementById('itinPublishTitle');
  if (!pub) return;
  pub.addEventListener('submit', function () {
    if (titleInput && pubTitle) {
      pubTitle.value = String(titleInput.value || '').trim() || 'Unnamed';
    }
  });
})();
CulTourMap.bindItineraryStopTiming(document.getElementById('itinAddStopForm'), {
  places: <?= json_encode($placesForJs, JSON_UNESCAPED_UNICODE) ?>,
  dayKey: <?= json_encode($itinDayKey) ?>,
  dayKeys: <?= json_encode($dayKeysForJs, JSON_UNESCAPED_UNICODE) ?>,
  dayPrevs: <?= json_encode($dayPrevsForJs, JSON_UNESCAPED_UNICODE) ?>,
  prev: {
    lat: <?= json_encode((float) $prevLeg['lat']) ?>,
    lng: <?= json_encode((float) $prevLeg['lng']) ?>,
    leaveMins: <?= (int) $prevLeg['leave_mins'] ?>,
    label: <?= json_encode($prevLeg['label'], JSON_UNESCAPED_UNICODE) ?>,
    skipTravel: <?= !empty($prevLeg['skip_travel']) ? 'true' : 'false' ?>,
    suggestMins: <?= (int) ($prevLeg['suggest_mins'] ?? $prevLeg['leave_mins'] ?? 8 * 60) ?>
  }
});
window.itinVisitHm = {
  maxMinutes: <?= (int) ITIN_MAX_VISIT_MINUTES ?>,
  maxHours: <?= (int) intdiv(ITIN_MAX_VISIT_MINUTES, 60) ?>,
  format: function (raw) {
    var maxH = window.itinVisitHm.maxHours || 12;
    var digits = String(raw || '').replace(/\D/g, '').slice(0, 4);
    if (!digits) return '';

    var hourStr = '';
    var rest = '';
    var d0 = digits.charAt(0);

    if (d0 === '0') {
      if (digits.length === 1) return '0';
      var d1 = digits.charAt(1);
      hourStr = d1;
      rest = digits.slice(2);
    } else if (d0 === '1') {
      if (digits.length === 1) return '1';
      var d1b = digits.charAt(1);
      if (d1b === '0' || d1b === '1' || d1b === '2') {
        hourStr = '1' + d1b;
        rest = digits.slice(2);
      } else {
        hourStr = '1';
        rest = digits.slice(1);
      }
    } else {
      hourStr = d0;
      rest = digits.slice(1);
    }

    var hNum = parseInt(hourStr, 10);
    if (!Number.isFinite(hNum) || hNum < 0) hNum = 0;
    if (hNum > maxH) hNum = maxH;

    var minStr = rest.slice(0, 2);
    if (minStr.length >= 1 && minStr.charAt(0) > '5') {
      minStr = '5' + minStr.slice(1);
    }
    if (minStr.length === 2) {
      var mi = parseInt(minStr, 10);
      if (mi > 59) minStr = '59';
    }

    if (hNum === maxH) {
      var typedMin = minStr === '' ? 0 : parseInt(minStr, 10);
      if (Number.isFinite(typedMin) && typedMin > 0) {
        return String(hNum).padStart(2, '0') + ':00';
      }
      if (!minStr) return String(hNum).padStart(2, '0');
      return String(hNum).padStart(2, '0') + ':' + minStr;
    }

    if (!minStr) {
      if (d0 === '0' || hNum >= 10) return String(hNum).padStart(2, '0');
      return String(hNum);
    }

    return String(hNum).padStart(2, '0') + ':' + minStr;
  },
  pad: function (raw) {
    var maxH = window.itinVisitHm.maxHours || 12;
    var formatted = window.itinVisitHm.format(raw);
    var m = String(formatted || '').match(/^(\d{1,2})(?::(\d{1,2}))?$/);
    if (!m) return formatted;
    var h = parseInt(m[1], 10);
    var min = m[2] !== undefined ? parseInt(m[2], 10) : 0;
    if (!Number.isFinite(h) || h < 0) h = 0;
    if (h > maxH) h = maxH;
    if (h === maxH) min = 0;
    if (!Number.isFinite(min) || min < 0) min = 0;
    if (min > 59) min = 59;
    return String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0');
  },
  fromMinutes: function (mins) {
    var n = parseInt(mins, 10);
    if (!Number.isFinite(n) || n < 0) n = 0;
    return String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');
  },
  total: function (clockEl) {
    var maxH = window.itinVisitHm.maxHours || 12;
    var raw = clockEl ? String(clockEl.value || '').trim() : '';
    if (raw === '') return null;
    var m = raw.match(/^(\d{1,2}):(\d{1,2})$/);
    if (!m) return NaN;
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (!Number.isFinite(h) || h < 0 || h > maxH) return NaN;
    if (h === maxH) min = 0;
    if (!Number.isFinite(min) || min < 0 || min > 59) return NaN;
    return (h * 60) + min;
  },
  ok: function (total) {
    var maxM = window.itinVisitHm.maxMinutes || 720;
    return Number.isFinite(total) && total >= 15 && total <= maxM;
  },
  label: function (mins) {
    var n = parseInt(mins, 10);
    if (!Number.isFinite(n) || n < 0) n = 0;
    var h = Math.floor(n / 60);
    var m = n % 60;
    if (h > 0 && m > 0) return h + ' hr ' + m + ' min';
    if (h > 0) return h + ' hr';
    return m + ' min';
  },
  unitLabel: function (clockEl) {
    var raw = clockEl ? String(clockEl.value || '').trim() : '';
    if (!raw) return '';
    var m = raw.match(/^(\d{1,2})/);
    var h = m ? parseInt(m[1], 10) : 0;
    if (!Number.isFinite(h) || h < 1) return 'mins';
    return h === 1 ? 'hour' : 'hours';
  },
  paintUnit: function (clockEl) {
    if (!clockEl) return;
    var wrap = clockEl.closest('.itin-visit-clock-wrap');
    var unit = wrap ? wrap.querySelector('[data-itin-visit-unit]') : null;
    var label = window.itinVisitHm.unitLabel(clockEl);
    if (unit) unit.textContent = label;
    if (wrap) wrap.classList.toggle('is-empty', label === '');
  },
  bind: function (clockEl) {
    if (!clockEl || clockEl._itinVisitBound) return;
    clockEl._itinVisitBound = true;
    clockEl.addEventListener('input', function () {
      var formatted = window.itinVisitHm.format(clockEl.value);
      if (formatted !== clockEl.value) clockEl.value = formatted;
      clockEl.classList.remove('is-invalid');
      window.itinVisitHm.paintUnit(clockEl);
    });
    clockEl.addEventListener('blur', function () {
      if (!String(clockEl.value || '').trim()) {
        window.itinVisitHm.paintUnit(clockEl);
        return;
      }
      clockEl.value = window.itinVisitHm.pad(clockEl.value);
      window.itinVisitHm.paintUnit(clockEl);
    });
    window.itinVisitHm.paintUnit(clockEl);
  },
  limitMessage: function (name, limit, mins) {
    var cap = parseInt(limit, 10);
    if (!Number.isFinite(cap) || cap <= 0 || !Number.isFinite(mins) || mins <= cap) return '';
    var label = window.itinVisitHm.label(cap);
    return (name || 'This place') + ' has a visit duration of ' + label + ' only. Set the stay to ' + label + ' or less.';
  },
  showWarn: function (warnEl, msg) {
    if (!warnEl) return;
    warnEl.textContent = msg || '';
    warnEl.classList.toggle('d-none', !msg);
  },
  sync: function (root) {
    if (!root) return null;
    var clock = root.querySelector('[data-itin-visit-clock]');
    var hidden = root.querySelector('[name="visit_minutes"]');
    var total = window.itinVisitHm.total(clock);
    if (hidden) hidden.value = window.itinVisitHm.ok(total) ? String(total) : '';
    if (clock) {
      clock.classList.toggle('is-invalid', total != null && !window.itinVisitHm.ok(total));
      window.itinVisitHm.paintUnit(clock);
    }
    return total;
  }
};
(function () {
  var places = <?= json_encode($placesForJs, JSON_UNESCAPED_UNICODE) ?>;
  var root = document.querySelector('[data-itin-rich-picker]');
  if (!root) return;

  var hidden = document.getElementById('itinStopKey');
  var trigger = root.querySelector('[data-itin-rich-trigger]');
  var panel = root.querySelector('[data-itin-rich-panel]');
  var listEl = root.querySelector('[data-itin-rich-list]');
  var searchEl = root.querySelector('[data-itin-rich-search]');
  var titleEl = root.querySelector('[data-itin-rich-trigger-title]');
  var subEl = root.querySelector('[data-itin-rich-trigger-sub]');
  var mediaEl = root.querySelector('[data-itin-rich-trigger-media]');
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-itin-rich-tab]'));
  var closeEl = root.querySelector('[data-itin-rich-close]');
  var kind = 'destination';
  var open = false;
  var query = '';
  var didDrag = false;
  var drag = {
    pointerId: null,
    startX: 0,
    startY: 0,
    key: '',
    active: false,
    ghost: null
  };

  var allPlaces = Object.keys(places).map(function (k) {
    return places[k];
  });

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setStopSortEnabled(on) {
    if (typeof window.itinSetStopSortEnabled === 'function') {
      window.itinSetStopSortEnabled(on);
    }
  }

  function setDropReady(on) {
    document.body.classList.toggle('itin-picking-place', !!on);
    var stopsRoot = document.querySelector('[data-itin-place-drop-root]');
    if (stopsRoot) stopsRoot.classList.toggle('itin-stops-drop-ready', !!on);
  }

  function clearDropOver() {
    document.querySelectorAll('.itin-place-dropzone.is-over').forEach(function (el) {
      el.classList.remove('is-over');
    });
  }

  function setDragging(on) {
    document.body.classList.toggle('itin-place-dragging', !!on);
    var stopsRoot = document.querySelector('[data-itin-place-drop-root]');
    if (stopsRoot) stopsRoot.classList.toggle('itin-stops-dragging', !!on);
    setStopSortEnabled(!on);
    if (!on) clearDropOver();
  }

  function setOpen(next) {
    open = !!next;
    panel.classList.toggle('d-none', !open);
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    root.classList.toggle('is-open', open);
    root.classList.toggle('is-picking', open);
    setDropReady(open);
    if (!open) stopPointerDrag();
    if (open && searchEl) {
      searchEl.focus();
    }
  }

  function filtered() {
    var q = String(query || '').trim().toLowerCase();
    return allPlaces.filter(function (p) {
      if (!p || p.kind !== kind) return false;
      if (!q) return true;
      var hay = ((p.name || '') + ' ' + (p.subtitle || '') + ' ' + (p.location || '') + ' ' + (p.description || '')).toLowerCase();
      return hay.indexOf(q) !== -1;
    }).sort(function (a, b) {
      if (!!a.favorite !== !!b.favorite) return a.favorite ? -1 : 1;
      return String(a.name || '').localeCompare(String(b.name || ''));
    });
  }

  function metaBits(p) {
    var bits = [];
    if (p.subtitle) bits.push(p.subtitle);
    if (p.rating != null && p.rating > 0) bits.push('★ ' + p.rating);
    if (p.fee) bits.push(p.fee);
    if (p.visitLimit && window.itinVisitHm) bits.push('Visit up to ' + window.itinVisitHm.label(p.visitLimit));
    else if (p.defaultVisit) bits.push('~' + p.defaultVisit + ' min');
    if (p.onTour) bits.push('On this tour');
    return bits.join(' · ');
  }

  function renderList() {
    if (!listEl) return;
    var rows = filtered();
    if (!rows.length) {
      listEl.innerHTML = '<div class="itin-rich-picker__empty">No places match.</div>';
      return;
    }
    var html = '';
    rows.forEach(function (p) {
      var coverStyle = p.cover
        ? 'background-image:url(\'' + String(p.cover).replace(/'/g, '%27') + '\')'
        : '';
      html +=
        '<button type="button" class="itin-rich-picker__card' +
        (hidden && hidden.value === p.key ? ' is-selected' : '') +
        '" data-itin-rich-key="' +
        esc(p.key) +
        '" role="option" aria-selected="' +
        (hidden && hidden.value === p.key ? 'true' : 'false') +
        '">' +
        '<span class="itin-rich-picker__thumb' +
        (p.cover ? ' has-image' : '') +
        '" style="' +
        coverStyle +
        '"></span>' +
        '<span class="itin-rich-picker__info">' +
        '<span class="itin-rich-picker__name">' +
        esc(p.name || 'Place') +
        '</span>' +
        '<span class="itin-rich-picker__loc">' +
        esc(p.location || p.subtitle || '') +
        '</span>' +
        '<span class="itin-rich-picker__meta">' +
        esc(metaBits(p)) +
        '</span>' +
        (p.description
          ? '<span class="itin-rich-picker__desc">' + esc(p.description) + '</span>'
          : '') +
        '</span></button>';
    });
    listEl.innerHTML = html;
  }

  function applySelection(key) {
    var place = places[key || ''];
    if (!hidden) return;
    hidden.value = place ? key : '';
    if (titleEl) titleEl.textContent = place ? place.name : 'Choose a place…';
    if (subEl) {
      subEl.textContent = place
        ? metaBits(place) || (place.kind === 'business' ? 'Business' : 'Destination')
        : 'Destinations or businesses';
    }
    if (mediaEl) {
      if (place && place.cover) {
        mediaEl.style.backgroundImage = 'url("' + String(place.cover).replace(/"/g, '%22') + '")';
        mediaEl.classList.add('has-image');
      } else {
        mediaEl.style.backgroundImage = '';
        mediaEl.classList.remove('has-image');
      }
    }
    root.classList.toggle('has-value', !!place);
    if (typeof hidden.dispatchEvent === 'function') {
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }
    renderList();
  }

  function dropDayFrom(el) {
    if (!el || !el.closest) return '1';
    var zone = el.closest('[data-itin-place-drop]');
    if (zone && zone.getAttribute('data-itin-day')) return zone.getAttribute('data-itin-day');
    var dayEl = el.closest('[data-itin-day]');
    if (dayEl && dayEl.getAttribute('data-itin-day')) return dayEl.getAttribute('data-itin-day');
    var section = el.closest('[data-itin-day-section]');
    if (section && section.getAttribute('data-itin-day-section')) return section.getAttribute('data-itin-day-section');
    return '1';
  }

  function openVisitDropModal(key, day) {
    var modalEl = document.getElementById('itinVisitDropModal');
    if (!modalEl) return;
    var placeEl = modalEl.querySelector('[data-itin-visit-drop-place]');
    var clockEl = document.getElementById('itinVisitDropClock');
    var place = places[key] || {};
    if (placeEl) {
      placeEl.textContent = 'How long will tourists stay at ' + (place.name || 'this stop') + '?';
    }
    if (clockEl) {
      clockEl.value = '';
      clockEl.classList.remove('is-invalid');
      if (window.itinVisitHm) window.itinVisitHm.paintUnit(clockEl);
    }
    if (window.itinVisitHm) {
      window.itinVisitHm.showWarn(modalEl.querySelector('[data-itin-visit-limit-hint]'), place.visitLimit
        ? 'Visit duration: up to ' + window.itinVisitHm.label(place.visitLimit) + '.'
        : '');
      window.itinVisitHm.showWarn(modalEl.querySelector('[data-itin-visit-limit-warn]'), '');
    }
    modalEl._dropKey = key;
    modalEl._dropName = place.name || '';
    modalEl._dropLimit = place.visitLimit || null;
    modalEl._dropDay = day || '1';
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      setTimeout(function () { if (clockEl) clockEl.focus(); }, 250);
    }
  }

  function finishPlaceDrop(key, overEl) {
    if (!key || !places[key] || !overEl) return;
    applySelection(key);
    var dayEl = document.getElementById('itinAddDayNumber');
    var day = dropDayFrom(overEl);
    if (dayEl && day) dayEl.value = String(day);
    setOpen(false);
    openVisitDropModal(key, day);
  }

  function highlightFromPoint(x, y) {
    var ghost = drag.ghost;
    if (ghost) ghost.style.visibility = 'hidden';
    var el = document.elementFromPoint(x, y);
    if (ghost) ghost.style.visibility = '';
    clearDropOver();
    if (!el || !el.closest) return null;
    var over = el.closest('[data-itin-place-drop-root], [data-itin-place-drop]');
    if (!over) return null;
    var slot = el.closest('[data-itin-place-drop]');
    if (!slot) {
      var dayBox = el.closest('[data-itin-day-section]');
      slot = dayBox
        ? dayBox.querySelector('[data-itin-place-drop]')
        : document.querySelector('[data-itin-place-drop]');
    }
    if (slot) slot.classList.add('is-over');
    return el;
  }

  function makeGhost(key, x, y) {
    var place = places[key] || {};
    var ghost = document.createElement('div');
    ghost.className = 'itin-place-drag-ghost';
    ghost.innerHTML =
      '<span class="itin-place-drag-ghost__thumb' +
      (place.cover ? ' has-image' : '') +
      '"' +
      (place.cover ? ' style="background-image:url(\'' + String(place.cover).replace(/'/g, '%27') + '\')"' : '') +
      '></span><span class="itin-place-drag-ghost__name">' +
      esc(place.name || 'Place') +
      '</span>';
    ghost.style.left = x + 'px';
    ghost.style.top = y + 'px';
    document.body.appendChild(ghost);
    return ghost;
  }

  function unbindPointerDrag() {
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', onPointerUp);
    document.removeEventListener('pointercancel', onPointerCancel);
  }

  function stopPointerDrag() {
    unbindPointerDrag();
    if (drag.ghost && drag.ghost.parentNode) drag.ghost.parentNode.removeChild(drag.ghost);
    drag.ghost = null;
    drag.pointerId = null;
    drag.active = false;
    drag.key = '';
    setDragging(false);
    document.querySelectorAll('.itin-rich-picker__card.is-dragging').forEach(function (el) {
      el.classList.remove('is-dragging');
    });
    setTimeout(function () { didDrag = false; }, 0);
  }

  function onPointerMove(e) {
    if (drag.pointerId !== e.pointerId) return;
    var dx = e.clientX - drag.startX;
    var dy = e.clientY - drag.startY;
    if (!drag.active) {
      if (Math.abs(dx) < 12 || Math.abs(dx) <= Math.abs(dy)) return;
      drag.active = true;
      didDrag = true;
      var card = listEl && listEl.querySelector('[data-itin-rich-key="' + drag.key.replace(/"/g, '') + '"]');
      if (card) card.classList.add('is-dragging');
      drag.ghost = makeGhost(drag.key, e.clientX, e.clientY);
      setDragging(true);
    }
    e.preventDefault();
    if (drag.ghost) {
      drag.ghost.style.left = e.clientX + 'px';
      drag.ghost.style.top = e.clientY + 'px';
    }
    highlightFromPoint(e.clientX, e.clientY);
  }

  function onPointerUp(e) {
    if (drag.pointerId !== e.pointerId) return;
    var key = drag.key;
    var wasDrag = drag.active;
    var overEl = wasDrag ? highlightFromPoint(e.clientX, e.clientY) : null;
    stopPointerDrag();
    if (wasDrag && overEl) finishPlaceDrop(key, overEl);
  }

  function onPointerCancel() {
    stopPointerDrag();
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      kind = tab.getAttribute('data-itin-rich-tab') || 'destination';
      tabs.forEach(function (t) {
        var on = t === tab;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      renderList();
    });
  });

  if (searchEl) {
    searchEl.addEventListener('input', function () {
      query = searchEl.value || '';
      renderList();
    });
  }

  if (trigger) {
    trigger.addEventListener('click', function () {
      setOpen(!open);
      if (open) renderList();
    });
  }

  if (closeEl) {
    closeEl.addEventListener('click', function () {
      setOpen(false);
    });
  }

  if (listEl) {
    listEl.addEventListener('click', function (e) {
      if (didDrag) {
        e.preventDefault();
        return;
      }
      var card = e.target.closest('[data-itin-rich-key]');
      if (!card) return;
      applySelection(card.getAttribute('data-itin-rich-key') || '');
      setOpen(false);
    });
    listEl.addEventListener('pointerdown', function (e) {
      if (!open) return;
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      if (e.target.closest && e.target.closest('[data-itin-sortable], .itin-item--draggable')) return;
      var card = e.target.closest('[data-itin-rich-key]');
      if (!card) return;
      drag.pointerId = e.pointerId;
      drag.startX = e.clientX;
      drag.startY = e.clientY;
      drag.key = card.getAttribute('data-itin-rich-key') || '';
      drag.active = false;
      document.addEventListener('pointermove', onPointerMove, { passive: false });
      document.addEventListener('pointerup', onPointerUp);
      document.addEventListener('pointercancel', onPointerCancel);
    });
  }

  document.addEventListener('click', function (e) {
    if (!open) return;
    if (didDrag || drag.active) return;
    if (root.contains(e.target)) return;
    if (e.target.closest && e.target.closest('[data-itin-sortable], [data-itin-place-drop-root], .itin-item--draggable, .itin-place-dropzone')) return;
    setOpen(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (drag.pointerId != null) {
      stopPointerDrag();
      return;
    }
    setOpen(false);
  });
  window.addEventListener('blur', function () {
    if (drag.pointerId != null) stopPointerDrag();
  });

  renderList();
})();
(function () {
  var modalEl = document.getElementById('itinVisitDropModal');
  if (!modalEl) return;
  var clockEl = document.getElementById('itinVisitDropClock');
  var okBtn = modalEl.querySelector('[data-itin-visit-drop-ok]');
  if (clockEl && window.itinVisitHm) window.itinVisitHm.bind(clockEl);

  var limitWarnEl = modalEl.querySelector('[data-itin-visit-limit-warn]');
  if (clockEl) {
    clockEl.addEventListener('input', function () {
      if (window.itinVisitHm) window.itinVisitHm.showWarn(limitWarnEl, '');
    });
  }

  function submitDrop() {
    if (clockEl && window.itinVisitHm) clockEl.value = window.itinVisitHm.pad(clockEl.value);
    var n = window.itinVisitHm ? window.itinVisitHm.total(clockEl) : NaN;
    if (!window.itinVisitHm || !window.itinVisitHm.ok(n)) {
      if (clockEl) {
        clockEl.classList.add('is-invalid');
        clockEl.focus();
      }
      return;
    }
    var limitMsg = window.itinVisitHm.limitMessage(modalEl._dropName, modalEl._dropLimit, n);
    if (limitMsg) {
      window.itinVisitHm.showWarn(limitWarnEl, limitMsg);
      if (clockEl) {
        clockEl.classList.add('is-invalid');
        clockEl.focus();
      }
      return;
    }
    var form = document.getElementById('itinAddStopForm');
    var visitEl = form && form.querySelector('[name="visit_minutes"]');
    var dayEl = document.getElementById('itinAddDayNumber');
    if (visitEl) {
      visitEl.value = String(n);
      visitEl.dispatchEvent(new Event('input', { bubbles: true }));
    }
    var addClock = form && form.querySelector('[data-itin-visit-clock]');
    if (addClock) addClock.value = window.itinVisitHm.fromMinutes(n);
    if (dayEl && modalEl._dropDay) dayEl.value = String(modalEl._dropDay);
    if (window.bootstrap && bootstrap.Modal) {
      var inst = bootstrap.Modal.getInstance(modalEl);
      if (inst) inst.hide();
    }
    if (!form) return;
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
  }

  if (okBtn) okBtn.addEventListener('click', submitDrop);
  if (clockEl) {
    clockEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitDrop();
      }
    });
  }
  modalEl.addEventListener('shown.bs.modal', function () {
    if (clockEl) {
      clockEl.value = '';
      clockEl.classList.remove('is-invalid');
      if (window.itinVisitHm) window.itinVisitHm.paintUnit(clockEl);
      clockEl.focus();
    }
  });
})();
(function () {
  var addForm = document.getElementById('itinAddStopForm');
  if (!addForm) return;
  var visitInput = addForm.querySelector('[name="visit_minutes"]');
  var visitClock = addForm.querySelector('[data-itin-visit-clock]');
  var addBtn = document.getElementById('itinAddStopBtn') || addForm.querySelector('button[type="submit"]');
  if (visitClock && window.itinVisitHm) window.itinVisitHm.bind(visitClock);

  function visitOk() {
    var n = window.itinVisitHm
      ? window.itinVisitHm.sync(addForm)
      : parseInt(visitInput && visitInput.value, 10);
    return window.itinVisitHm ? window.itinVisitHm.ok(n) : (Number.isFinite(n) && n >= 15 && n <= 720);
  }

  function syncAddBtn() {
    if (!addBtn) return;
    addBtn.disabled = !visitOk();
  }

  if (visitClock) {
    visitClock.addEventListener('input', syncAddBtn);
    visitClock.addEventListener('change', syncAddBtn);
    visitClock.addEventListener('blur', syncAddBtn);
  }
  if (visitInput) {
    visitInput.addEventListener('input', syncAddBtn);
  }
  syncAddBtn();
})();
(function () {
  function closeAllEditTime(except) {
    document.querySelectorAll('[data-itin-stop].is-editing').forEach(function (stop) {
      if (except && stop === except) return;
      stop.classList.remove('is-editing');
      var form = stop.querySelector('[data-itin-edit-time-form]');
      if (form) form.classList.add('d-none');
    });
  }
  document.addEventListener('click', function (e) {
    var editBtn = e.target.closest('[data-itin-edit-time]');
    if (editBtn) {
      var stop = editBtn.closest('[data-itin-stop]');
      if (!stop) return;
      var form = stop.querySelector('[data-itin-edit-time-form]');
      if (!form) return;
      closeAllEditTime(stop);
      stop.classList.add('is-editing');
      form.classList.remove('d-none');
      var input = form.querySelector('[data-itin-visit-clock]') || form.querySelector('[name="visit_minutes"]');
      if (input) {
        if (window.itinVisitHm) window.itinVisitHm.bind(input);
        input.focus();
        if (input.select) input.select();
      }
      return;
    }
    var cancelBtn = e.target.closest('[data-itin-edit-time-cancel]');
    if (cancelBtn) {
      var stopCancel = cancelBtn.closest('[data-itin-stop]');
      if (!stopCancel) return;
      stopCancel.classList.remove('is-editing');
      var formCancel = stopCancel.querySelector('[data-itin-edit-time-form]');
      if (formCancel) formCancel.classList.add('d-none');
    }
  });
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-itin-edit-time-form]');
    if (!form) return;
    var clock = form.querySelector('[data-itin-visit-clock]');
    if (clock && window.itinVisitHm) clock.value = window.itinVisitHm.pad(clock.value);
    var total = window.itinVisitHm ? window.itinVisitHm.sync(form) : parseInt((form.querySelector('[name="visit_minutes"]') || {}).value, 10);
    if (!window.itinVisitHm || !window.itinVisitHm.ok(total)) {
      e.preventDefault();
      if (clock) {
        clock.classList.add('is-invalid');
        clock.focus();
      }
      return;
    }
    var stop = form.closest('[data-itin-stop]');
    var limitMsg = stop
      ? window.itinVisitHm.limitMessage(stop.getAttribute('data-place-name'), stop.getAttribute('data-visit-limit'), total)
      : '';
    window.itinVisitHm.showWarn(form.querySelector('[data-itin-visit-limit-warn]'), limitMsg);
    if (limitMsg) {
      e.preventDefault();
      if (clock) {
        clock.classList.add('is-invalid');
        clock.focus();
      }
    }
  });
  document.addEventListener('input', function (e) {
    var form = e.target.closest && e.target.closest('[data-itin-edit-time-form]');
    if (form && window.itinVisitHm) window.itinVisitHm.showWarn(form.querySelector('[data-itin-visit-limit-warn]'), '');
  });
})();
(function () {
  var addForm = document.getElementById('itinAddStopForm');
  if (!addForm) return;
  var allowInput = document.getElementById('itinAllowDuplicate');
  var stopSelect = addForm.querySelector('[name="stop_key"]');
  var daySelect = addForm.querySelector('[name="day_number"]');
  var bypassDup = false;
  var placeLimits = <?= json_encode(array_map(static fn ($p) => ['name' => $p['name'], 'visitLimit' => $p['visitLimit'] ?? null], $placesForJs), JSON_UNESCAPED_UNICODE) ?>;

  function stopsList() {
    return window.CulTourItinStops || [];
  }

  function placeName(key) {
    var list = stopsList();
    for (var i = 0; i < list.length; i++) {
      if (list[i].key === key) return list[i].name || 'This place';
    }
    if (stopSelect && stopSelect.selectedOptions && stopSelect.selectedOptions[0]) {
      return String(stopSelect.selectedOptions[0].textContent || 'This place').replace(/\s*\(on this tour\)\s*$/i, '').trim();
    }
    return 'This place';
  }

  addForm.addEventListener('submit', function (e) {
    if (window.itinVisitHm && stopSelect && stopSelect.value) {
      var picked = placeLimits[String(stopSelect.value)] || {};
      var visitEl = addForm.querySelector('[name="visit_minutes"]');
      var limitMsg = window.itinVisitHm.limitMessage(picked.name, picked.visitLimit, parseInt(visitEl && visitEl.value, 10));
      if (limitMsg) {
        e.preventDefault();
        e.stopPropagation();
        if (window.CulTourItinConfirm) window.CulTourItinConfirm.open('adjacent', null, { title: 'Visit duration limit', text: limitMsg });
        else alert(limitMsg);
        return;
      }
    }
    if (bypassDup) {
      bypassDup = false;
      return;
    }
    if (!stopSelect || !stopSelect.value) return;
    var key = String(stopSelect.value);
    var day = daySelect ? parseInt(daySelect.value || '1', 10) : 1;
    if (!day || day < 1) day = 1;
    var list = stopsList();
    var onDay = [];
    for (var i = 0; i < list.length; i++) {
      if (parseInt(list[i].day, 10) === day) onDay.push(list[i]);
    }
    var last = onDay.length ? onDay[onDay.length - 1] : null;
    var name = placeName(key);
    var confirmApi = window.CulTourItinConfirm;

    if (last && last.key === key) {
      e.preventDefault();
      e.stopPropagation();
      if (allowInput) allowInput.value = '0';
      if (confirmApi) {
        confirmApi.open('adjacent', null, {
          text: 'You can’t add ' + name + ' as the next stop — it’s already last on this day. Add a different place in between first.'
        });
      }
      return;
    }

    var exists = false;
    for (var j = 0; j < list.length; j++) {
      if (list[j].key === key) { exists = true; break; }
    }
    if (!exists) {
      if (allowInput) allowInput.value = '0';
      return;
    }

    e.preventDefault();
    e.stopPropagation();
    if (allowInput) allowInput.value = '0';
    if (!confirmApi) {
      if (allowInput) allowInput.value = '1';
      bypassDup = true;
      if (typeof addForm.requestSubmit === 'function') addForm.requestSubmit();
      else addForm.submit();
      return;
    }
    confirmApi.open('duplicate', null, {
      text: name + ' is already on this tour. Add it again anyway?',
      onProceed: function () {
        if (allowInput) allowInput.value = '1';
        bypassDup = true;
        if (typeof addForm.requestSubmit === 'function') addForm.requestSubmit();
        else addForm.submit();
      }
    });
  });
})();
(function () {
  if (typeof Sortable === 'undefined') return;
  var timing = window.CulTourItinTiming || { meetupMins: 8 * 60, port: { lat: 11.0039142, lng: 124.6058115 } };
  var saving = false;
  var pendingOrder = null;

  function minsToLabel(mins) {
    if (window.CulTourMap && typeof CulTourMap.minutesToTimeStr === 'function') {
      return CulTourMap.minutesToTimeStr(mins);
    }
    mins = Math.max(0, Math.min(23 * 60 + 59, mins | 0));
    var h24 = Math.floor(mins / 60);
    var m = mins % 60;
    var ampm = h24 >= 12 ? 'PM' : 'AM';
    var h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + ampm;
  }

  function estimateTravel(lat1, lng1, lat2, lng2) {
    var R = 6371;
    var dLat = ((lat2 - lat1) * Math.PI) / 180;
    var dLng = ((lng2 - lng1) * Math.PI) / 180;
    var a =
      Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos((lat1 * Math.PI) / 180) *
        Math.cos((lat2 * Math.PI) / 180) *
        Math.sin(dLng / 2) *
        Math.sin(dLng / 2);
    var km = R * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
    if (km < 0.05) return 3;
    return Math.max(5, Math.ceil((km / 30) * 60) + 2);
  }

  function setTravelWrap(stopEl, travel) {
    var wrap = stopEl.querySelector('[data-itin-travel-wrap]');
    if (!wrap) return;
    if (travel > 0) {
      wrap.innerHTML = ' · ~<span data-itin-travel>' + travel + '</span> min drive';
    } else {
      wrap.innerHTML = '';
    }
  }

  function applyStopUi(stopEl, index, arriveMins, leaveMins, travel, visit) {
    var num = stopEl.querySelector('[data-itin-stop-num]');
    if (num) num.textContent = String(index);
    var arriveEl = stopEl.querySelector('[data-itin-arrive]');
    var leaveEl = stopEl.querySelector('[data-itin-leave]');
    var stayEl = stopEl.querySelector('[data-itin-stay]');
    if (arriveEl) arriveEl.textContent = minsToLabel(arriveMins);
    if (leaveEl) leaveEl.textContent = minsToLabel(leaveMins);
    if (stayEl) stayEl.textContent = String(visit);
    setTravelWrap(stopEl, travel);
  }

  function renumberAllDays() {
    document.querySelectorAll('[data-itin-sortable]').forEach(function (dayEl) {
      dayEl.querySelectorAll('[data-itin-stop]').forEach(function (stopEl, idx) {
        var num = stopEl.querySelector('[data-itin-stop-num]');
        if (num) num.textContent = String(idx + 1);
      });
    });
  }

  function reflowDayLive(dayEl) {
    var dayNum = parseInt(dayEl.getAttribute('data-itin-day') || '1', 10) || 1;
    var stops = Array.prototype.slice.call(dayEl.querySelectorAll('[data-itin-stop]'));
    if (!stops.length) return Promise.resolve([]);
    var meetupMins = timing.meetupMins || 8 * 60;
    var prevLat = timing.port && timing.port.lat != null ? Number(timing.port.lat) : 11.0039142;
    var prevLng = timing.port && timing.port.lng != null ? Number(timing.port.lng) : 124.6058115;
    var prevLeave = meetupMins;
    var mapStops = [];
    var chain = Promise.resolve();

    stops.forEach(function (stopEl, idx) {
      chain = chain.then(function () {
        var visit = parseInt(stopEl.getAttribute('data-visit') || '60', 10) || 60;
        if (visit < 15) visit = 15;
        var lat = parseFloat(stopEl.getAttribute('data-lat') || '');
        var lng = parseFloat(stopEl.getAttribute('data-lng') || '');
        var hasCoords = Number.isFinite(lat) && Number.isFinite(lng) && (lat !== 0 || lng !== 0);
        var travelP;

        if (idx === 0 && dayNum > 1) {
          travelP = Promise.resolve(0);
        } else if (hasCoords) {
          if (window.CulTourMap && typeof CulTourMap.fetchTravelMinutes === 'function') {
            travelP = CulTourMap.fetchTravelMinutes(prevLat, prevLng, lat, lng);
          } else {
            travelP = Promise.resolve(estimateTravel(prevLat, prevLng, lat, lng));
          }
        } else {
          travelP = Promise.resolve(45);
        }

        return travelP.then(function (travel) {
          travel = parseInt(travel, 10) || 0;
          if (travel < 0) travel = 0;
          var arrive;
          if (idx === 0 && dayNum > 1) {
            arrive = meetupMins;
            travel = 0;
          } else {
            arrive = Math.min(23 * 60 + 59, prevLeave + travel);
          }
          var leave = Math.min(23 * 60 + 59, arrive + visit);
          applyStopUi(stopEl, idx + 1, arrive, leave, travel, visit);
          prevLeave = leave;
          if (hasCoords) {
            prevLat = lat;
            prevLng = lng;
            mapStops.push({
              title: (stopEl.querySelector('.itin-item__info > div:not(.small)') || {}).textContent || 'Stop',
              lat: lat,
              lng: lng,
              day: dayNum
            });
          }
        });
      });
    });

    return chain.then(function () { return mapStops; });
  }

  function reflowAllDaysLive() {
    var dayEls = Array.prototype.slice.call(document.querySelectorAll('[data-itin-sortable]'));
    var allMap = [];
    var seq = Promise.resolve();
    dayEls.forEach(function (dayEl) {
      seq = seq.then(function () {
        return reflowDayLive(dayEl).then(function (dayMap) {
          allMap = allMap.concat(dayMap || []);
        });
      });
    });
    return seq.then(function () {
      var mapEl = document.getElementById('itinRouteMap');
      if (mapEl && typeof mapEl._ctUpdateItineraryStops === 'function') {
        mapEl._ctUpdateItineraryStops(allMap);
      }
    });
  }

  function syncStopsStateFromDom() {
    var next = [];
    document.querySelectorAll('[data-itin-sortable]').forEach(function (dayEl) {
      var day = parseInt(dayEl.getAttribute('data-itin-day') || '1', 10) || 1;
      dayEl.querySelectorAll('[data-itin-stop]').forEach(function (stopEl) {
        var id = parseInt(stopEl.getAttribute('data-id') || '0', 10);
        var prev = (window.CulTourItinStops || []).find(function (s) { return s.id === id; });
        next.push({
          id: id,
          key: prev ? prev.key : null,
          day: day,
          name: prev ? prev.name : 'Place'
        });
      });
    });
    window.CulTourItinStops = next;
  }

  function persistOrder(itinId, order) {
    pendingOrder = order;
    if (saving) return;
    saving = true;
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'reorder_stops');
    body.append('itinerary_id', itinId);
    body.append('order', JSON.stringify(order));
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: body,
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      saving = false;
      if (data && data.ok) {
        if (data.stops) {
          // Prefer server times if returned (keeps DB + UI aligned)
          data.stops.forEach(function (s) {
            var stopEl = document.querySelector('[data-itin-stop][data-id="' + s.id + '"]');
            if (!stopEl) return;
            var arriveEl = stopEl.querySelector('[data-itin-arrive]');
            var leaveEl = stopEl.querySelector('[data-itin-leave]');
            var stayEl = stopEl.querySelector('[data-itin-stay]');
            var num = stopEl.querySelector('[data-itin-stop-num]');
            if (num) num.textContent = String(s.index);
            if (arriveEl) arriveEl.textContent = s.arrive;
            if (leaveEl) leaveEl.textContent = s.leave;
            if (stayEl) stayEl.textContent = String(s.visit);
            setTravelWrap(stopEl, s.travel);
          });
          window.CulTourItinStops = data.stops.map(function (s) {
            return { id: s.id, key: s.key, day: s.day, name: s.name };
          });
        }
        if (pendingOrder && JSON.stringify(pendingOrder) !== JSON.stringify(order)) {
          var next = pendingOrder;
          pendingOrder = null;
          persistOrder(itinId, next);
        } else {
          pendingOrder = null;
        }
        return;
      }
      var msg = (data && data.message) || 'Could not reorder stops.';
      var confirmApi = window.CulTourItinConfirm;
      if (confirmApi) {
        confirmApi.open('adjacent', null, { title: 'Can’t reorder', text: msg });
      }
      window.location.reload();
    }).catch(function () {
      saving = false;
      // Keep live UI; order may retry on next drag
    });
  }

  var sortables = [];
  window.itinSetStopSortEnabled = function (on) {
    sortables.forEach(function (s) {
      try { s.option('disabled', !on); } catch (err) {}
    });
  };

  document.querySelectorAll('[data-itin-sortable]').forEach(function (el) {
    if (el.children.length < 2) return;
    var itinId = el.getAttribute('data-itin-id');
    sortables.push(Sortable.create(el, {
      animation: 160,
      draggable: '.itin-item--draggable',
      filter: 'button, a, input, select, textarea, label, form',
      preventOnFilter: false,
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      ghostClass: 'sortable-ghost',
      chosenClass: 'itin-item--dragging',
      onUpdate: function () {
        // Live as you drag within the list
        renumberAllDays();
      },
      onEnd: function () {
        renumberAllDays();
        syncStopsStateFromDom();
        // Live time + map update immediately (no page refresh)
        reflowAllDaysLive();
        var order = [];
        document.querySelectorAll('[data-itin-sortable] [data-id]').forEach(function (n) {
          order.push(n.getAttribute('data-id'));
        });
        persistOrder(itinId, order);
      }
    }));
  });
})();
<?php endif; ?>
</script>
<?php
$extraScripts = ($extraScripts ?? '') . '<script src="' . APP_URL . '/assets/js/itin-tutorial.js?v='
  . (@filemtime(ROOT_PATH . '/assets/js/itin-tutorial.js') ?: time()) . '"></script>';
require __DIR__ . '/../includes/guide_shell_end.php';
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
