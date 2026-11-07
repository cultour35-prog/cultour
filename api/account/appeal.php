<?php
/**
 * Submit suspension appeal via AJAX (session auth).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/suspension.php';

header('Cache-Control: no-store');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    json_response(['ok' => false, 'message' => csrf_error_message()], 400);
}

ensure_suspension_schema();

if (!user_is_suspended($user)) {
    json_response(['ok' => false, 'message' => 'Your account is not suspended.'], 422);
}

if (pending_suspension_appeal((int) $user['id'])) {
    json_response(['ok' => false, 'message' => 'You already have an appeal waiting for review.'], 422);
}

$message = trim($_POST['appeal_message'] ?? '');
if ($message === '') {
    json_response(['ok' => false, 'message' => 'Please explain why your account should be restored.'], 422);
}
if (function_exists('mb_strlen') ? mb_strlen($message) > 2000 : strlen($message) > 2000) {
    json_response(['ok' => false, 'message' => 'Appeal message is too long (max 2000 characters).'], 422);
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

json_response(array_merge(
    account_status_payload($user),
    ['ok' => true, 'message' => 'Your appeal was submitted. The Tourism Office will review it.']
));
