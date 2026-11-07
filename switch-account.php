<?php
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
if (!in_array((int) $user['role_id'], linkable_role_ids(), true)) {
    flash('error', 'Account switching is only available for Tourist and Tour Guide.');
    redirect(role_dashboard((int) $user['role_id']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', csrf_error_message());
    redirect(role_dashboard((int) $user['role_id']));
}

$targetUserId = (int) ($_POST['target_user_id'] ?? 0);
$targetRoleId = (int) ($_POST['role_id'] ?? 0);

if ($targetUserId < 1 && $targetRoleId > 0) {
    $siblings = linked_role_accounts((string) $user['email'], (int) $user['id']);
    foreach ($siblings as $sib) {
        if ((int) ($sib['role_id'] ?? 0) === $targetRoleId) {
            $targetUserId = (int) $sib['id'];
            break;
        }
    }
}

$result = switch_to_linked_account($targetUserId);
if (!$result['ok']) {
    flash('error', (string) ($result['message'] ?? 'Could not switch accounts.'));
    redirect(role_dashboard((int) $user['role_id']));
}

$switched = $result['user'];
flash('success', 'Switched to ' . role_label((int) $switched['role_id']) . '.');
redirect(role_dashboard((int) $switched['role_id']));
