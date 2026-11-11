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
    if ((string) ($_POST['action'] ?? '') === 'package_clearance') {
        $res = package_clearance_respond((int) ($_POST['clearance_id'] ?? 0), (int) $user['id'], 'guide', (string) ($_POST['decision'] ?? '') === 'accept');
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect(APP_URL . '/tourguide/bookings.php');
    }
    if ((string) ($_POST['action'] ?? '') === 'package_decline') {
        require_once __DIR__ . '/../includes/inhouse_guides.php';
        inhouse_guides_ensure_schema();
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $own = db()->prepare("SELECT a.order_id, o.business_id, b.user_id AS business_user_id, o.package_name
            FROM package_guide_assignments a
            JOIN package_orders o ON o.id=a.order_id
            JOIN businesses b ON b.id=o.business_id
            WHERE a.order_id=? AND a.guide_id=? AND a.status='assigned' LIMIT 1");
        $own->execute([$orderId, $gid]);
        $job = $own->fetch();
        if (!$job) {
            flash('error', 'This package is no longer assigned to you.');
        } elseif ($reason === '') {
            flash('error', 'Enter a reason for declining this package.');
        } else {
            db()->prepare("UPDATE package_guide_assignments SET status='needs_replacement', admin_note=? WHERE order_id=? AND guide_id=?")
                ->execute([mb_substr($reason, 0, 255), $orderId, $gid]);
            $admins = db()->query("SELECT id FROM users WHERE role_id=" . (int) ROLE_ADMIN . " AND status='active'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($admins as $adminId) {
                notify_user((int) $adminId, 'booking_replacement', 'Tour guide declined a package', 'A system tour guide declined ' . (string) $job['package_name'] . '. Assign a replacement.', APP_URL . '/admin/guide-requests.php');
            }
            if ((int) ($job['business_user_id'] ?? 0) > 0) {
                notify_user((int) $job['business_user_id'], 'guide_assignment', 'Tour guide changed', 'The tour guide declined this package. The Tourism Office will assign another guide.', APP_URL . '/business/bookings.php');
            }
            flash('success', 'Package declined. The Tourism Office will assign another guide.');
        }
        redirect(APP_URL . '/tourguide/bookings.php');
    }
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
require_once __DIR__ . '/../includes/inhouse_guides.php';
inhouse_guides_ensure_schema();
$packageJobs = db()->prepare(
    "SELECT o.id, o.order_code, o.package_name, o.tour_date, o.business_name
     FROM package_guide_assignments a
     JOIN package_orders o ON o.id=a.order_id
     WHERE a.guide_id=? AND a.status='assigned'
     ORDER BY o.tour_date IS NULL, o.tour_date"
);
$packageJobs->execute([$gid]);
$packageJobs = $packageJobs->fetchAll() ?: [];
$extraScripts = '<script src="' . APP_URL . '/assets/js/guide-bookings.js?v='
    . (@filemtime(ROOT_PATH . '/assets/js/guide-bookings.js') ?: time())
    . '"></script>'
    . <<<'HTML'
<style>
  .booking-detail-modal .modal-dialog { max-width: min(760px, 94vw); }
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
  title.textContent = 'Booking summary';
  status.textContent = '';
  var viewLink = document.getElementById('bookingDetailItinerary');
  if (viewLink) viewLink.hidden = true;
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
      title.textContent = 'Booking summary';
      if (res.data.status) status.textContent = res.data.code ? (res.data.code + ' · ' + res.data.status) : res.data.status;
      if (viewLink && res.data.itinerary_id) {
        var base = (window.CulTour && window.CulTour.appUrl) ? window.CulTour.appUrl : '';
        viewLink.href = base + '/itinerary-share.php?id=' + encodeURIComponent(res.data.itinerary_id);
        viewLink.hidden = false;
      }
      body.innerHTML = res.data.html || '';
    })
    .catch(function () {
      body.innerHTML = '<p class="bkd-muted mb-0">Could not load this booking.</p>';
    });
});
</script>
HTML;

$pageHeadingTools = '<div class="booking-list-tools">'
    . '<label class="guide-bookings-search">'
    . '<i class="bi bi-search" aria-hidden="true"></i>'
    . '<input type="search" id="guideBookingsSearch" placeholder="Search bookings" aria-label="Search bookings" autocomplete="off">'
    . '</label>'
    . '<select class="booking-list-sort" id="guideBookingsSort" aria-label="Sort bookings">'
    . '<option value="date-desc">Newest date</option>'
    . '<option value="date-asc">Oldest date</option>'
    . '<option value="status">Status</option>'
    . '<option value="name">Name A–Z</option>'
    . '</select></div>';
