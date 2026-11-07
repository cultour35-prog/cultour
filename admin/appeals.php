<?php
/**
 * Admin — suspended accounts + suspension appeals (live)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/suspension.php';

$user = require_login(ROLE_ADMIN);
ensure_suspension_schema();
$pageTitle = 'Suspended';
$pageHeading = 'Suspended accounts';
$pageSub = 'Activate accounts or review suspension appeals';
$activeNav = 'appeals';

// Fallback non-JS POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid or expired form. Please try again.');
        redirect(APP_URL . '/admin/appeals.php?status=' . urlencode($_GET['status'] ?? 'suspended'));
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'activate_user') {
        $result = activate_user_account(
            (int) ($_POST['user_id'] ?? 0),
            (int) $user['id'],
            'Your CulTour account has been reactivated by the Tourism Office.'
        );
        if ($result['ok']) {
            log_activity((int) $user['id'], 'activate_user', 'users', 'User #' . (int) ($_POST['user_id'] ?? 0));
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        redirect(APP_URL . '/admin/appeals.php?status=suspended');
    }

    $result = review_suspension_appeal(
        (int) ($_POST['appeal_id'] ?? 0),
        (int) $user['id'],
        $action,
        (string) ($_POST['admin_response'] ?? '')
    );
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    $redir = ($action === 'approve') ? 'approved' : (($action === 'deny') ? 'denied' : ($_GET['status'] ?? 'pending'));
    redirect(APP_URL . '/admin/appeals.php?status=' . urlencode($redir));
}

$filter = $_GET['status'] ?? 'suspended';
$list = admin_appeals_list_html((string) $filter);

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <div id="appealsFlash"></div>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="d-flex flex-wrap gap-2 mb-3" id="appealsFilters">
        <?php foreach ([
            'suspended' => 'Suspended accounts',
            'pending' => 'Pending appeals',
            'approved' => 'Approved',
            'denied' => 'Denied',
            'all' => 'All appeals',
        ] as $k => $label): ?>
          <a class="quick-chip <?= $list['filter'] === $k ? 'is-active fw-semibold' : '' ?>" href="?status=<?= e($k) ?>" data-appeals-filter="<?= e($k) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div id="appealsLiveList" data-appeals-version="<?= e($list['version']) ?>" data-appeals-filter="<?= e($list['filter']) ?>">
        <?= $list['html'] ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
