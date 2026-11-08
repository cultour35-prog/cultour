<?php
/**
 * Earnings from tourist bookings for accommodation, transportation, and travel businesses.
 */
require_once __DIR__ . '/payments.php';

/**
 * Paid, office-pay, and waiting bookings that earn money for this business.
 *
 * @return list<array{tourist:string,phone:string,item:string,code:string,when:string,sort_date:string,amount:float,payment:string}>
 */
function business_earnings_rows(int $businessId, string $mode): array
{
    if ($mode === 'packages') {
        ensure_package_order_schema();
        $st = db()->prepare(
            'SELECT o.order_code, o.package_name, o.amount, o.tour_date, o.payment_status, o.created_at,
                    u.first_name, u.last_name, u.phone
             FROM package_orders o
             JOIN users u ON u.id = o.tourist_id
             WHERE o.business_id=? AND o.payment_status IN (\'paid\', \'pay_at_office\', \'pending_review\')
             ORDER BY o.tour_date IS NULL, o.tour_date DESC, o.id DESC'
        );
        $st->execute([$businessId]);
        $rows = [];
        foreach ($st->fetchAll() ?: [] as $order) {
            $when = normalize_tour_date($order['tour_date'] ?? null) ?: substr((string) ($order['created_at'] ?? ''), 0, 10);
            $rows[] = [
                'tourist' => full_name($order),
                'phone' => trim((string) ($order['phone'] ?? '')),
                'item' => trim((string) ($order['package_name'] ?? '')) ?: 'Package',
                'code' => (string) ($order['order_code'] ?? ''),
                'when' => $when !== '' ? format_date($when) : '—',
                'sort_date' => $when,
                'amount' => (float) ($order['amount'] ?? 0),
                'payment' => (string) ($order['payment_status'] ?? ''),
            ];
        }
        return $rows;
    }

    ensure_booking_payment_schema();
    $side = $mode === 'vehicles' ? 'transportation' : 'accommodation';
    $st = db()->prepare(
        "SELECT b.booking_code, b.party_size, b.booking_date, b.tour_start_date, b.tour_end_date,
                b.{$side}_business_id, b.{$side}_service_id, b.{$side}_amount, b.{$side}_lines,
                b.payment_status, u.first_name, u.last_name, u.phone, s.name AS item_name
         FROM bookings b
         JOIN users u ON u.id = b.tourist_id
         LEFT JOIN business_services s ON s.id = b.{$side}_service_id
         WHERE b.{$side}_declined = 0
           AND b.status NOT IN ('cancelled', 'rejected')
           AND b.payment_status IN ('paid', 'pay_at_office', 'pending_review')
           AND (b.{$side}_business_id = ? OR b.{$side}_lines LIKE ?)
         ORDER BY COALESCE(b.tour_start_date, b.booking_date) DESC, b.id DESC"
    );
    $st->execute([$businessId, '%"business_id":' . $businessId . '%']);
    $rows = [];
    foreach ($st->fetchAll() ?: [] as $booking) {
        $earned = business_side_earning($booking, $businessId, $side);
        if ($earned !== null) {
            $rows[] = $earned;
        }
    }
    return $rows;
}

/**
 * @param array<string,mixed> $row
 * @return array{tourist:string,phone:string,item:string,code:string,when:string,sort_date:string,amount:float,payment:string}|null
 */
function business_side_earning(array $row, int $businessId, string $side): ?array
{
    $lines = stay_lines_list($row[$side . '_lines'] ?? null) ?: [];
    $mine = [];
    foreach ($lines as $line) {
        if (is_array($line) && (int) ($line['business_id'] ?? 0) === $businessId) {
            $mine[] = $line;
        }
    }
    $owns = (int) ($row[$side . '_business_id'] ?? 0) === $businessId;
    if ($mine === [] && !$owns) {
        return null;
    }
    $names = [];
    $amount = 0.0;
    $dates = [];
    $fallback = $side === 'transportation' ? 'Vehicle' : 'Room';
    foreach ($mine as $line) {
        $label = trim((string) ($line['label'] ?? ''));
        $label = preg_replace('/^Day\s+\d+\s+·\s+/u', '', $label) ?? $label;
        $parts = array_values(array_filter(array_map('trim', explode('·', $label)), static fn (string $part): bool => $part !== ''));
        $name = $parts !== [] ? (string) $parts[count($parts) - 1] : $fallback;
        $units = max(1, (int) ($line['units'] ?? 1));
        $names[] = $units > 1 ? $units . ' × ' . $name : $name;
        $amount += (float) ($line['amount'] ?? 0);
        $dated = normalize_tour_date(isset($line['date']) ? (string) $line['date'] : null);
        if ($dated !== null) {
            $dates[] = $dated;
        }
    }
    if ($names === []) {
        $named = trim((string) ($row['item_name'] ?? ''));
        $names[] = $named !== '' ? $named : $fallback;
        $amount = (float) ($row[$side . '_amount'] ?? 0);
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
        'tourist' => full_name($row),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'item' => implode(', ', $names),
        'code' => (string) ($row['booking_code'] ?? ''),
        'when' => $when,
        'sort_date' => $start ?: '',
        'amount' => $amount,
        'payment' => (string) ($row['payment_status'] ?? ''),
    ];
}
