<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/inhouse_guides.php';

$user = require_login(ROLE_BUSINESS);
ensure_package_order_schema();

$biz = db()->prepare(
    'SELECT b.id, b.name, bt.name AS type_name
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.user_id=? LIMIT 1'
);
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();
$catalogMode = $biz ? business_catalog_mode($biz['type_name'] ?? '') : '';
if (!$biz || !in_array($catalogMode, ['packages', 'rooms', 'vehicles'], true)) {
    redirect(APP_URL . '/business/dashboard.php');
}
$pageTitle = 'Bookings';
$activeNav = 'bookings';
$isRooms = $catalogMode === 'rooms';
$isRides = $catalogMode === 'vehicles';
if ($isRooms) {
    $pageHeading = 'Bookings';
    $pageSub = 'Accept or reject stays from ' . ($biz['name'] ?? 'your business');
} elseif ($isRides) {
    $pageHeading = 'Bookings';
    $pageSub = 'Accept or reject rides from ' . ($biz['name'] ?? 'your business');
} else {
    $pageHeading = 'Booked tours';
    $pageSub = 'Tourists who booked a package from ' . ($biz['name'] ?? 'your business');
}

$orders = [];
$stays = [];
$finalizeHotels = [];
$finalizeRides = [];
$finalizeOffers = [];
$finalizeGuides = [];
if ($isRooms || $isRides) {
    ensure_booking_payment_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && (string) ($_POST['action'] ?? '') === 'place_booking') {
        $res = booking_place_decide(
            (int) ($_POST['booking_id'] ?? 0),
            (int) $user['id'],
            $isRides ? 'transportation' : 'accommodation',
            (string) ($_POST['decision'] ?? '') === 'accept',
            (string) ($_POST['reason'] ?? '')
        );
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect(APP_URL . '/business/bookings.php');
    }
    ensure_booking_payment_schema();
    $bizId = (int) $biz['id'];
    $side = $isRides ? 'transportation' : 'accommodation';
    $st = db()->prepare(
        "SELECT b.id, b.booking_code, b.party_size, b.booking_date, b.tour_start_date, b.tour_end_date,
                b.accommodation_business_id, b.accommodation_service_id, b.accommodation_units,
                b.accommodation_amount, b.accommodation_lines, b.accommodation_decision,
                b.transportation_business_id, b.transportation_service_id,
                b.transportation_amount, b.transportation_lines, b.transportation_decision,
                b.payment_status, b.rejection_reason,
                u.first_name, u.last_name, u.phone, s.name AS room_name
         FROM bookings b
         JOIN users u ON u.id = b.tourist_id
         LEFT JOIN business_services s ON s.id = b.{$side}_service_id
         WHERE b.{$side}_declined = 0
           AND b.{$side}_decision <> 'none'
           AND b.status <> 'cancelled'
           AND b.payment_status IN ('paid', 'pay_at_office', 'pending_review')
           AND b.{$side}_business_id = ?
         ORDER BY COALESCE(b.tour_start_date, b.booking_date) DESC, b.id DESC"
    );
    $st->execute([$bizId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $stay = business_room_booking_summary($row, $bizId);
        if ($stay !== null) {
            $stays[] = $stay;
        }
    }
} else {
    $st = db()->prepare(
        'SELECT o.id, o.order_code, o.package_name, o.amount, o.tour_date, o.package_days, o.package_pax,
                o.payment_status, o.payment_reference, o.booking_status, o.operator_ready, o.snapshot_itinerary_id, o.rejection_reason, o.package_party_notes, o.tourist_id, o.service_id, o.created_at,
                u.first_name, u.last_name, u.phone, s.package_date_mode
         FROM package_orders o
         JOIN users u ON u.id = o.tourist_id
         LEFT JOIN business_services s ON s.id = o.service_id
         WHERE o.business_id=? AND o.payment_status IN (\'paid\', \'pay_at_office\', \'pending_review\')
         ORDER BY o.tour_date IS NULL, o.tour_date DESC, o.id DESC'
    );
    $st->execute([(int) $biz['id']]);
    $orders = $st->fetchAll() ?: [];
    inhouse_guides_ensure_schema();
    $finalizeGuides = inhouse_guides_for_business((int) $biz['id'], true);
    $placeRows = db()->query(
        "SELECT b.id, b.name, b.address, b.description, b.cover_image, b.avg_rating, b.review_count, bt.name AS type_name
         FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.approval_status='approved' AND b.status='active'
           AND bt.name IN ('Accommodation','Hotel','Resort','Transportation')
         ORDER BY b.name"
    )->fetchAll() ?: [];
    $placeIds = [];
    foreach ($placeRows as $placeRow) {
        if ((string) ($placeRow['type_name'] ?? '') === 'Transportation') {
            $finalizeRides[] = $placeRow;
        } else {
            $finalizeHotels[] = $placeRow;
        }
        $placeIds[] = (int) $placeRow['id'];
    }
    if ($placeIds) {
        $offerRows = db()->query(
            'SELECT id, business_id, name, price, capacity, unit_count, extra_guest_fee, extra_guest_limit, image_path FROM business_services
             WHERE is_active=1 AND business_id IN (' . implode(',', $placeIds) . ')
             ORDER BY price, name'
        )->fetchAll() ?: [];
        foreach ($offerRows as $offerRow) {
            $finalizeOffers[(int) $offerRow['business_id']][] = $offerRow;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
        $action = (string) ($_POST['action'] ?? '');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $owned = false;
        foreach ($orders as $order) {
            if ((int) $order['id'] === $orderId) {
                $owned = true;
                break;
            }
        }
        if (!$owned) {
            flash('error', 'That booking is not yours.');
        } elseif ($action === 'replace_stop') {
            require_once __DIR__ . '/../includes/revenue.php';
            revenue_ensure_schema();
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $newBusinessId = (int) ($_POST['business_id'] ?? 0);
            $current = null;
            foreach (package_order_reservations($orderId) as $reservation) {
                if ((int) $reservation['id'] === $reservationId) {
                    $current = $reservation;
                }
            }
            $replacement = db()->prepare(
                "SELECT b.id, b.name, b.user_id, bt.name AS type_name
                 FROM businesses b
                 JOIN business_types bt ON bt.id = b.business_type_id
                 WHERE b.id=? AND b.approval_status='approved' AND b.status='active'
                 LIMIT 1"
            );
            $replacement->execute([$newBusinessId]);
            $nextPlace = $replacement->fetch() ?: null;
            $touristId = 0;
            foreach ($orders as $order) {
                if ((int) $order['id'] === $orderId) {
                    $touristId = (int) $order['tourist_id'];
                }
            }
            $already = false;
            if ($current) {
                $prior = db()->prepare(
                    "SELECT id FROM restaurant_reservations
                     WHERE itinerary_item_id=? AND business_id=? AND tourist_id=? AND status='rejected'
                     LIMIT 1"
                );
                $prior->execute([(int) $current['itinerary_item_id'], $newBusinessId, $touristId]);
                $already = (bool) $prior->fetch();
            }
            if (!$current || (string) $current['status'] !== 'rejected' || !$nextPlace) {
                flash('error', 'Choose another place for the declined reservation.');
            } elseif ((string) $nextPlace['type_name'] !== (string) $current['type_name'] || $newBusinessId === (int) $current['business_id'] || $already) {
                flash('error', 'That place cannot replace this stop.');
            } else {
                $old = db()->prepare('SELECT * FROM restaurant_reservations WHERE id=? LIMIT 1');
                $old->execute([$reservationId]);
                $oldRow = $old->fetch() ?: [];
                $code = 'RES-' . strtoupper(bin2hex(random_bytes(3)));
                db()->prepare(
                    'INSERT INTO restaurant_reservations
                     (code, tourist_id, business_id, itinerary_id, itinerary_item_id, reserve_date, reserve_time, guests, order_mode, status, fee_snapshot, food_total)
                     VALUES (?,?,?,?,?,?,?,?,\'table_only\',\'pending\',0,0)'
                )->execute([
                    $code,
                    $touristId,
                    $newBusinessId,
                    (int) ($oldRow['itinerary_id'] ?? 0),
                    (int) ($oldRow['itinerary_item_id'] ?? 0),
                    (string) ($oldRow['reserve_date'] ?? ''),
                    (string) ($oldRow['reserve_time'] ?? '12:00:00'),
                    (int) ($oldRow['guests'] ?? 1),
                ]);
                $newId = (int) db()->lastInsertId();
                reservation_event($newId, 'pending', 'Reservation requested', (int) $user['id']);
                notify_user((int) $nextPlace['user_id'], 'booking_request', 'New reservation request', $code . ' is waiting for your response.', APP_URL . '/business/reservations.php');
                if ($touristId > 0) {
                    notify_user(
                        $touristId,
                        'package_booking',
                        'A package stop changed',
                        (string) $current['business_name'] . ' could not take the reservation, so that stop is now ' . (string) $nextPlace['name'] . '. ' . (string) $nextPlace['name'] . ' still has to confirm.',
                        APP_URL . '/tourist/bookings.php'
                    );
                }
                flash('success', 'Stop changed. The tourist was told, and the booking stays pending until the new place confirms.');
            }
        } elseif ($action === 'assign_guide') {
            $decided = '';
            foreach ($orders as $order) {
                if ((int) $order['id'] === $orderId) {
                    $decided = (string) ($order['booking_status'] ?? '');
                }
            }
            if ($decided !== 'approved') {
                flash('error', 'Accept the booking before assigning a guide.');
                redirect(APP_URL . '/business/bookings.php');
            }
            $guideId = (int) ($_POST['guide_id'] ?? 0);
            $date = '';
            foreach ($orders as $order) {
                if ((int) $order['id'] === $orderId) {
                    $date = (string) ($order['tour_date'] ?? '');
                }
            }
            $mine = false;
            foreach (inhouse_guides_for_business((int) $biz['id'], true) as $guide) {
                if ((int) $guide['guide_id'] === $guideId && (int) $guide['inhouse_active'] === 1 && (string) $guide['status'] === 'active' && (string) $guide['approval_status'] === 'approved') {
                    $mine = true;
                }
            }
            if (!$mine) {
                flash('error', 'Pick one of your active in-house guides.');
            } else {
                $prev = db()->prepare('SELECT guide_id FROM package_guide_assignments WHERE order_id=?');
                $prev->execute([$orderId]);
                $prevId = (int) ($prev->fetchColumn() ?: 0);
                db()->prepare(
                    "INSERT INTO package_guide_assignments (order_id, guide_id, assigned_by, status)
                     VALUES (?,?,?,'assigned')
                     ON DUPLICATE KEY UPDATE guide_id=VALUES(guide_id), assigned_by=VALUES(assigned_by), status='assigned', assigned_at=NOW()"
                )->execute([$orderId, $guideId, (int) $user['id']]);
                db()->prepare(
                    'INSERT INTO package_guide_assignment_history (order_id, previous_guide_id, new_guide_id, changed_by, change_reason) VALUES (?,?,?,?,?)'
                )->execute([$orderId, $prevId > 0 ? $prevId : null, $guideId, (int) $user['id'], 'Assigned by business']);
                $guideUser = db()->prepare('SELECT user_id FROM tour_guide_profiles WHERE id=?');
                $guideUser->execute([$guideId]);
                $guideUserId = (int) $guideUser->fetchColumn();
                if ($guideUserId > 0) {
                    notify_user($guideUserId, 'guide_assignment', 'New package assignment', 'You were assigned to a tour package.', APP_URL . '/tourguide/dashboard.php');
                }
                flash('success', 'Guide assigned.');
            }
        } elseif ($action === 'accept' || $action === 'reject') {
            $current = '';
            foreach ($orders as $order) {
                if ((int) $order['id'] === $orderId) {
                    $current = (string) ($order['booking_status'] ?? 'pending');
                }
            }
            if ($current !== 'pending') {
                flash('error', 'This booking was already decided.');
            } elseif ($action === 'accept') {
                $guideId = (int) ($_POST['guide_id'] ?? 0);
                $tourDate = '';
                foreach ($orders as $order) {
                    if ((int) $order['id'] === $orderId) {
                        $tourDate = (string) ($order['tour_date'] ?? '');
                    }
                }
                $guideOk = false;
                $hasInhouse = false;
                foreach (inhouse_guides_for_business((int) $biz['id'], true) as $guide) {
                    if ((int) $guide['inhouse_active'] === 1 && (string) $guide['status'] === 'active' && (string) $guide['approval_status'] === 'approved') {
                        $hasInhouse = true;
                        if ((int) $guide['guide_id'] === $guideId) {
                            $guideOk = true;
                        }
                    }
                }
                if (!$guideOk && !$hasInhouse && $guideId > 0) {
                    $pub = db()->prepare(
                        "SELECT g.id FROM tour_guide_profiles g
                         JOIN users u ON u.id = g.user_id
                         WHERE g.id=? AND g.approval_status='approved' AND u.status='active'
                           AND g.guide_type <> 'in_house'
                           AND " . guide_profile_complete_sql('g', 'u') . "
                         LIMIT 1"
                    );
                    $pub->execute([$guideId]);
                    $guideOk = (bool) $pub->fetchColumn();
                }
                $stayDeclined = (int) ($_POST['accommodation_declined'] ?? 0) === 1;
                $rideDeclined = (int) ($_POST['transportation_declined'] ?? 0) === 1;
                $stayBiz = $stayDeclined ? 0 : (int) ($_POST['accommodation_business_id'] ?? 0);
                $staySvc = $stayDeclined ? 0 : (int) ($_POST['accommodation_service_id'] ?? 0);
                $rideBiz = $rideDeclined ? 0 : (int) ($_POST['transportation_business_id'] ?? 0);
                $rideSvc = $rideDeclined ? 0 : (int) ($_POST['transportation_service_id'] ?? 0);
                $sideOk = static function (string $kind, int $businessId, int $serviceId): bool {
                    if ($businessId < 1 || $serviceId < 1) {
                        return false;
                    }
                    $names = stay_ride_type_names($kind);
                    $st = db()->prepare(
                        'SELECT s.id FROM business_services s
                         JOIN businesses b ON b.id = s.business_id
                         JOIN business_types bt ON bt.id = b.business_type_id
                         WHERE s.id=? AND s.business_id=? AND s.is_active=1
                           AND b.approval_status=\'approved\' AND b.status=\'active\'
                           AND bt.name IN (' . implode(',', array_fill(0, count($names), '?')) . ')
                         LIMIT 1'
                    );
                    $st->execute(array_merge([$serviceId, $businessId], $names));
                    return (bool) $st->fetchColumn();
                };
                if (!$guideOk) {
                    flash('error', $hasInhouse ? 'Choose one of your active in-house guides.' : 'Choose a tour guide.');
                } elseif (!$stayDeclined && !$sideOk('accommodation', $stayBiz, $staySvc)) {
                    flash('error', 'Choose a room or mark accommodation as not needed.');
                } elseif (!$rideDeclined && !$sideOk('transportation', $rideBiz, $rideSvc)) {
                    flash('error', 'Choose a vehicle or mark transportation as not needed.');
                } else {
                    $waitingOnPlace = package_order_reservations_block_approval($orderId);
                    db()->prepare(
                        'UPDATE package_orders
                         SET booking_status=?, operator_ready=1,
                             accommodation_business_id=?, accommodation_service_id=?, accommodation_declined=?,
                             transportation_business_id=?, transportation_service_id=?, transportation_declined=?
                         WHERE id=? AND business_id=?'
                    )->execute([
                        $waitingOnPlace ? 'pending' : 'approved',
                        $stayDeclined ? null : $stayBiz,
                        $stayDeclined ? null : $staySvc,
                        $stayDeclined ? 1 : 0,
                        $rideDeclined ? null : $rideBiz,
                        $rideDeclined ? null : $rideSvc,
                        $rideDeclined ? 1 : 0,
                        $orderId,
                        (int) $biz['id'],
                    ]);
                    $prev = db()->prepare('SELECT guide_id FROM package_guide_assignments WHERE order_id=?');
                    $prev->execute([$orderId]);
                    $prevId = (int) ($prev->fetchColumn() ?: 0);
                    db()->prepare(
                        "INSERT INTO package_guide_assignments (order_id, guide_id, assigned_by, status)
                         VALUES (?,?,?,'assigned')
                         ON DUPLICATE KEY UPDATE guide_id=VALUES(guide_id), assigned_by=VALUES(assigned_by), status='assigned', assigned_at=NOW()"
                    )->execute([$orderId, $guideId, (int) $user['id']]);
                    $assignStatus = $hasInhouse ? 'assigned' : 'pending_admin';
                    db()->prepare('UPDATE package_guide_assignments SET status=?, admin_note=NULL WHERE order_id=?')
                        ->execute([$assignStatus, $orderId]);
                    db()->prepare(
                        'INSERT INTO package_guide_assignment_history (order_id, previous_guide_id, new_guide_id, changed_by, change_reason) VALUES (?,?,?,?,?)'
                    )->execute([$orderId, $prevId > 0 ? $prevId : null, $guideId, (int) $user['id'], $hasInhouse ? 'Assigned when the booking was finalized' : 'Sent to the Tourism Office']);
                    $guideUser = db()->prepare('SELECT user_id, first_name, last_name FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id WHERE g.id=?');
                    $guideUser->execute([$guideId]);
                    $guideRow = $guideUser->fetch() ?: [];
                    $guideUserId = (int) ($guideRow['user_id'] ?? 0);
                    if ($hasInhouse && $guideUserId > 0) {
                        notify_user($guideUserId, 'guide_assignment', 'New package assignment', 'You were assigned to a tour package.', APP_URL . '/tourguide/bookings.php');
                    } elseif (!$hasInhouse) {
                        $guideName = trim((string) ($guideRow['first_name'] ?? '') . ' ' . (string) ($guideRow['last_name'] ?? ''));
                        $admins = db()->query("SELECT id FROM users WHERE role_id=" . (int) ROLE_ADMIN . " AND status='active'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        foreach ($admins as $adminId) {
                            notify_user((int) $adminId, 'guide_request', 'Tour guide request', ($biz['name'] ?? 'A travel business') . ' asked for ' . ($guideName !== '' ? $guideName : 'a system tour guide') . '.', APP_URL . '/admin/guide-requests.php');
                        }
                    }
                    $touristId = 0;
                    foreach ($orders as $order) {
                        if ((int) $order['id'] === $orderId) {
                            $touristId = (int) ($order['tourist_id'] ?? 0);
                        }
                    }
                    if ($touristId > 0 && !$waitingOnPlace) {
                        notify_user($touristId, 'package_booking', 'Package booking confirmed', 'The travel business confirmed your package booking.', APP_URL . '/tourist/bookings.php');
                    }
                    package_order_freeze_itinerary($orderId);
                    flash('success', !$hasInhouse
                        ? 'Finalized. The admin was notified and will review the tour guide before that guide is notified.'
                        : ($waitingOnPlace
                            ? 'Finalized. The booking stays pending until the place confirms the reservation.'
                            : 'Booking accepted and finalized.'));
                }
            } else {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === '') {
                    flash('error', 'Enter a reason for rejecting this booking.');
                } else {
                $reason = mb_substr($reason, 0, 255);
                db()->prepare('UPDATE package_orders SET booking_status=\'rejected\', rejection_reason=? WHERE id=? AND business_id=?')
                    ->execute([$reason, $orderId, (int) $biz['id']]);
                $touristId = 0;
                foreach ($orders as $order) {
                    if ((int) $order['id'] === $orderId) {
                        $touristId = (int) ($order['tourist_id'] ?? 0);
                    }
                }
                if ($touristId > 0) {
                    notify_user(
                        $touristId,
                        'package_booking',
                        'Package booking declined',
                        'The travel business declined your package booking. ' . $reason,
                        APP_URL . '/tourist/bookings.php'
                    );
                }
                flash('success', 'Booking declined.');
                }
            }
        } elseif ($action === 'complete') {
            $current = '';
            foreach ($orders as $order) {
                if ((int) $order['id'] === $orderId) {
                    $current = (string) ($order['booking_status'] ?? '');
                }
            }
            if ($current !== 'approved') {
                flash('error', 'Only an accepted booking can be marked completed.');
            } else {
                db()->prepare("UPDATE package_orders SET booking_status='completed' WHERE id=? AND business_id=?")
                    ->execute([$orderId, (int) $biz['id']]);
                flash('success', 'Booking marked completed.');
            }
        } elseif ($action === 'resubmit_system_guide') {
            $guideId = (int) ($_POST['guide_id'] ?? 0);
            $hold = db()->prepare("SELECT status FROM package_guide_assignments WHERE order_id=? LIMIT 1");
            $hold->execute([$orderId]);
            $holdStatus = (string) ($hold->fetchColumn() ?: '');
            $pub = db()->prepare(
                "SELECT g.id FROM tour_guide_profiles g
                 JOIN users u ON u.id = g.user_id
                 WHERE g.id=? AND g.approval_status='approved' AND u.status='active'
                   AND g.guide_type <> 'in_house'
                   AND " . guide_profile_complete_sql('g', 'u') . "
                 LIMIT 1"
            );
            $pub->execute([$guideId]);
            if ($holdStatus !== 'rejected' || !$pub->fetchColumn()) {
                flash('error', 'Choose another system tour guide.');
            } else {
                db()->prepare("UPDATE package_guide_assignments SET guide_id=?, assigned_by=?, status='pending_admin', admin_note=NULL, assigned_at=NOW() WHERE order_id=?")
                    ->execute([$guideId, (int) $user['id'], $orderId]);
                $guideUser = db()->prepare('SELECT u.first_name, u.last_name FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id WHERE g.id=?');
                $guideUser->execute([$guideId]);
                $guideRow = $guideUser->fetch() ?: [];
                $guideName = trim((string) ($guideRow['first_name'] ?? '') . ' ' . (string) ($guideRow['last_name'] ?? ''));
                $admins = db()->query("SELECT id FROM users WHERE role_id=" . (int) ROLE_ADMIN . " AND status='active'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($admins as $adminId) {
                    notify_user((int) $adminId, 'guide_request', 'Tour guide request', ($biz['name'] ?? 'A travel business') . ' asked again for ' . ($guideName !== '' ? $guideName : 'a system tour guide') . '.', APP_URL . '/admin/guide-requests.php');
                }
                flash('success', 'Tour guide request sent. The admin was notified.');
            }
        } elseif ($action === 'request_olga') {
            db()->prepare(
                'INSERT INTO olga_guide_requests (business_id, order_id, tour_date, notes, created_by) VALUES (?,?,?,?,?)'
            )->execute([(int) $biz['id'], $orderId, $_POST['tour_date'] ?? null, 'No in-house guide available', (int) $user['id']]);
            flash('success', 'OLGA request sent. An administrator can assign an OLGA guide.');
        }
        redirect(APP_URL . '/business/bookings.php');
    }
}

/**
 * Rooms from this business on one tourist booking.
 *
 * @param array<string,mixed> $row
 * @return array{tourist:array<string,mixed>,code:string,rooms:string,when:string,guests:int,amount:float,payment:string}|null
 */
function business_room_booking_summary(array $row, int $businessId): ?array
{
    $rideSide = (int) ($row['transportation_business_id'] ?? 0) === $businessId;
    $lines = stay_lines_list($row[$rideSide ? 'transportation_lines' : 'accommodation_lines'] ?? null) ?: [];
    $mine = [];
    foreach ($lines as $line) {
        if (is_array($line) && (int) ($line['business_id'] ?? 0) === $businessId) {
            $mine[] = $line;
        }
    }
    $ownsStay = (int) ($row[$rideSide ? 'transportation_business_id' : 'accommodation_business_id'] ?? 0) === $businessId;
    if ($mine === [] && !$ownsStay) {
        return null;
    }
    $names = [];
    $guestCount = 0;
    $amount = 0.0;
    $dates = [];
    foreach ($mine as $line) {
        $label = trim((string) ($line['label'] ?? ''));
        $label = preg_replace('/^Day\s+\d+\s+·\s+/u', '', $label) ?? $label;
        $parts = array_values(array_filter(array_map('trim', explode('·', $label)), static fn (string $part): bool => $part !== ''));
        $room = $parts !== [] ? (string) $parts[count($parts) - 1] : 'Room';
        $units = max(1, (int) ($line['units'] ?? 1));
        $names[] = $units > 1 ? $units . ' × ' . $room : $room;
        $listed = $line['guests'] ?? null;
        if (is_array($listed) && $listed !== []) {
            $guestCount += array_sum(array_map('intval', $listed));
        }
        $amount += (float) ($line['amount'] ?? 0);
        $dated = normalize_tour_date(isset($line['date']) ? (string) $line['date'] : null);
        if ($dated !== null) {
            $dates[] = $dated;
        }
    }
    if ($names === []) {
        $room = trim((string) ($row['room_name'] ?? ''));
        $names[] = $room !== '' ? $room : 'Room';
        $amount = (float) ($row[$rideSide ? 'transportation_amount' : 'accommodation_amount'] ?? 0);
    }
    if ($guestCount < 1) {
        $guestCount = max(0, (int) ($row['party_size'] ?? 0));
    }
    sort($dates);
    $start = $dates[0] ?? normalize_tour_date($row['tour_start_date'] ?? null) ?: normalize_tour_date($row['booking_date'] ?? null);
    $end = $dates !== [] ? $dates[count($dates) - 1] : normalize_tour_date($row['tour_end_date'] ?? null);
    $when = '—';
    if ($start) {
        $when = format_date($start);
        if ($end && $end !== $start) {
            $when .= ' – ' . format_date($end);
        }
    }
    $decisionKey = isset($row['transportation_decision']) && (int) ($row['transportation_business_id'] ?? 0) === $businessId
        ? 'transportation_decision'
        : 'accommodation_decision';
    return [
        'booking_id' => (int) ($row['id'] ?? 0),
        'decision' => (string) ($row[$decisionKey] ?? 'pending'),
        'reason' => (string) ($row['rejection_reason'] ?? ''),
        'tourist_name' => full_name($row),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'tourist' => $row,
        'code' => (string) ($row['booking_code'] ?? ''),
        'rooms' => implode(', ', $names),
        'when' => $when,
        'guests' => $guestCount,
        'amount' => $amount,
        'payment' => (string) ($row['payment_status'] ?? ''),
    ];
}

$statusLabel = static function (string $status): string {
    return match ($status) {
        'paid' => 'Paid',
        'pay_at_office' => 'Pay at Tourism Office',
        'pending_review' => 'Waiting for confirmation',
        default => 'Booked',
    };
};

if (!$isRooms) {
    $pageHeadingTools = '<div class="booking-list-tools">'
        . '<label class="guide-bookings-search">'
        . '<i class="bi bi-search" aria-hidden="true"></i>'
        . '<input type="search" id="bizBookingsSearch" placeholder="Search bookings" aria-label="Search bookings" autocomplete="off">'
        . '</label>'
        . '<select class="booking-list-sort" id="bizBookingsSort" aria-label="Sort bookings">'
        . '<option value="date-desc">Newest date</option>'
        . '<option value="date-asc">Oldest date</option>'
        . '<option value="status">Status</option>'
        . '<option value="name">Name A–Z</option>'
        . '<option value="pending">Pending</option>'
        . '<option value="approved">Approved</option>'
        . '<option value="cancelled">Cancelled</option>'
        . '<option value="rejected">Rejected</option>'
        . '</select></div>';
}
$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php if (($isRooms || $isRides) && !$stays): ?>
        <div class="glass p-4 text-muted">No bookings yet.</div>
      <?php elseif ($isRooms || $isRides): ?>
        <div class="row g-3">
          <?php foreach ($stays as $stay): ?>
            <?php $decision = (string) ($stay['decision'] ?? 'pending'); ?>
            <div class="col-12">
              <div class="glass p-3">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                  <div>
                    <div class="fw-semibold"><?= e($stay['code'] !== '' ? $stay['code'] : 'Booking') ?></div>
                    <div class="small text-muted"><?= e($stay['tourist_name']) ?><?= $stay['phone'] !== '' ? ' · ' . e($stay['phone']) : '' ?></div>
                    <div class="small text-muted"><?= e($stay['when']) ?> · <?= e($stay['rooms']) ?><?= $stay['guests'] > 0 ? ' · ' . (int) $stay['guests'] . ' guests' : '' ?></div>
                  </div>
                  <div class="text-end"><?= status_badge($decision === 'accepted' ? 'approved' : $decision) ?></div>
                </div>
                <div class="small mb-2"><span class="text-muted">Amount</span> <?= e(format_money((float) $stay['amount'])) ?></div>
                <?php if ($decision === 'pending'): ?>
                  <div class="d-flex flex-wrap gap-2 guide-booking-actions">
                    <form method="post" class="m-0"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="place_booking">
                      <input type="hidden" name="booking_id" value="<?= (int) $stay['booking_id'] ?>">
                      <input type="hidden" name="decision" value="accept">
                      <button class="btn btn-sm btn-ct" type="submit">Accept</button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectStay<?= (int) $stay['booking_id'] ?>">Reject</button>
                  </div>
                  <div class="modal fade" id="rejectStay<?= (int) $stay['booking_id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                      <div class="modal-content">
                        <form method="post"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="place_booking">
                          <input type="hidden" name="booking_id" value="<?= (int) $stay['booking_id'] ?>">
                          <input type="hidden" name="decision" value="reject">
                          <div class="modal-header">
                            <h2 class="modal-title h5">Reject <?= e($stay['code']) ?></h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <label class="form-label">Reason</label>
                            <textarea class="form-control" name="reason" rows="3" required placeholder="Why are you rejecting this booking?"></textarea>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-outline-danger" type="submit">Reject</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php elseif ($decision === 'rejected' && $stay['reason'] !== ''): ?>
                  <div class="small text-danger">Reason: <?= e($stay['reason']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif (!$orders): ?>
        <div class="glass p-4 text-muted">No booked tours yet.</div>
      <?php else: ?>
        <div class="row g-3" id="bizBookingList">
          <?php foreach ($orders as $order): ?>
            <?php
              $days = (int) ($order['package_days'] ?? 0);
              $pax = (int) ($order['package_pax'] ?? 0);
              $when = trim((string) ($order['tour_date'] ?? ''));
              $decision = (string) ($order['booking_status'] ?? 'pending');
              $asg = db()->prepare(
                  "SELECT a.guide_id, a.status AS guide_status, a.admin_note, u.first_name, u.last_name
                   FROM package_guide_assignments a
                   JOIN tour_guide_profiles g ON g.id=a.guide_id JOIN users u ON u.id=g.user_id
                   WHERE a.order_id=? LIMIT 1"
              );
              $asg->execute([(int) $order['id']]);
              $assignedGuide = $asg->fetch();
              $team = inhouse_guides_for_business((int) $biz['id'], true);
            ?>
            <div class="col-12" data-booking-card data-sort-status="<?= e($decision) ?>" data-sort-name="<?= e(strtolower(trim(full_name($order) . ' ' . (string) $order['package_name'] . ' ' . (string) $order['order_code']))) ?>" data-sort-date="<?= e($when !== '' ? $when : substr((string) ($order['created_at'] ?? ''), 0, 10)) ?>">
              <div class="glass p-3">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                  <div>
                    <div class="fw-semibold"><code><?= e((string) $order['order_code']) ?></code> · <?= e((string) $order['package_name']) ?></div>
                    <div class="small text-muted">
                      <?= e(full_name($order)) ?>
                      <?php if (trim((string) ($order['phone'] ?? '')) !== ''): ?>
                        · <?= e((string) $order['phone']) ?>
                      <?php endif; ?>
                    </div>
                    <div class="small text-muted">
                      <?= $when !== '' ? e(format_date($when)) : 'Date not set' ?>
                      <?php if ($days > 0): ?> · <?= e(package_days_label($days)) ?><?php endif; ?>
                      <?php if ($pax > 0): ?> · <?= e(package_pax_label($pax)) ?><?php endif; ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <?= status_badge($decision) ?>
                  </div>
                </div>
                <div class="row g-2 small mb-2">
                  <div class="col-md-6">
                    <span class="text-muted">Amount</span>
                    <div><?= e(format_money((float) $order['amount'])) ?></div>
                  </div>
                  <div class="col-md-6">
                    <span class="text-muted">Payment</span>
                    <div>
                      <?= e($statusLabel((string) $order['payment_status'])) ?>
                      <?php if (trim((string) ($order['payment_reference'] ?? '')) !== ''): ?>
                        <span class="text-muted">· Ref <?= e((string) $order['payment_reference']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="mb-3 d-flex flex-wrap gap-2">
                  <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#pkgOrder<?= (int) $order['id'] ?>">
                    <i class="bi bi-card-list"></i> View details
                  </button>
                  <?php if ((int) ($order['operator_ready'] ?? 0) === 1): ?>
                    <?php
                      if ((int) ($order['snapshot_itinerary_id'] ?? 0) < 1) {
                          package_order_freeze_itinerary((int) $order['id']);
                          $snap = db()->prepare('SELECT snapshot_itinerary_id FROM package_orders WHERE id=?');
                          $snap->execute([(int) $order['id']]);
                          $order['snapshot_itinerary_id'] = (int) $snap->fetchColumn();
                      }
                    ?>
                    <?php if ((int) ($order['snapshot_itinerary_id'] ?? 0) > 0): ?>
                      <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= APP_URL ?>/itinerary-share.php?from=package&order=<?= (int) $order['id'] ?>">View itinerary</a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php if ($decision === 'pending' && (int) ($order['operator_ready'] ?? 0) === 1): ?>
                  <?php $placeHolds = package_order_reservations((int) $order['id']); ?>
                  <?php $guideState = (string) ($assignedGuide['guide_status'] ?? ''); ?>
                  <?php if ($guideState === 'rejected'): ?>
                    <div class="small text-danger mb-2">The admin declined this tour guide. <?= e((string) ($assignedGuide['admin_note'] ?? '')) ?></div>
                    <button type="button" class="btn btn-sm btn-ct mb-2" data-pkg-finalize data-guide-only data-order-id="<?= (int) $order['id'] ?>" data-package="<?= e((string) $order['package_name']) ?>" data-busy="<?= e(implode(',', (string) ($order['tour_date'] ?? '') !== '' ? guides_busy_ids_on((string) $order['tour_date']) : [])) ?>">Choose another tour guide</button>
                  <?php elseif ($guideState === 'pending_admin'): ?>
                    <div class="small mb-2">Waiting for the admin to accept <?= e(trim($assignedGuide['first_name'] . ' ' . $assignedGuide['last_name'])) ?>.</div>
                  <?php elseif ($guideState === 'needs_replacement'): ?>
                    <div class="small mb-2">The tour guide declined. The admin will assign another guide.</div>
                  <?php endif; ?>
                  <div class="small mb-2">Waiting for the place to confirm. The tourist is not told the package is approved yet.</div>
                  <?php foreach ($placeHolds as $hold): ?>
                    <?php if ((string) $hold['status'] !== 'rejected') continue; ?>
                    <?php
                      $alts = db()->prepare(
                          "SELECT b.id, b.name FROM businesses b
                           JOIN business_types bt ON bt.id = b.business_type_id
                           WHERE bt.name=? AND b.approval_status='approved' AND b.status='active' AND b.id<>?
                             AND NOT EXISTS (
                               SELECT 1 FROM restaurant_reservations r
                               WHERE r.itinerary_item_id=? AND r.business_id=b.id AND r.tourist_id=? AND r.status='rejected'
                             )
                           ORDER BY b.name"
                      );
                      $alts->execute([(string) $hold['type_name'], (int) $hold['business_id'], (int) $hold['itinerary_item_id'], (int) $order['tourist_id']]);
                    ?>
                    <form method="post" class="border rounded p-2 mb-2">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="replace_stop">
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                      <input type="hidden" name="reservation_id" value="<?= (int) $hold['id'] ?>">
                      <div class="small text-danger mb-1"><?= e((string) $hold['business_name']) ?> declined. <?= e((string) ($hold['rejection_reason'] ?? '')) ?></div>
                      <div class="d-flex flex-wrap gap-2">
                        <select class="form-select form-select-sm" name="business_id" required style="max-width:16rem">
                          <option value="">Choose another place</option>
                          <?php foreach ($alts->fetchAll() ?: [] as $alt): ?>
                            <option value="<?= (int) $alt['id'] ?>"><?= e((string) $alt['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-ct" type="submit">Change this stop</button>
                      </div>
                    </form>
                  <?php endforeach; ?>
                <?php elseif ($decision === 'pending'): ?>
                  <div class="d-flex flex-wrap gap-2 guide-booking-actions">
                    <?php
                      $orderDate = (string) ($order['tour_date'] ?? '');
                      $busyGuideIds = $orderDate !== '' ? guides_busy_ids_on($orderDate) : [];
                      $orderExclusive = (string) ($order['package_date_mode'] ?? '') !== 'fixed';
                    ?>
                    <button type="button" class="btn btn-sm btn-ct" data-pkg-finalize data-bs-toggle="modal" data-bs-target="#pkgFinalizeModal" data-order-id="<?= (int) $order['id'] ?>" data-package="<?= e((string) $order['package_name']) ?>" data-exclusive="<?= $orderExclusive ? '1' : '0' ?>" data-pax="<?= (int) $pax ?>" data-busy="<?= e(implode(',', $busyGuideIds)) ?>">Accept and finalize</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectPackage<?= (int) $order['id'] ?>">Reject</button>
                  </div>
                <?php elseif ($decision === 'rejected' && trim((string) ($order['rejection_reason'] ?? '')) !== ''): ?>
                  <div class="small text-danger mt-2">Reason: <?= e((string) $order['rejection_reason']) ?></div>
                <?php elseif ((string) ($assignedGuide['guide_status'] ?? '') === 'rejected'): ?>
                  <div class="small text-danger mb-2">The admin declined this tour guide. <?= e((string) ($assignedGuide['admin_note'] ?? '')) ?></div>
                  <button type="button" class="btn btn-sm btn-ct" data-pkg-finalize data-guide-only data-order-id="<?= (int) $order['id'] ?>" data-package="<?= e((string) $order['package_name']) ?>" data-busy="<?= e(implode(',', (string) ($order['tour_date'] ?? '') !== '' ? guides_busy_ids_on((string) $order['tour_date']) : [])) ?>">Choose another tour guide</button>
                <?php elseif ((string) ($assignedGuide['guide_status'] ?? '') === 'pending_admin'): ?>
                  <div class="small mb-2">Waiting for the admin to accept <?= e(trim($assignedGuide['first_name'] . ' ' . $assignedGuide['last_name'])) ?>.</div>
                <?php elseif ((string) ($assignedGuide['guide_status'] ?? '') === 'needs_replacement'): ?>
                  <div class="small mb-2">The tour guide declined. The admin will assign another guide.</div>
                <?php elseif ($decision === 'approved'): ?>
                  <div class="d-flex flex-wrap gap-2 guide-booking-actions mb-2">
                    <form method="post" onsubmit="return confirm('Mark this tour completed?');"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="complete">
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                      <button class="btn btn-sm btn-ct" type="submit">Mark completed</button>
                    </form>
                  </div>
                  <div class="small mb-2">
                    <?php if ($assignedGuide): ?>
                      Guide · <?= e(trim($assignedGuide['first_name'] . ' ' . $assignedGuide['last_name'])) ?>
                    <?php else: ?>
                      <span class="text-muted">Awaiting guide</span>
                    <?php endif; ?>
                  </div>
                  <form method="post" class="d-flex flex-wrap gap-2 mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_guide">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <select class="form-select form-select-sm" name="guide_id" required style="max-width:16rem">
                      <option value="">Assign guide</option>
                      <?php foreach ($team as $guide): ?>
                        <?php if ((int) $guide['inhouse_active'] !== 1 || (string) $guide['status'] !== 'active' || (string) $guide['approval_status'] !== 'approved') continue; ?>
                        <option value="<?= (int) $guide['guide_id'] ?>"><?= e(trim($guide['first_name'] . ' ' . $guide['last_name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-ct" type="submit">Save</button>
                  </form>
                  <?php if (!$assignedGuide): ?>
                    <form method="post" class="m-0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="request_olga">
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                      <input type="hidden" name="tour_date" value="<?= e((string) ($order['tour_date'] ?? '')) ?>">
                      <button class="btn btn-sm btn-outline-secondary" type="submit">Request OLGA guide</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($decision === 'pending'): ?>
            <div class="modal fade" id="rejectPackage<?= (int) $order['id'] ?>" tabindex="-1" aria-labelledby="rejectPackageTitle<?= (int) $order['id'] ?>" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <div class="modal-header">
                      <h2 class="modal-title h5" id="rejectPackageTitle<?= (int) $order['id'] ?>">Reject <?= e((string) $order['order_code']) ?></h2>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <label class="form-label" for="rejectReason<?= (int) $order['id'] ?>">Reason</label>
                      <textarea class="form-control" id="rejectReason<?= (int) $order['id'] ?>" name="reason" rows="3" required placeholder="Why are you rejecting this booking?"></textarea>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button class="btn btn-outline-danger" type="submit">Reject</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <div class="modal fade" id="pkgOrder<?= (int) $order['id'] ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h2 class="modal-title h5 mb-0">Booking <?= e((string) $order['order_code']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="small text-muted">Tourist</div>
                    <div class="mb-2"><?= e(full_name($order)) ?><?php if (trim((string) ($order['phone'] ?? '')) !== ''): ?> · <?= e((string) $order['phone']) ?><?php endif; ?></div>
                    <div class="small text-muted">Package</div>
                    <div class="mb-2"><?= e((string) $order['package_name']) ?><?= $days > 0 ? ' · ' . e(package_days_label($days)) : '' ?></div>
                    <div class="small text-muted">Tour date</div>
                    <div class="mb-2"><?= $when !== '' ? e(format_date($when)) : '—' ?></div>
                    <div class="small text-muted">Guests</div>
                    <div class="mb-2"><?= $pax > 0 ? e(package_pax_label($pax)) : '—' ?></div>
                    <div class="small text-muted">Amount</div>
                    <div class="mb-2"><?= e(format_money((float) $order['amount'])) ?></div>
                    <div class="small text-muted">Payment</div>
                    <div class="mb-2">
                      <?= e($statusLabel((string) $order['payment_status'])) ?>
                      <?php if (trim((string) ($order['payment_reference'] ?? '')) !== ''): ?>
                        · Ref <?= e((string) $order['payment_reference']) ?>
                      <?php endif; ?>
                    </div>
                    <?php
                      $party = json_decode((string) ($order['package_party_notes'] ?? ''), true);
                      if (is_array($party) && $party):
                    ?>
                      <div class="small text-muted">Party</div>
                      <div>
                        <?= (int) ($party['adults'] ?? 0) ?> adults
                        · <?= (int) ($party['children'] ?? 0) ?> children
                        · <?= (int) ($party['infants'] ?? 0) ?> infants
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="glass p-4 text-muted" id="bizBookingEmpty" hidden>No bookings match this search.</div>
      <?php endif; ?>
      <?php if (!$isRooms): ?>
      <script>
      (function () {
        var input = document.getElementById('bizBookingsSearch');
        var sort = document.getElementById('bizBookingsSort');
        var list = document.getElementById('bizBookingList');
        var empty = document.getElementById('bizBookingEmpty');
        if (!input || !list) return;
        var rank = { pending: 0, approved: 1, completed: 2, cancelled: 3, rejected: 4 };
        function apply() {
          var q = input.value.trim().toLowerCase();
          var mode = sort ? sort.value : 'date-desc';
          var filter = (mode === 'pending' || mode === 'approved' || mode === 'cancelled' || mode === 'rejected') ? mode : '';
          var cards = Array.prototype.slice.call(list.querySelectorAll('[data-booking-card]'));
          var shown = 0;
          cards.forEach(function (card) {
            var text = (card.textContent || '').toLowerCase();
            var status = card.getAttribute('data-sort-status') || '';
            var hide = (q !== '' && text.indexOf(q) === -1) || (filter !== '' && status !== filter);
            card.hidden = hide;
            if (!hide) shown++;
          });
          cards.sort(function (a, b) {
            if (mode === 'name') return (a.getAttribute('data-sort-name') || '').localeCompare(b.getAttribute('data-sort-name') || '');
            if (mode === 'status' || filter !== '') return (rank[a.getAttribute('data-sort-status')] ?? 9) - (rank[b.getAttribute('data-sort-status')] ?? 9);
            var ad = a.getAttribute('data-sort-date') || '';
            var bd = b.getAttribute('data-sort-date') || '';
            return mode === 'date-asc' ? ad.localeCompare(bd) : bd.localeCompare(ad);
          });
          cards.forEach(function (card) { list.appendChild(card); });
          if (empty) empty.hidden = shown > 0;
        }
        input.addEventListener('input', apply);
        if (sort) sort.addEventListener('change', apply);
      })();
      </script>
      <div class="modal fade stay-ride-modal" id="pkgFinalizeModal" tabindex="-1" aria-labelledby="pkgFinalizeTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
          <form method="post" class="modal-content itin-book-review-card" id="pkgFinalizeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="pkgFinalizeAction" value="accept">
            <input type="hidden" name="order_id" id="pkgFinalizeOrder" value="">
            <div class="modal-header border-0 pb-0">
              <div class="stay-ride-head">
                <button type="button" class="stay-step-back" id="pkgFinalizeBack" hidden>Back</button>
                <div class="stay-ride-title-row">
                  <h2 class="modal-title h5 mb-0" id="pkgFinalizeTitle">Where will they stay?</h2>
                  <label class="pick-radio" id="pkgSkipWrap">
                    <input type="checkbox" id="pkgSkipNeed">
                    <span id="pkgSkipLabel">I don’t need accommodation</span>
                  </label>
                  <label class="pick-search" id="pkgFinalizeSearch">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="pkgFinalizeQuery" placeholder="Search a place" aria-label="Search a place" autocomplete="off">
                  </label>
                </div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php
              $pkgPhoto = static function (?string $path): string {
                  $path = trim((string) $path);
                  return $path !== '' ? APP_URL . '/uploads/' . ltrim($path, '/') : '';
              };
              $pkgPlaces = static function (array $rows) use ($finalizeOffers, $pkgPhoto): array {
                  $out = [];
                  foreach ($rows as $place) {
                      $offers = [];
                      foreach ($finalizeOffers[(int) $place['id']] ?? [] as $offer) {
                          $offers[] = [
                              'id' => (int) $offer['id'],
                              'name' => (string) $offer['name'],
                              'price' => round((float) ($offer['price'] ?? 0), 2),
                              'capacity' => (int) ($offer['capacity'] ?? 0),
                              'fee' => round((float) ($offer['extra_guest_fee'] ?? 0), 2),
                              'extra' => (int) ($offer['extra_guest_limit'] ?? 0),
                              'units' => ($offer['unit_count'] ?? '') === '' || $offer['unit_count'] === null ? null : (int) $offer['unit_count'],
                              'image' => $pkgPhoto($offer['image_path'] ?? ''),
                          ];
                      }
                      $out[] = [
                          'id' => (int) $place['id'],
                          'name' => (string) $place['name'],
                          'address' => (string) ($place['address'] ?? ''),
                          'blurb' => mb_substr(trim((string) ($place['description'] ?? '')), 0, 160),
                          'cover' => $pkgPhoto($place['cover_image'] ?? ''),
                          'rating' => round((float) ($place['avg_rating'] ?? 0), 2),
                          'reviews' => (int) ($place['review_count'] ?? 0),
                          'kicker' => (string) ($place['type_name'] ?? ''),
                          'offers' => $offers,
                      ];
                  }
                  return $out;
              };
              $pkgGuideJson = [];
              $guideShown = false;
              foreach ($finalizeGuides as $guide) {
                  if ((int) $guide['inhouse_active'] !== 1 || (string) $guide['status'] !== 'active' || (string) $guide['approval_status'] !== 'approved') {
                      continue;
                  }
                  $guideShown = true;
                  $avatar = trim((string) ($guide['avatar'] ?? ''));
                  $pkgGuideJson[] = [
                      'id' => (int) $guide['guide_id'],
                      'name' => trim($guide['first_name'] . ' ' . $guide['last_name']),
                      'photo' => $avatar !== '' ? APP_URL . '/uploads/' . ltrim($avatar, '/') : '',
                      'system' => false,
                  ];
              }
              $pkgSystemGuideJson = [];
              $sysRows = db()->query(
                      "SELECT g.id, g.languages, g.hourly_rate, g.license_number, g.avg_rating,
                              (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.guide_id = g.id) AS review_count,
                              u.first_name, u.last_name, u.avatar, u.phone
                       FROM tour_guide_profiles g
                       JOIN users u ON u.id = g.user_id
                       WHERE g.approval_status='approved' AND u.status='active'
                         AND g.guide_type <> 'in_house'
                         AND " . guide_profile_complete_sql('g', 'u') . "
                       ORDER BY g.avg_rating DESC"
                  )->fetchAll() ?: [];
                  foreach ($sysRows as $guide) {
                      $avatar = trim((string) ($guide['avatar'] ?? ''));
                      $pkgSystemGuideJson[] = [
                          'id' => (int) $guide['id'],
                          'name' => trim($guide['first_name'] . ' ' . $guide['last_name']),
                          'photo' => $avatar !== '' ? APP_URL . '/uploads/' . ltrim($avatar, '/') : '',
                          'phone' => trim((string) ($guide['phone'] ?? '')) ?: '—',
                          'languages' => trim((string) ($guide['languages'] ?? '')) ?: '—',
                          'rate' => format_money($guide['hourly_rate'] ?? 0),
                          'license' => trim((string) ($guide['license_number'] ?? '')) ?: '—',
                          'rating' => round((float) ($guide['avg_rating'] ?? 0), 2),
                          'reviews' => (int) ($guide['review_count'] ?? 0),
                          'system' => true,
                      ];
                      $guideShown = true;
                  }
            ?>
            <script type="application/json" id="pkgStayCatalog"><?= json_encode($pkgPlaces($finalizeHotels), JSON_UNESCAPED_UNICODE) ?></script>
            <script type="application/json" id="pkgRideCatalog"><?= json_encode($pkgPlaces($finalizeRides), JSON_UNESCAPED_UNICODE) ?></script>
            <script type="application/json" id="pkgGuideCatalog"><?= json_encode($pkgGuideJson, JSON_UNESCAPED_UNICODE) ?></script>
            <script type="application/json" id="pkgSystemGuideCatalog"><?= json_encode($pkgSystemGuideJson, JSON_UNESCAPED_UNICODE) ?></script>
            <input type="hidden" name="stay_pick" id="pkgStayPick" value="none">
            <input type="hidden" name="ride_pick" id="pkgRidePick" value="none">
            <div class="modal-body">
              <div data-finalize-step="stay">
                <div id="pkgStayStage"></div>
              </div>
              <div data-finalize-step="ride" hidden>
                <div id="pkgRideStage"></div>
              </div>
              <div data-finalize-step="guide" hidden>
                <div id="pkgGuideStage"></div>
              </div>
              <div data-finalize-step="confirm" hidden>
                <div class="small text-muted">Accommodation</div>
                <div class="mb-2" id="pkgConfirmStay">—</div>
                <div class="small text-muted">Transportation</div>
                <div class="mb-2" id="pkgConfirmRide">—</div>
                <div class="small text-muted">Tour guide</div>
                <div id="pkgConfirmGuide">—</div>
              </div>
            </div>
            <div class="stay-pop" id="pkgGuestPop" data-stay-pop="closed">
              <button type="button" class="stay-pop__scrim" data-pkg-guest-close aria-label="Minimize"></button>
              <section class="stay-pop__card" aria-label="Place the guests">
                <div class="stay-build__tray-head">
                  <div class="stay-pop__kicker" id="pkgGuestKicker">Your stay</div>
                  <button type="button" class="stay-pop__min" data-pkg-guest-close>Minimize</button>
                </div>
                <div class="stay-pop__body" id="pkgGuestBody"></div>
                <div class="stay-accommodate" id="pkgGuestHold">
                  <div class="stay-accommodate__fit">
                    <strong id="pkgGuestLeft">0</strong>
                    <span>guests still to accommodate</span>
                  </div>
                  <strong class="stay-accommodate__total" id="pkgGuestTotal">₱0.00</strong>
                </div>
                <div class="stay-pop__actions">
                  <button type="button" class="stay-build__another" id="pkgGuestAnother">Add another place</button>
                  <button type="button" class="stay-pop__done" id="pkgGuestDone">Done</button>
                </div>
              </section>
            </div>
            <p class="small text-danger mb-0 px-3" id="pkgFinalizeError" hidden></p>
            <div class="modal-footer stay-footer border-0 pt-0">
              <div class="stay-view stay-view--bar" id="pkgFinalizeBar">
                <span class="stay-view__kicker" id="pkgBarKicker">Your stay</span>
                <strong id="pkgBarTotal">₱0.00</strong>
                <small id="pkgBarCount">Nothing added yet</small>
                <button type="button" class="stay-view__open" id="pkgBarView" hidden>View</button>
              </div>
              <button type="button" class="btn btn-ct" id="pkgFinalizeNext">Continue to transportation</button>
              <button type="submit" class="btn btn-ct" id="pkgFinalizeSave" hidden<?= $guideShown ? '' : ' disabled' ?>>Finalize booking</button>
            </div>
          </form>
        </div>
      </div>
      <div class="modal fade" id="pkgNoGuideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title h5">No in-house tour guide</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">No in-house tour guide is available on the selected date.</div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-ct" id="pkgNoGuideRequest" hidden>Request tour guide</button>
            </div>
          </div>
        </div>
      </div>
      <?php
      $extraScripts = <<<'JS'
      <script>
      (function () {
        var modalEl = document.getElementById('pkgFinalizeModal');
        var form = document.getElementById('pkgFinalizeForm');
        if (!modalEl || !form || !window.bootstrap) return;
        document.body.appendChild(modalEl);
        var steps = ['stay', 'ride', 'guide', 'confirm'];
        var titles = {
          stay: 'Where will they stay?',
          ride: 'How will the group travel?',
          guide: 'Which in-house guide?',
          confirm: 'Confirm this booking'
        };
        var nextLabels = {
          stay: 'Continue to transportation',
          ride: 'Continue to in-house guide',
          guide: 'Continue to confirmation'
        };
        var skipLabels = {
          stay: 'I don’t need accommodation',
          ride: 'I don’t need transportation'
        };
        var backLabels = {
          ride: 'Back to accommodation',
          guide: 'Back to transportation',
          confirm: 'Back to in-house guide'
        };
        var index = 0;
        var cursor = { stay: 0, ride: 0, guide: 0 };
        var browsing = { stay: true, ride: true, guide: true };
        var chosen = { stay: null, ride: null, guide: null };
        var skipped = { stay: false, ride: false };
        function readJson(id) {
          try { return JSON.parse(document.getElementById(id).textContent || '[]'); } catch (err) { return []; }
        }
        var houseGuides = readJson('pkgGuideCatalog');
        var systemGuideList = readJson('pkgSystemGuideCatalog');
        var catalogs = { stay: readJson('pkgStayCatalog'), ride: readJson('pkgRideCatalog'), guide: houseGuides.slice() };
        var systemGuides = false;
        var orderExclusive = false;
        var orderParty = 1;
        var guestDraft = null;
        var busyGuides = {};
        function applyGuideLabels() {
          titles.guide = systemGuides ? 'Which tour guide?' : 'Which in-house guide?';
          backLabels.confirm = systemGuides ? 'Back to tour guide' : 'Back to in-house guide';
        }
        applyGuideLabels();
        function esc(value) {
          return String(value || '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
          });
        }
        function money(n) {
          return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function guestCap(offer) {
          return Math.max(1, Number(offer && offer.capacity) || 1);
        }
        function guestSum(draft) {
          return (draft.guests || []).reduce(function (sum, n) { return sum + Number(n || 0); }, 0);
        }
        function guestPrice(draft) {
          var cap = guestCap(draft.offer);
          var base = Number(draft.offer.price || 0) * draft.guests.length;
          var extraFee = Number(draft.offer.fee || 0);
          var extra = 0;
          draft.guests.forEach(function (count) {
            var over = Math.max(0, Number(count) - cap);
            if (draft.offer.extra > 0) over = Math.min(over, Number(draft.offer.extra));
            extra += over;
          });
          return base + extra * extraFee;
        }
        function renderGuestPop() {
          var draft = guestDraft;
          var pop = document.getElementById('pkgGuestPop');
          var body = document.getElementById('pkgGuestBody');
          if (!draft || !pop || !body) return;
          var noun = draft.kind === 'ride' ? 'vehicle' : 'room';
          var cap = guestCap(draft.offer);
          document.getElementById('pkgGuestKicker').textContent = draft.kind === 'ride' ? 'Your ride' : 'Your stay';
          var placed = guestSum(draft);
          var left = Math.max(0, orderParty - placed);
          var roomMax = cap + Math.max(0, Number(draft.offer.extra) || 0);
          var step = function (kind, dir, disabled, index) {
            return '<button type="button" class="stay-step" data-guest-step="' + kind + '" data-guest-dir="' + dir + '"'
              + (index === undefined ? '' : ' data-guest-index="' + index + '"')
              + (disabled ? ' disabled' : '') + '>' + (dir < 0 ? '−' : '+') + '</button>';
          };
          var html = '<article class="stay-line"><div class="stay-line__top"><div><strong>' + esc(draft.place.name + ' · ' + draft.offer.name) + '</strong></div>'
            + '<button type="button" class="stay-line__remove" data-pkg-guest-remove>Remove</button></div><div class="stay-line__steps">';
          html += '<div class="stay-line__step"><span>' + (noun === 'vehicle' ? 'Vehicles' : 'Rooms') + '</span>'
            + step('units', -1, draft.guests.length <= 1)
            + '<strong>' + draft.guests.length + '</strong>'
            + step('units', 1, left < 1) + '</div>';
          draft.guests.forEach(function (count, index) {
            html += '<div class="stay-line__step"><span>' + (noun === 'vehicle' ? 'Vehicle ' : 'Room ') + (index + 1) + ', guest</span>'
              + step('guest', -1, count <= 1, index)
              + '<strong>' + count + '</strong>'
              + step('guest', 1, count >= roomMax, index) + '</div>';
          });
          var hint = noun === 'vehicle'
            ? (cap + (cap === 1 ? ' seat' : ' seats') + '. It cannot take more than that.')
            : (cap + ' included in the room price.' + (Number(draft.offer.fee) > 0 ? ' More than that is ' + money(draft.offer.fee) + ' each.' : ' Add another room for more people.'));
          var unitPrice = Number(draft.offer.price || 0);
          html += '</div><p class="stay-line__hint">' + hint + '</p><p class="stay-line__math">'
            + draft.guests.length + ' × ' + money(unitPrice) + ' = ' + money(guestPrice(draft)) + '</p></article>';
          body.innerHTML = html;
          document.getElementById('pkgGuestLeft').textContent = String(left);
          document.getElementById('pkgGuestTotal').textContent = money(guestPrice(draft));
          pop.setAttribute('data-stay-pop', 'open');
        }
        function openGuestPop(kind, place, offer) {
          var cap = guestCap(offer);
          guestDraft = { kind: kind, place: place, offer: offer, guests: [Math.max(1, Math.min(cap, orderParty))] };
          renderGuestPop();
        }
        function closeGuestPop() {
          var pop = document.getElementById('pkgGuestPop');
          if (pop) pop.setAttribute('data-stay-pop', 'closed');
          guestDraft = null;
        }
        function filtered(kind) {
          var q = (document.getElementById('pkgFinalizeQuery').value || '').trim().toLowerCase();
          return catalogs[kind].filter(function (item) {
            return !q || String(item.name || '').toLowerCase().indexOf(q) !== -1;
          });
        }
        function placeCard(place) {
          var photo = place.cover ? ' style="background-image:url(' + place.cover + ')"' : '';
          return '<button type="button" class="pick-spot" data-pick-card="' + place.id + '">'
            + '<span class="pick-spot__photo' + (place.cover ? '' : ' is-empty') + '"' + photo + '><span class="pick-spot__shade"></span><span class="pick-spot__kicker">' + (place.kicker || '') + '</span></span>'
            + '<span class="pick-spot__copy"><span class="pick-spot__name">' + esc(place.name) + '</span>'
            + '<span class="pick-spot__rating">' + Number(place.rating || 0).toFixed(2) + ' (' + (place.reviews || 0) + ')</span>'
            + (place.address ? '<span class="pick-spot__place">' + esc(place.address) + '</span>' : '')
            + (place.blurb ? '<span class="pick-spot__blurb">' + esc(place.blurb) + '</span>' : '')
            + '</span></button>';
        }
        function offerCard(place, offer, kind) {
          var picked = chosen[kind] && chosen[kind].offerId === offer.id;
          var photo = offer.image ? ' style="background-image:url(' + offer.image + ')"' : '';
          var cap = offer.capacity > 0 ? ' · ' + offer.capacity + ' guest' + (offer.capacity === 1 ? '' : 's') : '';
          var fee = offer.fee > 0 ? '<small>Extra guest ' + money(offer.fee) + (offer.extra > 0 ? ' · up to ' + offer.extra : '') + '</small>' : '<small>No extra-guest fee</small>';
          var stock = offer.units === null ? '' : '<small>' + offer.units + ' listed</small>';
          return '<article class="stay-room stay-room--wide' + (picked ? ' is-chosen' : '') + '">'
            + '<span class="stay-room__hero' + (offer.image ? '' : ' is-empty') + '"' + photo + '></span>'
            + '<span class="stay-room__body"><strong>' + esc(offer.name) + '</strong><small>' + money(offer.price) + (kind === 'stay' ? ' / night' : '') + cap + '</small>' + fee + stock + '</span>'
            + '<button type="button" class="stay-room__add" data-add-offer="' + offer.id + '" data-add-place="' + place.id + '" data-add-kind="' + kind + '">' + (picked ? 'Added' : (kind === 'stay' ? 'Add room' : 'Add vehicle')) + '</button>'
            + '</article>';
        }
        function guideStars(entry) {
          var count = Number(entry.reviews || 0);
          var avg = Number(entry.rating || 0);
          if (!(count > 0)) return '—';
          var rounded = Math.round(avg * 2) / 2;
          var icons = '';
          for (var i = 1; i <= 5; i++) {
            var icon = 'bi-star';
            if (rounded >= i) icon = 'bi-star-fill';
            else if (rounded >= i - 0.5) icon = 'bi-star-half';
            icons += '<i class="bi ' + icon + '" aria-hidden="true"></i>';
          }
          return '<span class="ct-rating ct-rating--sm"><span class="ct-rating__stars">' + icons + '</span> <span class="ct-rating__num">' + avg.toFixed(2) + '</span> <span class="ct-rating__count">(' + count + ')</span></span>';
        }
        function paint(kind) {
          var stage = document.getElementById(kind === 'stay' ? 'pkgStayStage' : (kind === 'ride' ? 'pkgRideStage' : 'pkgGuideStage'));
          if (!stage) return;
          var list = filtered(kind);
          if (!list.length) {
            stage.innerHTML = '<p class="stay-ride-empty">Nothing matches that search.</p>';
            return;
          }
          if (cursor[kind] >= list.length) cursor[kind] = 0;
          var item = list[cursor[kind]];
          var grid = list.map(function (entry, at) {
            if (kind === 'guide') {
              var photo = entry.photo ? ' style="background-image:url(' + entry.photo + ')"' : '';
              var on = chosen.guide && chosen.guide.id === entry.id;
              var photoHtml = '<span class="pick-spot__photo' + (entry.photo ? '' : ' is-empty') + '"' + photo + '><span class="pick-spot__kicker">' + (entry.system ? 'Tour guide' : 'In-house guide') + '</span></span>';
              if (!entry.system) {
                return '<button type="button" class="pick-spot pick-spot--guide pick-spot--house' + (on ? ' is-selected' : '') + '" data-pick-guide="' + entry.id + '" data-pick-at="' + at + '">'
                  + photoHtml
                  + '<span class="pick-spot__copy"><span class="pick-spot__name">' + esc(entry.name) + '</span></span></button>';
              }
              var stars = guideStars(entry);
              return '<article class="pick-spot pick-spot--guide' + (on ? ' is-selected' : '') + '" data-pick-guide="' + entry.id + '" data-pick-at="' + at + '">'
                + photoHtml
                + '<span class="pick-spot__copy"><span class="pick-spot__name">' + esc(entry.name) + '</span>'
                + '<span class="pick-spot__rating">' + stars + '</span>'
                + '<ul class="pick-spot__facts">'
                + '<li><span>Phone</span><strong>' + esc(entry.phone) + '</strong></li>'
                + '<li><span>Daily rate</span><strong>' + esc(entry.rate) + '</strong></li>'
                + '<li class="is-languages" title="' + esc(entry.languages) + '"><span>Languages</span><strong>' + esc(entry.languages) + '</strong></li>'
                + '</ul></span></article>';
            }
            return '<button type="button" class="pick-spot" data-pick-at="' + at + '">'
              + '<span class="pick-spot__photo' + (entry.cover ? '' : ' is-empty') + '"' + (entry.cover ? ' style="background-image:url(' + entry.cover + ')"' : '') + '><span class="pick-spot__shade"></span><span class="pick-spot__kicker">' + esc(entry.kicker || '') + '</span></span>'
              + '<span class="pick-spot__copy"><span class="pick-spot__name">' + esc(entry.name) + '</span>'
              + '<span class="pick-spot__rating">' + Number(entry.rating || 0).toFixed(2) + ' (' + (entry.reviews || 0) + ')</span>'
              + (entry.address ? '<span class="pick-spot__place">' + esc(entry.address) + '</span>' : '')
              + (entry.blurb ? '<span class="pick-spot__blurb">' + esc(entry.blurb) + '</span>' : '')
              + '</span></button>';
          }).join('');
          var offers = kind === 'guide' ? '' : (item.offers || []).map(function (offer) { return offerCard(item, offer, kind); }).join('');
          stage.innerHTML = '<div class="pick-board' + (browsing[kind] ? ' is-browsing' : '') + '">'
            + '<div class="pick-row pick-row--places">' + grid + '</div>'
            + '<div class="pick-focus pick-focus--stay"><div class="pick-focus__stage"><div class="pick-focus__slot pick-focus__slot--center" data-slot="center">'
            + (kind === 'guide' ? grid : placeCard(item))
            + '</div></div></div>'
            + (kind === 'guide' ? '' : '<div class="stay-build"><div class="pick-shelf"><div class="pick-shelf__label">' + (kind === 'stay' ? 'Rooms' : 'Vehicles') + '</div><div class="pick-row">' + (offers || '<p class="stay-ride-empty">Nothing is listed at this place yet.</p>') + '</div></div></div>')
            + '</div>';
        }
        function showError(message) {
          var el = document.getElementById('pkgFinalizeError');
          if (!el) return;
          el.textContent = message || '';
          el.hidden = !message;
        }
        function summary(kind) {
          var pick = chosen[kind];
          if (pick) return pick.label;
          if (skipped[kind]) return 'Not needed';
          return 'Not chosen';
        }
        function show(i) {
          if (steps[i] === 'confirm' && !(chosen.guide && chosen.guide.id)) {
            showError('Choose an in-house tour guide first.');
            return;
          }
          showError('');
          index = i;
          var step = steps[index];
          steps.forEach(function (name, n) {
            var pane = form.querySelector('[data-finalize-step="' + name + '"]');
            if (pane) pane.hidden = n !== index;
          });
          document.getElementById('pkgFinalizeTitle').textContent = titles[step];
          modalEl.classList.toggle('is-confirm', step === 'confirm');
          var onSelection = step !== 'confirm' && browsing[step];
          document.getElementById('pkgFinalizeBack').hidden = onSelection && index === 0;
          document.getElementById('pkgFinalizeBack').textContent = onSelection ? (backLabels[step] || 'Back') : 'Back to selection';
          document.getElementById('pkgFinalizeNext').hidden = step === 'confirm';
          document.getElementById('pkgFinalizeNext').textContent = nextLabels[step] || 'Continue';
          document.getElementById('pkgFinalizeSave').hidden = step !== 'confirm';
          var skipWrap = document.getElementById('pkgSkipWrap');
          var search = document.getElementById('pkgFinalizeSearch');
          var bar = document.getElementById('pkgFinalizeBar');
          skipWrap.hidden = step === 'guide' || step === 'confirm';
          search.hidden = step === 'confirm';
          bar.hidden = step === 'confirm';
          document.getElementById('pkgSkipLabel').textContent = skipLabels[step] || '';
          document.getElementById('pkgSkipNeed').checked = step === 'stay' ? !!skipped.stay : (step === 'ride' ? !!skipped.ride : false);
          if (step === 'stay' || step === 'ride' || step === 'guide') paint(step);
          if (step === 'confirm') {
            document.getElementById('pkgConfirmStay').textContent = summary('stay');
            document.getElementById('pkgConfirmRide').textContent = summary('ride');
            document.getElementById('pkgConfirmGuide').textContent = summary('guide');
          }
          var current = step === 'stay' ? chosen.stay : chosen.ride;
          var totalEl = document.getElementById('pkgBarTotal');
          document.getElementById('pkgBarKicker').textContent = step === 'guide' ? (systemGuides ? 'Tour guide' : 'In-house guide') : (step === 'ride' ? 'Your ride' : 'Your stay');
          if (step === 'guide') {
            var showRate = !!(chosen.guide && chosen.guide.system && chosen.guide.rate);
            totalEl.hidden = !showRate;
            totalEl.textContent = showRate ? chosen.guide.rate : '';
            document.getElementById('pkgBarCount').textContent = chosen.guide ? chosen.guide.label : 'Pick a guide';
          } else {
            totalEl.hidden = false;
            totalEl.textContent = current ? money(current.price) : '₱0.00';
            document.getElementById('pkgBarCount').textContent = current ? current.offerName : 'Nothing added yet';
          }
          var viewBtn = document.getElementById('pkgBarView');
          if (viewBtn) viewBtn.hidden = step === 'guide' || step === 'confirm' || !current;
          document.getElementById('pkgStayPick').value = chosen.stay ? (chosen.stay.placeId + ':' + chosen.stay.offerId) : (skipped.stay ? 'none' : '');
          document.getElementById('pkgRidePick').value = chosen.ride ? (chosen.ride.placeId + ':' + chosen.ride.offerId) : (skipped.ride ? 'none' : '');
          var guideInput = form.querySelector('input[name="guide_id"]');
          if (!guideInput) {
            guideInput = document.createElement('input');
            guideInput.type = 'hidden';
            guideInput.name = 'guide_id';
            form.appendChild(guideInput);
          }
          guideInput.value = chosen.guide ? String(chosen.guide.id) : '';
        }
        form.addEventListener('click', function (event) {
          var picked = event.target.closest('[data-pick-at]');
          if (picked && !picked.hasAttribute('data-pick-guide')) {
            var stepNow = steps[index];
            cursor[stepNow] = Number(picked.getAttribute('data-pick-at')) || 0;
            browsing[stepNow] = false;
            document.getElementById('pkgFinalizeBack').hidden = false;
            document.getElementById('pkgFinalizeBack').textContent = 'Back to selection';
            paint(stepNow);
            return;
          }
          var add = event.target.closest('[data-add-offer]');
          if (add) {
            var kind = add.getAttribute('data-add-kind');
            var placeId = Number(add.getAttribute('data-add-place'));
            var offerId = Number(add.getAttribute('data-add-offer'));
            var place = catalogs[kind].filter(function (item) { return item.id === placeId; })[0];
            var offer = place && (place.offers || []).filter(function (item) { return item.id === offerId; })[0];
            if (!place || !offer) return;
            openGuestPop(kind, place, offer);
          }
          var guideBtn = event.target.closest('[data-pick-guide]');
          if (guideBtn) {
            var guideId = Number(guideBtn.getAttribute('data-pick-guide'));
            var guide = catalogs.guide.filter(function (item) { return item.id === guideId; })[0];
            if (!guide) return;
            chosen.guide = { id: guide.id, label: guide.name, system: !!guide.system, rate: guide.rate || '' };
            paint('guide');
            show(index);
          }
        });
        document.getElementById('pkgSkipNeed').addEventListener('change', function () {
          var step = steps[index];
          if (step !== 'stay' && step !== 'ride') return;
          skipped[step] = this.checked;
          if (this.checked) chosen[step] = null;
          show(index);
        });
        document.getElementById('pkgFinalizeQuery').addEventListener('input', function () {
          var step = steps[index];
          cursor[step] = 0;
          if (step === 'stay' || step === 'ride' || step === 'guide') paint(step);
        });
        document.querySelectorAll('[data-pkg-finalize]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            orderExclusive = btn.getAttribute('data-exclusive') === '1';
            orderParty = Math.max(1, Number(btn.getAttribute('data-pax')) || 1);
            busyGuides = {};
            String(btn.getAttribute('data-busy') || '').split(',').forEach(function (id) {
              if (id) busyGuides[id] = true;
            });
            var guideOnly = btn.hasAttribute('data-guide-only');
            systemGuides = guideOnly;
            catalogs.guide = guideOnly ? systemGuideList.filter(function (guide) { return !busyGuides[String(guide.id)]; }) : houseGuides.filter(function (guide) {
              return !busyGuides[String(guide.id)];
            });
            nextLabels.ride = catalogs.guide.length ? 'Continue to in-house guide' : 'Continue to tour guide';
            applyGuideLabels();
            var actionInput = document.getElementById('pkgFinalizeAction');
            if (actionInput) actionInput.value = guideOnly ? 'resubmit_system_guide' : 'accept';
            var saveBtn = document.getElementById('pkgFinalizeSave');
            if (saveBtn) saveBtn.textContent = guideOnly ? 'Send request' : 'Finalize booking';
            form.querySelector('#pkgFinalizeOrder').value = btn.getAttribute('data-order-id') || '';
            chosen.stay = null;
            chosen.ride = null;
            chosen.guide = null;
            skipped.stay = false;
            skipped.ride = false;
            cursor.stay = 0;
            cursor.ride = 0;
            cursor.guide = 0;
            browsing.stay = true;
            browsing.ride = true;
            browsing.guide = true;
            document.getElementById('pkgFinalizeQuery').value = '';
            show(guideOnly ? 2 : 0);
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
          });
        });
        var openGuide = new URLSearchParams(window.location.search).get('guide');
        if (openGuide) {
          var reopen = document.querySelector('[data-guide-only][data-order-id="' + openGuide + '"]');
          if (reopen) reopen.click();
        }
        document.getElementById('pkgGuestPop').addEventListener('click', function (event) {
          if (event.target.closest('[data-pkg-guest-close]')) {
            closeGuestPop();
            return;
          }
          if (event.target.closest('[data-pkg-guest-remove]')) {
            if (guestDraft) {
              chosen[guestDraft.kind] = null;
              paint(guestDraft.kind);
              show(index);
            }
            closeGuestPop();
            return;
          }
          var stepBtn = event.target.closest('[data-guest-step]');
          if (!stepBtn || !guestDraft) return;
          var dir = Number(stepBtn.getAttribute('data-guest-dir')) || 0;
          if (stepBtn.getAttribute('data-guest-step') === 'units') {
            if (dir < 0 && guestDraft.guests.length > 1) guestDraft.guests.pop();
            if (dir > 0 && guestSum(guestDraft) < orderParty) {
              var roomCap = guestCap(guestDraft.offer);
              var still = orderParty - guestSum(guestDraft);
              guestDraft.guests.push(Math.max(1, Math.min(roomCap, still)));
            }
          } else {
            var at = Number(stepBtn.getAttribute('data-guest-index'));
            var cap = guestCap(guestDraft.offer) + Math.max(0, Number(guestDraft.offer.extra) || 0);
            var next = Number(guestDraft.guests[at] || 1) + dir;
            if (next >= 1 && next <= cap) guestDraft.guests[at] = next;
          }
          renderGuestPop();
        });
        document.getElementById('pkgGuestAnother').addEventListener('click', function () {
          if (!guestDraft) return;
          browsing[guestDraft.kind] = true;
          closeGuestPop();
          show(index);
        });
        document.getElementById('pkgGuestDone').addEventListener('click', function () {
          if (!guestDraft) return;
          if (guestSum(guestDraft) < orderParty) {
            showError('Place every guest before continuing.');
            return;
          }
          var draft = guestDraft;
          var guestNote = draft.guests.map(function (count, index) {
            return (draft.kind === 'ride' ? 'Vehicle ' : 'Room ') + (index + 1) + ': ' + count;
          }).join(', ');
          chosen[draft.kind] = {
            placeId: draft.place.id,
            offerId: draft.offer.id,
            price: guestPrice(draft),
            offerName: draft.offer.name + ' · ' + guestNote,
            label: draft.place.name + ' · ' + draft.offer.name + ' · ' + guestNote
          };
          skipped[draft.kind] = false;
          document.getElementById('pkgSkipNeed').checked = false;
          closeGuestPop();
          showError('');
          paint(draft.kind);
          show(index);
        });
        var barView = document.getElementById('pkgBarView');
        if (barView) {
          barView.addEventListener('click', function () {
            var step = steps[index];
            var pick = chosen[step];
            if (!pick || (step !== 'stay' && step !== 'ride')) return;
            var place = catalogs[step].filter(function (item) { return item.id === pick.placeId; })[0];
            var offer = place && (place.offers || []).filter(function (item) { return item.id === pick.offerId; })[0];
            if (place && offer) openGuestPop(step, place, offer);
          });
        }
        document.getElementById('pkgFinalizeNext').addEventListener('click', function () {
          var step = steps[index];
          if ((step === 'stay' || step === 'ride') && !chosen[step] && !skipped[step]) {
            showError(step === 'stay'
              ? 'Add a room, or check I don’t need accommodation.'
              : 'Add a vehicle, or check I don’t need transportation.');
            return;
          }
          if (step === 'ride') {
            var free = houseGuides.filter(function (guide) { return !busyGuides[String(guide.id)]; });
            if (!free.length) {
              var requestBtn = document.getElementById('pkgNoGuideRequest');
              if (requestBtn) requestBtn.hidden = !orderExclusive;
              var notice = document.getElementById('pkgNoGuideModal');
              if (notice) bootstrap.Modal.getOrCreateInstance(notice).show();
              return;
            }
            systemGuides = false;
            catalogs.guide = free;
            applyGuideLabels();
          }
          show(Math.min(steps.length - 1, index + 1));
        });
        var requestGuideBtn = document.getElementById('pkgNoGuideRequest');
        if (requestGuideBtn) {
          requestGuideBtn.addEventListener('click', function () {
            if (!orderExclusive || !systemGuideList.length) return;
            systemGuides = true;
            catalogs.guide = systemGuideList.filter(function (guide) { return !busyGuides[String(guide.id)]; });
            applyGuideLabels();
            var notice = document.getElementById('pkgNoGuideModal');
            if (notice) {
              var open = bootstrap.Modal.getInstance(notice);
              if (open) open.hide();
            }
            show(steps.indexOf('guide'));
          });
        }
        document.getElementById('pkgFinalizeBack').addEventListener('click', function () {
          var step = steps[index];
          if (!browsing[step] && step !== 'confirm') {
            browsing[step] = true;
            show(index);
            return;
          }
          show(Math.max(0, index - 1));
        });
        form.addEventListener('submit', function () {
          var stay = document.getElementById('pkgStayPick');
          var ride = document.getElementById('pkgRidePick');
          function fill(pick, declinedName, bizName, svcName) {
            var declined = form.querySelector('input[name="' + declinedName + '"]');
            var biz = form.querySelector('input[name="' + bizName + '"]');
            var svc = form.querySelector('input[name="' + svcName + '"]');
            if (!declined) {
              declined = document.createElement('input');
              declined.type = 'hidden';
              declined.name = declinedName;
              form.appendChild(declined);
            }
            if (!biz) {
              biz = document.createElement('input');
              biz.type = 'hidden';
              biz.name = bizName;
              form.appendChild(biz);
            }
            if (!svc) {
              svc = document.createElement('input');
              svc.type = 'hidden';
              svc.name = svcName;
              form.appendChild(svc);
            }
            var value = pick ? pick.value : 'none';
            if (value === 'none') {
              declined.value = '1';
              biz.value = '';
              svc.value = '';
            } else {
              var parts = value.split(':');
              declined.value = '0';
              biz.value = parts[0] || '';
              svc.value = parts[1] || '';
            }
          }
          fill(stay, 'accommodation_declined', 'accommodation_business_id', 'accommodation_service_id');
          fill(ride, 'transportation_declined', 'transportation_business_id', 'transportation_service_id');
        });
      })();
      </script>
JS;
      ?>
      <?php endif; ?>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
require __DIR__ . '/../includes/footer.php';
