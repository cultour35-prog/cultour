<?php
/**
 * Tourist — single local business detail (cover, photos, services, hours, reviews, inquiry)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/content_reports.php';
require_once __DIR__ . '/../includes/business_engagement.php';

$user = require_login(ROLE_TOURIST);
ensure_business_listing_schema();
ensure_business_reviews_schema();
ensure_business_inquiries_schema();
ensure_business_favorites_schema();
ensure_place_schedule_schema();

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    "SELECT b.*, bt.name AS type_name,
            (SELECT COUNT(*) FROM business_photos bp WHERE bp.business_id = b.id) AS photo_count
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.id = ? AND b.approval_status = 'approved' AND b.status = 'active'"
);
$stmt->execute([$id]);
$b = $stmt->fetch();
if (!$b) {
    flash('error', 'Business not found.');
    redirect(APP_URL . '/tourist/businesses.php');
}
$listingBlock = business_listing_unavailable_message($b);
if ($listingBlock !== null) {
    flash('error', $listingBlock);
    redirect(APP_URL . '/tourist/businesses.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'favorite') {
        try {
            db()->prepare('INSERT INTO business_favorites (user_id, business_id) VALUES (?,?)')
                ->execute([(int) $user['id'], $id]);
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM business_favorites WHERE user_id=? AND business_id=?')
                ->execute([(int) $user['id'], $id]);
        }
        redirect(APP_URL . '/tourist/business.php?id=' . $id);
    }
    if ($action === 'review') {
        $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0)));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        db()->prepare(
            "INSERT INTO business_reviews (business_id, user_id, rating, comment, status) VALUES (?,?,?,?, 'active')
             ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), status='active'"
        )->execute([$id, (int) $user['id'], $rating, $comment !== '' ? $comment : null]);
        refresh_business_rating($id);
        flash('success', 'Review saved.');
        redirect(APP_URL . '/tourist/business.php?id=' . $id);
    }
    if ($action === 'inquiry') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($subject === '' || strlen($subject) > 160) {
            flash('error', 'Enter a short subject for your message.');
            redirect(APP_URL . '/tourist/business.php?id=' . $id);
        }
        if ($message === '') {
            flash('error', 'Please enter a message.');
            redirect(APP_URL . '/tourist/business.php?id=' . $id);
        }
        db()->prepare(
            'INSERT INTO business_inquiries (business_id, tourist_id, subject, message) VALUES (?,?,?,?)'
        )->execute([$id, (int) $user['id'], $subject, $message]);
        $ownerId = (int) ($b['user_id'] ?? 0);
        if ($ownerId > 0) {
            business_inquiry_post_to_chat(
                (int) $user['id'],
                $ownerId,
                (int) $user['id'],
                $subject,
                $message
            );
            notify_user(
                $ownerId,
                'inquiry',
                'New inquiry about ' . $b['name'],
                full_name($user) . ': ' . $subject,
                APP_URL . '/business/chat.php?with=' . (int) $user['id']
            );
        }
        flash('success', 'Message sent. When they reply, continue in Messages.');
        redirect(APP_URL . '/tourist/business.php?id=' . $id);
    }
    if ($action === 'report') {
        $photo = content_report_save_upload($_FILES['report_photo'] ?? null);
        if (empty($photo['ok'])) {
            flash('error', (string) ($photo['message'] ?? 'Photo could not be uploaded.'));
            redirect(APP_URL . '/tourist/business.php?id=' . $id);
        }
        $err = submit_content_report(
            (int) $user['id'],
            'business',
            $id,
            trim((string) ($_POST['reason'] ?? '')),
            trim((string) ($_POST['details'] ?? '')),
            $photo['path'] ?? null
        );
        flash($err ? 'error' : 'success', $err ?: 'Thanks — your report was sent to the Tourism Office.');
        redirect(APP_URL . '/tourist/business.php?id=' . $id);
    }
}

$fav = db()->prepare('SELECT id FROM business_favorites WHERE user_id=? AND business_id=?');
$fav->execute([(int) $user['id'], $id]);
$isFav = (bool) $fav->fetch();

$photos = db()->prepare(
    'SELECT id, image_path, caption FROM business_photos
     WHERE business_id = ? AND COALESCE(is_hidden,0)=0 ORDER BY id DESC'
);
$photos->execute([$id]);
$photos = $photos->fetchAll();

$services = db()->prepare(
    'SELECT name, description, price, duration_minutes, package_days, capacity, amenities, image_path FROM business_services WHERE business_id = ? AND is_active = 1 ORDER BY name'
);
$services->execute([$id]);
$services = $services->fetchAll();

$reviews = db()->prepare(
    "SELECT r.*, u.first_name, u.last_name
     FROM business_reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.business_id=? AND r.status='active'
     ORDER BY r.created_at DESC"
);
$reviews->execute([$id]);
$reviews = $reviews->fetchAll() ?: [];

$hours = parse_opening_hours_value($b['opening_hours'] ?? null) ?: [];
$dayLabels = [
    'mon' => 'Monday',
    'tue' => 'Tuesday',
    'wed' => 'Wednesday',
    'thu' => 'Thursday',
    'fri' => 'Friday',
    'sat' => 'Saturday',
    'sun' => 'Sunday',
];
$todayKey = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][(int) date('w')] ?? 'mon';
$logoUrl = trim((string) ($b['logo'] ?? '')) !== ''
    ? (APP_URL . '/uploads/' . ltrim((string) $b['logo'], '/'))
    : '';
$coverUrl = !empty($b['cover_image'])
    ? (APP_URL . '/uploads/' . ltrim((string) $b['cover_image'], '/'))
    : '';
$phone = trim((string) ($b['phone'] ?? ''));
$email = trim((string) ($b['email'] ?? ''));

$nearbyDests = [];
$nearbyBiz = [];
$nearbyPlaces = [];
$hereMarker = null;
if ($b['latitude'] !== null && $b['longitude'] !== null) {
    ensure_destination_coords();
    $lat = (float) $b['latitude'];
    $lng = (float) $b['longitude'];
    $allBiz = map_business_markers('__default__', true);
    foreach ($allBiz as $m) {
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
            'title' => $b['name'],
            'kind' => 'business',
            'open' => true,
        ];
    }
    $nearbyDests = map_rank_nearby(map_destination_markers(), $lat, $lng);
    $nearbyBiz = map_rank_nearby($allBiz, $lat, $lng, $id);
    $nearbyPlaces = map_nearby_combined($nearbyDests, $nearbyBiz);
}

$pageTitle = $b['name'];
$pageHeading = '';
$pageHeadBackHref = APP_URL . '/tourist/businesses.php';
$pageHeadBackLabel = 'Back to businesses';
$pageSub = '';
$activeNav = 'businesses';

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';
map_leaflet_head();
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="place-detail-identity mb-3">
        <div class="place-detail-logo" aria-hidden="true">
          <?php if ($logoUrl !== ''): ?>
            <img src="<?= e($logoUrl) ?>" alt="">
          <?php else: ?>
            <?= e(mb_strtoupper(mb_substr((string) $b['name'], 0, 1))) ?>
          <?php endif; ?>
        </div>
        <div class="place-detail-identity__copy">
          <div class="small text-muted mb-1"><?= e($b['type_name']) ?></div>
          <h1 class="h3 mb-1"><?= e($b['name']) ?></h1>
          <div class="mb-1"><?= rating_stars_html($b['avg_rating'] ?? 0, (int) ($b['review_count'] ?? 0), 'sm') ?></div>
          <p class="text-muted mb-0"><i class="bi bi-geo-alt"></i> <?= e($b['address']) ?></p>
        </div>
      </div>
      <div class="row g-3 align-items-start">
        <div class="col-lg-8">
          <div class="glass overflow-hidden mb-3 fade-up">
            <div class="place-detail-hero"<?= $coverUrl !== '' ? ' style="background-image:url(' . e($coverUrl) . ')"' : '' ?>></div>
            <?php if (!empty($b['description'])): ?>
              <div class="p-3 p-md-4">
                <p class="place-detail-copy mb-0"><?= nl2br(e($b['description'])) ?></p>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($photos): ?>
            <div class="glass p-3 p-md-4 mb-3 fade-up">
              <section class="ct-rail ct-rail--photos<?= count($photos) > 1 ? ' has-many' : '' ?>" data-focus-rail aria-label="Business photos">
                <div class="ct-rail__head">
                  <h3 class="ct-rail__heading">Photos</h3>
                </div>
                <div class="ct-rail__viewport">
                  <?php if (count($photos) > 1): ?>
                    <button type="button" class="ct-rail__arrow ct-rail__arrow--prev" aria-label="Previous photo">
                      <i class="bi bi-chevron-left"></i>
                    </button>
                  <?php endif; ?>
                  <div class="ct-rail__track">
                    <?php foreach ($photos as $i => $p): ?>
                      <?php
                        $photoUrl = APP_URL . '/uploads/' . $p['image_path'];
                        $caption = trim((string) ($p['caption'] ?? ''));
                      ?>
                      <article class="ct-rail__card<?= $i === 0 ? ' is-active' : '' ?>"
                               data-href="<?= e($photoUrl) ?>"
                               data-href-blank
                               tabindex="0"
                               role="button"
                               aria-label="<?= e($caption !== '' ? $caption : ('Photo ' . ($i + 1))) ?>">
                        <div class="ct-rail__poster" style="background-image:url(<?= e($photoUrl) ?>)">
                          <div class="ct-rail__shade"></div>
                          <?php if ($caption !== ''): ?>
                            <div class="ct-rail__meta">
                              <div class="ct-rail__title"><?= e($caption) ?></div>
                            </div>
                          <?php endif; ?>
                        </div>
                      </article>
                    <?php endforeach; ?>
                  </div>
                  <?php if (count($photos) > 1): ?>
                    <button type="button" class="ct-rail__arrow ct-rail__arrow--next" aria-label="Next photo">
                      <i class="bi bi-chevron-right"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </section>
            </div>
          <?php endif; ?>

          <?php if ($services): ?>
            <?php
              $offerCopy = business_catalog_copy($b['type_name'] ?? '');
              $offerMode = $offerCopy['mode'];
            ?>
            <div class="glass p-3 p-md-4 mb-3 fade-up">
              <h3 class="h6 mb-3"><?= e($offerCopy['label']) ?></h3>
              <ul class="place-detail-svcs list-unstyled mb-0">
                <?php foreach ($services as $s): ?>
                  <?php
                    $svcImg = trim((string) ($s['image_path'] ?? ''));
                    $svcImgUrl = $svcImg !== '' ? APP_URL . '/uploads/' . ltrim($svcImg, '/') : '';
                  ?>
                  <li class="place-detail-svc">
                    <?php if ($svcImgUrl !== ''): ?>
                      <img class="place-detail-svc__photo" src="<?= e($svcImgUrl) ?>" alt="">
                    <?php endif; ?>
                    <div>
                      <div class="fw-semibold"><?= e($s['name']) ?></div>
                      <?php if (in_array($offerMode, ['rooms', 'vehicles', 'packages'], true) && !empty($s['description'])): ?>
                        <div class="small text-muted"><?= e($s['description']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($s['amenities']) || !empty($s['capacity'])): ?>
                        <div class="small text-muted">
                          <?php if (!empty($s['capacity'])): ?><?= (int) $s['capacity'] ?> <?= $offerMode === 'vehicles' ? 'seats' : 'guests' ?><?php endif; ?>
                          <?php if (!empty($s['amenities'])): ?><?= !empty($s['capacity']) ? ' · ' : '' ?><?= e($s['amenities']) ?><?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                    <?php if (in_array($offerMode, ['rooms', 'vehicles', 'packages'], true)): ?>
                    <div class="place-detail-svc__meta">
                      <span><?= $s['price'] !== null ? e(format_money((float) $s['price'])) : '—' ?></span>
                      <?php if ($offerMode === 'packages' && (int) ($s['package_days'] ?? 0) > 0): ?>
                        <span class="small text-muted"><?= e(package_days_label((int) $s['package_days'])) ?></span>
                      <?php elseif (!empty($s['duration_minutes'])): ?>
                        <span class="small text-muted"><?= (int) $s['duration_minutes'] ?> min</span>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="glass p-3 p-md-4 mb-3 fade-up">
            <h3 class="h6 mb-2">Location</h3>
            <?php if ($b['latitude'] !== null && $b['longitude'] !== null): ?>
              <div id="bizDetailMap" class="ct-map ct-map--nearby"></div>
            <?php else: ?>
              <p class="small text-muted mb-0">Map pin not set for this business yet.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="glass p-3 mb-3 fade-up">
            <form method="post" class="mb-3"><?= csrf_field() ?>
              <input type="hidden" name="action" value="favorite">
              <button class="btn btn-sm <?= $isFav ? 'btn-ct' : 'btn-outline-secondary' ?> w-100">
                <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                <?= $isFav ? 'Saved' : 'Save to favorites' ?>
              </button>
            </form>
            <div class="place-detail-contact mb-3">
              <?php if ($phone !== ''): ?>
                <a class="place-detail-contact__link" href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>">
                  <i class="bi bi-telephone" aria-hidden="true"></i>
                  <span><?= e($phone) ?></span>
                  <span class="visually-hidden"> Phone</span>
                </a>
              <?php endif; ?>
              <?php if ($email !== ''): ?>
                <a class="place-detail-contact__link" href="mailto:<?= e($email) ?>">
                  <i class="bi bi-envelope" aria-hidden="true"></i>
                  <span><?= e($email) ?></span>
                  <span class="visually-hidden"> Email</span>
                </a>
              <?php endif; ?>
              <?= business_social_facts_html($b) ?>
            </div>
            <div>
              <div class="small text-muted mb-1">Operating hours</div>
              <?php if ($hours): ?>
                <ul class="place-detail-hours list-unstyled small mb-0">
                  <?php foreach ($dayLabels as $key => $label): ?>
                    <li class="<?= $key === $todayKey ? 'is-today' : '' ?>">
                      <span><?= e($label) ?></span>
                      <span><?= e(format_hours_slot_12h((string) ($hours[$key] ?? 'Closed'))) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="small text-muted">Hours not listed yet.</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="glass p-3 mb-3 fade-up">
            <div class="fw-semibold mb-2">Ask this business</div>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="inquiry">
              <input class="form-control form-control-sm mb-2" name="subject" maxlength="160" placeholder="Subject" required>
              <textarea class="form-control form-control-sm mb-2" name="message" rows="3" placeholder="Your question…" required></textarea>
              <button class="btn btn-sm btn-ct w-100">Send message</button>
            </form>
            <p class="small text-muted mb-0 mt-2">Replies show up in Messages.</p>
          </div>

          <div class="glass p-3 mb-3 fade-up dest-reviews-side">
            <div class="d-flex justify-content-between align-items-baseline mb-2">
              <h3 class="h6 mb-0">Reviews</h3>
              <span class="small text-muted"><?= (int) ($b['review_count'] ?? 0) ?></span>
            </div>
            <form method="post" class="mb-3"><?= csrf_field() ?>
              <input type="hidden" name="action" value="review">
              <select class="form-select form-select-sm mb-2" name="rating" required>
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <option value="<?= $i ?>"><?= $i ?> ★</option>
                <?php endfor; ?>
              </select>
              <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" placeholder="Optional comment"></textarea>
              <button class="btn btn-sm btn-ct w-100">Save review</button>
            </form>
            <div class="dest-reviews-side__list" data-live-self="biz-reviews">
              <?php if ($reviews): ?>
                <?php foreach ($reviews as $r): ?>
                  <div class="dest-reviews-side__item">
                    <div class="fw-semibold small"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?> · ★ <?= (int) $r['rating'] ?></div>
                    <?php if (!empty($r['comment'])): ?>
                      <div class="small"><?= e($r['comment']) ?></div>
                    <?php endif; ?>
                    <div class="small text-muted"><?= e(format_date($r['created_at'] ?? null, 'M j, Y')) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="small text-muted mb-0">No reviews yet — be the first.</p>
              <?php endif; ?>
            </div>
          </div>

          <a class="btn btn-outline-secondary w-100 mb-2" href="<?= APP_URL ?>/map.php">Open explore map</a>
          <button class="btn btn-outline-danger w-100 btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reportBiz">Report content</button>
          <div class="collapse mt-2" id="reportBiz">
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
      </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php if ($b['latitude'] !== null && $b['longitude'] !== null): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= APP_URL ?>/assets/js/map.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/map.js') ?: time() ?>"></script>
<script>
CulTourMap.renderExploreMap(
  'bizDetailMap',
  <?= json_encode(array_values(array_filter(array_merge(
      [$hereMarker],
      $nearbyPlaces
  ))), JSON_UNESCAPED_UNICODE) ?>,
  {
    zoom: 13,
    center: [<?= (float) $b['latitude'] ?>, <?= (float) $b['longitude'] ?>],
    fitMarkers: true,
    live: false,
    nearbyPlaces: <?= json_encode(map_nearby_legend_items($nearbyPlaces), JSON_UNESCAPED_UNICODE) ?>
  }
);
</script>
<?php endif; ?>
<?php
$extraScripts = ($extraScripts ?? '') . '<script src="' . APP_URL . '/assets/js/focus-rail.js?v='
  . (@filemtime(ROOT_PATH . '/assets/js/focus-rail.js') ?: time()) . '"></script>';
require __DIR__ . '/../includes/footer.php';
?>
