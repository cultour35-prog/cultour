<?php
/**
 * Admin suspended accounts + appeals live list / AJAX actions.
 * GET  ?status=suspended|pending|approved|denied|all
 * POST action=approve|deny|activate_user …
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/suspension.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_ADMIN) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

ensure_suspension_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['ok' => false, 'message' => csrf_error_message()], 400);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'activate_user') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $result = activate_user_account(
            $uid,
            (int) $user['id'],
            'Your CulTour account has been reactivated by the Tourism Office.'
        );
        if ($result['ok']) {
            log_activity((int) $user['id'], 'activate_user', 'users', 'User #' . $uid);
        }
    } else {
        $result = review_suspension_appeal(
            (int) ($_POST['appeal_id'] ?? 0),
            (int) $user['id'],
            $action,
            (string) ($_POST['admin_response'] ?? '')
        );
    }

    if (!$result['ok']) {
        json_response($result, 422);
    }
    $filter = $_POST['filter'] ?? $_GET['status'] ?? 'suspended';
    $list = admin_appeals_list_html((string) $filter);
    json_response([
        'ok' => true,
        'message' => $result['message'],
        'html' => $list['html'],
        'version' => $list['version'],
        'pending' => $list['pending'],
    ]);
}

$filter = $_GET['status'] ?? 'suspended';
$list = admin_appeals_list_html((string) $filter);
json_response([
    'ok' => true,
    'html' => $list['html'],
    'version' => $list['version'],
    'pending' => $list['pending'],
    'filter' => $list['filter'],
]);
