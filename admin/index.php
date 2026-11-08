<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/suspension.php';
ensure_password_schema();
start_app_session();
try_remember_login();
if ($u = current_user()) {
    if ((int) $u['role_id'] === ROLE_ADMIN) {
        if ($setup = account_setup_url($u)) {
            redirect($setup);
        }
        redirect(role_dashboard(ROLE_ADMIN));
    }
}

$error = null;
$emailPosted = (string) ($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = csrf_error_message();
    } else {
        $result = attempt_login(
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            isset($_POST['remember']),
            ROLE_ADMIN,
            'admin'
        );
        if ($result['ok']) {
            $logged = $result['user'];
            if ($setup = account_setup_url($logged)) {
                redirect($setup);
            }
            redirect(role_dashboard(ROLE_ADMIN));
        }
        $error = $result['message'] ?? 'Invalid email or password.';
    }
}

$pageTitle = 'Admin login';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-shell auth-shell--back">
  <div class="auth-hero d-none d-lg-flex">
    <div>
      <div class="small text-uppercase opacity-75 mb-2">Ormoc City Tourism Office</div>
      <h1>CulTour</h1>
      <p class="mb-0 opacity-90" style="max-width:28rem">Tourism office sign-in for managing government sites, businesses, and bookings.</p>
    </div>
  </div>
  <div class="auth-panel">
    <div class="glass p-4 p-md-5 w-100 fade-up" style="max-width:440px">
      <h2 class="h3 mb-1">Admin sign in</h2>
      <p class="text-muted mb-4">Tourism office staff only.</p>

      <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success" data-autohide="1"><?= e($msg) ?></div>
      <?php endif; ?>
      <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger"><?= e($msg) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate id="adminLoginForm">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="loginEmail">Email</label>
          <input type="email" name="email" id="loginEmail" class="form-control" required maxlength="190" value="<?= e($emailPosted) ?>" autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label" for="loginPassword">Password</label>
          <input type="password" name="password" id="loginPassword" class="form-control" required autocomplete="current-password" aria-describedby="loginCapsHint">
          <div class="form-text text-warning d-none" id="loginCapsHint" aria-live="polite">Caps Lock is on.</div>
        </div>
        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" name="remember" id="remember">
          <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <button class="btn btn-ct w-100 mb-3" type="submit">Login</button>
      </form>
      <p class="mb-0 small text-center"><a href="<?= APP_URL ?>/forgot-password.php?from=admin">Forgot password?</a></p>
    </div>
  </div>
</div>
<script>
(function () {
  var passInput = document.getElementById('loginPassword');
  var capsHint = document.getElementById('loginCapsHint');
  if (!passInput || !capsHint) return;
  ['keydown', 'keyup'].forEach(function (evt) {
    passInput.addEventListener(evt, function (e) {
      if (typeof e.getModifierState !== 'function') return;
      capsHint.classList.toggle('d-none', !e.getModifierState('CapsLock'));
    });
  });
  passInput.addEventListener('blur', function () { capsHint.classList.add('d-none'); });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
