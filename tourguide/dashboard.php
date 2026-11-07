<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/announcements.php';
require_once __DIR__ . '/../includes/suspension.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_booking_payment_schema();
$pageTitle = 'Guide Dashboard';
$pageHeading = 'Tour Guide Dashboard';
$activeNav = 'dashboard';

$annBundle = announcements_for_role(ROLE_TOUR_GUIDE, (int) $user['id']);

$profile = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([$user['id']]);
$profile = $profile->fetch();

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
    $upcomingRows = [];
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

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php render_account_status_panel($user); ?>
      <?php if (user_is_suspended($user)): ?>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; return; endif; ?>

      <div data-live-page="announcement_board" data-live-version="<?= e(announcement_board_version($annBundle)) ?>">
        <?php render_announcements_board($annBundle); ?>
      </div>
      <div data-live-stats="guide_dashboard">
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="glass stat-card"><div class="label">Pending</div><p class="value" data-stat="pending"><?= $pending ?></p></div></div>
        <div class="col-6 col-md-3"><div class="glass stat-card"><div class="label">Upcoming</div><p class="value" data-stat="upcoming"><?= $upcoming ?></p></div></div>
        <div class="col-6 col-md-3"><div class="glass stat-card"><div class="label">Completed</div><p class="value" data-stat="completed"><?= $completed ?></p></div></div>
        <div class="col-6 col-md-3"><div class="glass stat-card"><div class="label">Rating</div><p class="value" data-stat="rating"><?= e($profile['avg_rating'] ?? '0.00') ?></p></div></div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-lg-7">
          <div class="glass p-3 p-md-4 h-100 fade-up">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h3 class="panel-title mb-0">Upcoming schedule</h3>
              <a class="quick-chip" href="<?= APP_URL ?>/tourguide/schedule.php">Full calendar</a>
            </div>
            <div data-live-slot="upcoming">
            <?php if (!$upcomingRows): ?>
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
            <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="glass p-4 h-100 fade-up">
            <h3 class="panel-title">Quick actions</h3>
            <div class="d-flex flex-wrap gap-2 guide-quick-actions">
              <a class="btn btn-ct" href="bookings.php">Manage bookings</a>
              <a class="btn btn-outline-secondary" href="checkin.php">Stop check-in</a>
              <a class="btn btn-outline-secondary" href="schedule.php">Open schedule</a>
              <a class="btn btn-outline-secondary" href="availability.php">Set availability</a>
              <a class="btn btn-outline-secondary" href="itineraries.php">Suggested itineraries</a>
              <a class="btn btn-outline-secondary" href="profile.php">Update profile</a>
            </div>
          </div>
        </div>
      </div>
      </div>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
