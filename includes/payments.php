<?php
/**
 * CulTour payments — GCash demo checkout.
 * Tourists submit a reference; admins confirm it. Demo mode does not mark paid automatically.
 * Swap PAYMENT_PROVIDER later for PayMongo without changing booking flow.
 */

require_once __DIR__ . '/booking.php';

function payment_webhook_url(): string
{
    return APP_URL . '/api/payments/webhook.php';
}

function payment_checkout_url(int $bookingId, string $intentId): string
{
    return APP_URL . '/tourist/pay.php?booking_id=' . $bookingId . '&intent=' . rawurlencode($intentId);
}

function package_payment_checkout_url(int $orderId, string $intentId): string
{
    return APP_URL . '/tourist/package-pay.php?order_id=' . $orderId . '&intent=' . rawurlencode($intentId);
}

/**
 * Address a phone on the same Wi-Fi can open. localhost on the QR only works on this computer.
 */
function payment_phone_base_url(): string
{
    $parts = parse_url(APP_URL) ?: [];
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? 'localhost';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return $scheme . '://' . $host . $port . $path;
    }
    $ip = payment_lan_ipv4();
    if ($ip === null) {
        return rtrim(APP_URL, '/');
    }
    return $scheme . '://' . $ip . $port . $path;
}

function payment_lan_ipv4(): ?string
{
    $ips = [];
    $host = gethostname();
    if (is_string($host) && $host !== '') {
        $resolved = gethostbyname($host);
        if (is_string($resolved)) {
            $ips[] = $resolved;
        }
        $list = @gethostbynamel($host);
        if (is_array($list)) {
            $ips = array_merge($ips, $list);
        }
    }
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $ips[] = (string) $_SERVER['SERVER_ADDR'];
    }
    foreach ($ips as $ip) {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue;
        }
        if ($ip === '127.0.0.1') {
            continue;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            continue;
        }
        return $ip;
    }
    return null;
}

function payment_scan_token(int $bookingId, string $intent): string
{
    $secret = defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : 'cultour';
    return hash_hmac('sha256', $bookingId . '|' . $intent, $secret);
}

function payment_scan_token_ok(int $bookingId, string $intent, string $token): bool
{
    if ($bookingId < 1 || $intent === '' || $token === '') {
        return false;
    }
    return hash_equals(payment_scan_token($bookingId, $intent), $token);
}

function payment_scan_url(int $bookingId, string $intent): string
{
    return payment_phone_base_url()
        . '/tourist/pay-scan.php?booking_id=' . $bookingId
        . '&intent=' . rawurlencode($intent)
        . '&token=' . rawurlencode(payment_scan_token($bookingId, $intent));
}

function package_payment_scan_url(int $orderId, string $intent): string
{
    return payment_phone_base_url()
        . '/tourist/pay-scan.php?kind=package&order_id=' . $orderId
        . '&intent=' . rawurlencode($intent)
        . '&token=' . rawurlencode(payment_scan_token($orderId, $intent));
}

function payment_qr_data_uri(string $text): string
{
    $lib = __DIR__ . '/lib/phpqrcode.php';
    if ($text === '' || !is_file($lib)) {
        return '';
    }
    require_once $lib;
    if (!class_exists('QRcode', false)) {
        return '';
    }
    ob_start();
    $tab = QRcode::text($text, false, QR_ECLEVEL_M);
    ob_end_clean();
    if (!is_array($tab) || $tab === []) {
        return '';
    }
    $quiet = 2;
    $n = count($tab);
    $size = $n + ($quiet * 2);
    $rects = '';
    for ($y = 0; $y < $n; $y++) {
        $row = (string) $tab[$y];
        $len = strlen($row);
        for ($x = 0; $x < $len; $x++) {
            if ($row[$x] === '1') {
                $rects .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet) . '" width="1" height="1"/>';
            }
        }
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#ffffff"/>'
        . '<g fill="#000000">' . $rects . '</g></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Phone confirmed the scanned checkout. Marks this booking paid inside CulTour.
 *
 * @return array{ok:bool,message:string,reference?:string}
 */
function payment_complete_scan(array $booking): array
{
    ensure_booking_payment_schema();
    if (($booking['payment_status'] ?? '') === 'paid') {
        return [
            'ok' => true,
            'message' => 'Already paid.',
            'reference' => (string) ($booking['payment_reference'] ?? ''),
        ];
    }
    $ref = 'GCASH-' . strtoupper(bin2hex(random_bytes(4)));
    $res = booking_mark_payment($booking, 'paid', $ref, ['scan' => true]);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => $res['message']];
    }
    return ['ok' => true, 'message' => 'Payment received.', 'reference' => $ref];
}

