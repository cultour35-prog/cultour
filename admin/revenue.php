<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_ADMIN);
revenue_ensure_schema();
revenue_mark_overdue();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'category_rate') {
        $typeId = (int) ($_POST['business_type_id'] ?? 0);
        $model = ($_POST['model'] ?? '') === 'percent' ? 'percent' : 'fixed';
        $percent = max(0, (float) ($_POST['percent_rate'] ?? 0));
        $fixed = max(0, (float) ($_POST['fixed_amount'] ?? 0));
        $noshow = ($_POST['noshow_policy'] ?? '') === 'charge' ? 'charge' : 'none';
        db()->prepare(
            'INSERT INTO revenue_rates (business_type_id, business_id, model, percent_rate, fixed_amount, noshow_policy, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE model=VALUES(model), percent_rate=VALUES(percent_rate), fixed_amount=VALUES(fixed_amount), noshow_policy=VALUES(noshow_policy), updated_at=NOW()'
        )->execute([$typeId, $model, $percent, $fixed, $noshow]);
        flash('success', 'Category rate saved. Existing reservations keep their saved fee.');
    } elseif ($action === 'business_rate') {
        $bizId = (int) ($_POST['business_id'] ?? 0);
        $model = ($_POST['model'] ?? '') === 'percent' ? 'percent' : 'fixed';
        $percent = max(0, (float) ($_POST['percent_rate'] ?? 0));
        $fixed = max(0, (float) ($_POST['fixed_amount'] ?? 0));
        $owned = db()->prepare('SELECT id FROM businesses WHERE id=? LIMIT 1');
        $owned->execute([$bizId]);
        if (!$owned->fetch()) {
            flash('error', 'Choose a business.');
        } else {
            db()->prepare('DELETE FROM revenue_rates WHERE business_id=?')->execute([$bizId]);
            db()->prepare(
                'INSERT INTO revenue_rates (business_type_id, business_id, model, percent_rate, fixed_amount, noshow_policy, updated_at)
                 VALUES (NULL, ?, ?, ?, ?, \'none\', NOW())'
            )->execute([$bizId, $model, $percent, $fixed]);
            flash('success', 'Business override saved.');
        }
    } elseif ($action === 'clear_override') {
        db()->prepare('DELETE FROM revenue_rates WHERE business_id=?')->execute([(int) ($_POST['business_id'] ?? 0)]);
        flash('success', 'Override removed.');
    } elseif ($action === 'statement') {
        $result = revenue_generate_statement((int) ($_POST['business_id'] ?? 0), (string) ($_POST['period_ym'] ?? ''));
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'verify') {
        $result = revenue_verify_statement((int) ($_POST['statement_id'] ?? 0), (int) $user['id']);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    }
    redirect(APP_URL . '/admin/revenue.php');
}

$types = db()->query(
    'SELECT bt.id, bt.name, r.model, r.percent_rate, r.fixed_amount, r.noshow_policy
     FROM business_types bt
     LEFT JOIN revenue_rates r ON r.business_type_id = bt.id AND r.business_id IS NULL
     ORDER BY bt.name'
)->fetchAll() ?: [];
$businesses = db()->query(
    'SELECT b.id, b.name, bt.name AS type_name FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     ORDER BY b.name'
)->fetchAll() ?: [];
$overrides = db()->query(
    'SELECT r.*, b.name FROM revenue_rates r JOIN businesses b ON b.id = r.business_id ORDER BY b.name'
)->fetchAll() ?: [];

