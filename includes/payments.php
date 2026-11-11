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
    $tunnel = payment_tunnel_base_url();
    if ($tunnel !== null) {
        return $tunnel . $path;
    }
    $ip = payment_lan_ipv4();
    if ($ip === null) {
        return rtrim(APP_URL, '/');
    }
    return $scheme . '://' . $ip . $port . $path;
}

function payment_tunnel_base_url(): ?string
{
    $file = dirname(__DIR__) . '/storage/payment_tunnel_url.txt';
    if (!is_file($file)) {
        return null;
    }
    $url = trim((string) file_get_contents($file));
    if (!preg_match('#^https://[a-z0-9-]+\.trycloudflare\.com$#i', $url)) {
        return null;
    }
    if (time() - (int) filemtime($file) > 6 * 3600) {
        return null;
    }
    return $url;
}

function payment_lan_ipv4(): ?string
{
    $fromGateway = payment_lan_ipv4_with_gateway();
    if ($fromGateway !== null) {
        return $fromGateway;
    }
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
        if (payment_ipv4_is_phone_reachable($ip)) {
            return $ip;
        }
    }
    return null;
}

function payment_ipv4_is_phone_reachable(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    if ($ip === '127.0.0.1' || str_starts_with($ip, '169.254.')) {
        return false;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Wi-Fi address on the adapter that has a default gateway. Link-local addresses are skipped. */
function payment_lan_ipv4_with_gateway(): ?string
{
    $raw = shell_exec('ipconfig');
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $blocks = preg_split('/\r?\n\r?\n/', $raw) ?: [];
    $fallback = null;
    foreach ($blocks as $block) {
        if (!preg_match('/IPv4 Address[^:]*:\s*([0-9.]+)/i', $block, $ipMatch)) {
            continue;
        }
        $ip = $ipMatch[1];
        if (!payment_ipv4_is_phone_reachable($ip)) {
            continue;
        }
        $hasGateway = preg_match('/Default Gateway[^:]*:\s*([0-9.]+)/i', $block, $gwMatch) && $gwMatch[1] !== '0.0.0.0';
        if ($hasGateway) {
            return $ip;
        }
        $fallback ??= $ip;
    }
    return $fallback;
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

function payment_qr_svg(string $text): string
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
            if (($row[$x] ?? '') === '1') {
                $rects .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet) . '" width="1" height="1"/>';
            }
        }
    }
    if ($rects === '') {
        return '';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="220" height="220" shape-rendering="crispEdges" role="img" aria-label="Scan to pay">'
        . '<rect width="100%" height="100%" fill="#ffffff"/>'
        . '<g fill="#000000">' . $rects . '</g></svg>';
}

function payment_qr_data_uri(string $text): string
{
    $svg = payment_qr_svg($text);
    if ($svg === '') {
        return '';
    }
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
    if (!in_array('package_party_notes', $cols, true)) {
        db()->exec('ALTER TABLE package_orders ADD COLUMN package_party_notes TEXT NULL AFTER package_pax');
    }
    if (!in_array('booking_status', $cols, true)) {
        db()->exec("ALTER TABLE package_orders ADD COLUMN booking_status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending' AFTER payment_status");
    } else {
        $type = (string) db()->query("SHOW COLUMNS FROM package_orders LIKE 'booking_status'")->fetch()['Type'];
        if (!str_contains($type, 'completed')) {
            db()->exec("ALTER TABLE package_orders MODIFY booking_status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending'");
        }
    }
    $bizCols = db()->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bookings_seen_order_id', $bizCols, true)) {
        db()->exec('ALTER TABLE businesses ADD COLUMN bookings_seen_order_id INT UNSIGNED NOT NULL DEFAULT 0');
    }
    $orderCols = db()->query('SHOW COLUMNS FROM package_orders')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'accommodation_business_id' => 'INT UNSIGNED NULL',
        'accommodation_service_id' => 'INT UNSIGNED NULL',
        'accommodation_declined' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'transportation_business_id' => 'INT UNSIGNED NULL',
        'transportation_service_id' => 'INT UNSIGNED NULL',
        'transportation_declined' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'rejection_reason' => 'VARCHAR(255) NULL',
        'operator_ready' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'snapshot_itinerary_id' => 'INT UNSIGNED NULL',
        'snapshot_inclusions' => 'TEXT NULL',
        'snapshot_description' => 'TEXT NULL',
    ] as $col => $def) {
        if (!in_array($col, $orderCols, true)) {
            db()->exec('ALTER TABLE package_orders ADD COLUMN ' . $col . ' ' . $def);
        }
    }
}

