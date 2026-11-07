<?php
/**
 * Change account email (non-admin users). Requires current password.
 */
require_once __DIR__ . '/includes/auth.php';

$user = require_login();

if ((int) $user['role_id'] === ROLE_ADMIN) {
    flash('error', 'Administrators cannot change email from this page.');
    redirect(role_dashboard(ROLE_ADMIN));
}

$error = null;
$newEmailValue = '';
$returnTo = safe_app_return_url($_POST['return_to'] ?? $_GET['return_to'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    $error = csrf_error_message();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $password = (string) ($_POST['password'] ?? '');
    $newEmail = strtolower(trim((string) ($_POST['new_email'] ?? '')));
    $newEmailValue = $newEmail;

    if ($password === '') {
        $error = 'Enter your password to confirm this change.';
    } elseif (!password_verify($password, $user['password_hash'])) {
        $error = 'Password is incorrect.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($newEmail === strtolower(trim((string) $user['email']))) {
        $error = 'That is already your current email.';
    } else {
        $exists = db()->prepare('SELECT id FROM users WHERE email = ? AND role_id = ? AND id <> ? LIMIT 1');
        $exists->execute([$newEmail, (int) $user['role_id'], (int) $user['id']]);
        if ($exists->fetch()) {
            $error = 'An account with this email already exists for your account type.';
        } else {
            db()->prepare(
                'UPDATE users SET email = ?, email_verified_at = NULL, verification_token = NULL WHERE id = ?'
            )->execute([$newEmail, (int) $user['id']]);
            log_activity((int) $user['id'], 'email_change', 'auth', 'Email updated to ' . $newEmail);
            send_user_verification_email((int) $user['id'], $newEmail, (string) ($user['first_name'] ?? ''));
            flash('success', 'Email updated. Check your inbox to verify the new address.');
            redirect($returnTo ?? role_dashboard((int) $user['role_id']));
        }
    }

    if ($error && $returnTo) {
        flash('error', $error);
        redirect(account_change_return_url('email') ?? $returnTo);
    }
}

$pageTitle = 'Change Email';
$pageHeading = 'Change email';
$pageSub = 'Confirm with your password to update the email on this account';
$activeNav = 'email';
$useTouristShell = (int) $user['role_id'] === ROLE_TOURIST;
$useBusinessShell = (int) $user['role_id'] === ROLE_BUSINESS;
if ($useTouristShell) {
    $bodyClass = 'tourist-app';
} elseif ($useBusinessShell) {
    $bodyClass = 'business-app';
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
      <div class="glass p-4 fade-up" style="max-width:480px">
        <h3 class="panel-title">Change email</h3>
        <p class="small text-muted mb-3">Current email: <strong><?= e($user['email']) ?></strong></p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <?php if ($returnTo): ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">New email</label>
            <input type="email" class="form-control" name="new_email" required maxlength="190"
                   value="<?= e($newEmailValue) ?>" autocomplete="email">
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" required autocomplete="current-password">
            <div class="form-text">Enter your account password to confirm.</div>
          </div>
          <button class="btn btn-ct w-100" type="submit">Save new email</button>
        </form>
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
