<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$in = api_input();
$action = strtolower(trim((string) ($in['action'] ?? 'request')));
$email = strtolower(trim((string) ($in['email'] ?? '')));
$code = (string) ($in['code'] ?? '');

if ($action === 'request') {
    json_response(request_password_reset($email));
}

if ($action === 'verify') {
    $result = verify_password_reset_code($email, $code);
    unset($result['code_hash']);
    json_response($result, !empty($result['ok']) ? 200 : 422);
}

if ($action === 'reset') {
    $verified = verify_password_reset_code($email, $code);
    if (empty($verified['ok'])) {
        json_response($verified, 422);
    }
    $result = reset_password(
        $email,
        (string) ($verified['code_hash'] ?? ''),
        (string) ($in['password'] ?? ''),
        (string) ($in['password_confirm'] ?? ($in['password'] ?? ''))
    );
    json_response($result, !empty($result['ok']) ? 200 : 422);
}

json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
