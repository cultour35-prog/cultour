<?php
/**
 * Change password while signed in (Bearer token).
 * POST { current_password, password, password_confirm }
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = api_bearer_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}
ensure_password_schema();
$in = api_input();
$current = (string) ($in['current_password'] ?? '');
$password = (string) ($in['password'] ?? '');
$confirm = (string) ($in['password_confirm'] ?? '');
$forced = user_must_change_password($user);

if (!$forced) {
    if ($current === '') {
        json_response(['ok' => false, 'message' => 'Enter your current password.'], 400);
    }
    if (!password_verify($current, (string) $user['password_hash'])) {
        json_response(['ok' => false, 'message' => 'Current password is incorrect.'], 400);
    }
}
$check = validate_password_rules($password, $confirm);
if (!$check['ok']) {
    json_response(['ok' => false, 'message' => (string) $check['message']], 400);
}
if (password_verify($password, (string) $user['password_hash'])) {
    json_response(['ok' => false, 'message' => 'New password must be different from your current password.'], 400);
}
db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
    ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
log_activity((int) $user['id'], 'password_change', 'auth', 'Password updated (mobile)');
json_response(['ok' => true, 'message' => 'Password updated successfully.']);
