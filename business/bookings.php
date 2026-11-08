<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

$user = require_login(ROLE_BUSINESS);
ensure_package_order_schema();

$biz = db()->prepare(
    'SELECT b.id, b.name, bt.name AS type_name
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.user_id=? LIMIT 1'
);
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();
$catalogMode = $biz ? business_catalog_mode($biz['type_name'] ?? '') : '';
if (!$biz || !in_array($catalogMode, ['packages', 'rooms'], true)) {
    redirect(APP_URL . '/business/dashboard.php');
}

$pageTitle = 'Bookings';
$activeNav = 'bookings';
$isRooms = $catalogMode === 'rooms';
if ($isRooms) {
    $pageHeading = 'Booked rooms';
    $pageSub = 'Tourists who booked a room from ' . ($biz['name'] ?? 'your business');
} else {
    $pageHeading = 'Booked tours';
    $pageSub = 'Tourists who booked a package from ' . ($biz['name'] ?? 'your business');
}

$orders = [];
$stays = [];
if ($isRooms) {
    ensure_booking_payment_schema();
    $bizId = (int) $biz['id'];
    $st = db()->prepare(
        'SELECT b.booking_code, b.party_size, b.booking_date, b.tour_start_date, b.tour_end_date,
                b.accommodation_business_id, b.accommodation_service_id, b.accommodation_units,
                b.accommodation_amount, b.accommodation_lines, b.payment_status,
                u.first_name, u.last_name, u.phone, s.name AS room_name
         FROM bookings b
         JOIN users u ON u.id = b.tourist_id
         LEFT JOIN business_services s ON s.id = b.accommodation_service_id
         WHERE b.accommodation_declined = 0
           AND b.status NOT IN (\'cancelled\', \'rejected\')
           AND b.payment_status IN (\'paid\', \'pay_at_office\', \'pending_review\')
           AND (b.accommodation_business_id = ? OR b.accommodation_lines LIKE ?)
         ORDER BY COALESCE(b.tour_start_date, b.booking_date) DESC, b.id DESC'
    );
    $st->execute([$bizId, '%"business_id":' . $bizId . '%']);
    foreach ($st->fetchAll() ?: [] as $row) {
        $stay = business_room_booking_summary($row, $bizId);
        if ($stay !== null) {
            $stays[] = $stay;
        }
    }
} else {
    $st = db()->prepare(
        'SELECT o.order_code, o.package_name, o.amount, o.tour_date, o.package_days, o.package_pax,
                o.payment_status, o.created_at,
                u.first_name, u.last_name, u.phone
         FROM package_orders o
         JOIN users u ON u.id = o.tourist_id
         WHERE o.business_id=? AND o.payment_status IN (\'paid\', \'pay_at_office\', \'pending_review\')
         ORDER BY o.tour_date IS NULL, o.tour_date DESC, o.id DESC'
    );
    $st->execute([(int) $biz['id']]);
    $orders = $st->fetchAll() ?: [];
}

/**
 * Rooms from this business on one tourist booking.
 *
 * @param array<string,mixed> $row
 * @return array{tourist:array<string,mixed>,code:string,rooms:string,when:string,guests:int,amount:float,payment:string}|null
 */
function business_room_booking_summary(array $row, int $businessId): ?array
{
    $lines = stay_lines_list($row['accommodation_lines'] ?? null) ?: [];
    $mine = [];
    foreach ($lines as $line) {
        if (is_array($line) && (int) ($line['business_id'] ?? 0) === $businessId) {
            $mine[] = $line;
        }
    }
    $ownsStay = (int) ($row['accommodation_business_id'] ?? 0) === $businessId;
    if ($mine === [] && !$ownsStay) {
        return null;
    }
    $names = [];
    $guestCount = 0;
    $amount = 0.0;
    $dates = [];
    foreach ($mine as $line) {
        $label = trim((string) ($line['label'] ?? ''));
        $label = preg_replace('/^Day\s+\d+\s+·\s+/u', '', $label) ?? $label;
        $parts = array_values(array_filter(array_map('trim', explode('·', $label)), static fn (string $part): bool => $part !== ''));
        $room = $parts !== [] ? (string) $parts[count($parts) - 1] : 'Room';
        $units = max(1, (int) ($line['units'] ?? 1));
        $names[] = $units > 1 ? $units . ' × ' . $room : $room;
        $listed = $line['guests'] ?? null;
        if (is_array($listed) && $listed !== []) {
            $guestCount += array_sum(array_map('intval', $listed));
        }
        $amount += (float) ($line['amount'] ?? 0);
        $dated = normalize_tour_date(isset($line['date']) ? (string) $line['date'] : null);
        if ($dated !== null) {
            $dates[] = $dated;
        }
    }
    if ($names === []) {
        $room = trim((string) ($row['room_name'] ?? ''));
        $names[] = $room !== '' ? $room : 'Room';
        $amount = (float) ($row['accommodation_amount'] ?? 0);
    }
    if ($guestCount < 1) {
        $guestCount = max(0, (int) ($row['party_size'] ?? 0));
    }
    sort($dates);
    $start = $dates[0] ?? normalize_tour_date($row['tour_start_date'] ?? null) ?: normalize_tour_date($row['booking_date'] ?? null);
    $end = $dates !== [] ? $dates[count($dates) - 1] : normalize_tour_date($row['tour_end_date'] ?? null);
    $when = '—';
    if ($start) {
        $when = format_date($start);
        if ($end && $end !== $start) {
            $when .= ' – ' . format_date($end);
        }
    }
    return [
        'tourist' => $row,
        'code' => (string) ($row['booking_code'] ?? ''),
        'rooms' => implode(', ', $names),
        'when' => $when,
        'guests' => $guestCount,
        'amount' => $amount,
        'payment' => (string) ($row['payment_status'] ?? ''),
    ];
}

$statusLabel = static function (string $status): string {
    return match ($status) {
        'paid' => 'Paid',
        'pay_at_office' => 'Pay at Tourism Office',
        'pending_review' => 'Waiting for confirmation',
        default => 'Booked',
    };
};

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($isRooms && !$stays): ?>
        <div class="glass p-4 text-muted">No booked rooms yet.</div>
      <?php elseif ($isRooms): ?>
        <div class="glass p-0 overflow-hidden">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Tourist</th>
                  <th>Room</th>
                  <th>Dates</th>
                  <th>Guests</th>
                  <th>Amount</th>
                  <th>Payment</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($stays as $stay): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= e(full_name($stay['tourist'])) ?></div>
                      <?php if (trim((string) ($stay['tourist']['phone'] ?? '')) !== ''): ?>
                        <div class="small text-muted"><?= e((string) $stay['tourist']['phone']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div><?= e($stay['rooms']) ?></div>
                      <?php if ($stay['code'] !== ''): ?>
                        <div class="small text-muted"><?= e($stay['code']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= e($stay['when']) ?></td>
                    <td><?= $stay['guests'] > 0 ? (int) $stay['guests'] : '—' ?></td>
                    <td><?= e(format_money((float) $stay['amount'])) ?></td>
                    <td><?= e($statusLabel($stay['payment'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php elseif (!$orders): ?>
        <div class="glass p-4 text-muted">No booked tours yet.</div>
      <?php else: ?>
        <div class="glass p-0 overflow-hidden">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Tourist</th>
                  <th>Package</th>
                  <th>Tour date</th>
                  <th>Pax</th>
                  <th>Amount</th>
                  <th>Payment</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orders as $order): ?>
                  <?php
                    $days = (int) ($order['package_days'] ?? 0);
                    $pax = (int) ($order['package_pax'] ?? 0);
                    $when = trim((string) ($order['tour_date'] ?? ''));
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= e(full_name($order)) ?></div>
                      <?php if (trim((string) ($order['phone'] ?? '')) !== ''): ?>
                        <div class="small text-muted"><?= e((string) $order['phone']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div><?= e((string) $order['package_name']) ?></div>
                      <div class="small text-muted"><?= e((string) $order['order_code']) ?><?= $days > 0 ? ' · ' . e(package_days_label($days)) : '' ?></div>
                    </td>
                    <td><?= $when !== '' ? e(format_date($when)) : '—' ?></td>
                    <td><?= $pax > 0 ? e(package_pax_label($pax)) : '—' ?></td>
                    <td><?= e(format_money((float) $order['amount'])) ?></td>
                    <td><?= e($statusLabel((string) $order['payment_status'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
require __DIR__ . '/../includes/footer.php';
