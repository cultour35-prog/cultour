<?php
/**
 * Tour guide schedule calendar — itinerary name + Day N, with full itinerary details.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_booking_payment_schema();
ensure_itinerary_items_schema();
ensure_booking_stop_checkin_schema();

$pageTitle = 'Schedule';
$pageHeading = 'My schedule';
$pageSub = 'Click a tour to see the itinerary stops by day';
$activeNav = 'schedule';

$profile = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([$user['id']]);
$gid = (int) $profile->fetchColumn();

$ym = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
$monthStart = $ym . '-01';
$monthTs = strtotime($monthStart . ' 12:00:00');
$daysInMonth = (int) date('t', $monthTs);
$year = (int) date('Y', $monthTs);
$month = (int) date('n', $monthTs);
$firstDow = (int) date('w', $monthTs);
$prevYm = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextYm = date('Y-m', strtotime($monthStart . ' +1 month'));
$monthLabel = date('F Y', $monthTs);

$rangeFrom = date('Y-m-d', strtotime($monthStart . ' -14 days'));
$rangeTo = date('Y-m-d', strtotime($monthStart . ' +1 month +14 days'));

$stmt = db()->prepare(
    "SELECT b.id, b.booking_code, b.booking_date, b.tour_start_date, b.tour_end_date, b.start_time, b.status, b.party_size, b.itinerary_id, b.notes AS booking_notes,
            b.guide_id, b.original_guide_id, b.replacement_from_date, b.needs_replacement,
            b.is_pwd, b.pwd_notes, b.fitness_certificate_path,
            i.title AS itinerary_title, i.description AS itinerary_description,
            i.start_date AS itin_start, i.end_date AS itin_end, i.meetup_time AS itin_meetup,
            CONCAT(u.first_name,' ',u.last_name) AS tourist_name
     FROM bookings b
     JOIN users u ON u.id = b.tourist_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     WHERE (b.guide_id = ? OR b.original_guide_id = ?)
       AND b.status IN ('approved','completed')
       AND (
         DATE(COALESCE(b.tour_start_date, i.start_date, b.booking_date)) <= ?
         AND DATE(COALESCE(b.tour_end_date, b.tour_start_date, i.end_date, i.start_date, b.booking_date)) >= ?
       )
     ORDER BY COALESCE(b.tour_start_date, i.start_date, b.booking_date), b.start_time"
);
$stmt->execute([$gid, $gid, $rangeTo, $rangeFrom]);
$bookings = $stmt->fetchAll() ?: [];
$bookingsById = [];
foreach ($bookings as $b) {
    $bookingsById[(int) $b['id']] = $b;
}

/** @var array<string, true> $endedDayKeys booking id + visit date */
$endedDayKeys = [];
if ($bookingsById) {
    $endIds = array_map('intval', array_keys($bookingsById));
    $endPh = implode(',', array_fill(0, count($endIds), '?'));
    try {
        $endSt = db()->prepare('SELECT booking_id, visit_date FROM booking_day_ends WHERE booking_id IN (' . $endPh . ')');
        $endSt->execute($endIds);
        foreach ($endSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $er) {
            $endDate = normalize_tour_date((string) ($er['visit_date'] ?? ''));
            if ($endDate !== null) {
                $endedDayKeys[(int) $er['booking_id'] . '|' . $endDate] = true;
            }
        }
    } catch (Throwable $e) {
        $endedDayKeys = [];
    }
}

