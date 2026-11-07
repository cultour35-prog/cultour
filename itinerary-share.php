<?php
/**
 * Printable / shareable itinerary view (owner, suggested public copy, or admin).
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
ensure_itinerary_items_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, CONCAT(u.first_name,\' \',u.last_name) AS owner_name FROM itineraries i JOIN users u ON u.id=i.user_id WHERE i.id=?');
$stmt->execute([$id]);
$itin = $stmt->fetch();
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

if (!$allowed) {
    flash('error', 'Access denied.');
    redirect(role_dashboard($role));
}

$stops = itinerary_items_with_places($id);
$meetup = format_time_12h((string) ($itin['meetup_time'] ?? '08:00:00'));
$shareUrl = APP_URL . '/itinerary-share.php?id=' . $id;
$dayCount = itinerary_logical_day_count($itin);
$isMultiday = $dayCount > 1;
$stopsByDay = itinerary_group_stops_by_day($stops, $itin['start_date'] ?? null, $itin['end_date'] ?? null, $id, $itin);
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
    <a class="ghost" href="<?= APP_URL ?>/<?= $role===ROLE_TOURIST?'tourist/itineraries.php?id='.$id:($role===ROLE_TOUR_GUIDE?'tourguide/itineraries.php?id='.$id:'admin/itineraries.php') ?>">Back</a>
  </div>
  <article class="sheet">
    <div class="small" style="color:var(--teal);font-weight:700;letter-spacing:.04em;text-transform:uppercase">CulTour itinerary</div>
    <h1><?= e($itin['title']) ?></h1>
    <div class="meta">
      <?= (int)($itin['is_suggested'] ?? 0) ? 'Suggested tour' : 'Personal plan' ?>
      · <?= e($itin['owner_name']) ?><br>
      <?php if ($isMultiday): ?>
        <?= (int) $dayCount ?> days · starts <?= e(format_date($itin['start_date'] ?? null)) ?>
      <?php else: ?>
        <?= e(format_date($itin['start_date'] ?? null)) ?>
      <?php endif; ?>
      · Meetup <?= e($meetup) ?> at Ormoc City Port
    </div>
    <?php if (!empty($itin['description'])): ?>
      <p><?= nl2br(e($itin['description'])) ?></p>
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
            Estimated time of arrival <?= e(format_time_12h(isset($it['start_time']) ? (string)$it['start_time'] : null)) ?>
            · Leave <?= e(format_time_12h(isset($it['end_time']) ? (string)$it['end_time'] : null)) ?>
            · Stay <?= (int)($it['visit_minutes'] ?? 60) ?> min
          </div>
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
