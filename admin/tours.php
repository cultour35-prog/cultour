<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_ADMIN);
ensure_booking_payment_schema();
ensure_booking_stop_checkin_schema();

$pageTitle = 'Current tours';
$pageHeading = 'Current tours';
$pageSub = 'Approved tours on the selected day, with stop check-in photos from the guide';
$activeNav = 'tours';

mark_notifications_read((int) $user['id'], 'booking_stop_checkin');

$today = app_today();
$date = normalize_tour_date((string) ($_GET['date'] ?? '')) ?: $today;
$focusId = (int) ($_GET['booking'] ?? 0);
$live = live_admin_tours_payload($date, $focusId);

$yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
$tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <div class="d-flex flex-wrap justify-content-between gap-2 align-items-end mb-3">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
          <div>
            <label class="form-label small mb-1" for="tourDate">Tour day</label>
            <input class="form-control form-control-sm" type="date" id="tourDate" name="date" value="<?= e($date) ?>">
          </div>
          <button class="btn btn-sm btn-ct" type="submit">Show</button>
        </form>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-outline-secondary" href="?date=<?= e($yesterday) ?>">Previous day</a>
          <a class="btn btn-sm btn-outline-secondary" href="?date=<?= e($today) ?>">Today</a>
          <a class="btn btn-sm btn-outline-secondary" href="?date=<?= e($tomorrow) ?>">Next day</a>
          <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/admin/bookings.php">All bookings</a>
        </div>
      </div>

      <div data-live-page="admin_tours" data-live-version="<?= e($live['version']) ?>" data-live-query="<?= e(http_build_query(array_filter(['date' => $date, 'booking' => $focusId ?: null]))) ?>">
        <?= $live['html'] ?>
      </div>
    </div>
  </div>
</div>
<script>
function parkAdminTourModals() {
  document.querySelectorAll('body > [data-admin-tour-modal]').forEach(function (el) {
    if (!el.classList.contains('show')) el.remove();
  });
  document.querySelectorAll('[data-admin-tour-modal]').forEach(function (el) {
    if (el.parentElement !== document.body) document.body.appendChild(el);
  });
}
parkAdminTourModals();
document.addEventListener('cultour:live-replaced', parkAdminTourModals);
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-admin-day]');
  if (!btn) return;
  var modal = btn.closest('[data-admin-tour-modal]');
  if (!modal) return;
  var id = btn.getAttribute('data-admin-day');
  modal.querySelectorAll('[data-admin-day]').forEach(function (el) {
    var on = el === btn;
    el.classList.toggle('is-on', on);
    el.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  modal.querySelectorAll('[data-admin-day-pane]').forEach(function (pane) {
    pane.hidden = pane.getAttribute('data-admin-day-pane') !== id;
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
