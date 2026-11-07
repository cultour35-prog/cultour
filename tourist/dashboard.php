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

$featured = $pdo->query(
    "SELECT d.*, c.name AS category_name
     FROM destinations d
     JOIN categories c ON c.id=d.category_id
     WHERE d.status='published' AND d.is_featured=1
     ORDER BY d.avg_rating DESC, d.name ASC
     LIMIT 6"
)->fetchAll();
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
            <h2 class="dash-section__title mb-0">Places of interest</h2>
          </div>
          <a class="quick-chip" href="<?= APP_URL ?>/tourist/destinations.php">View all</a>
        </div>
        <div class="tourist-dest-grid">
          <?php foreach ($featured as $i => $d):
            $cover = !empty($d['cover_image'])
                ? 'background-image:url(' . APP_URL . '/uploads/' . e($d['cover_image']) . ')'
                : '';
          ?>
            <a class="tourist-dest fade-up" style="animation-delay:<?= $i * 0.05 ?>s;<?= $cover ?>"
               href="<?= APP_URL ?>/tourist/destination.php?id=<?= (int) $d['id'] ?>">
              <span class="tourist-dest__shade"></span>
              <span class="tourist-dest__meta">
                <span class="tourist-dest__cat"><?= e($d['category_name']) ?></span>
                <span class="tourist-dest__name d-block"><?= e($d['name']) ?></span>
                <span class="tourist-dest__loc"><?= e($d['location']) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
          <?php if (!$featured): ?>
            <p class="text-muted mb-0">No destinations published yet.</p>
          <?php endif; ?>
        </div>
      </section>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
