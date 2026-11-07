<?php
/**
 * Tour guide app shell — close + mobile bottom nav.
 * Expects: $activeNav, $user
 */
$activeNav = $activeNav ?? '';
$pendingGuideBookings = $pendingGuideBookings ?? 0;
$tbLinkedAccounts = $tbLinkedAccounts ?? (isset($user) ? switchable_linked_accounts($user) : []);
$moreActive = in_array($activeNav, ['availability', 'itineraries', 'ratings', 'profile', 'chat'], true);
?>
    </div>
  </main>

  <nav class="tourist-bottomnav" aria-label="Tour guide mobile navigation">
    <a class="tourist-bottomnav__item <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourguide/dashboard.php" data-app-tour="dashboard">
      <i class="bi bi-speedometer2"></i><span>Home</span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'schedule' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourguide/schedule.php" data-app-tour="schedule">
      <i class="bi bi-calendar-week"></i><span>Schedule</span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'bookings' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourguide/bookings.php" data-app-tour="bookings">
      <i class="bi bi-calendar-check"></i><span>Bookings</span>
      <span class="nav-dot"<?= $pendingGuideBookings > 0 ? '' : ' hidden' ?>></span>
    </a>
    <a class="tourist-bottomnav__item <?= $activeNav === 'chat' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/tourguide/chat.php" data-app-tour="chat">
      <i class="bi bi-chat-dots"></i><span>Chat</span>
    </a>
    <div class="dropup tourist-bottomnav__more">
      <button class="tourist-bottomnav__item <?= $moreActive ? 'is-active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-grid"></i><span>More</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item <?= $activeNav === 'availability' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourguide/availability.php"><i class="bi bi-calendar3 me-2"></i>Availability</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'itineraries' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourguide/itineraries.php"><i class="bi bi-signpost-2 me-2"></i>Suggested Tours</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'ratings' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourguide/ratings.php"><i class="bi bi-star me-2"></i>Ratings</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item <?= $activeNav === 'profile' ? 'active' : '' ?>" href="<?= APP_URL ?>/tourguide/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
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
