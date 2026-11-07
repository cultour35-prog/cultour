<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qr.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_booking_payment_schema();
$pageTitle = 'Bookings';
$pageHeading = 'Bookings';
$pageSub = 'Accept or decline paid booking requests';
$activeNav = 'bookings';

$profile = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([$user['id']]);
$profile = $profile->fetch();
$gid = (int) $profile['id'];
$hasSchedule = guide_has_weekly_availability($gid);

mark_notifications_read((int) $user['id'], 'booking_request');
mark_notifications_read((int) $user['id'], 'booking_cancelled');
mark_notifications_read((int) $user['id'], 'booking_replacement');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $id = (int) ($_POST['id'] ?? 0);
    $b = db()->prepare('SELECT * FROM bookings WHERE id=? AND (guide_id=? OR original_guide_id=?)');
    $b->execute([$id, $gid, $gid]);
    $booking = $b->fetch();
    if (!$booking) {
        flash('error', 'Booking not found.');
        redirect(APP_URL . '/tourguide/bookings.php');
    }

    $action = $_POST['action'] ?? '';
    $isCurrent = (int) ($booking['guide_id'] ?? 0) === $gid;
    if (!$isCurrent && in_array($action, ['approve', 'decline', 'complete', 'pay_at_office', 'withdraw', 'cancel'], true)) {
        flash('error', 'This booking is no longer assigned to you.');
        redirect(APP_URL . '/tourguide/bookings.php');
    }
    if ($action === 'approve') {
        $res = booking_guide_approve($booking, $user);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'decline') {
        $res = booking_guide_decline(
            $booking,
            (string) ($_POST['reason_preset'] ?? ''),
            (string) ($_POST['reason_detail'] ?? '')
        );
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'complete') {
        $res = booking_guide_complete($booking, $gid);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'pay_at_office') {
        $res = booking_mark_payment($booking, 'pay_at_office');
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'withdraw') {
        $res = booking_guide_withdraw(
            $booking,
            (string) ($_POST['from_date'] ?? ''),
            (string) ($_POST['reason_preset'] ?? ''),
            (string) ($_POST['reason_detail'] ?? '')
        );
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'cancel') {
        $res = booking_guide_cancel_approved(
            $booking,
            (string) ($_POST['reason_preset'] ?? ''),
            (string) ($_POST['reason_detail'] ?? '')
        );
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect(APP_URL . '/tourguide/bookings.php');
}

$live = guide_bookings_live_payload($gid);
$extraScripts = '<script src="' . APP_URL . '/assets/js/guide-bookings.js?v='
    . (@filemtime(ROOT_PATH . '/assets/js/guide-bookings.js') ?: time())
    . '"></script>'
    . <<<'HTML'
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
  var url = (window.CulTour && window.CulTour.appUrl ? window.CulTour.appUrl : '') + '/api/bookings/detail.php?id=' + encodeURIComponent(id);
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

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <?php if (!$hasSchedule): ?>
        <div class="alert alert-warning">
          Tourists cannot book you until you set weekly hours.
          <a href="<?= APP_URL ?>/tourguide/availability.php">Set availability</a>
        </div>
      <?php endif; ?>

      <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/tourguide/schedule.php"><i class="bi bi-calendar-week"></i> Schedule</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/tourguide/availability.php"><i class="bi bi-calendar3"></i> Availability</a>
      </div>

      <div
        id="guideBookingsLive"
        data-version="<?= e($live['version']) ?>"
        data-pending="<?= (int) $live['pending'] ?>"
        data-newest-id="<?= (int) $live['newest_id'] ?>"
      >
        <?= $live['html'] ?>
      </div>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
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
<script>
function parkWithdrawModals() {
  document.querySelectorAll('body > [data-withdraw-modal]').forEach(function (el) {
    if (!el.classList.contains('show')) el.remove();
  });
  document.querySelectorAll('[data-withdraw-modal]').forEach(function (el) {
    if (el.parentElement !== document.body) document.body.appendChild(el);
  });
}
parkWithdrawModals();
document.addEventListener('cultour:live-replaced', parkWithdrawModals);
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
