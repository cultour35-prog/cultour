<?php
/**
 * Live poll payloads for CulTour list/dashboard pages.
 * GET-only, no notification side effects.
 */

require_once __DIR__ . '/content_reports.php';
require_once __DIR__ . '/business_engagement.php';

function live_unauth(): never
{
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

function live_forbidden(): never
{
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}

function live_changed_payload(string $version, string $since, array $extra): array
{
    $out = array_merge(['ok' => true, 'version' => $version], $extra);
    if ($since !== '' && hash_equals($version, $since)) {
        $out['changed'] = false;
        unset($out['html'], $out['slots']);
        return $out;
    }
    $out['changed'] = true;
    return $out;
}

function live_html_partial(string $file, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require ROOT_PATH . '/includes/partials/' . $file;
    return (string) ob_get_clean();
}

function live_booking_rows_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $cid = (int) ($r['id'] ?? 0);
        $cin = '';
        if ($cid > 0 && function_exists('booking_stop_checkins_map')) {
            foreach (booking_stop_checkins_map($cid) as $itemId => $row) {
                $cin .= $itemId . ':' . ($row['checked_in_at'] ?? '') . ':' . ($row['photo_path'] ?? '') . ';';
            }
        }
        $parts[] = $cid
            . ':' . ($r['status'] ?? '')
            . ':' . ($r['payment_status'] ?? '')
            . ':' . ($r['payment_reference'] ?? '')
            . ':' . ($r['guide_id'] ?? '')
            . ':' . ($r['needs_replacement'] ?? '')
            . ':' . ($r['replacement_state'] ?? '')
            . ':' . ($r['replacement_candidate_id'] ?? '')
            . ':' . ($r['replacement_from_date'] ?? '')
            . ':' . ($r['rejection_reason'] ?? '')
            . ':' . ($r['refund_status'] ?? '')
            . ':' . ($r['rated'] ?? '')
            . ':' . ($r['checked_in_at'] ?? '')
            . ':' . ($r['tour_started_at'] ?? '')
            . ':' . $cin;
    }
    return md5(implode('|', $parts) . '|n=' . count($parts));
}

function live_tourist_bookings_fetch(int $touristId): array
{
    ensure_booking_refund_schema();
    $rows = db()->prepare(
        "SELECT b.*,
                CONCAT(u.first_name,' ',u.last_name) AS guide_name,
                CONCAT(ou.first_name,' ',ou.last_name) AS original_guide_name,
                d.name AS destination_name,
                i.title AS itinerary_title,
                (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.booking_id=b.id AND gr.tourist_id=b.tourist_id) AS rated
         FROM bookings b
         JOIN tour_guide_profiles gp ON gp.id=b.guide_id
         JOIN users u ON u.id=gp.user_id
         LEFT JOIN tour_guide_profiles ogp ON ogp.id=b.original_guide_id
         LEFT JOIN users ou ON ou.id=ogp.user_id
         LEFT JOIN destinations d ON d.id=b.destination_id
         LEFT JOIN itineraries i ON i.id=b.itinerary_id
         WHERE b.tourist_id=? ORDER BY b.created_at DESC"
    );
    $rows->execute([$touristId]);
    return $rows->fetchAll() ?: [];
}

function live_tourist_bookings_payload(int $touristId, string $since = ''): array
{
    $rows = live_tourist_bookings_fetch($touristId);
    $version = live_booking_rows_version($rows);
    $html = live_html_partial('tourist_bookings_list.php', ['rows' => $rows]);
    return live_changed_payload($version, $since, [
        'html' => $html,
        'pending' => (int) array_sum(array_map(
            static fn ($r) => (($r['status'] ?? '') === 'pending' ? 1 : 0),
            $rows
        )),
    ]);
}

/**
 * @return array{0:string,1:string} sort key and asc|desc
 */
function admin_bookings_sort_params(string $sort, string $dir): array
{
    $allowed = ['date', 'tourist', 'guide', 'code', 'itinerary', 'status', 'payment'];
    $sort = in_array($sort, $allowed, true) ? $sort : 'date';
    $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
    return [$sort, $dir];
}

function admin_bookings_sort_href(string $key, string $active, string $dir, string $paymentFilter): string
{
    [$active, $dir] = admin_bookings_sort_params($active, $dir);
    $nextDir = ($active === $key && $dir === 'asc') ? 'desc' : 'asc';
    $q = ['sort' => $key, 'dir' => $nextDir];
    if ($paymentFilter === 'pending') {
        $q = ['payment' => 'pending'] + $q;
    }
    return APP_URL . '/admin/bookings.php?' . http_build_query($q);
}

function live_admin_bookings_fetch(string $paymentFilter, string $sort = 'date', string $dir = 'desc'): array
{
    ensure_booking_payment_schema();
    [$sort, $dir] = admin_bookings_sort_params($sort, $dir);
    $sqlDir = $dir === 'desc' ? 'DESC' : 'ASC';
    $sql = "SELECT b.*, CONCAT(t.first_name,' ',t.last_name) AS tourist_name,
            CONCAT(gu.first_name,' ',gu.last_name) AS guide_name,
            CONCAT(ou.first_name,' ',ou.last_name) AS original_guide_name,
            i.title AS itinerary_title
     FROM bookings b
     JOIN users t ON t.id = b.tourist_id
     JOIN tour_guide_profiles gp ON gp.id = b.guide_id
     JOIN users gu ON gu.id = gp.user_id
     LEFT JOIN tour_guide_profiles ogp ON ogp.id = b.original_guide_id
     LEFT JOIN users ou ON ou.id = ogp.user_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id";
    if ($paymentFilter === 'pending') {
        $sql .= " WHERE b.payment_status='pending_review'";
    }
    $tie = ', b.id ASC';
    switch ($sort) {
        case 'tourist':
            $order = "CONCAT(t.first_name,' ',t.last_name) $sqlDir, b.booking_date ASC, b.start_time ASC";
            break;
        case 'guide':
            $order = "CONCAT(gu.first_name,' ',gu.last_name) $sqlDir, b.booking_date ASC, b.start_time ASC";
            break;
        case 'code':
            $order = "b.booking_code $sqlDir";
            break;
        case 'itinerary':
            $order = "(i.title IS NULL OR i.title = '') ASC, i.title $sqlDir, b.booking_date ASC";
            break;
        case 'status':
            $order = "FIELD(b.status,'pending','approved','completed','rejected','cancelled') $sqlDir, b.booking_date ASC, b.start_time ASC";
            break;
        case 'payment':
            $order = "b.payment_status $sqlDir, b.booking_date ASC, b.start_time ASC";
            break;
        default:
            $order = "b.booking_date $sqlDir, COALESCE(b.start_time, '00:00:00') $sqlDir";
            break;
    }
    $sql .= ' ORDER BY ' . $order . $tie;
    return db()->query($sql)->fetchAll() ?: [];
}

function live_admin_bookings_payload(string $paymentFilter, string $since = '', string $sort = 'date', string $dir = 'desc'): array
{
    [$sort, $dir] = admin_bookings_sort_params($sort, $dir);
    $rows = live_admin_bookings_fetch($paymentFilter, $sort, $dir);
    $pendingPayCount = (int) db()->query(
        "SELECT COUNT(*) FROM bookings WHERE payment_status='pending_review'"
    )->fetchColumn();
    $version = md5(live_booking_rows_version($rows) . '|pay=' . $pendingPayCount . '|' . $paymentFilter . '|' . $sort . '|' . $dir);
    $html = live_html_partial('admin_bookings_list.php', [
        'rows' => $rows,
        'pendingPayCount' => $pendingPayCount,
        'paymentFilter' => $paymentFilter,
        'sort' => $sort,
        'sortDir' => $dir,
    ]);
    return live_changed_payload($version, $since, [
        'html' => $html,
        'pending' => $pendingPayCount,
    ]);
}

