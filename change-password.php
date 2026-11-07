<?php
/**
 * Change password (logged-in users) or forced first-login password set.
 */
require_once __DIR__ . '/includes/auth.php';

ensure_password_schema();
$user = require_login();

$error = null;
$forced = user_must_change_password($user);
$returnTo = !$forced ? safe_app_return_url($_POST['return_to'] ?? $_GET['return_to'] ?? null) : null;
if ($forced) {
    $pendingError = flash('error');
    if ($pendingError && $pendingError !== 'Please set a new password to continue.') {
        flash('error', $pendingError);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    $error = csrf_error_message();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $current = $_POST['current_password'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    // First login already proved the temporary password. Only a voluntary change asks for it again.
    if (!$forced) {
        if ($current === '') {
            $error = 'Enter your current password.';
        } elseif (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        }
    }

    if (!$error) {
        $pwCheck = validate_password_rules($password, $confirm);
        if (!$pwCheck['ok']) {
            $error = $pwCheck['message'];
        } elseif (password_verify($password, $user['password_hash'])) {
            $error = 'New password must be different from your current password.';
        } else {
            db()->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
            )->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
            log_activity((int) $user['id'], 'password_change', 'auth', 'Password updated');
            $user['must_change_password'] = 0;
            if (!$forced) {
                flash('success', 'Password updated successfully.');
            }
            redirect($returnTo ?? role_dashboard((int) $user['role_id']));
        }
    }

    if ($error && $returnTo) {
        flash('error', $error);
        redirect(account_change_return_url('password') ?? $returnTo);
    }
}

$pageTitle = 'Change Password';
$pageHeading = $forced ? 'Set a new password' : 'Change password';
$pageSub = $forced
    ? 'You must change the temporary password before continuing'
    : 'Enter your current password, then choose a new one';
$activeNav = 'password';
$useTouristShell = !$forced && (int) $user['role_id'] === ROLE_TOURIST;
$useBusinessShell = !$forced && (int) $user['role_id'] === ROLE_BUSINESS;
if ($useTouristShell) {
    $bodyClass = 'tourist-app';
} elseif ($useBusinessShell) {
    $bodyClass = 'business-app';
}

require __DIR__ . '/includes/header.php';
?>
<?php if ($useTouristShell): ?>
  <?php require __DIR__ . '/includes/tourist_shell_start.php'; ?>
<?php elseif ($useBusinessShell): ?>
  <?php require __DIR__ . '/includes/business_shell_start.php'; ?>
<?php elseif ($forced): ?>
<div class="app-shell">
  <div class="main-wrap" style="margin-left:0;width:100%">
    <div class="page-content">
<?php else: ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-content">
<?php endif; ?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($forced): ?>
        <div class="auth-panel" style="min-height:70vh">
          <div class="glass p-4 p-md-5 w-100 fade-up" style="max-width:460px">
            <h1 class="h3 mb-1">Welcome to CulTour</h1>
            <p class="text-muted mb-3">Your account was created by the Tourism Office. Please set a new password to continue.</p>
      <?php else: ?>
          <div class="glass p-4 fade-up" style="max-width:480px">
            <h3 class="panel-title">Change password</h3>
            <p class="small text-muted mb-3">Signed in as <?= e($user['email']) ?></p>
      <?php endif; ?>

            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="off">
              <?= csrf_field() ?>
              <?php if ($returnTo): ?>
                <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
              <?php endif; ?>
              <?php if (!$forced): ?>
              <div class="mb-3">
                <label class="form-label">Current password</label>
                <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
              </div>
              <?php endif; ?>
              <div class="mb-3">
                <label class="form-label">New password</label>
                <input type="password" class="form-control" name="password" id="password" required minlength="5" autocomplete="new-password">
                <?= password_rules_hint_html('password') ?>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm new password</label>
                <input type="password" class="form-control" name="password_confirm" id="password_confirm" required minlength="5" autocomplete="new-password">
                <?= password_confirm_warn_html('password') ?>
              </div>
              <button class="btn btn-ct w-100" type="submit">Save new password</button>
            </form>
            <?php if ($forced): ?>
              <p class="mt-3 mb-0 small text-center"><a href="<?= APP_URL ?>/logout.php">Log out</a></p>
            <?php else: ?>
              <p class="mt-3 mb-0 small text-center">
                <a href="<?= APP_URL ?>/forgot-password.php?restart=1">Forgot it? Reset via email</a>
              </p>
            <?php endif; ?>
          </div>
      <?php if ($forced): ?>
        </div>
      <?php endif; ?>
<?php if ($useTouristShell): ?>
  <?php require __DIR__ . '/includes/tourist_shell_end.php'; ?>
<?php elseif ($useBusinessShell): ?>
  <?php require __DIR__ . '/includes/business_shell_end.php'; ?>
<?php else: ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?= password_rules_focus_script('password', 'passwordRules') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
