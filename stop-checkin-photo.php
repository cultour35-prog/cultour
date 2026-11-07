<?php
/**
 * Authenticated download of a tour-guide stop check-in photo.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/api/bootstrap.php';

ensure_booking_stop_checkin_schema();

$user = api_resolve_user();
if (!$user) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sign in to view this check-in photo.';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$checkin = booking_stop_checkin_by_id($id);
if (!$checkin) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Check-in photo not found.';
    exit;
}

$st = db()->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');
$st->execute([(int) $checkin['booking_id']]);
$booking = $st->fetch();
if (!$booking || !booking_user_can_view_stop_checkin($user, $booking)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'You cannot view this check-in photo.';
    exit;
}

$rel = trim(str_replace('\\', '/', (string) ($checkin['photo_path'] ?? '')));
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Check-in photo not found.';
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
    echo 'Check-in photo not found.';
    exit;
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="stop-checkin-' . $id . '.' . ($ext !== '' ? $ext : 'bin') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($realFile);
exit;
