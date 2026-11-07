<?php
/**
 * Phone page opened by scanning a booking ticket QR.
 * Shows the ticket, PWD details, booking notes, and the full itinerary.
 * The link is signed, so it does not require a CulTour login.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/includes/booking_detail_cards.php';

$id = (int) ($_GET['id'] ?? 0);
$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$token = trim((string) ($_GET['token'] ?? ''));

$booking = null;
$error = '';
if (!ticket_scan_token_ok($id, $code, $token)) {
    $error = 'This ticket link is not valid.';
} else {
    ensure_booking_payment_schema();
    ensure_booking_accessibility_schema();
    $st = db()->prepare(
        "SELECT b.*,
                CONCAT(t.first_name,' ',t.last_name) AS tourist_name, t.email AS tourist_email, t.phone AS tourist_phone,
                CONCAT(gu.first_name,' ',gu.last_name) AS guide_name, gu.phone AS guide_phone,
                d.name AS destination_name, d.location AS destination_location,
                i.title AS itinerary_title, i.description AS itinerary_description,
                i.start_date AS itin_start, i.end_date AS itin_end, i.meetup_time,
                i.is_suggested, i.template_day_count
         FROM bookings b
         JOIN users t ON t.id = b.tourist_id
         JOIN tour_guide_profiles gp ON gp.id = b.guide_id
         JOIN users gu ON gu.id = gp.user_id
         LEFT JOIN destinations d ON d.id = b.destination_id
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         WHERE b.id = ? AND b.booking_code = ?
         LIMIT 1"
    );
    $st->execute([$id, $code]);
    $booking = $st->fetch() ?: null;
    if (!$booking) {
        $error = 'This ticket could not be found.';
    }
}

$certUrl = '';
if ($booking && booking_is_pwd($booking) && booking_fitness_certificate_url($booking)) {
    $certUrl = ticket_scan_cert_url($id, $code);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $booking ? e($booking['booking_code']) . ' · ' : '' ?>CulTour ticket</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", system-ui, sans-serif; background: #e8eef0; color: #14242a; }
    .sheet { max-width: 480px; margin: 0 auto; min-height: 100vh; }
    .head {
      background: linear-gradient(135deg, #0b5f6b, #1a8a7a);
      color: #fff; padding: calc(1.15rem + env(safe-area-inset-top)) 1.2rem 1.2rem;
      display: flex; justify-content: space-between; gap: 1rem;
    }
    .brand { font-size: 1.55rem; font-weight: 700; line-height: 1; }
    .sub { opacity: .85; margin-top: .35rem; font-size: .9rem; }
    .code { font-weight: 700; letter-spacing: .04em; margin-top: .45rem; text-align: right; }
    .badge {
      display: inline-block; background: #c4a35a; color: #1a1408;
      padding: .15rem .5rem; border-radius: .35rem; font-size: .72rem; font-weight: 700; text-transform: uppercase;
    }
    .bkd-card { background: #fff; padding: 1rem 1.2rem 1.15rem; }
    .bkd-card + .bkd-card { margin-top: .65rem; }
    .bkd-line { margin-bottom: .85rem; }
    .bkd-line:last-child { margin-bottom: 0; }
    .bkd-label { font-size: .72rem; letter-spacing: .05em; text-transform: uppercase; color: #6a7c83; margin-bottom: .12rem; }
    .bkd-value { font-weight: 600; }
    .bkd-plain { font-weight: 400; white-space: pre-wrap; }
    .bkd-section { font-size: .78rem; letter-spacing: .06em; text-transform: uppercase; color: #0b5f6b; font-weight: 700; margin-bottom: .75rem; }
    .bkd-days { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; position: relative; }
    .bkd-day-radio {
      position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
      clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
    }
    .bkd-day-tab {
      order: 0; margin: 0 0 .15rem; cursor: pointer; border-radius: 999px; padding: .28rem .75rem;
      border: 1px solid #d5e0e3; background: #fff; color: #14343c; font-size: .8rem; font-weight: 700;
    }
    .bkd-day-radio:checked + .bkd-day-tab { background: #0b5f6b; color: #fff; border-color: #0b5f6b; }
    .bkd-day-panel { display: none; order: 1; flex: 1 0 100%; }
    .bkd-day-radio:checked + .bkd-day-tab + .bkd-day-panel { display: block; }
    .bkd-day { margin: .35rem 0 .35rem; padding: .4rem .65rem; background: #eef5f6; border-radius: .45rem; display: flex; justify-content: space-between; gap: .5rem; font-weight: 700; }
    .bkd-stop { padding: .75rem 0; border-bottom: 1px solid #e6ecee; }
    .bkd-stop:last-child { border-bottom: 0; }
    .bkd-muted { color: #6a7c83; font-size: .85rem; font-weight: 400; }
    .bkd-cert { display: block; max-width: 100%; margin-top: .5rem; border-radius: .5rem; border: 1px solid #d5e0e3; }
    .bkd-cert-link { margin-top: .45rem; }
    a { color: #0b5f6b; }
    .foot { padding: .9rem 1.2rem calc(1rem + env(safe-area-inset-bottom)); color: #6a7c83; font-size: .8rem; }
    .err { padding: 2rem 1.2rem; }
  </style>
</head>
<body>
  <div class="sheet">
    <?php if ($error !== '' || !$booking): ?>
      <header class="head"><div><div class="brand">CulTour</div><div class="sub">Ormoc City Tourism Office</div></div></header>
      <div class="card err"><?= e($error !== '' ? $error : 'This ticket could not be found.') ?></div>
    <?php else: ?>
      <header class="head">
        <div>
          <div class="brand">CulTour</div>
          <div class="sub">Ormoc City Tourism Office</div>
        </div>
        <div>
          <div class="badge"><?= e((string) $booking['status']) ?></div>
          <div class="code"><?= e((string) $booking['booking_code']) ?></div>
        </div>
      </header>

      <?= booking_detail_cards_html($booking, $certUrl) ?>
      <div class="foot">Present this ticket to your tour guide.</div>
    <?php endif; ?>
  </div>
</body>
</html>
