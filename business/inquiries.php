<?php
/**
 * Business owner — inquiry inbox + replies
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business_engagement.php';
require_once __DIR__ . '/../includes/suspension.php';
require_once __DIR__ . '/../includes/live.php';

$user = require_login(ROLE_BUSINESS);
ensure_business_inquiries_schema();

$pageTitle = 'Inquiries';
$pageHeading = 'Customer inquiries';
$pageSub = 'Messages from tourists about your listing';
$activeNav = 'inquiries';

$biz = db()->prepare('SELECT * FROM businesses WHERE user_id=? LIMIT 1');
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && $biz && !user_is_suspended($user)) {
    $id = (int) ($_POST['id'] ?? 0);
    $reply = trim((string) ($_POST['reply'] ?? ''));
    $action = (string) ($_POST['action'] ?? 'reply');
    $row = db()->prepare('SELECT * FROM business_inquiries WHERE id=? AND business_id=?');
    $row->execute([$id, (int) $biz['id']]);
    $row = $row->fetch();
    if (!$row) {
        flash('error', 'Inquiry not found.');
        redirect(APP_URL . '/business/inquiries.php');
    }
    if ($action === 'close') {
        db()->prepare("UPDATE business_inquiries SET status='closed' WHERE id=?")->execute([$id]);
        flash('success', 'Inquiry closed.');
        redirect(APP_URL . '/business/inquiries.php');
    }
    if ($reply === '') {
        flash('error', 'Write a reply before sending.');
        redirect(APP_URL . '/business/inquiries.php');
    }
    db()->prepare(
        "UPDATE business_inquiries SET reply=?, status='replied', replied_at=NOW() WHERE id=?"
    )->execute([$reply, $id]);
    $touristId = (int) $row['tourist_id'];
    $ownerId = (int) $user['id'];
    $cid = chat_get_or_create_conversation($touristId, $ownerId);
    if ($cid > 0) {
        $hasMsgs = db()->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id=?');
        $hasMsgs->execute([$cid]);
        if ((int) $hasMsgs->fetchColumn() === 0) {
            business_inquiry_post_to_chat(
                $touristId,
                $ownerId,
                $touristId,
                (string) ($row['subject'] ?? ''),
                (string) ($row['message'] ?? '')
            );
        }
        chat_post_text_message($cid, $ownerId, $reply);
    }
    notify_user(
        $touristId,
        'new_message',
        'Reply from ' . ($biz['name'] ?? 'a business'),
        $reply,
        APP_URL . '/tourist/chat.php?with=' . $ownerId
    );
    flash('success', 'Reply sent. Continue in Messages.');
    redirect(APP_URL . '/business/chat.php?with=' . $touristId);
}

$filter = (string) ($_GET['status'] ?? 'open');
if (!in_array($filter, ['open', 'replied', 'closed', 'all'], true)) {
    $filter = 'open';
}

$live = ['version' => 'none', 'html' => '<div class="alert alert-warning">No business profile found.</div>'];
if ($biz) {
    $live = live_business_inquiries_payload((int) $biz['id'], $filter);
}

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php render_account_status_panel($user); ?>
      <?php if (user_is_suspended($user)): ?>
<?php require __DIR__ . '/../includes/business_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; return; endif; ?>

      <?php if (!$biz): ?>
        <div class="alert alert-warning">No business profile found.</div>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach (['open' => 'Open', 'replied' => 'Replied', 'closed' => 'Closed', 'all' => 'All'] as $k => $lab): ?>
            <a class="quick-chip <?= $filter === $k ? 'is-active fw-semibold' : '' ?>" href="?status=<?= e($k) ?>"><?= e($lab) ?></a>
          <?php endforeach; ?>
        </div>

        <div data-live-page="business_inquiries" data-live-version="<?= e($live['version']) ?>" data-live-query="status=<?= e($filter) ?>">
          <?= $live['html'] ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/../includes/business_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