function live_tours_html(array $tours, string $date, int $focusId, bool $guideMode, int $guideProfileId = 0): string
{
    $pendingCheckins = 0;
    foreach ($tours as $tour) {
        $stops = booking_stops_on_calendar_date($tour, $date);
        if (!$stops) {
            continue;
        }
        $map = booking_stop_checkins_map((int) $tour['id']);
        foreach ($stops as $s) {
            if (!isset($map[(int) ($s['id'] ?? 0)])) {
                $pendingCheckins++;
            }
        }
    }
    ob_start();
    $today = app_today();
    if (!$guideMode): ?>
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
          <div class="glass stat-card">
            <div class="label"><?= $date === $today ? 'Tours today' : 'Tours this day' ?></div>
            <p class="value" data-stat="tours_count"><?= count($tours) ?></p>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="glass stat-card">
            <div class="label">Stops still open</div>
            <p class="value" data-stat="stops_open"><?= (int) $pendingCheckins ?></p>
          </div>
        </div>
      </div>
    <?php endif; ?>
      <?php if (!$tours): ?>
        <div class="glass p-4 <?= $guideMode ? '' : 'text-muted' ?>">
          <?php if ($guideMode): ?>
            <div class="fw-semibold mb-1">No tours to check in today</div>
            <p class="small text-muted mb-0">
              Photo check-in only works on a tour day for an approved booking assigned to you.
              Open your schedule to see upcoming stops.
            </p>
          <?php else: ?>
            No approved or completed tours on <?= e(format_date($date)) ?>.
          <?php endif; ?>
        </div>
      <?php elseif (!$guideMode): ?>
        <?php $adminModals = ''; ?>
        <div class="admin-tour-grid">
          <?php foreach ($tours as $tour): ?>
            <?php
              $bid = (int) $tour['id'];
              $title = trim((string) ($tour['itinerary_title'] ?? ''));
              if ($title === '') {
                  $title = 'Tour with ' . trim((string) ($tour['tourist_name'] ?? 'tourist'));
              }
              $stops = booking_stops_on_calendar_date($tour, $date);
              $checkins = booking_stop_progress_map($bid);
              $doneStops = 0;
              foreach ($stops as $s) {
                  if (isset($checkins[(int) ($s['id'] ?? 0)])) {
                      $doneStops++;
                  }
              }
              $modalId = 'adminTour-' . $bid;
              [$spanStart, $spanEnd] = booking_effective_tour_span($tour);
              ob_start();
            ?>
            <div class="modal fade tour-end-modal" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>-label" aria-hidden="true" data-admin-tour-modal="1">
              <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content tour-end-card">
                  <div class="modal-header border-0 pb-1">
                    <div>
                      <div class="small text-muted"><?= e(format_date($spanStart)) ?><?php if ($spanEnd !== $spanStart): ?> – <?= e(format_date($spanEnd)) ?><?php endif; ?> · <code><?= e($tour['booking_code']) ?></code></div>
                      <h2 class="h5 mb-0" id="<?= e($modalId) ?>-label"><?= e($title) ?></h2>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body pt-2">
                    <div class="mb-2">
                      <?= status_badge((string) $tour['status']) ?>
                    </div>
                    <div class="small mb-3">
                      Guide: <strong><?= e($tour['guide_name'] ?? '') ?></strong>
                      · Tourist: <strong><?= e($tour['tourist_name'] ?? '') ?></strong>
                      · Meetup <?= e(format_time_12h($tour['start_time'] ?? $tour['itin_meetup'] ?? null)) ?>
                      · <?= (int) ($tour['party_size'] ?? 1) ?> guest(s)
                    </div>
                    <?= booking_accessibility_html($tour) ?>
                    <?php
                      $visitDates = booking_visit_dates($tour);
                      if (!$visitDates) {
                          $visitDates = [$date];
                      }
                      $activeDate = in_array($date, $visitDates, true) ? $date : $visitDates[0];
                    ?>
                    <?php if (count($visitDates) > 1): ?>
                      <div class="admin-tour-days" role="tablist">
                        <?php foreach ($visitDates as $dayIndex => $visitDate): ?>
                          <?php $paneId = $modalId . '-day-' . ($dayIndex + 1); ?>
                          <button type="button" class="admin-tour-day<?= $visitDate === $activeDate ? ' is-on' : '' ?>" data-admin-day="<?= e($paneId) ?>" role="tab" aria-selected="<?= $visitDate === $activeDate ? 'true' : 'false' ?>">Day <?= (int) ($dayIndex + 1) ?></button>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <?php foreach ($visitDates as $dayIndex => $visitDate): ?>
                      <?php
                        $paneId = $modalId . '-day-' . ($dayIndex + 1);
                        $dayStops = booking_stops_on_calendar_date($tour, $visitDate);
                        $dayEnded = booking_day_is_ended($bid, $visitDate);
                      ?>
                      <div data-admin-day-pane="<?= e($paneId) ?>"<?= $visitDate === $activeDate ? '' : ' hidden' ?>>
                        <div class="small text-muted mb-2">
                          Day <?= (int) ($dayIndex + 1) ?> · <?= e(format_date($visitDate)) ?>
                          <?php if ($dayEnded): ?> · Day ended<?php endif; ?>
                        </div>
                        <?= booking_stop_checkin_panel_html($tour, $dayStops, [
                            'mode' => 'admin',
                            'date' => $visitDate,
                            'can_submit' => false,
                            'checkins' => $checkins,
                        ]) ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
              </div>
            </div>
            <?php
              $adminModals .= ob_get_clean();
            ?>
            <button type="button" class="admin-tour-card<?= $focusId === $bid ? ' is-focus' : '' ?>" data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>">
              <div class="admin-tour-card__top">
                <strong><?= e($title) ?></strong>
                <?= status_badge((string) $tour['status']) ?>
              </div>
              <div class="small text-muted"><?= e(format_date($spanStart)) ?><?php if ($spanEnd !== $spanStart): ?> – <?= e(format_date($spanEnd)) ?><?php endif; ?></div>
              <div class="small mt-1">Guide <strong><?= e($tour['guide_name'] ?? '') ?></strong> · Tourist <strong><?= e($tour['tourist_name'] ?? '') ?></strong></div>
              <div class="small text-muted mt-1"><code><?= e($tour['booking_code']) ?></code> · <?= (int) $doneStops ?> / <?= count($stops) ?> stops</div>
            </button>
          <?php endforeach; ?>
        </div>
        <?= $adminModals ?>
      <?php else: ?>
        <div class="d-grid gap-3">
          <?php foreach ($tours as $tour): ?>
            <?php
              $bid = (int) $tour['id'];
              $title = trim((string) ($tour['itinerary_title'] ?? ''));
              if ($title === '') {
                  $title = 'Tour with ' . trim((string) ($tour['tourist_name'] ?? 'tourist'));
              }
              $stops = booking_stops_on_calendar_date($tour, $date);
              $checkins = booking_stop_progress_map($bid);
              $isFocus = $focusId === $bid;
              $returnTo = APP_URL . '/tourguide/checkin.php#booking-' . $bid;
            ?>
            <section class="glass p-3 p-md-4<?= $isFocus ? ' stop-checkin--focus' : '' ?>" id="booking-<?= $bid ?>">
              <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-2">
                <div>
                  <div class="small text-muted"><?php if ($guideMode): ?>Tour day · <?php endif; ?><?= e(format_date($date)) ?><?php if (!$guideMode): ?> · <code><?= e($tour['booking_code']) ?></code><?php endif; ?></div>
                  <h2 class="h5 mb-0"><?= e($title) ?></h2>
                </div>
                <?= status_badge((string) $tour['status']) ?>
              </div>
              <div class="small mb-3">
                <?php if ($guideMode): ?>
                  Tourist: <strong><?= e($tour['tourist_name'] ?? '') ?></strong>
                  · Code <code><?= e($tour['booking_code']) ?></code>
                <?php else: ?>
                  Guide: <strong><?= e($tour['guide_name'] ?? '') ?></strong>
                  · Tourist: <strong><?= e($tour['tourist_name'] ?? '') ?></strong>
                <?php endif; ?>
                · Meetup <?= e(format_time_12h($tour['start_time'] ?? $tour['itin_meetup'] ?? null)) ?>
                · <?= (int) ($tour['party_size'] ?? 1) ?> guest(s)
              </div>
              <?= booking_accessibility_html($tour) ?>
              <?php if ($guideMode): ?>
                <?php
                  $mapStops = [];
                  $meetupPin = booking_checkin_meetup($tour, $date);
                  $pinSteps = [[
                      'id' => 0,
                      'title' => (string) $meetupPin['title'],
                      'lat' => (float) $meetupPin['lat'],
                      'lng' => (float) $meetupPin['lng'],
                      'label' => 'M',
                  ]];
                  $n = 0;
                  foreach ($stops as $s) {
                      $lat = $s['latitude'] ?? null;
                      $lng = $s['longitude'] ?? null;
                      if ($lat === null || $lng === null || $lat === '' || $lng === '') {
                          continue;
                      }
                      $n++;
                      $sid = (int) ($s['id'] ?? 0);
                      $stopTitle = trim((string) ($s['stop_name'] ?? $s['destination_name'] ?? 'Stop'));
                      $pinSteps[] = [
                          'id' => $sid,
                          'title' => $stopTitle !== '' ? $stopTitle : 'Stop',
                          'lat' => (float) $lat,
                          'lng' => (float) $lng,
                          'label' => (string) $n,
                      ];
                  }
                  $tourStarted = booking_tour_is_started($tour);
                  $nextPin = null;
                  if ($tourStarted) {
                      foreach ($pinSteps as $step) {
                          if (!isset($checkins[(int) $step['id']])) {
                              $nextPin = (int) $step['id'];
                              break;
                          }
                      }
                  }
                  $seenCurrent = false;
                  $nextLabeled = false;
                  foreach ($pinSteps as $step) {
                      $sid = (int) $step['id'];
                      if (isset($checkins[$sid]) && !empty($checkins[$sid]['skipped'])) {
                          $pin = 'skipped';
                          $status = 'Skipped';
                      } elseif (isset($checkins[$sid])) {
                          $pin = 'done';
                          $status = 'Done';
                      } elseif ($nextPin !== null && $sid === $nextPin) {
                          $pin = 'current';
                          $status = 'Current';
                          $seenCurrent = true;
                      } else {
                          $pin = 'waiting';
                          if ($seenCurrent && !$nextLabeled) {
                              $status = 'Next';
                              $nextLabeled = true;
                          } else {
                              $status = 'Upcoming';
                          }
                      }
                      $mapStops[] = [
                          'id' => $sid,
                          'title' => (string) $step['title'],
                          'lat' => (float) $step['lat'],
                          'lng' => (float) $step['lng'],
                          'day' => 1,
                          'label' => (string) $step['label'],
                          'pin' => $pin,
                          'status' => $status,
                          'done' => $pin === 'done',
                      ];
                  }
                  [$tourStart] = booking_effective_tour_span($tour);
                  $mapDay = itinerary_day_number($tourStart, $date);
                  if ($mapDay < 1) {
                      $mapDay = 1;
                  }
                  $meetupPlace = itinerary_day_meetup_place((int) ($tour['itinerary_id'] ?? 0), $mapDay);
                  $meetupJson = [
                      'lat' => (float) $meetupPlace['lat'],
                      'lng' => (float) $meetupPlace['lng'],
                      'title' => (string) ($meetupPlace['label'] ?? 'Meetup'),
                  ];
                ?>
                <div class="checkin-split" data-checkin-board>
                  <div class="checkin-split__head">
                    <strong>Today’s route</strong>
                    <span class="small text-muted" data-itin-map-sub>Blue is done, green is current, yellow is next, red is skipped.</span>
                  </div>
                  <div class="checkin-split__map">
                    <div
                      id="checkinMap-<?= $bid ?>"
                      class="ct-map ct-map--tall checkin-route-map"
                      data-checkin-map
                    ></div>
                    <script type="application/json" data-checkin-map-stops><?= json_encode($mapStops, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                    <script type="application/json" data-checkin-map-meetup><?= json_encode($meetupJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                  </div>
                  <div class="checkin-split__detail">
                    <?= booking_stop_checkin_panel_html($tour, $stops, [
                        'mode' => 'guide',
                        'date' => $date,
                        'can_submit' => booking_guide_can_checkin_on($tour, $guideProfileId, $date),
                        'return_to' => $returnTo,
                        'checkins' => $checkins,
                        'one_pin' => true,
                    ]) ?>
                  </div>
                </div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif;
    return (string) ob_get_clean();
}

function live_tours_version(array $tours, string $date): string
{
    return md5($date . '|' . live_booking_rows_version($tours));
}

function live_admin_tours_payload(string $date, int $focusId, string $since = ''): array
{
    ensure_booking_stop_checkin_schema();
    $tours = booking_tours_happening_on($date, null, ['approved', 'completed']);
    $version = live_tours_version($tours, $date . ':f' . $focusId);
    return live_changed_payload($version, $since, [
        'html' => live_tours_html($tours, $date, $focusId, false),
        'stats' => [
            'tours_count' => (string) count($tours),
        ],
    ]);
}

function live_guide_checkin_payload(int $guideProfileId, string $since = ''): array
{
    ensure_booking_stop_checkin_schema();
    $today = app_today();
    $tours = $guideProfileId > 0
        ? booking_tours_happening_on($today, $guideProfileId, ['approved'])
        : [];
    $version = live_tours_version($tours, $today . ':g' . $guideProfileId . ':m' . app_now_mins());
    return live_changed_payload($version, $since, [
        'html' => live_tours_html($tours, $today, 0, true, $guideProfileId),
    ]);
}

function live_notification_icons(): array
{
    return [
        'welcome' => 'bi-emoji-smile',
        'announcement' => 'bi-megaphone',
        'booking_request' => 'bi-calendar-plus',
        'tour_reminder' => 'bi-alarm',
        'booking_approved' => 'bi-calendar-check',
        'booking_cancelled' => 'bi-calendar-x',
        'booking_replacement' => 'bi-people',
        'booking_stop_checkin' => 'bi-camera',
        'booking_rejected' => 'bi-calendar-x',
        'new_message' => 'bi-chat-dots',
        'approval' => 'bi-patch-check',
        'guide_registration' => 'bi-person-badge',
        'business_registration' => 'bi-shop',
        'account' => 'bi-person-check',
        'refund_requested' => 'bi-cash-coin',
        'refund_sent' => 'bi-check-circle',
        'refund_details' => 'bi-wallet2',
    ];
}

function live_notifications_html(array $items, int $unreadCount): string
{
    $icons = live_notification_icons();
    ob_start(); ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted mb-0">
          <?= $unreadCount ? $unreadCount . ' unread' : 'You are all caught up' ?>
        </p>
        <?php if ($unreadCount): ?>
          <a class="btn btn-sm btn-outline-secondary" href="?read_all=1">Mark all read</a>
        <?php endif; ?>
      </div>
      <div class="glass overflow-hidden fade-up">
        <?php if (!$items): ?>
          <p class="text-muted mb-0 p-4">No notifications yet.</p>
        <?php else: ?>
          <?php foreach ($items as $n):
            $icon = $icons[$n['type']] ?? 'bi-bell';
            $href = '?go=' . (int) $n['id'];
          ?>
            <a href="<?= e($href) ?>" class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
              <div class="d-flex gap-3 align-items-start">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:40px;height:40px;background:var(--ct-foam);color:var(--ct-teal)">
                  <i class="bi <?= e($icon) ?>"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex justify-content-between gap-2">
                    <div class="<?= $n['is_read'] ? '' : 'fw-semibold' ?>">
                      <?php if (!$n['is_read']): ?><span class="badge bg-danger me-1">New</span><?php endif; ?>
                      <?= e($n['title']) ?>
                    </div>
                    <small class="text-muted text-nowrap"><?= e(format_date($n['created_at'], 'M j, g:i A')) ?></small>
                  </div>
                  <?php if (!empty($n['body'])): ?>
                    <div class="small text-muted mt-1"><?= e($n['body']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($n['link'])): ?>
                    <div class="small mt-1" style="color:var(--ct-sea)">Open related page →</div>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php
    return (string) ob_get_clean();
}

function live_notifications_payload(int $userId, string $since = ''): array
{
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll() ?: [];
    $unreadCount = 0;
    $parts = [];
    foreach ($items as $n) {
        if (!(int) $n['is_read']) {
            $unreadCount++;
        }
        $parts[] = ($n['id'] ?? '') . ':' . ($n['is_read'] ?? '');
    }
    $version = md5(implode('|', $parts) . '|u=' . $unreadCount);
    return live_changed_payload($version, $since, [
        'html' => live_notifications_html($items, $unreadCount),
        'unread_notifications' => $unreadCount,
    ]);
}

function live_inquiries_html(array $rows): string
{
    ob_start();
    if (!$rows): ?>
          <div class="glass p-4 text-muted">No inquiries in this filter.</div>
    <?php else: ?>
          <div class="row g-3">
            <?php foreach ($rows as $r): ?>
              <div class="col-lg-6">
                <div class="glass p-3 h-100">
                  <div class="d-flex justify-content-between gap-2 mb-1">
                    <div class="fw-semibold"><?= e($r['subject']) ?></div>
                    <?= status_badge($r['status']) ?>
                  </div>
                  <div class="small text-muted mb-2">
                    <?= e($r['tourist_name']) ?> · <?= e(format_date($r['created_at'] ?? null, 'M j, Y g:i A')) ?>
                  </div>
                  <p class="mb-2"><?= nl2br(e($r['message'])) ?></p>
                  <?php if (!empty($r['reply'])): ?>
                    <div class="border rounded-3 p-2 mb-2 small bg-transparent">
                      <div class="text-muted">Your reply<?= !empty($r['replied_at']) ? ' · ' . e(format_date($r['replied_at'], 'M j, Y g:i A')) : '' ?></div>
                      <?= nl2br(e($r['reply'])) ?>
                    </div>
                  <?php endif; ?>
                  <?php if (($r['status'] ?? '') !== 'closed'): ?>
                    <form method="post" class="mt-2"><?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <textarea class="form-control form-control-sm mb-2" name="reply" rows="2" placeholder="Write a reply…"<?= empty($r['reply']) ? ' required' : '' ?>><?= e((string) ($r['reply'] ?? '')) ?></textarea>
                      <div class="d-flex flex-wrap gap-1">
                        <button name="action" value="reply" class="btn btn-sm btn-ct"><?= empty($r['reply']) ? 'Send reply' : 'Update reply' ?></button>
                        <button name="action" value="close" class="btn btn-sm btn-outline-secondary" formnovalidate>Close</button>
                      </div>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
    <?php endif;
    return (string) ob_get_clean();
}

function live_business_inquiries_payload(int $businessId, string $filter, string $since = ''): array
{
    if (!in_array($filter, ['open', 'replied', 'closed', 'all'], true)) {
        $filter = 'open';
    }
    ensure_business_inquiries_schema();
    $sql = 'SELECT i.*, CONCAT(u.first_name,\' \',u.last_name) AS tourist_name, u.email AS tourist_email
            FROM business_inquiries i
            JOIN users u ON u.id = i.tourist_id
            WHERE i.business_id=?';
    $params = [$businessId];
    if ($filter !== 'all') {
        $sql .= ' AND i.status=?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY FIELD(i.status,\'open\',\'replied\',\'closed\'), i.created_at DESC LIMIT 100';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = ($r['id'] ?? '') . ':' . ($r['status'] ?? '') . ':' . ($r['reply'] ?? '') . ':' . ($r['replied_at'] ?? '');
    }
    $version = md5($filter . '|' . implode('|', $parts));
    return live_changed_payload($version, $since, [
        'html' => live_inquiries_html($rows),
    ]);
}

function live_content_reports_html(array $rows): string
{
    ob_start(); ?>
        <div class="table-responsive">
          <table class="table table-ct mb-0 align-middle">
            <thead>
              <tr>
                <th>When</th>
                <th>Reporter</th>
                <th>Target</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="small text-nowrap"><?= e(format_date($r['created_at'] ?? null, 'M j, Y g:i A')) ?></td>
                  <td>
                    <div><?= e($r['reporter_name']) ?></div>
                    <div class="small text-muted"><?= e($r['reporter_email']) ?></div>
                  </td>
                  <td>
                    <?php
                      $targetName = content_report_target_name((string) ($r['target_type'] ?? ''), (int) ($r['target_id'] ?? 0));
                      $targetKind = content_report_target_kind((string) ($r['target_type'] ?? ''));
                      $reportTitle = $targetName !== '' ? $targetName : $targetKind;
                      $photoPath = trim((string) ($r['attachment'] ?? ''));
                      $photoUrl = $photoPath !== '' ? (APP_URL . '/uploads/' . ltrim($photoPath, '/')) : '';
                    ?>
                    <div class="fw-semibold"><?= e($reportTitle) ?></div>
                    <template class="report-view-source">
                      <p class="report-view-card__label">Reason</p>
                      <p class="report-view-card__text"><?= e((string) ($r['reason'] ?? '')) ?></p>
                      <?php if (trim((string) ($r['details'] ?? '')) !== ''): ?>
                        <p class="report-view-card__label">Details</p>
                        <p class="report-view-card__text"><?= e((string) $r['details']) ?></p>
                      <?php endif; ?>
                      <?php if ($photoUrl !== ''): ?>
                        <p class="report-view-card__label">Photo</p>
                        <img class="report-view-card__photo" src="<?= e($photoUrl) ?>" alt="Photo attached to this report">
                      <?php else: ?>
                        <p class="report-view-card__empty">No photo attached.</p>
                      <?php endif; ?>
                    </template>
                  </td>
                  <td><?= status_badge($r['status']) ?></td>
                  <td class="text-nowrap">
                    <div class="report-actions">
                      <?php if (($r['status'] ?? '') === 'pending'): ?>
                        <form method="post" class="report-actions__form"><?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <button type="button" class="btn btn-sm btn-outline-secondary report-action-btn" data-view-report data-report-title="<?= e($reportTitle) ?>">View</button>
                          <button name="action" value="reviewed" class="btn btn-sm btn-outline-primary report-action-btn">Review</button>
                        </form>
                      <?php elseif (($r['status'] ?? '') === 'reviewed'): ?>
                        <form method="post" class="report-actions__form"><?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <button type="button" class="btn btn-sm btn-outline-secondary report-action-btn" data-view-report data-report-title="<?= e($reportTitle) ?>">View</button>
                          <button name="action" value="actioned" class="btn btn-sm btn-outline-primary report-action-btn">Actioned</button>
                          <button name="action" value="dismissed" class="btn btn-sm btn-outline-secondary report-action-btn">Dismissed</button>
                        </form>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary report-action-btn" data-view-report data-report-title="<?= e($reportTitle) ?>">View</button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-muted">No reports in this filter.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
    <?php
    return (string) ob_get_clean();
}

function live_content_reports_payload(string $filter, string $since = ''): array
{
    if (!in_array($filter, ['pending', 'reviewed', 'dismissed', 'actioned', 'all'], true)) {
        $filter = 'pending';
    }
    ensure_content_reports_schema();
    $sql = "SELECT r.*, CONCAT(u.first_name,' ',u.last_name) AS reporter_name, u.email AS reporter_email
        FROM content_reports r
        JOIN users u ON u.id = r.reporter_id";
    $params = [];
    if ($filter !== 'all') {
        $sql .= ' WHERE r.status=?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY FIELD(r.status,\'pending\',\'reviewed\',\'actioned\',\'dismissed\'), r.created_at DESC LIMIT 100';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = ($r['id'] ?? '') . ':' . ($r['status'] ?? '') . ':' . ($r['admin_notes'] ?? '');
    }
    $version = md5($filter . '|' . implode('|', $parts));
    return live_changed_payload($version, $since, [
        'html' => live_content_reports_html($rows),
        'pending' => pending_content_report_count(),
    ]);
}

function live_admin_dashboard_payload(string $since = ''): array
{
    require_once __DIR__ . '/map_helpers.php';
    $pdo = db();
    ensure_booking_stop_checkin_schema();
    $pendingGuides = (int) $pdo->query("SELECT COUNT(*) FROM tour_guide_profiles WHERE approval_status='pending'")->fetchColumn();
    $pendingBiz = (int) $pdo->query("SELECT COUNT(*) FROM businesses WHERE approval_status='pending'")->fetchColumn();
    $toursToday = booking_tours_happening_on(app_today(), null, ['approved']);
    $stats = [
        'users' => number_format((int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE status='active' AND role_id <> " . ROLE_ADMIN
        )->fetchColumn()),
        'tourists' => number_format((int) $pdo->query(
            'SELECT COUNT(*) FROM users WHERE role_id=' . ROLE_TOURIST . " AND status='active'"
        )->fetchColumn()),
        'guides' => number_format((int) $pdo->query(
            'SELECT COUNT(*) FROM users WHERE role_id=' . ROLE_TOUR_GUIDE . " AND status='active'"
        )->fetchColumn()),
        'businesses' => number_format((int) $pdo->query(
            "SELECT COUNT(*) FROM businesses WHERE approval_status='approved'"
        )->fetchColumn()),
        'bookings_today' => number_format((int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_date = CURDATE()")->fetchColumn()),
        'tours_today' => number_format(count($toursToday)),
        'pending_bookings' => number_format((int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn()),
        'pending_approvals' => number_format($pendingGuides + $pendingBiz),
    ];
    $statusRows = $pdo->query("SELECT status, COUNT(*) AS total FROM bookings GROUP BY status")->fetchAll() ?: [];
    $logs = $pdo->query(
        "SELECT l.*, u.first_name, u.last_name FROM activity_logs l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC LIMIT 8"
    )->fetchAll() ?: [];
    ob_start();
    if (!$statusRows): ?>
                <p class="text-muted mb-0">No bookings yet.</p>
    <?php else: ?>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($statusRows as $sr): ?>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                      <span><?= status_badge($sr['status']) ?></span>
                      <strong><?= (int) $sr['total'] ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
    <?php endif; ?>
              <div class="mt-3">
                <a class="quick-chip" href="<?= APP_URL ?>/admin/bookings.php"><i class="bi bi-calendar"></i> Manage bookings</a>
              </div>
    <?php
    $statusHtml = (string) ob_get_clean();
    ob_start();
    foreach ($logs as $log): ?>
                      <tr>
                        <td><?= e(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System') ?></td>
                        <td><?= e($log['action']) ?></td>
                        <td><?= e($log['module'] ?? '—') ?></td>
                        <td><?= e(format_date($log['created_at'], 'M j, g:i A')) ?></td>
                      </tr>
    <?php endforeach;
    if (!$logs): ?><tr><td colspan="4" class="text-muted">No activity yet.</td></tr><?php endif;
    $logsHtml = (string) ob_get_clean();
    $version = md5(json_encode([$stats, $statusRows, array_column($logs, 'id')]));
    return live_changed_payload($version, $since, [
        'stats' => $stats,
        'slots' => [
            'booking_status' => $statusHtml,
            'activity' => $logsHtml,
        ],
    ]);
}

function live_guide_dashboard_payload(int $userId, string $since = ''): array
{
    $profile = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
    $profile->execute([$userId]);
    $profile = $profile->fetch() ?: [];
    $gid = (int) ($profile['id'] ?? 0);
    $pending = 0;
    $upcoming = 0;
    $completed = 0;
    $upcomingRows = [];
    if ($gid) {
        $pending = guide_pending_booking_count($gid);
        $s = db()->prepare("SELECT COUNT(*) FROM bookings WHERE guide_id=? AND status='approved' AND booking_date >= CURDATE()");
        $s->execute([$gid]);
        $upcoming = (int) $s->fetchColumn();
        $s = db()->prepare("SELECT COUNT(*) FROM bookings WHERE guide_id=? AND status='completed'");
        $s->execute([$gid]);
        $completed = (int) $s->fetchColumn();
        $u = db()->prepare(
            "SELECT b.id, b.booking_date, b.start_time, b.status, b.booking_code,
                    b.guide_id, b.original_guide_id, b.replacement_from_date, b.needs_replacement,
                    b.tour_start_date, b.tour_end_date, b.itinerary_id,
                    i.title AS itinerary_title, i.start_date AS itin_start, i.end_date AS itin_end,
                    CONCAT(tu.first_name,' ',tu.last_name) AS tourist_name
             FROM bookings b
             JOIN users tu ON tu.id = b.tourist_id
             LEFT JOIN itineraries i ON i.id = b.itinerary_id
             WHERE (b.guide_id=? OR b.original_guide_id=?) AND b.status = 'approved'
               AND DATE(COALESCE(b.tour_end_date, i.end_date, b.tour_start_date, i.start_date, b.booking_date)) >= CURDATE()
             ORDER BY COALESCE(b.tour_start_date, i.start_date, b.booking_date), b.start_time
             LIMIT 12"
        );
        $u->execute([$gid, $gid]);
        foreach ($u->fetchAll() ?: [] as $row) {
            $keep = false;
            foreach (booking_remaining_visit_dates($row) as $d) {
                if (booking_guide_covers_date($row, $gid, $d)) {
                    $keep = true;
                    break;
                }
            }
            if ($keep && booking_guide_schedule_tone($row, $gid) !== 'completed') {
                $upcomingRows[] = $row;
            }
            if (count($upcomingRows) >= 6) {
                break;
            }
        }
    }
    ob_start();
    if (!$upcomingRows): ?>
              <div class="text-muted">No tours.</div>
    <?php else: ?>
              <div class="d-grid gap-2">
                <?php foreach ($upcomingRows as $r): ?>
                  <?php
                    $start = !empty($r['itin_start']) ? (string) $r['itin_start'] : (string) $r['booking_date'];
                    $end = !empty($r['itin_end']) ? (string) $r['itin_end'] : $start;
                    $days = itinerary_day_count($start, $end);
                    $title = trim((string) ($r['itinerary_title'] ?? ''));
                    if ($title === '') {
                        $title = 'Tour with ' . ($r['tourist_name'] ?? 'tourist');
                    }
                  ?>
                  <div class="guide-cal__detail guide-cal__detail--<?= e($schedTone = booking_guide_schedule_tone($r, $gid)) ?>">
                    <div class="fw-semibold"><?= e($title) ?></div>
                    <div class="small mt-1">
                      <?= e(format_date($start)) ?>
                      <?php if ($days > 1): ?>
                        – <?= e(format_date($end)) ?> · <?= $days ?> days
                      <?php else: ?>
                        · Day 1
                      <?php endif; ?>
                      · <?= e(booking_guide_schedule_tone_label($schedTone)) ?>
                    </div>
                    <div class="small text-muted"><?= e($r['tourist_name']) ?> · Meetup <?= e(format_time_12h($r['start_time'] ?? null)) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
    <?php endif;
    $upcomingHtml = (string) ob_get_clean();
    $stats = [
        'pending' => (string) $pending,
        'upcoming' => (string) $upcoming,
        'completed' => (string) $completed,
        'rating' => (string) ($profile['avg_rating'] ?? '0.00'),
    ];
    $version = md5(json_encode([$stats, array_column($upcomingRows, 'id')]));
    return live_changed_payload($version, $since, [
        'stats' => $stats,
        'pending' => $pending,
        'slots' => [
            'upcoming' => $upcomingHtml,
        ],
    ]);
}

function live_tourist_dashboard_payload(int $userId, string $since = ''): array
{
    $pdo = db();
    $s = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id=?');
    $s->execute([$userId]);
    $fav = (int) $s->fetchColumn();
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM itineraries i
         WHERE i.user_id=? AND i.is_suggested=0 AND ' . itinerary_sql_is_saved_tour('i')
    );
    $s->execute([$userId]);
    $itin = (int) $s->fetchColumn();
    $s = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE tourist_id=?');
    $s->execute([$userId]);
    $books = (int) $s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE tourist_id=? AND status='pending'");
    $s->execute([$userId]);
    $pending = (int) $s->fetchColumn();
    $stats = [
        'favorites' => (string) $fav,
        'itineraries' => (string) $itin,
        'bookings' => (string) $books,
        'pending' => (string) $pending,
    ];
    $version = md5(implode('|', $stats));
    return live_changed_payload($version, $since, ['stats' => $stats]);
}

function live_business_dashboard_payload(int $userId, string $since = ''): array
{
    $biz = db()->prepare('SELECT b.*, bt.name AS type_name FROM businesses b JOIN business_types bt ON bt.id=b.business_type_id WHERE b.user_id=? LIMIT 1');
    $biz->execute([$userId]);
    $biz = $biz->fetch();
    $svc = 0;
    $photos = 0;
    $openInquiries = 0;
    $listing = '—';
    if ($biz) {
        $s = db()->prepare('SELECT COUNT(*) FROM business_services WHERE business_id=?');
        $s->execute([$biz['id']]);
        $svc = (int) $s->fetchColumn();
        $s = db()->prepare('SELECT COUNT(*) FROM business_photos WHERE business_id=?');
        $s->execute([$biz['id']]);
        $photos = (int) $s->fetchColumn();
        $listing = ucfirst((string) ($biz['status'] ?? ''));
        if (function_exists('ensure_business_inquiries_schema')) {
            ensure_business_inquiries_schema();
            $s = db()->prepare("SELECT COUNT(*) FROM business_inquiries WHERE business_id=? AND status='open'");
            $s->execute([$biz['id']]);
            $openInquiries = (int) $s->fetchColumn();
        }
    }
    $stats = [
        'services' => (string) $svc,
        'photos' => (string) $photos,
        'listing' => $listing,
        'open_inquiries' => (string) $openInquiries,
    ];
    $version = md5(implode('|', $stats) . '|' . ($biz['approval_status'] ?? ''));
    return live_changed_payload($version, $since, ['stats' => $stats]);
}

function live_notif_preview(string $body): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($body, 0, 72, '…');
    }
    return strlen($body) > 72 ? substr($body, 0, 71) . '…' : $body;
}

function live_notif_return_url(): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && defined('APP_URL') && str_starts_with($ref, APP_URL) && !str_contains($ref, "\n")) {
        return $ref;
    }
    return rtrim((string) APP_URL, '/') . '/notifications.php';
}

/**
 * Bell dropdown inner HTML (head + list). Does not mark notifications read.
 *
 * @return array{html:string,version:string,unread:int}
 */
function live_header_notif_payload(int $userId, string $returnUrl = ''): array
{
    if ($returnUrl === '' || !str_starts_with($returnUrl, (string) APP_URL) || str_contains($returnUrl, "\n")) {
        $returnUrl = rtrim((string) APP_URL, '/') . '/notifications.php';
    }
    $stmt = db()->prepare(
        'SELECT id, type, title, body, link, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6'
    );
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll() ?: [];
    $unread = 0;
    $parts = [];
    foreach ($items as $n) {
        if (!(int) ($n['is_read'] ?? 0)) {
            $unread++;
        }
        $parts[] = ($n['id'] ?? '') . ':' . ($n['is_read'] ?? '');
    }
    $icons = live_notification_icons();
    ob_start(); ?>
        <div class="notif-panel__head">
          <div>
            <div class="fw-semibold">Notifications</div>
            <div class="small text-muted"><?= $unread ? $unread . ' unread' : 'No new alerts' ?></div>
          </div>
          <?php if ($unread > 0): ?>
            <a class="btn btn-sm btn-link text-decoration-none px-0" href="<?= e(APP_URL . '/notifications.php?read_all=1&return=' . urlencode($returnUrl)) ?>">Mark all read</a>
          <?php endif; ?>
        </div>
        <div class="notif-panel__list">
          <?php if (!$items): ?>
            <div class="notif-panel__empty text-muted">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($items as $n):
              $icon = $icons[$n['type'] ?? ''] ?? 'bi-bell';
            ?>
              <a class="notif-panel__item <?= $n['is_read'] ? '' : 'is-unread' ?>" href="<?= e(APP_URL . '/notifications.php?go=' . (int) $n['id']) ?>">
                <span class="notif-panel__icon"><i class="bi <?= e($icon) ?>"></i></span>
                <span class="notif-panel__body">
                  <span class="notif-panel__title"><?= e($n['title']) ?></span>
                  <?php if (!empty($n['body'])): ?>
                    <span class="notif-panel__text"><?= e(live_notif_preview((string) $n['body'])) ?></span>
                  <?php endif; ?>
                  <span class="notif-panel__time"><?= e(format_date($n['created_at'], 'M j, g:i A')) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
    <?php
    $html = (string) ob_get_clean();
    return [
        'html' => $html,
        'version' => md5(implode('|', $parts) . '|u=' . $unread),
        'unread' => $unread,
    ];
}

function live_admin_logs_payload(string $since = ''): array
{
    $rows = db()->query(
        "SELECT l.*, CONCAT(u.first_name,' ',u.last_name) AS user_name, u.email, u.role_id
         FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id
         ORDER BY l.created_at DESC LIMIT 200"
    )->fetchAll() ?: [];
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = ($r['id'] ?? '') . ':' . ($r['created_at'] ?? '');
    }
    $version = md5(implode('|', $parts) . '|n=' . count($parts));
    ob_start(); ?>
        <div class="table-responsive table-sticky-scroll">
          <table class="table table-ct mb-0">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Details</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= e(format_date($r['created_at'], 'M j, Y g:i A')) ?></td>
                  <td><?= e($r['user_name'] ?? 'System') ?></td>
                  <td><?= e($r['action']) ?></td>
                  <td><?= e($r['module'] ?? '') ?></td>
                  <td><?php
                    $logDetails = format_activity_log_details($r);
                    echo e($logDetails !== '' ? $logDetails : '—');
                  ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-muted">No activity yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
    <?php
    return live_changed_payload($version, $since, ['html' => (string) ob_get_clean()]);
}

