<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOUR_GUIDE);
$pageTitle = 'Messages';
$pageHeading = 'Chat with Tourists';
$activeNav = 'chat';

// Direct thread open (?with=). Also accept ?info= so older/broken peer links still work.
$with = (int) ($_GET['with'] ?? 0);
if ($with <= 0) {
    $with = (int) ($_GET['info'] ?? 0);
}
if ($with > 0 && (string) ($_GET['info'] ?? '') !== '' && (string) ($_GET['with'] ?? '') === '') {
    redirect(APP_URL . '/tourguide/chat.php?with=' . $with);
}

$cons = db()->prepare(
    "SELECT c.id, c.tourist_id, c.guide_id, c.last_message_at,
            u.first_name, u.last_name, u.avatar
     FROM conversations c
     JOIN users u ON u.id = c.tourist_id
     WHERE c.guide_id = ?
     ORDER BY COALESCE(c.last_message_at, c.id) DESC"
);
$cons->execute([(int) $user['id']]);
$cons = $cons->fetchAll() ?: [];

$messages = [];
$lastId = 0;
$peerName = 'Tourist';
$peerAvatar = null;
$peerInitials = '?';
if ($with) {
    $c = db()->prepare('SELECT id FROM conversations WHERE tourist_id=? AND guide_id=?');
    $c->execute([$with, (int) $user['id']]);
    $cid = $c->fetchColumn();
    if ($cid) {
        db()->prepare(
            'UPDATE messages SET is_seen=1, seen_at=NOW() WHERE conversation_id=? AND sender_id!=? AND is_seen=0'
        )->execute([$cid, (int) $user['id']]);
        mark_notifications_read((int) $user['id'], 'new_message', 'with=' . $with);
        $m = db()->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY created_at ASC, id ASC');
        $m->execute([$cid]);
        $messages = $m->fetchAll() ?: [];
        if ($messages) {
            $lastId = (int) $messages[count($messages) - 1]['id'];
        }
    }
    $tn = db()->prepare('SELECT first_name, last_name, avatar FROM users WHERE id=?');
    $tn->execute([$with]);
    $trow = $tn->fetch();
    if ($trow) {
        $peerName = trim($trow['first_name'] . ' ' . $trow['last_name']);
        $peerAvatar = !empty($trow['avatar'])
            ? (APP_URL . '/uploads/' . ltrim((string) $trow['avatar'], '/'))
            : null;
        $peerInitials = strtoupper(substr((string) $trow['first_name'], 0, 1)
            . substr((string) $trow['last_name'], 0, 1)) ?: '?';
    }
}

$chatJs = APP_URL . '/assets/js/chat.js?v=4';
$extraScripts = '<script src="' . $chatJs . '"></script>'
    . '<script>CulTourChat.init(' . json_encode([
        'appUrl' => APP_URL,
        'withId' => $with,
        'lastId' => $lastId,
        'csrf' => csrf_token(),
        'peerParam' => 'with',
        'peerBase' => APP_URL . '/tourguide/chat.php?with=',
    ], JSON_UNESCAPED_SLASHES) . ');</script>';

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <div class="row g-3">
        <div class="col-md-4"><div class="glass p-3">
          <h3 class="h6">Conversations</h3>
          <div id="chatPeerList">
          <?php foreach ($cons as $c):
            $tid = (int) $c['tourist_id'];
            $ucount = unread_in_conversation((int) $c['id'], (int) $user['id']);
            $isActive = $with === $tid;
            $cname = trim($c['first_name'] . ' ' . $c['last_name']);
            $cAvatar = !empty($c['avatar'])
                ? (APP_URL . '/uploads/' . ltrim((string) $c['avatar'], '/'))
                : null;
            $cInitials = strtoupper(substr((string) $c['first_name'], 0, 1)
                . substr((string) $c['last_name'], 0, 1)) ?: '?';
          ?>
            <a class="chat-peer <?= $isActive ? 'is-active' : '' ?> <?= $ucount ? 'has-unread' : '' ?>"
               href="<?= e(APP_URL) ?>/tourguide/chat.php?with=<?= $tid ?>"
               data-peer-id="<?= $tid ?>">
              <span class="chat-peer__who">
                <span class="chat-peer__avatar">
                  <?php if ($cAvatar): ?>
                    <img src="<?= e($cAvatar) ?>" alt="">
                  <?php else: ?>
                    <?= e($cInitials) ?>
                  <?php endif; ?>
                </span>
                <span class="chat-peer__name"><?= e($cname) ?></span>
              </span>
              <?php if ($ucount > 0): ?>
                <span class="badge bg-danger rounded-pill chat-unread"><?= $ucount ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
          <?php if (!$cons): ?><div class="text-muted chat-empty">No conversations yet.</div><?php endif; ?>
          </div>
        </div></div>
        <div class="col-md-8"><div class="glass p-3">
          <?php if (!$with): ?>
            <div class="text-muted p-4">Select a tourist conversation.</div>
          <?php else: ?>
            <div class="chat-thread-head">
              <span class="chat-thread-head__avatar">
                <?php if ($peerAvatar): ?>
                  <img src="<?= e($peerAvatar) ?>" alt="">
                <?php else: ?>
                  <?= e($peerInitials) ?>
                <?php endif; ?>
              </span>
              <span class="chat-thread-head__name"><?= e($peerName) ?></span>
            </div>
            <div class="chat-box" id="chatBox">
              <?php if (!$messages): ?>
                <div class="text-muted small">No messages yet. Say hello.</div>
              <?php endif; ?>
              <?php foreach ($messages as $msg): ?>
                <div class="bubble <?= (int) $msg['sender_id'] === (int) $user['id'] ? 'me' : 'them' ?>" data-msg-id="<?= (int) $msg['id'] ?>">
                  <?php if ($msg['body']): ?><div><?= e($msg['body']) ?></div><?php endif; ?>
                  <?php if ($msg['image_path']): ?><img src="<?= APP_URL ?>/uploads/<?= e($msg['image_path']) ?>" class="img-fluid rounded mt-1" style="max-height:160px" alt=""><?php endif; ?>
                  <div class="small opacity-75 mt-1"><?= e(format_date($msg['created_at'], 'g:i A')) ?><?= $msg['is_seen'] && (int) $msg['sender_id'] === (int) $user['id'] ? ' · Seen' : '' ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <form id="chatForm" class="mt-2" enctype="multipart/form-data"><?= csrf_field() ?>
              <input type="hidden" name="with" value="<?= $with ?>">
              <div class="input-group">
                <input class="form-control" name="body" placeholder="Type a message…" autocomplete="off">
                <input type="file" name="image" class="form-control" style="max-width:150px" accept="image/*">
                <button type="submit" class="btn btn-ct">Send</button>
              </div>
            </form>
          <?php endif; ?>
        </div></div>
      </div>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
