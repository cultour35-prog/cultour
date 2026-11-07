<?php
/**
 * CulTour booking validation + status transitions (web + API).
 */

/** Allowed party size range */
const BOOKING_PARTY_MIN = 1;
const BOOKING_PARTY_MAX = 20;
const BOOKING_FITNESS_CERT_MAX_BYTES = 8388608;
const BOOKING_STOP_CHECKIN_MAX_BYTES = 8388608;

require_once __DIR__ . '/booking_replacement.php';
require_once __DIR__ . '/refunds.php';

/**
 * Columns for mid-tour guide withdrawal / replacement.
 */
function ensure_booking_replacement_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return;
        }
        $add = [
            'original_guide_id' => 'INT UNSIGNED NULL',
            'replacement_from_date' => 'DATE NULL',
            'needs_replacement' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'withdraw_reason' => 'VARCHAR(255) NULL',
            'withdrawn_at' => 'DATETIME NULL',
        ];
        foreach ($add as $col => $def) {
            if (in_array($col, $cols, true)) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN `' . $col . '` ' . $def);
                $cols[] = $col;
            } catch (Throwable $e) {
                // ignore one-column failures
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * PWD / fitness-to-travel certificate columns on bookings.
 */
function ensure_booking_accessibility_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return;
        }
        $add = [
            'is_pwd' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'pwd_notes' => 'VARCHAR(255) NULL',
            'fitness_certificate_path' => 'VARCHAR(255) NULL',
        ];
        foreach ($add as $col => $def) {
            if (in_array($col, $cols, true)) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN `' . $col . '` ' . $def);
                $cols[] = $col;
            } catch (Throwable $e) {
                // ignore one-column failures
            }
        }
        ensure_booking_certs_private();
    } catch (Throwable $e) {
        // ignore
    }
}

/** Block direct HTTP access to fitness-to-travel uploads. */
function ensure_booking_certs_private(): void
{
    $dir = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'booking_certs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}

function booking_is_pwd(array $booking): bool
{
    return !empty($booking['is_pwd']) && (int) $booking['is_pwd'] === 1;
}

function booking_fitness_certificate_url(array $booking): ?string
{
    $path = trim((string) ($booking['fitness_certificate_path'] ?? ''));
    $id = (int) ($booking['id'] ?? 0);
    if ($path === '' || $id < 1) {
        return null;
    }
    return rtrim(APP_URL, '/') . '/booking-fitness-cert.php?id=' . $id;
}

function booking_user_can_view_fitness_certificate(array $user, array $booking): bool
{
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) {
        return false;
    }
    if ((int) ($user['role_id'] ?? 0) === ROLE_ADMIN) {
        return true;
    }
    if ($uid === (int) ($booking['tourist_id'] ?? 0)) {
        return true;
    }
    $guideIds = [];
    $current = (int) ($booking['guide_id'] ?? 0);
    $original = booking_original_guide_id($booking);
    if ($current > 0) {
        $guideIds[] = $current;
    }
    if ($original > 0) {
        $guideIds[] = $original;
    }
    foreach (array_unique($guideIds) as $gid) {
        if (tour_guide_user_id((int) $gid) === $uid) {
            return true;
        }
    }
    return false;
}

/**
 * Read the accessibility flag. A fitness-to-travel certificate is optional.
 *
 * @return array{ok:bool,message?:string,is_pwd:int,pwd_notes:?string,certificate_path:?string}
 */
function booking_parse_accessibility(?array $post = null, ?array $files = null, bool $fromApi = false): array
{
    ensure_booking_accessibility_schema();
    ensure_booking_certs_private();
    $post = $post ?? $_POST;
    $files = $files ?? $_FILES;
    $raw = $post['is_pwd'] ?? $post['is_handicapped'] ?? $post['handicapped'] ?? 0;
    $isPwd = in_array(strtolower(trim((string) $raw)), ['1', 'yes', 'on', 'true'], true);
    $notes = trim((string) ($post['pwd_notes'] ?? ''));
    if (strlen($notes) > 255) {
        $notes = substr($notes, 0, 255);
    }
    $empty = [
        'ok' => true,
        'is_pwd' => 0,
        'pwd_notes' => null,
        'certificate_path' => null,
    ];
    if (!$isPwd) {
        return $empty;
    }

    $file = $files['fitness_certificate'] ?? null;
    $err = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if (!is_array($file) || $err === UPLOAD_ERR_NO_FILE) {
        return [
            'ok' => true,
            'is_pwd' => 1,
            'pwd_notes' => $notes !== '' ? $notes : null,
            'certificate_path' => null,
        ];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return [
            'ok' => false,
            'message' => 'Certificate must be 8 MB or smaller.',
            'is_pwd' => 1,
            'pwd_notes' => $notes !== '' ? $notes : null,
            'certificate_path' => null,
        ];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [
            'ok' => false,
            'message' => 'The fitness-to-travel certificate could not be uploaded. Try a PDF or photo up to 8 MB.',
            'is_pwd' => 1,
            'pwd_notes' => $notes !== '' ? $notes : null,
            'certificate_path' => null,
        ];
    }
    if ((int) ($file['size'] ?? 0) > BOOKING_FITNESS_CERT_MAX_BYTES) {
        return [
            'ok' => false,
            'message' => 'Certificate must be 8 MB or smaller.',
            'is_pwd' => 1,
            'pwd_notes' => $notes !== '' ? $notes : null,
            'certificate_path' => null,
        ];
    }

    $path = upload_file($file, 'booking_certs', ['pdf', 'jpg', 'jpeg', 'png', 'webp']);
    if (!$path) {
        return [
            'ok' => false,
            'message' => 'Certificate must be a PDF or photo (JPG, PNG, or WebP).',
            'is_pwd' => 1,
            'pwd_notes' => $notes !== '' ? $notes : null,
            'certificate_path' => null,
        ];
    }

    return [
        'ok' => true,
        'is_pwd' => 1,
        'pwd_notes' => $notes !== '' ? $notes : null,
        'certificate_path' => $path,
    ];
}

function booking_save_accessibility(int $bookingId, array $acc): void
{
    if ($bookingId < 1) {
        return;
    }
    ensure_booking_accessibility_schema();
    db()->prepare(
        'UPDATE bookings SET is_pwd=?, pwd_notes=?, fitness_certificate_path=? WHERE id=?'
    )->execute([
        !empty($acc['is_pwd']) ? 1 : 0,
        $acc['pwd_notes'] ?? null,
        $acc['certificate_path'] ?? null,
        $bookingId,
    ]);
}

function booking_accessibility_notify_line(array $acc): string
{
    if (empty($acc['is_pwd'])) {
        return '';
    }
    $line = ' Includes a guest with accessibility needs.';
    if (!empty($acc['certificate_path'])) {
        $line .= ' Fitness-to-travel certificate attached.';
    }
    $notes = trim((string) ($acc['pwd_notes'] ?? ''));
    if ($notes !== '') {
        $line .= ' Needs: ' . $notes;
    }
    return $line;
}

function booking_accessibility_html(array $booking): string
{
    if (!booking_is_pwd($booking)) {
        return '';
    }
    $html = '<div class="booking-pwd-note mt-1">';
    $html .= '<span class="badge text-bg-info">Accessibility needs</span>';
    $html .= '</div>';
    return $html;
}

/**
 * Resolve tour start/end to store on a booking (frozen for guide schedule).
 *
 * @return array{0:string,1:string} [tour_start_date, tour_end_date] Y-m-d
 */
function booking_resolve_tour_span(?int $itineraryId, string $fallbackDate): array
{
    $fallback = normalize_tour_date($fallbackDate) ?: date('Y-m-d');
    $start = $fallback;
    $end = $fallback;
    if ($itineraryId && $itineraryId > 0) {
        $st = db()->prepare(
            'SELECT start_date, end_date, template_day_count, is_suggested FROM itineraries WHERE id=? LIMIT 1'
        );
        $st->execute([$itineraryId]);
        $row = $st->fetch() ?: null;
        if ($row) {
            $s = normalize_tour_date($row['start_date'] ?? null);
            $e = normalize_tour_date($row['end_date'] ?? null);
            if ($s !== null) {
                $start = $s;
            }
            if ($e !== null) {
                $end = $e;
            } elseif ($s !== null) {
                $end = $start;
            } else {
                // Undated multi-day template: span from booking/fallback date
                $days = itinerary_template_day_count($row);
                $end = itinerary_date_for_day($start, $days) ?: $start;
            }
            if ($end < $start) {
                $end = $start;
            }
        }
    }
    return [$start, $end];
}

/**
 * Pending/approved booking linked to this itinerary (locks schedule edits).
 */
function itinerary_active_booking(int $itineraryId): ?array
{
    if ($itineraryId < 1) {
        return null;
    }
    ensure_booking_payment_schema();
    ensure_booking_replacement_schema();
    $st = db()->prepare(
        "SELECT b.id, b.booking_code, b.status, b.booking_date, b.start_time, b.tour_start_date, b.tour_end_date,
                b.guide_id, b.party_size, b.payment_status, b.needs_replacement,
                CONCAT(gu.first_name, ' ', gu.last_name) AS guide_name,
                gu.avatar AS guide_avatar,
                gu.phone AS guide_phone,
                gp.languages AS guide_languages,
                gp.hourly_rate AS guide_hourly_rate
         FROM bookings b
         LEFT JOIN tour_guide_profiles gp ON gp.id = b.guide_id
         LEFT JOIN users gu ON gu.id = gp.user_id
         WHERE b.itinerary_id=? AND b.status IN ('pending','approved')
           AND NOT (b.status='pending' AND b.payment_status='unpaid')
         ORDER BY b.id DESC
         LIMIT 1"
    );
    $st->execute([$itineraryId]);
    $row = $st->fetch();
    return $row ?: null;
}

function itinerary_schedule_is_locked(int $itineraryId): bool
{
    return itinerary_active_booking($itineraryId) !== null;
}

/**
 * Drop a checkout that was never scanned. Going back must not leave the tour booked or paid.
 */
function booking_abandon_unpaid_checkout(int $itineraryId, int $touristId): void
{
    if ($itineraryId < 1 || $touristId < 1) {
        return;
    }
    ensure_booking_payment_schema();
    db()->prepare(
        "DELETE FROM bookings
         WHERE itinerary_id=? AND tourist_id=? AND status='pending' AND payment_status='unpaid'"
    )->execute([$itineraryId, $touristId]);
}

/**
 * Itinerary ids that currently have a pending/approved booking.
 *
 * @param list<int> $itineraryIds
 * @return array<int,true>
 */
function itinerary_ids_with_active_booking(array $itineraryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itineraryIds))));
    if (!$ids) {
        return [];
    }
    ensure_booking_payment_schema();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT DISTINCT itinerary_id FROM bookings
         WHERE itinerary_id IN ($ph) AND status IN ('pending','approved')
           AND NOT (status='pending' AND payment_status='unpaid')"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $out[(int) $id] = true;
    }
    return $out;
}

/**
 * Itinerary ids whose tour has finished (completed booking).
 *
 * @param list<int> $itineraryIds
 * @return array<int,true>
 */
function itinerary_ids_with_completed_booking(array $itineraryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itineraryIds))));
    if (!$ids) {
        return [];
    }
    ensure_booking_payment_schema();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT DISTINCT itinerary_id FROM bookings
         WHERE itinerary_id IN ($ph) AND status='completed'"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $out[(int) $id] = true;
    }
    return $out;
}

/**
 * Effective tour span for a booking row (snapshot first, then itinerary, then booking_date).
 *
 * @param array $booking Must include booking_date; optional tour_start_date, tour_end_date, itin_start, itin_end
 * @return array{0:string,1:string}
 */
function booking_effective_tour_span(array $booking): array
{
    $fallback = normalize_tour_date($booking['booking_date'] ?? null) ?: date('Y-m-d');
    $start = normalize_tour_date($booking['tour_start_date'] ?? null)
        ?: normalize_tour_date($booking['itin_start'] ?? null)
        ?: $fallback;
    $end = normalize_tour_date($booking['tour_end_date'] ?? null)
        ?: normalize_tour_date($booking['itin_end'] ?? null)
        ?: $start;
    if ($end < $start) {
        $end = $start;
    }
    return [$start, $end];
}

function booking_original_guide_id(array $booking): int
{
    $orig = (int) ($booking['original_guide_id'] ?? 0);
    if ($orig > 0) {
        return $orig;
    }
    return (int) ($booking['guide_id'] ?? 0);
}

function booking_needs_replacement(array $booking): bool
{
    return !empty($booking['needs_replacement']);
}

/**
 * Visit dates covered by this booking (itinerary days inside the frozen span).
 *
 * @return list<string>
 */
