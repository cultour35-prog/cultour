<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_ADMIN);
$pageTitle = 'Activity Logs';
$pageHeading = 'Activity Logs';
$activeNav = 'logs';

$live = live_admin_logs_payload();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <div class="glass p-3" data-live-page="admin_logs" data-live-version="<?= e($live['version']) ?>">
        <?= $live['html'] ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
