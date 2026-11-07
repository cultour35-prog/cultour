<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content_reports.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_ADMIN);
ensure_content_reports_schema();
$pageTitle = 'Content reports';
$pageHeading = 'Content moderation';
$pageSub = 'Pending reports go to Review, then Actioned or Dismissed';
$activeNav = 'content_reports';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $notes = trim((string) ($_POST['admin_notes'] ?? ''));
    $err = $id > 0 ? content_report_set_status($id, $action, (int) $user['id'], $notes) : 'Report not found.';
    if ($err !== null) {
        flash('error', $err);
        redirect(APP_URL . '/admin/content-reports.php');
    }
    log_activity((int) $user['id'], 'moderate_report', 'content_reports', 'Report #' . $id . ' → ' . $action);
    $done = [
        'reviewed' => 'Report moved to review. The reporter was notified.',
        'actioned' => 'Report marked actioned. The reporter was notified.',
        'dismissed' => 'Report dismissed. The reporter was notified.',
    ];
    flash('success', $done[$action] ?? 'Report updated.');
    redirect(APP_URL . '/admin/content-reports.php?status=' . urlencode($action));
}

$filter = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['pending', 'reviewed', 'dismissed', 'actioned', 'all'], true)) {
    $filter = 'pending';
}
$live = live_content_reports_payload($filter);

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
        <?php foreach (['pending' => 'Pending', 'reviewed' => 'Review', 'actioned' => 'Actioned', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $lab): ?>
          <a class="quick-chip <?= $filter === $k ? 'is-active fw-semibold' : '' ?>" href="?status=<?= e($k) ?>"><?= e($lab) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="glass p-3" data-live-page="content_reports" data-live-version="<?= e($live['version']) ?>" data-live-query="status=<?= e($filter) ?>">
        <?= $live['html'] ?>
      </div>
    </div>
  </div>
</div>
<div id="reportViewCard" class="admin-biz-proof-overlay" hidden>
  <div class="admin-biz-proof-card report-view-card" role="dialog" aria-modal="true" aria-labelledby="reportViewTitle">
    <div class="admin-biz-proof-card__head">
      <h2 class="h6 mb-0" id="reportViewTitle" data-report-card-title>Report</h2>
      <button type="button" class="admin-biz-proof-card__close" data-close-report-card aria-label="Close">&times;</button>
    </div>
    <div class="admin-biz-proof-card__body report-view-card__body" data-report-card-body></div>
  </div>
</div>
<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  function card() { return document.getElementById('reportViewCard'); }
  function closeCard() {
    var el = card();
    if (!el || el.hidden) return;
    el.hidden = true;
    document.body.classList.remove('admin-biz-proof-open');
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-view-report]');
    if (btn) {
      e.preventDefault();
      var el = card();
      if (!el) return;
      var row = btn.closest('tr');
      var tpl = row ? row.querySelector('.report-view-source') : null;
      var title = el.querySelector('[data-report-card-title]');
      var body = el.querySelector('[data-report-card-body]');
      if (title) title.textContent = btn.getAttribute('data-report-title') || 'Report';
      if (body) {
        body.innerHTML = tpl ? tpl.innerHTML : '';
        body.scrollTop = 0;
      }
      if (el.parentElement !== document.body) document.body.appendChild(el);
      el.hidden = false;
      document.body.classList.add('admin-biz-proof-open');
      return;
    }
    var el = card();
    if (!el || el.hidden) return;
    if (e.target === el || e.target.closest('[data-close-report-card]')) closeCard();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCard();
  });
})();
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
