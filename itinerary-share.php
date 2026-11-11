<?php
/**
 * Printable / shareable itinerary view (owner, suggested public copy, or admin).
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
ensure_itinerary_items_schema();

$id = (int) ($_GET['id'] ?? 0);
$fromPackage = (string) ($_GET['from'] ?? '') === 'package';
$orderId = (int) ($_GET['order'] ?? 0);
$orderRow = null;
if ($orderId > 0) {
    require_once __DIR__ . '/includes/payments.php';
    ensure_package_order_schema();
    $orderSt = db()->prepare('SELECT snapshot_itinerary_id, tourist_id, business_id, snapshot_inclusions, snapshot_description, amount, package_name FROM package_orders WHERE id=? LIMIT 1');
    $orderSt->execute([$orderId]);
    $orderRow = $orderSt->fetch();
    if ($orderRow && (int) ($orderRow['snapshot_itinerary_id'] ?? 0) > 0) {
        $id = (int) $orderRow['snapshot_itinerary_id'];
        $fromPackage = true;
    }
}
$stmt = db()->prepare('SELECT i.*, CONCAT(u.first_name,\' \',u.last_name) AS owner_name FROM itineraries i JOIN users u ON u.id=i.user_id WHERE i.id=?');
$stmt->execute([$id]);
$itin = $stmt->fetch();
$packageFallback = null;
if (!$itin && $fromPackage && $id > 0) {
    package_stops_ensure_schema();
    $pkgStmt = db()->prepare(
        'SELECT s.*, b.name AS business_name
         FROM business_services s
         JOIN businesses b ON b.id = s.business_id
         WHERE s.package_itinerary_id=? AND s.package_made=1
         LIMIT 1'
    );
    $pkgStmt->execute([$id]);
    $packageFallback = $pkgStmt->fetch() ?: null;
    if ($packageFallback) {
        $itin = [
            'id' => $id,
            'title' => (string) ($packageFallback['name'] ?? 'Package'),
            'description' => (string) ($packageFallback['description'] ?? ''),
            'start_date' => (string) ($packageFallback['package_fixed_date'] ?? ''),
            'end_date' => '',
            'meetup_time' => '08:00:00',
            'user_id' => 0,
            'owner_name' => (string) ($packageFallback['business_name'] ?? ''),
            'is_suggested' => 0,
            'package_days' => (int) ($packageFallback['package_days'] ?? 1),
        ];
    }
}
if (!$itin) {
    flash('error', 'Itinerary not found.');
    redirect(role_dashboard((int) $user['role_id']));
}

$role = (int) $user['role_id'];
$allowed = $role === ROLE_ADMIN
    || (int) $user['id'] === (int) $itin['user_id']
    || itinerary_is_published_suggested($itin);

// Tour guide may view itineraries linked to their bookings, or their own suggested drafts
if (!$allowed && $role === ROLE_TOUR_GUIDE) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([(int) $user['id']]);
    $gid = (int) $gp->fetchColumn();
    if ($gid > 0) {
        if ((int) ($itin['created_by_guide_id'] ?? 0) === $gid && (int) ($itin['is_suggested'] ?? 0) === 1) {
            $allowed = true;
        } else {
            $link = db()->prepare(
                'SELECT id FROM bookings WHERE guide_id=? AND itinerary_id=? LIMIT 1'
            );
            $link->execute([$gid, $id]);
            $allowed = (bool) $link->fetchColumn();
        }
    }
}

if (!$allowed && $orderRow && (int) $orderRow['tourist_id'] === (int) $user['id']) {
    $allowed = true;
}
if (!$allowed && $role === ROLE_BUSINESS) {
    $ownPkg = db()->prepare(
        'SELECT s.id
         FROM business_services s
         JOIN businesses b ON b.id = s.business_id
         WHERE s.package_itinerary_id=? AND s.package_made=1 AND b.user_id=?
         LIMIT 1'
    );
    $ownPkg->execute([$id, (int) $user['id']]);
    $allowed = (bool) $ownPkg->fetchColumn();
}
if (!$allowed && !$packageFallback) {
    $pkgLink = db()->prepare('SELECT id FROM business_services WHERE package_itinerary_id=? AND package_made=1 AND is_active=1 LIMIT 1');
    $pkgLink->execute([$id]);
    $allowed = (bool) $pkgLink->fetchColumn();
}
if ($packageFallback) {
    $allowed = true;
}

if (!$allowed) {
    flash('error', 'Access denied.');
    redirect(role_dashboard($role));
}

$packageBusinessName = '';
if ($fromPackage) {
    $bizNameStmt = db()->prepare(
        'SELECT b.name
         FROM business_services s
         JOIN businesses b ON b.id = s.business_id
         WHERE s.package_itinerary_id=? AND s.package_made=1
         LIMIT 1'
    );
    $bizNameStmt->execute([$id]);
    $packageBusinessName = trim((string) $bizNameStmt->fetchColumn());
}

if ($packageFallback) {
    $stops = [];
    $meetupMins = itin_time_to_minutes((string) ($itin['meetup_time'] ?? '08:00:00'));
    if ($meetupMins === null) {
        $meetupMins = 8 * 60;
    }
    $port = ormoc_port_coords();
    $byDay = [];
    foreach (package_stops_for_service((int) $packageFallback['id']) as $stop) {
        $day = max(1, (int) ($stop['day_number'] ?? 1));
        $byDay[$day][] = $stop;
    }
    ksort($byDay);
    foreach ($byDay as $day => $dayStops) {
        $prevLat = (float) $port['lat'];
        $prevLng = (float) $port['lng'];
        $prevLeave = $meetupMins;
        foreach ($dayStops as $i => $stop) {
            $visit = max(15, (int) ($stop['visit_minutes'] ?? 60));
            $lat = isset($stop['latitude']) && $stop['latitude'] !== null && $stop['latitude'] !== ''
                ? (float) $stop['latitude'] : null;
            $lng = isset($stop['longitude']) && $stop['longitude'] !== null && $stop['longitude'] !== ''
                ? (float) $stop['longitude'] : null;
            $hasCoords = $lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0);
            if ($i === 0 && $day > 1) {
                $arrive = $meetupMins;
            } else {
                $travel = $hasCoords
                    ? itin_estimate_travel_minutes($prevLat, $prevLng, $lat, $lng)
                    : 45;
                $arrive = itin_suggest_arrival($prevLeave, $travel);
            }
            $leave = itin_compute_end($arrive, $visit);
            $stops[] = [
                'id' => (int) $stop['id'],
                'stop_kind' => (int) ($stop['business_id'] ?? 0) > 0 ? 'business' : 'destination',
                'stop_name' => (string) ($stop['place_name'] ?? 'Place'),
                'location' => (string) ($stop['location'] ?? ''),
                'visit_minutes' => $visit,
                'day_number' => $day,
                'start_time' => itin_minutes_to_time($arrive),
                'end_time' => itin_minutes_to_time($leave),
            ];
            $prevLeave = $leave;
            if ($hasCoords) {
                $prevLat = $lat;
                $prevLng = $lng;
            }
        }
    }
    $reservationMap = [];
} else {
    $stops = itinerary_items_with_places($id);
    $reservationMap = reservation_itinerary_map($id);
}
$meetup = format_time_12h((string) ($itin['meetup_time'] ?? '08:00:00'));
$shareUrl = APP_URL . '/itinerary-share.php?id=' . $id;
if ($packageFallback) {
    $dayCount = max(1, (int) ($itin['package_days'] ?? 1));
    foreach ($stops as $stop) {
        $dayCount = max($dayCount, (int) ($stop['day_number'] ?? 1));
    }
    $stopsByDay = [];
    for ($d = 1; $d <= $dayCount; $d++) {
        $stopsByDay[$d] = ['day' => $d, 'date' => '', 'label' => 'Day ' . $d, 'stops' => []];
    }
    foreach ($stops as $stop) {
        $day = max(1, (int) ($stop['day_number'] ?? 1));
        $stopsByDay[$day]['stops'][] = $stop;
    }
} else {
    $dayCount = itinerary_logical_day_count($itin);
    $stopsByDay = itinerary_group_stops_by_day($stops, $itin['start_date'] ?? null, $itin['end_date'] ?? null, $id, $itin);
}
$isMultiday = $dayCount > 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($itin['title']) ?> · CulTour</title>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
  <style>
    :root { --teal:#0b5f6b; --ink:#14242a; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: Figtree, system-ui, sans-serif; color: var(--ink); background:#e8eef0; padding:1.25rem; }
    .toolbar { max-width:720px; margin:0 auto 1rem; display:flex; flex-wrap:wrap; gap:.5rem; }
    .toolbar a, .toolbar button { border:0; background:var(--teal); color:#fff; padding:.55rem 1rem; border-radius:.6rem; text-decoration:none; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; gap:.35rem; }
    .toolbar .ghost { background:#fff; color:var(--teal); border:1px solid #cfd8db; }
    .toolbar .download { background:#0e8a7a; }
    .sheet { max-width:720px; margin:0 auto; background:#fff; border-radius:1rem; padding:1.5rem; border:1px solid #d5e0e3; }
    h1 { font-family: Fraunces, Georgia, serif; font-size:1.75rem; margin:0 0 .35rem; }
    .meta { color:#5c6f76; font-size:.9rem; margin-bottom:1.25rem; }
    .desc { margin:0 0 1.15rem; color:#3d5158; font-size:.95rem; line-height:1.5; overflow-wrap:anywhere; word-break:break-word; }
    .day-head { margin:1.1rem 0 .35rem; padding:.4rem .65rem; background:#eef5f6; border-radius:.5rem; display:flex; justify-content:space-between; gap:.5rem; font-weight:700; }
    .stop { padding:.85rem 0; border-bottom:1px solid #e6ecee; }
    .stop:last-child { border-bottom:0; }
    @media print { body { background:#fff; padding:0; } .toolbar { display:none!important; } .sheet { border:0; box-shadow:none; } }
  </style>
</head>
<body>
  <div class="toolbar">
    <button type="button" onclick="window.print()">Print</button>
    <a class="download" href="<?= APP_URL ?>/itinerary-pdf.php?id=<?= (int) $id ?>">Download PDF</a>
    <button class="ghost" type="button" id="itinCopyLink" data-url="<?= e($shareUrl) ?>">Copy link</button>
    <?php if (!$fromPackage): ?>
    <a class="ghost" href="<?= e(APP_URL . '/' . ($role===ROLE_TOURIST?'tourist/itineraries.php?id='.$id:($role===ROLE_TOUR_GUIDE?'tourguide/itineraries.php?id='.$id:'admin/itineraries.php'))) ?>">Back</a>
    <?php endif; ?>
  </div>
  <article class="sheet">
    <div class="small" style="color:var(--teal);font-weight:700;letter-spacing:.04em;text-transform:uppercase">CulTour itinerary</div>
    <h1><?= e($itin['title']) ?></h1>
    <div class="meta">
      <?php if ($packageBusinessName !== ''): ?>
        <?= e($packageBusinessName) ?><br>
      <?php else: ?>
        <?= (int)($itin['is_suggested'] ?? 0) ? 'Suggested tour' : 'Personal plan' ?>
        · <?= e($itin['owner_name']) ?><br>
      <?php endif; ?>
      <?php $shareStart = trim((string) ($itin['start_date'] ?? '')); ?>
      <?php if ($shareStart !== '' && $isMultiday): ?>
        <?= (int) $dayCount ?> days · starts <?= e(format_date($shareStart)) ?><br>
      <?php elseif ($shareStart !== ''): ?>
        <?= e(format_date($shareStart)) ?><br>
      <?php endif; ?>
      Meetup <?= e($meetup) ?> at Ormoc City Port
    </div>
    <?php
      $shareDesc = trim((string) ($orderRow['snapshot_description'] ?? ''));
      if ($shareDesc === '') {
          $shareDesc = trim((string) ($itin['description'] ?? ''));
      }
      $shareInclusions = trim((string) ($orderRow['snapshot_inclusions'] ?? ''));
    ?>
    <?php if ($shareDesc !== ''): ?>
      <p class="desc"><?= nl2br(e($shareDesc)) ?></p>
    <?php endif; ?>
    <?php if ($shareInclusions !== ''): ?>
      <p class="meta">Inclusions: <?= e($shareInclusions) ?></p>
    <?php endif; ?>

    <?php foreach ($stopsByDay as $g): ?>
      <?php if ($isMultiday): ?>
        <div class="day-head">
          <span><?= e($g['label']) ?></span>
          <span style="font-weight:500;color:#6a7c83"><?= e(format_date($g['date'])) ?></span>
        </div>
      <?php endif; ?>
      <?php if ((int) $g['day'] === 1): ?>
        <div class="stop">
          <div class="small" style="color:#6a7c83">Start · <?= e($meetup) ?></div>
          <strong>Ormoc City Port</strong>
          <div class="small" style="color:#6a7c83">Meetup point</div>
        </div>
      <?php endif; ?>
      <?php foreach ($g['stops'] as $idx => $it): ?>
        <div class="stop">
          <div class="small" style="color:#6a7c83">
            Stop <?= $idx + 1 ?>
            · <?= ($it['stop_kind'] ?? '') === 'business' ? 'Business' : 'Destination' ?>
          </div>
          <strong><?= e($it['stop_name'] ?? $it['destination_name'] ?? 'Stop') ?></strong>
          <div class="small" style="color:#6a7c83"><?= e($it['location'] ?? '') ?></div>
          <div class="small">
            ETA <?= e(format_time_12h(isset($it['start_time']) ? (string)$it['start_time'] : null)) ?>
            · Leave <?= e(format_time_12h(isset($it['end_time']) ? (string)$it['end_time'] : null)) ?>
            · Stay <?= (int)($it['visit_minutes'] ?? 60) ?> min
          </div>
          <?php $stopReservation = $reservationMap[(int) ($it['id'] ?? 0)] ?? null; ?>
          <?php if ($stopReservation && $fromPackage): ?>
            <?php foreach ($stopReservation['lines'] ?? [] as $foodLine): ?>
              <?php if (!is_array($foodLine) || trim((string) ($foodLine['item_name'] ?? '')) === '') continue; ?>
              <div class="small" style="color:#0e8a7a"><?= (int) ($foodLine['qty'] ?? 0) ?> × <?= e((string) $foodLine['item_name']) ?></div>
            <?php endforeach; ?>
          <?php elseif ($stopReservation): ?>
            <?php foreach (reservation_summary_lines($stopReservation) as $reservationLine): ?>
              <div class="small" style="color:#0e8a7a"><?= e($reservationLine) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$g['stops']): ?>
        <p class="meta" style="margin:.35rem 0"><?= $isMultiday ? 'No stops on this day.' : 'No stops on this itinerary yet.' ?></p>
      <?php endif; ?>
    <?php endforeach; ?>
  </article>
  <script>
  (function () {
    var btn = document.getElementById('itinCopyLink');
    if (!btn || !navigator.clipboard) return;
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-url') || '';
      navigator.clipboard.writeText(url).then(function () {
        var prev = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = prev; }, 1200);
      });
    });
  })();
  </script>
</body>
</html>
