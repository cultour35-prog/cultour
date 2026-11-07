<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$in = api_input();
$password = (string) ($in['password'] ?? '');
$bearer = api_bearer_user();

if ($bearer) {
    $src = $bearer;
    if ($password === '' || !password_verify($password, (string) ($src['password_hash'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'Enter your current password to link another role.'], 422);
    }
    $targetRole = counterpart_linkable_role((int) $src['role_id']);
    if ($targetRole < 1) {
        json_response(['ok' => false, 'message' => 'Only Tourist and Tour Guide accounts can be linked.'], 403);
    }
} else {
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $targetRole = (int) ($in['target_role_id'] ?? $in['role_id'] ?? 0);
    $verified = verify_account_for_registration_link($email, $password, $targetRole);
    if (empty($verified['ok'])) {
        json_response($verified, 422);
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int) $verified['source_user_id']]);
    $src = $stmt->fetch();
    if (!$src) {
        json_response(['ok' => false, 'message' => 'That account was not found.'], 404);
    }
}

$email = strtolower(trim((string) ($src['email'] ?? '')));
if (email_taken_for_role($email, $targetRole)) {
    json_response([
        'ok' => false,
        'already_linked' => true,
        'message' => 'This email already has a ' . role_label($targetRole) . ' account. Switch to it from Profile.',
    ], 409);
}

$token = encode_registration_link_token((int) $src['id'], $email, $targetRole);
$payload = api_linked_payload($src, $targetRole, $token, $in);
$result = register_user($payload);
if (empty($result['ok'])) {
    json_response($result, 422);
}

json_response([
    'ok' => true,
    'message' => (string) ($result['message'] ?? ('Your ' . role_label($targetRole) . ' account is ready.')),
    'role_id' => $targetRole,
    'role_name' => role_label($targetRole),
    'user_id' => (int) ($result['user_id'] ?? 0),
    'pending' => (($result['message'] ?? '') !== '' && stripos((string) $result['message'], 'approval') !== false),
]);
