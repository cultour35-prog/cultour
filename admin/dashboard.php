<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
$user = require_login(ROLE_ADMIN);
$pageTitle = 'Admin Dashboard';
$pageHeading = 'Administrator Dashboard';
$pageSub = 'Ormoc City Tourism Office overview';
$activeNav = 'dashboard';

ensure_destination_coords();

$pdo = db();
$stats = [
  'users' => (int) $pdo->query(
      "SELECT COUNT(*) FROM users WHERE status='active' AND role_id <> " . ROLE_ADMIN
  )->fetchColumn(),
  'tourists' => (int) $pdo->query(
      'SELECT COUNT(*) FROM users WHERE role_id=' . ROLE_TOURIST . " AND status='active'"
  )->fetchColumn(),
  'guides' => (int) $pdo->query(
      'SELECT COUNT(*) FROM users WHERE role_id=' . ROLE_TOUR_GUIDE . " AND status='active'"
  )->fetchColumn(),
  'businesses' => (int) $pdo->query(
      "SELECT COUNT(*) FROM businesses WHERE approval_status='approved'"
  )->fetchColumn(),
  'bookings_today' => (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_date = CURDATE()")->fetchColumn(),
  'pending_bookings' => (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn(),
  'pending_guides' => (int) $pdo->query("SELECT COUNT(*) FROM tour_guide_profiles WHERE approval_status='pending'")->fetchColumn(),
  'pending_biz' => (int) $pdo->query("SELECT COUNT(*) FROM businesses WHERE approval_status='pending'")->fetchColumn(),
];

$statusRows = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM bookings GROUP BY status"
)->fetchAll();

$logs = $pdo->query(
    "SELECT l.*, u.first_name, u.last_name FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC LIMIT 8"
)->fetchAll();

$markers = array_merge(map_destination_markers(null), map_business_markers());
foreach ($markers as &$m) {
    unset($m['href']);
}
unset($m);
$markersJson = json_encode($markers, JSON_UNESCAPED_SLASHES);

ensure_booking_stop_checkin_schema();
$toursToday = booking_tours_happening_on(app_today(), null, ['approved']);
$stats['tours_today'] = count($toursToday);

require __DIR__ . '/../includes/header.php';
map_leaflet_head();
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">

      <!-- Stats only -->
      <section class="dash-section" data-live-stats="admin_dashboard">
        <div class="row g-3">
          <?php
          $cards = [
            ['Total Users', $stats['users'], 'bi-people', APP_URL . '/admin/users.php'],
            ['Tourists', $stats['tourists'], 'bi-person', APP_URL . '/admin/tourists.php'],
            ['Tour Guides', $stats['guides'], 'bi-person-badge', APP_URL . '/admin/guides.php'],
            ['Businesses', $stats['businesses'], 'bi-shop', APP_URL . '/admin/businesses.php'],
            ['Bookings Today', $stats['bookings_today'], 'bi-calendar-day', APP_URL . '/admin/bookings.php'],
            ['Tours today', $stats['tours_today'], 'bi-geo-fill', APP_URL . '/admin/tours.php'],
            ['Pending Bookings', $stats['pending_bookings'], 'bi-hourglass', APP_URL . '/admin/bookings.php'],
            ['Pending Approvals', $stats['pending_guides'] + $stats['pending_biz'], 'bi-check2-square', APP_URL . '/admin/guides.php?tab=pending'],
          ];
          foreach ($cards as $i => [$label, $val, $icon, $href]):
          ?>
            <div class="col-6 col-md-3 fade-up" style="animation-delay:<?= $i * 0.04 ?>s">
              <a class="glass stat-card text-decoration-none d-block" href="<?= e($href) ?>">
                <div class="d-flex justify-content-between">
                  <span class="label"><?= e($label) ?></span>
                  <i class="bi <?= e($icon) ?> text-muted"></i>
                </div>
                <p class="value" data-stat="<?= e(['users','tourists','guides','businesses','bookings_today','tours_today','pending_bookings','pending_approvals'][$i] ?? 'stat') ?>"><?= number_format($val) ?></p>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Map: separate full-width section -->
      <section class="dash-section dash-map-section fade-up">
        <div class="dash-section__head">
          <div>
            <h2 class="dash-section__title">City map</h2>
            <p class="dash-section__sub mb-0">Government sites and businesses pinned across Ormoc</p>
          </div>
          <div id="adminDashMapFilters">
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <button type="button" class="quick-chip is-active fw-semibold" data-map-filter="all">All</button>
              <button type="button" class="quick-chip" data-map-filter="destinations">Government sites</button>
              <button type="button" class="quick-chip" data-map-filter="businesses">Businesses</button>
              <div class="map-search" style="min-width:11rem;max-width:18rem">
                <label class="visually-hidden" for="adminMapMarkerSearch">Search places</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" class="form-control" id="adminMapMarkerSearch" data-map-search
                         placeholder="Search places…" autocomplete="off">
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="glass map-panel map-panel--standalone">
          <div id="adminDashMap" class="ct-map ct-map--tall"></div>
        </div>
      </section>

      <!-- Activity below map -->
      <section class="dash-section">
        <div class="row g-3">
          <div class="col-lg-4">
            <div class="glass p-3 p-md-4 h-100">
              <h3 class="panel-title">Booking status</h3>
              <div data-live-slot="booking_status">
              <?php if (!$statusRows): ?>
                <p class="text-muted mb-0">No bookings yet.</p>
              <?php else: ?>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($statusRows as $sr): ?>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                      <span><?= status_badge($sr['status']) ?></span>
                      <strong><?= (int)$sr['total'] ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="mt-3">
                <a class="quick-chip" href="<?= APP_URL ?>/admin/bookings.php"><i class="bi bi-calendar"></i> Manage bookings</a>
              </div>
              </div>
            </div>
          </div>
          <div class="col-lg-8">
            <div class="glass p-3 p-md-4 h-100">
              <h3 class="panel-title">Recent activity</h3>
              <div class="table-responsive">
                <table class="table table-ct align-middle mb-0">
                  <thead><tr><th>User</th><th>Action</th><th>Module</th><th>When</th></tr></thead>
                    <tbody data-live-slot="activity">
                    <?php foreach ($logs as $log): ?>
                      <tr>
                        <td><?= e(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System') ?></td>
                        <td><?= e($log['action']) ?></td>
                        <td><?= e($log['module'] ?? '—') ?></td>
                        <td><?= e(format_date($log['created_at'], 'M j, g:i A')) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$logs): ?><tr><td colspan="4" class="text-muted">No activity yet.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>
<?php
$extraScripts = map_leaflet_scripts() . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  CulTourMap.renderExploreMap('adminDashMap', {$markersJson}, {
    filterRoot: '#adminDashMapFilters',
    filter: 'all'
  });
  // Leaflet needs a second pass when the container is tall / delayed layout
  setTimeout(function () {
    var el = document.getElementById('adminDashMap');
    if (el && el._leaflet_map) el._leaflet_map.invalidateSize();
  }, 300);
});
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
