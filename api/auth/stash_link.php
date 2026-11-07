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

$action = strtolower(trim((string) ($in['action'] ?? 'stash')));
if ($action === 'clear') {
    clear_pending_registration_link();
    json_response(['ok' => true, 'cleared' => true]);
}

$targetRole = (int) ($in['target_role_id'] ?? $in['role_id'] ?? 0);
$token = trim((string) ($in['token'] ?? $in['link_token'] ?? ''));
$sourceId = (int) ($in['source_user_id'] ?? $in['link_source_user_id'] ?? 0);
$resolved = resolve_registration_link([
    'link_token' => $token,
    'link_source_user_id' => $sourceId,
], $targetRole);
if (empty($resolved['ok']) || empty($resolved['user'])) {
    json_response([
        'ok' => false,
        'message' => (string) ($resolved['message'] ?? 'Your account link expired. Please link again.'),
    ], 422);
}

$src = $resolved['user'];
$ident = user_link_identity($src);
$email = strtolower(trim((string) ($src['email'] ?? '')));
$ident['email'] = $email;
$missing = registration_link_missing_fields($ident, $targetRole);
$wantComplete = !empty($in['complete']) && !$missing;

store_pending_registration_link([
    'target_role_id' => $targetRole,
    'token' => $token,
    'source_user_id' => $sourceId,
    'email' => $email,
    'source_role_label' => role_label((int) ($src['role_id'] ?? 0)),
    'identity' => $ident,
    'missing' => $missing,
]);

if ($wantComplete) {
    $res = complete_linked_registration($token, $sourceId, $targetRole);
    if (!empty($res['ok'])) {
        clear_pending_registration_link();
        flash('success', (string) ($res['message'] ?? 'Account created successfully. You may now log in.'));
        json_response([
            'ok' => true,
            'auto_registered' => true,
            'redirect' => APP_URL . '/login.php',
            'message' => $res['message'] ?? '',
        ]);
    }
    if (empty($res['needs_form'])) {
        json_response([
            'ok' => false,
            'message' => (string) ($res['message'] ?? 'Could not finish linked registration.'),
        ], 422);
    }
}

json_response([
    'ok' => true,
    'auto_registered' => false,
    'missing' => $missing,
    'redirect' => APP_URL . '/register.php?role=' . $targetRole,
]);