/** @var array<string, list<array>> $byDate */
$byDate = [];
$clockToday = app_today();
foreach ($bookings as $b) {
    [$start, $end] = booking_effective_tour_span($b);
    $itinId = (int) ($b['itinerary_id'] ?? 0);
    $itinRow = $itinId > 0 ? itinerary_row_by_id($itinId) : [];
    $dates = $itinId > 0 ? itinerary_visit_dates_list($itinId, $itinRow ?: null) : [];
    if (!$dates) {
        $dayCount = itinerary_day_count($start, $end);
        for ($d = 1; $d <= $dayCount; $d++) {
            $date = itinerary_date_for_day($start, $d);
            if ($date !== null) {
                $dates[] = $date;
            }
        }
    }
    $dayCount = max(1, count($dates));
    $title = trim((string) ($b['itinerary_title'] ?? ''));
    if ($title === '') {
        $title = 'Tour with ' . trim((string) ($b['tourist_name'] ?? 'tourist'));
    }
    $status = (string) ($b['status'] ?? '');
    $bid = (int) $b['id'];
    $covered = [];
    foreach ($dates as $date) {
        if ($date === null || $date === '') {
            continue;
        }
        if (!booking_guide_covers_date($b, $gid, $date)) {
            continue;
        }
        $covered[] = $date;
    }
    $endedCount = 0;
    $started = false;
    foreach ($covered as $date) {
        if ($status === 'completed' || isset($endedDayKeys[$bid . '|' . $date])) {
            $endedCount++;
        }
        if ($date <= $clockToday) {
            $started = true;
        }
    }
    if ($status === 'pending') {
        $tone = 'pending';
    } elseif ($status === 'completed' || ($covered !== [] && $endedCount === count($covered))) {
        $tone = 'completed';
    } elseif ($started || $endedCount > 0) {
        $tone = 'ongoing';
    } else {
        $tone = 'upcoming';
    }
    foreach ($dates as $di => $date) {
        $d = $di + 1;
        if ($date === null || substr($date, 0, 7) !== $ym) {
            continue;
        }
        if (!booking_guide_covers_date($b, $gid, $date)) {
            continue;
        }
        $busyWin = booking_busy_window_for_date($b, $date);
        $busyLabel = booking_busy_window_label($busyWin);
        $dayEnded = (string) ($b['status'] ?? '') === 'completed'
            || isset($endedDayKeys[(int) $b['id'] . '|' . $date]);
        $byDate[$date][] = [
            'title' => $title,
            'day' => $d,
            'day_count' => $dayCount,
            'status' => $status,
            'tone' => $tone,
            'day_ended' => $dayEnded,
            'tourist' => (string) ($b['tourist_name'] ?? ''),
            'code' => (string) ($b['booking_code'] ?? ''),
            'time' => format_time_12h($b['start_time'] ?? null),
            'busy_label' => $busyLabel,
            'booking_id' => (int) $b['id'],
            'itinerary_id' => (int) ($b['itinerary_id'] ?? 0),
            'tour_start' => $start,
            'tour_end' => $end,
        ];
    }
}

$selected = trim((string) ($_GET['date'] ?? ''));
if ($selected !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected)) {
    $selected = '';
}
if ($selected === '' || substr($selected, 0, 7) !== $ym) {
    $today = app_today();
    if (substr($today, 0, 7) === $ym) {
        $selected = $today;
    } elseif ($byDate) {
        $keys = array_keys($byDate);
        sort($keys);
        $selected = $keys[0];
    } else {
        $selected = $monthStart;
    }
}
$selectedEvents = $byDate[$selected] ?? [];

$focusBookingId = (int) ($_GET['booking'] ?? 0);
if ($focusBookingId > 0 && !isset($bookingsById[$focusBookingId])) {
    // Allow opening a booking even if outside the month query window
    $extra = db()->prepare(
        "SELECT b.id, b.booking_code, b.booking_date, b.tour_start_date, b.tour_end_date, b.start_time, b.status, b.party_size, b.itinerary_id, b.notes AS booking_notes,
                b.guide_id, b.original_guide_id, b.replacement_from_date, b.needs_replacement,
                b.is_pwd, b.pwd_notes, b.fitness_certificate_path,
                i.title AS itinerary_title, i.description AS itinerary_description,
                i.start_date AS itin_start, i.end_date AS itin_end, i.meetup_time AS itin_meetup,
                CONCAT(u.first_name,' ',u.last_name) AS tourist_name
         FROM bookings b
         JOIN users u ON u.id = b.tourist_id
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         WHERE b.id=? AND (b.guide_id=? OR b.original_guide_id=?) AND b.status IN ('approved','completed')
         LIMIT 1"
    );
    $extra->execute([$focusBookingId, $gid, $gid]);
    $extraRow = $extra->fetch();
    if ($extraRow) {
        $bookingsById[$focusBookingId] = $extraRow;
    } else {
        $focusBookingId = 0;
    }
}
if ($focusBookingId < 1 && $selectedEvents) {
    $focusBookingId = (int) $selectedEvents[0]['booking_id'];
}

