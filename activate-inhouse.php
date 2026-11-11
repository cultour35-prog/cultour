<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inhouse_guides.php';
inhouse_guides_ensure_schema();

$uid = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$st = db()->prepare(
    "SELECT i.id, i.token_hash, i.expires_at, i.used_at, u.email, u.status
     FROM guide_invitations i JOIN users u ON u.id = i.user_id
     WHERE i.user_id=? ORDER BY i.id DESC LIMIT 1"
);
$st->execute([$uid]);
$invite = $st->fetch();
$valid = $invite
    && $token !== ''
    && hash_equals((string) $invite['token_hash'], hash('sha256', $token))
    && empty($invite['used_at'])
    && strtotime((string) $invite['expires_at']) >= time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid && verify_csrf()) {
    $password = (string) ($_POST['password'] ?? '');
    $again = (string) ($_POST['password_again'] ?? '');
    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters.');
    } elseif ($password !== $again) {
        flash('error', 'Passwords do not match.');
    } else {
        db()->prepare("UPDATE users SET password_hash=?, status='active', email_verified_at=COALESCE(email_verified_at, NOW()), verification_token=NULL, must_change_password=0 WHERE id=?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        db()->prepare('UPDATE guide_invitations SET used_at=NOW() WHERE id=?')->execute([(int) $invite['id']]);
        flash('success', 'Account ready. Log in with your email.');
        redirect(APP_URL . '/login.php');
    }
    redirect(APP_URL . '/activate-inhouse.php?uid=' . $uid . '&token=' . urlencode($token));
}

$pageTitle = 'Activate guide account';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width: 32rem;">
  <div class="glass p-4">
    <h1 class="h4">Activate your guide account</h1>
    <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
    <?php if (!$valid): ?>
      <p class="mb-0">This invitation link is invalid or already used.</p>
    <?php else: ?>
      <p class="small text-muted">Set a password for <?= e((string) $invite['email']) ?>. You will use the tour guide dashboard.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="uid" value="<?= $uid ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-2"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required minlength="8"></div>
        <div class="mb-3"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="password_again" required minlength="8"></div>
        <button class="btn btn-ct" type="submit">Activate</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php';
