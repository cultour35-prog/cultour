<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/announcements.php';
require_once __DIR__ . '/../includes/suspension.php';
$user = require_login(ROLE_TOURIST);
$pageTitle = 'Home';
$pageHeading = 'Hello, ' . $user['first_name'];
$pageSub = 'Plan your Ormoc adventure';
$activeNav = 'dashboard';
$touristHidePagehead = true;
$bodyClass = 'tourist-app';

$pdo = db();
ensure_business_listing_schema();

$featured = $pdo->query(
    "SELECT d.id, d.name, d.location, d.cover_image, c.name AS category_name, 'destination' AS kind
     FROM destinations d
     JOIN categories c ON c.id=d.category_id
     WHERE d.status='published' AND d.is_featured=1
     ORDER BY d.name ASC"
)->fetchAll() ?: [];
$featuredBiz = $pdo->query(
    "SELECT b.id, b.name, b.address AS location, b.cover_image, bt.name AS category_name, 'business' AS kind
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.approval_status='approved' AND b.status='active' AND b.is_featured=1
     ORDER BY b.name ASC"
)->fetchAll() ?: [];
$featured = array_merge($featured, $featuredBiz);
$featuredLoop = $featured;
while ($featured && count($featuredLoop) < 6) {
    $featuredLoop = array_merge($featuredLoop, $featured);
}
$featuredHalf = (int) ceil(count($featuredLoop) / 2);
$featuredLower = array_merge(array_slice($featuredLoop, $featuredHalf), array_slice($featuredLoop, 0, $featuredHalf));
$annBundle = announcements_for_role(ROLE_TOURIST, (int) $user['id']);

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/tourist_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php render_account_status_panel($user); ?>
      <?php if (user_is_suspended($user)): ?>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; return; endif; ?>

      <section class="tourist-hero fade-up">
        <div>
          <div class="tourist-hero__kicker">Ormoc City</div>
          <h1 class="tourist-hero__title">Hello, <?= e($user['first_name']) ?></h1>
          <p class="tourist-hero__lead">Plan your day, pin places on the map, and book a licensed guide — all in CulTour.</p>
          <div class="tourist-hero__actions">
            <a class="btn btn-light" href="<?= APP_URL ?>/tourist/itineraries.php"><i class="bi bi-signpost-2 me-1"></i> Plan itinerary</a>
            <a class="btn btn-outline-light" href="<?= APP_URL ?>/map.php"><i class="bi bi-map me-1"></i> Explore map</a>
          </div>
        </div>
      </section>

      <div data-live-page="announcement_board" data-live-version="<?= e(announcement_board_version($annBundle)) ?>">
        <?php render_announcements_board($annBundle); ?>
      </div>

      <section class="mb-2">
        <div class="d-flex justify-content-between align-items-end gap-2 mb-3">
          <div>
            <h2 class="dash-section__title mb-0">Featured</h2>
          </div>
          <a class="quick-chip" href="<?= APP_URL ?>/tourist/places.php?featured=1">View all</a>
        </div>
        <?php if (!$featured): ?>
          <p class="text-muted mb-0">No featured places yet.</p>
        <?php else: ?>
          <div class="feat-marquee-stack">
            <?php foreach ([$featuredLoop, $featuredLower] as $rowIndex => $rowItems): ?>
              <div class="feat-marquee<?= $rowIndex === 1 ? ' feat-marquee--right' : '' ?>">
                <div class="feat-marquee__track">
                  <?php for ($copy = 0; $copy < 2; $copy++): ?>
                    <?php foreach ($rowItems as $d):
                      $cover = !empty($d['cover_image'])
                          ? 'background-image:url(' . APP_URL . '/uploads/' . e($d['cover_image']) . ')'
                          : '';
                      $href = ($d['kind'] ?? '') === 'business'
                          ? APP_URL . '/tourist/business.php?id=' . (int) $d['id']
                          : APP_URL . '/tourist/destination.php?id=' . (int) $d['id'];
                    ?>
                      <a class="tourist-dest" style="<?= $cover ?>" href="<?= e($href) ?>"<?= $copy === 1 ? ' tabindex="-1" aria-hidden="true"' : '' ?>>
                        <span class="tourist-dest__shade"></span>
                        <span class="tourist-dest__meta">
                          <span class="tourist-dest__cat"><?= e($d['category_name']) ?></span>
                          <span class="tourist-dest__name d-block"><?= e($d['name']) ?></span>
                          <span class="tourist-dest__loc"><?= e($d['location']) ?></span>
                        </span>
                      </a>
                    <?php endforeach; ?>
                  <?php endfor; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
