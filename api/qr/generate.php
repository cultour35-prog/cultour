<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/qr.php';

$user = require_api_user();
$id = (int)($_GET['booking_id'] ?? 0);
$stmt = db()->prepare(
    'SELECT b.*, gp.user_id AS guide_user_id
     FROM bookings b
     JOIN tour_guide_profiles gp ON gp.id = b.guide_id
     WHERE b.id=?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'message' => 'Not found'], 404);
}

$role = (int) $user['role_id'];
$allowed = $role === ROLE_ADMIN
    || (int) $user['id'] === (int) $row['tourist_id']
    || (int) $user['id'] === (int) $row['guide_user_id'];
if (!$allowed) {
    json_response(['ok' => false, 'message' => 'Access denied'], 403);
}

if (!in_array($row['status'] ?? '', ['approved', 'completed'], true)) {
    json_response(['ok' => false, 'message' => 'QR is available only for approved bookings', 'status' => $row['status']], 400);
}

if (empty($row['qr_code_path'])) {
    $tourist = db()->prepare('SELECT * FROM users WHERE id=?');
    $tourist->execute([(int) $row['tourist_id']]);
    $tourist = $tourist->fetch();
    $guide = db()->prepare('SELECT * FROM users WHERE id=?');
    $guide->execute([(int) $row['guide_user_id']]);
    $guide = $guide->fetch();
    booking_qr_for($row, $tourist ?: null, $guide ?: null);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
}

json_response([
    'ok' => true,
    'data' => [
        'booking_code' => $row['booking_code'],
        'status' => $row['status'],
        'booking_date' => $row['booking_date'],
        'start_time' => $row['start_time'],
        'qr_code_path' => $row['qr_code_path'],
        'qr_url' => $row['qr_code_path'] ? (APP_URL . '/uploads/' . $row['qr_code_path']) : null,
    ],
]);
