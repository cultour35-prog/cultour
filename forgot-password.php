<?php
require_once __DIR__ . '/includes/auth.php';
start_app_session();

const PASSWORD_RESET_RESEND_SECONDS = 60;

/**
 * Seconds remaining before another reset code may be sent.
 */
function password_reset_resend_wait_seconds(): int
{
    $sentAt = (int) ($_SESSION['password_reset_sent_at'] ?? 0);
    if ($sentAt <= 0) {
        return 0;
    }
    return max(0, PASSWORD_RESET_RESEND_SECONDS - (time() - $sentAt));
}

function password_reset_mark_sent(): void
{
    $_SESSION['password_reset_sent_at'] = time();
}

$error = null;
$success = null;
$accountUser = current_user();
$fromAccount = $accountUser && trim((string) ($accountUser['email'] ?? '')) !== '';
$accountEmail = $fromAccount ? strtolower(trim((string) $accountUser['email'])) : '';
$resetFrom = strtolower(trim((string) ($_POST['from'] ?? $_GET['from'] ?? $_SESSION['password_reset_portal'] ?? '')));
$resetPortal = $resetFrom === 'admin' ? 'admin' : 'public';
if ($fromAccount && (int) ($accountUser['role_id'] ?? 0) === ROLE_ADMIN) {
    $resetPortal = 'admin';
}
$_SESSION['password_reset_portal'] = $resetPortal;

$email = strtolower(trim((string) ($_SESSION['password_reset_email'] ?? '')));
$codeHash = trim((string) ($_SESSION['password_reset_code_hash'] ?? ''));
$step = 'request';
if ($email !== '') {
    $step = $codeHash !== '' ? 'password' : 'code';
}

// Logged-in users must always enter a code on a fresh visit (never skip to password).
if ($fromAccount && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $restart = isset($_GET['restart']);
    unset($_SESSION['password_reset_code_hash']);
    $codeHash = '';
    $wait = password_reset_resend_wait_seconds();
    $needSend = $restart || $email === '' || $email !== $accountEmail;
    if ($needSend && $wait <= 0) {
        request_password_reset($accountEmail, $resetPortal);
        password_reset_mark_sent();
        $success = 'We sent a 6-digit code to your email. Enter it below.';
    } elseif ($needSend && $wait > 0) {
        $success = 'A code was already sent. You can resend in ' . $wait . 's.';
    }
    $_SESSION['password_reset_email'] = $accountEmail;
    $email = $accountEmail;
    $step = 'code';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = csrf_error_message();
    } elseif (isset($_POST['request_reset']) || isset($_POST['resend_code'])) {
        $email = $fromAccount
            ? $accountEmail
            : strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid account email.';
            $step = 'request';
            $email = '';
            unset(
                $_SESSION['password_reset_email'],
                $_SESSION['password_reset_code_hash'],
                $_SESSION['password_reset_sent_at']
            );
            $codeHash = '';
        } else {
            $isResend = isset($_POST['resend_code']);
            $wait = password_reset_resend_wait_seconds();
            if ($isResend && $wait > 0) {
                $error = 'Please wait ' . $wait . 's before requesting another code.';
                $_SESSION['password_reset_email'] = $email;
                unset($_SESSION['password_reset_code_hash']);
                $codeHash = '';
                $step = 'code';
            } else {
                $result = request_password_reset($email, $resetPortal);
                password_reset_mark_sent();
                $success = $fromAccount || $isResend
                    ? 'We sent a 6-digit code to your email. Enter it below.'
                    : $result['message'];
                $_SESSION['password_reset_email'] = $email;
                unset($_SESSION['password_reset_code_hash']);
                $codeHash = '';
                $step = 'code';
            }
        }
    } elseif (isset($_POST['use_different_email'])) {
        if ($fromAccount) {
            $email = $accountEmail;
            unset($_SESSION['password_reset_code_hash']);
            $codeHash = '';
            $step = 'code';
        } else {
            unset(
                $_SESSION['password_reset_email'],
                $_SESSION['password_reset_code_hash'],
                $_SESSION['password_reset_sent_at']
            );
            $email = '';
            $codeHash = '';
            $step = 'request';
        }
    } elseif (isset($_POST['verify_code'])) {
        $email = $fromAccount
            ? $accountEmail
            : strtolower(trim((string) ($_POST['email'] ?? $_SESSION['password_reset_email'] ?? '')));
        $result = verify_password_reset_code($email, (string) ($_POST['code'] ?? ''), $resetPortal);
        if ($result['ok']) {
            $_SESSION['password_reset_email'] = $email;
            $_SESSION['password_reset_code_hash'] = (string) $result['code_hash'];
            $codeHash = (string) $result['code_hash'];
            $success = $result['message'];
            $step = 'password';
        } else {
            $error = $result['message'];
            unset($_SESSION['password_reset_code_hash']);
            $codeHash = '';
            $_SESSION['password_reset_email'] = $email;
            $step = 'code';
        }
    } else {
        $email = $fromAccount
            ? $accountEmail
            : strtolower(trim((string) ($_POST['email'] ?? $_SESSION['password_reset_email'] ?? '')));
        $codeHash = trim((string) ($_SESSION['password_reset_code_hash'] ?? ''));
        $result = reset_password(
            $email,
            $codeHash,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? ''),
            $resetPortal
        );
        if ($result['ok']) {
            $backToAdmin = $resetPortal === 'admin';
            unset(
                $_SESSION['password_reset_email'],
                $_SESSION['password_reset_code_hash'],
                $_SESSION['password_reset_sent_at'],
                $_SESSION['password_reset_portal']
            );
            logout_user();
            flash('success', $result['message']);
            redirect(auth_login_url($backToAdmin));
        }
        $error = $result['message'];
        $_SESSION['password_reset_email'] = $email;
        if ($codeHash === '') {
            unset($_SESSION['password_reset_code_hash']);
            $step = 'code';
        } else {
            $step = 'password';
        }
    }
}

