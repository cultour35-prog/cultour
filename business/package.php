<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_BUSINESS);
package_stops_ensure_schema();

$biz = db()->prepare(
    'SELECT b.*, bt.name AS type_name FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.user_id=? LIMIT 1'
);
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();
if (!$biz || business_catalog_mode($biz['type_name'] ?? '') !== 'packages') {
    flash('error', 'Package maker is for tour and travel businesses.');
    redirect(APP_URL . '/business/services.php');
}
if (!business_listing_is_approved($biz)) {
    flash('error', 'Packages can be built after the Tourism Office approves your business.');
    redirect(APP_URL . '/business/dashboard.php');
}

$serviceId = (int) ($_GET['id'] ?? $_POST['service_id'] ?? 0);
$pkg = false;
if ($serviceId > 0) {
    $pkgSt = db()->prepare('SELECT * FROM business_services WHERE id=? AND business_id=? LIMIT 1');
    $pkgSt->execute([$serviceId, (int) $biz['id']]);
    $pkg = $pkgSt->fetch();
}

if ($pkg) {
    $itinId = (int) ($pkg['package_itinerary_id'] ?? 0);
    $exists = false;
    if ($itinId > 0) {
        $chk = db()->prepare('SELECT id FROM itineraries WHERE id=? AND user_id=?');
        $chk->execute([$itinId, (int) $user['id']]);
        $exists = (bool) $chk->fetchColumn();
    }
    if (!$exists) {
        db()->prepare(
            'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            (int) $user['id'],
            itinerary_is_placeholder_title((string) $pkg['name']) || strcasecmp(trim((string) $pkg['name']), 'Untitled package') === 0
                ? 'Unnamed'
                : (string) $pkg['name'],
            (string) ($pkg['description'] ?? ''),
            null,
            null,
            null,
            0,
            'draft',
        ]);
        $itinId = (int) db()->lastInsertId();
        db()->prepare('UPDATE business_services SET package_itinerary_id=? WHERE id=? AND business_id=?')
            ->execute([$itinId, (int) $pkg['id'], (int) $biz['id']]);
    }
    $_GET['id'] = $itinId;
    $GLOBALS['CULTOUR_PACKAGE_MAKER'] = true;
    $GLOBALS['CULTOUR_PACKAGE_ID'] = (int) $pkg['id'];
    $GLOBALS['CULTOUR_PACKAGE_BUSINESS_ID'] = (int) $biz['id'];
    $pkgName = trim((string) $pkg['name']);
    $GLOBALS['CULTOUR_PACKAGE_NAME'] = ($pkgName === '' || strcasecmp($pkgName, 'Unnamed') === 0 || strcasecmp($pkgName, 'Untitled package') === 0)
        ? ''
        : $pkgName;
    $GLOBALS['CULTOUR_PACKAGE_PRICE'] = (string) ($pkg['price'] ?? '0');
    $GLOBALS['CULTOUR_PACKAGE_DATE_MODE'] = (string) ($pkg['package_date_mode'] ?? 'fixed') === 'tourist' ? 'tourist' : 'fixed';
    $GLOBALS['CULTOUR_PACKAGE_MADE'] = (int) ($pkg['package_made'] ?? 0) === 1 ? 1 : 0;
    $GLOBALS['CULTOUR_PACKAGE_FIXED_DATE'] = (string) ($pkg['package_fixed_date'] ?? '');
    $GLOBALS['CULTOUR_PACKAGE_CLOSE_DATE'] = (string) ($pkg['package_close_date'] ?? '');
    $GLOBALS['CULTOUR_PACKAGE_IMAGE'] = (string) ($pkg['image_path'] ?? '');
    $GLOBALS['CULTOUR_PACKAGE_INCLUSIONS'] = (string) ($pkg['package_inclusions'] ?? '');
    $GLOBALS['CULTOUR_PACKAGE_DESCRIPTION'] = (string) ($pkg['description'] ?? '');
    $GLOBALS['CULTOUR_PACKAGE_MIN_PAX'] = (int) ($pkg['package_min_pax'] ?? 0);
    require __DIR__ . '/../tourist/itineraries.php';
    return;
}

