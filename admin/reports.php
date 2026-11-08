<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_ADMIN);
$pageTitle = 'Reports';
$pageHeading = 'Reports & Statistics';
$activeNav = 'reports';

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$d = live_admin_reports_data($from, $to);
$from = $d['from'];
$to = $d['to'];
$popular = $d['popular'];
$byRole = $d['byRole'];
$bookingStats = $d['bookingStats'];
$guideUtil = $d['guideUtil'];
$bizPopular = $d['bizPopular'];
$bizCount = $d['bizCount'];
$guideCount = $d['guideCount'];
$totalInRange = $d['totalInRange'];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="cultour-report-' . ($from ?: 'all') . '-to-' . ($to ?: 'all') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['CulTour report', $from . ' to ' . $to]);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Approved Businesses', $bizCount]);
    fputcsv($out, ['Bookings in range', $totalInRange]);
    foreach ($byRole as $r) {
        fputcsv($out, ['Users: ' . $r['name'], $r['total']]);
    }
    foreach ($bookingStats as $r) {
        fputcsv($out, ['Bookings: ' . $r['status'], $r['total']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Guide', 'Total', 'Completed', 'Approved', 'Pending', 'Avg rating']);
    foreach ($guideUtil as $r) {
        fputcsv($out, [$r['guide_name'], $r['total_bookings'], $r['completed'], $r['approved'], $r['pending'], $r['avg_rating']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Government site', 'Stops']);
    foreach ($popular as $r) {
        fputcsv($out, [$r['name'], $r['stops']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Business', 'Type', 'Stops']);
    foreach ($bizPopular as $r) {
        fputcsv($out, [$r['name'], $r['type_name'], $r['stops']]);
    }
    fclose($out);
    exit;
}

$live = live_admin_reports_payload($from, $to);
$query = http_build_query(['from' => $from, 'to' => $to]);

$bodyClass = 'admin-reports-page';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <form class="reports-filters glass mb-3" method="get">
        <div class="reports-filters__fields">
          <div>
            <label class="form-label small mb-1" for="reportFrom">From</label>
            <input type="date" class="form-control" id="reportFrom" name="from" value="<?= e($from) ?>">
          </div>
          <div>
            <label class="form-label small mb-1" for="reportTo">To</label>
            <input type="date" class="form-control" id="reportTo" name="to" value="<?= e($to) ?>">
          </div>
        </div>
        <div class="reports-filters__actions">
          <a class="btn btn-outline-secondary" href="?export=csv&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">
            <i class="bi bi-filetype-csv"></i> Download CSV
          </a>
        </div>
      </form>

      <div data-live-page="admin_reports" data-live-version="<?= e($live['version']) ?>" data-live-query="<?= e($query) ?>">
        <?= $live['html'] ?>
      </div>
    </div>
  </div>
</div>
<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  var form = document.querySelector('.reports-filters');
  if (form) {
    form.querySelectorAll('input[type="date"]').forEach(function (el) {
      el.addEventListener('change', function () {
        form.requestSubmit ? form.requestSubmit() : form.submit();
      });
    });
  }
  function parkPopularModals() {
    ['popularDestModal', 'popularBizModal', 'guideUtilModal'].forEach(function (id) {
      document.querySelectorAll('body > #' + id).forEach(function (el) {
        if (!el.classList.contains('show')) el.remove();
      });
      var el = document.getElementById(id);
      if (el && el.parentElement !== document.body) document.body.appendChild(el);
    });
  }
  parkPopularModals();
  document.addEventListener('cultour:live-replaced', parkPopularModals);
})();
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
