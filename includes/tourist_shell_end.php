<?php
/**
 * Tourist app shell — close + mobile bottom nav.
 * Expects: $activeNav, $user
 */
$activeNav = $activeNav ?? '';
$moreActive = in_array($activeNav, ['businesses', 'profile', 'destinations', 'chat', 'favorites', 'notifications'], true);
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
      <button class="tourist-bottomnav__item <?= $moreActive ? 'is-active' : '' ?>" type="button" data-bs-toggle="dropdown" data-app-tour-fallback="destinations businesses" aria-expanded="false">
        <i class="bi bi-grid"></i><span>More</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item <?= $activeNav === 'destinations' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourist/destinations.php" data-app-tour="destinations"><i class="bi bi-geo-alt me-2"></i>Destinations</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'businesses' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourist/businesses.php" data-app-tour="businesses"><i class="bi bi-shop me-2"></i>Businesses</a></li>
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
<script src="<?= APP_URL ?>/assets/js/app-tutorial.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/app-tutorial.js') ?: time() ?>"></script>
