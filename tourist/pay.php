<?php
/**
 * GCash checkout after booking a tour guide — shows your InstaPay/GCash QR.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

$user = require_login(ROLE_TOURIST);
ensure_booking_payment_schema();

$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$intentQ = trim((string) ($_GET['intent'] ?? $_POST['intent'] ?? ''));

$st = db()->prepare(
    "SELECT b.*,
            g.hourly_rate, g.license_number,
            u.first_name AS guide_first, u.last_name AS guide_last, u.avatar AS guide_avatar
     FROM bookings b
     JOIN tour_guide_profiles g ON g.id = b.guide_id
     JOIN users u ON u.id = g.user_id
     WHERE b.id=? AND b.tourist_id=?
     LIMIT 1"
);
$st->execute([$bookingId, $user['id']]);
$booking = $st->fetch() ?: null;

if (!$booking) {
    flash('error', 'Booking not found.');
    redirect(APP_URL . '/tourist/bookings.php');
}

$start = booking_start_gcash_payment($booking, $booking);
if (!$start['ok'] && ($booking['payment_status'] ?? '') !== 'paid') {
    flash('error', $start['message']);
    redirect(APP_URL . '/tourist/bookings.php');
}

$st->execute([$bookingId, $user['id']]);
$booking = $st->fetch();
$intent = (string) ($booking['payment_intent_id'] ?? $start['intent_id'] ?? '');
$amount = (float) ($booking['payment_amount'] ?? $start['amount'] ?? 0);

if ($intentQ !== '' && $intentQ !== $intent) {
    flash('error', 'Invalid payment link.');
    redirect(APP_URL . '/tourist/bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'confirm_gcash' && ($booking['payment_status'] ?? '') !== 'paid') {
        $res = booking_submit_gcash_reference($booking, (string) ($_POST['payment_reference'] ?? ''));
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect($res['ok'] ? (APP_URL . '/tourist/bookings.php') : payment_checkout_url($bookingId, $intent));
    }
    if ($action === 'pay_at_office') {
        $res = booking_mark_payment($booking, 'pay_at_office');
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect(APP_URL . '/tourist/bookings.php');
    }
}

$alreadyPaid = ($booking['payment_status'] ?? '') === 'paid';
$pendingReview = ($booking['payment_status'] ?? '') === 'pending_review';
$guideName = trim(($booking['guide_first'] ?? '') . ' ' . ($booking['guide_last'] ?? ''));
if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'payment_status' => (string) ($booking['payment_status'] ?? ''),
        'reference' => (string) ($booking['payment_reference'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$scanUrl = ($intent !== '' && !$alreadyPaid && !$pendingReview) ? payment_scan_url((int) $booking['id'], $intent) : '';
$qrSvg = $scanUrl !== '' ? payment_qr_svg($scanUrl) : '';
$pageTitle = 'Pay with GCash';
$pageHeading = 'GCash payment';
$activeNav = 'bookings';

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="gcash-pay">
        <div class="gcash-pay__card">
          <div class="gcash-pay__brand">
            <div>
              <div class="small opacity-75">CulTour checkout</div>
              <strong>GCash</strong>
            </div>
            <i class="bi bi-wallet2 fs-3"></i>
          </div>
          <div class="gcash-pay__body">
            <?php if ($alreadyPaid): ?>
              <div class="gcash-pay__done">
                <div class="alert alert-success mb-0">
                  Already paid<?= !empty($booking['payment_reference']) ? ' · Ref ' . e($booking['payment_reference']) : '' ?>.
                  <?php if (($booking['status'] ?? '') === 'pending'): ?>
                    Your tour guide still needs to accept this booking.
                  <?php endif; ?>
                </div>
                <a class="btn btn-ct w-100" href="<?= APP_URL ?>/tourist/bookings.php">Back to bookings</a>
              </div>
            <?php elseif ($pendingReview): ?>
              <div class="gcash-pay__done">
                <div class="alert alert-info mb-0">
                  Reference submitted<?= !empty($booking['payment_reference']) ? ' · Ref ' . e($booking['payment_reference']) : '' ?>.
                  Waiting for the Tourism Office to confirm this GCash payment.
                </div>
                <a class="btn btn-ct w-100" href="<?= APP_URL ?>/tourist/bookings.php">Back to bookings</a>
              </div>
            <?php else: ?>
              <div class="small text-muted">Amount to pay</div>
              <div class="gcash-pay__amount"><?= e(format_money($amount)) ?></div>
              <?php if (isset($booking['guide_amount']) && $booking['guide_amount'] !== null && $booking['guide_amount'] !== ''): ?>
                <dl class="gcash-pay__meta row mb-3">
                  <dt class="col-5">Guide</dt>
                  <dd class="col-7"><?= e(format_money($booking['guide_amount'])) ?></dd>
                  <dt class="col-5">Stay</dt>
                  <dd class="col-7"><?php
                    echo e(format_money($booking['accommodation_amount'] ?? 0));
                    $stayBits = [];
                    foreach (stay_lines_list($booking['accommodation_lines'] ?? null) ?: [] as $stayLine) {
                        if (!is_array($stayLine)) {
                            continue;
                        }
                        $stayBit = trim((string) ($stayLine['label'] ?? ''));
                        if ($stayBit === '') {
                            continue;
                        }
                        if (isset($stayLine['amount'])) {
                            $stayBit .= ' · ' . format_money($stayLine['amount']);
                        }
                        $stayBits[] = $stayBit;
                    }
                    if ($stayBits) {
                        echo '<div class="small text-muted">' . e(implode(', ', $stayBits)) . '</div>';
                    }
                  ?></dd>
                  <dt class="col-5">Transportation</dt>
                  <dd class="col-7"><?php
                    echo e(format_money($booking['transportation_amount'] ?? 0));
                    $rideBits = [];
                    foreach (stay_lines_list($booking['transportation_lines'] ?? null) ?: [] as $rideLine) {
                        if (!is_array($rideLine)) {
                            continue;
                        }
                        $rideBit = trim((string) ($rideLine['label'] ?? ''));
                        if ($rideBit === '') {
                            continue;
                        }
                        if (isset($rideLine['amount'])) {
                            $rideBit .= ' · ' . format_money($rideLine['amount']);
                        }
                        $rideBits[] = $rideBit;
                    }
                    if ($rideBits) {
                        echo '<div class="small text-muted">' . e(implode(', ', $rideBits)) . '</div>';
                    }
                  ?></dd>
                </dl>
              <?php endif; ?>
              <div hidden data-pay-wait data-pay-poll="<?= e(payment_checkout_url((int) $booking['id'], $intent) . '&poll=1') ?>"></div>
              <?php if ($qrSvg !== ''): ?>
                <div class="gcash-pay__qr-wrap">
                  <?= $qrSvg ?>
                </div>
              <?php else: ?>
                <div class="alert alert-warning">Could not build the payment QR on this computer.</div>
              <?php endif; ?>
              <dl class="gcash-pay__meta row mb-3">
                <dt class="col-5">Pay to</dt><dd class="col-7"><?= e(defined('GCASH_ACCOUNT_LABEL') ? GCASH_ACCOUNT_LABEL : 'CulTour GCash') ?></dd>
                <dt class="col-5">Booking</dt><dd class="col-7"><?= e($booking['booking_code']) ?></dd>
                <dt class="col-5">Tour guide</dt><dd class="col-7"><?= e($guideName) ?></dd>
                <dt class="col-5">Date</dt>
                <dd class="col-7"><?= e(format_date($booking['booking_date'])) ?> · <?= e(format_time_12h($booking['start_time'])) ?></dd>
                <dt class="col-5">Guests</dt><dd class="col-7"><?= (int) $booking['party_size'] ?></dd>
              </dl>
              <form method="post" class="d-grid gap-2"><?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                <input type="hidden" name="intent" value="<?= e($intent) ?>">
                <button class="btn btn-outline-secondary" name="action" value="pay_at_office" type="submit">
                  Pay at Tourism Office instead
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <script>
      (function () {
        var wait = document.querySelector('[data-pay-wait]');
        if (!wait) return;
        var url = wait.getAttribute('data-pay-poll');
        if (!url) return;
        function tick() {
          fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
              if (data && data.payment_status === 'paid') window.location.reload();
            })
            .catch(function () {});
        }
        setInterval(tick, 2000);
      })();
      </script>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php
require __DIR__ . '/../includes/footer.php'; ?>
