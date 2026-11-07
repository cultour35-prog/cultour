<?php
/**
 * Live admin registration lists (guides / businesses / users).
 * GET ?kind=guides|businesses|users
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin_registrations.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_ADMIN) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$kind = $_GET['kind'] ?? 'guides';
if (!in_array($kind, ['guides', 'businesses', 'users', 'tourists'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid kind'], 400);
}

if ($kind === 'users') {
    json_response(admin_users_live_payload([
        'q' => $_GET['q'] ?? '',
        'role' => $_GET['role'] ?? '',
        'status' => array_key_exists('status', $_GET) ? trim((string) ($_GET['status'] ?? '')) : 'active',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
    ], (int) $user['id']));
}

if ($kind === 'tourists') {
    json_response(admin_tourists_live_payload());
}

json_response(admin_registrations_live_payload($kind));
