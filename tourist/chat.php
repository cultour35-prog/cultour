<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOURIST);
$pageTitle = 'Messages';
$pageHeading = 'Messages';
$activeNav = 'chat';

$with = (int) ($_GET['with'] ?? 0);
$info = (int) ($_GET['info'] ?? 0);
$chatInCard = $with > 0 && (string) ($_GET['from'] ?? '') === 'profile';
// Chat only when explicitly opened via Chat button (?with=). Profile otherwise.
if ($with && $info) {
    $info = 0;
}

$guides = db()->query(
    "SELECT u.id, u.first_name, u.last_name, u.avatar, u.phone,
            g.id AS profile_id, g.bio, g.languages, g.years_experience, g.hourly_rate,
            g.license_number, g.avg_rating, g.total_tours,
            (SELECT c.id FROM conversations c WHERE c.tourist_id=" . (int) $user['id'] . " AND c.guide_id=u.id LIMIT 1) AS conv_id
     FROM tour_guide_profiles g
     JOIN users u ON u.id=g.user_id
     WHERE g.approval_status='approved' AND u.status='active'
       AND " . guide_profile_complete_sql('g') . "
     ORDER BY u.first_name, u.last_name"
)->fetchAll() ?: [];

$guidesById = [];
foreach ($guides as $g) {
    $guidesById[(int) $g['id']] = $g;
}

$bizPeers = db()->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.avatar, b.name AS business_name, c.id AS conv_id
     FROM conversations c
     JOIN users u ON u.id = c.guide_id
     LEFT JOIN businesses b ON b.user_id = u.id
     WHERE c.tourist_id=? AND u.role_id=?
     ORDER BY COALESCE(c.last_message_at, c.id) DESC"
);
$bizPeers->execute([(int) $user['id'], ROLE_BUSINESS]);
$bizPeers = $bizPeers->fetchAll() ?: [];
$bizById = [];
foreach ($bizPeers as $bp) {
    $bizById[(int) $bp['id']] = $bp;
}

$infoGuide = ($info && isset($guidesById[$info])) ? $guidesById[$info] : null;
if ($info && !$infoGuide) {
    flash('error', 'Guide not found.');
    redirect(APP_URL . '/tourist/chat.php');
}

$messages = [];
$lastId = 0;
$chatPeerIsBusiness = $with > 0 && isset($bizById[$with]);
$chatPeerIsGuide = $with > 0 && isset($guidesById[$with]);
if ($with && !$chatPeerIsGuide && !$chatPeerIsBusiness) {
    // Allow opening a business thread even if peer list missed it (fresh inquiry).
    $peek = db()->prepare(
        'SELECT u.id, u.first_name, u.last_name, u.avatar, u.role_id, b.name AS business_name
         FROM users u
         LEFT JOIN businesses b ON b.user_id = u.id
         WHERE u.id=?'
    );
    $peek->execute([$with]);
    $peek = $peek->fetch();
    if ($peek && (int) $peek['role_id'] === ROLE_BUSINESS) {
        $chatPeerIsBusiness = true;
        $bizById[$with] = [
            'id' => (int) $peek['id'],
            'first_name' => $peek['first_name'],
            'last_name' => $peek['last_name'],
            'avatar' => $peek['avatar'],
            'business_name' => $peek['business_name'],
            'conv_id' => null,
        ];
    }
}

if ($with) {
    $c = db()->prepare('SELECT id FROM conversations WHERE tourist_id=? AND guide_id=?');
    $c->execute([$user['id'], $with]);
    $cid = $c->fetchColumn();
    if ($cid) {
        db()->prepare(
            'UPDATE messages SET is_seen=1, seen_at=NOW() WHERE conversation_id=? AND sender_id!=? AND is_seen=0'
        )->execute([$cid, $user['id']]);
        mark_notifications_read((int) $user['id'], 'new_message', 'with=' . $with);
    }
    if ($cid) {
        $m = db()->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY created_at ASC');
        $m->execute([$cid]);
        $messages = $m->fetchAll();
        if ($messages) {
            $lastId = (int) $messages[count($messages) - 1]['id'];
        }
    }
}

$myItineraries = db()->prepare(
    'SELECT i.id, i.title, i.start_date, i.end_date, i.meetup_time, i.kept_as_draft, i.status,
            (SELECT COUNT(*) FROM itinerary_items ii WHERE ii.itinerary_id = i.id) AS stop_count
     FROM itineraries i
     WHERE i.user_id=? AND i.is_suggested=0 AND ' . itinerary_sql_is_saved_tour('i') . '
     ORDER BY i.kept_as_draft DESC, i.created_at DESC'
);
$myItineraries->execute([(int) $user['id']]);
$myItineraries = $myItineraries->fetchAll() ?: [];

