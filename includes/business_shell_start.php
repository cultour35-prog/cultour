<?php
/**
 * Business app shell — top nav (no sidebar).
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

$returnUrl = APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
if (!str_starts_with($returnUrl, APP_URL)) {
    $returnUrl = APP_URL . '/';
}

require_once __DIR__ . '/live.php';
$tbNotifLive = live_header_notif_payload((int) $user['id'], $returnUrl);

$catalogCopy = business_catalog_copy('');
$businessTypeName = '';
if (!empty($user['id'])) {
    $typeSt = db()->prepare(
        'SELECT bt.name FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id WHERE b.user_id=? LIMIT 1'
    );
    $typeSt->execute([(int) $user['id']]);
    $businessTypeName = (string) ($typeSt->fetchColumn() ?: '');
    $catalogCopy = business_catalog_copy($businessTypeName);
}
$catalogMode = $catalogCopy['mode'];
$catalogLabel = $catalogCopy['label'];
$catalogIcon = $catalogCopy['icon'];

$businessNav = [
    ['dashboard', 'Dashboard', '/business/dashboard.php', 'bi-speedometer2'],
    ['calendar', 'Calendar', '/business/dashboard.php?view=calendar', 'bi-calendar3'],
    ['report', 'Report', '/business/dashboard.php?view=report', 'bi-file-earmark-bar-graph'],
    ['services', $catalogLabel, '/business/services.php', $catalogIcon],
];
if (in_array($catalogMode, ['packages', 'rooms'], true)) {
    $businessNav[] = ['bookings', 'Bookings', '/business/bookings.php', 'bi-calendar-check'];
}
$revenueKind = revenue_bookable_kind($businessTypeName);
if (revenue_uses_reservation($revenueKind)) {
    $businessNav[] = ['reservations', 'Reservations', '/business/reservations.php', 'bi-journal-check'];
}
if (revenue_uses_reservation($revenueKind) || in_array($revenueKind, ['accommodation', 'transportation', 'package'], true)) {
    $businessNav[] = ['fees', 'Fees', '/business/fees.php', 'bi-receipt'];
}
$businessNav = array_merge($businessNav, [
    ['photos', 'Photos', '/business/photos.php', 'bi-images'],
    ['hours', 'Hours', '/business/hours.php', 'bi-clock'],
]);

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
?>
<div class="business-shell tourist-shell" data-app-tutorial="business" data-catalog-label="<?= e($catalogLabel) ?>" data-app-tutorial-user="<?= (int) ($user['id'] ?? 0) ?>"<?= $activeNav === 'dashboard' ? ' data-app-tutorial-auto="1"' : '' ?>>
  <header class="tourist-topnav">
    <a class="tourist-topnav__brand" href="<?= APP_URL ?>/business/dashboard.php"><?= e(APP_NAME) ?></a>

    <nav class="tourist-topnav__links" aria-label="Business navigation">
      <?php foreach ($businessNav as [$key, $label, $path, $icon]): ?>
        <a class="tourist-topnav__link <?= $activeNav === $key ? 'is-active' : '' ?>" href="<?= APP_URL . $path ?>" data-app-tour="<?= e($key) ?>">
          <i class="bi <?= e($icon) ?>"></i>
          <span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="tourist-topnav__actions">
      <button type="button" class="btn btn-sm btn-outline-secondary app-tutorial-replay" data-app-tutorial-replay data-app-tour="help">How to use</button>
      <a class="btn btn-sm btn-outline-secondary position-relative ct-tip<?= $activeNav === 'chat' ? ' is-active' : '' ?>"
         href="<?= APP_URL ?>/business/chat.php" data-app-tour="chat" data-tip="Messages" aria-label="Messages">
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
          <li><span class="dropdown-item-text small text-muted">Local Business</span></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item<?= $activeNav === 'profile' ? ' active' : '' ?>" href="<?= APP_URL ?>/business/profile.php">
              <i class="bi bi-shop me-2"></i>Business details
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
    <?php if (empty($businessHidePagehead) && (!empty($pageHeading) || !empty($pageSub))): ?>
      <div class="tourist-pagehead<?= !empty($pageHeadAction) ? ' tourist-pagehead--split' : '' ?>">
        <div>
          <?php if (!empty($pageHeading)): ?>
            <h1 class="tourist-pagehead__title"><?= e($pageHeading) ?></h1>
          <?php endif; ?>
          <?php if (!empty($pageSub)): ?>
            <p class="tourist-pagehead__sub"><?= e($pageSub) ?></p>
          <?php endif; ?>
        </div>
        <?php if (!empty($pageHeadAction)): ?>
          <div class="tourist-pagehead__action"><?= $pageHeadAction ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="tourist-content">