$filterBiz = (int) ($_GET['business'] ?? 0);
$filterStatus = (string) ($_GET['status'] ?? '');
$filterFrom = (string) ($_GET['from'] ?? '');
$filterTo = (string) ($_GET['to'] ?? '');
$sql = 'SELECT t.*, b.name AS business_name FROM revenue_transactions t JOIN businesses b ON b.id = t.business_id WHERE 1=1';
$params = [];
if ($filterBiz > 0) {
    $sql .= ' AND t.business_id=?';
    $params[] = $filterBiz;
}
if (in_array($filterStatus, ['unpaid', 'paid', 'overdue'], true)) {
    $sql .= ' AND t.status=?';
    $params[] = $filterStatus;
}
if (preg_match('/^\d{4}-\d{2}$/', $filterFrom)) {
    $sql .= ' AND t.period_ym>=?';
    $params[] = $filterFrom;
}
if (preg_match('/^\d{4}-\d{2}$/', $filterTo)) {
    $sql .= ' AND t.period_ym<=?';
    $params[] = $filterTo;
}
$sql .= ' ORDER BY t.period_ym DESC, t.id DESC LIMIT 200';
$tx = db()->prepare($sql);
$tx->execute($params);
$tx = $tx->fetchAll() ?: [];
$totals = ['unpaid' => 0.0, 'paid' => 0.0, 'overdue' => 0.0];
$sum = db()->query("SELECT status, COALESCE(SUM(fee_amount),0) AS total FROM revenue_transactions GROUP BY status")->fetchAll() ?: [];
foreach ($sum as $row) {
    $totals[$row['status']] = (float) $row['total'];
}
$statements = db()->query(
    'SELECT s.*, b.name AS business_name FROM revenue_statements s
     JOIN businesses b ON b.id = s.business_id
     ORDER BY s.period_ym DESC, b.name LIMIT 100'
)->fetchAll() ?: [];