$extraScripts = '<script src="' . APP_URL . '/assets/js/chat.js?v=5"></script>'
    . '<script>CulTourChat.init(' . json_encode([
        'appUrl' => APP_URL,
        'withId' => $with,
        'infoId' => $info,
        'lastId' => $lastId,
        'csrf' => csrf_token(),
        'peerParam' => 'with',
        'peerBase' => APP_URL . '/tourist/chat.php?with=',
    ], JSON_UNESCAPED_SLASHES) . ');</script>';

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <div class="row g-3">
        <div class="col-md-4">
          <div class="glass p-3">
            <h3 class="h6">Guides</h3>
            <div id="chatPeerList">
            <?php if (!$guides && !$bizPeers): ?>
              <p class="chat-empty text-muted small mb-0">No conversations yet.</p>
            <?php endif; ?>
            <?php foreach ($guides as $g):
              $gid = (int) $g['id'];
              $ucount = $g['conv_id'] ? unread_in_conversation((int) $g['conv_id'], (int) $user['id']) : 0;
              $gname = trim($g['first_name'] . ' ' . $g['last_name']);
              $isActive = $with === $gid || $info === $gid;
              $gAvatar = !empty($g['avatar'])
                  ? (APP_URL . '/uploads/' . ltrim((string) $g['avatar'], '/'))
                  : null;
              $gInitials = strtoupper(substr((string) $g['first_name'], 0, 1)
                  . substr((string) $g['last_name'], 0, 1)) ?: '?';
            ?>
              <a class="chat-peer <?= $isActive ? 'is-active' : '' ?> <?= $ucount ? 'has-unread' : '' ?>"
                 href="?with=<?= $gid ?>"
                 data-peer-id="<?= $gid ?>">
                <span class="chat-peer__who">
                  <span class="chat-peer__avatar">
                    <?php if ($gAvatar): ?>
                      <img src="<?= e($gAvatar) ?>" alt="">
                    <?php else: ?>
                      <?= e($gInitials) ?>
                    <?php endif; ?>
                  </span>
                  <span class="chat-peer__name"><?= e($gname) ?></span>
                </span>
                <?php if ($ucount > 0): ?>
                  <span class="badge bg-danger rounded-pill chat-unread"><?= $ucount ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
            <?php if ($bizPeers): ?>
              <h3 class="h6 mt-3">Businesses</h3>
              <?php foreach ($bizPeers as $bp):
                $bid = (int) $bp['id'];
                $ucount = $bp['conv_id'] ? unread_in_conversation((int) $bp['conv_id'], (int) $user['id']) : 0;
                $bname = trim((string) ($bp['business_name'] ?? '')) ?: trim($bp['first_name'] . ' ' . $bp['last_name']);
                $isActive = $with === $bid;
                $bAvatar = !empty($bp['avatar'])
                    ? (APP_URL . '/uploads/' . ltrim((string) $bp['avatar'], '/'))
                    : null;
                $bInitials = strtoupper(substr((string) $bp['first_name'], 0, 1)
                    . substr((string) $bp['last_name'], 0, 1)) ?: '?';
              ?>
                <a class="chat-peer <?= $isActive ? 'is-active' : '' ?> <?= $ucount ? 'has-unread' : '' ?>"
                   href="?with=<?= $bid ?>"
                   data-peer-id="<?= $bid ?>">
                  <span class="chat-peer__who">
                    <span class="chat-peer__avatar">
                      <?php if ($bAvatar): ?>
                        <img src="<?= e($bAvatar) ?>" alt="">
                      <?php else: ?>
                        <?= e($bInitials) ?>
                      <?php endif; ?>
                    </span>
                    <span class="chat-peer__name"><?= e($bname) ?></span>
                  </span>
                  <?php if ($ucount > 0): ?>
                    <span class="badge bg-danger rounded-pill chat-unread"><?= $ucount ?></span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-md-8">
          <div class="glass p-3">
            <?php if ($infoGuide):
              $ig = $infoGuide;
              $igName = trim($ig['first_name'] . ' ' . $ig['last_name']);
              $igAvatar = !empty($ig['avatar']) ? (APP_URL . '/uploads/' . $ig['avatar']) : null;
              $igPhone = trim((string) ($ig['phone'] ?? '')) ?: '—';
              $igLang = trim((string) ($ig['languages'] ?? '')) ?: '—';
              $igRate = format_money($ig['hourly_rate']);
              $igLicense = trim((string) ($ig['license_number'] ?? '')) ?: '—';
              $igInitials = strtoupper(substr((string) $ig['first_name'], 0, 1) . substr((string) $ig['last_name'], 0, 1));
            ?>
              <div class="chat-guide-stage">
                <article class="guide-focus__card guide-focus__card--center chat-guide-card">
                  <div class="guide-focus__photo">
                    <?php if ($igAvatar): ?>
                      <img src="<?= e($igAvatar) ?>" alt="">
                    <?php else: ?>
                      <div class="guide-focus__photo-fallback"><?= e($igInitials ?: '?') ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="guide-focus__body">
                    <h2 class="guide-focus__name"><?= e($igName) ?></h2>
                    <ul class="guide-focus__facts">
                      <li><span>Phone</span><strong><?= e($igPhone) ?></strong></li>
                      <li><span>Languages</span><strong><?= e($igLang) ?></strong></li>
                      <li><span>Daily rate</span><strong><?= e($igRate) ?></strong></li>
                      <li><span>License no.</span><strong><?= e($igLicense) ?></strong></li>
                    </ul>
                    <div class="guide-focus__actions chat-guide-card__actions">
                      <button type="button" class="btn btn-ct" data-bs-toggle="modal" data-bs-target="#chatBookItinModal">
                        Book tour guide
                      </button>
                      <a class="btn btn-outline-light" href="?with=<?= (int) $ig['id'] ?>">Chat tour guide</a>
                    </div>
                  </div>
                </article>
              </div>
            <?php elseif (!$with): ?>
              <div class="text-muted p-4">Select a guide or business to start chatting.</div>
            <?php else:
              $chatGuide = $guidesById[$with] ?? null;
              $chatBiz = $bizById[$with] ?? null;
              if ($chatBiz) {
                  $chatName = trim((string) ($chatBiz['business_name'] ?? ''))
                      ?: trim($chatBiz['first_name'] . ' ' . $chatBiz['last_name']);
                  $chatAvatar = !empty($chatBiz['avatar'])
                      ? (APP_URL . '/uploads/' . ltrim((string) $chatBiz['avatar'], '/'))
                      : null;
                  $chatInitials = strtoupper(substr((string) $chatBiz['first_name'], 0, 1)
                      . substr((string) $chatBiz['last_name'], 0, 1)) ?: '?';
                  $chatInCard = false;
              } else {
                  $chatName = $chatGuide
                      ? trim($chatGuide['first_name'] . ' ' . $chatGuide['last_name'])
                      : 'Guide';
                  $chatAvatar = $chatGuide && !empty($chatGuide['avatar'])
                      ? (APP_URL . '/uploads/' . $chatGuide['avatar'])
                      : null;
                  $chatInitials = $chatGuide
                      ? strtoupper(substr((string) $chatGuide['first_name'], 0, 1) . substr((string) $chatGuide['last_name'], 0, 1))
                      : '?';
              }
            ?>
              <?php if ($chatInCard && $chatGuide): ?>
              <div class="chat-guide-stage">
                <article class="guide-focus__card guide-focus__card--center chat-guide-card chat-guide-card--thread">
                  <header class="chat-card-head">
                    <a class="chat-card-head__back" href="?info=<?= $with ?>" aria-label="Back to profile">&larr;</a>
                    <div class="chat-card-head__who">
                      <span class="chat-card-head__avatar">
                        <?php if ($chatAvatar): ?>
                          <img src="<?= e($chatAvatar) ?>" alt="">
                        <?php else: ?>
                          <?= e($chatInitials) ?>
                        <?php endif; ?>
                      </span>
                      <a class="chat-card-head__name" href="?info=<?= $with ?>"><?= e($chatName) ?></a>
                    </div>
                  </header>
                  <div class="chat-box chat-box--card" id="chatBox">
                    <?php if (!$messages): ?>
                      <div class="chat-card-empty">Say hello to <?= e($chatName) ?>.</div>
                    <?php endif; ?>
                    <?php foreach ($messages as $msg): ?>
                      <div class="bubble <?= (int) $msg['sender_id'] === (int) $user['id'] ? 'me' : 'them' ?>" data-msg-id="<?= (int) $msg['id'] ?>">
                        <?php if ($msg['body']): ?><div><?= nl2br(e($msg['body'])) ?></div><?php endif; ?>
                        <?php if ($msg['image_path']): ?><img src="<?= APP_URL ?>/uploads/<?= e($msg['image_path']) ?>" class="img-fluid rounded mt-1" style="max-height:120px" alt=""><?php endif; ?>
                        <div class="small opacity-75 mt-1"><?= e(format_date($msg['created_at'], 'g:i A')) ?><?= $msg['is_seen'] && (int) $msg['sender_id'] === (int) $user['id'] ? ' · Seen' : '' ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <form id="chatForm" class="chat-card-form" enctype="multipart/form-data"><?= csrf_field() ?>
                    <input type="hidden" name="with" value="<?= $with ?>">
                    <input class="form-control form-control-sm" name="body" placeholder="Type a message…" autocomplete="off">
                    <label class="chat-card-form__file" title="Attach photo">
                      <input type="file" name="image" accept="image/*">
                      <span>+</span>
                    </label>
                    <button type="submit" class="btn btn-ct btn-sm">Send</button>
                  </form>
                </article>
              </div>
              <?php else: ?>
              <div class="chat-thread-head mb-2">
                <span class="chat-thread-head__avatar">
                  <?php if ($chatAvatar): ?>
                    <img src="<?= e($chatAvatar) ?>" alt="">
                  <?php else: ?>
                    <?= e($chatInitials) ?>
                  <?php endif; ?>
                </span>
                <?php if ($chatGuide): ?>
                  <a class="chat-thread-head__name" href="?info=<?= $with ?>"><?= e($chatName) ?></a>
                <?php else: ?>
                  <span class="chat-thread-head__name"><?= e($chatName) ?></span>
                <?php endif; ?>
              </div>
              <div class="chat-box" id="chatBox">
                <?php foreach ($messages as $msg): ?>
                  <div class="bubble <?= (int) $msg['sender_id'] === (int) $user['id'] ? 'me' : 'them' ?>" data-msg-id="<?= (int) $msg['id'] ?>">
                    <?php if ($msg['body']): ?><div><?= nl2br(e($msg['body'])) ?></div><?php endif; ?>
                    <?php if ($msg['image_path']): ?><img src="<?= APP_URL ?>/uploads/<?= e($msg['image_path']) ?>" class="img-fluid rounded mt-1" style="max-height:160px" alt=""><?php endif; ?>
                    <div class="small opacity-75 mt-1"><?= e(format_date($msg['created_at'], 'g:i A')) ?><?= $msg['is_seen'] && (int) $msg['sender_id'] === (int) $user['id'] ? ' · Seen' : '' ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <form id="chatForm" class="mt-2" enctype="multipart/form-data"><?= csrf_field() ?>
                <input type="hidden" name="with" value="<?= $with ?>">
                <div class="input-group">
                  <input class="form-control" name="body" placeholder="Type a message…" autocomplete="off">
                  <input type="file" class="form-control" name="image" style="max-width:160px" accept="image/*">
                  <button type="submit" class="btn btn-ct">Send</button>
                </div>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php if ($infoGuide): ?>
