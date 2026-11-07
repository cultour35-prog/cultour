<?php
/**
 * Booking detail for the app (Bearer): ticket QR, people, accessibility, itinerary days and stops.
 * GET ?id=
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/qr.php';
$user = require_api_user();
ensure_booking_payment_schema();
ensure_itinerary_items_schema();

$id = (int) ($_GET['id'] ?? 0);
$sql = "SELECT b.*,
            CONCAT(t.first_name,' ',t.last_name) AS tourist_name, t.email AS tourist_email, t.phone AS tourist_phone, t.avatar AS tourist_avatar,
            CONCAT(gu.first_name,' ',gu.last_name) AS guide_name, gu.phone AS guide_phone, gu.id AS guide_user_id, gu.avatar AS guide_avatar,
            i.title AS itinerary_title, i.description AS itinerary_description
     FROM bookings b
     JOIN users t ON t.id = b.tourist_id
     JOIN tour_guide_profiles gp ON gp.id = b.guide_id
     JOIN users gu ON gu.id = gp.user_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     WHERE b.id = ?";
$stmt = db()->prepare($sql);
$stmt->execute([$id]);
$b = $stmt->fetch();
if (!$b || !booking_user_can_access_ticket($user, $b)) {
    json_response(['ok' => false, 'message' => 'Booking not found.'], 404);
}

$ticketReady = in_array((string) $b['status'], ['approved', 'completed'], true);
if ($ticketReady) {
    booking_qr_for($b);
    $stmt->execute([$id]);
    $b = $stmt->fetch();
}

[$start, $end] = booking_effective_tour_span($b);
$itinId = (int) ($b['itinerary_id'] ?? 0);
$days = [];
if ($itinId > 0) {
    $itinRow = itinerary_row_by_id($itinId);
    $groups = itinerary_group_stops_by_day(itinerary_items_with_places($itinId), $start, $end, $itinId, $itinRow ?: null);
    foreach ($groups as $g) {
        $n = (int) ($g['day'] ?? 1);
        $place = itinerary_day_meetup_place($itinId, $n, $itinRow ?: null);
        $stops = [];
        foreach ($g['stops'] ?? [] as $s) {
            $stops[] = [
                'id' => (int) ($s['id'] ?? 0),
                'name' => (string) ($s['stop_name'] ?? $s['destination_name'] ?? $s['business_name'] ?? 'Stop'),
                'location' => (string) ($s['stop_location'] ?? $s['location'] ?? ''),
                'start' => format_time_12h($s['start_time'] ?? null),
                'end' => format_time_12h($s['end_time'] ?? null),
                'visit_minutes' => (int) ($s['visit_minutes'] ?? 0),
                'notes' => (string) ($s['notes'] ?? ''),
                'cover_url' => api_upload_url($s['stop_cover'] ?? $s['cover_image'] ?? null),
                'lat' => isset($s['stop_lat']) ? (float) $s['stop_lat'] : (isset($s['latitude']) ? (float) $s['latitude'] : null),
                'lng' => isset($s['stop_lng']) ? (float) $s['stop_lng'] : (isset($s['longitude']) ? (float) $s['longitude'] : null),
            ];
        }
        $days[] = [
            'day' => $n,
            'date' => (string) ($g['date'] ?? ''),
            'label' => (string) ($g['label'] ?? ('Day ' . $n)),
            'date_label' => !empty($g['date']) ? format_date($g['date']) : '',
            'meetup' => (string) ($place['label'] ?? ''),
            'meetup_time' => (string) ($place['meetup_display'] ?? ''),
            'stops' => $stops,
        ];
    }
}

$certUrl = null;
if (booking_is_pwd($b) && booking_user_can_view_fitness_certificate($user, $b)) {
    $certUrl = booking_fitness_certificate_url($b);
}

json_response([
    'ok' => true,
    'data' => [
        'id' => (int) $b['id'],
        'booking_code' => (string) $b['booking_code'],
        'status' => (string) $b['status'],
        'payment_status' => (string) ($b['payment_status'] ?? ''),
        'payment_label' => ucwords(str_replace('_', ' ', (string) ($b['payment_status'] ?? 'unpaid'))),
        'payment_reference' => (string) ($b['payment_reference'] ?? ''),
        'title' => trim((string) ($b['itinerary_title'] ?? '')) ?: ('Tour with ' . $b['guide_name']),
        'description' => itinerary_display_description($b['itinerary_description'] ?? null),
        'itinerary_id' => $itinId,
        'start_date' => $start,
        'end_date' => $end,
        'date_label' => format_date($start) . ($end && $end !== $start ? ' – ' . format_date($end) : ''),
        'meetup' => format_time_12h($b['start_time'] ?? null),
        'party_size' => (int) ($b['party_size'] ?? 1),
        'accommodation' => $itinId > 0 ? stay_ride_label($b, 'accommodation') : '',
        'transportation' => $itinId > 0 ? stay_ride_label($b, 'transportation') : '',
        'notes' => (string) ($b['notes'] ?? ''),
        'rejection_reason' => (string) ($b['rejection_reason'] ?? ''),
        'is_pwd' => booking_is_pwd($b),
        'pwd_notes' => (string) ($b['pwd_notes'] ?? ''),
        'fitness_certificate_url' => $certUrl,
        'tourist' => [
            'user_id' => (int) $b['tourist_id'],
            'name' => (string) $b['tourist_name'],
            'email' => (string) ($b['tourist_email'] ?? ''),
            'phone' => (string) ($b['tourist_phone'] ?? ''),
            'avatar_url' => api_upload_url($b['tourist_avatar'] ?? null),
        ],
        'guide' => [
            'user_id' => (int) $b['guide_user_id'],
            'name' => (string) $b['guide_name'],
            'phone' => (string) ($b['guide_phone'] ?? ''),
            'avatar_url' => api_upload_url($b['guide_avatar'] ?? null),
        ],
        'ticket_ready' => $ticketReady,
        'qr_url' => ($ticketReady && !empty($b['qr_code_path'])) ? api_upload_url($b['qr_code_path']) : null,
        'days' => $days,
    ],
]);