$detail = $focusBookingId > 0 ? ($bookingsById[$focusBookingId] ?? null) : null;
$detailStopsByDay = [];
$detailTitle = '';
$detailStart = null;
$detailEnd = null;
$detailDayCount = 1;
$detailFocusDay = 1;
$dayGroup = null;

if ($detail) {
    [$detailStart, $detailEnd] = booking_effective_tour_span($detail);
    $itinId = (int) ($detail['itinerary_id'] ?? 0);
    $itinRow = $itinId > 0 ? itinerary_row_by_id($itinId) : [];
    $detailDayCount = $itinRow
        ? itinerary_logical_day_count($itinRow)
        : itinerary_day_count($detailStart, $detailEnd);
    $detailTitle = trim((string) ($detail['itinerary_title'] ?? ''));
    if ($detailTitle === '') {
        $detailTitle = 'Tour with ' . trim((string) ($detail['tourist_name'] ?? 'tourist'));
    }
    $detailFocusDay = itinerary_day_number($detailStart, $selected);
    if ($detailFocusDay > $detailDayCount) {
        $detailFocusDay = 1;
    }
    if ($itinId > 0) {
        $stops = itinerary_items_with_places($itinId);
        $detailStopsByDay = itinerary_group_stops_by_day($stops, $detailStart, $detailEnd, $itinId, $itinRow ?: null);
        foreach ($detailStopsByDay as $g) {
            if ((string) ($g['date'] ?? '') === $selected) {
                $dayGroup = $g;
                $detailFocusDay = (int) $g['day'];
                break;
            }
        }
        if (!$dayGroup) {
            $dayGroup = $detailStopsByDay[$detailFocusDay] ?? null;
        }
    }
}

$clockToday = app_today();

