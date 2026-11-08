<?php
/**
 * Restaurant reservations and CulTour revenue (fixed fees and commissions).
 * The tourist is never charged the restaurant reservation fee.
 */

function revenue_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS revenue_rates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_type_id TINYINT UNSIGNED NULL,
            business_id INT UNSIGNED NULL,
            model ENUM('percent','fixed') NOT NULL,
            percent_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            fixed_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            noshow_policy ENUM('none','charge') NOT NULL DEFAULT 'none',
            updated_at DATETIME NULL,
            UNIQUE KEY uq_rate_type (business_type_id),
            UNIQUE KEY uq_rate_biz (business_id)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS restaurant_reservations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            tourist_id INT UNSIGNED NOT NULL,
            business_id INT UNSIGNED NOT NULL,
            itinerary_id INT UNSIGNED NOT NULL,
            itinerary_item_id INT UNSIGNED NOT NULL,
            reserve_date DATE NOT NULL,
            reserve_time TIME NOT NULL,
            guests INT UNSIGNED NOT NULL,
            order_mode ENUM('table_only','advance_order') NOT NULL DEFAULT 'table_only',
            status ENUM('pending','confirmed','rejected','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
            rejection_reason VARCHAR(255) NULL,
            fee_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0,
            food_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_res_code (code),
            INDEX idx_res_biz (business_id, status),
            INDEX idx_res_item (itinerary_item_id),
            INDEX idx_res_tourist (tourist_id)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS restaurant_reservation_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT UNSIGNED NOT NULL,
            service_id INT UNSIGNED NULL,
            item_name VARCHAR(150) NOT NULL,
            qty INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            INDEX idx_rri_res (reservation_id)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS restaurant_reservation_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,
            note VARCHAR(255) NULL,
            actor_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rre_res (reservation_id)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS revenue_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            category_name VARCHAR(100) NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            source_id INT UNSIGNED NOT NULL,
            base_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            rate_label VARCHAR(40) NOT NULL,
            fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid',
            period_ym CHAR(7) NOT NULL,
            statement_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            UNIQUE KEY uq_rev_source (source_type, source_id),
            INDEX idx_rev_biz (business_id, status),
            INDEX idx_rev_period (period_ym)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS revenue_statements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            period_ym CHAR(7) NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            verified_by INT UNSIGNED NULL,
            UNIQUE KEY uq_stmt_period (business_id, period_ym)
        ) ENGINE=InnoDB"
    );
    revenue_seed_category_rates();
}

function revenue_bookable_kind(?string $typeName): string
{
    $type = strtolower(trim((string) $typeName));
    if ($type === 'food and beverages' || str_contains($type, 'food')) {
        return 'food';
    }
    if (str_contains($type, 'beach')) {
        return 'beach';
    }
    if (str_contains($type, 'wellness') || str_contains($type, 'recreation')) {
        return 'wellness';
    }
    $mode = business_catalog_mode($typeName);
    if ($mode === 'rooms') {
        return 'accommodation';
    }
    if ($mode === 'vehicles') {
        return 'transportation';
    }
    if ($mode === 'packages') {
        return 'package';
    }
    if (str_contains($type, 'souvenir')) {
        return 'souvenir';
    }
    return 'other';
}

function revenue_uses_reservation(string $kind): bool
{
    return in_array($kind, ['food', 'wellness', 'beach'], true);
}

/** @return array<string,string> */
function reservation_place_copy(string $kind): array
{
    if ($kind === 'beach') {
        return [
            'button' => 'Reserve a spot',
            'fallback_name' => 'Beach',
            'check_title' => 'Get it at the beach',
            'check_note' => 'Choose when you arrive',
            'ticket' => 'Your booking',
            'empty' => 'Nothing added yet.',
            'empty_menu' => 'Nothing listed yet.',
            'at_place' => 'You will choose at the beach.',
            'at_place_short' => 'Choose at the beach',
            'ahead' => 'Booked ahead',
            'total' => 'Beach total',
            'fee_who' => 'The beach',
            'replace' => 'Replace beach',
            'add_blocked' => 'Get it at the beach',
            'stop_missing' => 'That stop is not a beach on your itinerary.',
            'kind_blocked' => 'Reservations are for beach places.',
            'need_item' => 'Choose at least one item, or get it at the beach.',
            'sent_to' => 'the beach',
            'sent' => 'Reservation request sent. The beach still has to accept it.',
            'replace_denied' => 'You can replace a beach after it declines the reservation.',
            'replace_other' => 'Choose another beach.',
            'replace_same' => 'Choose a different beach.',
        ];
    }
    if ($kind === 'wellness') {
        return [
            'button' => 'Book a session',
            'fallback_name' => 'Wellness',
            'check_title' => 'Use the service when I arrive',
            'check_note' => 'Choose it at the place',
            'ticket' => 'Your booking',
            'empty' => 'Nothing added yet.',
            'empty_menu' => 'No services yet.',
            'at_place' => 'You will choose the service when you arrive.',
            'at_place_short' => 'Choose on arrival',
            'ahead' => 'Booked ahead',
            'total' => 'Service total',
            'fee_who' => 'The place',
            'replace' => 'Replace place',
            'add_blocked' => 'Choose on arrival',
            'stop_missing' => 'That stop is not a wellness place on your itinerary.',
            'kind_blocked' => 'Reservations are for wellness and recreation places.',
            'need_item' => 'Choose at least one service, or use it when you arrive.',
            'sent_to' => 'the place',
            'sent' => 'Reservation request sent. The place still has to accept it.',
            'replace_denied' => 'You can replace a place after it declines the reservation.',
            'replace_other' => 'Choose another wellness or recreation place.',
            'replace_same' => 'Choose a different place.',
        ];
    }
    return [
        'button' => 'Reserve a table',
        'fallback_name' => 'Restaurant',
        'check_title' => 'Get food at the restaurant',
        'check_note' => 'Order when you arrive',
        'ticket' => 'Your order',
        'empty' => 'No dishes yet.',
        'empty_menu' => 'No menu items yet.',
        'at_place' => 'You will order at the restaurant.',
        'at_place_short' => 'Get food at the restaurant',
        'ahead' => 'Choose food now',
        'total' => 'Food total',
        'fee_who' => 'The restaurant',
        'replace' => 'Replace restaurant',
        'add_blocked' => 'Order at the restaurant',
        'stop_missing' => 'That stop is not a restaurant on your itinerary.',
        'kind_blocked' => 'Reservations are for food and wellness places.',
        'need_item' => 'Choose at least one menu item, or reserve the table only.',
        'sent_to' => 'the restaurant',
        'sent' => 'Reservation request sent. The restaurant still has to accept it.',
        'replace_denied' => 'You can replace a restaurant after it declines the reservation.',
        'replace_other' => 'Choose another restaurant.',
        'replace_same' => 'Choose a different restaurant.',
    ];
}

