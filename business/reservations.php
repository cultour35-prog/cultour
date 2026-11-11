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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && (string) ($_POST['action'] ?? '') === 'package_clearance') {
    $res = package_clearance_respond((int) ($_POST['clearance_id'] ?? 0), (int) $user['id'], 'business', (string) ($_POST['decision'] ?? '') === 'accept');
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    redirect(APP_URL . '/business/reservations.php');
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
<?php
  package_clearance_ensure_schema();
  $clearSt = db()->prepare(
      "SELECT c.id, s.name, s.package_fixed_date
       FROM package_clearances c
       JOIN business_services s ON s.id=c.service_id
       WHERE c.business_id=? AND c.status='pending'
       ORDER BY c.id DESC"
  );
  $clearSt->execute([(int) $biz['id']]);
  $packageClearances = $clearSt->fetchAll() ?: [];
?>
<?php foreach ($packageClearances as $clearance): ?>
  <article class="glass p-3 mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <div class="fw-semibold"><?= e((string) $clearance['name']) ?></div>
        <div class="small text-muted">Inclusive package<?= !empty($clearance['package_fixed_date']) ? ' · ' . e(format_date((string) $clearance['package_fixed_date'])) : '' ?></div>
      </div>
      <form method="post" class="d-flex gap-2"><?= csrf_field() ?>
        <input type="hidden" name="action" value="package_clearance">
        <input type="hidden" name="clearance_id" value="<?= (int) $clearance['id'] ?>">
        <button class="btn btn-ct btn-sm" name="decision" value="accept" type="submit">Accept</button>
        <button class="btn btn-outline-secondary btn-sm" name="decision" value="decline" type="submit">Decline</button>
      </form>
    </div>
  </article>
<?php endforeach; ?>
<div class="reservation-tabs mb-3">
  <a class="btn btn-sm <?= $tab === 'requests' ? 'btn-ct' : 'btn-outline-secondary' ?>" href="?tab=requests">Requests</a>
  <a class="btn btn-sm <?= $tab === 'confirmed' ? 'btn-ct' : 'btn-outline-secondary' ?>" href="?tab=confirmed">Confirmed</a>
  <a class="btn btn-sm <?= $tab === 'history' ? 'btn-ct' : 'btn-outline-secondary' ?>" href="?tab=history">History</a>
</div>
<?php $rejectModals = ''; ?>
<?php if (!$rows): ?>
  <div class="glass p-4 text-muted">No reservations in this list.</div>
<?php else: ?>
  <?php foreach ($rows as $row):
    $lines = reservation_lines((int) $row['id']);
  ?>
    <article class="glass p-3 mb-3">
      <div class="reservation-card__head">
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
        <div class="small"><?= e($placeCopy['total']) ?> <?= e(format_money((float) $row['food_total'])) ?> is yours.</div>
      <?php endif; ?>
      <?php if ($row['status'] === 'pending'): ?>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <form method="post" onsubmit="return confirm('Accept this reservation?');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="status" value="confirmed">
            <button class="btn btn-sm btn-ct" type="submit">Accept</button>
          </form>
          <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectReservation<?= (int) $row['id'] ?>">Reject</button>
        </div>
        <?php ob_start(); ?>
        <div class="modal fade" id="rejectReservation<?= (int) $row['id'] ?>" tabindex="-1" aria-labelledby="rejectReservationTitle<?= (int) $row['id'] ?>" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="rejected">
                <div class="modal-header">
                  <h2 class="modal-title h5" id="rejectReservationTitle<?= (int) $row['id'] ?>">Reject <?= e($row['code']) ?></h2>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <label class="form-label" for="rejectReason<?= (int) $row['id'] ?>">Reason</label>
                  <textarea class="form-control" id="rejectReason<?= (int) $row['id'] ?>" name="reason" rows="3" required placeholder="Why are you rejecting this reservation?"></textarea>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-outline-danger" type="submit">Reject</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php $rejectModals .= ob_get_clean(); ?>
      <?php elseif ($row['status'] === 'confirmed'): ?>
        <div class="d-flex gap-2 mt-3">
          <form method="post" onsubmit="return confirm('Mark this guest as a no-show?');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="status" value="no_show">
            <button class="btn btn-sm btn-outline-danger" type="submit">No-show</button>
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
echo $rejectModals;
require __DIR__ . '/../includes/footer.php';
