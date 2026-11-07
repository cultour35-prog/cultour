<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_ADMIN);
ensure_booking_payment_schema();
$pageTitle = 'Bookings';
$pageHeading = 'Manage Bookings';
$activeNav = 'bookings';

mark_notifications_read((int) $user['id'], 'booking_replacement');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id=?');
    $stmt->execute([$id]);
    $b = $stmt->fetch();
    if ($b) {
        if ($action === 'approve') {
            $guideUser = db()->prepare(
                'SELECT u.* FROM tour_guide_profiles gp JOIN users u ON u.id=gp.user_id WHERE gp.id=?'
            );
            $guideUser->execute([(int) $b['guide_id']]);
            $guideUser = $guideUser->fetch() ?: $user;
            // Admin may approve even if status already pending — use helper when pending
            if (($b['status'] ?? '') === 'pending') {
                $res = booking_guide_approve($b, $guideUser);
                flash($res['ok'] ? 'success' : 'error', $res['message']);
                redirect(APP_URL . '/admin/bookings.php');
            }
            if (($b['status'] ?? '') !== 'rejected') {
                flash('error', 'Only pending or rejected bookings can be approved.');
                redirect(APP_URL . '/admin/bookings.php');
            }
            // Force approve rejected (admin override) with QR
            db()->prepare("UPDATE bookings SET status='approved' WHERE id=?")->execute([$id]);
            $b['status'] = 'approved';
            $tourist = db()->prepare('SELECT * FROM users WHERE id=?');
            $tourist->execute([(int) $b['tourist_id']]);
            $tourist = $tourist->fetch();
            $destination = null;
            if (!empty($b['destination_id'])) {
                $d = db()->prepare('SELECT id, name FROM destinations WHERE id=?');
                $d->execute([(int) $b['destination_id']]);
                $destination = $d->fetch() ?: null;
            }
            $itinerary = null;
            if (!empty($b['itinerary_id'])) {
                $i = db()->prepare('SELECT id, title FROM itineraries WHERE id=?');
                $i->execute([(int) $b['itinerary_id']]);
                $itinerary = $i->fetch() ?: null;
            }
            booking_qr_for($b, $tourist ?: null, $guideUser ?: null, $destination, $itinerary);
            notify_user((int) $b['tourist_id'], 'booking_approved', 'Booking approved', 'Your booking ' . $b['booking_code'] . ' was approved.', APP_URL . '/tourist/bookings.php');
        } elseif ($action === 'cancel') {
            if (!in_array((string) ($b['status'] ?? ''), ['pending', 'approved'], true)) {
                flash('error', 'Only pending or approved bookings can be cancelled.');
                redirect(APP_URL . '/admin/bookings.php');
            }
            db()->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$id]);
            notify_user((int) $b['tourist_id'], 'booking_cancelled', 'Booking cancelled', 'Booking ' . $b['booking_code'] . ' was cancelled.', APP_URL . '/tourist/bookings.php');
            $g = db()->prepare('SELECT user_id FROM tour_guide_profiles WHERE id=?');
            $g->execute([(int) $b['guide_id']]);
            $guideUserId = (int) $g->fetchColumn();
            if ($guideUserId) {
                notify_user($guideUserId, 'booking_cancelled', 'Booking cancelled', 'Admin cancelled ' . $b['booking_code'] . '.', APP_URL . '/tourguide/bookings.php');
            }
        } elseif ($action === 'mark_paid' || $action === 'confirm_gcash') {
            $res = booking_mark_payment($b, 'paid', $_POST['payment_reference'] ?? null, ['admin' => true]);
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'GCash reference confirmed. Booking marked paid.' : $res['message']);
            $pendingQueue = $action === 'confirm_gcash' || ($b['payment_status'] ?? '') === 'pending_review';
            redirect(APP_URL . '/admin/bookings.php' . ($pendingQueue ? '?payment=pending' : ''));
        } elseif ($action === 'pay_at_office') {
            $res = booking_mark_payment($b, 'pay_at_office');
            flash($res['ok'] ? 'success' : 'error', $res['message']);
            redirect(APP_URL . '/admin/bookings.php');
        } elseif ($action === 'assign_replacement') {
            $res = booking_assign_replacement(
                $b,
                (int) ($_POST['replacement_guide_id'] ?? 0),
                (int) $user['id']
            );
            flash($res['ok'] ? 'success' : 'error', $res['message']);
            redirect(APP_URL . '/admin/bookings.php');
        }
        flash('success', 'Booking updated.');
    }
    redirect(APP_URL . '/admin/bookings.php');
}

$paymentFilter = (string) ($_GET['payment'] ?? '');
[$bookingSort, $bookingSortDir] = admin_bookings_sort_params((string) ($_GET['sort'] ?? 'date'), (string) ($_GET['dir'] ?? 'desc'));
$liveQuery = http_build_query(array_filter([
    'payment' => $paymentFilter === 'pending' ? 'pending' : null,
    'sort' => $bookingSort,
    'dir' => $bookingSortDir,
], static fn ($v) => $v !== null && $v !== ''));
$live = live_admin_bookings_payload($paymentFilter, '', $bookingSort, $bookingSortDir);

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div class="mb-3 d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/verify-ticket.php"><i class="bi bi-qr-code-scan"></i> Verify ticket</a>
        <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/tours.php"><i class="bi bi-camera"></i> Current tours &amp; check-in</a>
      </div>
      <div data-live-page="admin_bookings" data-live-version="<?= e($live['version']) ?>" data-live-query="<?= e($liveQuery) ?>">
        <?= $live['html'] ?>
      </div>

      <div class="modal fade booking-detail-modal" id="bookingDetailModal" tabindex="-1" aria-labelledby="bookingDetailLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <h2 class="modal-title fs-5" id="bookingDetailLabel">Booking</h2>
                <div class="small" id="bookingDetailStatus"></div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="bookingDetailBody">
              <p class="bkd-muted mb-0">Loading…</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$extraScripts = <<<'HTML'
