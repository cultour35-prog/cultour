<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
$in = api_input();
$destId = (int) ($in['destination_id'] ?? 0);
$bizId = (int) ($in['business_id'] ?? 0);
if ($bizId > 0) {
    require_once __DIR__ . '/../../includes/business_engagement.php';
    ensure_business_favorites_schema();
    $exists = db()->prepare(
        "SELECT id FROM businesses WHERE id=? AND approval_status='approved' AND status='active'"
    );
    $exists->execute([$bizId]);
    if (!$exists->fetch()) {
        json_response(['ok' => false, 'message' => 'Business not found'], 404);
    }
    $fav = db()->prepare('SELECT id FROM business_favorites WHERE user_id=? AND business_id=?');
    $fav->execute([(int) $user['id'], $bizId]);
    if ($fav->fetch()) {
        db()->prepare('DELETE FROM business_favorites WHERE user_id=? AND business_id=?')
            ->execute([(int) $user['id'], $bizId]);
        json_response(['ok' => true, 'favorited' => false]);
    }
    db()->prepare('INSERT INTO business_favorites (user_id, business_id) VALUES (?,?)')
        ->execute([(int) $user['id'], $bizId]);
    json_response(['ok' => true, 'favorited' => true]);
}
if ($destId < 1) {
    json_response(['ok' => false, 'message' => 'destination_id required'], 400);
}
$exists = db()->prepare("SELECT id FROM destinations WHERE id=? AND status='published'");
$exists->execute([$destId]);
if (!$exists->fetch()) {
    json_response(['ok' => false, 'message' => 'Destination not found'], 404);
}
$fav = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND destination_id=?');
$fav->execute([$user['id'], $destId]);
if ($fav->fetch()) {
    db()->prepare('DELETE FROM favorites WHERE user_id=? AND destination_id=?')->execute([$user['id'], $destId]);
    json_response(['ok' => true, 'favorited' => false]);
}
db()->prepare('INSERT INTO favorites (user_id, destination_id) VALUES (?,?)')->execute([$user['id'], $destId]);
json_response(['ok' => true, 'favorited' => true]);
