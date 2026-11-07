<?php
/**
 * Submit a suspension appeal (session user must be suspended).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/suspension.php';

$user = require_login();
ensure_suspension_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', csrf_error_message());
    redirect(role_dashboard((int) $user['role_id']));
}

if (!user_is_suspended($user)) {
    flash('error', 'Your account is not suspended.');
    redirect(role_dashboard((int) $user['role_id']));
}

if (pending_suspension_appeal((int) $user['id'])) {
    flash('error', 'You already have an appeal waiting for review.');
    redirect(role_dashboard((int) $user['role_id']));
}

$message = trim($_POST['appeal_message'] ?? '');
if ($message === '') {
    flash('error', 'Please explain why your account should be restored.');
    redirect(role_dashboard((int) $user['role_id']));
}
$len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
if ($len > 2000) {
    flash('error', 'Appeal message is too long (max 2000 characters).');
    redirect(role_dashboard((int) $user['role_id']));
}

db()->prepare(
    'INSERT INTO suspension_appeals (user_id, appeal_message, status) VALUES (?,?,?)'
)->execute([(int) $user['id'], $message, 'pending']);

$admins = db()->query(
    'SELECT id FROM users WHERE role_id=' . ROLE_ADMIN . " AND status='active'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($admins as $adminId) {
    notify_user(
        (int) $adminId,
        'account',
        'Suspension appeal from ' . full_name($user),
        $message,
        APP_URL . '/admin/appeals.php'
    );
}

log_activity((int) $user['id'], 'appeal_suspend', 'users', 'Submitted suspension appeal');
flash('success', 'Your appeal was submitted. The Tourism Office will review it.');
redirect(role_dashboard((int) $user['role_id']));
