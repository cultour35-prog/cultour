<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_BUSINESS);
revenue_ensure_schema();
revenue_mark_overdue();

$biz = db()->prepare(
    'SELECT b.id, b.name, bt.name AS type_name
     FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.user_id=? LIMIT 1'
);
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();
$kind = revenue_bookable_kind($biz['type_name'] ?? '');
if (!$biz || !(revenue_uses_reservation($kind) || in_array($kind, ['accommodation', 'transportation', 'package'], true))) {
    redirect(APP_URL . '/business/dashboard.php');
}

$st = db()->prepare(
    'SELECT * FROM revenue_transactions WHERE business_id=? ORDER BY period_ym DESC, id DESC'
);
$st->execute([(int) $biz['id']]);
$rows = $st->fetchAll() ?: [];
$statements = db()->prepare('SELECT * FROM revenue_statements WHERE business_id=? ORDER BY period_ym DESC');
$statements->execute([(int) $biz['id']]);
$statements = $statements->fetchAll() ?: [];
$unpaid = 0.0;
$paid = 0.0;
foreach ($rows as $row) {
    if ($row['status'] === 'paid') {
        $paid += (float) $row['fee_amount'];
    } else {
        $unpaid += (float) $row['fee_amount'];
    }
}

$pageTitle = 'Fees';
$pageHeading = 'Fees';
$pageSub = 'CulTour fees for ' . $biz['name'] . '.';
if (revenue_uses_reservation($kind)) {
    $feeCopy = reservation_place_copy($kind);
    $pageSub .= ' ' . ($kind === 'food' ? 'Food orders stay with you.' : $feeCopy['total'] . ' stays with you.');
}
$activeNav = 'fees';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="glass stat-card"><div class="label">Open fees</div><p class="value" style="font-size:1.35rem"><?= e(format_money($unpaid)) ?></p></div></div>
  <div class="col-6 col-lg-3"><div class="glass stat-card"><div class="label">Paid</div><p class="value" style="font-size:1.35rem"><?= e(format_money($paid)) ?></p></div></div>
</div>
<div class="glass p-3 mb-4">
  <h2 class="h6">Fee records</h2>
  <?php if (!$rows): ?>
    <p class="text-muted mb-0">No fees yet. A fee is added only after a completed reservation or booking.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-ct align-middle mb-0">
        <thead><tr><th>Record</th><th>Base</th><th>Rate</th><th>Fee</th><th>Month</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e(ucfirst((string) $row['source_type'])) ?> #<?= (int) $row['source_id'] ?></td>
              <td><?= e(format_money((float) $row['base_amount'])) ?></td>
              <td><?= e((string) $row['rate_label']) ?></td>
              <td><?= e(format_money((float) $row['fee_amount'])) ?></td>
              <td><?= e((string) $row['period_ym']) ?></td>
              <td><?= status_badge((string) $row['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<div class="glass p-3">
  <h2 class="h6">Monthly statements</h2>
  <?php if (!$statements): ?>
    <p class="text-muted mb-0">The Tourism Office has not issued a statement yet.</p>
  <?php else: ?>
    <ul class="list-unstyled mb-0">
      <?php foreach ($statements as $statement): ?>
        <li class="d-flex justify-content-between gap-2 py-1 border-bottom">
          <span><?= e((string) $statement['period_ym']) ?> · <?= e(format_money((float) $statement['total_amount'])) ?></span>
          <?= status_badge((string) $statement['status']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
require __DIR__ . '/../includes/footer.php';
