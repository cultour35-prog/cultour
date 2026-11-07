<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/content_reports.php';
$user = require_login(ROLE_TOURIST);
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT d.*, c.name AS category_name, c.pin_color FROM destinations d JOIN categories c ON c.id=d.category_id WHERE d.id=? AND d.status='published'");
$stmt->execute([$id]);
$d = $stmt->fetch();
if (!$d) {
    flash('error', 'Destination not found.');
    redirect(APP_URL . '/tourist/destinations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (($_POST['action'] ?? '') === 'favorite') {
        try {
            db()->prepare('INSERT INTO favorites (user_id, destination_id) VALUES (?,?)')->execute([$user['id'], $id]);
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM favorites WHERE user_id=? AND destination_id=?')->execute([$user['id'], $id]);
        }
        redirect(APP_URL . '/tourist/destination.php?id=' . $id);
    }
    if (($_POST['action'] ?? '') === 'review') {
        $rating = max(1, min(5, (int)$_POST['rating']));
        $comment = trim($_POST['comment'] ?? '');
        db()->prepare(
            'INSERT INTO destination_reviews (destination_id, user_id, rating, comment) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)'
        )->execute([$id, $user['id'], $rating, $comment]);
        $avg = db()->prepare('SELECT AVG(rating), COUNT(*) FROM destination_reviews WHERE destination_id=?');
        $avg->execute([$id]);
        [$a, $c] = $avg->fetch(PDO::FETCH_NUM);
        db()->prepare('UPDATE destinations SET avg_rating=?, review_count=? WHERE id=?')->execute([round((float)$a, 2), (int)$c, $id]);
        flash('success', 'Review saved.');
        redirect(APP_URL . '/tourist/destination.php?id=' . $id);
    }
    if (($_POST['action'] ?? '') === 'report') {
        $photo = content_report_save_upload($_FILES['report_photo'] ?? null);
        if (empty($photo['ok'])) {
            flash('error', (string) ($photo['message'] ?? 'Photo could not be uploaded.'));
            redirect(APP_URL . '/tourist/destination.php?id=' . $id);
        }
        $err = submit_content_report(
            (int) $user['id'],
            'destination',
            $id,
            trim((string) ($_POST['reason'] ?? '')),
            trim((string) ($_POST['details'] ?? '')),
            $photo['path'] ?? null
        );
        flash($err ? 'error' : 'success', $err ?: 'Thanks — your report was sent to the Tourism Office.');
        redirect(APP_URL . '/tourist/destination.php?id=' . $id);
    }
}

$fav = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND destination_id=?');
$fav->execute([$user['id'], $id]);
$isFav = (bool)$fav->fetch();

$reviews = db()->prepare(
    "SELECT r.*, u.first_name, u.last_name FROM destination_reviews r JOIN users u ON u.id=r.user_id WHERE r.destination_id=? ORDER BY r.created_at DESC"
);
$reviews->execute([$id]);
$reviews = $reviews->fetchAll();

ensure_place_schedule_schema();
$services = db()->prepare(
    'SELECT name, description, price, duration_minutes FROM destination_services
     WHERE destination_id=? AND is_active=1 ORDER BY name'
);
$services->execute([$id]);
$services = $services->fetchAll() ?: [];

$nearbyDests = [];
$nearbyBiz = [];
$nearbyPlaces = [];
$hereMarker = null;
if (!empty($d['latitude']) && !empty($d['longitude'])) {
    ensure_destination_coords();
    $lat = (float) $d['latitude'];
    $lng = (float) $d['longitude'];
    $allDest = map_destination_markers();
    foreach ($allDest as $m) {
        if ((int) ($m['id'] ?? 0) === $id) {
            $hereMarker = $m;
            $hereMarker['open'] = true;
            break;
        }
    }
    if ($hereMarker === null) {
        $hereMarker = [
            'id' => $id,
            'lat' => $lat,
            'lng' => $lng,
            'title' => $d['name'],
            'kind' => 'destination',
            'color' => normalize_pin_color($d['pin_color'] ?? ''),
            'open' => true,
        ];
    }
    $nearbyDests = map_rank_nearby($allDest, $lat, $lng, $id);
    $nearbyBiz = map_rank_nearby(map_business_markers('__default__', true), $lat, $lng);
    $nearbyPlaces = map_nearby_combined($nearbyDests, $nearbyBiz);
}

