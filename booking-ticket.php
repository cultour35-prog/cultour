<?php
/**
 * Printable booking ticket with QR code
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr.php';
$user = require_login();
ensure_booking_payment_schema();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT b.*,
            CONCAT(t.first_name,' ',t.last_name) AS tourist_name, t.email AS tourist_email, t.phone AS tourist_phone,
            CONCAT(gu.first_name,' ',gu.last_name) AS guide_name, gu.phone AS guide_phone,
            d.name AS destination_name, d.location AS destination_location,
            i.title AS itinerary_title
     FROM bookings b
     JOIN users t ON t.id = b.tourist_id
     JOIN tour_guide_profiles gp ON gp.id = b.guide_id
     JOIN users gu ON gu.id = gp.user_id
     LEFT JOIN destinations d ON d.id = b.destination_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     WHERE b.id = ?"
);
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    flash('error', 'Booking not found.');
    redirect(role_dashboard((int)$user['role_id']));
}

// Access: tourist owner, assigned guide, or admin
$allowed = booking_user_can_access_ticket($user, $b);
if (!$allowed) {
    flash('error', 'Access denied.');
    redirect(role_dashboard((int)$user['role_id']));
}

$ticketReady = in_array($b['status'] ?? '', ['approved', 'completed'], true);

if ($ticketReady) {
    booking_qr_for($b);
    $stmt->execute([$id]);
    $b = $stmt->fetch();
}

$qrUrl = ($ticketReady && $b['qr_code_path']) ? (APP_URL . '/uploads/' . $b['qr_code_path']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Booking Ticket <?= e($b['booking_code']) ?> · CulTour</title>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
  <style>
    :root { --teal:#0b5f6b; --gold:#c4a35a; --ink:#14242a; }
    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: Figtree, system-ui, sans-serif; color: var(--ink);
      background: #e8eef0; padding: 1.5rem;
    }
    .toolbar { max-width: 720px; margin: 0 auto 1rem; display: flex; flex-wrap: wrap; gap: .5rem; }
    .toolbar a, .toolbar button {
      border: 0; background: var(--teal); color: #fff; padding: .55rem 1rem;
      border-radius: .6rem; text-decoration: none; cursor: pointer; font-weight: 600;
      display: inline-flex; align-items: center;
    }
    .toolbar .download { background: #0e8a7a; }
    .toolbar .ghost { background: #fff; color: var(--teal); border: 1px solid #cfd8db; }
    .ticket {
      max-width: 720px; margin: 0 auto; background: #fff; border-radius: 1rem;
      overflow: hidden; box-shadow: 0 16px 40px rgba(7,61,70,.15);
      border: 1px solid #d5e0e3;
    }
    .ticket-head {
      background: linear-gradient(135deg, var(--teal), #1a8a7a);
      color: #fff; padding: 1.35rem 1.5rem; display: flex; justify-content: space-between; align-items: flex-start;
    }
    .brand { font-family: Fraunces, Georgia, serif; font-size: 1.75rem; line-height: 1; }
    .code { font-size: 1.1rem; letter-spacing: .06em; font-weight: 700; }
    .badge {
      display: inline-block; background: var(--gold); color: #1a1408;
      padding: .2rem .55rem; border-radius: .4rem; font-size: .75rem; font-weight: 700; text-transform: uppercase;
    }
    .ticket-body { display: grid; grid-template-columns: 1.4fr .9fr; gap: 0; }
    .details { padding: 1.35rem 1.5rem; }
    .qr-panel {
      padding: 1.35rem; border-left: 1px dashed #cfd8db; text-align: center;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      background: #fafbfc;
    }
    .qr-panel img { width: 200px; height: 200px; object-fit: contain; border: 8px solid #fff; box-shadow: 0 4px 16px rgba(0,0,0,.08); }
    .label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #6a7c83; margin-bottom: .15rem; }
    .value { font-weight: 600; margin-bottom: .9rem; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem 1rem; }
    .footer {
      border-top: 1px solid #e6ecee; padding: .85rem 1.5rem; font-size: .8rem; color: #6a7c83;
      display: flex; justify-content: space-between;
    }
    @media (max-width: 640px) {
      .ticket-body { grid-template-columns: 1fr; }
      .qr-panel { border-left: 0; border-top: 1px dashed #cfd8db; }
    }
    @media print {
      body { background: #fff; padding: 0; }
      .toolbar { display: none !important; }
      .ticket { box-shadow: none; border-radius: 0; max-width: 100%; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button type="button" onclick="window.print()">Print</button>
    <a class="download" href="<?= APP_URL ?>/booking-ticket-pdf.php?id=<?= (int) $id ?>">Download PDF</a>
    <a class="ghost" href="<?= APP_URL ?>/<?= (int)$user['role_id']===ROLE_TOURIST?'tourist/bookings.php':((int)$user['role_id']===ROLE_TOUR_GUIDE?'tourguide/bookings.php':'admin/bookings.php') ?>">Back</a>
  </div>

  <article class="ticket">
    <header class="ticket-head">
      <div>
        <div class="brand">CulTour</div>
        <div style="opacity:.85;margin-top:.35rem">Ormoc City Tourism Office</div>
      </div>
      <div style="text-align:right">
        <div class="badge"><?= e($b['status']) ?></div>
        <div class="code" style="margin-top:.5rem"><?= e($b['booking_code']) ?></div>
      </div>
    </header>
    <div class="ticket-body">
      <div class="details">
        <div class="grid-2">
          <div>
            <div class="label">Tourist</div>
            <div class="value"><?= e($b['tourist_name']) ?></div>
          </div>
          <div>
            <div class="label">Tour Guide</div>
            <div class="value"><?= e($b['guide_name']) ?></div>
          </div>
          <div>
            <div class="label">Schedule</div>
            <div class="value"><?= e(format_date($b['booking_date'], 'l, M j, Y')) ?><br><?= e(format_time_12h($b['start_time'] ?? null)) ?><?= !empty($b['end_time']) ? ' – ' . e(format_time_12h($b['end_time'])) : '' ?></div>
          </div>
          <div>
            <div class="label">Party size</div>
            <div class="value"><?= (int)$b['party_size'] ?> guest(s)</div>
          </div>
        </div>
        <?php if (booking_is_pwd($b)): ?>
        <div>
          <div class="label">Accessibility</div>
          <div class="value" style="font-weight:400">
            Accessibility needs
            <?php if (!empty($b['pwd_notes'])): ?>
              · <?= e($b['pwd_notes']) ?>
            <?php endif; ?>
            <?php $certUrl = booking_fitness_certificate_url($b); ?>
            <?php if ($certUrl): ?>
              <div style="margin-top:.4rem">
                <a href="<?= e($certUrl) ?>" target="_blank" rel="noopener" style="font-size:.9rem;font-weight:600;color:var(--teal,#0b5f6b)">Fitness-to-travel certificate</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <div>
          <div class="label">Itinerary</div>
          <div class="value">
            <?= e($b['itinerary_title'] ?: '—') ?>
            <?php if (!empty($b['itinerary_id'])): ?>
              <div style="margin-top:.4rem">
                <a href="<?= APP_URL ?>/itinerary-share.php?id=<?= (int) $b['itinerary_id'] ?>" target="_blank" rel="noopener" style="font-size:.9rem;font-weight:600;color:var(--teal,#0b5f6b)">View itinerary</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php $ticketReservations = reservation_itinerary_map((int) ($b['itinerary_id'] ?? 0)); ?>
        <?php if ($ticketReservations): ?>
        <div>
          <div class="label">Reservations</div>
          <div class="value" style="font-weight:400">
            <?php foreach ($ticketReservations as $ticketReservation): ?>
              <?php foreach (reservation_summary_lines($ticketReservation) as $ticketLine): ?>
                <div><?= e($ticketLine) ?></div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($b['notes']): ?>
          <div>
            <div class="label">Notes</div>
            <div class="value" style="font-weight:400"><?= e($b['notes']) ?></div>
          </div>
        <?php endif; ?>
      </div>
      <div class="qr-panel">
        <?php if ($ticketReady && $qrUrl): ?>
          <img src="<?= e($qrUrl) ?>" alt="Booking QR Code">
          <div style="margin-top:.75rem;font-size:.8rem;color:#6a7c83">Code <?= e($b['booking_code']) ?><br>Scan with your phone to open this ticket</div>
        <?php elseif ($ticketReady): ?>
          <div style="width:200px;height:200px;background:#eee;display:flex;align-items:center;justify-content:center;padding:1rem;font-size:.85rem">QR unavailable — use code <?= e($b['booking_code']) ?></div>
        <?php else: ?>
          <div style="width:200px;min-height:160px;background:#f3f5f6;display:flex;align-items:center;justify-content:center;padding:1rem;font-size:.9rem;color:#5c6f76;text-align:center">
            Ticket &amp; QR unlock after the guide accepts this booking.
          </div>
        <?php endif; ?>
      </div>
    </div>
    <footer class="footer">
      <span>Present this ticket to your tour guide</span>
      <span>Generated <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
  </article>
</body>
</html>
