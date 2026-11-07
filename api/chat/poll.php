<?php
/**
 * Poll chat messages (session auth).
 * GET with=USER_ID&after=MESSAGE_ID
 * Supports tourist ↔ guide and tourist ↔ business.
 */
require_once __DIR__ . '/../../includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

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

$with = (int) ($_GET['with'] ?? 0);
$after = (int) ($_GET['after'] ?? 0);

$myId = (int) $user['id'];
$touristId = $myId;
$peerId = $with;
if ($with > 0) {
    $resolved = chat_resolve_conversation_peers($user, $with);
    if (empty($resolved['ok'])) {
        json_response(['ok' => false, 'message' => (string) ($resolved['message'] ?? 'Forbidden')], (int) ($resolved['code'] ?? 403));
    }
    $touristId = (int) $resolved['tourist_id'];
    $peerId = (int) $resolved['peer_id'];
} elseif ($role === ROLE_TOUR_GUIDE || $role === ROLE_BUSINESS) {
    $touristId = $with;
    $peerId = $myId;
}

$cid = 0;
if ($with) {
    $c = db()->prepare('SELECT id FROM conversations WHERE tourist_id=? AND guide_id=?');
    $c->execute([$touristId, $peerId]);
    $cid = (int) $c->fetchColumn();
}
$messages = [];
$unreadPeers = [];
$seenIds = [];
$peers = [];

if ($cid) {
    db()->prepare(
        'UPDATE messages SET is_seen=1, seen_at=NOW() WHERE conversation_id=? AND sender_id!=? AND is_seen=0'
    )->execute([$cid, $myId]);
    mark_notifications_read($myId, 'new_message', 'with=' . $with);

    $stmt = db()->prepare(
        'SELECT id, sender_id, message_type, body, image_path, is_seen, created_at
         FROM messages WHERE conversation_id=? AND id > ? ORDER BY id ASC LIMIT 100'
    );
    $stmt->execute([$cid, $after]);
    foreach ($stmt->fetchAll() as $m) {
        $messages[] = [
            'id' => (int) $m['id'],
            'sender_id' => (int) $m['sender_id'],
            'mine' => (int) $m['sender_id'] === $myId,
            'body' => $m['body'],
            'image_path' => $m['image_path']
                ? (APP_URL . '/uploads/' . $m['image_path'])
                : null,
            'is_seen' => (int) $m['is_seen'],
            'time' => format_date($m['created_at'], 'g:i A'),
            'created_at' => $m['created_at'],
        ];
    }

    $seen = db()->prepare(
        'SELECT id FROM messages WHERE conversation_id=? AND sender_id=? AND is_seen=1'
    );
    $seen->execute([$cid, $myId]);
    $seenIds = array_map('intval', $seen->fetchAll(PDO::FETCH_COLUMN));
}

if ($role === ROLE_TOURIST) {
    $guideRows = db()->query(
        "SELECT u.id AS peer_id, u.first_name, u.last_name, u.avatar,
                (SELECT COUNT(*) FROM messages m
                 JOIN conversations c2 ON c2.id=m.conversation_id
                 WHERE c2.tourist_id={$myId} AND c2.guide_id=u.id
                   AND m.sender_id!=$myId AND m.is_seen=0) AS unread
         FROM tour_guide_profiles g
         JOIN users u ON u.id=g.user_id
         WHERE g.approval_status='approved' AND u.status='active'
           AND " . guide_profile_complete_sql('g') . "
         ORDER BY u.first_name, u.last_name"
    )->fetchAll() ?: [];
    $bizRows = db()->prepare(
        "SELECT u.id AS peer_id, u.first_name, u.last_name, u.avatar,
                COALESCE(b.name, CONCAT(u.first_name,' ',u.last_name)) AS display_name,
                (SELECT COUNT(*) FROM messages m
                 WHERE m.conversation_id=c.id AND m.sender_id!=? AND m.is_seen=0) AS unread
         FROM conversations c
         JOIN users u ON u.id = c.guide_id
         LEFT JOIN businesses b ON b.user_id = u.id
         WHERE c.tourist_id=? AND u.role_id=?
         ORDER BY COALESCE(c.last_message_at, c.id) DESC"
    );
    $bizRows->execute([$myId, $myId, ROLE_BUSINESS]);
    $bizRows = $bizRows->fetchAll() ?: [];
    $rows = array_merge($guideRows, $bizRows);
} else {
    $rows = db()->prepare(
        "SELECT c.tourist_id AS peer_id, u.first_name, u.last_name, u.avatar,
                (SELECT COUNT(*) FROM messages m
                 WHERE m.conversation_id=c.id AND m.sender_id!=? AND m.is_seen=0) AS unread
         FROM conversations c
         JOIN users u ON u.id=c.tourist_id
         WHERE c.guide_id=?
         ORDER BY c.last_message_at DESC"
    );
    $rows->execute([$myId, $myId]);
    $rows = $rows->fetchAll() ?: [];
}

$seenPeer = [];
foreach ($rows as $r) {
    $pid = (int) $r['peer_id'];
    if (isset($seenPeer[$pid])) {
        continue;
    }
    $seenPeer[$pid] = true;
    $unreadPeers[(string) $pid] = (int) $r['unread'];
    $avatarPath = trim((string) ($r['avatar'] ?? ''));
    $name = trim((string) ($r['display_name'] ?? ''));
    if ($name === '') {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    }
    $peers[] = [
        'id' => $pid,
        'name' => $name,
        'avatar' => $avatarPath !== ''
            ? (APP_URL . '/uploads/' . ltrim($avatarPath, '/'))
            : null,
        'initials' => strtoupper(substr((string) ($r['first_name'] ?? ''), 0, 1)
            . substr((string) ($r['last_name'] ?? ''), 0, 1)) ?: '?',
        'unread' => (int) $r['unread'],
    ];
}

json_response([
    'ok' => true,
    'conversation_id' => $cid ?: null,
    'messages' => $messages,
    'seen_ids' => $seenIds,
    'unread' => $unreadPeers,
    'peers' => $peers,
    'me' => $myId,
]);
