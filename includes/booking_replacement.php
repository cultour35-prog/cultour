<?php
/**
 * Automatic replacement-guide matching after a guide cannot continue a tour.
 *
 * Flow: guide steps down -> system offers the leftover days to one guide at a time
 * (15 minutes to answer) -> tourist accepts or asks for another guide.
 * When nobody is free the tourist can move the leftover days or request a refund,
 * and admins are notified so they can assign someone by hand.
 *
 * bookings.replacement_state: offered | awaiting_tourist | no_guide | NULL (searching)
 */

const BOOKING_REPLACEMENT_OFFER_MINUTES = 15;
const BOOKING_REPLACEMENT_MOVE_WINDOW_DAYS = 30;

function ensure_booking_replacement_offer_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_booking_replacement_schema();
    try {
        $pdo = db();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_replacement_offers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                booking_id INT UNSIGNED NOT NULL,
                guide_id INT UNSIGNED NOT NULL,
                for_date DATE NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'offered',
                offered_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                responded_at DATETIME NULL,
                KEY idx_repl_booking (booking_id, status),
                KEY idx_repl_guide (guide_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $add = [
            'replacement_state' => 'VARCHAR(20) NULL',
            'replacement_candidate_id' => 'INT UNSIGNED NULL',
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $cols, true)) {
                try {
                    $pdo->exec('ALTER TABLE bookings ADD COLUMN `' . $col . '` ' . $def);
                } catch (Throwable $e) {
                    // ignore one-column failures
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function booking_replacement_row(int $bookingId): ?array
{
    if ($bookingId < 1) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');
    $st->execute([$bookingId]);
    $row = $st->fetch();
    return $row ?: null;
}

function booking_replacement_state(array $booking): string
{
    return (string) ($booking['replacement_state'] ?? '');
}

/** @return list<string> */
function booking_replacement_dates(array $booking): array
{
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);
    return booking_remaining_visit_dates($booking, $from);
}

/** "Sep 28, 9:00 AM" for the first leftover day, plus a day count when there are more. */
function booking_replacement_when_label(array $booking): string
{
    $dates = booking_replacement_dates($booking);
    if (!$dates) {
        return '';
    }
    $first = $dates[0];
    $label = format_date($first, 'M j') . ', ' . format_time_12h(booking_time_for_calendar_date($booking, $first));
    if (count($dates) > 1) {
        $label .= ' (' . count($dates) . ' days, until ' . format_date($dates[count($dates) - 1], 'M j') . ')';
    }
    return $label;
}

/** Logical day number (1-based) of the first leftover day within the booking. */
function booking_replacement_day_number(array $booking): int
{
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);
    $all = booking_visit_dates($booking);
    if ($from === null || !$all) {
        return 0;
    }
    $idx = array_search($from, $all, true);
    return $idx === false ? 0 : ((int) $idx + 1);
}

/** " due to illness" style phrase from the stored withdraw reason. */
function booking_replacement_reason_phrase(array $booking): string
{
    $reason = strtolower(trim((string) ($booking['withdraw_reason'] ?? '')));
    if ($reason === '') {
        return '';
    }
    if (str_starts_with($reason, 'sick')) {
        return ' due to illness';
    }
    if (str_starts_with($reason, 'emergency')) {
        return ' due to an emergency';
    }
    return '';
}

/** "today" / "tomorrow" / "on Sep 30" for the first leftover day. */
function booking_replacement_relative_day(array $booking): string
{
    $dates = booking_replacement_dates($booking);
    if (!$dates) {
        return '';
    }
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime($today . ' 12:00:00 +1 day'));
    $prefix = count($dates) > 1 ? 'from ' : '';
    if ($dates[0] === $today) {
        return $prefix . 'today';
    }
    if ($dates[0] === $tomorrow) {
        return $prefix . 'tomorrow';
    }
    return ($prefix !== '' ? $prefix : 'on ') . format_date($dates[0], 'M j');
}

/** @return array<string,mixed>|null */
function booking_replacement_guide_card(int $guideProfileId): ?array
{
    if ($guideProfileId < 1) {
        return null;
    }
    $st = db()->prepare(
        "SELECT g.id, g.user_id, g.languages, g.years_experience, g.avg_rating, g.total_tours,
                u.first_name, u.last_name, u.avatar
         FROM tour_guide_profiles g
         JOIN users u ON u.id = g.user_id
         WHERE g.id=? LIMIT 1"
    );
    $st->execute([$guideProfileId]);
    $g = $st->fetch();
    if (!$g) {
        return null;
    }
    $years = (int) ($g['years_experience'] ?? 0);
    return [
        'id' => (int) $g['id'],
        'user_id' => (int) $g['user_id'],
        'name' => trim((string) $g['first_name'] . ' ' . (string) $g['last_name']),
        'languages' => trim((string) ($g['languages'] ?? '')) ?: '—',
        'experience' => $years > 0 ? ($years . ' year' . ($years === 1 ? '' : 's')) : 'New guide',
        'rating' => round((float) ($g['avg_rating'] ?? 0), 1),
        'avatar' => !empty($g['avatar']) ? (APP_URL . '/uploads/' . ltrim((string) $g['avatar'], '/')) : null,
    ];
}

/** Mark offers past their 15 minutes as expired and tell those guides. */
function booking_replacement_expire_offers(int $bookingId): void
{
    $st = db()->prepare(
        "SELECT o.id, o.guide_id, b.booking_code
         FROM booking_replacement_offers o
         JOIN bookings b ON b.id = o.booking_id
         WHERE o.booking_id=? AND o.status='offered' AND o.expires_at <= NOW()"
    );
    $st->execute([$bookingId]);
    foreach ($st->fetchAll() ?: [] as $o) {
        $up = db()->prepare(
            "UPDATE booking_replacement_offers SET status='expired', responded_at=NOW()
             WHERE id=? AND status='offered'"
        );
        $up->execute([(int) $o['id']]);
        if ($up->rowCount() < 1) {
            continue;
        }
        $uid = tour_guide_user_id((int) $o['guide_id']);
        if ($uid > 0) {
            notify_user(
                $uid,
                'booking_replacement',
                'Replacement request expired',
                'The request to continue Booking #' . $o['booking_code'] . ' expired after '
                    . BOOKING_REPLACEMENT_OFFER_MINUTES . ' minutes. No action is needed.',
                APP_URL . '/tourguide/bookings.php'
            );
        }
    }
}

