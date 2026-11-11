<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_TOURIST);
ensure_booking_payment_schema();
$pageTitle = 'My Bookings';
$pageHeading = 'Booking History';
$pageSub = 'Track requests, tickets, payment, and ratings';
$activeNav = 'bookings';

mark_notifications_read((int) $user['id'], 'booking_approved');
mark_notifications_read((int) $user['id'], 'booking_cancelled');
mark_notifications_read((int) $user['id'], 'booking_rejected');
mark_notifications_read((int) $user['id'], 'booking_replacement');
mark_notifications_read((int) $user['id'], 'booking_stop_checkin');
mark_notifications_read((int) $user['id'], 'approval');
mark_notifications_read((int) $user['id'], 'refund_requested');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $b = db()->prepare('SELECT * FROM bookings WHERE id=? AND tourist_id=?');
    $b->execute([$id, $user['id']]);
    $booking = $b->fetch();

    if (!$booking) {
        flash('error', 'Booking not found.');
        redirect(APP_URL . '/tourist/bookings.php');
    }

    if ($action === 'cancel') {
        $res = booking_tourist_cancel($booking, (string) ($_POST['refund_number'] ?? ''), refund_opts_from($_POST));
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    }

    if ($action === 'payment_ref') {
        if (!in_array($booking['status'], ['pending', 'approved'], true)) {
            flash('error', 'Payment can only be updated on open bookings.');
        } elseif (($booking['payment_status'] ?? '') === 'paid') {
            flash('success', 'This booking is already marked paid.');
        } elseif (($_POST['payment_mode'] ?? '') === 'pay_at_office') {
            $res = booking_mark_payment($booking, 'pay_at_office');
            flash($res['ok'] ? 'success' : 'error', $res['message']);
        } else {
            $ref = trim((string) ($_POST['payment_reference'] ?? ''));
            if ($ref !== '') {
                $res = booking_submit_gcash_reference($booking, $ref);
                flash($res['ok'] ? 'success' : 'error', $res['message']);
            } else {
                flash('error', 'Enter a GCash reference or choose pay at the Tourism Office.');
            }
        }
    }

    if ($action === 'rate') {
        $res = booking_tourist_rate($booking, (int) $user['id'], $_POST['rating'] ?? 5, (string) ($_POST['comment'] ?? ''));
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    }

    redirect(APP_URL . '/tourist/bookings.php');
}

$live = live_tourist_bookings_payload((int) $user['id']);

$pageHeadingTools = '<div class="booking-list-tools">'
    . '<label class="guide-bookings-search">'
    . '<i class="bi bi-search" aria-hidden="true"></i>'
    . '<input type="search" id="touristBookingsSearch" placeholder="Search bookings" aria-label="Search bookings" autocomplete="off">'
    . '</label>'
    . '<select class="booking-list-sort" id="touristBookingsSort" aria-label="Sort bookings">'
    . '<option value="date-desc">Newest date</option>'
    . '<option value="date-asc">Oldest date</option>'
    . '<option value="status">Status</option>'
    . '<option value="name">Guide A–Z</option>'
    . '<option value="pending">Pending</option>'
    . '<option value="approved">Approved</option>'
    . '<option value="cancelled">Cancelled</option>'
    . '<option value="rejected">Rejected</option>'
    . '</select></div>';
