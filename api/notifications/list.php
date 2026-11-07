<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
$stmt = db()->prepare('SELECT id, type, title, body, link, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$user['id']]);
json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
