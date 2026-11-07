<?php
require_once __DIR__ . '/../bootstrap.php';

$in = api_input();
$check = normalize_ph_phone((string) ($in['phone'] ?? ''), false);
if (!$check['ok'] || $check['phone'] === null) {
    json_response(['ok' => true, 'available' => true, 'message' => null]);
}

$roleId = (int) ($in['role_id'] ?? 0);
$taken = phone_taken_by_other_account((string) $check['phone'], (string) ($in['email'] ?? ''), null, $roleId > 0 ? $roleId : null);
json_response([
    'ok' => true,
    'available' => !$taken,
    'message' => $taken ? phone_taken_message() : null,
]);