$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php
        package_clearance_ensure_schema();
        $guideClearSt = db()->prepare(
            "SELECT c.id, s.name, s.package_fixed_date
             FROM package_clearances c
             JOIN business_services s ON s.id=c.service_id
             WHERE c.guide_id=? AND c.status='pending'
             ORDER BY c.id DESC"
        );
        $guideClearSt->execute([$gid]);
        $guideClearances = $guideClearSt->fetchAll() ?: [];
      ?>
      <?php foreach ($guideClearances as $clearance): ?>
        <div class="glass p-3 mb-3">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
              <div class="fw-semibold"><?= e((string) $clearance['name']) ?></div>
              <div class="small text-muted">Inclusive package<?= !empty($clearance['package_fixed_date']) ? ' · ' . e(format_date((string) $clearance['package_fixed_date'])) : '' ?></div>
            </div>
            <form method="post" class="d-flex gap-2"><?= csrf_field() ?>
              <input type="hidden" name="action" value="package_clearance">
              <input type="hidden" name="clearance_id" value="<?= (int) $clearance['id'] ?>">
              <button class="btn btn-ct btn-sm" name="decision" value="accept" type="submit">Accept</button>
              <button class="btn btn-outline-secondary btn-sm" name="decision" value="decline" type="submit">Decline</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php foreach ($packageJobs as $job): ?>
        <div class="glass p-3 mb-3">
          <div class="d-flex flex-wrap justify-content-between gap-2">
            <div>
              <div class="fw-semibold"><?= e((string) $job['package_name']) ?></div>
              <div class="small text-muted"><?= e((string) $job['order_code']) ?> · <?= e((string) $job['business_name']) ?><?= !empty($job['tour_date']) ? ' · ' . e(format_date((string) $job['tour_date'])) : '' ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#declinePackage<?= (int) $job['id'] ?>">Decline</button>
          </div>
        </div>
        <div class="modal fade" id="declinePackage<?= (int) $job['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="package_decline">
                <input type="hidden" name="order_id" value="<?= (int) $job['id'] ?>">
                <div class="modal-header">
                  <h2 class="modal-title h5">Decline <?= e((string) $job['order_code']) ?></h2>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <label class="form-label" for="declinePackageReason<?= (int) $job['id'] ?>">Reason</label>
                  <textarea class="form-control" id="declinePackageReason<?= (int) $job['id'] ?>" name="reason" rows="3" required placeholder="Why are you declining this package?"></textarea>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-outline-danger" type="submit">Decline</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <?php if (!$hasSchedule): ?>
        <div class="alert alert-warning">
          Tourists cannot book you until you set weekly hours.
          <a href="<?= APP_URL ?>/tourguide/availability.php">Set availability</a>
        </div>
      <?php endif; ?>

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
          <div class="itin-book-review-heading">
            <h2 class="modal-title fs-5 mb-0" id="bookingDetailLabel">Booking summary</h2>
            <a class="itin-book-review-view" id="bookingDetailItinerary" href="#" target="_blank" rel="noopener" hidden>View itinerary</a>
          </div>
          <div class="small text-muted">Review the booking</div>
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
(function () {
  var input = document.getElementById('guideBookingsSearch');
  var sort = document.getElementById('guideBookingsSort');
  var list = document.getElementById('guideBookingsLive');
  if (!input || !list) return;
  var statusRank = { pending: 0, approved: 1, completed: 2, rejected: 3, cancelled: 4 };
  var observer = new MutationObserver(apply);
  function apply() {
    observer.disconnect();
    var q = input.value.trim().toLowerCase();
    var mode = sort ? sort.value : 'date-desc';
    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-booking-id]'));
    cards.forEach(function (card) {
      card.hidden = q !== '' && (card.textContent || '').toLowerCase().indexOf(q) === -1;
    });
    cards.sort(function (a, b) {
      if (mode === 'name') return (a.getAttribute('data-sort-name') || '').localeCompare(b.getAttribute('data-sort-name') || '');
      if (mode === 'status') return (statusRank[a.getAttribute('data-sort-status')] ?? 9) - (statusRank[b.getAttribute('data-sort-status')] ?? 9);
      var ad = a.getAttribute('data-sort-date') || '';
      var bd = b.getAttribute('data-sort-date') || '';
      return mode === 'date-asc' ? ad.localeCompare(bd) : bd.localeCompare(ad);
    });
    cards.forEach(function (card) {
      if (card.parentElement) card.parentElement.appendChild(card);
    });
    observer.observe(list, { childList: true, subtree: true });
  }
  input.addEventListener('input', apply);
  if (sort) sort.addEventListener('change', apply);
  apply();
})();
document.addEventListener('cultour:live-replaced', parkWithdrawModals);
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
