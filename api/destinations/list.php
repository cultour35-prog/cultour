<?php
require_once __DIR__ . '/../bootstrap.php';
$q = trim($_GET['q'] ?? '');
$cat = (int)($_GET['category_id'] ?? 0);
$sql = "SELECT d.id, d.name, d.slug, d.location, d.description, d.entrance_fee, d.avg_rating, d.review_count,
               d.cover_image, d.is_featured, d.latitude, d.longitude, d.opening_hours, d.category_id,
               c.name AS category_name
        FROM destinations d JOIN categories c ON c.id=d.category_id WHERE d.status='published'";
$params = [];
if ($q !== '') {
    $sql .= ' AND (d.name LIKE ? OR d.location LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like];
}
if ($cat) { $sql .= ' AND d.category_id=?'; $params[] = $cat; }
$sql .= ' ORDER BY d.is_featured DESC, d.name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = array_map('api_decorate_place', $stmt->fetchAll() ?: []);
json_response(['ok' => true, 'data' => $rows]);
