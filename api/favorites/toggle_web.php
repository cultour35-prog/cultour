<?php
/**
 * Toggle a favorite from the browser (session auth).
 * POST: destination_id | business_id, csrf_token
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/business_engagement.php';

header('Cache-Control: no-store');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    json_response(['ok' => false, 'message' => csrf_error_message()], 400);
}

$userId = (int) $user['id'];
$destId = (int) ($_POST['destination_id'] ?? 0);
$bizId = (int) ($_POST['business_id'] ?? 0);

if ($destId > 0) {
    $exists = db()->prepare("SELECT id FROM destinations WHERE id=? AND status='published'");
    $exists->execute([$destId]);
    if (!$exists->fetch()) {
        json_response(['ok' => false, 'message' => 'Destination not found'], 404);
    }
    $fav = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND destination_id=?');
    $fav->execute([$userId, $destId]);
    if ($fav->fetch()) {
        db()->prepare('DELETE FROM favorites WHERE user_id=? AND destination_id=?')
            ->execute([$userId, $destId]);
        json_response(['ok' => true, 'favorited' => false]);
    }
    db()->prepare('INSERT INTO favorites (user_id, destination_id) VALUES (?,?)')
        ->execute([$userId, $destId]);
    json_response(['ok' => true, 'favorited' => true]);
}

if ($bizId > 0) {
    ensure_business_favorites_schema();
    $exists = db()->prepare(
        "SELECT id FROM businesses WHERE id=? AND approval_status='approved' AND status='active'"
    );
    $exists->execute([$bizId]);
    if (!$exists->fetch()) {
        json_response(['ok' => false, 'message' => 'Business not found'], 404);
    }
    $fav = db()->prepare('SELECT id FROM business_favorites WHERE user_id=? AND business_id=?');
    $fav->execute([$userId, $bizId]);
    if ($fav->fetch()) {
        db()->prepare('DELETE FROM business_favorites WHERE user_id=? AND business_id=?')
            ->execute([$userId, $bizId]);
        json_response(['ok' => true, 'favorited' => false]);
    }
    db()->prepare('INSERT INTO business_favorites (user_id, business_id) VALUES (?,?)')
        ->execute([$userId, $bizId]);
    json_response(['ok' => true, 'favorited' => true]);
}

json_response(['ok' => false, 'message' => 'destination_id or business_id required'], 400);