/**
 * Amount due for a guide booking.
 * Daily guide rate × tour days. Guest count does not change the price.
 *
 * @param array $guideRow Must include hourly_rate (used as daily rate)
 * @param array|null $booking Optional booking row (tour dates, itinerary_id)
 */
function booking_saved_bill_total(?array $booking): ?float
{
    if (!$booking) {
        return null;
    }
    if ((int) ($booking['id'] ?? 0) > 0 && !array_key_exists('guide_amount', $booking)) {
        $st = db()->prepare(
            'SELECT guide_amount, accommodation_amount, transportation_amount FROM bookings WHERE id=? LIMIT 1'
        );
        $st->execute([(int) $booking['id']]);
        $row = $st->fetch();
        if ($row) {
            $booking = array_merge($booking, $row);
        }
    }
    if (!array_key_exists('guide_amount', $booking) || $booking['guide_amount'] === null || $booking['guide_amount'] === '') {
        return null;
    }
    return round(
        (float) $booking['guide_amount']
        + (float) ($booking['accommodation_amount'] ?? 0)
        + (float) ($booking['transportation_amount'] ?? 0),
        2
    );
}

function booking_payment_amount(array $guideRow, ?array $booking = null): float
{
    $saved = booking_saved_bill_total($booking ?? $guideRow);
    if ($saved !== null) {
        return $saved;
    }
    $rate = (float) ($guideRow['hourly_rate'] ?? $booking['hourly_rate'] ?? 0);
    $b = $booking ?? $guideRow;
    $days = 1;

    $start = normalize_tour_date($b['tour_start_date'] ?? null)
        ?: normalize_tour_date($b['booking_date'] ?? null);
    $end = normalize_tour_date($b['tour_end_date'] ?? null);
    if (!empty($b['itinerary_id'])) {
        try {
            $itin = db()->prepare(
                'SELECT start_date, end_date, template_day_count, is_suggested FROM itineraries WHERE id=?'
            );
            $itin->execute([(int) $b['itinerary_id']]);
            $itin = $itin->fetch() ?: null;
            if ($itin) {
                $days = itinerary_logical_day_count($itin);
            } elseif ($start !== null) {
                $days = itinerary_day_count($start, $end ?: $start);
            }
        } catch (Throwable $e) {
            $days = $start !== null ? itinerary_day_count($start, $end ?: $start) : 1;
        }
    } elseif ($start !== null) {
        $days = itinerary_day_count($start, $end ?: $start);
    }

    return max(0, round($rate * max(1, $days), 2));
}

function payment_new_intent_id(): string
{
    return 'gcash_' . bin2hex(random_bytes(12));
}

/**
 * Create / refresh a GCash payment intent on an unpaid booking.
 * @return array{ok:bool,message:string,intent_id:?string,amount:?float,checkout_url:?string}
 */
