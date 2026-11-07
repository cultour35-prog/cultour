<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/payments.php';
$user = require_api_user();
ensure_booking_refund_schema();
ensure_booking_replacement_schema();
$role = (int) $user['role_id'];
$gid = 0;
if ($role === ROLE_TOURIST) {
    $stmt = db()->prepare(
        "SELECT b.*, CONCAT(u.first_name,' ',u.last_name) AS guide_name,
                gp.user_id AS guide_user_id, u.avatar AS guide_avatar, gp.hourly_rate,
                CONCAT(ou.first_name,' ',ou.last_name) AS original_guide_name,
                d.name AS destination_name, i.title AS itinerary_title,
                (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.booking_id=b.id AND gr.tourist_id=b.tourist_id) AS rated
         FROM bookings b
         JOIN tour_guide_profiles gp ON gp.id=b.guide_id JOIN users u ON u.id=gp.user_id
         LEFT JOIN tour_guide_profiles ogp ON ogp.id=b.original_guide_id
         LEFT JOIN users ou ON ou.id=ogp.user_id
         LEFT JOIN destinations d ON d.id=b.destination_id
         LEFT JOIN itineraries i ON i.id=b.itinerary_id
         WHERE b.tourist_id=? ORDER BY b.created_at DESC"
    );
    $stmt->execute([$user['id']]);
} elseif ($role === ROLE_TOUR_GUIDE) {
    $g = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $g->execute([$user['id']]);
    $gid = (int) $g->fetchColumn();
    $stmt = db()->prepare(
        "SELECT b.*, CONCAT(u.first_name,' ',u.last_name) AS tourist_name, u.avatar AS tourist_avatar,
                u.phone AS tourist_phone, u.email AS tourist_email,
                d.name AS destination_name, i.title AS itinerary_title, gp.hourly_rate
         FROM bookings b
         JOIN users u ON u.id=b.tourist_id
         JOIN tour_guide_profiles gp ON gp.id=b.guide_id
         LEFT JOIN destinations d ON d.id=b.destination_id
         LEFT JOIN itineraries i ON i.id=b.itinerary_id
         WHERE b.guide_id=? OR b.original_guide_id=?
         ORDER BY FIELD(b.status,'pending','approved','completed','rejected','cancelled'), b.created_at DESC, b.booking_date"
    );
    $stmt->execute([$gid, $gid]);
} else {
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}
$rows = $stmt->fetchAll() ?: [];
foreach ($rows as &$row) {
    $status = (string) ($row['status'] ?? '');
    $pay = (string) ($row['payment_status'] ?? 'unpaid');
    $needsRepl = booking_needs_replacement($row);
    $dates = booking_visit_dates($row);
    $row['guide_avatar_url'] = api_upload_url($row['guide_avatar'] ?? null);
    $row['tourist_avatar_url'] = api_upload_url($row['tourist_avatar'] ?? null);
    $row['needs_replacement'] = $needsRepl;
    $row['replacement_state'] = $needsRepl ? booking_replacement_state($row) : '';
    $row['rated'] = !empty($row['rated']);
    $row['is_pwd'] = booking_is_pwd($row);
    $row['fitness_certificate_url'] = api_upload_url($row['fitness_certificate_path'] ?? null);
    $row['date_label'] = format_date($row['booking_date'] ?? null);
    $row['time_label'] = format_time_12h($row['start_time'] ?? null);
    $row['visit_dates'] = array_map(static fn ($d) => ['date' => $d, 'label' => format_date($d)], $dates);
    $row['payment_label'] = ucwords(str_replace('_', ' ', $pay));
    $amount = $row['payment_amount'] !== null ? (float) $row['payment_amount'] : booking_payment_amount($row, $row);
    $row['amount'] = $amount;
    $row['amount_label'] = '₱' . number_format($amount, 2);
    $row['ticket_ready'] = in_array($status, ['approved', 'completed'], true);
    $row['has_itinerary'] = !empty($row['itinerary_id']);
    $row['handed_off'] = false;
    if ($role === ROLE_TOURIST) {
        $row['pay_open'] = in_array($status, ['pending', 'approved'], true) && $pay !== 'paid';
        $row['can_cancel'] = booking_tourist_can_cancel($row);
        $row['cancel_needs_refund'] = $row['can_cancel'] && in_array($status, ['pending', 'approved'], true) && $pay === 'paid';
        $row['cancel_label'] = $row['cancel_needs_refund']
            ? ($needsRepl ? 'Refund remaining days' : 'Cancel and refund')
            : ($status === 'pending' ? 'Cancel request' : 'Cancel remaining days');
        $row['can_rate'] = $status === 'completed' && !$row['rated'];
        $row['replacement_note'] = ($needsRepl && $status === 'approved')
            ? trim(preg_replace('/\s+/', ' ', strip_tags(booking_replacement_tourist_status_html($row))))
            : '';
        $row['website_pay_url'] = rtrim(APP_URL, '/') . '/tourist/pay.php?booking_id=' . (int) $row['id'];
        $refundState = (string) ($row['refund_status'] ?? '');
        $refundAmount = format_money(refund_amount_of($row));
        $row['refund_status'] = $refundState;
        $row['refund_amount_label'] = $refundAmount;
        $row['refund_needs_details'] = $refundState === REFUND_NEEDS_DETAILS;
        $row['refund_line'] = match ($refundState) {
            REFUND_NEEDS_DETAILS => 'Refund of ' . $refundAmount . ' is waiting for your GCash details.',
            REFUND_REQUESTED => 'Refund of ' . $refundAmount . ' requested. The Tourism Office will send it to '
                . (!empty($row['refund_number']) ? $row['refund_number'] : 'your GCash QR') . '.',
            REFUND_SENT => 'Refunded ' . $refundAmount . (!empty($row['refund_reference']) ? ' · Ref ' . $row['refund_reference'] : ''),
            default => '',
        };
    } else {
        $isCurrent = (int) ($row['guide_id'] ?? 0) === $gid;
        $fromDate = normalize_tour_date($row['replacement_from_date'] ?? null);
        $row['is_current'] = $isCurrent;
        $row['handed_off'] = !$isCurrent && booking_original_guide_id($row) === $gid;
        $row['replacement_from_label'] = $fromDate ? format_date($fromDate) : '';
        $row['can_decide'] = $isCurrent && $status === 'pending' && in_array($pay, ['paid', 'pay_at_office'], true);
        $row['waiting_payment'] = $isCurrent && $status === 'pending' && !$row['can_decide'];
        $live = $isCurrent && $status === 'approved' && !$needsRepl;
        $row['can_complete'] = $live;
        $row['can_pay_at_office'] = $live && $pay !== 'paid';
        $row['not_checked_in'] = empty($row['checked_in_at']);
        $row['can_cancel'] = $isCurrent && booking_can_guide_cancel_approved($row, $gid);
        $row['cancel_label'] = count($dates) > 1 ? 'Cancel whole tour' : 'Cancel tour';
        $row['can_withdraw'] = $isCurrent && booking_can_guide_withdraw($row, $gid);
        $row['withdraw_dates'] = $row['can_withdraw']
            ? array_map(static fn ($d) => ['date' => $d, 'label' => format_date($d)], booking_releasable_visit_dates($row))
            : [];
        $row['is_new'] = $status === 'pending' && !empty($row['created_at'])
            && (time() - strtotime((string) $row['created_at'])) < 120;
    }
}
unset($row);
$extra = [];
if ($role === ROLE_TOUR_GUIDE) {
    $extra = [
        'decline_reasons' => booking_decline_reason_presets(),
        'withdraw_reasons' => booking_withdraw_reason_presets(),
        'has_schedule' => guide_has_weekly_availability($gid),
    ];
}
json_response(array_merge(['ok' => true, 'data' => $rows], $extra));
