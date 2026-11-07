<?php
/**
 * Confirm the email address on a CulTour account.
 */
require_once __DIR__ . '/includes/auth.php';

$uid = (int) ($_GET['uid'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));

if ($uid < 1 || $token === '' || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
    flash('error', 'This verification link is invalid or incomplete.');
    redirect(APP_URL . '/login.php');
}

$st = db()->prepare('SELECT id, email, email_verified_at, verification_token FROM users WHERE id=? LIMIT 1');
$st->execute([$uid]);
$user = $st->fetch();
if (!$user) {
    flash('error', 'This verification link is invalid.');
    redirect(APP_URL . '/login.php');
}

if (!empty($user['email_verified_at']) && (string) ($user['verification_token'] ?? '') === '') {
    flash('success', 'Your email is already verified. You can log in.');
    redirect(APP_URL . '/login.php');
}

if (!hash_equals((string) ($user['verification_token'] ?? ''), $token)) {
    flash('error', 'This verification link has expired. Sign in and request a new email if needed.');
    redirect(APP_URL . '/login.php');
}

db()->prepare('UPDATE users SET email_verified_at=NOW(), verification_token=NULL WHERE id=?')
    ->execute([$uid]);
log_activity($uid, 'verify_email', 'auth', 'Email verified');
flash('success', 'Email verified. You can log in to CulTour.');
redirect(APP_URL . '/login.php');
