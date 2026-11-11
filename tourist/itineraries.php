<?php
require_once __DIR__ . '/../includes/auth.php';
$packageMaker = !empty($GLOBALS['CULTOUR_PACKAGE_MAKER']);
if (!$packageMaker) {
    $user = require_login(ROLE_TOURIST);
} elseif (empty($user['id'])) {
    redirect(APP_URL . '/business/dashboard.php');
}
ensure_itinerary_items_schema();
ensure_place_schedule_schema();

function itinerary_content_hash(int $itineraryId): string
{
    if ($itineraryId < 1) {
        return '';
    }
    $head = db()->prepare('SELECT title, start_date, end_date, party_size FROM itineraries WHERE id=? LIMIT 1');
    $head->execute([$itineraryId]);
    $itin = $head->fetch(PDO::FETCH_ASSOC) ?: [];
    $items = db()->prepare(
        'SELECT destination_id, business_id, visit_date, start_time, end_time, travel_minutes, visit_minutes, sort_order
         FROM itinerary_items WHERE itinerary_id=? ORDER BY sort_order, id'
    );
    $items->execute([$itineraryId]);
    $meetups = [];
    try {
        $meet = db()->prepare(
            'SELECT day_number, destination_id, business_id, meetup_time, visit_date
             FROM itinerary_day_meetups WHERE itinerary_id=? ORDER BY day_number'
        );
        $meet->execute([$itineraryId]);
        $meetups = $meet->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $meetups = [];
    }
    $payload = [
        'title' => trim((string) ($itin['title'] ?? '')),
        'start' => (string) ($itin['start_date'] ?? ''),
        'end' => (string) ($itin['end_date'] ?? ''),
        'party' => (int) ($itin['party_size'] ?? 0),
        'stops' => $items->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'meetups' => $meetups,
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
}
if (!$packageMaker) {
    $GLOBALS['CULTOUR_VIEW_ITINERARY_ID'] = (int) ($_GET['id'] ?? 0);
}
$pageTitle = $packageMaker ? 'Package maker' : 'Itineraries';
$pageHeading = $packageMaker ? 'Package maker' : 'My Itineraries';
$pageSub = $packageMaker ? (string) ($GLOBALS['CULTOUR_PACKAGE_NAME'] ?? 'Tour and travel') : 'CulTour · Ormoc City';
$touristHidePagehead = true; // custom title row with tour chips beside heading
$activeNav = $packageMaker ? 'package-maker' : 'itineraries';
$bodyClass = 'itin-page';

$skipPlaceCatalog = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (($_POST['action'] ?? '') === 'add_stop')
    && verify_csrf();
$destinations = [];
$businesses = [];
$placeVisitLimits = [];
$placeVisitMins = [];
$favoriteDestinations = [];
$favoriteDestIds = [];
$favoriteBizIds = [];
if (!$skipPlaceCatalog) {
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
                b.default_visit_minutes, b.visit_duration_minutes, b.min_visit_minutes, bt.name AS type_name, " . business_itinerary_ready_columns() . "
         FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.approval_status='approved' AND b.status='active'
         ORDER BY b.name"
    )->fetchAll();
    foreach ($destinations as $d) {
        $placeVisitLimits['d' . (int) $d['id']] = place_visit_limit($d['visit_duration_minutes'] ?? null);
    }
    foreach ($businesses as $b) {
        $placeVisitLimits['b' . (int) $b['id']] = place_visit_limit($b['visit_duration_minutes'] ?? null);
        $placeVisitMins['b' . (int) $b['id']] = place_min_visit($b['min_visit_minutes'] ?? null);
    }
    $destinations = array_values(array_filter($destinations, 'destination_itinerary_ready'));
    $businesses = array_values(array_filter($businesses, 'business_itinerary_ready'));

    $favDestStmt = db()->prepare(
        "SELECT d.id, d.name, d.location, d.description, d.cover_image, d.entrance_fee, d.avg_rating,
                d.latitude, d.longitude, d.default_visit_minutes, d.visit_duration_minutes, d.opening_hours, c.name AS category_name
         FROM favorites f
         JOIN destinations d ON d.id = f.destination_id
         JOIN categories c ON c.id = d.category_id
         WHERE f.user_id = ? AND d.status = 'published'
         ORDER BY d.name"
    );
    $favDestStmt->execute([(int) $user['id']]);
    $favoriteDestinations = array_values(array_filter($favDestStmt->fetchAll() ?: [], 'destination_itinerary_ready'));
    $favoriteDestIds = array_map('intval', array_column($favoriteDestinations, 'id'));
    try {
        ensure_business_favorites_schema();
        $favBizStmt = db()->prepare('SELECT business_id FROM business_favorites WHERE user_id=?');
        $favBizStmt->execute([(int) $user['id']]);
        $favoriteBizIds = array_map('intval', $favBizStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        $favoriteBizIds = [];
    }
}

function itin_owned(int $iid, int $uid): bool
{
    $own = db()->prepare('SELECT id FROM itineraries WHERE id=? AND user_id=?');
    $own->execute([$iid, $uid]);
    return (bool) $own->fetch();
}

function itin_reject_if_booked(int $iid): void
{
    if ($iid < 1 || !itinerary_schedule_is_locked($iid)) {
        return;
    }
    $active = itinerary_active_booking($iid);
    $msg = 'This tour is booked'
        . (!empty($active['booking_code']) ? ' (' . $active['booking_code'] . ')' : '')
        . ' and can no longer be edited.';
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('error', $msg);
    redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? 'create';
    if ($packageMaker && $action === 'itinerary_changed') {
        $iid = (int) ($_POST['itinerary_id'] ?? 0);
        $baseline = (string) ($_POST['baseline'] ?? '');
        $now = $iid > 0 && itin_owned($iid, (int) $user['id']) ? itinerary_content_hash($iid) : '';
        $changed = $now !== '' && strlen($baseline) === 64 && !hash_equals($baseline, $now);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'changed' => $changed], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bookedLockActions = [
        'save_draft', 'update_title', 'update_party_size', 'update_draft_guide', 'update_meetup', 'update_day_date', 'update_day_meetup', 'auto_regenerate',
        'reorder_stops', 'delete', 'clear_stops', 'add_stop', 'remove_stop', 'update_stop_stay', 'book_guide',
    ];
    if (in_array($action, $bookedLockActions, true)) {
        itin_reject_if_booked((int) ($_POST['itinerary_id'] ?? $_POST['id'] ?? 0));
    }

    if (in_array($action, ['reserve_submit', 'reserve_cancel', 'reserve_replace', 'reserve_draft_clear', 'reserve_finalize'], true)) {
        $iid = (int) ($_POST['itinerary_id'] ?? 0);
        $back = APP_URL . '/tourist/itineraries.php' . ($iid > 0 ? '?id=' . $iid : '');
        if ($action === 'reserve_draft_clear') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if (itin_owned($iid, (int) $user['id']) && $itemId > 0) {
                db()->prepare('UPDATE itinerary_items SET reservation_draft=NULL WHERE id=? AND itinerary_id=?')
                    ->execute([$itemId, $iid]);
            }
            redirect($back);
        }
        if ($action === 'reserve_finalize') {
            $result = reservation_commit_held_replacements((int) $user['id'], $iid);
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            redirect($result['ok'] ? APP_URL . '/tourist/dashboard.php' : $back);
        }
        if ($action === 'reserve_submit') {
            $result = reservation_submit(
                (int) $user['id'],
                (int) ($_POST['item_id'] ?? 0),
                (string) ($_POST['reserve_date'] ?? ''),
                (string) ($_POST['reserve_time'] ?? ''),
                (int) ($_POST['guests'] ?? 0),
                (string) ($_POST['order_mode'] ?? 'table_only'),
                is_array($_POST['qty'] ?? null) ? $_POST['qty'] : []
            );
        } elseif ($action === 'reserve_cancel') {
            $result = reservation_set_status((int) ($_POST['reservation_id'] ?? 0), 'cancelled', (int) $user['id'], 'tourist');
        } else {
            $result = reservation_replace_restaurant((int) $user['id'], (int) ($_POST['item_id'] ?? 0), (int) ($_POST['business_id'] ?? 0));
        }
        if ($action !== 'reserve_submit' && $action !== 'reserve_replace' && !$result['ok']) {
            flash('error', $result['message']);
        } elseif ($action !== 'reserve_submit' && $action !== 'reserve_replace' && $result['ok']) {
            flash('success', $result['message']);
        }
        $queue = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['reserve_queue'] ?? '')))));
        if ($action === 'reserve_replace' && !empty($result['ok'])) {
            $replaced = (int) ($_POST['item_id'] ?? 0);
            if ($replaced > 0) {
                $queue[] = $replaced;
            }
        }
        if ($queue) {
            $next = (int) array_shift($queue);
            $back .= (str_contains($back, '?') ? '&' : '?') . 'reserve=' . $next;
            if ($queue) {
                $back .= '&queue=' . implode(',', $queue);
            }
        }
        redirect($back);
    }

    if ($action === 'create') {
        $rawMeetup = trim((string) ($_POST['meetup_time'] ?? ''));
        $meetup = $rawMeetup === '' ? null : (normalize_time_input($rawMeetup) ?? null);
        if ($rawMeetup !== '' && $meetup === null) {
            flash('error', 'Enter a valid meetup time (e.g. 8:00 AM).');
            redirect(APP_URL . '/tourist/itineraries.php');
        }
        $startDate = null;
        $endDate = null;
        if (trim((string) ($_POST['start_date'] ?? '')) !== '') {
            [$startDate, $endDate] = itinerary_resolve_dates_from_post($_POST);
            if ($startDate === null) {
                flash('error', 'Choose a valid tour date.');
                redirect(APP_URL . '/tourist/itineraries.php');
            }
            if ($meetup === null) {
                flash('error', 'Enter a meetup time before setting a tour date.');
                redirect(APP_URL . '/tourist/itineraries.php');
            }
            $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
            if ($pastMsg !== null) {
                flash('error', $pastMsg);
                redirect(APP_URL . '/tourist/itineraries.php');
            }
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $title = 'Unnamed';
        }
        // Blank Unnamed drafts are not listed until renamed or filled — reuse one draft.
        if (itinerary_is_placeholder_title($title) && $startDate === null) {
            $reuseId = itinerary_find_blank_draft_id((int) $user['id']);
            if ($reuseId > 0) {
                itinerary_prune_blank_drafts((int) $user['id'], $reuseId);
                db()->prepare(
                    'UPDATE itineraries
                     SET accommodation_business_id=NULL, accommodation_service_id=NULL, accommodation_declined=0, accommodation_lines=NULL
                     WHERE id=? AND user_id=?'
                )->execute([$reuseId, (int) $user['id']]);
                $qs = 'id=' . $reuseId;
                $guideUser = (int) ($_POST['guide_user'] ?? 0);
                if ($guideUser > 0) {
                    $qs .= '&book=1&guide_user=' . $guideUser;
                }
                redirect(APP_URL . '/tourist/itineraries.php?' . $qs);
            }
        }
        db()->prepare(
            'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $user['id'],
            $title,
            trim($_POST['description'] ?? ''),
            $startDate,
            $endDate,
            $meetup,
            0,
            'draft',
        ]);
        $iid = (int) db()->lastInsertId();
        if (itinerary_is_placeholder_title($title) && $startDate === null) {
            itinerary_prune_blank_drafts((int) $user['id'], $iid);
        }
        $qs = 'id=' . $iid;
        $guideUser = (int) ($_POST['guide_user'] ?? 0);
        if ($guideUser > 0) {
            $qs .= '&book=1&guide_user=' . $guideUser;
        }
        redirect(APP_URL . '/tourist/itineraries.php?' . $qs);
    }

    if ($action === 'save_draft' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        if (itinerary_guard_new_draft((int) $user['id'], 'tourist', $iid) !== null) {
            itinerary_reject_draft_limit(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
            db()->prepare(
            "UPDATE itineraries SET kept_as_draft=1, status='draft' WHERE id=? AND user_id=?"
        )->execute([$iid, $user['id']]);
        $party = (int) ($_POST['party_size'] ?? 0);
        if ($party >= 1 && $party <= 20) {
            db()->prepare('UPDATE itineraries SET party_size=? WHERE id=? AND user_id=?')
                ->execute([$party, $iid, $user['id']]);
        }
        if (isset($_POST['guide_id'])) {
            $draftGuide = (int) $_POST['guide_id'];
            if ($draftGuide > 0 && booking_is_own_guide((int) $user['id'], $draftGuide)) {
                $draftGuide = 0;
            }
            if ($draftGuide > 0) {
                $gok = db()->prepare('SELECT id FROM tour_guide_profiles WHERE id=? LIMIT 1');
                $gok->execute([$draftGuide]);
                if ($gok->fetch()) {
                    db()->prepare('UPDATE itineraries SET draft_guide_id=? WHERE id=? AND user_id=?')
                        ->execute([$draftGuide, $iid, $user['id']]);
                }
            } else {
                db()->prepare('UPDATE itineraries SET draft_guide_id=NULL WHERE id=? AND user_id=?')
                    ->execute([$iid, $user['id']]);
            }
        }
        $draftTitle = trim((string) ($_POST['title'] ?? ''));
        if ($draftTitle !== '' && !itinerary_is_placeholder_title($draftTitle)) {
            db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND user_id=?')
                ->execute([$draftTitle, $iid, $user['id']]);
        }
        $postedStart = normalize_tour_date($_POST['start_date'] ?? null);
        if ($postedStart !== null && $postedStart >= date('Y-m-d')) {
            $curStart = db()->prepare('SELECT start_date FROM itineraries WHERE id=? AND user_id=?');
            $curStart->execute([$iid, $user['id']]);
            if (normalize_tour_date($curStart->fetchColumn() ?: null) === null) {
                db()->prepare('UPDATE itineraries SET start_date=? WHERE id=? AND user_id=?')
                    ->execute([$postedStart, $iid, $user['id']]);
            }
        }
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'id' => $iid], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('success', $packageMaker ? 'Draft saved.' : 'Tour saved as draft.');
        redirect($packageMaker
            ? APP_URL . '/business/package.php?id=' . (int) ($GLOBALS['CULTOUR_PACKAGE_ID'] ?? 0)
            : APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($packageMaker && $action === 'save_as_new' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        require_once __DIR__ . '/../includes/payments.php';
        $copy = itinerary_duplicate($iid);
        $newId = (int) ($copy['id'] ?? 0);
        if ($newId < 1) {
            flash('error', 'Could not save a new itinerary.');
        } else {
            db()->prepare("UPDATE itineraries SET title=TRIM(CONCAT(title, ' copy')) WHERE id=?")->execute([$newId]);
            flash('success', 'Saved as a new itinerary. This package stays linked to the original.');
        }
        redirect(APP_URL . '/business/package.php?id=' . (int) ($GLOBALS['CULTOUR_PACKAGE_ID'] ?? 0));
    }

    if ($packageMaker && $action === 'package_guide_qr') {
        require_once __DIR__ . '/../includes/payments.php';
        $amount = round(max(0, (float) ($_POST['amount'] ?? 0)), 2);
        $guide = trim((string) ($_POST['guide_name'] ?? ''));
        $label = 'CulTour GCash ' . format_money($amount) . ($guide !== '' ? ' ' . $guide : '');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'qr' => payment_qr_data_uri($label)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($packageMaker && $action === 'save_package_limits' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $minRaw = trim((string) ($_POST['package_min_pax'] ?? ''));
        $maxRaw = trim((string) ($_POST['package_max_pax'] ?? ''));
        $priceRaw = trim((string) ($_POST['package_price'] ?? ''));
        $sets = [];
        $params = [];
        if ($minRaw !== '' && (int) $minRaw >= 1 && (int) $minRaw <= 50) {
            $sets[] = 'package_min_pax=?';
            $params[] = (int) $minRaw;
        }
        if ($maxRaw !== '' && (int) $maxRaw >= 1 && (int) $maxRaw <= 50) {
            $sets[] = 'capacity=?';
            $params[] = (int) $maxRaw;
            db()->prepare('UPDATE itineraries SET party_size=? WHERE id=? AND user_id=?')
                ->execute([(int) $maxRaw, $iid, (int) $user['id']]);
        }
        if ($priceRaw !== '' && round((float) $priceRaw, 2) > 0) {
            $sets[] = 'price=?';
            $params[] = round((float) $priceRaw, 2);
        }
        if ($sets) {
            $params[] = (int) ($GLOBALS['CULTOUR_PACKAGE_ID'] ?? 0);
            $params[] = (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0);
            db()->prepare(
                'UPDATE business_services SET ' . implode(', ', $sets) . ' WHERE id=? AND business_id=?'
            )->execute($params);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update_party_size' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $party = (int) ($_POST['party_size'] ?? 0);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($party < 1 || $party > 20) {
            $partyMsg = $packageMaker ? 'Enter the participants.' : 'Enter the number of guests.';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $partyMsg], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('error', $partyMsg);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        db()->prepare('UPDATE itineraries SET party_size=? WHERE id=? AND user_id=?')
            ->execute([$party, $iid, $user['id']]);
        if ($wantsJson) {
            [$qStart, $qEnd] = booking_resolve_tour_span($iid, date('Y-m-d'));
            $qItin = itinerary_row_by_id($iid);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'party_size' => $party,
                'stay_spans' => itinerary_day_count($qStart, $qEnd),
                'ride_spans' => itinerary_logical_day_count($qItin),
                'offers' => stay_ride_offer_quotes($party, $qStart, $qEnd, $iid),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'update_draft_guide' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $draftGuide = (int) ($_POST['guide_id'] ?? 0);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($draftGuide > 0 && booking_is_own_guide((int) $user['id'], $draftGuide)) {
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
            ->execute([$draftGuide > 0 ? $draftGuide : null, $iid, $user['id']]);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'guide_id' => $draftGuide], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'update_stay_ride' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        $stay = stay_ride_parse($_POST);
        if (!$stay['ok']) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $stay['message']], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('error', $stay['message']);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        [$stockStart, $stockEnd] = booking_resolve_tour_span($iid, date('Y-m-d'));
        $partyNow = db()->prepare('SELECT party_size FROM itineraries WHERE id=?');
        $partyNow->execute([$iid]);
        $charge = booking_charge_for_stay(
            $stay,
            max(1, (int) $partyNow->fetchColumn()),
            $stockStart,
            $stockEnd,
            0,
            $iid
        );
        if (!$charge['ok']) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $charge['message']], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('error', $charge['message']);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $stay['accommodation_lines'] = $charge['accommodation_lines'];
        $stay['transportation_lines'] = $charge['transportation_lines'];
        stay_ride_save_itinerary($iid, (int) $user['id'], $stay);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            $stayQuote = null;
            if (empty($stay['accommodation_declined']) && !empty($charge['accommodation_lines'])) {
                $stayQuote = [
                    'ok' => true,
                    'label' => (string) $charge['stay_label'],
                    'amount' => (float) $charge['accommodation_amount'],
                    'amount_label' => format_money($charge['accommodation_amount']),
                    'detail' => (string) $charge['stay_detail'],
                    'review' => (string) $charge['stay_review'],
                    'lines' => $charge['accommodation_lines'],
                ];
            }
            $rideQuote = null;
            if (empty($stay['transportation_declined']) && !empty($charge['transportation_lines'])) {
                $rideQuote = [
                    'ok' => true,
                    'label' => (string) $charge['ride_label'],
                    'amount' => (float) $charge['transportation_amount'],
                    'amount_label' => format_money($charge['transportation_amount']),
                    'detail' => (string) $charge['ride_detail'],
                    'review' => (string) $charge['ride_review'],
                    'lines' => $charge['transportation_lines'],
                ];
            }
            echo json_encode(['ok' => true, 'stay' => $stayQuote, 'ride' => $rideQuote], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'save_stay_lines' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        header('Content-Type: application/json; charset=utf-8');
        $declined = (int) ($_POST['accommodation_declined'] ?? 0) === 1;
        $clearStay = static function () use ($iid, $user, $declined): void {
            db()->prepare(
                'UPDATE itineraries
                 SET accommodation_business_id=NULL, accommodation_service_id=NULL, accommodation_declined=?, accommodation_lines=NULL
                 WHERE id=? AND user_id=?'
            )->execute([$declined ? 1 : 0, $iid, (int) $user['id']]);
        };
        if ($declined) {
            $clearStay();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rawLines = stay_lines_request($_POST);
        if (!is_array($rawLines) || $rawLines === []) {
            $clearStay();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $shaped = stay_lines_shape($rawLines);
        if (!$shaped['ok'] || !$shaped['lines']) {
            echo json_encode(['ok' => false, 'message' => $shaped['message'] !== '' ? $shaped['message'] : 'Add a room.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        [$stockStart, $stockEnd] = booking_resolve_tour_span($iid, date('Y-m-d'));
        $partyNow = db()->prepare('SELECT party_size FROM itineraries WHERE id=?');
        $partyNow->execute([$iid]);
        $dayDates = function_exists('itinerary_day_dates_map') ? itinerary_day_dates_map($iid) : [];
        $priced = stay_lines_price(
            $shaped['lines'],
            itinerary_day_count($stockStart, $stockEnd),
            $stockStart,
            $stockEnd,
            null,
            max(1, (int) $partyNow->fetchColumn()),
            'accommodation',
            $dayDates
        );
        if (!$priced['ok'] || !$priced['lines']) {
            echo json_encode(['ok' => false, 'message' => $priced['message'] !== '' ? $priced['message'] : 'Could not save the rooms.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $first = $priced['lines'][0];
        db()->prepare(
            'UPDATE itineraries
             SET accommodation_business_id=?, accommodation_service_id=?, accommodation_declined=0, accommodation_lines=?
             WHERE id=? AND user_id=?'
        )->execute([
            (int) $first['business_id'],
            (int) $first['service_id'],
            json_encode(array_values($priced['lines']), JSON_UNESCAPED_UNICODE),
            $iid,
            (int) $user['id'],
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_ride_lines' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        header('Content-Type: application/json; charset=utf-8');
        $declined = (int) ($_POST['transportation_declined'] ?? 0) === 1;
        $clearRide = static function () use ($iid, $user, $declined): void {
            db()->prepare(
                'UPDATE itineraries
                 SET transportation_business_id=NULL, transportation_service_id=NULL, transportation_declined=?, transportation_lines=NULL
                 WHERE id=? AND user_id=?'
            )->execute([$declined ? 1 : 0, $iid, (int) $user['id']]);
        };
        if ($declined) {
            $clearRide();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rawLines = stay_lines_request($_POST, 'transportation_lines');
        if (!is_array($rawLines) || $rawLines === []) {
            $clearRide();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $shaped = stay_lines_shape($rawLines, 'vehicle');
        if (!$shaped['ok'] || !$shaped['lines']) {
            echo json_encode(['ok' => false, 'message' => $shaped['message'] !== '' ? $shaped['message'] : 'Add a vehicle.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        [$stockStart, $stockEnd] = booking_resolve_tour_span($iid, date('Y-m-d'));
        $itin = itinerary_row_by_id($iid);
        $partyNow = max(1, (int) ($itin['party_size'] ?? 1));
        $spans = $itin !== [] ? itinerary_logical_day_count($itin) : itinerary_day_count($stockStart, $stockEnd);
        $dayDates = function_exists('itinerary_day_dates_map') ? itinerary_day_dates_map($iid, $itin !== [] ? $itin : null) : [];
        $priced = stay_lines_price(
            $shaped['lines'],
            max(1, $spans),
            $stockStart,
            $stockEnd,
            null,
            $partyNow,
            'transportation',
            $dayDates
        );
        if (!$priced['ok'] || !$priced['lines']) {
            echo json_encode(['ok' => false, 'message' => $priced['message'] !== '' ? $priced['message'] : 'Could not save the vehicles.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $first = $priced['lines'][0];
        db()->prepare(
            'UPDATE itineraries
             SET transportation_business_id=?, transportation_service_id=?, transportation_declined=0, transportation_lines=?
             WHERE id=? AND user_id=?'
        )->execute([
            (int) $first['business_id'],
            (int) $first['service_id'],
            json_encode(array_values($priced['lines']), JSON_UNESCAPED_UNICODE),
            $iid,
            (int) $user['id'],
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update_title' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
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
        db()->prepare('UPDATE itineraries SET title=? WHERE id=? AND user_id=?')
            ->execute([$title, $iid, $user['id']]);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'title' => $title, 'id' => $iid], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'auto_generate') {
        $rawMeetup = trim((string) ($_POST['meetup_time'] ?? ''));
        $meetup = $rawMeetup === '' ? null : (normalize_time_input($rawMeetup) ?? null);
        if ($rawMeetup !== '' && $meetup === null) {
            flash('error', 'Enter a valid meetup time (e.g. 8:00 AM).');
            redirect(APP_URL . '/tourist/itineraries.php?add=1');
        }
        [$startDate, $endDate, $dayCount, $usedDefault] = itinerary_dates_for_autogen($_POST);
        if (!$usedDefault) {
            $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
            if ($pastMsg !== null) {
                flash('error', $pastMsg);
                redirect(APP_URL . '/tourist/itineraries.php?add=1');
            }
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
                [
                    'keep_undated' => !empty($usedDefault),
                    'day_meetup' => itinerary_posted_day_meetup_times($_POST),
                ]
            );
            if (!empty($result['warning'])) {
                flash('warning', $result['warning']);
            } else {
                flash('success', 'Tour auto-generated with ' . (int) $result['stops'] . ' stop(s). Adjust times or reorder as needed.');
            }
            redirect(APP_URL . '/tourist/itineraries.php?id=' . (int) $result['id']);
        } catch (Throwable $e) {
            flash('error', $e->getMessage() ?: 'Could not auto-generate a tour.');
            redirect(APP_URL . '/tourist/itineraries.php?add=1');
        }
    }

    if ($action === 'clone') {
        $srcId = (int) ($_POST['id'] ?? 0);
        $src = db()->prepare('SELECT id, is_suggested, status FROM itineraries WHERE id=?');
        $src->execute([$srcId]);
        $srcRow = $src->fetch();
        if (!$srcRow || !itinerary_is_published_suggested($srcRow)) {
            flash('error', 'Suggested tour not found.');
            redirect(APP_URL . '/tourist/itineraries.php');
        }
        try {
            $newId = clone_itinerary_for_tourist($srcId, (int) $user['id']);
            flash('success', 'Tour copied to your itineraries. Adjust the date and stops, then book a guide.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $newId);
        } catch (Throwable $e) {
            flash('error', $e->getMessage() ?: 'Could not copy that tour.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $srcId);
        }
    }

    if ($action === 'update_meetup' && itin_owned((int) $_POST['itinerary_id'], (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $rawMeetup = trim((string) ($_POST['meetup_time'] ?? ''));
        $meetup = $rawMeetup === '' ? null : normalize_time_input($rawMeetup);
        [$startDate, $endDate, $postedDayCount] = itinerary_resolve_dates_from_post($_POST);
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
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        };
        if (itinerary_schedule_is_locked($iid)) {
            $active = itinerary_active_booking($iid);
            $respondMeetup(
                false,
                'Tour dates are locked while booking '
                    . ($active['booking_code'] ?? '')
                    . ' is ' . ($active['status'] ?? 'active')
                    . '. Cancel that booking first to change dates or multi-day.'
            );
        }
        if ($rawMeetup !== '' && $meetup === null) {
            $respondMeetup(false, 'Enter a valid meetup time (e.g. 8:00 AM).');
        }
        $dayCount = max(1, min(itinerary_max_days(), (int) $postedDayCount));
        $jsonExtra = static function () use ($iid, $meetup): array {
            $row = itinerary_row_by_id($iid);
            $extra = itinerary_schedule_json_extra($iid, $row, $meetup ? (format_time_12h($meetup) ?: $meetup) : '');
            return $extra;
        };
        if ($packageMaker && !empty($_POST['package_date_tourist'])) {
            $dayCount = max(1, min(itinerary_max_days(), (int) $postedDayCount));
            db()->prepare(
                'UPDATE itineraries SET meetup_time=?, start_date=NULL, end_date=NULL, template_day_count=? WHERE id=? AND user_id=?'
            )->execute([$meetup, $dayCount, $iid, $user['id']]);
            db()->prepare(
                "UPDATE business_services SET package_date_mode='tourist', package_fixed_date=NULL, package_close_date=NULL WHERE id=? AND business_id=?"
            )->execute([(int) ($GLOBALS['CULTOUR_PACKAGE_ID'] ?? 0), (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0)]);
            $respondMeetup(true, '', $jsonExtra());
        }
        // Blank date is allowed for drafts — required only when booking a guide.
        // An empty post must not wipe a date already saved on the draft.
        if ($startDate === null) {
            $keepDates = db()->prepare('SELECT start_date FROM itineraries WHERE id=? AND user_id=?');
            $keepDates->execute([$iid, $user['id']]);
            $keptStart = normalize_tour_date($keepDates->fetchColumn() ?: null);
            if ($keptStart !== null) {
                db()->prepare(
                    'UPDATE itineraries SET meetup_time=?, template_day_count=? WHERE id=? AND user_id=?'
                )->execute([$meetup, $dayCount, $iid, $user['id']]);
                if ($packageMaker) {
                    $postedClose = normalize_tour_date($_POST['package_close_date'] ?? null);
                    $latestClose = package_cutoff_date($keptStart);
                    if ($postedClose !== null && $latestClose !== null && $postedClose > $latestClose) {
                        $postedClose = $latestClose;
                    }
                    db()->prepare('UPDATE business_services SET package_close_date=? WHERE id=? AND business_id=?')
                        ->execute([
                            $postedClose,
                            (int) ($GLOBALS['CULTOUR_PACKAGE_ID'] ?? 0),
                            (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0),
                        ]);
                }
                $respondMeetup(true, '', $jsonExtra());
            }
            if (!empty($_POST['drop_later_stops'])) {
                itinerary_drop_stops_after_day($iid, $dayCount);
                db()->prepare('DELETE FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number>?')
                    ->execute([$iid, $dayCount]);
            }
            db()->prepare(
                'UPDATE itineraries SET meetup_time=?, start_date=NULL, end_date=NULL, template_day_count=? WHERE id=? AND user_id=?'
            )->execute([$meetup, $dayCount, $iid, $user['id']]);
            try {
                db()->prepare('UPDATE itinerary_day_meetups SET visit_date=NULL WHERE itinerary_id=?')
                    ->execute([$iid]);
            } catch (Throwable $e) {
                // ignore
            }
            $respondMeetup(true, '', $jsonExtra());
        }
        if ($packageMaker && $startDate < package_tour_earliest_date()) {
            $respondMeetup(false, 'The package tour date must be at least 2 days from today.');
        }
        if ($startDate < date('Y-m-d')) {
            $respondMeetup(false, 'Choose a tour date that is today or later.');
        }
        if ($meetup !== null) {
            $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
            if ($pastMsg !== null) {
                $respondMeetup(false, $pastMsg);
            }
        }
        db()->prepare(
            'UPDATE itineraries SET meetup_time=? WHERE id=? AND user_id=?'
        )->execute([$meetup, $iid, $user['id']]);
        if (!empty($_POST['drop_later_stops'])) {
            itinerary_drop_stops_after_day($iid, $dayCount);
        }
        itinerary_apply_logical_day_count($iid, $dayCount);
        $map = itinerary_day_dates_map($iid);
        $curDay1 = $map[1] ?? '';
        $reordered = false;
        if ($startDate !== $curDay1) {
            $set = itinerary_set_day_date($iid, 1, $startDate);
            if (!empty($set['error'])) {
                $respondMeetup(false, (string) $set['error']);
            }
            $reordered = !empty($set['reordered']);
        } else {
            itinerary_sync_span_from_day_dates($iid);
        }
        $row = itinerary_row_by_id($iid);
        $tourDates = itinerary_visit_dates_list($iid, $row);
        $spanStart = $tourDates[0] ?? $startDate;
        $spanEnd = $tourDates ? $tourDates[count($tourDates) - 1] : $startDate;
        $conflict = tourist_date_range_booking_conflict_message(
            (int) $user['id'],
            $spanStart,
            $spanEnd,
            $iid,
            null,
            $tourDates ?: null
        );
        if ($conflict !== null) {
            $respondMeetup(false, $conflict);
        }
        $extra = $jsonExtra();
        $extra['reordered'] = $reordered;
        if ($packageMaker && $startDate !== null && $startDate !== '') {
            $closeDate = normalize_tour_date($_POST['package_close_date'] ?? null);
            $latestClose = package_cutoff_date($startDate);
            if ($closeDate !== null && $latestClose !== null && $closeDate > $latestClose) {
                $respondMeetup(false, 'The package cut off must be at least 2 days before the tour date.');
            }
            db()->prepare(
                "UPDATE business_services SET package_date_mode='fixed', package_fixed_date=?, package_close_date=? WHERE id=? AND business_id=?"
            )->execute([$startDate, $closeDate, (int) ($GLOBALS['CULTOUR_PACKAGE_ID'] ?? 0), (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0)]);
        }
        $respondMeetup(true, '', $extra);
    }

    if ($action === 'update_day_date' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $dayNum = (int) ($_POST['day_number'] ?? 1);
        $ymd = trim((string) ($_POST['date'] ?? $_POST['visit_date'] ?? ''));
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        $respondDayDate = static function (bool $ok, string $message = '', array $extra = []) use ($wantsJson, $iid): void {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array_merge(['ok' => $ok, 'message' => $message, 'id' => $iid], $extra), JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($message !== '') {
                flash($ok ? 'success' : 'error', $message);
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        };
        if (itinerary_schedule_is_locked($iid)) {
            $active = itinerary_active_booking($iid);
            $respondDayDate(
                false,
                'Tour dates are locked while booking '
                    . ($active['booking_code'] ?? '')
                    . ' is ' . ($active['status'] ?? 'active')
                    . '. Cancel that booking first to change dates.'
            );
        }
        $set = itinerary_set_day_date($iid, $dayNum, $ymd);
        if (!empty($set['error'])) {
            $respondDayDate(false, (string) $set['error']);
        }
        $row = itinerary_row_by_id($iid);
        $extra = itinerary_schedule_json_extra($iid, $row);
        $extra['reordered'] = !empty($set['reordered']);
        $respondDayDate(true, '', $extra);
    }

    if ($action === 'update_day_meetup' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $dayNum = (int) ($_POST['day_number'] ?? 1);
        if ($dayNum < 1) {
            $dayNum = 1;
        }
        if ($dayNum > 14) {
            $dayNum = 14;
        }
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        $usePort = !empty($_POST['use_port'])
            || trim((string) ($_POST['stop_key'] ?? '')) === 'port';
        $clear = !empty($_POST['clear']) || !empty($_POST['reset']);
        $meetupTimeIn = trim((string) ($_POST['meetup_time'] ?? ''));
        if (!$packageMaker && $meetupTimeIn !== '') {
            $hourWindows = tourist_meetup_hour_windows();
            $dayDates = itinerary_visit_dates_list($iid);
            $dayYmd = $dayDates[$dayNum - 1] ?? null;
            $dayTs = $dayYmd ? strtotime($dayYmd . ' 12:00:00') : false;
            $win = ($dayTs !== false) ? ($hourWindows[(string) (int) date('w', $dayTs)] ?? null) : null;
            $pickedMins = itin_time_to_minutes($meetupTimeIn);
            if ($win && $pickedMins !== null) {
                $openMins = itin_time_to_minutes($win['min']);
                $cutMins = itin_time_to_minutes($win['max']);
                if ($openMins !== null && $cutMins !== null && ($pickedMins < $openMins || $pickedMins > $cutMins)) {
                    $msg = 'Meetup time must be between ' . format_time_12h($win['min']) . ' and '
                        . format_time_12h($win['max']) . ', when a tour guide can be booked.';
                    if ($wantsJson) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    flash('error', $msg);
                    redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
                }
            }
        }
        $timeOnly = !empty($_POST['time_only']);
        $ref = null;
        if ($timeOnly) {
            $err = itinerary_set_day_meetup_time($iid, $dayNum, $meetupTimeIn, !empty($_POST['allow_past']));
        } elseif ($usePort) {
            $err = itinerary_set_day_meetup($iid, $dayNum, null, true, $meetupTimeIn !== '' ? $meetupTimeIn : null);
        } elseif ($clear) {
            $err = itinerary_set_day_meetup($iid, $dayNum, null, false);
        } else {
            $ref = parse_itinerary_stop_ref($_POST);
            if ($ref === null) {
                $msg = 'Choose a destination or business for the meetup.';
                if ($wantsJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                flash('error', $msg);
                redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
            }
            $err = itinerary_set_day_meetup($iid, $dayNum, $ref, false, $meetupTimeIn !== '' ? $meetupTimeIn : null);
        }
        if ($err !== null) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $err], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('error', $err);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $itinRow = db()->prepare('SELECT * FROM itineraries WHERE id=?');
        $itinRow->execute([$iid]);
        $itinRow = $itinRow->fetch() ?: [];
        $visitDate = null;
        if (!empty($itinRow['start_date'])) {
            $visitDate = itinerary_date_for_logical_day($iid, $dayNum, $itinRow)
                ?: itinerary_date_for_day((string) $itinRow['start_date'], $dayNum);
        }
        itinerary_reflow_day_times($iid, $visitDate);
        $place = itinerary_day_meetup_place($iid, $dayNum, $itinRow);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'day' => $dayNum,
                'meetup' => [
                    'label' => $place['label'],
                    'key' => $place['key'],
                    'kind' => $place['kind'],
                    'is_default' => !empty($place['is_default']),
                    'cover_url' => $place['cover_url'] ?? null,
                    'has_place' => !empty($place['has_place']),
                    'meetup_display' => (string) ($place['meetup_display'] ?? ''),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'auto_regenerate' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        if (itinerary_schedule_is_locked($iid)) {
            $active = itinerary_active_booking($iid);
            flash(
                'error',
                'Tour dates are locked while booking '
                    . ($active['booking_code'] ?? '')
                    . ' is ' . ($active['status'] ?? 'active')
                    . '. Cancel that booking first to change dates or re-generate.'
            );
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $rawMeetup = trim((string) ($_POST['meetup_time'] ?? ''));
        $meetup = $rawMeetup === '' ? null : (normalize_time_input($rawMeetup) ?? null);
        if ($rawMeetup !== '' && $meetup === null) {
            flash('error', 'Enter a valid meetup time (e.g. 8:00 AM).');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        // Prefer posted date; else saved tour date; else tomorrow — date is not required to generate
        $postForDates = $_POST;
        if (trim((string) ($postForDates['start_date'] ?? '')) === '') {
            $savedDates = db()->prepare('SELECT start_date, end_date, template_day_count FROM itineraries WHERE id=? AND user_id=?');
            $savedDates->execute([$iid, $user['id']]);
            $savedDates = $savedDates->fetch() ?: [];
            $savedStart = normalize_tour_date($savedDates['start_date'] ?? null);
            if ($savedStart !== null) {
                $postForDates['start_date'] = $savedStart;
                // Keep multi-day from the form; if unset, infer from saved range
                if (empty($postForDates['is_multiday'])) {
                    $savedDays = itinerary_logical_day_count($savedDates);
                    if ($savedDays > 1) {
                        $postForDates['is_multiday'] = '1';
                        $postForDates['day_count'] = (string) $savedDays;
                    }
                }
            }
        }
        [$startDate, $endDate, $dayCount, $usedDefault] = itinerary_dates_for_autogen($postForDates);
        // Only validate calendar dates the tourist actually chose
        if (!$usedDefault) {
            $pastMsg = tour_datetime_past_message($startDate, $meetup, 'Meetup time');
            if ($pastMsg !== null) {
                flash('error', $pastMsg);
                redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
            }
            $conflict = tourist_date_range_booking_conflict_message(
                (int) $user['id'],
                $startDate,
                $endDate,
                $iid
            );
            if ($conflict !== null) {
                flash('error', $conflict);
                redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
            }
        }
        $cur = db()->prepare('SELECT title, description FROM itineraries WHERE id=? AND user_id=?');
        $cur->execute([$iid, $user['id']]);
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
                [
                    'keep_undated' => !empty($usedDefault),
                    'day_meetup' => itinerary_posted_day_meetup_times($_POST),
                ]
            );
            if (!empty($result['warning'])) {
                flash('warning', $result['warning']);
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage() ?: 'Could not auto-generate this tour.');
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'reorder_stops' && itin_owned((int) $_POST['itinerary_id'], (int) $user['id'])) {
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
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
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
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'delete' && itin_owned((int) ($_POST['id'] ?? 0), (int) $user['id'])) {
        $delId = (int) $_POST['id'];
        db()->prepare('DELETE FROM itineraries WHERE id=? AND user_id=?')->execute([$delId, $user['id']]);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'id' => $delId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('success', 'Itinerary deleted.');
        redirect(APP_URL . '/tourist/itineraries.php');
    }

    if ($action === 'add_stop' && itin_owned((int) $_POST['itinerary_id'], (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $ref = parse_itinerary_stop_ref($_POST);
        if (!$ref) {
            flash('error', 'Choose a destination or business to add.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $blockedStop = itinerary_stop_business_blocked((int) ($ref['business_id'] ?? 0));
        if ($blockedStop !== null) {
            flash('error', $blockedStop);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }

        $place = itinerary_place_coords($ref);
        if (!$place) {
            flash('error', 'That place is not available.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $noHoursMsg = itinerary_place_unavailable_message($ref, $place);
        if ($noHoursMsg !== null) {
            flash('error', $noHoursMsg);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }

        $itinRow = db()->prepare('SELECT * FROM itineraries WHERE id=?');
        $itinRow->execute([$iid]);
        $itinRow = $itinRow->fetch() ?: [];
        $dayCount = itinerary_logical_day_count($itinRow);
        $dayNum = (int) ($_POST['day_number'] ?? 1);
        if ($dayNum < 1) {
            $dayNum = 1;
        }
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
            flash('error', 'You can’t add ' . ($dup['name'] ?? 'this place') . ' as the next stop — it’s already the last stop on this day. Add a different place in between.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $allowDuplicate = !empty($_POST['allow_duplicate']);
        if (!empty($dup['exists']) && !$allowDuplicate) {
            flash('error', ($dup['name'] ?? 'That place') . ' is already on this tour. Confirm to add it again.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }

        // Hours hard-block uses the stop’s visit day (skipped when drafting without a date)
        $itinForHours = $itinRow;
        $itinForHours['start_date'] = $visitDate;
        $prev = itinerary_prev_leg_context($iid, $itinRow, $visitDate);
        if (!$hasTourDate) {
            // No weekday known yet — still enforce travel timing, not opening hours
            $place['opening_hours'] = null;
        }

        $hasCoords = $place['lat'] != 0.0 || $place['lng'] != 0.0;
        if (!empty($prev['skip_travel'])) {
            // First stop on Day 2+ — new day, no travel-from-Port constraint
            $travelMins = 0;
        } else {
            $postedTravel = (int) ($_POST['travel_minutes'] ?? 0);
            $travelMins = $hasCoords
                ? itin_estimate_travel_minutes($prev['lat'], $prev['lng'], $place['lat'], $place['lng'])
                : ($postedTravel > 0 ? $postedTravel : 45);
        }

        $rawVisit = trim((string) ($_POST['visit_minutes'] ?? ''));
        if ($rawVisit === '' || !is_numeric($rawVisit)) {
            flash('error', 'Enter how many minutes to visit this stop.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $visitMins = (int) $rawVisit;
        if ($visitMins < 15) {
            flash('error', 'Visit time must be at least 15 minutes.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        if ($visitMins > ITIN_MAX_VISIT_MINUTES) {
            $visitMins = ITIN_MAX_VISIT_MINUTES;
        }
        $limitMsg = itinerary_visit_limit_message((string) ($place['name'] ?? ''), $place['visit_limit_minutes'] ?? null, $visitMins);
        if ($limitMsg !== null) {
            flash('error', $limitMsg);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $minMsg = itinerary_visit_min_message((string) ($place['name'] ?? ''), $place['min_visit_minutes'] ?? null, $visitMins);
        if ($minMsg !== null) {
            flash('error', $minMsg);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
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
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
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
        $newStopId = (int) db()->lastInsertId();
        $reserve = '';
        if (!empty($ref['business_id'])) {
            $typeSt = db()->prepare(
                'SELECT bt.name FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id WHERE b.id=? LIMIT 1'
            );
            $typeSt->execute([(int) $ref['business_id']]);
            $addedKind = revenue_bookable_kind((string) $typeSt->fetchColumn());
            if (revenue_uses_reservation($addedKind)) {
                $reserve = '&reserve=' . $newStopId;
            }
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid . $reserve);
    }

    if ($action === 'clear_stops' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        db()->prepare('DELETE FROM itinerary_items WHERE itinerary_id=?')->execute([$iid]);
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'remove_stop' && itin_owned((int) $_POST['itinerary_id'], (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        db()->prepare('DELETE FROM itinerary_items WHERE id=? AND itinerary_id=?')
            ->execute([(int) $_POST['item_id'], $iid]);
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'update_stop_stay' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
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
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($action === 'cancel_booking' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        $bid = (int) ($_POST['booking_id'] ?? 0);
        $b = db()->prepare('SELECT * FROM bookings WHERE id=? AND tourist_id=? AND itinerary_id=?');
        $b->execute([$bid, $user['id'], $iid]);
        $booking = $b->fetch();
        if (!$booking) {
            flash('error', 'Booking not found.');
        } else {
            $res = booking_tourist_cancel($booking, (string) ($_POST['refund_number'] ?? ''), refund_opts_from($_POST));
            flash($res['ok'] ? 'success' : 'error', $res['message']);
        }
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
    }

    if ($packageMaker && $action === 'book_guide') {
        if ((string) ($_POST['itinerary_save_mode'] ?? '') === 'new') {
            require_once __DIR__ . '/../includes/payments.php';
            $sourceId = (int) ($_POST['itinerary_id'] ?? 0);
            if ($sourceId > 0 && itin_owned($sourceId, (int) $user['id'])) {
                $copy = itinerary_duplicate($sourceId);
                $newId = (int) ($copy['id'] ?? 0);
                if ($newId > 0) {
                    db()->prepare("UPDATE itineraries SET title=TRIM(CONCAT(title, ' copy')) WHERE id=?")->execute([$newId]);
                }
            }
        }
        $price = round(max(0, (float) ($_POST['package_price'] ?? 0)), 2);
        if ($price <= 0) {
            flash('error', 'Enter the price per person.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        $madeName = trim((string) ($_POST['title'] ?? ''));
        if ($madeName === '') {
            $nameSt = db()->prepare('SELECT title FROM itineraries WHERE id=? LIMIT 1');
            $nameSt->execute([(int) ($_POST['itinerary_id'] ?? 0)]);
            $madeName = trim((string) $nameSt->fetchColumn());
        }
        if ($madeName === '' || strcasecmp($madeName, 'Unnamed') === 0) {
            $madeName = (string) ($GLOBALS['CULTOUR_PACKAGE_NAME'] ?? 'Package');
        }
        $modeSt = db()->prepare('SELECT package_date_mode, package_fixed_date FROM business_services WHERE id=? AND business_id=?');
        $modeSt->execute([(int) $GLOBALS['CULTOUR_PACKAGE_ID'], (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0)]);
        $modeRow = $modeSt->fetch() ?: [];
        $dateMode = (string) ($modeRow['package_date_mode'] ?? 'tourist') === 'fixed' ? 'fixed' : 'tourist';
        $fixedDate = $dateMode === 'fixed' ? normalize_tour_date($modeRow['package_fixed_date'] ?? null) : null;
        if ($dateMode !== 'fixed') {
            $dateSt = db()->prepare('SELECT start_date FROM itineraries WHERE id=? LIMIT 1');
            $dateSt->execute([(int) ($_POST['itinerary_id'] ?? 0)]);
            $typedDate = normalize_tour_date($dateSt->fetchColumn() ?: null);
            if ($typedDate !== null) {
                $dateMode = 'fixed';
                $fixedDate = $typedDate;
            }
        }
        $guideId = (int) ($_POST['guide_id'] ?? 0);
        $stayJson = json_encode([
            'business_id' => (int) ($_POST['accommodation_business_id'] ?? 0),
            'service_id' => (int) ($_POST['accommodation_service_id'] ?? 0),
            'declined' => (int) ($_POST['accommodation_declined'] ?? 0) === 1 ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);
        $rideJson = json_encode([
            'business_id' => (int) ($_POST['transportation_business_id'] ?? 0),
            'service_id' => (int) ($_POST['transportation_service_id'] ?? 0),
            'declined' => (int) ($_POST['transportation_declined'] ?? 0) === 1 ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);
        $itinId = (int) ($_POST['itinerary_id'] ?? 0);
        if ($dateMode === 'fixed' && $fixedDate) {
            db()->prepare('UPDATE itineraries SET start_date=? WHERE id=?')->execute([$fixedDate, $itinId]);
        }
        $span = db()->prepare('SELECT start_date, end_date, party_size FROM itineraries WHERE id=? LIMIT 1');
        $span->execute([$itinId]);
        $spanRow = $span->fetch() ?: [];
        $packageDays = 1;
        $startTs = strtotime((string) ($spanRow['start_date'] ?? ''));
        $endTs = strtotime((string) ($spanRow['end_date'] ?? ''));
        if ($startTs && $endTs && $endTs >= $startTs) {
            $packageDays = (int) floor(($endTs - $startTs) / 86400) + 1;
        }
        $packageDays = max(1, $packageDays);
        $minPax = (int) ($_POST['package_min_pax'] ?? 0);
        $maxPax = (int) ($_POST['package_max_pax'] ?? 0);
        if ($minPax < 1 || $maxPax < 1 || $minPax > $maxPax) {
            flash('error', 'Enter the minimum and maximum participants.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        if ($dateMode === 'fixed' && !$fixedDate) {
            flash('error', 'Set the package tour date.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        $postedClose = normalize_tour_date($_POST['package_close_date'] ?? null);
        if ($dateMode === 'fixed' && $postedClose === null) {
            flash('error', 'Set the package cut off.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        if ($dateMode === 'fixed' && $fixedDate < package_tour_earliest_date()) {
            flash('error', 'The package tour date must be at least 2 days from today.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        $latestClose = $dateMode === 'fixed' ? package_cutoff_date($fixedDate) : null;
        if ($dateMode === 'fixed' && $postedClose !== null && $latestClose !== null && $postedClose > $latestClose) {
            flash('error', 'The package cut off must be at least 2 days before the tour date.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        $pax = $maxPax;
        db()->prepare('UPDATE itineraries SET party_size=? WHERE id=?')->execute([$pax, $itinId]);
        $inclusionTags = [];
        foreach (preg_split('/,/', (string) ($_POST['package_inclusions'] ?? '')) ?: [] as $inclusionTag) {
            $inclusionTag = trim($inclusionTag);
            if ($inclusionTag === '' || mb_strlen($inclusionTag) > 40 || isset($inclusionTags[mb_strtolower($inclusionTag)])) {
                continue;
            }
            $inclusionTags[mb_strtolower($inclusionTag)] = $inclusionTag;
            if (count($inclusionTags) >= 8) {
                break;
            }
        }
        $inclusions = $inclusionTags ? implode(', ', $inclusionTags) : null;
        $packageDescription = trim((string) ($_POST['package_description'] ?? ''));
        if ($packageDescription === '') {
            flash('error', 'Enter the package description.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        if (mb_strlen($packageDescription) > 120) {
            $packageDescription = mb_substr($packageDescription, 0, 120);
        }
        if (!$inclusions) {
            flash('error', 'Add at least one inclusion.');
            redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        }
        $coverPath = null;
        if (!empty($_FILES['package_cover']['name'])) {
            $coverPath = upload_file($_FILES['package_cover'], 'businesses', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            if (!$coverPath) {
                flash('error', upload_last_error() ?: 'Cover photo upload failed. Use a JPG, PNG, or WebP image.');
                redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
            }
        } else {
            $existingCover = db()->prepare('SELECT image_path FROM business_services WHERE id=? AND business_id=?');
            $existingCover->execute([(int) $GLOBALS['CULTOUR_PACKAGE_ID'], (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0)]);
            if (trim((string) $existingCover->fetchColumn()) === '') {
                flash('error', 'Add a cover photo.');
                redirect(APP_URL . '/business/package.php?id=' . (int) $GLOBALS['CULTOUR_PACKAGE_ID']);
            }
        }
        $coverSql = $coverPath !== null ? ', image_path=?' : '';
        $coverParams = $coverPath !== null ? [$coverPath] : [];
        $listNow = true;
        db()->prepare(
            'UPDATE business_services
             SET name=?, description=?, price=?, package_made=?, is_active=?, package_awaiting=?, package_date_mode=?, package_fixed_date=?, package_close_date=?,
                 package_guide_id=?, package_stay_json=?, package_ride_json=?, package_days=?, capacity=?, package_min_pax=?, package_inclusions=?'
            . $coverSql . '
             WHERE id=? AND business_id=?'
        )->execute(array_merge([
            $madeName,
            $packageDescription !== '' ? $packageDescription : null,
            $price,
            $listNow ? 1 : 0,
            $listNow ? 1 : 0,
            $listNow ? 0 : 1,
            $dateMode,
            $fixedDate,
            $dateMode === 'fixed' ? $postedClose : null,
            $guideId > 0 ? $guideId : null,
            $stayJson,
            $rideJson,
            $packageDays,
            $pax,
            $minPax,
            $inclusions,
        ], $coverParams, [
            (int) $GLOBALS['CULTOUR_PACKAGE_ID'],
            (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0),
        ]));
        db()->prepare("UPDATE itineraries SET kept_as_draft=0, status='active' WHERE id=?")->execute([$itinId]);
        package_stops_delete_for_service((int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        $stopRows = db()->prepare(
            'SELECT destination_id, business_id, visit_minutes, visit_date, sort_order
             FROM itinerary_items WHERE itinerary_id=? ORDER BY sort_order, id'
        );
        $stopRows->execute([$itinId]);
        $insertStop = db()->prepare(
            'INSERT INTO package_stops (service_id, day_number, sort_order, destination_id, business_id, visit_minutes)
             VALUES (?,?,?,?,?,?)'
        );
        $anchor = $startTs ?: null;
        $daySort = [];
        foreach ($stopRows->fetchAll() ?: [] as $stopRow) {
            $day = 1;
            $visitTs = strtotime((string) ($stopRow['visit_date'] ?? ''));
            if ($anchor && $visitTs && $visitTs >= $anchor) {
                $day = (int) floor(($visitTs - $anchor) / 86400) + 1;
            }
            $daySort[$day] = ($daySort[$day] ?? 0);
            $insertStop->execute([
                (int) $GLOBALS['CULTOUR_PACKAGE_ID'],
                $day,
                $daySort[$day],
                (int) ($stopRow['destination_id'] ?? 0) > 0 ? (int) $stopRow['destination_id'] : null,
                (int) ($stopRow['business_id'] ?? 0) > 0 ? (int) $stopRow['business_id'] : null,
                max(1, (int) ($stopRow['visit_minutes'] ?? 60)),
            ]);
            $daySort[$day]++;
        }
        if ($dateMode === 'fixed') {
            $toldBusiness = [];
            $notifyPlace = static function (int $businessId) use (&$toldBusiness, $madeName, $fixedDate): void {
                if ($businessId < 1 || isset($toldBusiness[$businessId])) {
                    return;
                }
                $owner = db()->prepare(
                    'SELECT b.user_id, b.name, bt.name AS type_name
                     FROM businesses b JOIN business_types bt ON bt.id=b.business_type_id
                     WHERE b.id=? LIMIT 1'
                );
                $owner->execute([$businessId]);
                $place = $owner->fetch();
                if (!$place) {
                    return;
                }
                $kind = revenue_bookable_kind((string) ($place['type_name'] ?? ''));
                if (!in_array($kind, ['food', 'beach', 'wellness', 'accommodation', 'transportation'], true)) {
                    return;
                }
                $toldBusiness[$businessId] = true;
                notify_user(
                    (int) $place['user_id'],
                    'booking_request',
                    'Inclusive package',
                    'Accept ' . $madeName . ' for ' . (string) $place['name'] . ' on ' . format_date((string) $fixedDate) . ' before it is listed.',
                    in_array($kind, ['food', 'beach', 'wellness'], true)
                        ? APP_URL . '/business/reservations.php'
                        : APP_URL . '/business/dashboard.php'
                );
            };
            $stopBiz = db()->prepare('SELECT DISTINCT business_id FROM itinerary_items WHERE itinerary_id=? AND business_id IS NOT NULL');
            $stopBiz->execute([$itinId]);
            foreach ($stopBiz->fetchAll(PDO::FETCH_COLUMN) ?: [] as $stopBusinessId) {
                $notifyPlace((int) $stopBusinessId);
            }
            if ((int) ($_POST['accommodation_declined'] ?? 0) !== 1) {
                $notifyPlace((int) ($_POST['accommodation_business_id'] ?? 0));
            }
            if ((int) ($_POST['transportation_declined'] ?? 0) !== 1) {
                $notifyPlace((int) ($_POST['transportation_business_id'] ?? 0));
            }
            if ($guideId > 0) {
                $guideRow = db()->prepare(
                    "SELECT g.user_id, g.guide_type, u.first_name, u.last_name
                     FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id WHERE g.id=? LIMIT 1"
                );
                $guideRow->execute([$guideId]);
                $guideRow = $guideRow->fetch() ?: [];
                $guideName = trim((string) ($guideRow['first_name'] ?? '') . ' ' . (string) ($guideRow['last_name'] ?? ''));
                if ((int) ($guideRow['user_id'] ?? 0) > 0) {
                    notify_user((int) $guideRow['user_id'], 'guide_assignment', 'Inclusive package', 'Accept ' . $madeName . ' on ' . format_date((string) $fixedDate) . ' before it is listed.', APP_URL . '/tourguide/bookings.php');
                }
                if ((string) ($guideRow['guide_type'] ?? '') !== 'in_house') {
                    $admins = db()->query("SELECT id FROM users WHERE role_id=" . (int) ROLE_ADMIN . " AND status='active'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($admins as $adminId) {
                        notify_user((int) $adminId, 'guide_request', 'Tour guide request', $madeName . ' needs ' . ($guideName !== '' ? $guideName : 'a system tour guide') . ' on ' . format_date((string) $fixedDate) . '.', APP_URL . '/admin/guide-requests.php');
                    }
                }
            }
            package_clearance_replace((int) $GLOBALS['CULTOUR_PACKAGE_ID'], array_keys($toldBusiness), $guideId);
            package_clearance_publish_if_ready((int) $GLOBALS['CULTOUR_PACKAGE_ID']);
        } else {
            package_clearance_ensure_schema();
            db()->prepare('DELETE FROM package_clearances WHERE service_id=?')->execute([(int) $GLOBALS['CULTOUR_PACKAGE_ID']]);
        }
        flash('success', $dateMode === 'fixed'
            ? 'Inclusive package saved. It is listed under Packages made and Tour packages.'
            : 'Package saved. It is listed under Packages made and Tour packages. Places are not reserved until a tourist books it.');
        unset($_SESSION['pkg_itin_base_' . $itinId]);
        redirect(APP_URL . '/business/services.php');
    }

    if ($action === 'book_guide' && itin_owned((int) ($_POST['itinerary_id'] ?? 0), (int) $user['id'])) {
        $iid = (int) $_POST['itinerary_id'];
        if (itinerary_schedule_is_locked($iid)) {
            $active = itinerary_active_booking($iid);
            flash(
                'error',
                'This tour already has booking '
                    . ($active['booking_code'] ?? '')
                    . ' (' . ($active['status'] ?? 'active') . ').'
            );
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $itin = db()->prepare('SELECT * FROM itineraries WHERE id=? AND user_id=?');
        $itin->execute([$iid, $user['id']]);
        $itin = $itin->fetch();
        if (!$itin) {
            flash('error', 'Itinerary not found.');
            redirect(APP_URL . '/tourist/itineraries.php');
        }
        if (empty($itin['start_date'])) {
            flash('error', 'Set a tour date before booking a guide.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        if (itinerary_is_placeholder_title($itin['title'] ?? '')) {
            flash('error', 'Name this itinerary before booking a guide.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $stopCount = db()->prepare('SELECT COUNT(*) FROM itinerary_items WHERE itinerary_id=?');
        $stopCount->execute([$iid]);
        if ((int) $stopCount->fetchColumn() < 1) {
            flash('error', 'Add at least one stop to this tour before booking a guide.');
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $bookDate = (string) $itin['start_date'];
        $bookTime = format_time_12h((string) ($itin['meetup_time'] ?? '09:00:00')) ?: '9:00 AM';
        $check = validate_booking_request(
            (int) $user['id'],
            (int) ($_POST['guide_id'] ?? 0),
            $bookDate,
            $bookTime,
            $_POST['party_size'] ?? null,
            $iid,
            null
        );
        if (!$check['ok']) {
            flash('error', $check['message']);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid . '&book=1');
        }
        $acc = booking_parse_accessibility();
        if (!$acc['ok']) {
            flash('error', $acc['message']);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid . '&review=1');
        }
        $stay = stay_ride_parse($_POST);
        if (!$stay['ok']) {
            $savedStay = stay_ride_from_row($itin);
            $stay = is_array($savedStay) && !empty($savedStay['ok']) ? $savedStay : $stay;
        }
        if (!$stay['ok']) {
            flash('error', $stay['message']);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        [$tourStart, $tourEnd] = booking_resolve_tour_span(
            $check['itinerary_id'] ? (int) $check['itinerary_id'] : null,
            (string) $check['date']
        );
        $charge = booking_charge_for_stay(
            $stay,
            (int) $check['party'],
            $tourStart,
            $tourEnd,
            (float) ($check['guide']['hourly_rate'] ?? 0),
            $check['itinerary_id'] ? (int) $check['itinerary_id'] : null
        );
        if (!$charge['ok']) {
            flash('error', $charge['message']);
            redirect(APP_URL . '/tourist/itineraries.php?id=' . $iid);
        }
        $stay['accommodation_lines'] = $charge['accommodation_lines'] ?? [];
        $stay['transportation_lines'] = $charge['transportation_lines'] ?? [];
        stay_ride_save_itinerary($iid, (int) $user['id'], $stay);
        ensure_booking_payment_schema();
        require_once __DIR__ . '/../includes/payments.php';
        $code = booking_code();
        $bookingId = booking_insert_pending([
            'booking_code' => $code,
            'tourist_id' => $user['id'],
            'guide_id' => (int) $_POST['guide_id'],
            'itinerary_id' => $check['itinerary_id'],
            'destination_id' => $check['destination_id'],
            'booking_date' => $check['date'],
            'tour_start_date' => $tourStart,
            'tour_end_date' => $tourEnd,
            'start_time' => $check['time'],
            'party_size' => $check['party'],
            'notes' => (function () use ($packageMaker) {
                $notes = trim((string) ($_POST['notes'] ?? ''));
                if ($packageMaker) {
                    return $notes;
                }
                $lines = [];
                $adults = max(0, (int) ($_POST['guide_adults'] ?? 0));
                $children = max(0, (int) ($_POST['guide_children'] ?? 0));
                $infants = max(0, (int) ($_POST['guide_infants'] ?? 0));
                if ($adults + $children + $infants > 0) {
                    $lines[] = 'Adults: ' . $adults;
                    $lines[] = 'Children: ' . $children;
                    if ($infants > 0) {
                        $lines[] = 'Infant/toddler: ' . $infants . ' (not counted as guests)';
                    }
                }
                $needs = json_decode((string) ($_POST['guide_needs'] ?? ''), true);
                if (is_array($needs)) {
                    foreach ($needs as $need) {
                        if (!is_array($need)) {
                            continue;
                        }
                        $label = trim((string) ($need['label'] ?? ''));
                        if ($label === '') {
                            continue;
                        }
                        $line = $label;
                        $count = (int) ($need['count'] ?? 0);
                        if ($count > 0) {
                            $line .= ' · ' . $count;
                        }
                        $detail = trim((string) ($need['detail'] ?? ''));
                        if ($detail !== '') {
                            $line .= ' · ' . mb_substr($detail, 0, 160);
                        }
                        $lines[] = $line;
                    }
                }
                if (!$lines) {
                    return $notes;
                }
                $extra = implode("\n", $lines);
                return $notes === '' ? $extra : $notes . "\n" . $extra;
            })(),
            'accommodation_business_id' => $stay['accommodation_business_id'],
            'accommodation_service_id' => $stay['accommodation_service_id'],
            'accommodation_declined' => (int) $stay['accommodation_declined'],
            'accommodation_units' => $charge['accommodation_units'],
            'accommodation_extra_guests' => $charge['accommodation_extra_guests'],
            'accommodation_amount' => $charge['accommodation_amount'],
            'accommodation_lines' => !empty($charge['accommodation_lines'])
                ? json_encode($charge['accommodation_lines'], JSON_UNESCAPED_UNICODE)
                : null,
            'transportation_business_id' => $stay['transportation_business_id'],
            'transportation_service_id' => $stay['transportation_service_id'],
            'transportation_declined' => (int) $stay['transportation_declined'],
            'transportation_units' => $charge['transportation_units'],
            'transportation_extra_guests' => $charge['transportation_extra_guests'],
            'transportation_amount' => $charge['transportation_amount'],
            'transportation_lines' => !empty($charge['transportation_lines'])
                ? json_encode($charge['transportation_lines'], JSON_UNESCAPED_UNICODE)
                : null,
            'guide_amount' => $charge['guide_amount'],
            'payment_amount' => $charge['payment_amount'],
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);
        booking_save_accessibility($bookingId, $acc);
        if (!empty($check['itinerary_id'])) {
            db()->prepare("UPDATE itineraries SET kept_as_draft=0, status='active' WHERE id=?")
                ->execute([(int) $check['itinerary_id']]);
        }
        $pay = booking_start_gcash_payment(
            ['id' => $bookingId, 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_intent_id' => null],
            $check['guide']
        );
        if ($pay['ok'] && !empty($pay['checkout_url'])) {
            redirect($pay['checkout_url']);
        }
        flash('success', 'Booking created (Code: ' . $code . '). Your tour guide accepts it after payment.');
        redirect(APP_URL . '/tourist/bookings.php');
    }

    redirect(APP_URL . '/tourist/itineraries.php');
}

$viewId = (int) ($_GET['id'] ?? 0);
$view = null;
$stops = [];
if ($viewId) {
    $s = db()->prepare(
        'SELECT * FROM itineraries WHERE id=? AND (
            user_id=? OR (' . itinerary_sql_published_suggested('') . ')
         )'
    );
    $s->execute([$viewId, $user['id']]);
    $view = $s->fetch() ?: null;
    if ($view) {
        $stops = itinerary_items_with_places($viewId);
    }
}

// No tour selected → open (or create) an empty Unnamed draft (not listed until filled/renamed)
if (!$packageMaker && !$view && !isset($_GET['book'])) {
    $reuseId = itinerary_find_blank_draft_id((int) $user['id']);
    if ($reuseId > 0) {
        itinerary_prune_blank_drafts((int) $user['id'], $reuseId);
        redirect(APP_URL . '/tourist/itineraries.php?id=' . $reuseId . '&drafts=1');
    }
    $meetup = null;
    db()->prepare(
        'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, status)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        (int) $user['id'],
        'Unnamed',
        '',
        null,
        null,
        $meetup,
        0,
        'draft',
    ]);
    $newId = (int) db()->lastInsertId();
    itinerary_prune_blank_drafts((int) $user['id'], $newId);
    redirect(APP_URL . '/tourist/itineraries.php?id=' . $newId . '&drafts=1');
}

// Keep only one blank Unnamed draft around the current view
if (!$packageMaker && $view && (int) ($view['is_suggested'] ?? 0) === 0) {
    itinerary_prune_blank_drafts((int) $user['id'], (int) $view['id']);
}

// Saved tours only: renamed and/or with at least one stop
ensure_booking_payment_schema();
$mine = db()->prepare(
    'SELECT i.* FROM itineraries i
     WHERE i.user_id=? AND i.is_suggested=0 AND ' . itinerary_sql_is_saved_tour('i') . '
     ORDER BY i.created_at DESC'
);
$mine->execute([$user['id']]);
$mine = $mine->fetchAll() ?: [];
$mineIds = array_map(static fn($r) => (int) $r['id'], $mine);
// Past approved tours become completed (then leave the main chip row)
bookings_auto_complete_past_tours();
$bookedItinIds = itinerary_ids_with_active_booking($mineIds);
$completedItinIds = itinerary_ids_with_completed_booking($mineIds);

$activeMine = [];
foreach ($mine as $r) {
    $rid = (int) $r['id'];
    $isCompleted = !empty($completedItinIds[$rid])
        || ($r['status'] ?? '') === 'completed'
        || ($r['status'] ?? '') === 'archived';
    // Finished tours leave the main chip row; still-booked ones stay
    if ($isCompleted && empty($bookedItinIds[$rid])) {
        continue;
    }
    $activeMine[] = $r;
}

usort($activeMine, static function (array $a, array $b) use ($bookedItinIds): int {
    $aBooked = !empty($bookedItinIds[(int) $a['id']]);
    $bBooked = !empty($bookedItinIds[(int) $b['id']]);
    if ($aBooked !== $bBooked) {
        return $aBooked ? -1 : 1;
    }
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});

$draftMine = [];
$mainMine = [];
foreach ($activeMine as $r) {
    $rid = (int) $r['id'];
    // Booked tours stay on the chip row. View drafts lists only tours the
    // tourist explicitly saved with Save draft.
    if (!empty($bookedItinIds[$rid])) {
        $mainMine[] = $r;
    } elseif (!empty($r['kept_as_draft'])) {
        $draftMine[] = $r;
    }
}
if ($packageMaker) {
    $packageBizId = (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0);
    $packageDraftIds = [];
    if ($packageBizId > 0) {
        $packageDraftSt = db()->prepare(
            'SELECT package_itinerary_id FROM business_services
             WHERE business_id=? AND package_made=0 AND package_itinerary_id IS NOT NULL'
        );
        $packageDraftSt->execute([$packageBizId]);
        foreach ($packageDraftSt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $packageDraftId) {
            $packageDraftIds[(int) $packageDraftId] = true;
        }
    }
    $draftMine = array_values(array_filter(
        $draftMine,
        static fn (array $row): bool => isset($packageDraftIds[(int) ($row['id'] ?? 0)])
    ));
}
$draftMax = itinerary_max_drafts('tourist');
$draftCount = count($draftMine);
$draftToggleLabel = $draftCount > 0
    ? ('View drafts (' . $draftCount . '/' . $draftMax . ')')
    : 'View drafts';
$viewIsListedDraft = $view && in_array((int) $view['id'], array_map(static fn($r) => (int) $r['id'], $draftMine), true);
$draftsAtLimit = $draftCount >= $draftMax;

// Booked tours should not stay flagged as drafts in the DB
if ($mainMine) {
    $bookedIds = array_map(static fn($r) => (int) $r['id'], $mainMine);
    $ph = implode(',', array_fill(0, count($bookedIds), '?'));
    try {
        db()->prepare(
            "UPDATE itineraries SET kept_as_draft=0, status='active'
             WHERE id IN ($ph) AND (kept_as_draft=1 OR status='draft')"
        )->execute($bookedIds);
    } catch (Throwable $e) {
        // ignore
    }
}

$showDrafts = (string) ($_GET['drafts'] ?? '') === '1';

// Opening an unbooked tour → open it under View draft (booked tours stay on the main row)
if (
    !$packageMaker
    && !$showDrafts
    && $view
    && (int) ($view['user_id'] ?? 0) === (int) $user['id']
    && !(int) ($view['is_suggested'] ?? 0)
    && empty($bookedItinIds[(int) $view['id']])
    && !empty($view['kept_as_draft'])
) {
    redirect(APP_URL . '/tourist/itineraries.php?id=' . (int) $view['id'] . '&drafts=1');
}

$suggested = db()->query(
    'SELECT * FROM itineraries WHERE ' . itinerary_sql_published_suggested('') . ' ORDER BY created_at DESC LIMIT 10'
)->fetchAll() ?: [];

$bookDestId = (int) ($_GET['destination_id'] ?? 0);
$bookGuideUser = (int) ($_GET['guide_user'] ?? 0);
if (!$view && isset($_GET['book'])) {
    if ($mainMine) {
        $qs = 'id=' . (int) $mainMine[0]['id'] . '&book=1';
        if ($bookDestId) {
            $qs .= '&destination_id=' . $bookDestId;
        }
        if ($bookGuideUser > 0) {
            $qs .= '&guide_user=' . $bookGuideUser;
        }
        redirect(APP_URL . '/tourist/itineraries.php?' . $qs);
    }
    flash('error', 'Add a named tour (or stops) first, then book a guide.');
    redirect(APP_URL . '/tourist/itineraries.php');
}

$ownsView = $view && (int) $view['user_id'] === (int) $user['id'] && !(int) ($view['is_suggested'] ?? 0);
if ($view && $ownsView) {
    booking_abandon_unpaid_checkout((int) $view['id'], (int) $user['id']);
}
$activeBooking = ($view && $ownsView) ? itinerary_active_booking((int) $view['id']) : null;
$scheduleLocked = $activeBooking !== null;
$canEdit = $ownsView && !$scheduleLocked;
$holdProceed = false;
if ($view && $ownsView && $scheduleLocked) {
    $holdSt = db()->prepare(
        "SELECT ii.id FROM itinerary_items ii
         WHERE ii.itinerary_id=? AND ii.reservation_draft IS NOT NULL AND ii.reservation_draft<>''
           AND EXISTS (
             SELECT 1 FROM restaurant_reservations r
             WHERE r.itinerary_item_id=ii.id AND r.status='rejected'
           )
         LIMIT 1"
    );
    $holdSt->execute([(int) $view['id']]);
    $holdProceed = (bool) $holdSt->fetch();
}
// When booked, show the frozen booking span (not later itinerary edits)
if ($scheduleLocked && $view) {
    [$lockStart, $lockEnd] = booking_effective_tour_span($activeBooking);
    if ($lockStart) {
        $view['start_date'] = $lockStart;
        $view['end_date'] = $lockEnd;
    }
}
$existingDestIds = array_values(array_filter(array_map('intval', array_column($stops, 'destination_id'))));
$existingBizIds = array_values(array_filter(array_map('intval', array_column($stops, 'business_id'))));

$placePickerGroups = [];
$favPicker = [];
foreach ($favoriteDestinations as $d) {
    $onTour = in_array((int) $d['id'], $existingDestIds, true);
    $favPicker[] = [
        'key' => 'd:' . (int) $d['id'],
        'label' => (string) $d['name']
            . (!empty($d['location']) ? ' — ' . $d['location'] : '')
            . ($onTour ? ' (on this tour)' : ''),
        'on_tour' => $onTour,
    ];
}
if ($favPicker) {
    $placePickerGroups[] = ['label' => 'Favorites', 'items' => $favPicker];
}
$destPicker = [];
foreach ($destinations as $d) {
    if (in_array((int) $d['id'], $favoriteDestIds, true)) {
        continue;
    }
    $onTour = in_array((int) $d['id'], $existingDestIds, true);
    $destPicker[] = [
        'key' => 'd:' . (int) $d['id'],
        'label' => (string) $d['name']
            . (!empty($d['location']) ? ' — ' . $d['location'] : '')
            . ($onTour ? ' (on this tour)' : ''),
        'on_tour' => $onTour,
    ];
}
$bizPicker = [];
foreach ($businesses as $b) {
    if (business_excluded_from_itinerary_stops($b['type_name'] ?? '')) {
        continue;
    }
    $onTour = in_array((int) $b['id'], $existingBizIds, true);
    $bizPicker[] = [
        'key' => 'b:' . (int) $b['id'],
        'label' => (string) $b['name']
            . (!empty($b['type_name']) ? ' · ' . $b['type_name'] : '')
            . (!empty($b['address']) ? ' — ' . $b['address'] : '')
            . ($onTour ? ' (on this tour)' : ''),
        'on_tour' => $onTour,
    ];
}
$placePicker = array_merge($destPicker, $bizPicker);
if ($placePicker) {
    $placePickerGroups[] = ['label' => 'Places', 'items' => $placePicker];
}

// Empty drafts with a past date → clear so the field stays blank.
// Do not assume 8:00 AM when meetup is unset (that wiped today's date after a refresh).
if ($canEdit && !$scheduleLocked && $view && !$stops && !empty($view['start_date'])) {
    $rawMeetupCheck = trim((string) ($view['meetup_time'] ?? ''));
    $meetupCheck = $rawMeetupCheck !== '' ? (normalize_time_input($rawMeetupCheck) ?? null) : null;
    $datePast = normalize_tour_date((string) $view['start_date']);
    $shouldClear = $datePast !== null && $datePast < date('Y-m-d');
    if (!$shouldClear && $meetupCheck !== null) {
        $shouldClear = tour_datetime_past_message((string) $view['start_date'], $meetupCheck, 'Meetup time') !== null;
    }
    if ($shouldClear) {
        db()->prepare('UPDATE itineraries SET start_date=NULL, end_date=NULL WHERE id=? AND user_id=?')
            ->execute([(int) $view['id'], (int) $user['id']]);
        $view['start_date'] = null;
        $view['end_date'] = null;
    }
}

$meetupMinsUi = null;
if ($view && isset($view['meetup_time']) && $view['meetup_time'] !== null && trim((string) $view['meetup_time']) !== '') {
    $meetupMinsUi = itin_time_to_minutes((string) $view['meetup_time']);
}
$meetupClock = '';
$meetupAmPm = 'AM';
if ($meetupMinsUi !== null) {
    $h24 = intdiv($meetupMinsUi, 60);
    $mm = $meetupMinsUi % 60;
    $meetupAmPm = $h24 >= 12 ? 'PM' : 'AM';
    $h12 = $h24 % 12;
    if ($h12 === 0) {
        $h12 = 12;
    }
    $meetupClock = sprintf('%02d:%02d', $h12, $mm);
}
$meetupDisplay = $meetupMinsUi !== null ? format_minutes_12h($meetupMinsUi) : '';
$meetupLabel = $meetupDisplay !== '' ? $meetupDisplay : 'Not set';

// Blank Unnamed drafts still on the old 8:00 AM default → clear so the field starts empty
if (
    $canEdit
    && empty($scheduleLocked)
    && $view
    && !$stops
    && empty($view['start_date'])
    && itinerary_is_placeholder_title($view['title'] ?? null)
    && $meetupMinsUi === (8 * 60)
) {
    db()->prepare('UPDATE itineraries SET meetup_time=NULL WHERE id=? AND user_id=?')
        ->execute([(int) $view['id'], (int) $user['id']]);
    $view['meetup_time'] = null;
    $meetupMinsUi = null;
    $meetupClock = '';
    $meetupAmPm = 'AM';
    $meetupDisplay = '';
    $meetupLabel = 'Not set';
}

$tourDateDisplay = $view && !empty($view['start_date'])
    ? (normalize_tour_date((string) $view['start_date']) ?? '')
    : '';
$tourEndDisplay = $view && !empty($view['end_date'])
    ? (normalize_tour_date((string) $view['end_date']) ?? '')
    : $tourDateDisplay;
$viewingSuggestedTemplate = $view && (int) ($view['is_suggested'] ?? 0) === 1 && $tourDateDisplay === '';
$viewingUndatedTour = $view && $tourDateDisplay === '' && itinerary_template_day_count($view) > 1;
$dayDatesMap = $view ? itinerary_day_dates_map((int) $view['id'], $view) : [];
if ($viewingSuggestedTemplate || $viewingUndatedTour) {
    $dayCountDisplay = itinerary_template_day_count($view);
    $isMultidayTour = $dayCountDisplay > 1;
    $templateDayBase = itinerary_template_day_base();
    $stopsByDay = itinerary_group_stops_by_day(
        $stops,
        $isMultidayTour ? $templateDayBase : null,
        $isMultidayTour ? itinerary_date_for_day($templateDayBase, $dayCountDisplay) : null,
        (int) $view['id'],
        $view
    );
} else {
    $dayCountDisplay = $view ? itinerary_logical_day_count($view) : 1;
    $isMultidayTour = $dayCountDisplay > 1;
    $stopsByDay = $view
        ? itinerary_group_stops_by_day($stops, $tourDateDisplay, $tourEndDisplay, (int) $view['id'], $view)
        : [];
}

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

$dayMeetupsByDay = [];
if ($view) {
    for ($d = 1; $d <= max(1, (int) $dayCountDisplay); $d++) {
        $dayMeetupsByDay[$d] = itinerary_day_meetup_place((int) $view['id'], $d, $view);
    }
}
$day1Meetup = $dayMeetupsByDay[1] ?? itinerary_day_meetup_place(0, 1);

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
        $dDate = $dayDatesMap[$d] ?? ($tourDateDisplay !== ''
            ? (itinerary_date_for_day($tourDateDisplay, $d) ?: $tourDateDisplay)
            : '');
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
$placeHoursRows = static function (?array $resolved): array {
    if (!$resolved) {
        return [];
    }
    $labels = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];
    $rows = [];
    foreach ($labels as $key => $label) {
        if (!array_key_exists($key, $resolved)) {
            continue;
        }
        $rows[] = [
            'label' => $label,
            'value' => format_hours_slot_12h((string) $resolved[$key]),
        ];
    }
    return $rows;
};
$servicesByPlace = ['d' => [], 'b' => []];
$packOfferedService = static function (array $svc): array {
    $price = $svc['price'] ?? null;
    $photo = trim((string) ($svc['image_path'] ?? ''));
    return [
        'name' => (string) ($svc['name'] ?? ''),
        'description' => trim((string) ($svc['description'] ?? '')),
        'price' => ($price !== null && $price !== '') ? format_money($price) : '',
        'minutes' => (int) ($svc['duration_minutes'] ?? 0),
        'days' => (int) ($svc['package_days'] ?? 0),
        'photo' => $photo !== '' ? (APP_URL . '/uploads/' . ltrim($photo, '/')) : '',
    ];
};
try {
    $destSvcRows = db()->query(
        'SELECT destination_id, name, description, price, duration_minutes
         FROM destination_services WHERE is_active=1 ORDER BY name'
    )->fetchAll() ?: [];
    foreach ($destSvcRows as $svc) {
        $servicesByPlace['d'][(int) $svc['destination_id']][] = $packOfferedService($svc);
    }
} catch (Throwable $e) {
    $servicesByPlace['d'] = [];
}
try {
    $bizSvcRows = db()->query(
        'SELECT business_id, name, description, price, duration_minutes, package_days, image_path
         FROM business_services WHERE is_active=1 ORDER BY name'
    )->fetchAll() ?: [];
    foreach ($bizSvcRows as $svc) {
        $servicesByPlace['b'][(int) $svc['business_id']][] = $packOfferedService($svc);
    }
} catch (Throwable $e) {
    $servicesByPlace['b'] = [];
}
foreach ($destinations as $d) {
    $resolved = resolve_place_opening_hours($d['opening_hours'] ?? null);
    $cover = !empty($d['cover_image']) ? (APP_URL . '/uploads/' . ltrim((string) $d['cover_image'], '/')) : null;
    $did = (int) $d['id'];
    $feeRaw = trim((string) ($d['entrance_fee'] ?? ''));
    $placesForJs['d:' . $did] = [
        'kind' => 'destination',
        'key' => 'd:' . $did,
        'lat' => $d['latitude'] !== null ? (float) $d['latitude'] : null,
        'lng' => $d['longitude'] !== null ? (float) $d['longitude'] : null,
        'name' => $d['name'],
        'subtitle' => (string) ($d['category_name'] ?? 'Destination'),
        'location' => (string) ($d['location'] ?? ''),
        'description' => $placeDetailBlurb($d['description'] ?? null),
        'detail' => $placeDetailBlurb($d['description'] ?? null, 600),
        'services' => $servicesByPlace['d'][$did] ?? [],
        'offerLabel' => 'Listing',
        'offerEmpty' => 'No listings yet.',
        'showOfferMeta' => false,
        'cover' => $cover,
        'fee' => $feeRaw,
        'feeLabel' => $feeRaw === '' ? '' : (is_numeric($feeRaw) ? format_money($feeRaw) : $feeRaw),
        'hoursRows' => $placeHoursRows($resolved),
        'rating' => $d['avg_rating'] !== null ? (float) $d['avg_rating'] : null,
        'defaultVisit' => (int) ($d['default_visit_minutes'] ?? 60),
        'visitLimit' => place_visit_limit($d['visit_duration_minutes'] ?? null),
        'hours' => $resolved,
        'hoursLabel' => $resolved ? format_hours_slot_12h($resolved[$itinDayKey] ?? 'Closed') : null,
        'url' => APP_URL . '/tourist/destination.php?id=' . $did,
        'favorite' => in_array($did, $favoriteDestIds, true),
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
    $offerCopy = business_catalog_copy($b['type_name'] ?? '');
    $offerServices = $servicesByPlace['b'][$bid] ?? [];
    $showOfferMeta = in_array($offerCopy['mode'], ['rooms', 'vehicles', 'packages'], true);
    if (!$showOfferMeta) {
        foreach ($offerServices as &$plainSvc) {
            $plainSvc['price'] = '';
            $plainSvc['minutes'] = 0;
            $plainSvc['days'] = 0;
            $plainSvc['description'] = '';
        }
        unset($plainSvc);
    }
    $placesForJs['b:' . $bid] = [
        'kind' => 'business',
        'key' => 'b:' . $bid,
        'stopExcluded' => business_excluded_from_itinerary_stops($b['type_name'] ?? ''),
        'lat' => $b['latitude'] !== null ? (float) $b['latitude'] : null,
        'lng' => $b['longitude'] !== null ? (float) $b['longitude'] : null,
        'name' => $b['name'],
        'subtitle' => (string) ($b['type_name'] ?? 'Business'),
        'location' => (string) ($b['address'] ?? ''),
        'description' => $placeDetailBlurb($b['description'] ?? null),
        'detail' => $placeDetailBlurb($b['description'] ?? null, 600),
        'services' => $offerServices,
        'offerLabel' => $offerCopy['label'],
        'offerEmpty' => 'No ' . $offerCopy['plural'] . ' yet.',
        'showOfferMeta' => $showOfferMeta,
        'cover' => $cover,
        'fee' => '',
        'feeLabel' => '',
        'hoursRows' => $placeHoursRows($resolved),
        'rating' => null,
        'defaultVisit' => (int) ($b['default_visit_minutes'] ?? 60),
        'visitLimit' => place_visit_limit($b['visit_duration_minutes'] ?? null),
        'minVisit' => place_min_visit($b['min_visit_minutes'] ?? null),
        'hours' => $resolved,
        'hoursLabel' => $resolved ? format_hours_slot_12h($resolved[$itinDayKey] ?? 'Closed') : null,
        'url' => APP_URL . '/tourist/business.php?id=' . $bid,
        'favorite' => in_array($bid, $favoriteBizIds, true),
        'onTour' => in_array($bid, $existingBizIds, true),
    ];
}
$formatNearbyKm = static function (float $km): string {
    if ($km < 0.1) {
        return '< 100 m';
    }
    if ($km < 1) {
        return (string) ((int) round($km * 1000)) . ' m';
    }
    return number_format($km, 1) . ' km';
};
foreach ($placesForJs as $selfKey => $self) {
    $nearby = [];
    $selfLat = $self['lat'] ?? null;
    $selfLng = $self['lng'] ?? null;
    if ($selfLat !== null && $selfLng !== null) {
        foreach ($placesForJs as $otherKey => $other) {
            if ($otherKey === $selfKey) {
                continue;
            }
            $otherLat = $other['lat'] ?? null;
            $otherLng = $other['lng'] ?? null;
            if ($otherLat === null || $otherLng === null) {
                continue;
            }
            $km = itin_haversine_km((float) $selfLat, (float) $selfLng, (float) $otherLat, (float) $otherLng);
            if ($km > 5) {
                continue;
            }
            $nearby[] = [
                'name' => (string) ($other['name'] ?? ''),
                'kind' => (string) ($other['subtitle'] ?? ''),
                'km' => $km,
                'distance' => $formatNearbyKm($km),
            ];
        }
        usort($nearby, static function (array $a, array $b): int {
            return $a['km'] <=> $b['km'];
        });
        $nearby = array_slice($nearby, 0, 6);
        foreach ($nearby as &$nearRow) {
            unset($nearRow['km']);
        }
        unset($nearRow);
    }
    $placesForJs[$selfKey]['nearby'] = $nearby;
}

$mapDayAnchor = $tourDateDisplay !== ''
    ? $tourDateDisplay
    : (!empty($isMultidayTour) ? itinerary_template_day_base() : null);
$dateToLogicalDay = [];
foreach ($dayDatesMap as $dn => $ymd) {
    if ($ymd !== '' && !isset($dateToLogicalDay[$ymd])) {
        $dateToLogicalDay[$ymd] = (int) $dn;
    }
}
$mapMeetups = [];
foreach ($dayMeetupsByDay as $meetDay => $meetPlace) {
    if (empty($meetPlace['has_place'])) {
        continue;
    }
    $meetLat = $meetPlace['lat'] ?? null;
    $meetLng = $meetPlace['lng'] ?? null;
    if ($meetLat === null || $meetLng === null || ((float) $meetLat == 0.0 && (float) $meetLng == 0.0)) {
        continue;
    }
    $mapMeetups[] = [
        'day' => (int) $meetDay,
        'title' => (string) ($meetPlace['label'] ?? 'Meetup'),
        'lat' => (float) $meetLat,
        'lng' => (float) $meetLng,
    ];
}
$mapStops = array_values(array_filter(
    array_map(static function ($it) use ($mapDayAnchor, $dateToLogicalDay) {
        if ($it['latitude'] === null || $it['longitude'] === null) {
            return null;
        }
        $vd = normalize_tour_date($it['visit_date'] ?? null);
        if ($vd !== null && isset($dateToLogicalDay[$vd])) {
            $day = $dateToLogicalDay[$vd];
        } else {
            $day = $mapDayAnchor
                ? itinerary_day_number($mapDayAnchor, $it['visit_date'] ?? $mapDayAnchor)
                : 1;
        }
        return [
            'title' => $it['stop_name'] ?? $it['destination_name'] ?? 'Stop',
            'lat' => (float) $it['latitude'],
            'lng' => (float) $it['longitude'],
            'day' => $day,
        ];
    }, $stops)
));

$bookGuides = [];
$bookGuidesTotal = 0;
$packageSystemGuides = [];
if ($packageMaker && $canEdit && $view) {
    require_once __DIR__ . '/../includes/inhouse_guides.php';
    $packageBizId = (int) ($GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] ?? 0);
    foreach (inhouse_guides_for_business($packageBizId, true) as $houseGuide) {
        if ((int) ($houseGuide['inhouse_active'] ?? 0) !== 1) {
            continue;
        }
        if ((string) ($houseGuide['status'] ?? '') !== 'active') {
            continue;
        }
        if ((string) ($houseGuide['approval_status'] ?? '') !== 'approved') {
            continue;
        }
        $bookGuides[] = [
            'id' => (int) $houseGuide['guide_id'],
            'user_id' => (int) ($houseGuide['user_id'] ?? 0),
            'name' => trim((string) ($houseGuide['first_name'] ?? '') . ' ' . (string) ($houseGuide['last_name'] ?? '')),
            'phone' => trim((string) ($houseGuide['phone'] ?? '')) ?: '—',
            'languages' => trim((string) ($houseGuide['languages'] ?? '')) ?: '—',
            'rate' => format_money($houseGuide['hourly_rate'] ?? 0),
            'rate_amount' => round((float) ($houseGuide['hourly_rate'] ?? 0), 2),
            'license' => '—',
            'rating' => 0,
            'reviews' => 0,
            'avatar' => !empty($houseGuide['avatar']) ? (APP_URL . '/uploads/' . $houseGuide['avatar']) : null,
            'hint' => null,
            'ok' => true,
            'hint_ok' => true,
            'full_day_ok' => true,
            'busy_by_day' => [],
            'name_only' => true,
        ];
    }
    $packageInclusiveMaker = (string) ($GLOBALS['CULTOUR_PACKAGE_DATE_MODE'] ?? 'fixed') !== 'tourist';
    if ($packageInclusiveMaker && $tourDateDisplay !== '') {
        $busyGuideIds = array_fill_keys(guides_busy_ids_on($tourDateDisplay), true);
        $bookGuides = array_values(array_filter($bookGuides, static function (array $guide) use ($busyGuideIds): bool {
            return !isset($busyGuideIds[(int) $guide['id']]);
        }));
    }
    $bookGuidesTotal = count($bookGuides);
    if (!$packageInclusiveMaker && !$bookGuides) {
        $meetupForAvail = normalize_time_input($meetupDisplay)
            ?? normalize_time_input((string) ($view['meetup_time'] ?? ''));
        $bookGuides = tourist_book_guides_list(
            $tourDateDisplay !== '' ? $tourDateDisplay : null,
            $meetupForAvail,
            $tourDateDisplay !== '' ? $tourDateDisplay : null,
            $viewId > 0 ? $viewId : null,
            null,
            (int) $user['id']
        );
        $bookGuidesTotal = count($bookGuides);
    }
    if ($packageInclusiveMaker && !$bookGuides) {
        $meetupForAvail = normalize_time_input($meetupDisplay)
            ?? normalize_time_input((string) ($view['meetup_time'] ?? ''));
        $packageSystemGuides = tourist_book_guides_list(
            $tourDateDisplay !== '' ? $tourDateDisplay : null,
            $meetupForAvail,
            $tourDateDisplay !== '' ? $tourDateDisplay : null,
            $viewId > 0 ? $viewId : null,
            null,
            (int) $user['id']
        );
        if ($tourDateDisplay !== '') {
            $busyGuideIds = array_fill_keys(guides_busy_ids_on($tourDateDisplay), true);
            $packageSystemGuides = array_values(array_filter($packageSystemGuides, static function (array $guide) use ($busyGuideIds): bool {
                return !isset($busyGuideIds[(int) ($guide['id'] ?? 0)]);
            }));
        }
    }
} elseif ($canEdit && $view) {
    $meetupForAvail = normalize_time_input($meetupDisplay)
        ?? normalize_time_input((string) ($view['meetup_time'] ?? ''));
    // Use full multi-day span so day 2+ hours/blocks are checked (not only the start date)
    $availEndDate = $tourDateDisplay;
    if ($tourDateDisplay !== '' && !empty($isMultidayTour) && (int) $dayCountDisplay > 1) {
        $availEndDate = $tourEndDisplay !== '' ? $tourEndDisplay : $tourDateDisplay;
        $visitDates = itinerary_visit_dates_list((int) $view['id'], $view);
        if ($visitDates) {
            $availEndDate = $visitDates[count($visitDates) - 1];
        }
    }
    $bookGuides = tourist_book_guides_list(
        $tourDateDisplay !== '' ? $tourDateDisplay : null,
        $meetupForAvail,
        $availEndDate !== '' ? $availEndDate : null,
        $viewId > 0 ? $viewId : null,
        null,
        (int) $user['id']
    );
    $bookGuidesTotal = count($bookGuides);
}
$draftGuideClient = null;
$ownGuideProfileId = 0;
$ownEmail = strtolower(trim((string) ($user['email'] ?? '')));
if ($ownEmail !== '') {
    $ownGuide = db()->prepare(
        'SELECT g.id FROM tour_guide_profiles g
         JOIN users u ON u.id = g.user_id
         WHERE LOWER(u.email)=? AND u.role_id=? LIMIT 1'
    );
    $ownGuide->execute([$ownEmail, ROLE_TOUR_GUIDE]);
    $ownGuideProfileId = (int) $ownGuide->fetchColumn();
}
$hasTourDate = $view && $tourDateDisplay !== '';
$guideModalDateLabel = 'No date set';
if ($tourDateDisplay !== '') {
    $guideModalDateLabel = format_date($tourDateDisplay);
    if (!empty($isMultidayTour) && (int) $dayCountDisplay > 1) {
        $endForModal = $tourEndDisplay !== '' ? $tourEndDisplay : $tourDateDisplay;
        $visitDates = itinerary_visit_dates_list((int) $view['id'], $view);
        if ($visitDates) {
            $endForModal = $visitDates[count($visitDates) - 1];
        }
        if ($endForModal && $endForModal !== $tourDateDisplay) {
            $guideModalDateLabel = format_date($tourDateDisplay) . '–' . format_date($endForModal);
        }
    }
}
$preferGuideUser = (int) ($_GET['guide_user'] ?? 0);
$openBookModal = $canEdit && $view && !$scheduleLocked && isset($_GET['book']) && $hasTourDate;
$openBookReview = $canEdit && $view && !$scheduleLocked && isset($_GET['review']);
$promptBookDate = $canEdit && $view && (isset($_GET['book']) || isset($_GET['review'])) && !$hasTourDate;
$hasItinContent = $canEdit && $view && (
    count($stops) > 0
    || !empty($view['start_date'])
    || (int) ($view['party_size'] ?? 0) >= 1
    || !itinerary_is_placeholder_title($view['title'] ?? null)
);
$keptAsDraft = $canEdit && $view && !empty($view['kept_as_draft']);

$bodyClass = trim(($bodyClass ?? '') . ($packageMaker ? ' business-app package-maker itin-page' : ' tourist-app'));
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php
if ($packageMaker) {
    $businessHidePagehead = true;
    require __DIR__ . '/../includes/business_shell_start.php';
} else {
    require __DIR__ . '/../includes/tourist_shell_start.php';
}
?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('warning')): ?><div class="alert alert-warning"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?= booking_refund_receipt_modal() ?>

      <div class="itin-pagehead" data-itin-tutorial-user="<?= (int) ($user['id'] ?? 0) ?>">
        <div class="itin-pagehead__titleblock">
          <div class="itin-pagehead__title-row">
            <h1 class="itin-pagehead__title"><?= $packageMaker ? 'Package maker' : 'My Itineraries' ?></h1>
            <button type="button" class="itin-tutorial-replay" data-itin-tutorial-replay aria-label="Replay itinerary tutorial">How to use</button>
              </div>
          <p class="itin-pagehead__sub"><?= $packageMaker ? e((string) ($GLOBALS['CULTOUR_PACKAGE_NAME'] ?? 'Tour and travel')) : 'CulTour · Ormoc City' ?></p>
              </div>
        <div class="itin-suggest-tabs" data-itin-chips>
          <form method="post" class="d-inline" data-itin-new-tour><?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="title" value="Unnamed">
            <input type="hidden" name="meetup_time" value="">
            <button type="submit" class="quick-chip">+ New tour</button>
            </form>
          <form method="post" class="d-inline" id="itinSaveDraftForm"><?= csrf_field() ?>
            <input type="hidden" name="action" value="save_draft">
            <input type="hidden" name="itinerary_id" value="<?= (int) ($view['id'] ?? 0) ?>">
            <input type="hidden" name="title" value="">
            <input type="hidden" name="start_date" value="">
            <input type="hidden" name="party_size" value="">
            <button type="submit" class="quick-chip quick-chip--hollow<?= !empty($hasItinContent) && empty($scheduleLocked) ? ' is-ready' : '' ?>" id="itinSaveDraftBtn" data-locked="<?= !empty($scheduleLocked) ? '1' : '0' ?>"<?= !empty($hasItinContent) && empty($scheduleLocked) ? '' : ' disabled' ?>>Save draft</button>
          </form>
          <script>
          (function () {
            var form = document.getElementById('itinSaveDraftForm');
            var btn = document.getElementById('itinSaveDraftBtn');
            if (!form || !btn) return;
            function textOf(id) {
              var el = document.getElementById(id);
              return el ? String(el.value || '').trim() : '';
            }
            function hasInput() {
              if (btn.getAttribute('data-locked') === '1') return false;
              if (!form.querySelector('[name="itinerary_id"]').value || form.querySelector('[name="itinerary_id"]').value === '0') return false;
              var title = textOf('itinTourTitle').toLowerCase();
              var named = title !== '' && title !== 'unnamed' && title !== 'untitled package';
              var guests = parseInt(textOf('itinPartySize'), 10);
              return named || textOf('itinStartDate') !== '' || (guests >= 1) || document.querySelector('[data-itin-stop]');
            }
            function sync() {
              var on = hasInput();
              btn.disabled = !on;
              btn.classList.toggle('is-ready', on);
            }
            document.addEventListener('input', sync);
            document.addEventListener('change', sync);
            form.addEventListener('submit', function (e) {
              if (btn.disabled) {
                e.preventDefault();
                return;
              }
              form.querySelector('[name="title"]').value = textOf('itinTourTitle');
              form.querySelector('[name="start_date"]').value = textOf('itinStartDate');
              form.querySelector('[name="party_size"]').value = textOf('itinPartySize');
            });
            sync();
          })();
          </script>
          </div>
              </div>

      <div class="row itin-builder">
        <div class="col-lg-4 itin-builder__col itin-builder__col--controls">
          <div class="glass p-3 p-md-4 h-100 itin-builder__card">
            <?php if (!$view): ?>
              <h3 class="panel-title mb-2">Your tours</h3>
              <p class="text-muted mb-2">Click <strong>+ New tour</strong> to start planning.</p>
              <?php if (!empty($draftsAtLimit)): ?>
                <div class="alert alert-warning py-2 small"><?= e(itinerary_draft_limit_message()) ?></div>
              <?php endif; ?>
              <div class="dropdown itin-draft-select">
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
              <?php if ($canEdit): ?><div class="itin-place-overlay-host" data-itin-rich-picker><?php endif; ?>
              <div class="itin-tour-head mb-2">
                <div class="itin-tour-head__row">
                  <?php if ($canEdit): ?>
                    <form method="post" class="itin-rename" id="itinRenameForm" data-itin-rename data-itin-tour="title"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_title">
                      <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                      <label class="itin-field-label itin-field-label--title" for="itinTourTitle"><?= $packageMaker ? 'Package name' : 'Tour name' ?></label>
                      <?php
                        $titleShown = (string) ($view['title'] ?? '');
                        $titleBlank = itinerary_is_placeholder_title($titleShown)
                            || ($packageMaker && strcasecmp(trim($titleShown), 'Untitled package') === 0);
                      ?>
                      <input type="text" class="form-control form-control-sm itin-rename__input" id="itinTourTitle" name="title"
                        value="<?= $titleBlank ? '' : e($titleShown) ?>"
                        maxlength="120" aria-label="<?= $packageMaker ? 'Package name' : 'Tour name' ?>" placeholder="<?= $packageMaker ? '' : 'Unnamed' ?>">
                      <div class="itin-date-feedback" data-itin-title-feedback hidden><?= $packageMaker ? 'Name this package before saving.' : 'Name this itinerary before booking.' ?></div>
                </form>
                  <?php else: ?>
                    <h3 class="panel-title mb-0 itin-tour-head__name"><?= e($view['title']) ?></h3>
                    <?php if ($scheduleLocked): ?>
                      <a class="btn btn-ct itin-tour-head__book" href="<?= APP_URL ?>/tourist/bookings.php">View booking</a>
                    <?php endif; ?>
                  <?php endif; ?>
              </div>
                <?php if (!empty($draftsAtLimit) && empty($viewIsListedDraft)): ?>
                  <div class="alert alert-warning py-2 small mb-2"><?= e(itinerary_draft_limit_message()) ?></div>
                <?php endif; ?>
                <div class="itin-tour-meta">
                  <div class="itin-tour-meta__left">
                    <div class="itin-tour-actions">
                      <?php if ((int) ($view['is_suggested'] ?? 0)): ?>
                        <form method="post" class="itin-tour-actions__item" data-itin-tour="use-suggested"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="clone">
                          <input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
                          <button class="btn btn-sm btn-ct"<?= !empty($draftsAtLimit) ? ' disabled title="' . e(itinerary_draft_limit_message()) . '"' : '' ?>>Use this tour</button>
                        </form>
                      <?php else: ?>
                      <div class="dropdown itin-tour-actions__item itin-draft-select">
                        <span class="itin-field-label">Drafts</span>
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
                      <?php endif; ?>
        </div>
                    <?php if ($canEdit || $ownsView): ?>
                      <div class="itin-guest-field" data-itin-tour="guests">
                        <label class="itin-field-label" for="itinPartySize"><?= $packageMaker ? 'Participants' : 'Number of guests' ?></label>
                        <?php if ($packageMaker): ?>
                        <div class="itin-guest-field__pair">
                          <input type="number" class="form-control form-control-sm itin-guest-field__input"
                                 id="packageMinPax" name="package_min_pax" form="guideBookForm" min="1" max="50"
                                 inputmode="numeric" placeholder="min" aria-label="Minimum participants"
                                 value="<?= (int) ($GLOBALS['CULTOUR_PACKAGE_MIN_PAX'] ?? 0) >= 1 ? (int) $GLOBALS['CULTOUR_PACKAGE_MIN_PAX'] : '' ?>"
                                 <?= $canEdit ? '' : 'readonly' ?>>
                          <input type="number" class="form-control form-control-sm itin-guest-field__input"
                                 id="itinPartySize" name="package_max_pax" form="guideBookForm" min="1" max="50"
                                 inputmode="numeric" placeholder="max" aria-label="Maximum participants"
                                 value="<?php
                                   $guestSaved = $view ? (int) ($view['party_size'] ?? 0) : 0;
                                   echo $guestSaved >= 1 ? (int) $guestSaved : '';
                                 ?>"
                                 <?= $canEdit ? '' : 'readonly' ?>>
                        </div>
                        <?php else: ?>
                        <input type="number" class="form-control form-control-sm itin-guest-field__input"
                               id="itinPartySize" name="itin_party_size" min="1" max="20"
                               inputmode="numeric"
                               aria-label="Number of guests"
                               value="<?php
                                 $guestSaved = 0;
                                 if ($scheduleLocked && $activeBooking) {
                                     $guestSaved = (int) ($activeBooking['party_size'] ?? 0);
                                 } elseif ($view) {
                                     $guestSaved = (int) ($view['party_size'] ?? 0);
                                 }
                                 echo $guestSaved >= 1 ? (int) $guestSaved : '';
                               ?>"
                               <?= $canEdit ? '' : 'readonly' ?>>
                        <?php endif; ?>
                        <div class="itin-date-feedback<?= $packageMaker ? ' itin-field-note' : '' ?>" data-itin-guest-feedback hidden><?= $packageMaker ? 'Enter the participants.' : 'Enter the number of guests.' ?></div>
                </div>
                    <?php endif; ?>
              </div>
                  <?php
                    $previewGuideName = '';
                    $previewGuideAvatar = null;
                    $previewGuideInitials = '?';
                    $previewGuidePhone = '';
                    $previewGuideRate = '';
                    $previewGuideLang = '';
                    $draftGuideClient = null;
                    if ($scheduleLocked && $activeBooking) {
                        $previewGuideName = trim((string) ($activeBooking['guide_name'] ?? ''));
                        if (!empty($activeBooking['guide_avatar'])) {
                            $previewGuideAvatar = APP_URL . '/uploads/' . ltrim((string) $activeBooking['guide_avatar'], '/');
                        }
                        $previewGuidePhone = trim((string) ($activeBooking['guide_phone'] ?? ''));
                        $previewGuideLang = trim((string) ($activeBooking['guide_languages'] ?? ''));
                        if (isset($activeBooking['guide_hourly_rate']) && $activeBooking['guide_hourly_rate'] !== null && $activeBooking['guide_hourly_rate'] !== '') {
                            $previewGuideRate = format_money($activeBooking['guide_hourly_rate']);
                        }
                        $parts = preg_split('/\s+/', $previewGuideName) ?: [];
                        $previewGuideInitials = strtoupper(
                            substr((string) ($parts[0] ?? '?'), 0, 1)
                            . substr((string) ($parts[1] ?? ''), 0, 1)
                        ) ?: '?';
                    } elseif ($canEdit && $view && (int) ($view['draft_guide_id'] ?? 0) > 0
                        && !booking_is_own_guide((int) $user['id'], (int) $view['draft_guide_id'])) {
                        $dg = db()->prepare(
                            'SELECT g.id, g.languages, g.hourly_rate, u.first_name, u.last_name, u.avatar, u.phone
                             FROM tour_guide_profiles g
                             JOIN users u ON u.id = g.user_id
                             WHERE g.id=? LIMIT 1'
                        );
                        $dg->execute([(int) $view['draft_guide_id']]);
                        $dgRow = $dg->fetch();
                        if ($dgRow) {
                            $previewGuideName = trim((string) $dgRow['first_name'] . ' ' . (string) $dgRow['last_name']);
                            if (!empty($dgRow['avatar'])) {
                                $previewGuideAvatar = APP_URL . '/uploads/' . ltrim((string) $dgRow['avatar'], '/');
                            }
                            $previewGuidePhone = trim((string) ($dgRow['phone'] ?? ''));
                            $previewGuideLang = trim((string) ($dgRow['languages'] ?? ''));
                            if ($dgRow['hourly_rate'] !== null && $dgRow['hourly_rate'] !== '') {
                                $previewGuideRate = format_money($dgRow['hourly_rate']);
                            }
                            $parts = preg_split('/\s+/', $previewGuideName) ?: [];
                            $previewGuideInitials = strtoupper(
                                substr((string) ($parts[0] ?? '?'), 0, 1)
                                . substr((string) ($parts[1] ?? ''), 0, 1)
                            ) ?: '?';
                            $draftGuideClient = [
                                'id' => (int) $dgRow['id'],
                                'name' => $previewGuideName,
                                'phone' => $previewGuidePhone !== '' ? $previewGuidePhone : '—',
                                'languages' => $previewGuideLang !== '' ? $previewGuideLang : '—',
                                'rate' => $previewGuideRate,
                                'avatar' => $previewGuideAvatar,
                            ];
                        }
                    }
                  ?>
                  <div class="itin-guide-slot<?= ($canEdit && !$scheduleLocked) ? ' d-none' : '' ?>">
                  <div class="itin-guide-preview<?= $previewGuideName === '' ? ' is-empty' : '' ?>" id="itinGuidePreview" data-itin-tour="guide"
                       <?= $canEdit ? 'role="button" tabindex="0" title="Pick a tour guide"' : '' ?>>
                    <span class="itin-guide-preview__avatar" data-itin-guide-avatar>
                      <?php if ($previewGuideAvatar): ?>
                        <img src="<?= e($previewGuideAvatar) ?>" alt="">
                      <?php else: ?>
                        <span data-itin-guide-initials><?= e($previewGuideInitials) ?></span>
                      <?php endif; ?>
                    </span>
                    <span class="itin-guide-preview__text">
                      <span class="itin-guide-preview__label">Tour guide</span>
                      <span class="itin-guide-preview__name" data-itin-guide-name>
                        <?= $previewGuideName !== '' ? e($previewGuideName) : 'No guide yet' ?>
                      </span>
                      <span class="itin-guide-preview__details<?= $previewGuideName === '' ? ' d-none' : '' ?>" data-itin-guide-details>
                        <span data-itin-guide-phone><?= $previewGuidePhone !== '' ? e($previewGuidePhone) : '—' ?></span>
                        <span aria-hidden="true">·</span>
                        <span data-itin-guide-rate><?= $previewGuideRate !== '' ? e($previewGuideRate) . '/day' : '—' ?></span>
                        <span aria-hidden="true">·</span>
                        <span data-itin-guide-lang><?= $previewGuideLang !== '' ? e($previewGuideLang) : '—' ?></span>
                      </span>
                    </span>
                    <?php if ($canEdit): ?>
                      <button type="button" class="itin-guide-preview__clear<?= $previewGuideName === '' ? ' d-none' : '' ?>" data-itin-guide-clear aria-label="Remove tour guide" title="Remove tour guide">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                      </button>
                    <?php endif; ?>
              </div>
                  <div class="itin-date-feedback" data-itin-guide-feedback hidden>Pick a tour guide.</div>
                  </div>
                </div>
              </div>
              <?php if (!$packageMaker && ($itinDesc = itinerary_display_description($view['description'] ?? null)) !== ''): ?>
                <p class="small text-muted mb-2"><?= e($itinDesc) ?></p>
              <?php endif; ?>
              <?php if ((int) ($view['is_suggested'] ?? 0)): ?>
                <div class="small text-muted mb-2">Suggested tour — copy it to edit and book a guide</div>
              <?php endif; ?>

              <?php
                $bookingDateConflict = '';
                if ($canEdit && empty($scheduleLocked) && !empty($view['id']) && !empty($view['start_date'])) {
                    $conflictDates = itinerary_visit_dates_list((int) $view['id'], $view);
                    if (!$conflictDates) {
                        $one = normalize_tour_date((string) $view['start_date']);
                        $conflictDates = $one !== null ? [$one] : [];
                    }
                    if ($conflictDates) {
                        $bookingDateConflict = (string) (tourist_date_range_booking_conflict_message(
                            (int) $user['id'],
                            (string) $conflictDates[0],
                            (string) $conflictDates[count($conflictDates) - 1],
                            (int) $view['id'],
                            null,
                            $conflictDates
                        ) ?? '');
                    }
                }
              ?>
              <div class="itin-schedule-card mb-2">
                <div class="fw-semibold mb-1">Tour date & meetup</div>
                <div class="small text-muted mb-1"><i class="bi bi-geo-alt-fill text-danger"></i> Day 1 meetup: <span data-itin-sidebar-meetup-place><?= e($day1Meetup['label']) ?></span></div>
                <?php if ($canEdit && !$scheduleLocked): ?>
                  <form method="post" data-itin-date-fields id="itinEditScheduleForm"
                        data-itin-avail-date="<?= e($tourDateDisplay) ?>"
                        data-itin-avail-meetup="<?= e($meetupDisplay) ?>"
                        data-itin-today="<?= e(booking_earliest_date()) ?>"
                        data-itin-now-mins="<?= (int) app_now_mins() ?>"
                        novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_meetup" id="itinEditAction">
                    <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                    <div class="row g-2">
                      <?php if ($packageMaker): ?>
                      <?php $packageTouristPicks = (string) ($GLOBALS['CULTOUR_PACKAGE_DATE_MODE'] ?? 'fixed') === 'tourist'; ?>
                      <?php
                        $packageCloseShown = trim((string) ($GLOBALS['CULTOUR_PACKAGE_CLOSE_DATE'] ?? ''));
                        $packageCloseMax = $packageTouristPicks ? '' : (string) (package_cutoff_date($tourDateDisplay ?? null) ?? '');
                      ?>
                      <div class="col-12">
                        <div class="form-check mb-0">
                          <input class="form-check-input" type="checkbox" name="package_date_tourist" value="1" id="packageDateTourist" <?= $packageTouristPicks ? 'checked' : '' ?>>
                          <label class="form-check-label small" for="packageDateTourist">Tourist chooses the date when booking</label>
                        </div>
                      </div>
                      <?php endif; ?>
                      <div class="col-12">
                        <label class="form-label small mb-0"><?= $packageMaker ? 'Package tour date' : 'Tour start date' ?></label>
                        <div class="itin-schedule-start-row">
                          <div class="itin-schedule-start-row__date" data-itin-tour="start-date">
                            <input type="date" class="form-control form-control-sm<?= $bookingDateConflict !== '' ? ' is-invalid' : '' ?>" name="start_date" id="itinStartDate" min="<?= e($packageMaker ? package_tour_earliest_date() : booking_earliest_date()) ?>" value="<?= e($tourDateDisplay) ?>" title="Optional for drafts & auto-generate — required to book">
                          </div>
                          <div class="itin-multiday-row" data-itin-tour="multiday">
                            <div class="form-check mb-0">
                              <input class="form-check-input" type="checkbox" name="is_multiday" value="1" id="itinEditMultiday" data-itin-multiday<?= $isMultidayTour ? ' checked' : '' ?>>
                              <label class="form-check-label small" for="itinEditMultiday">Multi-day</label>
                            </div>
                            <div class="itin-multiday-row__days<?= $isMultidayTour ? '' : ' d-none' ?>" data-itin-end-wrap>
                              <select class="form-select form-select-sm" name="day_count" data-itin-day-count data-max-days="<?= (int) itinerary_max_days() ?>" aria-label="Number of days">
                                <?php
                                  $dayOptions = range(2, 7);
                                  if ($dayCountDisplay > 7 && $dayCountDisplay <= itinerary_max_days()) {
                                      $dayOptions[] = (int) $dayCountDisplay;
                                  }
                                ?>
                                <?php foreach ($dayOptions as $d): ?>
                                  <option value="<?= $d ?>"<?= $dayCountDisplay === $d ? ' selected' : '' ?>><?= $d ?> days</option>
                                <?php endforeach; ?>
                                <option value="more">More days…</option>
                              </select>
                            </div>
                          </div>
                        </div>
                      </div>
                      <?php if ($packageMaker): ?>
                      <div class="col-6">
                        <label class="form-label small mb-0" for="packageCloseDate">Package cut off</label>
                        <input type="date" class="form-control form-control-sm" id="packageCloseDate" name="package_close_date" form="guideBookForm" min="<?= e(app_today()) ?>"<?= $packageCloseMax !== '' ? ' max="' . e($packageCloseMax) . '"' : '' ?> value="<?= e($packageCloseShown) ?>"<?= $packageTouristPicks ? ' disabled' : '' ?>>
                        <div class="itin-date-feedback itin-field-note" data-package-close-feedback hidden>Set the package cut off.</div>
                      </div>
                      <div class="col-6">
                        <label class="form-label small mb-0" for="packagePriceField">Price per person</label>
                        <?php $packagePriceShown = (float) ($GLOBALS['CULTOUR_PACKAGE_PRICE'] ?? 0); ?>
                        <div class="input-group input-group-sm">
                          <span class="input-group-text peso-mark">₱</span>
                          <input class="form-control" id="packagePriceField" name="package_price" form="guideBookForm" type="number" min="0" step="0.01" required value="<?= $packagePriceShown > 0 ? e(number_format($packagePriceShown, 2, '.', '')) : '' ?>">
                        </div>
                        <div class="itin-date-feedback itin-field-note" data-package-price-feedback hidden>Enter the price per person.</div>
                      </div>
                      <?php endif; ?>
                      <div class="col-12">
                        <div class="itin-date-feedback" data-itin-date-feedback<?= $bookingDateConflict === '' ? ' hidden' : '' ?>><?= e($bookingDateConflict !== '' ? $bookingDateConflict : 'Set a tour date before booking a guide.') ?></div>
                      </div>
                    </div>
                    <input type="hidden" name="meetup_time" value="<?= e($meetupDisplay) ?>">
                  </form>
                <?php else: ?>
                  <div class="small mt-1 mb-0">
                    <?php if (!empty($viewingSuggestedTemplate) || $tourDateDisplay === ''): ?>
                      <?= $isMultidayTour ? ((int) $dayCountDisplay . ' days') : '1 day' ?>
                      · Meetup <?= e($meetupLabel) ?>
                    <?php elseif ($isMultidayTour): ?>
                      <?= $dayCountDisplay ?> days · starts <?= e(format_date($tourDateDisplay)) ?>
                      · Meetup <?= e($meetupLabel) ?>
                    <?php else: ?>
                      <?= e(format_date($tourDateDisplay)) ?>
                      · Meetup <?= e($meetupLabel) ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($scheduleLocked && $activeBooking): ?>
                    <div class="small text-warning mt-2 mb-0">
                      <?php if (($activeBooking['status'] ?? '') === 'pending'): ?>
                        Waiting for the tour guide to accept (<code><?= e((string) $activeBooking['booking_code']) ?></code>).
                        This tour cannot be edited until that booking is cancelled.
                      <?php else: ?>
                        This tour is booked (<code><?= e((string) $activeBooking['booking_code']) ?></code>)
                        and cannot be edited. Cancel the booking to change it again.
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <?php if ($scheduleLocked && $activeBooking && booking_tourist_can_cancel($activeBooking)): ?>
                <?php $refundCancel = in_array((string) ($activeBooking['status'] ?? ''), ['pending', 'approved'], true) && ($activeBooking['payment_status'] ?? '') === 'paid' && !booking_needs_replacement($activeBooking); ?>
                <div class="itin-cancel-request mb-2">
                  <?php if ($refundCancel): ?>
                    <button type="button" class="btn btn-outline-danger itin-tour-head__book" data-bs-toggle="modal" data-bs-target="#itinRefundModal">Cancel and refund</button>
                  <?php else: ?>
                    <form method="post" class="itin-schedule-book__cancel" onsubmit="return confirm('Cancel this booking request?');"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="cancel_booking">
                      <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                      <input type="hidden" name="booking_id" value="<?= (int) $activeBooking['id'] ?>">
                      <button type="submit" class="btn btn-outline-danger itin-tour-head__book">Cancel request</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if ($refundCancel): ?>
                <div class="modal fade" id="itinRefundModal" tabindex="-1" aria-labelledby="itinRefundLabel" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_booking">
                        <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                        <input type="hidden" name="booking_id" value="<?= (int) $activeBooking['id'] ?>">
                        <div class="modal-header border-0">
                          <h2 class="modal-title h5" id="itinRefundLabel">Cancel and refund</h2>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body pt-0">
                          <p class="small text-muted">Where should we send the money?</p>
                          <?= booking_refund_destination_fields('itinRefundNumber') ?>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
                          <button type="submit" class="btn btn-outline-danger">Cancel and refund</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?= booking_refund_destination_script() ?>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($canEdit): ?>
                <div class="itin-addstop-card mb-0">
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
                            <span class="itin-rich-picker__trigger-sub" data-itin-rich-trigger-sub>Places</span>
                          </span>
                        </button>
                      </div>
                      <div class="form-text" data-itin-travel-hint>Pick a place with a photo and details.</div>
                      <div class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none" data-itin-travel-warn role="alert"></div>
                    </div>
                    <div class="col-12<?= $isMultidayTour ? '' : ' d-none' ?>" data-itin-add-day-wrap>
                      <label class="form-label small" for="itinAddDayNumber">Add to</label>
                      <select class="form-select" name="day_number" id="itinAddDayNumber" data-itin-add-day>
                        <?php
                          $addDayMax = $isMultidayTour ? max(2, (int) $dayCountDisplay) : 1;
                          for ($d = 1; $d <= $addDayMax; $d++):
                            $dDate = $dayDatesMap[$d] ?? ($tourDateDisplay !== ''
                              ? (itinerary_date_for_day($tourDateDisplay, $d) ?: $tourDateDisplay)
                              : '');
                        ?>
                          <option value="<?= $d ?>">Day <?= $d ?><?= $dDate !== '' ? ' · ' . e(format_date($dDate)) : '' ?></option>
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
                  <?php if (!$scheduleLocked): ?>
                    <div class="itin-addstop-schedule">
                      <div class="itin-edit-actions">
                        <button type="submit" form="itinEditScheduleForm" class="itin-edit-actions__btn btn btn-ct" data-itin-auto-generate data-itin-tour="auto-generate">
                          Auto-generate
                        </button>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
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
                      <form method="post" class="d-inline" data-itin-confirm="clear"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_stops">
                        <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Clear</button>
                      </form>
                <?php endif; ?>
                      </div>
                </div>
                <?php if ($canEdit): ?>
                  <div class="itin-plan-head__hint">Drag a place here from Choose a place<?= count($stops) > 1 ? ', or reorder stops' : '' ?></div>
                  <div class="itin-date-feedback" data-itin-stops-feedback hidden>Add at least one stop before booking a guide.</div>
                <?php endif; ?>
              </div>
              <div class="itin-stop-list itin-stop-list--panel"<?= $isMultidayTour ? ' data-itin-day-scroll' : '' ?>>
                <?php foreach ($stopsByDay as $g): ?>
                  <div class="itin-day<?= $isMultidayTour ? '' : ' itin-day--single' ?>" data-itin-day-section="<?= (int) $g['day'] ?>">
                    <?php
                      $dayMeetup = $dayMeetupsByDay[(int) $g['day']] ?? itinerary_day_meetup_place((int) $view['id'], (int) $g['day'], $view);
                      $dayYmd = normalize_tour_date((string) ($g['date'] ?? ''))
                          ?: (string) ($dayDatesMap[(int) $g['day']] ?? '');
                      if ($dayYmd === '' && $tourDateDisplay !== '') {
                          $dayYmd = itinerary_date_for_day($tourDateDisplay, (int) $g['day']) ?: '';
                      }
                      $dayDateLabel = $dayYmd !== '' ? format_date($dayYmd) : 'Date not set';
                      $dayTourDone = $scheduleLocked
                          && $activeBooking
                          && in_array((string) ($activeBooking['status'] ?? ''), ['approved', 'completed'], true)
                          && $dayYmd !== ''
                          && $dayYmd < app_today();
                      $hideTemplateDate = !empty($viewingSuggestedTemplate)
                          || ($tourDateDisplay === '' && $dayYmd !== '' && strncmp($dayYmd, '2000-', 5) === 0);
                    ?>
                      <div class="itin-day__head">
                        <span class="itin-day__head-main" data-itin-day-toggle role="button" tabindex="0" aria-expanded="true" aria-label="Toggle Day <?= (int) $g['day'] ?>">
                          <i class="bi bi-chevron-down itin-day__chevron" aria-hidden="true"></i>
                          <strong><?= e($g['label']) ?></strong>
                          <?php if ($dayTourDone): ?>
                            <span class="itin-day-done"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Completed</span>
                          <?php endif; ?>
                        </span>
                        <?php if (!$hideTemplateDate): ?>
                        <span class="itin-day__date" data-itin-day-date>
                          <?php if ((int) $g['day'] === 1): ?>
                            <span class="itin-day__date-kind" data-itin-day-start-label>Start</span>
                          <?php endif; ?>
                          <span data-itin-day-date-label><?= e($dayDateLabel) ?></span>
                          <?php if ($canEdit): ?>
                            <label class="itin-day__date-pick" title="Change date">
                              <i class="bi bi-calendar3" aria-hidden="true"></i>
                              <input type="date" data-itin-day-date-input data-day="<?= (int) $g['day'] ?>"
                                     min="<?= e(booking_earliest_date()) ?>" value="<?= e($dayYmd) ?>" aria-label="Date for Day <?= (int) $g['day'] ?>">
                            </label>
                          <?php endif; ?>
                        </span>
                        <?php endif; ?>
                      </div>
                      <div class="itin-day__guide-busy d-none" data-itin-day-guide-busy hidden></div>
                      <div class="itin-date-feedback" data-itin-day-date-feedback hidden></div>
                    <div class="itin-item itin-item--meetup" data-itin-day-meetup="<?= (int) $g['day'] ?>">
                      <div class="itin-meetup-card">
                        <?php
                          $meetupKind = (string) ($dayMeetup['kind'] ?? '');
                          $canRemoveMeetup = $canEdit && !empty($dayMeetup['has_place']);
                        ?>
                        <div class="itin-meetup-card__head">
                          <div class="itin-meetup-card__copy">
                            <div class="itin-meetup-place-line">
                              <span class="itin-meetup-place-line__label">Meetup place ·</span>
                              <div data-itin-meetup-place-name><?= e($dayMeetup['label']) ?></div>
                              <?php if ($canEdit): ?>
                                <button type="button" class="itin-meetup-place-edit" data-itin-edit-meetup-place data-day="<?= (int) $g['day'] ?>">Edit place</button>
                                <?php if ($meetupKind !== 'port'): ?>
                                  <button type="button" class="itin-meetup-place-edit" data-itin-use-port data-day="<?= (int) $g['day'] ?>">Use port</button>
                                <?php endif; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                          <?php if ($canRemoveMeetup): ?>
                            <div class="itin-meetup-menu">
                              <button type="button" class="itin-meetup-menu__btn" data-itin-meetup-menu-btn aria-expanded="false" aria-haspopup="true" aria-label="Meetup place options">
                                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                      </button>
                              <div class="itin-meetup-menu__panel itin-meetup-place-actions" hidden>
                                <button type="button" class="itin-meetup-menu__opt" data-itin-clear-meetup-place data-day="<?= (int) $g['day'] ?>">Remove</button>
                    </div>
                    </div>
                          <?php endif; ?>
                  </div>
                        <div class="itin-date-feedback" data-itin-day-meetup-place-feedback hidden>Meetup place not set</div>
                        <?php
                          $dayMeetupMins = $dayMeetup['meetup_mins'] ?? null;
                          $dayTimeValue = '';
                          if ($dayMeetupMins !== null) {
                              $dayTimeValue = sprintf('%02d:%02d', intdiv((int) $dayMeetupMins, 60), ((int) $dayMeetupMins) % 60);
                          }
                        ?>
                        <?php if ($canEdit && !$scheduleLocked): ?>
                          <div class="itin-meetup-time-row">
                            <span class="itin-meetup-time-row__label">Meetup time ·</span>
                            <div class="itin-meetup-time" data-itin-day-meetup-time="<?= (int) $g['day'] ?>">
                            <input type="time" class="form-control form-control-sm itin-meetup-time__clock"
                                   data-itin-day-meetup-clock data-day="<?= (int) $g['day'] ?>"
                                   step="900"
                                   aria-label="Day <?= (int) $g['day'] ?> meetup time"
                                   value="<?= e($dayTimeValue) ?>">
                            </div>
                          </div>
                          <div class="itin-date-feedback" data-itin-day-meetup-feedback hidden>Meetup time not set</div>
                        <?php else: ?>
                          <div class="small text-muted" data-itin-meetup-card-label><?= trim((string) ($dayMeetup['meetup_display'] ?? '')) !== '' ? 'Meetup time · ' . e($dayMeetup['meetup_display']) : 'Meetup time not set' ?></div>
                        <?php endif; ?>
                        <?php if (!$isMultidayTour): ?>
                          <div class="itin-day__guide-busy d-none" data-itin-day-guide-busy hidden></div>
                        <?php endif; ?>
                      </div>
                      <?php if ($canEdit): ?>
                        <div class="itin-meetup-drop-hint">Drop to set meetup place</div>
                      <?php endif; ?>
                    </div>
                    <?php if (!$g['stops'] && !$canEdit): ?>
                      <p class="small text-muted mb-0 mt-1"><?= $isMultidayTour ? 'No stops on this day yet.' : 'No stops yet.' ?></p>
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
                          $stopPlaceKey = !empty($it['business_id'])
                              ? ('b:' . (int) $it['business_id'])
                              : ('d:' . (int) ($it['destination_id'] ?? 0));
                          $stopCover = trim((string) ($it['cover_url'] ?? ''));
                          $stopLimitKey = !empty($it['business_id']) ? 'b' . (int) $it['business_id'] : 'd' . (int) ($it['destination_id'] ?? 0);
                          $stopLimit = $placeVisitLimits[$stopLimitKey] ?? null;
                          $stopMin = $placeVisitMins[$stopLimitKey] ?? null;
                          $stopRejected = false;
                          $stopNeedsReserve = false;
                          if ($stopKind === 'business' && itinerary_booking_is_finalized((int) $view['id'])) {
                              $stopReservation = reservation_latest_for_item((int) $it['id']);
                              $stopRejected = $stopReservation && (string) ($stopReservation['status'] ?? '') === 'rejected';
                              $replaced = db()->prepare(
                                  "SELECT id FROM restaurant_reservations WHERE itinerary_item_id=? AND status='rejected' LIMIT 1"
                              );
                              $replaced->execute([(int) $it['id']]);
                              $currentStatus = (string) ($stopReservation['status'] ?? '');
                              $stopNeedsReserve = (bool) $replaced->fetch()
                                  && !in_array($currentStatus, ['pending', 'confirmed', 'completed', 'no_show'], true);
                          }
                        ?>
                        <div class="itin-item<?= $canEdit ? ' itin-item--draggable' : '' ?><?= $stopNeedsReserve ? ' itin-item--needs-reserve' : '' ?>"
                             data-id="<?= (int) $it['id'] ?>"
                             data-itin-stop
                             data-lat="<?= e((string) $stopLat) ?>"
                             data-lng="<?= e((string) $stopLng) ?>"
                             data-visit="<?= (int) $visit ?>"
                             data-visit-limit="<?= $stopLimit !== null ? (int) $stopLimit : '' ?>"
                             data-visit-min="<?= $stopMin !== null ? (int) $stopMin : '' ?>"
                             data-place-name="<?= e((string) ($it['stop_name'] ?? $it['destination_name'] ?? '')) ?>"
                             data-place-key="<?= e($stopPlaceKey) ?>"
                             data-kind="<?= e($stopKind) ?>">
                          <div class="itin-item__main">
                            <div class="itin-item__body">
                              <span class="itin-item__thumb<?= $stopCover !== '' ? ' has-image' : '' ?>"<?= $stopCover !== '' ? ' style="background-image:url(\'' . e($stopCover) . '\')"' : '' ?> aria-hidden="true"></span>
                              <div class="itin-item__info">
                              <div class="itin-item__actions">
                                <?php if (!$stopRejected): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-itin-stop-view data-place-key="<?= e($stopPlaceKey) ?>">View</button>
                            <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-itin-edit-time>Edit</button>
                            <?php endif; ?>
                                <?php endif; ?>
                                <?php reservation_render_stop_panel($it, $view, (bool) $canEdit, 'button'); ?>
                              </div>
                            <?php if ($canEdit && !$scheduleLocked): ?>
                              <form method="post" data-itin-confirm="remove" class="itin-item__remove"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_stop">
                                <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
                                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                <button type="submit" aria-label="Remove stop" title="Remove stop"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                              </form>
                            <?php endif; ?>
                              <div class="small text-muted">
                                <?php if ($canEdit): ?><i class="bi bi-grip-vertical itin-item__handle"></i><?php endif; ?>
                                Stop <span data-itin-stop-num><?= $idx + 1 ?></span>
                                · Place<span data-itin-travel-wrap><?php if ($travel !== null && $travel > 0): ?> · ~<span data-itin-travel><?= (int) $travel ?></span> min drive<?php endif; ?></span>
                              </div>
                              <div><?= e($it['stop_name'] ?? $it['destination_name'] ?? 'Unknown place') ?></div>
                              <div class="small text-muted"><?= e($it['location'] ?? '') ?></div>
                              <?php reservation_render_stop_panel($it, $view, (bool) $canEdit, 'modal'); ?>
                              <div class="small itin-stop-times" data-itin-stop-times>
                                <span class="itin-stop-times__when" data-itin-arrive-leave<?= ($dayMeetupMins === null) ? ' hidden' : '' ?>>
                                  <span>ETA · <span data-itin-arrive><?= e($arrive) ?></span></span>
                                  <span>Leave · <span data-itin-leave><?= e($leave) ?></span></span>
                                </span>
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
                              <div class="form-text"><?php
                                $stayNotes = [];
                                if ($stopMin !== null) {
                                    $stayNotes[] = 'Minimum visit: ' . format_visit_duration_label((int) $stopMin) . '.';
                                }
                                if ($stopLimit !== null) {
                                    $stayNotes[] = 'Visit duration: up to ' . format_visit_duration_label((int) $stopLimit) . '.';
                                }
                                $stayNotes[] = 'Leave time updates; later stops this day re-adjust.';
                                echo e(implode(' ', $stayNotes));
                              ?></div>
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
          <?php if ($canEdit || $holdProceed): ?>
          <div class="itin-proceed-dock" data-itin-proceed-dock></div>
          <?php endif; ?>
        </div>
      </div>

<?php
if ($packageMaker) {
    require __DIR__ . '/../includes/business_shell_end.php';
} else {
    require __DIR__ . '/../includes/tourist_shell_end.php';
}
?>

<?php if ($holdProceed): ?>
<div class="itin-schedule-book" id="itinHoldProceedWrap">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reserve_finalize">
    <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
    <button type="submit" class="btn btn-ct itin-tour-head__book" id="itinHoldProceed">Proceed</button>
  </form>
</div>
<script>
(function () {
  var wrap = document.getElementById('itinHoldProceedWrap');
  var btn = document.getElementById('itinHoldProceed');
  var dock = document.querySelector('[data-itin-proceed-dock]');
  var mapEl = document.getElementById('itinRouteMap');
  if (!wrap || !btn || !dock || !mapEl) return;
  function floatBottom() {
    return window.innerWidth < 992 ? 16 + 76 : 18;
  }
  function place() {
    var mapRight = mapEl.getBoundingClientRect().right;
    var dockTop = dock.getBoundingClientRect().top;
    var btnH = btn.offsetHeight || 44;
    var bottom = floatBottom();
    var floatTop = window.innerHeight - bottom - btnH;
    wrap.style.position = 'fixed';
    wrap.style.zIndex = '1040';
    wrap.style.right = Math.max(8, window.innerWidth - mapRight) + 'px';
    wrap.style.left = 'auto';
    if (dockTop <= floatTop) {
      wrap.style.top = Math.max(8, dockTop) + 'px';
      wrap.style.bottom = 'auto';
    } else {
      wrap.style.top = 'auto';
      wrap.style.bottom = bottom + 'px';
    }
  }
  window.addEventListener('scroll', place, { passive: true });
  window.addEventListener('resize', place);
  place();
})();
</script>
<?php endif; ?>
<?php if ($canEdit): ?>
<div class="itin-schedule-book">
  <button type="button" class="btn btn-ct itin-tour-head__book" id="btnOpenGuideBook" data-itin-tour="book"
    <?= $hasTourDate
      ? ''
      : 'data-itin-need-date title="Set a tour date before continuing"' ?>>Proceed</button>
</div>
<script>
(function () {
  var wrap = document.querySelector('.itin-schedule-book');
  var btn = document.getElementById('btnOpenGuideBook');
  var dock = document.querySelector('[data-itin-proceed-dock]');
  var mapEl = document.getElementById('itinRouteMap');
  if (!wrap || !btn || !dock || !mapEl) return;

  function floatBottom() {
    if (window.innerWidth < 992) return 16 + 76;
    return 18;
  }

  function place() {
    var mapRight = mapEl.getBoundingClientRect().right;
    var dockTop = dock.getBoundingClientRect().top;
    var btnH = btn.offsetHeight || 44;
    var bottom = floatBottom();
    var floatTop = window.innerHeight - bottom - btnH;
    wrap.style.position = 'fixed';
    wrap.style.zIndex = '1040';
    wrap.style.right = Math.max(8, window.innerWidth - mapRight) + 'px';
    wrap.style.left = 'auto';
    if (dockTop <= floatTop) {
      wrap.style.top = Math.max(8, dockTop) + 'px';
      wrap.style.bottom = 'auto';
    } else {
      wrap.style.top = 'auto';
      wrap.style.bottom = bottom + 'px';
    }
  }

  var scheduled = false;
  function requestPlace() {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(function () {
      scheduled = false;
      place();
    });
  }

  document.addEventListener('scroll', requestPlace, { passive: true, capture: true });
  window.addEventListener('resize', requestPlace);
  window.addEventListener('load', requestPlace);
  place();
})();
</script>
<?php endif; ?>

<?php if ($canEdit && $view): ?>
<div class="modal fade itin-book-review-modal" id="itinBookReviewModal" tabindex="-1" aria-labelledby="itinBookReviewLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content itin-book-review-card">
      <div class="modal-header border-0 pb-0">
        <div>
          <div class="itin-book-review-heading">
            <h2 class="modal-title h5 mb-0" id="itinBookReviewLabel"><?= $packageMaker ? 'Package summary' : 'Booking summary' ?></h2>
            <?php if (!$packageMaker && $view): ?>
            <a class="itin-book-review-view" href="<?= APP_URL ?>/itinerary-share.php?id=<?= (int) $view['id'] ?>" target="_blank" rel="noopener">View itinerary</a>
            <?php endif; ?>
          </div>
          <div class="small text-muted"><?= $packageMaker ? 'Check the package. Cancel to keep editing, or show it under Packages made.' : 'Review the booking before you continue' ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2">
        <dl class="itin-book-review-list mb-0">
          <?php if (!$packageMaker): ?>
          <div><dt>Tour guide</dt><dd data-itin-review="guide">Not chosen</dd></div>
          <?php endif; ?>
          <div><dt><?= $packageMaker ? 'Package' : 'Tour name' ?></dt><dd data-itin-review="title"><?= e($view['title'] !== '' ? $view['title'] : 'Unnamed') ?></dd></div>
          <div><dt>Date</dt><dd data-itin-review="date"><?= $hasTourDate ? e(format_date($tourDateDisplay)) : 'Not set' ?></dd></div>
          <div><dt>Days</dt><dd data-itin-review="days"><?= (int) $dayCountDisplay ?> day<?= (int) $dayCountDisplay === 1 ? '' : 's' ?></dd></div>
          <div><dt>Meetup</dt><dd data-itin-review="meetup"><?= e($meetupLabel) ?> · Ormoc City Port</dd></div>
          <div><dt><?= $packageMaker ? 'Participants' : 'Guests' ?></dt><dd data-itin-review="guests">—</dd></div>
          <?php if (!$packageMaker): ?>
          <div><dt>Special requirements</dt><dd data-itin-review="needs">None</dd></div>
          <?php endif; ?>
          <?php if ($packageMaker): ?>
          <div><dt>Package cut off</dt><dd data-itin-review="close">Not set</dd></div>
          <div><dt>Price per person</dt><dd data-itin-review="price">—</dd></div>
          <?php else: ?>
          <div><dt>Stay</dt><dd data-itin-review="stay">Not chosen</dd></div>
          <div><dt>Ride</dt><dd data-itin-review="ride">Not chosen</dd></div>
          <?php endif; ?>
          <div><dt>Stops</dt><dd data-itin-review="stops"><?= count($stops) ?> stop<?= count($stops) === 1 ? '' : 's' ?></dd></div>
        </dl>
        <?php $reviewReservations = $packageMaker ? [] : reservation_itinerary_map((int) $view['id']); ?>
        <?php if ($reviewReservations): ?>
        <div class="itin-book-reserves">
          <?php foreach ($reviewReservations as $reviewReservation): ?>
            <?php
              $reviewCopy = is_array($reviewReservation['copy'] ?? null) ? $reviewReservation['copy'] : reservation_place_copy((string) ($reviewReservation['kind'] ?? 'food'));
              $reviewMode = ($reviewReservation['order_mode'] ?? '') === 'advance_order'
                  ? (string) ($reviewCopy['ahead'] ?? 'Booked ahead')
                  : (string) ($reviewCopy['at_place_short'] ?? 'Choose on arrival');
              $reviewStatus = trim((string) ($reviewReservation['status'] ?? ''));
              $reviewCode = trim((string) ($reviewReservation['code'] ?? ''));
              $reviewItems = [];
              foreach ($reviewReservation['lines'] ?? [] as $reviewLine) {
                  if (!is_array($reviewLine)) {
                      continue;
                  }
                  $reviewItems[] = (int) ($reviewLine['qty'] ?? 0) . ' × ' . (string) ($reviewLine['item_name'] ?? '');
              }
            ?>
            <article class="itin-book-reserve">
              <?php
                $reviewDay = 0;
                if (!empty($isMultidayTour)) {
                    $reviewVisit = (string) ($reviewReservation['visit_date'] ?? $reviewReservation['stop_visit_date'] ?? '');
                    $reviewDay = $reviewVisit !== '' && !empty($tourDateDisplay)
                        ? itinerary_day_number((string) $tourDateDisplay, $reviewVisit)
                        : 1;
                }
              ?>
              <div class="itin-book-reserve__label">Reservation<?= $reviewDay > 0 ? ' · Day ' . (int) $reviewDay : '' ?></div>
              <strong><?= e((string) ($reviewReservation['business_name'] ?? '')) ?></strong>
              <div><?= e($reviewMode) ?></div>
              <?php if ($reviewCode !== '' || $reviewStatus !== ''): ?>
                <div class="itin-book-reserve__meta"><?= e(trim($reviewCode . ($reviewStatus !== '' ? ' · ' . ucfirst(str_replace('_', ' ', $reviewStatus)) : ''))) ?></div>
              <?php endif; ?>
              <?php if ($reviewItems): ?>
                <div><?= e(implode(', ', $reviewItems)) ?></div>
                <div class="itin-book-reserve__total"><?= e((string) ($reviewCopy['total'] ?? 'Total')) ?> <?= e(format_money((float) ($reviewReservation['food_total'] ?? 0))) ?></div>
              <?php endif; ?>
              <div class="itin-book-reserve__note">Paid at the place. Not included in the CulTour booking total.</div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="guide-book-bill<?= $packageMaker ? ' d-none' : '' ?>" data-guide-bill data-ride-spans="<?= (int) ($rideDays ?? $dayCountDisplay) ?>">
          <ul class="guide-book-bill__rows">
            <li><span>Guests</span><strong data-bill-guests>—</strong></li>
            <li><span>Guide</span><strong data-bill-guide>—</strong></li>
            <li><span>Stay</span><strong data-bill-stay>Not needed</strong></li>
            <li class="is-math" data-bill-stay-math hidden></li>
            <li><span>Transportation</span><strong data-bill-ride>Not needed</strong></li>
            <li class="is-math" data-bill-ride-math hidden></li>
            <li class="is-total"><span>Total</span><strong data-bill-total>—</strong></li>
          </ul>
          <?php if (!$packageMaker): ?>
          <label class="itin-book-review-footer__note" for="guideBookNotes">
            <span>Note for the guide</span>
            <textarea class="form-control form-control-sm" id="guideBookNotes" name="notes" form="guideBookForm" rows="3" maxlength="1000" placeholder="Anything the guide should know"></textarea>
          </label>
          <?php endif; ?>
          <p class="guide-book-bill__warn" data-bill-warn hidden></p>
        </div>
        <?php if (!$packageMaker): ?>
        <div class="itin-book-access">
          <div class="itin-book-access__title">Accessibility needs</div>
          <p class="itin-book-access__hint">Does this party include a tourist with a disability who needs extra assistance?</p>
          <div class="itin-book-access__choices" role="radiogroup" aria-label="Accessibility needs">
            <label class="itin-book-access__choice">
              <input type="radio" name="is_pwd" id="guideBookPwdNo" value="0" form="guideBookForm" checked>
              <span>No</span>
            </label>
            <label class="itin-book-access__choice">
              <input type="radio" name="is_pwd" id="guideBookPwdYes" value="1" form="guideBookForm">
              <span>Yes</span>
            </label>
          </div>
          <div class="itin-book-pwd-extra" id="guideBookPwdFields">
            <label class="form-label small mb-1" for="guideBookPwdNotes">Assistance needs (optional)</label>
            <input class="form-control form-control-sm mb-2" id="guideBookPwdNotes" name="pwd_notes" form="guideBookForm" maxlength="255" placeholder="Wheelchair, slower pace, visual assistance…">
            <label class="form-label small mb-1" for="guideBookFitnessCert">Fitness-to-travel certificate (optional)</label>
            <input class="form-control form-control-sm" type="file" id="guideBookFitnessCert" name="fitness_certificate" form="guideBookForm" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
            <div class="itin-book-access__cert-hint">A guest with a high-risk condition needs a doctor’s fitness-to-travel certificate (PDF or photo, up to 8 MB) so the guide knows they are safe to join the tour.</div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($packageMaker): ?>
        <?php
          $packageCoverPath = trim((string) ($GLOBALS['CULTOUR_PACKAGE_IMAGE'] ?? ''));
          $packageCoverUrl = $packageCoverPath !== '' ? APP_URL . '/uploads/' . ltrim($packageCoverPath, '/') : '';
          $packageInclusionList = array_values(array_filter(array_map('trim', explode(',', (string) ($GLOBALS['CULTOUR_PACKAGE_INCLUSIONS'] ?? '')))));
        ?>
        <div class="package-summary-extra">
          <label class="form-label" for="packageCoverInput">Cover photo</label>
          <input class="form-control form-control-sm" id="packageCoverInput" name="package_cover" form="guideBookForm" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
          <img class="package-summary-extra__preview" id="packageCoverPreview" alt=""<?= $packageCoverUrl === '' ? ' hidden' : '' ?> src="<?= e($packageCoverUrl) ?>">
          <div class="itin-date-feedback itin-field-note" data-package-cover-feedback hidden>Add a cover photo.</div>
          <label class="form-label mt-3" for="packageDescriptionField">Package description</label>
          <textarea class="form-control form-control-sm" id="packageDescriptionField" name="package_description" form="guideBookForm" rows="3" maxlength="120" placeholder="Describe this package"><?= e((string) ($GLOBALS['CULTOUR_PACKAGE_DESCRIPTION'] ?? '')) ?></textarea>
          <div class="small text-muted" id="packageDescriptionCount">0 / 120</div>
          <div class="itin-date-feedback itin-field-note" data-package-description-feedback hidden>Enter the package description.</div>
          <label class="form-label mt-3" for="packageInclusionEntry">Inclusions</label>
          <div class="tag-input" id="packageInclusionTags">
            <?php foreach ($packageInclusionList as $packageInclusion): ?>
              <span class="tag-input__chip"><?= e($packageInclusion) ?><button type="button" class="tag-input__remove" aria-label="Remove <?= e($packageInclusion) ?>">×</button></span>
            <?php endforeach; ?>
            <input class="tag-input__entry" id="packageInclusionEntry" type="text" maxlength="40" placeholder="Type an inclusion and press Enter">
          </div>
          <input type="hidden" name="package_inclusions" id="packageInclusionsField" form="guideBookForm" value="<?= e(implode(', ', $packageInclusionList)) ?>">
          <div class="small text-muted" id="packageInclusionCount">0 / 8</div>
          <div class="itin-date-feedback itin-field-note" data-package-inclusion-feedback hidden>Add at least one inclusion.</div>
        </div>
        <script>
        (function () {
          var box = document.getElementById('packageInclusionTags');
          var entry = document.getElementById('packageInclusionEntry');
          var hidden = document.getElementById('packageInclusionsField');
          var descField = document.getElementById('packageDescriptionField');
          var descCount = document.getElementById('packageDescriptionCount');
          if (descField && descCount) {
            function paintDescCount() {
              descCount.textContent = String(descField.value.length) + ' / 120';
            }
            descField.addEventListener('input', paintDescCount);
            paintDescCount();
          }
          var cover = document.getElementById('packageCoverInput');
          var preview = document.getElementById('packageCoverPreview');
          if (!box || !entry || !hidden) return;
          function tags() {
            return Array.prototype.map.call(box.querySelectorAll('.tag-input__chip'), function (chip) {
              return chip.firstChild && chip.firstChild.nodeType === 3 ? chip.firstChild.textContent.trim() : '';
            }).filter(Boolean);
          }
          function sync() {
            var count = tags().length;
            hidden.value = tags().join(', ');
            var full = count >= 8;
            entry.readOnly = full;
            if (full) entry.value = '';
            entry.placeholder = full ? '' : (count ? '' : 'Type an inclusion and press Enter');
            var countEl = document.getElementById('packageInclusionCount');
            if (countEl) countEl.textContent = count + ' / 8';
          }
          function add(text) {
            text = String(text || '').replace(/,/g, '').trim();
            if (!text || text.length > 40 || tags().length >= 8) return;
            var lower = text.toLowerCase();
            if (tags().some(function (tag) { return tag.toLowerCase() === lower; })) return;
            var chip = document.createElement('span');
            chip.className = 'tag-input__chip';
            chip.appendChild(document.createTextNode(text));
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tag-input__remove';
            btn.setAttribute('aria-label', 'Remove ' + text);
            btn.textContent = '×';
            chip.appendChild(btn);
            box.insertBefore(chip, entry);
            sync();
          }
          box.addEventListener('click', function (e) {
            var btn = e.target.closest('.tag-input__remove');
            if (!btn || !btn.parentElement) return;
            btn.parentElement.remove();
            sync();
          });
          entry.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
              e.preventDefault();
              add(entry.value);
              entry.value = '';
            } else if (e.key === 'Backspace' && entry.value === '') {
              var chips = box.querySelectorAll('.tag-input__chip');
              if (chips.length) chips[chips.length - 1].remove();
              sync();
            }
          });
          sync();
          if (cover && preview) {
            cover.addEventListener('change', function () {
              var file = cover.files && cover.files[0];
              if (!file) return;
              preview.src = URL.createObjectURL(file);
              preview.hidden = false;
            });
          }
        })();
        </script>
        <?php endif; ?>
      </div>
      <div class="modal-footer border-0 pt-0 itin-book-review-footer">
        <div class="itin-book-review-footer__actions<?= $packageMaker ? ' itin-book-review-footer__actions--row' : '' ?>">
          <?php if ($packageMaker): ?>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-ct" id="itinBookProceed">Save</button>
          <?php else: ?>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-ct" id="itinBookProceed">Proceed to book</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php if ($packageMaker): ?>
<div class="modal fade" id="itinSaveChoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h2 class="modal-title h5 mb-0">Save this itinerary</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0">
        Save these changes on the itinerary this package already uses, or keep that link and store a separate copy.
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" id="itinSaveNew">Save as new itinerary</button>
        <button type="button" class="btn btn-ct" id="itinSaveExisting">Save itinerary</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="packageGuidePayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h2 class="modal-title h5 mb-0">Pay the tour guide</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0">
        <div class="gcash-pay">
          <div class="gcash-pay__card">
            <div class="gcash-pay__brand">
              <div>
                <div class="small opacity-75">CulTour checkout</div>
                <strong>GCash</strong>
              </div>
              <i class="bi bi-wallet2 fs-3"></i>
            </div>
            <div class="gcash-pay__body">
              <div class="small text-muted">Amount to pay</div>
              <div class="gcash-pay__amount" data-package-pay-amount></div>
              <div class="gcash-pay__qr-wrap">
                <img data-package-pay-qr alt="Scan to pay" width="220" height="220">
              </div>
              <dl class="gcash-pay__meta row mb-0">
                <dt class="col-5">Tour guide</dt>
                <dd class="col-7" data-package-pay-guide></dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-ct" id="packageGuidePayConfirm">Pay with GCash</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
  ensure_place_schedule_schema();
  require_once __DIR__ . '/../includes/business_engagement.php';
  ensure_business_reviews_schema();
  $stayRideRows = array_values(array_filter(db()->query(
      "SELECT b.id, b.name, b.address, b.description, b.phone, b.cover_image, b.avg_rating, b.review_count, bt.name AS type_name, "
      . business_itinerary_ready_columns() . "
       FROM businesses b
       JOIN business_types bt ON bt.id = b.business_type_id
       WHERE b.approval_status='approved' AND b.status='active'
         AND bt.name IN ('Accommodation','Hotel','Resort','Transportation')
       ORDER BY b.name"
  )->fetchAll() ?: [], 'business_itinerary_ready'));
  $stayHotels = [];
  $stayRides = [];
  foreach ($stayRideRows as $stayRow) {
      if ((string) ($stayRow['type_name'] ?? '') === 'Transportation') {
          $stayRides[] = $stayRow;
      } else {
          $stayHotels[] = $stayRow;
      }
  }
  $stayOfferMap = [];
  $stayIds = array_map(static fn (array $row): int => (int) $row['id'], $stayRideRows);
  if ($stayIds) {
      $offerRows = db()->query(
          'SELECT id, business_id, name, description, price, capacity, unit_count, extra_guest_fee, extra_guest_limit, amenities, image_path
           FROM business_services
           WHERE is_active=1 AND business_id IN (' . implode(',', $stayIds) . ')
           ORDER BY price, name'
      )->fetchAll() ?: [];
      foreach ($offerRows as $offerRow) {
          $stayOfferMap[(int) $offerRow['business_id']][] = $offerRow;
      }
  }
  $savedAccId = (int) ($view['accommodation_business_id'] ?? 0);
  $savedAccSvc = (int) ($view['accommodation_service_id'] ?? 0);
  $savedAccDeclined = (int) ($view['accommodation_declined'] ?? 0) === 1;
  $savedTrnId = (int) ($view['transportation_business_id'] ?? 0);
  $savedTrnSvc = (int) ($view['transportation_service_id'] ?? 0);
  $savedTrnDeclined = (int) ($view['transportation_declined'] ?? 0) === 1;
  $readyHotelIds = array_map(static fn (array $row): int => (int) $row['id'], $stayHotels);
  $readyRideIds = array_map(static fn (array $row): int => (int) $row['id'], $stayRides);
  if ($savedAccId > 0 && !in_array($savedAccId, $readyHotelIds, true)) {
      $savedAccId = 0;
      $savedAccSvc = 0;
  }
  if ($savedTrnId > 0 && !in_array($savedTrnId, $readyRideIds, true)) {
      $savedTrnId = 0;
      $savedTrnSvc = 0;
  }
  $accFocusId = 0;
  $trnFocusId = 0;
  [$stayStockStart, $stayStockEnd] = booking_resolve_tour_span((int) $view['id'], (string) ($view['start_date'] ?? date('Y-m-d')));
  $stayBlurb = static function (?string $text): string {
      $text = trim((string) $text);
      if (strlen($text) > 160) {
          $text = substr($text, 0, 157) . '…';
      }
      return $text;
  };
  $stayPhoto = static function (?string $path): string {
      $path = trim((string) $path);
      return $path !== '' ? APP_URL . '/uploads/' . ltrim($path, '/') : '';
  };
  $staySpot = static function (array $biz, string $kicker, string $countAttr, bool $on) use ($stayPhoto, $stayBlurb): void {
      $cover = $stayPhoto($biz['cover_image'] ?? '');
      $blurb = $stayBlurb($biz['description'] ?? '');
      $address = trim((string) ($biz['address'] ?? ''));
      ?>
      <button type="button" class="pick-spot<?= $on ? ' is-selected' : '' ?>" data-pick-card="<?= (int) $biz['id'] ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
        <span class="pick-spot__count" <?= $countAttr ?>="<?= (int) $biz['id'] ?>" hidden></span>
        <span class="pick-spot__photo<?= $cover === '' ? ' is-empty' : '' ?>"<?= $cover !== '' ? ' style="background-image:url(' . e($cover) . ')"' : '' ?>>
          <span class="pick-spot__shade"></span>
          <span class="pick-spot__kicker"><?= e($kicker) ?></span>
        </span>
        <span class="pick-spot__copy">
          <span class="pick-spot__name"><?= e((string) $biz['name']) ?></span>
          <span class="pick-spot__rating"><?= rating_stars_html($biz['avg_rating'] ?? 0, (int) ($biz['review_count'] ?? 0), 'sm') ?></span>
          <?php if ($address !== ''): ?><span class="pick-spot__place"><?= e($address) ?></span><?php endif; ?>
          <?php if ($blurb !== ''): ?><span class="pick-spot__blurb"><?= e($blurb) ?></span><?php endif; ?>
        </span>
      </button>
      <?php
  };
  $stayParty = max(1, (int) ($view['party_size'] ?? 1));
  $stayNights = itinerary_day_count($stayStockStart, $stayStockEnd);
  $rideDays = itinerary_logical_day_count($view);
  $stayDayChips = [];
  if ($rideDays > 1) {
      for ($d = 1; $d <= $rideDays; $d++) {
          $ymd = isset($dayDatesMap[$d]) ? (string) $dayDatesMap[$d] : '';
          $stayDayChips[] = [
              'day' => $d,
              'date' => $ymd,
              'label' => 'Day ' . $d,
              'when' => $ymd !== '' ? format_date($ymd, 'M j') : '',
          ];
      }
  }
  $freeByDay = static function (int $serviceId, ?int $total) use ($stayDayChips, $stayStockStart, $stayStockEnd): ?array {
      if ($total === null || $stayDayChips === []) {
          return null;
      }
      $map = [];
      foreach ($stayDayChips as $chip) {
          $ymd = (string) ($chip['date'] ?? '');
          $map[(string) (int) $chip['day']] = $ymd !== ''
              ? offering_units_free($serviceId, $ymd, $ymd)
              : offering_units_free($serviceId, $stayStockStart, $stayStockEnd);
      }
      return $map;
  };
  $offerQuotes = [];
  $quoteCard = static function (array $service, string $noun, int $spans) use ($stayParty, $stayStockStart, $stayStockEnd, &$offerQuotes): array {
      $quote = offering_quote_card($service, $stayParty, $spans, $noun, $stayStockStart, $stayStockEnd);
      $offerQuotes[(string) (int) $service['id']] = [
          'ok' => (bool) $quote['ok'],
          'message' => (string) $quote['message'],
          'units' => (int) $quote['units'],
          'extra_guests' => (int) $quote['extra_guests'],
          'amount' => (float) $quote['amount'],
          'label' => (string) $quote['label'],
          'amount_label' => (string) ($quote['amount_label'] ?? format_money($quote['amount'])),
          'detail' => (string) ($quote['detail'] ?? ''),
      ];
      return $quote;
  };
  $stayRoomFacts = static function (array $room): array {
      $capRaw = $room['capacity'] ?? null;
      $cap = ($capRaw === null || $capRaw === '' || (int) $capRaw < 1) ? null : (int) $capRaw;
      $feeRaw = $room['extra_guest_fee'] ?? null;
      $fee = ($feeRaw === null || $feeRaw === '' || (float) $feeRaw <= 0) ? 0.0 : round((float) $feeRaw, 2);
      $limitRaw = $room['extra_guest_limit'] ?? null;
      $limit = ($limitRaw === null || $limitRaw === '') ? null : max(0, (int) $limitRaw);
      $extraPer = $fee > 0 ? ($limit !== null ? $limit : (int) ($cap ?? 0)) : 0;
      return ['capacity' => $cap, 'fee' => $fee, 'extra_per' => $extraPer];
  };
  $stayCatalog = [];
  foreach ($stayHotels as $biz) {
      foreach ($stayOfferMap[(int) $biz['id']] ?? [] as $room) {
          $facts = $stayRoomFacts($room);
          $roomTotal = ($room['unit_count'] ?? null) === null || $room['unit_count'] === '' ? null : (int) $room['unit_count'];
          $free = $roomTotal === null ? null : offering_units_free((int) $room['id'], $stayStockStart, $stayStockEnd);
          $stayCatalog[] = [
              'id' => (int) $room['id'],
              'business_id' => (int) $biz['id'],
              'business_name' => (string) $biz['name'],
              'name' => (string) $room['name'],
              'price' => round((float) ($room['price'] ?? 0), 2),
              'capacity' => $facts['capacity'],
              'fee' => $facts['fee'],
              'extra_per' => $facts['extra_per'],
              'free' => $free,
              'free_days' => $freeByDay((int) $room['id'], $roomTotal),
              'nights' => $stayNights,
          ];
      }
  }
  $staySavedLines = [];
  $savedLineRows = stay_lines_list($view['accommodation_lines'] ?? null);
  if (is_array($savedLineRows)) {
      foreach ($savedLineRows as $savedLine) {
          if (!is_array($savedLine)) {
              continue;
          }
          $sid = (int) ($savedLine['service_id'] ?? 0);
          $bid = (int) ($savedLine['business_id'] ?? 0);
          $units = (int) ($savedLine['units'] ?? 0);
          if ($sid < 1 || $bid < 1 || $units < 1 || !in_array($bid, $stayIds, true)) {
              continue;
          }
          $savedStayLine = [
              'business_id' => $bid,
              'service_id' => $sid,
              'units' => $units,
              'extra_guests' => max(0, (int) ($savedLine['extra_guests'] ?? 0)),
          ];
          if (isset($savedLine['guests']) && is_array($savedLine['guests']) && $savedLine['guests'] !== []) {
              $savedStayLine['guests'] = array_values(array_map('intval', $savedLine['guests']));
              $savedStayLine['units'] = count($savedStayLine['guests']);
          }
          $savedDay = (int) ($savedLine['day'] ?? 0);
          if ($savedDay < 1 && count($stayDayChips) > 1) {
              $savedDay = 1;
          }
          if ($savedDay > 0) {
              $savedStayLine['day'] = $savedDay;
          }
          $staySavedLines[] = $savedStayLine;
      }
  }
?>
<div class="modal fade stay-ride-modal" id="stayRideModal" tabindex="-1" aria-labelledby="stayRideModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content itin-book-review-card">
      <div class="modal-header border-0 pb-0">
        <div class="stay-ride-head">
          <button type="button" class="stay-step-back" id="stayPickBack">Back to itinerary</button>
          <div class="stay-ride-title-row">
            <h2 class="modal-title h5 mb-0" id="stayRideModalLabel">Where will you stay?</h2>
            <label class="pick-radio">
              <input type="<?= $packageMaker ? 'checkbox' : 'radio' ?>" name="stayRideAcc" value="0" data-service-id="" data-review-label="Not needed"<?= $savedAccDeclined ? ' checked' : '' ?>>
              <span>I don’t need accommodation</span>
            </label>
            <?php if ($stayHotels): ?>
            <label class="pick-search">
              <i class="bi bi-search" aria-hidden="true"></i>
              <input type="search" data-pick-search data-pick-search-for="stayRideAcc" placeholder="Search a place" aria-label="Search a place" autocomplete="off">
            </label>
            <?php endif; ?>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-3">
        <?php if (count($stayDayChips) > 1): ?>
        <script type="application/json" id="itinTourDays"><?= json_encode($stayDayChips, JSON_UNESCAPED_UNICODE) ?></script>
        <?php endif; ?>
        <div class="pick-board is-browsing" data-pick-board data-pick-group="stayRideAcc">
          <?php if ($stayHotels): ?>
          <div class="pick-focus pick-focus--stay" data-pick-focus>
            <p class="pick-search__empty" data-pick-empty hidden>No places match that search.</p>
            <div class="pick-focus__stage">
              <div class="pick-focus__slot" data-slot="left">
                <button type="button" class="pick-focus__arrow pick-focus__arrow--prev" aria-label="Previous place"><i class="bi bi-chevron-up"></i></button>
              </div>
              <div class="pick-focus__slot pick-focus__slot--center" data-slot="center"></div>
              <div class="pick-focus__slot" data-slot="right">
                <button type="button" class="pick-focus__arrow pick-focus__arrow--next" aria-label="Next place"><i class="bi bi-chevron-down"></i></button>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div class="pick-row pick-row--places" role="listbox" aria-label="Accommodation">
            <?php foreach ($stayHotels as $biz): ?>
              <?php $staySpot($biz, (string) $biz['type_name'], 'data-stay-count', !$savedAccDeclined && $savedAccId === (int) $biz['id']); ?>
            <?php endforeach; ?>
          </div>
          <?php if (!$stayHotels): ?>
            <p class="stay-ride-empty">No approved hotels or resorts yet. You can continue without accommodation.</p>
          <?php else: ?>
            <p class="pick-search__empty" data-pick-grid-empty hidden>No places match that search.</p>
          <?php endif; ?>
          <div class="stay-build">
          <div class="pick-shelf" data-pick-shelf<?= $accFocusId < 1 ? ' hidden' : '' ?>>
            <div class="pick-shelf__label">Rooms</div>
            <?php foreach ($stayHotels as $biz): ?>
              <?php $rooms = $stayOfferMap[(int) $biz['id']] ?? []; ?>
              <div class="pick-row" data-pick-rooms="<?= (int) $biz['id'] ?>"<?= $accFocusId === (int) $biz['id'] ? '' : ' hidden' ?>>
                <?php if (!$rooms): ?>
                  <?php $picked = !$savedAccDeclined && $savedAccId === (int) $biz['id'] && $savedAccSvc < 1; ?>
                  <label class="pick-card pick-card--plain">
                    <input class="pick-card__input" type="radio" name="stayRideAcc" value="<?= (int) $biz['id'] ?>" data-service-id="" data-review-label="<?= e((string) $biz['name']) ?>"<?= $picked ? ' checked' : '' ?>>
                    <span class="pick-card__plain"><strong>Stay at this place</strong><small>Rooms are not listed yet</small></span>
                  </label>
                <?php else: ?>
                  <?php foreach ($rooms as $room): ?>
                    <?php
                      $facts = $stayRoomFacts($room);
                      $roomImg = $stayPhoto($room['image_path'] ?? '');
                      $amenityBits = [];
                      foreach (explode(',', (string) ($room['amenities'] ?? '')) as $amenityBit) {
                          $amenityBit = trim($amenityBit);
                          if ($amenityBit !== '') {
                              $amenityBits[] = $amenityBit;
                          }
                      }
                      $roomTotal = ($room['unit_count'] ?? null) === null || $room['unit_count'] === '' ? null : (int) $room['unit_count'];
                      $roomLeft = $roomTotal === null ? null : offering_units_free((int) $room['id'], $stayStockStart, $stayStockEnd);
                      $roomFull = $roomLeft !== null && $roomLeft < 1;
                    ?>
                    <article class="stay-room stay-room--wide<?= $roomFull ? ' is-full' : '' ?>"
                      data-stay-room="<?= (int) $room['id'] ?>"
                      data-business-id="<?= (int) $biz['id'] ?>"
                      data-business-name="<?= e((string) $biz['name']) ?>"
                      data-room-name="<?= e((string) $room['name']) ?>"
                      data-price="<?= e((string) round((float) ($room['price'] ?? 0), 2)) ?>"
                      data-capacity="<?= $facts['capacity'] === null ? '' : (int) $facts['capacity'] ?>"
                      data-fee="<?= e((string) $facts['fee']) ?>"
                      data-extra-per="<?= (int) $facts['extra_per'] ?>"
                      data-free="<?= $roomLeft === null ? '' : (int) $roomLeft ?>"
                      data-nights="<?= (int) $stayNights ?>">
                      <span class="stay-room__hero<?= $roomImg === '' ? ' is-empty' : '' ?>"<?= $roomImg !== '' ? ' style="background-image:url(' . e($roomImg) . ')"' : '' ?>>
                        <button type="button" class="stay-room__amenities" data-stay-amenities>Amenities</button>
                        <span class="stay-room__chosen" data-stay-chosen="<?= (int) $room['id'] ?>" hidden></span>
                      </span>
                      <div class="stay-room__amenity-pop" data-stay-amenity-pop hidden>
                        <div class="stay-room__amenity-head">
                          <strong>Amenities</strong>
                          <button type="button" data-stay-amenity-close>Close</button>
                        </div>
                        <?php if ($amenityBits): ?>
                          <ul class="stay-room__amenity-list">
                            <?php foreach ($amenityBits as $amenityBit): ?>
                              <li><?= e($amenityBit) ?></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          <p>No amenities listed.</p>
                        <?php endif; ?>
                      </div>
                      <span class="stay-room__body">
                        <strong><?= e((string) $room['name']) ?></strong>
                        <small><?= e(format_money((float) ($room['price'] ?? 0))) ?> / night<?php if ($facts['capacity']): ?> · <?= (int) $facts['capacity'] ?> guest<?= (int) $facts['capacity'] === 1 ? '' : 's' ?><?php endif; ?></small>
                        <?php if ($facts['fee'] > 0 && $facts['extra_per'] > 0): ?>
                          <small>Extra guest <?= e(format_money($facts['fee'])) ?> · up to <?= (int) $facts['extra_per'] ?></small>
                        <?php else: ?>
                          <small>No extra-guest fee</small>
                        <?php endif; ?>
                        <?php if ($roomFull): ?>
                          <small data-stay-stock-note data-stock-total="<?= (int) $roomTotal ?>">None left for these dates</small>
                        <?php elseif ($roomLeft !== null): ?>
                          <small data-stay-stock-note data-stock-total="<?= (int) $roomTotal ?>"><?= (int) $roomLeft ?> of <?= (int) $roomTotal ?> left</small>
                        <?php endif; ?>
                      </span>
                      <button type="button" class="stay-room__add" data-stay-add="<?= (int) $room['id'] ?>"<?= $roomFull ? ' disabled data-stay-stock="0"' : '' ?>>Add room</button>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          </div>
          <script type="application/json" id="itinStayCatalog"><?= json_encode($stayCatalog, JSON_UNESCAPED_UNICODE) ?></script>
          <script type="application/json" id="itinStaySaved"><?= json_encode($staySavedLines, JSON_UNESCAPED_UNICODE) ?></script>
        </div>
      </div>
      <div class="stay-pop" data-stay-pop="closed">
        <button type="button" class="stay-pop__scrim" data-stay-min aria-label="Minimize your stay"></button>
        <section class="stay-pop__card" data-stay-tray data-nights="<?= (int) $stayNights ?>" data-day-count="<?= count($stayDayChips) ?>" data-party="<?= (int) $stayParty ?>" aria-label="Your stay">
          <div class="stay-build__tray-head">
            <div>
              <div class="stay-pop__kicker">Your stay</div>
            </div>
            <button type="button" class="stay-pop__min" data-stay-min>Minimize</button>
          </div>
          <?php if (count($stayDayChips) > 1): ?>
          <div class="stay-days" data-stay-days>
            <?php foreach ($stayDayChips as $chipIndex => $chip): ?>
              <button type="button" class="stay-days__chip<?= $chipIndex === 0 ? ' is-on' : '' ?>" data-stay-day="<?= (int) $chip['day'] ?>">
                <strong><?= e((string) $chip['label']) ?></strong>
                <?php if ((string) $chip['when'] !== ''): ?><small><?= e((string) $chip['when']) ?></small><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="stay-pop__body" data-stay-lines>
            <p class="stay-build__empty">Add a room. It starts with the guests included in its price. If every guest already has a room, those guests move off another room.</p>
          </div>
          <div class="stay-accommodate" data-stay-hold hidden>
            <div class="stay-accommodate__fit">
              <strong data-stay-left>0</strong>
              <span data-stay-left-label>guests still to accommodate</span>
            </div>
            <strong class="stay-accommodate__total" data-stay-day-total>₱0.00</strong>
          </div>
          <div class="stay-pop__actions">
            <button type="button" class="stay-build__another" data-stay-another<?= count($stayHotels) < 2 ? ' hidden' : '' ?>>Add another place</button>
            <button type="button" class="stay-pop__done" data-stay-done>Done</button>
          </div>
        </section>
      </div>
      <div class="modal-footer stay-footer border-0 pt-0">
        <div class="stay-view stay-view--bar">
          <span class="stay-view__kicker">Your stay</span>
          <strong data-stay-view-total>₱0.00</strong>
          <small data-stay-view-count>Nothing added yet</small>
          <button type="button" class="stay-view__open" data-stay-open>View</button>
        </div>
        <button type="button" class="btn btn-ct" id="stayRideContinue"<?= ($savedAccDeclined || $savedAccId > 0) ? '' : ' disabled' ?>>Continue to transportation</button>
      </div>
      <div class="stay-warn" data-stay-warn hidden>
        <button type="button" class="stay-pop__scrim" data-stay-warn-close aria-label="Close"></button>
        <section class="stay-warn__card" role="dialog" aria-modal="true" aria-labelledby="stayWarnTitle">
          <div class="stay-pop__kicker">Before transportation</div>
          <h3 id="stayWarnTitle">Guests still need a room</h3>
          <p data-stay-warn-text>Place every guest before continuing to transportation.</p>
          <button type="button" class="btn btn-ct" data-stay-warn-place>Place guests</button>
        </section>
      </div>
    </div>
  </div>
</div>

<?php
  $rideCatalog = [];
  foreach ($stayRides as $biz) {
      foreach ($stayOfferMap[(int) $biz['id']] ?? [] as $car) {
          $facts = $stayRoomFacts($car);
          $carTotal = ($car['unit_count'] ?? null) === null || $car['unit_count'] === '' ? null : (int) $car['unit_count'];
          $free = $carTotal === null ? null : offering_units_free((int) $car['id'], $stayStockStart, $stayStockEnd);
          $rideCatalog[] = [
              'id' => (int) $car['id'],
              'business_id' => (int) $biz['id'],
              'business_name' => (string) $biz['name'],
              'name' => (string) $car['name'],
              'price' => round((float) ($car['price'] ?? 0), 2),
              'capacity' => $facts['capacity'],
              'fee' => 0,
              'extra_per' => 0,
              'seat_limit' => true,
              'free' => $free,
              'free_days' => $freeByDay((int) $car['id'], $carTotal),
              'nights' => $rideDays,
          ];
      }
  }
  $rideSavedLines = [];
  $savedRideRows = stay_lines_list($view['transportation_lines'] ?? null);
  if (is_array($savedRideRows)) {
      foreach ($savedRideRows as $savedLine) {
          if (!is_array($savedLine)) {
              continue;
          }
          $sid = (int) ($savedLine['service_id'] ?? 0);
          $bid = (int) ($savedLine['business_id'] ?? 0);
          $units = (int) ($savedLine['units'] ?? 0);
          if ($sid < 1 || $bid < 1 || $units < 1 || !in_array($bid, $stayIds, true)) {
              continue;
          }
          $savedRideLine = [
              'business_id' => $bid,
              'service_id' => $sid,
              'units' => $units,
              'extra_guests' => max(0, (int) ($savedLine['extra_guests'] ?? 0)),
          ];
          if (isset($savedLine['guests']) && is_array($savedLine['guests']) && $savedLine['guests'] !== []) {
              $savedRideLine['guests'] = array_values(array_map('intval', $savedLine['guests']));
              $savedRideLine['units'] = count($savedRideLine['guests']);
          }
          $savedDay = (int) ($savedLine['day'] ?? 0);
          if ($savedDay < 1 && count($stayDayChips) > 1) {
              $savedDay = 1;
          }
          if ($savedDay > 0) {
              $savedRideLine['day'] = $savedDay;
          }
          $rideSavedLines[] = $savedRideLine;
      }
  }
?>
<div class="modal fade stay-ride-modal" id="ridePickModal" tabindex="-1" aria-labelledby="ridePickModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content itin-book-review-card">
      <div class="modal-header border-0 pb-0">
        <div class="stay-ride-head">
          <button type="button" class="stay-step-back" id="ridePickBack">Back to accommodation</button>
          <div class="stay-ride-title-row">
            <h2 class="modal-title h5 mb-0" id="ridePickModalLabel">How will you get around?</h2>
            <label class="pick-radio">
              <input type="<?= $packageMaker ? 'checkbox' : 'radio' ?>" name="stayRideTrn" value="0" data-service-id="" data-review-label="Not needed"<?= $savedTrnDeclined ? ' checked' : '' ?>>
              <span>I don’t need transportation</span>
            </label>
            <label class="pick-search">
              <i class="bi bi-search" aria-hidden="true"></i>
              <input type="search" data-pick-search data-pick-search-for="stayRideTrn" placeholder="Search a place" aria-label="Search a place" autocomplete="off">
            </label>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-3">
        <div class="pick-board is-browsing" data-pick-board data-pick-group="stayRideTrn">
          <?php if ($stayRides): ?>
          <div class="pick-focus pick-focus--stay" data-pick-focus>
            <p class="pick-search__empty" data-pick-empty hidden>No places match that search.</p>
            <div class="pick-focus__stage">
              <div class="pick-focus__slot" data-slot="left">
                <button type="button" class="pick-focus__arrow pick-focus__arrow--prev" aria-label="Previous place"><i class="bi bi-chevron-up"></i></button>
              </div>
              <div class="pick-focus__slot pick-focus__slot--center" data-slot="center"></div>
              <div class="pick-focus__slot" data-slot="right">
                <button type="button" class="pick-focus__arrow pick-focus__arrow--next" aria-label="Next place"><i class="bi bi-chevron-down"></i></button>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div class="pick-row pick-row--places" role="listbox" aria-label="Transportation">
            <?php foreach ($stayRides as $biz): ?>
              <?php $staySpot($biz, 'Transportation', 'data-ride-count', !$savedTrnDeclined && $savedTrnId === (int) $biz['id']); ?>
            <?php endforeach; ?>
          </div>
          <?php if (!$stayRides): ?>
            <p class="stay-ride-empty">No approved transportation listings yet. You can continue without a ride.</p>
          <?php else: ?>
            <p class="pick-search__empty" data-pick-grid-empty hidden>No places match that search.</p>
          <?php endif; ?>
          <div class="stay-build">
          <div class="pick-shelf" data-pick-shelf<?= $trnFocusId < 1 ? ' hidden' : '' ?>>
            <div class="pick-shelf__label">Vehicles</div>
            <?php foreach ($stayRides as $biz): ?>
              <?php $cars = $stayOfferMap[(int) $biz['id']] ?? []; ?>
              <div class="pick-row" data-pick-rooms="<?= (int) $biz['id'] ?>"<?= $trnFocusId === (int) $biz['id'] ? '' : ' hidden' ?>>
                <?php if (!$cars): ?>
                  <?php $picked = !$savedTrnDeclined && $savedTrnId === (int) $biz['id'] && $savedTrnSvc < 1; ?>
                  <label class="pick-card pick-card--plain">
                    <input class="pick-card__input" type="radio" name="stayRideTrn" value="<?= (int) $biz['id'] ?>" data-service-id="" data-review-label="<?= e((string) $biz['name']) ?>"<?= $picked ? ' checked' : '' ?>>
                    <span class="pick-card__plain"><strong>Use this service</strong><small>Vehicles are not listed yet</small></span>
                  </label>
                <?php else: ?>
                  <?php foreach ($cars as $car): ?>
                    <?php
                      $facts = $stayRoomFacts($car);
                      $carImg = $stayPhoto($car['image_path'] ?? '');
                      $carTotal = ($car['unit_count'] ?? null) === null || $car['unit_count'] === '' ? null : (int) $car['unit_count'];
                      $carLeft = $carTotal === null ? null : offering_units_free((int) $car['id'], $stayStockStart, $stayStockEnd);
                      $carFull = $carLeft !== null && $carLeft < 1;
                    ?>
                    <?php
                      $featureBits = [];
                      foreach (explode(',', (string) ($car['amenities'] ?? '')) as $featureBit) {
                          $featureBit = trim($featureBit);
                          if ($featureBit !== '') {
                              $featureBits[] = $featureBit;
                          }
                      }
                    ?>
                    <article class="stay-room<?= $carFull ? ' is-full' : '' ?>"
                      data-ride-room="<?= (int) $car['id'] ?>"
                      data-business-id="<?= (int) $biz['id'] ?>"
                      data-business-name="<?= e((string) $biz['name']) ?>"
                      data-room-name="<?= e((string) $car['name']) ?>"
                      data-price="<?= e((string) round((float) ($car['price'] ?? 0), 2)) ?>"
                      data-capacity="<?= $facts['capacity'] === null ? '' : (int) $facts['capacity'] ?>"
                      data-fee="0"
                      data-extra-per="0"
                      data-seat-limit="1"
                      data-free="<?= $carLeft === null ? '' : (int) $carLeft ?>"
                      data-nights="<?= (int) $rideDays ?>">
                      <span class="stay-room__hero<?= $carImg === '' ? ' is-empty' : '' ?>"<?= $carImg !== '' ? ' style="background-image:url(' . e($carImg) . ')"' : '' ?>>
                        <button type="button" class="stay-room__amenities" data-stay-amenities>Features</button>
                        <span class="stay-room__chosen" data-ride-chosen="<?= (int) $car['id'] ?>" hidden></span>
                      </span>
                      <div class="stay-room__amenity-pop" data-stay-amenity-pop hidden>
                        <div class="stay-room__amenity-head">
                          <strong>Features</strong>
                          <button type="button" data-stay-amenity-close>Close</button>
                        </div>
                        <?php if ($featureBits): ?>
                          <ul class="stay-room__amenity-list">
                            <?php foreach ($featureBits as $featureBit): ?>
                              <li><?= e($featureBit) ?></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          <p>No features listed.</p>
                        <?php endif; ?>
                      </div>
                      <span class="stay-room__body">
                        <strong><?= e((string) $car['name']) ?></strong>
                        <small><?= e(format_money((float) ($car['price'] ?? 0))) ?> / day<?php if ($facts['capacity']): ?> · <?= (int) $facts['capacity'] ?> seat<?= (int) $facts['capacity'] === 1 ? '' : 's' ?> max<?php else: ?> · holds 1 guest<?php endif; ?></small>
                        <?php if ($carFull): ?>
                          <small data-ride-stock-note data-stock-total="<?= (int) $carTotal ?>">None left for these dates</small>
                        <?php elseif ($carLeft !== null): ?>
                          <small data-ride-stock-note data-stock-total="<?= (int) $carTotal ?>"><?= (int) $carLeft ?> of <?= (int) $carTotal ?> left</small>
                        <?php endif; ?>
                      </span>
                      <button type="button" class="stay-room__add" data-ride-add="<?= (int) $car['id'] ?>"<?= $carFull ? ' disabled data-ride-stock="0"' : '' ?>>Add vehicle</button>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          </div>
          <script type="application/json" id="itinRideCatalog"><?= json_encode($rideCatalog, JSON_UNESCAPED_UNICODE) ?></script>
          <script type="application/json" id="itinRideSaved"><?= json_encode($rideSavedLines, JSON_UNESCAPED_UNICODE) ?></script>
        </div>
      </div>
      <div class="ride-pop" data-ride-pop="closed">
        <button type="button" class="stay-pop__scrim" data-ride-min aria-label="Close your ride"></button>
        <section class="stay-pop__card" data-ride-tray data-nights="<?= (int) $rideDays ?>" data-day-count="<?= count($stayDayChips) ?>" data-party="<?= (int) $stayParty ?>" aria-label="Your ride">
          <div class="stay-build__tray-head">
            <div>
              <div class="stay-pop__kicker">Your ride</div>
            </div>
            <button type="button" class="stay-pop__min" data-ride-min>Minimize</button>
          </div>
          <?php if (count($stayDayChips) > 1): ?>
          <div class="stay-days" data-ride-days>
            <?php foreach ($stayDayChips as $chipIndex => $chip): ?>
              <button type="button" class="stay-days__chip<?= $chipIndex === 0 ? ' is-on' : '' ?>" data-ride-day="<?= (int) $chip['day'] ?>">
                <strong><?= e((string) $chip['label']) ?></strong>
                <?php if ((string) $chip['when'] !== ''): ?><small><?= e((string) $chip['when']) ?></small><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="stay-pop__body" data-ride-lines>
            <p class="stay-build__empty">Add a vehicle. It takes the guests who still need a seat, up to its seats.</p>
          </div>
          <div class="stay-accommodate" data-ride-hold hidden>
            <div class="stay-accommodate__fit">
              <strong data-ride-left>0</strong>
              <span data-ride-left-label>guests still to accommodate</span>
            </div>
            <strong class="stay-accommodate__total" data-ride-day-total>₱0.00</strong>
          </div>
          <div class="stay-pop__actions">
            <button type="button" class="stay-build__another" data-ride-add-unit>Add another vehicle</button>
            <button type="button" class="stay-build__another" data-ride-another<?= count($stayRides) < 2 ? ' hidden' : '' ?>>Add another place</button>
            <button type="button" class="stay-pop__done" data-ride-done>Done</button>
          </div>
        </section>
      </div>
      <p class="stay-ride-empty text-danger mb-2 px-3" id="stayRideError" hidden></p>
      <div class="modal-footer stay-footer border-0 pt-0">
        <div class="stay-view stay-view--bar">
          <span class="stay-view__kicker">Your ride</span>
          <strong data-ride-view-total>₱0.00</strong>
          <small data-ride-view-count>Nothing added yet</small>
          <button type="button" class="stay-view__open" data-ride-open>View</button>
        </div>
        <button type="button" class="btn btn-ct" id="ridePickContinue" data-house-free="<?= (int) ($bookGuidesTotal ?? 0) ?>"<?= ($savedTrnDeclined || $savedTrnId > 0 || $rideSavedLines) ? '' : ' disabled' ?>><?= $packageMaker ? 'Continue to in-house guide' : 'Continue to tour guide' ?></button>
      </div>
      <div class="stay-warn" data-ride-warn hidden>
        <button type="button" class="stay-pop__scrim" data-ride-warn-close aria-label="Close"></button>
        <section class="stay-warn__card" role="dialog" aria-modal="true" aria-labelledby="rideWarnTitle">
          <div class="stay-pop__kicker">Before the tour guide</div>
          <h3 id="rideWarnTitle">This day still needs a ride</h3>
          <p data-ride-warn-text>Add a vehicle for each day, or mark transportation as not needed.</p>
          <button type="button" class="btn btn-ct" data-ride-warn-place>Place guests</button>
        </section>
      </div>
    </div>
  </div>
</div>

<?php if ($packageMaker): ?>
<div class="modal fade" id="packageNoGuideModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5">No in-house tour guide</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">No in-house tour guide is available on the tour date.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-ct" id="packageNoGuideRequest"<?= empty($packageSystemGuides) ? ' disabled' : '' ?>>Request tour guide</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade guide-book-modal" id="guideBookModal" tabindex="-1" aria-labelledby="guideBookModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content guide-book-modal__panel">
      <div class="modal-header border-0 pb-0">
        <div class="guide-book-modal__head">
          <button type="button" class="stay-step-back" id="guideBookBack">Back to transportation</button>
          <div class="guide-book-modal__title-row">
            <h2 class="modal-title h5 mb-0" id="guideBookModalLabel"><?= $packageMaker ? 'In-house tour guide' : 'Book a tour guide' ?></h2>
            <?php if ($bookGuides || !empty($packageSystemGuides)): ?>
            <label class="pick-search guide-pick__search">
              <i class="bi bi-search" aria-hidden="true"></i>
              <input type="search" id="guidePickSearch" placeholder="Search a guide" aria-label="Search a guide" autocomplete="off">
            </label>
            <?php endif; ?>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">        </button>
      </div>
      <div class="modal-body pt-1">
        <?php if (!$bookGuides && empty($packageSystemGuides)): ?>
          <div class="text-muted p-4 text-center">
            <?php if ($bookGuidesTotal < 1): ?>
              <?= $packageMaker ? 'No active in-house guides yet.' : 'No approved guides with a complete profile yet.' ?>
            <?php else: ?>
              No tour guides to show right now. Try again in a moment.
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div class="guide-pick" id="guideFocusCarousel">
            <div class="guide-pick-grid" id="guidePickGrid"></div>
          </div>
          <div class="guide-book-empty d-none" data-guide-empty>No available guides for this meetup time.</div>
          <?php endif; ?>
          <script type="application/json" id="itinOfferQuotes"><?= json_encode($offerQuotes ?? [], JSON_UNESCAPED_UNICODE) ?></script>
          <form method="post" class="guide-book-form" id="guideBookForm" enctype="multipart/form-data"<?= !$bookGuides && empty($packageSystemGuides) ? ' hidden' : '' ?>><?= csrf_field() ?>
            <input type="hidden" name="action" value="book_guide">
            <input type="hidden" name="itinerary_save_mode" id="itinSaveMode" value="existing">
            <input type="hidden" id="packageAlreadyMade" value="<?= (int) ($GLOBALS['CULTOUR_PACKAGE_MADE'] ?? 0) ?>">
            <input type="hidden" id="itinBaseline" value="<?php
              $itinBaseline = '';
              if ($packageMaker && !empty($view['id'])) {
                  $itinBaseKey = 'pkg_itin_base_' . (int) $view['id'];
                  if (empty($_SESSION[$itinBaseKey])) {
                      $_SESSION[$itinBaseKey] = itinerary_content_hash((int) $view['id']);
                  }
                  $itinBaseline = (string) $_SESSION[$itinBaseKey];
              }
              echo e($itinBaseline);
            ?>">
            <input type="hidden" name="itinerary_id" value="<?= (int) $view['id'] ?>">
            <input type="hidden" name="guide_id" id="guideBookGuideId" value="">
            <input type="hidden" name="party_size" id="guideBookPartySize" value="<?= (int) ($view['party_size'] ?? 0) >= 1 ? (int) $view['party_size'] : '' ?>">
            <input type="hidden" name="guide_adults" id="guideAdultsField" value="">
            <input type="hidden" name="guide_children" id="guideChildrenField" value="">
            <input type="hidden" name="guide_infants" id="guideInfantsField" value="">
            <input type="hidden" name="guide_special" id="guideSpecialField" value="">
            <input type="hidden" name="guide_needs" id="guideNeedsField" value="">
            <input type="hidden" name="accommodation_business_id" id="stayAccId" value="">
            <input type="hidden" name="accommodation_service_id" id="stayAccService" value="">
            <input type="hidden" name="accommodation_declined" id="stayAccDeclined" value="0">
            <input type="hidden" name="accommodation_lines" id="stayAccLines" value="">
            <input type="hidden" name="transportation_business_id" id="stayTrnId" value="">
            <input type="hidden" name="transportation_service_id" id="stayTrnService" value="">
            <input type="hidden" name="transportation_declined" id="stayTrnDeclined" value="0">
            <input type="hidden" name="transportation_lines" id="stayTrnLines" value="">
            <button type="submit" class="d-none" id="guideBookSubmit" disabled>Pick this tour guide</button>
          </form>
        </div>
        <?php if ($bookGuides || !empty($packageSystemGuides)): ?>
        <div class="modal-footer stay-footer border-0 pt-0" id="guidePickDock">
          <div class="stay-view stay-view--bar">
            <span class="stay-view__kicker">Your guide</span>
            <strong data-guide-view-name></strong>
            <small data-guide-view-rate>Select a guide</small>
          </div>
          <button type="button" class="btn btn-ct" id="guidePickConfirm" disabled>Pick this tour guide</button>
        </div>
        <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!$packageMaker): ?>
<div class="modal fade" id="guideGuestModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h2 class="modal-title h5 mb-0" id="guideGuestLabel">Guests joining</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0">
        <p class="mb-2" id="guideInfantQuestion">1. Is your group traveling with an infant/toddler (3 months to 3 years old)?</p>
        <p class="small text-muted mb-2">Infants and toddlers are not counted as guests.</p>
        <div class="pkg-choice mb-3">
          <label><input type="radio" name="guideInfantChoice" value="yes"> Yes</label>
          <label><input type="radio" name="guideInfantChoice" value="no"> No</label>
        </div>
        <div class="row g-2 mb-3">
          <div class="col" id="guideAdultsCol">
            <label class="form-label" for="guideAdults">Adults</label>
            <input type="number" min="0" class="form-control" id="guideAdults" inputmode="numeric">
          </div>
          <div class="col" id="guideChildrenCol">
            <label class="form-label" for="guideChildren">Children</label>
            <input type="number" min="0" class="form-control" id="guideChildren" inputmode="numeric">
          </div>
          <div class="col d-none" id="guideInfantsCol">
            <label class="form-label" for="guideInfants">Infant/toddler</label>
            <input type="number" min="1" class="form-control" id="guideInfants" inputmode="numeric">
          </div>
        </div>
        <p class="mb-2" id="guideSpecialQuestion">2. Does anyone in your group have special requirements?</p>
        <div class="pkg-choice">
          <label><input type="radio" name="guideSpecialChoice" value="yes"> Yes</label>
          <label><input type="radio" name="guideSpecialChoice" value="no"> No</label>
        </div>
        <p class="small text-danger mt-2 mb-0" id="guideGuestError" hidden></p>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-ct w-100" id="guideGuestProceed">Proceed</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="guideNeedsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h2 class="modal-title h5 mb-0">Special requirements</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0">
        <?php
        $guideNeedGroups = [
            'Accessibility & Assistance Needs' => [
                ['Wheelchair assistance', false],
                ['Difficulty walking or standing for long periods', false],
                ['Visual impairment assistance', false],
                ['Hearing impairment assistance', false],
                ['Senior citizen assistance', false],
                ['Pregnant tourist', false],
                ['Assistance with stairs or uneven terrain', false],
                ['Other accessibility requirements', true],
            ],
            'Dietary Requirements & Food Preferences' => [
                ['No pork', false],
                ['No beef', false],
                ['Vegetarian', false],
                ['Vegan', false],
                ['Seafood allergy', false],
                ['Peanut allergy', false],
                ['Dairy-free', false],
                ['Gluten-free', false],
                ['Other food allergies or dietary restrictions', true],
            ],
            'Medical & Health Considerations' => [
                ['Motion sickness', false],
                ['Easily fatigued', false],
                ['Requires regular medication breaks', false],
                ['Requires frequent restroom access', false],
                ['Other health-related travel considerations', true],
            ],
        ];
        foreach ($guideNeedGroups as $groupTitle => $groupItems):
        ?>
          <h3 class="h6 mt-3 mb-2"><?= e($groupTitle) ?></h3>
          <?php foreach ($groupItems as $item): ?>
            <div class="pkg-need">
              <label>
                <input type="checkbox" data-need-check value="<?= e($item[0]) ?>"<?= $item[1] ? ' data-need-other="1"' : '' ?>>
                <span><?= e($item[0]) ?></span>
              </label>
              <input type="number" min="1" class="form-control form-control-sm pkg-need__count" placeholder="How many" hidden>
              <?php if ($item[1]): ?>
                <input type="text" class="form-control form-control-sm pkg-need__detail" maxlength="160" placeholder="What is it?" hidden>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <h3 class="h6 mt-3 mb-2">Other special requests</h3>
        <textarea class="form-control" id="guideOtherRequest" rows="3" maxlength="300" placeholder="Write any other request"></textarea>
        <p class="small text-danger mt-2 mb-0" id="guideNeedsError" hidden></p>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-ct w-100" id="guideNeedsProceed">Proceed</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var guestModal = document.getElementById('guideGuestModal');
  var needsModal = document.getElementById('guideNeedsModal');
  if (!guestModal || !needsModal) return;
  var adults = document.getElementById('guideAdults');
  var children = document.getElementById('guideChildren');
  var infants = document.getElementById('guideInfants');
  var guestError = document.getElementById('guideGuestError');
  var needsError = document.getElementById('guideNeedsError');

  function partyCount() {
    var el = document.getElementById('itinPartySize') || document.getElementById('guideBookPartySize');
    var n = parseInt(el && el.value ? el.value : '1', 10);
    return n >= 1 ? n : 1;
  }
  function onePerson() { return partyCount() === 1; }
  function infantYes() {
    var picked = guestModal.querySelector('input[name="guideInfantChoice"]:checked');
    return !!(picked && picked.value === 'yes');
  }
  function specialYes() {
    var picked = guestModal.querySelector('input[name="guideSpecialChoice"]:checked');
    return picked ? picked.value : '';
  }
  function showError(msg) {
    if (!guestError) return;
    guestError.hidden = !msg;
    guestError.textContent = msg || '';
  }
  function syncInfant() {
    var col = document.getElementById('guideInfantsCol');
    var on = infantYes();
    if (col) col.classList.toggle('d-none', !on);
    if (!on && infants) infants.value = '';
  }
  function fillRemainder(from) {
    if (onePerson()) return;
    var total = partyCount();
    var source = from === 'children' ? children : adults;
    var other = from === 'children' ? adults : children;
    var n = parseInt(source.value || '0', 10);
    if (!isFinite(n) || n < 0) n = 0;
    if (n > total) n = total;
    source.value = String(n);
    other.value = String(total - n);
  }
  guestModal.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'guideInfantChoice') syncInfant();
    showError('');
  });
  if (adults) adults.addEventListener('input', function () { fillRemainder('adults'); });
  if (children) children.addEventListener('input', function () { fillRemainder('children'); });
  needsModal.addEventListener('change', function (e) {
    var box = e.target.closest && e.target.closest('[data-need-check]');
    if (!box) return;
    var row = box.closest('.pkg-need');
    if (!row) return;
    row.querySelectorAll('.pkg-need__count, .pkg-need__detail').forEach(function (field) {
      var hideCount = field.classList.contains('pkg-need__count') && onePerson();
      field.hidden = !box.checked || hideCount;
      if (!box.checked) field.value = '';
    });
    if (needsError) needsError.hidden = true;
  });

  function guestOk() {
    var total = partyCount();
    if (specialYes() === '') {
      showError('Choose Yes or No.');
      return false;
    }
    if (!infantYes() && !guestModal.querySelector('input[name="guideInfantChoice"]:checked')) {
      showError('Choose Yes or No.');
      return false;
    }
    if (onePerson()) {
      adults.value = '1';
      children.value = '0';
    } else if (adults.value === '' || children.value === '') {
      showError('Enter the number of adults and children.');
      return false;
    }
    var a = parseInt(adults.value || '0', 10) || 0;
    var c = parseInt(children.value || '0', 10) || 0;
    var i = infantYes() ? (parseInt(infants.value || '0', 10) || 0) : 0;
    if (infantYes() && i < 1) {
      showError('Enter how many infants or toddlers.');
      return false;
    }
    if (!onePerson() && a + c !== total) {
      showError('Adults and children must add up to ' + total + '. Infants and toddlers are not counted.');
      return false;
    }
    showError('');
    return true;
  }
  function saveFields(needs) {
    document.getElementById('guideAdultsField').value = adults.value || '0';
    document.getElementById('guideChildrenField').value = children.value || '0';
    document.getElementById('guideInfantsField').value = infantYes() ? (infants.value || '0') : '0';
    document.getElementById('guideSpecialField').value = specialYes() === 'yes' ? 'yes' : 'no';
    document.getElementById('guideNeedsField').value = JSON.stringify(needs || []);
    var guestsDd = document.querySelector('[data-itin-review="guests"]');
    if (guestsDd) {
      var text = (adults.value || '0') + ' adults, ' + (children.value || '0') + ' children';
      if (infantYes() && infants.value) text += ', ' + infants.value + ' infant/toddler (not counted)';
      guestsDd.textContent = text;
    }
    var needsDd = document.querySelector('[data-itin-review="needs"]');
    if (needsDd) {
      needsDd.textContent = (needs && needs.length)
        ? needs.map(function (item) {
            return item.label + (item.count ? ' · ' + item.count : '') + (item.detail ? ' · ' + item.detail : '');
          }).join('; ')
        : 'None';
    }
  }
  function continueReview(needs) {
    saveFields(needs);
    var reviewEl = document.getElementById('itinBookReviewModal');
    function showReview() {
      if (typeof syncBookingDetailsPreview === 'function') syncBookingDetailsPreview();
      saveFields(needs);
      if (typeof window.itinRenderBill === 'function') window.itinRenderBill();
      var access = reviewEl ? reviewEl.querySelector('.itin-book-access') : null;
      var special = document.getElementById('guideSpecialField');
      if (access) access.hidden = !!(special && (special.value === 'yes' || special.value === 'no'));
      if (!reviewEl || !window.bootstrap) return;
      document.body.appendChild(reviewEl);
      reviewEl.style.zIndex = '2000';
      bootstrap.Modal.getOrCreateInstance(reviewEl).show();
    }
    var open = [guestModal, needsModal].filter(function (el) { return el.classList.contains('show'); });
    if (!open.length || !window.bootstrap) {
      showReview();
      return;
    }
    var left = open.length;
    open.forEach(function (el) {
      el.addEventListener('hidden.bs.modal', function once() {
        el.removeEventListener('hidden.bs.modal', once);
        left -= 1;
        if (left === 0) showReview();
      });
      bootstrap.Modal.getOrCreateInstance(el).hide();
    });
  }
  window.openGuideGuestModal = function () {
    var total = partyCount();
    var solo = total === 1;
    var label = document.getElementById('guideGuestLabel');
    if (label) label.textContent = solo ? '1 person traveling' : (total + ' guests joining');
    var iq = document.getElementById('guideInfantQuestion');
    if (iq) iq.textContent = solo
      ? 'Are you traveling with an infant/toddler (3 months to 3 years old)?'
      : '1. Is your group traveling with an infant/toddler (3 months to 3 years old)?';
    var sq = document.getElementById('guideSpecialQuestion');
    if (sq) sq.textContent = solo
      ? 'Do you have any special requirements?'
      : '2. Does anyone in your group have special requirements?';
    document.getElementById('guideAdultsCol').classList.toggle('d-none', solo);
    document.getElementById('guideChildrenCol').classList.toggle('d-none', solo);
    if (solo) { adults.value = '1'; children.value = '0'; }
    guestModal.querySelectorAll('input[type="radio"]').forEach(function (r) { r.checked = false; });
    syncInfant();
    showError('');
    if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(guestModal).show();
  };
  document.getElementById('guideGuestProceed').addEventListener('click', function () {
    if (!guestOk()) return;
    if (specialYes() === 'yes') {
      if (needsError) needsError.hidden = true;
      var other = document.getElementById('guideOtherRequest');
      if (other) other.value = '';
      needsModal.querySelectorAll('[data-need-check]').forEach(function (box) {
        box.checked = false;
        var row = box.closest('.pkg-need');
        if (!row) return;
        row.querySelectorAll('.pkg-need__count, .pkg-need__detail').forEach(function (field) {
          field.hidden = true;
          field.value = '';
          field.classList.remove('is-invalid');
        });
      });
      bootstrap.Modal.getOrCreateInstance(needsModal).show();
      return;
    }
    continueReview([]);
  });
  document.getElementById('guideNeedsProceed').addEventListener('click', function () {
    var total = partyCount();
    var needs = [];
    var firstBad = null;
    needsModal.querySelectorAll('[data-need-check]').forEach(function (box) {
      if (!box.checked) return;
      var row = box.closest('.pkg-need');
      var countEl = row ? row.querySelector('.pkg-need__count') : null;
      var detailEl = row ? row.querySelector('.pkg-need__detail') : null;
      var count = onePerson() ? 1 : (countEl ? parseInt(countEl.value || '', 10) : 0);
      var bad = null;
      if (!onePerson() && (!count || count < 1 || count > total)) bad = countEl;
      var detail = detailEl ? detailEl.value.trim() : '';
      if (!bad && box.getAttribute('data-need-other') === '1' && detail === '') bad = detailEl;
      if (bad) {
        bad.classList.add('is-invalid');
        if (!firstBad) firstBad = bad;
        return;
      }
      needs.push({ label: box.value, count: count, detail: detail });
    });
    var other = document.getElementById('guideOtherRequest');
    var otherText = other ? other.value.trim() : '';
    if (otherText) needs.push({ label: 'Other special requests', count: 1, detail: otherText });
    if (firstBad) {
      firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' });
      return;
    }
    if (!needs.length) {
      if (needsError) {
        needsError.hidden = false;
        needsError.textContent = 'Check at least one requirement, or write an other request.';
      }
      return;
    }
    continueReview(needs);
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($canEdit && $view): ?>
<div class="modal fade itin-leave-overlay" id="itinLeaveModal" tabindex="-1" aria-labelledby="itinLeaveModalLabel" aria-hidden="true" data-bs-backdrop="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinLeaveModalLabel">Save this itinerary as a draft?</h2>
        <p class="itin-leave-card__text">You’ve added details to this tour. Save it as a draft to keep it in your list, or discard it.</p>
</div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-itin-leave-save>Save draft</button>
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--danger" data-itin-leave-discard>Discard itinerary</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal" data-itin-leave-keep>Keep editing</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade itin-leave-overlay" id="itinMoreDaysCard" tabindex="-1" aria-labelledby="itinMoreDaysTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered itin-more-days-dialog">
    <div class="modal-content itin-leave-card itin-more-days">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinMoreDaysTitle">How many days?</h2>
        <p class="itin-leave-card__text">The list stops at 7. Type a longer tour, up to <?= (int) itinerary_max_days() ?> days.</p>
        <label class="form-label small mb-1" for="itinMoreDaysInput">Number of days</label>
        <input type="number" class="form-control form-control-sm itin-more-days__input" id="itinMoreDaysInput"
               min="2" max="<?= (int) itinerary_max_days() ?>" step="1" inputmode="numeric" value="8" autocomplete="off">
        <div class="itin-more-days__warn" data-itin-more-days-warn hidden></div>
        <div class="itin-more-days__actions">
          <button type="button" class="btn btn-ct btn-sm" data-itin-more-days-ok>Set days</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-itin-more-days-cancel>Cancel</button>
        </div>
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
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-itin-confirm-ok>Yes</button>
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--danger d-none" data-itin-confirm-alt hidden>Remove later stops</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal" data-itin-confirm-cancel>Cancel</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade itin-leave-overlay" id="itinGuideBusyModal" tabindex="-1" aria-labelledby="itinGuideBusyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinGuideBusyModalLabel">Check this guide’s schedule</h2>
        <p class="itin-leave-card__text" data-itin-guide-busy-name></p>
        <p class="itin-leave-card__text itin-guide-busy-modal__hint" data-itin-guide-busy-hint></p>
        <ul class="itin-guide-busy-modal__days" data-itin-guide-busy-days hidden></ul>
      </div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-itin-guide-busy-ok>Proceed</button>
        <button type="button" class="itin-leave-card__btn" data-itin-guide-busy-pick hidden>Pick another guide</button>
        <button type="button" class="itin-leave-card__btn" data-itin-guide-busy-cancel>Cancel</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade itin-leave-overlay" id="itinVisitDropModal" tabindex="-1" aria-labelledby="itinVisitDropLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinVisitDropLabel">Visit time</h2>
        <p class="itin-leave-card__text" data-itin-visit-drop-place>How long will you stay at this stop?</p>
        <div class="itin-leave-card__field">
          <label class="form-label small mb-1">How long will you stay?</label>
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
<div class="modal fade itin-leave-overlay" id="itinMeetupDropModal" tabindex="-1" aria-labelledby="itinMeetupDropLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinMeetupDropLabel">Meetup time</h2>
        <p class="itin-leave-card__text" data-itin-meetup-drop-place>What time will you meet at this place?</p>
        <div class="itin-leave-card__field">
          <label class="form-label small mb-1">Meetup time</label>
          <div class="itin-meetup-time">
            <input type="time" class="form-control itin-meetup-time__clock" id="itinMeetupDropClock" aria-label="Meetup time">
          </div>
        </div>
      </div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-itin-meetup-drop-ok>Set meetup</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($view): ?>
<div class="modal fade itin-leave-overlay" id="itinStopViewModal" tabindex="-1" aria-labelledby="itinStopViewTitle" aria-hidden="true" data-bs-backdrop="true">
  <div class="modal-dialog itin-stop-view-dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="itinStopViewTitle">Place</h2>
        <p class="itin-leave-card__text" data-stop-view-sub></p>
      </div>
      <div class="itin-stop-view__body" data-stop-view-body></div>
      <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal">Close</button>
    </div>
  </div>
</div>
<div class="itin-listing-zoom" data-listing-zoom hidden>
  <button type="button" class="itin-listing-zoom__backdrop" data-listing-zoom-close aria-label="Close photo"></button>
  <img data-listing-zoom-img alt="">
</div>
<script type="application/json" id="itinPlaceDetails"><?= json_encode($placesForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
  var modal = document.getElementById('itinStopViewModal');
  var detailsEl = document.getElementById('itinPlaceDetails');
  if (!modal || !detailsEl) return;
  var places = {};
  try { places = JSON.parse(detailsEl.textContent || '{}') || {}; } catch (err) { places = {}; }
  var titleEl = document.getElementById('itinStopViewTitle');
  var subEl = modal.querySelector('[data-stop-view-sub]');
  var bodyEl = modal.querySelector('[data-stop-view-body]');

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderPlace(key, fallbackName) {
    var place = places[key] || null;
    if (titleEl) titleEl.textContent = (place && place.name) || fallbackName || 'Place';
    if (subEl) {
      var sub = place && place.subtitle ? place.subtitle : 'Place';
      subEl.textContent = sub;
    }
    if (!bodyEl) return;
    if (!place) {
      bodyEl.innerHTML = '<p class="mb-0">Details are not available for this stop.</p>';
      return;
    }
    var html = '';
    if (place.cover) {
      html += '<div class="itin-stop-view__photo" style="background-image:url(\'' + esc(place.cover) + '\')"></div>';
    }
    html += '<h3 class="itin-stop-view__svcs-title">' + esc(place.offerLabel || 'Listing') + '</h3>';
    var services = Array.isArray(place.services) ? place.services : [];
    if (!services.length) {
      html += '<p class="itin-stop-view__empty">' + esc(place.offerEmpty || 'No listings yet.') + '</p>';
    } else {
      var rows = '';
      services.forEach(function (svc) {
        var showName = !!place.showOfferMeta;
        if (!svc.photo && !showName) return;
        rows += '<li class="place-detail-svc itin-stop-view__listing">';
        if (svc.photo) {
          rows += '<button type="button" class="itin-stop-view__listing-btn" data-listing-photo="' + esc(svc.photo) + '" aria-label="View photo">';
          rows += '<img class="itin-stop-view__listing-photo" src="' + esc(svc.photo) + '" alt="">';
          rows += '</button>';
        }
        if (showName) {
          rows += '<div class="itin-stop-view__listing-copy"><div><div class="fw-semibold">' + esc(svc.name) + '</div>';
          if (svc.description) rows += '<div class="small">' + esc(svc.description) + '</div>';
          rows += '</div>';
          var meta = '';
          if (svc.price) meta += '<span>' + esc(svc.price) + '</span>';
          if (svc.days) meta += '<span class="small">' + esc(svc.days) + (Number(svc.days) === 1 ? ' day' : ' days') + '</span>';
          else if (svc.minutes) meta += '<span class="small">' + esc(svc.minutes) + ' min</span>';
          if (meta) rows += '<div class="place-detail-svc__meta">' + meta + '</div>';
          rows += '</div>';
        }
        rows += '</li>';
      });
      if (!rows) {
        html += '<p class="itin-stop-view__empty">' + esc(place.offerEmpty || 'No listings yet.') + '</p>';
      } else {
        html += '<ul class="place-detail-svcs list-unstyled mb-3">' + rows + '</ul>';
      }
    }
    if (place.location) html += '<p class="itin-stop-view__line"><span>Location</span>' + esc(place.location) + '</p>';
    var hourRows = Array.isArray(place.hoursRows) ? place.hoursRows : [];
    if (hourRows.length) {
      html += '<p class="itin-stop-view__line"><span>Operating hours</span></p><ul class="itin-stop-view__hours">';
      hourRows.forEach(function (row) {
        html += '<li><span>' + esc(row.label) + '</span><span>' + esc(row.value) + '</span></li>';
      });
      html += '</ul>';
    } else if (place.hoursLabel) {
      html += '<p class="itin-stop-view__line"><span>Operating hours</span>' + esc(place.hoursLabel) + '</p>';
    }
    var nearby = Array.isArray(place.nearby) ? place.nearby : [];
    html += '<p class="itin-stop-view__line"><span>Nearby places</span></p>';
    if (!nearby.length) {
      html += '<p class="itin-stop-view__empty">No nearby places yet.</p>';
    } else {
      html += '<ul class="itin-stop-view__nearby">';
      nearby.forEach(function (row) {
        html += '<li><span><strong>' + esc(row.name) + '</strong>';
        if (row.kind) html += '<small>' + esc(row.kind) + '</small>';
        html += '</span><span>' + esc(row.distance || '') + '</span></li>';
      });
      html += '</ul>';
    }
    if (place.showOfferMeta) {
      var about = place.detail || place.description || '';
      if (about) html += '<p class="itin-stop-view__about">' + esc(about) + '</p>';
    }
    var feeText = place.feeLabel || place.fee || '';
    if (feeText) html += '<p class="itin-stop-view__line"><span>Entrance</span>' + esc(feeText) + '</p>';
    if (place.rating != null && place.rating !== '') {
      html += '<p class="itin-stop-view__line"><span>Rating</span>★ ' + esc(place.rating) + '</p>';
    }
    bodyEl.innerHTML = html;
  }

  var zoom = document.querySelector('[data-listing-zoom]');
  var zoomImg = zoom ? zoom.querySelector('[data-listing-zoom-img]') : null;
  function closeListingZoom() {
    if (!zoom) return;
    zoom.hidden = true;
    if (zoomImg) zoomImg.removeAttribute('src');
  }
  if (bodyEl) {
    bodyEl.addEventListener('click', function (e) {
      var photoBtn = e.target.closest('[data-listing-photo]');
      if (!photoBtn || !zoom || !zoomImg) return;
      e.preventDefault();
      e.stopPropagation();
      zoomImg.src = photoBtn.getAttribute('data-listing-photo') || '';
      zoom.hidden = false;
    });
  }
  if (zoom) {
    zoom.addEventListener('click', function (e) {
      if (e.target.closest('[data-listing-zoom-close]')) closeListingZoom();
    });
  }
  modal.addEventListener('hide.bs.modal', function (e) {
    if (zoom && !zoom.hidden) {
      e.preventDefault();
      closeListingZoom();
    }
  });
  modal.addEventListener('hidden.bs.modal', closeListingZoom);

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-itin-stop-view]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var stop = btn.closest('[data-itin-stop]');
    var key = btn.getAttribute('data-place-key') || (stop ? stop.getAttribute('data-place-key') : '') || '';
    var fallback = stop ? (stop.getAttribute('data-place-name') || '') : '';
    renderPlace(key, fallback);
    if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
  });
})();
</script>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/map.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/map.js') ?: time() ?>"></script>
<script>
CulTourMap.renderItineraryRouteMap('itinRouteMap', <?= json_encode($mapStops, JSON_UNESCAPED_UNICODE) ?>, {
  dayCount: <?= (int) $dayCountDisplay ?>,
  meetups: <?= json_encode($mapMeetups, JSON_UNESCAPED_UNICODE) ?>
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
      if (typeof window.itinEnsureDayExpanded === 'function') {
        window.itinEnsureDayExpanded(day);
      }
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

  function watchDaySections() {
    list.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
      io.observe(sec);
    });
  }
  watchDaySections();
  window.itinObserveMapDays = watchDaySections;
})();

(function () {
  var itinId = <?= (int) $view['id'] ?>;
  var storageKey = 'cultour_itin_day_collapsed_' + itinId;

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
    if (e.target.closest('[data-itin-day-date]')) return;
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
    meetupMins: <?= (int) ($meetupMinsUi ?? (8 * 60)) ?>,
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
    if (a.closest('#itinLeaveModal') || a.closest('#itinConfirmModal') || a.closest('#itinVisitDropModal') || a.closest('#itinGuideBusyModal') || a.closest('#guideBookModal')) return;
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
    if (form.closest('#itinLeaveModal') || form.closest('#itinConfirmModal') || form.closest('#itinVisitDropModal') || form.closest('#itinGuideBusyModal')) return true;
    if (form.id === 'itinRenameForm' || form.id === 'itinEditScheduleForm' || form.id === 'itinAddStopForm') return true;
    if (form.id === 'guideBookForm') return true;
    var action = (form.querySelector('[name="action"]') || {}).value || '';
    return action === 'delete'
      || action === 'clear_stops'
      || action === 'remove_stop'
      || action === 'update_stop_stay'
      || action === 'reorder_stops'
      || action === 'update_title'
      || action === 'update_meetup'
      || action === 'update_day_meetup'
      || action === 'add_stop'
      || action === 'auto_regenerate'
      || action === 'auto_generate'
      || action === 'book_guide'
      || action === 'save_draft'
      || action === 'clone'
      || action === 'reserve_submit'
      || action === 'reserve_cancel'
      || action === 'reserve_replace'
      || action === 'reserve_draft_clear';
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
      var extra = {};
      var partyNow = document.getElementById('itinPartySize');
      var dateNow = document.getElementById('itinStartDate');
      if (partyNow && String(partyNow.value || '').trim()) extra.party_size = String(partyNow.value).trim();
      if (dateNow && String(dateNow.value || '').trim()) extra.start_date = String(dateNow.value).trim();
      try {
        var pickedRaw = sessionStorage.getItem('cultour_itin_picked_guide_' + cfg.itinId);
        var picked = pickedRaw ? JSON.parse(pickedRaw) : null;
        if (picked && picked.id) extra.guide_id = String(picked.id);
      } catch (err) {}
      var flush = (typeof window.itinSaveSchedule === 'function')
        ? window.itinSaveSchedule({})
        : Promise.resolve();
      flush.then(function () {
        return postAction('save_draft', extra);
      }).then(function (data) {
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
          pendingUrl = <?= json_encode(APP_URL . '/tourist/itineraries.php') ?>;
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
  var altBtn = modalEl.querySelector('[data-itin-confirm-alt]');
  var pendingForm = null;
  var allowSubmit = false;
  var copy = {
    delete: {
      title: 'Delete this itinerary?',
      text: 'This tour and its stops will be removed. This cannot be undone.',
      ok: 'Delete'
    },
    clear: {
      title: 'Clear all stops?',
      text: 'Every stop will be removed. Meetup place, time, and tour dates stay.',
      ok: 'Clear'
    },
    multiday: {
      title: 'Turn off multi-day?',
      text: 'A later day still has stops. Move them onto Day 1, or remove them so only Day 1 remains.',
      ok: 'Move to Day 1',
      alt: 'Remove later stops'
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
  var pendingAlt = null;

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
        okBtn.classList.toggle('itin-leave-card__btn--danger', kind !== 'multiday');
        okBtn.classList.toggle('itin-leave-card__btn--primary', kind === 'multiday');
      } else {
        okBtn.classList.add('d-none');
      }
    }
    pendingForm = form || null;
    pendingCallback = typeof opts.onProceed === 'function' ? opts.onProceed : null;
    pendingAlt = typeof opts.onAlt === 'function' ? opts.onAlt : null;
    if (altBtn) {
      var altLabel = opts.alt != null ? opts.alt : (c.alt || '');
      if (altLabel && pendingAlt) {
        altBtn.textContent = altLabel;
        altBtn.hidden = false;
        altBtn.classList.remove('d-none');
      } else {
        altBtn.hidden = true;
        altBtn.classList.add('d-none');
      }
    }
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
      pendingAlt = null;
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
  if (altBtn) {
    altBtn.addEventListener('click', function () {
      var cb = pendingAlt;
      pendingCallback = null;
      pendingAlt = null;
      pendingForm = null;
      allowSubmit = false;
      var m = getModal();
      if (m) m.hide();
      if (cb) cb();
    });
  }
  modalEl.addEventListener('hidden.bs.modal', function () {
    if (!allowSubmit) {
      pendingForm = null;
      pendingCallback = null;
      pendingAlt = null;
    }
    if (okBtn) okBtn.classList.remove('d-none');
    if (altBtn) {
      altBtn.hidden = true;
      altBtn.classList.add('d-none');
    }
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
  var multiEl = document.querySelector('[data-itin-multiday]');
  var dayCountEl = document.querySelector('[data-itin-day-count]');
  var addDayWrap = document.querySelector('[data-itin-add-day-wrap]');
  var addDaySel = document.querySelector('[data-itin-add-day]');
  var timer = null;
  var saving = false;
  var suppressReload = false;
  var saveChain = Promise.resolve();
  var skipBusySpanWarn = false;
  var lastSavedMulti = !!(multiEl && multiEl.checked);
  var lastSavedDayCount = 1;
  var itinDayDates = <?= json_encode((object) ($dayDatesMap ?? []), JSON_UNESCAPED_UNICODE) ?> || {};

  function currentScheduleDayCount() {
    if (multiEl && multiEl.checked && dayCountEl) {
      var n = parseInt(String(dayCountEl.value || '2'), 10) || 2;
      return n < 2 ? 2 : n;
    }
    return 1;
  }

  function snapshotScheduleSpan() {
    lastSavedMulti = !!(multiEl && multiEl.checked);
    lastSavedDayCount = currentScheduleDayCount();
  }
  snapshotScheduleSpan();

  function applyDayDatesFromPayload(days) {
    if (!days || !days.length) return;
    var next = {};
    days.forEach(function (row) {
      var d = parseInt(row.day, 10);
      if (d) next[d] = String(row.date || '');
    });
    itinDayDates = next;
  }

  function dayDateYmd(dayNum) {
    var d = parseInt(dayNum, 10) || 1;
    if (itinDayDates[d]) return String(itinDayDates[d]);
    if (itinDayDates[String(d)]) return String(itinDayDates[String(d)]);
    var start = dateEl ? String(dateEl.value || '').trim() : '';
    return start ? addDaysYmd(start, d - 1) : '';
  }

  function formatMeetupClock(raw) {
    var digits = String(raw || '').replace(/\D/g, '').slice(0, 4);
    if (!digits) return '';

    var hourStr = '';
    var minStr = '';
    var rest = '';

    var d0 = digits.charAt(0);

    // First number(s) = hour (1–12 only), then minutes
    if (d0 === '0') {
      if (digits.length === 1) return '0';
      var d1 = digits.charAt(1);
      if (d1 === '0') {
        hourStr = '12';
        rest = digits.slice(2);
      } else {
        hourStr = String(parseInt(d1, 10)); // 01–09 → 1–9
        rest = digits.slice(2);
      }
    } else if (d0 === '1') {
      if (digits.length === 1) return '1';
      var d1b = digits.charAt(1);
      if (d1b === '0' || d1b === '1' || d1b === '2') {
        // 10, 11, or 12
        hourStr = '1' + d1b;
        rest = digits.slice(2);
      } else {
        // 13–19: hour is 1, next digits are minutes
        hourStr = '1';
        rest = digits.slice(1);
      }
    } else {
      // 2–9: single-digit hour
      hourStr = d0;
      rest = digits.slice(1);
    }

    var hNum = parseInt(hourStr, 10);
    if (!hNum || hNum < 1) hNum = 1;
    if (hNum > 12) hNum = 12;

    minStr = rest.slice(0, 2);
    if (minStr.length >= 1 && minStr.charAt(0) > '5') {
      minStr = '5' + minStr.slice(1);
    }
    if (minStr.length === 2) {
      var mi = parseInt(minStr, 10);
      if (mi > 59) minStr = '59';
    }

    if (!minStr) {
      // Still entering the hour — keep 10/11/12 zero-padded, else show as typed
      if (hNum >= 10) return String(hNum);
      if (d0 === '1' && digits.length === 1) return '1';
      if (d0 === '0' && digits.length === 1) return '0';
      return String(hNum);
    }

    return String(hNum).padStart(2, '0') + ':' + minStr;
  }

  function time24ToDisplay(value) {
    var m = String(value || '').trim().match(/^(\d{1,2}):(\d{2})/);
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (!isFinite(h) || !isFinite(min) || h < 0 || h > 23 || min < 0 || min > 59) return '';
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(min).padStart(2, '0') + ' ' + ampm;
  }

  function displayToTime24(display) {
    var m = String(display || '').trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (h === 12) h = 0;
    if (m[3].toUpperCase() === 'PM') h += 12;
    if (h < 0 || h > 23 || min < 0 || min > 59) return '';
    return String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0');
  }
  window.itinDisplayToTime24 = displayToTime24;
  window.itinGuideHourWindows = <?= json_encode($packageMaker ? null : tourist_meetup_hour_windows()) ?>;

  function meetupWindowForDay(day) {
    var windows = window.itinGuideHourWindows;
    if (!windows) return null;
    var dateEl = document.querySelector('[data-itin-day-section="' + String(day) + '"] [data-itin-day-date-input]');
    var ymd = dateEl ? String(dateEl.value || '').trim() : '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
    var dow = new Date(ymd + 'T12:00:00').getDay();
    return windows[String(dow)] || null;
  }

  function applyMeetupHourWindow(clockEl) {
    if (!clockEl) return;
    var day = clockEl.getAttribute('data-day') || '1';
    var win = meetupWindowForDay(day);
    if (!win) {
      clockEl.removeAttribute('min');
      clockEl.removeAttribute('max');
      return;
    }
    clockEl.min = win.min;
    clockEl.max = win.max;
    var value = String(clockEl.value || '');
    if (!value || (value >= win.min && value <= win.max)) {
      clockEl.classList.remove('is-invalid');
      return;
    }
    if (value && (value < win.min || value > win.max)) {
      clockEl.classList.add('is-invalid');
      var card = clockEl.closest('[data-itin-day-meetup]');
      var fb = card && card.querySelector('[data-itin-day-meetup-feedback]');
      if (fb) {
        fb.hidden = false;
        fb.textContent = 'Pick a time between ' + win.min + ' and ' + win.max + '.';
      }
    }
  }

  function applyAllMeetupHourWindows() {
    document.querySelectorAll('[data-itin-day-meetup-clock]').forEach(applyMeetupHourWindow);
  }
  window.itinApplyMeetupHourWindow = applyMeetupHourWindow;
  applyAllMeetupHourWindows();

  (function () {
    document.querySelectorAll('input[type="time"].itin-meetup-time__clock').forEach(function (clock) {
      clock.classList.add('itin-meetup-time__clock--custom');
      clock.readOnly = false;
    });
    var pop = document.createElement('div');
    pop.className = 'itin-time-pop';
    pop.hidden = true;
    document.body.appendChild(pop);
    var openClock = null;

    function clockMins(value) {
      var m = String(value || '').match(/^(\d{1,2}):(\d{2})$/);
      if (!m) return null;
      return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    }

    function hourLabel(h) {
      var ampm = h >= 12 ? 'PM' : 'AM';
      var h12 = h % 12;
      if (h12 === 0) h12 = 12;
      return h12 + ' ' + ampm;
    }

    function closePop() {
      pop.hidden = true;
      openClock = null;
    }

    function paintHours(clock) {
      var win = meetupWindowForDay(clock.getAttribute('data-day') || '1');
      var minM = win ? clockMins(win.min) : 0;
      var maxM = win ? clockMins(win.max) : (23 * 60 + 59);
      pop.replaceChildren();
      var title = document.createElement('p');
      title.className = 'itin-time-pop__title';
      title.textContent = 'Choose a time';
      pop.appendChild(title);
      var grid = document.createElement('div');
      grid.className = 'itin-time-pop__grid';
      for (var h = 0; h < 24; h++) {
        var start = h * 60;
        var blocked = win && (start + 59 < minM || start > maxM);
        var current = clockMins(clock.value);
        var selected = current !== null && Math.floor(current / 60) === h;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'itin-time-pop__hour' + (blocked ? ' is-blocked' : '') + (selected ? ' is-selected' : '');
        btn.textContent = hourLabel(h);
        btn.disabled = !!blocked;
        if (blocked) btn.title = 'Not available';
        btn.setAttribute('data-hour', String(h));
        grid.appendChild(btn);
      }
      pop.appendChild(grid);
      placePop(clock);
    }

    function paintMinutes(clock, hour) {
      var win = meetupWindowForDay(clock.getAttribute('data-day') || '1');
      var minM = win ? clockMins(win.min) : 0;
      var maxM = win ? clockMins(win.max) : (23 * 60 + 59);
      pop.replaceChildren();
      var title = document.createElement('p');
      title.className = 'itin-time-pop__title';
      title.textContent = hourLabel(hour);
      pop.appendChild(title);
      var back = document.createElement('button');
      back.type = 'button';
      back.className = 'itin-time-pop__min';
      back.textContent = 'Back';
      back.addEventListener('click', function () { paintHours(clock); });
      pop.appendChild(back);
      var grid = document.createElement('div');
      grid.className = 'itin-time-pop__grid';
      [0, 15, 30, 45].forEach(function (min) {
        var total = hour * 60 + min;
        var blocked = win && (total < minM || total > maxM);
        var current = clockMins(clock.value);
        var selected = current !== null && current === total;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'itin-time-pop__min' + (blocked ? ' is-blocked' : '') + (selected ? ' is-selected' : '');
        btn.textContent = String(min).padStart(2, '0');
        btn.disabled = blocked;
        if (!blocked) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var hhmm = String(hour).padStart(2, '0') + ':' + String(min).padStart(2, '0');
            clock.removeAttribute('min');
            clock.removeAttribute('max');
            clock.value = hhmm;
            clock.blur();
            clock.dispatchEvent(new Event('input', { bubbles: true }));
            clock.dispatchEvent(new Event('change', { bubbles: true }));
            closePop();
          });
        }
        grid.appendChild(btn);
      });
      pop.appendChild(grid);
      placePop(clock);
    }

    function placePop(clock) {
      if (!clock) return;
      var rect = clock.getBoundingClientRect();
      var height = pop.offsetHeight || 160;
      var width = pop.offsetWidth || 220;
      var top = rect.bottom + 6;
      if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 6);
      pop.style.top = top + 'px';
      pop.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + 'px';
    }

    pop.addEventListener('click', function (e) {
      var hourBtn = e.target.closest('[data-hour]');
      if (!hourBtn || !openClock || hourBtn.disabled) return;
      paintMinutes(openClock, parseInt(hourBtn.getAttribute('data-hour'), 10));
    });

    function openPop(clock) {
      openClock = clock;
      pop.hidden = false;
      paintHours(clock);
    }

    document.addEventListener('mousedown', function (e) {
      var clock = e.target.closest && e.target.closest('input[type="time"].itin-meetup-time__clock--custom');
      if (clock) {
        e.preventDefault();
        openPop(clock);
        return;
      }
      if (!pop.hidden && !pop.contains(e.target)) closePop();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closePop();
    });
  })();

  function dayMeetupClock(day) {
    var card = document.querySelector('[data-itin-day-meetup="' + String(day) + '"]');
    return card ? card.querySelector('[data-itin-day-meetup-clock]') : null;
  }

  function dayMeetupAmPm(day) {
    var card = document.querySelector('[data-itin-day-meetup="' + String(day) + '"]');
    return card ? card.querySelector('[data-itin-day-meetup-ampm]') : null;
  }

  function composeDayMeetupValue(day) {
    var clockEl = dayMeetupClock(day);
    if (!clockEl) return '';
    return time24ToDisplay(clockEl.value);
  }

  function composeMeetupValue() {
    var clockEl = dayMeetupClock(1);
    if (clockEl) return composeDayMeetupValue(1);
    return meetup ? String(meetup.value || '').trim() : '';
  }

  function paintDayMeetupClock(day, display) {
    var clockEl = dayMeetupClock(day);
    var labelEl = document.querySelector('[data-itin-day-meetup="' + String(day) + '"] [data-itin-meetup-card-label]');
    var v = String(display || '').trim();
    var time24 = displayToTime24(v);
    if (!time24) {
      if (clockEl) clockEl.value = '';
      if (labelEl) labelEl.textContent = 'Meetup time not set';
      if (window.itinSyncMeetupTimeFeedback) window.itinSyncMeetupTimeFeedback();
      return;
    }
    if (clockEl) clockEl.value = time24;
    if (labelEl) labelEl.textContent = 'Meetup time · ' + time24ToDisplay(time24);
    if (window.itinSyncMeetupTimeFeedback) window.itinSyncMeetupTimeFeedback();
  }
  window.itinPaintDayMeetupClock = paintDayMeetupClock;

  function syncMeetupHidden() {
    if (!meetup) return;
    meetup.value = composeMeetupValue();
  }

  function paintMeetupCard() {
    var v = meetup ? String(meetup.value || '').trim() : composeMeetupValue();
    var cardEl = document.querySelector('[data-itin-meetup-card-label]');
    if (cardEl) {
      cardEl.textContent = v ? ('Meetup time · ' + v) : 'Meetup time not set';
    }
    var modalEl = document.querySelector('[data-itin-guide-meetup-label]');
    if (modalEl) {
      modalEl.textContent = v ? ('Meetup ' + v) : 'Meetup Not set';
    }
  }

  function formatYmdLabel(ymd) {
    var parts = String(ymd || '').split('-');
    if (parts.length !== 3) return '';
    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    if (isNaN(dt.getTime())) return '';
    try {
      return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (err) {
      return ymd;
    }
  }

  function paintStartCard(ymd) {
    var day1Date = document.querySelector('[data-itin-day-section="1"] [data-itin-day-date-label]');
    if (day1Date) {
      var label = formatYmdLabel(ymd);
      day1Date.textContent = label || 'Date not set';
    }
    paintGuideModalDate();
  }

  function meetupToMins(display) {
    var m = String(display || '').trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (!m) return null;
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (!Number.isFinite(h) || !Number.isFinite(min)) return null;
    var ap = m[3].toUpperCase();
    if (h === 12) h = 0;
    if (ap === 'PM') h += 12;
    return h * 60 + min;
  }

  function markScheduleSaved(data) {
    var savedDate = data && data.start_date !== undefined
      ? String(data.start_date || '').trim()
      : (dateEl ? String(dateEl.value || '').trim() : '');
    var savedMeetup = data && data.meetup_time !== undefined
      ? String(data.meetup_time || '').trim()
      : (meetup ? String(meetup.value || '').trim() : '');
    form.setAttribute('data-itin-avail-date', savedDate);
    form.setAttribute('data-itin-avail-meetup', savedMeetup);
    paintStartCard(savedDate);
    paintMeetupCard();
    var mins = meetupToMins(savedMeetup);
    if (mins != null && window.CulTourItinTiming) {
      window.CulTourItinTiming.meetupMins = mins;
    }
  }

  function hasTourDate() {
    return !!(dateEl && String(dateEl.value || '').trim());
  }

  function formatDayOption(dayNum, startYmd) {
    var ymd = dayDateYmd(dayNum) || startYmd;
    if (!ymd) return 'Day ' + dayNum;
    var parts = String(ymd).split('-');
    if (parts.length !== 3) return 'Day ' + dayNum;
    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    if (isNaN(dt.getTime())) return 'Day ' + dayNum;
    try {
      return 'Day ' + dayNum + ' · ' + dt.toLocaleDateString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric'
      });
    } catch (err) {
      return 'Day ' + dayNum + ' · ' + ymd;
    }
  }

  function addDaysYmd(ymd, add) {
    var parts = String(ymd || '').split('-');
    if (parts.length !== 3) return '';
    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]) + add);
    if (isNaN(dt.getTime())) return '';
    var mm = String(dt.getMonth() + 1).padStart(2, '0');
    var dd = String(dt.getDate()).padStart(2, '0');
    return dt.getFullYear() + '-' + mm + '-' + dd;
  }

  function desiredDayCount() {
    if (!multiEl || !multiEl.checked) return 1;
    var n = parseInt(dayCountEl && dayCountEl.value ? dayCountEl.value : '2', 10) || 2;
    if (n < 2) n = 2;
    var maxDays = parseInt(dayCountEl && dayCountEl.getAttribute('data-max-days') ? dayCountEl.getAttribute('data-max-days') : '30', 10) || 30;
    if (n > maxDays) n = maxDays;
    return n;
  }

  function formatYmdRangeLabel(startYmd, endYmd) {
    var a = formatYmdLabel(startYmd);
    if (!a) return '';
    if (!endYmd || endYmd === startYmd) return a;
    var b = formatYmdLabel(endYmd);
    if (!b) return a;
    var ap = String(startYmd).split('-');
    var bp = String(endYmd).split('-');
    if (ap.length === 3 && bp.length === 3 && ap[0] === bp[0] && ap[1] === bp[1]) {
      var d1 = String(parseInt(ap[2], 10));
      var d2 = String(parseInt(bp[2], 10));
      var mon = a.replace(/\s+\d{1,2},.*/, '');
      return mon + ' ' + d1 + '–' + d2 + ', ' + ap[0];
    }
    return a + '–' + b;
  }

  function paintGuideModalDate() {
    var el = document.querySelector('[data-itin-guide-date-label]');
    if (!el) return;
    var start = dateEl ? String(dateEl.value || '').trim() : '';
    if (!start) {
      el.textContent = 'No date set';
      return;
    }
    var n = desiredDayCount();
    var first = dayDateYmd(1) || start;
    var last = dayDateYmd(n) || first;
    el.textContent = formatYmdRangeLabel(first, last) || first;
  }
  window.itinPaintGuideModalDate = paintGuideModalDate;

  function syncDayPlanUi() {
    var list = document.querySelector('.itin-stop-list--panel');
    if (!list) return;
    var n = desiredDayCount();
    var start = dateEl ? String(dateEl.value || '').trim() : '';
    var title = document.querySelector('.itin-builder__stops-card .itin-plan-head .panel-title');
    if (title) title.textContent = n > 1 ? 'Day-by-day plan' : 'Route stops';
    if (n > 1) list.setAttribute('data-itin-day-scroll', '');
    else list.removeAttribute('data-itin-day-scroll');

    var sections = Array.prototype.slice.call(list.querySelectorAll('[data-itin-day-section]'));
    if (!sections.length) return;
    var itinId = '';
    var firstSort = sections[0].querySelector('[data-itin-sortable]');
    if (firstSort) itinId = firstSort.getAttribute('data-itin-id') || '';

    function paintSection(sec, dayNum) {
      sec.setAttribute('data-itin-day-section', String(dayNum));
      sec.classList.toggle('itin-day--single', n < 2);
      var head = sec.querySelector('.itin-day__head');
      var note = sec.querySelector(':scope > [data-itin-day-guide-busy]');
      if (n < 2) {
        var meetupWrap = sec.querySelector('.itin-item--meetup .itin-meetup-card')
          || sec.querySelector('.itin-item--meetup > div');
        sec.querySelectorAll('[data-itin-day-guide-busy]').forEach(function (el) {
          if (meetupWrap && meetupWrap.contains(el)) return;
          if (head && head.contains(el)) return;
          el.remove();
        });
        if (meetupWrap && !meetupWrap.querySelector('[data-itin-day-guide-busy]')) {
          note = document.createElement('div');
          note.className = 'itin-day__guide-busy d-none';
          note.setAttribute('data-itin-day-guide-busy', '');
          note.hidden = true;
          meetupWrap.appendChild(note);
        }
      }
      if (!head) {
        head = document.createElement('div');
        head.className = 'itin-day__head';
        head.innerHTML = '<span class="itin-day__head-main" data-itin-day-toggle role="button" tabindex="0"><i class="bi bi-chevron-down itin-day__chevron" aria-hidden="true"></i><strong></strong></span>' +
          '<span class="itin-day__date" data-itin-day-date><span data-itin-day-date-label></span>' +
          '<label class="itin-day__date-pick" title="Change date"><i class="bi bi-calendar3" aria-hidden="true"></i>' +
          '<input type="date" data-itin-day-date-input min="' + tourDateMin() + '" value=""></label></span>';
        sec.insertBefore(head, sec.firstChild);
      } else {
        var toggleEl = head.querySelector('[data-itin-day-toggle]') || head.querySelector('.itin-day__head-main');
        if (head.hasAttribute('data-itin-day-toggle')) {
          head.removeAttribute('data-itin-day-toggle');
          head.removeAttribute('role');
          head.removeAttribute('tabindex');
        }
        if (toggleEl) {
          toggleEl.setAttribute('data-itin-day-toggle', '');
          toggleEl.setAttribute('role', 'button');
          toggleEl.setAttribute('tabindex', '0');
        }
        if (!head.querySelector('[data-itin-day-date]')) {
          var oldMuted = null;
          head.querySelectorAll('.small.text-muted').forEach(function (el) {
            if (!el.closest('[data-itin-day-date]')) oldMuted = el;
          });
          if (oldMuted) oldMuted.remove();
          var dateBox = document.createElement('span');
          dateBox.className = 'itin-day__date';
          dateBox.setAttribute('data-itin-day-date', '');
          dateBox.innerHTML = '<span data-itin-day-date-label></span>' +
            '<label class="itin-day__date-pick" title="Change date"><i class="bi bi-calendar3" aria-hidden="true"></i>' +
            '<input type="date" data-itin-day-date-input min="' + tourDateMin() + '" value=""></label>';
          head.appendChild(dateBox);
        }
      }
      var toggleBtn = head.querySelector('[data-itin-day-toggle]');
      if (toggleBtn) {
        toggleBtn.setAttribute('aria-label', 'Toggle Day ' + dayNum);
        toggleBtn.setAttribute('aria-expanded', sec.classList.contains('is-collapsed') ? 'false' : 'true');
      }
      var strong = head.querySelector('strong');
      if (strong) strong.textContent = 'Day ' + dayNum;
      var ymd = dayDateYmd(dayNum);
      var dateLabel = head.querySelector('[data-itin-day-date-label]');
      if (dateLabel) dateLabel.textContent = ymd ? formatYmdLabel(ymd) : 'Date not set';
      var dateInput = head.querySelector('[data-itin-day-date-input]');
      if (dateInput) {
        dateInput.setAttribute('data-day', String(dayNum));
        dateInput.setAttribute('aria-label', 'Date for Day ' + dayNum);
        dateInput.min = tourDateMin();
        dateInput.value = ymd || '';
      }
      var dateBoxEl = head.querySelector('[data-itin-day-date]');
      if (dateBoxEl) {
        var startKind = dateBoxEl.querySelector('[data-itin-day-start-label]');
        if (dayNum === 1) {
          if (!startKind) {
            startKind = document.createElement('span');
            startKind.className = 'itin-day__date-kind';
            startKind.setAttribute('data-itin-day-start-label', '');
            startKind.textContent = 'Start';
            dateBoxEl.insertBefore(startKind, dateBoxEl.querySelector('[data-itin-day-date-label]') || dateBoxEl.firstChild);
          }
        } else if (startKind) {
          startKind.remove();
        }
      }
      if (n >= 2 && (!note || note.closest('.itin-item--meetup'))) {
        note = document.createElement('div');
        note.className = 'itin-day__guide-busy d-none';
        note.setAttribute('data-itin-day-guide-busy', '');
        note.hidden = true;
        head.insertAdjacentElement('afterend', note);
      }
      var dayFb = sec.querySelector(':scope > [data-itin-day-date-feedback]');
      if (!dayFb && n >= 2) {
        dayFb = document.createElement('div');
        dayFb.className = 'itin-date-feedback';
        dayFb.setAttribute('data-itin-day-date-feedback', '');
        dayFb.hidden = true;
        var afterBusy = sec.querySelector(':scope > [data-itin-day-guide-busy]') || head;
        afterBusy.insertAdjacentElement('afterend', dayFb);
      }
      var stopsBox = sec.querySelector('.itin-day__stops');
      if (stopsBox) stopsBox.setAttribute('data-itin-day', String(dayNum));
      var zone = sec.querySelector('[data-itin-place-drop]');
      if (zone) {
        zone.setAttribute('data-itin-day', String(dayNum));
        var label = zone.querySelector('span');
        if (label) {
          label.textContent = n > 1 ? ('Drop a place on Day ' + dayNum) : 'Drop a place here';
        }
      }
    }

    sections.forEach(function (sec, i) {
      paintSection(sec, i + 1);
    });

    while (sections.length < n) {
      var clone = sections[0].cloneNode(true);
      var stopsBox = clone.querySelector('.itin-day__stops');
      if (stopsBox) {
        stopsBox.innerHTML = '';
        if (itinId) stopsBox.setAttribute('data-itin-id', itinId);
      }
      var nextDay = sections.length + 1;
      var cloneFb = clone.querySelector('[data-itin-day-date-feedback]');
      if (cloneFb) {
        cloneFb.hidden = true;
        cloneFb.textContent = '';
      }
      var meetupCard = clone.querySelector('[data-itin-day-meetup]');
      if (meetupCard) {
        meetupCard.setAttribute('data-itin-day-meetup', String(nextDay));
        var startLbl = meetupCard.querySelector('[data-itin-start-card-label]');
        if (startLbl) startLbl.remove();
        meetupCard.querySelectorAll('.itin-meetup-card__copy > .small.text-muted').forEach(function (el) {
          el.remove();
        });
        var editPlace = meetupCard.querySelector('[data-itin-edit-meetup-place]');
        if (editPlace) editPlace.setAttribute('data-day', String(nextDay));
        meetupCard.querySelectorAll('.itin-meetup-place-actions [data-itin-use-port]').forEach(function (btn) {
          btn.remove();
        });
        var placeLine = meetupCard.querySelector('.itin-meetup-place-line');
        var portLink = placeLine && placeLine.querySelector('[data-itin-use-port]');
        if (!portLink && placeLine) {
          portLink = document.createElement('button');
          portLink.type = 'button';
          portLink.className = 'itin-meetup-place-edit';
          portLink.setAttribute('data-itin-use-port', '');
          portLink.textContent = 'Use port';
          if (editPlace) editPlace.insertAdjacentElement('afterend', portLink);
          else placeLine.appendChild(portLink);
        }
        if (portLink) portLink.setAttribute('data-day', String(nextDay));
        var emptyMenu = meetupCard.querySelector('.itin-meetup-menu');
        if (emptyMenu && !emptyMenu.querySelector('.itin-meetup-menu__opt')) emptyMenu.remove();
        var timeLbl = meetupCard.querySelector('[data-itin-meetup-card-label]');
        if (timeLbl) timeLbl.textContent = 'Meetup time not set';
        var timeWrap = meetupCard.querySelector('[data-itin-day-meetup-time]');
        if (timeWrap) {
          timeWrap.setAttribute('data-itin-day-meetup-time', String(nextDay));
          var clockEl = timeWrap.querySelector('[data-itin-day-meetup-clock]');
          var ampmEl = timeWrap.querySelector('[data-itin-day-meetup-ampm]');
          if (clockEl) {
            clockEl.value = '';
            clockEl.setAttribute('data-day', String(nextDay));
            clockEl.setAttribute('aria-label', 'Day ' + nextDay + ' meetup time');
          }
        }
        var meetupFb = meetupCard.querySelector('[data-itin-day-meetup-feedback]');
        if (!meetupFb) {
          meetupFb = document.createElement('div');
          meetupFb.className = 'itin-date-feedback';
          meetupFb.setAttribute('data-itin-day-meetup-feedback', '');
          meetupFb.textContent = 'Meetup time not set';
          var timeRowEl = meetupCard.querySelector('.itin-meetup-time-row');
          if (timeRowEl) timeRowEl.insertAdjacentElement('afterend', meetupFb);
        }
        meetupFb.hidden = true;
        var cloneClock = meetupCard.querySelector('[data-itin-day-meetup-clock]');
        if (cloneClock) cloneClock.classList.remove('is-invalid');
        var placeFb = meetupCard.querySelector('[data-itin-day-meetup-place-feedback]');
        if (!placeFb) {
          placeFb = document.createElement('div');
          placeFb.className = 'itin-date-feedback';
          placeFb.setAttribute('data-itin-day-meetup-place-feedback', '');
          placeFb.textContent = 'Meetup place not set';
          var headEl = meetupCard.querySelector('.itin-meetup-card__head');
          if (headEl) headEl.insertAdjacentElement('afterend', placeFb);
        }
        placeFb.hidden = true;
        var nameEl = meetupCard.querySelector('[data-itin-meetup-place-name]');
        if (nameEl) nameEl.textContent = 'Meetup not set';
        meetupCard.querySelectorAll('[data-itin-edit-meetup-place], [data-itin-use-port], [data-itin-clear-meetup-place], [data-itin-reset-meetup-place]').forEach(function (btn) {
          btn.setAttribute('data-day', String(nextDay));
          if (btn.hasAttribute('data-itin-clear-meetup-place') || btn.hasAttribute('data-itin-reset-meetup-place')) btn.remove();
        });
        var leftoverMenu = meetupCard.querySelector('.itin-meetup-menu');
        if (leftoverMenu && !leftoverMenu.querySelector('.itin-meetup-menu__opt')) leftoverMenu.remove();
      } else {
        // Ensure later days have a meetup card
        var insertAfter = clone.querySelector(':scope > [data-itin-day-date-feedback]')
          || clone.querySelector(':scope > [data-itin-day-guide-busy]')
          || clone.querySelector('.itin-day__head');
        meetupCard = document.createElement('div');
        meetupCard.className = 'itin-item itin-item--meetup';
        meetupCard.setAttribute('data-itin-day-meetup', String(nextDay));
        meetupCard.innerHTML =
          '<div class="itin-meetup-card">' +
            '<div class="itin-meetup-card__head">' +
              '<div class="itin-meetup-card__copy">' +
                '<div class="itin-meetup-place-line">' +
                  '<span class="itin-meetup-place-line__label">Meetup place ·</span>' +
                  '<div data-itin-meetup-place-name>Meetup not set</div>' +
                  '<button type="button" class="itin-meetup-place-edit" data-itin-edit-meetup-place data-day="' + nextDay + '">Edit place</button>' +
                  '<button type="button" class="itin-meetup-place-edit" data-itin-use-port data-day="' + nextDay + '">Use port</button>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div class="itin-date-feedback" data-itin-day-meetup-place-feedback hidden>Meetup place not set</div>' +
            '<div class="itin-meetup-time-row">' +
              '<span class="itin-meetup-time-row__label">Meetup time ·</span>' +
              '<div class="itin-meetup-time" data-itin-day-meetup-time="' + nextDay + '">' +
                '<input type="time" class="form-control form-control-sm itin-meetup-time__clock itin-meetup-time__clock--custom" data-itin-day-meetup-clock data-day="' + nextDay + '" aria-label="Day ' + nextDay + ' meetup time" value="">' +
              '</div>' +
            '</div>' +
            '<div class="itin-date-feedback" data-itin-day-meetup-feedback hidden>Meetup time not set</div>' +
          '</div>' +
          '<div class="itin-meetup-drop-hint">Drop to set meetup place</div>';
        if (insertAfter && insertAfter.parentNode === clone) {
          insertAfter.insertAdjacentElement('afterend', meetupCard);
        } else {
          clone.insertBefore(meetupCard, clone.firstChild);
        }
      }
      paintSection(clone, nextDay);
      list.appendChild(clone);
      sections.push(clone);
    }

    for (var i = sections.length - 1; i >= n; i--) {
      if (sections[i].querySelector('[data-itin-stop]')) continue;
      sections[i].remove();
      sections.splice(i, 1);
    }
    if (typeof window.itinPaintGuideDayBusyNotes === 'function') {
      window.itinPaintGuideDayBusyNotes();
    }
    if (typeof window.itinApplyDayCollapsed === 'function') {
      window.itinApplyDayCollapsed();
    }
    if (window.itinSyncBookButton) window.itinSyncBookButton();
    if (typeof window.itinObserveMapDays === 'function') window.itinObserveMapDays();
  }

  function laterDaysHaveStops() {
    var found = false;
    document.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
      var day = parseInt(sec.getAttribute('data-itin-day-section') || '1', 10) || 1;
      if (day > 1 && sec.querySelector('[data-itin-stop]')) found = true;
    });
    return found;
  }

  function syncAddDayPicker() {
    if (addDayWrap && addDaySel) {
      var multiOn = !!(multiEl && multiEl.checked);
      addDayWrap.classList.toggle('d-none', !multiOn);
      var n = 1;
      if (multiOn) {
        n = parseInt(dayCountEl && dayCountEl.value ? dayCountEl.value : '2', 10);
        if (!n || n < 2) n = 2;
        var maxDays = parseInt(dayCountEl && dayCountEl.getAttribute('data-max-days') ? dayCountEl.getAttribute('data-max-days') : '30', 10) || 30;
        if (n > maxDays) n = maxDays;
      }
      var start = dateEl ? String(dateEl.value || '').trim() : '';
      var prev = addDaySel.value;
      addDaySel.innerHTML = '';
      for (var d = 1; d <= n; d++) {
        var opt = document.createElement('option');
        opt.value = String(d);
        opt.textContent = formatDayOption(d, start);
        addDaySel.appendChild(opt);
      }
      if (prev && parseInt(prev, 10) <= n) addDaySel.value = prev;
      else addDaySel.value = '1';
    }
    var mapEl = document.getElementById('itinRouteMap');
    if (mapEl) {
      if (typeof mapEl._ctSetDayCount === 'function') mapEl._ctSetDayCount(n);
      else mapEl._ctPendingDayCount = n;
    }
    syncDayPlanUi();
    paintGuideModalDate();
  }

  function tourDateMin() {
    var min = String(form.getAttribute('data-itin-today') || '').trim();
    if (!document.body.classList.contains('package-maker') || !min) return min;
    var parts = min.split('-');
    if (parts.length !== 3) return min;
    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    dt.setDate(dt.getDate() + 1);
    var month = String(dt.getMonth() + 1).padStart(2, '0');
    var day = String(dt.getDate()).padStart(2, '0');
    return dt.getFullYear() + '-' + month + '-' + day;
  }

  function isPastTourDate(ymd) {
    var min = tourDateMin();
    return !!(ymd && min && String(ymd) < min);
  }

  function flagPastTourDates() {
    var msg = document.body.classList.contains('package-maker')
      ? 'The package tour date must be at least 2 days from today.'
      : 'Tours cannot be booked for today. Choose tomorrow or a later date.';
    var past = false;
    if (dateEl && isPastTourDate(dateEl.value)) {
      past = true;
      dateEl.classList.add('is-invalid');
    }
    document.querySelectorAll('[data-itin-day-date-input]').forEach(function (input) {
      if (!isPastTourDate(input.value)) return;
      past = true;
      input.classList.add('is-invalid');
      var sec = input.closest('[data-itin-day-section]');
      var dayFb = sec ? sec.querySelector('[data-itin-day-date-feedback]') : null;
      if (dayFb) {
        dayFb.textContent = msg;
        dayFb.hidden = false;
      }
    });
    if (!past) return '';
    showDateError(msg);
    return msg;
  }

  function dateFeedback() {
    return form.querySelector('[data-itin-date-feedback]');
  }

  function showDateError(message) {
    if (dateEl) dateEl.classList.add('is-invalid');
    var fb = dateFeedback();
    if (fb) {
      fb.textContent = message || 'Set a tour date before booking a guide.';
      fb.hidden = false;
    }
    if (window.itinSyncBookButton) window.itinSyncBookButton();
  }

  function clearDateError() {
    if (dateEl) dateEl.classList.remove('is-invalid');
    var fb = dateFeedback();
    if (fb) fb.hidden = true;
    if (window.itinSyncBookButton) window.itinSyncBookButton();
  }
  window.itinShowDateError = showDateError;
  window.itinClearDateError = clearDateError;

  function labeledBusyDayMap(g) {
    var days = {};
    var rows = (g && Array.isArray(g.busy_by_day)) ? g.busy_by_day : [];
    rows.forEach(function (row) {
      if (!row || row.day == null || !row.label) return;
      days[String(row.day)] = true;
    });
    return days;
  }

  function hasNewLabeledBusyDays(prevG, nextG) {
    var prev = labeledBusyDayMap(prevG);
    var rows = (nextG && Array.isArray(nextG.busy_by_day)) ? nextG.busy_by_day : [];
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      if (!row || row.day == null || !row.label) continue;
      if (!prev[String(row.day)]) return true;
    }
    return false;
  }

  function applyPickedGuideBusy(iid, g, paintNotes) {
    try {
      sessionStorage.setItem('cultour_itin_picked_guide_' + iid, JSON.stringify(g));
    } catch (err) {}
    paintNotes(g);
    snapshotScheduleSpan();
  }

  function revertSpanDayCount() {
    skipBusySpanWarn = true;
    if (multiEl) multiEl.checked = lastSavedMulti;
    if (dayCountEl && lastSavedMulti) {
      dayCountEl.value = String(Math.max(2, lastSavedDayCount));
    }
    syncAddDayPicker();
    saveSchedule({ structureOnly: true }).then(function (data) {
      if (!data || !data.ok) skipBusySpanWarn = false;
    }, function () {
      skipBusySpanWarn = false;
    });
  }

  function refreshPickedGuideBusyNotes() {
    var skipWarn = skipBusySpanWarn;
    skipBusySpanWarn = false;
    var paintNotes = window.itinPaintGuideDayBusyNotes;
    var iidEl = form.querySelector('[name="itinerary_id"]');
    var iid = iidEl ? parseInt(iidEl.value, 10) : 0;
    if (typeof paintNotes !== 'function' || !iid) {
      snapshotScheduleSpan();
      return;
    }
    var picked = null;
    try {
      var raw = sessionStorage.getItem('cultour_itin_picked_guide_' + iid);
      picked = raw ? JSON.parse(raw) : null;
    } catch (err) {
      picked = null;
    }
    if (!picked || !picked.id) {
      snapshotScheduleSpan();
      return;
    }
    var prevNotes = window._itinBusyGuideNotes;
    if (!prevNotes && picked && Array.isArray(picked.busy_by_day)) prevNotes = picked;
    var meetupHidden = form.querySelector('[name="meetup_time"]');
    var q = new URLSearchParams();
    q.set('itinerary_id', String(iid));
    if (dateEl && dateEl.value) q.set('start_date', String(dateEl.value).trim());
    if (meetupHidden && meetupHidden.value) q.set('meetup_time', String(meetupHidden.value).trim());
    var nDays = currentScheduleDayCount();
    if (nDays > 1) q.set('day_count', String(nDays));
    fetch(<?= json_encode(APP_URL . '/api/guides/book_poll.php', JSON_UNESCAPED_SLASHES) ?> + '?' + q.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (res) { return res.json(); }).then(function (poll) {
      if (!poll || !poll.ok || !Array.isArray(poll.guides)) {
        snapshotScheduleSpan();
        return;
      }
      var g = null;
      for (var i = 0; i < poll.guides.length; i++) {
        if (Number(poll.guides[i].id) === Number(picked.id)) {
          g = poll.guides[i];
          break;
        }
      }
      if (!g) {
        snapshotScheduleSpan();
        return;
      }
      applyPickedGuideBusy(iid, g, paintNotes);
    }).catch(function () {
      snapshotScheduleSpan();
    });
  }
  window.itinRefreshPickedGuideBusyNotes = refreshPickedGuideBusyNotes;

  function localPastMeetupMessage() {
    var today = String(form.getAttribute('data-itin-today') || '');
    var nowMins = parseInt(form.getAttribute('data-itin-now-mins') || '-1', 10);
    var date = dateEl ? String(dateEl.value || '').trim() : '';
    if (!date || date !== today || nowMins < 0) return '';
    var display = composeMeetupValue();
    var mins = meetupToMins(display);
    if (mins == null || mins >= nowMins) return '';
    return 'Meetup time (' + display + ') is already past for today. Choose a later time or another date.';
  }

  function refreshPastMeetupWarning() {
    var fb = dateFeedback();
    var current = fb && !fb.hidden ? String(fb.textContent || '') : '';
    var pastNow = localPastMeetupMessage();
    if (pastNow) {
      showDateError(pastNow);
      return;
    }
    if (/already past/i.test(current)) {
      clearDateError();
    }
  }

  function saveScheduleNow(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (suppressReload) {
        resolve({ ok: false, skipped: true });
        return;
      }
      if (actionInput) actionInput.value = 'update_meetup';
      syncMeetupHidden();

      var body = new FormData(form);
      body.set('action', 'update_meetup');
      if (dateEl) body.set('start_date', String(dateEl.value || '').trim());
      var closePick = document.getElementById('packageCloseDate');
      if (closePick && !closePick.disabled) body.set('package_close_date', String(closePick.value || '').trim());
      if (multiEl) {
        if (multiEl.checked) body.set('is_multiday', '1');
        else body.delete('is_multiday');
      }
      if (dayCountEl) body.set('day_count', String(dayCountEl.value || '2'));
      if (opts.dropLaterStops) body.set('drop_later_stops', '1');

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
          if (data && data.message) showDateError(data.message);
          resolve(data || { ok: false });
          return;
        }
        clearDateError();
        if (data.days) applyDayDatesFromPayload(data.days);
        if (data.start_date !== undefined && dateEl && data.start_date) {
          dateEl.value = String(data.start_date);
        }
        if (data.reordered) {
          window.location.reload();
          resolve(data);
          return;
        }
        syncAddDayPicker();
        markScheduleSaved(data);
        refreshPickedGuideBusyNotes();
        resolve(data);
      }).catch(function () {
        saving = false;
        resolve({ ok: false });
      });
    });
  }

  function saveSchedule(opts) {
    var run = function () { return saveScheduleNow(opts || {}); };
    saveChain = saveChain.then(run, run);
    return saveChain;
  }

  /**
   * Persist schedule when needed so guide availability matches the form.
   * Meetup is required only when finishing a booking (reopen === 'review').
   */
  function ensureScheduleForBooking(reopen) {
    syncMeetupHidden();
    if (flagPastTourDates()) {
      if (dateEl) dateEl.focus();
      return Promise.resolve({ ok: false, reason: 'past-date' });
    }
    var curDate = dateEl ? String(dateEl.value || '').trim() : '';
    var curMeetup = meetup ? String(meetup.value || '').trim() : '';
    var availDate = String(form.getAttribute('data-itin-avail-date') || '').trim();
    var availMeetup = String(form.getAttribute('data-itin-avail-meetup') || '').trim();
    var needMeetup = reopen === 'review';
    if (!curDate && !(touristDate && touristDate.checked)) {
      showDateError('Set a tour date before booking a guide.');
      if (dateEl) dateEl.focus();
      return Promise.resolve({ ok: false, reason: 'need-date' });
    }
    if (needMeetup && !curMeetup) {
      showDateError('Enter a meetup time for this tour date.');
      var day1Clock = dayMeetupClock(1);
      if (day1Clock) day1Clock.focus();
      return Promise.resolve({ ok: false, reason: 'need-meetup' });
    }
    if (curDate === availDate && curMeetup === availMeetup) {
      return Promise.resolve({ ok: true, reloading: false });
    }
    clearTimeout(timer);
    if (saving) {
      return new Promise(function (resolve) {
        setTimeout(function () {
          ensureScheduleForBooking(reopen).then(resolve);
        }, 150);
      });
    }
    return saveSchedule({}).then(function (data) {
      if (!data || !data.ok) {
        return { ok: false, reason: 'save-failed' };
      }
      return { ok: true, reloading: false };
    });
  }

  window.itinSaveSchedule = saveSchedule;
  window.itinEnsureScheduleForBooking = ensureScheduleForBooking;
  window.itinFormatMeetupClock = formatMeetupClock;
  window.itinFlagPastTourDates = flagPastTourDates;

  function applyMeetupClockDisplay(display) {
    var v = String(display || '').trim();
    var m = v.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (!m) return false;
    var h = parseInt(m[1], 10);
    if (h === 0) h = 12;
    paintDayMeetupClock(1, h + ':' + m[2] + ' ' + m[3].toUpperCase());
    if (meetup) meetup.value = h + ':' + m[2] + ' ' + m[3].toUpperCase();
    syncMeetupHidden();
    paintMeetupCard();
    var mins = meetupToMins(meetup ? meetup.value : '');
    if (mins != null && window.CulTourItinTiming) {
      window.CulTourItinTiming.meetupMins = mins;
    }
    return true;
  }
  function clearMeetupClockDisplay() {
    paintDayMeetupClock(1, '');
    if (meetup) meetup.value = '';
    syncMeetupHidden();
    paintMeetupCard();
    if (window.CulTourItinTiming) window.CulTourItinTiming.meetupMins = 8 * 60;
  }
  window.itinPaintMeetupClock = function (display) {
    if (!String(display || '').trim()) {
      clearMeetupClockDisplay();
      return;
    }
    applyMeetupClockDisplay(display);
  };
  window.itinApplyMeetupTime = function (display) {
    applyMeetupClockDisplay(display);
    if (!meetup || !String(meetup.value || '').trim()) return Promise.resolve({ ok: false });
    return saveSchedule({});
  };

  window.itinSaveDayMeetupTime = function (day, timeDisplay, opts) {
    var iid = form.querySelector('[name="itinerary_id"]');
    var csrf = form.querySelector('[name="csrf_token"]') || form.querySelector('input[type="hidden"][name*="csrf"]');
    if (!iid || timeDisplay == null) return Promise.resolve({ ok: false });
    var body = new FormData();
    body.set('action', 'update_day_meetup');
    body.set('itinerary_id', iid.value);
    body.set('day_number', String(day));
    body.set('time_only', '1');
    body.set('meetup_time', String(timeDisplay));
    if (opts && opts.allowPast) body.set('allow_past', '1');
    if (csrf) body.set(csrf.name, csrf.value);
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: body,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (String(timeDisplay || '') !== String(composeDayMeetupValue(day) || '')) {
        return data || { ok: false };
      }
      if (!data || !data.ok) {
        if (data && data.message) showDateError(data.message);
        return data || { ok: false };
      }
      refreshPastMeetupWarning();
      var info = data.meetup || {};
      var shown = String(info.meetup_display || timeDisplay || '').trim();
      paintDayMeetupClock(day, shown);
      if (Number(day) === 1) {
        if (shown) applyMeetupClockDisplay(shown);
        else clearMeetupClockDisplay();
      }
      if (typeof window.itinReflowDayLive === 'function') {
        window.itinReflowDayLive(day);
      }
      return data;
    }).catch(function () { return { ok: false }; });
  };

  function saveDayDateNow(day, ymd) {
    return new Promise(function (resolve) {
      if (suppressReload) {
        resolve({ ok: false, skipped: true });
        return;
      }
      var body = new FormData(form);
      body.set('action', 'update_day_date');
      body.set('day_number', String(day));
      body.set('date', ymd);
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
          var sec = document.querySelector('[data-itin-day-section="' + String(day) + '"]');
          var dayFb = sec ? sec.querySelector('[data-itin-day-date-feedback]') : null;
          var dayInput = sec ? sec.querySelector('[data-itin-day-date-input]') : null;
          if (dayFb) {
            dayFb.textContent = (data && data.message) || 'Choose a tour date that is today or later.';
            dayFb.hidden = false;
          }
          if (dayInput) dayInput.classList.add('is-invalid');
          resolve(data || { ok: false });
          return;
        }
        var okSec = document.querySelector('[data-itin-day-section="' + String(day) + '"]');
        var okFb = okSec ? okSec.querySelector('[data-itin-day-date-feedback]') : null;
        var okInput = okSec ? okSec.querySelector('[data-itin-day-date-input]') : null;
        if (okFb) {
          okFb.hidden = true;
          okFb.textContent = '';
        }
        if (okInput) okInput.classList.remove('is-invalid');
        if (data.days) applyDayDatesFromPayload(data.days);
        if (data.start_date && dateEl) dateEl.value = String(data.start_date);
        if (data.reordered) {
          window.location.reload();
          resolve(data);
          return;
        }
        syncAddDayPicker();
        markScheduleSaved(data);
        refreshPickedGuideBusyNotes();
        resolve(data);
      }).catch(function () {
        saving = false;
        resolve({ ok: false });
      });
    });
  }

  function saveDayDate(day, ymd) {
    var run = function () { return saveDayDateNow(day, ymd); };
    saveChain = saveChain.then(run, run);
    return saveChain;
  }

  document.addEventListener('click', function (e) {
    var pick = e.target.closest('.itin-day__date-pick');
    if (!pick) return;
    e.stopPropagation();
    var input = pick.querySelector('[data-itin-day-date-input]');
    if (!input) return;
    input.min = tourDateMin();
    if (input && typeof input.showPicker === 'function') {
      e.preventDefault();
      try { input.showPicker(); } catch (err) { input.focus(); }
    }
  });
  document.addEventListener('focusin', function (e) {
    var input = e.target && e.target.closest ? e.target.closest('[data-itin-day-date-input], #itinStartDate') : null;
    if (!input) return;
    input.min = tourDateMin();
    input.setAttribute('data-prev-date', input.value || '');
  });
  document.addEventListener('change', function (e) {
    var input = e.target.closest('[data-itin-day-date-input]');
    if (!input) return;
    var day = parseInt(input.getAttribute('data-day') || '1', 10) || 1;
    var picked = String(input.value || '').trim();
    if (!picked) return;
    if (isPastTourDate(picked)) {
      input.value = input.getAttribute('data-prev-date') || '';
      input.classList.add('is-invalid');
      var sec = input.closest('[data-itin-day-section]');
      var dayFb = sec ? sec.querySelector('[data-itin-day-date-feedback]') : null;
      if (dayFb) {
        dayFb.textContent = 'Choose a tour date that is today or later.';
        dayFb.hidden = false;
      }
      return;
    }
    saveDayDate(day, picked);
  });

  function turnOffMultiday(dropLaterStops) {
    multiEl.checked = false;
    var endWrap = document.querySelector('[data-itin-end-wrap]');
    if (endWrap) endWrap.classList.add('d-none');
    syncAddDayPicker();
    saveSchedule({ structureOnly: true, dropLaterStops: !!dropLaterStops }).then(function (data) {
      if (!data || !data.ok) {
        multiEl.checked = true;
        if (endWrap) endWrap.classList.remove('d-none');
        syncAddDayPicker();
        return;
      }
      if (dropLaterStops) window.location.reload();
    });
  }

  syncAddDayPicker();

  if (dateEl) {
    dateEl.min = tourDateMin();
    dateEl.addEventListener('change', function () {
      if (isPastTourDate(dateEl.value)) {
        dateEl.value = dateEl.getAttribute('data-prev-date') || '';
        showDateError(document.body.classList.contains('package-maker')
          ? 'The package tour date must be at least 2 days from today.'
          : 'Tours cannot be booked for today. Choose tomorrow or a later date.');
        return;
      }
      syncAddDayPicker();
      if (typeof syncPackageDateChoice === 'function') syncPackageDateChoice();
      saveSchedule({});
    });
    dateEl.addEventListener('input', syncAddDayPicker);
  }
  var touristDate = document.getElementById('packageDateTourist');
  if (touristDate && dateEl) {
    function cutoffBeforeTour(ymd) {
      var parts = String(ymd || '').split('-');
      if (parts.length !== 3) return '';
      var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
      if (isNaN(dt.getTime())) return '';
      dt.setDate(dt.getDate() - 2);
      var month = String(dt.getMonth() + 1).padStart(2, '0');
      var day = String(dt.getDate()).padStart(2, '0');
      return dt.getFullYear() + '-' + month + '-' + day;
    }
    function syncPackageDateChoice() {
      var closeEl = document.getElementById('packageCloseDate');
      dateEl.disabled = touristDate.checked;
      if (closeEl) {
        var latest = touristDate.checked ? '' : cutoffBeforeTour(dateEl.value);
        closeEl.disabled = touristDate.checked;
        if (latest) closeEl.max = latest;
        else closeEl.removeAttribute('max');
        var todayParts = String(form.getAttribute('data-itin-today') || '').split('-');
        if (todayParts.length === 3) {
          var todayDt = new Date(Number(todayParts[0]), Number(todayParts[1]) - 1, Number(todayParts[2]));
          todayDt.setDate(todayDt.getDate() - 1);
          var tMonth = String(todayDt.getMonth() + 1).padStart(2, '0');
          var tDay = String(todayDt.getDate()).padStart(2, '0');
          closeEl.min = todayDt.getFullYear() + '-' + tMonth + '-' + tDay;
        }
        if (touristDate.checked) closeEl.value = '';
      }
      if (touristDate.checked) clearDateError();
    }
    touristDate.addEventListener('change', function () {
      syncPackageDateChoice();
      if (touristDate.checked) {
        ['stayRideModal', 'ridePickModal', 'guideBookModal', 'packageNoGuideModal'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el && window.bootstrap) {
            var inst = bootstrap.Modal.getInstance(el);
            if (inst) inst.hide();
          }
        });
      }
      saveSchedule({});
    });
    dateEl.addEventListener('change', syncPackageDateChoice);
    syncPackageDateChoice();
  }
  var packageCloseDate = document.getElementById('packageCloseDate');
  if (packageCloseDate) {
    packageCloseDate.addEventListener('change', function () {
      saveSchedule({});
    });
  }
  if (multiEl) {
    multiEl.addEventListener('change', function () {
      if (!multiEl.checked && lastSavedMulti && laterDaysHaveStops() && window.CulTourItinConfirm) {
        multiEl.checked = true;
        var wrap = document.querySelector('[data-itin-end-wrap]');
        if (wrap) wrap.classList.remove('d-none');
        window.CulTourItinConfirm.open('multiday', null, {
          onProceed: function () {
            turnOffMultiday(false);
          },
          onAlt: function () {
            turnOffMultiday(true);
          }
        });
        return;
      }
      syncAddDayPicker();
      saveSchedule({ structureOnly: true });
    });
  }
  function maxTourDays() {
    return parseInt(dayCountEl && dayCountEl.getAttribute('data-max-days') ? dayCountEl.getAttribute('data-max-days') : '30', 10) || 30;
  }
  function selectDayCount(n) {
    var value = String(n);
    var existing = null;
    Array.prototype.forEach.call(dayCountEl.options, function (opt) {
      if (opt.value === value) existing = opt;
    });
    if (!existing) {
      existing = document.createElement('option');
      existing.value = value;
      existing.textContent = n + ' days';
      var more = dayCountEl.querySelector('option[value="more"]');
      if (more) dayCountEl.insertBefore(existing, more);
      else dayCountEl.appendChild(existing);
    }
    dayCountEl.value = value;
    dayCountEl.setAttribute('data-prev', value);
  }
  if (dayCountEl && dayCountEl.value !== 'more') {
    dayCountEl.setAttribute('data-prev', dayCountEl.value || '2');
  }
  var moreDaysCard = document.getElementById('itinMoreDaysCard');
  var moreDaysInput = document.getElementById('itinMoreDaysInput');
  var moreDaysWarn = moreDaysCard ? moreDaysCard.querySelector('[data-itin-more-days-warn]') : null;
  var moreDaysApplied = false;
  function moreDaysModal() {
    if (!moreDaysCard || !window.bootstrap || !bootstrap.Modal) return null;
    return bootstrap.Modal.getOrCreateInstance(moreDaysCard);
  }
  function showMoreDaysWarn(message) {
    if (moreDaysInput) moreDaysInput.classList.add('is-invalid');
    if (!moreDaysWarn) return;
    moreDaysWarn.textContent = message;
    moreDaysWarn.hidden = false;
  }
  function clearMoreDaysWarn() {
    if (moreDaysInput) moreDaysInput.classList.remove('is-invalid');
    if (!moreDaysWarn) return;
    moreDaysWarn.textContent = '';
    moreDaysWarn.hidden = true;
  }
  function closeMoreDaysCard() {
    clearMoreDaysWarn();
    var modal = moreDaysModal();
    if (modal) modal.hide();
  }
  function applyMoreDays() {
    var n = parseInt(String(moreDaysInput ? moreDaysInput.value : '').trim(), 10);
    if (!n || n < 2) {
      showMoreDaysWarn('Enter at least 2 days.');
      if (moreDaysInput) moreDaysInput.focus();
      return;
    }
    if (n > maxTourDays()) {
      showMoreDaysWarn('A tour can be up to ' + maxTourDays() + ' days.');
      if (moreDaysInput) moreDaysInput.focus();
      return;
    }
    moreDaysApplied = true;
    selectDayCount(n);
    closeMoreDaysCard();
    syncAddDayPicker();
    saveSchedule({ structureOnly: true });
  }
  function openMoreDaysCard() {
    var prev = parseInt(dayCountEl.getAttribute('data-prev') || '2', 10) || 2;
    dayCountEl.value = String(prev);
    if (!moreDaysCard) return;
    if (moreDaysInput) {
      moreDaysInput.max = String(maxTourDays());
      moreDaysInput.value = String(prev > 7 ? prev : 8);
    }
    clearMoreDaysWarn();
    moreDaysApplied = false;
    var modal = moreDaysModal();
    if (modal) modal.show();
  }
  if (moreDaysCard) {
    moreDaysCard.addEventListener('shown.bs.modal', function () {
      if (!moreDaysInput) return;
      moreDaysInput.focus();
      moreDaysInput.select();
    });
    moreDaysCard.addEventListener('hidden.bs.modal', function () {
      if (!moreDaysApplied && dayCountEl) {
        dayCountEl.value = dayCountEl.getAttribute('data-prev') || '2';
      }
      moreDaysApplied = false;
      clearMoreDaysWarn();
    });
    var moreDaysOk = moreDaysCard.querySelector('[data-itin-more-days-ok]');
    var moreDaysCancel = moreDaysCard.querySelector('[data-itin-more-days-cancel]');
    if (moreDaysOk) moreDaysOk.addEventListener('click', applyMoreDays);
    if (moreDaysCancel) moreDaysCancel.addEventListener('click', function () {
      moreDaysApplied = false;
      closeMoreDaysCard();
    });
    if (moreDaysInput) {
      moreDaysInput.addEventListener('input', clearMoreDaysWarn);
      moreDaysInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyMoreDays();
        }
      });
    }
  }
  if (dayCountEl) {
    dayCountEl.addEventListener('change', function () {
      if (dayCountEl.value !== 'more') {
        dayCountEl.setAttribute('data-prev', dayCountEl.value);
        syncAddDayPicker();
        saveSchedule({ structureOnly: true });
        return;
      }
      openMoreDaysCard();
    });
  }

  function finishDayMeetupClock(clockEl) {
    var day = parseInt(clockEl.getAttribute('data-day') || '1', 10) || 1;
    if (day === 1) {
      syncMeetupHidden();
      paintMeetupCard();
    }
    return window.itinSaveDayMeetupTime(day, composeDayMeetupValue(day));
  }

  document.addEventListener('change', function (e) {
    var clockEl = e.target.closest && e.target.closest('[data-itin-day-meetup-clock]');
    if (!clockEl) return;
    var day = parseInt(clockEl.getAttribute('data-day') || '1', 10) || 1;
    if (day === 1) {
      syncMeetupHidden();
      paintMeetupCard();
    }
    refreshPastMeetupWarning();
    applyMeetupHourWindow(clockEl);
    if (clockEl.classList.contains('is-invalid')) return;
    window.itinSaveDayMeetupTime(day, composeDayMeetupValue(day));
  });
  document.addEventListener('change', function (e) {
    var dateEl = e.target.closest && e.target.closest('[data-itin-day-date-input]');
    if (!dateEl) return;
    var section = dateEl.closest('[data-itin-day-section]');
    var day = section ? section.getAttribute('data-itin-day-section') : '';
    var clockEl = day ? document.querySelector('[data-itin-day-meetup="' + day + '"] [data-itin-day-meetup-clock]') : null;
    if (clockEl) applyMeetupHourWindow(clockEl);
  });
  syncMeetupHidden();
  paintMeetupCard();

  function syncDayMeetupHiddens() {
    form.querySelectorAll('input[name^="day_meetup"]').forEach(function (el) {
      el.parentNode.removeChild(el);
    });
    document.querySelectorAll('[data-itin-day-meetup]').forEach(function (card) {
      var day = parseInt(card.getAttribute('data-itin-day-meetup') || '0', 10);
      if (!day) return;
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'day_meetup[' + day + ']';
      inp.value = composeDayMeetupValue(day);
      form.appendChild(inp);
    });
  }

  var autoBtn = document.querySelector('[data-itin-auto-generate]');
  if (autoBtn) {
    autoBtn.addEventListener('click', function (e) {
      e.preventDefault();
      syncMeetupHidden();
      syncDayMeetupHiddens();
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
    var raw = String(input.value || '').trim();
    var title = raw || 'Unnamed';
    if (title === lastSaved) {
      if (!raw) input.value = '';
      return;
    }
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
      var isPlaceholder = !lastSaved || String(lastSaved).toLowerCase() === 'unnamed';
      input.value = isPlaceholder ? '' : lastSaved;
    }).catch(function () {});
  }

  input.addEventListener('input', function () {
    input.classList.remove('is-invalid');
    if (input.setCustomValidity) input.setCustomValidity('');
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
window.CulTourItin = window.CulTourItin || {};
window.CulTourItin.places = <?= json_encode($placesForJs, JSON_UNESCAPED_UNICODE) ?>;
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
  var closeBtn = root.querySelector('[data-itin-rich-close]');
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-itin-rich-tab]'));
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
    return String(s == null ? ''
      : s)
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
    document.querySelectorAll('.itin-place-dropzone.is-over, .itin-item--meetup.is-over').forEach(function (el) {
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

  var meetupPickDay = null;
  var headTitle = root.querySelector('.itin-rich-picker__head-title');
  var headHint = root.querySelector('.itin-rich-picker__head-hint');

  function setMeetupPickMode(day) {
    meetupPickDay = day == null ? null : (parseInt(day, 10) || 1);
    if (headTitle) {
      headTitle.textContent = meetupPickDay
        ? ('Meetup place · Day ' + meetupPickDay)
        : 'Choose a place';
    }
    if (headHint) {
      headHint.textContent = meetupPickDay
        ? 'Drag a place onto the meetup card, or tap to set it'
        : 'Drag a place to Route stops';
    }
    root.classList.toggle('is-meetup-pick', !!meetupPickDay);
    document.body.classList.toggle('itin-meetup-picking', !!meetupPickDay);
    document.querySelectorAll('[data-itin-day-meetup]').forEach(function (card) {
      var on = meetupPickDay != null && String(card.getAttribute('data-itin-day-meetup')) === String(meetupPickDay);
      card.classList.toggle('is-meetup-target', on);
    });
  }

  function saveDayMeetup(day, key, mode, timeDisplay) {
    var form = document.getElementById('itinEditScheduleForm');
    var iid = form && form.querySelector('[name="itinerary_id"]');
    var csrf = form && (form.querySelector('[name="csrf_token"]') || form.querySelector('input[type="hidden"][name*="csrf"]'));
    if (!iid) return Promise.resolve({ ok: false });
    var body = new FormData();
    body.set('action', 'update_day_meetup');
    body.set('itinerary_id', iid.value);
    body.set('day_number', String(day));
    if (csrf) body.set(csrf.name, csrf.value);
    if (mode === 'port') body.set('use_port', '1');
    else if (mode === 'clear') body.set('clear', '1');
    else if (key) body.set('stop_key', key);
    if (timeDisplay) body.set('meetup_time', String(timeDisplay));
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: body,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (!data || !data.ok) {
        if (data && data.message) alert(data.message);
        return data || { ok: false };
      }
      paintDayMeetupCard(day, data.meetup || null);
      // Soft reload so stop travel times match new meetup origin
      window.location.reload();
      return data;
    }).catch(function () { return { ok: false }; });
  }

  function paintDayMeetupCard(day, meetup) {
    var card = document.querySelector('[data-itin-day-meetup="' + day + '"]');
    if (!card || !meetup) return;
    var nameEl = card.querySelector('[data-itin-meetup-place-name]');
    if (nameEl) nameEl.textContent = meetup.label || 'Meetup not set';
    if (typeof window.itinSyncMeetupPlaceFeedback === 'function') {
      window.itinSyncMeetupPlaceFeedback();
    }
    if (typeof window.itinPaintDayMeetupClock === 'function') {
      window.itinPaintDayMeetupClock(day, meetup.meetup_display || '');
    } else {
      var timeEl = card.querySelector('[data-itin-meetup-card-label]');
      if (timeEl) {
        var t = String(meetup.meetup_display || '').trim();
        timeEl.textContent = t ? ('Meetup time · ' + t) : 'Meetup time not set';
      }
    }
    if (Number(day) === 1) {
      var side = document.querySelector('[data-itin-sidebar-meetup-place]');
      if (side) side.textContent = meetup.label || 'Ormoc City Port';
    }
    var kind = String(meetup.kind || '');
    var placeLine = card.querySelector('.itin-meetup-place-line');
    var editPlace = card.querySelector('[data-itin-edit-meetup-place]');
    var portLink = placeLine && placeLine.querySelector('[data-itin-use-port]');
    if (kind === 'port') {
      if (portLink) portLink.remove();
    } else if (!portLink && placeLine && editPlace) {
      portLink = document.createElement('button');
      portLink.type = 'button';
      portLink.className = 'itin-meetup-place-edit';
      portLink.setAttribute('data-itin-use-port', '');
      portLink.setAttribute('data-day', String(day));
      portLink.textContent = 'Use port';
      editPlace.insertAdjacentElement('afterend', portLink);
    }
    var hasPlace = !!meetup.has_place;
    var head = card.querySelector('.itin-meetup-card__head');
    var menu = card.querySelector('.itin-meetup-menu');
    if (hasPlace) {
      if (!menu && head) {
        menu = document.createElement('div');
        menu.className = 'itin-meetup-menu';
        menu.innerHTML =
          '<button type="button" class="itin-meetup-menu__btn" data-itin-meetup-menu-btn aria-expanded="false" aria-haspopup="true" aria-label="Meetup place options">' +
            '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i>' +
          '</button>' +
          '<div class="itin-meetup-menu__panel itin-meetup-place-actions" hidden>' +
            '<button type="button" class="itin-meetup-menu__opt" data-itin-clear-meetup-place data-day="' + String(day) + '">Remove</button>' +
          '</div>';
        head.appendChild(menu);
      } else if (menu) {
        var panel = menu.querySelector('.itin-meetup-place-actions') || menu;
        var clearBtn = menu.querySelector('[data-itin-clear-meetup-place]');
        if (!clearBtn) {
          clearBtn = document.createElement('button');
          clearBtn.type = 'button';
          clearBtn.className = 'itin-meetup-menu__opt';
          clearBtn.setAttribute('data-itin-clear-meetup-place', '');
          clearBtn.textContent = 'Remove';
          panel.appendChild(clearBtn);
        }
        clearBtn.setAttribute('data-day', String(day));
        menu.querySelectorAll('[data-itin-use-port]').forEach(function (btn) { btn.remove(); });
      }
    } else if (menu) {
      menu.remove();
    }
  }

  window.itinOpenMeetupPlacePicker = function (day) {
    setMeetupPickMode(day);
    setOpen(true);
  };
  window.itinResetMeetupPlace = function (day) {
    return saveDayMeetup(day, '', 'clear');
  };
  window.itinUsePortMeetup = function (day) {
    return saveDayMeetup(day, 'port', 'port');
  };
  window.itinClearMeetupPlace = function (day) {
    return saveDayMeetup(day, '', 'clear');
  };
  window.itinSaveMeetupDrop = function (key, day, timeDisplay) {
    var go = function () { return saveDayMeetup(day, key, 'place', timeDisplay); };
    if (Number(day) === 1 && timeDisplay && window.itinApplyMeetupTime) {
      return window.itinApplyMeetupTime(timeDisplay).then(function (data) {
        if (data && data.ok === false) return data;
        return go();
      });
    }
    return go();
  };

  function setOpen(next) {
    open = !!next;
    panel.classList.toggle('d-none', !open);
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    root.classList.toggle('is-open', open);
    root.classList.toggle('is-picking', open);
    setDropReady(open && !meetupPickDay);
    if (!open) {
      stopPointerDrag();
      setMeetupPickMode(null);
    }
    if (open && searchEl) {
      searchEl.focus();
    }
  }

  function filtered() {
    var q = String(query || '').trim().toLowerCase();
    return allPlaces.filter(function (p) {
      if (!p) return false;
      if (meetupPickDay == null && p.stopExcluded) return false;
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
    if (p.minVisit && window.itinVisitHm) bits.push('At least ' + window.itinVisitHm.label(p.minVisit));
    if (p.visitLimit && window.itinVisitHm) bits.push('Visit up to ' + window.itinVisitHm.label(p.visitLimit));
    else if (!p.minVisit && p.defaultVisit) bits.push('~' + p.defaultVisit + ' min');
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
    var shownFavHead = false;
    var shownAllHead = false;
    rows.forEach(function (p) {
      if (p.favorite && !shownFavHead) {
        html += '<div class="itin-rich-picker__section">Favorites</div>';
        shownFavHead = true;
      }
      if (!p.favorite && shownFavHead && !shownAllHead) {
        html += '<div class="itin-rich-picker__section">All places</div>';
        shownAllHead = true;
      }
      var coverStyle = p.cover
        ? 'background-image:url(\'' + String(p.cover).replace(/'/g, '%27') + '\')'
        : '';
      html +=
        '<div class="itin-rich-picker__card' +
        (hidden && hidden.value === p.key ? ' is-selected' : '') +
        '" data-itin-rich-key="' +
        esc(p.key) +
        '" role="option" tabindex="0" aria-selected="' +
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
        (p.favorite ? ' <i class="bi bi-heart-fill itin-rich-picker__fav" aria-hidden="true"></i>' : '') +
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
        '</span>' +
        '<span class="itin-rich-picker__grip" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>' +
        '</div>';
    });
    listEl.innerHTML = html;
  }

  function applySelection(key) {
    if (meetupPickDay != null) {
      var day = meetupPickDay;
      openMeetupDropModal(key, day);
      return;
    }
    var place = places[key || ''];
    if (!hidden) return;
    hidden.value = place ? key : '';
    if (titleEl) titleEl.textContent = place ? place.name : 'Choose a place…';
    if (subEl) {
      subEl.textContent = place
        ? metaBits(place) || 'Place'
        : 'Places';
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
      setMeetupPickMode(null);
      setOpen(!open);
      if (open) renderList();
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      setOpen(false);
    });
  }

  function closeMeetupMenus() {
    document.querySelectorAll('.itin-meetup-menu.is-open').forEach(function (openMenu) {
      openMenu.classList.remove('is-open');
      var b = openMenu.querySelector('[data-itin-meetup-menu-btn]');
      var p = openMenu.querySelector('.itin-meetup-menu__panel');
      if (b) b.setAttribute('aria-expanded', 'false');
      if (p) p.hidden = true;
    });
  }

  document.addEventListener('click', function (e) {
    var menuBtn = e.target.closest('[data-itin-meetup-menu-btn]');
    if (menuBtn) {
      e.preventDefault();
      e.stopPropagation();
      var menu = menuBtn.closest('.itin-meetup-menu');
      var willOpen = menu && !menu.classList.contains('is-open');
      closeMeetupMenus();
      if (willOpen) {
        menu.classList.add('is-open');
        menuBtn.setAttribute('aria-expanded', 'true');
        var panel = menu.querySelector('.itin-meetup-menu__panel');
        if (panel) panel.hidden = false;
      }
      return;
    }
    if (!e.target.closest('.itin-meetup-menu')) closeMeetupMenus();
    var editBtn = e.target.closest('[data-itin-edit-meetup-place]');
    if (editBtn) {
      e.preventDefault();
      closeMeetupMenus();
      var day = editBtn.getAttribute('data-day') || '1';
      if (typeof window.itinOpenMeetupPlacePicker === 'function') {
        window.itinOpenMeetupPlacePicker(day);
        renderList();
      }
      return;
    }
    var resetBtn = e.target.closest('[data-itin-reset-meetup-place], [data-itin-clear-meetup-place]');
    if (resetBtn) {
      e.preventDefault();
      closeMeetupMenus();
      var dayR = resetBtn.getAttribute('data-day') || '1';
      if (typeof window.itinClearMeetupPlace === 'function') {
        window.itinClearMeetupPlace(dayR);
      }
      return;
    }
    var portBtn = e.target.closest('[data-itin-use-port]');
    if (portBtn) {
      e.preventDefault();
      closeMeetupMenus();
      var dayP = portBtn.getAttribute('data-day') || '1';
      if (typeof window.itinUsePortMeetup === 'function') {
        window.itinUsePortMeetup(dayP);
      }
    }
  });

  function dropDayFrom(el) {
    if (!el || !el.closest) return '1';
    var meetup = el.closest('[data-itin-day-meetup]');
    if (meetup && meetup.getAttribute('data-itin-day-meetup')) return meetup.getAttribute('data-itin-day-meetup');
    var zone = el.closest('[data-itin-place-drop]');
    if (zone && zone.getAttribute('data-itin-day')) return zone.getAttribute('data-itin-day');
    var dayEl = el.closest('[data-itin-day]');
    if (dayEl && dayEl.getAttribute('data-itin-day')) return dayEl.getAttribute('data-itin-day');
    var section = el.closest('[data-itin-day-section]');
    if (section && section.getAttribute('data-itin-day-section')) return section.getAttribute('data-itin-day-section');
    return '1';
  }

  function openMeetupDropModal(key, day) {
    var modalEl = document.getElementById('itinMeetupDropModal');
    var placeEl = modalEl ? modalEl.querySelector('[data-itin-meetup-drop-place]') : null;
    var clockEl = document.getElementById('itinMeetupDropClock');
    var place = places[key] || {};
    if (placeEl) {
      placeEl.textContent = 'What time will you meet at ' + (place.name || 'this place') + '?';
    }
    var cur = '';
    var dayClock = document.querySelector('[data-itin-day-meetup="' + String(day) + '"] [data-itin-day-meetup-clock]');
    if (dayClock && /^\d{2}:\d{2}$/.test(String(dayClock.value || '').trim())) {
      cur = String(dayClock.value).trim();
    }
    if (!cur) {
      var dayCard = document.querySelector('[data-itin-day-meetup="' + String(day) + '"] [data-itin-meetup-card-label]');
      if (dayCard) {
        var fromCard = String(dayCard.textContent || '').replace(/^Meetup time\s*·\s*/i, '').trim();
        if (fromCard && fromCard.toLowerCase() !== 'meetup time not set') cur = fromCard;
      }
    }
    if (!cur && String(day) === '1') {
      var existing = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
      cur = existing ? String(existing.value || '').trim() : '';
    }
    var time24 = /^\d{2}:\d{2}$/.test(cur) ? cur : (window.itinDisplayToTime24 ? window.itinDisplayToTime24(cur) : '');
    if (clockEl) {
      clockEl.classList.remove('is-invalid');
      clockEl.value = time24;
      var win = window.itinGuideHourWindows && window.itinApplyMeetupHourWindow
        ? (function () {
            var dateEl = document.querySelector('[data-itin-day-section="' + String(day) + '"] [data-itin-day-date-input]');
            var ymd = dateEl ? String(dateEl.value || '').trim() : '';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
            return window.itinGuideHourWindows[String(new Date(ymd + 'T12:00:00').getDay())] || null;
          })()
        : null;
      if (win) {
        clockEl.min = win.min;
        clockEl.max = win.max;
      } else {
        clockEl.removeAttribute('min');
        clockEl.removeAttribute('max');
      }
    }
    if (!modalEl) {
      saveDayMeetup(day, key, 'place');
      return;
    }
    modalEl._dropKey = key;
    modalEl._dropDay = day || '1';
    var dropClock = document.getElementById('itinMeetupDropClock');
    if (dropClock) dropClock.setAttribute('data-day', String(day || '1'));
    setMeetupPickMode(null);
    setOpen(false);
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      setTimeout(function () { if (clockEl) clockEl.focus(); }, 250);
    } else {
      saveDayMeetup(day, key, 'place');
    }
  }

  function applyMeetupDrop(key, day) {
    if (!key || !places[key]) return;
    openMeetupDropModal(key, day);
  }

  function addDroppedPlace(key, day) {
    if (!key || !places[key]) return;
    applySelection(key);
    var form = document.getElementById('itinAddStopForm');
    if (!form) return;
    var dayEl = document.getElementById('itinAddDayNumber');
    if (dayEl && day) dayEl.value = String(day);
    setOpen(false);
    openVisitDropModal(key, day);
  }

  function finishPlaceDrop(key, overEl) {
    if (!key || !overEl) return;
    var meetupCard = overEl.closest ? overEl.closest('[data-itin-day-meetup]') : null;
    if (meetupPickDay != null) {
      var day = meetupCard
        ? (meetupCard.getAttribute('data-itin-day-meetup') || String(meetupPickDay))
        : String(meetupPickDay);
      applyMeetupDrop(key, day);
      return;
    }
    if (meetupCard) {
      applyMeetupDrop(key, meetupCard.getAttribute('data-itin-day-meetup') || '1');
      return;
    }
    addDroppedPlace(key, dropDayFrom(overEl));
  }

  function openVisitDropModal(key, day) {
    var modalEl = document.getElementById('itinVisitDropModal');
    var placeEl = modalEl ? modalEl.querySelector('[data-itin-visit-drop-place]') : null;
    var clockEl = document.getElementById('itinVisitDropClock');
    var place = places[key] || {};
    if (placeEl) {
      placeEl.textContent = 'How long will you stay at ' + (place.name || 'this stop') + '?';
    }
    if (clockEl) {
      clockEl.value = '';
      clockEl.classList.remove('is-invalid');
      if (window.itinVisitHm) window.itinVisitHm.paintUnit(clockEl);
    }
    var hintEl = modalEl ? modalEl.querySelector('[data-itin-visit-limit-hint]') : null;
    var warnEl = modalEl ? modalEl.querySelector('[data-itin-visit-limit-warn]') : null;
    if (window.itinVisitHm) {
      var hints = [];
      if (place.minVisit) hints.push('Minimum visit: ' + window.itinVisitHm.label(place.minVisit) + '.');
      if (place.visitLimit) hints.push('Visit duration: up to ' + window.itinVisitHm.label(place.visitLimit) + '.');
      window.itinVisitHm.showWarn(hintEl, hints.join(' '));
      window.itinVisitHm.showWarn(warnEl, '');
    }
    modalEl._dropKey = key;
    modalEl._dropName = place.name || '';
    modalEl._dropLimit = place.visitLimit || null;
    modalEl._dropMin = place.minVisit || null;
    modalEl._dropPrefill = place.minVisit || null;
    modalEl._dropDay = day || '1';
    if (modalEl && window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      setTimeout(function () { if (clockEl) clockEl.focus(); }, 250);
    } else if (minsEl) {
      minsEl.focus();
    }
  }

  function highlightFromPoint(x, y) {
    var ghost = drag.ghost;
    if (ghost) ghost.style.visibility = 'hidden';
    var el = document.elementFromPoint(x, y);
    if (ghost) ghost.style.visibility = '';
    clearDropOver();
    if (!el || !el.closest) return null;
    var meetup = el.closest('[data-itin-day-meetup]');
    if (meetup) {
      meetup.classList.add('is-over');
      return el;
    }
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
    document.body.classList.remove('itin-place-dragging');
    document.querySelectorAll('.itin-rich-picker__card.is-dragging').forEach(function (el) {
      el.classList.remove('is-dragging');
    });
    setTimeout(function () { didDrag = false; }, 0);
  }
  window.itinCancelPlaceDrag = stopPointerDrag;

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
    var x = e.clientX;
    var y = e.clientY;
    var overEl = wasDrag ? highlightFromPoint(x, y) : null;
    stopPointerDrag();
    if (wasDrag && overEl) finishPlaceDrop(key, overEl);
  }

  function onPointerCancel() {
    stopPointerDrag();
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
  minMessage: function (name, min, mins) {
    var floor = parseInt(min, 10);
    if (!Number.isFinite(floor) || floor <= 0 || !Number.isFinite(mins) || mins >= floor) return '';
    var label = window.itinVisitHm.label(floor);
    return (name || 'This place') + ' needs a minimum visit of ' + label + '. Set the stay to ' + label + ' or more.';
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
  var modalEl = document.getElementById('itinVisitDropModal');
  if (!modalEl) return;
  var clockEl = document.getElementById('itinVisitDropClock');
  var okBtn = modalEl.querySelector('[data-itin-visit-drop-ok]');
  if (clockEl && window.itinVisitHm) window.itinVisitHm.bind(clockEl);

  var limitWarnEl = modalEl.querySelector('[data-itin-visit-limit-warn]');

  function dropStayMessage() {
    if (!window.itinVisitHm || !clockEl) return 'Enter how long you will stay.';
    var raw = String(clockEl.value || '').trim();
    if (raw === '') return '';
    var n = window.itinVisitHm.total(clockEl);
    if (!Number.isFinite(n)) return 'Enter the visit time as hours and minutes, like 01:00.';
    var minMsg = window.itinVisitHm.minMessage(modalEl._dropName, modalEl._dropMin, n);
    if (minMsg) return minMsg;
    var limitMsg = window.itinVisitHm.limitMessage(modalEl._dropName, modalEl._dropLimit, n);
    if (limitMsg) return limitMsg;
    if (!window.itinVisitHm.ok(n)) return 'Visit time must be at least 15 minutes.';
    return '';
  }

  function refreshDropStay() {
    if (!window.itinVisitHm) return;
    var msg = dropStayMessage();
    var n = clockEl ? window.itinVisitHm.total(clockEl) : NaN;
    var blocked = msg !== '' || !window.itinVisitHm.ok(n);
    window.itinVisitHm.showWarn(limitWarnEl, msg);
    if (clockEl) clockEl.classList.toggle('is-invalid', msg !== '');
    if (okBtn) okBtn.disabled = blocked;
  }

  if (clockEl) {
    clockEl.addEventListener('input', refreshDropStay);
    clockEl.addEventListener('blur', refreshDropStay);
  }

  function submitDrop() {
    if (clockEl && window.itinVisitHm) clockEl.value = window.itinVisitHm.pad(clockEl.value);
    refreshDropStay();
    if (okBtn && okBtn.disabled) {
      if (clockEl) clockEl.focus();
      return;
    }
    var n = window.itinVisitHm.total(clockEl);
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
      var pre = modalEl._dropPrefill;
      clockEl.value = (pre && window.itinVisitHm) ? window.itinVisitHm.fromMinutes(pre) : '';
      clockEl.classList.remove('is-invalid');
      if (window.itinVisitHm) window.itinVisitHm.paintUnit(clockEl);
      if (typeof refreshDropStay === 'function') refreshDropStay();
      clockEl.focus();
    }
  });
})();
(function () {
  var modalEl = document.getElementById('itinMeetupDropModal');
  if (!modalEl) return;
  var clockEl = document.getElementById('itinMeetupDropClock');
  var okBtn = modalEl.querySelector('[data-itin-meetup-drop-ok]');

  function composeMeetupDrop() {
    var clock = clockEl ? String(clockEl.value || '').trim() : '';
    var m = clock.match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (h < 0 || h > 23 || !Number.isFinite(min) || min < 0 || min > 59) return '';
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(min).padStart(2, '0') + ' ' + ampm;
  }

  function submitMeetupDrop() {
    var timeDisplay = composeMeetupDrop();
    if (!timeDisplay) {
      if (clockEl) {
        clockEl.classList.add('is-invalid');
        clockEl.focus();
      }
      return;
    }
    var key = modalEl._dropKey;
    var day = modalEl._dropDay || '1';
    if (window.bootstrap && bootstrap.Modal) {
      var inst = bootstrap.Modal.getInstance(modalEl);
      if (inst) inst.hide();
    }
    if (typeof window.itinSaveMeetupDrop === 'function') {
      window.itinSaveMeetupDrop(key, day, timeDisplay);
    }
  }

  if (clockEl) {
    clockEl.addEventListener('input', function () {
      if (window.itinFormatMeetupClock) {
        var formatted = window.itinFormatMeetupClock(clockEl.value);
        if (formatted !== clockEl.value) clockEl.value = formatted;
      }
      clockEl.classList.remove('is-invalid');
    });
    clockEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitMeetupDrop();
      }
    });
  }
  if (okBtn) okBtn.addEventListener('click', submitMeetupDrop);
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
    var minMsg = stop
      ? window.itinVisitHm.minMessage(stop.getAttribute('data-place-name'), stop.getAttribute('data-visit-min'), total)
      : '';
    var limitMsg = stop
      ? window.itinVisitHm.limitMessage(stop.getAttribute('data-place-name'), stop.getAttribute('data-visit-limit'), total)
      : '';
    var stayMsg = minMsg || limitMsg;
    window.itinVisitHm.showWarn(form.querySelector('[data-itin-visit-limit-warn]'), stayMsg);
    if (stayMsg) {
      e.preventDefault();
      if (clock) {
        clock.classList.add('is-invalid');
        clock.focus();
      }
    }
  });
  document.addEventListener('input', function (e) {
    var form = e.target.closest && e.target.closest('[data-itin-edit-time-form]');
    if (!form || !window.itinVisitHm) return;
    var clock = form.querySelector('[data-itin-visit-clock]');
    var stop = form.closest('[data-itin-stop]');
    var total = clock ? window.itinVisitHm.total(clock) : NaN;
    var typed = clock && String(clock.value || '').trim() !== '';
    var minMsg = typed && stop
      ? window.itinVisitHm.minMessage(stop.getAttribute('data-place-name'), stop.getAttribute('data-visit-min'), total)
      : '';
    var limitMsg = typed && stop
      ? window.itinVisitHm.limitMessage(stop.getAttribute('data-place-name'), stop.getAttribute('data-visit-limit'), total)
      : '';
    var msg = minMsg || limitMsg;
    if (!msg && typed && !window.itinVisitHm.ok(total)) msg = 'Visit time must be at least 15 minutes.';
    window.itinVisitHm.showWarn(form.querySelector('[data-itin-visit-limit-warn]'), msg);
    if (clock) clock.classList.toggle('is-invalid', msg !== '');
    var saveBtn = form.querySelector('button[type="submit"]');
    if (saveBtn) saveBtn.disabled = msg !== '' || !window.itinVisitHm.ok(total);
  });
})();
(function () {
  var addForm = document.getElementById('itinAddStopForm');
  if (!addForm) return;
  var allowInput = document.getElementById('itinAllowDuplicate');
  var stopSelect = addForm.querySelector('[name="stop_key"]');
  var daySelect = addForm.querySelector('[name="day_number"]');
  var visitInput = addForm.querySelector('[name="visit_minutes"]');
  var visitClock = addForm.querySelector('[data-itin-visit-clock]');
  var addBtn = document.getElementById('itinAddStopBtn') || addForm.querySelector('button[type="submit"]');
  var bypassDup = false;
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

  function stopsList() {
    return window.CulTourItinStops || [];
  }

  function placeName(key) {
    var list = stopsList();
    for (var i = 0; i < list.length; i++) {
      if (list[i].key === key) return list[i].name || 'This place';
    }
    var placesMap = (window.CulTourItin && window.CulTourItin.places) || {};
    if (placesMap[key] && placesMap[key].name) return placesMap[key].name;
    return 'This place';
  }

  function addLimitWarnEl() {
    var el = addForm.querySelector('[data-itin-visit-limit-warn]');
    if (el) return el;
    var anchor = visitClock ? (visitClock.closest('.itin-visit-clock-wrap') || visitClock) : null;
    if (!anchor || !anchor.parentNode) return null;
    el = document.createElement('div');
    el.className = 'form-text text-danger d-none';
    el.setAttribute('data-itin-visit-limit-warn', '');
    anchor.parentNode.insertBefore(el, anchor.nextSibling);
    return el;
  }
  if (visitClock) {
    visitClock.addEventListener('input', function () {
      if (window.itinVisitHm) window.itinVisitHm.showWarn(addLimitWarnEl(), '');
    });
  }

  addForm.addEventListener('submit', function (e) {
    if (!visitOk()) {
      e.preventDefault();
      e.stopPropagation();
      if (visitInput) {
        visitInput.classList.add('is-invalid');
        visitInput.focus();
      }
      return;
    }
    if (window.itinVisitHm && stopSelect && stopSelect.value) {
      var placesAll = (window.CulTourItin && window.CulTourItin.places) || {};
      var picked = placesAll[String(stopSelect.value)] || {};
      var mins = parseInt(visitInput && visitInput.value, 10);
      var minMsg = window.itinVisitHm.minMessage(picked.name, picked.minVisit, mins);
      var limitMsg = window.itinVisitHm.limitMessage(picked.name, picked.visitLimit, mins);
      var stayMsg = minMsg || limitMsg;
      if (stayMsg) {
        e.preventDefault();
        e.stopPropagation();
        var warnEl = addLimitWarnEl();
        window.itinVisitHm.showWarn(warnEl, stayMsg);
        if (!warnEl || (visitClock && visitClock.offsetParent === null)) {
          if (window.CulTourItinConfirm) window.CulTourItinConfirm.open('adjacent', null, { title: 'Visit time', text: stayMsg });
          else alert(stayMsg);
        }
        if (visitClock) visitClock.classList.add('is-invalid');
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

  function applyStopUi(stopEl, index, arriveMins, leaveMins, travel, visit, opts) {
    opts = opts || {};
    var num = stopEl.querySelector('[data-itin-stop-num]');
    if (num) num.textContent = String(index);
    var arriveEl = stopEl.querySelector('[data-itin-arrive]');
    var leaveEl = stopEl.querySelector('[data-itin-leave]');
    var stayEl = stopEl.querySelector('[data-itin-stay]');
    var rangeEl = stopEl.querySelector('[data-itin-arrive-leave]');
    if (opts.hideArriveLeave) {
      if (rangeEl) rangeEl.hidden = true;
    } else {
      if (rangeEl) rangeEl.hidden = false;
      if (arriveEl) arriveEl.textContent = minsToLabel(arriveMins);
      if (leaveEl) leaveEl.textContent = minsToLabel(leaveMins);
    }
    if (stayEl) stayEl.textContent = (window.itinVisitHm && window.itinVisitHm.label)
      ? window.itinVisitHm.label(visit)
      : String(visit);
    if (!opts.skipTravel) setTravelWrap(stopEl, travel);
  }

  function dayMeetupMinsFromClock(dayNum) {
    var wrap = document.querySelector('[data-itin-day-meetup="' + String(dayNum) + '"]');
    var clock = wrap && wrap.querySelector('[data-itin-day-meetup-clock]');
    if (!clock || !String(clock.value || '').trim()) return null;
    var m = String(clock.value).match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return null;
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (!isFinite(h) || !isFinite(min) || h < 0 || h > 23 || min < 0 || min > 59) return null;
    return h * 60 + min;
  }

  function dayMeetupClockFilled(dayNum) {
    return dayMeetupMinsFromClock(dayNum) != null;
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
    var meetupMins = dayMeetupMinsFromClock(dayNum);
    if (meetupMins == null) {
      stops.forEach(function (stopEl, idx) {
        var visit = parseInt(stopEl.getAttribute('data-visit') || '60', 10) || 60;
        if (visit < 15) visit = 15;
        applyStopUi(stopEl, idx + 1, 0, 0, 0, visit, { hideArriveLeave: true, skipTravel: true });
      });
      return Promise.resolve([]);
    }
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

  window.itinReflowDayLive = function (dayNum) {
    var dayEl = document.querySelector('[data-itin-sortable][data-itin-day="' + String(dayNum) + '"]');
    if (!dayEl) return Promise.resolve([]);
    return reflowDayLive(dayEl);
  };

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
            var rangeEl = stopEl.querySelector('[data-itin-arrive-leave]');
            var dayEl = stopEl.closest('[data-itin-sortable]');
            var dayNum = dayEl ? parseInt(dayEl.getAttribute('data-itin-day') || '1', 10) : (parseInt(s.day, 10) || 1);
            if (num) num.textContent = String(s.index);
            if (!dayMeetupClockFilled(dayNum)) {
              if (rangeEl) rangeEl.hidden = true;
            } else {
              if (rangeEl) rangeEl.hidden = false;
              if (arriveEl) arriveEl.textContent = s.arrive;
              if (leaveEl) leaveEl.textContent = s.leave;
            }
            if (stayEl) stayEl.textContent = (window.itinVisitHm && window.itinVisitHm.label)
              ? window.itinVisitHm.label(s.visit)
              : String(s.visit);
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

  var stopSortables = [];
  window.itinSetStopSortEnabled = function (on) {
    stopSortables.forEach(function (s) {
      s.option('disabled', !on);
    });
  };

  document.querySelectorAll('[data-itin-sortable]').forEach(function (el) {
    if (el.children.length < 2) return;
    var itinId = el.getAttribute('data-itin-id');
    stopSortables.push(Sortable.create(el, {
      animation: 160,
      draggable: '.itin-item--draggable',
      filter: 'button, a, input, select, textarea, label, form',
      preventOnFilter: false,
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      ghostClass: 'sortable-ghost',
      chosenClass: 'itin-item--dragging',
      onStart: function () {
        if (typeof window.itinCancelPlaceDrag === 'function') window.itinCancelPlaceDrag();
      },
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
(function () {
  var bookBtn = document.getElementById('btnOpenGuideBook');
  var dateEl = document.getElementById('itinStartDate');
  var partyEl = document.getElementById('itinPartySize');
  var partyHidden = document.getElementById('guideBookPartySize');
  var itinId = <?= (int) ($view['id'] ?? 0) ?>;
  var pickedGuideKey = 'cultour_itin_picked_guide_' + itinId;
  var itinPickedGuide = null;
  var ownGuideId = <?= (int) ($ownGuideProfileId ?? 0) ?>;
  var serverDraftGuide = <?= json_encode($draftGuideClient ?? null, JSON_UNESCAPED_UNICODE) ?>;

  function guideInitials(name) {
    return String(name || '?').split(/\s+/).map(function (p) { return p.charAt(0); }).join('').slice(0, 2).toUpperCase() || '?';
  }
  function persistDraftGuide(id) {
    if (!itinId) return;
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'update_draft_guide');
    body.append('itinerary_id', String(itinId));
    body.append('guide_id', id ? String(id) : '0');
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: body,
      credentials: 'same-origin'
    }).catch(function () {});
  }
  function savePickedGuide(g) {
    if (!itinId) return;
    try {
      if (g && g.id) sessionStorage.setItem(pickedGuideKey, JSON.stringify(g));
      else sessionStorage.removeItem(pickedGuideKey);
    } catch (err) {}
    persistDraftGuide(g && g.id ? g.id : 0);
  }
  function clearPickedGuide(opts) {
    opts = opts || {};
    var leaving = itinPickedGuide || window._itinBusyGuideNotes;
    if (!opts.keepMeetupTimes) {
      revertGuideMeetupTimes(leaving);
    }
    itinPickedGuide = null;
    savePickedGuide(null);
    applyGuidePreview(null);
    var guideIdField = document.getElementById('guideBookGuideId');
    if (guideIdField) guideIdField.value = '';
  }
  window.itinClearPickedGuideKeepTimes = function () {
    clearPickedGuide({ keepMeetupTimes: true });
  };
  function loadPickedGuide() {
    if (!itinId) return null;
    try {
      var raw = sessionStorage.getItem(pickedGuideKey);
      if (!raw) return null;
      var g = JSON.parse(raw);
      return g && g.id ? g : null;
    } catch (err) {
      return null;
    }
  }
  function paintGuideDayBusyNotes(g) {
    if (g !== undefined) {
      window._itinBusyGuideNotes = (g && Array.isArray(g.busy_by_day)) ? g : null;
    }
    var source = window._itinBusyGuideNotes;
    var byDay = {};
    if (source && Array.isArray(source.busy_by_day)) {
      source.busy_by_day.forEach(function (row) {
        if (!row || row.day == null) return;
        byDay[String(row.day)] = row;
      });
    }
    document.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
      var day = sec.getAttribute('data-itin-day-section') || '1';
      var note = sec.querySelector('[data-itin-day-guide-busy]');
      if (!note) return;
      var row = byDay[day];
      if (row && row.label && !/earliest start/i.test(String(row.label))) {
        note.textContent = String(row.label).replace(/\s*\(\d+-min(?:ute)?s? buffer\)/i, '');
        note.classList.remove('d-none');
        note.hidden = false;
      } else {
        note.textContent = '';
        note.classList.add('d-none');
        note.hidden = true;
      }
    });
  }

  function displayToMins(text) {
    var m = String(text || '').match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
    if (!m) return null;
    var h = parseInt(m[1], 10);
    var min = parseInt(m[2], 10);
    if (h === 12) h = 0;
    if (m[3].toUpperCase() === 'PM') h += 12;
    return h * 60 + min;
  }

  function minsToDisplay(mins) {
    mins = Math.round(Number(mins));
    if (!isFinite(mins) || mins < 0) return '';
    mins = Math.max(0, Math.min(23 * 60 + 59, mins));
    var h24 = Math.floor(mins / 60);
    var m = mins % 60;
    var ampm = h24 >= 12 ? 'PM' : 'AM';
    var h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + ampm;
  }

  function currentDayMeetupMins(day) {
    var card = document.querySelector('[data-itin-day-meetup="' + day + '"]');
    var clock = card && card.querySelector('[data-itin-day-meetup-clock]');
    if (clock) {
      var clockVal = String(clock.value || '').trim();
      var cm = clockVal.match(/^(\d{1,2}):(\d{2})$/);
      if (cm) {
        var h24 = parseInt(cm[1], 10);
        var mins = parseInt(cm[2], 10);
        if (h24 >= 0 && h24 <= 23 && mins >= 0 && mins <= 59) return h24 * 60 + mins;
      }
    }
    var timeEl = card && card.querySelector('[data-itin-meetup-card-label]');
    var fromCard = displayToMins(timeEl ? timeEl.textContent : '');
    if (fromCard != null) return fromCard;
    if (Number(day) === 1) {
      var hidden = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
      return displayToMins(hidden ? hidden.value : '');
    }
    return null;
  }

  function busyFreeFromMins(row) {
    if (row && row.free_from != null && row.free_from !== '') {
      var n = parseInt(row.free_from, 10);
      if (isFinite(n) && n >= 0) return n;
    }
    var label = row && row.label ? String(row.label) : '';
    if (!/earliest start/i.test(label)) return null;
    return displayToMins(label);
  }

  var busyMeetupSaving = false;
  var autoMeetupKey = 'cultour_itin_auto_meetup_' + itinId;

  function loadAutoMeetup() {
    try {
      var raw = sessionStorage.getItem(autoMeetupKey);
      var o = raw ? JSON.parse(raw) : {};
      return o && typeof o === 'object' && !Array.isArray(o) ? o : {};
    } catch (err) {
      return {};
    }
  }

  function saveAutoMeetup(map) {
    try {
      if (!map || !Object.keys(map).length) sessionStorage.removeItem(autoMeetupKey);
      else sessionStorage.setItem(autoMeetupKey, JSON.stringify(map));
    } catch (err) {}
  }

  function rememberAutoMeetup(day, display) {
    var map = loadAutoMeetup();
    map[String(day)] = String(display || '');
    saveAutoMeetup(map);
  }

  function revertGuideMeetupTimes(g) {
    if (typeof window.itinSaveDayMeetupTime !== 'function') {
      saveAutoMeetup({});
      return;
    }
    var remembered = loadAutoMeetup();
    var toClear = {};
    Object.keys(remembered).forEach(function (day) {
      toClear[day] = true;
    });
    if (g && Array.isArray(g.busy_by_day)) {
      g.busy_by_day.forEach(function (row) {
        if (!row || row.day == null) return;
        var key = String(row.day);
        if (toClear[key]) return;
        var expected = busyFreeFromMins(row);
        var cur = currentDayMeetupMins(row.day);
        if (expected != null && cur != null && cur === expected) toClear[key] = true;
      });
    }
    var days = Object.keys(toClear);
    if (!days.length) return;
    var chain = Promise.resolve();
    days.forEach(function (day) {
      chain = chain.then(function () {
        return window.itinSaveDayMeetupTime(day, '');
      });
    });
    chain.then(function () { saveAutoMeetup({}); }, function () { saveAutoMeetup({}); });
  }

  function padYmd(y, m, d) {
    return String(y) + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
  }

  function addDaysYmdLocal(ymd, days) {
    var p = String(ymd || '').split('-');
    if (p.length !== 3) return '';
    var dt = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]) + (parseInt(days, 10) || 0));
    if (isNaN(dt.getTime())) return '';
    return padYmd(dt.getFullYear(), dt.getMonth() + 1, dt.getDate());
  }

  function manilaNowClock() {
    var now = new Date();
    return {
      today: padYmd(now.getFullYear(), now.getMonth() + 1, now.getDate()),
      mins: Math.max(0, Math.min(23 * 60 + 59, now.getHours() * 60 + now.getMinutes()))
    };
  }

  function dayVisitYmd(day) {
    var input = document.querySelector('[data-itin-day-section="' + String(day) + '"] [data-itin-day-date-input]');
    var ymd = input ? String(input.value || '').trim() : '';
    if (ymd) return ymd;
    var startEl = document.getElementById('itinStartDate');
    var start = startEl ? String(startEl.value || '').trim() : '';
    if (!start) return '';
    return addDaysYmdLocal(start, (parseInt(day, 10) || 1) - 1);
  }

  function queueMeetupJob(jobs, day, display) {
    var clock = document.querySelector('[data-itin-day-meetup="' + String(day) + '"] [data-itin-day-meetup-clock]');
    var clockEmpty = !clock || !String(clock.value || '').trim();
    var cur = clockEmpty ? null : currentDayMeetupMins(day);
    var clearing = !String(display || '').trim();
    if (clearing) {
      if (clockEmpty && cur == null) return;
      jobs.push({ day: day, display: '' });
      return;
    }
    var target = displayToMins(display);
    if (target == null) return;
    if (cur != null && cur >= target) return;
    jobs.push({ day: day, display: display });
  }

  function syncMeetupFromEarliest(source) {
    if (busyMeetupSaving) return;
    if (!source || !Array.isArray(source.busy_by_day)) return;
    if (typeof window.itinSaveDayMeetupTime !== 'function') return;
    var jobs = [];
    var nowClock = manilaNowClock();
    source.busy_by_day.forEach(function (row) {
      if (!row || row.day == null) return;
      var free = busyFreeFromMins(row);
      if (free == null) return;
      var ymd = dayVisitYmd(row.day);
      var pastEarliest = !!(ymd && nowClock.today && (
        ymd < nowClock.today || (ymd === nowClock.today && nowClock.mins > free)
      ));
      if (pastEarliest) {
        queueMeetupJob(jobs, row.day, '');
        return;
      }
      queueMeetupJob(jobs, row.day, minsToDisplay(free));
    });
    if (!jobs.length) return;
    busyMeetupSaving = true;
    var chain = Promise.resolve();
    jobs.forEach(function (job) {
      chain = chain.then(function () {
        return window.itinSaveDayMeetupTime(job.day, job.display).then(function (data) {
          if (data && data.ok) rememberAutoMeetup(job.day, job.display || '');
          return data;
        });
      });
    });
    chain.then(function () {
      busyMeetupSaving = false;
    }, function () {
      busyMeetupSaving = false;
    });
  }
  window.itinPaintGuideDayBusyNotes = paintGuideDayBusyNotes;

  function applyGuidePreview(g) {
    var preview = document.getElementById('itinGuidePreview');
    var nameEl = document.querySelector('[data-itin-guide-name]');
    var avatarWrap = document.querySelector('[data-itin-guide-avatar]');
    var detailsEl = document.querySelector('[data-itin-guide-details]');
    var phoneEl = document.querySelector('[data-itin-guide-phone]');
    var rateEl = document.querySelector('[data-itin-guide-rate]');
    var langEl = document.querySelector('[data-itin-guide-lang]');
    if (!preview || !nameEl || !avatarWrap) return;
    if (!g || !g.name) {
      preview.classList.add('is-empty');
      nameEl.textContent = 'No guide yet';
      avatarWrap.innerHTML = '<span data-itin-guide-initials>?</span>';
      if (detailsEl) detailsEl.classList.add('d-none');
      if (phoneEl) phoneEl.textContent = '—';
      if (rateEl) rateEl.textContent = '—';
      if (langEl) langEl.textContent = '—';
      var clearBtnOff = document.querySelector('[data-itin-guide-clear]');
      if (clearBtnOff) clearBtnOff.classList.add('d-none');
      paintGuideDayBusyNotes(null);
      return;
    }
    preview.classList.remove('is-empty');
    preview.classList.remove('is-invalid');
    var gufb = document.querySelector('[data-itin-guide-feedback]');
    if (gufb) gufb.hidden = true;
    nameEl.textContent = g.name;
    if (g.avatar) {
      avatarWrap.innerHTML = '<img src="' + g.avatar + '" alt="">';
    } else {
      avatarWrap.innerHTML = '<span data-itin-guide-initials>' + guideInitials(g.name) + '</span>';
    }
    if (detailsEl) detailsEl.classList.remove('d-none');
    if (phoneEl) phoneEl.textContent = g.phone || '—';
    if (rateEl) rateEl.textContent = g.rate ? (String(g.rate).indexOf('/day') >= 0 ? g.rate : g.rate + '/day') : '—';
    if (langEl) langEl.textContent = g.languages || '—';
    var clearBtnOn = document.querySelector('[data-itin-guide-clear]');
    if (clearBtnOn) clearBtnOn.classList.remove('d-none');
    paintGuideDayBusyNotes(g);
  }

  // Restore the guide saved on this draft (session, then the database).
  (function restorePickedGuide() {
    var preview = document.getElementById('itinGuidePreview');
    var showingBooked = preview && !preview.classList.contains('is-empty') && !(serverDraftGuide && serverDraftGuide.id);
    if (showingBooked) return;
    var stored = loadPickedGuide();
    if ((!stored || !stored.id) && serverDraftGuide && serverDraftGuide.id) {
      stored = serverDraftGuide;
    }
    if (!stored || !stored.id) return;
    if (ownGuideId && Number(stored.id) === ownGuideId) {
      itinPickedGuide = null;
      savePickedGuide(null);
      applyGuidePreview(null);
      return;
    }
    itinPickedGuide = stored;
    if (!(serverDraftGuide && Number(serverDraftGuide.id) === Number(stored.id))) {
      savePickedGuide(stored);
    }
    applyGuidePreview(stored);
  })();

  function syncPartySize() {
    if (!partyHidden) return false;
    var n = partyEl ? parseInt(String(partyEl.value || '').trim(), 10) : NaN;
    if (!n || n < 1 || n > 20) {
      return false;
    }
    partyHidden.value = String(n);
    return true;
  }
  function syncBookingDetailsPreview() {
    var guestsDd = document.querySelector('[data-itin-review="guests"]');
    var dateDd = document.querySelector('[data-itin-review="date"]');
    var meetupDd = document.querySelector('[data-itin-review="meetup"]');
    var titleDd = document.querySelector('[data-itin-review="title"]');
    var daysDd = document.querySelector('[data-itin-review="days"]');
    var guideDd = document.querySelector('[data-itin-review="guide"]');
    var titleInput = document.querySelector('#itinRenameForm input[name="title"]');
    if (guideDd) {
      guideDd.textContent = (itinPickedGuide && itinPickedGuide.name) ? String(itinPickedGuide.name) : 'Not chosen';
    }
    if (titleDd && titleInput) {
      var t = String(titleInput.value || '').trim();
      titleDd.textContent = (t !== '' && t.toLowerCase() !== 'unnamed') ? t : 'Not set';
    }
    if (guestsDd && partyEl) {
      var n = parseInt(String(partyEl.value || '').trim(), 10);
      var minEl = document.getElementById('packageMinPax');
      var minN = minEl ? parseInt(String(minEl.value || '').trim(), 10) : 0;
      if (document.body.classList.contains('package-maker')) {
        guestsDd.textContent = (minN >= 1 ? String(minN) : '—') + ' – ' + (n >= 1 ? String(n) : '—');
      } else {
        guestsDd.textContent = n && n >= 1 ? String(n) : '—';
      }
    }
    if (dateDd && dateEl) {
      var raw = String(dateEl.value || '').trim();
      if (document.body.classList.contains('package-maker') && packageTouristPicksDate()) {
        dateDd.textContent = 'Tourist chooses the date';
        raw = '';
      }
      if (!raw && dateDd.textContent !== 'Tourist chooses the date') {
        dateDd.textContent = 'Not set';
      } else if (raw) {
        try {
          var d = new Date(raw + 'T12:00:00');
          dateDd.textContent = isNaN(d.getTime())
            ? raw
            : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (err) {
          dateDd.textContent = raw;
        }
      }
    }
    if (meetupDd) {
      var meetupHidden = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
      var meetupClockEl = document.querySelector('[data-itin-day-meetup="1"] [data-itin-day-meetup-clock]');
      var mt = '';
      if (meetupClockEl) {
        var clock = String(meetupClockEl.value || '').trim();
        var m = clock.match(/^(\d{1,2}):(\d{2})$/);
        if (m) {
          var h = parseInt(m[1], 10);
          var min = parseInt(m[2], 10);
          if (h >= 0 && h <= 23 && min >= 0 && min <= 59) {
            var ampm = h >= 12 ? 'PM' : 'AM';
            var h12 = h % 12;
            if (h12 === 0) h12 = 12;
            mt = h12 + ':' + String(min).padStart(2, '0') + ' ' + ampm;
          }
        }
      } else if (meetupHidden) {
        mt = String(meetupHidden.value || '').trim();
      }
      meetupDd.textContent = (mt || 'Not set') + ' · Ormoc City Port';
    }
    if (daysDd) {
      var multi = document.getElementById('itinEditMultiday');
      var daySel = document.querySelector('[data-itin-day-count]');
      var days = 1;
      if (multi && multi.checked && daySel) {
        days = parseInt(String(daySel.value || '2'), 10) || 2;
      }
      daysDd.textContent = days + (days === 1 ? ' day' : ' days');
    }
    ['stay', 'ride'].forEach(function (key) {
      var dd = document.querySelector('[data-itin-review="' + key + '"]');
      if (!dd) return;
      if (key === 'stay' && document.querySelector('input[name="stayRideAcc"][value="0"]:checked')) {
        dd.textContent = 'Not needed';
        return;
      }
      if (key === 'stay' && window.itinStayQuote && window.itinStayQuote.review) {
        dd.textContent = window.itinStayQuote.review;
        return;
      }
      if (key === 'ride' && document.querySelector('input[name="stayRideTrn"][value="0"]:checked')) {
        dd.textContent = 'Not needed';
        return;
      }
      if (key === 'ride' && window.itinRideQuote && window.itinRideQuote.review) {
        dd.textContent = window.itinRideQuote.review;
        return;
      }
      dd.textContent = stayRideReviewText(key === 'stay' ? 'stayRideAcc' : 'stayRideTrn');
    });
    var closeDd = document.querySelector('[data-itin-review="close"]');
    var priceDd = document.querySelector('[data-itin-review="price"]');
    if (closeDd) {
      var closeEl = document.getElementById('packageCloseDate');
      var closeRaw = closeEl && !closeEl.disabled ? String(closeEl.value || '').trim() : '';
      if (!closeRaw) {
        closeDd.textContent = 'Not set';
      } else {
        try {
          var closeDate = new Date(closeRaw + 'T12:00:00');
          closeDd.textContent = isNaN(closeDate.getTime())
            ? closeRaw
            : closeDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (closeErr) {
          closeDd.textContent = closeRaw;
        }
      }
    }
    if (priceDd) {
      var priceEl = document.getElementById('packagePriceField');
      var priceRaw = priceEl ? String(priceEl.value || '').trim() : '';
      var priceNum = parseFloat(priceRaw);
      priceDd.textContent = priceRaw !== '' && !isNaN(priceNum)
        ? '₱' + priceNum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '—';
    }
    var stopsDd = document.querySelector('[data-itin-review="stops"]');
    if (stopsDd) {
      var nStops = routeStopCount();
      stopsDd.textContent = nStops + (nStops === 1 ? ' stop' : ' stops');
    }
  }
  function dateErrorVisible() {
    var fb = document.querySelector('[data-itin-date-feedback]');
    return !!(fb && !fb.hidden && String(fb.textContent || '').trim());
  }
  function stayRideReviewText(groupName) {
    var checked = document.querySelector('input[name="' + groupName + '"]:checked');
    if (!checked) return 'Not chosen';
    var label = checked.getAttribute('data-review-label');
    if (label) return label;
    return String(checked.value) === '0' ? 'Not needed' : 'Not chosen';
  }
  function stayGuestsOpen() {
    if (document.querySelector('input[name="stayRideAcc"][value="0"]:checked')) return false;
    var quote = window.itinStayQuote;
    if (quote && typeof quote.left === 'number' && !isNaN(quote.left)) return quote.left > 0;
    return (window.itinStayLines || []).length > 0;
  }
  function focusGapDay(message, kind) {
    var match = String(message || '').match(/Day (\d+)/);
    if (!match) return;
    var day = parseInt(match[1], 10) || 1;
    if (kind === 'ride') {
      rideActiveDay = day;
      renderRideTray();
      return;
    }
    stayActiveDay = day;
    renderStayTray();
  }
  function stayGapMessage(noun) {
    var isRide = noun === 'vehicle';
    var declinedName = isRide ? 'stayRideTrn' : 'stayRideAcc';
    if (document.querySelector('input[name="' + declinedName + '"][value="0"]:checked')) return '';
    var lines = (isRide ? window.itinRideLines : window.itinStayLines) || [];
    var quote = isRide ? window.itinRideQuote : window.itinStayQuote;
    var place = isRide ? 'seat' : 'room';
    if (!stayIsMulti()) {
      if (isRide || !quote || !(quote.left > 0) || !lines.length) return '';
      var count = quote.left;
      return count + (count === 1 ? ' guest still needs a room.' : ' guests still need a room.') + ' Place them before continuing to transportation.';
    }
    var hasUndated = lines.some(function (line) { return lineDay(line) < 1; });
    var message = '';
    tourDays().forEach(function (chip) {
      if (message) return;
      if (!isRide && stayDaySkipped(chip.day)) return;
      if (isRide && rideDaySkipped(chip.day)) return;
      var mine = lines.filter(function (line) { return lineDay(line) === chip.day; });
      if (!mine.length) {
        if (!hasUndated) message = 'Day ' + chip.day + ' still needs a ' + (isRide ? 'vehicle.' : 'room.');
        return;
      }
      var left = quote && quote.leftByDay ? Number(quote.leftByDay[String(chip.day)] || 0) : 0;
      if (left > 0) {
        message = 'Day ' + chip.day + ': ' + left + (left === 1 ? ' guest still needs a ' + place + '.' : ' guests still need a ' + place + '.');
      }
    });
    if (!message && hasUndated && quote && quote.leftByDay && Number(quote.leftByDay['0'] || 0) > 0) {
      var leftAll = Number(quote.leftByDay['0'] || 0);
      message = leftAll + (leftAll === 1 ? ' guest still needs a ' + place + '.' : ' guests still need a ' + place + '.');
    }
    return message;
  }
  function staySideReady(groupName) {
    if (groupName === 'stayRideAcc') {
      if (document.querySelector('input[name="stayRideAcc"][value="0"]:checked')) return true;
      return (window.itinStayLines || []).length > 0;
    }
    if (groupName === 'stayRideTrn') {
      if (document.querySelector('input[name="stayRideTrn"][value="0"]:checked')) return true;
      if ((window.itinRideLines || []).length || (window.itinRideSkipDays || []).length) return true;
    }
    return !!document.querySelector('input[name="' + groupName + '"]:checked');
  }
  function stayRideReady() {
    return staySideReady('stayRideAcc') && staySideReady('stayRideTrn');
  }
  function writeStaySide(checked, idEl, serviceEl, declinedEl) {
    if (!checked || !idEl || !declinedEl) return;
    var none = String(checked.value) === '0';
    idEl.value = none ? '' : String(checked.value);
    if (serviceEl) serviceEl.value = none ? '' : (checked.getAttribute('data-service-id') || '');
    declinedEl.value = none ? '1' : '0';
  }
  function syncStayRideFields() {
    var stayDeclined = document.querySelector('input[name="stayRideAcc"][value="0"]:checked');
    var stayLines = window.itinStayLines || [];
    var stayIdEl = document.getElementById('stayAccId');
    var stayServiceEl = document.getElementById('stayAccService');
    var stayDeclinedEl = document.getElementById('stayAccDeclined');
    var stayLinesEl = document.getElementById('stayAccLines');
    if (stayDeclined) {
      if (stayIdEl) stayIdEl.value = '';
      if (stayServiceEl) stayServiceEl.value = '';
      if (stayDeclinedEl) stayDeclinedEl.value = '1';
      if (stayLinesEl) stayLinesEl.value = '[]';
    } else if (stayLines.length || (window.itinStaySkipDays || []).length) {
      var stayPayload = stayLines.slice();
      (window.itinStaySkipDays || []).forEach(function (day) {
        stayPayload.push({ day: day, skip: 1 });
      });
      if (stayIdEl) stayIdEl.value = stayLines.length ? String(stayLines[0].business_id || '') : '';
      if (stayServiceEl) stayServiceEl.value = stayLines.length ? String(stayLines[0].service_id || '') : '';
      if (stayDeclinedEl) stayDeclinedEl.value = '0';
      if (stayLinesEl) stayLinesEl.value = JSON.stringify(stayPayload);
    } else {
      writeStaySide(
        document.querySelector('input[name="stayRideAcc"]:checked'),
        stayIdEl,
        stayServiceEl,
        stayDeclinedEl
      );
      if (stayLinesEl) stayLinesEl.value = '';
    }
    var rideDeclined = document.querySelector('input[name="stayRideTrn"][value="0"]:checked');
    var rideLines = window.itinRideLines || [];
    var rideIdEl = document.getElementById('stayTrnId');
    var rideServiceEl = document.getElementById('stayTrnService');
    var rideDeclinedEl = document.getElementById('stayTrnDeclined');
    var rideLinesEl = document.getElementById('stayTrnLines');
    if (rideDeclined) {
      if (rideIdEl) rideIdEl.value = '';
      if (rideServiceEl) rideServiceEl.value = '';
      if (rideDeclinedEl) rideDeclinedEl.value = '1';
      if (rideLinesEl) rideLinesEl.value = '[]';
    } else if (rideLines.length || (window.itinRideSkipDays || []).length) {
      var ridePayload = rideLines.slice();
      (window.itinRideSkipDays || []).forEach(function (day) {
        ridePayload.push({ day: day, skip: 1 });
      });
      if (rideIdEl) rideIdEl.value = rideLines.length ? String(rideLines[0].business_id || '') : '';
      if (rideServiceEl) rideServiceEl.value = rideLines.length ? String(rideLines[0].service_id || '') : '';
      if (rideDeclinedEl) rideDeclinedEl.value = '0';
      if (rideLinesEl) rideLinesEl.value = JSON.stringify(ridePayload);
    } else {
      writeStaySide(
        document.querySelector('input[name="stayRideTrn"]:checked'),
        rideIdEl,
        rideServiceEl,
        rideDeclinedEl
      );
      if (rideLinesEl) rideLinesEl.value = '';
    }
    var stayGo = document.getElementById('stayRideContinue');
    var rideGo = document.getElementById('ridePickContinue');
    if (stayGo) stayGo.disabled = !staySideReady('stayRideAcc');
    if (rideGo) rideGo.disabled = !staySideReady('stayRideTrn');
    syncBookingDetailsPreview();
  }
  function openNamedModal(id) {
    var el = document.getElementById(id);
    if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
  }
  function hideThen(id, next) {
    var el = document.getElementById(id);
    if (el && window.bootstrap) {
      el.addEventListener('hidden.bs.modal', function onHidden() {
        el.removeEventListener('hidden.bs.modal', onHidden);
        next();
      });
      bootstrap.Modal.getOrCreateInstance(el).hide();
    } else {
      next();
    }
  }
  function resetStaySelection() {
    window.itinStayLines = [];
    window.itinStayQuote = null;
    stayActiveDay = 1;
    document.querySelectorAll('input[name="stayRideAcc"]').forEach(function (input) {
      input.checked = false;
    });
    var stayIdEl = document.getElementById('stayAccId');
    var stayServiceEl = document.getElementById('stayAccService');
    var stayDeclinedEl = document.getElementById('stayAccDeclined');
    var stayLinesEl = document.getElementById('stayAccLines');
    if (stayIdEl) stayIdEl.value = '';
    if (stayServiceEl) stayServiceEl.value = '';
    if (stayDeclinedEl) stayDeclinedEl.value = '0';
    if (stayLinesEl) stayLinesEl.value = '[]';
    setStayPop('closed');
    renderStayTray();
  }
  function resetRideSelection() {
    window.itinRideLines = [];
    window.itinRideQuote = null;
    rideActiveDay = 1;
    document.querySelectorAll('input[name="stayRideTrn"]').forEach(function (input) {
      input.checked = false;
    });
    var rideIdEl = document.getElementById('stayTrnId');
    var rideServiceEl = document.getElementById('stayTrnService');
    var rideDeclinedEl = document.getElementById('stayTrnDeclined');
    var rideLinesEl = document.getElementById('stayTrnLines');
    if (rideIdEl) rideIdEl.value = '';
    if (rideServiceEl) rideServiceEl.value = '';
    if (rideDeclinedEl) rideDeclinedEl.value = '0';
    if (rideLinesEl) rideLinesEl.value = '[]';
    setRidePop('closed');
    renderRideTray();
  }
  function openStayRideModal() {
    if (document.body.classList.contains('package-maker') && packageTouristPicksDate()) return;
    openNamedModal('stayRideModal');
  }
  function openRidePickModal() {
    openNamedModal('ridePickModal');
  }
  function saveStayRide() {
    syncStayRideFields();
    if (!stayRideReady() || !itinId) return Promise.resolve({ ok: false });
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'update_stay_ride');
    body.append('itinerary_id', String(itinId));
    body.append('accommodation_business_id', (document.getElementById('stayAccId') || {}).value || '');
    body.append('accommodation_service_id', (document.getElementById('stayAccService') || {}).value || '');
    body.append('accommodation_declined', (document.getElementById('stayAccDeclined') || {}).value || '0');
    body.append('accommodation_lines', (document.getElementById('stayAccLines') || {}).value || '[]');
    body.append('transportation_business_id', (document.getElementById('stayTrnId') || {}).value || '');
    body.append('transportation_service_id', (document.getElementById('stayTrnService') || {}).value || '');
    body.append('transportation_declined', (document.getElementById('stayTrnDeclined') || {}).value || '0');
    body.append('transportation_lines', (document.getElementById('stayTrnLines') || {}).value || '[]');
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: body,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.ok) {
        window.itinStayQuote = data.stay || null;
        window.itinRideQuote = data.ride || null;
        if (typeof window.itinRenderBill === 'function') window.itinRenderBill();
        syncBookingDetailsPreview();
      }
      return data;
    }).catch(function () { return { ok: false, message: 'Could not save accommodation and transportation.' }; });
  }
  document.querySelectorAll('input[name="stayRideAcc"], input[name="stayRideTrn"]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (input.type === 'checkbox' && String(input.value) === '0' && input.checked) {
        if (input.name === 'stayRideAcc') resetStaySelection();
        else resetRideSelection();
        input.checked = true;
        syncStayRideFields();
      }
      if (input.name === 'stayRideAcc') renderStayTray();
      else renderRideTray();
    });
  });
  document.querySelectorAll('[data-pick-board]').forEach(function (board) {
    var group = board.getAttribute('data-pick-group');
    var cards = Array.prototype.slice.call(board.querySelectorAll('[data-pick-card]'));
    var focus = board.querySelector('[data-pick-focus]');
    var idx = 0;
    cards.forEach(function (card, i) {
      if (card.classList.contains('is-selected')) idx = i;
    });
    function show(id) {
      var declined = String(id) === '0';
      board.querySelectorAll('[data-pick-card]').forEach(function (card) {
        var on = !declined && String(card.getAttribute('data-pick-card')) === String(id);
        card.classList.toggle('is-selected', on);
        card.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      var any = false;
      board.querySelectorAll('[data-pick-rooms]').forEach(function (panel) {
        var on = !declined && String(panel.getAttribute('data-pick-rooms')) === String(id);
        panel.hidden = !on;
        if (on) any = true;
      });
      var shelf = board.querySelector('[data-pick-shelf]');
      if (shelf) shelf.hidden = !any;
    }
    var search = document.querySelector('[data-pick-search-for="' + group + '"]');
    function cardText(card) {
      var name = card.querySelector('.pick-spot__name');
      var place = card.querySelector('.pick-spot__place');
      return ((name ? name.textContent : '') + ' ' + (place ? place.textContent : '')).toLowerCase();
    }
    function visibleCards() {
      var q = search ? String(search.value || '').trim().toLowerCase() : '';
      if (!q) return cards.slice();
      return cards.filter(function (card) { return cardText(card).indexOf(q) !== -1; });
    }
    function setBrowsing(on) {
      board.classList.toggle('is-browsing', !!on);
      var back = document.getElementById(group === 'stayRideAcc' ? 'stayPickBack' : 'ridePickBack');
      if (!back) return;
      if (on) back.textContent = group === 'stayRideAcc' ? 'Back to itinerary' : 'Back to accommodation';
      else back.textContent = 'Back to selection';
    }
    function paint() {
      if (!focus || !cards.length) return;
      var loop = visibleCards();
      var n = loop.length;
      var pos = loop.indexOf(cards[idx]);
      if (pos < 0) pos = 0;
      ['left', 'center', 'right'].forEach(function (name) {
        var slot = focus.querySelector('[data-slot="' + name + '"]');
        if (!slot) return;
        Array.prototype.slice.call(slot.querySelectorAll('.pick-spot')).forEach(function (node) {
          slot.removeChild(node);
        });
        var offset = name === 'left' ? -1 : (name === 'right' ? 1 : 0);
        var source = (!n || (n < 2 && offset !== 0)) ? null : loop[(pos + offset + n) % n];
        slot.classList.toggle('is-empty', !source);
        if (!source) return;
        var clone = source.cloneNode(true);
        clone.addEventListener('click', function () {
          var id = String(source.getAttribute('data-pick-card') || '');
          var at = cards.indexOf(source);
          if (at >= 0) idx = at;
          paint();
          selectPlace(id);
        });
        slot.appendChild(clone);
      });
      var hideArrows = n < 2;
      var prev = focus.querySelector('.pick-focus__arrow--prev');
      var next = focus.querySelector('.pick-focus__arrow--next');
      if (prev) prev.hidden = hideArrows;
      if (next) next.hidden = hideArrows;
      var empty = focus.querySelector('[data-pick-empty]');
      var stage = focus.querySelector('.pick-focus__stage');
      if (empty) empty.hidden = n > 0;
      if (stage) stage.hidden = n === 0;
    }
    function step(dir) {
      var loop = visibleCards();
      if (loop.length < 2) return;
      var pos = loop.indexOf(cards[idx]);
      if (pos < 0) pos = 0;
      pos = (pos + dir + loop.length) % loop.length;
      idx = cards.indexOf(loop[pos]);
      paint();
      selectPlace(cards[idx].getAttribute('data-pick-card'));
    }
    function selectPlace(id) {
      board.querySelectorAll('input[name="' + group + '"]').forEach(function (input) {
        if (String(input.value) !== String(id)) input.checked = false;
      });
      var room = board.querySelector('[data-pick-rooms="' + id + '"]');
      var options = room ? room.querySelectorAll('input[name="' + group + '"]:not(:disabled)') : [];
      var checkedHere = room ? room.querySelector('input[name="' + group + '"]:checked') : null;
      if (!checkedHere && options.length === 1 && !options[0].getAttribute('data-service-id')) {
        options[0].checked = true;
        options[0].dispatchEvent(new Event('change', { bubbles: true }));
      } else if (group === 'stayRideAcc') {
        renderStayTray();
      } else {
        syncStayRideFields();
      }
      if (String(id) !== '0') setBrowsing(false);
      show(id);
    }
    cards.forEach(function (card) {
      card.addEventListener('click', function () {
        var id = String(card.getAttribute('data-pick-card') || '');
        var pos = cards.indexOf(card);
        if (pos >= 0) idx = pos;
        paint();
        selectPlace(id);
      });
    });
    if (focus) {
      var prevBtn = focus.querySelector('.pick-focus__arrow--prev');
      var nextBtn = focus.querySelector('.pick-focus__arrow--next');
      if (prevBtn) prevBtn.addEventListener('click', function () { step(-1); });
      if (nextBtn) nextBtn.addEventListener('click', function () { step(1); });
    }
    if (search) {
      search.addEventListener('input', function () {
        if (board.classList.contains('is-browsing')) {
          var loop = visibleCards();
          cards.forEach(function (card) { card.hidden = loop.indexOf(card) < 0; });
          var empty = board.querySelector('[data-pick-grid-empty]');
          if (empty) empty.hidden = loop.length > 0;
          return;
        }
        var loop = visibleCards();
        if (loop.length && loop.indexOf(cards[idx]) < 0) {
          idx = cards.indexOf(loop[0]);
          paint();
          selectPlace(cards[idx].getAttribute('data-pick-card'));
          return;
        }
        paint();
      });
    }
    board.querySelectorAll('input[name="' + group + '"]').forEach(function (input) {
      input.addEventListener('change', function () {
        if (!input.checked) return;
        var value = String(input.value || '0');
        if (value !== '0') {
          cards.forEach(function (card, i) {
            if (String(card.getAttribute('data-pick-card')) === value) idx = i;
          });
          paint();
        }
        if (value === '0') setBrowsing(true);
        show(value);
      });
    });
    function currentPlaceId() {
      var picked = board.querySelector('input[name="' + group + '"]:checked');
      if (picked) return String(picked.value || '0');
      return cards[idx] ? String(cards[idx].getAttribute('data-pick-card') || '0') : '0';
    }
    paint();
    show('0');
    if (group === 'stayRideAcc') {
      window.stayShowPlaces = function () { setBrowsing(true); show('0'); };
    } else {
      window.rideShowPlaces = function () { setBrowsing(true); show('0'); };
    }
    var modal = board.closest('.modal');
    if (modal) {
      modal.addEventListener('shown.bs.modal', function () {
        if (board.classList.contains('is-browsing')) return;
        show(currentPlaceId());
      });
    }
  });
  (function () {
    var node = document.getElementById('itinStaySaved');
    var saved = [];
    try { saved = JSON.parse((node && node.textContent) || '[]'); } catch (err) { saved = []; }
    saved = Array.isArray(saved) ? saved : [];
    window.itinStaySkipDays = [];
    window.itinStayLines = [];
    saved.forEach(function (line) {
      if (line && line.skip) {
        var skippedDay = parseInt(line.day, 10) || 0;
        if (skippedDay > 0) window.itinStaySkipDays.push(skippedDay);
        return;
      }
      window.itinStayLines.push(line);
    });
  })();
  var stayPickBack = document.getElementById('stayPickBack');
  if (stayPickBack) {
    stayPickBack.addEventListener('click', function () {
      var board = document.querySelector('[data-pick-group="stayRideAcc"]');
      if (board && !board.classList.contains('is-browsing') && window.stayShowPlaces) {
        window.stayShowPlaces();
        return;
      }
      var modal = document.getElementById('stayRideModal');
      if (modal && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
    });
  }
  var stayModalEl = document.getElementById('stayRideModal');
  if (stayModalEl && !stayModalEl.dataset.stayBound) {
    stayModalEl.dataset.stayBound = '1';
    stayModalEl.addEventListener('click', function (e) {
      var target = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
      if (!target || !target.closest) return;
      var add = target.closest('[data-stay-add]');
      var card = target.closest('[data-stay-room]');
      var amenities = target.closest('[data-stay-amenities]');
      var amenityClose = target.closest('[data-stay-amenity-close]');
      var step = target.closest('[data-stay-step]');
      var remove = target.closest('[data-stay-remove]');
      var another = target.closest('[data-stay-another]');
      var openStay = target.closest('[data-stay-open]');
      var minStay = target.closest('[data-stay-min]');
      var doneStay = target.closest('[data-stay-done]');
      var warnClose = target.closest('[data-stay-warn-close]');
      var warnPlace = target.closest('[data-stay-warn-place]');
      var dayChip = target.closest('[data-stay-days] [data-stay-day]');
      if (dayChip) {
        e.preventDefault();
        stayActiveDay = parseInt(dayChip.getAttribute('data-stay-day') || '1', 10) || 1;
        renderStayTray();
      } else if (warnClose || warnPlace) {
        e.preventDefault();
        var warn = document.querySelector('[data-stay-warn]');
        if (warn) warn.hidden = true;
        if (warnPlace) setStayPop('open');
      } else if (openStay) {
        e.preventDefault();
        setStayPop('open');
      } else if (minStay || doneStay) {
        e.preventDefault();
        if (doneStay) commitStayBar();
        setStayPop('closed');
      } else if (amenityClose) {
        e.preventDefault();
        e.stopPropagation();
        var closedPop = amenityClose.closest('[data-stay-amenity-pop]');
        if (closedPop) closedPop.hidden = true;
      } else if (amenities) {
        e.preventDefault();
        e.stopPropagation();
        var amenityCard = amenities.closest('[data-stay-room]');
        var amenityPop = amenityCard ? amenityCard.querySelector('[data-stay-amenity-pop]') : null;
        stayModalEl.querySelectorAll('[data-stay-amenity-pop]').forEach(function (other) {
          if (other !== amenityPop) other.hidden = true;
        });
        if (amenityPop) amenityPop.hidden = false;
      } else if (add) {
        e.preventDefault();
        e.stopPropagation();
        if (add.disabled) return;
        addStayRoom(add.getAttribute('data-stay-add'));
      } else if (card && !target.closest('[data-stay-step], [data-stay-remove], [data-stay-another], [data-stay-amenities], [data-stay-amenity-pop]')) {
        var addBtn = card.querySelector('[data-stay-add]');
        if (addBtn && !addBtn.disabled) {
          e.preventDefault();
          addStayRoom(addBtn.getAttribute('data-stay-add'));
        }
      } else if (step && !step.disabled) {
        e.preventDefault();
        stepStayLine(step.getAttribute('data-stay-id'), step.getAttribute('data-stay-step'), Number(step.getAttribute('data-dir')) || 0, step.getAttribute('data-stay-index'), step.getAttribute('data-stay-day'));
      } else if (remove) {
        e.preventDefault();
        window.itinStayLines = (window.itinStayLines || []).filter(function (line) {
          if (String(line.service_id) !== String(remove.getAttribute('data-stay-remove'))) return true;
          if (!stayIsMulti()) return false;
          var day = parseInt(remove.getAttribute('data-stay-day') || '0', 10) || 0;
          return lineDay(line) !== day;
        });
        renderStayTray();
      } else if (another) {
        e.preventDefault();
        setStayPop('closed');
        if (window.stayShowPlaces) window.stayShowPlaces();
      }
    });
  }
  var stayDraftReady = false;
  var stayDraftTimer = null;
  function scheduleStayDraft() {
    if (!stayDraftReady || !itinId) return;
    clearTimeout(stayDraftTimer);
    if (!(window.itinStayLines || []).length) {
      stayDraftTimer = null;
      saveStayDraft();
      return;
    }
    stayDraftTimer = setTimeout(saveStayDraft, 400);
  }
  function saveStayDraft() {
    syncStayRideFields();
    if (!itinId) return;
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'save_stay_lines');
    body.append('itinerary_id', String(itinId));
    body.append('accommodation_declined', (document.getElementById('stayAccDeclined') || {}).value || '0');
    body.append('accommodation_lines', (document.getElementById('stayAccLines') || {}).value || '[]');
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).catch(function () {});
  }
  window.addEventListener('pagehide', function () {
    if (!stayDraftTimer) return;
    clearTimeout(stayDraftTimer);
    stayDraftTimer = null;
    saveStayDraft();
  });
  stayActiveDay = 1;
  rideActiveDay = 1;
  try {
    renderStayTray();
    commitStayBar();
  } catch (err) {}
  stayDraftReady = true;
  (function () {
    var node = document.getElementById('itinRideSaved');
    var saved = [];
    try { saved = JSON.parse((node && node.textContent) || '[]'); } catch (err) { saved = []; }
    window.itinRideSkipDays = [];
    window.itinRideLines = [];
    (Array.isArray(saved) ? saved : []).forEach(function (line) {
      if (line && line.skip) {
        var skippedDay = parseInt(line.day, 10) || 0;
        if (skippedDay > 0) window.itinRideSkipDays.push(skippedDay);
        return;
      }
      window.itinRideLines.push(line);
    });
  })();
  var rideModalEl = document.getElementById('ridePickModal');
  if (rideModalEl && !rideModalEl.dataset.rideBound) {
    rideModalEl.dataset.rideBound = '1';
    rideModalEl.addEventListener('click', function (e) {
      var target = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
      if (!target || !target.closest) return;
      var add = target.closest('[data-ride-add]');
      var amenities = target.closest('[data-stay-amenities]');
      var amenityClose = target.closest('[data-stay-amenity-close]');
      var step = target.closest('[data-stay-step]');
      var remove = target.closest('[data-stay-remove]');
      var another = target.closest('[data-ride-another]');
      var addUnit = target.closest('[data-ride-add-unit]');
      var openRide = target.closest('[data-ride-open]');
      var minRide = target.closest('[data-ride-min]');
      var doneRide = target.closest('[data-ride-done]');
      var rideDayChip = target.closest('[data-ride-days] [data-ride-day]');
      var rideWarnClose = target.closest('[data-ride-warn-close]');
      var rideWarnPlace = target.closest('[data-ride-warn-place]');
      if (rideDayChip) {
        e.preventDefault();
        rideActiveDay = parseInt(rideDayChip.getAttribute('data-ride-day') || '1', 10) || 1;
        renderRideTray();
      } else if (rideWarnClose || rideWarnPlace) {
        e.preventDefault();
        var rideWarnBox = document.querySelector('[data-ride-warn]');
        if (rideWarnBox) rideWarnBox.hidden = true;
        if (rideWarnPlace) setRidePop('open');
      } else if (addUnit) {
        e.preventDefault();
        var rideLinesNow = (window.itinRideLines || []).filter(function (line) {
          return !stayIsMulti() || lineDay(line) === rideActiveDay;
        });
        var rideServiceId = rideLinesNow.length ? rideLinesNow[rideLinesNow.length - 1].service_id : '';
        if (!rideServiceId) {
          var shelfAdd = rideModalEl.querySelector('[data-pick-rooms]:not([hidden]) [data-ride-add]:not(:disabled)');
          rideServiceId = shelfAdd ? shelfAdd.getAttribute('data-ride-add') : '';
        }
        if (rideServiceId) addRideVehicle(rideServiceId);
      } else if (openRide) {
        e.preventDefault();
        setRidePop('open');
      } else if (minRide || doneRide) {
        e.preventDefault();
        setRidePop('closed');
      } else if (amenityClose) {
        e.preventDefault();
        e.stopPropagation();
        var closedRidePop = amenityClose.closest('[data-stay-amenity-pop]');
        if (closedRidePop) closedRidePop.hidden = true;
      } else if (amenities) {
        e.preventDefault();
        e.stopPropagation();
        var featureCard = amenities.closest('[data-ride-room]');
        var featurePop = featureCard ? featureCard.querySelector('[data-stay-amenity-pop]') : null;
        rideModalEl.querySelectorAll('[data-stay-amenity-pop]').forEach(function (other) {
          if (other !== featurePop) other.hidden = true;
        });
        if (featurePop) featurePop.hidden = false;
      } else if (add) {
        e.preventDefault();
        e.stopPropagation();
        if (add.disabled) return;
        addRideVehicle(add.getAttribute('data-ride-add'));
      } else if (step && !step.disabled) {
        e.preventDefault();
        stepRideLine(step.getAttribute('data-stay-id'), step.getAttribute('data-stay-step'), Number(step.getAttribute('data-dir')) || 0, step.getAttribute('data-stay-index'), step.getAttribute('data-stay-day'));
      } else if (remove) {
        e.preventDefault();
        window.itinRideLines = (window.itinRideLines || []).filter(function (line) {
          if (String(line.service_id) !== String(remove.getAttribute('data-stay-remove'))) return true;
          if (!stayIsMulti()) return false;
          var day = parseInt(remove.getAttribute('data-stay-day') || '0', 10) || 0;
          return lineDay(line) !== day;
        });
        renderRideTray();
      } else if (another) {
        e.preventDefault();
        setRidePop('closed');
        if (window.rideShowPlaces) window.rideShowPlaces();
      }
    });
  }
  var rideDraftReady = false;
  var rideDraftTimer = null;
  function scheduleRideDraft() {
    if (!rideDraftReady || !itinId) return;
    clearTimeout(rideDraftTimer);
    if (!(window.itinRideLines || []).length) {
      rideDraftTimer = null;
      saveRideDraft();
      return;
    }
    rideDraftTimer = setTimeout(saveRideDraft, 400);
  }
  function saveRideDraft() {
    syncStayRideFields();
    if (!itinId) return;
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'save_ride_lines');
    body.append('itinerary_id', String(itinId));
    body.append('transportation_declined', (document.getElementById('stayTrnDeclined') || {}).value || '0');
    body.append('transportation_lines', (document.getElementById('stayTrnLines') || {}).value || '[]');
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).catch(function () {});
  }
  window.addEventListener('pagehide', function () {
    if (!rideDraftTimer) return;
    clearTimeout(rideDraftTimer);
    rideDraftTimer = null;
    saveRideDraft();
  });
  try {
    renderRideTray();
  } catch (err) {}
  rideDraftReady = true;
  var stayContinue = document.getElementById('stayRideContinue');
  if (stayContinue) {
    stayContinue.addEventListener('click', function () {
      if (!staySideReady('stayRideAcc')) return;
      var stayGap = stayGapMessage('room');
      if (stayGap) {
        var warn = document.querySelector('[data-stay-warn]');
        var text = warn ? warn.querySelector('[data-stay-warn-text]') : null;
        if (text) text.textContent = stayGap;
        if (warn) warn.hidden = false;
        focusGapDay(stayGap, 'stay');
        return;
      }
      var openWarn = document.querySelector('[data-stay-warn]');
      if (openWarn) openWarn.hidden = true;
      syncStayRideFields();
      hideThen('stayRideModal', openRidePickModal);
    });
  }
  var rideBack = document.getElementById('ridePickBack');
  if (rideBack) {
    rideBack.addEventListener('click', function () {
      var board = document.querySelector('[data-pick-group="stayRideTrn"]');
      if (board && !board.classList.contains('is-browsing') && window.rideShowPlaces) {
        window.rideShowPlaces();
        return;
      }
      hideThen('ridePickModal', openStayRideModal);
    });
  }
  var guideBack = document.getElementById('guideBookBack');
  if (guideBack) {
    guideBack.addEventListener('click', function () {
      hideThen('guideBookModal', openRidePickModal);
    });
  }
  var rideContinue = document.getElementById('ridePickContinue');
  if (rideContinue) {
    rideContinue.addEventListener('click', function () {
      if (!stayRideReady()) return;
      var rideGap = stayGapMessage('vehicle');
      if (rideGap) {
        var rideWarn = document.querySelector('[data-ride-warn]');
        var rideText = rideWarn ? rideWarn.querySelector('[data-ride-warn-text]') : null;
        if (rideText) rideText.textContent = rideGap;
        if (rideWarn) rideWarn.hidden = false;
        focusGapDay(rideGap, 'ride');
        return;
      }
      rideContinue.disabled = true;
      saveStayRide().then(function (res) {
        rideContinue.disabled = !staySideReady('stayRideTrn');
        var stayError = document.getElementById('stayRideError');
        if (!res || !res.ok) {
          if (stayError) {
            stayError.textContent = (res && res.message) || 'Could not save accommodation and transportation.';
            stayError.hidden = false;
          }
          return;
        }
        if (stayError) stayError.hidden = true;
        var houseFree = parseInt(rideContinue.getAttribute('data-house-free') || '0', 10);
        if (document.body.classList.contains('package-maker') && !packageTouristPicksDate() && houseFree < 1) {
          hideThen('ridePickModal', function () {
            var notice = document.getElementById('packageNoGuideModal');
            if (notice && window.bootstrap) bootstrap.Modal.getOrCreateInstance(notice).show();
          });
          return;
        }
        hideThen('ridePickModal', openGuideBookModal);
      });
    });
  }
  function openBookReviewModal() {
    if (!hasRouteStops()) {
      needDateOrGuests(bookBtn, { requireGuests: true, requireTitle: true, requireGuide: true, requireStops: true });
      return;
    }
    if (!stayRideReady()) {
      openStayRideModal();
      return;
    }
    syncStayRideFields();
    syncBookingDetailsPreview();
    if (typeof window.itinRenderBill === 'function') window.itinRenderBill();
    var reviewEl = document.getElementById('itinBookReviewModal');
    if (reviewEl && window.bootstrap) {
      bootstrap.Modal.getOrCreateInstance(reviewEl).show();
    }
  }
  function packageTouristPicksDate() {
    var box = document.getElementById('packageDateTourist');
    return !!(box && box.checked);
  }
  function syncBookBtnForDate() {
    if (!bookBtn) return;
    var hasDate = !!(dateEl && String(dateEl.value || '').trim()) || packageTouristPicksDate();
    var blocked = dateErrorVisible();
    bookBtn.removeAttribute('data-bs-toggle');
    bookBtn.removeAttribute('data-bs-target');
    if (hasDate && !blocked) {
      bookBtn.disabled = false;
      bookBtn.removeAttribute('data-itin-need-date');
      bookBtn.removeAttribute('data-itin-book-blocked');
      bookBtn.removeAttribute('title');
    } else if (blocked) {
      bookBtn.disabled = true;
      bookBtn.setAttribute('data-itin-book-blocked', '');
      bookBtn.removeAttribute('data-itin-need-date');
      bookBtn.setAttribute('title', 'Fix the tour date before continuing');
    } else {
      bookBtn.disabled = false;
      bookBtn.removeAttribute('data-itin-book-blocked');
      bookBtn.setAttribute('data-itin-need-date', '');
      bookBtn.setAttribute('title', 'Set a tour date before continuing');
    }
  }
  window.itinSyncBookButton = syncBookBtnForDate;
  syncBookBtnForDate();
  if (typeof window.itinFlagPastTourDates === 'function') window.itinFlagPastTourDates();
  if (dateEl) {
    dateEl.addEventListener('change', syncBookBtnForDate);
    dateEl.addEventListener('input', syncBookBtnForDate);
    dateEl.addEventListener('change', syncBookingDetailsPreview);
    dateEl.addEventListener('input', function () {
      syncBookingDetailsPreview();
      if (dateEl && String(dateEl.value || '').trim()) {
        dateEl.classList.remove('is-invalid');
        var dfb = document.querySelector('[data-itin-date-feedback]');
        if (dfb && /tour date/i.test(dfb.textContent || '')) dfb.hidden = true;
      }
    });
  }
  var partySaveTimer = null;
  function stayCatalogMap() {
    if (window.itinStayCatalog && Object.keys(window.itinStayCatalog).length) return window.itinStayCatalog;
    var node = document.getElementById('itinStayCatalog');
    var list = [];
    try { list = JSON.parse((node && node.textContent) || '[]'); } catch (err) { list = []; }
    var map = {};
    (Array.isArray(list) ? list : []).forEach(function (room) { map[String(room.id)] = room; });
    document.querySelectorAll('[data-stay-room]').forEach(function (card) {
      var id = String(card.getAttribute('data-stay-room') || '');
      if (!id || map[id]) return;
      var freeRaw = card.getAttribute('data-free');
      var capRaw = card.getAttribute('data-capacity');
      map[id] = {
        id: Number(id),
        business_id: Number(card.getAttribute('data-business-id') || 0),
        business_name: card.getAttribute('data-business-name') || '',
        name: card.getAttribute('data-room-name') || 'Room',
        price: Number(card.getAttribute('data-price') || 0),
        capacity: capRaw ? Number(capRaw) : null,
        fee: Number(card.getAttribute('data-fee') || 0),
        extra_per: Number(card.getAttribute('data-extra-per') || 0),
        free: freeRaw === '' || freeRaw == null ? null : Number(freeRaw),
        nights: Number(card.getAttribute('data-nights') || 1)
      };
    });
    window.itinStayCatalog = map;
    return map;
  }
  function stayNights() {
    var tray = document.querySelector('[data-stay-tray]');
    var n = tray ? parseInt(tray.getAttribute('data-nights') || '1', 10) : 1;
    return n > 0 ? n : 1;
  }
  function stayPartyCount() {
    var n = partyEl ? parseInt(String(partyEl.value || '').trim(), 10) : 0;
    if (!n) {
      var tray = document.querySelector('[data-stay-tray]');
      n = tray ? parseInt(tray.getAttribute('data-party') || '1', 10) : 1;
    }
    return n > 0 ? n : 1;
  }
  var stayActiveDay = 1;
  var rideActiveDay = 1;
  function tourDays() {
    var chips = document.querySelectorAll('[data-stay-days] .stay-days__chip, [data-ride-days] .stay-days__chip');
    var list = [];
    var seen = {};
    chips.forEach(function (chip) {
      var day = parseInt(chip.getAttribute('data-stay-day') || chip.getAttribute('data-ride-day') || '0', 10);
      if (!day || seen[day]) return;
      seen[day] = true;
      var strong = chip.querySelector('strong');
      var small = chip.querySelector('small');
      list.push({
        day: day,
        label: strong ? strong.textContent : ('Day ' + day),
        when: small ? small.textContent : ''
      });
    });
    if (list.length > 1) return list;
    var node = document.getElementById('itinTourDays');
    if (!node) return list;
    try {
      var parsed = JSON.parse(node.textContent || '[]');
      if (Array.isArray(parsed) && parsed.length > 1) return parsed;
    } catch (err) {}
    return list;
  }
  function stayIsMulti() {
    var tray = document.querySelector('[data-stay-tray], [data-ride-tray]');
    var marked = tray ? parseInt(tray.getAttribute('data-day-count') || '0', 10) : 0;
    if (marked > 1) return true;
    return tourDays().length > 1;
  }
  function lineDay(line) {
    var day = parseInt(line && line.day, 10);
    return day > 0 ? day : 0;
  }
  function dayAmountLabel(quote, day) {
    if (!quote || !quote.lines || !quote.lines.length) return formatPeso(0);
    if (!stayIsMulti()) return quote.amount_label || formatPeso(quote.amount || 0);
    var amount = 0;
    quote.lines.forEach(function (line) {
      if (lineDay(line) !== day) return;
      amount += Number(line.amount) || 0;
    });
    return formatPeso(amount);
  }
  function roomFreeCount(room, day) {
    if (!room) return null;
    if (day > 0 && room.free_days && Object.prototype.hasOwnProperty.call(room.free_days, String(day))) {
      var dayFree = room.free_days[String(day)];
      if (dayFree === null || typeof dayFree === 'undefined') return null;
      return Number(dayFree);
    }
    if (room.free === null || typeof room.free === 'undefined') return null;
    return Number(room.free);
  }
  function lineMaxUnits(room, day) {
    var free = roomFreeCount(room, day || 0);
    if (free === null) return 20;
    return Math.max(0, Math.min(20, free || 0));
  }
  function roomGuestCeiling(room) {
    var cap = room && room.capacity ? Number(room.capacity) : 0;
    if (room && room.seat_limit) return cap > 0 ? cap : 1;
    var extra = Number(room && room.extra_per) || 0;
    if (cap > 0) return Math.max(1, cap + extra);
    return 20;
  }
  function lineOccupancy(line, room) {
    var ceiling = roomGuestCeiling(room);
    var guests = [];
    if (Array.isArray(line.guests) && line.guests.length) {
      line.guests.forEach(function (n) {
        if (guests.length >= 20) return;
        guests.push(Math.max(1, Math.min(ceiling, Number(n) || 1)));
      });
      return guests.length ? guests : [1];
    }
    var maxUnits = lineMaxUnits(room);
    var units = Math.max(1, Math.min(maxUnits || 1, Number(line.units) || 1));
    for (var j = 0; j < units; j++) guests.push(1);
    return guests;
  }
  function extrasForGuests(guests, cap, extraPer) {
    if (!cap) return 0;
    var extras = 0;
    guests.forEach(function (g) {
      if (g > cap) extras += Math.min(extraPer, g - cap);
    });
    return extras;
  }
  function fitGuestsToParty(groups, party) {
    function totals() {
      var sum = 0;
      var count = 0;
      groups.forEach(function (guests) {
        guests.forEach(function (g) {
          sum += g;
          count++;
        });
      });
      return { sum: sum, count: count };
    }
    var guard = 0;
    var t = totals();
    while (t.sum > party && guard < 400) {
      guard++;
      var cut = false;
      for (var i = groups.length - 1; i >= 0 && !cut; i--) {
        for (var j = groups[i].length - 1; j >= 0; j--) {
          if (groups[i][j] > 1) {
            groups[i][j]--;
            cut = true;
            break;
          }
        }
      }
      if (!cut) break;
      t = totals();
    }
    t = totals();
    while (t.count > party && t.count > 1) {
      var last = groups[groups.length - 1];
      if (!last || !last.length) break;
      last.pop();
      if (!last.length) groups.pop();
      t = totals();
    }
    return groups.filter(function (guests) { return guests.length; });
  }
  function copyStayLine(line) {
    var copy = {
      business_id: line.business_id,
      service_id: line.service_id,
      units: line.units,
      guests: (line.guests || []).slice(),
      extra_guests: line.extra_guests
    };
    if (lineDay(line) > 0) copy.day = lineDay(line);
    return copy;
  }
  function quoteStayLines(lines, opts) {
    opts = opts || {};
    var catalog = opts.catalog || stayCatalogMap();
    var nights = opts.spans || stayNights();
    var noun = opts.noun || 'room';
    var spanWord = opts.spanWord || 'night';
    var party = stayPartyCount();
    var multi = stayIsMulti();
    var prepared = [];
    lines.forEach(function (line) {
      var room = catalog[String(line.service_id)];
      if (!room) return;
      var day = lineDay(line);
      if (multi && day < 1) day = 1;
      if (!multi) day = 0;
      prepared.push({
        room: room,
        day: day,
        guests: lineOccupancy(line, room),
        maxUnits: lineMaxUnits(room, day)
      });
    });
    var buckets = {};
    prepared.forEach(function (item, index) {
      var key = String(item.day || 0);
      if (!buckets[key]) buckets[key] = [];
      buckets[key].push(index);
    });
    var keep = [];
    Object.keys(buckets).forEach(function (key) {
      var indexes = buckets[key];
      var groups = fitGuestsToParty(indexes.map(function (index) { return prepared[index].guests; }), party);
      indexes.forEach(function (index, n) {
        if (!groups[n]) return;
        prepared[index].guests = groups[n];
        keep.push(prepared[index]);
      });
    });
    prepared = keep;
    var sumUnits = 0;
    var sumExtras = 0;
    var sumAmount = 0;
    var holds = 0;
    var details = [];
    var reviews = [];
    var out = [];
    prepared.forEach(function (item) {
      var room = item.room;
      var guests = item.guests;
      var units = guests.length;
      var cap = room.capacity ? Number(room.capacity) : 0;
      var extraPer = Number(room.extra_per) || 0;
      var extras = extrasForGuests(guests, cap, extraPer);
      var price = Number(room.price) || 0;
      var fee = Number(room.fee) || 0;
      var lineNights = multi ? 1 : nights;
      var amount = (units * price + extras * fee) * lineNights;
      var math = units + ' × ' + formatPeso(price);
      if (extras > 0 && fee > 0) math += ' + ' + extras + ' × ' + formatPeso(fee);
      if (lineNights > 1) math += ' × ' + lineNights + ' ' + spanWord + 's';
      math += ' = ' + formatPeso(amount);
      var label = (room.business_name ? room.business_name + ' · ' : '') + room.name;
      var ceiling = roomGuestCeiling(room);
      guests.forEach(function (g) { holds += g; });
      var savedLine = {
        business_id: room.business_id,
        service_id: room.id,
        units: units,
        guests: guests.slice(),
        extra_guests: extras,
        amount: amount,
        label: label,
        math: math,
        extra_per: extraPer,
        fee: fee,
        max_units: item.maxUnits,
        capacity: cap,
        ceiling: ceiling
      };
      if (item.day > 0) savedLine.day = item.day;
      out.push(savedLine);
      sumUnits += units;
      sumExtras += extras;
      sumAmount += amount;
      details.push(label + ': ' + math + '.');
      reviews.push(label);
    });
    var holdsByDay = {};
    out.forEach(function (line) {
      var key = String(lineDay(line));
      if (!holdsByDay[key]) holdsByDay[key] = 0;
      (line.guests || []).forEach(function (g) { holdsByDay[key] += g; });
    });
    var leftByDay = {};
    Object.keys(holdsByDay).forEach(function (key) {
      leftByDay[key] = Math.max(0, party - holdsByDay[key]);
    });
    var focusKey = String(multi ? (opts.openDay || stayActiveDay || 1) : 0);
    if (opts.openDay && opts.noun === 'vehicle') focusKey = String(opts.openDay);
    if (!multi) focusKey = '0';
    if (leftByDay[focusKey] === undefined) leftByDay[focusKey] = party;
    var left = leftByDay[focusKey];
    out.forEach(function (line) {
      var roomCap = line.max_units > 0 ? line.units < line.max_units : line.max_units !== 0 && line.units < 20;
      var dayLeft = leftByDay[String(lineDay(line))];
      if (dayLeft === undefined) dayLeft = party;
      line.can_add_unit = roomCap;
      line.can_add_guest = line.guests.map(function (g) { return g < line.ceiling && dayLeft > 0; });
    });
    var short = sumUnits + ' ' + (sumUnits === 1 ? noun : noun + 's');
    if (sumExtras > 0) short += ' · ' + sumExtras + ' extra guest' + (sumExtras === 1 ? '' : 's');
    return {
      ok: out.length > 0,
      label: short,
      amount: sumAmount,
      amount_label: formatPeso(sumAmount),
      detail: details.join('\n'),
      review: reviews.join(', '),
      lines: out,
      holds: holds,
      holdsKnown: true,
      left: left,
      leftByDay: leftByDay,
      party: party
    };
  }
  function stayStepBtn(id, kind, dir, disabled, index, noun, day) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'stay-step';
    btn.setAttribute('data-stay-step', kind);
    btn.setAttribute('data-stay-id', String(id));
    btn.setAttribute('data-dir', String(dir));
    if (index !== undefined && index !== null) btn.setAttribute('data-stay-index', String(index));
    if (day > 0) btn.setAttribute('data-stay-day', String(day));
    btn.textContent = dir < 0 ? '−' : '+';
    btn.disabled = !!disabled;
    var unitWord = noun === 'vehicle' ? 'vehicles' : 'rooms';
    btn.setAttribute('aria-label', (dir < 0 ? 'Fewer ' : 'More ') + (kind === 'units' ? unitWord : 'guests'));
    return btn;
  }
  function stayFitHint(line, noun) {
    noun = noun || 'room';
    if (noun === 'vehicle') {
      if (!line.capacity) return 'This vehicle does not list its seats, so it holds 1 guest.';
      return line.capacity + (line.capacity === 1 ? ' seat' : ' seats') + '. It cannot take more than that.';
    }
    if (!line.capacity) return 'This room does not list how many guests are included.';
    var text = line.capacity + ' included in the room price.';
    if (line.extra_per > 0 && line.fee > 0) text += ' More than that is ' + formatPeso(line.fee) + ' each.';
    else text += ' Add another room for more people.';
    return text;
  }
  function stayLineNode(line, noun) {
    noun = noun || 'room';
    var unitWord = noun === 'vehicle' ? 'Vehicles' : 'Rooms';
    var guestWord = noun === 'vehicle' ? 'Vehicle ' : 'Room ';
    var row = document.createElement('article');
    row.className = 'stay-line';
    var top = document.createElement('div');
    top.className = 'stay-line__top';
    var title = document.createElement('div');
    var name = document.createElement('strong');
    name.textContent = line.label;
    title.appendChild(name);
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'stay-line__remove';
    remove.setAttribute('data-stay-remove', String(line.service_id));
    if (line.day > 0) remove.setAttribute('data-stay-day', String(line.day));
    remove.textContent = 'Remove';
    top.appendChild(title);
    top.appendChild(remove);
    var steps = document.createElement('div');
    steps.className = 'stay-line__steps';
    var rooms = document.createElement('div');
    rooms.className = 'stay-line__step';
    var roomsLabel = document.createElement('span');
    roomsLabel.textContent = unitWord;
    var roomsCount = document.createElement('strong');
    roomsCount.textContent = String(line.units);
    rooms.appendChild(roomsLabel);
    rooms.appendChild(stayStepBtn(line.service_id, 'units', -1, line.units <= 1, undefined, noun, line.day || 0));
    rooms.appendChild(roomsCount);
    rooms.appendChild(stayStepBtn(line.service_id, 'units', 1, !line.can_add_unit, undefined, noun, line.day || 0));
    steps.appendChild(rooms);
    (line.guests || []).forEach(function (count, index) {
      var guests = document.createElement('div');
      guests.className = 'stay-line__step';
      var guestsLabel = document.createElement('span');
      guestsLabel.textContent = guestWord + (index + 1) + ', guest';
      var guestsCount = document.createElement('strong');
      guestsCount.textContent = String(count);
      var canAdd = line.can_add_guest && line.can_add_guest[index];
      guests.appendChild(guestsLabel);
      guests.appendChild(stayStepBtn(line.service_id, 'guest', -1, count <= 1, index, noun, line.day || 0));
      guests.appendChild(guestsCount);
      guests.appendChild(stayStepBtn(line.service_id, 'guest', 1, !canAdd, index, noun, line.day || 0));
      steps.appendChild(guests);
    });
    var hint = document.createElement('p');
    hint.className = 'stay-line__hint';
    hint.textContent = stayFitHint(line, noun);
    var math = document.createElement('p');
    math.className = 'stay-line__math';
    math.textContent = line.math;
    row.appendChild(top);
    row.appendChild(steps);
    row.appendChild(hint);
    row.appendChild(math);
    return row;
  }
  function appendDayGroups(box, lines, noun, activeDay) {
    var showing = stayIsMulti()
      ? lines.filter(function (line) { return lineDay(line) === activeDay; })
      : lines;
    if (!showing.length) {
      var empty = document.createElement('p');
      empty.className = 'stay-build__empty';
      if (stayIsMulti()) {
        empty.textContent = noun === 'vehicle'
          ? 'No vehicle on day ' + activeDay + ' yet. This day is separate from the other days.'
          : 'No room on day ' + activeDay + ' yet. You can book the same place as another day.';
        var donorDay = 0;
        lines.forEach(function (line) {
          var d = lineDay(line);
          if (d > 0 && d !== activeDay) donorDay = d;
        });
        if (noun === 'room' || noun === 'vehicle') {
          var rideNoun = noun === 'vehicle';
          var skippedDay = rideNoun ? rideDaySkipped(activeDay) : stayDaySkipped(activeDay);
          if (skippedDay) {
            empty.textContent = rideNoun
              ? 'No transportation on day ' + activeDay + '.'
              : 'No accommodation on day ' + activeDay + '.';
            var undo = document.createElement('button');
            undo.type = 'button';
            undo.className = 'btn btn-outline-light btn-sm mt-2';
            undo.textContent = rideNoun ? 'Add a vehicle for this day' : 'Add a room for this day';
            undo.addEventListener('click', function () {
              if (rideNoun) unskipRideDay(activeDay);
              else unskipStayDay(activeDay);
            });
            box.appendChild(empty);
            box.appendChild(undo);
            return;
          }
          var actions = document.createElement('div');
          actions.className = 'stay-day-actions';
          if (donorDay > 0) {
            var same = document.createElement('button');
            same.type = 'button';
            same.className = 'btn btn-outline-light btn-sm';
            same.textContent = rideNoun
              ? 'Use the same transportation as day ' + donorDay
              : 'Use the same accommodation as day ' + donorDay;
            same.addEventListener('click', function () {
              if (rideNoun) copyRideDay(donorDay, activeDay);
              else copyStayDay(donorDay, activeDay);
            });
            actions.appendChild(same);
          }
          var skip = document.createElement('button');
          skip.type = 'button';
          skip.className = 'btn btn-outline-light btn-sm';
          skip.textContent = rideNoun
            ? "I don't need transportation on this day"
            : "I don't need accommodation on this day";
          skip.addEventListener('click', function () {
            if (rideNoun) skipRideDay(activeDay);
            else skipStayDay(activeDay);
          });
          actions.appendChild(skip);
          box.appendChild(empty);
          box.appendChild(actions);
          return;
        }
      } else {
        empty.textContent = noun === 'vehicle'
          ? 'Add a vehicle. It takes the guests who still need a seat, up to its seats.'
          : 'Add a room. It starts with the guests included in its price. If every guest already has a room, those guests move off another room.';
      }
      box.appendChild(empty);
      return;
    }
    showing.forEach(function (line) { box.appendChild(stayLineNode(line, noun)); });
  }
  function paintDayChips(kind) {
    var wrap = kind === 'ride' ? '[data-ride-days]' : '[data-stay-days]';
    var attr = kind === 'ride' ? 'data-ride-day' : 'data-stay-day';
    var active = kind === 'ride' ? rideActiveDay : stayActiveDay;
    document.querySelectorAll(wrap + ' .stay-days__chip').forEach(function (chip) {
      chip.classList.toggle('is-on', String(chip.getAttribute(attr)) === String(active));
    });
  }
  function paintDayStock(kind) {
    if (!stayIsMulti()) return;
    var day = kind === 'ride' ? rideActiveDay : stayActiveDay;
    var catalog = kind === 'ride' ? rideCatalogMap() : stayCatalogMap();
    var cardAttr = kind === 'ride' ? 'data-ride-room' : 'data-stay-room';
    var noteAttr = kind === 'ride' ? 'data-ride-stock-note' : 'data-stay-stock-note';
    var addAttr = kind === 'ride' ? 'data-ride-add' : 'data-stay-add';
    var stockAttr = kind === 'ride' ? 'data-ride-stock' : 'data-stay-stock';
    document.querySelectorAll('[' + cardAttr + ']').forEach(function (card) {
      var room = catalog[String(card.getAttribute(cardAttr) || '')];
      var free = roomFreeCount(room, day);
      var note = card.querySelector('[' + noteAttr + ']');
      var total = note ? parseInt(note.getAttribute('data-stock-total') || '0', 10) : 0;
      if (note && free !== null) {
        note.textContent = free < 1 ? 'None left on day ' + day : (free + ' of ' + total + ' left on day ' + day);
      }
      var btn = card.querySelector('[' + addAttr + ']');
      if (!btn || free === null) return;
      if (free < 1) btn.setAttribute(stockAttr, '0');
      else btn.removeAttribute(stockAttr);
    });
  }
  function renderStayTray() {
    var box = document.querySelector('[data-stay-lines]');
    var totalEl = document.querySelector('[data-stay-total]');
    var holdEl = document.querySelector('[data-stay-hold]');
    var leftEl = document.querySelector('[data-stay-left]');
    var leftLabel = document.querySelector('[data-stay-left-label]');
    if (!box) {
      syncStayRideFields();
      return;
    }
    var declined = !!document.querySelector('input[name="stayRideAcc"][value="0"]:checked');
    var quote = (window.itinStayLines || []).length ? quoteStayLines(window.itinStayLines) : null;
    window.itinStayQuote = (!declined && quote && quote.lines.length) ? quote : null;
    if (window.itinStayQuote) {
      window.itinStayLines = quote.lines.map(function (line) {
        return copyStayLine(line);
      });
    }
    while (box.firstChild) box.removeChild(box.firstChild);
    if (declined) {
      var skipped = document.createElement('p');
      skipped.className = 'stay-build__empty';
      skipped.textContent = 'Accommodation is not needed for this tour.';
      box.appendChild(skipped);
    } else if (!window.itinStayQuote) {
      var empty = document.createElement('p');
      empty.className = 'stay-build__empty';
      empty.textContent = 'Add a room. It starts with the guests included in its price. If every guest already has a room, those guests move off another room.';
      box.appendChild(empty);
    } else {
      appendDayGroups(box, window.itinStayQuote.lines, 'room', stayActiveDay);
    }
    var tripLabel = window.itinStayQuote ? window.itinStayQuote.amount_label : formatPeso(0);
    var tripCount = window.itinStayQuote ? window.itinStayQuote.label : 'Nothing added yet';
    if (totalEl) totalEl.textContent = declined ? formatPeso(0) : tripLabel;
    if (holdEl) {
      if (declined || stayDaySkipped(stayActiveDay)) {
        holdEl.hidden = true;
      } else if (window.itinStayQuote && !window.itinStayQuote.holdsKnown) {
        holdEl.hidden = false;
        holdEl.classList.add('is-note');
        if (leftEl) leftEl.hidden = true;
        if (leftLabel) leftLabel.textContent = 'Guest fit is counted only for rooms that list how many people they hold.';
      } else {
        var partyLeft = stayPartyCount();
        var left = window.itinStayQuote && typeof window.itinStayQuote.left === 'number'
          ? window.itinStayQuote.left
          : partyLeft;
        holdEl.hidden = false;
        holdEl.classList.remove('is-note');
        if (leftEl) {
          leftEl.hidden = false;
          leftEl.textContent = String(left);
        }
        if (leftLabel) {
          leftLabel.textContent = (left === 1 ? 'guest still to accommodate' : 'guests still to accommodate') + (stayIsMulti() ? ' on day ' + stayActiveDay : '');
        }
        var dayTotalEl = document.querySelector('[data-stay-day-total]');
        if (dayTotalEl) dayTotalEl.textContent = dayAmountLabel(window.itinStayQuote, stayActiveDay);
      }
    }
    paintDayChips('stay');
    paintDayStock('stay');
    var anotherStay = document.querySelector('[data-stay-another]');
    if (anotherStay) {
      var hasStay = !!(window.itinStayLines || []).length;
      anotherStay.textContent = hasStay ? 'Add another place' : 'Add place';
    }
    document.querySelectorAll('[data-stay-count]').forEach(function (badge) {
      var id = String(badge.getAttribute('data-stay-count') || '');
      var count = 0;
      (window.itinStayLines || []).forEach(function (line) {
        if (stayIsMulti() && lineDay(line) !== stayActiveDay) return;
        if (String(line.business_id) === id) count += Number(line.units) || 0;
      });
      badge.hidden = declined || count < 1;
      badge.textContent = count > 0 ? String(count) : '';
    });
    document.querySelectorAll('[data-stay-room]').forEach(function (room) {
      room.classList.remove('is-party-full');
    });
    if (stayModalEl) stayModalEl.removeAttribute('data-party-full');
    document.querySelectorAll('[data-stay-add]').forEach(function (btn) {
      var id = String(btn.getAttribute('data-stay-add') || '');
      var units = 0;
      (window.itinStayLines || []).forEach(function (line) {
        if (stayIsMulti() && lineDay(line) !== stayActiveDay) return;
        if (String(line.service_id) === id) units = Number(line.units) || 0;
      });
      var stockFull = btn.getAttribute('data-stay-stock') === '0';
      btn.disabled = stockFull;
      btn.classList.remove('is-locked');
      btn.removeAttribute('aria-disabled');
      if (btn.getAttribute('title') === 'All guests already have a room') btn.removeAttribute('title');
      if (!stockFull) btn.textContent = units > 0 ? 'Add another' : 'Add room';
      var chosen = document.querySelector('[data-stay-chosen="' + id + '"]');
      if (chosen) {
        chosen.hidden = declined || units < 1;
        chosen.textContent = units ? (units + (units === 1 ? ' room' : ' rooms')) : '';
      }
    });
    syncStayRideFields();
    commitStayBar();
    if (typeof window.itinRenderBill === 'function') window.itinRenderBill();
    scheduleStayDraft();
  }
  function commitStayBar() {
    var declined = !!document.querySelector('input[name="stayRideAcc"][value="0"]:checked');
    var quote = window.itinStayQuote;
    var viewTotal = document.querySelector('[data-stay-view-total]');
    var viewCount = document.querySelector('[data-stay-view-count]');
    var label = quote ? quote.amount_label : formatPeso(0);
    var count = quote ? quote.label : 'Nothing added yet';
    if (viewTotal) viewTotal.textContent = declined ? formatPeso(0) : label;
    if (viewCount) viewCount.textContent = declined ? 'Not needed' : count;
  }
  function roomIncludedGuests(room) {
    var cap = room && Number(room.capacity) > 0 ? Number(room.capacity) : 1;
    return cap;
  }
  function guestsForNewRoom(room, quote) {
    var included = roomIncludedGuests(room);
    var left = quote && typeof quote.left === 'number' ? quote.left : stayPartyCount();
    if (left > 0) return Math.max(1, Math.min(included, left));
    return included;
  }
  function freeGuestsForRoom(lines, serviceId, roomIndex, day) {
    var party = stayPartyCount();
    function total() {
      var sum = 0;
      lines.forEach(function (line) {
        if (stayIsMulti() && lineDay(line) !== day) return;
        (line.guests || []).forEach(function (g) { sum += Number(g) || 0; });
      });
      return sum;
    }
    var guard = 0;
    while (total() > party && guard < 400) {
      guard++;
      var cut = false;
      for (var i = 0; i < lines.length && !cut; i++) {
        if (stayIsMulti() && lineDay(lines[i]) !== day) continue;
        var guests = lines[i].guests || [];
        for (var j = 0; j < guests.length; j++) {
          if (String(lines[i].service_id) === String(serviceId) && j === roomIndex) continue;
          if (guests[j] > 1) {
            guests[j]--;
            cut = true;
            break;
          }
        }
      }
      if (!cut) break;
    }
  }
  function stayDaySkipped(day) {
    return (window.itinStaySkipDays || []).indexOf(day) !== -1;
  }
  function skipStayDay(day) {
    window.itinStaySkipDays = window.itinStaySkipDays || [];
    if (window.itinStaySkipDays.indexOf(day) === -1) window.itinStaySkipDays.push(day);
    window.itinStayLines = (window.itinStayLines || []).filter(function (line) { return lineDay(line) !== day; });
    renderStayTray();
  }
  function unskipStayDay(day) {
    window.itinStaySkipDays = (window.itinStaySkipDays || []).filter(function (item) { return item !== day; });
    renderStayTray();
  }
  function copyStayDay(fromDay, toDay) {
    window.itinStaySkipDays = (window.itinStaySkipDays || []).filter(function (item) { return item !== toDay; });
    var lines = (window.itinStayLines || []).map(copyStayLine);
    lines = lines.filter(function (line) { return lineDay(line) !== toDay; });
    (window.itinStayLines || []).forEach(function (line) {
      if (lineDay(line) !== fromDay) return;
      var room = stayCatalogMap()[String(line.service_id)];
      var max = lineMaxUnits(room, toDay);
      if (!room || max < 1) return;
      var copy = copyStayLine(line);
      copy.day = toDay;
      var units = Math.min(copy.guests.length || copy.units || 1, max);
      copy.guests = (copy.guests || [1]).slice(0, units);
      copy.units = copy.guests.length;
      lines.push(copy);
    });
    window.itinStayLines = lines;
    renderStayTray();
  }
  function addStayRoom(id) {
    var room = stayCatalogMap()[String(id)];
    if (!room) return;
    var day = stayIsMulti() ? stayActiveDay : 0;
    window.itinStaySkipDays = (window.itinStaySkipDays || []).filter(function (item) { return item !== day; });
    var max = lineMaxUnits(room, day);
    if (max < 1) return;
    var lines = window.itinStayLines || [];
    var quote = null;
    if (lines.length) {
      quote = quoteStayLines(lines);
      lines = quote.lines.map(copyStayLine);
    }
    var start = guestsForNewRoom(room, quote);
    var line = null;
    lines.forEach(function (item) {
      if (String(item.service_id) !== String(id)) return;
      if (stayIsMulti() && lineDay(item) !== day) return;
      line = item;
    });
    var roomIndex = 0;
    if (line) {
      if (line.units >= max) return;
      if (!Array.isArray(line.guests)) line.guests = [];
      line.guests.push(start);
      line.units = line.guests.length;
      roomIndex = line.guests.length - 1;
    } else {
      var fresh = { business_id: room.business_id, service_id: room.id, units: 1, guests: [start], extra_guests: 0 };
      if (day > 0) fresh.day = day;
      lines.push(fresh);
    }
    freeGuestsForRoom(lines, id, roomIndex, day);
    window.itinStayLines = lines;
    var none = document.querySelector('input[name="stayRideAcc"][value="0"]');
    if (none) none.checked = false;
    renderStayTray();
    setStayPop('open');
  }
  function setStayPop(mode) {
    var pop = document.querySelector('[data-stay-pop]');
    if (!pop) return;
    if (mode !== 'open' && mode !== 'min') mode = 'closed';
    pop.setAttribute('data-stay-pop', mode);
  }
  function stepStayLine(id, kind, dir, index, dayAttr) {
    var room = stayCatalogMap()[String(id)];
    var day = stayIsMulti() ? (parseInt(dayAttr, 10) || stayActiveDay) : 0;
    var quote = quoteStayLines(window.itinStayLines || []);
    var lines = quote.lines.map(copyStayLine);
    var dayLeft = quote.leftByDay && quote.leftByDay[String(day)] !== undefined ? quote.leftByDay[String(day)] : (quote.left || 0);
    lines.forEach(function (line) {
      if (String(line.service_id) !== String(id)) return;
      if (stayIsMulti() && lineDay(line) !== day) return;
      if (!Array.isArray(line.guests) || !line.guests.length) line.guests = [1];
      if (kind === 'units') {
        var max = lineMaxUnits(room, day);
        if (dir > 0) {
          if (max < 1 || line.units >= max) return;
          line.guests.push(guestsForNewRoom(room, { left: dayLeft }));
          freeGuestsForRoom(lines, id, line.guests.length - 1, day);
        } else if (line.guests.length > 1) {
          line.guests.pop();
        }
        line.units = line.guests.length;
      } else {
        var at = parseInt(index, 10);
        if (isNaN(at) || at < 0 || at >= line.guests.length) return;
        if (dir < 0) line.guests[at] = Math.max(1, line.guests[at] - 1);
        else if (dayLeft > 0) line.guests[at] = Math.min(roomGuestCeiling(room), line.guests[at] + 1);
      }
    });
    window.itinStayLines = lines;
    renderStayTray();
  }
  function rideCatalogMap() {
    if (window.itinRideCatalog && Object.keys(window.itinRideCatalog).length) return window.itinRideCatalog;
    var node = document.getElementById('itinRideCatalog');
    var list = [];
    try { list = JSON.parse((node && node.textContent) || '[]'); } catch (err) { list = []; }
    var map = {};
    (Array.isArray(list) ? list : []).forEach(function (car) {
      car.fee = 0;
      car.extra_per = 0;
      car.seat_limit = true;
      map[String(car.id)] = car;
    });
    document.querySelectorAll('[data-ride-room]').forEach(function (card) {
      var id = String(card.getAttribute('data-ride-room') || '');
      if (!id || map[id]) return;
      var freeRaw = card.getAttribute('data-free');
      var capRaw = card.getAttribute('data-capacity');
      map[id] = {
        id: Number(id),
        business_id: Number(card.getAttribute('data-business-id') || 0),
        business_name: card.getAttribute('data-business-name') || '',
        name: card.getAttribute('data-room-name') || 'Vehicle',
        price: Number(card.getAttribute('data-price') || 0),
        capacity: capRaw ? Number(capRaw) : null,
        fee: 0,
        extra_per: 0,
        seat_limit: true,
        free: freeRaw === '' || freeRaw == null ? null : Number(freeRaw)
      };
    });
    window.itinRideCatalog = map;
    return map;
  }
  function rideSpanCount() {
    var tray = document.querySelector('[data-ride-tray]');
    var n = tray ? parseInt(tray.getAttribute('data-nights') || '1', 10) : 1;
    return n > 0 ? n : 1;
  }
  function quoteRideLines(lines) {
    return quoteStayLines(lines, {
      catalog: rideCatalogMap(),
      spans: rideSpanCount(),
      noun: 'vehicle',
      spanWord: 'day',
      openDay: rideActiveDay
    });
  }
  function renderRideTray() {
    var box = document.querySelector('[data-ride-lines]');
    var totalEl = document.querySelector('[data-ride-total]');
    var holdEl = document.querySelector('[data-ride-hold]');
    var leftEl = document.querySelector('[data-ride-left]');
    var leftLabel = document.querySelector('[data-ride-left-label]');
    if (!box) {
      syncStayRideFields();
      return;
    }
    var declined = !!document.querySelector('input[name="stayRideTrn"][value="0"]:checked');
    var quote = (window.itinRideLines || []).length ? quoteRideLines(window.itinRideLines) : null;
    window.itinRideQuote = (!declined && quote && quote.lines.length) ? quote : null;
    if (window.itinRideQuote) {
      window.itinRideLines = quote.lines.map(function (line) { return copyStayLine(line); });
    }
    while (box.firstChild) box.removeChild(box.firstChild);
    if (declined) {
      var skipped = document.createElement('p');
      skipped.className = 'stay-build__empty';
      skipped.textContent = 'Transportation is not needed for this tour.';
      box.appendChild(skipped);
    } else if (!window.itinRideQuote) {
      var empty = document.createElement('p');
      empty.className = 'stay-build__empty';
      empty.textContent = 'Add a vehicle. It takes the guests who still need a seat, up to its seats.';
      box.appendChild(empty);
    } else {
      appendDayGroups(box, window.itinRideQuote.lines, 'vehicle', rideActiveDay);
    }
    var tripLabel = window.itinRideQuote ? window.itinRideQuote.amount_label : formatPeso(0);
    var tripCount = window.itinRideQuote ? window.itinRideQuote.label : 'Nothing added yet';
    if (totalEl) totalEl.textContent = declined ? formatPeso(0) : tripLabel;
    var viewTotal = document.querySelector('[data-ride-view-total]');
    var viewCount = document.querySelector('[data-ride-view-count]');
    if (viewTotal) viewTotal.textContent = declined ? formatPeso(0) : tripLabel;
    if (viewCount) viewCount.textContent = declined ? 'Not needed' : tripCount;
    if (holdEl) {
      if (declined || rideDaySkipped(rideActiveDay)) {
        holdEl.hidden = true;
      } else {
        var left = window.itinRideQuote && typeof window.itinRideQuote.left === 'number'
          ? window.itinRideQuote.left
          : stayPartyCount();
        holdEl.hidden = false;
        holdEl.classList.remove('is-note');
        if (leftEl) {
          leftEl.hidden = false;
          leftEl.textContent = String(left);
        }
        if (leftLabel) leftLabel.textContent = (left === 1 ? 'guest still to accommodate' : 'guests still to accommodate') + (stayIsMulti() ? ' on day ' + rideActiveDay : '');
        var dayTotalEl = document.querySelector('[data-ride-day-total]');
        if (dayTotalEl) dayTotalEl.textContent = dayAmountLabel(window.itinRideQuote, rideActiveDay);
      }
    }
    paintDayChips('ride');
    paintDayStock('ride');
    document.querySelectorAll('[data-ride-count]').forEach(function (badge) {
      var id = String(badge.getAttribute('data-ride-count') || '');
      var count = 0;
      (window.itinRideLines || []).forEach(function (line) {
        if (stayIsMulti() && lineDay(line) !== rideActiveDay) return;
        if (String(line.business_id) === id) count += Number(line.units) || 0;
      });
      badge.hidden = declined || count < 1;
      badge.textContent = count > 0 ? String(count) : '';
    });
    document.querySelectorAll('[data-ride-add]').forEach(function (btn) {
      var id = String(btn.getAttribute('data-ride-add') || '');
      var units = 0;
      (window.itinRideLines || []).forEach(function (line) {
        if (stayIsMulti() && lineDay(line) !== rideActiveDay) return;
        if (String(line.service_id) === id) units = Number(line.units) || 0;
      });
      var stockFull = btn.getAttribute('data-ride-stock') === '0';
      btn.disabled = stockFull;
      if (!stockFull) btn.textContent = units > 0 ? 'Add another' : 'Add vehicle';
      var chosen = document.querySelector('[data-ride-chosen="' + id + '"]');
      if (chosen) {
        chosen.hidden = declined || units < 1;
        chosen.textContent = units ? (units + (units === 1 ? ' vehicle' : ' vehicles')) : '';
      }
    });
    syncStayRideFields();
    if (typeof window.itinRenderBill === 'function') window.itinRenderBill();
    scheduleRideDraft();
  }
  function setRidePop(mode) {
    var pop = document.querySelector('[data-ride-pop]');
    if (!pop) return;
    pop.setAttribute('data-ride-pop', mode === 'open' ? 'open' : 'closed');
  }
  function guestsForNewVehicle(car, quote) {
    var seats = car && Number(car.capacity) > 0 ? Number(car.capacity) : 1;
    var left = quote && typeof quote.left === 'number' ? quote.left : stayPartyCount();
    if (left < 1) return 1;
    return Math.max(1, Math.min(seats, left));
  }
  function rideDaySkipped(day) {
    return (window.itinRideSkipDays || []).indexOf(day) !== -1;
  }
  function skipRideDay(day) {
    window.itinRideSkipDays = window.itinRideSkipDays || [];
    if (window.itinRideSkipDays.indexOf(day) === -1) window.itinRideSkipDays.push(day);
    window.itinRideLines = (window.itinRideLines || []).filter(function (line) { return lineDay(line) !== day; });
    renderRideTray();
  }
  function unskipRideDay(day) {
    window.itinRideSkipDays = (window.itinRideSkipDays || []).filter(function (item) { return item !== day; });
    renderRideTray();
  }
  function copyRideDay(fromDay, toDay) {
    window.itinRideSkipDays = (window.itinRideSkipDays || []).filter(function (item) { return item !== toDay; });
    var lines = (window.itinRideLines || []).map(copyStayLine);
    lines = lines.filter(function (line) { return lineDay(line) !== toDay; });
    (window.itinRideLines || []).forEach(function (line) {
      if (lineDay(line) !== fromDay) return;
      var car = rideCatalogMap()[String(line.service_id)];
      var max = lineMaxUnits(car, toDay);
      if (!car || max < 1) return;
      var copy = copyStayLine(line);
      copy.day = toDay;
      var units = Math.min(copy.guests.length || copy.units || 1, max);
      copy.guests = (copy.guests || [1]).slice(0, units);
      copy.units = copy.guests.length;
      lines.push(copy);
    });
    window.itinRideLines = lines;
    renderRideTray();
  }
  function addRideVehicle(id) {
    var car = rideCatalogMap()[String(id)];
    if (!car) return;
    var day = stayIsMulti() ? rideActiveDay : 0;
    window.itinRideSkipDays = (window.itinRideSkipDays || []).filter(function (item) { return item !== day; });
    var max = lineMaxUnits(car, day);
    if (max < 1) return;
    var lines = window.itinRideLines || [];
    var quote = null;
    if (lines.length) {
      quote = quoteRideLines(lines);
      lines = quote.lines.map(copyStayLine);
    }
    var start = guestsForNewVehicle(car, quote);
    var line = null;
    lines.forEach(function (item) {
      if (String(item.service_id) !== String(id)) return;
      if (stayIsMulti() && lineDay(item) !== day) return;
      line = item;
    });
    var vehicleIndex = 0;
    if (line) {
      if (line.units >= max) return;
      if (!Array.isArray(line.guests)) line.guests = [];
      line.guests.push(start);
      line.units = line.guests.length;
      vehicleIndex = line.guests.length - 1;
    } else {
      var fresh = { business_id: car.business_id, service_id: car.id, units: 1, guests: [start], extra_guests: 0 };
      if (day > 0) fresh.day = day;
      lines.push(fresh);
    }
    freeGuestsForRoom(lines, id, vehicleIndex, day);
    window.itinRideLines = lines;
    var none = document.querySelector('input[name="stayRideTrn"][value="0"]');
    if (none) none.checked = false;
    renderRideTray();
    setRidePop('open');
  }
  function stepRideLine(id, kind, dir, index, dayAttr) {
    var car = rideCatalogMap()[String(id)];
    var day = stayIsMulti() ? (parseInt(dayAttr, 10) || rideActiveDay) : 0;
    var quote = quoteRideLines(window.itinRideLines || []);
    var lines = quote.lines.map(copyStayLine);
    var dayLeft = quote.leftByDay && quote.leftByDay[String(day)] !== undefined ? quote.leftByDay[String(day)] : (quote.left || 0);
    lines.forEach(function (line) {
      if (String(line.service_id) !== String(id)) return;
      if (stayIsMulti() && lineDay(line) !== day) return;
      if (!Array.isArray(line.guests) || !line.guests.length) line.guests = [1];
      if (kind === 'units') {
        var max = lineMaxUnits(car, day);
        if (dir > 0) {
          if (max < 1 || line.units >= max) return;
          line.guests.push(guestsForNewVehicle(car, { left: dayLeft }));
          freeGuestsForRoom(lines, id, line.guests.length - 1, day);
        } else if (line.guests.length > 1) {
          line.guests.pop();
        }
        line.units = line.guests.length;
      } else {
        var at = parseInt(index, 10);
        if (isNaN(at) || at < 0 || at >= line.guests.length) return;
        if (dir < 0) line.guests[at] = Math.max(1, line.guests[at] - 1);
        else if (dayLeft > 0) line.guests[at] = Math.min(roomGuestCeiling(car), line.guests[at] + 1);
      }
    });
    window.itinRideLines = lines;
    renderRideTray();
  }
  function formatPeso(n) {
    var v = Math.round((Number(n) || 0) * 100) / 100;
    return '₱' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function sideBill(groupName) {
    if (groupName === 'stayRideAcc' || groupName === 'stayRideTrn') {
      var declinedName = groupName === 'stayRideAcc' ? 'stayRideAcc' : 'stayRideTrn';
      if (document.querySelector('input[name="' + declinedName + '"][value="0"]:checked')) {
        return { text: 'Not needed', amount: 0, ok: true, detail: '' };
      }
      var built = groupName === 'stayRideAcc' ? window.itinStayQuote : window.itinRideQuote;
      if (built && built.lines && built.lines.length) {
        return {
          text: built.label + ' · ' + built.amount_label,
          amount: Number(built.amount) || 0,
          ok: built.ok !== false,
          detail: built.detail || ''
        };
      }
    }
    var checked = document.querySelector('input[name="' + groupName + '"]:checked');
    if (!checked || String(checked.value) === '0') return { text: 'Not needed', amount: 0, ok: true, detail: '' };
    var sid = checked.getAttribute('data-service-id') || '';
    if (!sid) return { text: 'Not needed', amount: 0, ok: true, detail: '' };
    var q = (window.itinOfferQuotes || {})[sid];
    if (!q) return { text: 'Not chosen', amount: 0, ok: true, detail: '' };
    if (!q.ok) return { text: q.message || 'Not enough left for these dates.', amount: 0, ok: false, detail: '' };
    return { text: q.label + ' · ' + q.amount_label, amount: Number(q.amount) || 0, ok: true, detail: q.detail || '' };
  }
  function applyOfferQuotes(offers) {
    window.itinOfferQuotes = offers || {};
    document.querySelectorAll('[data-offer-quote]').forEach(function (el) {
      var q = window.itinOfferQuotes[el.getAttribute('data-offer-quote')];
      var card = el.closest('.pick-card');
      var input = card ? card.querySelector('input') : null;
      if (!q) return;
      if (q.ok) {
        el.textContent = q.label + ' · ' + q.amount_label;
        if (card) card.classList.remove('is-full');
        if (input) input.disabled = false;
      } else {
        el.textContent = q.message || 'Not enough left for these dates.';
        if (card) card.classList.add('is-full');
        if (input) input.disabled = true;
      }
    });
    window.itinRenderBill();
  }
  window.itinRenderBill = function () {
    var guestsEl = document.querySelector('[data-bill-guests]');
    var n = partyEl ? parseInt(String(partyEl.value || '').trim(), 10) : 0;
    if (guestsEl) guestsEl.textContent = (n && n >= 1) ? String(n) : '—';
    var bill = document.querySelector('[data-guide-bill]');
    var rideSpans = bill ? parseInt(bill.getAttribute('data-ride-spans') || '1', 10) : 1;
    if (!rideSpans || rideSpans < 1) rideSpans = 1;
    var g = window.itinBillGuide || null;
    var guideAmount = g ? (Number(g.rate_amount) || 0) * rideSpans : 0;
    var guideEl = document.querySelector('[data-bill-guide]');
    var stayEl = document.querySelector('[data-bill-stay]');
    var rideEl = document.querySelector('[data-bill-ride]');
    var totalEl = document.querySelector('[data-bill-total]');
    var warnEl = document.querySelector('[data-bill-warn]');
    if (guideEl) {
      guideEl.textContent = g
        ? formatPeso(guideAmount) + ' · ' + rideSpans + (rideSpans === 1 ? ' day' : ' days')
        : '—';
    }
    var stay = sideBill('stayRideAcc');
    var ride = sideBill('stayRideTrn');
    if (stayEl) stayEl.textContent = stay.text;
    if (rideEl) rideEl.textContent = ride.text;
    var stayMath = document.querySelector('[data-bill-stay-math]');
    var rideMath = document.querySelector('[data-bill-ride-math]');
    if (stayMath) {
      stayMath.hidden = !stay.detail;
      stayMath.textContent = stay.detail || '';
    }
    if (rideMath) {
      rideMath.hidden = !ride.detail;
      rideMath.textContent = ride.detail || '';
    }
    if (totalEl) totalEl.textContent = g ? formatPeso(guideAmount + stay.amount + ride.amount) : '—';
    var blocked = !stay.ok || !ride.ok;
    window.itinBillBlocked = blocked;
    if (warnEl) {
      warnEl.hidden = !blocked;
      warnEl.textContent = !stay.ok ? stay.text : (ride.ok ? '' : ride.text);
    }
    var proceedBtn = document.getElementById('itinBookProceed');
    if (proceedBtn) proceedBtn.disabled = blocked;
  };
  document.addEventListener('change', function (e) {
    if (e.target && (e.target.name === 'stayRideAcc' || e.target.name === 'stayRideTrn')) {
      window.itinRenderBill();
    }
  });
  function savePackageLimits() {
    if (!partyEl || !itinId) return;
    var minEl = document.getElementById('packageMinPax');
    var priceEl = document.getElementById('packagePriceField');
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'save_package_limits');
    body.append('itinerary_id', String(itinId));
    body.append('package_min_pax', minEl ? String(minEl.value || '').trim() : '');
    body.append('package_max_pax', String(partyEl.value || '').trim());
    body.append('package_price', priceEl ? String(priceEl.value || '').trim() : '');
    if (!body.get('package_min_pax') && !body.get('package_max_pax') && !body.get('package_price')) return;
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: body,
      credentials: 'same-origin'
    }).catch(function () {});
  }
  function savePartySize() {
    if (document.body.classList.contains('package-maker')) {
      savePackageLimits();
      return;
    }
    if (!partyEl || partyEl.readOnly || !itinId) return;
    var n = parseInt(String(partyEl.value || '').trim(), 10);
    if (!n || n < 1 || n > 20) return;
    var body = new FormData();
    body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
    body.append('action', 'update_party_size');
    body.append('itinerary_id', String(itinId));
    body.append('party_size', String(n));
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: body,
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (!data || !data.ok || !data.offers) return;
      var bill = document.querySelector('[data-guide-bill]');
      if (bill && data.ride_spans) bill.setAttribute('data-ride-spans', String(data.ride_spans));
      applyOfferQuotes(data.offers);
    }).catch(function () {});
  }
  (function loadOfferQuotes() {
    var node = document.getElementById('itinOfferQuotes');
    if (!node) return;
    try { applyOfferQuotes(JSON.parse(node.textContent || '{}')); } catch (err) {}
  })();
  if (partyEl) {
    partyEl.addEventListener('input', function () {
      if (typeof renderStayTray === 'function') renderStayTray();
      if (typeof renderRideTray === 'function') renderRideTray();
      syncBookingDetailsPreview();
      if (syncPartySize()) {
        partyEl.classList.remove('is-invalid');
        var gfb = document.querySelector('[data-itin-guest-feedback]');
        if (gfb) gfb.hidden = true;
      }
      clearTimeout(partySaveTimer);
      partySaveTimer = setTimeout(savePartySize, 400);
    });
    partyEl.addEventListener('change', function () {
      syncBookingDetailsPreview();
      clearTimeout(partySaveTimer);
      savePartySize();
    });
  }
  if (document.body.classList.contains('package-maker')) {
    ['packageMinPax', 'packagePriceField'].forEach(function (id) {
      var field = document.getElementById(id);
      if (!field) return;
      field.addEventListener('input', function () {
        clearTimeout(partySaveTimer);
        partySaveTimer = setTimeout(savePackageLimits, 400);
      });
      field.addEventListener('change', savePackageLimits);
    });
  }
  var meetupInputEl = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
  var meetupClockEl = document.querySelector('[data-itin-day-meetup="1"] [data-itin-day-meetup-clock]');
  if (meetupClockEl) {
    meetupClockEl.addEventListener('input', syncBookingDetailsPreview);
    meetupClockEl.addEventListener('change', syncBookingDetailsPreview);
  }
  document.addEventListener('input', function (e) {
    if (e.target && e.target.closest && e.target.closest('[data-itin-day-meetup-clock]')) {
      if (window.itinSyncMeetupTimeFeedback) window.itinSyncMeetupTimeFeedback();
    }
  });
  document.addEventListener('change', function (e) {
    if (!e.target || !e.target.closest) return;
    if (e.target.closest('[data-itin-day-meetup-clock]')) {
      if (window.itinSyncMeetupTimeFeedback) window.itinSyncMeetupTimeFeedback();
    }
    var dayDate = e.target.closest('[data-itin-day-date-input]');
    if (dayDate && String(dayDate.value || '').trim()) {
      dayDate.classList.remove('is-invalid');
      var sec = dayDate.closest('[data-itin-day-section]');
      var dfb = sec && sec.querySelector('[data-itin-day-date-feedback]');
      if (dfb && /set a date/i.test(dfb.textContent || '')) dfb.hidden = true;
    }
  });
  if (meetupInputEl && !meetupClockEl) {
    meetupInputEl.addEventListener('input', syncBookingDetailsPreview);
    meetupInputEl.addEventListener('change', syncBookingDetailsPreview);
  }
  var multiEl = document.getElementById('itinEditMultiday');
  var dayCountEl = document.querySelector('[data-itin-day-count]');
  if (multiEl) multiEl.addEventListener('change', syncBookingDetailsPreview);
  if (dayCountEl) dayCountEl.addEventListener('change', syncBookingDetailsPreview);
  var titleInputEl = document.querySelector('#itinRenameForm input[name="title"]');
  if (titleInputEl) {
    titleInputEl.addEventListener('input', function () {
      syncBookingDetailsPreview();
      if (titleIsReady()) {
        titleInputEl.classList.remove('is-invalid');
        var tfb = document.querySelector('[data-itin-title-feedback]');
        if (tfb) tfb.hidden = true;
      }
    });
    titleInputEl.addEventListener('change', syncBookingDetailsPreview);
  }
  syncBookingDetailsPreview();
  function setFb(el, show, msg) {
    if (!el) return;
    if (msg) el.textContent = msg;
    el.hidden = !show;
  }
  function titleIsReady() {
    var titleEl = document.querySelector('#itinRenameForm input[name="title"]');
    var titleVal = titleEl ? String(titleEl.value || '').trim() : '';
    return !!(titleVal && titleVal.toLowerCase() !== 'unnamed');
  }
  function guideIsPicked() {
    if (itinPickedGuide && itinPickedGuide.id) return true;
    var preview = document.getElementById('itinGuidePreview');
    return !!(preview && !preview.classList.contains('is-empty'));
  }
  function routeStopCount() {
    return document.querySelectorAll('[data-itin-stop]').length;
  }
  function hasRouteStops() {
    return routeStopCount() > 0;
  }
  function meetupPlaceIsSet(card) {
    var nameEl = card.querySelector('[data-itin-meetup-place-name]');
    var label = nameEl ? String(nameEl.textContent || '').trim() : '';
    return label !== '' && !/^meetup not set$/i.test(label);
  }
  function applyMeetupPlaceFeedback(forceShowEmpty) {
    var firstEmpty = null;
    document.querySelectorAll('[data-itin-day-meetup]').forEach(function (card) {
      var fb = card.querySelector('[data-itin-day-meetup-place-feedback]');
      var ok = meetupPlaceIsSet(card);
      if (ok) {
        setFb(fb, false);
        return;
      }
      if (forceShowEmpty) {
        setFb(fb, true, 'Meetup place not set');
        if (!firstEmpty) firstEmpty = card.querySelector('[data-itin-edit-meetup-place]') || card;
      }
    });
    return firstEmpty;
  }
  function applyDayDateFeedback(forceShowEmpty) {
    if (packageTouristPicksDate()) {
      document.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
        var input = sec.querySelector('[data-itin-day-date-input]');
        var fb = sec.querySelector('[data-itin-day-date-feedback]');
        if (input) input.classList.remove('is-invalid');
        if (fb && /set a date/i.test(fb.textContent || '')) {
          fb.hidden = true;
          fb.textContent = '';
        }
      });
      return null;
    }
    var firstEmpty = null;
    document.querySelectorAll('[data-itin-day-section]').forEach(function (sec) {
      var input = sec.querySelector('[data-itin-day-date-input]');
      var fb = sec.querySelector('[data-itin-day-date-feedback]');
      if (!input) return;
      var empty = !String(input.value || '').trim();
      if (!empty) {
        input.classList.remove('is-invalid');
        if (fb && !fb.textContent) setFb(fb, false);
        else if (fb && /set a date/i.test(fb.textContent || '')) setFb(fb, false);
        return;
      }
      if (forceShowEmpty) {
        input.classList.add('is-invalid');
        setFb(fb, true, 'Set a date for this day.');
        if (!firstEmpty) firstEmpty = input;
      }
    });
    return firstEmpty;
  }
  var packageDateBox = document.getElementById('packageDateTourist');
  if (packageDateBox) {
    packageDateBox.addEventListener('change', function () {
      if (packageTouristPicksDate()) applyDayDateFeedback(false);
    });
    if (packageTouristPicksDate()) applyDayDateFeedback(false);
  }
  function needDateOrGuests(btn, opts) {
    opts = opts || {};
    var requireGuests = opts.requireGuests !== false;
    var requireMeetup = opts.requireMeetup !== false;
    var requireTitle = opts.requireTitle !== false;
    var requireGuide = opts.requireGuide === true;
    var requireStops = opts.requireStops === true;
    var hasDate = !!(dateEl && String(dateEl.value || '').trim()) || packageTouristPicksDate();
    var blocked = dateErrorVisible() && !packageTouristPicksDate();
    if (blocked && !requireMeetup) {
      var fbMeetup = document.querySelector('[data-itin-date-feedback]');
      var msg = fbMeetup ? String(fbMeetup.textContent || '') : '';
      if (/meetup/i.test(msg)) {
        if (dateEl) dateEl.classList.remove('is-invalid');
        if (fbMeetup) fbMeetup.hidden = true;
        blocked = false;
        if (window.itinSyncBookButton) window.itinSyncBookButton();
      }
    }
    if (btn && (btn.disabled || btn.hasAttribute('data-itin-book-blocked'))) {
      return 'blocked';
    }
    if (blocked) return 'blocked';

    var firstFocus = null;
    var reason = '';
    function miss(el, why) {
      if (!reason) reason = why;
      if (!firstFocus && el) firstFocus = el;
    }

    if (requireTitle) {
      var titleEl = document.querySelector('#itinRenameForm input[name="title"]');
      var tfb = document.querySelector('[data-itin-title-feedback]');
      if (!titleIsReady()) {
        if (titleEl) titleEl.classList.add('is-invalid');
        setFb(tfb, true, 'Name this itinerary before booking.');
        miss(titleEl, 'need-title');
      } else {
        if (titleEl) titleEl.classList.remove('is-invalid');
        setFb(tfb, false);
      }
    }

    if (requireGuests) {
      var gfb = document.querySelector('[data-itin-guest-feedback]');
      if (partyEl && !syncPartySize()) {
        if (partyEl) partyEl.classList.add('is-invalid');
        setFb(gfb, true, document.body.classList.contains('package-maker') ? 'Enter the participants.' : 'Enter the number of guests.');
        miss(partyEl, 'need-guests');
      } else if (partyEl) {
        partyEl.classList.remove('is-invalid');
        setFb(gfb, false);
      }
    }

    if (requireGuide) {
      var preview = document.getElementById('itinGuidePreview');
      var gufb = document.querySelector('[data-itin-guide-feedback]');
      if (!guideIsPicked()) {
        if (preview) preview.classList.add('is-invalid');
        setFb(gufb, true, 'Pick a tour guide.');
        miss(preview, 'need-guide');
      } else {
        if (preview) preview.classList.remove('is-invalid');
        setFb(gufb, false);
      }
    }

    var stopsFb = document.querySelector('[data-itin-stops-feedback]');
    var stopsCard = document.querySelector('.itin-builder__stops-card');
    if (requireStops) {
      if (!hasRouteStops()) {
        setFb(stopsFb, true, 'Add at least one stop before booking a guide.');
        if (stopsCard) stopsCard.classList.add('is-invalid');
        miss(stopsCard || document.querySelector('[data-itin-place-drop-root]'), 'need-stops');
      } else {
        setFb(stopsFb, false);
        if (stopsCard) stopsCard.classList.remove('is-invalid');
      }
    } else if (hasRouteStops()) {
      setFb(stopsFb, false);
      if (stopsCard) stopsCard.classList.remove('is-invalid');
    }

    if (!hasDate) {
      var dfb = document.querySelector('[data-itin-date-feedback]');
      if (dateEl) {
        dateEl.classList.add('is-invalid');
        setFb(dfb, true, 'Set a tour date before booking a guide.');
        miss(dateEl, 'need-date');
      }
    } else if (dateEl) {
      dateEl.classList.remove('is-invalid');
      var dfbOk = document.querySelector('[data-itin-date-feedback]');
      if (dfbOk && /tour date/i.test(dfbOk.textContent || '')) setFb(dfbOk, false);
    }

    if (hasDate) {
      var emptyDayDate = applyDayDateFeedback(true);
      if (emptyDayDate) miss(emptyDayDate, 'need-day-date');
    }

    if (requireMeetup) {
      var emptyPlace = applyMeetupPlaceFeedback(true);
      if (emptyPlace) miss(emptyPlace, 'need-meetup-place');
      var emptyMeetup = applyMeetupTimeFeedback(true);
      if (emptyMeetup) miss(emptyMeetup, 'need-meetup');
    }

    if (firstFocus) {
      if (firstFocus.focus) firstFocus.focus({ preventScroll: false });
      if (firstFocus.scrollIntoView) firstFocus.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return reason;
  }

  function applyMeetupTimeFeedback(forceShowEmpty) {
    var firstEmpty = null;
    document.querySelectorAll('[data-itin-day-meetup]').forEach(function (card) {
      var clock = card.querySelector('[data-itin-day-meetup-clock]');
      var fb = card.querySelector('[data-itin-day-meetup-feedback]');
      var empty = !clock || !String(clock.value || '').trim();
      if (!empty) {
        if (clock) clock.classList.remove('is-invalid');
        if (fb) fb.hidden = true;
        return;
      }
      if (forceShowEmpty) {
        if (clock) clock.classList.add('is-invalid');
        if (fb) {
          fb.textContent = 'Meetup time not set';
          fb.hidden = false;
        }
        if (!firstEmpty && clock) firstEmpty = clock;
      }
    });
    return firstEmpty;
  }
  window.itinSyncMeetupTimeFeedback = function () {
    applyMeetupTimeFeedback(false);
  };
  window.itinSyncMeetupPlaceFeedback = function () {
    applyMeetupPlaceFeedback(false);
  };
  function packageGuestMessage(which) {
    var minEl = document.getElementById('packageMinPax');
    var maxEl = document.getElementById('itinPartySize');
    var minN = minEl ? parseInt(String(minEl.value || '').trim(), 10) : 0;
    var maxN = maxEl ? parseInt(String(maxEl.value || '').trim(), 10) : 0;
    var checkMin = which !== 'max';
    var checkMax = which !== 'min';
    var minBad = checkMin && (!minN || minN < 1);
    var maxBad = checkMax && (!maxN || maxN < 1);
    var orderBad = minN >= 1 && maxN >= 1 && minN > maxN && (checkMin || checkMax);
    var guestMsg = '';
    if (minBad) guestMsg = 'Enter the minimum participants.';
    else if (maxBad) guestMsg = 'Enter the maximum participants.';
    else if (orderBad) guestMsg = 'Minimum participants cannot be more than the maximum.';
    if (minEl) minEl.classList.toggle('is-invalid', minBad || (orderBad && checkMin));
    if (maxEl) maxEl.classList.toggle('is-invalid', maxBad || (orderBad && checkMax));
    setFb(document.querySelector('[data-itin-guest-feedback]'), !!guestMsg, guestMsg);
    if (minBad) return minEl;
    if (maxBad) return maxEl;
    if (orderBad) return minEl || maxEl;
    return null;
  }
  function packagePriceMessage() {
    var priceEl = document.getElementById('packagePriceField');
    var price = priceEl ? parseFloat(String(priceEl.value || '').trim()) : 0;
    var priceMsg = (!isFinite(price) || price <= 0) ? 'Enter the price per person.' : '';
    if (priceEl) priceEl.classList.toggle('is-invalid', !!priceMsg);
    setFb(document.querySelector('[data-package-price-feedback]'), !!priceMsg, priceMsg);
    return priceMsg ? priceEl : null;
  }
  function packageCloseMessage() {
    var closeEl = document.getElementById('packageCloseDate');
    if (!closeEl || packageTouristPicksDate()) return null;
    var closeVal = String(closeEl.value || '').trim();
    var tourEl = document.getElementById('itinStartDate');
    var latestClose = '';
    if (tourEl && tourEl.value) {
      var parts = String(tourEl.value).split('-');
      if (parts.length === 3) {
        var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        dt.setDate(dt.getDate() - 2);
        latestClose = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
      }
    }
    var closeMsg = !closeVal ? 'Set the package cut off.' : (latestClose && closeVal > latestClose ? 'The package cut off must be at least 2 days before the tour date.' : '');
    closeEl.classList.toggle('is-invalid', !!closeMsg);
    setFb(document.querySelector('[data-package-close-feedback]'), !!closeMsg, closeMsg);
    return closeMsg ? closeEl : null;
  }
  function packageSummaryMessage() {
    var coverEl = document.getElementById('packageCoverInput');
    var preview = document.getElementById('packageCoverPreview');
    var hasCover = !!(coverEl && coverEl.files && coverEl.files.length)
      || !!(preview && !preview.hidden && String(preview.getAttribute('src') || '').trim());
    if (coverEl) coverEl.classList.toggle('is-invalid', !hasCover);
    setFb(document.querySelector('[data-package-cover-feedback]'), !hasCover, 'Add a cover photo.');
    var descEl = document.getElementById('packageDescriptionField');
    var descEmpty = !descEl || !String(descEl.value || '').trim();
    if (descEl) descEl.classList.toggle('is-invalid', descEmpty);
    setFb(document.querySelector('[data-package-description-feedback]'), descEmpty, 'Enter the package description.');
    var hidden = document.getElementById('packageInclusionsField');
    var entry = document.getElementById('packageInclusionEntry');
    var chips = document.querySelectorAll('#packageInclusionTags .tag-input__chip');
    var hasInclusion = !!((hidden && String(hidden.value || '').trim()) || (entry && String(entry.value || '').trim()) || chips.length);
    var tagBox = document.getElementById('packageInclusionTags');
    if (tagBox) tagBox.classList.toggle('is-invalid', !hasInclusion);
    setFb(document.querySelector('[data-package-inclusion-feedback]'), !hasInclusion, 'Add at least one inclusion.');
    return (!hasCover && coverEl) || (descEmpty && descEl) || (!hasInclusion && (entry || tagBox)) || null;
  }
  function packageFieldsReady(focus) {
    if (!document.body.classList.contains('package-maker')) return true;
    var first = packageGuestMessage() || packagePriceMessage() || packageCloseMessage();
    if (focus !== false && first && first.focus) {
      first.focus({ preventScroll: false });
      if (first.scrollIntoView) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return !first;
  }
  ['packageMinPax', 'itinPartySize', 'packagePriceField', 'packageCloseDate'].forEach(function (id) {
    var field = document.getElementById(id);
    if (!field) return;
    field.addEventListener('input', function () {
      if (id === 'packageMinPax') packageGuestMessage('min');
      else if (id === 'itinPartySize') packageGuestMessage('max');
      else if (id === 'packagePriceField') packagePriceMessage();
      else packageCloseMessage();
    });
  });
  ['packageCoverInput', 'packageDescriptionField', 'packageInclusionEntry'].forEach(function (id) {
    var field = document.getElementById(id);
    if (!field) return;
    field.addEventListener('input', function () {
      var note = document.querySelector(id === 'packageCoverInput' ? '[data-package-cover-feedback]' : (id === 'packageDescriptionField' ? '[data-package-description-feedback]' : '[data-package-inclusion-feedback]'));
      if (note && !note.hidden) packageSummaryMessage();
    });
    field.addEventListener('change', function () {
      var note = document.querySelector('[data-package-cover-feedback], [data-package-description-feedback], [data-package-inclusion-feedback]');
      if (note && !note.hidden) packageSummaryMessage();
    });
  });
  function openGuideBookModal() {
    var guideEl = document.getElementById('guideBookModal');
    if (guideEl && window.bootstrap) {
      bootstrap.Modal.getOrCreateInstance(guideEl).show();
    }
  }
  window.itinOpenGuideBookModal = openGuideBookModal;
  if (bookBtn) {
    bookBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (document.body.classList.contains('package-maker')) {
        if (needDateOrGuests(bookBtn, { requireGuests: true, requireTitle: true, requireGuide: false, requireStops: true })) return;
        if (!packageFieldsReady()) return;
        if (!packageTouristPicksDate()) {
          openStayRideModal();
          return;
        }
        syncBookingDetailsPreview();
        var reviewEl = document.getElementById('itinBookReviewModal');
        if (reviewEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(reviewEl).show();
        return;
      }
      if (needDateOrGuests(bookBtn, { requireGuests: true, requireTitle: true, requireGuide: false, requireStops: true })) return;
      var ensure = window.itinEnsureScheduleForBooking;
      function openFreshStay() {
        openStayRideModal();
      }
      if (typeof ensure === 'function') {
        ensure('review').then(function (r) {
          if (!r || !r.ok || r.reloading) return;
          openFreshStay();
        });
        return;
      }
      openFreshStay();
    });
  }
  var guidePreview = document.getElementById('itinGuidePreview');
  if (guidePreview && guidePreview.getAttribute('role') === 'button') {
    function openGuideFromPreview() {
      if (needDateOrGuests(null, { requireGuests: false, requireMeetup: false, requireTitle: false })) return;
      var ensure = window.itinEnsureScheduleForBooking;
      if (typeof ensure === 'function') {
        ensure('guides').then(function (r) {
          if (!r || !r.ok || r.reloading) return;
          openGuideBookModal();
        });
        return;
      }
      openGuideBookModal();
    }
    guidePreview.addEventListener('click', function (e) {
      if (e.target.closest('[data-itin-guide-clear]')) return;
      openGuideFromPreview();
    });
    guidePreview.addEventListener('keydown', function (e) {
      if (e.target.closest('[data-itin-guide-clear]')) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openGuideFromPreview();
      }
    });
  }
  var clearGuideBtn = document.querySelector('[data-itin-guide-clear]');
  if (clearGuideBtn) {
    clearGuideBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      clearPickedGuide();
    });
  }
  var proceedBtn = document.getElementById('itinBookProceed');
  function pwdYesSelected() {
    var yes = document.getElementById('guideBookPwdYes');
    return !!(yes && yes.checked);
  }
  function syncPwdFields() {
    var extra = document.getElementById('guideBookPwdFields');
    var cert = document.getElementById('guideBookFitnessCert');
    var on = pwdYesSelected();
    if (extra) extra.classList.toggle('is-open', on);
    if (cert && !on) cert.value = '';
  }
  ['guideBookPwdYes', 'guideBookPwdNo'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', syncPwdFields);
  });
  syncPwdFields();
  function submitPackageSave(mode) {
    var pkgForm = document.getElementById('guideBookForm');
    var inclusionEntry = document.getElementById('packageInclusionEntry');
    var inclusionHidden = document.getElementById('packageInclusionsField');
    var saveMode = document.getElementById('itinSaveMode');
    if (inclusionEntry && inclusionHidden && String(inclusionEntry.value || '').trim()) {
      inclusionHidden.value = [inclusionHidden.value, inclusionEntry.value].filter(Boolean).join(', ');
    }
    if (saveMode) saveMode.value = mode === 'new' ? 'new' : 'existing';
    var touristDate = document.getElementById('packageDateTourist');
    var systemGuide = !(touristDate && touristDate.checked)
      && itinPickedGuide && itinPickedGuide.id && !itinPickedGuide.name_only;
    if (systemGuide && !submitPackageSave.paid) {
      var amountEl = document.querySelector('[data-package-pay-amount]');
      var nameEl = document.querySelector('[data-package-pay-guide]');
      if (amountEl) amountEl.textContent = String(itinPickedGuide.rate || '');
      if (nameEl) nameEl.textContent = String(itinPickedGuide.name || 'Tour guide');
      var qrImg = document.querySelector('[data-package-pay-qr]');
      if (qrImg) {
        qrImg.removeAttribute('src');
        var qrBody = new FormData();
        qrBody.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
        qrBody.append('action', 'package_guide_qr');
        qrBody.append('amount', String(itinPickedGuide.rate_amount || 0));
        qrBody.append('guide_name', String(itinPickedGuide.name || ''));
        fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          body: qrBody,
          credentials: 'same-origin'
        }).then(function (res) { return res.json(); }).then(function (data) {
          if (data && data.qr) qrImg.src = data.qr;
        }).catch(function () {});
      }
      submitPackageSave.mode = mode;
      var reviewEl = document.getElementById('itinBookReviewModal');
      var choiceEl = document.getElementById('itinSaveChoiceModal');
      if (reviewEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(reviewEl).hide();
      if (choiceEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(choiceEl).hide();
      var payEl = document.getElementById('packageGuidePayModal');
      if (payEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(payEl).show();
      return;
    }
    submitPackageSave.paid = false;
    if (pkgForm) pkgForm.submit();
  }
  var packagePayBtn = document.getElementById('packageGuidePayConfirm');
  if (packagePayBtn) {
    packagePayBtn.addEventListener('click', function () {
      submitPackageSave.paid = true;
      submitPackageSave(submitPackageSave.mode || 'existing');
    });
  }
  var saveExistingBtn = document.getElementById('itinSaveExisting');
  var saveNewBtn = document.getElementById('itinSaveNew');
  if (saveExistingBtn) saveExistingBtn.addEventListener('click', function () { submitPackageSave('existing'); });
  if (saveNewBtn) saveNewBtn.addEventListener('click', function () { submitPackageSave('new'); });
  if (proceedBtn) {
    proceedBtn.addEventListener('click', function () {
      if (document.body.classList.contains('package-maker')) {
        if (needDateOrGuests(null, { requireGuests: true, requireTitle: true, requireMeetup: true, requireStops: true }) || !packageFieldsReady()) {
          var reviewBlock = document.getElementById('itinBookReviewModal');
          if (reviewBlock && window.bootstrap) bootstrap.Modal.getOrCreateInstance(reviewBlock).hide();
          return;
        }
        var summaryMiss = packageSummaryMessage();
        if (summaryMiss) {
          if (summaryMiss.focus) summaryMiss.focus();
          return;
        }
        var choiceEl = document.getElementById('itinSaveChoiceModal');
        var baselineEl = document.getElementById('itinBaseline');
        var itinField = document.querySelector('#guideBookForm [name="itinerary_id"]');
        var body = new FormData();
        body.append('csrf_token', (window.CulTour && CulTour.csrf) || '');
        body.append('action', 'itinerary_changed');
        body.append('itinerary_id', itinField ? String(itinField.value || '') : '');
        body.append('baseline', baselineEl ? String(baselineEl.value || '') : '');
        fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          body: body,
          credentials: 'same-origin'
        }).then(function (res) { return res.json(); }).then(function (data) {
          var published = document.getElementById('packageAlreadyMade');
          if (data && data.changed && published && published.value === '1' && choiceEl && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(choiceEl).show();
            return;
          }
          submitPackageSave('existing');
        }).catch(function () {
          submitPackageSave('existing');
        });
        return;
      }
      if (needDateOrGuests(null, { requireGuests: true, requireTitle: true, requireMeetup: true, requireStops: true })) {
        var reviewElBlock = document.getElementById('itinBookReviewModal');
        if (reviewElBlock && window.bootstrap) bootstrap.Modal.getOrCreateInstance(reviewElBlock).hide();
        return;
      }
      var reviewEl = document.getElementById('itinBookReviewModal');
      var guideEl = document.getElementById('guideBookModal');
      var form = document.getElementById('guideBookForm');
      var guideIdField = document.getElementById('guideBookGuideId');
      function afterReview() {
        if (itinPickedGuide && form) {
          if (guideIdField) guideIdField.value = String(itinPickedGuide.id);
          if (form.requestSubmit) form.requestSubmit();
          else form.submit();
          return;
        }
        if (guideEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(guideEl).show();
      }
      if (reviewEl && window.bootstrap) {
        var reviewModal = bootstrap.Modal.getOrCreateInstance(reviewEl);
        reviewEl.addEventListener('hidden.bs.modal', function onHidden() {
          reviewEl.removeEventListener('hidden.bs.modal', onHidden);
          afterReview();
        });
        reviewModal.hide();
      } else {
        afterReview();
      }
    });
  }
  <?php if (!empty($openBookReview)): ?>
  openBookReviewModal();
  <?php endif; ?>
  <?php if (!empty($promptBookDate)): ?>
  if (dateEl) {
    dateEl.classList.add('is-invalid');
    var promptFb = document.querySelector('[data-itin-date-feedback]');
    if (promptFb) {
      promptFb.textContent = 'Set a tour date before booking a guide.';
      promptFb.hidden = false;
    }
    dateEl.focus({ preventScroll: false });
    dateEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  <?php endif; ?>
  syncBookBtnForDate();

  var allGuides = <?= json_encode($bookGuides, JSON_UNESCAPED_UNICODE) ?>;
  var systemGuideList = <?= json_encode($packageSystemGuides ?? [], JSON_UNESCAPED_UNICODE) ?>;
  var guides = [];
  var showFilter = 'available';
  var root = document.getElementById('guideFocusCarousel');
  var modalEl = document.getElementById('guideBookModal');
  var filterEl = document.getElementById('guideShowFilter');
  var emptyEl = document.querySelector('[data-guide-empty]');
  if (!root) {
    return;
  }

  var idx = 0;
  var leftEl = root.querySelector('[data-slot="left"]');
  var centerEl = root.querySelector('[data-slot="center"]');
  var rightEl = root.querySelector('[data-slot="right"]');
  var guideIdInput = document.getElementById('guideBookGuideId');
  var hintEl = document.getElementById('guideBookHint');
  var submitBtn = document.getElementById('guideBookSubmit');
  var chatBase = <?= json_encode(APP_URL . '/tourist/chat.php?with=', JSON_UNESCAPED_SLASHES) ?>;
  var prevBtn = root.querySelector('.guide-focus__arrow--prev');
  var nextBtn = root.querySelector('.guide-focus__arrow--next');

  function meetupIsSet() {
    var meetupHidden = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
    return !!(meetupHidden && String(meetupHidden.value || '').trim());
  }

  function showModeLabel(mode) {
    return mode === 'available' ? 'Available guides' : 'Full day available guides';
  }

  function closeShowMenu() {
    if (!filterEl) return;
    filterEl.classList.remove('is-open');
    var btn = filterEl.querySelector('[data-guide-show-btn]');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function paintShowFilter() {
    if (!filterEl) return;
    var labelEl = filterEl.querySelector('[data-guide-show-label]');
    var panel = filterEl.querySelector('[data-guide-show-panel]');
    var current = showFilter === 'available' ? 'available' : 'full_day';
    var other = current === 'available' ? 'full_day' : 'available';
    if (labelEl) labelEl.textContent = showModeLabel(current);
    if (!panel) return;
    panel.innerHTML = [current, other].map(function (mode) {
      var on = mode === current;
      return '<button type="button" class="guide-show-menu__opt' + (on ? ' is-current' : '') + '" role="option" aria-selected="' + (on ? 'true' : 'false') + '" data-guide-show-opt="' + mode + '">' +
        escapeHtml(showModeLabel(mode)) +
      '</button>';
    }).join('');
  }

  function defaultShowMode() {
    return 'available';
  }

  function visibleGuides() {
    return (allGuides || []).filter(function (g) { return !!(g && g.ok); });
  }

  function wrap(i) {
    var n = guides.length;
    if (!n) return 0;
    return ((i % n) + n) % n;
  }

  function applyShowFilter(keepId) {
    var keep = keepId != null ? Number(keepId) : (guides.length ? Number(guides[wrap(idx)].id) : null);
    guides = visibleGuides();
    idx = 0;
    if (keep != null) {
      for (var i = 0; i < guides.length; i++) {
        if (Number(guides[i].id) === keep) {
          idx = i;
          break;
        }
      }
    }
    if (emptyEl) emptyEl.classList.add('d-none');
    if (root) root.classList.remove('d-none');
    if (prevBtn) prevBtn.classList.toggle('is-idle', guides.length < 2);
    if (nextBtn) nextBtn.classList.toggle('is-idle', guides.length < 2);
    var notesForm = document.getElementById('guideBookForm');
    if (notesForm) notesForm.classList.toggle('d-none', guides.length === 0);
    render();
  }

  var requestGuideBtn = document.getElementById('packageNoGuideRequest');
  if (requestGuideBtn) {
    requestGuideBtn.addEventListener('click', function () {
      allGuides = systemGuideList || [];
      var guideTitle = document.getElementById('guideBookModalLabel');
      if (guideTitle) guideTitle.textContent = 'Tour guide';
      applyShowFilter();
      var notice = document.getElementById('packageNoGuideModal');
      if (notice && window.bootstrap) {
        notice.addEventListener('hidden.bs.modal', function onHidden() {
          notice.removeEventListener('hidden.bs.modal', onHidden);
          openGuideBookModal();
        });
        bootstrap.Modal.getOrCreateInstance(notice).hide();
      } else {
        openGuideBookModal();
      }
    });
  }

  function initials(name) {
    return guideInitials(name);
  }

  function photoHtml(g) {
    if (g.avatar) {
      return '<div class="guide-focus__photo"><img src="' + g.avatar + '" alt=""></div>';
    }
    return '<div class="guide-focus__photo"><div class="guide-focus__photo-fallback">' + initials(g.name) + '</div></div>';
  }

  function ratingStarsHtml(g, size) {
    var avg = Number(g && g.rating != null ? g.rating : 0);
    if (!Number.isFinite(avg) || avg < 0) avg = 0;
    if (avg > 5) avg = 5;
    var count = parseInt(g && g.reviews != null ? g.reviews : 0, 10) || 0;
    var has = count > 0 || avg > 0;
    var rounded = Math.round(avg * 2) / 2;
    var stars = '';
    for (var i = 1; i <= 5; i++) {
      var icon = 'bi-star';
      if (has && rounded >= i) icon = 'bi-star-fill';
      else if (has && rounded >= i - 0.5) icon = 'bi-star-half';
      stars += '<i class="bi ' + icon + '" aria-hidden="true"></i>';
    }
    var num = has ? avg.toFixed(2) : '—';
    var countHtml = count > 0 ? ' <span class="ct-rating__count">(' + count + ')</span>' : '';
    var cls = 'ct-rating' + (size === 'sm' ? ' ct-rating--sm' : (size === 'lg' ? ' ct-rating--lg' : ''));
    return '<span class="' + cls + '">' +
      '<span class="ct-rating__stars">' + stars + '</span>' +
      '<span class="ct-rating__num">' + num + '</span>' +
      countHtml +
    '</span>';
  }

  function fillSide(el, g) {
    if (!el || !g) {
      if (el) el.innerHTML = '';
      return;
    }
    el.innerHTML =
      photoHtml(g) +
      '<div class="guide-focus__body">' +
        '<div class="guide-focus__name">' + escapeHtml(g.name) + '</div>' +
        '<div class="guide-focus__side-rating">' + ratingStarsHtml(g, 'sm') + '</div>' +
        '<div class="guide-focus__side-rate">' + escapeHtml(g.rate) + '/day</div>' +
      '</div>';
  }

  function emptyGuideMessage() {
    return meetupIsSet()
      ? 'No available guides for this meetup time.'
      : 'No available guides for these tour dates.';
  }

  function fillCenter(el, g) {
    if (!el) return;
    if (!g) {
      el.classList.remove('is-unavailable');
      el.classList.add('is-empty-state');
      el.innerHTML = '<div class="guide-focus__empty">' + escapeHtml(emptyGuideMessage()) + '</div>';
      return;
    }
    el.classList.remove('is-empty-state');
    el.classList.toggle('is-unavailable', !g.ok);
    var hintOk = g.hint_ok !== undefined ? !!g.hint_ok : !!g.ok;
    el.innerHTML =
      photoHtml(g) +
      '<div class="guide-focus__body">' +
        '<div class="guide-focus__scroll">' +
          '<h3 class="guide-focus__name">' + escapeHtml(g.name) + '</h3>' +
          '<div class="guide-focus__rating">' + ratingStarsHtml(g, 'lg') + '</div>' +
          '<ul class="guide-focus__facts">' +
            '<li><span>Phone</span><strong>' + escapeHtml(g.phone) + '</strong></li>' +
            '<li><span>Languages</span><strong>' + escapeHtml(g.languages) + '</strong></li>' +
            '<li><span>Daily rate</span><strong>' + escapeHtml(g.rate) + '</strong></li>' +
          '</ul>' +
          (g.hint ? '<div class="guide-focus__avail-hint' + (hintOk ? ' is-ok' : '') + '">' + escapeHtml(g.hint) + '</div>' : '') +
        '</div>' +
        '<div class="guide-focus__actions">' +
          '<button type="button" class="btn btn-ct btn-sm" data-guide-book ' + (g.ok ? '' : 'disabled') + '>Pick this tour guide</button>' +
          '<a class="btn btn-outline-light btn-sm" href="' + chatBase + g.user_id + '">Chat tour guide</a>' +
        '</div>' +
      '</div>';
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function syncForm(g) {
    if (guideIdInput && !itinPickedGuide) guideIdInput.value = g ? String(g.id) : '';
    if (hintEl) {
      hintEl.textContent = g && g.hint ? g.hint : '';
      var hintOk = g && g.hint_ok !== undefined ? !!g.hint_ok : !!(g && g.ok);
      hintEl.classList.toggle('text-danger', !!(g && g.hint && !hintOk));
      hintEl.classList.toggle('text-muted', !(g && g.hint && !hintOk));
    }
    if (submitBtn) submitBtn.disabled = !(g && g.ok);
    window.itinBillGuide = g || null;
  }

  function commitPickGuide(g) {
    if (!g || !g.ok) return false;
    itinPickedGuide = g;
    window.itinBillGuide = g;
    savePickedGuide(g);
    if (guideIdInput) guideIdInput.value = String(g.id);
    applyGuidePreview(g);
    if (modalEl && window.bootstrap) {
      var bookModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modalEl.addEventListener('hidden.bs.modal', function onGuideHidden() {
        modalEl.removeEventListener('hidden.bs.modal', onGuideHidden);
        if (!document.body.classList.contains('package-maker') && typeof openGuideGuestModal === 'function') openGuideGuestModal();
        else if (typeof openBookReviewModal === 'function') openBookReviewModal();
      });
      bookModal.hide();
    } else if (!document.body.classList.contains('package-maker') && typeof openGuideGuestModal === 'function') {
      openGuideGuestModal();
    } else if (typeof openBookReviewModal === 'function') {
      openBookReviewModal();
    }
    return true;
  }

  var busyModalEl = document.getElementById('itinGuideBusyModal');
  var busyPendingGuide = null;
  var busyRestoreBook = false;
  var skipBookShownReset = false;
  var busyConfirmMode = 'pick';
  var busySpanCallbacks = null;
  var busySpanSettled = false;
  var busyOpenBookAfterHide = false;

  function setBusyPickAnotherVisible(on) {
    if (!busyModalEl) return;
    var pickBtn = busyModalEl.querySelector('[data-itin-guide-busy-pick]');
    if (pickBtn) pickBtn.hidden = !on;
  }

  function fillBusyConfirm(g) {
    if (!busyModalEl || !g) return;
    var nameEl = busyModalEl.querySelector('[data-itin-guide-busy-name]');
    var hintElBusy = busyModalEl.querySelector('[data-itin-guide-busy-hint]');
    var daysEl = busyModalEl.querySelector('[data-itin-guide-busy-days]');
    if (nameEl) nameEl.textContent = g.name ? String(g.name) : '';
    if (hintElBusy) hintElBusy.textContent = g.hint ? String(g.hint) : '';
    if (daysEl) {
      var rows = Array.isArray(g.busy_by_day) ? g.busy_by_day : [];
      daysEl.innerHTML = rows.map(function (row) {
        if (!row) return '';
        var day = row.day != null ? ('Day ' + row.day) : 'Day';
        var label = row.label ? String(row.label).replace(/\s*\(\d+-min(?:ute)?s? buffer\)/i, '') : '';
        return '<li>' + escapeHtml(day) + (label ? ' · ' + escapeHtml(label) : '') + '</li>';
      }).join('');
      daysEl.hidden = rows.length === 0;
    }
  }

  function showBusyConfirm() {
    if (!busyModalEl || !window.bootstrap) return;
    bootstrap.Modal.getOrCreateInstance(busyModalEl).show();
  }

  function openBusyConfirm(g) {
    if (!g || !busyModalEl || !window.bootstrap) return;
    busyConfirmMode = 'pick';
    busySpanCallbacks = null;
    busySpanSettled = false;
    busyOpenBookAfterHide = false;
    busyPendingGuide = g;
    busyRestoreBook = true;
    setBusyPickAnotherVisible(false);
    fillBusyConfirm(g);
    if (modalEl && modalEl.classList.contains('show')) {
      modalEl.addEventListener('hidden.bs.modal', function onBookHidden() {
        modalEl.removeEventListener('hidden.bs.modal', onBookHidden);
        showBusyConfirm();
      });
      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    } else {
      showBusyConfirm();
    }
  }

  function openBusyConfirmSpan(g, callbacks) {
    if (!g || !busyModalEl || !window.bootstrap) return;
    busyConfirmMode = 'span';
    busyPendingGuide = g;
    busyRestoreBook = false;
    busySpanSettled = false;
    busyOpenBookAfterHide = false;
    busySpanCallbacks = callbacks || {};
    setBusyPickAnotherVisible(true);
    fillBusyConfirm(g);
    showBusyConfirm();
  }
  window.itinOpenGuideBusySpanWarn = openBusyConfirmSpan;

  function pickCurrentGuide() {
    var g = guides[wrap(idx)];
    if (!g || !g.ok) return false;
    var dateCheck = document.getElementById('itinStartDate');
    if (dateCheck && !dateCheck.value) {
      if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      if (bookBtn) bookBtn.click();
      return false;
    }
    return commitPickGuide(g);
  }

  if (busyModalEl) {
    var busyOkBtn = busyModalEl.querySelector('[data-itin-guide-busy-ok]');
    var busyCancelBtn = busyModalEl.querySelector('[data-itin-guide-busy-cancel]');
    var busyPickBtn = busyModalEl.querySelector('[data-itin-guide-busy-pick]');
    if (busyOkBtn) {
      busyOkBtn.addEventListener('click', function () {
        var g = busyPendingGuide;
        if (busyConfirmMode === 'span') {
          busySpanSettled = true;
          var proceedCb = busySpanCallbacks && busySpanCallbacks.proceed;
          busySpanCallbacks = null;
          busyPendingGuide = null;
          busyRestoreBook = false;
          busyConfirmMode = 'pick';
          setBusyPickAnotherVisible(false);
          if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(busyModalEl).hide();
          if (proceedCb) proceedCb();
          return;
        }
        busyPendingGuide = null;
        busyRestoreBook = false;
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(busyModalEl).hide();
        commitPickGuide(g);
      });
    }
    if (busyPickBtn) {
      busyPickBtn.addEventListener('click', function () {
        if (busyConfirmMode !== 'span') return;
        busySpanSettled = true;
        busyOpenBookAfterHide = true;
        var pickCb = busySpanCallbacks && busySpanCallbacks.pickAnother;
        busySpanCallbacks = null;
        busyPendingGuide = null;
        busyRestoreBook = false;
        busyConfirmMode = 'pick';
        setBusyPickAnotherVisible(false);
        if (pickCb) pickCb();
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(busyModalEl).hide();
      });
    }
    if (busyCancelBtn) {
      busyCancelBtn.addEventListener('click', function () {
        if (busyConfirmMode === 'span') {
          busySpanSettled = true;
          var cancelCb = busySpanCallbacks && busySpanCallbacks.cancel;
          busySpanCallbacks = null;
          busyPendingGuide = null;
          busyRestoreBook = false;
          busyConfirmMode = 'pick';
          setBusyPickAnotherVisible(false);
          if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(busyModalEl).hide();
          if (cancelCb) cancelCb();
          return;
        }
        busyPendingGuide = null;
        busyRestoreBook = true;
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(busyModalEl).hide();
      });
    }
    busyModalEl.addEventListener('hidden.bs.modal', function () {
      if (busyOpenBookAfterHide) {
        busyOpenBookAfterHide = false;
        busyRestoreBook = false;
        busyPendingGuide = null;
        setBusyPickAnotherVisible(false);
        openGuideBookModal();
        return;
      }
      if (busyConfirmMode === 'span' && !busySpanSettled) {
        var cancelCb = busySpanCallbacks && busySpanCallbacks.cancel;
        busySpanCallbacks = null;
        busyConfirmMode = 'pick';
        busyPendingGuide = null;
        busyRestoreBook = false;
        setBusyPickAnotherVisible(false);
        if (cancelCb) cancelCb();
        return;
      }
      if (!busyRestoreBook) return;
      busyRestoreBook = false;
      busyPendingGuide = null;
      if (modalEl && window.bootstrap) {
        skipBookShownReset = true;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      }
    });
  }

  function guideCard(g) {
    var photo = g.avatar
      ? '<span class="pick-spot__photo" style="background-image:url(' + escapeHtml(g.avatar) + ')"><span class="pick-spot__kicker">' + (g.name_only ? 'In-house guide' : 'Tour guide') + '</span></span>'
      : '<span class="pick-spot__photo is-empty"><span class="pick-spot__kicker">' + (g.name_only ? 'In-house guide' : 'Tour guide') + '</span></span>';
    var selected = Number(g.id) === Number(selectedGuideId);
    if (g.name_only) {
      return '<article class="pick-spot pick-spot--guide pick-spot--house' + (selected ? ' is-selected' : '') + '" data-guide-select="' + g.id + '">' +
        photo +
        '<span class="pick-spot__copy"><span class="pick-spot__name">' + escapeHtml(g.name) + '</span></span>' +
      '</article>';
    }
    return '<article class="pick-spot pick-spot--guide' + (g.ok ? '' : ' is-unavailable') + (selected ? ' is-selected' : '') + '" data-guide-select="' + g.id + '">' +
      photo +
      '<span class="pick-spot__copy">' +
        '<span class="pick-spot__name">' + escapeHtml(g.name) + '</span>' +
        '<span class="pick-spot__rating">' + ratingStarsHtml(g, 'sm') + '</span>' +
        '<ul class="pick-spot__facts">' +
          '<li><span>Phone</span><strong>' + escapeHtml(g.phone) + '</strong></li>' +
          '<li><span>Daily rate</span><strong>' + escapeHtml(g.rate) + '</strong></li>' +
          '<li class="is-languages" title="' + escapeHtml(g.languages) + '"><span>Languages</span><strong>' + escapeHtml(g.languages) + '</strong></li>' +
        '</ul>' +
        (g.hint ? '<div class="guide-focus__avail-hint' + (g.ok ? ' is-ok' : '') + '">' + escapeHtml(g.hint) + '</div>' : '') +
        '<span class="pick-spot__actions">' +
          '<a class="btn btn-outline-light btn-sm" href="' + chatBase + g.user_id + '">Chat</a>' +
        '</span>' +
      '</span>' +
    '</article>';
  }

  function render() {
    var grid = document.getElementById('guidePickGrid');
    if (!grid) return;
    var q = '';
    var search = document.getElementById('guidePickSearch');
    if (search) q = String(search.value || '').trim().toLowerCase();
    var list = guides.filter(function (g) {
      if (!q) return true;
      return (String(g.name || '') + ' ' + String(g.languages || '') + ' ' + String(g.phone || '')).toLowerCase().indexOf(q) !== -1;
    });
    if (!list.length) {
      grid.innerHTML = '';
      if (emptyEl) {
        emptyEl.textContent = q ? 'No guides match that search.' : emptyGuideMessage();
        emptyEl.classList.remove('d-none');
      }
      syncForm(null);
      return;
    }
    if (emptyEl) emptyEl.classList.add('d-none');
    grid.innerHTML = list.map(guideCard).join('');
    syncForm(guides[wrap(idx)] || list[0]);
    if (window.itinPlaceGuidePick) window.itinPlaceGuidePick();
  }

  if (prevBtn) prevBtn.addEventListener('click', function () { idx = wrap(idx - 1); render(); });
  if (nextBtn) nextBtn.addEventListener('click', function () { idx = wrap(idx + 1); render(); });
  var selectedGuideId = 0;
  var guidePickConfirm = document.getElementById('guidePickConfirm');
  function markGuideSelection(id) {
    selectedGuideId = Number(id) || 0;
    var chosen = null;
    for (var si = 0; si < guides.length; si++) {
      if (Number(guides[si].id) === selectedGuideId) {
        idx = si;
        chosen = guides[si];
        break;
      }
    }
    if (guidePickConfirm) guidePickConfirm.disabled = !chosen || !chosen.ok;
    var nameEl = document.querySelector('[data-guide-view-name]');
    var rateEl = document.querySelector('[data-guide-view-rate]');
    if (nameEl) {
      nameEl.textContent = !chosen ? '' : (chosen.name_only ? String(chosen.name || 'Guide') : String(chosen.rate || ''));
    }
    if (rateEl) {
      rateEl.textContent = !chosen ? 'Select a guide' : (chosen.name_only ? '' : String(chosen.name || 'Guide'));
    }
    render();
  }
  if (root) {
    root.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      var card = e.target.closest('[data-guide-select]');
      if (!card) return;
      markGuideSelection(card.getAttribute('data-guide-select'));
    });
  }
  if (guidePickConfirm) {
    guidePickConfirm.addEventListener('click', function () {
      if (!selectedGuideId) return;
      pickCurrentGuide();
    });
  }
  var guideSearch = document.getElementById('guidePickSearch');
  if (guideSearch) guideSearch.addEventListener('input', function () { render(); });
  var bookForm = document.getElementById('guideBookForm');
  if (bookForm) {
    bookForm.addEventListener('submit', function (e) {
      if (itinPickedGuide && guideIdInput) {
        guideIdInput.value = String(itinPickedGuide.id);
      }
      if (!syncPartySize()) {
        e.preventDefault();
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        if (partyEl) {
          partyEl.focus();
          partyEl.reportValidity && partyEl.reportValidity();
        }
        return;
      }
      if (typeof hasRouteStops === 'function' && !hasRouteStops()) {
        e.preventDefault();
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        var reviewElStops = document.getElementById('itinBookReviewModal');
        if (reviewElStops && window.bootstrap) bootstrap.Modal.getOrCreateInstance(reviewElStops).hide();
        if (typeof needDateOrGuests === 'function') {
          needDateOrGuests(bookBtn, { requireGuests: true, requireTitle: true, requireGuide: true, requireStops: true });
        }
        return;
      }
      var dateCheck = document.getElementById('itinStartDate');
      if (dateCheck && !dateCheck.value) {
        e.preventDefault();
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        if (bookBtn) {
          bookBtn.setAttribute('data-itin-need-date', '');
          bookBtn.removeAttribute('data-bs-toggle');
          bookBtn.removeAttribute('data-bs-target');
          bookBtn.click();
        }
      }
    });
  }
  if (leftEl) leftEl.addEventListener('click', function () { idx = wrap(idx - 1); render(); });
  if (rightEl) rightEl.addEventListener('click', function () { idx = wrap(idx + 1); render(); });

  var preferGuideUser = <?= (int) ($preferGuideUser ?? 0) ?>;

  function syncPickedAgainstGuides() {
    if (!itinPickedGuide) return;
    if (ownGuideId && Number(itinPickedGuide.id) === ownGuideId) {
      clearPickedGuide();
      return;
    }
    var matched = null;
    for (var pi = 0; pi < allGuides.length; pi++) {
      if (Number(allGuides[pi].id) === Number(itinPickedGuide.id)) {
        matched = allGuides[pi];
        break;
      }
    }
    if (matched) {
      itinPickedGuide = matched;
      savePickedGuide(itinPickedGuide);
      applyGuidePreview(itinPickedGuide);
      if (guideIdInput) guideIdInput.value = String(itinPickedGuide.id);
      for (var vi = 0; vi < guides.length; vi++) {
        if (Number(guides[vi].id) === Number(matched.id)) {
          idx = vi;
          break;
        }
      }
    } else {
      applyGuidePreview(itinPickedGuide);
      if (guideIdInput) guideIdInput.value = String(itinPickedGuide.id);
    }
  }

  function setShowMode(mode, keepId) {
    showFilter = mode === 'full_day' ? 'full_day' : 'available';
    closeShowMenu();
    paintShowFilter();
    applyShowFilter(keepId);
    syncPickedAgainstGuides();
  }

  if (filterEl) {
    filterEl.addEventListener('click', function (e) {
      var opt = e.target.closest('[data-guide-show-opt]');
      if (opt) {
        setShowMode(opt.getAttribute('data-guide-show-opt'), guides.length ? guides[wrap(idx)].id : null);
        return;
      }
      if (e.target.closest('[data-guide-show-btn]')) {
        var open = filterEl.classList.toggle('is-open');
        var btn = filterEl.querySelector('[data-guide-show-btn]');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) paintShowFilter();
      }
    });
    document.addEventListener('click', function (e) {
      if (!filterEl.contains(e.target)) closeShowMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeShowMenu();
    });
  }

  if (preferGuideUser > 0) {
    var preferOk = false;
    for (var gi = 0; gi < allGuides.length; gi++) {
      if (Number(allGuides[gi].user_id) === preferGuideUser) {
        preferOk = !!allGuides[gi].ok;
        if (!preferOk) showFilter = 'available';
        break;
      }
    }
    paintShowFilter();
    applyShowFilter(null);
    for (var gj = 0; gj < guides.length; gj++) {
      if (Number(guides[gj].user_id) === preferGuideUser) {
        idx = gj;
        break;
      }
    }
  } else {
    showFilter = defaultShowMode();
    paintShowFilter();
    applyShowFilter(null);
  }
  syncPickedAgainstGuides();

  // Live availability while Book a tour guide modal is open
  var pollUrl = <?= json_encode(APP_URL . '/api/guides/book_poll.php', JSON_UNESCAPED_SLASHES) ?>;
  var pollVersion = '';
  var pollKey = '';
  var pollTimer = null;
  var pollBusy = false;

  function pollScheduleParams() {
    var start = dateEl ? String(dateEl.value || '').trim() : '';
    var meetupHidden = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
    var meetup = meetupHidden ? String(meetupHidden.value || '').trim() : '';
    var multi = document.getElementById('itinEditMultiday');
    var daySel = document.querySelector('[data-itin-day-count]');
    var dayCount = 1;
    if (multi && multi.checked && daySel) {
      dayCount = parseInt(String(daySel.value || '2'), 10) || 2;
      if (dayCount < 2) dayCount = 2;
    }
    return {
      itinerary_id: itinId,
      start_date: start,
      meetup_time: meetup,
      day_count: dayCount
    };
  }

  function applyPolledGuides(next) {
    if (!Array.isArray(next) || !next.length) return;
    var curId = guides.length ? guides[wrap(idx)].id : null;
    allGuides = next;
    applyShowFilter(curId);
    syncPickedAgainstGuides();
  }

  function pollGuides() {
    if (pollBusy || document.hidden) return;
    if (!modalEl || !modalEl.classList.contains('show')) return;
    var p = pollScheduleParams();
    if (!p.itinerary_id) return;
    var key = p.start_date + '|' + p.meetup_time + '|' + p.day_count;
    if (key !== pollKey) {
      pollKey = key;
      pollVersion = '';
      showFilter = defaultShowMode();
      paintShowFilter();
    }
    pollBusy = true;
    var q = new URLSearchParams();
    q.set('itinerary_id', String(p.itinerary_id));
    if (p.start_date) q.set('start_date', p.start_date);
    if (p.meetup_time) q.set('meetup_time', p.meetup_time);
    if (p.day_count > 1) q.set('day_count', String(p.day_count));
    if (pollVersion) q.set('since', pollVersion);
    fetch(pollUrl + '?' + q.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (res) {
      return res.json();
    }).then(function (data) {
      pollBusy = false;
      if (!data || !data.ok) return;
      if (data.version) pollVersion = data.version;
      if (data.changed && data.guides) applyPolledGuides(data.guides);
    }).catch(function () {
      pollBusy = false;
    });
  }

  function startGuidePoll() {
    stopGuidePoll();
    pollVersion = '';
    pollGuides();
    pollTimer = setInterval(pollGuides, 3000);
  }
  function stopGuidePoll() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', function () {
      var meetupHidden = document.querySelector('#itinEditScheduleForm [name="meetup_time"]');
      var meetupLabelEl = document.querySelector('[data-itin-guide-meetup-label]');
      if (meetupLabelEl) {
        var mv = meetupHidden ? String(meetupHidden.value || '').trim() : '';
        meetupLabelEl.textContent = mv ? ('Meetup ' + mv) : 'Meetup Not set';
      }
      if (typeof window.itinPaintGuideModalDate === 'function') {
        window.itinPaintGuideModalDate();
      }
      if (skipBookShownReset) {
        skipBookShownReset = false;
        startGuidePoll();
        return;
      }
      showFilter = defaultShowMode();
      paintShowFilter();
      applyShowFilter(guides.length ? guides[wrap(idx)].id : null);
      startGuidePoll();
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
      closeShowMenu();
      stopGuidePoll();
    });
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && modalEl && modalEl.classList.contains('show')) {
      pollGuides();
    }
  });

  try {
    var reopen = sessionStorage.getItem('cultour_itin_reopen');
    if (reopen) {
      sessionStorage.removeItem('cultour_itin_reopen');
      if (reopen === 'guides' && modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      } else if (reopen === 'review') {
        var reviewEl = document.getElementById('itinBookReviewModal');
        if (reviewEl && window.bootstrap) {
          bootstrap.Modal.getOrCreateInstance(reviewEl).show();
        }
      }
    }
  } catch (reopenErr) {}

  <?php if ($openBookModal && empty($openBookReview)): ?>
  if (modalEl && window.bootstrap) {
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }
  <?php endif; ?>
})();
<?php endif; ?>
</script>
<?php
$extraScripts = ($extraScripts ?? '') . '<script src="' . APP_URL . '/assets/js/itin-tutorial.js?v='
  . (@filemtime(ROOT_PATH . '/assets/js/itin-tutorial.js') ?: time()) . '"></script>';
require __DIR__ . '/../includes/footer.php';
?>