$pageTitle = 'Revenue';
$pageHeading = 'Revenue';
$pageSub = 'Category rates, reservation fees, and commissions';
$activeNav = 'revenue';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="glass stat-card"><div class="label">Unpaid</div><p class="value" style="font-size:1.35rem"><?= e(format_money($totals['unpaid'])) ?></p></div></div>
        <div class="col-md-4"><div class="glass stat-card"><div class="label">Overdue</div><p class="value" style="font-size:1.35rem"><?= e(format_money($totals['overdue'])) ?></p></div></div>
        <div class="col-md-4"><div class="glass stat-card"><div class="label">Paid</div><p class="value" style="font-size:1.35rem"><?= e(format_money($totals['paid'])) ?></p></div></div>
      </div>

      <section class="glass p-3 mb-4">
        <h2 class="h6">Rates by category</h2>
        <p class="small text-muted">Food uses a fixed reservation fee. Accommodation, transportation, and tour packages use a percentage of the booking amount. The fee is saved on each reservation, so later rate changes do not rewrite old records. Government sites are not billed.</p>
        <div class="table-responsive">
          <table class="table table-sm table-ct align-middle">
            <thead><tr><th>Category</th><th>Model</th><th>Percent</th><th>Fixed fee</th><th>No-show</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($types as $type): $formId = 'rate-' . (int) $type['id']; ?>
                <form id="<?= e($formId) ?>" method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="category_rate">
                  <input type="hidden" name="business_type_id" value="<?= (int) $type['id'] ?>">
                </form>
                <tr>
                    <td><?= e($type['name']) ?></td>
                    <td>
                      <select class="form-select form-select-sm" name="model" form="<?= e($formId) ?>">
                        <option value="fixed"<?= ($type['model'] ?? '') === 'fixed' ? ' selected' : '' ?>>Fixed fee</option>
                        <option value="percent"<?= ($type['model'] ?? '') === 'percent' ? ' selected' : '' ?>>Percent</option>
                      </select>
                    </td>
                    <td><input class="form-control form-control-sm" type="number" min="0" step="0.01" name="percent_rate" form="<?= e($formId) ?>" value="<?= e((string) ($type['percent_rate'] ?? '0')) ?>"></td>
                    <td><input class="form-control form-control-sm" type="number" min="0" step="0.01" name="fixed_amount" form="<?= e($formId) ?>" value="<?= e((string) ($type['fixed_amount'] ?? '0')) ?>"></td>
                    <td>
                      <select class="form-select form-select-sm" name="noshow_policy" form="<?= e($formId) ?>">
                        <option value="none"<?= ($type['noshow_policy'] ?? '') !== 'charge' ? ' selected' : '' ?>>Do not charge</option>
                        <option value="charge"<?= ($type['noshow_policy'] ?? '') === 'charge' ? ' selected' : '' ?>>Charge fee</option>
                      </select>
                    </td>
                    <td><button class="btn btn-sm btn-ct" type="submit" form="<?= e($formId) ?>">Save</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="glass p-3 mb-4">
        <h2 class="h6">Business override</h2>
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="business_rate">
          <div class="col-md-4">
            <label class="form-label small">Business</label>
            <select class="form-select" name="business_id" required>
              <?php foreach ($businesses as $business): ?>
                <option value="<?= (int) $business['id'] ?>"><?= e($business['name']) ?> · <?= e($business['type_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Model</label>
            <select class="form-select" name="model">
              <option value="fixed">Fixed fee</option>
              <option value="percent">Percent</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Percent</label>
            <input class="form-control" type="number" min="0" step="0.01" name="percent_rate" value="0">
          </div>
          <div class="col-md-2">
            <label class="form-label small">Fixed fee</label>
            <input class="form-control" type="number" min="0" step="0.01" name="fixed_amount" value="0">
          </div>
          <div class="col-md-2"><button class="btn btn-ct w-100" type="submit">Save override</button></div>
        </form>
        <?php if ($overrides): ?>
          <ul class="list-unstyled mt-3 mb-0">
            <?php foreach ($overrides as $override): ?>
              <li class="d-flex justify-content-between gap-2 py-1 border-bottom">
                <span><?= e($override['name']) ?> · <?= $override['model'] === 'percent' ? e($override['percent_rate'] . '%') : e(format_money($override['fixed_amount'])) ?></span>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="clear_override">
                  <input type="hidden" name="business_id" value="<?= (int) $override['business_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="glass p-3 mb-4">
        <h2 class="h6">Transactions</h2>
        <form method="get" class="row g-2 mb-3">
          <div class="col-md-3">
            <select class="form-select form-select-sm" name="business">
              <option value="0">All businesses</option>
              <?php foreach ($businesses as $business): ?>
                <option value="<?= (int) $business['id'] ?>"<?= $filterBiz === (int) $business['id'] ? ' selected' : '' ?>><?= e($business['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="status">
              <option value="">Any status</option>
              <?php foreach (['unpaid', 'paid', 'overdue'] as $status): ?>
                <option value="<?= e($status) ?>"<?= $filterStatus === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><input class="form-control form-control-sm" name="from" value="<?= e($filterFrom) ?>" placeholder="From YYYY-MM"></div>
          <div class="col-md-2"><input class="form-control form-control-sm" name="to" value="<?= e($filterTo) ?>" placeholder="To YYYY-MM"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-ct" type="submit">Filter</button></div>
        </form>
        <?php if (!$tx): ?>
          <p class="text-muted mb-0">No fee transactions match.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-ct align-middle mb-0">
              <thead><tr><th>Business</th><th>Category</th><th>Source</th><th>Base</th><th>Rate</th><th>Fee</th><th>Month</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($tx as $row): ?>
                  <tr>
                    <td><?= e($row['business_name']) ?></td>
                    <td><?= e($row['category_name']) ?></td>
                    <td><?= e($row['source_type']) ?> #<?= (int) $row['source_id'] ?></td>
                    <td><?= e(format_money((float) $row['base_amount'])) ?></td>
                    <td><?= e($row['rate_label']) ?></td>
                    <td><?= e(format_money((float) $row['fee_amount'])) ?></td>
                    <td><?= e($row['period_ym']) ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="glass p-3">
        <h2 class="h6">Monthly statements</h2>
        <form method="post" class="row g-2 align-items-end mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="statement">
          <div class="col-md-5">
            <label class="form-label small">Business</label>
            <select class="form-select form-select-sm" name="business_id" required>
              <?php foreach ($businesses as $business): ?>
                <option value="<?= (int) $business['id'] ?>"><?= e($business['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Month</label>
            <input class="form-control form-control-sm" name="period_ym" required value="<?= e(date('Y-m')) ?>" placeholder="YYYY-MM">
          </div>
          <div class="col-md-2"><button class="btn btn-sm btn-ct" type="submit">Generate</button></div>
        </form>
        <?php if (!$statements): ?>
          <p class="text-muted mb-0">No statements yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-ct align-middle mb-0">
              <thead><tr><th>Business</th><th>Month</th><th>Total</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($statements as $statement): ?>
                  <tr>
                    <td><?= e($statement['business_name']) ?></td>
                    <td><?= e($statement['period_ym']) ?></td>
                    <td><?= e(format_money((float) $statement['total_amount'])) ?></td>
                    <td><?= status_badge((string) $statement['status']) ?></td>
                    <td>
                      <?php if ($statement['status'] !== 'paid'): ?>
                        <form method="post" onsubmit="return confirm('Mark this statement paid?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="verify">
                          <input type="hidden" name="statement_id" value="<?= (int) $statement['id'] ?>">
                          <button class="btn btn-sm btn-outline-primary" type="submit">Verify payment</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