$resendWait = $step === 'code' ? password_reset_resend_wait_seconds() : 0;

$backUrl = auth_login_url($resetPortal === 'admin');
if ($fromAccount) {
    $roleId = (int) ($accountUser['role_id'] ?? 0);
    $backUrl = $roleId === ROLE_TOURIST
        ? APP_URL . '/tourist/profile.php'
        : role_dashboard($roleId);
}

$pageTitle = 'Forgot Password';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-panel" style="min-height:100vh">
  <div class="glass p-4 p-md-5 w-100 fade-up" style="max-width:460px">
    <h2 class="h3 mb-1">Reset password</h2>
    <p class="text-muted mb-4">
      <?php if ($step === 'request'): ?>
        We’ll email a 6-digit code. Enter that code here to choose a new password.
      <?php elseif ($step === 'code'): ?>
        Enter the 6-digit code from your email. Codes expire in 15 minutes.
      <?php else: ?>
        Choose a new password for your account.
      <?php endif; ?>
    </p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" data-autohide="1"><?= e($success) ?></div><?php endif; ?>

    <?php if ($step === 'request'): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="from" value="<?= $resetPortal === 'admin' ? 'admin' : 'public' ?>">
        <input type="hidden" name="request_reset" value="1">
        <div class="mb-3">
          <label class="form-label">Account email</label>
          <input type="email" name="email" class="form-control" required autocomplete="email"
                 value="<?= e($email) ?>">
        </div>
        <button class="btn btn-ct w-100" type="submit">Send code</button>
      </form>
    <?php elseif ($step === 'code'): ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="from" value="<?= $resetPortal === 'admin' ? 'admin' : 'public' ?>">
        <input type="hidden" name="verify_code" value="1">
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <?php if (!$fromAccount): ?>
          <div class="mb-3">
            <label class="form-label">Account email</label>
            <input type="email" class="form-control" value="<?= e($email) ?>" disabled>
          </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label">Reset code</label>
          <input type="text" class="form-control" name="code" required
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6"
                 autocomplete="one-time-code" placeholder="6-digit code">
        </div>
        <button class="btn btn-ct w-100" type="submit">Continue</button>
      </form>
      <div class="d-flex flex-wrap justify-content-between gap-2 mt-3 small">
        <form method="post" class="m-0" id="resendCodeForm">
          <?= csrf_field() ?>
          <input type="hidden" name="from" value="<?= $resetPortal === 'admin' ? 'admin' : 'public' ?>">
          <input type="hidden" name="email" value="<?= e($email) ?>">
          <input type="hidden" name="resend_code" value="1">
          <button type="submit" class="btn btn-link btn-sm p-0" id="resendCodeBtn"
                  data-remain="<?= (int) $resendWait ?>"
                  <?= $resendWait > 0 ? 'disabled' : '' ?>>
            <span id="resendCodeLabel"><?= $resendWait > 0 ? 'Resend code in ' . (int) $resendWait . 's' : 'Resend code' ?></span>
          </button>
        </form>
        <?php if (!$fromAccount): ?>
          <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="from" value="<?= $resetPortal === 'admin' ? 'admin' : 'public' ?>">
            <input type="hidden" name="use_different_email" value="1">
            <button type="submit" class="btn btn-link btn-sm p-0">Use a different email</button>
          </form>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="from" value="<?= $resetPortal === 'admin' ? 'admin' : 'public' ?>">
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <div class="mb-3">
          <label class="form-label">New password</label>
          <input type="password" class="form-control" name="password" id="password" required minlength="5" autocomplete="new-password">
          <?= password_rules_hint_html('password') ?>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm password</label>
          <input type="password" class="form-control" name="password_confirm" id="password_confirm" required minlength="5" autocomplete="new-password">
          <?= password_confirm_warn_html('password') ?>
        </div>
        <button class="btn btn-ct w-100" type="submit">Update password</button>
      </form>
    <?php endif; ?>
    <p class="mt-3 mb-0 small text-center"><a href="<?= e($backUrl) ?>">back</a></p>
  </div>
</div>
<?php if ($step === 'password'): ?>
  <?= password_rules_focus_script('password', 'passwordRules') ?>
<?php endif; ?>
<?php if ($step === 'code'): ?>
<script>
(function () {
  var btn = document.getElementById('resendCodeBtn');
  var label = document.getElementById('resendCodeLabel');
  if (!btn || !label) return;
  var remain = parseInt(btn.getAttribute('data-remain') || '0', 10);
  if (!remain || remain < 1) return;

  function tick() {
    if (remain <= 0) {
      btn.disabled = false;
      label.textContent = 'Resend code';
      return;
    }
    btn.disabled = true;
    label.textContent = 'Resend code in ' + remain + 's';
    remain -= 1;
    window.setTimeout(tick, 1000);
  }
  tick();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
