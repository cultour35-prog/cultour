<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = require_api_user();
$roleId = (int) $user['role_id'];
$counterpart = counterpart_linkable_role($roleId);
$accounts = [];
foreach (switchable_linked_accounts($user) as $row) {
    $accounts[] = [
        'id' => (int) $row['id'],
        'role_id' => (int) $row['role_id'],
        'role_name' => (string) ($row['role_name'] ?? role_label((int) $row['role_id'])),
        'status' => (string) ($row['status'] ?? ''),
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
    ];
}

$canCreate = $counterpart > 0 && !email_taken_for_role((string) $user['email'], $counterpart);

json_response([
    'ok' => true,
    'current_role_id' => $roleId,
    'current_role_name' => (string) ($user['role_name'] ?? role_label($roleId)),
    'counterpart_role_id' => $counterpart,
    'counterpart_role_name' => $counterpart > 0 ? role_label($counterpart) : '',
    'can_create' => $canCreate,
    'accounts' => $accounts,
]);
