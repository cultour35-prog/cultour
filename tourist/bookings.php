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
      <script>
      document.addEventListener('click', function (e) {
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
      document.addEventListener('cultour:live-replaced', parkBookingDetailsModals);
      </script>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php
require __DIR__ . '/../includes/footer.php'; ?>
