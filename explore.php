<?php
/**
 * Public explore map — no login required. Detail links prompt login.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/map_helpers.php';
start_app_session();
try_remember_login();
$user = current_user();
if ($user) {
    redirect(APP_URL . '/map.php');
}

$pageTitle = 'Explore Ormoc';
$bodyClass = 'public-explore';
ensure_destination_coords();

$destinations = [];
$rows = db()->query(
    "SELECT d.id, d.name, d.location, d.latitude, d.longitude, d.cover_image, c.name AS category_name, c.pin_color
     FROM destinations d
     JOIN categories c ON c.id = d.category_id
     WHERE d.status='published' AND d.latitude IS NOT NULL AND d.longitude IS NOT NULL
     ORDER BY d.is_featured DESC, d.name"
)->fetchAll();
foreach ($rows as $r) {
    $destinations[] = [
        'id' => (int) $r['id'],
        'title' => $r['name'],
        'lat' => (float) $r['latitude'],
        'lng' => (float) $r['longitude'],
        'kind' => 'destination',
        'color' => $r['pin_color'] ?: '#0e8a7a',
        'subtitle' => $r['category_name'] . ($r['location'] ? ' · ' . $r['location'] : ''),
        'url' => APP_URL . '/login.php?next=' . rawurlencode('/tourist/destination.php?id=' . (int) $r['id']),
    ];
}

$businesses = [];
$bizRows = array_values(array_filter(db()->query(
    "SELECT b.id, b.name, b.address, b.latitude, b.longitude, bt.name AS type_name, bt.pin_color, "
    . business_itinerary_ready_columns() . "
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.approval_status='approved' AND b.status='active'
       AND b.latitude IS NOT NULL AND b.longitude IS NOT NULL
     ORDER BY b.name"
)->fetchAll() ?: [], 'business_itinerary_ready'));
foreach ($bizRows as $r) {
    $businesses[] = [
        'id' => (int) $r['id'],
        'title' => $r['name'],
        'lat' => (float) $r['latitude'],
        'lng' => (float) $r['longitude'],
        'kind' => 'business',
        'color' => $r['pin_color'] ?: '#c4a35a',
        'subtitle' => $r['type_name'] . ($r['address'] ? ' · ' . $r['address'] : ''),
        'url' => APP_URL . '/login.php?next=' . rawurlencode('/tourist/business.php?id=' . (int) $r['id']),
    ];
}

$featured = db()->query(
    "SELECT id, name, location, cover_image, entrance_fee
     FROM destinations WHERE status='published' AND is_featured=1
     ORDER BY name LIMIT 6"
)->fetchAll();

$markers = array_merge($destinations, $businesses);
require __DIR__ . '/includes/header.php';
map_leaflet_head();
?>
<div class="public-shell">
  <header class="public-topbar">
    <a class="public-brand" href="<?= APP_URL ?>"><?= e(APP_NAME) ?></a>
    <div class="d-flex gap-2 align-items-center">
      <a class="btn btn-sm btn-outline-light" href="<?= APP_URL ?>/login.php">Login</a>
      <a class="btn btn-sm btn-gold" href="<?= APP_URL ?>/register.php">Register</a>
    </div>
  </header>

  <main class="public-main">
    <div class="container-fluid py-3" style="max-width:1200px">
      <div class="mb-3">
        <h1 class="h3 mb-1 text-white">Explore Ormoc</h1>
        <p class="text-white-50 mb-0 small">Browse destinations and local businesses. Sign in to view details, build itineraries, and book guides.</p>
      </div>

      <?php if ($featured): ?>
        <div class="row g-2 mb-3">
          <?php foreach ($featured as $f): ?>
            <div class="col-6 col-md-4 col-lg-2">
              <a class="public-card text-decoration-none" href="<?= APP_URL ?>/login.php?next=<?= urlencode('/tourist/destination.php?id=' . (int) $f['id']) ?>">
                <div class="public-card__img" style="<?= !empty($f['cover_image']) ? 'background-image:url(' . APP_URL . '/uploads/' . e($f['cover_image']) . ')' : '' ?>"></div>
                <div class="public-card__body">
                  <div class="fw-semibold text-truncate"><?= e($f['name']) ?></div>
                  <div class="small text-muted text-truncate"><?= e($f['location'] ?? '') ?></div>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="glass p-2 p-md-3">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2" id="exploreMapFilters">
          <button type="button" class="quick-chip is-active fw-semibold" data-map-filter="all">All</button>
          <button type="button" class="quick-chip" data-map-filter="destinations">Destinations</button>
          <button type="button" class="quick-chip" data-map-filter="businesses">Businesses</button>
          <div class="map-search flex-grow-1" style="min-width:12rem;max-width:20rem">
            <label class="visually-hidden" for="mapMarkerSearch">Search places</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="search" class="form-control" id="mapMarkerSearch" data-map-search
                     placeholder="Search destinations or businesses…" autocomplete="off">
            </div>
          </div>
          <span class="small text-muted ms-auto"><?= count($destinations) ?> destinations · <?= count($businesses) ?> businesses</span>
        </div>
        <div id="publicExploreMap" class="ct-map ct-map--tall"></div>
      </div>
    </div>
  </main>
</div>
<?php
$extraScripts = map_leaflet_scripts() . '<script>
document.addEventListener("DOMContentLoaded", function () {
  if (window.CulTourMap && CulTourMap.renderExploreMap) {
    CulTourMap.renderExploreMap("publicExploreMap", ' . json_encode($markers, JSON_UNESCAPED_UNICODE) . ', { filterRoot: "#exploreMapFilters" });
  }
});
</script>';
require __DIR__ . '/includes/footer.php';
