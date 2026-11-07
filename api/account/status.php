<?php
/**
 * Live account status for suspension / appeal UI (session auth).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/suspension.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

if ((int) $user['role_id'] === ROLE_ADMIN) {
    require_once __DIR__ . '/../../includes/admin_registrations.php';
    json_response([
        'ok' => true,
        'status' => 'active',
        'suspended' => false,
        'version' => 'admin',
        'html' => '',
        'pending_appeals' => pending_appeal_count(),
        'pending_guides' => pending_guide_application_count(),
        'pending_businesses' => pending_business_application_count(),
    ]);
}

// Dismiss appeal banner (session auth is enough; used by beacon on nav/logout)
$dismiss = (string) ($_POST['dismiss'] ?? $_GET['dismiss'] ?? '');
if ($dismiss !== '' && str_starts_with($dismiss, 'appeal_')) {
    dismiss_appeal_notice((int) $user['id'], $dismiss);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['dismiss'])) {
        json_response(['ok' => true, 'dismissed' => true, 'version' => account_status_version($user)]);
    }
}

json_response(account_status_payload($user));
