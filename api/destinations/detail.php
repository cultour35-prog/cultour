<?php
require_once __DIR__ . '/../bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT d.*, c.name AS category_name FROM destinations d JOIN categories c ON c.id=d.category_id WHERE d.id=? AND d.status='published'");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['ok' => false, 'message' => 'Not found'], 404);
$row = api_decorate_place($row);
$reviews = db()->prepare("SELECT r.rating, r.comment, r.created_at, u.first_name, u.last_name FROM destination_reviews r JOIN users u ON u.id=r.user_id WHERE r.destination_id=? ORDER BY r.created_at DESC");
$reviews->execute([$id]);
$services = [];
try {
    $svc = db()->prepare(
        'SELECT name, description, price, duration_minutes FROM destination_services
         WHERE destination_id=? AND is_active=1 ORDER BY name'
    );
    $svc->execute([$id]);
    $services = $svc->fetchAll() ?: [];
} catch (Throwable $e) {
    $services = [];
}
$favorited = false;
$user = api_bearer_user();
if ($user) {
    $fav = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND destination_id=?');
    $fav->execute([(int) $user['id'], $id]);
    $favorited = (bool) $fav->fetch();
}
json_response([
    'ok' => true,
    'data' => $row,
    'reviews' => $reviews->fetchAll() ?: [],
    'services' => $services,
    'favorited' => $favorited,
]);
