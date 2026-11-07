<?php
/**
 * Top navigation bar for app pages
 */
$name = full_name($user);
$tbUnread = unread_notifications((int) $user['id']);
$tbRoleId = (int) $user['role_id'];
$tbChatUrl = $tbRoleId === ROLE_TOUR_GUIDE
    ? (APP_URL . '/tourguide/chat.php')
    : ($tbRoleId === ROLE_TOURIST
        ? (APP_URL . '/tourist/chat.php')
        : ($tbRoleId === ROLE_BUSINESS ? (APP_URL . '/business/chat.php') : null));
$tbUnreadChat = $tbChatUrl && function_exists('unread_messages')
    ? unread_messages((int) $user['id'])
    : 0;
$tbShowAvatar = in_array($tbRoleId, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true);
$tbAvatarUrl = ($tbShowAvatar && !empty($user['avatar']))
    ? (APP_URL . '/uploads/' . ltrim((string) $user['avatar'], '/'))
    : null;
$tbInitials = strtoupper(substr((string) ($user['first_name'] ?? ''), 0, 1)
    . substr((string) ($user['last_name'] ?? ''), 0, 1));
if ($tbInitials === '') {
    $tbInitials = '?';
}

$profileLinks = [
  ROLE_TOURIST => ['/tourist/profile.php', 'Profile', 'bi-person'],
  ROLE_TOUR_GUIDE => ['/tourguide/profile.php', 'Profile', 'bi-person'],
  ROLE_BUSINESS => ['/business/profile.php', 'Business Profile', 'bi-shop'],
];
$tbProfileNav = $profileLinks[$tbRoleId] ?? null;

$tbLinkedAccounts = [];
if (in_array($tbRoleId, linkable_role_ids(), true)) {
    $tbLinkedAccounts = switchable_linked_accounts($user);
}

$returnUrl = APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
// Prefer path under this app only
if (!str_starts_with($returnUrl, APP_URL)) {
    $returnUrl = APP_URL . '/';
}

require_once __DIR__ . '/live.php';
$tbNotifLive = live_header_notif_payload((int) $user['id'], $returnUrl);
?>
<header class="topbar glass mb-4 fade-up">
  <div class="d-flex align-items-center gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" type="button" aria-label="Open navigation menu" aria-controls="appSidebar">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div>
      <div class="fw-semibold"><?= e($pageHeading ?? $pageTitle ?? 'Dashboard') ?></div>
      <div class="small text-muted"><?= e($pageSub ?? 'CulTour · Ormoc City') ?></div>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php if ($tbChatUrl): ?>
      <a class="btn btn-sm btn-outline-secondary position-relative ct-tip<?= ($activeNav ?? '') === 'chat' ? ' active' : '' ?>"
         href="<?= e($tbChatUrl) ?>" data-tip="Messages" aria-label="Messages">
        <i class="bi bi-chat-dots"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="navChatUnreadTop" style="font-size:.65rem"<?= $tbUnreadChat > 0 ? '' : ' hidden' ?>><?= $tbUnreadChat > 9 ? '9+' : (int) $tbUnreadChat ?></span>
      </a>
    <?php endif; ?>
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
      <button class="btn btn-sm btn-ct dropdown-toggle topbar-user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for <?= e($name) ?>">
        <?php if ($tbShowAvatar): ?>
          <span class="topbar-user-avatar" aria-hidden="true">
            <?php if ($tbAvatarUrl): ?>
              <img src="<?= e($tbAvatarUrl) ?>" alt="">
            <?php else: ?>
              <?= e($tbInitials) ?>
            <?php endif; ?>
          </span>
        <?php else: ?>
          <i class="bi bi-person-circle"></i>
        <?php endif; ?>
        <?= e($name) ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><span class="dropdown-item-text small text-muted"><?= e(ucfirst(str_replace('_', ' ', $user['role_name']))) ?></span></li>
        <li><hr class="dropdown-divider"></li>
        <?php if ($tbProfileNav): ?>
          <li>
            <a class="dropdown-item" href="<?= APP_URL . $tbProfileNav[0] ?>">
              <i class="bi <?= e($tbProfileNav[2]) ?> me-2"></i><?= e($tbProfileNav[1]) ?>
            </a>
          </li>
        <?php endif; ?>
        <?php if ($tbLinkedAccounts): ?>
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
        <?php if (in_array((int) $user['role_id'], [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)): ?>
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
        <?php endif; ?>
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
