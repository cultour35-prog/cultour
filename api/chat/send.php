<?php
/**
 * Bearer-token chat send (Flutter).
 * POST JSON: receiver_id, body
 * Same tourist ↔ guide/business mapping as send_web.php.
 */
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
$in = api_input();

$role = (int) $user['role_id'];
if (!in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}

$otherId = (int) ($in['receiver_id'] ?? $in['with'] ?? 0);
$body = trim((string) ($in['body'] ?? ''));
$hasImage = !empty($_FILES['image']['name']);
if (($body === '' && !$hasImage) || $otherId < 1) {
    json_response(['ok' => false, 'message' => 'receiver_id and body required'], 422);
}
if (mb_strlen($body) > CHAT_MESSAGE_MAX_LEN) {
    json_response(['ok' => false, 'message' => 'Message is too long (max ' . CHAT_MESSAGE_MAX_LEN . ' characters).'], 422);
}

$myId = (int) $user['id'];
$peers = chat_resolve_conversation_peers($user, $otherId);
if (empty($peers['ok'])) {
    json_response(['ok' => false, 'message' => (string) ($peers['message'] ?? 'Forbidden')], (int) ($peers['code'] ?? 403));
}
$peerRole = (int) $peers['peer_role'];
$touristId = (int) $peers['tourist_id'];
$peerId = (int) $peers['peer_id'];

$c = db()->prepare('SELECT id FROM conversations WHERE tourist_id=? AND guide_id=?');
$c->execute([$touristId, $peerId]);
$cid = $c->fetchColumn();
if (!$cid) {
    db()->prepare('INSERT INTO conversations (tourist_id, guide_id, last_message_at) VALUES (?,?,NOW())')
        ->execute([$touristId, $peerId]);
    $cid = db()->lastInsertId();
}
$img = null;
if ($hasImage) {
    $img = upload_file($_FILES['image'], 'chat', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$img) {
        json_response(['ok' => false, 'message' => upload_last_error() ?: 'Could not upload that photo.'], 422);
    }
}
if ($body === '' && $img) {
    $body = '📷 Photo';
}
db()->prepare('INSERT INTO messages (conversation_id, sender_id, message_type, body, image_path) VALUES (?,?,?,?,?)')
    ->execute([$cid, $myId, $img ? 'image' : 'text', $body, $img]);
db()->prepare('UPDATE conversations SET last_message_at=NOW() WHERE id=?')->execute([$cid]);

if ($role === ROLE_TOURIST) {
    $chatLink = $peerRole === ROLE_BUSINESS
        ? (APP_URL . '/business/chat.php?with=' . $myId)
        : (APP_URL . '/tourguide/chat.php?with=' . $myId);
} else {
    $chatLink = APP_URL . '/tourist/chat.php?with=' . $myId;
}
notify_user($otherId, 'new_message', 'New message from ' . full_name($user), $body, $chatLink);
json_response(['ok' => true]);
