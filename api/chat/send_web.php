<?php
/**
 * Send chat message via AJAX (session auth).
 * POST: with, body, image?, csrf_token
 * Supports tourist ↔ guide and tourist ↔ business (peer stored as guide_id).
 */
require_once __DIR__ . '/../../includes/auth.php';

header('Cache-Control: no-store');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}
if (user_is_suspended($user)) {
    json_response(['ok' => false, 'message' => 'Your account is suspended.', 'suspended' => true], 403);
}

$role = (int) $user['role_id'];
if (!in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
    json_response(['ok' => false, 'message' => 'Forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    json_response(['ok' => false, 'message' => csrf_error_message()], 400);
}

$with = (int) ($_POST['with'] ?? 0);
$body = trim((string) ($_POST['body'] ?? ''));
$hasImage = !empty($_FILES['image']['name']);

if (mb_strlen($body) > CHAT_MESSAGE_MAX_LEN) {
    json_response(['ok' => false, 'message' => 'Message is too long (max ' . CHAT_MESSAGE_MAX_LEN . ' characters).'], 422);
}

$peers = chat_resolve_conversation_peers($user, $with);
if (empty($peers['ok'])) {
    json_response(['ok' => false, 'message' => (string) ($peers['message'] ?? 'Forbidden')], (int) ($peers['code'] ?? 403));
}

if ($body === '' && !$hasImage) {
    json_response(['ok' => false, 'message' => 'Message required'], 422);
}

$myId = (int) $user['id'];
$touristId = (int) $peers['tourist_id'];
$peerId = (int) $peers['peer_id'];
$peerRole = (int) $peers['peer_role'];

$c = db()->prepare('SELECT id FROM conversations WHERE tourist_id=? AND guide_id=?');
$c->execute([$touristId, $peerId]);
$cid = $c->fetchColumn();
if (!$cid) {
    db()->prepare(
        'INSERT INTO conversations (tourist_id, guide_id, last_message_at) VALUES (?,?,NOW())'
    )->execute([$touristId, $peerId]);
    $cid = db()->lastInsertId();
}

$img = null;
if ($hasImage) {
    $img = upload_file($_FILES['image'], 'chat', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$img) {
        json_response(['ok' => false, 'message' => upload_last_error() ?: 'Could not upload that photo.'], 422);
    }
}

db()->prepare(
    'INSERT INTO messages (conversation_id, sender_id, message_type, body, image_path) VALUES (?,?,?,?,?)'
)->execute([
    $cid,
    $myId,
    $img ? 'image' : 'text',
    $body !== '' ? $body : ($img ? '📷 Photo' : null),
    $img,
]);
$msgId = (int) db()->lastInsertId();
db()->prepare('UPDATE conversations SET last_message_at=NOW() WHERE id=?')->execute([$cid]);

if ($role === ROLE_TOURIST) {
    $chatLink = $peerRole === ROLE_BUSINESS
        ? (APP_URL . '/business/chat.php?with=' . $myId)
        : (APP_URL . '/tourguide/chat.php?with=' . $myId);
} else {
    $chatLink = APP_URL . '/tourist/chat.php?with=' . $myId;
}

notify_user(
    $with,
    'new_message',
    'New message from ' . full_name($user),
    $body !== '' ? $body : 'Sent a photo',
    $chatLink
);

$msg = db()->prepare('SELECT * FROM messages WHERE id=?');
$msg->execute([$msgId]);
$m = $msg->fetch();

json_response([
    'ok' => true,
    'message' => [
        'id' => (int) $m['id'],
        'sender_id' => (int) $m['sender_id'],
        'mine' => true,
        'body' => $m['body'],
        'image_path' => $m['image_path'] ? (APP_URL . '/uploads/' . $m['image_path']) : null,
        'is_seen' => (int) $m['is_seen'],
        'time' => format_date($m['created_at'], 'g:i A'),
        'created_at' => $m['created_at'],
    ],
]);