$guideScheduleToneLabel = [
    'upcoming' => 'Upcoming',
    'ongoing' => 'Ongoing',
    'completed' => 'Completed',
    'pending' => 'Pending',
];

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div class="row g-3 guide-sched-row" data-live-self="guide-schedule">
        <div class="col-lg-7 guide-sched-cal-col">
          <div class="glass p-3 p-md-4 guide-cal">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
              <a class="btn btn-sm btn-outline-secondary" href="?month=<?= e($prevYm) ?>">&larr; Prev</a>
              <h2 class="h5 mb-0"><?= e($monthLabel) ?></h2>
              <a class="btn btn-sm btn-outline-secondary" href="?month=<?= e($nextYm) ?>">Next &rarr;</a>
            </div>
            <div class="guide-cal__weekdays">
              <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?>
                <div class="guide-cal__wd"><?= $wd ?></div>
              <?php endforeach; ?>
            </div>
            <div class="guide-cal__grid">
              <?php for ($i = 0; $i < $firstDow; $i++): ?>
                <div class="guide-cal__cell is-empty"></div>
              <?php endfor; ?>
              <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                  $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                  $events = $byDate[$date] ?? [];
                  $isToday = $date === $clockToday;
                  $isSelected = $date === $selected;
                  $classes = 'guide-cal__cell';
                  if ($events) {
                      $classes .= ' has-events';
                  }
                  if ($isToday) {
                      $classes .= ' is-today';
                  }
                  if ($isSelected) {
                      $classes .= ' is-selected';
                  }
                  $cellEnded = false;
                  foreach ($events as $evEnded) {
                      if (!empty($evEnded['day_ended'])) {
                          $cellEnded = true;
                          break;
                      }
                  }
                  if ($cellEnded) {
                      $classes .= ' is-ended';
                  }
                  $cellBooking = $events ? (int) $events[0]['booking_id'] : 0;
                  $cellHref = '?month=' . rawurlencode($ym) . '&date=' . rawurlencode($date)
                      . ($cellBooking ? '&booking=' . $cellBooking : '');
                ?>
                <a class="<?= $classes ?>" href="<?= e($cellHref) ?>">
                  <div class="guide-cal__numrow">
                    <div class="guide-cal__num"><?= $day ?></div>
                    <?php if ($cellEnded): ?>
                      <span class="guide-cal__done" title="Day done"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span class="visually-hidden"> Day done</span></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($events): ?>
                    <div class="guide-cal__chips">
                      <?php foreach (array_slice($events, 0, 3) as $ev): ?>
                        <?php $chipStatus = (string) ($ev['tone'] ?? 'upcoming'); ?>
                        <div class="guide-cal__chip guide-cal__chip--<?= e($chipStatus) ?>" title="<?= e($ev['title'] . ' · Day ' . $ev['day'] . (!empty($ev['busy_label']) ? ' · ' . $ev['busy_label'] : '') . ' · ' . ($guideScheduleToneLabel[$chipStatus] ?? ucfirst($chipStatus))) ?>">
                          <span class="guide-cal__chip-title"><?= e($ev['title']) ?></span>
                          <span class="guide-cal__chip-day">Day <?= (int) $ev['day'] ?><?= $ev['day_count'] > 1 ? '/' . (int) $ev['day_count'] : '' ?><?php if (!empty($ev['busy_label'])): ?> · <?= e($ev['busy_label']) ?><?php endif; ?></span>
                        </div>
                      <?php endforeach; ?>
                      <?php if (count($events) > 3): ?>
                        <div class="guide-cal__more">+<?= count($events) - 3 ?> more</div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </a>
              <?php endfor; ?>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-3 small text-muted">
              <span><span class="guide-cal__legend guide-cal__legend--upcoming"></span> Upcoming</span>
              <span><span class="guide-cal__legend guide-cal__legend--ongoing"></span> Ongoing</span>
              <span><span class="guide-cal__legend guide-cal__legend--completed"></span> Completed</span>
            </div>
          </div>
        </div>

        <div class="col-lg-5 guide-sched-side">
          <div class="glass p-3 p-md-4 mb-3 guide-sched-day">
            <div class="small text-muted mb-1">Selected day</div>
            <h3 class="h5 mb-3"><?= e(format_date($selected)) ?></h3>
            <?php if (!$selectedEvents): ?>
              <div class="guide-sched-day__list text-muted">No scheduled itinerary days on this date.</div>
            <?php else: ?>
              <div class="d-grid gap-2 guide-sched-day__list">
                <?php foreach ($selectedEvents as $ev): ?>
                  <?php
                    $isFocus = (int) $ev['booking_id'] === $focusBookingId;
                    $dayStatus = (string) ($ev['tone'] ?? 'upcoming');
                  ?>
                  <a
                    class="guide-cal__detail guide-cal__detail--<?= e($dayStatus) ?> text-decoration-none<?= $isFocus ? ' is-focus' : '' ?>"
                    href="?month=<?= e($ym) ?>&date=<?= e($selected) ?>&booking=<?= (int) $ev['booking_id'] ?>"
                  >
                    <div class="fw-semibold text-body"><?= e($ev['title']) ?></div>
                    <div class="small mt-1 text-body">
                      <strong>Day <?= (int) $ev['day'] ?></strong>
                      <?php if ($ev['day_count'] > 1): ?> of <?= (int) $ev['day_count'] ?><?php endif; ?>
                      · <?= e($guideScheduleToneLabel[$dayStatus] ?? ucfirst($dayStatus)) ?>
                    </div>
                    <div class="small text-muted mt-1">
                      <?= e($ev['tourist']) ?>
                      <?php if (!empty($ev['busy_label'])): ?>
                        · Busy <?= e($ev['busy_label']) ?>
                      <?php elseif ($ev['time']): ?>
                        · Meetup <?= e($ev['time']) ?>
                      <?php endif; ?>
                      · <code><?= e($ev['code']) ?></code>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($detail): ?>
            <div class="glass p-3 p-md-4 guide-cal-itin" id="itinDetail">
              <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-2">
                <div>
                  <div class="small text-muted">Itinerary details</div>
                  <h3 class="h5 mb-0"><?= e($detailTitle) ?></h3>
                </div>
                <?= status_badge((string) $detail['status']) ?>
              </div>

              <div class="small text-muted mb-2">
                Showing <strong>Day <?= (int) $detailFocusDay ?></strong>
                <?php if ($detailDayCount > 1): ?>
                  of <?= (int) $detailDayCount ?>
                <?php endif; ?>
                for <?= e(format_date($selected)) ?>
                · Meetup <?= e(format_time_12h($detail['start_time'] ?? $detail['itin_meetup'] ?? null)) ?>
                · <?= (int) ($detail['party_size'] ?? 1) ?> guest(s)
              </div>
              <div class="small mb-3">
                Tourist: <strong><?= e($detail['tourist_name'] ?? '') ?></strong>
                · Code <code><?= e($detail['booking_code']) ?></code>
              </div>

              <?php if (($schedItinDesc = itinerary_display_description($detail['itinerary_description'] ?? null)) !== ''): ?>
                <p class="small text-muted"><?= e($schedItinDesc) ?></p>
              <?php endif; ?>
              <?php if (!empty($detail['booking_notes'])): ?>
                <div class="alert alert-light border py-2 small mb-3">
                  <strong>Tourist notes:</strong> <?= e($detail['booking_notes']) ?>
                </div>
              <?php endif; ?>
              <?= booking_accessibility_html($detail) ?>

              <?php if (!(int) ($detail['itinerary_id'] ?? 0)): ?>
                <div class="text-muted small">This booking has no linked itinerary stops.</div>
              <?php elseif (!$detailStopsByDay): ?>
                <div class="text-muted small">Itinerary has no stops yet.</div>
              <?php else: ?>
                <div class="d-grid gap-3">
                  <?php if (!$dayGroup): ?>
                    <div class="text-muted small">No itinerary day matched this date.</div>
                  <?php else: ?>
                    <section class="guide-cal-itin__day is-current">
                      <div class="guide-cal-itin__day-head">
                        <strong><?= e($dayGroup['label']) ?></strong>
                        <span class="text-muted"><?= e(format_date($dayGroup['date'] ?? $selected)) ?></span>
                        <?= booking_day_checkin_summary_html($detail, $dayGroup['stops'] ?? [], (string) ($dayGroup['date'] ?? $selected)) ?>
                        <?php if ($selected < $clockToday && in_array((string) ($detail['status'] ?? ''), ['approved', 'completed'], true)): ?>
                          <span class="itin-day-done"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Completed</span>
                        <?php endif; ?>
                      </div>
                      <?php if (empty($dayGroup['stops'])): ?>
                        <div class="small text-muted px-1 py-1">No stops planned for this day.</div>
                      <?php else: ?>
                        <ol class="guide-cal-itin__stops">
                          <?php foreach ($dayGroup['stops'] as $stop): ?>
                            <li>
                              <div class="fw-semibold"><?= e($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop') ?></div>
                              <div class="small text-muted">
                                <?php if (!empty($stop['start_time']) || !empty($stop['end_time'])): ?>
                                  <?= e(format_time_12h($stop['start_time'] ?? null)) ?>
                                  <?php if (!empty($stop['end_time'])): ?>
                                    – <?= e(format_time_12h($stop['end_time'])) ?>
                                  <?php endif; ?>
                                  ·
                                <?php endif; ?>
                                <?= e($stop['stop_location'] ?? $stop['location'] ?? '') ?: 'Ormoc area' ?>
                                <?php if (!empty($stop['visit_minutes'])): ?>
                                  · <?= (int) $stop['visit_minutes'] ?> min
                                <?php endif; ?>
                              </div>
                              <?php if (!empty($stop['notes'])): ?>
                                <div class="small"><?= e($stop['notes']) ?></div>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ol>
                      <?php endif; ?>
                    </section>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="d-flex flex-wrap gap-2 mt-3 guide-cal-actions">
                <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/tourguide/bookings.php">Open booking</a>
                <?php if ($selected === $clockToday && ($detail['status'] ?? '') === 'approved'): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/tourguide/checkin.php#booking-<?= (int) $detail['id'] ?>">Stop check-in</a>
                <?php endif; ?>
                <?php if ((int) ($detail['itinerary_id'] ?? 0) > 0): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/itinerary-share.php?id=<?= (int) $detail['itinerary_id'] ?>" target="_blank" rel="noopener">View itinerary</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<script>
function parkBookingDetailsModals() {
  document.querySelectorAll('body > [data-booking-details-modal]').forEach(function (el) {
    el.remove();
  });
  document.querySelectorAll('[data-booking-details-modal]').forEach(function (el) {
    document.body.appendChild(el);
  });
}
parkBookingDetailsModals();
document.addEventListener('cultour:live-replaced', parkBookingDetailsModals);
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