function live_reports_normalize_range(string $from, string $to): array
{
    $from = trim($from);
    $to = trim($to);
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = '';
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = '';
    }
    if ($from === '' && $to === '') {
        $from = date('Y-m-01');
        $to = date('Y-m-d');
    }
    return [$from, $to];
}

/**
 * @return array{
 *   from:string,to:string,popular:array,byRole:array,bookingStats:array,guideUtil:array,
 *   bizPopular:array,bizCount:int,guideCount:int,totalInRange:int,completedInRange:int
 * }
 */
function live_admin_reports_data(string $from, string $to): array
{
    [$from, $to] = live_reports_normalize_range($from, $to);
    $pdo = db();
    $popularStmt = $pdo->prepare(
        "SELECT d.name, COUNT(bk.id) AS stops
         FROM destinations d
         LEFT JOIN itinerary_items ii ON ii.destination_id = d.id
         LEFT JOIN bookings bk ON bk.itinerary_id = ii.itinerary_id
           AND bk.status IN ('pending', 'approved', 'completed')
           AND (? = '' OR bk.booking_date >= ?)
           AND (? = '' OR bk.booking_date <= ?)
         GROUP BY d.id
         ORDER BY stops DESC, d.name ASC"
    );
    $popularStmt->execute([$from, $from, $to, $to]);
    $popular = $popularStmt->fetchAll() ?: [];

    $byRole = $pdo->query(
        "SELECT r.name, COUNT(u.id) AS total FROM roles r LEFT JOIN users u ON u.role_id=r.id GROUP BY r.id"
    )->fetchAll() ?: [];

    $bookingStatsStmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total FROM bookings b
         WHERE (? = '' OR booking_date >= ?) AND (? = '' OR booking_date <= ?)
         GROUP BY status"
    );
    $bookingStatsStmt->execute([$from, $from, $to, $to]);
    $bookingStats = $bookingStatsStmt->fetchAll() ?: [];

    $guideUtilStmt = $pdo->prepare(
        "SELECT CONCAT(u.first_name,' ',u.last_name) AS guide_name,
                COUNT(b.id) AS total_bookings,
                SUM(b.status='completed') AS completed,
                SUM(b.status='approved') AS approved,
                SUM(b.status='pending') AS pending,
                ROUND(AVG(NULLIF(g.avg_rating,0)), 2) AS avg_rating
         FROM tour_guide_profiles g
         JOIN users u ON u.id = g.user_id
         LEFT JOIN bookings b ON b.guide_id = g.id
           AND (? = '' OR b.booking_date >= ?)
           AND (? = '' OR b.booking_date <= ?)
         WHERE g.approval_status='approved'
         GROUP BY g.id
         ORDER BY total_bookings DESC, guide_name ASC"
    );
    $guideUtilStmt->execute([$from, $from, $to, $to]);
    $guideUtil = $guideUtilStmt->fetchAll() ?: [];

    $bizPopularStmt = $pdo->prepare(
        "SELECT b.name, bt.name AS type_name, COUNT(bk.id) AS stops
         FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         LEFT JOIN itinerary_items ii ON ii.business_id = b.id
         LEFT JOIN bookings bk ON bk.itinerary_id = ii.itinerary_id
           AND bk.status IN ('pending', 'approved', 'completed')
           AND (? = '' OR bk.booking_date >= ?)
           AND (? = '' OR bk.booking_date <= ?)
         WHERE b.approval_status='approved'
         GROUP BY b.id
         ORDER BY stops DESC, b.name ASC"
    );
    $bizPopularStmt->execute([$from, $from, $to, $to]);
    $bizPopular = $bizPopularStmt->fetchAll() ?: [];

    $bizCount = (int) $pdo->query("SELECT COUNT(*) FROM businesses WHERE approval_status='approved'")->fetchColumn();
    $guideCount = (int) $pdo->query("SELECT COUNT(*) FROM tour_guide_profiles WHERE approval_status='approved'")->fetchColumn();
    $totalInRange = 0;
    $statusMap = [];
    foreach ($bookingStats as $r) {
        $totalInRange += (int) $r['total'];
        $statusMap[(string) $r['status']] = (int) $r['total'];
    }

    return [
        'from' => $from,
        'to' => $to,
        'popular' => $popular,
        'byRole' => $byRole,
        'bookingStats' => $bookingStats,
        'guideUtil' => $guideUtil,
        'bizPopular' => $bizPopular,
        'bizCount' => $bizCount,
        'guideCount' => $guideCount,
        'totalInRange' => $totalInRange,
        'completedInRange' => (int) ($statusMap['completed'] ?? 0),
    ];
}

