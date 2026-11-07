<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/business_engagement.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
ensure_business_favorites_schema();
$stmt = db()->prepare(
    "SELECT d.id, d.name, d.location, d.cover_image, d.avg_rating, c.name AS category_name
     FROM favorites f
     JOIN destinations d ON d.id = f.destination_id
     JOIN categories c ON c.id = d.category_id
     WHERE f.user_id=? AND d.status='published'
     ORDER BY d.name"
);
$stmt->execute([$user['id']]);
$biz = db()->prepare(
    "SELECT b.id, b.name, b.address, b.cover_image, bt.name AS type_name, " . business_itinerary_ready_columns() . "
     FROM business_favorites f
     JOIN businesses b ON b.id = f.business_id
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE f.user_id=? AND b.approval_status='approved' AND b.status='active'
     ORDER BY b.name"
);
$biz->execute([$user['id']]);
json_response([
    'ok' => true,
    'data' => array_map('api_decorate_place', $stmt->fetchAll() ?: []),
    'businesses' => array_map('api_decorate_place', array_values(array_filter($biz->fetchAll() ?: [], 'business_itinerary_ready'))),
]);
