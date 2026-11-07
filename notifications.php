<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/live.php';
$user = require_login();
$pageTitle = 'Notifications';
$pageHeading = 'Notifications';
$pageSub = 'Updates from bookings, messages, and the Tourism Office';
$activeNav = 'notifications';

// Mark read + optional redirect to linked page
if (isset($_GET['go'])) {
    $nid = (int) $_GET['go'];
    $stmt = db()->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([$nid, $user['id']]);
    $n = $stmt->fetch();
    if ($n) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$nid]);
        if (!empty($n['link'])) {
            redirect(notification_href($n['link']));
        }
    }
    redirect(APP_URL . '/notifications.php');
}

if (isset($_GET['read_all'])) {
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    $return = $_GET['return'] ?? '';
    if (is_string($return) && $return !== '' && str_starts_with($return, APP_URL) && !str_contains($return, "\n")) {
        redirect($return);
    }
    flash('success', 'All notifications marked as read.');
    redirect(APP_URL . '/notifications.php');
}

$live = live_notifications_payload((int) $user['id']);

$useTouristShell = (int) $user['role_id'] === ROLE_TOURIST;
$useBusinessShell = (int) $user['role_id'] === ROLE_BUSINESS;
if ($useTouristShell) {
    $bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
} elseif ($useBusinessShell) {
    $bodyClass = trim(($bodyClass ?? '') . ' business-app');
}

require __DIR__ . '/includes/header.php';
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
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <div data-live-page="notifications" data-live-version="<?= e($live['version']) ?>">
        <?= $live['html'] ?>
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
<?php } ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