function revenue_default_for_kind(string $kind): array
{
    return match ($kind) {
        'food', 'wellness', 'beach' => ['model' => 'fixed', 'percent_rate' => 0, 'fixed_amount' => 30],
        'accommodation', 'transportation', 'package' => ['model' => 'percent', 'percent_rate' => 10, 'fixed_amount' => 0],
        default => ['model' => 'fixed', 'percent_rate' => 0, 'fixed_amount' => 0],
    };
}

function revenue_seed_category_rates(): void
{
    $types = db()->query('SELECT id, name FROM business_types')->fetchAll() ?: [];
    $ins = db()->prepare(
        'INSERT IGNORE INTO revenue_rates (business_type_id, business_id, model, percent_rate, fixed_amount, noshow_policy)
         VALUES (?, NULL, ?, ?, ?, \'none\')'
    );
    foreach ($types as $type) {
        $defaults = revenue_default_for_kind(revenue_bookable_kind($type['name'] ?? ''));
        $ins->execute([
            (int) $type['id'],
            $defaults['model'],
            $defaults['percent_rate'],
            $defaults['fixed_amount'],
        ]);
    }
}

/** @return array{model:string,percent_rate:float,fixed_amount:float,noshow_policy:string,category:string,business_type_id:int} */
function revenue_rate_for_business(int $businessId): array
{
    revenue_ensure_schema();
    $st = db()->prepare(
        'SELECT b.business_type_id, bt.name AS type_name
         FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.id=? LIMIT 1'
    );
    $st->execute([$businessId]);
    $biz = $st->fetch() ?: ['business_type_id' => 0, 'type_name' => ''];
    $kind = revenue_bookable_kind($biz['type_name'] ?? '');
    $fallback = revenue_default_for_kind($kind);
    $rate = $fallback;
    $rate['noshow_policy'] = 'none';
    $over = db()->prepare('SELECT * FROM revenue_rates WHERE business_id=? LIMIT 1');
    $over->execute([$businessId]);
    $row = $over->fetch();
    if (!$row) {
        $cat = db()->prepare('SELECT * FROM revenue_rates WHERE business_type_id=? AND business_id IS NULL LIMIT 1');
        $cat->execute([(int) $biz['business_type_id']]);
        $row = $cat->fetch();
    }
    if ($row) {
        $rate['model'] = (string) $row['model'];
        $rate['percent_rate'] = (float) $row['percent_rate'];
        $rate['fixed_amount'] = (float) $row['fixed_amount'];
        $rate['noshow_policy'] = (string) ($row['noshow_policy'] ?? 'none');
    }
    $rate['category'] = (string) ($biz['type_name'] ?? '');
    $rate['business_type_id'] = (int) ($biz['business_type_id'] ?? 0);
    return $rate;
}

function revenue_fee_from_rate(array $rate, float $baseAmount): float
{
    if (($rate['model'] ?? '') === 'percent') {
        return round(max(0, $baseAmount) * ((float) ($rate['percent_rate'] ?? 0) / 100), 2);
    }
    return round(max(0, (float) ($rate['fixed_amount'] ?? 0)), 2);
}

function revenue_insert_transaction(
    int $businessId,
    string $category,
    string $sourceType,
    int $sourceId,
    float $baseAmount,
    string $rateLabel,
    float $feeAmount
): bool {
    if ($businessId < 1 || $sourceId < 1 || $feeAmount <= 0) {
        return false;
    }
    $exists = db()->prepare('SELECT id FROM revenue_transactions WHERE source_type=? AND source_id=? LIMIT 1');
    $exists->execute([$sourceType, $sourceId]);
    if ($exists->fetch()) {
        return false;
    }
    db()->prepare(
        'INSERT INTO revenue_transactions
         (business_id, category_name, source_type, source_id, base_amount, rate_label, fee_amount, status, period_ym)
         VALUES (?,?,?,?,?,?,?,\'unpaid\',?)'
    )->execute([
        $businessId,
        $category,
        $sourceType,
        $sourceId,
        round($baseAmount, 2),
        $rateLabel,
        round($feeAmount, 2),
        date('Y-m'),
    ]);
    return true;
}

function revenue_record_booking_commissions(int $bookingId): void
{
    revenue_ensure_schema();
    if ($bookingId < 1) {
        return;
    }
    $st = db()->prepare('SELECT * FROM bookings WHERE id=? AND status=\'completed\' LIMIT 1');
    $st->execute([$bookingId]);
    $booking = $st->fetch();
    if (!$booking) {
        return;
    }
    $pairs = [
        ['accommodation', 'accommodation_business_id', 'accommodation_amount', 'accommodation_declined'],
        ['transportation', 'transportation_business_id', 'transportation_amount', 'transportation_declined'],
    ];
    foreach ($pairs as [$source, $bizCol, $amtCol, $declinedCol]) {
        if (!empty($booking[$declinedCol])) {
            continue;
        }
        $bizId = (int) ($booking[$bizCol] ?? 0);
        $base = (float) ($booking[$amtCol] ?? 0);
        if ($bizId < 1 || $base <= 0) {
            continue;
        }
        $rate = revenue_rate_for_business($bizId);
        if (($rate['model'] ?? '') !== 'percent') {
            continue;
        }
        $fee = revenue_fee_from_rate($rate, $base);
        revenue_insert_transaction(
            $bizId,
            $rate['category'],
            $source,
            $bookingId,
            $base,
            rtrim(rtrim(number_format((float) $rate['percent_rate'], 2, '.', ''), '0'), '.') . '%',
            $fee
        );
    }
}

function revenue_record_package_order(int $orderId): void
{
    revenue_ensure_schema();
    $st = db()->prepare('SELECT * FROM package_orders WHERE id=? AND payment_status=\'paid\' LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) {
        return;
    }
    $bizId = (int) ($order['business_id'] ?? 0);
    $base = (float) ($order['amount'] ?? 0);
    if ($bizId < 1 || $base <= 0) {
        return;
    }
    $rate = revenue_rate_for_business($bizId);
    if (revenue_bookable_kind($rate['category']) !== 'package' || ($rate['model'] ?? '') !== 'percent') {
        return;
    }
    revenue_insert_transaction(
        $bizId,
        $rate['category'],
        'package',
        $orderId,
        $base,
        rtrim(rtrim(number_format((float) $rate['percent_rate'], 2, '.', ''), '0'), '.') . '%',
        revenue_fee_from_rate($rate, $base)
    );
}

function reservation_event(int $reservationId, string $status, ?string $note, int $actorId): void
{
    db()->prepare(
        'INSERT INTO restaurant_reservation_events (reservation_id, status, note, actor_user_id) VALUES (?,?,?,?)'
    )->execute([$reservationId, $status, $note !== '' ? $note : null, $actorId > 0 ? $actorId : null]);
}

function reservation_latest_for_item(int $itemId): ?array
{
    revenue_ensure_schema();
    $st = db()->prepare(
        'SELECT * FROM restaurant_reservations WHERE itinerary_item_id=? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$itemId]);
    $row = $st->fetch();
    return $row ?: null;
}

function reservation_lines(int $reservationId): array
{
    $st = db()->prepare('SELECT * FROM restaurant_reservation_items WHERE reservation_id=? ORDER BY id');
    $st->execute([$reservationId]);
    return $st->fetchAll() ?: [];
}

/** Latest reservation for each stop on an itinerary. @return array<int,array<string,mixed>> */
function reservation_itinerary_map(int $itineraryId): array
{
    if ($itineraryId < 1) {
        return [];
    }
    revenue_ensure_schema();
    $st = db()->prepare(
        'SELECT r.*, b.name AS business_name, bt.name AS type_name
         FROM restaurant_reservations r
         JOIN businesses b ON b.id = r.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE r.itinerary_id=?
         ORDER BY r.id DESC'
    );
    $st->execute([$itineraryId]);
    $byItem = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $itemId = (int) ($row['itinerary_item_id'] ?? 0);
        if ($itemId < 1 || isset($byItem[$itemId])) {
            continue;
        }
        $kind = revenue_bookable_kind($row['type_name'] ?? '');
        $row['kind'] = $kind;
        $row['copy'] = reservation_place_copy($kind);
        $row['lines'] = reservation_lines((int) $row['id']);
        $byItem[$itemId] = $row;
    }
    return $byItem;
}

/** @param array<string,mixed> $reservation @return list<string> */
function reservation_summary_lines(array $reservation): array
{
    $copy = is_array($reservation['copy'] ?? null) ? $reservation['copy'] : reservation_place_copy((string) ($reservation['kind'] ?? 'food'));
    $mode = ($reservation['order_mode'] ?? '') === 'advance_order'
        ? (string) ($copy['ahead'] ?? 'Booked ahead')
        : (string) ($copy['at_place_short'] ?? 'Choose on arrival');
    $status = ucfirst(str_replace('_', ' ', (string) ($reservation['status'] ?? '')));
    $lines = ['Reservation ' . (string) ($reservation['code'] ?? '') . ' · ' . (string) ($reservation['business_name'] ?? '') . ' · ' . $status . ' · ' . $mode];
    $items = [];
    foreach ($reservation['lines'] ?? [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $items[] = (int) ($line['qty'] ?? 0) . ' × ' . (string) ($line['item_name'] ?? '');
    }
    if ($items) {
        $lines[] = implode(', ', $items) . ' · ' . (string) ($copy['total'] ?? 'Total') . ' ' . format_money((float) ($reservation['food_total'] ?? 0));
    }
    $lines[] = 'Paid at the place. Not included in the CulTour booking total.';
    return $lines;
}

/** @return array{ok:bool,message:string} */
function reservation_submit(int $touristId, int $itemId, string $date, string $time, int $guests, string $mode, array $qtyByService): array
{
    revenue_ensure_schema();
    $item = db()->prepare(
        'SELECT ii.*, i.user_id, i.start_date, b.user_id AS business_user_id, b.name AS business_name, bt.name AS type_name
         FROM itinerary_items ii
         JOIN itineraries i ON i.id = ii.itinerary_id
         JOIN businesses b ON b.id = ii.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE ii.id=? AND i.user_id=? AND b.approval_status=\'approved\' AND b.status=\'active\'
         LIMIT 1'
    );
    $item->execute([$itemId, $touristId]);
    $stop = $item->fetch();
    if (!$stop) {
        return ['ok' => false, 'message' => 'That stop is not on your itinerary.'];
    }
    $kind = revenue_bookable_kind($stop['type_name'] ?? '');
    if (!revenue_uses_reservation($kind)) {
        return ['ok' => false, 'message' => 'This place does not take reservations.'];
    }
    $placeCopy = reservation_place_copy($kind);
    $open = reservation_latest_for_item($itemId);
    if ($open && in_array($open['status'], ['pending', 'confirmed'], true)) {
        return ['ok' => false, 'message' => 'This stop already has an open reservation.'];
    }
    $date = normalize_tour_date($date);
    $time = normalize_time_input($time);
    if ($date === null || $time === null) {
        return ['ok' => false, 'message' => 'Choose a reservation date and time.'];
    }
    if ($guests < 1 || $guests > 50) {
        return ['ok' => false, 'message' => 'Enter the number of guests.'];
    }
    if (!in_array($mode, ['table_only', 'advance_order'], true)) {
        $mode = 'table_only';
    }
    $lines = [];
    $foodTotal = 0.0;
    if ($mode === 'advance_order') {
        $menu = db()->prepare('SELECT id, name, price FROM business_services WHERE business_id=? AND is_active=1');
        $menu->execute([(int) $stop['business_id']]);
        $byId = [];
        foreach ($menu->fetchAll() ?: [] as $dish) {
            $byId[(int) $dish['id']] = $dish;
        }
        foreach ($qtyByService as $sid => $qty) {
            $sid = (int) $sid;
            $qty = (int) $qty;
            if ($qty < 1 || !isset($byId[$sid])) {
                continue;
            }
            $price = (float) ($byId[$sid]['price'] ?? 0);
            $lines[] = [
                'service_id' => $sid,
                'item_name' => (string) $byId[$sid]['name'],
                'qty' => $qty,
                'unit_price' => $price,
            ];
            $foodTotal += $price * $qty;
        }
        if (!$lines) {
            return ['ok' => false, 'message' => $placeCopy['need_item']];
        }
    }
    $rate = revenue_rate_for_business((int) $stop['business_id']);
    $fee = ($rate['model'] ?? '') === 'fixed' ? revenue_fee_from_rate($rate, 0) : 0.0;
    $code = 'RES-' . strtoupper(bin2hex(random_bytes(3)));
    db()->prepare(
        'INSERT INTO restaurant_reservations
         (code, tourist_id, business_id, itinerary_id, itinerary_item_id, reserve_date, reserve_time, guests, order_mode, status, fee_snapshot, food_total)
         VALUES (?,?,?,?,?,?,?,?,?,\'pending\',?,?)'
    )->execute([
        $code,
        $touristId,
        (int) $stop['business_id'],
        (int) $stop['itinerary_id'],
        $itemId,
        $date,
        $time,
        $guests,
        $mode,
        $fee,
        round($foodTotal, 2),
    ]);
    $id = (int) db()->lastInsertId();
    $lineIns = db()->prepare(
        'INSERT INTO restaurant_reservation_items (reservation_id, service_id, item_name, qty, unit_price) VALUES (?,?,?,?,?)'
    );
    foreach ($lines as $line) {
        $lineIns->execute([$id, $line['service_id'], $line['item_name'], $line['qty'], $line['unit_price']]);
    }
    reservation_event($id, 'pending', 'Reservation requested', $touristId);
    notify_user(
        (int) $stop['business_user_id'],
        'booking_request',
        'New reservation request',
        $code . ' is waiting for your response.',
        APP_URL . '/business/reservations.php'
    );
    notify_user(
        $touristId,
        'booking_request',
        'Reservation request sent',
        'Your request ' . $code . ' was sent to ' . ($stop['business_name'] ?? $placeCopy['sent_to']) . '.',
        APP_URL . '/tourist/itineraries.php?id=' . (int) $stop['itinerary_id']
    );
    return ['ok' => true, 'message' => $placeCopy['sent']];
}

/** @return array{ok:bool,message:string} */
function reservation_set_status(int $reservationId, string $next, int $actorId, string $role, string $reason = ''): array
{
    revenue_ensure_schema();
    $st = db()->prepare(
        'SELECT r.*, b.user_id AS business_user_id, b.name AS business_name, u.first_name, u.last_name
         FROM restaurant_reservations r
         JOIN businesses b ON b.id = r.business_id
         JOIN users u ON u.id = r.tourist_id
         WHERE r.id=? LIMIT 1'
    );
    $st->execute([$reservationId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Reservation not found.'];
    }
    $isBusiness = $role === 'business' && (int) $row['business_user_id'] === $actorId;
    $isTourist = $role === 'tourist' && (int) $row['tourist_id'] === $actorId;
    $status = (string) $row['status'];
    if ($next === 'cancelled' && $isTourist && in_array($status, ['pending', 'confirmed'], true)) {
        db()->prepare('UPDATE restaurant_reservations SET status=\'cancelled\', updated_at=NOW() WHERE id=?')->execute([$reservationId]);
        reservation_event($reservationId, 'cancelled', 'Cancelled by tourist', $actorId);
        notify_user((int) $row['business_user_id'], 'booking_cancelled', 'Reservation cancelled', $row['code'] . ' was cancelled by the tourist.', APP_URL . '/business/reservations.php');
        notify_user((int) $row['tourist_id'], 'booking_cancelled', 'Reservation cancelled', 'You cancelled ' . $row['code'] . '.', APP_URL . '/tourist/itineraries.php?id=' . (int) $row['itinerary_id']);
        return ['ok' => true, 'message' => 'Reservation cancelled. No fee was charged.'];
    }
    if (!$isBusiness) {
        return ['ok' => false, 'message' => 'You cannot update this reservation.'];
    }
    if ($next === 'confirmed' && $status === 'pending') {
        db()->prepare('UPDATE restaurant_reservations SET status=\'confirmed\', updated_at=NOW() WHERE id=?')->execute([$reservationId]);
        reservation_event($reservationId, 'confirmed', 'Accepted', $actorId);
        notify_user((int) $row['tourist_id'], 'booking_approved', 'Reservation confirmed', $row['business_name'] . ' accepted ' . $row['code'] . '.', APP_URL . '/tourist/itineraries.php?id=' . (int) $row['itinerary_id']);
        return ['ok' => true, 'message' => 'Reservation confirmed.'];
    }
    if ($next === 'rejected' && $status === 'pending') {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Enter a reason for rejecting this reservation.'];
        }
        db()->prepare('UPDATE restaurant_reservations SET status=\'rejected\', rejection_reason=?, updated_at=NOW() WHERE id=?')
            ->execute([mb_substr($reason, 0, 255), $reservationId]);
        reservation_event($reservationId, 'rejected', $reason, $actorId);
        notify_user((int) $row['tourist_id'], 'booking_rejected', 'Reservation declined', $row['business_name'] . ' declined ' . $row['code'] . '. ' . $reason, APP_URL . '/tourist/itineraries.php?id=' . (int) $row['itinerary_id']);
        return ['ok' => true, 'message' => 'Reservation declined. The itinerary stop stays in place.'];
    }
    if ($status !== 'confirmed' || !in_array($next, ['completed', 'no_show'], true)) {
        return ['ok' => false, 'message' => 'That status change is not available.'];
    }
    db()->prepare('UPDATE restaurant_reservations SET status=?, updated_at=NOW() WHERE id=?')->execute([$next, $reservationId]);
    reservation_event($reservationId, $next, $next === 'no_show' ? 'Marked no-show' : 'Visit completed', $actorId);
    $rate = revenue_rate_for_business((int) $row['business_id']);
    $charge = $next === 'completed' || ($next === 'no_show' && ($rate['noshow_policy'] ?? 'none') === 'charge');
    if ($charge && (float) $row['fee_snapshot'] > 0) {
        revenue_insert_transaction(
            (int) $row['business_id'],
            $rate['category'],
            'reservation',
            $reservationId,
            0,
            format_money((float) $row['fee_snapshot']),
            (float) $row['fee_snapshot']
        );
    }
    $label = $next === 'completed' ? 'Reservation marked completed.' : 'Reservation marked as a no-show.';
    if ($next === 'no_show' && !$charge) {
        $label .= ' No fee was charged.';
    }
    return ['ok' => true, 'message' => $label];
}

/** @return array{ok:bool,message:string} */
function reservation_replace_restaurant(int $touristId, int $itemId, int $newBusinessId): array
{
    revenue_ensure_schema();
    $current = reservation_latest_for_item($itemId);
    if (!$current || $current['status'] !== 'rejected' || (int) $current['tourist_id'] !== $touristId) {
        return ['ok' => false, 'message' => 'You can replace this place after it declines the reservation.'];
    }
    $typeSt = db()->prepare(
        'SELECT bt.name FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id WHERE b.id=? LIMIT 1'
    );
    $typeSt->execute([(int) $current['business_id']]);
    $currentKind = revenue_bookable_kind((string) $typeSt->fetchColumn());
    $currentCopy = reservation_place_copy($currentKind);
    $biz = db()->prepare(
        'SELECT b.id, bt.name AS type_name FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.id=? AND b.approval_status=\'approved\' AND b.status=\'active\' LIMIT 1'
    );
    $biz->execute([$newBusinessId]);
    $row = $biz->fetch();
    $nextKind = revenue_bookable_kind($row['type_name'] ?? '');
    if (!$row || $nextKind !== $currentKind || !revenue_uses_reservation($nextKind)) {
        return ['ok' => false, 'message' => $currentCopy['replace_other']];
    }
    if ($newBusinessId === (int) $current['business_id']) {
        return ['ok' => false, 'message' => $currentCopy['replace_same']];
    }
    db()->prepare(
        'UPDATE itinerary_items SET business_id=? WHERE id=? AND itinerary_id=?'
    )->execute([$newBusinessId, $itemId, (int) $current['itinerary_id']]);
    return ['ok' => true, 'message' => 'Restaurant replaced. Submit a new reservation when you are ready.'];
}

function revenue_mark_overdue(): void
{
    revenue_ensure_schema();
    $ym = date('Y-m');
    db()->prepare(
        "UPDATE revenue_transactions SET status='overdue' WHERE status='unpaid' AND period_ym < ?"
    )->execute([$ym]);
    db()->prepare(
        "UPDATE revenue_statements SET status='overdue' WHERE status='unpaid' AND period_ym < ?"
    )->execute([$ym]);
}

function revenue_generate_statement(int $businessId, string $period): array
{
    revenue_ensure_schema();
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        return ['ok' => false, 'message' => 'Choose a month.'];
    }
    $sum = db()->prepare(
        "SELECT COALESCE(SUM(fee_amount),0) FROM revenue_transactions
         WHERE business_id=? AND period_ym=? AND statement_id IS NULL AND status IN ('unpaid','overdue')"
    );
    $sum->execute([$businessId, $period]);
    $total = round((float) $sum->fetchColumn(), 2);
    if ($total <= 0) {
        return ['ok' => false, 'message' => 'No open fees for that month.'];
    }
    $existing = db()->prepare('SELECT id, status FROM revenue_statements WHERE business_id=? AND period_ym=? LIMIT 1');
    $existing->execute([$businessId, $period]);
    $stmt = $existing->fetch();
    if ($stmt && ($stmt['status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'That month is already paid.'];
    }
    if ($stmt) {
        $id = (int) $stmt['id'];
        db()->prepare('UPDATE revenue_statements SET total_amount=total_amount+? WHERE id=?')->execute([$total, $id]);
    } else {
        db()->prepare(
            'INSERT INTO revenue_statements (business_id, period_ym, total_amount, status) VALUES (?,?,?,\'unpaid\')'
        )->execute([$businessId, $period, $total]);
        $id = (int) db()->lastInsertId();
    }
    db()->prepare(
        "UPDATE revenue_transactions SET statement_id=?
         WHERE business_id=? AND period_ym=? AND statement_id IS NULL AND status IN ('unpaid','overdue')"
    )->execute([$id, $businessId, $period]);
    return ['ok' => true, 'message' => 'Billing statement saved.'];
}

function revenue_verify_statement(int $statementId, int $adminId): array
{
    revenue_ensure_schema();
    $st = db()->prepare('SELECT * FROM revenue_statements WHERE id=? LIMIT 1');
    $st->execute([$statementId]);
    $row = $st->fetch();
    if (!$row || ($row['status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'That statement is not waiting for payment.'];
    }
    db()->prepare(
        "UPDATE revenue_statements SET status='paid', paid_at=NOW(), verified_by=? WHERE id=?"
    )->execute([$adminId, $statementId]);
    db()->prepare(
        "UPDATE revenue_transactions SET status='paid', paid_at=NOW() WHERE statement_id=? AND status<>'paid'"
    )->execute([$statementId]);
    return ['ok' => true, 'message' => 'Payment verified.'];
}

/** @return array<string,string> */
function menu_category_defaults(): array
{
    return menu_category_defaults_for_kind('food');
}

/** @return array<string,string> */
function menu_category_defaults_for_kind(string $kind): array
{
    if ($kind === 'beach') {
        return [
            'rental' => 'Rentals',
            'activity' => 'Activities',
            'cottage' => 'Cottages',
            'entrance' => 'Entrance',
        ];
    }
    if ($kind === 'wellness') {
        return [
            'treatment' => 'Treatments',
            'massage' => 'Massage',
            'class' => 'Classes',
            'facility' => 'Facilities',
        ];
    }
    return [
        'appetizer' => 'Appetizers',
        'main' => 'Mains',
        'drink' => 'Drinks',
        'dessert' => 'Desserts',
        'side' => 'Sides',
    ];
}

function menu_category_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS business_menu_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            category_key VARCHAR(40) NOT NULL,
            label VARCHAR(40) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_biz_menu_cat (business_id, category_key)
        ) ENGINE=InnoDB"
    );
}

/** @return array<string,string> */
function menu_category_options(?int $businessId = null): array
{
    if ($businessId === null || $businessId < 1) {
        return menu_category_defaults();
    }
    menu_category_ensure_schema();
    $st = db()->prepare('SELECT category_key, label FROM business_menu_categories WHERE business_id=? ORDER BY sort_order, id');
    $st->execute([$businessId]);
    $rows = $st->fetchAll() ?: [];
    if (!$rows) {
        $typeSt = db()->prepare(
            'SELECT bt.name FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id WHERE b.id=? LIMIT 1'
        );
        $typeSt->execute([$businessId]);
        $defaults = menu_category_defaults_for_kind(revenue_bookable_kind((string) $typeSt->fetchColumn()));
        $order = 1;
        $insert = db()->prepare('INSERT INTO business_menu_categories (business_id, category_key, label, sort_order) VALUES (?,?,?,?)');
        foreach ($defaults as $key => $label) {
            $insert->execute([$businessId, $key, $label, $order]);
            $order++;
        }
        $st->execute([$businessId]);
        $rows = $st->fetchAll() ?: [];
    }
    $options = [];
    foreach ($rows as $row) {
        $options[(string) $row['category_key']] = (string) $row['label'];
    }
    return $options !== [] ? $options : menu_category_defaults();
}

function menu_category_key(?string $raw, ?int $businessId = null): string
{
    $key = strtolower(trim((string) $raw));
    $options = menu_category_options($businessId);
    if (array_key_exists($key, $options)) {
        return $key;
    }
    $first = array_key_first($options);
    return $first !== null ? (string) $first : 'main';
}

/** @return array{ok:bool,message:string} */
function menu_category_add(int $businessId, string $label): array
{
    $label = trim($label);
    if ($label === '' || strlen($label) > 40) {
        return ['ok' => false, 'message' => 'Enter a category name up to 40 characters.'];
    }
    $options = menu_category_options($businessId);
    foreach ($options as $existing) {
        if (strcasecmp($existing, $label) === 0) {
            return ['ok' => false, 'message' => 'That category is already on the menu.'];
        }
    }
    $base = strtolower((string) preg_replace('/[^a-z0-9]+/', '', strtolower($label)));
    $base = substr($base !== '' ? $base : 'category', 0, 36);
    $key = $base;
    $n = 2;
    while (array_key_exists($key, $options)) {
        $key = substr($base, 0, 36) . $n;
        $n++;
    }
    $sort = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM business_menu_categories WHERE business_id=?');
    $sort->execute([$businessId]);
    db()->prepare('INSERT INTO business_menu_categories (business_id, category_key, label, sort_order) VALUES (?,?,?,?)')
        ->execute([$businessId, $key, $label, (int) $sort->fetchColumn()]);
    return ['ok' => true, 'message' => 'Category added.'];
}

/** @return array{ok:bool,message:string} */
function menu_category_rename(int $businessId, string $key, string $label): array
{
    $options = menu_category_options($businessId);
    $key = strtolower(trim($key));
    if (!array_key_exists($key, $options)) {
        return ['ok' => false, 'message' => 'Category not found.'];
    }
    $label = trim($label);
    if ($label === '' || strlen($label) > 40) {
        return ['ok' => false, 'message' => 'Enter a category name up to 40 characters.'];
    }
    foreach ($options as $existingKey => $existing) {
        if ($existingKey !== $key && strcasecmp($existing, $label) === 0) {
            return ['ok' => false, 'message' => 'That category is already on the menu.'];
        }
    }
    db()->prepare('UPDATE business_menu_categories SET label=? WHERE business_id=? AND category_key=?')
        ->execute([$label, $businessId, $key]);
    return ['ok' => true, 'message' => 'Category updated.'];
}

/** @return array{ok:bool,message:string} */
function menu_category_remove(int $businessId, string $key): array
{
    $options = menu_category_options($businessId);
    $key = strtolower(trim($key));
    if (!array_key_exists($key, $options)) {
        return ['ok' => false, 'message' => 'Category not found.'];
    }
    if (count($options) < 2) {
        return ['ok' => false, 'message' => 'Keep at least one category on the menu.'];
    }
    $fallback = '';
    foreach ($options as $existingKey => $existingLabel) {
        if ($existingKey !== $key) {
            $fallback = $existingKey;
            break;
        }
    }
    db()->prepare('UPDATE business_services SET menu_category=? WHERE business_id=? AND menu_category=?')
        ->execute([$fallback, $businessId, $key]);
    db()->prepare('DELETE FROM business_menu_categories WHERE business_id=? AND category_key=?')
        ->execute([$businessId, $key]);
    $movedTo = $options[$fallback] ?? 'another category';
    return ['ok' => true, 'message' => 'Category removed. Its dishes are now under ' . $movedTo . '.'];
}

function reservation_hours_text(mixed $raw): string
{
    $resolved = resolve_place_opening_hours($raw);
    if (!$resolved) {
        return '';
    }
    $bits = [];
    foreach (['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'] as $key => $label) {
        $slot = trim((string) ($resolved[$key] ?? ''));
        if ($slot === '' || strcasecmp($slot, 'closed') === 0) {
            continue;
        }
        $bits[] = $label . ' ' . format_hours_slot_12h($slot);
    }
    return implode(' · ', $bits);
}

function reservation_render_stop_panel(array $stop, array $itinerary, bool $canEdit): void
{
    if (($stop['stop_kind'] ?? '') !== 'business') {
        return;
    }
    $kind = revenue_bookable_kind($stop['business_type_name'] ?? '');
    if (!revenue_uses_reservation($kind)) {
        return;
    }
    $placeCopy = reservation_place_copy($kind);
    revenue_ensure_schema();
    ensure_place_schedule_schema();
    $itemId = (int) ($stop['id'] ?? 0);
    $bizId = (int) ($stop['business_id'] ?? 0);
    $reservation = reservation_latest_for_item($itemId);
    $lines = $reservation ? reservation_lines((int) $reservation['id']) : [];
    $menu = db()->prepare('SELECT id, name, price, description, image_path, menu_category FROM business_services WHERE business_id=? AND is_active=1 ORDER BY name');
    $menu->execute([$bizId]);
    $menu = $menu->fetchAll() ?: [];
    $hours = reservation_hours_text($stop['business_hours'] ?? null);
    $rate = revenue_rate_for_business($bizId);
    $fee = ($rate['model'] ?? '') === 'fixed' ? revenue_fee_from_rate($rate, 0) : 0.0;
    $dateValue = normalize_tour_date($stop['visit_date'] ?? null) ?: normalize_tour_date($itinerary['start_date'] ?? null) ?: '';
    $timeValue = '';
    $rawTime = trim((string) ($stop['start_time'] ?? ''));
    if ($rawTime !== '') {
        $timeValue = substr($rawTime, 0, 5);
    }
    if ($timeValue === '') {
        $timeValue = '12:00';
    }
    $guestCount = (int) ($itinerary['party_size'] ?? 0);
    if ($guestCount < 1) {
        $guestCount = 1;
    }
    $modalId = 'reserveModal' . $itemId;
    $replacements = [];
    if ($reservation && ($reservation['status'] ?? '') === 'rejected' && $canEdit) {
        $rep = db()->prepare(
            "SELECT b.id, b.name, bt.name AS type_name FROM businesses b
             JOIN business_types bt ON bt.id = b.business_type_id
             WHERE b.approval_status='approved' AND b.status='active' AND b.id<>?
             ORDER BY b.name"
        );
        $rep->execute([$bizId]);
        foreach ($rep->fetchAll() ?: [] as $place) {
            if (revenue_bookable_kind($place['type_name'] ?? '') === $kind) {
                $replacements[] = $place;
            }
        }
    }
    ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>">
        <?= $reservation ? 'Reservation' : e($placeCopy['button']) ?>
      </button>
      <?php if ($reservation): ?>
        <?= status_badge((string) $reservation['status']) ?>
      <?php endif; ?>
    </div>
    <div class="modal fade reserve-modal" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5 mb-0" id="<?= e($modalId) ?>Label">Reservation</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php $showReserveForm = $canEdit && !$reservation; ?>
            <?php if ($showReserveForm): ?>
            <form method="post" class="jb-kiosk" data-empty-order="<?= e($placeCopy['empty']) ?>" data-at-place="<?= e($placeCopy['at_place']) ?>" data-add-blocked="<?= e($placeCopy['add_blocked']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reserve_submit">
              <input type="hidden" name="itinerary_id" value="<?= (int) ($itinerary['id'] ?? 0) ?>">
              <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <?php else: ?>
            <div class="jb-kiosk">
            <?php endif; ?>
              <div class="jb-kiosk__bar">
                <div>
                  <div class="jb-kiosk__name"><?= e((string) ($stop['stop_name'] ?? $stop['business_name'] ?? $placeCopy['fallback_name'])) ?></div>
                  <?php if ($hours !== ''): ?>
                    <div class="jb-kiosk__hours"><?= e($hours) ?></div>
                  <?php endif; ?>
                </div>
                <?php if ($reservation): ?>
                  <?= status_badge((string) $reservation['status']) ?>
                <?php endif; ?>
              </div>
              <div class="jb-kiosk__stage" data-kiosk-root>
                <?php
                  $menuGroups = [];
                  foreach ($menu as $dish) {
                      $menuGroups[menu_category_key($dish['menu_category'] ?? '', $bizId)][] = $dish;
                  }
                  $firstCat = '';
                  foreach (array_keys(menu_category_options($bizId)) as $catKey) {
                      if (!empty($menuGroups[$catKey])) {
                          $firstCat = $catKey;
                          break;
                      }
                  }
                ?>
                <div class="jb-kiosk__cats" role="tablist">
                  <?php foreach (menu_category_options($bizId) as $catKey => $catLabel): ?>
                    <?php if (empty($menuGroups[$catKey])) { continue; } ?>
                    <button type="button" class="jb-kiosk__cat<?= $catKey === $firstCat ? ' is-on' : '' ?>" data-kiosk-cat="<?= e($catKey) ?>"><?= e($catLabel) ?></button>
                  <?php endforeach; ?>
                </div>
                <div class="jb-kiosk__board">
                  <?php if (!$menu): ?>
                    <div class="jb-kiosk__empty"><?= e($placeCopy['empty_menu']) ?></div>
                  <?php endif; ?>
                  <?php foreach ($menu as $dish): ?>
                    <?php
                      $dishImg = trim((string) ($dish['image_path'] ?? ''));
                      $dishUrl = $dishImg !== '' ? APP_URL . '/uploads/' . ltrim($dishImg, '/') : '';
                      $dishCat = menu_category_key($dish['menu_category'] ?? '', $bizId);
                      $dishPrice = (float) ($dish['price'] ?? 0);
                    ?>
                    <button type="button" class="jb-card" data-kiosk-item="<?= e($dishCat) ?>"<?= $dishCat === $firstCat ? '' : ' hidden' ?>
                            data-dish-open
                            data-dish-id="<?= (int) $dish['id'] ?>"
                            data-dish-name="<?= e($dish['name']) ?>"
                            data-dish-price="<?= e(number_format($dishPrice, 2, '.', '')) ?>"
                            data-dish-price-label="<?= e(format_money($dishPrice)) ?>"
                            data-dish-desc="<?= e((string) ($dish['description'] ?? '')) ?>"
                            data-dish-photo="<?= e($dishUrl) ?>">
                      <span class="jb-card__photo<?= $dishUrl === '' ? ' is-empty' : '' ?>"<?= $dishUrl !== '' ? ' style="background-image:url(\'' . e($dishUrl) . '\')"' : '' ?>></span>
                      <span class="jb-card__body">
                        <span class="jb-card__name"><?= e($dish['name']) ?></span>
                        <span class="jb-card__price"><?= e(format_money($dishPrice)) ?></span>
                      </span>
                    </button>
                    <?php if ($showReserveForm): ?>
                      <input type="hidden" name="qty[<?= (int) $dish['id'] ?>]" value="0" data-dish-qty="<?= (int) $dish['id'] ?>" data-price="<?= e(number_format($dishPrice, 2, '.', '')) ?>" data-name="<?= e($dish['name']) ?>">
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
                <aside class="jb-kiosk__side">
                  <?php if ($showReserveForm): ?>
                    <input type="hidden" name="order_mode" value="advance_order" data-order-mode>
                    <input type="hidden" name="reserve_date" value="<?= e($dateValue) ?>">
                    <input type="hidden" name="reserve_time" value="<?= e($timeValue) ?>">
                    <input type="hidden" name="guests" value="<?= $guestCount ?>">
                    <label class="jb-kiosk__check">
                      <input type="checkbox" data-at-restaurant>
                      <span><strong><?= e($placeCopy['check_title']) ?></strong><small><?= e($placeCopy['check_note']) ?></small></span>
                    </label>
                  <?php endif; ?>
                <div class="jb-kiosk__ticket">
                  <div class="jb-kiosk__ticket-title"><?= e($placeCopy['ticket']) ?></div>
                  <?php if ($reservation): ?>
                    <div class="jb-kiosk__ticket-line"><?= e($reservation['code']) ?></div>
                    <div class="jb-kiosk__ticket-line"><?= e(format_date((string) $reservation['reserve_date'])) ?> · <?= e(format_time_12h((string) $reservation['reserve_time'])) ?></div>
                    <div class="jb-kiosk__ticket-line"><?= (int) $reservation['guests'] ?> guest<?= (int) $reservation['guests'] === 1 ? '' : 's' ?> · <?= $reservation['order_mode'] === 'advance_order' ? e($placeCopy['ahead']) : e($placeCopy['at_place_short']) ?></div>
                    <?php if ($lines): ?>
                      <ul class="jb-kiosk__lines">
                        <?php foreach ($lines as $line): ?>
                          <li><span><?= (int) $line['qty'] ?> × <?= e($line['item_name']) ?></span><span><?= e(format_money((float) $line['unit_price'] * (int) $line['qty'])) ?></span></li>
                        <?php endforeach; ?>
                      </ul>
                      <div class="jb-kiosk__total"><span><?= e($placeCopy['total']) ?></span><strong><?= e(format_money((float) $reservation['food_total'])) ?></strong></div>
                    <?php endif; ?>
                    <?php if (($reservation['status'] ?? '') === 'rejected' && trim((string) ($reservation['rejection_reason'] ?? '')) !== ''): ?>
                      <div class="small text-danger">Reason: <?= e((string) $reservation['rejection_reason']) ?></div>
                    <?php endif; ?>
                    <?php if ($canEdit && in_array($reservation['status'], ['pending', 'confirmed'], true)): ?>
                      <form method="post" onsubmit="return confirm('Cancel this reservation?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reserve_cancel">
                        <input type="hidden" name="itinerary_id" value="<?= (int) ($itinerary['id'] ?? 0) ?>">
                        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel reservation</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($replacements): ?>
                      <form method="post" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reserve_replace">
                        <input type="hidden" name="itinerary_id" value="<?= (int) ($itinerary['id'] ?? 0) ?>">
                        <input type="hidden" name="item_id" value="<?= $itemId ?>">
                        <label class="form-label small mb-1"><?= e($placeCopy['replace']) ?></label>
                        <select class="form-select form-select-sm mb-2" name="business_id" required>
                          <?php foreach ($replacements as $place): ?>
                            <option value="<?= (int) $place['id'] ?>"><?= e($place['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Replace</button>
                      </form>
                    <?php endif; ?>
                  <?php elseif ($showReserveForm): ?>
                    <ul class="jb-kiosk__lines" data-order-list>
                      <li class="jb-kiosk__empty-order"><?= e($placeCopy['empty']) ?></li>
                    </ul>
                    <div class="jb-kiosk__total"><span><?= e($placeCopy['total']) ?></span><strong data-order-total><?= e(format_money(0)) ?></strong></div>
                    <p class="jb-kiosk__note"><?= e($placeCopy['fee_who']) ?> pays the CulTour fee<?= $fee > 0 ? ' (' . e(format_money($fee)) . ')' : '' ?>. You are not charged.</p>
                    <button class="btn jb-kiosk__go" type="submit">Place reservation</button>
                  <?php else: ?>
                    <div class="jb-kiosk__note">No reservation request yet.</div>
                  <?php endif; ?>
                </div>
                </aside>
              </div>
                <?php if ($showReserveForm): ?>
                <div class="jb-pop" data-dish-pop hidden>
                  <button type="button" class="jb-pop__scrim" data-pop-close aria-label="Close"></button>
                  <div class="jb-pop__card" role="dialog" aria-modal="true">
                    <button type="button" class="jb-pop__x" data-pop-close aria-label="Close">×</button>
                    <div class="jb-pop__photo is-empty" data-pop-photo></div>
                    <div class="jb-pop__top">
                      <div class="jb-pop__price" data-pop-price></div>
                      <div class="jb-qty">
                        <button type="button" data-qty-step="-1" aria-label="Less">−</button>
                        <input type="number" min="1" max="20" value="1" readonly data-pop-qty>
                        <button type="button" data-qty-step="1" aria-label="More">+</button>
                      </div>
                    </div>
                    <div class="jb-pop__name" data-pop-name></div>
                    <div class="jb-pop__label">Description</div>
                    <p class="jb-pop__desc" data-pop-desc></p>
                    <button type="button" class="jb-pop__add" data-pop-add>Add</button>
                  </div>
                </div>
                <?php endif; ?>
            <?php if ($showReserveForm): ?>
            </form>
            <?php else: ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php
    static $reserveModeScript = false;
    if (!$reserveModeScript) {
        $reserveModeScript = true;
        ?>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.reserve-modal').forEach(function (modal) {
          document.body.appendChild(modal);
        });
        var params = new URLSearchParams(window.location.search);
        var openId = params.get('reserve');
        if (openId && window.bootstrap) {
          var modal = document.getElementById('reserveModal' + openId);
          if (modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
          params.delete('reserve');
          var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
          window.history.replaceState({}, '', next);
        }
      });
      function peso(amount) {
        return '₱' + amount.toFixed(2);
      }
      function esc(text) {
        return String(text || '').replace(/[&<>"']/g, function (ch) {
          return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
      }
      function paintOrder(form) {
        if (!form) return;
        var list = form.querySelector('[data-order-list]');
        var totalEl = form.querySelector('[data-order-total]');
        if (!list) return;
        var atRestaurant = form.classList.contains('is-at-restaurant');
        var total = 0;
        var html = '';
        if (!atRestaurant) {
          form.querySelectorAll('[data-dish-qty]').forEach(function (input) {
            var qty = parseInt(input.value, 10) || 0;
            if (qty < 1) return;
            var price = parseFloat(input.getAttribute('data-price') || '0');
            total += price * qty;
            html += '<li><span>' + qty + ' × ' + esc(input.getAttribute('data-name')) + '</span><span class="jb-kiosk__line-end"><span>' + peso(price * qty) + '</span><button type="button" class="jb-kiosk__remove" data-order-remove="' + esc(input.getAttribute('data-dish-qty')) + '">Remove</button></span></li>';
          });
        }
        var emptyOrder = form.getAttribute('data-empty-order') || 'No dishes yet.';
        var atPlace = form.getAttribute('data-at-place') || 'You will order at the restaurant.';
        list.innerHTML = html || '<li class="jb-kiosk__empty-order">' + (atRestaurant ? esc(atPlace) : esc(emptyOrder)) + '</li>';
        if (totalEl) totalEl.textContent = peso(total);
      }
      document.addEventListener('change', function (event) {
        var box = event.target.closest('[data-at-restaurant]');
        if (!box) return;
        var form = box.closest('form');
        if (!form) return;
        var mode = form.querySelector('[data-order-mode]');
        var on = box.checked;
        form.classList.toggle('is-at-restaurant', on);
        if (mode) mode.value = on ? 'table_only' : 'advance_order';
        if (on) {
          form.querySelectorAll('[data-dish-qty]').forEach(function (input) { input.value = '0'; });
        }
        paintOrder(form);
      });
      document.addEventListener('click', function (event) {
        var removeBtn = event.target.closest('[data-order-remove]');
        if (removeBtn) {
          var orderForm = removeBtn.closest('form');
          var savedQty = orderForm ? orderForm.querySelector('[data-dish-qty="' + removeBtn.getAttribute('data-order-remove') + '"]') : null;
          if (savedQty) savedQty.value = '0';
          paintOrder(orderForm);
          return;
        }
        var opener = event.target.closest('[data-dish-open]');
        if (opener) {
          var root = opener.closest('.jb-kiosk');
          var pop = root ? root.querySelector('[data-dish-pop]') : null;
          if (!pop) return;
          pop.hidden = false;
          pop.setAttribute('data-open-id', opener.getAttribute('data-dish-id') || '');
          var photo = pop.querySelector('[data-pop-photo]');
          var src = opener.getAttribute('data-dish-photo') || '';
          if (photo) {
            photo.classList.toggle('is-empty', src === '');
            photo.style.backgroundImage = src ? 'url("' + src.replace(/"/g, '') + '")' : '';
          }
          var name = pop.querySelector('[data-pop-name]');
          var price = pop.querySelector('[data-pop-price]');
          var desc = pop.querySelector('[data-pop-desc]');
          var qty = pop.querySelector('[data-pop-qty]');
          if (name) name.textContent = opener.getAttribute('data-dish-name') || '';
          if (price) price.textContent = opener.getAttribute('data-dish-price-label') || '';
          if (desc) desc.textContent = opener.getAttribute('data-dish-desc') || 'No description yet.';
          var saved = root.querySelector('[data-dish-qty="' + opener.getAttribute('data-dish-id') + '"]');
          var current = saved ? parseInt(saved.value, 10) || 0 : 0;
          if (qty) qty.value = String(current > 0 ? current : 1);
          var add = pop.querySelector('[data-pop-add]');
          var atRestaurant = root.classList.contains('is-at-restaurant');
          if (add) {
            add.disabled = atRestaurant;
            add.textContent = atRestaurant ? (root.getAttribute('data-add-blocked') || 'Order at the restaurant') : 'Add';
          }
          return;
        }
        if (event.target.closest('[data-pop-close]')) {
          var popClose = event.target.closest('[data-dish-pop]');
          if (popClose) popClose.hidden = true;
          return;
        }
        var addBtn = event.target.closest('[data-pop-add]');
        if (addBtn && !addBtn.disabled) {
          var popAdd = addBtn.closest('[data-dish-pop]');
          var form = addBtn.closest('form');
          var id = popAdd ? popAdd.getAttribute('data-open-id') : '';
          var qtyInput = popAdd ? popAdd.querySelector('[data-pop-qty]') : null;
          var savedInput = form ? form.querySelector('[data-dish-qty="' + id + '"]') : null;
          if (savedInput && qtyInput) savedInput.value = String(parseInt(qtyInput.value, 10) || 1);
          if (popAdd) popAdd.hidden = true;
          paintOrder(form);
          return;
        }
        var step = event.target.closest('[data-qty-step]');
        if (!step) return;
        var wrap = step.closest('.jb-qty');
        var input = wrap ? wrap.querySelector('input') : null;
        if (!input) return;
        var next = (parseInt(input.value, 10) || 0) + parseInt(step.getAttribute('data-qty-step'), 10);
        if (next < 1) next = 1;
        if (next > 20) next = 20;
        input.value = String(next);
      });
      document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-kiosk-cat]');
        if (!btn) return;
        var kiosk = btn.closest('[data-kiosk-root]');
        if (!kiosk) return;
        var cat = btn.getAttribute('data-kiosk-cat');
        kiosk.querySelectorAll('[data-kiosk-cat]').forEach(function (tab) {
          tab.classList.toggle('is-on', tab === btn);
        });
        kiosk.querySelectorAll('[data-kiosk-item]').forEach(function (item) {
          item.hidden = item.getAttribute('data-kiosk-item') !== cat;
        });
      });
    </script>
        <?php
    }
}
