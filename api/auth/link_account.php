<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

start_app_session();
$in = api_input();
$csrf = (string) ($in[CSRF_TOKEN_NAME] ?? $_POST[CSRF_TOKEN_NAME] ?? '');
if (empty($_SESSION[CSRF_TOKEN_NAME]) || !hash_equals((string) $_SESSION[CSRF_TOKEN_NAME], $csrf)) {
    json_response(['ok' => false, 'message' => csrf_error_message()], 400);
}

$targetRole = (int) ($in['target_role_id'] ?? $in['role_id'] ?? 0);
$result = verify_account_for_registration_link(
    (string) ($in['email'] ?? ''),
    (string) ($in['password'] ?? ''),
    $targetRole
);
json_response($result, $result['ok'] ? 200 : 422);