function live_admin_reports_html(array $d): string
{
    $from = (string) ($d['from'] ?? '');
    $to = (string) ($d['to'] ?? '');
    $kpiCards = [
        ['Bookings in range', (int) ($d['totalInRange'] ?? 0), 'bi-calendar-check', $from . ' → ' . $to],
        ['Completed', (int) ($d['completedInRange'] ?? 0), 'bi-check2-circle', 'In selected range'],
        ['Approved guides', (int) ($d['guideCount'] ?? 0), 'bi-person-badge', 'Active on CulTour'],
        ['Approved businesses', (int) ($d['bizCount'] ?? 0), 'bi-shop', 'Live on map'],
    ];
    $guideUtil = $d['guideUtil'] ?? [];
    $bookingStats = $d['bookingStats'] ?? [];
    $popular = $d['popular'] ?? [];
    $bizPopular = $d['bizPopular'] ?? [];
    ob_start(); ?>
      <div class="row g-3 mb-3">
        <?php foreach ($kpiCards as $i => [$label, $val, $icon, $hint]): ?>
          <div class="col-6 col-xl-3">
            <div class="glass stat-card reports-kpi">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <span class="label"><?= e($label) ?></span>
                <i class="bi <?= e($icon) ?> text-muted"></i>
              </div>
              <p class="value"><?= number_format((int) $val) ?></p>
              <div class="reports-kpi__hint"><?= e($hint) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <section class="glass reports-panel reports-guide">
            <header class="reports-panel__head reports-panel__head--split">
              <div>
                <h2 class="reports-panel__title">Guide utilization</h2>
                <p class="reports-panel__sub mb-0">Top guides by bookings in range</p>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#guideUtilModal">View all</button>
            </header>
            <div class="reports-panel__body reports-popular__scroll">
              <table class="table table-ct reports-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Guide</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Done</th>
                    <th class="text-end">Open</th>
                    <th class="text-end">★</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($guideUtil as $r): ?>
                    <tr>
                      <td><?= e($r['guide_name']) ?></td>
                      <td class="text-end"><?= (int) $r['total_bookings'] ?></td>
                      <td class="text-end"><?= (int) $r['completed'] ?></td>
                      <td class="text-end"><?= (int) $r['pending'] + (int) $r['approved'] ?></td>
                      <td class="text-end"><?= e($r['avg_rating'] !== null ? (string) $r['avg_rating'] : '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$guideUtil): ?>
                    <tr><td colspan="5" class="text-muted">No guides yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div class="col-lg-6">
          <section class="glass reports-panel reports-guide">
            <header class="reports-panel__head">
              <div>
                <h2 class="reports-panel__title">Booking status</h2>
                <p class="reports-panel__sub mb-0">In selected date range</p>
              </div>
            </header>
            <div class="reports-panel__body reports-popular__scroll">
              <?php if ($bookingStats): ?>
                <ul class="reports-metric-list">
                  <?php foreach ($bookingStats as $r): ?>
                    <li>
                      <span><?= e(ucfirst((string) $r['status'])) ?></span>
                      <strong><?= number_format((int) $r['total']) ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="text-muted small mb-0">No bookings in this range.</p>
              <?php endif; ?>
            </div>
          </section>
        </div>
      </div>

      <div class="modal fade reports-popular-modal" id="guideUtilModal" tabindex="-1" aria-labelledby="guideUtilModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <h2 class="modal-title fs-5" id="guideUtilModalLabel">Guide utilization</h2>
                <div class="reports-panel__sub">Top guides by bookings in range</div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body reports-popular-modal__scroll">
              <table class="table table-ct reports-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Guide</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Done</th>
                    <th class="text-end">Open</th>
                    <th class="text-end">★</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($guideUtil as $r): ?>
                    <tr>
                      <td><?= e($r['guide_name']) ?></td>
                      <td class="text-end"><?= (int) $r['total_bookings'] ?></td>
                      <td class="text-end"><?= (int) $r['completed'] ?></td>
                      <td class="text-end"><?= (int) $r['pending'] + (int) $r['approved'] ?></td>
                      <td class="text-end"><?= e($r['avg_rating'] !== null ? (string) $r['avg_rating'] : '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$guideUtil): ?>
                    <tr><td colspan="5" class="text-muted">No guides yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <section class="glass reports-panel reports-popular">
            <header class="reports-panel__head reports-panel__head--split">
              <div>
                <h2 class="reports-panel__title">Popular destinations</h2>
                <p class="reports-panel__sub mb-0">Booked stops in range</p>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#popularDestModal">View all</button>
            </header>
            <div class="reports-panel__body reports-popular__scroll">
              <table class="table table-ct reports-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Destination</th>
                    <th class="text-end">Stops</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($popular as $r): ?>
                    <tr>
                      <td><?= e($r['name']) ?></td>
                      <td class="text-end"><?= (int) $r['stops'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$popular): ?>
                    <tr><td colspan="2" class="text-muted">No booked destination stops in this range.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div class="col-lg-6">
          <section class="glass reports-panel reports-popular">
            <header class="reports-panel__head reports-panel__head--split">
              <div>
                <h2 class="reports-panel__title">Popular businesses</h2>
                <p class="reports-panel__sub mb-0">Booked stops in range</p>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#popularBizModal">View all</button>
            </header>
            <div class="reports-panel__body reports-popular__scroll">
              <table class="table table-ct reports-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Business</th>
                    <th>Type</th>
                    <th class="text-end">Stops</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bizPopular as $r): ?>
                    <tr>
                      <td><?= e($r['name']) ?></td>
                      <td class="text-muted"><?= e($r['type_name']) ?></td>
                      <td class="text-end"><?= (int) $r['stops'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$bizPopular): ?>
                    <tr><td colspan="3" class="text-muted">No booked business stops in this range.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>

      <div class="modal fade reports-popular-modal" id="popularDestModal" tabindex="-1" aria-labelledby="popularDestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <h2 class="modal-title fs-5" id="popularDestModalLabel">Popular destinations</h2>
                <div class="reports-panel__sub">Booked stops in range</div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body reports-popular-modal__scroll">
              <table class="table table-ct reports-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Destination</th>
                    <th class="text-end">Stops</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($popular as $r): ?>
                    <tr>
                      <td><?= e($r['name']) ?></td>
                      <td class="text-end"><?= (int) $r['stops'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$popular): ?>
                    <tr><td colspan="2" class="text-muted">No booked destination stops in this range.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade reports-popular-modal" id="popularBizModal" tabindex="-1" aria-labelledby="popularBizModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <h2 class="modal-title fs-5" id="popularBizModalLabel">Popular businesses</h2>
                <div class="reports-panel__sub">Booked stops in range</div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body reports-popular-modal__scroll">
              <table class="table table-ct reports-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Business</th>
                    <th>Type</th>
                    <th class="text-end">Stops</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bizPopular as $r): ?>
                    <tr>
                      <td><?= e($r['name']) ?></td>
                      <td class="text-muted"><?= e($r['type_name']) ?></td>
                      <td class="text-end"><?= (int) $r['stops'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$bizPopular): ?>
                    <tr><td colspan="3" class="text-muted">No booked business stops in this range.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php
    return (string) ob_get_clean();
}

