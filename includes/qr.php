<?php
/**
 * QR code generation — local phpqrcode first, online API fallback.
 */

function generate_qr_payload(string $entityType, int $entityId, array $payload): string
{
    $dir = UPLOAD_PATH . '/qr';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $filename = $entityType . '_' . $entityId . '_' . time() . '.png';
    $relative = 'qr/' . $filename;
    $path = $dir . '/' . $filename;

    // Always keep payload JSON for verify / offline recovery
    $txt = $dir . '/' . $entityType . '_' . $entityId . '.json';
    file_put_contents($txt, $json);

    $wrote = false;

    // 1) Local offline generator (phpqrcode)
    $lib = __DIR__ . '/lib/phpqrcode.php';
    if (is_file($lib) && function_exists('imagepng')) {
        try {
            require_once $lib;
            if (class_exists('QRcode', false)) {
                // Suppress phpqrcode cache notices on some hosts
                if (!defined('QR_CACHEABLE')) {
                    // library defines its own constants when included
                }
                QRcode::png($json, $path, QR_ECLEVEL_M, 6, 2);
                if (is_file($path) && filesize($path) > 100) {
                    $wrote = true;
                }
            }
        } catch (Throwable $e) {
            $wrote = false;
        }
    }

    // 2) Online fallback
    if (!$wrote) {
        $data = rawurlencode($json);
        $chartUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . $data;
        $imageData = false;
        if (ini_get('allow_url_fopen')) {
            $imageData = @file_get_contents($chartUrl);
        }
        if ($imageData === false && function_exists('curl_init')) {
            $ch = curl_init($chartUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
            $imageData = curl_exec($ch);
            curl_close($ch);
        }
        if ($imageData) {
            file_put_contents($path, $imageData);
            $wrote = true;
        }
    }

    // 3) Last resort placeholder
    if (!$wrote) {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W2fQAAAAASUVORK5CYII=');
        file_put_contents($path, $png);
    }

    db()->prepare(
        'INSERT INTO qr_codes (entity_type, entity_id, payload_json, qr_image_path) VALUES (?,?,?,?)'
    )->execute([$entityType, $entityId, $json, $relative]);

    return $relative;
}

function ticket_scan_token(int $bookingId, string $code): string
{
    $secret = defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : 'cultour';
    return hash_hmac('sha256', 'ticket|' . $bookingId . '|' . $code, $secret);
}

function ticket_scan_token_ok(int $bookingId, string $code, string $token): bool
{
    if ($bookingId < 1 || $code === '' || $token === '') {
        return false;
    }
    return hash_equals(ticket_scan_token($bookingId, $code), $token);
}

/**
 * Read a scanned ticket QR (URL) or a typed booking code.
 * @return array{id:int,code:string,token:string}
 */
function booking_ticket_scan_parse(string $raw): array
{
    $raw = trim($raw);
    $id = 0;
    $code = '';
    $token = '';
    if ($raw === '') {
        return ['id' => 0, 'code' => '', 'token' => ''];
    }
    $query = '';
    if (preg_match('~ticket-scan\\.php\\?([^#\\s]+)~i', $raw, $m)) {
        $query = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (filter_var($raw, FILTER_VALIDATE_URL)) {
        $query = (string) (parse_url($raw, PHP_URL_QUERY) ?? '');
    }
    if ($query !== '') {
        $q = [];
        parse_str($query, $q);
        $id = (int) ($q['id'] ?? 0);
        $code = strtoupper(trim((string) ($q['code'] ?? '')));
        $token = trim((string) ($q['token'] ?? ''));
    }
    if ($code === '' && preg_match('/\b(CT[A-Z0-9]{6,})\b/i', $raw, $m)) {
        $code = strtoupper($m[1]);
    }
    return ['id' => $id, 'code' => $code, 'token' => $token];
}

/**
 * Meetup check-in: the scanned ticket must belong to this booking.
 * @return array{ok:bool,message:string}
 */
function booking_meetup_ticket_ok(array $booking, string $raw): array
{
    $wantId = (int) ($booking['id'] ?? 0);
    $wantCode = strtoupper(trim((string) ($booking['booking_code'] ?? '')));
    $parsed = booking_ticket_scan_parse($raw);
    if ($parsed['id'] < 1 && $parsed['code'] === '') {
        return ['ok' => false, 'message' => 'Scan the tourist\'s ticket QR for this tour.'];
    }
    if ($parsed['token'] !== '' && $parsed['id'] > 0 && $parsed['code'] !== '') {
        if (!ticket_scan_token_ok($parsed['id'], $parsed['code'], $parsed['token'])) {
            return ['ok' => false, 'message' => 'This ticket is not valid.'];
        }
    }
    $idMatch = $parsed['id'] < 1 || $parsed['id'] === $wantId;
    $codeMatch = $parsed['code'] === '' || $parsed['code'] === $wantCode;
    $hasMatch = ($parsed['id'] === $wantId && $wantId > 0) || ($parsed['code'] === $wantCode && $wantCode !== '');
    if ($idMatch && $codeMatch && $hasMatch) {
        return ['ok' => true, 'message' => 'Ticket matched ' . $wantCode . '.'];
    }
    return ['ok' => false, 'message' => 'This ticket is not for this tour.'];
}

/** Address a phone on the same Wi-Fi can open. localhost on the QR only works on this computer. */
function ticket_scan_url(int $bookingId, string $code): string
{
    if (!function_exists('payment_phone_base_url')) {
        require_once __DIR__ . '/payments.php';
    }
    return payment_phone_base_url()
        . '/ticket-scan.php?id=' . $bookingId
        . '&code=' . rawurlencode($code)
        . '&token=' . rawurlencode(ticket_scan_token($bookingId, $code));
}

function ticket_scan_cert_url(int $bookingId, string $code): string
{
    if (!function_exists('payment_phone_base_url')) {
        require_once __DIR__ . '/payments.php';
    }
    return payment_phone_base_url()
        . '/booking-fitness-cert.php?id=' . $bookingId
        . '&code=' . rawurlencode($code)
        . '&token=' . rawurlencode(ticket_scan_token($bookingId, $code));
}

/**
 * QR encodes a phone link to the ticket page (same idea as the payment QR).
 * Extra arguments are kept so existing callers do not need to change.
 */
function booking_qr_for(array $booking, ?array $tourist = null, ?array $guide = null, ?array $destination = null, ?array $itinerary = null): string
{
    unset($tourist, $guide, $destination, $itinerary);
    $id = (int) $booking['id'];
    $code = (string) ($booking['booking_code'] ?? '');
    $url = ticket_scan_url($id, $code);

    $dir = UPLOAD_PATH . '/qr';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $relative = 'qr/booking_' . $id . '.png';
    $path = $dir . '/booking_' . $id . '.png';
    $wrote = false;

    $lib = __DIR__ . '/lib/phpqrcode.php';
    if (is_file($lib) && function_exists('imagepng')) {
        try {
            require_once $lib;
            if (class_exists('QRcode', false)) {
                QRcode::png($url, $path, QR_ECLEVEL_M, 6, 2);
                $wrote = is_file($path) && filesize($path) > 100;
            }
        } catch (Throwable $e) {
            $wrote = false;
        }
    }

    if (!$wrote) {
        $chartUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . rawurlencode($url);
        $imageData = false;
        if (ini_get('allow_url_fopen')) {
            $imageData = @file_get_contents($chartUrl);
        }
        if ($imageData === false && function_exists('curl_init')) {
            $ch = curl_init($chartUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
            $imageData = curl_exec($ch);
            curl_close($ch);
        }
        if ($imageData) {
            file_put_contents($path, $imageData);
            $wrote = true;
        }
    }

    db()->prepare('UPDATE bookings SET qr_code_path=? WHERE id=?')->execute([$relative, $id]);
    return $relative;
}
