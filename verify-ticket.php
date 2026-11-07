<?php
/**
 * Guide/admin check-in: look up booking by code and mark checked in.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr.php';
$user = require_login();
$role = (int) $user['role_id'];
if (!in_array($role, [ROLE_ADMIN, ROLE_TOUR_GUIDE], true)) {
    flash('error', 'Only tour guides and admins can verify tickets.');
    redirect(role_dashboard($role));
}

ensure_booking_payment_schema();
$pageTitle = 'Verify ticket';
$pageHeading = 'Verify booking ticket';
$activeNav = 'bookings';

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? 'lookup';
    $code = strtoupper(trim((string) ($_POST['booking_code'] ?? '')));

    if ($action === 'lookup' || $action === 'check_in') {
        if ($code === '') {
            $error = 'Enter a booking code.';
        } else {
            $stmt = db()->prepare(
                "SELECT b.*,
                        CONCAT(t.first_name,' ',t.last_name) AS tourist_name,
                        CONCAT(gu.first_name,' ',gu.last_name) AS guide_name,
                        gp.user_id AS guide_user_id,
                        ogp.user_id AS original_guide_user_id,
                        d.name AS destination_name,
                        i.title AS itinerary_title
                 FROM bookings b
                 JOIN users t ON t.id = b.tourist_id
                 JOIN tour_guide_profiles gp ON gp.id = b.guide_id
                 JOIN users gu ON gu.id = gp.user_id
                 LEFT JOIN tour_guide_profiles ogp ON ogp.id = b.original_guide_id
                 LEFT JOIN destinations d ON d.id = b.destination_id
                 LEFT JOIN itineraries i ON i.id = b.itinerary_id
                 WHERE b.booking_code = ?"
            );
            $stmt->execute([$code]);
            $result = $stmt->fetch() ?: null;
            if (!$result) {
                $error = 'No booking found for that code.';
            } elseif ($role === ROLE_TOUR_GUIDE && !booking_user_can_access_ticket($user, $result)) {
                $error = 'This booking belongs to another guide.';
                $result = null;
            } elseif ($action === 'check_in' && $result) {
                if (($result['status'] ?? '') !== 'approved') {
                    $error = 'Only approved bookings can be checked in (current status: ' . ($result['status'] ?? '?') . ').';
                } elseif (!empty($result['checked_in_at'])) {
                    $error = 'This ticket is already checked in.';
                } elseif (!booking_is_calendar_tour_day($result, app_today())) {
                    $error = 'Ticket check-in only works on the tour day.';
                } else {
                    db()->prepare('UPDATE bookings SET checked_in_at=NOW() WHERE id=? AND checked_in_at IS NULL')->execute([(int) $result['id']]);
                    flash('success', 'Checked in ' . $result['booking_code'] . '.');
                    redirect(APP_URL . '/verify-ticket.php?code=' . urlencode($code));
                }
            }
        }
    }
}

$codePrefill = strtoupper(trim((string) ($_GET['code'] ?? $_POST['booking_code'] ?? '')));
if ($result === null && $codePrefill !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_POST['booking_code'] = $codePrefill;
    // Soft lookup via GET
    $stmt = db()->prepare(
        "SELECT b.*,
                CONCAT(t.first_name,' ',t.last_name) AS tourist_name,
                CONCAT(gu.first_name,' ',gu.last_name) AS guide_name,
                gp.user_id AS guide_user_id,
                ogp.user_id AS original_guide_user_id,
                d.name AS destination_name,
                i.title AS itinerary_title
         FROM bookings b
         JOIN users t ON t.id = b.tourist_id
         JOIN tour_guide_profiles gp ON gp.id = b.guide_id
         JOIN users gu ON gu.id = gp.user_id
         LEFT JOIN tour_guide_profiles ogp ON ogp.id = b.original_guide_id
         LEFT JOIN destinations d ON d.id = b.destination_id
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         WHERE b.booking_code = ?"
    );
    $stmt->execute([$codePrefill]);
    $row = $stmt->fetch() ?: null;
    if ($row && ($role === ROLE_ADMIN || booking_user_can_access_ticket($user, $row))) {
        $result = $row;
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <div class="glass p-3 p-md-4 mb-3" style="max-width:560px">
        <p class="small text-muted mb-3">Enter the booking code from the ticket or QR payload to verify status and check in the guest.</p>
        <form method="post" class="row g-2"><?= csrf_field() ?>
          <input type="hidden" name="action" value="lookup">
          <div class="col-8">
            <label class="form-label">Booking code</label>
            <input class="form-control text-uppercase" name="booking_code" value="<?= e($codePrefill) ?>" placeholder="CTxxxxxxxx" required>
          </div>
          <div class="col-4 d-flex align-items-end">
            <button class="btn btn-ct w-100">Look up</button>
          </div>
        </form>
      </div>

      <?php if ($result): ?>
        <div class="glass p-3 p-md-4" style="max-width:560px">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="small text-muted">Booking</div>
              <div class="h4 mb-0"><code><?= e($result['booking_code']) ?></code></div>
            </div>
            <?= status_badge($result['status']) ?>
          </div>
          <dl class="row small mb-3">
            <dt class="col-4">Tourist</dt><dd class="col-8"><?= e($result['tourist_name']) ?></dd>
            <dt class="col-4">Guide</dt><dd class="col-8"><?= e($result['guide_name']) ?></dd>
            <dt class="col-4">Schedule</dt>
            <dd class="col-8"><?= e(format_date($result['booking_date'])) ?> <?= e(format_time_12h($result['start_time'] ?? null)) ?></dd>
            <dt class="col-4">Guests</dt><dd class="col-8"><?= (int) ($result['party_size'] ?? 1) ?></dd>
            <?php if (booking_is_pwd($result)): ?>
            <dt class="col-4">Accessibility</dt>
            <dd class="col-8">
              Accessibility needs
              <?php if (!empty($result['pwd_notes'])): ?>
                · <?= e($result['pwd_notes']) ?>
              <?php endif; ?>
              <?php $certUrl = booking_fitness_certificate_url($result); ?>
              <?php if ($certUrl): ?>
                · <a href="<?= e($certUrl) ?>" target="_blank" rel="noopener">Fitness-to-travel certificate</a>
              <?php endif; ?>
            </dd>
            <?php endif; ?>
            <dt class="col-4">Itinerary</dt><dd class="col-8"><?= e($result['itinerary_title'] ?? '—') ?></dd>
            <dt class="col-4">Payment</dt>
            <dd class="col-8"><?= e(ucwords(str_replace('_', ' ', (string) ($result['payment_status'] ?? 'unpaid')))) ?>
              <?php if (!empty($result['payment_reference'])): ?>
                · Ref <?= e($result['payment_reference']) ?>
              <?php endif; ?>
            </dd>
            <dt class="col-4">Check-in</dt>
            <dd class="col-8"><?= !empty($result['checked_in_at']) ? e(format_date($result['checked_in_at'], 'M j, Y g:i A')) : 'Not yet' ?></dd>
          </dl>
          <?php if (($result['status'] ?? '') === 'approved' && empty($result['checked_in_at']) && booking_is_calendar_tour_day($result, app_today())): ?>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="check_in">
              <input type="hidden" name="booking_code" value="<?= e($result['booking_code']) ?>">
              <button class="btn btn-ct">Mark checked in</button>
            </form>
          <?php endif; ?>
          <?php if (in_array($result['status'] ?? '', ['approved', 'completed'], true)): ?>
            <a class="btn btn-outline-secondary mt-2" href="<?= APP_URL ?>/booking-ticket.php?id=<?= (int) $result['id'] ?>" target="_blank">Open ticket</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