function live_admin_reports_payload(string $from, string $to, string $since = ''): array
{
    $d = live_admin_reports_data($from, $to);
    $version = md5(json_encode([
        $d['from'], $d['to'], $d['totalInRange'], $d['completedInRange'],
        $d['bizCount'], $d['guideCount'], $d['bookingStats'],
        array_column($d['guideUtil'], 'total_bookings'),
        array_column($d['popular'], 'stops'),
        array_column($d['bizPopular'], 'stops'),
    ]));
    return live_changed_payload($version, $since, [
        'html' => live_admin_reports_html($d),
    ]);
}

function live_tourist_favorites_html(array $destRows, array $bizRows): string
{
    ob_start(); ?>
      <h3 class="h6 text-muted mb-2">Destinations</h3>
      <div class="row g-3 mb-4">
        <?php foreach ($destRows as $d): ?>
          <div class="col-md-4">
            <div class="glass dest-card">
              <div class="cover" style="<?= $d['cover_image'] ? 'background-image:url('.APP_URL.'/uploads/'.e($d['cover_image']).')' : '' ?>"></div>
              <div class="body">
                <div class="fw-semibold"><?= e($d['name']) ?></div>
                <div class="small text-muted mb-2"><?= e($d['category_name']) ?></div>
                <a class="btn btn-sm btn-ct" href="destination.php?id=<?= (int)$d['id'] ?>">View</a>
                <form method="post" class="d-inline"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove_dest">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$destRows): ?><div class="col-12"><div class="glass p-3 text-muted">No saved destinations.</div></div><?php endif; ?>
      </div>

      <h3 class="h6 text-muted mb-2">Businesses</h3>
      <div class="row g-3">
        <?php foreach ($bizRows as $b): ?>
          <div class="col-md-4">
            <div class="glass dest-card">
              <div class="cover" style="<?= $b['cover_image'] ? 'background-image:url('.APP_URL.'/uploads/'.e($b['cover_image']).')' : '' ?>"></div>
              <div class="body">
                <div class="fw-semibold"><?= e($b['name']) ?></div>
                <div class="small text-muted mb-2"><?= e($b['type_name']) ?> · ★ <?= e(number_format((float) ($b['avg_rating'] ?? 0), 1)) ?></div>
                <a class="btn btn-sm btn-ct" href="business.php?id=<?= (int)$b['id'] ?>">View</a>
                <form method="post" class="d-inline"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove_biz">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$bizRows): ?><div class="col-12"><div class="glass p-3 text-muted">No saved businesses.</div></div><?php endif; ?>
      </div>
    <?php
    return (string) ob_get_clean();
}