<div class="modal fade" id="chatBookItinModal" tabindex="-1" aria-labelledby="chatBookItinModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 mb-0" id="chatBookItinModalLabel">Pick an itinerary</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Choose a draft or saved tour to book <strong><?= e($igName) ?></strong>.</p>
        <?php if ($myItineraries): ?>
          <div class="chat-itin-pick">
            <?php foreach ($myItineraries as $itin):
              $itinTitle = trim((string) ($itin['title'] ?? ''));
              if ($itinTitle === '' || strcasecmp($itinTitle, 'Unnamed') === 0) {
                  $itinTitle = 'Untitled tour';
              }
              $isDraft = !empty($itin['kept_as_draft']) || itinerary_is_placeholder_title($itin['title'] ?? '');
              $dateLabel = !empty($itin['start_date'])
                  ? format_date($itin['start_date'], 'M j, Y')
                  : 'No date set';
              $stops = (int) ($itin['stop_count'] ?? 0);
              $bookUrl = APP_URL . '/tourist/itineraries.php?id=' . (int) $itin['id']
                  . '&book=1&guide_user=' . (int) $ig['id'];
            ?>
              <a class="chat-itin-pick__item" href="<?= e($bookUrl) ?>">
                <span class="chat-itin-pick__title">
                  <?= e($itinTitle) ?>
                  <?php if ($isDraft): ?><span class="itin-draft-badge">Draft</span><?php endif; ?>
                </span>
                <span class="chat-itin-pick__meta">
                  <?= e($dateLabel) ?>
                  · <?= $stops ?> stop<?= $stops === 1 ? '' : 's' ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted small mb-3">No saved tours yet. Create one to book this guide.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer justify-content-between">
        <form method="post" action="<?= e(APP_URL) ?>/tourist/itineraries.php" class="m-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="guide_user" value="<?= (int) $ig['id'] ?>">
          <button type="submit" class="btn btn-outline-secondary btn-sm">+ New tour</button>
        </form>
        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
require __DIR__ . '/../includes/footer.php'; ?>
