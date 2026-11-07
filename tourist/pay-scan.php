<?php
/**
 * Phone page opened by scanning the checkout QR.
 * Confirms the booking payment inside CulTour. It does not charge a real GCash wallet.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

$kind = trim((string) ($_GET['kind'] ?? $_POST['kind'] ?? ''));
$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$intent = trim((string) ($_GET['intent'] ?? $_POST['intent'] ?? ''));
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$isPackage = $kind === 'package';
$subjectId = $isPackage ? $orderId : $bookingId;

$booking = null;
$packageOrder = null;
$error = '';
if (!payment_scan_token_ok($subjectId, $intent, $token)) {
    $error = 'This payment code is not valid.';
} elseif ($isPackage) {
    ensure_package_order_schema();
    $st = db()->prepare('SELECT * FROM package_orders WHERE id=? AND payment_intent_id=? LIMIT 1');
    $st->execute([$orderId, $intent]);
    $packageOrder = $st->fetch() ?: null;
    if (!$packageOrder) {
        $error = 'This payment could not be found.';
    } else {
        $booking = $packageOrder;
        $booking['booking_code'] = $packageOrder['order_code'];
        $booking['guide_first'] = $packageOrder['package_name'];
        $booking['guide_last'] = '';
        $booking['status'] = 'approved';
        $booking['payment_amount'] = $packageOrder['amount'];
    }
} else {
    ensure_booking_payment_schema();
    $st = db()->prepare(
        "SELECT b.*, u.first_name AS guide_first, u.last_name AS guide_last
         FROM bookings b
         JOIN tour_guide_profiles g ON g.id = b.guide_id
         JOIN users u ON u.id = g.user_id
         WHERE b.id=? AND b.payment_intent_id=?
         LIMIT 1"
    );
    $st->execute([$bookingId, $intent]);
    $booking = $st->fetch() ?: null;
    if (!$booking) {
        $error = 'This payment could not be found.';
    }
}

$paid = $booking && ($booking['payment_status'] ?? '') === 'paid';
$reference = $booking ? (string) ($booking['payment_reference'] ?? '') : '';

if ($booking && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'pay_scan') {
    $res = $isPackage && $packageOrder ? package_order_complete_scan($packageOrder) : payment_complete_scan($booking);
    if (!$res['ok']) {
        $error = $res['message'];
    } else {
        $paid = true;
        $reference = (string) ($res['reference'] ?? $reference);
        $booking['payment_status'] = 'paid';
        $booking['payment_reference'] = $reference;
    }
}

$amount = $booking ? (float) ($booking['payment_amount'] ?? 0) : 0;
$guideName = $booking ? trim(($booking['guide_first'] ?? '') . ' ' . ($booking['guide_last'] ?? '')) : '';
$blocked = $booking && !$paid && !in_array((string) ($booking['status'] ?? ''), ['pending', 'approved'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Pay · CulTour</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Segoe UI", system-ui, sans-serif;
      background: #f3f6f8;
      color: #16303a;
    }
    .sheet {
      max-width: 440px;
      margin: 0 auto;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: #fff;
    }
    .brand {
      background: linear-gradient(160deg, #0a7dff 0%, #0057c2 100%);
      color: #fff;
      padding: calc(1.15rem + env(safe-area-inset-top)) 1.25rem 1.35rem;
    }
    .brand small {
      display: block;
      opacity: 0.82;
      font-size: 0.78rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .brand strong { font-size: 1.55rem; letter-spacing: -0.02em; }
    .body {
      flex: 1;
      padding: 1.35rem 1.25rem calc(1.25rem + env(safe-area-inset-bottom));
    }
    .kicker {
      margin: 0;
      color: #6b808c;
      font-size: 0.82rem;
    }
    .amount {
      margin: 0.15rem 0 1.15rem;
      font-size: 2.65rem;
      font-weight: 750;
      letter-spacing: -0.03em;
      line-height: 1.05;
    }
    .card {
      border: 1px solid #e4ecef;
      border-radius: 1rem;
      overflow: hidden;
    }
    .row {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.85rem 1rem;
      font-size: 0.95rem;
    }
    .row + .row { border-top: 1px solid #e4ecef; }
    .row span { color: #6b808c; }
    .row strong { text-align: right; }
    .pay {
      display: block;
      width: 100%;
      margin-top: 1.35rem;
      border: 0;
      border-radius: 0.9rem;
      background: #007dff;
      color: #fff;
      font-size: 1.05rem;
      font-weight: 700;
      padding: 0.95rem 1rem;
      box-shadow: 0 10px 22px rgba(0, 125, 255, 0.28);
    }
    .ok { text-align: center; padding-top: 1.75rem; }
    .ok .mark {
      display: inline-grid;
      place-items: center;
      width: 4.5rem;
      height: 4.5rem;
      margin-bottom: 0.85rem;
      border-radius: 999px;
      background: #e8f8ef;
      color: #128a4a;
      font-size: 2.1rem;
      font-weight: 700;
    }
    .ok h1 { margin: 0 0 0.35rem; font-size: 1.55rem; }
    .ok .amount { margin-bottom: 0.35rem; }
    .ref { margin: 0; color: #6b808c; font-size: 0.92rem; }
    .err {
      background: #fff4f2;
      color: #9b2c20;
      padding: 0.85rem 0.95rem;
      border-radius: 0.85rem;
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="brand">
      <small>CulTour checkout</small>
      <strong>GCash</strong>
    </div>
    <div class="body">
      <?php if ($error !== '' && !$paid): ?>
        <div class="err"><?= e($error) ?></div>
      <?php elseif ($paid): ?>
        <div class="ok">
          <div class="mark">✓</div>
          <h1>Paid</h1>
          <div class="amount"><?= e(format_money($amount)) ?></div>
          <?php if ($reference !== ''): ?>
            <p class="ref"><?= e($reference) ?></p>
          <?php endif; ?>
          <?php if (!$isPackage && ($booking['status'] ?? '') === 'pending'): ?>
            <p class="ref">Waiting for the tour guide to accept.</p>
          <?php endif; ?>
        </div>
      <?php elseif ($blocked): ?>
        <div class="err">This booking cannot be paid right now.</div>
      <?php else: ?>
        <p class="kicker">Amount to pay</p>
        <div class="amount"><?= e(format_money($amount)) ?></div>
        <div class="card">
          <div class="row"><span>Pay to</span><strong>CulTour GCash</strong></div>
          <div class="row"><span>Booking</span><strong><?= e($booking['booking_code'] ?? '') ?></strong></div>
          <div class="row"><span><?= $isPackage ? 'Package' : 'Guide' ?></span><strong><?= e($guideName) ?></strong></div>
        </div>
        <form method="post">
          <?php if ($isPackage): ?>
            <input type="hidden" name="kind" value="package">
            <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">
          <?php else: ?>
            <input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">
          <?php endif; ?>
          <input type="hidden" name="intent" value="<?= e($intent) ?>">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <input type="hidden" name="action" value="pay_scan">
          <button class="pay" type="submit">Pay <?= e(format_money($amount)) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