function live_tourist_favorites_payload(int $userId, string $since = ''): array
{
    ensure_business_favorites_schema();
    $stmt = db()->prepare(
        "SELECT d.*, c.name AS category_name FROM favorites f
         JOIN destinations d ON d.id=f.destination_id
         JOIN categories c ON c.id=d.category_id
         WHERE f.user_id=? ORDER BY f.created_at DESC"
    );
    $stmt->execute([$userId]);
    $destRows = $stmt->fetchAll() ?: [];
    $bizStmt = db()->prepare(
        "SELECT b.*, bt.name AS type_name,
                (SELECT COUNT(*) FROM business_photos bp WHERE bp.business_id = b.id) AS photo_count
         FROM business_favorites f
         JOIN businesses b ON b.id = f.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE f.user_id=? AND b.approval_status='approved' AND b.status='active'
         ORDER BY f.created_at DESC"
    );
    $bizStmt->execute([$userId]);
    $bizRows = array_values(array_filter($bizStmt->fetchAll() ?: [], 'business_itinerary_ready'));
    $parts = ['d'];
    foreach ($destRows as $d) {
        $parts[] = (string) ($d['id'] ?? '');
    }
    $parts[] = 'b';
    foreach ($bizRows as $b) {
        $parts[] = ($b['id'] ?? '') . ':' . ($b['avg_rating'] ?? '');
    }
    $version = md5(implode('|', $parts));
    return live_changed_payload($version, $since, [
        'html' => live_tourist_favorites_html($destRows, $bizRows),
    ]);
}

