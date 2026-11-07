<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_api_user();
if ((int) $user['role_id'] === ROLE_ADMIN) {
    json_response(['ok' => false, 'message' => 'Administrators cannot change email from the app.'], 403);
}

$in = api_input();
$password = (string) ($in['password'] ?? '');
$newEmail = strtolower(trim((string) ($in['new_email'] ?? $in['email'] ?? '')));

if ($password === '') {
    json_response(['ok' => false, 'message' => 'Enter your password to confirm this change.'], 422);
}
if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
    json_response(['ok' => false, 'message' => 'Password is incorrect.'], 422);
}
if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'message' => 'Please enter a valid email address.'], 422);
}
if ($newEmail === strtolower(trim((string) $user['email']))) {
    json_response(['ok' => false, 'message' => 'That is already your current email.'], 422);
}

$exists = db()->prepare('SELECT id FROM users WHERE email = ? AND role_id = ? AND id <> ? LIMIT 1');
$exists->execute([$newEmail, (int) $user['role_id'], (int) $user['id']]);
if ($exists->fetch()) {
    json_response(['ok' => false, 'message' => 'An account with this email already exists for your account type.'], 422);
}

db()->prepare(
    'UPDATE users SET email = ?, email_verified_at = NULL, verification_token = NULL WHERE id = ?'
)->execute([$newEmail, (int) $user['id']]);
log_activity((int) $user['id'], 'email_change', 'auth', 'Email updated to ' . $newEmail);
if (function_exists('send_user_verification_email')) {
    send_user_verification_email((int) $user['id'], $newEmail, (string) ($user['first_name'] ?? ''));
}

$user['email'] = $newEmail;
json_response([
    'ok' => true,
    'message' => 'Email updated. Check your inbox to verify the new address.',
    'user' => api_public_user($user),
]);