function booking_start_gcash_payment(array $booking, array $guideRow): array
{
    ensure_booking_payment_schema();
    if (($booking['payment_status'] ?? '') === 'paid') {
        return [
            'ok' => false,
            'message' => 'This booking is already paid.',
            'intent_id' => null,
            'amount' => null,
            'checkout_url' => null,
        ];
    }
    if (!in_array((string) ($booking['status'] ?? ''), ['pending', 'approved'], true)) {
        return [
            'ok' => false,
            'message' => 'This booking cannot accept payment right now.',
            'intent_id' => null,
            'amount' => null,
            'checkout_url' => null,
        ];
    }

    $intent = trim((string) ($booking['payment_intent_id'] ?? ''));
    if ($intent === '') {
        $intent = payment_new_intent_id();
    }
    $amount = booking_payment_amount($guideRow, $booking);
    if ($amount <= 0) {
        return [
            'ok' => false,
            'message' => 'Guide daily rate is missing — cannot start GCash payment.',
            'intent_id' => null,
            'amount' => null,
            'checkout_url' => null,
        ];
    }

    db()->prepare(
        'UPDATE bookings SET payment_intent_id=?, payment_amount=?, payment_status=IF(payment_status IN (\'paid\',\'pending_review\',\'pay_at_office\'), payment_status, \'unpaid\') WHERE id=?'
    )->execute([$intent, $amount, (int) $booking['id']]);

    return [
        'ok' => true,
        'message' => 'GCash checkout ready.',
        'intent_id' => $intent,
        'amount' => $amount,
        'checkout_url' => payment_checkout_url((int) $booking['id'], $intent),
    ];
}

/**
 * HMAC signature for webhook body (demo + future providers).
 */
function payment_sign_payload(string $rawBody, ?string $secret = null): string
{
    $secret = $secret ?: (defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : '');
    return hash_hmac('sha256', $rawBody, $secret);
}

function payment_verify_signature(string $rawBody, ?string $headerSig): bool
{
    if ($headerSig === null || $headerSig === '') {
        return false;
    }
    $expected = payment_sign_payload($rawBody);
    return hash_equals($expected, $headerSig);
}

/**
 * Apply a successful payment webhook payload.
 * Expected JSON: { event, intent_id, reference, amount? }
 *
 * @return array{ok:bool,message:string,booking:?array}
 */
function payment_apply_webhook(array $payload): array
{
    ensure_booking_payment_schema();
    $event = (string) ($payload['event'] ?? '');
    if (!in_array($event, ['payment.paid', 'checkout.session.completed', 'gcash.paid'], true)) {
        return ['ok' => false, 'message' => 'Ignored event.', 'booking' => null];
    }

    $intent = trim((string) ($payload['intent_id'] ?? $payload['data']['intent_id'] ?? ''));
    if ($intent === '') {
        return ['ok' => false, 'message' => 'Missing intent_id.', 'booking' => null];
    }

    $st = db()->prepare('SELECT * FROM bookings WHERE payment_intent_id=? LIMIT 1');
    $st->execute([$intent]);
    $booking = $st->fetch() ?: null;
    if (!$booking) {
        return ['ok' => false, 'message' => 'Booking not found for intent.', 'booking' => null];
    }
    if (($booking['payment_status'] ?? '') === 'paid') {
        return ['ok' => true, 'message' => 'Already paid.', 'booking' => $booking];
    }

    $ref = trim((string) ($payload['reference'] ?? $payload['data']['reference'] ?? ''));
    if ($ref === '') {
        $ref = 'GCASH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    $amountIn = isset($payload['amount']) ? (float) $payload['amount'] : null;
    if ($amountIn === null) {
        return ['ok' => false, 'message' => 'Amount is required.', 'booking' => $booking];
    }
    if ((float) ($booking['payment_amount'] ?? 0) > 0) {
        if (abs($amountIn - (float) $booking['payment_amount']) > 0.05) {
            return ['ok' => false, 'message' => 'Amount mismatch.', 'booking' => $booking];
        }
    }

    $demo = defined('PAYMENT_PROVIDER') && PAYMENT_PROVIDER === 'gcash_demo';
    if ($demo) {
        return [
            'ok' => false,
            'message' => 'Demo GCash is confirmed by the Tourism Office, not by webhook.',
            'booking' => $booking,
        ];
    }

    $res = booking_mark_payment($booking, 'paid', $ref, ['webhook' => true]);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => $res['message'], 'booking' => $booking];
    }

    $st->execute([$intent]);
    $booking = $st->fetch() ?: $booking;

    return ['ok' => true, 'message' => 'Payment recorded.', 'booking' => $booking];
}

/**
 * Demo-only: POST a signed paid event to our own webhook (simulates GCash callback).
 */