function live_guide_ratings_rows(int $guideProfileId): array
{
    if ($guideProfileId < 1) {
        return [];
    }
    ensure_itinerary_items_schema();
    $rows = db()->prepare(
        "SELECT gr.id AS rating_id, gr.rating, gr.comment, gr.created_at AS rated_at,
                CONCAT(u.first_name,' ',u.last_name) AS tourist_name,
                b.id AS booking_id, b.booking_code, b.booking_date, b.tour_start_date, b.tour_end_date,
                b.start_time, b.status, b.party_size, b.itinerary_id, b.notes AS booking_notes,
                i.title AS itinerary_title, i.description AS itinerary_description,
                i.meetup_time AS itin_meetup, i.start_date AS itin_start, i.end_date AS itin_end
         FROM guide_ratings gr
         JOIN users u ON u.id = gr.tourist_id
         JOIN bookings b ON b.id = gr.booking_id
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         WHERE gr.guide_id=?
         ORDER BY gr.created_at DESC"
    );
    $rows->execute([$guideProfileId]);
    $rows = $rows->fetchAll() ?: [];
    $itinCache = [];
    foreach ($rows as &$r) {
        [$start, $end] = booking_effective_tour_span($r);
        $r['_start'] = $start;
        $r['_end'] = $end;
        $itinId = (int) ($r['itinerary_id'] ?? 0);
        $title = trim((string) ($r['itinerary_title'] ?? ''));
        $r['_title'] = $title !== '' ? $title : ('Tour with ' . trim((string) ($r['tourist_name'] ?? 'tourist')));
        $r['_desc'] = itinerary_display_description($r['itinerary_description'] ?? null);
        $r['_days'] = 1;
        $r['_stops'] = [];
        if ($itinId > 0) {
            if (!isset($itinCache[$itinId])) {
                $itinRow = itinerary_row_by_id($itinId);
                $stops = itinerary_items_with_places($itinId);
                $itinCache[$itinId] = [
                    'row' => $itinRow,
                    'days' => $itinRow ? itinerary_logical_day_count($itinRow) : itinerary_day_count($start, $end),
                    'groups' => itinerary_group_stops_by_day($stops, $start, $end, $itinId, $itinRow ?: null),
                ];
            }
            $r['_days'] = (int) ($itinCache[$itinId]['days'] ?? 1);
            $r['_stops'] = $itinCache[$itinId]['groups'] ?? [];
        } else {
            $r['_days'] = itinerary_day_count($start, $end);
        }
    }
    unset($r);
    return $rows;
}

