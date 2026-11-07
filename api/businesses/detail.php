<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/business_engagement.php';
ensure_business_listing_schema();
ensure_place_schedule_schema();
ensure_business_reviews_schema();
ensure_business_favorites_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT b.*, bt.name AS type_name,
            (SELECT COUNT(*) FROM business_photos bp WHERE bp.business_id = b.id) AS photo_count
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.id=? AND b.approval_status='approved' AND b.status='active'"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'message' => 'Not found'], 404);
}
$listingBlock = business_listing_unavailable_message($row);
if ($listingBlock !== null) {
    json_response(['ok' => false, 'message' => $listingBlock], 404);
}
$data = api_decorate_place($row);
$data['logo_url'] = api_upload_url($row['logo'] ?? null);

$photos = db()->prepare(
    'SELECT id, image_path, caption FROM business_photos
     WHERE business_id = ? AND COALESCE(is_hidden,0)=0 ORDER BY id DESC'
);
$photos->execute([$id]);
$data['photos'] = array_map(static fn (array $p): array => [
    'id' => (int) $p['id'],
    'url' => api_upload_url($p['image_path']),
    'caption' => (string) ($p['caption'] ?? ''),
], $photos->fetchAll() ?: []);

$services = db()->prepare(
    'SELECT id, name, description, price, duration_minutes, capacity, amenities, image_path FROM business_services WHERE business_id = ? AND is_active = 1 ORDER BY name'
);
$services->execute([$id]);
$data['services'] = array_map(static function (array $s): array {
    $image = trim((string) ($s['image_path'] ?? ''));
    return [
        'id' => (int) ($s['id'] ?? 0),
        'name' => (string) ($s['name'] ?? ''),
        'description' => (string) ($s['description'] ?? ''),
        'price' => $s['price'],
        'price_label' => $s['price'] !== null ? format_money((float) $s['price']) : '',
        'duration_minutes' => $s['duration_minutes'] !== null ? (int) $s['duration_minutes'] : null,
        'capacity' => $s['capacity'] !== null ? (int) $s['capacity'] : null,
        'amenities' => (string) ($s['amenities'] ?? ''),
        'image_url' => $image !== '' ? api_upload_url($image) : null,
    ];
}, $services->fetchAll() ?: []);

$reviews = db()->prepare(
    "SELECT r.rating, r.comment, r.created_at, r.user_id, u.first_name, u.last_name
     FROM business_reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.business_id=? AND r.status='active'
     ORDER BY r.created_at DESC"
);
$reviews->execute([$id]);
$data['reviews'] = $reviews->fetchAll() ?: [];

$hours = parse_opening_hours_value($row['opening_hours'] ?? null) ?: [];
$labels = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$data['hours'] = [];
foreach ($labels as $key => $label) {
    $data['hours'][] = ['day' => $label, 'label' => trim((string) ($hours[$key] ?? '')) ?: 'Closed'];
}

$user = api_bearer_user();
$data['favorited'] = false;
$data['my_review'] = null;
if ($user && (int) $user['role_id'] === ROLE_TOURIST) {
    $fav = db()->prepare('SELECT 1 FROM business_favorites WHERE user_id=? AND business_id=?');
    $fav->execute([(int) $user['id'], $id]);
    $data['favorited'] = (bool) $fav->fetchColumn();
    foreach ($data['reviews'] as $r) {
        if ((int) $r['user_id'] === (int) $user['id']) {
            $data['my_review'] = ['rating' => (int) $r['rating'], 'comment' => (string) ($r['comment'] ?? '')];
        }
    }
}
json_response(['ok' => true, 'data' => $data]);