$back = $pkg ? APP_URL . '/business/package.php?id=' . (int) $pkg['id'] : APP_URL . '/business/package.php';
$days = max(1, (int) ($pkg['package_days'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (!$pkg) {
        flash('error', 'Open a package before saving it.');
        redirect(APP_URL . '/business/package.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'set_guide') {
        $guideId = (int) ($_POST['guide_id'] ?? 0);
        if ($guideId < 1) {
            db()->prepare('UPDATE business_services SET package_guide_id=NULL WHERE id=? AND business_id=?')
                ->execute([(int) $pkg['id'], (int) $biz['id']]);
            flash('success', 'Tour guide removed.');
            redirect($back);
        }
        $guide = db()->prepare(
            "SELECT g.id FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id
             WHERE g.id=? AND g.approval_status='approved' AND u.status='active' LIMIT 1"
        );
        $guide->execute([$guideId]);
        if (!$guide->fetch()) {
            flash('error', 'That tour guide is not available.');
            redirect($back);
        }
        db()->prepare('UPDATE business_services SET package_guide_id=? WHERE id=? AND business_id=?')
            ->execute([$guideId, (int) $pkg['id'], (int) $biz['id']]);
        flash('success', 'Tour guide saved on this package. No payment was taken.');
        redirect($back);
    }
    if ($action === 'set_stay' || $action === 'set_ride') {
        $column = $action === 'set_stay' ? 'package_stay_json' : 'package_ride_json';
        $declined = (int) ($_POST['offer_declined'] ?? 0) === 1;
        $offerBiz = (int) ($_POST['offer_business_id'] ?? 0);
        $offerSvc = (int) ($_POST['offer_service_id'] ?? 0);
        $mode = $action === 'set_stay' ? 'rooms' : 'vehicles';
        if ($declined) {
            $payload = json_encode(['declined' => 1], JSON_UNESCAPED_UNICODE);
        } else {
            $catalog = package_offer_catalog($mode);
            $chosenBiz = null;
            foreach ($catalog['businesses'] as $row) {
                if ((int) $row['id'] === $offerBiz) {
                    $chosenBiz = $row;
                    break;
                }
            }
            $chosenOffer = null;
            foreach ($catalog['offers'][$offerBiz] ?? [] as $offer) {
                if ((int) $offer['id'] === $offerSvc) {
                    $chosenOffer = $offer;
                    break;
                }
            }
            if (!$chosenBiz || ($offerSvc > 0 && !$chosenOffer)) {
                flash('error', $action === 'set_stay' ? 'Choose a place to stay.' : 'Choose a ride.');
                redirect($back);
            }
            $payload = json_encode([
                'declined' => 0,
                'business_id' => $offerBiz,
                'service_id' => $offerSvc,
                'business_name' => (string) $chosenBiz['name'],
                'service_name' => $chosenOffer ? (string) $chosenOffer['name'] : '',
            ], JSON_UNESCAPED_UNICODE);
        }
        db()->prepare("UPDATE business_services SET $column=? WHERE id=? AND business_id=?")
            ->execute([$payload, (int) $pkg['id'], (int) $biz['id']]);
        flash('success', $action === 'set_stay' ? 'Accommodation saved.' : 'Transportation saved.');
        redirect($back);
    }
    if ($action === 'save_package') {
        $parsed = parse_place_service_post($_POST);
        $daysParsed = package_days_from_post($_POST);
        $paxParsed = package_pax_from_post($_POST);
        if (empty($parsed['ok'])) {
            flash('error', (string) ($parsed['message'] ?? 'Check the package details.'));
        } elseif (!$daysParsed['ok']) {
            flash('error', $daysParsed['message']);
        } elseif (!$paxParsed['ok']) {
            flash('error', $paxParsed['message']);
        } else {
            $data = $parsed['data'];
            db()->prepare(
                'UPDATE business_services SET name=?, description=?, price=?, package_days=?, capacity=? WHERE id=? AND business_id=?'
            )->execute([
                $data['name'],
                $data['description'],
                $data['price'],
                $daysParsed['days'],
                $paxParsed['pax'],
                (int) $pkg['id'],
                (int) $biz['id'],
            ]);
            if ((int) $daysParsed['days'] < $days) {
                db()->prepare('DELETE FROM package_stops WHERE service_id=? AND day_number>?')
                    ->execute([(int) $pkg['id'], (int) $daysParsed['days']]);
            }
            flash('success', 'Package price and details saved.');
        }
        redirect($back);
    }

    if ($action === 'add_stop') {
        $day = (int) ($_POST['day_number'] ?? 1);
        if ($day < 1 || $day > $days) {
            $day = 1;
        }
        $key = (string) ($_POST['place_key'] ?? '');
        $destId = null;
        $bizStop = null;
        if (preg_match('/^d:(\d+)$/', $key, $m)) {
            $destId = (int) $m[1];
        } elseif (preg_match('/^b:(\d+)$/', $key, $m)) {
            $bizStop = (int) $m[1];
        }
        $visit = (int) ($_POST['visit_minutes'] ?? 60);
        if ($visit < 15) {
            $visit = 15;
        }
        if ($visit > ITIN_MAX_VISIT_MINUTES) {
            $visit = ITIN_MAX_VISIT_MINUTES;
        }
        $place = null;
        if ($destId) {
            $place = db()->prepare("SELECT id, name, opening_hours FROM destinations WHERE id=? AND status='published'");
            $place->execute([$destId]);
            $place = $place->fetch() ?: null;
            if ($place && !destination_itinerary_ready($place)) {
                $place = null;
            }
        } elseif ($bizStop && $bizStop !== (int) $biz['id']) {
            $blocked = itinerary_stop_business_blocked($bizStop);
            if ($blocked === null) {
                $place = db()->prepare(
                    'SELECT b.id, b.name, ' . business_itinerary_ready_columns() . ' FROM businesses b WHERE b.id=? AND b.approval_status=\'approved\' AND b.status=\'active\''
                );
                $place->execute([$bizStop]);
                $place = $place->fetch() ?: null;
                if ($place && !business_itinerary_ready($place)) {
                    $place = null;
                }
            }
        }
        if (!$place) {
            flash('error', 'Choose a place that can be added to a tour.');
            redirect($back);
        }
        $sort = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM package_stops WHERE service_id=? AND day_number=?');
        $sort->execute([(int) $pkg['id'], $day]);
        $next = (int) $sort->fetchColumn() + 1;
        db()->prepare(
            'INSERT INTO package_stops (service_id, day_number, sort_order, destination_id, business_id, visit_minutes) VALUES (?,?,?,?,?,?)'
        )->execute([
            (int) $pkg['id'],
            $day,
            $next,
            $destId,
            $bizStop,
            $visit,
        ]);
        flash('success', 'Stop added to day ' . $day . '.');
        redirect($back);
    }

    if ($action === 'remove_stop') {
        db()->prepare('DELETE FROM package_stops WHERE id=? AND service_id=?')
            ->execute([(int) ($_POST['stop_id'] ?? 0), (int) $pkg['id']]);
        flash('success', 'Stop removed.');
        redirect($back);
    }

    if ($action === 'move_stop') {
        $stopId = (int) ($_POST['stop_id'] ?? 0);
        $dir = (string) ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $stops = package_stops_for_service((int) $pkg['id']);
        $current = null;
        foreach ($stops as $stop) {
            if ((int) $stop['id'] === $stopId) {
                $current = $stop;
                break;
            }
        }
        if ($current) {
            $same = array_values(array_filter(
                $stops,
                static fn (array $stop): bool => (int) $stop['day_number'] === (int) $current['day_number']
            ));
            $index = 0;
            foreach ($same as $i => $stop) {
                if ((int) $stop['id'] === $stopId) {
                    $index = $i;
                    break;
                }
            }
            $swap = $index + $dir;
            if (isset($same[$swap])) {
                $moved = $same[$index];
                $same[$index] = $same[$swap];
                $same[$swap] = $moved;
                $upd = db()->prepare('UPDATE package_stops SET sort_order=? WHERE id=? AND service_id=?');
                foreach ($same as $i => $stop) {
                    $upd->execute([$i, (int) $stop['id'], (int) $pkg['id']]);
                }
            }
        }
        redirect($back);
    }
}

$stops = $pkg ? package_stops_for_service((int) $pkg['id']) : [];
if (!$pkg) {
    package_stops_ensure_schema();
    $open = db()->prepare(
        'SELECT s.id
         FROM business_services s
         LEFT JOIN itineraries i ON i.id = s.package_itinerary_id
         WHERE s.business_id=? AND s.package_made=0 AND COALESCE(s.package_awaiting, 0)=0 AND COALESCE(i.kept_as_draft, 0)=0
         ORDER BY s.id DESC LIMIT 1'
    );
    $open->execute([(int) $biz['id']]);
    $openId = (int) $open->fetchColumn();
    if ($openId < 1) {
        db()->prepare(
            'INSERT INTO business_services (business_id, name, description, price, package_days, capacity, is_active, package_made)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([(int) $biz['id'], 'Unnamed', null, 0, 1, 1, 0, 0]);
        $openId = (int) db()->lastInsertId();
    }
    redirect(APP_URL . '/business/package.php?id=' . $openId);
}
require __DIR__ . '/package_maker_body.php';