function live_guide_ratings_list_html(array $rows): string
{
    ob_start(); ?>
      <div class="glass p-3">
        <?php if (!$rows): ?>
          <p class="text-muted mb-0">No ratings yet.</p>
        <?php else: ?>
          <div class="d-grid gap-2">
            <?php foreach ($rows as $r): ?>
              <button type="button"
                      class="guide-cal__detail guide-cal__detail--completed text-start border-0 w-100"
                      data-open-tour="<?= (int) $r['booking_id'] ?>">
                <div class="fw-semibold"><?= (int) $r['rating'] ?>★ from <?= e($r['tourist_name']) ?></div>
                <div class="small text-muted">
                  <?php if (trim((string) ($r['itinerary_title'] ?? '')) !== ''): ?>
                    <?= e($r['_title']) ?> ·
                  <?php endif; ?>
                  <?= e($r['booking_code']) ?> · <?= e(format_date($r['booking_date'])) ?>
                </div>
                <?php if (trim((string) ($r['comment'] ?? '')) !== ''): ?>
                  <div class="small mt-1"><?= e($r['comment']) ?></div>
                <?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php
    return (string) ob_get_clean();
}

function live_guide_ratings_overlay_html(array $rows): string
{
    ob_start();
    foreach ($rows as $r): ?>
          <div class="admin-biz-proof-card guide-tour-card" data-tour-card="<?= (int) $r['booking_id'] ?>" hidden>
            <div class="admin-biz-proof-card__head">
              <div>
                <div class="small text-muted">Tour details</div>
                <h2 class="h6 mb-0" id="guideTourTitle<?= (int) $r['booking_id'] ?>"><?= e($r['_title']) ?></h2>
              </div>
              <div class="d-flex align-items-center gap-2">
                <?= status_badge((string) $r['status']) ?>
                <button type="button" class="admin-biz-proof-card__close" data-close-tour aria-label="Close">&times;</button>
              </div>
            </div>
            <div class="admin-biz-proof-card__body guide-tour-card__body">
              <div class="small text-muted mb-2">
                <?= e(format_date($r['_start'])) ?>
                <?php if ($r['_end'] && $r['_end'] !== $r['_start']): ?>
                  – <?= e(format_date($r['_end'])) ?>
                <?php endif; ?>
                <?php if ((int) $r['_days'] > 1): ?>
                  · <?= (int) $r['_days'] ?> days
                <?php endif; ?>
                · Meetup <?= e(format_time_12h($r['start_time'] ?? $r['itin_meetup'] ?? null)) ?>
                · <?= (int) ($r['party_size'] ?? 1) ?> guest(s)
              </div>
              <div class="small mb-3">
                Tourist: <strong><?= e($r['tourist_name']) ?></strong>
                · Code <code><?= e($r['booking_code']) ?></code>
                · <?= (int) $r['rating'] ?>★
              </div>
              <?php if (trim((string) ($r['comment'] ?? '')) !== ''): ?>
                <div class="guide-tour-quote">
                  <span class="guide-tour-quote__label">Review</span>
                  <?= e($r['comment']) ?>
                </div>
              <?php endif; ?>
              <?php if ($r['_desc'] !== ''): ?>
                <p class="small text-muted"><?= e($r['_desc']) ?></p>
              <?php endif; ?>
              <?php if (!empty($r['booking_notes'])): ?>
                <div class="guide-tour-quote">
                  <span class="guide-tour-quote__label">Tourist notes</span>
                  <?= e($r['booking_notes']) ?>
                </div>
              <?php endif; ?>

              <?php if (!(int) ($r['itinerary_id'] ?? 0)): ?>
                <div class="text-muted small">This booking has no linked itinerary stops.</div>
              <?php elseif (!$r['_stops']): ?>
                <div class="text-muted small">Itinerary has no stops yet.</div>
              <?php else: ?>
                <div class="d-grid gap-3">
                  <?php foreach ($r['_stops'] as $dayGroup): ?>
                    <section class="guide-cal-itin__day">
                      <div class="guide-cal-itin__day-head">
                        <strong><?= e($dayGroup['label'] ?? ('Day ' . (int) ($dayGroup['day'] ?? 1))) ?></strong>
                        <?php if (!empty($dayGroup['date'])): ?>
                          <span class="text-muted"><?= e(format_date($dayGroup['date'])) ?></span>
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
                            </li>
                          <?php endforeach; ?>
                        </ol>
                      <?php endif; ?>
                    </section>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
    <?php endforeach;
    return (string) ob_get_clean();
}

function live_guide_ratings_payload(int $guideProfileId, string $since = ''): array
{
    $rows = live_guide_ratings_rows($guideProfileId);
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = ($r['rating_id'] ?? '') . ':' . ($r['rating'] ?? '') . ':' . ($r['comment'] ?? '');
    }
    $version = md5(implode('|', $parts) . '|n=' . count($parts));
    return live_changed_payload($version, $since, [
        'html' => live_guide_ratings_list_html($rows),
        'slots' => [
            'ratings_overlay' => live_guide_ratings_overlay_html($rows),
        ],
    ]);
}

function live_admin_announcements_payload(string $since = ''): array
{
    require_once __DIR__ . '/announcements.php';
    $rows = admin_announcements_rows();
    return live_changed_payload(admin_announcements_version($rows), $since, [
        'html' => admin_announcements_list_html($rows),
    ]);
}

function live_announcement_board_payload(int $roleId, string $since = '', int $userId = 0): array
{
    require_once __DIR__ . '/announcements.php';
    $bundle = announcements_for_role($roleId, $userId);
    ob_start();
    render_announcements_board($bundle);
    return live_changed_payload(announcement_board_version($bundle), $since, [
        'html' => (string) ob_get_clean(),
    ]);
}

/**
 * @return array{ok:bool,changed?:bool,version?:string,html?:string,stats?:array,slots?:array}
 */
function live_page_dispatch(array $user, string $page, array $query, string $since): array
{
    $role = (int) ($user['role_id'] ?? 0);
    $uid = (int) ($user['id'] ?? 0);

    if ($page === 'tourist_bookings') {
        if ($role !== ROLE_TOURIST) {
            live_forbidden();
        }
        return live_tourist_bookings_payload($uid, $since);
    }
    if ($page === 'admin_bookings') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        $filter = (string) ($query['payment'] ?? '');
        return live_admin_bookings_payload(
            $filter,
            $since,
            (string) ($query['sort'] ?? 'date'),
            (string) ($query['dir'] ?? 'desc')
        );
    }
    if ($page === 'admin_tours') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        $date = normalize_tour_date((string) ($query['date'] ?? '')) ?: app_today();
        $focus = (int) ($query['booking'] ?? 0);
        return live_admin_tours_payload($date, $focus, $since);
    }
    if ($page === 'guide_checkin') {
        if ($role !== ROLE_TOUR_GUIDE) {
            live_forbidden();
        }
        $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
        $gp->execute([$uid]);
        return live_guide_checkin_payload((int) $gp->fetchColumn(), $since);
    }
    if ($page === 'notifications') {
        return live_notifications_payload($uid, $since);
    }
    if ($page === 'business_inquiries') {
        if ($role !== ROLE_BUSINESS) {
            live_forbidden();
        }
        $biz = db()->prepare('SELECT id FROM businesses WHERE user_id=? LIMIT 1');
        $biz->execute([$uid]);
        $bizId = (int) $biz->fetchColumn();
        if ($bizId < 1) {
            return ['ok' => true, 'changed' => false, 'version' => 'none'];
        }
        return live_business_inquiries_payload($bizId, (string) ($query['status'] ?? 'open'), $since);
    }
    if ($page === 'content_reports') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        return live_content_reports_payload((string) ($query['status'] ?? 'pending'), $since);
    }
    if ($page === 'admin_dashboard') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        return live_admin_dashboard_payload($since);
    }
    if ($page === 'guide_dashboard') {
        if ($role !== ROLE_TOUR_GUIDE) {
            live_forbidden();
        }
        return live_guide_dashboard_payload($uid, $since);
    }
    if ($page === 'tourist_dashboard') {
        if ($role !== ROLE_TOURIST) {
            live_forbidden();
        }
        return live_tourist_dashboard_payload($uid, $since);
    }
    if ($page === 'business_dashboard') {
        if ($role !== ROLE_BUSINESS) {
            live_forbidden();
        }
        return live_business_dashboard_payload($uid, $since);
    }
    if ($page === 'admin_logs') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        return live_admin_logs_payload($since);
    }
    if ($page === 'admin_announcements') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        return live_admin_announcements_payload($since);
    }
    if ($page === 'announcement_board') {
        if (!in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
            live_forbidden();
        }
        return live_announcement_board_payload($role, $since, $uid);
    }
    if ($page === 'admin_reports') {
        if ($role !== ROLE_ADMIN) {
            live_forbidden();
        }
        return live_admin_reports_payload(
            (string) ($query['from'] ?? ''),
            (string) ($query['to'] ?? ''),
            $since
        );
    }
    if ($page === 'tourist_favorites') {
        if ($role !== ROLE_TOURIST) {
            live_forbidden();
        }
        return live_tourist_favorites_payload($uid, $since);
    }
    if ($page === 'guide_ratings') {
        if ($role !== ROLE_TOUR_GUIDE) {
            live_forbidden();
        }
        $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
        $gp->execute([$uid]);
        return live_guide_ratings_payload((int) $gp->fetchColumn(), $since);
    }
    json_response(['ok' => false, 'message' => 'Unknown live page'], 404);
}
