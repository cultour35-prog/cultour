<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$current = require_api_user();
$in = api_input();
$targetUserId = (int) ($in['user_id'] ?? $in['target_user_id'] ?? 0);
if ($targetUserId < 1) {
    json_response(['ok' => false, 'message' => 'Choose a linked account to switch to.'], 422);
}

$currentRole = (int) ($current['role_id'] ?? 0);
if (!in_array($currentRole, linkable_role_ids(), true)) {
    json_response(['ok' => false, 'message' => 'Account switching is only available for Tourist and Tour Guide.'], 403);
}

$stmt = db()->prepare(
    'SELECT u.*, r.name AS role_name FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE u.id = ? LIMIT 1'
);
$stmt->execute([$targetUserId]);
$target = $stmt->fetch();
if (!$target) {
    json_response(['ok' => false, 'message' => 'That account was not found.'], 404);
}

$email = strtolower(trim((string) ($current['email'] ?? '')));
$targetEmail = strtolower(trim((string) ($target['email'] ?? '')));
$targetRole = (int) ($target['role_id'] ?? 0);
if ($email === '' || $email !== $targetEmail || !in_array($targetRole, linkable_role_ids(), true)) {
    json_response(['ok' => false, 'message' => 'You can only switch to a linked Tourist or Tour Guide account.'], 403);
}
if (($target['status'] ?? '') === 'rejected') {
    json_response(['ok' => false, 'message' => 'That linked account was rejected.'], 403);
}
if (($target['status'] ?? '') === 'suspended') {
    json_response(['ok' => false, 'message' => 'That linked account is suspended.'], 403);
}

$gate = registration_approval_gate($target);
if (!$gate['ok']) {
    json_response(['ok' => false, 'message' => (string) ($gate['message'] ?? 'That linked account is not available yet.')], 403);
}

db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $target['id']]);
log_activity((int) $target['id'], 'switch_account', 'auth', 'Switched to ' . role_label($targetRole) . ' from app');

$token = api_issue_token((int) $target['id']);
json_response([
    'ok' => true,
    'token' => $token,
    'user' => api_public_user($target),
    'message' => 'Switched to your ' . role_label($targetRole) . ' account.',
]);
