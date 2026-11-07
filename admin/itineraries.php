<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_ADMIN);
$pageTitle = 'Itineraries';
$pageHeading = 'Manage Itineraries';
$activeNav = 'itineraries';

/**
 * @return array{0:string,1:string}
 */
function admin_itineraries_sort_params(string $sort, string $dir): array
{
    $allowed = ['title', 'owner', 'dates', 'stops', 'status', 'type'];
    $sort = in_array($sort, $allowed, true) ? $sort : 'dates';
    $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
    return [$sort, $dir];
}

function admin_itineraries_sort_href(string $key, string $active, string $dir): string
{
    [$active, $dir] = admin_itineraries_sort_params($active, $dir);
    $nextDir = ($active === $key && $dir === 'asc') ? 'desc' : 'asc';
    return APP_URL . '/admin/itineraries.php?' . http_build_query(['sort' => $key, 'dir' => $nextDir]);
}

[$itinSort, $itinDir] = admin_itineraries_sort_params((string) ($_GET['sort'] ?? 'dates'), (string) ($_GET['dir'] ?? 'desc'));
$itinListUrl = APP_URL . '/admin/itineraries.php?' . http_build_query(['sort' => $itinSort, 'dir' => $itinDir]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && $action === 'delete') {
        db()->prepare('DELETE FROM itineraries WHERE id=?')->execute([$id]);
        log_activity((int) $user['id'], 'delete_itinerary', 'itineraries', 'Itinerary #' . $id);
        flash('success', 'Itinerary deleted.');
    } elseif ($id && $action === 'archive') {
        db()->prepare("UPDATE itineraries SET status='archived' WHERE id=?")->execute([$id]);
        flash('success', 'Itinerary archived.');
    } elseif ($id && $action === 'activate') {
        db()->prepare("UPDATE itineraries SET status='active' WHERE id=?")->execute([$id]);
        flash('success', 'Itinerary set to active.');
    }
    redirect($itinListUrl);
}

$sqlDir = $itinDir === 'asc' ? 'ASC' : 'DESC';
$itinOrder = match ($itinSort) {
    'title' => "i.title $sqlDir, i.id ASC",
    'owner' => "CONCAT(u.first_name,' ',u.last_name) $sqlDir, i.start_date DESC, i.id ASC",
    'stops' => "(SELECT COUNT(*) FROM itinerary_items ii2 WHERE ii2.itinerary_id=i.id) $sqlDir, i.title ASC",
    'status' => "FIELD(i.status,'draft','active','completed','archived') $sqlDir, i.start_date DESC",
    'type' => "i.is_suggested $sqlDir, i.title ASC",
    default => "COALESCE(i.start_date, '1970-01-01') $sqlDir, i.id DESC",
};
$rows = db()->query(
    "SELECT i.*, CONCAT(u.first_name,' ',u.last_name) AS owner_name,
            (SELECT COUNT(*) FROM itinerary_items ii WHERE ii.itinerary_id=i.id) AS stops
     FROM itineraries i JOIN users u ON u.id=i.user_id
     ORDER BY $itinOrder"
)->fetchAll();
$itinSortHead = static function (string $key, string $label) use ($itinSort, $itinDir): string {
    $active = $itinSort === $key;
    $icon = $active
        ? ($itinDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill')
        : 'bi-caret-down-fill';
    return '<a class="bk-sort' . ($active ? ' is-active' : '') . '" href="' . e(admin_itineraries_sort_href($key, $itinSort, $itinDir)) . '">'
        . e($label) . ' <i class="bi ' . $icon . ($active ? '' : ' bk-sort__idle') . '" aria-hidden="true"></i></a>';
};

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <div class="glass p-3" data-live-self="admin-itineraries">
        <div class="table-responsive table-sticky-scroll">
          <table class="table table-ct mb-0 align-middle">
            <thead><tr>
              <th><?= $itinSortHead('title', 'Title') ?></th>
              <th><?= $itinSortHead('owner', 'Owner') ?></th>
              <th><?= $itinSortHead('dates', 'Dates') ?></th>
              <th><?= $itinSortHead('stops', 'Stops') ?></th>
              <th><?= $itinSortHead('status', 'Status') ?></th>
              <th><?= $itinSortHead('type', 'Type') ?></th>
              <th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= e($r['title']) ?></td>
                  <td><?= e($r['owner_name']) ?></td>
                  <td><?= e(format_date($r['start_date'])) ?> – <?= e(format_date($r['end_date'])) ?></td>
                  <td><?= (int)$r['stops'] ?></td>
                  <td><?= status_badge($r['status']) ?></td>
                  <td><?= $r['is_suggested'] ? 'Suggested' : 'Personal' ?></td>
                  <td class="text-nowrap">
                    <?php if (($r['status'] ?? '') !== 'archived'): ?>
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button name="action" value="archive" class="btn btn-sm btn-outline-secondary">Archive</button>
                      </form>
                    <?php else: ?>
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button name="action" value="activate" class="btn btn-sm btn-outline-secondary">Activate</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this itinerary permanently?')"><?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button name="action" value="delete" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No itineraries yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