function payment_simulate_gcash_paid(string $intentId, float $amount): array
{
    $payload = [
        'event' => 'gcash.paid',
        'intent_id' => $intentId,
        'amount' => $amount,
        'reference' => 'GCASH-' . strtoupper(bin2hex(random_bytes(4))),
        'provider' => 'gcash_demo',
        'paid_at' => date('c'),
    ];
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig = payment_sign_payload($raw);

    $url = payment_webhook_url();
    // Prefer internal apply when curl unavailable / same process
    if (!function_exists('curl_init')) {
        return payment_apply_webhook($payload);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-CulTour-Signature: ' . $sig,
            'X-Payment-Provider: gcash_demo',
        ],
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_TIMEOUT => 12,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        // Fallback if localhost webhook loop fails in some XAMPP setups
        return payment_apply_webhook($payload);
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return payment_apply_webhook($payload);
    }
    return [
        'ok' => !empty($decoded['ok']),
        'message' => (string) ($decoded['message'] ?? 'Webhook response'),
        'booking' => $decoded['booking'] ?? null,
    ];
}

/**
 * Tourist submits a GCash reference. Does not mark the booking paid.
 *
 * @return array{ok:bool,message:string}
 */
function booking_submit_gcash_reference(array $booking, string $ref): array
{
    ensure_booking_payment_schema();
    if (($booking['payment_status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'This booking is already paid.'];
    }
    if (!in_array((string) ($booking['status'] ?? ''), ['pending', 'approved'], true)) {
        return ['ok' => false, 'message' => 'This booking cannot accept payment right now.'];
    }
    $ref = trim($ref);
    if (strlen($ref) < 4) {
        return ['ok' => false, 'message' => 'Enter your GCash / InstaPay reference number after you pay.'];
    }
    if (strlen($ref) > 120) {
        return ['ok' => false, 'message' => 'Reference number is too long (max 120 characters).'];
    }
    db()->prepare(
        "UPDATE bookings SET payment_reference=?, payment_status='pending_review' WHERE id=? AND payment_status<>'paid'"
    )->execute([$ref, (int) $booking['id']]);
    if (function_exists('notify_active_admins')) {
        notify_active_admins(
            'approval',
            'GCash reference to confirm',
            'Booking ' . ($booking['booking_code'] ?? '') . ' submitted GCash ref ' . $ref . '.',
            APP_URL . '/admin/bookings.php?payment=pending'
        );
    }
    return [
        'ok' => true,
        'message' => 'Reference submitted. The Tourism Office will confirm your GCash payment. CulTour checkout is a demo — payment is not marked paid until an admin confirms the reference.',
    ];
}

function ensure_package_order_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS package_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_code VARCHAR(20) NOT NULL,
            tourist_id INT UNSIGNED NOT NULL,
            business_id INT UNSIGNED NOT NULL,
            business_user_id INT UNSIGNED NOT NULL,
            service_id INT UNSIGNED NOT NULL,
            package_name VARCHAR(160) NOT NULL,
            business_name VARCHAR(200) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            tour_date DATE NULL,
            package_days INT UNSIGNED NULL,
            payment_status ENUM('unpaid','pending_review','paid','pay_at_office') NOT NULL DEFAULT 'unpaid',
            payment_reference VARCHAR(120) NULL,
            payment_intent_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_package_order_code (order_code),
            INDEX idx_package_order_tourist (tourist_id, service_id, payment_status)
        ) ENGINE=InnoDB"
    );
    $cols = db()->query('SHOW COLUMNS FROM package_orders')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tour_date', $cols, true)) {
        db()->exec('ALTER TABLE package_orders ADD COLUMN tour_date DATE NULL AFTER amount');
    }
    if (!in_array('package_days', $cols, true)) {
        db()->exec('ALTER TABLE package_orders ADD COLUMN package_days INT UNSIGNED NULL AFTER tour_date');
    }
    if (!in_array('package_pax', $cols, true)) {
        db()->exec('ALTER TABLE package_orders ADD COLUMN package_pax INT UNSIGNED NULL AFTER package_days');
    }
}

