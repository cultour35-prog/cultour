<?php
/**
 * Tour guide app shell — top nav (no sidebar).
 * Expects: $user, $activeNav, optional $pageHeading / $pageSub / $pageTitle
 */
$activeNav = $activeNav ?? '';
$name = full_name($user);
$tbUnread = unread_notifications((int) $user['id']);
$unreadChat = function_exists('unread_messages') ? unread_messages((int) $user['id']) : 0;
$tbAvatarUrl = !empty($user['avatar'])
    ? (APP_URL . '/uploads/' . ltrim((string) $user['avatar'], '/'))
    : null;
$tbInitials = strtoupper(substr((string) ($user['first_name'] ?? ''), 0, 1)
    . substr((string) ($user['last_name'] ?? ''), 0, 1));
if ($tbInitials === '') {
    $tbInitials = '?';
}

$tbLinkedAccounts = switchable_linked_accounts($user);

$pendingGuideBookings = 0;
$gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$gp->execute([(int) $user['id']]);
$guideProfileId = (int) $gp->fetchColumn();
if ($guideProfileId > 0 && function_exists('guide_pending_booking_count')) {
    $pendingGuideBookings = guide_pending_booking_count($guideProfileId);
}

$returnUrl = APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
if (!str_starts_with($returnUrl, APP_URL)) {
    $returnUrl = APP_URL . '/';
}

require_once __DIR__ . '/live.php';
$tbNotifLive = live_header_notif_payload((int) $user['id'], $returnUrl);

$guideNav = [
    ['dashboard', 'Dashboard', '/tourguide/dashboard.php', 'bi-speedometer2'],
    ['schedule', 'Schedule', '/tourguide/schedule.php', 'bi-calendar-week'],
    ['checkin', 'Check-in', '/tourguide/checkin.php', 'bi-camera'],
    ['bookings', 'Bookings', '/tourguide/bookings.php', 'bi-calendar-check'],
    ['availability', 'Availability', '/tourguide/availability.php', 'bi-calendar3'],
    ['itineraries', 'Tours', '/tourguide/itineraries.php', 'bi-signpost-2'],
    ['ratings', 'Ratings', '/tourguide/ratings.php', 'bi-star'],
];

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
?>
<div class="guide-shell tourist-shell" data-app-tutorial="guide" data-app-tutorial-user="<?= (int) ($user['id'] ?? 0) ?>"<?= $activeNav === 'dashboard' ? ' data-app-tutorial-auto="1"' : '' ?>>
  <header class="tourist-topnav">
    <a class="tourist-topnav__brand" href="<?= APP_URL ?>/tourguide/dashboard.php"><?= e(APP_NAME) ?></a>

    <nav class="tourist-topnav__links" aria-label="Tour guide navigation">
      <?php foreach ($guideNav as [$key, $label, $path, $icon]): ?>
        <a class="tourist-topnav__link <?= $activeNav === $key ? 'is-active' : '' ?>" href="<?= APP_URL . $path ?>" data-app-tour="<?= e($key) ?>">
          <i class="bi <?= e($icon) ?>"></i>
          <span><?= e($label) ?></span>
          <?php if ($key === 'bookings'): ?>
            <span class="nav-dot" id="navBookingsPending" title="Pending booking requests" aria-label="Pending booking requests"<?= $pendingGuideBookings > 0 ? '' : ' hidden' ?>></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="tourist-topnav__actions">
      <button type="button" class="btn btn-sm btn-outline-secondary app-tutorial-replay" data-app-tutorial-replay data-app-tour="help">How to use</button>
      <a class="btn btn-sm btn-outline-secondary position-relative ct-tip<?= $activeNav === 'chat' ? ' is-active' : '' ?>"
         href="<?= APP_URL ?>/tourguide/chat.php" data-app-tour="chat" data-tip="Messages" aria-label="Messages">
        <i class="bi bi-chat-dots"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="navChatUnread" style="font-size:.65rem"<?= $unreadChat > 0 ? '' : ' hidden' ?>><?= $unreadChat > 9 ? '9+' : (int) $unreadChat ?></span>
      </a>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary position-relative ct-tip" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-tip="Notifications" aria-label="Notifications" aria-expanded="false" id="notifBellBtn">
          <i class="bi bi-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="navNotifUnread" style="font-size:.65rem"<?= $tbUnread > 0 ? '' : ' hidden' ?>><?= $tbUnread > 9 ? '9+' : (int) $tbUnread ?></span>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-panel p-0">
          <div data-live-slot="notif_dropdown" data-live-version="<?= e($tbNotifLive['version']) ?>">
            <?= $tbNotifLive['html'] ?>
          </div>
        </div>
      </div>

      <div class="dropdown">
        <button class="btn btn-sm btn-ct dropdown-toggle topbar-user-btn" data-bs-toggle="dropdown">
          <span class="topbar-user-avatar" aria-hidden="true">
            <?php if ($tbAvatarUrl): ?>
              <img src="<?= e($tbAvatarUrl) ?>" alt="">
            <?php else: ?>
              <?= e($tbInitials) ?>
            <?php endif; ?>
          </span>
          <span class="d-none d-md-inline"><?= e($name) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted">Tour Guide</span></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item<?= $activeNav === 'profile' ? ' active' : '' ?>" href="<?= APP_URL ?>/tourguide/profile.php">
              <i class="bi bi-person me-2"></i>Profile
            </a>
          </li>
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
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/change-email.php">
              <i class="bi bi-envelope me-2"></i>Change email
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/change-password.php">
              <i class="bi bi-key me-2"></i>Change password
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/notifications.php">
              <i class="bi bi-bell me-2"></i>Notifications
              <span class="badge bg-danger ms-1" id="navNotifUnreadMenu"<?= $tbUnread ? '' : ' hidden' ?>><?= (int) $tbUnread ?></span>
            </a>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/logout.php">
              <i class="bi bi-box-arrow-right me-2"></i>Logout
            </a>
          </li>
        </ul>
      </div>
    </div>
  </header>

  <main class="tourist-main">
    <?php if (empty($guideHidePagehead) && (!empty($pageHeading) || !empty($pageSub))): ?>
      <div class="tourist-pagehead">
        <?php if (!empty($pageHeading)): ?>
          <h1 class="tourist-pagehead__title"><?= e($pageHeading) ?></h1>
        <?php endif; ?>
        <?php if (!empty($pageSub)): ?>
          <p class="tourist-pagehead__sub"><?= e($pageSub) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="tourist-content">
