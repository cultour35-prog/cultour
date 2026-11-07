<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
unset($user['password_hash'], $user['remember_token'], $user['reset_token']);
$user['avatar_url'] = api_upload_url($user['avatar'] ?? null);
json_response(['ok' => true, 'user' => $user]);
