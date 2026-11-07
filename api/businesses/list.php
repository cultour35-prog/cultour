<?php
require_once __DIR__ . '/../bootstrap.php';
ensure_place_schedule_schema();
$rows = db()->query(
    "SELECT b.id, b.name, b.slug, b.description, b.address, b.phone, b.email, b.opening_hours,
            b.cover_image, b.latitude, b.longitude, bt.name AS type_name, " . business_itinerary_ready_columns() . "
     FROM businesses b JOIN business_types bt ON bt.id=b.business_type_id
     WHERE b.approval_status='approved' AND b.status='active' ORDER BY b.name"
)->fetchAll() ?: [];
$offers = [];
$svcRows = db()->query(
    'SELECT id, business_id, name, description, price, capacity, unit_count, amenities, image_path,
            extra_guest_fee, extra_guest_limit
     FROM business_services WHERE is_active=1 ORDER BY price, name'
)->fetchAll() ?: [];
foreach ($svcRows as $svc) {
    $image = trim((string) ($svc['image_path'] ?? ''));
    $from = normalize_tour_date($_GET['from'] ?? null);
    $to = normalize_tour_date($_GET['to'] ?? null) ?: $from;
    $available = null;
    if ($from !== null && $svc['unit_count'] !== null && $svc['unit_count'] !== '') {
        $available = offering_units_free((int) $svc['id'], $from, $to);
    }
    $offers[(int) $svc['business_id']][] = [
        'id' => (int) $svc['id'],
        'name' => (string) $svc['name'],
        'description' => (string) ($svc['description'] ?? ''),
        'price_label' => $svc['price'] !== null ? format_money((float) $svc['price']) : '',
        'price' => $svc['price'] !== null ? (float) $svc['price'] : 0,
        'extra_guest_fee' => isset($svc['extra_guest_fee']) && $svc['extra_guest_fee'] !== null && $svc['extra_guest_fee'] !== ''
            ? (float) $svc['extra_guest_fee'] : 0,
        'extra_guest_limit' => isset($svc['extra_guest_limit']) && $svc['extra_guest_limit'] !== null && $svc['extra_guest_limit'] !== ''
            ? (int) $svc['extra_guest_limit'] : null,
        'capacity' => $svc['capacity'] !== null ? (int) $svc['capacity'] : null,
        'unit_count' => $svc['unit_count'] !== null && $svc['unit_count'] !== '' ? (int) $svc['unit_count'] : null,
        'available' => $available,
        'amenities' => (string) ($svc['amenities'] ?? ''),
        'image_url' => $image !== '' ? api_upload_url($image) : null,
    ];
}
$out = [];
foreach ($rows as $row) {
    if (!business_itinerary_ready($row)) {
        continue;
    }
    $item = api_decorate_place($row);
    $item['offerings'] = $offers[(int) $row['id']] ?? [];
    $out[] = $item;
}
json_response(['ok' => true, 'data' => $out]);