/**
 * Book a tour package at the price stored on the service, then open GCash checkout.
 *
 * @return array{ok:bool,message:string,checkout_url:?string}
 */
function package_order_create(int $touristId, int $serviceId, string $tourDate): array
{
    ensure_place_schedule_schema();
    ensure_package_order_schema();
    if ($touristId < 1 || $serviceId < 1) {
        return ['ok' => false, 'message' => 'Choose a package to book.', 'checkout_url' => null];
    }
    $dateParsed = package_tour_date_from_post(['tour_date' => $tourDate]);
    if (!$dateParsed['ok'] || $dateParsed['date'] === null) {
        return ['ok' => false, 'message' => $dateParsed['message'], 'checkout_url' => null];
    }
    $tourDate = $dateParsed['date'];
    $st = db()->prepare(
        'SELECT s.id, s.name AS package_name, s.price, s.package_days, s.capacity, s.is_active,
                b.id AS business_id, b.user_id AS business_user_id, b.name AS business_name,
                bt.name AS type_name, ' . business_itinerary_ready_columns() . '
         FROM business_services s
         JOIN businesses b ON b.id = s.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE s.id=? AND s.is_active=1 AND b.approval_status=\'approved\' AND b.status=\'active\'
         LIMIT 1'
    );
    $st->execute([$serviceId]);
    $row = $st->fetch() ?: null;
    if (!$row || business_catalog_mode($row['type_name'] ?? '') !== 'packages' || !business_itinerary_ready($row)) {
        return ['ok' => false, 'message' => 'This package is not available to book.', 'checkout_url' => null];
    }
    $amount = round((float) ($row['price'] ?? 0), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'This package does not have a price yet.', 'checkout_url' => null];
    }
    $packageDays = (int) ($row['package_days'] ?? 0);
    if ($packageDays < 1) {
        return ['ok' => false, 'message' => 'This package does not have a length yet.', 'checkout_url' => null];
    }
    $packagePax = (int) ($row['capacity'] ?? 0);
    $packagePax = $packagePax > 0 ? $packagePax : null;
    $packageName = trim((string) ($row['package_name'] ?? ''));
    if ($packageName === '') {
        $packageName = 'Package';
    }
    $businessName = trim((string) ($row['business_name'] ?? ''));
    $open = db()->prepare(
        "SELECT * FROM package_orders
         WHERE tourist_id=? AND service_id=? AND payment_status='unpaid'
         ORDER BY id DESC LIMIT 1"
    );
    $open->execute([$touristId, $serviceId]);
    $order = $open->fetch() ?: null;
    if ($order) {
        db()->prepare(
            'UPDATE package_orders
             SET package_name=?, business_name=?, business_id=?, business_user_id=?, amount=?, tour_date=?, package_days=?, package_pax=?
             WHERE id=? AND payment_status=\'unpaid\''
        )->execute([
            $packageName,
            $businessName,
            (int) $row['business_id'],
            (int) $row['business_user_id'],
            $amount,
            $tourDate,
            $packageDays,
            $packagePax,
            (int) $order['id'],
        ]);
        $order['package_name'] = $packageName;
        $order['business_name'] = $businessName;
        $order['amount'] = $amount;
    } else {
        $code = 'PK' . strtoupper(bin2hex(random_bytes(4)));
        db()->prepare(
            'INSERT INTO package_orders
                (order_code, tourist_id, business_id, business_user_id, service_id, package_name, business_name, amount, tour_date, package_days, package_pax)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $code,
            $touristId,
            (int) $row['business_id'],
            (int) $row['business_user_id'],
            $serviceId,
            $packageName,
            $businessName,
            $amount,
            $tourDate,
            $packageDays,
            $packagePax,
        ]);
        $load = db()->prepare('SELECT * FROM package_orders WHERE id=? LIMIT 1');
        $load->execute([(int) db()->lastInsertId()]);
        $order = $load->fetch() ?: null;
    }
    if (!$order) {
        return ['ok' => false, 'message' => 'Could not start this booking.', 'checkout_url' => null];
    }
    $intent = trim((string) ($order['payment_intent_id'] ?? ''));
    if ($intent === '') {
        $intent = payment_new_intent_id();
        db()->prepare('UPDATE package_orders SET payment_intent_id=? WHERE id=? AND payment_status=\'unpaid\'')
            ->execute([$intent, (int) $order['id']]);
    }
    return [
        'ok' => true,
        'message' => 'Checkout ready.',
        'checkout_url' => package_payment_checkout_url((int) $order['id'], $intent),
    ];
}

