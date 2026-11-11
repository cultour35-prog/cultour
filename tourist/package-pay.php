<?php
/**
 * GCash checkout for a tour package. The amount is the price stored on the package.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

$user = require_login(ROLE_TOURIST);

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$intentQ = trim((string) ($_GET['intent'] ?? $_POST['intent'] ?? ''));
$order = package_order_for_tourist($orderId, (int) $user['id']);

if (!$order) {
    flash('error', 'Package booking not found.');
    redirect(APP_URL . '/tourist/packages.php');
}

$intent = trim((string) ($order['payment_intent_id'] ?? ''));
if ($intent === '' || ($intentQ !== '' && $intentQ !== $intent)) {
    flash('error', 'Invalid payment link.');
    redirect(APP_URL . '/tourist/packages.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'pay_at_office' && ($order['payment_status'] ?? '') !== 'paid') {
        $res = package_order_mark_office($order);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect(APP_URL . '/tourist/packages.php');
    }
}

$order = package_order_for_tourist($orderId, (int) $user['id']) ?: $order;
$alreadyPaid = ($order['payment_status'] ?? '') === 'paid';
$pendingReview = ($order['payment_status'] ?? '') === 'pending_review';
$amount = (float) ($order['amount'] ?? 0);

if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'payment_status' => (string) ($order['payment_status'] ?? ''),
        'reference' => (string) ($order['payment_reference'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$scanUrl = (!$alreadyPaid && !$pendingReview) ? package_payment_scan_url((int) $order['id'], $intent) : '';
$qrSvg = $scanUrl !== '' ? payment_qr_svg($scanUrl) : '';
$pageTitle = 'Pay with GCash';
$pageHeading = 'GCash payment';
$activeNav = 'packages';

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
                  Already paid<?= !empty($order['payment_reference']) ? ' · Ref ' . e($order['payment_reference']) : '' ?>.
                </div>
                <a class="btn btn-ct w-100" href="<?= APP_URL ?>/tourist/packages.php">Back to tour packages</a>
              </div>
            <?php elseif ($pendingReview): ?>
              <div class="gcash-pay__done">
                <div class="alert alert-info mb-0">
                  Reference submitted<?= !empty($order['payment_reference']) ? ' · Ref ' . e($order['payment_reference']) : '' ?>.
                  Waiting for the Tourism Office to confirm this GCash payment.
                </div>
                <a class="btn btn-ct w-100" href="<?= APP_URL ?>/tourist/packages.php">Back to tour packages</a>
              </div>
            <?php else: ?>
              <div class="small text-muted">Amount to pay</div>
              <div class="gcash-pay__amount"><?= e(format_money($amount)) ?></div>
              <div hidden data-pay-wait data-pay-poll="<?= e(package_payment_checkout_url((int) $order['id'], $intent) . '&poll=1') ?>"></div>
              <?php if ($qrSvg !== ''): ?>
                <div class="gcash-pay__qr-wrap">
                  <?= $qrSvg ?>
                </div>
              <?php else: ?>
                <div class="alert alert-warning">Could not build the payment QR on this computer.</div>
              <?php endif; ?>
              <dl class="gcash-pay__meta row mb-3">
                <dt class="col-5">Pay to</dt><dd class="col-7"><?= e(defined('GCASH_ACCOUNT_LABEL') ? GCASH_ACCOUNT_LABEL : 'CulTour GCash') ?></dd>
                <dt class="col-5">Booking</dt><dd class="col-7"><?= e($order['order_code']) ?></dd>
                <dt class="col-5">Package</dt><dd class="col-7"><?= e($order['package_name']) ?></dd>
                <dt class="col-5">Business</dt><dd class="col-7"><?= e($order['business_name']) ?></dd>
                <?php
                  $tourDays = (int) ($order['package_days'] ?? 0);
                  $tourWhen = trim((string) ($order['tour_date'] ?? ''));
                ?>
                <?php if ($tourDays > 0): ?>
                  <dt class="col-5">Length</dt><dd class="col-7"><?= e(package_days_label($tourDays)) ?></dd>
                <?php endif; ?>
                <?php if ($tourWhen !== ''): ?>
                  <dt class="col-5">Tour date</dt><dd class="col-7"><?= e(package_tour_span_label($tourWhen, max(1, $tourDays))) ?></dd>
                <?php endif; ?>
              </dl>
              <form method="post" class="d-grid gap-2"><?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