$pageTitle = $d['name'];
$pageHeading = '';
$pageHeadBackHref = APP_URL . '/tourist/destinations.php';
$pageHeadBackLabel = 'Back to destinations';
$pageSub = '';
$activeNav = 'destinations';
$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';
map_leaflet_head();
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div class="row g-3 align-items-start">
        <div class="col-lg-8">
          <div class="glass overflow-hidden mb-3 fade-up">
            <div class="place-detail-hero"<?= !empty($d['cover_image']) ? ' style="background-image:url(' . APP_URL . '/uploads/' . e($d['cover_image']) . ')"' : '' ?>></div>
            <div class="p-3 p-md-4">
              <div class="small text-muted mb-1"><?= e($d['category_name']) ?></div>
              <h2 class="h3 mb-1"><?= e($d['name']) ?></h2>
              <div class="mb-2"><?= rating_stars_html($d['avg_rating'] ?? 0, (int) ($d['review_count'] ?? 0), 'sm') ?></div>
              <p class="text-muted mb-0"><i class="bi bi-geo-alt"></i> <?= e($d['location']) ?></p>
              <?php if (!empty($d['description'])): ?>
                <p class="place-detail-copy mb-0"><?= nl2br(e($d['description'])) ?></p>
              <?php endif; ?>
              <?php if ($d['history']): ?>
                <h3 class="h6 mt-3 mb-1">History</h3>
                <p class="mb-0"><?= nl2br(e($d['history'])) ?></p>
              <?php endif; ?>
              <?php if ($d['travel_tips']): ?>
                <h3 class="h6 mt-3 mb-1">Travel Tips</h3>
                <p class="mb-0"><?= nl2br(e($d['travel_tips'])) ?></p>
              <?php endif; ?>
              <?php if ($d['nearby_attractions']): ?>
                <h3 class="h6 mt-3 mb-1">Nearby</h3>
                <p class="mb-0"><?= nl2br(e($d['nearby_attractions'])) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($services): ?>
            <div class="glass p-3 p-md-4 mb-3 fade-up">
              <h3 class="h6 mb-3">Listing</h3>
              <ul class="place-detail-svcs list-unstyled mb-0">
                <?php foreach ($services as $svc): ?>
                  <li class="place-detail-svc">
                    <div>
                      <div class="fw-semibold"><?= e($svc['name']) ?></div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="glass p-3 p-md-4 mb-3 fade-up">
            <h3 class="h6 mb-2">Location</h3>
            <?php if ($d['latitude'] && $d['longitude']): ?>
              <div id="destMap" class="ct-map ct-map--nearby"></div>
            <?php else: ?>
              <p class="small text-muted mb-0">Map pin not set for this destination yet.</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="glass p-3 mb-3">
            <div class="mb-2">
              <span class="text-muted">Hours</span>
              <?php
                $ohStruct = parse_opening_hours_value($d['opening_hours'] ?? null);
                $dayLabels = ['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'];
              ?>
              <?php if ($ohStruct): ?>
                <div class="small">
                  <?php foreach ($dayLabels as $k => $lab): ?>
                    <div><span class="text-muted"><?= e($lab) ?></span> <?= e(format_hours_slot_12h($ohStruct[$k] ?? 'Closed')) ?></div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div><?= e($d['opening_hours'] ? format_hours_slot_12h((string) $d['opening_hours']) : '—') ?></div>
              <?php endif; ?>
            </div>
            <div class="mb-2"><span class="text-muted">Entrance</span><div><?= e(format_money($d['entrance_fee'])) ?></div></div>
            <div class="mb-3"><span class="text-muted">Emergency</span><div><?= e($d['emergency_contacts'] ?: '—') ?></div></div>
            <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="favorite">
              <button class="btn <?= $isFav ? 'btn-outline-danger' : 'btn-ct' ?> w-100"><?= $isFav ? 'Remove Favorite' : 'Save Favorite' ?></button>
            </form>
            <a class="btn btn-outline-secondary w-100 mb-2" href="<?= APP_URL ?>/tourist/itineraries.php?book=1&destination_id=<?= (int)$d['id'] ?>">Book a Guide</a>
            <button class="btn btn-outline-danger w-100 btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reportDest">Report content</button>
            <div class="collapse mt-2" id="reportDest">
              <form method="post" enctype="multipart/form-data" class="border rounded-3 p-2"><?= csrf_field() ?>
                <input type="hidden" name="action" value="report">
                <select class="form-select form-select-sm mb-2" name="reason" required>
                  <option value="">Reason…</option>
                  <option value="Inaccurate information">Inaccurate information</option>
                  <option value="Inappropriate photo">Inappropriate photo</option>
                  <option value="Spam or misleading">Spam or misleading</option>
                  <option value="Other">Other</option>
                </select>
                <textarea class="form-control form-control-sm mb-2" name="details" rows="2" maxlength="2000" placeholder="Optional details"></textarea>
                <label class="form-label small mb-1">Photo <span class="text-muted fw-normal">(optional)</span></label>
                <input type="file" class="form-control form-control-sm mb-2" name="report_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                <button class="btn btn-sm btn-outline-danger w-100">Submit report</button>
              </form>
            </div>
          </div>

          <div class="glass p-3 dest-reviews-side">
            <div class="d-flex justify-content-between align-items-baseline mb-2">
              <h3 class="h6 mb-0">Reviews</h3>
              <span class="small text-muted">★ <?= e($d['avg_rating']) ?> · <?= (int) $d['review_count'] ?></span>
            </div>
            <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="review">
              <select class="form-select form-select-sm mb-2" name="rating">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <option value="<?= $i ?>"><?= $i ?> ★</option>
                <?php endfor; ?>
              </select>
              <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" placeholder="Your review"></textarea>
              <button class="btn btn-ct btn-sm w-100">Post review</button>
            </form>
            <div class="dest-reviews-side__list" data-live-self="dest-reviews">
              <?php if (!$reviews): ?>
                <div class="small text-muted">No reviews yet. Be the first.</div>
              <?php else: ?>
                <?php foreach ($reviews as $r): ?>
                  <div class="dest-reviews-side__item">
                    <div class="fw-semibold small"><?= e($r['first_name'] . ' ' . $r['last_name']) ?> · <?= (int) $r['rating'] ?>★</div>
                    <?php if (!empty($r['comment'])): ?>
                      <div class="small"><?= e($r['comment']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php if ($d['latitude'] && $d['longitude']): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= APP_URL ?>/assets/js/map.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/map.js') ?: time() ?>"></script>
<script>
CulTourMap.renderExploreMap(
  'destMap',
  <?= json_encode(array_values(array_filter(array_merge(
      [$hereMarker],
      $nearbyPlaces
  ))), JSON_UNESCAPED_UNICODE) ?>,
  {
    zoom: 13,
    center: [<?= (float) $d['latitude'] ?>, <?= (float) $d['longitude'] ?>],
    fitMarkers: true,
    live: false,
    nearbyPlaces: <?= json_encode(map_nearby_legend_items($nearbyPlaces), JSON_UNESCAPED_UNICODE) ?>
  }
);
</script>
<?php endif; ?>
<?php
require __DIR__ . '/../includes/footer.php';
?>