function booking_visit_dates(array $booking): array
{
    [$start, $end] = booking_effective_tour_span($booking);
    $itinId = (int) ($booking['itinerary_id'] ?? 0);
    $dates = $itinId > 0 ? itinerary_visit_dates_list($itinId) : [];
    if (!$dates) {
        $dates = itinerary_span_dates(null, $start, $end);
    }
    $out = [];
    foreach ($dates as $d) {
        $n = normalize_tour_date(is_string($d) ? $d : null);
        if ($n !== null && $n >= $start && $n <= $end) {
            $out[] = $n;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Leftover visit dates from today (or from a chosen withdraw day).
 *
 * @return list<string>
 */
function booking_remaining_visit_dates(array $booking, ?string $fromDate = null): array
{
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $from = normalize_tour_date($fromDate) ?: $today;
    if ($from < $today) {
        $from = $today;
    }
    $out = [];
    foreach (booking_visit_dates($booking) as $d) {
        if ($d >= $from) {
            $out[] = $d;
        }
    }
    return $out;
}

/**
 * Whether this guide still owns the booking on a calendar date
 * (original guide keeps finished days; replacement owns leftover days).
 */
function booking_guide_covers_date(array $booking, int $guideProfileId, string $date): bool
{
    if ($guideProfileId < 1) {
        return false;
    }
    $date = normalize_tour_date($date);
    if ($date === null) {
        return false;
    }
    $current = (int) ($booking['guide_id'] ?? 0);
    $original = booking_original_guide_id($booking);
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);

    if ($from === null) {
        return $current === $guideProfileId;
    }
    if ($date < $from) {
        return $original === $guideProfileId;
    }
    if (booking_needs_replacement($booking)) {
        // Leftover days stay reserved on the original/current guide until a replacement is assigned.
        return $current === $guideProfileId || $original === $guideProfileId;
    }
    return $current === $guideProfileId;
}

function booking_calendar_day_number(array $booking, string $date): int
{
    [$spanStart] = booking_effective_tour_span($booking);
    return itinerary_day_number($spanStart, $date);
}

/** Meetup clock minutes for a visit date (per-day meetup, then day-1 itinerary meetup). */
function booking_calendar_day_meetup_mins(array $booking, string $date): ?int
{
    $itinId = (int) ($booking['itinerary_id'] ?? 0);
    $dayNum = booking_calendar_day_number($booking, $date);
    $raw = null;
    if ($itinId > 0 && function_exists('itinerary_day_stored_meetup_time')) {
        $raw = itinerary_day_stored_meetup_time($itinId, $dayNum);
    }
    if (($raw === null || trim((string) $raw) === '') && $dayNum === 1) {
        $raw = $booking['itin_meetup'] ?? $booking['meetup_time'] ?? null;
        if (($raw === null || $raw === '') && $itinId > 0) {
            try {
                $m = db()->prepare('SELECT meetup_time FROM itineraries WHERE id=?');
                $m->execute([$itinId]);
                $raw = $m->fetchColumn() ?: null;
            } catch (Throwable $e) {
                $raw = null;
            }
        }
    }
    return itin_time_to_minutes($raw !== null ? (string) $raw : null);
}

/** Weekly availability close for the guide who currently holds this booking. */
function booking_guide_availability_end_mins(array $booking, string $date): ?int
{
    $guideId = (int) ($booking['guide_id'] ?? 0);
    if ($guideId < 1) {
        $guideId = booking_original_guide_id($booking);
    }
    $date = normalize_tour_date($date);
    if ($guideId < 1 || $date === null) {
        return null;
    }
    $dow = (int) date('w', strtotime($date . ' 12:00:00'));
    try {
        $st = db()->prepare(
            'SELECT end_time FROM guide_availability
             WHERE guide_id=? AND day_of_week=? AND is_available=1 LIMIT 1'
        );
        $st->execute([$guideId, $dow]);
        return itin_time_to_minutes((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return null;
    }
}

/** Start time (H:i) to use when checking a guide on one leftover/visit day. */
function booking_time_for_calendar_date(array $booking, string $date): string
{
    $stops = booking_stops_on_calendar_date($booking, $date);
    foreach ($stops as $s) {
        $sm = itin_time_to_minutes($s['start_time'] ?? null);
        if ($sm !== null) {
            return itin_minutes_to_time($sm);
        }
    }
    $meetup = booking_calendar_day_meetup_mins($booking, $date);
    if ($meetup !== null) {
        return itin_minutes_to_time($meetup);
    }
    $fallback = normalize_time_input((string) ($booking['start_time'] ?? '')) ?: '09:00';
    return $fallback;
}

function tour_guide_user_id(int $guideProfileId): int
{
    if ($guideProfileId < 1) {
        return 0;
    }
    $st = db()->prepare('SELECT user_id FROM tour_guide_profiles WHERE id=? LIMIT 1');
    $st->execute([$guideProfileId]);
    return (int) $st->fetchColumn();
}

/** True when this tourist is booking the tour-guide account linked to the same person. */
function booking_is_own_guide(int $touristUserId, int $guideProfileId): bool
{
    if ($touristUserId < 1 || $guideProfileId < 1) {
        return false;
    }
    $guideUserId = tour_guide_user_id($guideProfileId);
    if ($guideUserId < 1) {
        return false;
    }
    if ($guideUserId === $touristUserId) {
        return true;
    }
    $st = db()->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
    $st->execute([$touristUserId]);
    $touristEmail = strtolower(trim((string) $st->fetchColumn()));
    $st->execute([$guideUserId]);
    $guideEmail = strtolower(trim((string) $st->fetchColumn()));
    return $touristEmail !== '' && $touristEmail === $guideEmail;
}

/** Tourist user id linked to this guide profile (same email), or 0. */
function guide_linked_tourist_user_id(int $guideProfileId): int
{
    $guideUserId = tour_guide_user_id($guideProfileId);
    if ($guideUserId < 1) {
        return 0;
    }
    $st = db()->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
    $st->execute([$guideUserId]);
    $email = strtolower(trim((string) $st->fetchColumn()));
    if ($email === '') {
        return 0;
    }
    $st = db()->prepare(
        "SELECT id FROM users
         WHERE LOWER(email)=? AND role_id=? AND id<>?
           AND status IN ('active','pending')
         LIMIT 1"
    );
    $st->execute([$email, ROLE_TOURIST, $guideUserId]);
    return (int) $st->fetchColumn();
}

function notify_active_admins(string $type, string $title, ?string $body = null, ?string $link = null): void
{
    try {
        $ids = db()->query(
            'SELECT id FROM users WHERE role_id=' . (int) ROLE_ADMIN . " AND status='active'"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            notify_user((int) $id, $type, $title, $body, $link);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Default busy length when a tour day has no timed stops (minutes). */
const GUIDE_BOOKING_BUSY_FALLBACK_MINS = 240;

/** Minimum gap required between two guide tours (minutes). */
const GUIDE_BOOKING_BUFFER_MINS = 30;

/** Same-day bookings close this many minutes before weekly availability end. */
const GUIDE_BOOKING_CUTOFF_BEFORE_END_MINS = 120;

/**
 * Clock minutes for the same-day booking cutoff (end minus 2 hours).
 */
function guide_booking_cutoff_mins(?string $endTime): ?int
{
    $close = itin_time_to_minutes($endTime);
    if ($close === null) {
        return null;
    }
    return max(0, $close - GUIDE_BOOKING_CUTOFF_BEFORE_END_MINS);
}

/** 12h label for the cutoff, e.g. "6:00 PM". */
function guide_booking_cutoff_display(?string $endTime): string
{
    $mins = guide_booking_cutoff_mins($endTime);
    if ($mins === null) {
        return '';
    }
    return format_minutes_12h($mins);
}

/**
 * Same-day Day 1 booking is closed when now is at or after (availability end − 2 hours).
 */
function guide_same_day_booking_cutoff_message(int $guideId, string $date): ?string
{
    $date = normalize_tour_date($date);
    $today = app_today();
    if ($date === null || $guideId < 1 || $date !== $today) {
        return null;
    }
    $dow = (int) date('w', strtotime($date . ' 12:00:00'));
    $st = db()->prepare(
        'SELECT end_time FROM guide_availability
         WHERE guide_id=? AND day_of_week=? AND is_available=1 LIMIT 1'
    );
    $st->execute([$guideId, $dow]);
    $endRaw = $st->fetchColumn();
    if ($endRaw === false || $endRaw === null || trim((string) $endRaw) === '') {
        return null;
    }
    $close = itin_time_to_minutes((string) $endRaw);
    $cutoff = guide_booking_cutoff_mins((string) $endRaw);
    if ($close === null || $cutoff === null) {
        return null;
    }
    $nowMins = app_now_mins();
    if ($nowMins < $cutoff) {
        return null;
    }
    $endLabel = format_time_12h((string) $endRaw) ?: format_minutes_12h($close);
    $cutLabel = format_minutes_12h($cutoff);
    return 'Same-day booking closed for this guide (cutoff ' . $cutLabel
        . '; available until ' . $endLabel . '). Choose another date.';
}

/**
 * Whether two busy windows overlap, including a buffer gap between them.
 */
function booking_busy_windows_overlap(
    int $aStart,
    int $aEnd,
    int $bStart,
    int $bEnd,
    int $bufferMins = GUIDE_BOOKING_BUFFER_MINS
): bool {
    if ($aEnd < $aStart) {
        $aEnd = $aStart;
    }
    if ($bEnd < $bStart) {
        $bEnd = $bStart;
    }
    $bufferMins = max(0, $bufferMins);
    return !(($aEnd + $bufferMins) <= $bStart || ($bEnd + $bufferMins) <= $aStart);
}

/**
 * Itinerary stops that belong to a calendar date for this booking.
 *
 * @return list<array<string,mixed>>
 */
function booking_stops_on_calendar_date(array $booking, string $date): array
{
    $itinId = (int) ($booking['itinerary_id'] ?? 0);
    if ($itinId < 1) {
        return [];
    }
    $date = normalize_tour_date($date);
    if ($date === null) {
        return [];
    }
    [$tourStart] = booking_effective_tour_span($booking);
    $stops = itinerary_items_with_places($itinId);
    if (!$stops) {
        return [];
    }

    $onDate = [];
    foreach ($stops as $s) {
        $vd = normalize_tour_date($s['visit_date'] ?? null);
        if ($vd === $date) {
            $onDate[] = $s;
        }
    }
    if ($onDate) {
        return $onDate;
    }

    // Undated / template visit_dates: match by day number within the tour.
    $dayNum = itinerary_day_number($tourStart, $date);
    $templateBase = itinerary_template_day_base();
    foreach ($stops as $s) {
        $vd = normalize_tour_date($s['visit_date'] ?? null);
        if ($vd === null) {
            continue;
        }
        $stopDay = str_starts_with($vd, substr($templateBase, 0, 8))
            ? itinerary_day_number($templateBase, $vd)
            : itinerary_day_number($tourStart, $vd);
        if ($stopDay === $dayNum) {
            $onDate[] = $s;
        }
    }
    return $onDate;
}

/**
 * Busy [start_mins, end_mins) for a booking on one calendar day.
 * Null if the booking does not cover that date.
 *
 * @return array{start_mins:int,end_mins:int,date:string}|null
 */
function booking_busy_window_for_date(array $booking, string $date): ?array
{
    $date = normalize_tour_date($date);
    if ($date === null) {
        return null;
    }
    [$spanStart, $spanEnd] = booking_effective_tour_span($booking);
    if ($date < $spanStart || $date > $spanEnd) {
        return null;
    }

    $isDayOne = ($date === $spanStart);
    $stops = booking_stops_on_calendar_date($booking, $date);

    $startMins = null;
    if ($isDayOne) {
        $startMins = itin_time_to_minutes($booking['start_time'] ?? null);
    }
    if ($startMins === null && $stops) {
        foreach ($stops as $s) {
            $sm = itin_time_to_minutes($s['start_time'] ?? null);
            if ($sm !== null) {
                $startMins = $sm;
                break;
            }
        }
    }
    // Per-day meetup (day 1 itinerary meetup, or that day's stored meetup). Does not
    // copy day-1 meetup onto later days.
    if ($startMins === null) {
        $startMins = booking_calendar_day_meetup_mins($booking, $date);
    }
    if ($startMins === null) {
        $startMins = 8 * 60;
    }

    $endMins = null;
    if ($stops) {
        $last = $stops[count($stops) - 1];
        $endMins = itin_time_to_minutes($last['end_time'] ?? null);
        $ls = itin_time_to_minutes($last['start_time'] ?? null);
        $visit = (int) ($last['visit_minutes'] ?? 60);
        if ($visit < 1) {
            $visit = 60;
        }
        if ($endMins === null && $ls !== null) {
            $endMins = itin_compute_end($ls, $visit);
        }
    }
    if ($endMins === null && $isDayOne) {
        $be = itin_time_to_minutes($booking['end_time'] ?? null);
        if ($be !== null) {
            $endMins = $be;
        }
    }
    if ($endMins === null && !$stops) {
        $close = booking_guide_availability_end_mins($booking, $date);
        if ($close !== null && $close > $startMins) {
            $endMins = $close;
        } else {
            $endMins = max($startMins + GUIDE_BOOKING_BUSY_FALLBACK_MINS, 18 * 60);
        }
    }
    if ($endMins === null) {
        $endMins = $startMins + GUIDE_BOOKING_BUSY_FALLBACK_MINS;
    }
    if ($endMins <= $startMins) {
        $endMins = $startMins + 60;
    }
    if ($endMins > 24 * 60) {
        $endMins = 24 * 60;
    }

    // Until the guide presses End tour, the guide stays busy through the last stop
    // in the itinerary. After that, the real end time is what other bookings follow.
    $actualEnd = booking_day_ended_mins((int) ($booking['id'] ?? 0), $date);
    if ($actualEnd !== null) {
        $endMins = $actualEnd;
        if ($endMins < $startMins) {
            $startMins = $endMins;
        }
        if ($endMins <= $startMins) {
            $endMins = $startMins + 1;
        }
    }

    return [
        'start_mins' => $startMins,
        'end_mins' => $endMins,
        'date' => $date,
    ];
}

/**
 * All pending/approved busy windows for a guide on a calendar date.
 *
 * @return list<array{start_mins:int,end_mins:int,date:string,booking_id:int,booking_code:string}>
 */
function guide_busy_windows_on_date(
    int $guideProfileId,
    string $date,
    ?int $excludeBookingId = null,
    ?int $excludeItineraryId = null
): array {
    $date = normalize_tour_date($date);
    if ($date === null || $guideProfileId < 1) {
        return [];
    }
    ensure_booking_payment_schema();
    ensure_booking_replacement_schema();
    ensure_booking_stop_checkin_schema();

    $sql = "SELECT b.*, i.start_date AS itin_start, i.end_date AS itin_end, i.meetup_time AS itin_meetup
            FROM bookings b
            LEFT JOIN itineraries i ON i.id = b.itinerary_id
            WHERE (b.guide_id = ? OR b.original_guide_id = ?)
              AND (
                b.status IN ('pending','approved')
                OR (
                  b.status = 'completed'
                  AND EXISTS (
                    SELECT 1 FROM booking_day_ends e
                    WHERE e.booking_id = b.id AND e.visit_date = ?
                  )
                )
              )
              AND DATE(COALESCE(b.tour_start_date, i.start_date, b.booking_date)) <= ?
              AND DATE(COALESCE(b.tour_end_date, b.tour_start_date, i.end_date, i.start_date, b.booking_date)) >= ?";
    $params = [$guideProfileId, $guideProfileId, $date, $date, $date];
    if ($excludeBookingId && $excludeBookingId > 0) {
        $sql .= ' AND b.id <> ?';
        $params[] = $excludeBookingId;
    }
    if ($excludeItineraryId && $excludeItineraryId > 0) {
        $sql .= ' AND (b.itinerary_id IS NULL OR b.itinerary_id <> ?)';
        $params[] = $excludeItineraryId;
    }
    $sql .= ' ORDER BY b.start_time, b.id';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];

    $windows = [];
    foreach ($rows as $b) {
        if (!booking_guide_covers_date($b, $guideProfileId, $date)) {
            continue;
        }
        $w = booking_busy_window_for_date($b, $date);
        if ($w === null) {
            continue;
        }
        $w['booking_id'] = (int) ($b['id'] ?? 0);
        $w['booking_code'] = (string) ($b['booking_code'] ?? '');
        $windows[] = $w;
    }

    // Same person already has a tour as a tourist: the guide account cannot be booked that day.
    $linkedTouristId = guide_linked_tourist_user_id($guideProfileId);
    if ($linkedTouristId > 0) {
        $sqlT = "SELECT b.*, i.start_date AS itin_start, i.end_date AS itin_end, i.meetup_time AS itin_meetup
                 FROM bookings b
                 LEFT JOIN itineraries i ON i.id = b.itinerary_id
                 WHERE b.tourist_id=?
                   AND b.status IN ('pending','approved')
                   AND DATE(COALESCE(b.tour_start_date, i.start_date, b.booking_date)) <= ?
                   AND DATE(COALESCE(b.tour_end_date, b.tour_start_date, i.end_date, i.start_date, b.booking_date)) >= ?";
        $paramsT = [$linkedTouristId, $date, $date];
        if ($excludeBookingId && $excludeBookingId > 0) {
            $sqlT .= ' AND b.id <> ?';
            $paramsT[] = $excludeBookingId;
        }
        $sqlT .= ' ORDER BY b.start_time, b.id';
        $stT = db()->prepare($sqlT);
        $stT->execute($paramsT);
        $seen = [];
        foreach ($windows as $have) {
            $seen[(int) ($have['booking_id'] ?? 0)] = true;
        }
        foreach ($stT->fetchAll() ?: [] as $b) {
            $bid = (int) ($b['id'] ?? 0);
            if ($bid > 0 && isset($seen[$bid])) {
                continue;
            }
            $w = booking_busy_window_for_date($b, $date);
            if ($w === null) {
                continue;
            }
            $w['start_mins'] = 0;
            $w['end_mins'] = 24 * 60;
            $w['booking_id'] = $bid;
            $w['booking_code'] = (string) ($b['booking_code'] ?? '');
            $w['as_tourist'] = true;
            $windows[] = $w;
        }
    }
    return $windows;
}

/**
 * Human-readable busy range, e.g. "12:00 PM–4:30 PM".
 */
function booking_busy_window_label(?array $window): string
{
    if (!$window) {
        return '';
    }
    return format_minutes_12h((int) $window['start_mins'])
        . '–'
        . format_minutes_12h((int) $window['end_mins']);
}

/** Preset decline reasons for guides */
function booking_decline_reason_presets(): array
{
    return [
        'Unavailable',
        'Schedule conflict',
        'Party too large',
        'Other',
    ];
}

/**
 * Error if any day in [start, end] overlaps a pending/approved booking
 * (by booking_date or another booked itinerary’s span).
 *
 * @param int|null $excludeItineraryId Skip bookings linked to this itinerary (editing its own dates)
 * @param int|null $excludeBookingId   Skip a specific booking id
 * @param list<string>|null $onlyDates If set, check these Y-m-d days only (gaps omitted)
 */
function tourist_date_range_booking_conflict_message(
    int $touristId,
    string $startDate,
    ?string $endDate = null,
    ?int $excludeItineraryId = null,
    ?int $excludeBookingId = null,
    ?array $onlyDates = null
): ?string {
    if ($touristId < 1) {
        return null;
    }
    $checkDates = [];
    if (is_array($onlyDates) && $onlyDates) {
        foreach ($onlyDates as $d) {
            $n = normalize_tour_date(is_string($d) ? $d : null);
            if ($n !== null) {
                $checkDates[] = $n;
            }
        }
    } else {
        $start = normalize_tour_date($startDate);
        $end = normalize_tour_date($endDate) ?: $start;
        if ($start === null) {
            return null;
        }
        if ($end === null || $end < $start) {
            $end = $start;
        }
        $cursor = $start;
        $guard = 0;
        while ($cursor <= $end && $guard < 31) {
            $checkDates[] = $cursor;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            $guard++;
        }
    }
    $checkDates = array_values(array_unique($checkDates));
    if (!$checkDates) {
        return null;
    }

    ensure_booking_payment_schema();
    $sql = "SELECT b.booking_code,
                   DATE(COALESCE(b.tour_start_date, i.start_date, b.booking_date)) AS span_start,
                   DATE(COALESCE(b.tour_end_date, b.tour_start_date, i.end_date, i.start_date, b.booking_date)) AS span_end
            FROM bookings b
            LEFT JOIN itineraries i ON i.id = b.itinerary_id
            WHERE b.tourist_id = ?
              AND b.status IN ('pending','approved')";
    $params = [$touristId];
    if ($excludeBookingId && $excludeBookingId > 0) {
        $sql .= ' AND b.id <> ?';
        $params[] = $excludeBookingId;
    }
    if ($excludeItineraryId && $excludeItineraryId > 0) {
        $sql .= ' AND (b.itinerary_id IS NULL OR b.itinerary_id <> ?)';
        $params[] = $excludeItineraryId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $row) {
        $bStart = normalize_tour_date($row['span_start'] ?? null);
        $bEnd = normalize_tour_date($row['span_end'] ?? null) ?: $bStart;
        if ($bStart === null) {
            continue;
        }
        if ($bEnd === null || $bEnd < $bStart) {
            $bEnd = $bStart;
        }
        $hit = false;
        foreach ($checkDates as $d) {
            if ($d >= $bStart && $d <= $bEnd) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
        $when = format_date($bStart);
        if ($bEnd !== $bStart) {
            $when .= ' – ' . format_date($bEnd);
        }
        $code = trim((string) ($row['booking_code'] ?? ''));
        return 'You already have a booking on ' . $when
            . ($code !== '' ? ' (' . $code . ')' : '')
            . '. You cannot book another tour on those days.';
    }
    return null;
}

/**
 * Error if tourist already has a pending/approved booking on this date
 * (or any day of a multi-day itinerary being booked).
 */
function tourist_booking_date_conflict_message(
    int $touristId,
    string $date,
    ?int $newItineraryId = null,
    ?int $excludeBookingId = null
): ?string {
    $start = normalize_tour_date($date) ?: $date;
    $end = $start;
    if ($newItineraryId) {
        $itin = db()->prepare('SELECT start_date, end_date FROM itineraries WHERE id=? AND user_id=? LIMIT 1');
        $itin->execute([$newItineraryId, $touristId]);
        $row = $itin->fetch();
        if ($row && !empty($row['start_date'])) {
            $start = (string) $row['start_date'];
            $end = !empty($row['end_date']) ? (string) $row['end_date'] : $start;
        }
    }

    return tourist_date_range_booking_conflict_message(
        $touristId,
        $start,
        $end,
        null,
        $excludeBookingId
    );
}

/**
 * Rooms or vehicles still free for every date in the range.
 * Null means this offering has no stock limit.
 * Pending and approved bookings hold the units they needed. A cancelled booking gives them back.
 * Older rows with no unit count hold one.
 */
function offering_units_free(int $serviceId, string $start, string $end, ?int $excludeBookingId = null): ?int
{
    if ($serviceId < 1) {
        return null;
    }
    $start = normalize_tour_date($start) ?: '';
    $end = normalize_tour_date($end) ?: $start;
    if ($start === '' || $end === '' || $end < $start) {
        return null;
    }
    ensure_place_schedule_schema();
    ensure_booking_payment_schema();
    $svc = db()->prepare('SELECT unit_count FROM business_services WHERE id=? LIMIT 1');
    $svc->execute([$serviceId]);
    $totalRaw = $svc->fetchColumn();
    if ($totalRaw === false || $totalRaw === null || $totalRaw === '') {
        return null;
    }
    $total = (int) $totalRaw;
    $st = db()->prepare(
        "SELECT id, accommodation_service_id, transportation_service_id,
                accommodation_units, transportation_units, accommodation_lines, transportation_lines,
                tour_start_date, tour_end_date, booking_date
         FROM bookings
         WHERE status IN ('pending','approved')
           AND (
                accommodation_service_id=?
                OR transportation_service_id=?
                OR accommodation_lines LIKE ?
                OR transportation_lines LIKE ?
           )
           AND COALESCE(tour_end_date, tour_start_date, booking_date) >= ?
           AND COALESCE(tour_start_date, booking_date) <= ?"
    );
    $like = '%"service_id":' . $serviceId . ',%';
    $st->execute([$serviceId, $serviceId, $like, $like, $start, $end]);
    $spans = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        if ($excludeBookingId && (int) ($row['id'] ?? 0) === $excludeBookingId) {
            continue;
        }
        $spanStart = normalize_tour_date($row['tour_start_date'] ?? null)
            ?: normalize_tour_date($row['booking_date'] ?? null);
        $spanEnd = normalize_tour_date($row['tour_end_date'] ?? null) ?: $spanStart;
        if ($spanStart === null) {
            continue;
        }
        if ($spanEnd === null || $spanEnd < $spanStart) {
            $spanEnd = $spanStart;
        }
        $pushHold = static function (string $holdStart, string $holdEnd, int $units) use (&$spans): void {
            if ($units < 1 || $holdStart === '') {
                return;
            }
            if ($holdEnd === '' || $holdEnd < $holdStart) {
                $holdEnd = $holdStart;
            }
            $spans[] = [$holdStart, $holdEnd, $units];
        };
        $pushLine = static function (array $line) use ($pushHold, $spanStart, $spanEnd): void {
            $units = max(0, (int) ($line['units'] ?? 0));
            $dated = normalize_tour_date(isset($line['date']) ? (string) $line['date'] : null);
            if ($dated !== null) {
                $pushHold($dated, $dated, $units);
                return;
            }
            $day = (int) ($line['day'] ?? 0);
            if ($day > 1) {
                $shift = date('Y-m-d', strtotime($spanStart . ' +' . ($day - 1) . ' days'));
                $pushHold($shift !== '' ? $shift : $spanStart, $shift !== '' ? $shift : $spanStart, $units);
                return;
            }
            if ($day === 1) {
                $pushHold($spanStart, $spanStart, $units);
                return;
            }
            $pushHold($spanStart, $spanEnd, $units);
        };
        $stayLines = stay_lines_list($row['accommodation_lines'] ?? null);
        if (is_array($stayLines) && $stayLines !== []) {
            foreach ($stayLines as $stayLine) {
                if (!is_array($stayLine) || (int) ($stayLine['service_id'] ?? 0) !== $serviceId) {
                    continue;
                }
                $pushLine($stayLine);
            }
        } elseif ((int) ($row['accommodation_service_id'] ?? 0) === $serviceId) {
            $raw = $row['accommodation_units'] ?? null;
            $pushHold($spanStart, $spanEnd, ($raw === null || $raw === '') ? 1 : max(0, (int) $raw));
        }
        $rideLines = stay_lines_list($row['transportation_lines'] ?? null);
        if (is_array($rideLines) && $rideLines !== []) {
            foreach ($rideLines as $rideLine) {
                if (!is_array($rideLine) || (int) ($rideLine['service_id'] ?? 0) !== $serviceId) {
                    continue;
                }
                $pushLine($rideLine);
            }
        } elseif ((int) ($row['transportation_service_id'] ?? 0) === $serviceId) {
            $raw = $row['transportation_units'] ?? null;
            $pushHold($spanStart, $spanEnd, ($raw === null || $raw === '') ? 1 : max(0, (int) $raw));
        }
    }
    $peak = 0;
    $cursor = $start;
    $guard = 0;
    while ($cursor <= $end && $guard < 62) {
        $held = 0;
        foreach ($spans as [$spanStart, $spanEnd, $heldUnits]) {
            if ($spanStart <= $cursor && $spanEnd >= $cursor) {
                $held += $heldUnits;
            }
        }
        if ($held > $peak) {
            $peak = $held;
        }
        $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        $guard++;
    }
    return max(0, $total - $peak);
}

function offering_noun(string $noun, int $count): string
{
    return $count === 1 ? $noun : $noun . 's';
}

/**
 * How many units a group needs, and what the extras cost, before multiplying by nights or days.
 * No capacity: one unit at the listed price. A fee fills extras on that unit before another unit is added.
 *
 * @param array<string,mixed> $service
 * @return array{ok:bool,message:string,units:int,extra_guests:int,amount:float,spans:int,label:string,detail:string}
 */
function offering_party_quote(array $service, int $guests, int $spans, string $noun = 'room'): array
{
    $guests = max(1, min(20, $guests));
    $spans = max(1, $spans);
    $price = round((float) ($service['price'] ?? 0), 2);
    $capacityRaw = $service['capacity'] ?? null;
    $capacity = ($capacityRaw === null || $capacityRaw === '' || (int) $capacityRaw < 1) ? null : (int) $capacityRaw;
    $feeRaw = $service['extra_guest_fee'] ?? null;
    $fee = ($feeRaw === null || $feeRaw === '' || (float) $feeRaw <= 0) ? 0.0 : round((float) $feeRaw, 2);
    if ($noun === 'vehicle') {
        $fee = 0.0;
        if ($capacity === null) {
            $capacity = 1;
        }
    }
    $limitRaw = $service['extra_guest_limit'] ?? null;
    $limit = ($limitRaw === null || $limitRaw === '') ? null : max(0, (int) $limitRaw);
    $spanWord = ($noun === 'vehicle' ? 'day' : 'night') . ($spans === 1 ? '' : 's');
    $guestWord = $guests . ' guest' . ($guests === 1 ? '' : 's');
    $blank = [
        'ok' => true,
        'message' => '',
        'units' => 1,
        'extra_guests' => 0,
        'amount' => round($price * $spans, 2),
        'spans' => $spans,
        'label' => '1 ' . $noun,
        'detail' => $guestWord . '. This ' . $noun . ' has no guest limit, so the group stays in 1 ' . $noun
            . ' at ' . format_money($price) . ($spans > 1 ? ' × ' . $spans . ' ' . $spanWord : '') . '.',
    ];
    if ($capacity === null) {
        return $blank;
    }
    $extraLimit = $fee > 0 ? ($limit !== null ? $limit : $capacity) : 0;
    $perUnit = $capacity + $extraLimit;
    if ($perUnit < 1) {
        return [
            'ok' => false,
            'message' => 'This ' . $noun . ' cannot take this group.',
            'units' => 0,
            'extra_guests' => 0,
            'amount' => 0.0,
            'spans' => $spans,
            'label' => '',
            'detail' => '',
        ];
    }
    $units = (int) ceil($guests / $perUnit);
    $remaining = $guests;
    $extras = 0;
    for ($i = 0; $i < $units; $i++) {
        $occupants = min($perUnit, $remaining);
        $extras += max(0, $occupants - $capacity);
        $remaining -= $occupants;
    }
    $label = $units . ' ' . offering_noun($noun, $units);
    if ($extras > 0) {
        $label .= ' · ' . $extras . ' extra guest' . ($extras === 1 ? '' : 's');
    }
    $amount = round(($units * $price + $extras * $fee) * $spans, 2);
    $fit = $noun === 'vehicle'
        ? 'Each vehicle holds ' . $capacity . ' guest' . ($capacity === 1 ? '' : 's')
        : 'Each ' . $noun . ' includes ' . $capacity . ' guest' . ($capacity === 1 ? '' : 's');
    if ($noun !== 'vehicle' && $fee > 0) {
        $fit .= ', then up to ' . $extraLimit . ' more at ' . format_money($fee) . ' each';
    }
    $need = $units . ' ' . offering_noun($noun, $units);
    if ($extras > 0) {
        $need .= ' and ' . $extras . ' extra guest' . ($extras === 1 ? '' : 's');
    }
    $math = $units . ' × ' . format_money($price);
    if ($extras > 0 && $fee > 0) {
        $math .= ' + ' . $extras . ' × ' . format_money($fee);
    }
    if ($spans > 1) {
        $math .= ', then × ' . $spans . ' ' . $spanWord;
    }
    return [
        'ok' => true,
        'message' => '',
        'units' => $units,
        'extra_guests' => $extras,
        'amount' => $amount,
        'spans' => $spans,
        'label' => $label,
        'detail' => $guestWord . '. ' . $fit . '. That comes to ' . $need . '. ' . $math . ' = ' . format_money($amount) . '.',
    ];
}

/**
 * Quote one listed room or vehicle, including stock.
 *
 * @param array<string,mixed> $service
 * @return array{ok:bool,message:string,units:int,extra_guests:int,amount:float,spans:int,label:string,amount_label:string}
 */
function offering_quote_card(array $service, int $guests, int $spans, string $noun, string $start, string $end, ?int $excludeBookingId = null): array
{
    $quote = offering_party_quote($service, $guests, $spans, $noun);
    $quote['amount_label'] = format_money($quote['amount']);
    if (!$quote['ok']) {
        return $quote;
    }
    $free = offering_units_free((int) ($service['id'] ?? 0), $start, $end, $excludeBookingId);
    if ($free !== null && $quote['units'] > $free) {
        $need = (int) $quote['units'];
        $quote['ok'] = false;
        $quote['message'] = 'Need ' . $need . ' ' . offering_noun($noun, $need)
            . '. Only ' . $free . ' ' . offering_noun($noun, $free) . ' left for these dates.';
    }
    return $quote;
}

/**
 * @param array<string,mixed> $choice
 * @return array{ok:bool,message:string,units:int,extra_guests:int,amount:float,label:string}
 */
function stay_ride_side_charge(array $choice, string $kind, int $party, int $spans, string $start, string $end, ?int $excludeBookingId = null): array
{
    $noun = $kind === 'transportation' ? 'vehicle' : 'room';
    $zero = [
        'ok' => true,
        'message' => '',
        'units' => 0,
        'extra_guests' => 0,
        'amount' => 0.0,
        'label' => 'Not needed',
    ];
    if (!empty($choice[$kind . '_declined'])) {
        return $zero;
    }
    $serviceId = (int) ($choice[$kind . '_service_id'] ?? 0);
    if ($serviceId < 1) {
        $zero['label'] = 'Not listed';
        return $zero;
    }
    ensure_place_schedule_schema();
    $st = db()->prepare('SELECT * FROM business_services WHERE id=? AND is_active=1 LIMIT 1');
    $st->execute([$serviceId]);
    $service = $st->fetch();
    if (!$service) {
        return [
            'ok' => false,
            'message' => $noun === 'vehicle' ? 'Choose a vehicle from that listing.' : 'Choose a room from that listing.',
            'units' => 0,
            'extra_guests' => 0,
            'amount' => 0.0,
            'label' => '',
        ];
    }
    $bizSt = db()->prepare(
        'SELECT b.name AS business_name, ' . business_itinerary_ready_columns() . ' FROM businesses b WHERE b.id=? AND b.status=\'active\' LIMIT 1'
    );
    $bizSt->execute([(int) ($service['business_id'] ?? 0)]);
    $bizRow = $bizSt->fetch();
    $unavailable = is_array($bizRow) ? business_listing_unavailable_message($bizRow) : 'This listing isn’t available.';
    if ($unavailable !== null) {
        return [
            'ok' => false,
            'message' => $unavailable,
            'units' => 0,
            'extra_guests' => 0,
            'amount' => 0.0,
            'label' => '',
        ];
    }
    $quote = offering_quote_card($service, max(1, $party), $spans, $noun, $start, $end, $excludeBookingId);
    return [
        'ok' => (bool) $quote['ok'],
        'message' => (string) $quote['message'],
        'units' => (int) $quote['units'],
        'extra_guests' => (int) $quote['extra_guests'],
        'amount' => (float) $quote['amount'],
        'label' => (string) $quote['label'],
    ];
}

/**
 * Guide fee stays the daily rate times the tour days. Stay and ride are added from the guest count.
 *
 * @param array<string,mixed> $stay
 * @return array{ok:bool,message:string,accommodation_units:int,accommodation_extra_guests:int,accommodation_amount:float,transportation_units:int,transportation_extra_guests:int,transportation_amount:float,guide_amount:float,payment_amount:float,stay_label:string,ride_label:string,stay_spans:int,ride_spans:int}
 */
function booking_charge_for_stay(
    array $stay,
    int $party,
    string $start,
    string $end,
    float $guideDailyRate,
    ?int $itineraryId = null,
    ?int $excludeBookingId = null
): array {
    $party = max(1, min(20, $party));
    $staySpans = itinerary_day_count($start, $end);
    $rideSpans = $staySpans;
    if ($itineraryId) {
        $itin = function_exists('itinerary_row_by_id') ? itinerary_row_by_id($itineraryId) : null;
        if ($itin) {
            $rideSpans = itinerary_logical_day_count($itin);
        }
    }
    $stayLines = [];
    $stayDetail = '';
    $stayReview = '';
    $rideLines = [];
    $rideDetail = '';
    $rideReview = '';
    $dayDates = [];
    if ($itineraryId && function_exists('itinerary_day_dates_map')) {
        $dayDates = itinerary_day_dates_map((int) $itineraryId, isset($itin) && is_array($itin) ? $itin : null);
    }
    if (empty($stay['accommodation_declined']) && !empty($stay['accommodation_lines']) && is_array($stay['accommodation_lines'])) {
        $priced = stay_lines_price($stay['accommodation_lines'], $staySpans, $start, $end, $excludeBookingId, $party, 'accommodation', $dayDates);
        if (!$priced['ok']) {
            return booking_charge_fail($priced['message']);
        }
        $acc = [
            'ok' => true,
            'message' => '',
            'units' => (int) $priced['units'],
            'extra_guests' => (int) $priced['extra_guests'],
            'amount' => (float) $priced['amount'],
            'label' => (string) $priced['label'],
        ];
        $stayLines = $priced['lines'];
        $stayDetail = (string) $priced['detail'];
        $stayReview = (string) $priced['review'];
    } else {
        $acc = stay_ride_side_charge($stay, 'accommodation', $party, $staySpans, $start, $end, $excludeBookingId);
        if (!$acc['ok']) {
            return booking_charge_fail($acc['message']);
        }
        $stayReview = (string) $acc['label'];
    }
    if (empty($stay['transportation_declined']) && !empty($stay['transportation_lines']) && is_array($stay['transportation_lines'])) {
        $pricedRide = stay_lines_price($stay['transportation_lines'], $rideSpans, $start, $end, $excludeBookingId, $party, 'transportation', $dayDates);
        if (!$pricedRide['ok']) {
            return booking_charge_fail($pricedRide['message']);
        }
        $trn = [
            'ok' => true,
            'message' => '',
            'units' => (int) $pricedRide['units'],
            'extra_guests' => (int) $pricedRide['extra_guests'],
            'amount' => (float) $pricedRide['amount'],
            'label' => (string) $pricedRide['label'],
        ];
        $rideLines = $pricedRide['lines'];
        $rideDetail = (string) $pricedRide['detail'];
        $rideReview = (string) $pricedRide['review'];
    } else {
        $trn = stay_ride_side_charge($stay, 'transportation', $party, $rideSpans, $start, $end, $excludeBookingId);
        if (!$trn['ok']) {
            return booking_charge_fail($trn['message']);
        }
        $rideReview = (string) $trn['label'];
    }
    $guideAmount = round(max(0, $guideDailyRate) * max(1, $rideSpans), 2);
    return [
        'ok' => true,
        'message' => '',
        'accommodation_units' => $acc['units'],
        'accommodation_extra_guests' => $acc['extra_guests'],
        'accommodation_amount' => $acc['amount'],
        'transportation_units' => $trn['units'],
        'transportation_extra_guests' => $trn['extra_guests'],
        'transportation_amount' => $trn['amount'],
        'guide_amount' => $guideAmount,
        'payment_amount' => round($guideAmount + $acc['amount'] + $trn['amount'], 2),
        'stay_label' => $acc['label'],
        'ride_label' => $trn['label'],
        'stay_detail' => $stayDetail,
        'stay_review' => $stayReview,
        'ride_detail' => $rideDetail,
        'ride_review' => $rideReview,
        'accommodation_lines' => $stayLines,
        'transportation_lines' => $rideLines,
        'stay_spans' => $staySpans,
        'ride_spans' => $rideSpans,
    ];
}

/** @return array{ok:bool,message:string,accommodation_units:int,accommodation_extra_guests:int,accommodation_amount:float,transportation_units:int,transportation_extra_guests:int,transportation_amount:float,guide_amount:float,payment_amount:float,stay_label:string,ride_label:string,stay_spans:int,ride_spans:int} */
function booking_charge_fail(string $message): array
{
    return [
        'ok' => false,
        'message' => $message,
        'accommodation_units' => 0,
        'accommodation_extra_guests' => 0,
        'accommodation_amount' => 0.0,
        'transportation_units' => 0,
        'transportation_extra_guests' => 0,
        'transportation_amount' => 0.0,
        'guide_amount' => 0.0,
        'payment_amount' => 0.0,
        'stay_label' => '',
        'ride_label' => '',
        'stay_detail' => '',
        'stay_review' => '',
        'ride_detail' => '',
        'ride_review' => '',
        'accommodation_lines' => [],
        'transportation_lines' => [],
        'stay_spans' => 1,
        'ride_spans' => 1,
    ];
}

/**
 * Quotes for every active room and vehicle, keyed by service id.
 *
 * @return array<string,array{ok:bool,message:string,units:int,extra_guests:int,amount:float,label:string,amount_label:string}>
 */
function stay_ride_offer_quotes(int $party, string $start, string $end, ?int $itineraryId = null): array
{
    $party = max(1, min(20, $party));
    $staySpans = itinerary_day_count($start, $end);
    $rideSpans = $staySpans;
    if ($itineraryId && function_exists('itinerary_row_by_id')) {
        $itin = itinerary_row_by_id($itineraryId);
        if ($itin) {
            $rideSpans = itinerary_logical_day_count($itin);
        }
    }
    ensure_place_schedule_schema();
    $rows = db()->query(
        "SELECT s.*, bt.name AS type_name
         FROM business_services s
         JOIN businesses b ON b.id = s.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE s.is_active=1 AND b.approval_status='approved' AND b.status='active'
           AND bt.name IN ('Accommodation','Hotel','Resort','Transportation')"
    )->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $noun = (string) ($row['type_name'] ?? '') === 'Transportation' ? 'vehicle' : 'room';
        $spans = $noun === 'vehicle' ? $rideSpans : $staySpans;
        $quote = offering_quote_card($row, $party, $spans, $noun, $start, $end);
        $out[(string) (int) $row['id']] = [
            'ok' => (bool) $quote['ok'],
            'message' => (string) $quote['message'],
            'units' => (int) $quote['units'],
            'extra_guests' => (int) $quote['extra_guests'],
            'amount' => (float) $quote['amount'],
            'label' => (string) $quote['label'],
            'amount_label' => (string) $quote['amount_label'],
            'detail' => (string) ($quote['detail'] ?? ''),
        ];
    }
    return $out;
}

/** Error when the chosen room or vehicle does not have enough units for this group. */
function stay_ride_room_stock_message(
    array $choice,
    string $start,
    string $end,
    ?int $excludeBookingId = null,
    int $party = 1,
    ?int $itineraryId = null
): ?string {
    $charge = booking_charge_for_stay($choice, max(1, $party), $start, $end, 0, $itineraryId, $excludeBookingId);
    if (!$charge['ok']) {
        return $charge['message'] !== '' ? $charge['message'] : 'That room is fully booked for these dates.';
    }
    return null;
}

/**
 * @param array<string,mixed> $row
 */
function booking_insert_pending(array $row): int
{
    ensure_booking_payment_schema();
    db()->prepare(
        'INSERT INTO bookings (
            booking_code, tourist_id, guide_id, itinerary_id, destination_id, booking_date,
            tour_start_date, tour_end_date, start_time, party_size, notes,
            accommodation_business_id, accommodation_service_id, accommodation_declined,
            accommodation_units, accommodation_extra_guests, accommodation_amount, accommodation_lines,
            transportation_business_id, transportation_service_id, transportation_declined,
            transportation_units, transportation_extra_guests, transportation_amount, transportation_lines,
            guide_amount, payment_amount, status, payment_status
         ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $row['booking_code'],
        $row['tourist_id'],
        $row['guide_id'],
        $row['itinerary_id'],
        $row['destination_id'],
        $row['booking_date'],
        $row['tour_start_date'],
        $row['tour_end_date'],
        $row['start_time'],
        $row['party_size'],
        $row['notes'] ?? null,
        $row['accommodation_business_id'],
        $row['accommodation_service_id'],
        (int) ($row['accommodation_declined'] ?? 0),
        (int) ($row['accommodation_units'] ?? 0),
        (int) ($row['accommodation_extra_guests'] ?? 0),
        $row['accommodation_amount'] ?? 0,
        $row['accommodation_lines'] ?? null,
        $row['transportation_business_id'],
        $row['transportation_service_id'],
        (int) ($row['transportation_declined'] ?? 0),
        (int) ($row['transportation_units'] ?? 0),
        (int) ($row['transportation_extra_guests'] ?? 0),
        $row['transportation_amount'] ?? 0,
        $row['transportation_lines'] ?? null,
        $row['guide_amount'] ?? 0,
        $row['payment_amount'] ?? 0,
        $row['status'] ?? 'pending',
        $row['payment_status'] ?? 'unpaid',
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Decoded stay lines, or null when this booking has no mixed-room list.
 *
 * @return list<array<string,mixed>>|null
 */
function stay_lines_list($raw): ?array
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_array($raw)) {
        return array_is_list($raw) ? $raw : null;
    }
    if (!is_string($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** @return list<array<string,mixed>>|null */
function stay_lines_request(array $input, string $key = 'accommodation_lines'): ?array
{
    if (!array_key_exists($key, $input)) {
        return null;
    }
    $raw = $input[$key];
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Highest guest count for one room. Capacity is how many are included in the price.
 */
function stay_room_guest_max(?int $cap, int $extraPer): int
{
    if ($cap === null || $cap < 1) {
        return BOOKING_PARTY_MAX;
    }
    return max(1, $cap + max(0, $extraPer));
}

/**
 * One number per room. A posted guests list is the people actually staying.
 * A line without that list starts at 1 guest per room or vehicle.
 * Filling every seat and then cutting the party down assigned guests the tourist never set.
 *
 * @param array<string,mixed> $line
 * @return list<int>
 */
function stay_line_occupancy(array $line, ?int $cap, int $extraPer, bool $fillIncluded = false): array
{
    $max = stay_room_guest_max($cap, $extraPer);
    $extraPer = max(0, $extraPer);
    if (isset($line['guests']) && is_array($line['guests']) && $line['guests'] !== []) {
        $guests = [];
        foreach ($line['guests'] as $n) {
            if (count($guests) >= 20) {
                break;
            }
            $guests[] = max(1, min($max, (int) $n));
        }
        return $guests !== [] ? $guests : [1];
    }
    $units = max(1, min(20, (int) ($line['units'] ?? 1)));
    if (!$fillIncluded) {
        return array_fill(0, $units, 1);
    }
    $fill = ($cap === null || $cap < 1) ? 1 : $cap;
    $extrasLeft = max(0, (int) ($line['extra_guests'] ?? 0));
    $guests = [];
    for ($i = 0; $i < $units; $i++) {
        $add = min($extraPer, $extrasLeft);
        $extrasLeft -= $add;
        $guests[] = max(1, min($max, $fill + $add));
    }
    return $guests;
}

/**
 * People past the included capacity are the only extra guests.
 *
 * @param list<int> $guests
 */
function stay_line_extra_count(array $guests, ?int $cap, int $extraPer): int
{
    if ($cap === null || $cap < 1) {
        return 0;
    }
    $extraPer = max(0, $extraPer);
    $extras = 0;
    foreach ($guests as $guestsInRoom) {
        if ((int) $guestsInRoom > $cap) {
            $extras += min($extraPer, (int) $guestsInRoom - $cap);
        }
    }
    return $extras;
}

/**
 * Keep each room at least one guest. Lower counts before removing a room.
 *
 * @param list<list<int>> $groups
 * @return list<list<int>>
 */
function stay_occupancy_fit_party(array $groups, int $party): array
{
    $party = max(BOOKING_PARTY_MIN, min(BOOKING_PARTY_MAX, $party));
    $sum = 0;
    $count = 0;
    foreach ($groups as $guests) {
        foreach ($guests as $guestsInRoom) {
            $sum += (int) $guestsInRoom;
            $count++;
        }
    }
    $guard = 0;
    while ($sum > $party && $guard < 400) {
        $guard++;
        $cut = false;
        for ($i = count($groups) - 1; $i >= 0 && !$cut; $i--) {
            for ($j = count($groups[$i]) - 1; $j >= 0; $j--) {
                if ((int) $groups[$i][$j] > 1) {
                    $groups[$i][$j]--;
                    $sum--;
                    $cut = true;
                    break;
                }
            }
        }
        if (!$cut) {
            break;
        }
    }
    while ($count > $party && $count > 1) {
        $last = count($groups) - 1;
        if ($last < 0 || $groups[$last] === []) {
            break;
        }
        array_pop($groups[$last]);
        $count--;
        if ($groups[$last] === []) {
            array_pop($groups);
        }
    }
    return array_values(array_filter(
        $groups,
        static fn (array $guests): bool => $guests !== []
    ));
}

function stay_line_day(array $line): int
{
    $day = (int) ($line['day'] ?? 0);
    return $day > 0 ? $day : 0;
}

/**
 * @param list<array<string,mixed>> $raw
 * @return array{ok:bool,message:string,lines:list<array<string,mixed>>}
 */
function stay_lines_shape(array $raw, string $noun = 'room'): array
{
    $merged = [];
    foreach ($raw as $line) {
        if (!is_array($line)) {
            continue;
        }
        $sid = (int) ($line['service_id'] ?? 0);
        $bid = (int) ($line['business_id'] ?? 0);
        $units = (int) ($line['units'] ?? 0);
        $extras = max(0, (int) ($line['extra_guests'] ?? 0));
        $posted = (isset($line['guests']) && is_array($line['guests']) && $line['guests'] !== []) ? $line['guests'] : null;
        $day = stay_line_day($line);
        if ($sid < 1 || $bid < 1 || ($units < 1 && $posted === null)) {
            continue;
        }
        $key = $day . ':' . $sid;
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'business_id' => $bid,
                'service_id' => $sid,
                'day' => $day,
                'units' => 0,
                'extra_guests' => 0,
                'guests' => [],
                'has_guests' => false,
            ];
        }
        if ($posted !== null) {
            $merged[$key]['has_guests'] = true;
            foreach ($posted as $n) {
                if (count($merged[$key]['guests']) >= 20) {
                    break;
                }
                $merged[$key]['guests'][] = max(1, (int) $n);
            }
        } else {
            $merged[$key]['units'] += max(1, $units);
            $merged[$key]['extra_guests'] += $extras;
        }
    }
    $lines = [];
    $perDay = [];
    foreach ($merged as $line) {
        $guestCount = $line['has_guests'] ? count($line['guests']) : 0;
        $units = $guestCount + (int) $line['units'];
        if ($units < 1) {
            continue;
        }
        if ($units > 20) {
            return ['ok' => false, 'message' => 'Choose up to 20 ' . offering_noun($noun, 20) . ' of one type.', 'lines' => []];
        }
        $dayKey = (int) $line['day'];
        $perDay[$dayKey] = ($perDay[$dayKey] ?? 0) + 1;
        if ($perDay[$dayKey] > 12) {
            $scope = $dayKey > 0 ? ' for one day' : '';
            return ['ok' => false, 'message' => 'Choose up to 12 ' . $noun . ' types' . $scope . '.', 'lines' => []];
        }
        $item = [
            'business_id' => (int) $line['business_id'],
            'service_id' => (int) $line['service_id'],
            'units' => $units,
            'extra_guests' => (int) $line['extra_guests'],
        ];
        if ($dayKey > 0) {
            $item['day'] = $dayKey;
        }
        if ($line['has_guests']) {
            $item['guests'] = $line['guests'];
            $item['legacy_units'] = (int) $line['units'];
        }
        $lines[] = $item;
    }
    return ['ok' => true, 'message' => '', 'lines' => $lines];
}

/**
 * @return array{ok:bool,message:string,units:int,extra_guests:int,amount:float,label:string,detail:string,review:string,lines:list<array<string,mixed>>}
 */
function stay_lines_fail(string $message): array
{
    return [
        'ok' => false,
        'message' => $message,
        'units' => 0,
        'extra_guests' => 0,
        'amount' => 0.0,
        'label' => '',
        'detail' => '',
        'review' => '',
        'lines' => [],
    ];
}

/**
 * Price the rooms the tourist picked. Amounts are computed here.
 *
 * Each room starts from the guests placed in it. Capacity is included in the room price.
 * Only people past that capacity are extra guests, and the party is never exceeded.
 *
 * @param list<array<string,mixed>> $lines
 * @return array{ok:bool,message:string,units:int,extra_guests:int,amount:float,label:string,detail:string,review:string,lines:list<array<string,mixed>>}
 */
function stay_lines_price(array $lines, int $spans, string $start, string $end, ?int $excludeBookingId = null, int $party = 1, string $kind = 'accommodation', ?array $dayDates = null): array
{
    $kind = $kind === 'transportation' ? 'transportation' : 'accommodation';
    $noun = $kind === 'transportation' ? 'vehicle' : 'room';
    $spanWord = $kind === 'transportation' ? 'day' : 'night';
    $spans = max(1, $spans);
    $shaped = stay_lines_shape($lines, $noun);
    if (!$shaped['ok']) {
        return stay_lines_fail($shaped['message']);
    }
    if (!$shaped['lines']) {
        return stay_lines_fail($kind === 'transportation'
            ? 'Add a vehicle, or mark transportation as not needed.'
            : 'Add a room, or mark accommodation as not needed.');
    }
    $ids = array_map(static fn (array $line): int => (int) $line['service_id'], $shaped['lines']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    ensure_place_schedule_schema();
    $st = db()->prepare(
        "SELECT s.*, b.name AS business_name, bt.name AS type_name, " . business_itinerary_ready_columns() . "
         FROM business_services s
         JOIN businesses b ON b.id = s.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE s.id IN ($placeholders) AND s.is_active=1
           AND b.approval_status='approved' AND b.status='active'"
    );
    $st->execute($ids);
    $byId = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $byId[(int) $row['id']] = $row;
    }
    $allowed = stay_ride_type_names($kind);
    $party = max(1, min(20, $party));
    $pending = [];
    foreach ($shaped['lines'] as $line) {
        $svc = $byId[(int) $line['service_id']] ?? null;
        if (
            !$svc
            || (int) $svc['business_id'] !== (int) $line['business_id']
            || !in_array((string) ($svc['type_name'] ?? ''), $allowed, true)
        ) {
            return stay_lines_fail($kind === 'transportation'
                ? 'Choose a vehicle from an approved ride.'
                : 'Choose a room from an approved stay.');
        }
        $unavailable = business_listing_unavailable_message($svc);
        if ($unavailable !== null) {
            return stay_lines_fail($unavailable);
        }
        $price = round((float) ($svc['price'] ?? 0), 2);
        $capRaw = $svc['capacity'] ?? null;
        $cap = ($capRaw === null || $capRaw === '' || (int) $capRaw < 1) ? null : (int) $capRaw;
        $feeRaw = $svc['extra_guest_fee'] ?? null;
        $fee = ($feeRaw === null || $feeRaw === '' || (float) $feeRaw <= 0) ? 0.0 : round((float) $feeRaw, 2);
        $limitRaw = $svc['extra_guest_limit'] ?? null;
        $limit = ($limitRaw === null || $limitRaw === '') ? null : max(0, (int) $limitRaw);
        $extraPer = $fee > 0 ? ($limit !== null ? $limit : (int) ($cap ?? 0)) : 0;
        if ($kind === 'transportation') {
            $fee = 0.0;
            $extraPer = 0;
            if ($cap === null) {
                $cap = 1;
            }
        }
        $roomName = trim((string) ($svc['name'] ?? ''));
        if ($roomName === '') {
            $roomName = $noun === 'vehicle' ? 'Vehicle' : 'Room';
        }
        $guests = stay_line_occupancy($line, $cap, $extraPer);
        if (!empty($line['guests']) && !empty($line['legacy_units'])) {
            $legacy = stay_line_occupancy([
                'units' => (int) $line['legacy_units'],
                'extra_guests' => (int) ($line['extra_guests'] ?? 0),
            ], $cap, $extraPer);
            $guests = array_slice(array_merge($guests, $legacy), 0, 20);
        }
        $label = trim((string) ($svc['business_name'] ?? ''));
        $label = $label !== '' ? $label . ' · ' . $roomName : $roomName;
        $pending[] = [
            'business_id' => (int) $line['business_id'],
            'service_id' => (int) $line['service_id'],
            'day' => stay_line_day($line),
            'guests' => $guests,
            'capacity' => $cap,
            'extra_per' => $extraPer,
            'price' => $price,
            'fee' => $fee,
            'label' => $label,
            'service_key' => (int) $svc['id'],
        ];
    }
    $byDay = [];
    foreach ($pending as $i => $row) {
        $byDay[(int) $row['day']][] = $i;
    }
    foreach ($byDay as $indexes) {
        $groups = [];
        foreach ($indexes as $i) {
            $groups[] = $pending[$i]['guests'];
        }
        $fitted = stay_occupancy_fit_party($groups, $party);
        foreach ($indexes as $n => $i) {
            if (!isset($fitted[$n])) {
                $pending[$i]['drop'] = true;
                continue;
            }
            $pending[$i]['guests'] = $fitted[$n];
        }
    }
    $pending = array_values(array_filter(
        $pending,
        static fn (array $row): bool => empty($row['drop'])
    ));
    foreach ($pending as $i => $row) {
        $day = (int) $row['day'];
        $lineSpans = $spans;
        $stockStart = $start;
        $stockEnd = $end;
        $date = '';
        if ($day < 1 && is_array($dayDates) && count($dayDates) > 1) {
            $day = 1;
        }
        if ($day > 0) {
            $lineSpans = 1;
            if (is_array($dayDates) && !empty($dayDates[$day])) {
                $date = normalize_tour_date((string) $dayDates[$day]) ?: '';
            }
            if ($date !== '') {
                $stockStart = $date;
                $stockEnd = $date;
            }
        }
        $pending[$i]['day'] = $day;
        $pending[$i]['spans'] = $lineSpans;
        $pending[$i]['date'] = $date;
        $units = count($row['guests']);
        $free = offering_units_free((int) $row['service_key'], $stockStart, $stockEnd, $excludeBookingId);
        if ($free !== null && $units > $free) {
            $when = $day > 0 ? ' on day ' . $day : ' for these dates';
            return stay_lines_fail(
                'Need ' . $units . ' ' . offering_noun($noun, $units)
                . '. Only ' . $free . ' ' . offering_noun($noun, $free) . ' left' . $when . '.'
            );
        }
    }
    $out = [];
    $sumUnits = 0;
    $sumExtras = 0;
    $sumAmount = 0.0;
    $details = [];
    $reviews = [];
    foreach ($pending as $row) {
        $guests = array_values(array_map('intval', $row['guests']));
        $units = count($guests);
        $extras = stay_line_extra_count($guests, $row['capacity'], (int) $row['extra_per']);
        $price = (float) $row['price'];
        $fee = (float) $row['fee'];
        $lineSpans = max(1, (int) ($row['spans'] ?? $spans));
        $amount = round(($units * $price + $extras * $fee) * $lineSpans, 2);
        $math = $units . ' × ' . format_money($price);
        if ($extras > 0 && $fee > 0) {
            $math .= ' + ' . $extras . ' × ' . format_money($fee);
        }
        if ($lineSpans > 1) {
            $math .= ' × ' . $lineSpans . ' ' . $spanWord . 's';
        }
        $math .= ' = ' . format_money($amount);
        $day = (int) ($row['day'] ?? 0);
        $label = (string) $row['label'];
        if ($day > 0) {
            $label = 'Day ' . $day . ' · ' . $label;
        }
        $saved = [
            'business_id' => (int) $row['business_id'],
            'service_id' => (int) $row['service_id'],
            'units' => $units,
            'extra_guests' => $extras,
            'guests' => $guests,
            'amount' => $amount,
            'label' => $label,
        ];
        if ($day > 0) {
            $saved['day'] = $day;
            $dated = (string) ($row['date'] ?? '');
            if ($dated !== '') {
                $saved['date'] = $dated;
            }
        }
        $out[] = $saved;
        $sumUnits += $units;
        $sumExtras += $extras;
        $sumAmount += $amount;
        $details[] = $label . ': ' . $math . '.';
        $reviews[] = $label;
    }
    $short = $sumUnits . ' ' . offering_noun($noun, $sumUnits);
    if ($sumExtras > 0) {
        $short .= ' · ' . $sumExtras . ' extra guest' . ($sumExtras === 1 ? '' : 's');
    }
    return [
        'ok' => true,
        'message' => '',
        'units' => $sumUnits,
        'extra_guests' => $sumExtras,
        'amount' => round($sumAmount, 2),
        'label' => $short,
        'detail' => implode(' ', $details),
        'review' => implode(', ', $reviews),
        'lines' => $out,
    ];
}

/**
 * Accommodation listings are places to stay. Older Hotel and Resort names still count.
 * Transportation is its own type.
 *
 * @return list<string>
 */
function stay_ride_type_names(string $kind): array
{
    return $kind === 'transportation' ? ['Transportation'] : ['Accommodation', 'Hotel', 'Resort'];
}

/**
 * One side of the stay-and-ride step: a listed business, or an explicit decline.
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string,business_id:?int,service_id:?int,declined:int}
 */
function stay_ride_parse_side(array $input, string $kind): array
{
    $declined = (int) ($input[$kind . '_declined'] ?? 0) === 1;
    $id = (int) ($input[$kind . '_business_id'] ?? 0);
    $serviceId = (int) ($input[$kind . '_service_id'] ?? 0);
    $label = $kind === 'transportation' ? 'transportation' : 'accommodation';
    $offerLabel = $kind === 'transportation' ? 'vehicle' : 'room';
    $fail = static function (string $message) : array {
        return ['ok' => false, 'message' => $message, 'business_id' => null, 'service_id' => null, 'declined' => 0];
    };
    if ($declined) {
        return ['ok' => true, 'message' => '', 'business_id' => null, 'service_id' => null, 'declined' => 1];
    }
    if ($id < 1) {
        return $fail('Choose ' . $label . ' or mark it as not needed.');
    }
    $names = stay_ride_type_names($kind);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $st = db()->prepare(
        "SELECT b.id, b.name AS business_name, " . business_itinerary_ready_columns() . "
         FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.id=? AND b.approval_status='approved' AND b.status='active'
           AND bt.name IN ($placeholders)
         LIMIT 1"
    );
    $st->execute(array_merge([$id], $names));
    $bizRow = $st->fetch();
    if (!$bizRow) {
        return $fail('Choose an approved ' . $label . ' listing.');
    }
    $unavailable = business_listing_unavailable_message($bizRow);
    if ($unavailable !== null) {
        return $fail($unavailable);
    }
    ensure_place_schedule_schema();
    $offers = db()->prepare('SELECT id FROM business_services WHERE business_id=? AND is_active=1');
    $offers->execute([$id]);
    $offerIds = array_map('intval', $offers->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($offerIds && !in_array($serviceId, $offerIds, true)) {
        return $fail('Choose a ' . $offerLabel . ' from that listing.');
    }
    if (!$offerIds) {
        $serviceId = 0;
    }
    return [
        'ok' => true,
        'message' => '',
        'business_id' => $id,
        'service_id' => $serviceId > 0 ? $serviceId : null,
        'declined' => 0,
    ];
}

/**
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string,accommodation_business_id:?int,accommodation_declined:int,transportation_business_id:?int,transportation_declined:int}
 */
function stay_ride_parse(array $input): array
{
    $empty = [
        'ok' => false,
        'message' => 'Choose accommodation and transportation before booking.',
        'accommodation_business_id' => null,
        'accommodation_service_id' => null,
        'accommodation_declined' => 0,
        'transportation_business_id' => null,
        'transportation_service_id' => null,
        'transportation_declined' => 0,
        'accommodation_lines' => [],
        'transportation_lines' => [],
    ];
    $lineReq = stay_lines_request($input);
    $declined = (int) ($input['accommodation_declined'] ?? 0) === 1;
    if ($declined) {
        $acc = stay_ride_parse_side($input, 'accommodation');
        $lines = [];
    } elseif ($lineReq !== null) {
        $shaped = stay_lines_shape($lineReq);
        if (!$shaped['ok'] || !$shaped['lines']) {
            $empty['message'] = $shaped['message'] !== '' ? $shaped['message'] : 'Add a room, or mark accommodation as not needed.';
            return $empty;
        }
        $first = $shaped['lines'][0];
        $acc = [
            'ok' => true,
            'message' => '',
            'business_id' => (int) $first['business_id'],
            'service_id' => (int) $first['service_id'],
            'declined' => 0,
        ];
        $lines = $shaped['lines'];
    } else {
        $acc = stay_ride_parse_side($input, 'accommodation');
        $lines = [];
    }
    if (!$acc['ok']) {
        $empty['message'] = $acc['message'];
        return $empty;
    }
    $trnReq = stay_lines_request($input, 'transportation_lines');
    $trnDeclined = (int) ($input['transportation_declined'] ?? 0) === 1;
    if ($trnDeclined) {
        $trn = stay_ride_parse_side($input, 'transportation');
        $trnLines = [];
    } elseif (is_array($trnReq) && $trnReq !== []) {
        $trnShaped = stay_lines_shape($trnReq, 'vehicle');
        if (!$trnShaped['ok'] || !$trnShaped['lines']) {
            $empty['message'] = $trnShaped['message'] !== '' ? $trnShaped['message'] : 'Add a vehicle, or mark transportation as not needed.';
            return $empty;
        }
        $trnFirst = $trnShaped['lines'][0];
        $trn = [
            'ok' => true,
            'message' => '',
            'business_id' => (int) $trnFirst['business_id'],
            'service_id' => (int) $trnFirst['service_id'],
            'declined' => 0,
        ];
        $trnLines = $trnShaped['lines'];
    } else {
        $trn = stay_ride_parse_side($input, 'transportation');
        $trnLines = [];
    }
    if (!$trn['ok']) {
        $empty['message'] = $trn['message'];
        return $empty;
    }
    return [
        'ok' => true,
        'message' => '',
        'accommodation_business_id' => $acc['business_id'],
        'accommodation_service_id' => $acc['service_id'],
        'accommodation_declined' => $acc['declined'],
        'accommodation_lines' => $lines,
        'transportation_business_id' => $trn['business_id'],
        'transportation_service_id' => $trn['service_id'],
        'transportation_declined' => $trn['declined'],
        'transportation_lines' => $trnLines,
    ];
}

/**
 * A saved itinerary or booking row is complete when each side is declined or has a business.
 *
 * @param array<string,mixed> $row
 * @return array{ok:bool,message:string,accommodation_business_id:?int,accommodation_declined:int,transportation_business_id:?int,transportation_declined:int}|null
 */
function stay_ride_from_row(array $row): ?array
{
    $accId = (int) ($row['accommodation_business_id'] ?? 0);
    $accDec = (int) ($row['accommodation_declined'] ?? 0) === 1;
    $trnId = (int) ($row['transportation_business_id'] ?? 0);
    $trnDec = (int) ($row['transportation_declined'] ?? 0) === 1;
    if ((!$accDec && $accId < 1) || (!$trnDec && $trnId < 1)) {
        return null;
    }
    $payload = [
        'accommodation_business_id' => $accDec ? 0 : $accId,
        'accommodation_service_id' => $accDec ? 0 : (int) ($row['accommodation_service_id'] ?? 0),
        'accommodation_declined' => $accDec ? 1 : 0,
        'transportation_business_id' => $trnDec ? 0 : $trnId,
        'transportation_service_id' => $trnDec ? 0 : (int) ($row['transportation_service_id'] ?? 0),
        'transportation_declined' => $trnDec ? 1 : 0,
    ];
    $savedLines = stay_lines_list($row['accommodation_lines'] ?? null);
    if (!$accDec && is_array($savedLines) && $savedLines !== []) {
        $payload['accommodation_lines'] = $savedLines;
    }
    $savedRideLines = stay_lines_list($row['transportation_lines'] ?? null);
    if (!$trnDec && is_array($savedRideLines) && $savedRideLines !== []) {
        $payload['transportation_lines'] = $savedRideLines;
    }
    return stay_ride_parse($payload);
}

/**
 * @param array{accommodation_business_id:?int,accommodation_declined:int,transportation_business_id:?int,transportation_declined:int} $choice
 */
function stay_ride_save_itinerary(int $itinId, int $userId, array $choice): void
{
    ensure_itinerary_items_schema();
    $lines = $choice['accommodation_lines'] ?? [];
    $linesJson = (is_array($lines) && $lines !== [])
        ? json_encode(array_values($lines), JSON_UNESCAPED_UNICODE)
        : null;
    $rideLines = $choice['transportation_lines'] ?? [];
    $rideJson = (is_array($rideLines) && $rideLines !== [])
        ? json_encode(array_values($rideLines), JSON_UNESCAPED_UNICODE)
        : null;
    db()->prepare(
        'UPDATE itineraries
         SET accommodation_business_id=?, accommodation_service_id=?, accommodation_declined=?,
             accommodation_lines=?,
             transportation_business_id=?, transportation_service_id=?, transportation_declined=?,
             transportation_lines=?
         WHERE id=? AND user_id=?'
    )->execute([
        $choice['accommodation_business_id'],
        $choice['accommodation_service_id'],
        (int) $choice['accommodation_declined'],
        $linesJson,
        $choice['transportation_business_id'],
        $choice['transportation_service_id'],
        (int) $choice['transportation_declined'],
        $rideJson,
        $itinId,
        $userId,
    ]);
}

/** Display label for one saved side. Uses a joined *_name column when present. */
function stay_ride_label(array $row, string $kind): string
{
    if ((int) ($row[$kind . '_declined'] ?? 0) === 1) {
        return 'Not needed';
    }
    $lineColumn = $kind === 'transportation' ? 'transportation_lines' : 'accommodation_lines';
    if ($kind === 'accommodation' || $kind === 'transportation') {
        $savedLines = stay_lines_list($row[$lineColumn] ?? null);
        if (is_array($savedLines) && $savedLines !== []) {
            $parts = [];
            foreach ($savedLines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $label = trim((string) ($line['label'] ?? ''));
                if ($label !== '') {
                    $parts[] = $label;
                }
            }
            if ($parts) {
                return implode(', ', $parts);
            }
        }
    }
    $id = (int) ($row[$kind . '_business_id'] ?? 0);
    if ($id < 1) {
        return 'Not chosen';
    }
    $name = trim((string) ($row[$kind . '_name'] ?? ''));
    if ($name === '') {
        $st = db()->prepare('SELECT name FROM businesses WHERE id=?');
        $st->execute([$id]);
        $name = trim((string) ($st->fetchColumn() ?: ''));
    }
    $serviceId = (int) ($row[$kind . '_service_id'] ?? 0);
    if ($serviceId > 0) {
        $svc = db()->prepare('SELECT name FROM business_services WHERE id=?');
        $svc->execute([$serviceId]);
        $offer = trim((string) ($svc->fetchColumn() ?: ''));
        if ($offer !== '') {
            $name = ($name !== '' ? $name . ' · ' : '') . $offer;
        }
    }
    return $name !== '' ? $name : 'Not chosen';
}

/**
 * Validate a tourist booking request.
 *
 * @return array{ok:bool,message:?string,date:?string,time:?string,party:?int,itinerary_id:?int,destination_id:?int,guide:?array}
 */
function validate_booking_request(
    int $touristId,
    int $guideProfileId,
    string $dateRaw,
    string $timeRaw,
    $partyRaw,
    $itineraryIdRaw,
    $destinationIdRaw
): array {
    $fail = static function (string $msg): array {
        return [
            'ok' => false,
            'message' => $msg,
            'date' => null,
            'time' => null,
            'party' => null,
            'itinerary_id' => null,
            'destination_id' => null,
            'guide' => null,
        ];
    };

    // Party size first: required, no silent coerce from empty
    if ($partyRaw === null || $partyRaw === '' || !is_numeric($partyRaw)) {
        return $fail('Enter the number of guests (1–20).');
    }
    $party = (int) $partyRaw;
    if ($party < BOOKING_PARTY_MIN || $party > BOOKING_PARTY_MAX) {
        return $fail('Number of guests must be between ' . BOOKING_PARTY_MIN . ' and ' . BOOKING_PARTY_MAX . '.');
    }

    $guideCheck = db()->prepare(
        "SELECT g.*, u.status AS user_status, u.id AS guide_user_id, u.avatar
         FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id WHERE g.id=?"
    );
    $guideCheck->execute([$guideProfileId]);
    $guide = $guideCheck->fetch();
    if (
        !$guide
        || ($guide['approval_status'] ?? '') !== 'approved'
        || ($guide['user_status'] ?? '') !== 'active'
        || !guide_profile_is_complete($guide)
    ) {
        return $fail('This guide is not available for booking yet.');
    }
    if (booking_is_own_guide($touristId, $guideProfileId)) {
        return $fail('You cannot book your own tour guide account.');
    }

    $itineraryId = (int) ($itineraryIdRaw ?? 0);
    if ($itineraryId < 1) {
        $itineraryId = null;
    } else {
        $itin = db()->prepare(
            'SELECT id, start_date, meetup_time FROM itineraries WHERE id=? AND user_id=? AND is_suggested=0 LIMIT 1'
        );
        $itin->execute([$itineraryId, $touristId]);
        $itinRow = $itin->fetch();
        if (!$itinRow) {
            return $fail('Choose one of your own itineraries, or leave itinerary blank.');
        }
        $stopCount = db()->prepare('SELECT COUNT(*) FROM itinerary_items WHERE itinerary_id=?');
        $stopCount->execute([$itineraryId]);
        if ((int) $stopCount->fetchColumn() < 1) {
            return $fail('Add at least one stop to this tour before booking a guide.');
        }
        // Fill date/time from itinerary when missing
        if (trim($dateRaw) === '' && !empty($itinRow['start_date'])) {
            $dateRaw = (string) $itinRow['start_date'];
        }
        if (trim($timeRaw) === '' && !empty($itinRow['meetup_time'])) {
            $timeRaw = format_time_12h((string) $itinRow['meetup_time']) ?: '9:00 AM';
        }
    }

    $destinationId = (int) ($destinationIdRaw ?? 0);
    if ($destinationId < 1) {
        $destinationId = null;
    } else {
        $dest = db()->prepare("SELECT id FROM destinations WHERE id=? AND status='published' LIMIT 1");
        $dest->execute([$destinationId]);
        if (!$dest->fetch()) {
            return $fail('Selected destination is not available.');
        }
    }

    $date = normalize_tour_date($dateRaw);
    if ($date === null) {
        if ($itineraryId) {
            $date = app_today();
        } else {
            return $fail('Enter a valid booking date.');
        }
    }
    $today = app_today();
    if ($date < $today) {
        return $fail('Tour date cannot be in the past. Choose today or a future date.');
    }
    if ($itineraryId) {
        foreach (itinerary_visit_dates_list($itineraryId) as $visitDate) {
            if ($visitDate < $today) {
                return $fail('Tour date cannot be in the past. Choose today or a future date.');
            }
        }
    }

    // Block another booking on a date the tourist already reserved
    $dateConflict = tourist_booking_date_conflict_message($touristId, $date, $itineraryId);
    if ($dateConflict !== null) {
        return $fail($dateConflict);
    }

    $timeNorm = normalize_time_input($timeRaw);
    if ($timeNorm === null) {
        return $fail('Enter a valid start time (e.g. 9:00 AM).');
    }

    // Same-day: start / meetup time must not be in the past
    $pastMsg = tour_datetime_past_message($date, $timeNorm, 'Start time');
    if ($pastMsg !== null) {
        return $fail($pastMsg);
    }

    $availMsg = guide_booking_availability_message(
        $guideProfileId,
        $date,
        $timeNorm,
        null,
        $itineraryId
    );
    if ($availMsg !== null) {
        return $fail($availMsg);
    }

    return [
        'ok' => true,
        'message' => null,
        'date' => $date,
        'time' => $timeNorm,
        'party' => $party,
        'itinerary_id' => $itineraryId,
        'destination_id' => $destinationId,
        'guide' => $guide,
    ];
}

/**
 * Optimistic status transition. Returns true if exactly one row updated.
 */
function assert_booking_transition(int $bookingId, string $fromStatus, string $toStatus, ?string $rejectionReason = null): bool
{
    ensure_booking_payment_schema();
    if ($toStatus === 'rejected') {
        $stmt = db()->prepare(
            'UPDATE bookings SET status=?, rejection_reason=? WHERE id=? AND status=?'
        );
        $stmt->execute([$toStatus, $rejectionReason, $bookingId, $fromStatus]);
    } else {
        $stmt = db()->prepare('UPDATE bookings SET status=? WHERE id=? AND status=?');
        $stmt->execute([$toStatus, $bookingId, $fromStatus]);
    }
    return $stmt->rowCount() > 0;
}

/**
 * Resolve decline reason from preset + optional detail. Empty = invalid.
 * @return array{ok:bool,reason:?string,message:?string}
 */
function resolve_booking_decline_reason(string $preset, string $detail = ''): array
{
    $preset = trim($preset);
    $detail = trim($detail);
    $allowed = booking_decline_reason_presets();
    if ($preset === '' || !in_array($preset, $allowed, true)) {
        return ['ok' => false, 'reason' => null, 'message' => 'Choose a decline reason.'];
    }
    if ($preset === 'Other') {
        if ($detail === '') {
            return ['ok' => false, 'reason' => null, 'message' => 'Please add a short decline detail.'];
        }
        $reason = function_exists('mb_substr') ? mb_substr($detail, 0, 240) : substr($detail, 0, 240);
        return ['ok' => true, 'reason' => $reason, 'message' => null];
    }
    if ($detail !== '') {
        $combined = $preset . ': ' . $detail;
        $reason = function_exists('mb_substr') ? mb_substr($combined, 0, 240) : substr($combined, 0, 240);
        return ['ok' => true, 'reason' => $reason, 'message' => null];
    }
    return ['ok' => true, 'reason' => $preset, 'message' => null];
}

/**
 * Approve a pending booking: re-check availability (excluding self), lock status, QR + notify.
 * Payment does not approve the tour. The guide still has to accept it.
 * @return array{ok:bool,message:string}
 */
function booking_guide_approve(array $booking, array $guideUser): array
{
    $pay = (string) ($booking['payment_status'] ?? '');
    if (!in_array($pay, ['paid', 'pay_at_office'], true)) {
        return ['ok' => false, 'message' => 'Wait until this booking is paid before accepting it.'];
    }
    $id = (int) $booking['id'];
    $gid = (int) $booking['guide_id'];
    $date = (string) $booking['booking_date'];
    $time = (string) ($booking['start_time'] ?? '09:00');

    $availMsg = guide_booking_availability_message(
        $gid,
        $date,
        $time,
        $id,
        !empty($booking['itinerary_id']) ? (int) $booking['itinerary_id'] : null,
        normalize_tour_date($booking['tour_end_date'] ?? null)
    );
    if ($availMsg !== null) {
        return ['ok' => false, 'message' => $availMsg];
    }

    if (!assert_booking_transition($id, 'pending', 'approved')) {
        return ['ok' => false, 'message' => 'This booking is no longer pending.'];
    }

    notify_user(
        (int) $booking['tourist_id'],
        'booking_approved',
        'Booking approved',
        'Guide approved ' . $booking['booking_code'] . '. Your ticket and QR are ready.',
        APP_URL . '/tourist/bookings.php'
    );

    $tourist = db()->prepare('SELECT * FROM users WHERE id=?');
    $tourist->execute([(int) $booking['tourist_id']]);
    $tourist = $tourist->fetch() ?: null;

    $destination = null;
    if (!empty($booking['destination_id'])) {
        $d = db()->prepare('SELECT id, name FROM destinations WHERE id=?');
        $d->execute([(int) $booking['destination_id']]);
        $destination = $d->fetch() ?: null;
    }
    $itinerary = null;
    if (!empty($booking['itinerary_id'])) {
        $i = db()->prepare('SELECT id, title FROM itineraries WHERE id=?');
        $i->execute([(int) $booking['itinerary_id']]);
        $itinerary = $i->fetch() ?: null;
    }

    $booking['status'] = 'approved';
    require_once __DIR__ . '/qr.php';
    booking_qr_for($booking, $tourist, $guideUser, $destination, $itinerary);

    return ['ok' => true, 'message' => 'Booking approved. Ticket and QR are ready for the tourist.'];
}

/**
 * Decline a pending booking with a required reason.
 * @return array{ok:bool,message:string}
 */
function booking_guide_decline(array $booking, string $preset, string $detail = ''): array
{
    $resolved = resolve_booking_decline_reason($preset, $detail);
    if (!$resolved['ok']) {
        return ['ok' => false, 'message' => (string) $resolved['message']];
    }
    $id = (int) $booking['id'];
    $wasPaid = ($booking['payment_status'] ?? '') === 'paid';
    if (!assert_booking_transition($id, 'pending', 'rejected', $resolved['reason'])) {
        return ['ok' => false, 'message' => 'This booking is no longer pending.'];
    }
    $refundNote = $wasPaid ? ' Enter your GCash number or QR so the Tourism Office can refund your payment.' : '';
    notify_user(
        (int) $booking['tourist_id'],
        'booking_rejected',
        'Booking declined',
        'Guide declined ' . $booking['booking_code'] . ': ' . $resolved['reason'] . '.' . $refundNote,
        APP_URL . '/tourist/bookings.php'
    );
    if ($wasPaid) {
        refund_open_awaiting_details($booking, (float) ($booking['payment_amount'] ?? 0), 'The guide declined this booking.');
        return ['ok' => true, 'message' => 'Booking declined. The Tourism Office will refund the tourist.'];
    }
    return ['ok' => true, 'message' => 'Booking declined.'];
}

/**
 * Complete an approved booking (once). Notifies tourist.
 * @return array{ok:bool,message:string}
 */
function booking_guide_complete(array $booking, int $guideProfileId, bool $auto = false): array
{
    $id = (int) $booking['id'];
    if (!assert_booking_transition($id, 'approved', 'completed')) {
        return ['ok' => false, 'message' => 'Only approved bookings can be completed.'];
    }
    db()->prepare('UPDATE tour_guide_profiles SET total_tours = total_tours + 1 WHERE id=?')
        ->execute([$guideProfileId]);
    $itinId = (int) ($booking['itinerary_id'] ?? 0);
    if ($itinId < 1) {
        $look = db()->prepare('SELECT itinerary_id FROM bookings WHERE id=? LIMIT 1');
        $look->execute([$id]);
        $itinId = (int) $look->fetchColumn();
    }
    if ($itinId > 0) {
        try {
            db()->prepare("UPDATE itineraries SET status='completed' WHERE id=? AND status IN ('draft','active')")
                ->execute([$itinId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    notify_user(
        (int) $booking['tourist_id'],
        'approval',
        'Tour completed',
        $auto
            ? ('Your tour ' . $booking['booking_code'] . ' was completed after the tour date ended. You can rate your guide.')
            : ('Your tour ' . $booking['booking_code'] . ' was marked completed. You can rate your guide.'),
        APP_URL . '/tourist/bookings.php'
    );
    return ['ok' => true, 'message' => $auto ? 'Tour auto-completed.' : 'Tour marked completed.'];
}

/** @return list<string> */
function booking_ended_dates(int $bookingId): array
{
    ensure_booking_stop_checkin_schema();
    if ($bookingId < 1) {
        return [];
    }
    try {
        $st = db()->prepare('SELECT visit_date FROM booking_day_ends WHERE booking_id=? ORDER BY visit_date');
        $st->execute([$bookingId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $d) {
            $n = normalize_tour_date((string) $d);
            if ($n !== null) {
                $out[] = $n;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function booking_day_is_ended(int $bookingId, string $date): bool
{
    $date = normalize_tour_date($date);
    return $date !== null && in_array($date, booking_ended_dates($bookingId), true);
}

/**
 * Clock minutes when the guide pressed End tour on this calendar day, or null.
 */
function booking_day_ended_mins(int $bookingId, string $date): ?int
{
    ensure_booking_stop_checkin_schema();
    $date = normalize_tour_date($date);
    if ($bookingId < 1 || $date === null) {
        return null;
    }
    try {
        $st = db()->prepare(
            'SELECT ended_at FROM booking_day_ends WHERE booking_id=? AND visit_date=? LIMIT 1'
        );
        $st->execute([$bookingId, $date]);
        $raw = $st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
}

/**
 * Calendar tone for a whole tour: upcoming, ongoing, or completed.
 */
function booking_guide_schedule_tone(array $booking, int $guideProfileId): string
{
    $status = (string) ($booking['status'] ?? '');
    if ($status === 'pending') {
        return 'pending';
    }
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $dates = booking_visit_dates($booking);
    $covered = [];
    foreach ($dates as $date) {
        if ($guideProfileId < 1 || booking_guide_covers_date($booking, $guideProfileId, $date)) {
            $covered[] = $date;
        }
    }
    if (!$covered) {
        $covered = $dates;
    }
    $ended = array_flip(booking_ended_dates((int) ($booking['id'] ?? 0)));
    $endedCount = 0;
    $started = false;
    foreach ($covered as $date) {
        if ($status === 'completed' || isset($ended[$date])) {
            $endedCount++;
        }
        if ($date <= $today) {
            $started = true;
        }
    }
    if ($status === 'completed' || ($covered !== [] && $endedCount === count($covered))) {
        return 'completed';
    }
    if ($started || $endedCount > 0) {
        return 'ongoing';
    }
    return 'upcoming';
}

function booking_guide_schedule_tone_label(string $tone): string
{
    return match ($tone) {
        'upcoming' => 'Upcoming',
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
        'pending' => 'Pending',
        default => ucfirst($tone),
    };
}

/**
 * Dates the guide can still hand off. Days already ended are not included.
 *
 * @return list<string>
 */
function booking_releasable_visit_dates(array $booking): array
{
    $ended = booking_ended_dates((int) ($booking['id'] ?? 0));
    $out = [];
    foreach (booking_remaining_visit_dates($booking) as $d) {
        if (!in_array($d, $ended, true)) {
            $out[] = $d;
        }
    }
    return $out;
}

/**
 * End the check-in day only. The booking stays approved while later days remain.
 *
 * @return array{ok:bool,message:string,completed?:bool}
 */
function booking_guide_end_day(array $booking, int $guideProfileId, ?string $date = null): array
{
    ensure_booking_stop_checkin_schema();
    $id = (int) ($booking['id'] ?? 0);
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $date = normalize_tour_date($date) ?: $today;
    if ($id < 1 || ($booking['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'message' => 'Only an approved tour day can be ended.'];
    }
    if ((int) ($booking['guide_id'] ?? 0) !== $guideProfileId || !booking_guide_covers_date($booking, $guideProfileId, $date)) {
        return ['ok' => false, 'message' => 'You can only end a day on your own tour.'];
    }
    if (!in_array($date, booking_visit_dates($booking), true)) {
        return ['ok' => false, 'message' => 'That date is not part of this tour.'];
    }
    if (booking_day_is_ended($id, $date)) {
        return ['ok' => true, 'message' => 'This day is already ended. Later days stay scheduled.', 'completed' => false];
    }

    db()->prepare(
        'INSERT INTO booking_day_ends (booking_id, visit_date, ended_at) VALUES (?,?,NOW())'
    )->execute([$id, $date]);

    $later = [];
    foreach (booking_visit_dates($booking) as $d) {
        if ($d > $date) {
            $later[] = $d;
        }
    }
    if (!$later) {
        $done = booking_guide_complete($booking, $guideProfileId, false);
        if (!$done['ok']) {
            return $done;
        }
        return ['ok' => true, 'message' => 'Tour ended. Nice work.', 'completed' => true];
    }

    $code = (string) ($booking['booking_code'] ?? '');
    notify_user(
        (int) ($booking['tourist_id'] ?? 0),
        'approval',
        'Tour day ended',
        'Day of ' . format_date($date) . ' on ' . $code . ' is done. The remaining days are still scheduled.',
        APP_URL . '/tourist/bookings.php'
    );
    return [
        'ok' => true,
        'message' => 'This day is ended. The rest of the tour stays on your schedule.',
        'completed' => false,
    ];
}

/**
 * Mark approved bookings completed once their itinerary / booking date has passed.
 * Uses itinerary end_date when present (multi-day), otherwise start_date / booking_date.
 * Safe to call often — at most once a minute for the whole site.
 */
function bookings_auto_complete_past_tours(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $stamp = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/storage/autocomplete.stamp';
    if (is_file($stamp) && (time() - (int) filemtime($stamp)) < 60) {
        return;
    }
    @touch($stamp);
    try {
        $stmt = db()->query(
            "SELECT b.id, b.booking_code, b.tourist_id, b.guide_id, b.status, b.booking_date, b.itinerary_id
             FROM bookings b
             LEFT JOIN itineraries i ON i.id = b.itinerary_id
             WHERE b.status = 'approved'
               AND DATE(COALESCE(
                     b.tour_end_date,
                     NULLIF(i.end_date, '0000-00-00'),
                     i.start_date,
                     b.tour_start_date,
                     b.booking_date
               )) < CURDATE()
             ORDER BY b.booking_date ASC
             LIMIT 40"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
            booking_guide_complete($b, (int) $b['guide_id'], true);
        }
    } catch (Throwable $e) {
        // ignore — never block page load
    }
}

function ensure_booking_guide_reminder_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('guide_reminded_at', $cols, true)) {
            db()->exec('ALTER TABLE bookings ADD COLUMN guide_reminded_at DATETIME NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * One in-app notice to the tour guide when an approved tour starts within 30 minutes.
 * Uses the laptop clock. Safe to call on each page load and live poll.
 */
function bookings_notify_guides_before_tours(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        ensure_booking_guide_reminder_schema();
        $today = app_today();
        $nowMins = app_now_mins();
        $stmt = db()->prepare(
            "SELECT b.id, b.booking_code, b.start_time,
                    i.title AS itinerary_title, i.meetup_time,
                    gu.id AS guide_user_id,
                    CONCAT(tu.first_name, ' ', tu.last_name) AS tourist_name
             FROM bookings b
             JOIN tour_guide_profiles gp ON gp.id = b.guide_id
             JOIN users gu ON gu.id = gp.user_id
             JOIN users tu ON tu.id = b.tourist_id
             LEFT JOIN itineraries i ON i.id = b.itinerary_id
             WHERE b.status = 'approved'
               AND b.guide_reminded_at IS NULL
               AND DATE(COALESCE(b.tour_start_date, NULLIF(i.start_date, '0000-00-00'), b.booking_date)) = ?
             LIMIT 40"
        );
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
            $time = trim((string) ($b['start_time'] ?? ''));
            if ($time === '') {
                $time = trim((string) ($b['meetup_time'] ?? ''));
            }
            $startMins = itin_time_to_minutes($time);
            if ($startMins === null) {
                continue;
            }
            $until = $startMins - $nowMins;
            if ($until < 1 || $until > 30) {
                continue;
            }
            $claim = db()->prepare(
                'UPDATE bookings SET guide_reminded_at = NOW() WHERE id=? AND guide_reminded_at IS NULL'
            );
            $claim->execute([(int) $b['id']]);
            if ($claim->rowCount() < 1) {
                continue;
            }
            $tourName = trim((string) ($b['itinerary_title'] ?? ''));
            if ($tourName === '') {
                $tourName = 'Tour ' . (string) $b['booking_code'];
            }
            $clock = format_time_12h($time);
            $tourist = trim((string) ($b['tourist_name'] ?? ''));
            $body = $tourName . ($tourist !== '' ? ' with ' . $tourist : '')
                . ' starts at ' . ($clock !== '' ? $clock : 'the meetup time') . '.';
            notify_user(
                (int) $b['guide_user_id'],
                'tour_reminder',
                'Tour starts in ' . $until . ' minute' . ($until === 1 ? '' : 's'),
                $body,
                APP_URL . '/tourguide/schedule.php?date=' . rawurlencode($today) . '&booking=' . (int) $b['id']
            );
        }
    } catch (Throwable $e) {
        // ignore — never block page load
    }
}

/**
 * Tourist can cancel a pending request, or an approved tour stuck waiting for a replacement.
 */
function ensure_booking_refund_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_booking_payment_schema();
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return;
        }
        if (!in_array('refund_number', $cols, true)) {
            $pdo->exec('ALTER TABLE bookings ADD COLUMN refund_number VARCHAR(20) NULL');
        }
        if (!in_array('refund_reference', $cols, true)) {
            $pdo->exec('ALTER TABLE bookings ADD COLUMN refund_reference VARCHAR(40) NULL');
        }
        if (!in_array('refund_qr_path', $cols, true)) {
            $pdo->exec('ALTER TABLE bookings ADD COLUMN refund_qr_path VARCHAR(255) NULL');
        }
        $refundCols = [
            'refund_status' => 'VARCHAR(20) NULL',
            'refund_amount' => 'DECIMAL(10,2) NULL',
            'refund_requested_at' => 'DATETIME NULL',
            'refund_sent_at' => 'DATETIME NULL',
            'refund_sent_by' => 'INT UNSIGNED NULL',
            'refund_note' => 'VARCHAR(255) NULL',
        ];
        foreach ($refundCols as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN ' . $col . ' ' . $def);
                if ($col === 'refund_status') {
                    $pdo->exec("UPDATE bookings SET refund_status='sent' WHERE payment_status='refunded'");
                }
            }
        }
        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('refund_gcash_number', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN refund_gcash_number VARCHAR(20) NULL');
        }
        if (!in_array('refund_gcash_qr_path', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN refund_gcash_qr_path VARCHAR(255) NULL');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function booking_refund_number_ok(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
        return $digits;
    }
    return null;
}

/**
 * GCash number or QR upload for the cancel-and-refund window.
 */
function booking_refund_destination_fields(string $numberId, ?array $saved = null): string
{
    if ($saved === null) {
        $me = function_exists('current_user') ? current_user() : null;
        $saved = $me ? refund_saved_destination((int) $me['id']) : ['number' => '', 'qr' => ''];
    }
    $savedNumber = (string) ($saved['number'] ?? '');
    $savedQr = (string) ($saved['qr'] ?? '');
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $numberId) ?: 'refundNumber';
    $fileId = $id . 'Qr';
    $html = '<label class="form-label small mb-1" for="' . $id . '">GCash number</label>';
    $html .= '<input class="form-control" id="' . $id . '" name="refund_number" inputmode="numeric" autocomplete="off" placeholder="09XXXXXXXXX" maxlength="11" value="' . e($savedNumber) . '" data-refund-number aria-describedby="' . $id . 'Error">';
    $html .= '<div class="refund-number-error" id="' . $id . 'Error" data-refund-number-error hidden>Enter a valid GCash number</div>';
    $html .= '<div class="refund-or" aria-hidden="true">or</div>';
    if ($savedQr !== '') {
        $html .= '<label class="refund-saved-qr">'
            . '<input class="form-check-input" type="checkbox" name="refund_use_saved_qr" value="1" data-refund-saved-qr' . ($savedNumber === '' ? ' checked' : '') . '>'
            . '<img src="' . e(APP_URL . '/uploads/' . ltrim($savedQr, '/')) . '" alt="Saved GCash QR">'
            . '<span>Use my saved GCash QR</span></label>';
    }
    $html .= '<label class="form-label small mb-1" for="' . $fileId . '">' . ($savedQr !== '' ? 'Upload a different GCash QR' : 'GCash QR') . '</label>';
    $html .= '<input class="form-control" id="' . $fileId . '" name="refund_qr" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" data-refund-qr>';
    $html .= '<img alt="Selected GCash QR" class="refund-qr-preview" data-refund-qr-preview hidden>';
    $html .= '<div class="refund-number-error" data-refund-choice-error hidden>Enter a GCash number or upload a QR</div>';
    $html .= '<div class="form-check mt-3">'
        . '<input class="form-check-input" type="checkbox" name="refund_save" value="1" id="' . $id . 'Save"' . (($savedNumber !== '' || $savedQr !== '') ? ' checked' : '') . '>'
        . '<label class="form-check-label small" for="' . $id . 'Save">Save for future refunds</label></div>';
    return $html;
}

function booking_refund_destination_script(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<script>'
        . 'function cultourRefundDigits(el){var digits=String(el.value||"").replace(/\\D/g,"").slice(0,11);if(el.value!==digits)el.value=digits;return digits;}'
        . 'function cultourRefundNumberCheck(el){var digits=cultourRefundDigits(el);var msg=el.nextElementSibling;var bad=digits.length>=2&&digits.slice(0,2)!=="09";if(msg&&msg.matches&&msg.matches("[data-refund-number-error]"))msg.hidden=!bad;el.setAttribute("aria-invalid",bad?"true":"false");return !bad;}'
        . 'document.addEventListener("input",function(e){var el=e.target;if(!el||!el.matches||!el.matches("[data-refund-number]"))return;cultourRefundNumberCheck(el);var choice=el.form?el.form.querySelector("[data-refund-choice-error]"):null;if(choice)choice.hidden=true;});'
        . 'document.addEventListener("submit",function(e){var form=e.target;if(!form||!form.querySelector)return;var el=form.querySelector("[data-refund-number]");if(!el)return;var file=form.querySelector("[data-refund-qr]");var digits=cultourRefundDigits(el);var hasFile=!!(file&&file.files&&file.files.length)||!!form.querySelector("[data-refund-saved-qr]:checked");var numberOk=/^09\\d{9}$/.test(digits);var choice=form.querySelector("[data-refund-choice-error]");if(choice)choice.hidden=true;if(!cultourRefundNumberCheck(el)){e.preventDefault();return;}if(digits.length>0&&!numberOk){e.preventDefault();var msg=el.nextElementSibling;if(msg&&msg.matches&&msg.matches("[data-refund-number-error]"))msg.hidden=false;return;}if(!numberOk&&!hasFile){e.preventDefault();if(choice)choice.hidden=false;}});'
        . 'document.addEventListener("change",function(e){var el=e.target;if(!el||!el.matches||!el.matches("[data-refund-qr]"))return;var img=el.form?el.form.querySelector("[data-refund-qr-preview]"):null;var choice=el.form?el.form.querySelector("[data-refund-choice-error]"):null;if(choice)choice.hidden=true;if(!img)return;var file=el.files&&el.files[0];if(!file){img.hidden=true;img.removeAttribute("src");return;}img.src=URL.createObjectURL(file);img.hidden=false;});'
        . '</script>';
}

/**
 * @return array{ok:bool,path:?string,message:?string}
 */
function booking_save_refund_qr(?array $file = null, bool $required = true): array
{
    $file = $file ?? ($_FILES['refund_qr'] ?? null);
    $err = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if (!is_array($file) || $err === UPLOAD_ERR_NO_FILE) {
        if (!$required) {
            return ['ok' => true, 'path' => null, 'message' => null];
        }
        return ['ok' => false, 'path' => null, 'message' => 'Upload your GCash QR.'];
    }
    $path = upload_file($file, 'refund_qr', ['jpg', 'jpeg', 'png', 'webp']);
    if (!$path) {
        $msg = upload_last_error();
        return ['ok' => false, 'path' => null, 'message' => $msg ?: 'Upload a JPG, PNG, or WebP QR.'];
    }
    return ['ok' => true, 'path' => $path, 'message' => null];
}

function booking_refund_remember(array $receipt): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['cultour_refund_receipt'] = $receipt;
}

function booking_refund_receipt_modal(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $r = $_SESSION['cultour_refund_receipt'] ?? null;
    unset($_SESSION['cultour_refund_receipt']);
    if (!is_array($r) || (empty($r['reference']) && empty($r['amount']))) {
        return '';
    }
    $qr = (string) ($r['qr'] ?? '');
    $title = (string) ($r['title'] ?? 'Refund sent');
    $html = '<div class="modal fade" id="refundReceiptModal" tabindex="-1" aria-labelledby="refundReceiptLabel" aria-hidden="true">';
    $html .= '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">';
    $html .= '<div class="modal-header border-0"><h2 class="modal-title h5" id="refundReceiptLabel">' . e($title) . '</h2>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>';
    $html .= '<div class="modal-body text-center pt-0">';
    if (!empty($r['lead'])) {
        $html .= '<p class="small text-muted">' . e((string) $r['lead']) . '</p>';
    }
    if (!empty($r['reference'])) {
        $html .= '<div class="text-muted small">Reference number</div>';
        $html .= '<div class="fw-bold fs-4 mb-2">' . e((string) $r['reference']) . '</div>';
    }
    if (!empty($r['number'])) {
        $html .= '<div class="text-muted small">GCash number</div>';
        $html .= '<div class="fw-semibold mb-2">' . e((string) $r['number']) . '</div>';
    }
    if (!empty($r['amount'])) {
        $html .= '<div class="mb-3">' . e((string) $r['amount']) . '</div>';
    }
    if ($qr !== '') {
        $html .= '<div class="text-muted small mb-2">GCash QR</div>';
        $html .= '<img src="' . e($qr) . '" alt="GCash QR" width="220" height="220" style="width:220px;height:auto;background:#fff;padding:8px;border-radius:12px;">';
    }
    $html .= '</div></div></div></div>';
    $html .= '<script>document.addEventListener("DOMContentLoaded",function(){var el=document.getElementById("refundReceiptModal");if(el&&window.bootstrap){bootstrap.Modal.getOrCreateInstance(el).show();}});</script>';
    return $html;
}

function booking_tourist_can_cancel(array $booking): bool
{
    $status = (string) ($booking['status'] ?? '');
    if ($status === 'pending') {
        return true;
    }
    if ($status === 'approved' && booking_needs_replacement($booking)) {
        return true;
    }
    return $status === 'approved'
        && ($booking['payment_status'] ?? '') === 'paid'
        && !booking_tour_has_started($booking);
}

/**
 * Tourist cancels a pending booking, or leftover days of a replacement-stuck tour.
 * @return array{ok:bool,message:string}
 */
function booking_tourist_cancel(array $booking, ?string $refundNumber = null, array $refundOpts = []): array
{
    $id = (int) $booking['id'];
    $status = (string) ($booking['status'] ?? '');
    $code = (string) ($booking['booking_code'] ?? '');
    $paidRefund = in_array($status, ['pending', 'approved'], true)
        && ($booking['payment_status'] ?? '') === 'paid'
        && !booking_needs_replacement($booking);
    if ($paidRefund) {
        if (booking_tour_has_started($booking)) {
            return ['ok' => false, 'message' => 'This tour has already started.'];
        }
        $dest = refund_read_destination((int) $booking['tourist_id'], $refundNumber, $refundOpts);
        if (!$dest['ok']) {
            return ['ok' => false, 'message' => (string) $dest['message']];
        }
        $number = $dest['number'];
        $qrPath = $dest['qr'];
        $refundAmount = (float) ($booking['payment_amount'] ?? 0);
        $st = db()->prepare(
            "UPDATE bookings
             SET status='cancelled', refund_status='" . REFUND_REQUESTED . "', refund_amount=?, refund_requested_at=NOW(),
                 refund_number=?, refund_qr_path=?,
                 rejection_reason='Tourist cancelled and requested a refund.'
             WHERE id=? AND status IN ('pending','approved') AND payment_status='paid'"
        );
        $st->execute([$refundAmount, $number, $qrPath, $id]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'message' => 'This booking could not be cancelled.'];
        }
        $guideProfileId = (int) ($booking['guide_id'] ?? 0);
        $guideUserId = $guideProfileId > 0 ? tour_guide_user_id($guideProfileId) : 0;
        if ($guideUserId > 0) {
            notify_user(
                $guideUserId,
                'booking_cancelled',
                'Booking cancelled',
                'Tourist cancelled ' . $code . '. The Tourism Office will refund the payment.',
                APP_URL . '/tourguide/bookings.php'
            );
        }
        refund_notify_requested($booking, $refundAmount, $number, 'Tourist cancelled the booking.');
        booking_refund_remember([
            'title' => 'Refund requested',
            'lead' => 'The Tourism Office will send your refund and notify you with the reference number.',
            'number' => $number ?? '',
            'amount' => format_money($refundAmount),
            'qr' => $qrPath !== '' ? (APP_URL . '/uploads/' . ltrim($qrPath, '/')) : '',
        ]);
        $where = $number !== null ? $number : 'your GCash QR';
        return ['ok' => true, 'message' => 'Booking cancelled. The Tourism Office will send your refund to ' . $where . '.'];
    }
    $replacementStuck = $status === 'approved' && booking_needs_replacement($booking);
    $refundNote = '';

    if ($status === 'pending') {
        if (!assert_booking_transition($id, 'pending', 'cancelled')) {
            return ['ok' => false, 'message' => 'Only pending bookings can be cancelled.'];
        }
    } elseif ($replacementStuck) {
        ensure_booking_replacement_offer_schema();
        if (($booking['payment_status'] ?? '') === 'paid') {
            $dest = refund_read_destination((int) $booking['tourist_id'], $refundNumber, $refundOpts);
            if (!$dest['ok']) {
                return ['ok' => false, 'message' => (string) $dest['message']];
            }
            $number = $dest['number'];
            $qrPath = $dest['qr'];
            $refundAmount = booking_replacement_refund_amount($booking);
            $st = db()->prepare(
                "UPDATE bookings
                 SET status='cancelled', needs_replacement=0, replacement_state=NULL, replacement_candidate_id=NULL,
                     refund_status='" . REFUND_REQUESTED . "', refund_amount=?, refund_requested_at=NOW(),
                     refund_number=?, refund_qr_path=?,
                     rejection_reason='Tourist requested a refund for the remaining days after the guide stepped down.'
                 WHERE id=? AND status='approved' AND COALESCE(needs_replacement,0)=1"
            );
            $st->execute([$refundAmount, $number, $qrPath, $id]);
            if ($st->rowCount() < 1) {
                return ['ok' => false, 'message' => 'This tour could not be cancelled.'];
            }
            refund_notify_requested($booking, $refundAmount, $number, 'Remaining days cancelled after the guide stepped down.');
            booking_refund_remember([
                'title' => 'Refund requested',
                'lead' => 'The Tourism Office will send your refund and notify you with the reference number.',
                'number' => $number ?? '',
                'amount' => format_money($refundAmount),
                'qr' => $qrPath !== '' ? (APP_URL . '/uploads/' . ltrim($qrPath, '/')) : '',
            ]);
            $refundNote = ' The Tourism Office will send your ' . format_money($refundAmount) . ' refund to '
                . ($number !== null ? $number : 'your GCash QR') . '.';
        } else {
            $st = db()->prepare(
                "UPDATE bookings
                 SET status='cancelled', needs_replacement=0, replacement_state=NULL, replacement_candidate_id=NULL,
                     rejection_reason='Tourist cancelled remaining days after the guide stepped down.'
                 WHERE id=? AND status='approved' AND COALESCE(needs_replacement,0)=1"
            );
            $st->execute([$id]);
            if ($st->rowCount() < 1) {
                return ['ok' => false, 'message' => 'This tour could not be cancelled.'];
            }
        }
        db()->prepare(
            "UPDATE booking_replacement_offers SET status='cancelled', responded_at=NOW()
             WHERE booking_id=? AND status IN ('offered','accepted')"
        )->execute([$id]);
    } else {
        return ['ok' => false, 'message' => 'Only pending bookings, or tours waiting for a replacement, can be cancelled.'];
    }

    $notified = [];
    $notifyGuide = static function (int $guideProfileId) use (&$notified, $booking, $code): void {
        if ($guideProfileId < 1) {
            return;
        }
        $uid = tour_guide_user_id($guideProfileId);
        if ($uid < 1 || isset($notified[$uid])) {
            return;
        }
        $notified[$uid] = true;
        notify_user(
            $uid,
            'booking_cancelled',
            'Booking cancelled',
            'Tourist cancelled ' . $code . ' for ' . format_date($booking['booking_date']) . '.',
            APP_URL . '/tourguide/bookings.php'
        );
    };
    $notifyGuide((int) ($booking['guide_id'] ?? 0));
    if ($replacementStuck) {
        $notifyGuide(booking_original_guide_id($booking));
        notify_active_admins(
            'booking_cancelled',
            'Tourist cancelled a replacement-stuck tour',
            $code . ' leftover days were cancelled by the tourist.' . $refundNote,
            APP_URL . '/admin/bookings.php'
        );
        return ['ok' => true, 'message' => 'Remaining days cancelled.' . $refundNote];
    }
    return ['ok' => true, 'message' => 'Booking cancelled.'];
}

/** True if at least one visit day is already in the past. */
function booking_tour_has_started(array $booking): bool
{
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $dates = booking_visit_dates($booking);
    if (!$dates) {
        $start = normalize_tour_date($booking['tour_start_date'] ?? $booking['booking_date'] ?? null);
        return $start !== null && $start < $today;
    }
    foreach ($dates as $d) {
        if ($d < $today) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function booking_withdraw_reason_presets(): array
{
    return ['Sick', 'Emergency', 'Other'];
}

function booking_can_guide_cancel_approved(array $booking, int $guideProfileId): bool
{
    if (($booking['status'] ?? '') !== 'approved') {
        return false;
    }
    if (booking_needs_replacement($booking)) {
        return false;
    }
    if ((int) ($booking['guide_id'] ?? 0) !== $guideProfileId) {
        return false;
    }
    if (booking_ended_dates((int) ($booking['id'] ?? 0)) !== []) {
        return false;
    }
    if (booking_tour_has_started($booking)) {
        return false;
    }
    return booking_remaining_visit_dates($booking) !== [];
}

/**
 * Guide cancels an approved tour that has not started (1-day or the whole multi-day trip).
 * @return array{ok:bool,message:string}
 */
function booking_guide_cancel_approved(array $booking, string $reasonPreset, string $reasonDetail = ''): array
{
    ensure_booking_replacement_schema();
    $id = (int) ($booking['id'] ?? 0);
    $guideId = (int) ($booking['guide_id'] ?? 0);
    if ($id < 1 || $guideId < 1 || !booking_can_guide_cancel_approved($booking, $guideId)) {
        return ['ok' => false, 'message' => 'This tour can only be cancelled before it starts.'];
    }

    $preset = trim($reasonPreset);
    $allowed = booking_withdraw_reason_presets();
    if ($preset === '' || !in_array($preset, $allowed, true)) {
        return ['ok' => false, 'message' => 'Choose why you are cancelling.'];
    }
    $detail = trim($reasonDetail);
    if ($preset === 'Other' && $detail === '') {
        return ['ok' => false, 'message' => 'Please add a short note.'];
    }
    $reason = $preset === 'Other' ? $detail : ($detail !== '' ? $preset . ': ' . $detail : $preset);
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 240);
    } else {
        $reason = substr($reason, 0, 240);
    }

    $st = db()->prepare(
        "UPDATE bookings
         SET status='cancelled', rejection_reason=?, needs_replacement=0
         WHERE id=? AND status='approved' AND COALESCE(needs_replacement,0)=0"
    );
    $st->execute([$reason, $id]);
    if ($st->rowCount() < 1) {
        return ['ok' => false, 'message' => 'This tour could not be cancelled.'];
    }

    $code = (string) ($booking['booking_code'] ?? '');
    $dates = booking_visit_dates($booking);
    $spanNote = count($dates) > 1
        ? ' The whole ' . count($dates) . '-day tour was cancelled.'
        : '';
    $wasPaid = ($booking['payment_status'] ?? '') === 'paid';
    $paidNote = $wasPaid
        ? ' Enter your GCash number or QR so the Tourism Office can refund your payment.'
        : '';
    if ($wasPaid) {
        refund_open_awaiting_details($booking, (float) ($booking['payment_amount'] ?? 0), 'The guide cancelled this tour.');
    }
    notify_user(
        (int) $booking['tourist_id'],
        'booking_cancelled',
        'Tour cancelled',
        'Your guide cancelled ' . $code . '.' . $spanNote . ' Reason: ' . $reason . '.' . $paidNote,
        APP_URL . '/tourist/bookings.php'
    );
    notify_active_admins(
        'booking_cancelled',
        'Guide cancelled a tour',
        $code . ' was cancelled before it started. Reason: ' . $reason . '.',
        APP_URL . '/admin/bookings.php'
    );
    $guideUserId = tour_guide_user_id($guideId);
    if ($guideUserId > 0) {
        notify_user(
            $guideUserId,
            'booking_cancelled',
            'Tour cancelled',
            'You cancelled ' . $code . '. Those days are no longer on your schedule.',
            APP_URL . '/tourguide/bookings.php'
        );
    }
    if (function_exists('log_activity')) {
        log_activity($guideUserId ?: null, 'guide_cancel', 'bookings', $code . ': cancelled before start (' . $reason . ')');
    }
    return ['ok' => true, 'message' => 'Tour cancelled. Those days are now free on your schedule.'];
}

function booking_can_guide_withdraw(array $booking, int $guideProfileId): bool
{
    if (($booking['status'] ?? '') !== 'approved') {
        return false;
    }
    if (booking_needs_replacement($booking)) {
        return false;
    }
    if ((int) ($booking['guide_id'] ?? 0) !== $guideProfileId) {
        return false;
    }
    if (!booking_tour_has_started($booking) && booking_ended_dates((int) ($booking['id'] ?? 0)) === []) {
        return false;
    }
    return booking_releasable_visit_dates($booking) !== [];
}

/**
 * Guide steps down from leftover days of an approved tour.
 * @return array{ok:bool,message:string}
 */
function booking_guide_withdraw(array $booking, string $fromDate, string $reasonPreset, string $reasonDetail = ''): array
{
    ensure_booking_replacement_schema();
    $id = (int) ($booking['id'] ?? 0);
    if ($id < 1 || ($booking['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'message' => 'Only an approved tour can be stepped down.'];
    }
    if (booking_needs_replacement($booking)) {
        return ['ok' => false, 'message' => 'This tour is already waiting for a replacement guide.'];
    }
    $actorGuideId = (int) ($booking['guide_id'] ?? 0);
    if ($actorGuideId < 1 || !booking_can_guide_withdraw($booking, $actorGuideId)) {
        return ['ok' => false, 'message' => 'This tour cannot be stepped down right now.'];
    }

    $preset = trim($reasonPreset);
    $allowed = booking_withdraw_reason_presets();
    if ($preset === '' || !in_array($preset, $allowed, true)) {
        return ['ok' => false, 'message' => 'Choose why you cannot continue.'];
    }
    $detail = trim($reasonDetail);
    if ($preset === 'Other' && $detail === '') {
        return ['ok' => false, 'message' => 'Please add a short note.'];
    }
    $reason = $preset === 'Other' ? $detail : ($detail !== '' ? $preset . ': ' . $detail : $preset);
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 240);
    } else {
        $reason = substr($reason, 0, 240);
    }

    $remaining = booking_remaining_visit_dates($booking, $fromDate);
    if (!$remaining) {
        return ['ok' => false, 'message' => 'There are no remaining tour days to step down from.'];
    }
    $from = $remaining[0];
    if (!in_array($from, booking_remaining_visit_dates($booking), true)) {
        return ['ok' => false, 'message' => 'Pick a remaining tour day (today or later).'];
    }

    $guideId = (int) ($booking['guide_id'] ?? 0);
    $orig = booking_original_guide_id($booking) ?: $guideId;

    db()->prepare(
        'UPDATE bookings
         SET original_guide_id=?, replacement_from_date=?, needs_replacement=1,
             withdraw_reason=?, withdrawn_at=NOW()
         WHERE id=? AND status=? AND COALESCE(needs_replacement,0)=0'
    )->execute([$orig, $from, $reason, $id, 'approved']);
    if (db()->prepare('SELECT needs_replacement FROM bookings WHERE id=?')->execute([$id]) === false) {
        // ignore
    }
    $chk = db()->prepare('SELECT needs_replacement FROM bookings WHERE id=?');
    $chk->execute([$id]);
    if (!(int) $chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Could not update this booking.'];
    }

    ensure_booking_replacement_offer_schema();
    db()->prepare('UPDATE bookings SET replacement_state=NULL, replacement_candidate_id=NULL WHERE id=?')
        ->execute([$id]);

    $code = (string) ($booking['booking_code'] ?? '');
    $fromLabel = format_date($from);
    $reasonNote = $reason !== '' ? ' Reason: ' . $reason . '.' : '';
    notify_user(
        (int) $booking['tourist_id'],
        'booking_replacement',
        'Your guide cannot continue',
        'Your guide stepped down from ' . $code . ' starting ' . $fromLabel . '.' . $reasonNote
            . ' We are finding a replacement guide and will ask you to confirm.',
        APP_URL . '/tourist/bookings.php'
    );
    notify_active_admins(
        'booking_replacement',
        'Guide withdrew from a tour',
        $code . ' needs a replacement from ' . $fromLabel . '.' . $reasonNote
            . ' The system is asking available guides.',
        APP_URL . '/admin/bookings.php'
    );
    $guideUserId = tour_guide_user_id($guideId);
    if ($guideUserId > 0) {
        notify_user(
            $guideUserId,
            'booking_replacement',
            'Remaining days released',
            'You stepped down from ' . $code . ' starting ' . $fromLabel
                . '. Leftover days stay reserved on your schedule until a replacement is assigned.',
            APP_URL . '/tourguide/bookings.php'
        );
    }
    if (function_exists('log_activity')) {
        log_activity($guideUserId ?: null, 'guide_withdraw', 'bookings', $code . ': cannot continue from ' . $from);
    }
    $state = booking_replacement_advance($id);
    return [
        'ok' => true,
        'message' => $state === 'offered'
            ? 'Remaining days were released. The system is asking another guide to take over.'
            : 'Remaining days were released. No other guide is free, so the tourist and the Tourism Office were notified.',
    ];
}

/**
 * Available replacement guides for leftover dates.
 *
 * @return list<array<string,mixed>>
 */
function booking_list_replacement_guides(array $booking): array
{
    ensure_booking_replacement_schema();
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);
    $dates = booking_remaining_visit_dates($booking, $from);
    if (!$dates) {
        return [];
    }
    $start = $dates[0];
    $end = $dates[count($dates) - 1];
    $excludeOrig = booking_original_guide_id($booking);
    $excludeCurrent = (int) ($booking['guide_id'] ?? 0);
    $firstTime = booking_time_for_calendar_date($booking, $start);
    $list = tourist_book_guides_list(
        $start,
        $firstTime,
        $end,
        null,
        $excludeOrig > 0 ? $excludeOrig : null,
        (int) ($booking['tourist_id'] ?? 0)
    );
    $out = [];
    foreach ($list as $g) {
        $gid = (int) ($g['id'] ?? 0);
        if ($gid < 1 || $gid === $excludeOrig || $gid === $excludeCurrent) {
            continue;
        }
        $ok = true;
        foreach ($dates as $d) {
            $msg = guide_booking_availability_message(
                $gid,
                $d,
                booking_time_for_calendar_date($booking, $d),
                (int) ($booking['id'] ?? 0),
                null,
                $d
            );
            if ($msg !== null) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $out[] = $g;
        }
    }
    return $out;
}

/**
 * Assign a replacement guide to leftover days (immediately approved).
 * @return array{ok:bool,message:string}
 */
function booking_assign_replacement(array $booking, int $newGuideId, ?int $actorUserId = null): array
{
    ensure_booking_replacement_schema();
    $id = (int) ($booking['id'] ?? 0);
    if ($id < 1 || ($booking['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'message' => 'This tour is not open for a replacement.'];
    }
    if (!booking_needs_replacement($booking)) {
        return ['ok' => false, 'message' => 'This tour does not need a replacement guide.'];
    }
    if ($newGuideId < 1) {
        return ['ok' => false, 'message' => 'Choose a replacement tour guide.'];
    }
    $orig = booking_original_guide_id($booking);
    if ($newGuideId === $orig) {
        return ['ok' => false, 'message' => 'Pick a different guide than the one who stepped down.'];
    }
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);
    $dates = booking_remaining_visit_dates($booking, $from);
    if (!$dates) {
        return ['ok' => false, 'message' => 'There are no remaining days to assign.'];
    }
    $g = db()->prepare(
        "SELECT g.id, u.id AS user_id, CONCAT(u.first_name,' ',u.last_name) AS name
         FROM tour_guide_profiles g
         JOIN users u ON u.id = g.user_id
         WHERE g.id=? AND g.approval_status='approved' AND u.status='active' LIMIT 1"
    );
    $g->execute([$newGuideId]);
    $guide = $g->fetch();
    if (!$guide) {
        return ['ok' => false, 'message' => 'That tour guide is not available.'];
    }
    foreach ($dates as $d) {
        $msg = guide_booking_availability_message(
            $newGuideId,
            $d,
            booking_time_for_calendar_date($booking, $d),
            $id,
            null,
            $d
        );
        if ($msg !== null) {
            return ['ok' => false, 'message' => $msg];
        }
    }

    ensure_booking_replacement_offer_schema();
    $prevCandidate = booking_replacement_state($booking) === 'awaiting_tourist'
        ? (int) ($booking['replacement_candidate_id'] ?? 0)
        : 0;
    db()->prepare(
        'UPDATE bookings SET guide_id=?, needs_replacement=0, replacement_state=NULL, replacement_candidate_id=NULL
         WHERE id=? AND status=? AND needs_replacement=1'
    )->execute([$newGuideId, $id, 'approved']);
    $chk = db()->prepare('SELECT guide_id, needs_replacement FROM bookings WHERE id=?');
    $chk->execute([$id]);
    $row = $chk->fetch() ?: [];
    if ((int) ($row['guide_id'] ?? 0) !== $newGuideId || !empty($row['needs_replacement'])) {
        return ['ok' => false, 'message' => 'Could not assign that guide. Try again.'];
    }
    db()->prepare(
        "UPDATE booking_replacement_offers SET status='cancelled', responded_at=NOW()
         WHERE booking_id=? AND status IN ('offered','accepted') AND guide_id<>?"
    )->execute([$id, $newGuideId]);
    if ($prevCandidate > 0 && $prevCandidate !== $newGuideId) {
        $candUserId = tour_guide_user_id($prevCandidate);
        if ($candUserId > 0) {
            notify_user(
                $candUserId,
                'booking_replacement',
                'Replacement not needed',
                'Booking #' . ($booking['booking_code'] ?? '') . ' was assigned to another guide. Thanks for accepting.',
                APP_URL . '/tourguide/bookings.php'
            );
        }
    }

    $code = (string) ($booking['booking_code'] ?? '');
    $fromLabel = format_date($dates[0]);
    $newName = trim((string) ($guide['name'] ?? 'a tour guide'));
    $prevGuideId = (int) ($booking['guide_id'] ?? 0);
    notify_user(
        (int) $booking['tourist_id'],
        'booking_replacement',
        'Replacement guide assigned',
        $newName . ' will continue ' . $code . ' from ' . $fromLabel . '.',
        APP_URL . '/tourist/bookings.php'
    );
    $newUserId = (int) ($guide['user_id'] ?? 0);
    if ($newUserId > 0) {
        notify_user(
            $newUserId,
            'booking_request',
            'You were assigned to continue a tour',
            'You will continue ' . $code . ' from ' . $fromLabel . '. Open Bookings for details.',
            APP_URL . '/tourguide/bookings.php'
        );
    }
    $prevUserId = tour_guide_user_id($prevGuideId);
    if ($prevUserId > 0 && $prevUserId !== $newUserId) {
        notify_user(
            $prevUserId,
            'booking_replacement',
            'Replacement guide found',
            $newName . ' will continue ' . $code . ' from ' . $fromLabel . '. Remaining days stay off your schedule.',
            APP_URL . '/tourguide/bookings.php'
        );
    }
    $origUserId = tour_guide_user_id($orig);
    if ($origUserId > 0 && $origUserId !== $newUserId && $origUserId !== $prevUserId) {
        notify_user(
            $origUserId,
            'booking_replacement',
            'Replacement guide found',
            $newName . ' will continue ' . $code . ' from ' . $fromLabel . '.',
            APP_URL . '/tourguide/bookings.php'
        );
    }
    notify_active_admins(
        'booking_replacement',
        'Replacement guide assigned',
        $newName . ' will continue ' . $code . ' from ' . $fromLabel . '.',
        APP_URL . '/admin/bookings.php'
    );
    if (function_exists('log_activity')) {
        log_activity(
            $actorUserId,
            'assign_replacement',
            'bookings',
            $code . ': replacement guide #' . $newGuideId . ' from ' . $dates[0]
        );
    }
    return ['ok' => true, 'message' => $newName . ' will continue this tour from ' . $fromLabel . '.'];
}

/**
 * Replacement picker used on tourist + admin booking screens.
 */
function booking_replacement_picker_html(array $booking, string $hiddenFields = '', string $audience = 'tourist'): string
{
    $guides = booking_list_replacement_guides($booking);
    $from = normalize_tour_date($booking['replacement_from_date'] ?? null);
    $dates = booking_remaining_visit_dates($booking, $from);
    $dateBits = [];
    foreach ($dates as $d) {
        $dateBits[] = format_date($d);
    }
    $title = $audience === 'admin'
        ? 'This tour needs a replacement guide'
        : 'Your guide cannot continue this tour';
    $html = '<div class="alert alert-warning py-2 small mb-2">';
    $html .= '<div class="fw-semibold mb-1">' . e($title) . '</div>';
    if ($audience === 'admin') {
        $html .= booking_replacement_admin_status_html($booking);
    }
    if (!empty($booking['withdraw_reason'])) {
        $html .= '<div class="mb-1">Reason: ' . e((string) $booking['withdraw_reason']) . '</div>';
    }
    if ($dateBits) {
        $html .= '<div class="mb-2">Remaining days: ' . e(implode(', ', $dateBits)) . '</div>';
    }
    if (!$dates) {
        $html .= '<div>There are no leftover tour days to assign. Follow up at the Tourism Office.</div></div>';
        return $html;
    }
    if (!$guides) {
        $html .= '<div>No other tour guides are free on those days. You can cancel the remaining days, or contact the Tourism Office.</div></div>';
        return $html;
    }
    $html .= '<div class="mb-2">'
        . ($audience === 'admin'
            ? 'You can also assign one of these free guides yourself.'
            : 'Choose a replacement so the remaining days can continue.')
        . '</div></div>';
    $html .= '<div class="row g-2">';
    foreach ($guides as $g) {
        $name = trim((string) ($g['name'] ?? 'Tour guide'));
        $initials = '';
        foreach (preg_split('/\s+/', $name) ?: [] as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }
            if (strlen($initials) >= 2) {
                break;
            }
        }
        $html .= '<div class="col-md-6"><form method="post" class="border rounded-3 p-2 h-100 d-flex flex-wrap justify-content-between align-items-center gap-2">';
        $html .= csrf_field() . $hiddenFields;
        $html .= '<input type="hidden" name="action" value="assign_replacement">';
        $html .= '<input type="hidden" name="replacement_guide_id" value="' . (int) $g['id'] . '">';
        $html .= '<div class="d-flex align-items-center gap-2 min-w-0">';
        if (!empty($g['avatar'])) {
            $html .= '<img src="' . e((string) $g['avatar']) . '" alt="" width="40" height="40" class="rounded-circle" style="object-fit:cover">';
        } else {
            $html .= '<span class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center fw-semibold" style="width:40px;height:40px">'
                . e($initials !== '' ? $initials : '?') . '</span>';
        }
        $html .= '<div class="min-w-0"><div class="fw-semibold">' . e($name) . '</div>';
        $html .= '<div class="small text-muted">'
            . e((string) ($g['languages'] ?? ''))
            . (!empty($g['rate']) ? ' · ' . e((string) $g['rate']) . '/day' : '')
            . ((float) ($g['rating'] ?? 0) > 0 ? ' · ' . e(number_format((float) $g['rating'], 1)) . '★' : '')
            . '</div></div></div>';
        $html .= '<button class="btn btn-sm btn-ct">Choose this guide</button></form></div>';
    }
    $html .= '</div>';
    return $html;
}

function booking_user_can_access_ticket(array $user, array $booking): bool
{
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) {
        return false;
    }
    if ((int) ($user['role_id'] ?? 0) === ROLE_ADMIN) {
        return true;
    }
    if ($uid === (int) ($booking['tourist_id'] ?? 0)) {
        return true;
    }
    $guideIds = [];
    $current = (int) ($booking['guide_id'] ?? 0);
    $original = booking_original_guide_id($booking);
    if ($current > 0) {
        $guideIds[] = $current;
    }
    if ($original > 0) {
        $guideIds[] = $original;
    }
    foreach (array_unique($guideIds) as $gid) {
        if (tour_guide_user_id((int) $gid) === $uid) {
            return true;
        }
    }
    return false;
}

/**
 * Mark payment paid / office and notify tourist.
 * Demo GCash "paid" is Tourism Office only; guides may still set pay-at-office.
 *
 * @param array{admin?:bool,webhook?:bool} $opts
 * @return array{ok:bool,message:string}
 */
function booking_mark_payment(array $booking, string $status, ?string $reference = null, array $opts = []): array
{
    ensure_booking_payment_schema();
    if (!in_array($status, ['paid', 'pay_at_office'], true)) {
        return ['ok' => false, 'message' => 'Invalid payment status.'];
    }
    $bookingStatus = (string) ($booking['status'] ?? '');
    if (!in_array($bookingStatus, ['pending', 'approved'], true)) {
        return ['ok' => false, 'message' => 'Payment can only be recorded for pending or approved bookings.'];
    }
    if (($booking['payment_status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'This booking is already paid.'];
    }
    $id = (int) $booking['id'];
    if ($status === 'paid') {
        $fromAdmin = !empty($opts['admin']);
        $fromWebhook = !empty($opts['webhook']);
        $fromScan = !empty($opts['scan']);
        $demo = defined('PAYMENT_PROVIDER') && PAYMENT_PROVIDER === 'gcash_demo';
        if ($demo && !$fromAdmin && !$fromScan) {
            return ['ok' => false, 'message' => 'GCash demo payments are confirmed by the Tourism Office.'];
        }
        if (!$demo && !$fromAdmin && !$fromWebhook) {
            return ['ok' => false, 'message' => 'Only the Tourism Office can mark a booking paid.'];
        }
        $ref = trim((string) ($reference ?? $booking['payment_reference'] ?? ''));
        if (strlen($ref) > 120) {
            $ref = substr($ref, 0, 120);
        }
        db()->prepare("UPDATE bookings SET payment_status='paid', payment_reference=? WHERE id=?")
            ->execute([$ref !== '' ? $ref : null, $id]);
        $fresh = db()->prepare('SELECT * FROM bookings WHERE id=?');
        $fresh->execute([$id]);
        $row = $fresh->fetch() ?: $booking;
        $row['payment_status'] = 'paid';
        $row['payment_reference'] = $ref !== '' ? $ref : ($row['payment_reference'] ?? null);
        if (($row['status'] ?? '') === 'pending') {
            $gst = db()->prepare(
                'SELECT u.* FROM tour_guide_profiles g JOIN users u ON u.id = g.user_id WHERE g.id=?'
            );
            $gst->execute([(int) ($row['guide_id'] ?? 0)]);
            $guideUser = $gst->fetch() ?: null;
            $tourist = db()->prepare('SELECT * FROM users WHERE id=?');
            $tourist->execute([(int) ($row['tourist_id'] ?? 0)]);
            $tourist = $tourist->fetch() ?: null;
            $who = $tourist ? full_name($tourist) : 'A tourist';
            if ($who === '' || $who === 'Guest') {
                $who = 'A tourist';
            }
            if ($guideUser) {
                notify_user(
                    (int) $guideUser['id'],
                    'booking_request',
                    'New booking request',
                    $who . ' paid for ' . ($row['booking_code'] ?? '') . ' on '
                        . format_date($row['booking_date'] ?? '') . '. Accept or decline it.',
                    APP_URL . '/tourguide/bookings.php'
                );
            }
            notify_user(
                (int) $row['tourist_id'],
                'approval',
                'Payment received',
                'Payment for ' . ($row['booking_code'] ?? '') . ' was received. Your tour guide still needs to accept the booking.',
                APP_URL . '/tourist/bookings.php'
            );
            return ['ok' => true, 'message' => 'Paid. Waiting for the tour guide to accept.'];
        }
        notify_user(
            (int) $booking['tourist_id'],
            'approval',
            'Payment confirmed',
            'Payment for ' . $booking['booking_code'] . ' was marked as paid.',
            APP_URL . '/tourist/bookings.php'
        );
        return ['ok' => true, 'message' => 'Marked as paid.'];
    }
    db()->prepare("UPDATE bookings SET payment_status='pay_at_office' WHERE id=?")->execute([$id]);
    notify_user(
        (int) $booking['tourist_id'],
        'approval',
        'Pay at Tourism Office',
        'Booking ' . $booking['booking_code'] . ' is set to pay at the Tourism Office.',
        APP_URL . '/tourist/bookings.php'
    );
    return ['ok' => true, 'message' => 'Marked as pay at office.'];
}

/**
 * Clamp guide rating 1–5.
 */
function clamp_guide_rating($rating): int
{
    return max(1, min(5, (int) $rating));
}

/**
 * Whether tourist already rated this booking.
 */
function booking_has_rating(int $bookingId, int $touristId): bool
{
    $s = db()->prepare('SELECT id FROM guide_ratings WHERE booking_id=? AND tourist_id=? LIMIT 1');
    $s->execute([$bookingId, $touristId]);
    return (bool) $s->fetch();
}

/**
 * Tourist rates a completed tour guide booking.
 *
 * @return array{ok:bool,message:string}
 */
function booking_tourist_rate(array $booking, int $touristId, $rating, string $comment = ''): array
{
    if ((int) ($booking['tourist_id'] ?? 0) !== $touristId) {
        return ['ok' => false, 'message' => 'Forbidden'];
    }
    if (($booking['status'] ?? '') !== 'completed') {
        return ['ok' => false, 'message' => 'You can only rate completed tours.'];
    }
    $id = (int) ($booking['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'message' => 'Booking not found.'];
    }
    if (booking_has_rating($id, $touristId)) {
        return ['ok' => false, 'message' => 'You already rated this tour.'];
    }
    $stars = clamp_guide_rating($rating);
    $guideId = (int) ($booking['guide_id'] ?? 0);
    db()->prepare(
        'INSERT INTO guide_ratings (booking_id, tourist_id, guide_id, rating, comment) VALUES (?,?,?,?,?)'
    )->execute([$id, $touristId, $guideId, $stars, trim($comment)]);
    $avg = db()->prepare('SELECT AVG(rating) FROM guide_ratings WHERE guide_id=?');
    $avg->execute([$guideId]);
    db()->prepare('UPDATE tour_guide_profiles SET avg_rating=? WHERE id=?')
        ->execute([round((float) $avg->fetchColumn(), 2), $guideId]);
    return ['ok' => true, 'message' => 'Thanks for rating your guide.'];
}

/**
 * Count weekly availability rows for a guide profile.
 */
function guide_has_weekly_availability(int $guideProfileId): bool
{
    $s = db()->prepare(
        'SELECT COUNT(*) FROM guide_availability WHERE guide_id=? AND is_available=1'
    );
    $s->execute([$guideProfileId]);
    return (int) $s->fetchColumn() > 0;
}

/**
 * Merge overlapping [start, end] minute ranges.
 *
 * @param list<array{0:int,1:int}> $ranges
 * @return list<array{0:int,1:int}>
 */
function booking_merge_minute_ranges(array $ranges): array
{
    $clean = [];
    foreach ($ranges as $r) {
        $s = (int) ($r[0] ?? 0);
        $e = (int) ($r[1] ?? 0);
        if ($e > $s) {
            $clean[] = [$s, $e];
        }
    }
    if (!$clean) {
        return [];
    }
    usort($clean, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $out = [$clean[0]];
    for ($i = 1, $n = count($clean); $i < $n; $i++) {
        $last = count($out) - 1;
        if ($clean[$i][0] <= $out[$last][1]) {
            $out[$last][1] = max($out[$last][1], $clean[$i][1]);
        } else {
            $out[] = $clean[$i];
        }
    }
    return $out;
}

/**
 * Remaining [start, end] windows after subtracting blocked ranges from [open, close].
 *
 * @param list<array{0:int,1:int}> $blocked
 * @return list<array{0:int,1:int}>
 */
function booking_subtract_minute_ranges(int $open, int $close, array $blocked): array
{
    if ($close <= $open) {
        return [];
    }
    $free = [];
    $cursor = $open;
    foreach (booking_merge_minute_ranges($blocked) as [$s, $e]) {
        if ($e <= $open || $s >= $close) {
            continue;
        }
        $s = max($s, $open);
        $e = min($e, $close);
        if ($s > $cursor) {
            $free[] = [$cursor, $s];
        }
        $cursor = max($cursor, $e);
    }
    if ($cursor < $close) {
        $free[] = [$cursor, $close];
    }
    return $free;
}

/**
 * Join minute windows as "8:00 AM–11:30 AM and 2:30 PM–8:00 PM".
 *
 * @param list<array{0:int,1:int}> $windows
 */
function booking_format_minute_range_list(array $windows): string
{
    $bits = [];
    foreach ($windows as $w) {
        $s = (int) ($w[0] ?? 0);
        $e = (int) ($w[1] ?? 0);
        if ($e > $s) {
            $bits[] = format_minutes_12h($s) . '–' . format_minutes_12h($e);
        }
    }
    if (!$bits) {
        return '';
    }
    if (count($bits) === 1) {
        return $bits[0];
    }
    $last = array_pop($bits);
    return implode(', ', $bits) . ' and ' . $last;
}

/**
 * Weekly hours minus busy tours, expanded by the booking buffer on both sides.
 *
 * @param list<array{start_mins:int,end_mins:int}> $busy
 * @return list<array{0:int,1:int}>
 */
function guide_bookable_windows_after_busy(
    int $open,
    int $close,
    array $busy,
    int $bufferMins = GUIDE_BOOKING_BUFFER_MINS
): array {
    $bufferMins = max(0, $bufferMins);
    $blocked = [];
    foreach ($busy as $w) {
        $s = (int) ($w['start_mins'] ?? 0);
        $e = (int) ($w['end_mins'] ?? 0);
        if ($e <= $s) {
            continue;
        }
        $blocked[] = [
            max($open, $s - $bufferMins),
            min($close, $e + $bufferMins),
        ];
    }
    return booking_subtract_minute_ranges($open, $close, $blocked);
}

/**
 * Weekly hours label for a guide on one calendar date, minus busy + buffer.
 * @return array{ok:bool,hint:string}
 */
function guide_day_hours_hint(
    int $guideId,
    string $date,
    ?int $excludeBookingId = null,
    ?int $excludeItineraryId = null
): array
{
    $date = normalize_tour_date($date) ?? '';
    if ($date === '' || $guideId < 1) {
        return ['ok' => false, 'hint' => 'Set a tour date to check this guide’s availability.'];
    }

    $block = db()->prepare(
        'SELECT reason FROM guide_calendar_blocks WHERE guide_id=? AND block_date=? LIMIT 1'
    );
    $block->execute([$guideId, $date]);
    $blocked = $block->fetch();
    if ($blocked) {
        $why = trim((string) ($blocked['reason'] ?? ''));
        return [
            'ok' => false,
            'hint' => 'This tour guide is unavailable on ' . format_date($date)
                . ($why !== '' ? ' (' . $why . ')' : '') . '.',
        ];
    }

    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return ['ok' => false, 'hint' => 'Set a tour date to check this guide’s availability.'];
    }
    $dow = (int) date('w', $ts);
    $avail = db()->prepare(
        'SELECT start_time, end_time FROM guide_availability
         WHERE guide_id=? AND day_of_week=? AND is_available=1 LIMIT 1'
    );
    $avail->execute([$guideId, $dow]);
    $slot = $avail->fetch();
    if (!$slot) {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return [
            'ok' => false,
            'hint' => 'This tour guide is not available on ' . ($dayNames[$dow] ?? 'that day')
                . ' (' . format_date($date) . ').',
        ];
    }

    $open = itin_time_to_minutes((string) $slot['start_time']);
    $close = itin_time_to_minutes((string) $slot['end_time']);
    $hoursLabel = format_time_12h((string) $slot['start_time']) . '–' . format_time_12h((string) $slot['end_time']);
    $dateLabel = format_date($date);
    if ($open === null || $close === null || $close <= $open) {
        return ['ok' => false, 'hint' => 'Guide weekly hours are invalid for ' . $dateLabel . '.'];
    }

    $busy = guide_busy_windows_on_date($guideId, $date, $excludeBookingId, $excludeItineraryId);
    $bookable = guide_bookable_windows_after_busy($open, $close, $busy);
    if (!$busy) {
        return [
            'ok' => true,
            'hint' => 'Available ' . $hoursLabel . ' on ' . $dateLabel . '.',
        ];
    }

    $bufferMins = GUIDE_BOOKING_BUFFER_MINS;
    $bufferNote = $bufferMins > 0 ? ' (' . $bufferMins . '-minute buffer)' : '';
    $busyEnds = [];
    foreach ($busy as $w) {
        $end = (int) ($w['end_mins'] ?? 0);
        if ($end > 0) {
            $busyEnds[] = $end;
        }
    }
    sort($busyEnds);
    $busyNote = count($busyEnds) === 1
        ? 'Busy until ' . format_minutes_12h($busyEnds[0]) . $bufferNote . '.'
        : 'Busy ' . booking_format_minute_range_list(array_map(
            static fn (array $w): array => [(int) ($w['start_mins'] ?? 0), (int) ($w['end_mins'] ?? 0)],
            $busy
        )) . $bufferNote . '.';

    if (!$bookable) {
        return [
            'ok' => false,
            'hint' => 'This tour guide is fully booked for ' . $dateLabel
                . ' during their hours (' . $hoursLabel . '). ' . $busyNote,
        ];
    }

    return [
        'ok' => true,
        'hint' => 'Available ' . $hoursLabel . ' on ' . $dateLabel . '.',
    ];
}

/**
 * Day 2+ of a multi-day span: any booking, calendar block, or closed weekday
 * blocks the whole pick (free hours later that day do not matter).
 *
 * @param bool $soft When true (meetup empty / still pickable), omit “can’t take this N-day tour”.
 */
function guide_later_days_conflict_message(
    int $guideId,
    string $startDate,
    ?string $endDate = null,
    ?int $excludeItineraryId = null,
    bool $soft = false
): ?string {
    $start = normalize_tour_date($startDate);
    $end = normalize_tour_date($endDate) ?: $start;
    if ($start === null || $guideId < 1) {
        return null;
    }
    if ($end !== null && $end < $start) {
        $end = $start;
    }
    $dates = itinerary_span_dates($excludeItineraryId, $start, $end);
    if (count($dates) < 2) {
        return null;
    }
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $spanNote = 'so they can’t take this ' . count($dates) . '-day tour.';

    foreach ($dates as $di => $dayDate) {
        if ($di === 0) {
            continue;
        }
        $dayDate = normalize_tour_date($dayDate);
        if ($dayDate === null) {
            continue;
        }
        $block = db()->prepare(
            'SELECT reason FROM guide_calendar_blocks WHERE guide_id=? AND block_date=? LIMIT 1'
        );
        $block->execute([$guideId, $dayDate]);
        $blocked = $block->fetch();
        if ($blocked) {
            $why = trim((string) ($blocked['reason'] ?? ''));
            if ($soft) {
                return 'They are unavailable on ' . format_date($dayDate)
                    . ($why !== '' ? ' (' . $why . ')' : '') . '.';
            }
            return 'This tour guide is unavailable on ' . format_date($dayDate)
                . ($why !== '' ? ' (' . $why . ')' : '')
                . ', ' . $spanNote;
        }

        $dayTs = strtotime($dayDate . ' 12:00:00');
        if ($dayTs === false) {
            continue;
        }
        $dow = (int) date('w', $dayTs);
        $avail = db()->prepare(
            'SELECT id FROM guide_availability
             WHERE guide_id=? AND day_of_week=? AND is_available=1 LIMIT 1'
        );
        $avail->execute([$guideId, $dow]);
        if (!$avail->fetch()) {
            if ($soft) {
                return 'They are not available on ' . ($dayNames[$dow] ?? 'that day')
                    . ' (' . format_date($dayDate) . ').';
            }
            return 'This tour guide is not available on ' . ($dayNames[$dow] ?? 'that day')
                . ' (' . format_date($dayDate) . '), ' . $spanNote;
        }

        $busy = guide_busy_windows_on_date($guideId, $dayDate, null, $excludeItineraryId);
        if ($busy) {
            $w = $busy[0];
            $range = booking_busy_window_label($w);
            if ($soft) {
                return 'They also have a tour on ' . format_date($dayDate)
                    . ($range !== '' ? ' (' . $range . ')' : '') . '.';
            }
            return 'This tour guide already has a tour on ' . format_date($dayDate)
                . ($range !== '' ? ' (' . $range . ')' : '')
                . ', ' . $spanNote;
        }
    }

    return null;
}

/**
 * Per-day earliest-start notes for a guide across a tour span (for day-by-day plan).
 *
 * @return list<array{day:int,date:string,free_from:?int,label:string}>
 */
function guide_busy_by_day_notes(
    int $guideId,
    string $startDate,
    ?string $endDate = null,
    ?int $excludeItineraryId = null
): array {
    $start = normalize_tour_date($startDate);
    $end = normalize_tour_date($endDate) ?: $start;
    if ($start === null || $guideId < 1) {
        return [];
    }
    if ($end !== null && $end < $start) {
        $end = $start;
    }
    $dates = itinerary_span_dates($excludeItineraryId, $start, $end);
    $bufferMins = GUIDE_BOOKING_BUFFER_MINS;
    $out = [];

    foreach ($dates as $di => $dayDate) {
        $d = $di + 1;
        $dayDate = normalize_tour_date($dayDate);
        if ($dayDate === null) {
            continue;
        }

        $block = db()->prepare(
            'SELECT reason FROM guide_calendar_blocks WHERE guide_id=? AND block_date=? LIMIT 1'
        );
        $block->execute([$guideId, $dayDate]);
        if ($block->fetch()) {
            $out[] = [
                'day' => $d,
                'date' => $dayDate,
                'free_from' => null,
                'label' => 'Unavailable this day',
            ];
            continue;
        }

        $dayTs = strtotime($dayDate . ' 12:00:00');
        if ($dayTs === false) {
            continue;
        }
        $dow = (int) date('w', $dayTs);
        $avail = db()->prepare(
            'SELECT start_time, end_time FROM guide_availability
             WHERE guide_id=? AND day_of_week=? AND is_available=1 LIMIT 1'
        );
        $avail->execute([$guideId, $dow]);
        $slot = $avail->fetch();
        if (!$slot) {
            $out[] = [
                'day' => $d,
                'date' => $dayDate,
                'free_from' => null,
                'label' => 'Unavailable this day',
            ];
            continue;
        }

        $open = itin_time_to_minutes((string) $slot['start_time']);
        $close = itin_time_to_minutes((string) $slot['end_time']);
        if ($open === null || $close === null || $close <= $open) {
            $out[] = [
                'day' => $d,
                'date' => $dayDate,
                'free_from' => null,
                'label' => 'Unavailable this day',
            ];
            continue;
        }

        $busy = guide_busy_windows_on_date($guideId, $dayDate, null, $excludeItineraryId);
        if (!$busy) {
            continue;
        }

        $busyUntil = 0;
        $asTourist = false;
        foreach ($busy as $w) {
            $busyUntil = max($busyUntil, (int) ($w['end_mins'] ?? 0));
            if (!empty($w['as_tourist'])) {
                $asTourist = true;
            }
        }
        $freeFrom = $busyUntil + $bufferMins;
        if ($freeFrom < $open) {
            $freeFrom = $open;
        }
        if ($asTourist || $freeFrom >= $close) {
            $out[] = [
                'day' => $d,
                'date' => $dayDate,
                'free_from' => null,
                'label' => $asTourist ? 'On a tour as a tourist' : 'Unavailable this day',
                'as_tourist' => $asTourist,
            ];
            continue;
        }

        // A gap after another tour is handled when the booking is submitted.
        // Do not surface the old "earliest start / buffer" warning here.
    }

    return $out;
}

/**
 * Tourist “Book a tour guide” carousel payload (availability checked live).
 *
 * @return list<array{id:int,user_id:int,name:string,phone:string,languages:string,rate:string,license:string,rating:float,reviews:int,avatar:?string,hint:?string,ok:bool,hint_ok:bool,busy_by_day:list}>
 */
function tourist_book_guides_list(
    ?string $startDate,
    ?string $meetupTime,
    ?string $endDate = null,
    ?int $itineraryId = null,
    ?int $excludeGuideId = null,
    int $viewerUserId = 0
): array {
    $startDate = normalize_tour_date($startDate) ?? '';
    $endDate = normalize_tour_date($endDate) ?: $startDate;
    $meetupNorm = $meetupTime !== null && trim((string) $meetupTime) !== ''
        ? (normalize_time_input((string) $meetupTime) ?: null)
        : null;
    $itinId = ($itineraryId && $itineraryId > 0) ? $itineraryId : null;

    $guideRows = db()->query(
        "SELECT g.id, g.user_id, g.bio, g.languages, g.years_experience, g.hourly_rate, g.license_number,
                g.avg_rating, g.total_tours,
                (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.guide_id = g.id) AS review_count,
                u.first_name, u.last_name, u.avatar, u.phone
         FROM tour_guide_profiles g
         JOIN users u ON u.id = g.user_id
         WHERE g.approval_status='approved' AND u.status='active'
           AND " . guide_profile_complete_sql('g', 'u') . "
         ORDER BY g.avg_rating DESC, g.total_tours DESC"
    )->fetchAll() ?: [];

    $out = [];
    foreach ($guideRows as $g) {
        $gid = (int) $g['id'];
        if ($excludeGuideId !== null && $excludeGuideId > 0 && $gid === $excludeGuideId) {
            continue;
        }
        if ($viewerUserId > 0 && booking_is_own_guide($viewerUserId, $gid)) {
            continue;
        }
        $hint = null;
        $ok = false;
        // hint_ok drives green vs red styling (separate from pickable ok when meetup is empty).
        $hintOk = false;
        $busyByDay = [];
        $fullDayOk = false;
        if ($startDate === '') {
            $hint = 'Set a tour date to check this guide’s availability.';
        } elseif (!guide_has_weekly_availability($gid)) {
            $hint = 'This guide has not set weekly hours yet.';
        } else {
            $busyByDay = guide_busy_by_day_notes(
                $gid,
                $startDate,
                $endDate !== '' ? $endDate : $startDate,
                $itinId
            );
            $dayHours = guide_day_hours_hint($gid, $startDate, null, $itinId);
            $hoursHint = (string) ($dayHours['hint'] ?? '');
            // Soft later-day copy: still pickable. Hard later is only for full-day filter.
            $later = ($endDate !== '' && $endDate !== $startDate)
                ? guide_later_days_conflict_message(
                    $gid,
                    $startDate,
                    $endDate,
                    $itinId,
                    true
                )
                : null;
            // A meetup clash (busy, buffer, or closed hours) keeps the guide off the list.
            $ok = !empty($dayHours['ok']);
            $hint = $hoursHint !== '' ? $hoursHint : null;
            $hintOk = $ok && !$later;
            $meetupOutsideHours = false;
            if ($meetupNorm !== null) {
                $conflict = guide_booking_availability_message(
                    $gid,
                    $startDate,
                    $meetupNorm,
                    null,
                    $itinId,
                    $endDate !== '' ? $endDate : null
                );
                if ($conflict !== null && str_contains($conflict, 'is outside those hours')) {
                    $meetupOutsideHours = true;
                    $ok = false;
                } elseif ($conflict !== null) {
                    $ok = false;
                    $hintOk = false;
                } elseif ($later) {
                    $hint = trim(($hint !== null && $hint !== '' ? $hint . ' ' : '') . $later);
                } elseif ($hoursHint === '') {
                    $hint = 'Available ' . format_date($startDate) . '.';
                }
            } elseif ($later) {
                $hint = trim(($hint !== null && $hint !== '' ? $hint . ' ' : '') . $later);
            }
            // Full-day = no busy windows/blocks on any day of the span.
            $laterHard = ($endDate !== '' && $endDate !== $startDate)
                ? guide_later_days_conflict_message($gid, $startDate, $endDate, $itinId, false)
                : null;
            $fullDayOk = empty($busyByDay) && $laterHard === null && !empty($dayHours['ok']);
            if ($meetupNorm !== null && !$ok && !$meetupOutsideHours) {
                $fullDayOk = false;
            }
            foreach ($busyByDay as $note) {
                if (empty($note['as_tourist'])) {
                    continue;
                }
                $ok = false;
                $hintOk = false;
                $fullDayOk = false;
                $when = !empty($note['date']) ? format_date((string) $note['date']) : 'that day';
                $hint = 'This tour guide is already on a tour as a tourist on ' . $when
                    . ', so they can’t be booked that day.';
                break;
            }
            $cutoffMsg = guide_same_day_booking_cutoff_message($gid, $startDate);
            if ($cutoffMsg !== null) {
                $ok = false;
                $hint = $cutoffMsg;
                $hintOk = false;
                $fullDayOk = false;
            }
        }
        $out[] = [
            'id' => $gid,
            'user_id' => (int) $g['user_id'],
            'name' => trim($g['first_name'] . ' ' . $g['last_name']),
            'phone' => trim((string) ($g['phone'] ?? '')) ?: '—',
            'languages' => trim((string) ($g['languages'] ?? '')) ?: '—',
            'rate' => format_money($g['hourly_rate']),
            'rate_amount' => round((float) ($g['hourly_rate'] ?? 0), 2),
            'license' => trim((string) ($g['license_number'] ?? '')) ?: '—',
            'rating' => round((float) ($g['avg_rating'] ?? 0), 2),
            'reviews' => (int) ($g['review_count'] ?? 0),
            'avatar' => !empty($g['avatar']) ? (APP_URL . '/uploads/' . $g['avatar']) : null,
            'hint' => $hint,
            'ok' => $ok,
            'hint_ok' => $hintOk,
            'full_day_ok' => $fullDayOk,
            'busy_by_day' => $busyByDay,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $fa = ((int) !empty($b['full_day_ok'])) <=> ((int) !empty($a['full_day_ok']));
        if ($fa !== 0) {
            return $fa;
        }
        return ((int) !empty($b['ok'])) <=> ((int) !empty($a['ok']));
    });
    return $out;
}

/**
 * Replace weekly hours from a profile/availability form post.
 * Expects day[] checkboxes and day_start[N] / day_end[N] time strings.
 *
 * @param array<int,string> $dayLabels
 * @return array{saved:int,errors:list<string>}
 */
function guide_save_weekly_availability_from_post(int $guideId, array $post, array $dayLabels = []): array
{
    if ($guideId < 1) {
        return ['saved' => 0, 'errors' => ['Guide profile not found.']];
    }
    if (!$dayLabels) {
        $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    }

    db()->prepare('DELETE FROM guide_availability WHERE guide_id=?')->execute([$guideId]);

    $days = $post['day'] ?? [];
    if (!is_array($days)) {
        $days = [];
    }
    $starts = is_array($post['day_start'] ?? null) ? $post['day_start'] : [];
    $ends = is_array($post['day_end'] ?? null) ? $post['day_end'] : [];
    $saved = 0;
    $errors = [];

    foreach ($days as $dayRaw) {
        $day = (int) $dayRaw;
        if ($day < 0 || $day > 6) {
            continue;
        }
        $start = normalize_time_input((string) ($starts[$day] ?? '8:00 AM'));
        $end = normalize_time_input((string) ($ends[$day] ?? '5:00 PM'));
        if ($start === null || $end === null) {
            $errors[] = ($dayLabels[$day] ?? 'Day') . ': enter a valid start and end time.';
            continue;
        }
        $startMins = itin_time_to_minutes($start);
        $endMins = itin_time_to_minutes($end);
        if ($startMins === null || $endMins === null || $endMins <= $startMins) {
            $errors[] = ($dayLabels[$day] ?? 'Day') . ': end time must be after start time.';
            continue;
        }
        db()->prepare(
            'INSERT INTO guide_availability (guide_id, day_of_week, start_time, end_time, is_available) VALUES (?,?,?,?,1)'
        )->execute([$guideId, $day, $start, $end]);
        $saved++;
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * Load weekly availability rows keyed by day_of_week (0=Sun … 6=Sat).
 *
 * @return array<int,array{on:bool,start:string,end:string}>
 */
function guide_weekly_availability_by_day(int $guideId): array
{
    $byDay = [];
    for ($i = 0; $i < 7; $i++) {
        $byDay[$i] = [
            'on' => false,
            'start' => '8:00 AM',
            'end' => '5:00 PM',
        ];
    }
    if ($guideId < 1) {
        return $byDay;
    }
    $st = db()->prepare(
        'SELECT day_of_week, start_time, end_time FROM guide_availability
         WHERE guide_id=? AND is_available=1 ORDER BY day_of_week'
    );
    $st->execute([$guideId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $d = (int) ($row['day_of_week'] ?? -1);
        if ($d < 0 || $d > 6) {
            continue;
        }
        $byDay[$d] = [
            'on' => true,
            'start' => format_time_12h((string) ($row['start_time'] ?? '08:00:00')) ?: '8:00 AM',
            'end' => format_time_12h((string) ($row['end_time'] ?? '17:00:00')) ?: '5:00 PM',
        ];
    }
    return $byDay;
}

/**
 * Pending booking requests for a guide profile.
 */
function guide_pending_booking_count(int $guideProfileId): int
{
    if ($guideProfileId < 1) {
        return 0;
    }
    try {
        $s = db()->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE guide_id=? AND status='pending'
               AND payment_status IN ('paid','pay_at_office')"
        );
        $s->execute([$guideProfileId]);
        return (int) $s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Fetch guide bookings for the live bookings board.
 * @return list<array>
 */
function guide_bookings_fetch(int $guideProfileId): array
{
    ensure_booking_replacement_schema();
    $rows = db()->prepare(
        "SELECT b.*,
                CONCAT(u.first_name,' ',u.last_name) AS tourist_name,
                d.name AS destination_name,
                i.title AS itinerary_title
         FROM bookings b
         JOIN users u ON u.id=b.tourist_id
         LEFT JOIN destinations d ON d.id=b.destination_id
         LEFT JOIN itineraries i ON i.id=b.itinerary_id
         WHERE b.guide_id=? OR b.original_guide_id=?
         ORDER BY FIELD(b.status,'pending','approved','completed','rejected','cancelled'),
                  b.created_at DESC, b.booking_date"
    );
    $rows->execute([$guideProfileId, $guideProfileId]);
    return $rows->fetchAll() ?: [];
}

/**
 * Fingerprint so the guide UI only re-renders when bookings change.
 */
function guide_bookings_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (int) ($r['id'] ?? 0)
            . ':' . ($r['status'] ?? '')
            . ':' . ($r['payment_status'] ?? '')
            . ':' . ($r['payment_reference'] ?? '')
            . ':' . ($r['checked_in_at'] ?? '')
            . ':' . ($r['rejection_reason'] ?? '')
            . ':' . ($r['guide_id'] ?? '')
            . ':' . ($r['needs_replacement'] ?? '')
            . ':' . ($r['replacement_from_date'] ?? '')
            . ':' . ($r['withdraw_reason'] ?? '')
            . ':' . ($r['updated_at'] ?? $r['created_at'] ?? '');
    }
    return md5(implode('|', $parts) . '|n=' . count($parts));
}

/**
 * Render the live bookings list HTML for a tour guide.
 */
function render_guide_bookings_live_html(array $rows, int $guideProfileId): string
{
    if (!$rows) {
        return '<div class="glass p-4 text-muted" data-empty="1">No bookings yet. Paid requests show here for you to accept or decline.</div>';
    }

    $html = '<div class="row g-3" data-bookings-list="1">';

    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? '');
        $id = (int) ($r['id'] ?? 0);
        $unpaid = ($r['payment_status'] ?? 'unpaid') !== 'paid';
        $notCheckedIn = empty($r['checked_in_at']);
        $isNew = $status === 'pending'
            && !empty($r['created_at'])
            && (time() - strtotime((string) $r['created_at'])) < 120;

        $html .= '<div class="col-12" data-booking-id="' . $id . '"'
            . ($isNew ? ' data-booking-new="1"' : '') . '>';
        $html .= '<div class="glass p-3 p-md-4' . ($isNew ? ' guide-booking--pulse' : '') . '">';
        $html .= '<div class="d-flex flex-wrap justify-content-between gap-2 mb-2">';
        $html .= '<div><div class="small text-muted">Booking'
            . ($isNew ? ' <span class="badge bg-danger ms-1">New</span>' : '')
            . '</div>';
        $html .= '<div class="fw-semibold"><code>' . e($r['booking_code']) . '</code> · '
            . e($r['tourist_name']) . '</div></div>';
        $html .= '<div class="text-end">' . status_badge($status);
        if (booking_needs_replacement($r)) {
            $html .= ' <span class="badge bg-warning text-dark">Needs replacement</span>';
        }
        $html .= '<div class="small text-muted mt-1">'
            . e(ucwords(str_replace('_', ' ', (string) ($r['payment_status'] ?? 'unpaid'))))
            . '</div></div></div>';

        $html .= '<div class="row g-2 small mb-3">';
        $html .= '<div class="col-md-4"><div class="text-muted">Schedule</div><div>'
            . e(format_date($r['booking_date'])) . ' · '
            . e(format_time_12h($r['start_time'] ?? null)) . '</div></div>';
        $html .= '<div class="col-md-3"><div class="text-muted">Guests</div><div>'
            . (int) ($r['party_size'] ?? 1) . '</div></div>';
        $html .= '<div class="col-md-5"><div class="text-muted">Itinerary</div><div>';
        $itinId = (int) ($r['itinerary_id'] ?? 0);
        if ($itinId > 0 && !empty($r['itinerary_title'])) {
            $html .= e($r['itinerary_title']);
        } else {
            $html .= '—';
        }
        $html .= '</div></div>';
        if (!empty($r['notes'])) {
            $html .= '<div class="col-12"><div class="text-muted">Notes</div><div>'
                . e($r['notes']) . '</div></div>';
        }
        $accHtml = booking_accessibility_html($r);
        if ($accHtml !== '') {
            $html .= '<div class="col-12">' . $accHtml . '</div>';
        }
        if (!empty($r['payment_reference'])) {
            $html .= '<div class="col-12"><div class="text-muted">Payment ref</div><div>'
                . e($r['payment_reference']) . '</div></div>';
        }
        if ($status === 'rejected' && !empty($r['rejection_reason'])) {
            $html .= '<div class="col-12 text-danger">Declined: '
                . e($r['rejection_reason']) . '</div>';
        }
        if ($status === 'cancelled' && !empty($r['rejection_reason'])) {
            $html .= '<div class="col-12 text-danger">Cancelled: '
                . e($r['rejection_reason']) . '</div>';
        }
        $html .= '</div>';

        $html .= '<div class="mb-3">'
            . '<button type="button" class="btn btn-sm btn-outline-primary" data-booking-detail="' . $id
            . '" data-booking-code="' . e($r['booking_code']) . '">'
            . '<i class="bi bi-card-list"></i> View details</button></div>';

        if ($status === 'pending') {
            $canDecide = in_array((string) ($r['payment_status'] ?? ''), ['paid', 'pay_at_office'], true);
            if (!$canDecide) {
                $html .= '<div class="alert alert-info py-2 small mb-0">Waiting for payment.</div>';
            } else {
                $html .= '<div class="d-flex flex-wrap gap-2">';
                $html .= '<form method="post">' . csrf_field()
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<button name="action" value="approve" class="btn btn-sm btn-ct">Accept</button></form>';
                $html .= '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#decline'
                    . $id . '">Decline</button>';
                $html .= '</div>';
                $html .= '<div class="collapse mt-2" id="decline' . $id . '"><form method="post" class="row g-2">'
                    . csrf_field()
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<input type="hidden" name="action" value="decline">'
                    . '<div class="col-md-4"><label class="form-label small mb-1">Reason</label>'
                    . '<select class="form-select form-select-sm" name="reason_preset" required>'
                    . '<option value="">Choose reason…</option>';
                foreach (booking_decline_reason_presets() as $p) {
                    $html .= '<option value="' . e($p) . '">' . e($p) . '</option>';
                }
                $html .= '</select></div>'
                    . '<div class="col-md-5"><label class="form-label small mb-1">Note</label>'
                    . '<input class="form-control form-control-sm" name="reason_detail" placeholder="Required if Other"></div>'
                    . '<div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-outline-danger w-100">Decline</button></div>'
                    . '</form></div>';
                if (($r['payment_status'] ?? '') === 'paid') {
                    $html .= '<div class="small text-muted mt-2">Paid. Accept to add it to your schedule, or decline to refund the tourist.</div>';
                }
            }
        } elseif ($status === 'approved') {
            $isCurrent = (int) ($r['guide_id'] ?? 0) === $guideProfileId;
            $fromDate = normalize_tour_date($r['replacement_from_date'] ?? null);
            if (!$isCurrent && booking_original_guide_id($r) === $guideProfileId) {
                $html .= '<div class="alert alert-secondary py-2 small mb-2">You stepped down'
                    . ($fromDate ? ' from ' . e(format_date($fromDate)) : '')
                    . '. Remaining days were released from your schedule.</div>';
                $html .= '<a class="btn btn-sm btn-outline-primary" href="' . APP_URL
                    . '/booking-ticket.php?id=' . $id . '" target="_blank">Ticket</a>';
            } elseif (booking_needs_replacement($r)) {
                $html .= '<div class="alert alert-warning py-2 small mb-2">You cannot continue this tour'
                    . ($fromDate ? ' from ' . e(format_date($fromDate)) : '')
                    . '. Remaining days are waiting for a replacement guide.</div>';
                $html .= '<div class="d-flex flex-wrap gap-2">'
                    . '<a class="btn btn-sm btn-outline-secondary" href="' . APP_URL
                    . '/tourguide/chat.php?with=' . (int) $r['tourist_id'] . '">Chat</a>'
                    . '<a class="btn btn-sm btn-outline-primary" href="' . APP_URL
                    . '/booking-ticket.php?id=' . $id . '" target="_blank">Ticket</a></div>';
            } else {
            $warn = ($unpaid || $notCheckedIn)
                ? 'Warning: payment and/or check-in may still be open. Mark completed anyway?'
                : 'Mark this tour completed?';
            $html .= '<div class="d-flex flex-wrap gap-2 guide-booking-actions">';
            $html .= '<form method="post" onsubmit="return confirm(' . json_encode($warn) . ');">'
                . csrf_field()
                . '<input type="hidden" name="id" value="' . $id . '">'
                . '<button name="action" value="complete" class="btn btn-sm btn-ct">Mark completed</button></form>';
            $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . APP_URL
                . '/tourguide/chat.php?with=' . (int) $r['tourist_id'] . '">Chat</a>';
            $html .= '<a class="btn btn-sm btn-outline-primary" href="' . APP_URL
                . '/booking-ticket.php?id=' . $id . '" target="_blank">Ticket</a>';
            $html .= '</div>';
            if ($unpaid) {
                $html .= '<form method="post" class="d-flex flex-wrap gap-1 mt-2">'
                    . csrf_field()
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<button name="action" value="pay_at_office" class="btn btn-sm btn-outline-secondary">Pay at Tourism Office</button>'
                    . '</form>';
            }
            if ($unpaid || $notCheckedIn) {
                $html .= '<div class="small text-muted mt-2">';
                if ($unpaid) {
                    $html .= 'Payment still open. ';
                }
                if ($notCheckedIn) {
                    $html .= 'Not checked in yet.';
                }
                $html .= '</div>';
            }
            if (booking_can_guide_cancel_approved($r, $guideProfileId)) {
                $visitDates = booking_visit_dates($r);
                $cancelLabel = count($visitDates) > 1 ? 'Cancel whole tour' : 'Cancel tour';
                $html .= '<div class="mt-3 border-top pt-3">';
                $html .= '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancelTour'
                    . $id . '">' . e($cancelLabel) . '</button>';
                $html .= '<div class="collapse mt-2" id="cancelTour' . $id . '">';
                $html .= '<div class="small text-muted mb-2">'
                    . (count($visitDates) > 1
                        ? 'This cancels every remaining day of the tour. The tourist will be notified.'
                        : 'This cancels the tour. The tourist will be notified.')
                    . '</div>';
                $html .= '<form method="post" class="row g-2" onsubmit="return confirm('
                    . json_encode('Cancel this entire tour? All days will be released from your schedule.')
                    . ');">'
                    . csrf_field()
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<input type="hidden" name="action" value="cancel">'
                    . '<div class="col-md-4"><label class="form-label small mb-1">Reason</label>'
                    . '<select class="form-select form-select-sm" name="reason_preset" required>'
                    . '<option value="">Choose reason…</option>';
                foreach (booking_withdraw_reason_presets() as $p) {
                    $html .= '<option value="' . e($p) . '">' . e($p) . '</option>';
                }
                $html .= '</select></div>'
                    . '<div class="col-md-5"><label class="form-label small mb-1">Note</label>'
                    . '<input class="form-control form-control-sm" name="reason_detail" placeholder="Required if Other"></div>'
                    . '<div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-outline-danger w-100">'
                    . e($cancelLabel) . '</button></div>'
                    . '</form></div></div>';
            }
            if (booking_can_guide_withdraw($r, $guideProfileId)) {
                $remain = booking_releasable_visit_dates($r);
                $modalId = 'withdrawModal-' . $id;
                $tourTitle = trim((string) ($r['itinerary_title'] ?? ''));
                if ($tourTitle === '') {
                    $tourTitle = (string) ($r['booking_code'] ?? 'Tour');
                }
                $html .= '<div class="mt-3 border-top pt-3">';
                $html .= '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#'
                    . e($modalId) . '">Cannot continue</button>';
                $html .= '</div>';
                $html .= '<div class="modal fade tour-end-modal tour-end-modal--form" id="' . e($modalId) . '" tabindex="-1" aria-labelledby="'
                    . e($modalId) . '-label" aria-hidden="true" data-withdraw-modal="1">';
                $html .= '<div class="modal-dialog modal-dialog-centered">';
                $html .= '<div class="modal-content tour-end-card">';
                $html .= '<div class="modal-header border-0 pb-1">';
                $html .= '<div><div class="small text-muted">Cannot continue</div>';
                $html .= '<h2 class="h5 mb-0" id="' . e($modalId) . '-label">' . e($tourTitle) . '</h2></div>';
                $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                $html .= '</div>';
                $html .= '<form method="post">';
                $html .= '<div class="modal-body pt-2">';
                $html .= '<p class="small text-muted">Release the days you cannot make. Days you already ended stay on your schedule.</p>';
                $html .= csrf_field();
                $html .= '<input type="hidden" name="id" value="' . $id . '">';
                $html .= '<input type="hidden" name="action" value="withdraw">';
                $html .= '<div class="mb-3"><label class="form-label small mb-1" for="' . e($modalId) . '-day">From remaining day</label>';
                $html .= '<select class="form-select" id="' . e($modalId) . '-day" name="from_date" required>';
                foreach ($remain as $d) {
                    $html .= '<option value="' . e($d) . '">' . e(format_date($d)) . '</option>';
                }
                $html .= '</select></div>';
                $html .= '<div class="mb-3"><label class="form-label small mb-1" for="' . e($modalId) . '-reason">Reason</label>';
                $html .= '<select class="form-select" id="' . e($modalId) . '-reason" name="reason_preset" required>';
                $html .= '<option value="">Choose reason…</option>';
                foreach (booking_withdraw_reason_presets() as $p) {
                    $html .= '<option value="' . e($p) . '">' . e($p) . '</option>';
                }
                $html .= '</select></div>';
                $html .= '<div class="mb-0"><label class="form-label small mb-1" for="' . e($modalId) . '-note">Note</label>';
                $html .= '<input class="form-control" id="' . e($modalId) . '-note" name="reason_detail" placeholder="Required if Other">';
                $html .= '</div></div>';
                $html .= '<div class="modal-footer border-0 pt-0">';
                $html .= '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
                $html .= '<button class="btn btn-outline-danger">Release remaining days</button>';
                $html .= '</div></form></div></div></div>';
            }
            }
        } elseif ($status === 'completed') {
            $html .= '<a class="btn btn-sm btn-outline-primary" href="' . APP_URL
                . '/booking-ticket.php?id=' . $id . '" target="_blank">Ticket</a>';
        }

        $html .= '</div></div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Live payload for guide bookings board polling.
 * @return array{ok:bool,version:string,pending:int,newest_id:int,html:string}
 */
function guide_bookings_live_payload(int $guideProfileId): array
{
    $rows = guide_bookings_fetch($guideProfileId);
    $pending = 0;
    $newestId = 0;
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'pending'
            && in_array((string) ($r['payment_status'] ?? ''), ['paid', 'pay_at_office'], true)) {
            $pending++;
            $newestId = max($newestId, (int) $r['id']);
        }
    }
    return [
        'ok' => true,
        'version' => guide_bookings_version($rows),
        'pending' => $pending,
        'newest_id' => $newestId,
        'html' => render_guide_bookings_live_html($rows, $guideProfileId),
    ];
}

/** Per-stop photo proof that the assigned guide is at a planned itinerary stop. */
function ensure_booking_stop_checkin_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_stop_checkins (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                itinerary_item_id INT UNSIGNED NOT NULL,
                guide_id INT UNSIGNED NOT NULL,
                visit_date DATE NOT NULL,
                photo_path VARCHAR(255) NOT NULL,
                checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_stop_checkin (booking_id, itinerary_item_id),
                INDEX idx_stop_checkin_booking (booking_id),
                INDEX idx_stop_checkin_date (visit_date),
                INDEX idx_stop_checkin_guide (guide_id)
            ) ENGINE=InnoDB"
        );
        // Meetup check-in uses item id 0, which is not an itinerary_items row.
        try {
            $pdo->exec('ALTER TABLE booking_stop_checkins DROP FOREIGN KEY fk_stop_checkin_item');
        } catch (Throwable $e) {
            // already dropped, or the table was created without that key
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_stop_starts (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                itinerary_item_id INT UNSIGNED NOT NULL,
                visit_date DATE NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_stop_start (booking_id, itinerary_item_id, visit_date),
                INDEX idx_stop_start_booking (booking_id)
            ) ENGINE=InnoDB"
        );
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tour_started_at', $cols, true)) {
            $pdo->exec('ALTER TABLE bookings ADD COLUMN tour_started_at DATETIME NULL DEFAULT NULL');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_day_ends (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                visit_date DATE NOT NULL,
                ended_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_booking_day_end (booking_id, visit_date),
                INDEX idx_booking_day_end (booking_id)
            ) ENGINE=InnoDB"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_stop_skips (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                itinerary_item_id INT UNSIGNED NOT NULL,
                guide_id INT UNSIGNED NOT NULL,
                visit_date DATE NOT NULL,
                reason VARCHAR(255) NOT NULL,
                skipped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_stop_skip (booking_id, itinerary_item_id),
                INDEX idx_stop_skip_booking (booking_id)
            ) ENGINE=InnoDB"
        );
        ensure_booking_stop_checkins_private();
    } catch (Throwable $e) {
        // ignore
    }
}

function booking_checkin_clock_reached(?string $time): bool
{
    $mins = itin_time_to_minutes($time);
    if ($mins === null) {
        return true;
    }
    $cookie = function_exists('app_now_mins') ? app_now_mins() : (((int) date('G')) * 60 + (int) date('i'));
    $server = ((int) date('G')) * 60 + (int) date('i');
    return max($cookie, $server) >= $mins;
}

function booking_checkin_wait_label(?string $time): string
{
    $label = format_time_12h($time);
    return $label !== '' && $label !== '—' ? $label : 'the scheduled time';
}

/**
 * Photo check-in is locked until this stop's scheduled start, unless the guide already started the stop.
 */
function booking_checkin_needs_wait(?string $startTime): bool
{
    return !booking_checkin_clock_reached($startTime);
}

function booking_stop_is_started(array $starts, int $itemId): bool
{
    return isset($starts[$itemId]);
}

/**
 * True until the guide presses Start this stop. The clock does not open photo check-in by itself.
 */
function booking_checkin_needs_early_start(?string $startTime, int $bookingId, int $itemId, string $date, array $starts = []): bool
{
    if (!booking_checkin_needs_wait($startTime)) {
        return false;
    }
    if ($starts === []) {
        $starts = booking_stop_starts_map($bookingId, $date);
    }
    return !booking_stop_is_started($starts, $itemId);
}

/** @return array<int, array<string,mixed>> */
function booking_stop_starts_map(int $bookingId, string $date): array
{
    ensure_booking_stop_checkin_schema();
    $date = normalize_tour_date($date);
    if ($bookingId < 1 || $date === null) {
        return [];
    }
    try {
        $st = db()->prepare(
            'SELECT * FROM booking_stop_starts WHERE booking_id=? AND visit_date=?'
        );
        $st->execute([$bookingId, $date]);
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $map[(int) $row['itinerary_item_id']] = $row;
    }
    return $map;
}

function ensure_booking_stop_checkins_private(): void
{
    $dir = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'stop_checkins';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}

function booking_is_calendar_tour_day(array $booking, ?string $date = null): bool
{
    $date = normalize_tour_date($date) ?: (function_exists('app_today') ? app_today() : date('Y-m-d'));
    if ($date === null) {
        return false;
    }
    return in_array($date, booking_visit_dates($booking), true);
}

function booking_guide_can_checkin_on(array $booking, int $guideProfileId, ?string $date = null): bool
{
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $date = normalize_tour_date($date) ?: $today;
    if ($date !== $today) {
        return false;
    }
    if (($booking['status'] ?? '') !== 'approved') {
        return false;
    }
    if ($guideProfileId < 1 || !booking_guide_covers_date($booking, $guideProfileId, $date)) {
        return false;
    }
    return booking_is_calendar_tour_day($booking, $date);
}

/**
 * Approved/completed tours whose itinerary includes this calendar date.
 *
 * @param list<string> $statuses
 * @return list<array<string,mixed>>
 */
function booking_tours_happening_on(string $date, ?int $guideProfileId = null, array $statuses = ['approved', 'completed']): array
{
    ensure_booking_stop_checkin_schema();
    $date = normalize_tour_date($date);
    if ($date === null) {
        return [];
    }
    $allowed = [];
    foreach ($statuses as $st) {
        $st = strtolower(trim((string) $st));
        if (in_array($st, ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true)) {
            $allowed[] = $st;
        }
    }
    if (!$allowed) {
        $allowed = ['approved', 'completed'];
    }
    $ph = implode(',', array_fill(0, count($allowed), '?'));
    $sql = "SELECT b.*,
                   CONCAT(t.first_name,' ',t.last_name) AS tourist_name,
                   CONCAT(gu.first_name,' ',gu.last_name) AS guide_name,
                   gp.user_id AS guide_user_id,
                   i.title AS itinerary_title,
                   i.description AS itinerary_description,
                   i.start_date AS itin_start,
                   i.end_date AS itin_end,
                   i.meetup_time AS itin_meetup
            FROM bookings b
            JOIN users t ON t.id = b.tourist_id
            JOIN tour_guide_profiles gp ON gp.id = b.guide_id
            JOIN users gu ON gu.id = gp.user_id
            LEFT JOIN itineraries i ON i.id = b.itinerary_id
            WHERE b.status IN ($ph)
              AND DATE(COALESCE(b.tour_start_date, i.start_date, b.booking_date)) <= ?
              AND DATE(COALESCE(b.tour_end_date, b.tour_start_date, i.end_date, i.start_date, b.booking_date)) >= ?";
    $params = $allowed;
    $params[] = $date;
    $params[] = $date;
    if ($guideProfileId !== null && $guideProfileId > 0) {
        $sql .= ' AND (b.guide_id = ? OR b.original_guide_id = ?)';
        $params[] = $guideProfileId;
        $params[] = $guideProfileId;
    }
    $sql .= ' ORDER BY COALESCE(b.start_time, \'09:00:00\'), b.id';
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!booking_is_calendar_tour_day($row, $date)) {
            continue;
        }
        if ($guideProfileId !== null && $guideProfileId > 0
            && !booking_guide_covers_date($row, $guideProfileId, $date)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * @return array<int, array<string,mixed>> keyed by itinerary_item_id
 */
function booking_stop_checkins_map(int $bookingId): array
{
    ensure_booking_stop_checkin_schema();
    if ($bookingId < 1) {
        return [];
    }
    try {
        $st = db()->prepare(
            "SELECT c.*, CONCAT(u.first_name,' ',u.last_name) AS guide_name
             FROM booking_stop_checkins c
             JOIN tour_guide_profiles gp ON gp.id = c.guide_id
             JOIN users u ON u.id = gp.user_id
             WHERE c.booking_id = ?
             ORDER BY c.checked_in_at ASC"
        );
        $st->execute([$bookingId]);
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $map[(int) $row['itinerary_item_id']] = $row;
    }
    return $map;
}

/** @return list<string> */
function booking_stop_skip_reasons(): array
{
    return ['Not available right now', 'Closed', 'Could not reach it', 'Other'];
}

/** @return array<int, array<string,mixed>> */
function booking_stop_skips_map(int $bookingId): array
{
    ensure_booking_stop_checkin_schema();
    if ($bookingId < 1) {
        return [];
    }
    try {
        $st = db()->prepare(
            'SELECT * FROM booking_stop_skips WHERE booking_id=? ORDER BY skipped_at ASC'
        );
        $st->execute([$bookingId]);
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $row['skipped'] = 1;
        $map[(int) $row['itinerary_item_id']] = $row;
    }
    return $map;
}

/**
 * Photo check-ins plus skipped stops. A skipped stop counts as finished for the next stop.
 *
 * @return array<int, array<string,mixed>>
 */
function booking_stop_progress_map(int $bookingId): array
{
    $map = booking_stop_checkins_map($bookingId);
    foreach (booking_stop_skips_map($bookingId) as $id => $row) {
        if (!isset($map[$id])) {
            $map[$id] = $row;
        }
    }
    return $map;
}

function booking_stop_checkin_by_id(int $id): ?array
{
    ensure_booking_stop_checkin_schema();
    if ($id < 1) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM booking_stop_checkins WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function booking_stop_checkin_photo_url(array $checkin): ?string
{
    $id = (int) ($checkin['id'] ?? 0);
    $path = trim((string) ($checkin['photo_path'] ?? ''));
    if ($id < 1 || $path === '') {
        return null;
    }
    return rtrim(APP_URL, '/') . '/stop-checkin-photo.php?id=' . $id;
}

function booking_user_can_view_stop_checkin(array $user, array $booking): bool
{
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) {
        return false;
    }
    if ((int) ($user['role_id'] ?? 0) === ROLE_ADMIN) {
        return true;
    }
    if ($uid === (int) ($booking['tourist_id'] ?? 0)) {
        return true;
    }
    $guideIds = [];
    $current = (int) ($booking['guide_id'] ?? 0);
    $original = booking_original_guide_id($booking);
    if ($current > 0) {
        $guideIds[] = $current;
    }
    if ($original > 0) {
        $guideIds[] = $original;
    }
    foreach (array_unique($guideIds) as $gid) {
        if (tour_guide_user_id((int) $gid) === $uid) {
            return true;
        }
    }
    return false;
}

function booking_stop_checkin_safe_return(?string $url, string $fallback): string
{
    $url = trim((string) $url);
    if ($url !== '' && isset($url[0]) && $url[0] === '/') {
        $url = rtrim(APP_URL, '/') . $url;
    }
    $safe = function_exists('safe_app_return_url') ? safe_app_return_url($url) : null;
    return $safe ?: $fallback;
}

/**
 * Meetup pin for this tour day.
 * @return array{title:string,lat:float,lng:float,time:?string}
 */
function booking_checkin_meetup(array $booking, string $date): array
{
    [$tourStart] = booking_effective_tour_span($booking);
    $day = itinerary_day_number($tourStart, $date);
    if ($day < 1) {
        $day = 1;
    }
    $place = itinerary_day_meetup_place((int) ($booking['itinerary_id'] ?? 0), $day);
    $time = trim((string) ($place['meetup_time'] ?? ''));
    if ($time === '' && $day === 1) {
        $time = trim((string) ($booking['start_time'] ?? $booking['itin_meetup'] ?? ''));
    }
    return [
        'title' => trim((string) ($place['label'] ?? '')) !== '' ? (string) $place['label'] : 'Meetup',
        'lat' => (float) ($place['lat'] ?? 0),
        'lng' => (float) ($place['lng'] ?? 0),
        'time' => $time !== '' ? $time : null,
    ];
}

/**
 * @param list<array<string,mixed>> $stops
 * @param array<int, array<string,mixed>> $existing
 */
function booking_checkin_order_message(array $stops, int $itemId, array $existing): ?string
{
    // Meetup (id 0) is always first and is not in the stop list.
    if ($itemId === 0) {
        return null;
    }
    if (!isset($existing[0])) {
        return 'Check in at the meetup first.';
    }
    foreach ($stops as $s) {
        $earlierId = (int) ($s['id'] ?? 0);
        if ($earlierId < 1) {
            continue;
        }
        if ($earlierId === $itemId) {
            return null;
        }
        if (!isset($existing[$earlierId])) {
            $name = trim((string) ($s['stop_name'] ?? $s['destination_name'] ?? 'the previous stop'));
            return 'Check in ' . ($name !== '' ? $name : 'the previous stop')
                . ' first. You can start the next stop as soon as that one is done.';
        }
    }
    return 'That stop is not on today’s itinerary.';
}

function booking_tour_is_started(array $booking): bool
{
    return trim((string) ($booking['tour_started_at'] ?? '')) !== '';
}

/**
 * @return array{ok:bool,message:string}
 */
function booking_tour_start_submit(array $booking, int $guideProfileId): array
{
    ensure_booking_stop_checkin_schema();
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    if (!booking_guide_can_checkin_on($booking, $guideProfileId, $today)) {
        return ['ok' => false, 'message' => 'You can start this tour only on the tour day for an approved booking assigned to you.'];
    }
    if (booking_tour_is_started($booking)) {
        return ['ok' => true, 'message' => 'Tour already started. Check in at the meetup.'];
    }
    db()->prepare('UPDATE bookings SET tour_started_at = NOW() WHERE id=? AND tour_started_at IS NULL')
        ->execute([(int) $booking['id']]);
    return ['ok' => true, 'message' => 'Tour started. Check in at the meetup.'];
}

function booking_stop_start_submit(array $booking, int $guideProfileId, int $itineraryItemId): array
{
    ensure_booking_stop_checkin_schema();
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    if (!booking_guide_can_checkin_on($booking, $guideProfileId, $today)) {
        return ['ok' => false, 'message' => 'You can start a stop only on the tour day for an approved tour assigned to you.'];
    }
    if (!booking_tour_is_started($booking)) {
        return ['ok' => false, 'message' => 'Start the tour first, then check in at the meetup.'];
    }
    if ($itineraryItemId < 0) {
        return ['ok' => false, 'message' => 'Choose a stop to start.'];
    }
    $stops = booking_stops_on_calendar_date($booking, $today);
    if ($itineraryItemId !== 0) {
        $found = false;
        foreach ($stops as $s) {
            if ((int) ($s['id'] ?? 0) === $itineraryItemId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['ok' => false, 'message' => 'That stop is not on today’s itinerary.'];
        }
    }
    $existing = booking_stop_progress_map((int) $booking['id']);
    if (isset($existing[$itineraryItemId])) {
        return ['ok' => false, 'message' => 'This stop is already checked in.'];
    }
    $blocked = booking_checkin_order_message($stops, $itineraryItemId, $existing);
    if ($blocked !== null) {
        return ['ok' => false, 'message' => $blocked];
    }
    try {
        db()->prepare(
            'INSERT INTO booking_stop_starts (booking_id, itinerary_item_id, visit_date, started_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE started_at = started_at'
        )->execute([(int) $booking['id'], $itineraryItemId, $today]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not start this stop. Try again.'];
    }
    return ['ok' => true, 'message' => 'Started. You can check in now.'];
}

/**
 * Skip the current stop when the guide cannot go there.
 *
 * @return array{ok:bool,message:string}
 */
function booking_stop_skip_submit(
    array $booking,
    int $guideProfileId,
    int $itineraryItemId,
    string $reasonPreset,
    string $reasonDetail = ''
): array {
    ensure_booking_stop_checkin_schema();
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    if (!booking_guide_can_checkin_on($booking, $guideProfileId, $today)) {
        return ['ok' => false, 'message' => 'You can skip a stop only on the tour day for an approved tour assigned to you.'];
    }
    if (booking_day_is_ended((int) ($booking['id'] ?? 0), $today)) {
        return ['ok' => false, 'message' => 'This day is already ended.'];
    }
    if (!booking_tour_is_started($booking)) {
        return ['ok' => false, 'message' => 'Start the tour first.'];
    }
    if ($itineraryItemId < 0) {
        return ['ok' => false, 'message' => 'Choose a stop to skip.'];
    }
    $stops = booking_stops_on_calendar_date($booking, $today);
    $stopName = 'Meetup';
    if ($itineraryItemId === 0) {
        $meetup = booking_checkin_meetup($booking, $today);
        $stopName = (string) ($meetup['title'] ?? 'Meetup');
    } else {
        $found = false;
        foreach ($stops as $s) {
            if ((int) ($s['id'] ?? 0) === $itineraryItemId) {
                $found = true;
                $stopName = trim((string) ($s['stop_name'] ?? $s['destination_name'] ?? 'Stop'));
                break;
            }
        }
        if (!$found) {
            return ['ok' => false, 'message' => 'That stop is not on today’s itinerary.'];
        }
    }
    if ($stopName === '') {
        $stopName = 'Stop';
    }
    $preset = trim($reasonPreset);
    $detail = trim($reasonDetail);
    if (!in_array($preset, booking_stop_skip_reasons(), true)) {
        return ['ok' => false, 'message' => 'Choose a reason for skipping this stop.'];
    }
    if (strcasecmp($preset, 'Other') === 0 && $detail === '') {
        return ['ok' => false, 'message' => 'Add a note when the reason is Other.'];
    }
    $reason = $preset;
    if ($detail !== '') {
        $reason .= ': ' . $detail;
    }
    if (strlen($reason) > 255) {
        $reason = substr($reason, 0, 255);
    }
    $existing = booking_stop_progress_map((int) $booking['id']);
    if (isset($existing[$itineraryItemId])) {
        return ['ok' => false, 'message' => !empty($existing[$itineraryItemId]['skipped'])
            ? 'This stop is already skipped.'
            : 'This stop is already checked in.'];
    }
    $blocked = booking_checkin_order_message($stops, $itineraryItemId, $existing);
    if ($blocked !== null) {
        return ['ok' => false, 'message' => $blocked];
    }
    try {
        db()->prepare(
            'INSERT INTO booking_stop_skips
             (booking_id, itinerary_item_id, guide_id, visit_date, reason, skipped_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            (int) $booking['id'],
            $itineraryItemId,
            $guideProfileId,
            $today,
            $reason,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not skip this stop. Try again.'];
    }
    $code = (string) ($booking['booking_code'] ?? '');
    notify_user(
        (int) ($booking['tourist_id'] ?? 0),
        'approval',
        'Stop skipped',
        $stopName . ' on ' . $code . ' was skipped. Reason: ' . $reason,
        APP_URL . '/tourist/bookings.php'
    );
    return ['ok' => true, 'message' => $stopName . ' skipped. Continue with the next stop.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function booking_stop_checkin_submit(
    array $booking,
    int $guideProfileId,
    int $itineraryItemId,
    array $file,
    ?string $ticketScan = null
): array {
    ensure_booking_stop_checkin_schema();
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    if (!booking_guide_can_checkin_on($booking, $guideProfileId, $today)) {
        return [
            'ok' => false,
            'message' => 'Stop check-in only works on the tour day for an approved tour assigned to you.',
        ];
    }
    if (!booking_tour_is_started($booking)) {
        return ['ok' => false, 'message' => 'Start the tour first, then check in at the meetup.'];
    }
    $itineraryItemId = (int) $itineraryItemId;
    if ($itineraryItemId < 0) {
        return ['ok' => false, 'message' => 'Choose a stop to check in.'];
    }
    $stops = booking_stops_on_calendar_date($booking, $today);
    $stop = null;
    if ($itineraryItemId === 0) {
        require_once __DIR__ . '/qr.php';
        $scanRaw = $ticketScan !== null ? $ticketScan : (string) ($_POST['ticket_scan'] ?? '');
        $ticket = booking_meetup_ticket_ok($booking, $scanRaw);
        if (!$ticket['ok']) {
            return $ticket;
        }
        $meetup = booking_checkin_meetup($booking, $today);
        $stop = [
            'id' => 0,
            'stop_name' => (string) ($meetup['title'] ?? 'Meetup'),
            'start_time' => $meetup['time'] ?? null,
        ];
    } else {
        foreach ($stops as $s) {
            if ((int) ($s['id'] ?? 0) === $itineraryItemId) {
                $stop = $s;
                break;
            }
        }
    }
    if ($stop === null) {
        return ['ok' => false, 'message' => 'That stop is not on today’s itinerary.'];
    }
    $existing = booking_stop_progress_map((int) $booking['id']);
    $blocked = booking_checkin_order_message($stops, $itineraryItemId, $existing);
    if ($blocked !== null) {
        return ['ok' => false, 'message' => $blocked];
    }
    $sched = (string) ($stop['start_time'] ?? '');
    $starts = booking_stop_starts_map((int) $booking['id'], $today);
    if (booking_checkin_needs_early_start($sched, (int) $booking['id'], $itineraryItemId, $today, $starts)) {
        return [
            'ok' => false,
            'message' => 'This stop is scheduled at ' . booking_checkin_wait_label($sched)
                . '. Press Start this stop if you arrived early, then check in with a photo.',
        ];
    }
    if (isset($existing[$itineraryItemId])) {
        return ['ok' => false, 'message' => 'This stop is already checked in.'];
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE || $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => 'Take or choose a photo proving you are at this stop.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'The photo could not be uploaded. Try another picture.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > BOOKING_STOP_CHECKIN_MAX_BYTES) {
        return ['ok' => false, 'message' => 'Photo must be an image under 8 MB.'];
    }
    $rel = upload_file($file, 'stop_checkins', ['jpg', 'jpeg', 'png', 'webp']);
    if ($rel === null || $rel === '') {
        return ['ok' => false, 'message' => 'Use a JPG, PNG, or WebP photo.'];
    }
    ensure_booking_stop_checkins_private();
    try {
        db()->prepare(
            'INSERT INTO booking_stop_checkins
             (booking_id, itinerary_item_id, guide_id, visit_date, photo_path, checked_in_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            (int) $booking['id'],
            $itineraryItemId,
            $guideProfileId,
            $today,
            $rel,
        ]);
        if ($itineraryItemId === 0) {
            db()->prepare('UPDATE bookings SET checked_in_at=NOW() WHERE id=? AND checked_in_at IS NULL')
                ->execute([(int) $booking['id']]);
        }
    } catch (Throwable $e) {
        $abs = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs)) {
            @unlink($abs);
        }
        return ['ok' => false, 'message' => 'This stop is already checked in.'];
    }

    $stopName = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'a stop'));
    $code = (string) ($booking['booking_code'] ?? '');
    $title = $stopName !== '' ? $stopName : 'Stop';
    log_activity(
        tour_guide_user_id($guideProfileId) ?: null,
        'stop_checkin',
        'bookings',
        'Checked in at ' . $title . ' for ' . $code
    );
    $adminLink = rtrim(APP_URL, '/') . '/admin/tours.php?date=' . rawurlencode($today)
        . '&booking=' . (int) $booking['id'];
    notify_active_admins(
        'booking_stop_checkin',
        'Stop check-in · ' . $code,
        'Guide checked in at ' . $title . '.',
        $adminLink
    );
    $touristId = (int) ($booking['tourist_id'] ?? 0);
    if ($touristId > 0) {
        notify_user(
            $touristId,
            'booking_stop_checkin',
            'Guide arrived at ' . $title,
            'Your tour guide checked in at ' . $title . ' with a photo.',
            rtrim(APP_URL, '/') . '/tourist/bookings.php'
        );
    }
    return ['ok' => true, 'message' => 'Checked in at ' . $title . '.'];
}

/**
 * Right-panel End tour control + summary modal after every stop is checked in.
 *
 * @param list<array<string,mixed>> $stops
 * @param array<int, array<string,mixed>> $checkins
 */
/**
 * Selected-day check-in count plus a View popup of that day's photos.
 *
 * @param list<array<string,mixed>> $stops
 */
function booking_day_checkin_summary_html(array $booking, array $stops, string $date): string
{
    $bid = (int) ($booking['id'] ?? 0);
    $date = normalize_tour_date($date) ?: '';
    if ($bid < 1 || $date === '') {
        return '';
    }
    $checkins = booking_stop_checkins_map($bid);
    $total = count($stops);
    $done = 0;
    foreach ($stops as $stop) {
        if (isset($checkins[(int) ($stop['id'] ?? 0)])) {
            $done++;
        }
    }
    $dayEnded = booking_day_is_ended($bid, $date) || (string) ($booking['status'] ?? '') === 'completed';
    $modalId = 'schedCheckins-' . $bid . '-' . str_replace('-', '', $date);
    $html = '<span class="guide-cal-checkin-top">';
    if ($dayEnded) {
        $html .= '<span class="itin-day-done"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Day ended</span>';
    }
    $html .= '<span class="badge bg-teal-soft">';
    if ($dayEnded) {
        $html .= '<i class="bi bi-check-circle-fill" aria-hidden="true"></i> ';
    }
    $html .= (int) $done . ' / ' . (int) $total . ' checked in</span>';
    $html .= '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#' . e($modalId) . '">View</button>';
    $html .= '</span>';

    $html .= '<div class="modal fade tour-end-modal" id="' . e($modalId) . '" tabindex="-1" aria-labelledby="' . e($modalId) . '-label" aria-hidden="true" data-booking-details-modal="1">';
    $html .= '<div class="modal-dialog modal-dialog-centered modal-lg">';
    $html .= '<div class="modal-content tour-end-card">';
    $html .= '<div class="modal-header border-0 pb-1">';
    $html .= '<div><div class="small text-muted">Stop check-in</div>';
    $html .= '<h2 class="h5 mb-0" id="' . e($modalId) . '-label">' . e(format_date($date)) . '</h2></div>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
    $html .= '</div><div class="modal-body pt-2">';
    if (!$stops) {
        $html .= '<p class="small text-muted mb-0">No stops on this day.</p>';
    } else {
        $html .= '<div class="booking-details-strip" aria-label="Stop check-in photos">';
        $skips = booking_stop_skips_map($bid);
        $n = 0;
        foreach ($stops as $stop) {
            $n++;
            $sid = (int) ($stop['id'] ?? 0);
            $name = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop'));
            if ($name === '') {
                $name = 'Stop';
            }
            $row = $checkins[$sid] ?? null;
            $skip = $skips[$sid] ?? null;
            $photoUrl = is_array($row) ? booking_stop_checkin_photo_url($row) : null;
            $when = is_array($row) ? format_date($row['checked_in_at'] ?? '', 'g:i A') : '';
            $html .= '<article class="booking-details-stop">';
            if ($photoUrl) {
                $html .= '<a class="booking-details-stop__photo" href="' . e($photoUrl) . '" target="_blank" rel="noopener">';
                $html .= '<img src="' . e($photoUrl) . '" alt="Stop ' . $n . ' ' . e($name) . '">';
                $html .= '</a>';
            } else {
                $html .= '<div class="booking-details-stop__photo booking-details-stop__photo--empty">' . ($skip ? 'Skipped' : 'No photo') . '</div>';
            }
            $html .= '<div class="booking-details-stop__body">';
            $html .= '<div class="booking-details-stop__label">Stop ' . $n . '</div>';
            $html .= '<div class="booking-details-stop__name">' . e($name) . '</div>';
            if ($skip && !$photoUrl) {
                $html .= '<div class="booking-details-stop__time">' . e('Skipped · ' . (string) ($skip['reason'] ?? '')) . '</div>';
            } else {
                $html .= '<div class="booking-details-stop__time">' . ($when !== '' && $when !== '—' ? e('Checked in ' . $when) : 'Not checked in') . '</div>';
            }
            $html .= '</div></article>';
        }
        $html .= '</div>';
    }
    $html .= '</div><div class="modal-footer border-0 pt-0">';
    $html .= '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
    $html .= '</div></div></div></div>';
    return $html;
}

/**
 * Booking-history popup: check-ins grouped by day, photos in a sideways strip.
 */
function booking_history_details_html(array $booking): string
{
    $bid = (int) ($booking['id'] ?? 0);
    if ($bid < 1) {
        return '';
    }
    $modalId = 'bookingDetails-' . $bid;
    $title = trim((string) ($booking['itinerary_title'] ?? ''));
    if ($title === '') {
        $title = 'Tour ' . (string) ($booking['booking_code'] ?? '');
    }
    $dates = booking_visit_dates($booking);
    $checkins = booking_stop_checkins_map($bid);
    $skips = booking_stop_skips_map($bid);
    if (!$dates && $checkins) {
        foreach ($checkins as $row) {
            $vd = normalize_tour_date($row['visit_date'] ?? null);
            if ($vd !== null) {
                $dates[] = $vd;
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
    }

    $html = '<button type="button" class="btn btn-sm btn-outline-primary booking-pair-btn" data-bs-toggle="modal" data-bs-target="#' . e($modalId) . '">View details</button>';
    $html .= '<div class="modal fade tour-end-modal" id="' . e($modalId) . '" tabindex="-1" aria-labelledby="' . e($modalId) . '-label" aria-hidden="true" data-booking-details-modal="1">';
    $html .= '<div class="modal-dialog modal-dialog-centered modal-lg">';
    $html .= '<div class="modal-content tour-end-card">';
    $html .= '<div class="modal-header border-0 pb-1">';
    $html .= '<div><div class="small text-muted">Check-in details</div>';
    $html .= '<h2 class="h5 mb-0" id="' . e($modalId) . '-label">' . e($title) . '</h2></div>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
    $html .= '</div>';
    $html .= '<div class="modal-body pt-2">';
    $html .= '<div class="tour-end-card__meta small text-muted mb-3">';
    $html .= 'Code <code>' . e((string) ($booking['booking_code'] ?? '')) . '</code>';
    $guide = trim((string) ($booking['guide_name'] ?? ''));
    if ($guide !== '') {
        $html .= ' · Tour guide · ' . e($guide);
    }
    $html .= '</div>';

    if (!$dates) {
        $html .= '<p class="small text-muted mb-0">No tour days on this booking yet.</p>';
    }

    $dayNum = 0;
    foreach ($dates as $date) {
        $dayNum++;
        $stops = booking_stops_on_calendar_date($booking, $date);
        $cards = [];
        if ($dayNum === 1) {
            $meetup = booking_checkin_meetup($booking, $date);
            $cards[] = [
                'label' => 'Meetup',
                'name' => (string) ($meetup['title'] ?? 'Meetup'),
                'item_id' => 0,
                'row' => $checkins[0] ?? null,
            ];
        }
        $n = 0;
        foreach ($stops as $stop) {
            $n++;
            $sid = (int) ($stop['id'] ?? 0);
            $name = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop'));
            if ($name === '') {
                $name = 'Stop';
            }
            $cards[] = [
                'label' => 'Stop ' . $n,
                'name' => $name,
                'item_id' => $sid,
                'row' => $checkins[$sid] ?? null,
            ];
        }

        $dayEnded = booking_day_is_ended($bid, $date) || (string) ($booking['status'] ?? '') === 'completed';
        $html .= '<section class="booking-details-day">';
        $html .= '<div class="booking-details-day__title">Day ' . $dayNum . ' · ' . e(format_date($date));
        if ($dayEnded) {
            $html .= ' <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>';
        }
        $html .= '</div>';
        if (!$cards) {
            $html .= '<p class="small text-muted mb-0">No stops on this day.</p>';
        } else {
            $html .= '<div class="booking-details-strip" aria-label="Day ' . $dayNum . ' check-ins">';
            foreach ($cards as $card) {
                $row = is_array($card['row']) ? $card['row'] : null;
                $skip = $skips[(int) ($card['item_id'] ?? -1)] ?? null;
                $photoUrl = $row ? booking_stop_checkin_photo_url($row) : null;
                $when = $row ? format_date($row['checked_in_at'] ?? '', 'g:i A') : '';
                $html .= '<article class="booking-details-stop">';
                if ($photoUrl) {
                    $html .= '<a class="booking-details-stop__photo" href="' . e($photoUrl) . '" target="_blank" rel="noopener">';
                    $html .= '<img src="' . e($photoUrl) . '" alt="' . e($card['label'] . ' ' . $card['name']) . '">';
                    $html .= '</a>';
                } else {
                    $html .= '<div class="booking-details-stop__photo booking-details-stop__photo--empty">' . ($skip ? 'Skipped' : 'No photo') . '</div>';
                }
                $html .= '<div class="booking-details-stop__body">';
                $html .= '<div class="booking-details-stop__label">' . e($card['label']) . '</div>';
                $html .= '<div class="booking-details-stop__name">' . e($card['name']) . '</div>';
                if ($skip && !$photoUrl) {
                    $html .= '<div class="booking-details-stop__time">' . e('Skipped · ' . (string) ($skip['reason'] ?? '')) . '</div>';
                } else {
                    $html .= '<div class="booking-details-stop__time">' . ($when !== '' && $when !== '—' ? e('Checked in ' . $when) : 'Not checked in') . '</div>';
                }
                $html .= '</div></article>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';
    }

    $html .= '</div>';
    $html .= '<div class="modal-footer border-0 pt-0">';
    $html .= '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
    $html .= '</div></div></div></div>';
    return $html;
}

function booking_stop_checkin_end_tour_html(array $booking, array $stops, array $checkins, string $returnTo = '', string $dayDate = ''): string
{
    $bid = (int) ($booking['id'] ?? 0);
    $modalId = 'endTourModal-' . $bid;
    $title = trim((string) ($booking['itinerary_title'] ?? ''));
    if ($title === '') {
        $title = 'Tour ' . (string) ($booking['booking_code'] ?? '');
    }
    $started = trim((string) ($booking['tour_started_at'] ?? ''));
    $tourist = trim((string) ($booking['tourist_name'] ?? ''));
    $dayDate = normalize_tour_date($dayDate) ?: (function_exists('app_today') ? app_today() : date('Y-m-d'));
    $laterDays = false;
    foreach (booking_visit_dates($booking) as $d) {
        if ($d > $dayDate) {
            $laterDays = true;
            break;
        }
    }
    $endLabel = $laterDays ? 'End this day' : 'End tour';
    $confirmLabel = $laterDays ? 'Confirm end day' : 'Confirm end tour';

    $html = '<article class="stop-checkin__item is-on tour-end-panel" data-stop-id="end" data-checkin-next="1">';
    $html .= '<div class="fw-semibold">All stops checked in</div>';
    $html .= '<p class="small text-muted mt-1 mb-2">'
        . ($laterDays
            ? 'Open the summary, then end this day only. Later days stay scheduled.'
            : 'Open the summary to review times and photos, then end the tour.')
        . '</p>';
    $html .= '<button type="button" class="btn btn-sm btn-ct" data-bs-toggle="modal" data-bs-target="#' . e($modalId) . '">' . e($endLabel) . '</button>';
    $html .= '</article>';

    $html .= '<div class="modal fade tour-end-modal" id="' . e($modalId) . '" tabindex="-1" aria-labelledby="' . e($modalId) . '-label" aria-hidden="true" data-tour-end-modal="1">';
    $html .= '<div class="modal-dialog modal-dialog-centered modal-lg">';
    $html .= '<div class="modal-content tour-end-card">';
    $html .= '<div class="modal-header border-0 pb-1">';
    $html .= '<div><div class="small text-muted">Tour summary</div>';
    $html .= '<h2 class="h5 mb-0" id="' . e($modalId) . '-label">' . e($title) . '</h2></div>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
    $html .= '</div>';
    $html .= '<div class="modal-body pt-2">';
    $html .= '<div class="tour-end-card__meta small text-muted mb-3">';
    $html .= 'Code <code>' . e((string) ($booking['booking_code'] ?? '')) . '</code>';
    if ($tourist !== '') {
        $html .= ' · ' . e($tourist);
    }
    if ($started !== '') {
        $html .= ' · Started ' . e(format_date($started, 'M j, g:i A'));
    }
    $html .= '</div>';

    $html .= '<div class="tour-end-card__section-title">Stops &amp; times</div>';
    $html .= '<div class="tour-end-card__stops">';
    foreach ($stops as $stop) {
        $sid = (int) ($stop['id'] ?? 0);
        $name = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop'));
        if ($name === '') {
            $name = 'Stop';
        }
        $row = $checkins[$sid] ?? null;
        $sched = '';
        if (!empty($stop['start_time']) || !empty($stop['end_time'])) {
            $sched = format_time_12h($stop['start_time'] ?? null);
            if (!empty($stop['end_time'])) {
                $sched .= ' – ' . format_time_12h($stop['end_time']);
            }
        }
        $checked = is_array($row) ? format_date($row['checked_in_at'] ?? '', 'g:i A') : '—';
        $html .= '<div class="tour-end-card__stop">';
        $html .= '<div class="tour-end-card__stop-text">';
        $html .= '<div class="fw-semibold">' . e($name) . '</div>';
        $html .= '<div class="small text-muted">';
        if ($sched !== '') {
            $html .= 'Scheduled ' . e($sched) . '<br>';
        }
        $html .= 'Checked in ' . e($checked);
        $html .= '</div></div>';
        if (is_array($row)) {
            $photoUrl = booking_stop_checkin_photo_url($row);
            if ($photoUrl) {
                $html .= '<a class="tour-end-card__shot" href="' . e($photoUrl) . '" target="_blank" rel="noopener">';
                $html .= '<img src="' . e($photoUrl) . '" alt="Check-in at ' . e($name) . '">';
                $html .= '</a>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    $html .= '<div class="tour-end-card__section-title">Photos</div>';
    $html .= '<div class="tour-end-card__photos" aria-label="Check-in photos">';
    $photoCount = 0;
    foreach ($stops as $stop) {
        $sid = (int) ($stop['id'] ?? 0);
        $row = $checkins[$sid] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $photoUrl = booking_stop_checkin_photo_url($row);
        if (!$photoUrl) {
            continue;
        }
        $photoCount++;
        $name = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop'));
        $html .= '<figure class="tour-end-card__photo">';
        $html .= '<img src="' . e($photoUrl) . '" alt="' . e($name !== '' ? $name : 'Stop') . '">';
        $html .= '<figcaption>' . e($name !== '' ? $name : 'Stop');
        $when = format_date($row['checked_in_at'] ?? '', 'g:i A');
        if ($when !== '—') {
            $html .= ' · ' . e($when);
        }
        $html .= '</figcaption></figure>';
    }
    if ($photoCount < 1) {
        $html .= '<div class="small text-muted">No check-in photos on file.</div>';
    }
    $html .= '</div></div>';

    $html .= '<div class="modal-footer border-0 pt-0 flex-wrap gap-2">';
    $html .= '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep reviewing</button>';
    $html .= '<form method="post" action="' . e(rtrim(APP_URL, '/') . '/tourguide/checkin.php') . '">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="action" value="end_tour">';
    $html .= '<input type="hidden" name="booking_id" value="' . $bid . '">';
    $html .= '<input type="hidden" name="visit_date" value="' . e($dayDate) . '">';
    if ($returnTo !== '') {
        $html .= '<input type="hidden" name="return" value="' . e($returnTo) . '">';
    }
    $html .= '<button type="submit" class="btn btn-ct">' . e($confirmLabel) . '</button>';
    $html .= '</form></div>';
    $html .= '</div></div></div>';
    return $html;
}

function booking_stop_start_controls_html(array $booking, int $itemId, string $returnTo): string
{
    $html = '<form method="post" action="' . e(rtrim(APP_URL, '/') . '/tourguide/checkin.php') . '" class="stop-checkin__form">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="action" value="start_stop">';
    $html .= '<input type="hidden" name="booking_id" value="' . (int) ($booking['id'] ?? 0) . '">';
    $html .= '<input type="hidden" name="itinerary_item_id" value="' . $itemId . '">';
    if ($returnTo !== '') {
        $html .= '<input type="hidden" name="return" value="' . e($returnTo) . '">';
    }
    $html .= '<button class="btn btn-sm btn-ct" type="submit">Start this stop</button>';
    $html .= '</form>';
    return $html;
}

function booking_stop_skip_controls_html(array $booking, int $itemId, string $stopName, string $returnTo, string $date): string
{
    $bid = (int) ($booking['id'] ?? 0);
    $modalId = 'skipStop-' . $bid . '-' . $itemId;
    $html = '<button type="button" class="btn btn-sm stop-skip-open" data-bs-toggle="modal" data-bs-target="#'
        . e($modalId) . '">Can\'t go to this stop</button>';
    $html .= '<div class="modal fade tour-end-modal tour-end-modal--form" id="' . e($modalId) . '" tabindex="-1" aria-labelledby="'
        . e($modalId) . '-label" aria-hidden="true" data-tour-end-modal="1">';
    $html .= '<div class="modal-dialog modal-dialog-centered">';
    $html .= '<div class="modal-content tour-end-card">';
    $html .= '<div class="modal-header border-0 pb-1">';
    $html .= '<div><div class="small text-muted">Skip this stop</div>';
    $html .= '<h2 class="h5 mb-0" id="' . e($modalId) . '-label">' . e($stopName) . '</h2></div>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
    $html .= '</div>';
    $html .= '<form method="post" action="' . e(rtrim(APP_URL, '/') . '/tourguide/checkin.php') . '">';
    $html .= '<div class="modal-body pt-2">';
    $html .= '<p class="small text-muted">Use this when the stop is not available. The tour continues at the next stop.</p>';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="action" value="skip_stop">';
    $html .= '<input type="hidden" name="booking_id" value="' . $bid . '">';
    $html .= '<input type="hidden" name="itinerary_item_id" value="' . $itemId . '">';
    $html .= '<input type="hidden" name="visit_date" value="' . e($date) . '">';
    if ($returnTo !== '') {
        $html .= '<input type="hidden" name="return" value="' . e($returnTo) . '">';
    }
    $html .= '<div class="mb-3"><label class="form-label small mb-1" for="' . e($modalId) . '-reason">Reason</label>';
    $html .= '<select class="form-select" id="' . e($modalId) . '-reason" name="reason_preset" required>';
    $html .= '<option value="">Choose reason…</option>';
    foreach (booking_stop_skip_reasons() as $p) {
        $html .= '<option value="' . e($p) . '">' . e($p) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="mb-0"><label class="form-label small mb-1" for="' . e($modalId) . '-note">Note</label>';
    $html .= '<input class="form-control" id="' . e($modalId) . '-note" name="reason_detail" placeholder="Required if Other">';
    $html .= '</div></div>';
    $html .= '<div class="modal-footer border-0 pt-0">';
    $html .= '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
    $html .= '<button class="btn stop-skip-open">Skip this stop</button>';
    $html .= '</div></form></div></div></div>';
    return $html;
}

/**
 * Guide form + admin proof list for one day’s stops.
 *
 * @param list<array<string,mixed>> $stops
 * @param array<int, array<string,mixed>> $checkins
 */
function booking_stop_checkin_panel_html(array $booking, array $stops, array $opts = []): string
{
    $mode = (string) ($opts['mode'] ?? 'admin');
    $date = normalize_tour_date($opts['date'] ?? null) ?: (function_exists('app_today') ? app_today() : date('Y-m-d'));
    $checkins = is_array($opts['checkins'] ?? null) ? $opts['checkins'] : [];
    foreach (booking_stop_skips_map((int) ($booking['id'] ?? 0)) as $skipId => $skipRow) {
        if (!isset($checkins[$skipId])) {
            $checkins[$skipId] = $skipRow;
        }
    }
    $canSubmit = !empty($opts['can_submit']);
    $onePin = !empty($opts['one_pin']);
    $returnTo = trim((string) ($opts['return_to'] ?? ''));
    $today = function_exists('app_today') ? app_today() : date('Y-m-d');
    $isToday = $date === $today;
    $total = count($stops);
    $done = 0;
    foreach ($stops as $s) {
        if (isset($checkins[(int) ($s['id'] ?? 0)])) {
            $done++;
        }
    }

    $starts = [];
    $tourStarted = $onePin && booking_tour_is_started($booking);
    if ($onePin) {
        $meetup = booking_checkin_meetup($booking, $date);
        array_unshift($stops, [
            'id' => 0,
            'stop_name' => (string) $meetup['title'],
            'stop_location' => 'Meetup',
            'start_time' => $meetup['time'],
            'is_meetup' => 1,
        ]);
        $starts = booking_stop_starts_map((int) ($booking['id'] ?? 0), $date);
        if ($tourStarted) {
            foreach ($stops as $s) {
                $sid = (int) ($s['id'] ?? 0);
                if (!isset($checkins[$sid])) {
                    $nextId = $sid;
                    break;
                }
            }
        }
    }
    if (!isset($nextId)) {
        $nextId = -1;
    }
    $allDone = $onePin && $tourStarted && $nextId < 0 && $stops !== [];
    foreach ($stops as $s) {
        if (!isset($checkins[(int) ($s['id'] ?? 0)])) {
            $allDone = false;
            break;
        }
    }

    $html = '<div class="stop-checkin' . ($onePin ? ' stop-checkin--one' : '') . '" data-checkin-stops>';
    if (!$onePin) {
        $html .= '<div class="stop-checkin__head">';
        $html .= '<div><strong>Stop check-in</strong>';
        $html .= '<div class="small text-muted">Photo proof that the guide is at each planned stop</div></div>';
        $html .= '<span class="badge bg-teal-soft">' . (int) $done . ' / ' . (int) $total . ' checked in</span>';
        $html .= '</div>';
    } elseif ($allDone && booking_day_is_ended((int) ($booking['id'] ?? 0), $date)) {
        $html .= '<article class="stop-checkin__item is-on" data-stop-id="end" data-checkin-next="1">';
        $html .= '<div class="fw-semibold">This day is ended</div>';
        $html .= '<p class="small text-muted mt-1 mb-0">Later days stay on the tour.</p>';
        $html .= '</article>';
    } elseif ($allDone && $canSubmit) {
        $html .= booking_stop_checkin_end_tour_html($booking, $stops, $checkins, $returnTo, $date);
    } else {
        $html .= '<div class="stop-checkin__empty" data-checkin-empty>Click a stop pin to check in.</div>';
        if (!$tourStarted && $canSubmit && $isToday && ($booking['status'] ?? '') === 'approved') {
            $html .= '<article class="stop-checkin__item" data-stop-id="tour" data-checkin-next="1">';
            $html .= '<div class="fw-semibold">Start tour</div>';
            $html .= '<p class="small text-muted mt-1 mb-2">Start the tour first. After that, check in at the meetup, then each stop.</p>';
            $html .= '<form method="post" action="' . e(rtrim(APP_URL, '/') . '/tourguide/checkin.php') . '" class="stop-checkin__form">';
            $html .= csrf_field();
            $html .= '<input type="hidden" name="action" value="start_tour">';
            $html .= '<input type="hidden" name="booking_id" value="' . (int) ($booking['id'] ?? 0) . '">';
            if ($returnTo !== '') {
                $html .= '<input type="hidden" name="return" value="' . e($returnTo) . '">';
            }
            $html .= '<button class="btn btn-sm btn-ct" type="submit">Start tour</button>';
            $html .= '</form></article>';
        }
    }

    if ($mode === 'guide' && !$isToday) {
        if ($date > $today) {
            $html .= '<div class="alert alert-light border py-2 small mb-2">Photo check-in opens on the tour day ('
                . e(format_date($date)) . ').</div>';
        } else {
            $html .= '<div class="alert alert-light border py-2 small mb-2">Check-in for this day is closed. Photos already submitted stay on file.</div>';
        }
    } elseif ($mode === 'guide' && $isToday && ($booking['status'] ?? '') !== 'approved') {
        $html .= '<div class="alert alert-light border py-2 small mb-2">Check-in starts after the booking is approved.</div>';
    }

    if (!$stops) {
        $html .= '<div class="small text-muted">No planned stops for this day. Meetup-only days do not use photo check-in.</div></div>';
        return $html;
    }

    foreach ($stops as $stop) {
        $sid = (int) ($stop['id'] ?? 0);
        $name = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop'));
        if ($name === '') {
            $name = 'Stop';
        }
        $loc = trim((string) ($stop['stop_location'] ?? $stop['location'] ?? ''));
        $row = $checkins[$sid] ?? null;
        $isNext = $onePin && $sid === $nextId;
        $isLocked = $onePin && $row === null && !$isNext;
        $html .= '<article class="stop-checkin__item" id="stop-' . $sid . '" data-stop-id="' . $sid . '"'
            . ($isNext ? ' data-checkin-next="1"' : '') . '>';
        $html .= '<div class="fw-semibold">' . e($name) . '</div>';
        $html .= '<div class="small text-muted">';
        if (!empty($stop['start_time']) || !empty($stop['end_time'])) {
            $html .= e(format_time_12h($stop['start_time'] ?? null));
            if (!empty($stop['end_time'])) {
                $html .= ' – ' . e(format_time_12h($stop['end_time']));
            }
            $html .= ' · ';
        }
        $html .= e($loc !== '' ? $loc : 'Ormoc area');
        $html .= '</div>';

        if (is_array($row) && !empty($row['skipped'])) {
            $when = format_date($row['skipped_at'] ?? '', 'M j, g:i A');
            $html .= '<div class="small mt-1"><span class="badge text-bg-warning">Skipped</span> '
                . e((string) ($row['reason'] ?? ''))
                . ($when !== '' && $when !== '—' ? ' · ' . e($when) : '')
                . '</div>';
        } elseif (is_array($row)) {
            $photoUrl = booking_stop_checkin_photo_url($row);
            $when = format_date($row['checked_in_at'] ?? '', 'M j, g:i A');
            $who = trim((string) ($row['guide_name'] ?? 'Tour guide'));
            if ($photoUrl) {
                $html .= '<a class="stop-checkin__shot" href="' . e($photoUrl) . '" target="_blank" rel="noopener">';
                $html .= '<img src="' . e($photoUrl) . '" alt="Check-in photo at ' . e($name) . '">';
                $html .= '</a>';
            }
            $html .= '<div class="small mt-1"><span class="badge text-bg-success">Checked in</span> '
                . e($when) . ($who !== '' ? ' · ' . e($who) : '') . '</div>';
        } elseif ($onePin && !$tourStarted) {
            $html .= '<div class="small text-muted mt-2">Start the tour first. Check-in at the meetup comes after that.</div>';
        } elseif ($isLocked) {
            $html .= '<div class="small text-muted mt-2">Check in the earlier stop first. You can start this one as soon as that check-in is done.</div>';
        } elseif ($canSubmit && ($isNext || !$onePin) && booking_checkin_needs_early_start(
            isset($stop['start_time']) ? (string) $stop['start_time'] : null,
            (int) ($booking['id'] ?? 0),
            $sid,
            $date,
            $starts
        )) {
            $waitUntil = booking_checkin_wait_label(isset($stop['start_time']) ? (string) $stop['start_time'] : null);
            $html .= '<div class="stop-checkin__early">';
            $html .= '<p class="small text-muted mt-2 mb-2">This stop is scheduled at ' . e($waitUntil)
                . '. If you arrived early, start the stop, then check in with a photo.</p>';
            $html .= '<div class="stop-checkin__actions">';
            $html .= booking_stop_start_controls_html($booking, $sid, $returnTo);
            $html .= booking_stop_skip_controls_html($booking, $sid, $name, $returnTo, $date);
            $html .= '</div>';
            $html .= '</div>';
        } elseif ($canSubmit && $sid === 0 && ($isNext || !$onePin)) {
            $formId = 'stopCheckin-' . (int) ($booking['id'] ?? 0) . '-' . $sid;
            $code = strtoupper((string) ($booking['booking_code'] ?? ''));
            $html .= '<form id="' . e($formId) . '" method="post" action="' . e(rtrim(APP_URL, '/') . '/tourguide/checkin.php')
                . '" enctype="multipart/form-data" class="stop-checkin__form mt-2" data-meetup-ticket'
                . ' data-booking-id="' . (int) ($booking['id'] ?? 0) . '" data-booking-code="' . e($code) . '">';
            $html .= csrf_field();
            $html .= '<input type="hidden" name="action" value="checkin">';
            $html .= '<input type="hidden" name="booking_id" value="' . (int) ($booking['id'] ?? 0) . '">';
            $html .= '<input type="hidden" name="itinerary_item_id" value="0">';
            $html .= '<input type="hidden" name="ticket_scan" value="" data-ticket-scan>';
            if ($returnTo !== '') {
                $html .= '<input type="hidden" name="return" value="' . e($returnTo) . '">';
            }
            $html .= '<p class="small text-muted mb-2">Scan the tourist\'s ticket QR. A ticket for a different tour is rejected.</p>';
            $html .= '<div class="d-flex flex-wrap gap-2 mb-2">';
            $html .= '<button class="btn btn-sm btn-ct" type="button" data-ticket-scan-start><i class="bi bi-qr-code-scan"></i> Scan ticket</button>';
            $html .= '</div>';
            $html .= '<label class="form-label small mb-1" for="' . e($formId) . 'Code">Or type the booking code</label>';
            $html .= '<input class="form-control form-control-sm mb-2" id="' . e($formId) . 'Code" data-ticket-code placeholder="CTxxxxxxxx" maxlength="20" autocomplete="off">';
            $html .= '<div class="small mb-2" data-ticket-status></div>';
            $html .= '<label class="form-label small mb-1">Photo at meetup</label>';
            $html .= '<input class="form-control form-control-sm" type="file" name="photo" accept="image/*" capture="environment" required>';
            $html .= '<img class="stop-checkin__photo mt-2" data-preview alt="" hidden>';
            $html .= '</form>';
            $html .= '<div class="stop-checkin__actions">';
            $html .= '<button class="btn btn-sm btn-ct" type="submit" form="' . e($formId) . '"><i class="bi bi-camera"></i> Check in here</button>';
            $html .= booking_stop_skip_controls_html($booking, $sid, $name, $returnTo, $date);
            $html .= '</div>';
        } elseif ($canSubmit && $sid >= 0 && ($isNext || !$onePin)) {
            $formId = 'stopCheckin-' . (int) ($booking['id'] ?? 0) . '-' . $sid;
            $html .= '<form id="' . e($formId) . '" method="post" action="' . e(rtrim(APP_URL, '/') . '/tourguide/checkin.php')
                . '" enctype="multipart/form-data" class="stop-checkin__form mt-2">';
            $html .= csrf_field();
            $html .= '<input type="hidden" name="action" value="checkin">';
            $html .= '<input type="hidden" name="booking_id" value="' . (int) ($booking['id'] ?? 0) . '">';
            $html .= '<input type="hidden" name="itinerary_item_id" value="' . $sid . '">';
            if ($returnTo !== '') {
                $html .= '<input type="hidden" name="return" value="' . e($returnTo) . '">';
            }
            $html .= '<label class="form-label small mb-1">Photo at this stop</label>';
            $html .= '<input class="form-control form-control-sm" type="file" name="photo" accept="image/*" capture="environment" required>';
            $html .= '<img class="stop-checkin__photo mt-2" data-preview alt="" hidden>';
            $html .= '</form>';
            $html .= '<div class="stop-checkin__actions">';
            $html .= '<button class="btn btn-sm btn-ct" type="submit" form="' . e($formId) . '"><i class="bi bi-camera"></i> Check in here</button>';
            $html .= booking_stop_skip_controls_html($booking, $sid, $name, $returnTo, $date);
            $html .= '</div>';
        } else {
            $html .= '<div class="small text-muted mt-1">Not checked in yet.</div>';
        }
        $html .= '</article>';
    }

    $html .= '</div>';
    return $html;
}