/**
 * Move the replacement search forward: expire stale offers, then offer the leftover
 * days to the next free guide, or flag the booking as having no guide.
 *
 * @return string the booking's replacement state afterwards
 */
function booking_replacement_advance(int $bookingId): string
{
    ensure_booking_replacement_offer_schema();
    $lockName = 'cultour_repl_' . $bookingId;
    $locked = false;
    try {
        $lk = db()->prepare('SELECT GET_LOCK(?, 5)');
        $lk->execute([$lockName]);
        $locked = (int) $lk->fetchColumn() === 1;
    } catch (Throwable $e) {
        $locked = false;
    }
    try {
        return booking_replacement_advance_locked($bookingId);
    } finally {
        if ($locked) {
            try {
                db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}

function booking_replacement_advance_locked(int $bookingId): string
{
    $b = booking_replacement_row($bookingId);
    if (!$b || ($b['status'] ?? '') !== 'approved' || !booking_needs_replacement($b)) {
        return '';
    }
    booking_replacement_expire_offers($bookingId);

    $state = booking_replacement_state($b);
    if ($state === 'awaiting_tourist' && (int) ($b['replacement_candidate_id'] ?? 0) > 0) {
        return $state;
    }

    $active = db()->prepare(
        "SELECT id FROM booking_replacement_offers
         WHERE booking_id=? AND status='offered' AND expires_at > NOW() LIMIT 1"
    );
    $active->execute([$bookingId]);
    if ($active->fetchColumn()) {
        if ($state !== 'offered') {
            db()->prepare("UPDATE bookings SET replacement_state='offered' WHERE id=?")->execute([$bookingId]);
        }
        return 'offered';
    }

    $dates = booking_replacement_dates($b);
    $code = (string) ($b['booking_code'] ?? '');
    $from = $dates[0] ?? normalize_tour_date($b['replacement_from_date'] ?? null);

    $pick = null;
    if ($dates) {
        $tried = db()->prepare(
            "SELECT DISTINCT guide_id FROM booking_replacement_offers
             WHERE booking_id=? AND (for_date <=> ? OR status='tourist_declined')"
        );
        $tried->execute([$bookingId, $from]);
        $triedIds = array_map('intval', $tried->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach (booking_list_replacement_guides($b) as $g) {
            $gid = (int) ($g['id'] ?? 0);
            if ($gid > 0 && !in_array($gid, $triedIds, true)) {
                $pick = $g;
                break;
            }
        }
    }

    if ($pick) {
        $gid = (int) $pick['id'];
        db()->prepare(
            "INSERT INTO booking_replacement_offers (booking_id, guide_id, for_date, status, offered_at, expires_at)
             VALUES (?, ?, ?, 'offered', NOW(), DATE_ADD(NOW(), INTERVAL " . (int) BOOKING_REPLACEMENT_OFFER_MINUTES . ' MINUTE))'
        )->execute([$bookingId, $gid, $from]);
        db()->prepare(
            "UPDATE bookings SET replacement_state='offered', replacement_candidate_id=NULL WHERE id=?"
        )->execute([$bookingId]);
        $uid = (int) ($pick['user_id'] ?? 0) ?: tour_guide_user_id($gid);
        if ($uid > 0) {
            notify_user(
                $uid,
                'replacement_offer',
                'Replacement request',
                'You have been selected as a replacement for Booking #' . $code . ' on '
                    . booking_replacement_when_label($b) . '. You have '
                    . BOOKING_REPLACEMENT_OFFER_MINUTES . ' minutes to accept.',
                APP_URL . '/tourguide/dashboard.php'
            );
        }
        return 'offered';
    }

    if ($state !== 'no_guide') {
        db()->prepare(
            "UPDATE bookings SET replacement_state='no_guide', replacement_candidate_id=NULL WHERE id=?"
        )->execute([$bookingId]);
        $fromLabel = $from ? format_date($from) : 'the remaining days';
        notify_user(
            (int) $b['tourist_id'],
            'booking_replacement',
            'No replacement guide available',
            'We could not find another guide for ' . $code . ' from ' . $fromLabel
                . '. You can move the remaining days to another date or request a refund.',
            APP_URL . '/tourist/bookings.php'
        );
        notify_active_admins(
            'booking_replacement',
            'Replacement needed: no guide available',
            $code . ' has no free replacement guide from ' . $fromLabel
                . '. The tourist was asked to move the days or request a refund. You can also assign a guide.',
            APP_URL . '/admin/bookings.php'
        );
    }
    return 'no_guide';
}

/** Advance every booking that is between offers or whose offer ran out. */
function booking_replacement_sweep(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    ensure_booking_replacement_offer_schema();
    try {
        $ids = db()->query(
            "SELECT b.id FROM bookings b
             WHERE b.status='approved' AND COALESCE(b.needs_replacement,0)=1
               AND (
                 b.replacement_state IS NULL OR b.replacement_state IN ('', 'searching')
                 OR (b.replacement_state='offered' AND NOT EXISTS (
                     SELECT 1 FROM booking_replacement_offers o
                     WHERE o.booking_id=b.id AND o.status='offered' AND o.expires_at > NOW()
                 ))
               )
             LIMIT 50"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    foreach ($ids as $id) {
        booking_replacement_advance((int) $id);
    }
}

/**
 * Open offer for a guide (with seconds left), joined with its booking.
 *
 * @return array<string,mixed>|null
 */
function booking_replacement_active_offer_for_guide(int $guideProfileId): ?array
{
    if ($guideProfileId < 1) {
        return null;
    }
    ensure_booking_replacement_offer_schema();
    $st = db()->prepare(
        "SELECT o.id AS offer_id, TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) AS offer_secs_left,
                b.*, i.title AS itinerary_title,
                CONCAT(t.first_name, ' ', t.last_name) AS tourist_name
         FROM booking_replacement_offers o
         JOIN bookings b ON b.id = o.booking_id
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         LEFT JOIN users t ON t.id = b.tourist_id
         WHERE o.guide_id=? AND o.status='offered' AND o.expires_at > NOW()
           AND b.status='approved' AND COALESCE(b.needs_replacement,0)=1
         ORDER BY o.expires_at ASC LIMIT 1"
    );
    $st->execute([$guideProfileId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Guide answers a replacement request.
 *
 * @return array{ok:bool,message:string}
 */
function booking_replacement_guide_respond(int $offerId, int $guideProfileId, bool $accept): array
{
    ensure_booking_replacement_offer_schema();
    $st = db()->prepare(
        'SELECT o.*, TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) AS secs_left
         FROM booking_replacement_offers o WHERE o.id=? AND o.guide_id=? LIMIT 1'
    );
    $st->execute([$offerId, $guideProfileId]);
    $o = $st->fetch();
    if (!$o) {
        return ['ok' => false, 'message' => 'That replacement request was not found.'];
    }
    $bookingId = (int) $o['booking_id'];
    if (($o['status'] ?? '') !== 'offered') {
        return ['ok' => false, 'message' => 'This replacement request is no longer open.'];
    }
    if ((int) $o['secs_left'] <= 0) {
        booking_replacement_advance($bookingId);
        return ['ok' => false, 'message' => 'This request expired after ' . BOOKING_REPLACEMENT_OFFER_MINUTES . ' minutes.'];
    }
    $b = booking_replacement_row($bookingId);
    if (!$b || ($b['status'] ?? '') !== 'approved' || !booking_needs_replacement($b)) {
        db()->prepare("UPDATE booking_replacement_offers SET status='cancelled', responded_at=NOW() WHERE id=?")
            ->execute([$offerId]);
        return ['ok' => false, 'message' => 'This tour no longer needs a replacement guide.'];
    }
    $code = (string) ($b['booking_code'] ?? '');

    if (!$accept) {
        db()->prepare(
            "UPDATE booking_replacement_offers SET status='declined', responded_at=NOW() WHERE id=? AND status='offered'"
        )->execute([$offerId]);
        booking_replacement_advance($bookingId);
        return ['ok' => true, 'message' => 'You declined Booking #' . $code . '. We will ask another guide.'];
    }

    foreach (booking_replacement_dates($b) as $d) {
        $msg = guide_booking_availability_message(
            $guideProfileId,
            $d,
            booking_time_for_calendar_date($b, $d),
            $bookingId,
            null,
            $d
        );
        if ($msg !== null) {
            db()->prepare(
                "UPDATE booking_replacement_offers SET status='unavailable', responded_at=NOW() WHERE id=? AND status='offered'"
            )->execute([$offerId]);
            booking_replacement_advance($bookingId);
            return ['ok' => false, 'message' => 'Your schedule no longer fits this tour: ' . $msg];
        }
    }

    $up = db()->prepare(
        "UPDATE booking_replacement_offers SET status='accepted', responded_at=NOW()
         WHERE id=? AND status='offered' AND expires_at > NOW()"
    );
    $up->execute([$offerId]);
    if ($up->rowCount() < 1) {
        booking_replacement_advance($bookingId);
        return ['ok' => false, 'message' => 'This request expired after ' . BOOKING_REPLACEMENT_OFFER_MINUTES . ' minutes.'];
    }
    db()->prepare(
        "UPDATE bookings SET replacement_state='awaiting_tourist', replacement_candidate_id=?
         WHERE id=? AND status='approved' AND COALESCE(needs_replacement,0)=1"
    )->execute([$guideProfileId, $bookingId]);

    $card = booking_replacement_guide_card($guideProfileId);
    $dayNum = booking_replacement_day_number($b);
    notify_user(
        (int) $b['tourist_id'],
        'booking_replacement',
        ($dayNum > 1 ? 'Your Day ' . $dayNum . ' guide' : 'Your guide') . ' has become unavailable',
        'We found ' . ($card['name'] ?? 'another guide') . ' to continue ' . $code . ' '
            . booking_replacement_relative_day($b) . '. Accept the replacement or choose another guide.',
        APP_URL . '/tourist/bookings.php'
    );
    if (function_exists('log_activity')) {
        log_activity(
            tour_guide_user_id($guideProfileId) ?: null,
            'replacement_offer_accept',
            'bookings',
            $code . ': guide accepted the replacement request'
        );
    }
    return [
        'ok' => true,
        'message' => 'Thanks! The tourist will confirm, and the tour will show under Bookings once they accept.',
    ];
}

/**
 * Tourist-owned booking that needs an answer in a pop-up.
 *
 * @return array<string,mixed>|null
 */
function booking_replacement_tourist_pending(int $touristUserId): ?array
{
    if ($touristUserId < 1) {
        return null;
    }
    ensure_booking_replacement_offer_schema();
    $st = db()->prepare(
        "SELECT b.*, i.title AS itinerary_title
         FROM bookings b
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         WHERE b.tourist_id=? AND b.status='approved' AND COALESCE(b.needs_replacement,0)=1
           AND b.replacement_state IN ('awaiting_tourist', 'no_guide')
         ORDER BY b.replacement_from_date ASC, b.id ASC LIMIT 1"
    );
    $st->execute([$touristUserId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** @return array{ok:bool,message:string} */
function booking_replacement_tourist_accept(array $booking, int $touristUserId): array
{
    ensure_booking_replacement_offer_schema();
    $cand = (int) ($booking['replacement_candidate_id'] ?? 0);
    if (booking_replacement_state($booking) !== 'awaiting_tourist' || $cand < 1) {
        return ['ok' => false, 'message' => 'There is no replacement guide waiting for your answer.'];
    }
    $res = booking_assign_replacement($booking, $cand, $touristUserId);
    if ($res['ok']) {
        db()->prepare(
            "UPDATE booking_replacement_offers SET status='confirmed', responded_at=NOW()
             WHERE booking_id=? AND guide_id=? AND status='accepted'"
        )->execute([(int) $booking['id'], $cand]);
        return $res;
    }
    db()->prepare(
        "UPDATE booking_replacement_offers SET status='unavailable', responded_at=NOW()
         WHERE booking_id=? AND guide_id=? AND status='accepted'"
    )->execute([(int) $booking['id'], $cand]);
    db()->prepare(
        'UPDATE bookings SET replacement_state=NULL, replacement_candidate_id=NULL WHERE id=?'
    )->execute([(int) $booking['id']]);
    booking_replacement_advance((int) $booking['id']);
    return ['ok' => false, 'message' => $res['message'] . ' We are looking for another guide.'];
}

/** Tourist turns down the proposed guide; the system asks the next one. */
function booking_replacement_tourist_other(array $booking): array
{
    ensure_booking_replacement_offer_schema();
    $id = (int) ($booking['id'] ?? 0);
    $cand = (int) ($booking['replacement_candidate_id'] ?? 0);
    if (booking_replacement_state($booking) !== 'awaiting_tourist' || $cand < 1) {
        return ['ok' => false, 'message' => 'There is no replacement guide waiting for your answer.'];
    }
    db()->prepare(
        "UPDATE booking_replacement_offers SET status='tourist_declined', responded_at=NOW()
         WHERE booking_id=? AND guide_id=? AND status='accepted'"
    )->execute([$id, $cand]);
    db()->prepare(
        "UPDATE bookings SET replacement_state=NULL, replacement_candidate_id=NULL
         WHERE id=? AND replacement_state='awaiting_tourist'"
    )->execute([$id]);
    $uid = tour_guide_user_id($cand);
    if ($uid > 0) {
        notify_user(
            $uid,
            'booking_replacement',
            'Replacement not needed',
            'The tourist chose a different guide for Booking #' . ($booking['booking_code'] ?? '')
                . '. Thanks for accepting.',
            APP_URL . '/tourguide/bookings.php'
        );
    }
    $state = booking_replacement_advance($id);
    if ($state === 'offered') {
        return ['ok' => true, 'message' => 'We are asking another guide. You will get a pop-up once one accepts.'];
    }
    return [
        'ok' => true,
        'message' => 'No other guide is free on those days. You can move the remaining days or request a refund.',
    ];
}

/** @return list<int> guides the tourist already turned down for this booking */
function booking_replacement_tourist_declined_ids(int $bookingId): array
{
    $st = db()->prepare(
        "SELECT DISTINCT guide_id FROM booking_replacement_offers WHERE booking_id=? AND status='tourist_declined'"
    );
    $st->execute([$bookingId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * New start dates for the leftover days where at least one guide is free.
 *
 * @return list<array{start:string,end:string}>
 */
function booking_replacement_move_suggestions(array $booking, int $limit = 3): array
{
    $dates = booking_replacement_dates($booking);
    if (!$dates) {
        return [];
    }
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $bookingId = (int) ($booking['id'] ?? 0);
    $touristId = (int) ($booking['tourist_id'] ?? 0);
    $skip = array_merge(
        [booking_original_guide_id($booking), (int) ($booking['guide_id'] ?? 0)],
        booking_replacement_tourist_declined_ids($bookingId)
    );
    $guideIds = [];
    $rows = db()->query(
        "SELECT g.id FROM tour_guide_profiles g JOIN users u ON u.id = g.user_id
         WHERE g.approval_status='approved' AND u.status='active' AND " . guide_profile_complete_sql('g', 'u') . "
         ORDER BY g.avg_rating DESC, g.total_tours DESC"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($rows as $gid) {
        $gid = (int) $gid;
        if (in_array($gid, $skip, true) || booking_is_own_guide($touristId, $gid)) {
            continue;
        }
        $guideIds[] = $gid;
    }
    if (!$guideIds) {
        return [];
    }
    $times = [];
    foreach ($dates as $d) {
        $times[] = booking_time_for_calendar_date($booking, $d);
    }
    $out = [];
    for ($offset = 1; $offset <= BOOKING_REPLACEMENT_MOVE_WINDOW_DAYS && count($out) < $limit; $offset++) {
        $shifted = [];
        foreach ($dates as $d) {
            $shifted[] = date('Y-m-d', strtotime($d . ' 12:00:00 +' . $offset . ' day'));
        }
        if ($shifted[0] <= $today) {
            continue;
        }
        if ($touristId > 0 && tourist_date_range_booking_conflict_message(
            $touristId,
            $shifted[0],
            $shifted[count($shifted) - 1],
            null,
            $bookingId,
            $shifted
        ) !== null) {
            continue;
        }
        foreach ($guideIds as $gid) {
            $ok = true;
            foreach ($shifted as $i => $sd) {
                if (guide_booking_availability_message($gid, $sd, $times[$i], $bookingId, null, $sd) !== null) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = ['start' => $shifted[0], 'end' => $shifted[count($shifted) - 1]];
                break;
            }
        }
    }
    return $out;
}

/**
 * Shift the leftover days to a new start date, then restart the guide search.
 *
 * @return array{ok:bool,message:string}
 */
function booking_replacement_move_dates(array $booking, string $newStart): array
{
    ensure_booking_replacement_offer_schema();
    $id = (int) ($booking['id'] ?? 0);
    if (($booking['status'] ?? '') !== 'approved' || !booking_needs_replacement($booking)) {
        return ['ok' => false, 'message' => 'This tour does not need a replacement guide.'];
    }
    if (booking_replacement_state($booking) !== 'no_guide') {
        return ['ok' => false, 'message' => 'You can move the days only when no replacement guide is free.'];
    }
    $newStart = normalize_tour_date($newStart);
    $dates = booking_replacement_dates($booking);
    if ($newStart === null || !$dates) {
        return ['ok' => false, 'message' => 'Pick one of the suggested dates.'];
    }
    $allowed = array_column(booking_replacement_move_suggestions($booking, 10), 'start');
    if (!in_array($newStart, $allowed, true)) {
        return ['ok' => false, 'message' => 'That date is no longer free. Pick one of the suggested dates.'];
    }
    $from = $dates[0];
    $offset = (int) round((strtotime($newStart . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
    if ($offset < 1) {
        return ['ok' => false, 'message' => 'Pick a later date.'];
    }
    $shift = static fn (string $d): string => date('Y-m-d', strtotime($d . ' 12:00:00 +' . $offset . ' day'));
    $newEnd = $shift($dates[count($dates) - 1]);
    [$spanStart] = booking_effective_tour_span($booking);
    $itinId = (int) ($booking['itinerary_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($itinId > 0) {
            foreach (itinerary_day_dates_map($itinId) as $dayNum => $ymd) {
                $ymd = normalize_tour_date(is_string($ymd) ? $ymd : null);
                if ($ymd === null || $ymd < $from) {
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO itinerary_day_meetups (itinerary_id, day_number, visit_date)
                     VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE visit_date=VALUES(visit_date)'
                )->execute([$itinId, (int) $dayNum, $shift($ymd)]);
            }
            $pdo->prepare(
                'UPDATE itinerary_items SET visit_date = DATE_ADD(visit_date, INTERVAL ? DAY)
                 WHERE itinerary_id=? AND visit_date >= ?'
            )->execute([$offset, $itinId, $from]);
            $pdo->prepare('UPDATE itineraries SET end_date=? WHERE id=?')->execute([$newEnd, $itinId]);
            if ($from <= $spanStart) {
                $pdo->prepare('UPDATE itineraries SET start_date=? WHERE id=?')->execute([$newStart, $itinId]);
            }
        }
        if ($from <= $spanStart) {
            $pdo->prepare('UPDATE bookings SET booking_date=?, tour_start_date=? WHERE id=?')
                ->execute([$newStart, $newStart, $id]);
        }
        $pdo->prepare(
            'UPDATE bookings SET tour_end_date=?, replacement_from_date=?, replacement_state=NULL,
                    replacement_candidate_id=NULL
             WHERE id=?'
        )->execute([$newEnd, $newStart, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not move the remaining days. Try again.'];
    }

    $code = (string) ($booking['booking_code'] ?? '');
    $range = format_date($newStart) . ($newEnd !== $newStart ? ' – ' . format_date($newEnd) : '');
    notify_active_admins(
        'booking_replacement',
        'Tourist moved remaining days',
        $code . ' remaining days moved to ' . $range . ' while looking for a replacement guide.',
        APP_URL . '/admin/bookings.php'
    );
    if (function_exists('log_activity')) {
        log_activity(
            (int) ($booking['tourist_id'] ?? 0) ?: null,
            'replacement_move_days',
            'bookings',
            $code . ': remaining days moved from ' . $from . ' to ' . $newStart
        );
    }
    $state = booking_replacement_advance($id);
    if ($state === 'offered') {
        return [
            'ok' => true,
            'message' => 'Remaining days moved to ' . $range . '. We are asking a guide now; you will get a pop-up once one accepts.',
        ];
    }
    return ['ok' => true, 'message' => 'Remaining days moved to ' . $range . '.'];
}

/** Share of the payment for the leftover days (used for replacement refunds). */
function booking_replacement_refund_amount(array $booking): float
{
    $paid = (float) ($booking['payment_amount'] ?? 0);
    $all = booking_visit_dates($booking);
    $left = booking_replacement_dates($booking);
    if ($paid <= 0 || !$all || !$left) {
        return $paid;
    }
    return round($paid * count($left) / count($all), 2);
}

/** Status line on the tourist booking card while a replacement is in progress. */
function booking_replacement_tourist_status_html(array $booking): string
{
    $state = booking_replacement_state($booking);
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);
    $fromLabel = $from ? format_date($from) : 'the remaining days';
    $html = '<div class="alert alert-warning py-2 small mb-2">';
    $html .= '<div class="fw-semibold mb-1">Your guide cannot continue from ' . e($fromLabel) . '</div>';
    if ($state === 'awaiting_tourist') {
        $card = booking_replacement_guide_card((int) ($booking['replacement_candidate_id'] ?? 0));
        $html .= '<div class="mb-2">' . e($card['name'] ?? 'A guide') . ' accepted and can continue your tour.</div>';
        $html .= '<button type="button" class="btn btn-sm btn-ct" data-repl-open>Review replacement</button>';
    } elseif ($state === 'no_guide') {
        $html .= '<div class="mb-2">No other guide is free on those days. You can move the remaining days or request a refund.</div>';
        $html .= '<button type="button" class="btn btn-sm btn-ct" data-repl-open>See options</button>';
    } else {
        $html .= '<div>We are finding a replacement guide for you. You will get a pop-up once one accepts.</div>';
    }
    $html .= '</div>';
    return $html;
}

/** Status line for admins above the manual picker. */
function booking_replacement_admin_status_html(array $booking): string
{
    ensure_booking_replacement_offer_schema();
    $state = booking_replacement_state($booking);
    $id = (int) ($booking['id'] ?? 0);
    if ($state === 'offered') {
        $st = db()->prepare(
            "SELECT o.guide_id, TIMESTAMPDIFF(MINUTE, NOW(), o.expires_at) AS mins_left
             FROM booking_replacement_offers o
             WHERE o.booking_id=? AND o.status='offered' AND o.expires_at > NOW() LIMIT 1"
        );
        $st->execute([$id]);
        $o = $st->fetch();
        if ($o) {
            $card = booking_replacement_guide_card((int) $o['guide_id']);
            return '<div class="small text-muted mb-2">Waiting for ' . e($card['name'] ?? 'a guide')
                . ' to accept (' . max(1, (int) $o['mins_left'] + 1) . ' min left).</div>';
        }
    }
    if ($state === 'awaiting_tourist') {
        $card = booking_replacement_guide_card((int) ($booking['replacement_candidate_id'] ?? 0));
        return '<div class="small text-muted mb-2">' . e($card['name'] ?? 'A guide')
            . ' accepted. Waiting for the tourist to confirm.</div>';
    }
    if ($state === 'no_guide') {
        return '<div class="small text-warning mb-2">Escalated: no guide accepted. The tourist can move the days or request a refund, or you can assign a guide below.</div>';
    }
    return '<div class="small text-muted mb-2">Looking for a replacement guide.</div>';
}

/**
 * Pop-up for the signed-in guide or tourist, if one needs an answer.
 *
 * @return array{key:string,html:string,audience:string}
 */
function booking_replacement_popup(array $user): array
{
    $empty = ['key' => '', 'html' => '', 'audience' => ''];
    $role = (int) ($user['role_id'] ?? 0);
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) {
        return $empty;
    }
    ensure_booking_replacement_offer_schema();
    if ($role === ROLE_TOUR_GUIDE) {
        $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
        $gp->execute([$uid]);
        $gid = (int) $gp->fetchColumn();
        $offer = booking_replacement_active_offer_for_guide($gid);
        if (!$offer) {
            return $empty;
        }
        return [
            'key' => 'offer-' . (int) $offer['offer_id'],
            'html' => booking_replacement_guide_popup_html($offer),
            'audience' => 'guide',
        ];
    }
    if ($role === ROLE_TOURIST) {
        $b = booking_replacement_tourist_pending($uid);
        if (!$b) {
            return $empty;
        }
        $state = booking_replacement_state($b);
        return [
            'key' => 'tourist-' . (int) $b['id'] . '-' . $state . '-' . (int) ($b['replacement_candidate_id'] ?? 0)
                . '-' . (string) ($b['replacement_from_date'] ?? ''),
            'html' => booking_replacement_tourist_popup_html($b),
            'audience' => 'tourist',
        ];
    }
    return $empty;
}

function booking_replacement_form(string $action, array $fields, string $buttonHtml, string $extraAttr = ''): string
{
    $html = '<form method="post" action="' . e(APP_URL . '/replacement-action.php') . '" data-repl-form' . $extraAttr . '>';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    $html .= $buttonHtml . '</form>';
    return $html;
}

function booking_replacement_guide_popup_html(array $offer): string
{
    $secs = max(0, (int) ($offer['offer_secs_left'] ?? 0));
    $code = (string) ($offer['booking_code'] ?? '');
    $dates = booking_replacement_dates($offer);
    $dayBits = [];
    $allDates = booking_visit_dates($offer);
    foreach ($dates as $d) {
        $n = array_search($d, $allDates, true);
        $dayBits[] = ($n !== false ? 'Day ' . ((int) $n + 1) . ' · ' : '') . format_date($d, 'D, M j')
            . ' · ' . format_time_12h(booking_time_for_calendar_date($offer, $d));
    }
    $party = (int) ($offer['party_size'] ?? 1);

    $html = '<div class="modal fade ct-repl-modal" id="ctReplacementModal" tabindex="-1" data-bs-backdrop="static"'
        . ' aria-labelledby="ctReplacementLabel" aria-hidden="true">';
    $html .= '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">';
    $html .= '<div class="modal-header border-0 pb-0"><h2 class="modal-title h5" id="ctReplacementLabel">'
        . '<i class="bi bi-person-raised-hand me-2"></i>Replacement request</h2></div>';
    $html .= '<div class="modal-body" data-repl-body>';
    $html .= '<p class="ct-repl__lead">You have been selected as a replacement for <strong>Booking #' . e($code)
        . '</strong> on <strong>' . e(booking_replacement_when_label($offer)) . '</strong>.</p>';
    $html .= '<div class="ct-repl__card">';
    if (!empty($offer['itinerary_title'])) {
        $html .= '<div class="ct-repl__row"><span>Tour</span><strong>' . e((string) $offer['itinerary_title']) . '</strong></div>';
    }
    $html .= '<div class="ct-repl__row"><span>Days to cover</span><strong>' . implode('<br>', array_map('e', $dayBits)) . '</strong></div>';
    $html .= '<div class="ct-repl__row"><span>Tourist</span><strong>' . e(trim((string) ($offer['tourist_name'] ?? '')) ?: '—') . '</strong></div>';
    $html .= '<div class="ct-repl__row"><span>Guests</span><strong>' . $party . '</strong></div>';
    if (!empty($offer['is_pwd'])) {
        $html .= '<div class="ct-repl__row"><span>Accessibility</span><strong><span class="badge text-bg-info">Accessibility needs</span></strong></div>';
    }
    $html .= '</div>';
    $html .= '<div class="ct-repl__timer" data-repl-timer data-repl-secs="' . $secs . '">'
        . '<i class="bi bi-hourglass-split"></i> Time left to answer: <strong data-repl-countdown>'
        . sprintf('%d:%02d', intdiv($secs, 60), $secs % 60) . '</strong></div>';
    $html .= '<div class="ct-repl__msg" data-repl-msg hidden></div>';
    $html .= '</div>';
    $html .= '<div class="modal-footer border-0 pt-0 ct-repl__actions" data-repl-actions>';
    $html .= booking_replacement_form(
        'guide_decline',
        ['offer_id' => (int) $offer['offer_id']],
        '<button type="submit" class="btn btn-outline-secondary">Decline</button>'
    );
    $html .= booking_replacement_form(
        'guide_accept',
        ['offer_id' => (int) $offer['offer_id']],
        '<button type="submit" class="btn btn-ct" data-repl-accept>Accept</button>'
    );
    $html .= '</div></div></div></div>';
    return $html;
}

function booking_replacement_tourist_popup_html(array $b): string
{
    $state = booking_replacement_state($b);
    $id = (int) $b['id'];
    $code = (string) ($b['booking_code'] ?? '');
    $dayNum = booking_replacement_day_number($b);
    $who = $dayNum > 1 ? 'Your Day ' . $dayNum . ' guide' : 'Your guide';
    $reason = booking_replacement_reason_phrase($b);
    $when = booking_replacement_relative_day($b);

    $html = '<div class="modal fade ct-repl-modal" id="ctReplacementModal" tabindex="-1"'
        . ' aria-labelledby="ctReplacementLabel" aria-hidden="true">';
    $html .= '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">';

    if ($state === 'awaiting_tourist') {
        $card = booking_replacement_guide_card((int) ($b['replacement_candidate_id'] ?? 0)) ?? [];
        $name = (string) ($card['name'] ?? 'Tour guide');
        $html .= '<div class="modal-header border-0 pb-0"><h2 class="modal-title h5" id="ctReplacementLabel">'
            . e($who) . ' has become unavailable</h2>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>';
        $html .= '<div class="modal-body" data-repl-body>';
        $html .= '<p class="ct-repl__lead">Unfortunately, your original guide is unable to continue' . e($reason)
            . '. We’ve found another qualified guide who can continue your tour ' . e($when) . '.</p>';
        $html .= '<div class="ct-repl__guide">';
        if (!empty($card['avatar'])) {
            $html .= '<img class="ct-repl__avatar" src="' . e((string) $card['avatar']) . '" alt="">';
        } else {
            $initials = '';
            foreach (preg_split('/\s+/', $name) ?: [] as $part) {
                if ($part !== '' && strlen($initials) < 2) {
                    $initials .= strtoupper(substr($part, 0, 1));
                }
            }
            $html .= '<span class="ct-repl__avatar ct-repl__avatar--initials">' . e($initials ?: '?') . '</span>';
        }
        $html .= '<div class="ct-repl__guide-info">';
        $html .= '<div class="ct-repl__row"><span>Replacement</span><strong>' . e($name) . '</strong></div>';
        $html .= '<div class="ct-repl__row"><span>Languages</span><strong>' . e((string) ($card['languages'] ?? '—')) . '</strong></div>';
        $html .= '<div class="ct-repl__row"><span>Tour experience</span><strong>' . e((string) ($card['experience'] ?? '—')) . '</strong></div>';
        if ((float) ($card['rating'] ?? 0) > 0) {
            $html .= '<div class="ct-repl__row"><span>Rating</span><strong>' . e(number_format((float) $card['rating'], 1)) . ' <i class="bi bi-star-fill text-warning"></i></strong></div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="small text-muted mt-2">Booking #' . e($code) . ' · ' . e(booking_replacement_when_label($b)) . '</div>';
        $html .= '<div class="ct-repl__msg" data-repl-msg hidden></div>';
        $html .= '</div>';
        $html .= '<div class="modal-footer border-0 pt-0 ct-repl__actions" data-repl-actions>';
        $html .= booking_replacement_form(
            'tourist_other',
            ['id' => $id],
            '<button type="submit" class="btn btn-outline-secondary">Choose another guide</button>'
        );
        $html .= booking_replacement_form(
            'tourist_accept',
            ['id' => $id],
            '<button type="submit" class="btn btn-ct">Accept replacement</button>'
        );
        $html .= '</div></div></div></div>';
        return $html;
    }

    $from = normalize_tour_date($b['replacement_from_date'] ?? null);
    $fromLabel = $from ? format_date($from) : 'the remaining days';
    $suggestions = booking_replacement_move_suggestions($b);
    $paid = ($b['payment_status'] ?? '') === 'paid';

    $html .= '<div class="modal-header border-0 pb-0"><h2 class="modal-title h5" id="ctReplacementLabel">'
        . 'No replacement guide is available</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>';
    $html .= '<div class="modal-body" data-repl-body>';
    $html .= '<p class="ct-repl__lead">' . e($who) . ' is unable to continue' . e($reason)
        . ', and no other guide is free from ' . e($fromLabel) . '. Choose what you’d like to do with Booking #'
        . e($code) . '.</p>';

    $html .= '<div class="ct-repl__option">';
    $html .= '<div class="ct-repl__option-title"><i class="bi bi-calendar-event me-1"></i>Move to another day</div>';
    if ($suggestions) {
        $html .= '<form method="post" action="' . e(APP_URL . '/replacement-action.php') . '" data-repl-form>';
        $html .= csrf_field() . '<input type="hidden" name="action" value="tourist_move">'
            . '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<div class="small text-muted mb-2">A guide is free on these dates:</div>';
        foreach ($suggestions as $i => $s) {
            $label = format_date($s['start'], 'D, M j') . ($s['end'] !== $s['start'] ? ' – ' . format_date($s['end'], 'D, M j') : '');
            $rid = 'replMove' . $id . '_' . $i;
            $html .= '<div class="form-check"><input class="form-check-input" type="radio" name="new_start" id="' . $rid
                . '" value="' . e($s['start']) . '"' . ($i === 0 ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $rid . '">' . e($label) . '</label></div>';
        }
        $html .= '<button type="submit" class="btn btn-sm btn-ct mt-2">Move remaining days</button></form>';
    } else {
        $html .= '<div class="small text-muted">No guide is free in the next ' . BOOKING_REPLACEMENT_MOVE_WINDOW_DAYS
            . ' days. The Tourism Office has been told and may still assign one.</div>';
    }
    $html .= '</div>';

    $html .= '<div class="ct-repl__option">';
    $html .= '<div class="ct-repl__option-title"><i class="bi bi-cash-coin me-1"></i>Request a refund</div>';
    $html .= '<form method="post" action="' . e(APP_URL . '/tourist/bookings.php') . '" enctype="multipart/form-data"'
        . ' onsubmit="return confirm(' . e(json_encode('Cancel the remaining days of this tour?')) . ');">';
    $html .= csrf_field() . '<input type="hidden" name="action" value="cancel">'
        . '<input type="hidden" name="id" value="' . $id . '">';
    if ($paid) {
        $html .= '<div class="small text-muted mb-2">We will refund '
            . e(format_money(booking_replacement_refund_amount($b)))
            . ' for the days you did not use. Where should we send it?</div>';
        $html .= booking_refund_destination_fields('replRefundNumber' . $id);
    } else {
        $html .= '<div class="small text-muted mb-2">This cancels the remaining days. Finished days stay as they are.</div>';
    }
    $html .= '<button type="submit" class="btn btn-sm btn-outline-danger mt-2">'
        . ($paid ? 'Cancel remaining days and refund' : 'Cancel remaining days') . '</button></form>';
    $html .= booking_refund_destination_script();
    $html .= '</div>';
    $html .= '<div class="small text-muted">The Tourism Office has been notified.</div>';
    $html .= '<div class="ct-repl__msg" data-repl-msg hidden></div>';
    $html .= '</div></div></div></div>';
    return $html;
}

/**
 * Same pop-up as booking_replacement_popup(), as plain data for the mobile app.
 *
 * @return array<string,mixed>|null
 */
function booking_replacement_popup_data(array $user): ?array
{
    $role = (int) ($user['role_id'] ?? 0);
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) {
        return null;
    }
    ensure_booking_replacement_offer_schema();

    if ($role === ROLE_TOUR_GUIDE) {
        $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
        $gp->execute([$uid]);
        $offer = booking_replacement_active_offer_for_guide((int) $gp->fetchColumn());
        if (!$offer) {
            return null;
        }
        $allDates = booking_visit_dates($offer);
        $days = [];
        foreach (booking_replacement_dates($offer) as $d) {
            $n = array_search($d, $allDates, true);
            $days[] = ($n !== false ? 'Day ' . ((int) $n + 1) . ' · ' : '') . format_date($d, 'D, M j')
                . ' · ' . format_time_12h(booking_time_for_calendar_date($offer, $d));
        }
        return [
            'key' => 'offer-' . (int) $offer['offer_id'],
            'audience' => 'guide',
            'state' => 'offer',
            'offer_id' => (int) $offer['offer_id'],
            'booking_id' => (int) $offer['id'],
            'booking_code' => (string) ($offer['booking_code'] ?? ''),
            'title' => 'Replacement request',
            'lead' => 'You have been selected as a replacement for Booking #' . ($offer['booking_code'] ?? '')
                . ' on ' . booking_replacement_when_label($offer) . '.',
            'tour' => (string) ($offer['itinerary_title'] ?? ''),
            'days' => $days,
            'tourist' => trim((string) ($offer['tourist_name'] ?? '')),
            'party_size' => (int) ($offer['party_size'] ?? 1),
            'is_pwd' => !empty($offer['is_pwd']),
            'secs_left' => max(0, (int) ($offer['offer_secs_left'] ?? 0)),
        ];
    }

    if ($role !== ROLE_TOURIST) {
        return null;
    }
    $b = booking_replacement_tourist_pending($uid);
    if (!$b) {
        return null;
    }
    $state = booking_replacement_state($b);
    $dayNum = booking_replacement_day_number($b);
    $who = $dayNum > 1 ? 'Your Day ' . $dayNum . ' guide' : 'Your guide';
    $reason = booking_replacement_reason_phrase($b);
    $base = [
        'key' => 'tourist-' . (int) $b['id'] . '-' . $state . '-' . (int) ($b['replacement_candidate_id'] ?? 0)
            . '-' . (string) ($b['replacement_from_date'] ?? ''),
        'audience' => 'tourist',
        'state' => $state,
        'booking_id' => (int) $b['id'],
        'booking_code' => (string) ($b['booking_code'] ?? ''),
        'when' => booking_replacement_when_label($b),
    ];

    if ($state === 'awaiting_tourist') {
        $card = booking_replacement_guide_card((int) ($b['replacement_candidate_id'] ?? 0)) ?? [];
        return $base + [
            'title' => $who . ' has become unavailable',
            'lead' => 'Unfortunately, your original guide is unable to continue' . $reason
                . '. We’ve found another qualified guide who can continue your tour '
                . booking_replacement_relative_day($b) . '.',
            'guide' => [
                'name' => (string) ($card['name'] ?? 'Tour guide'),
                'languages' => (string) ($card['languages'] ?? '—'),
                'experience' => (string) ($card['experience'] ?? '—'),
                'rating' => (float) ($card['rating'] ?? 0),
                'avatar_url' => $card['avatar'] ?? null,
            ],
        ];
    }

    $from = normalize_tour_date($b['replacement_from_date'] ?? null);
    $moves = [];
    foreach (booking_replacement_move_suggestions($b) as $s) {
        $moves[] = [
            'start' => $s['start'],
            'end' => $s['end'],
            'label' => format_date($s['start'], 'D, M j')
                . ($s['end'] !== $s['start'] ? ' – ' . format_date($s['end'], 'D, M j') : ''),
        ];
    }
    $paid = ($b['payment_status'] ?? '') === 'paid';
    return $base + [
        'title' => 'No replacement guide is available',
        'lead' => $who . ' is unable to continue' . $reason . ', and no other guide is free from '
            . ($from ? format_date($from) : 'the remaining days') . '. Choose what you’d like to do with Booking #'
            . ($b['booking_code'] ?? '') . '.',
        'moves' => $moves,
        'move_window_days' => BOOKING_REPLACEMENT_MOVE_WINDOW_DAYS,
        'paid' => $paid,
        'refund_amount' => $paid ? booking_replacement_refund_amount($b) : 0,
        'refund_amount_label' => $paid ? format_money(booking_replacement_refund_amount($b)) : '',
    ];
}
