<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
ensure_itinerary_items_schema();
$stmt = db()->prepare(
    'SELECT i.* FROM itineraries i
     WHERE (i.user_id=? AND i.is_suggested=0 AND ' . itinerary_sql_is_saved_tour('i') . ')
        OR (' . itinerary_sql_published_suggested('i') . ')
     ORDER BY i.created_at DESC'
);
$stmt->execute([$user['id']]);
$items = $stmt->fetchAll() ?: [];
$ownIds = [];
foreach ($items as $it) {
    if ((int) ($it['is_suggested'] ?? 0) === 0) {
        $ownIds[] = (int) $it['id'];
    }
}
$bookedIds = itinerary_ids_with_active_booking($ownIds);
$completedIds = itinerary_ids_with_completed_booking($ownIds);
foreach ($items as &$it) {
    $id = (int) $it['id'];
    $status = (string) ($it['status'] ?? '');
    $finished = !empty($completedIds[$id]) || $status === 'completed' || $status === 'archived';
    $booked = !empty($bookedIds[$id]);
    $it['stops'] = itinerary_items_with_places($id);
    $it['day_count'] = itinerary_logical_day_count($it);
    $it['locked'] = (int) ($it['is_suggested'] ?? 0) === 0 && itinerary_schedule_is_locked($id);
    $it['is_draft'] = (int) ($it['is_suggested'] ?? 0) === 0
        && (int) ($it['kept_as_draft'] ?? 0) === 1
        && !$booked
        && !$finished;
}
unset($it);
json_response(['ok' => true, 'data' => $items]);
