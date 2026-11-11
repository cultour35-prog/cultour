<?php
/**
 * Business app shell — close + mobile bottom nav.
 * Expects: $activeNav, $user
 */
$activeNav = $activeNav ?? '';
$tbLinkedAccounts = $tbLinkedAccounts ?? (isset($user) ? switchable_linked_accounts($user) : []);
$moreActive = in_array($activeNav, ['profile', 'inquiries', 'chat', 'bookings', 'reservations', 'fees'], true);
?>
    </div>
  </main>

  <nav class="tourist-bottomnav" aria-label="Business mobile navigation">
    <a class="tourist-bottomnav__item <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/business/dashboard.php" data-app-tour="dashboard">
      <i class="bi bi-speedometer2"></i><span>Home</span>
    </a>
    <?php if (($catalogMode ?? '') === 'packages'): ?>
    <a class="tourist-bottomnav__item <?= $activeNav === 'package-maker' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/business/package.php" data-app-tour="package-maker">
      <i class="bi bi-signpost-split"></i><span>Maker</span>
    </a>
    <?php else: ?>
    <a class="tourist-bottomnav__item <?= $activeNav === 'services' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/business/services.php" data-app-tour="services">
      <i class="bi <?= e($catalogIcon ?? 'bi-list-check') ?>"></i><span><?= e($catalogLabel ?? 'Listing') ?></span>
    </a>
    <?php endif; ?>
    <a class="tourist-bottomnav__item <?= $activeNav === 'photos' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/business/photos.php" data-app-tour="photos">
      <i class="bi bi-images"></i><span>Photos</span>
    </a>
    <?php if (($catalogMode ?? '') !== 'packages'): ?>
    <a class="tourist-bottomnav__item <?= $activeNav === 'hours' ? 'is-active' : '' ?>" href="<?= APP_URL ?>/business/hours.php" data-app-tour="hours">
      <i class="bi bi-clock"></i><span>Hours</span>
    </a>
    <?php endif; ?>
    <div class="dropup tourist-bottomnav__more">
      <button class="tourist-bottomnav__item <?= $moreActive ? 'is-active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-grid"></i><span>More</span>
        <?php if (($pendingReservations ?? 0) > 0): ?>
          <span class="tourist-bottomnav__more-dot" title="Reservation requests waiting" aria-label="Reservation requests waiting"></span>
        <?php endif; ?>
        <?php if (($catalogMode ?? '') === 'packages'): ?>
        <span class="tourist-bottomnav__more-dot" data-booking-dot title="Pending package bookings" aria-label="Pending package bookings"<?= ($pendingPackageBookings ?? 0) > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if (($catalogMode ?? '') === 'packages'): ?>
        <li><a class="dropdown-item <?= $activeNav === 'services' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/services.php"><i class="bi bi-suitcase-lg me-2"></i>Packages made</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'inhouse' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/guides.php"><i class="bi bi-person-badge me-2"></i>In-house Guides</a></li>
        <?php endif; ?>
        <?php if (in_array(($catalogMode ?? ''), ['packages', 'rooms'], true)): ?>
        <li><a class="dropdown-item <?= $activeNav === 'bookings' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/bookings.php"><i class="bi bi-calendar-check me-2"></i>Bookings<?php if (($catalogMode ?? '') === 'packages'): ?> <span class="nav-dot" data-booking-dot title="Pending package bookings" aria-label="Pending package bookings"<?= ($pendingPackageBookings ?? 0) > 0 ? '' : ' hidden' ?>></span><?php endif; ?></a></li>
        <?php endif; ?>
        <?php if (revenue_uses_reservation((string) ($revenueKind ?? ''))): ?>
        <li><a class="dropdown-item <?= $activeNav === 'reservations' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/reservations.php"><i class="bi bi-journal-check me-2"></i>Reservations<?php if (($pendingReservations ?? 0) > 0): ?> <span class="nav-dot" title="Reservation requests waiting" aria-label="Reservation requests waiting"></span><?php endif; ?></a></li>
        <?php endif; ?>
        <li><a class="dropdown-item <?= $activeNav === 'inquiries' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/inquiries.php"><i class="bi bi-inbox me-2"></i>Inquiries</a></li>
        <li><a class="dropdown-item <?= $activeNav === 'chat' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/chat.php"><i class="bi bi-chat-dots me-2"></i>Messages</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item <?= $activeNav === 'profile' ? 'active' : '' ?>" href="<?= APP_URL ?>/business/profile.php"><i class="bi bi-shop me-2"></i>Business details</a></li>
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
