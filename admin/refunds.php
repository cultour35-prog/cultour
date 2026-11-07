<?php
/**
 * Admin — refunds for declined, cancelled, or leftover-day bookings that were already paid.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_ADMIN);
ensure_booking_refund_schema();
$pageTitle = 'Refunds';
$pageHeading = 'Refunds';
$pageSub = 'Send GCash refunds to tourists whose paid tours did not push through';
$activeNav = 'refunds';

mark_notifications_read((int) $user['id'], 'refund_requested');

$tabs = [
    REFUND_REQUESTED => 'Needs refund',
    REFUND_NEEDS_DETAILS => 'Waiting for tourist',
    REFUND_SENT => 'Refunded',
    'all' => 'All',
];
$filter = (string) ($_GET['status'] ?? REFUND_REQUESTED);
if (!isset($tabs[$filter])) {
    $filter = REFUND_REQUESTED;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid or expired form. Please try again.');
        redirect(APP_URL . '/admin/refunds.php?status=' . urlencode($filter));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'send') {
        $res = refund_mark_sent($id, (int) $user['id'], (string) ($_POST['reference'] ?? ''), (string) ($_POST['note'] ?? ''));
    } elseif ($action === 'remind') {
        $res = refund_remind_tourist($id);
    } else {
        $res = ['ok' => false, 'message' => 'Unknown action.'];
    }
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    redirect(APP_URL . '/admin/refunds.php?status=' . urlencode($filter));
}

$counts = refund_admin_counts();
$rows = refund_admin_rows($filter);
$stateLabels = [
    REFUND_REQUESTED => ['Needs refund', 'bg-warning text-dark'],
    REFUND_NEEDS_DETAILS => ['Waiting for tourist', 'bg-secondary'],
    REFUND_SENT => ['Refunded', 'bg-success'],
];

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($tabs as $k => $lab): ?>
          <?php $n = $k === 'all' ? array_sum($counts) : (int) ($counts[$k] ?? 0); ?>
          <a class="quick-chip <?= $filter === $k ? 'is-active fw-semibold' : '' ?>" href="?status=<?= e($k) ?>">
            <?= e($lab) ?><?php if ($n > 0): ?> <span class="badge <?= $k === REFUND_REQUESTED ? 'bg-warning text-dark' : 'bg-secondary' ?> ms-1"><?= $n ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="glass p-3">
        <?php if (!$rows): ?>
          <div class="text-muted p-2">
            <?= $filter === REFUND_REQUESTED ? 'All caught up. Refunds that are ready to send show here.' : 'Nothing here yet.' ?>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-ct mb-0 align-middle">
              <thead>
                <tr>
                  <th>Booking</th>
                  <th>Tourist</th>
                  <th>Why</th>
                  <th class="text-end">Amount</th>
                  <th>Send to</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $state = (string) ($r['refund_status'] ?? '');
                    [$stateLabel, $stateClass] = $stateLabels[$state] ?? [ucfirst($state), 'bg-secondary'];
                    $qrUrl = !empty($r['refund_qr_path']) ? APP_URL . '/uploads/' . ltrim((string) $r['refund_qr_path'], '/') : '';
                    $why = trim((string) ($r['refund_note'] ?? '')) ?: trim((string) ($r['rejection_reason'] ?? ''));
                  ?>
                  <tr>
                    <td>
                      <code><?= e($r['booking_code']) ?></code>
                      <div class="small text-muted"><?= e(format_date($r['booking_date'])) ?></div>
                      <?php if (trim((string) ($r['guide_name'] ?? '')) !== ''): ?>
                        <div class="small text-muted">Guide · <?= e($r['guide_name']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div><?= e($r['tourist_name']) ?></div>
                      <div class="small text-muted"><?= e($r['tourist_email']) ?></div>
                    </td>
                    <td class="small" style="max-width:240px">
                      <div><?= e(ucfirst((string) $r['status'])) ?></div>
                      <?php if ($why !== ''): ?><div class="text-muted"><?= e($why) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold text-nowrap">
                      <?= e(format_money(refund_amount_of($r))) ?>
                      <?php if (!empty($r['payment_reference'])): ?>
                        <div class="small text-muted fw-normal">Paid ref <?= e($r['payment_reference']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['refund_number'])): ?>
                        <div class="fw-semibold text-nowrap"><i class="bi bi-phone"></i> <?= e($r['refund_number']) ?></div>
                      <?php endif; ?>
                      <?php if ($qrUrl !== ''): ?>
                        <a href="<?= e($qrUrl) ?>" target="_blank" rel="noopener" title="Open GCash QR">
                          <img class="refund-admin__qr mt-1" src="<?= e($qrUrl) ?>" alt="GCash QR for <?= e($r['booking_code']) ?>">
                        </a>
                      <?php endif; ?>
                      <?php if (empty($r['refund_number']) && $qrUrl === ''): ?>
                        <span class="small text-muted">Waiting for details</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge <?= e($stateClass) ?>"><?= e($stateLabel) ?></span>
                      <?php if ($state === REFUND_SENT): ?>
                        <div class="small text-muted mt-1">
                          <?= e(format_date($r['refund_sent_at'] ?? null, 'M j, Y g:i A')) ?>
                          <?php if (trim((string) ($r['sent_by_name'] ?? '')) !== ''): ?> · <?= e($r['sent_by_name']) ?><?php endif; ?>
                        </div>
                      <?php elseif ($state === REFUND_REQUESTED && !empty($r['refund_requested_at'])): ?>
                        <div class="small text-muted mt-1">Since <?= e(format_date($r['refund_requested_at'], 'M j, g:i A')) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($state === REFUND_REQUESTED): ?>
        <form method="post" class="refund-admin__form"
              data-ct-confirm="<?= e('Mark ' . format_money(refund_amount_of($r)) . ' as sent to the tourist?') ?>"
              data-ct-confirm-title="Send refund"
              data-ct-confirm-ok="Mark refunded">
                          <?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <input type="hidden" name="action" value="send">
                          <input class="form-control form-control-sm" name="reference" maxlength="40" placeholder="GCash reference (optional)">
                          <button class="btn btn-sm btn-ct text-nowrap"><i class="bi bi-send-check"></i> Mark refunded</button>
                        </form>
                      <?php elseif ($state === REFUND_NEEDS_DETAILS): ?>
                        <form method="post">
                          <?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <input type="hidden" name="action" value="remind">
                          <button class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-bell"></i> Remind tourist</button>
                        </form>
                      <?php elseif (!empty($r['refund_reference'])): ?>
                        <div class="small text-nowrap">Ref <code><?= e($r['refund_reference']) ?></code></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
