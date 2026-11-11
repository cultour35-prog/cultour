<?php
/**
 * Tourist app shell — close + mobile bottom nav.
 * Expects: $activeNav, $user
 */
$activeNav = $activeNav ?? '';
$moreActive = in_array($activeNav, ['places', 'profile', 'chat', 'favorites', 'notifications'], true);
$tbLinkedAccounts = $tbLinkedAccounts ?? (isset($user) ? switchable_linked_accounts($user) : []);
?>
    </div>
  </main>

  <nav class="tourist-bottomnav" aria-label="Tourist mobile navigation">
    <a class="tourist-bottomnav__item <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourist/dashboard.php" data-app-tour="dashboard">
      <i class="bi bi-house"></i><span>Home</span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'map' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/map.php" data-app-tour="map">
      <i class="bi bi-map"></i><span>Map</span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'itineraries' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourist/itineraries.php" data-app-tour="itineraries">
      <i class="bi bi-signpost-2"></i><span>Tours</span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'packages' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourist/packages.php" data-app-tour="packages">
      <i class="bi bi-suitcase-lg"></i><span>Packages</span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'bookings' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourist/bookings.php" data-app-tour="bookings">
      <i class="bi bi-calendar-check"></i><span>Bookings</span>
    </a>
    <div class="dropup tourist-bottomnav__more">
      <button class="tourist-bottomnav__item <?= $moreActive ? 'is-active' : '' ?>" type="button" data-bs-toggle="dropdown" data-app-tour-fallback="places" aria-expanded="false">
        <i class="bi bi-grid"></i><span>More</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item <?= $activeNav === 'places' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourist/places.php" data-app-tour="places"><i class="bi bi-geo-alt me-2"></i>Places</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'favorites' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourist/favorites.php" data-app-tour="favorites"><i class="bi bi-heart me-2"></i>Favorites</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'chat' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourist/chat.php"><i class="bi bi-chat-dots me-2"></i>Messages</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'notifications' ? 'active' : '' ?>" href="<?= APP_URL ?>/notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item <?= $activeNav === 'profile' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourist/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'email' ? 'active' : '' ?>" href="<?= APP_URL ?>/change-email.php"><i class="bi bi-envelope me-2"></i>Change email</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'password' ? 'active' : '' ?>" href="<?= APP_URL ?>/change-password.php"><i class="bi bi-key me-2"></i>Change password</a></li>
        <?php if (!empty($tbLinkedAccounts)): ?>
          <li><hr class="dropdown-divider"></li>
          <li><span class="dropdown-item-text small text-muted">Linked accounts</span></li>
          <?php foreach ($tbLinkedAccounts as $linked): ?>
            <li>
              <form method="post" action="<?= APP_URL ?>/switch-account.php" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="target_user_id" value="<?= (int) $linked['id'] ?>">
                <button type="submit" class="dropdown-item">
                  <i class="bi bi-arrow-left-right me-2"></i>Switch to <?= e(role_label((int) ($linked['role_id'] ?? 0))) ?>
                </button>
              </form>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  </nav>
</div>
<?php
if (!empty($user['id']) && (int) ($user['role_id'] ?? 0) === ROLE_TOURIST && empty($GLOBALS['CULTOUR_PACKAGE_MAKER'])) {
    start_app_session();
    $rejectSql = "SELECT r.id, r.rejection_reason, r.itinerary_id, b.name AS business_name
      FROM restaurant_reservations r
      JOIN businesses b ON b.id = r.business_id
      JOIN itinerary_items ii ON ii.id = r.itinerary_item_id AND ii.business_id = r.business_id
      WHERE r.tourist_id=? AND r.status='rejected'
      ORDER BY r.id DESC LIMIT 1";
    $rejectParams = [(int) $user['id']];
    try {
        revenue_ensure_schema();
        $rejectSt = db()->prepare($rejectSql);
        $rejectSt->execute($rejectParams);
        $rejectPop = $rejectSt->fetch() ?: null;
        $viewingItinerary = (int) ($GLOBALS['CULTOUR_VIEW_ITINERARY_ID'] ?? 0);
        if ($rejectPop && $viewingItinerary > 0 && $viewingItinerary === (int) $rejectPop['itinerary_id']) {
            $rejectPop = null;
        }
    } catch (Throwable $e) {
        $rejectPop = null;
    }
    if ($rejectPop):
        $rejectUrl = APP_URL . '/tourist/itineraries.php?id=' . (int) $rejectPop['itinerary_id'];
?>
<div class="modal fade reject-notice" id="rejectNoticeModal" data-reject-id="<?= (int) $rejectPop['id'] ?>" tabindex="-1" aria-labelledby="rejectNoticeTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content reject-notice__card">
      <button type="button" class="btn-close reject-notice__close" data-bs-dismiss="modal" aria-label="Close"></button>
      <div class="reject-notice__mark" aria-hidden="true">!</div>
      <p class="reject-notice__kicker">Reservation declined</p>
      <h2 class="reject-notice__title" id="rejectNoticeTitle"><?= e((string) $rejectPop['business_name']) ?></h2>
      <p class="reject-notice__lead">did not accept this stop.</p>
      <div class="reject-notice__reason">
        <span>Their reason</span>
        <p><?= e(trim((string) $rejectPop['rejection_reason']) !== '' ? (string) $rejectPop['rejection_reason'] : 'No reason was given.') ?></p>
      </div>
      <a class="btn btn-ct reject-notice__go" href="<?= e($rejectUrl) ?>">Open the tour</a>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('rejectNoticeModal');
  if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
});
</script>
<?php
    endif;
}
?>
<script src="<?= APP_URL ?>/assets/js/app-tutorial.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/app-tutorial.js') ?: time() ?>"></script>
