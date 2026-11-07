<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(\w+)/', $header, $m)) {
    db()->prepare('DELETE FROM api_tokens WHERE token=? AND user_id=?')->execute([$m[1], $user['id']]);
}
json_response(['ok' => true]);
