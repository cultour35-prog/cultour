<?php
/**
 * Mark notifications as read (session or Bearer token).
 * POST/GET { ids?: number[], all?: bool, type?: string }
 */
require_once __DIR__ . '/../../includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();

if (!$user) {
    // Mobile / API token fallback
    require_once __DIR__ . '/../bootstrap.php';
    $user = require_api_user();
}

$in = array_merge($_GET, $_POST);
$raw = json_decode(file_get_contents('php://input') ?: '[]', true);
if (is_array($raw) && $raw) {
    $in = array_merge($in, $raw);
}

$uid = (int) $user['id'];

if (!empty($in['all'])) {
    db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$uid]);
    json_response(['ok' => true, 'marked' => 'all', 'notifications' => 0]);
}

if (!empty($in['type'])) {
    mark_notifications_read($uid, (string) $in['type']);
    json_response([
        'ok' => true,
        'marked' => 'type',
        'type' => $in['type'],
        'notifications' => unread_notifications($uid),
    ]);
}

$ids = $in['ids'] ?? [];
if (!is_array($ids) || !$ids) {
    json_response(['ok' => false, 'message' => 'Provide ids, type, or all=true'], 422);
}
$ids = array_values(array_filter(array_map('intval', $ids)));
if (!$ids) {
    json_response(['ok' => false, 'message' => 'No valid ids'], 422);
}
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = array_merge([$uid], $ids);
db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND id IN ($placeholders)")->execute($params);
json_response([
    'ok' => true,
    'marked' => count($ids),
    'notifications' => unread_notifications($uid),
]);
