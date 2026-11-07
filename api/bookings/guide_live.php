<?php
/**
 * Live guide bookings board (session auth) — poll without full page refresh.
 */
require_once __DIR__ . '/../../includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_TOUR_GUIDE) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$profile = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$gid = (int) $profile->fetchColumn();
if ($gid < 1) {
    json_response(['ok' => false, 'message' => 'Guide profile not found'], 404);
}

$since = trim((string) ($_GET['since'] ?? ''));
$payload = guide_bookings_live_payload($gid);

// Cheap poll: if client already has this version, skip re-sending HTML
if ($since !== '' && hash_equals($payload['version'], $since)) {
    json_response([
        'ok' => true,
        'changed' => false,
        'version' => $payload['version'],
        'pending' => $payload['pending'],
        'newest_id' => $payload['newest_id'],
    ]);
}

json_response([
    'ok' => true,
    'changed' => true,
    'version' => $payload['version'],
    'pending' => $payload['pending'],
    'newest_id' => $payload['newest_id'],
    'html' => $payload['html'],
]);
