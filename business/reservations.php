<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_BUSINESS);
revenue_ensure_schema();

$biz = db()->prepare(
    'SELECT b.id, b.name, b.user_id, bt.name AS type_name
     FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.user_id=? LIMIT 1'
);
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();
$kind = revenue_bookable_kind($biz['type_name'] ?? '');
$placeCopy = reservation_place_copy($kind);
if (!$biz || !revenue_uses_reservation($kind)) {
    redirect(APP_URL . '/business/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $result = reservation_set_status(
        (int) ($_POST['reservation_id'] ?? 0),
        (string) ($_POST['status'] ?? ''),
        (int) $user['id'],
        'business',
        (string) ($_POST['reason'] ?? '')
    );
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect(APP_URL . '/business/reservations.php');
}

$tab = (string) ($_GET['tab'] ?? 'requests');
if (!in_array($tab, ['requests', 'confirmed', 'history'], true)) {
    $tab = 'requests';
}
$statuses = match ($tab) {
    'confirmed' => ['confirmed'],
    'history' => ['rejected', 'cancelled', 'completed', 'no_show'],
    default => ['pending'],
};
$ph = implode(',', array_fill(0, count($statuses), '?'));
$st = db()->prepare(
    "SELECT r.*, u.first_name, u.last_name, u.phone
     FROM restaurant_reservations r
     JOIN users u ON u.id = r.tourist_id
     WHERE r.business_id=? AND r.status IN ($ph)
     ORDER BY r.reserve_date DESC, r.id DESC"
);
$st->execute(array_merge([(int) $biz['id']], $statuses));
$rows = $st->fetchAll() ?: [];

$pageTitle = 'Reservations';
$pageHeading = 'Reservations';
$pageSub = $biz['name'];
$activeNav = 'reservations';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
<?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
<div class="d-flex gap-2 mb-3">
  <a class="btn btn-sm <?= $tab === 'requests' ? 'btn-ct' : 'btn-outline-secondary' ?>" href="?tab=requests">Requests</a>
  <a class="btn btn-sm <?= $tab === 'confirmed' ? 'btn-ct' : 'btn-outline-secondary' ?>" href="?tab=confirmed">Confirmed</a>
  <a class="btn btn-sm <?= $tab === 'history' ? 'btn-ct' : 'btn-outline-secondary' ?>" href="?tab=history">History</a>
</div>
<?php if (!$rows): ?>
  <div class="glass p-4 text-muted">No reservations in this list.</div>
<?php else: ?>
  <?php foreach ($rows as $row):
    $lines = reservation_lines((int) $row['id']);
  ?>
    <article class="glass p-3 mb-3">
      <div class="d-flex justify-content-between gap-2">
        <div>
          <div class="fw-semibold"><?= e($row['code']) ?> · <?= e(full_name($row)) ?></div>
          <div class="small text-muted"><?= e(format_date((string) $row['reserve_date'])) ?> <?= e(format_time_12h((string) $row['reserve_time'])) ?> · <?= (int) $row['guests'] ?> guests<?= $row['phone'] ? ' · ' . e($row['phone']) : '' ?></div>
        </div>
        <?= status_badge((string) $row['status']) ?>
      </div>
      <div class="small mt-2"><?= $row['order_mode'] === 'advance_order' ? e($placeCopy['ahead']) : e($placeCopy['at_place_short']) ?></div>
      <?php if ($lines): ?>
        <ul class="small mb-2">
          <?php foreach ($lines as $line): ?>
            <li><?= (int) $line['qty'] ?> × <?= e($line['item_name']) ?> · <?= e(format_money((float) $line['unit_price'])) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="small"><?= e($placeCopy['total']) ?> <?= e(format_money((float) $row['food_total'])) ?> is yours. CulTour fee snapshot <?= e(format_money((float) $row['fee_snapshot'])) ?>.</div>
      <?php else: ?>
        <div class="small text-muted">CulTour fee snapshot <?= e(format_money((float) $row['fee_snapshot'])) ?>, charged only after a completed visit.</div>
      <?php endif; ?>
      <?php if ($row['status'] === 'pending'): ?>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <form method="post" onsubmit="return confirm('Accept this reservation?');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="status" value="confirmed">
            <button class="btn btn-sm btn-ct" type="submit">Accept</button>
          </form>
          <form method="post" class="d-flex gap-2" onsubmit="return confirm('Reject this reservation?');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="status" value="rejected">
            <input class="form-control form-control-sm" name="reason" required placeholder="Reason for rejecting" style="min-width:14rem">
            <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
          </form>
        </div>
      <?php elseif ($row['status'] === 'confirmed'): ?>
        <div class="d-flex gap-2 mt-3">
          <form method="post" onsubmit="return confirm('Mark this visit completed? A reservation fee will be added to your bill.');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="status" value="completed">
            <button class="btn btn-sm btn-ct" type="submit">Completed</button>
          </form>
          <form method="post" onsubmit="return confirm('Mark this guest as a no-show?');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="status" value="no_show">
            <button class="btn btn-sm btn-outline-secondary" type="submit">No-show</button>
          </form>
        </div>
      <?php elseif ($row['status'] === 'rejected' && trim((string) $row['rejection_reason']) !== ''): ?>
        <div class="small text-danger mt-2">Reason: <?= e((string) $row['rejection_reason']) ?></div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
require __DIR__ . '/../includes/footer.php';
