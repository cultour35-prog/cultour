<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
ensure_email_role_unique_schema();

$in = api_input();
$email = strtolower(trim((string) ($in['email'] ?? '')));
$password = (string) ($in['password'] ?? '');
$roleId = (int) ($in['role_id'] ?? 0);

$result = attempt_login($email, $password, false, $roleId > 0 ? $roleId : null, 'public');
if (!empty($result['need_role'])) {
    json_response([
        'ok' => false,
        'need_role' => true,
        'message' => (string) ($result['message'] ?? 'Choose an account type.'),
        'roles' => $result['roles'] ?? [],
    ], 409);
}
if (empty($result['ok'])) {
    json_response(
        ['ok' => false, 'message' => (string) ($result['message'] ?? 'Invalid credentials'), 'locked' => !empty($result['locked'])],
        !empty($result['locked']) ? 429 : 401
    );
}

$user = $result['user'];
if ((int) ($user['role_id'] ?? 0) === ROLE_ADMIN) {
    logout_user();
    json_response(['ok' => false, 'message' => 'Invalid credentials'], 401);
}
if (($user['status'] ?? '') === 'suspended') {
    json_response(['ok' => false, 'message' => 'Your account is suspended.', 'suspended' => true], 403);
}
if ($user['status'] !== 'active' && !($user['status'] === 'pending' && (int) $user['role_id'] === ROLE_TOURIST)) {
    json_response(['ok' => false, 'message' => 'Account not active'], 403);
}
$gate = registration_approval_gate($user);
if (!$gate['ok']) {
    json_response(['ok' => false, 'message' => (string) ($gate['message'] ?? 'Account not approved.')], 403);
}
if (user_must_change_password($user)) {
    json_response(['ok' => false, 'message' => 'Please set a new password to continue.', 'must_change_password' => true], 403);
}

$token = bin2hex(random_bytes(32));
db()->prepare('INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
    ->execute([(int) $user['id'], $token]);

unset($user['password_hash'], $user['remember_token'], $user['reset_token']);
json_response(['ok' => true, 'token' => $token, 'user' => $user]);
