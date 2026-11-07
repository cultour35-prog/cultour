<?php
/**
 * Authenticated download of a booking fitness-to-travel certificate.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/api/bootstrap.php';

ensure_booking_accessibility_schema();

$id = (int) ($_GET['id'] ?? 0);
$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$token = trim((string) ($_GET['token'] ?? ''));
$viaTicket = ticket_scan_token_ok($id, $code, $token);

if ($id < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing booking.';
    exit;
}

$st = db()->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');
$st->execute([$id]);
$booking = $st->fetch();
$ticketMatch = $booking && strcasecmp((string) $booking['booking_code'], $code) === 0;

if ($viaTicket) {
    if (!$ticketMatch) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You cannot view this certificate.';
        exit;
    }
} else {
    $user = api_resolve_user();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Sign in to view this certificate.';
        exit;
    }
    if (!$booking || !booking_user_can_view_fitness_certificate($user, $booking)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You cannot view this certificate.';
        exit;
    }
}

$rel = trim(str_replace('\\', '/', (string) ($booking['fitness_certificate_path'] ?? '')));
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Certificate not found.';
    exit;
}

$abs = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$realUpload = realpath((string) UPLOAD_PATH);
$realFile = is_file($abs) ? realpath($abs) : false;
$uploadPrefix = $realUpload !== false ? strtolower(str_replace('\\', '/', rtrim($realUpload, '/\\'))) . '/' : '';
$fileNorm = $realFile !== false ? strtolower(str_replace('\\', '/', $realFile)) : '';
if ($uploadPrefix === '' || $fileNorm === '' || !str_starts_with($fileNorm, $uploadPrefix)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Certificate not found.';
    exit;
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="fitness-certificate-' . $id . '.' . ($ext !== '' ? $ext : 'bin') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($realFile);
exit;