<style>
  .booking-detail-modal .modal-content { background: var(--ct-bg); color: var(--ct-ink); border: 1px solid var(--ct-border); }
  .booking-detail-modal .modal-header { border-color: var(--ct-border); align-items: flex-start; }
  .booking-detail-modal .modal-title { color: var(--ct-ink); font-weight: 700; }
  .booking-detail-modal #bookingDetailStatus { color: var(--ct-gold); font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
  .booking-detail-modal .modal-body { padding-top: .75rem; }
  .booking-detail-modal .bkd-card {
    background: var(--ct-card); color: var(--ct-ink); border-radius: var(--ct-radius);
    padding: 1rem 1.1rem 1.05rem; margin-bottom: .65rem; border: 1px solid var(--ct-border);
  }
  .booking-detail-modal .bkd-line { margin-bottom: .75rem; }
  .booking-detail-modal .bkd-line:last-child { margin-bottom: 0; }
  .booking-detail-modal .bkd-label { font-size: .72rem; letter-spacing: .05em; text-transform: uppercase; color: var(--ct-muted); margin-bottom: .12rem; }
  .booking-detail-modal .bkd-value { font-weight: 600; color: var(--ct-ink); }
  .booking-detail-modal .bkd-plain { font-weight: 400; white-space: pre-wrap; }
  .booking-detail-modal .bkd-section { font-size: .78rem; letter-spacing: .06em; text-transform: uppercase; color: var(--ct-gold); font-weight: 700; margin-bottom: .7rem; }
  .booking-detail-modal .bkd-days { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; position: relative; }
  .booking-detail-modal .bkd-day-radio {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
    clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
  }
  .booking-detail-modal .bkd-day-tab {
    order: 0; margin: 0 0 .15rem; cursor: pointer; border-radius: 999px; padding: .28rem .75rem;
    border: 1px solid var(--ct-border); background: transparent; color: var(--ct-ink);
    font-size: .8rem; font-weight: 700;
  }
  .booking-detail-modal .bkd-day-radio:checked + .bkd-day-tab {
    background: var(--ct-gold); color: #1a1408; border-color: var(--ct-gold);
  }
  .booking-detail-modal .bkd-day-radio:focus-visible + .bkd-day-tab { outline: 2px solid var(--ct-gold); outline-offset: 2px; }
  .booking-detail-modal .bkd-day-panel { display: none; order: 1; flex: 1 0 100%; }
  .booking-detail-modal .bkd-day-radio:checked + .bkd-day-tab + .bkd-day-panel { display: block; }
  .booking-detail-modal .bkd-day {
    margin: .35rem 0 .35rem; padding: .4rem .65rem; background: var(--ct-foam); color: var(--ct-ink);
    border-radius: .45rem; display: flex; justify-content: space-between; gap: .5rem; font-weight: 700;
  }
  .booking-detail-modal .bkd-stop { padding: .7rem 0; border-bottom: 1px solid var(--ct-border); }
  .booking-detail-modal .bkd-stop:last-child { border-bottom: 0; }
  .booking-detail-modal .bkd-muted { color: var(--ct-muted); font-size: .85rem; font-weight: 400; }
  .booking-detail-modal .bkd-cert { display: block; max-width: 100%; margin-top: .5rem; border-radius: .5rem; border: 1px solid var(--ct-border); background: #fff; }
  .booking-detail-modal .bkd-cert-link { margin-top: .45rem; }
  .booking-detail-modal a { color: #8fd9cf; }
</style>
<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-booking-detail]');
  if (!btn) return;
  var modalEl = document.getElementById('bookingDetailModal');
  var body = document.getElementById('bookingDetailBody');
  var title = document.getElementById('bookingDetailLabel');
  var status = document.getElementById('bookingDetailStatus');
  if (!modalEl || !body || typeof bootstrap === 'undefined') return;
  var id = btn.getAttribute('data-booking-detail') || '';
  var code = btn.getAttribute('data-booking-code') || 'Booking';
  title.textContent = code;
  status.textContent = '';
  body.innerHTML = '<p class="bkd-muted mb-0">Loading…</p>';
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
  var url = (window.CulTour && window.CulTour.appUrl ? window.CulTour.appUrl : '') + '/api/admin/booking-detail.php?id=' + encodeURIComponent(id);
  fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
    .then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        body.innerHTML = '<p class="bkd-muted mb-0">' + ((res.data && res.data.message) ? res.data.message : 'Could not load this booking.') + '</p>';
        return;
      }
      if (res.data.code) title.textContent = res.data.code;
      if (res.data.status) status.textContent = res.data.status;
      body.innerHTML = res.data.html || '';
    })
    .catch(function () {
      body.innerHTML = '<p class="bkd-muted mb-0">Could not load this booking.</p>';
    });
});
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