$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?= booking_refund_receipt_modal() ?>

      <div class="modal fade" id="touristRefundModal" tabindex="-1" aria-labelledby="touristRefundLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="id" value="" data-refund-id>
              <div class="modal-header border-0">
                <h2 class="modal-title h5" id="touristRefundLabel">Cancel and refund</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body pt-0">
                <p class="small text-muted">Where should we send the money?</p>
                <?= booking_refund_destination_fields('touristRefundNumber') ?>
              </div>
              <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
                <button type="submit" class="btn btn-outline-danger">Cancel and refund</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div class="modal fade booking-detail-modal" id="touristSummaryModal" tabindex="-1" aria-labelledby="touristSummaryLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <div class="itin-book-review-heading">
                  <h2 class="modal-title fs-5 mb-0" id="touristSummaryLabel">Booking summary</h2>
                  <a class="itin-book-review-view" id="touristSummaryItinerary" href="#" target="_blank" rel="noopener" hidden>View itinerary</a>
                </div>
                <div class="small text-muted">Review the booking</div>
                <div class="small" id="touristSummaryStatus"></div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="touristSummaryBody">
              <p class="text-muted mb-0">Loading…</p>
            </div>
          </div>
        </div>
      </div>
      <style>
        .booking-detail-modal .modal-dialog { max-width: min(760px, 94vw); }
        .booking-detail-modal .modal-content { background: var(--ct-bg); color: var(--ct-ink); border: 1px solid var(--ct-border); }
      </style>
      <script>
      document.addEventListener('click', function (e) {
        var summaryBtn = e.target.closest('[data-tourist-summary]');
        if (summaryBtn) {
          var modalEl = document.getElementById('touristSummaryModal');
          var body = document.getElementById('touristSummaryBody');
          var status = document.getElementById('touristSummaryStatus');
          var viewLink = document.getElementById('touristSummaryItinerary');
          if (!modalEl || !body || typeof bootstrap === 'undefined') return;
          if (status) status.textContent = '';
          if (viewLink) viewLink.hidden = true;
          body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
          var id = summaryBtn.getAttribute('data-tourist-summary') || '';
          var base = (window.CulTour && window.CulTour.appUrl) ? window.CulTour.appUrl : '';
          fetch(base + '/api/bookings/summary.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (res) {
              if (!res.ok || !res.data || !res.data.ok) {
                body.innerHTML = '<p class="text-muted mb-0">' + ((res.data && res.data.message) ? res.data.message : 'Could not load this booking.') + '</p>';
                return;
              }
              if (status) status.textContent = res.data.code ? (res.data.code + (res.data.status ? ' · ' + res.data.status : '')) : '';
              if (viewLink && res.data.itinerary_id) {
                viewLink.href = base + '/itinerary-share.php?id=' + encodeURIComponent(res.data.itinerary_id);
                viewLink.hidden = false;
              }
              body.innerHTML = res.data.html || '';
            })
            .catch(function () {
              body.innerHTML = '<p class="text-muted mb-0">Could not load this booking.</p>';
            });
          return;
        }
        var btn = e.target.closest('[data-refund-booking]');
        if (!btn) return;
        var input = document.querySelector('#touristRefundModal [data-refund-id]');
        if (input) input.value = btn.getAttribute('data-refund-booking') || '';
      });
      </script>
      <?= booking_refund_destination_script() ?>

      <div data-live-page="tourist_bookings" data-live-version="<?= e($live['version']) ?>">
        <?= $live['html'] ?>
      </div>
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
      document.addEventListener('cultour:live-replaced', function () {
        parkBookingDetailsModals();
        if (window.touristBookingsFilter) window.touristBookingsFilter();
      });
      (function () {
        var input = document.getElementById('touristBookingsSearch');
        var sort = document.getElementById('touristBookingsSort');
        var list = document.querySelector('[data-live-page="tourist_bookings"]');
        if (!input || !list) return;
        var statusRank = { pending: 0, approved: 1, completed: 2, rejected: 3, cancelled: 4 };
        function apply() {
          var q = input.value.trim().toLowerCase();
          var mode = sort ? sort.value : 'date-desc';
          var filter = (mode === 'pending' || mode === 'approved' || mode === 'cancelled' || mode === 'rejected') ? mode : '';
          var cards = Array.prototype.slice.call(list.querySelectorAll('[data-booking-id]'));
          cards.forEach(function (card) {
            var text = (card.textContent || '').toLowerCase();
            var status = card.getAttribute('data-sort-status') || '';
            card.hidden = (q !== '' && text.indexOf(q) === -1) || (filter !== '' && status !== filter);
          });
          cards.sort(function (a, b) {
            if (mode === 'name') return (a.getAttribute('data-sort-name') || '').localeCompare(b.getAttribute('data-sort-name') || '');
            if (mode === 'status' || filter !== '') return (statusRank[a.getAttribute('data-sort-status')] ?? 9) - (statusRank[b.getAttribute('data-sort-status')] ?? 9);
            var ad = a.getAttribute('data-sort-date') || '';
            var bd = b.getAttribute('data-sort-date') || '';
            return mode === 'date-asc' ? ad.localeCompare(bd) : bd.localeCompare(ad);
          });
          cards.forEach(function (card) {
            if (card.parentElement) card.parentElement.appendChild(card);
          });
        }
        window.touristBookingsFilter = apply;
        input.addEventListener('input', apply);
        if (sort) sort.addEventListener('change', apply);
      })();
      </script>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php
require __DIR__ . '/../includes/footer.php'; ?>