function package_order_for_tourist(int $orderId, int $touristId): ?array
{
    ensure_package_order_schema();
    if ($orderId < 1 || $touristId < 1) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM package_orders WHERE id=? AND tourist_id=? LIMIT 1');
    $st->execute([$orderId, $touristId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * @return array{ok:bool,message:string,reference?:string}
 */
function package_order_complete_scan(array $order): array
{
    ensure_package_order_schema();
    if (($order['payment_status'] ?? '') === 'paid') {
        return [
            'ok' => true,
            'message' => 'Already paid.',
            'reference' => (string) ($order['payment_reference'] ?? ''),
        ];
    }
    if (!in_array((string) ($order['payment_status'] ?? ''), ['unpaid', 'pay_at_office'], true)) {
        return ['ok' => false, 'message' => 'This package payment cannot be confirmed right now.'];
    }
    $ref = 'GCASH-' . strtoupper(bin2hex(random_bytes(4)));
    db()->prepare(
        "UPDATE package_orders SET payment_status='paid', payment_reference=? WHERE id=? AND payment_status<>'paid'"
    )->execute([$ref, (int) $order['id']]);
    package_order_notify_paid($order, $ref);
    if (function_exists('revenue_record_package_order')) {
        revenue_record_package_order((int) ($order['id'] ?? 0));
    }
    return ['ok' => true, 'message' => 'Payment received.', 'reference' => $ref];
}

/**
 * @return array{ok:bool,message:string}
 */
function package_order_mark_office(array $order): array
{
    ensure_package_order_schema();
    if (($order['payment_status'] ?? '') === 'paid') {
        return ['ok' => false, 'message' => 'This package is already paid.'];
    }
    db()->prepare(
        "UPDATE package_orders SET payment_status='pay_at_office' WHERE id=? AND payment_status<>'paid'"
    )->execute([(int) $order['id']]);
    $name = trim((string) ($order['package_name'] ?? 'package'));
    notify_user(
        (int) ($order['tourist_id'] ?? 0),
        'approval',
        'Pay at the Tourism Office',
        'Your booking for ' . $name . ' (' . ($order['order_code'] ?? '') . ') will be paid at the Tourism Office.',
        APP_URL . '/tourist/packages.php'
    );
    $ownerId = (int) ($order['business_user_id'] ?? 0);
    if ($ownerId > 0) {
        notify_user(
            $ownerId,
            'booking_request',
            'Package booked',
            $name . ' (' . ($order['order_code'] ?? '') . ') will be paid at the Tourism Office.',
            APP_URL . '/business/dashboard.php'
        );
    }
    return ['ok' => true, 'message' => 'You can pay for ' . $name . ' at the Tourism Office.'];
}

function package_order_notify_paid(array $order, string $ref): void
{
    $name = trim((string) ($order['package_name'] ?? 'package'));
    $amount = format_money((float) ($order['amount'] ?? 0));
    $touristId = (int) ($order['tourist_id'] ?? 0);
    if ($touristId < 1) {
        return;
    }
    notify_user(
        $touristId,
        'approval',
        'Payment received',
        'Payment for ' . $name . ' (' . ($order['order_code'] ?? '') . ') was received. ' . $amount . ' · ' . $ref,
        APP_URL . '/tourist/packages.php'
    );
    $ownerId = (int) ($order['business_user_id'] ?? 0);
    if ($ownerId > 0) {
        notify_user(
            $ownerId,
            'booking_request',
            'Package booked',
            $name . ' (' . ($order['order_code'] ?? '') . ') was paid. ' . $amount . '.',
            APP_URL . '/business/dashboard.php'
        );
    }
}
