<?php
/**
 * Interactive explore map — available to all logged-in roles
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/map_helpers.php';

$user = require_login();
$pageTitle = 'Explore Map';
$pageHeading = 'Ormoc Explore Map';
$pageSub = 'Destinations and local businesses';
$activeNav = 'map';

ensure_destination_coords();

$isTourist = (int) $user['role_id'] === ROLE_TOURIST;
$destinations = map_destination_markers(
    $isTourist ? (APP_URL . '/tourist/destination.php?id=') : null
);
$businesses = map_business_markers(
    $isTourist ? (APP_URL . '/tourist/business.php?id=') : null,
    (int) $user['role_id'] !== ROLE_ADMIN
);

$markers = array_merge($destinations, $businesses);
$markersJson = json_encode($markers, JSON_UNESCAPED_SLASHES);
$useTouristShell = $isTourist;
$useBusinessShell = (int) $user['role_id'] === ROLE_BUSINESS;
$searchPlaceholder = (int) $user['role_id'] === ROLE_ADMIN
    ? 'Search government sites or businesses…'
    : 'Search destinations or businesses…';
if ($useTouristShell) {
    $pageHeadingTools = '<div class="booking-list-tools" id="exploreMapFilters">'
        . '<label class="guide-bookings-search">'
        . '<i class="bi bi-search" aria-hidden="true"></i>'
        . '<input type="search" id="mapMarkerSearch" data-map-search placeholder="' . e($searchPlaceholder) . '" aria-label="Search places" autocomplete="off">'
        . '</label>'
        . '<select class="booking-list-sort" data-map-sort aria-label="Filter places"><option value="all">All places</option></select>'
        . '</div>';
} elseif ($useBusinessShell) {
    $pageHeadingTools = '<label class="guide-bookings-search">'
        . '<i class="bi bi-search" aria-hidden="true"></i>'
        . '<input type="search" id="mapMarkerSearch" data-map-search placeholder="' . e($searchPlaceholder) . '" aria-label="Search places" autocomplete="off">'
        . '</label>';
}
if ($useTouristShell) {
    $bodyClass = trim(($bodyClass ?? '') . ' tourist-app map-explore-page');
} elseif ($useBusinessShell) {
    $bodyClass = trim(($bodyClass ?? '') . ' business-app map-explore-page');
}

require __DIR__ . '/includes/header.php';
map_leaflet_head();
if ($useTouristShell) {
    require __DIR__ . '/includes/tourist_shell_start.php';
} elseif ($useBusinessShell) {
    require __DIR__ . '/includes/business_shell_start.php';
} else {
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-content">
<?php } ?>
      <?php if (!$isTourist): ?>
      <div class="mb-3" id="exploreMapFilters">
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <button type="button" class="quick-chip is-active fw-semibold" data-map-filter="all">All</button>
          <button type="button" class="quick-chip" data-map-filter="destinations"><?= (int) $user['role_id'] === ROLE_ADMIN ? 'Government sites' : 'Destinations' ?></button>
          <button type="button" class="quick-chip" data-map-filter="businesses">Businesses</button>
          <?php if (empty($pageHeadingTools)): ?>
          <label class="guide-bookings-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="mapMarkerSearch" data-map-search placeholder="<?= e($searchPlaceholder) ?>" aria-label="Search places" autocomplete="off">
          </label>
          <?php endif; ?>
          <?php if ((int) $user['role_id'] === ROLE_ADMIN): ?>
            <a class="quick-chip" href="<?= APP_URL ?>/admin/destinations.php"><i class="bi bi-geo-fill"></i> Pin government sites</a>
          <?php elseif ((int) $user['role_id'] === ROLE_BUSINESS): ?>
            <a class="quick-chip" href="<?= APP_URL ?>/business/profile.php"><i class="bi bi-geo-fill"></i> Pin your business</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="glass p-3 p-md-4 fade-up">
        <?php if (!$markers): ?>
          <div class="alert alert-warning mb-3 mb-md-0">
            No mapped places yet.
            <?php if ((int) $user['role_id'] === ROLE_ADMIN): ?>
              Open Government sites and click the map to drop a pin.
            <?php elseif ((int) $user['role_id'] === ROLE_BUSINESS): ?>
              Open your business profile and pin your location on the map.
            <?php else: ?>
              Check back soon — the Tourism Office is adding locations.
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div id="exploreMap" class="ct-map ct-map--hero"></div>
      </div>
<?php
if ($useTouristShell) {
    require __DIR__ . '/includes/tourist_shell_end.php';
} elseif ($useBusinessShell) {
    require __DIR__ . '/includes/business_shell_end.php';
} else {
?>
    </div>
  </div>
</div>
<?php
}
$extraScripts = map_leaflet_scripts() . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  CulTourMap.renderExploreMap('exploreMap', {$markersJson}, {
    filterRoot: '#exploreMapFilters',
    filter: 'all'
  });
});
</script>
HTML;
require __DIR__ . '/includes/footer.php';
?>
