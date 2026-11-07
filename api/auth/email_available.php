<?php
require_once __DIR__ . '/../bootstrap.php';
ensure_email_role_unique_schema();

$in = api_input();
$email = strtolower(trim((string) ($in['email'] ?? '')));
$roleId = (int) ($in['role_id'] ?? 0);
if ($roleId < 1) {
    $roleId = ROLE_TOURIST;
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response([
        'ok' => true,
        'available' => true,
        'counterpart_taken' => false,
        'counterpart_role_id' => 0,
        'counterpart_role_label' => null,
        'counterpart_in_leyte' => null,
        'message' => null,
        'counterpart_message' => null,
    ]);
}

$taken = email_taken_for_role($email, $roleId);
$counterpartId = counterpart_linkable_role($roleId);
$counterpartTaken = $counterpartId > 0 && email_taken_for_role($email, $counterpartId);
$counterpartLabel = $counterpartTaken ? role_label($counterpartId) : null;
$counterpartInLeyte = null;
if ($counterpartTaken) {
    $src = db()->prepare('SELECT * FROM users WHERE email=? AND role_id=? LIMIT 1');
    $src->execute([$email, $counterpartId]);
    $srcRow = $src->fetch() ?: null;
    $counterpartInLeyte = $srcRow ? user_current_address_in_leyte($srcRow) : false;
}

json_response([
    'ok' => true,
    'available' => !$taken,
    'counterpart_taken' => $counterpartTaken,
    'counterpart_role_id' => $counterpartTaken ? $counterpartId : 0,
    'counterpart_role_label' => $counterpartLabel,
    'counterpart_in_leyte' => $counterpartInLeyte,
    'message' => $taken
        ? ('This email is already registered as ' . role_label($roleId) . '.')
        : null,
    'counterpart_message' => $counterpartTaken
        ? ('This email already exists as a ' . $counterpartLabel . '. You can link them instead of creating a separate account.')
        : null,
]);
