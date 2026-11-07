<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
ensure_itinerary_items_schema();
$id = (int) (api_input()['id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    json_response(['ok' => false, 'message' => 'id required'], 400);
}
$stmt = db()->prepare('SELECT * FROM itineraries WHERE id=?');
$stmt->execute([$id]);
$itin = $stmt->fetch();
if (!$itin) {
    json_response(['ok' => false, 'message' => 'Not found'], 404);
}
$role = (int) $user['role_id'];
$allowed = $role === ROLE_ADMIN
    || (int) $itin['user_id'] === (int) $user['id']
    || itinerary_is_published_suggested($itin);
if (!$allowed && $role === ROLE_TOUR_GUIDE) {
    $g = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $g->execute([$user['id']]);
    $gid = (int) $g->fetchColumn();
    $allowed = $gid
        && (int) ($itin['created_by_guide_id'] ?? 0) === $gid
        && (int) ($itin['is_suggested'] ?? 0) === 1;
}
if (!$allowed) {
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}
json_response(['ok' => true, 'data' => mobile_itinerary_payload($id)]);