/** Package orders still waiting on the tour operator. Stays until none are pending. */
function package_unseen_booking_count(int $businessId): int
{
    if ($businessId < 1) {
        return 0;
    }
    try {
        ensure_package_order_schema();
        $st = db()->prepare(
            "SELECT COUNT(*) FROM package_orders o
             WHERE o.business_id=?
               AND o.booking_status='pending'
               AND o.payment_status IN ('paid','pay_at_office','pending_review')"
        );
        $st->execute([$businessId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function package_mark_bookings_seen(int $businessId): void
{
    if ($businessId < 1) {
        return;
    }
    ensure_package_order_schema();
    $max = db()->prepare(
        "SELECT COALESCE(MAX(id), 0) FROM package_orders
         WHERE business_id=? AND payment_status IN ('paid','pay_at_office','pending_review')"
    );
    $max->execute([$businessId]);
    $seen = (int) $max->fetchColumn();
    db()->prepare('UPDATE businesses SET bookings_seen_order_id=? WHERE id=? AND bookings_seen_order_id < ?')
        ->execute([$seen, $businessId, $seen]);
}

/**
 * Book a tour package at the price stored on the service, then open GCash checkout.
 *
 * @return array{ok:bool,message:string,checkout_url:?string}
 */
function package_party_notes_from_post(int $partySize): array
{
    $adults = (int) ($_POST['package_adults'] ?? -1);
    $children = (int) ($_POST['package_children'] ?? -1);
    $infants = (int) ($_POST['package_infants'] ?? 0);
    if ($infants < 0) {
        $infants = 0;
    }
    if ($adults < 0 || $children < 0 || ($adults + $children) !== $partySize) {
        return ['ok' => false, 'message' => 'Adults and children must add up to the party size. Infants and toddlers are not counted.', 'json' => null];
    }
    $special = (string) ($_POST['package_special'] ?? '') === 'yes';
    $needs = [];
    if ($special) {
        $raw = json_decode((string) ($_POST['package_party_needs'] ?? ''), true);
        if (!is_array($raw) || !$raw) {
            return ['ok' => false, 'message' => 'Choose the special requirements that apply.', 'json' => null];
        }
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $count = (int) ($item['count'] ?? 0);
            $detail = trim((string) ($item['detail'] ?? ''));
            if ($label === '' || $count < 1 || $count > $partySize) {
                return ['ok' => false, 'message' => 'Enter how many guests need each requirement you checked.', 'json' => null];
            }
            if (!empty($item['other']) && $detail === '') {
                return ['ok' => false, 'message' => 'Describe each Other requirement you checked.', 'json' => null];
            }
            $needs[] = [
                'label' => mb_substr($label, 0, 120),
                'count' => $count,
                'detail' => mb_substr($detail, 0, 300),
            ];
        }
        if (!$needs) {
            return ['ok' => false, 'message' => 'Choose the special requirements that apply.', 'json' => null];
        }
    }
    $json = json_encode([
        'adults' => $adults,
        'children' => $children,
        'infants' => $infants,
        'special' => $special,
        'needs' => $needs,
    ], JSON_UNESCAPED_UNICODE);
    return ['ok' => true, 'message' => '', 'json' => $json === false ? null : $json];
}

function package_order_create(int $touristId, int $serviceId, string $tourDate, int $partySize = 0, ?string $partyNotes = null): array
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
        'SELECT s.id, s.name AS package_name, s.price, s.package_days, s.capacity, s.package_min_pax, s.is_active,
                s.package_date_mode, s.package_fixed_date, s.package_close_date,
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
    $unit = round((float) ($row['price'] ?? 0), 2);
    if ($unit <= 0) {
        return ['ok' => false, 'message' => 'This package does not have a price yet.', 'checkout_url' => null];
    }
    $packageDays = (int) ($row['package_days'] ?? 0);
    if ($packageDays < 1) {
        return ['ok' => false, 'message' => 'This package does not have a length yet.', 'checkout_url' => null];
    }
    $minPax = (int) ($row['package_min_pax'] ?? 0);
    $maxPax = (int) ($row['capacity'] ?? 0);
    if ($minPax < 1) {
        $minPax = 1;
    }
    if ($maxPax < 1) {
        $maxPax = $minPax;
    }
    $inclusive = (string) ($row['package_date_mode'] ?? '') === 'fixed'
        && trim((string) ($row['package_close_date'] ?? '')) !== '';
    if ($inclusive) {
        $taken = package_slots_taken_map([$serviceId])[$serviceId] ?? 0;
        $left = max(0, $maxPax - $taken);
        if ($left < 1) {
            return ['ok' => false, 'message' => 'This package has no slots left.', 'checkout_url' => null];
        }
        if ($partySize > $left) {
            return ['ok' => false, 'message' => 'Only ' . $left . ' slot' . ($left === 1 ? '' : 's') . ' left.', 'checkout_url' => null];
        }
        if ($left < $minPax) {
            $minPax = 1;
        }
    }
    if ($partySize < $minPax || $partySize > $maxPax) {
        return ['ok' => false, 'message' => 'Party size must be from ' . $minPax . ' to ' . $maxPax . '.', 'checkout_url' => null];
    }
    $amount = round($unit * $partySize, 2);
    $packagePax = $partySize;
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
             SET package_name=?, business_name=?, business_id=?, business_user_id=?, amount=?, tour_date=?, package_days=?, package_pax=?, package_party_notes=?
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
            $partyNotes,
            (int) $order['id'],
        ]);
        $order['package_name'] = $packageName;
        $order['business_name'] = $businessName;
        $order['amount'] = $amount;
    } else {
        $code = 'PK' . strtoupper(bin2hex(random_bytes(4)));
        db()->prepare(
            'INSERT INTO package_orders
                (order_code, tourist_id, business_id, business_user_id, service_id, package_name, business_name, amount, tour_date, package_days, package_pax, package_party_notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
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
            $partyNotes,
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
    package_order_place_reservations($order);
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
    package_order_place_reservations($order);
    return ['ok' => true, 'message' => 'You can pay for ' . $name . ' at the Tourism Office.'];
}

/** Reservations for places on a package are created only after a tourist books it. */
function package_order_place_reservations(array $order): void
{
    package_stops_ensure_schema();
    revenue_ensure_schema();
    $serviceId = (int) ($order['service_id'] ?? 0);
    $touristId = (int) ($order['tourist_id'] ?? 0);
    if ($serviceId < 1 || $touristId < 1) {
        return;
    }
    package_order_freeze_itinerary((int) ($order['id'] ?? 0));
    $snap = db()->prepare('SELECT snapshot_itinerary_id FROM package_orders WHERE id=? LIMIT 1');
    $snap->execute([(int) ($order['id'] ?? 0)]);
    $svc = db()->prepare('SELECT package_itinerary_id, capacity FROM business_services WHERE id=? LIMIT 1');
    $svc->execute([$serviceId]);
    $svcRow = $svc->fetch() ?: null;
    $itinId = (int) ($snap->fetchColumn() ?: 0);
    if ($itinId < 1) {
        $itinId = (int) ($svcRow['package_itinerary_id'] ?? 0);
    }
    if ($itinId < 1 || !$svcRow) {
        return;
    }
    $tourDate = normalize_tour_date($order['tour_date'] ?? null);
    if ($tourDate === null) {
        return;
    }
    $guests = (int) ($order['package_pax'] ?? 0);
    if ($guests < 1) {
        $guests = (int) ($svcRow['capacity'] ?? 1);
    }
    $guests = max(1, min(50, $guests));
    $itin = db()->prepare('SELECT start_date FROM itineraries WHERE id=? LIMIT 1');
    $itin->execute([$itinId]);
    $anchor = strtotime((string) ($itin->fetchColumn() ?: ''));
    $tourTs = strtotime($tourDate);
    $stops = db()->prepare(
        'SELECT ii.id, ii.business_id, ii.visit_date, ii.start_time, b.user_id AS business_user_id, b.name AS business_name, bt.name AS type_name
         FROM itinerary_items ii
         JOIN businesses b ON b.id = ii.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE ii.itinerary_id=? AND ii.business_id IS NOT NULL
           AND b.approval_status=\'approved\' AND b.status=\'active\''
    );
    $stops->execute([$itinId]);
    foreach ($stops->fetchAll() ?: [] as $stop) {
        if (!revenue_uses_reservation(revenue_bookable_kind((string) ($stop['type_name'] ?? '')))) {
            continue;
        }
        $date = $tourDate;
        $visitTs = strtotime((string) ($stop['visit_date'] ?? ''));
        if ($anchor && $tourTs && $visitTs && $visitTs >= $anchor) {
            $offset = (int) floor(($visitTs - $anchor) / 86400);
            $date = date('Y-m-d', strtotime('+' . $offset . ' days', $tourTs));
        }
        $time = normalize_time_input((string) ($stop['start_time'] ?? ''));
        if ($time === null) {
            $time = '12:00:00';
        }
        $exists = db()->prepare(
            "SELECT id FROM restaurant_reservations
             WHERE tourist_id=? AND itinerary_item_id=? AND reserve_date=? AND status IN ('pending','confirmed')
             LIMIT 1"
        );
        $exists->execute([$touristId, (int) $stop['id'], $date]);
        if ($exists->fetch()) {
            continue;
        }
        $code = 'RES-' . strtoupper(bin2hex(random_bytes(3)));
        db()->prepare(
            'INSERT INTO restaurant_reservations
             (code, tourist_id, business_id, itinerary_id, itinerary_item_id, reserve_date, reserve_time, guests, order_mode, status, fee_snapshot, food_total)
             VALUES (?,?,?,?,?,?,?,?,\'table_only\',\'pending\',0,0)'
        )->execute([
            $code,
            $touristId,
            (int) $stop['business_id'],
            $itinId,
            (int) $stop['id'],
            $date,
            $time,
            $guests,
        ]);
        $id = (int) db()->lastInsertId();
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
            'Your request ' . $code . ' was sent to ' . (string) ($stop['business_name'] ?? 'the place') . '.',
            APP_URL . '/tourist/packages.php'
        );
    }
}

/** Package booking tied to a place reservation, when this stop belongs to a made package. */
function package_order_for_reservation(int $itineraryId, int $touristId): ?array
{
    if ($itineraryId < 1 || $touristId < 1) {
        return null;
    }
    ensure_package_order_schema();
    $st = db()->prepare(
        'SELECT o.id, o.booking_status, o.operator_ready, o.package_name, o.tourist_id, o.business_user_id, o.service_id
         FROM package_orders o
         JOIN business_services s ON s.id = o.service_id
         WHERE o.tourist_id=? AND s.package_made=1
           AND (s.package_itinerary_id=? OR o.snapshot_itinerary_id=?)
           AND o.booking_status IN (\'pending\',\'approved\')
         ORDER BY o.id DESC
         LIMIT 1'
    );
    $st->execute([$touristId, $itineraryId, $itineraryId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function package_order_reservations(int $orderId): array
{
    ensure_package_order_schema();
    require_once __DIR__ . '/revenue.php';
    revenue_ensure_schema();
    $st = db()->prepare(
        'SELECT r.id, r.code, r.status, r.rejection_reason, r.business_id, r.itinerary_item_id,
                r.reserve_date, r.reserve_time, r.guests, b.name AS business_name, bt.name AS type_name
         FROM package_orders o
         JOIN business_services s ON s.id = o.service_id
         JOIN restaurant_reservations r ON r.tourist_id = o.tourist_id
           AND r.itinerary_id = IF(o.snapshot_itinerary_id IS NOT NULL AND o.snapshot_itinerary_id > 0, o.snapshot_itinerary_id, s.package_itinerary_id)
         JOIN businesses b ON b.id = r.business_id
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE o.id=? AND r.status NOT IN (\'cancelled\')
         ORDER BY r.id DESC'
    );
    $st->execute([$orderId]);
    $rows = [];
    $seen = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $itemId = (int) $row['itinerary_item_id'];
        if (isset($seen[$itemId])) {
            continue;
        }
        $seen[$itemId] = true;
        $rows[] = $row;
    }
    return $rows;
}

function package_order_reservations_block_approval(int $orderId): bool
{
    foreach (package_order_reservations($orderId) as $row) {
        if (in_array((string) $row['status'], ['pending', 'rejected'], true)) {
            return true;
        }
    }
    return false;
}

/** The place confirming is what approves a finalized package. */
/** @return array{id:int,items:array<int,int>} */
function itinerary_duplicate(int $srcId): array
{
    ensure_itinerary_items_schema();
    $itinSt = db()->prepare('SELECT * FROM itineraries WHERE id=? LIMIT 1');
    $itinSt->execute([$srcId]);
    $itin = $itinSt->fetch();
    if (!$itin) {
        return ['id' => 0, 'items' => []];
    }
    unset($itin['id']);
    $itinCols = array_keys($itin);
    db()->prepare(
        'INSERT INTO itineraries (' . implode(',', $itinCols) . ') VALUES (' . implode(',', array_fill(0, count($itinCols), '?')) . ')'
    )->execute(array_values($itin));
    $newId = (int) db()->lastInsertId();
    $itemCols = db()->query('SHOW COLUMNS FROM itinerary_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $copyCols = array_values(array_filter($itemCols, static fn ($col) => $col !== 'id'));
    $items = db()->prepare('SELECT * FROM itinerary_items WHERE itinerary_id=? ORDER BY sort_order, id');
    $items->execute([$srcId]);
    $ins = db()->prepare(
        'INSERT INTO itinerary_items (' . implode(',', $copyCols) . ') VALUES (' . implode(',', array_fill(0, count($copyCols), '?')) . ')'
    );
    $map = [];
    foreach ($items->fetchAll() ?: [] as $item) {
        $oldItemId = (int) $item['id'];
        $item['itinerary_id'] = $newId;
        $vals = [];
        foreach ($copyCols as $col) {
            $vals[] = $item[$col] ?? null;
        }
        $ins->execute($vals);
        $map[$oldItemId] = (int) db()->lastInsertId();
    }
    try {
        $meet = db()->prepare('SELECT * FROM itinerary_day_meetups WHERE itinerary_id=?');
        $meet->execute([$srcId]);
        $meetRows = $meet->fetchAll() ?: [];
        if ($meetRows) {
            $meetCols = array_values(array_filter(array_keys($meetRows[0]), static fn ($col) => $col !== 'id'));
            $meetIns = db()->prepare(
                'INSERT INTO itinerary_day_meetups (' . implode(',', $meetCols) . ') VALUES (' . implode(',', array_fill(0, count($meetCols), '?')) . ')'
            );
            foreach ($meetRows as $row) {
                $row['itinerary_id'] = $newId;
                $vals = [];
                foreach ($meetCols as $col) {
                    $vals[] = $row[$col] ?? null;
                }
                $meetIns->execute($vals);
            }
        }
    } catch (Throwable $e) {
        // Day meetup rows are optional.
    }
    return ['id' => $newId, 'items' => $map];
}

/** Copy the agreed itinerary onto the booking. Later package edits do not replace it. */
function package_order_freeze_itinerary(int $orderId): void
{
    ensure_package_order_schema();
    $st = db()->prepare(
        'SELECT o.id, o.snapshot_itinerary_id, o.tourist_id, o.snapshot_inclusions, o.snapshot_description,
                s.package_itinerary_id, s.package_inclusions, s.description
         FROM package_orders o
         JOIN business_services s ON s.id = o.service_id
         WHERE o.id=? LIMIT 1'
    );
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) {
        return;
    }
    if ((int) ($order['snapshot_itinerary_id'] ?? 0) > 0) {
        return;
    }
    $srcId = (int) ($order['package_itinerary_id'] ?? 0);
    if ($srcId < 1) {
        return;
    }
    $copy = itinerary_duplicate($srcId);
    $newId = (int) $copy['id'];
    if ($newId < 1) {
        return;
    }
    $map = $copy['items'];
    if ($map && (int) $order['tourist_id'] > 0) {
        $move = db()->prepare('UPDATE restaurant_reservations SET itinerary_id=?, itinerary_item_id=? WHERE id=? AND tourist_id=? AND itinerary_id=?');
        $res = db()->prepare('SELECT id, itinerary_item_id FROM restaurant_reservations WHERE tourist_id=? AND itinerary_id=?');
        $res->execute([(int) $order['tourist_id'], $srcId]);
        foreach ($res->fetchAll() ?: [] as $row) {
            $newItem = $map[(int) $row['itinerary_item_id']] ?? (int) $row['itinerary_item_id'];
            $move->execute([$newId, $newItem, (int) $row['id'], (int) $order['tourist_id'], $srcId]);
        }
    }
    db()->prepare(
        'UPDATE package_orders
         SET snapshot_itinerary_id=?, snapshot_inclusions=COALESCE(snapshot_inclusions, ?), snapshot_description=COALESCE(snapshot_description, ?)
         WHERE id=?'
    )->execute([
        $newId,
        (string) ($order['package_inclusions'] ?? ''),
        (string) ($order['description'] ?? ''),
        $orderId,
    ]);
}

function package_order_approve_if_clear(int $orderId): void
{
    ensure_package_order_schema();
    $st = db()->prepare('SELECT id, booking_status, operator_ready, tourist_id, package_name FROM package_orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order || (int) $order['operator_ready'] !== 1 || (string) $order['booking_status'] !== 'pending') {
        return;
    }
    if (package_order_reservations_block_approval($orderId)) {
        return;
    }
    db()->prepare("UPDATE package_orders SET booking_status='approved' WHERE id=? AND booking_status='pending'")->execute([$orderId]);
    if ((int) $order['tourist_id'] > 0) {
        notify_user(
            (int) $order['tourist_id'],
            'package_booking',
            'Package booking confirmed',
            (string) $order['package_name'] . ' is confirmed. The places on the tour accepted the reservation.',
            APP_URL . '/tourist/bookings.php'
        );
    }
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
