<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/suspension.php';
require_once __DIR__ . '/../includes/admin_registrations.php';

$user = require_login(ROLE_ADMIN);
ensure_suspension_schema();
$pageTitle = 'Users';
$pageHeading = 'Manage Users';
$activeNav = 'users';
$liveRegistrations = 'users';

$allowedRoles = [
    'tourist' => ROLE_TOURIST,
    'tour_guide' => ROLE_TOUR_GUIDE,
    'business' => ROLE_BUSINESS,
];
$allowedStatuses = ['pending', 'active', 'rejected', 'suspended'];

$q = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusExplicit = array_key_exists('status', $_GET);
$statusFilter = $statusExplicit ? trim((string) $_GET['status']) : 'active';
$dateFrom = trim($_GET['from'] ?? '');
$dateTo = trim($_GET['to'] ?? '');

if ($roleFilter !== '' && !isset($allowedRoles[$roleFilter])) {
    $roleFilter = '';
}
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$queryParams = array_filter([
    'q' => $q !== '' ? $q : null,
    'role' => $roleFilter !== '' ? $roleFilter : null,
    'from' => $dateFrom !== '' ? $dateFrom : null,
    'to' => $dateTo !== '' ? $dateTo : null,
], static fn($v) => $v !== null);

if ($statusFilter !== '') {
    $queryParams['status'] = $statusFilter;
} elseif ($statusExplicit) {
    $queryParams['status'] = ''; // All statuses
} else {
    $queryParams['status'] = 'active';
}

$usersListUrl = APP_URL . '/admin/users.php' . ($queryParams ? '?' . http_build_query($queryParams) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $id = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && $id !== (int) $user['id']) {
        if ($action === 'suspend') {
            $reason = trim($_POST['suspend_reason'] ?? '');
            $result = suspend_user($id, (int) $user['id'], $reason);
            if ($result['ok']) {
                log_activity((int) $user['id'], 'suspend_user', 'users', 'User #' . $id . ': ' . $reason);
                flash('success', 'User suspended.');
            } else {
                flash('error', $result['message']);
            }
        } elseif ($action === 'reject') {
            $target = db()->prepare('SELECT id, role_id FROM users WHERE id=? LIMIT 1');
            $target->execute([$id]);
            $targetRow = $target->fetch();
            if (
                !$targetRow
                || (int) ($targetRow['role_id'] ?? 0) === ROLE_ADMIN
                || (int) ($targetRow['id'] ?? 0) === (int) $user['id']
            ) {
                flash('error', 'That account cannot be rejected.');
            } else {
                db()->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$id]);
                log_activity((int) $user['id'], 'reject_user', 'users', 'User #' . $id);
                flash('success', 'User updated.');
            }
        }
    }
    redirect($usersListUrl);
}

$users = admin_users_fetch_rows([
    'q' => $q,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'from' => $dateFrom,
    'to' => $dateTo,
]);
$usersLiveVersion = admin_users_version($users);
$hasFilters = $q !== '' || $roleFilter !== '' || $dateFrom !== '' || $dateTo !== ''
    || ($statusExplicit && $statusFilter !== 'active');

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div id="registrationsFlash"></div>

      <div class="glass p-3 mb-4">
        <form method="get" class="row g-3 align-items-end" id="usersFilterForm">
          <div class="col-md-4 col-lg-3">
            <label class="form-label">Search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Name or email" autocomplete="off">
          </div>
          <div class="col-md-4 col-lg-2">
            <label class="form-label">Role</label>
            <select class="form-select" name="role">
              <option value="">All roles</option>
              <option value="tourist" <?= $roleFilter === 'tourist' ? 'selected' : '' ?>>Tourist</option>
              <option value="tour_guide" <?= $roleFilter === 'tour_guide' ? 'selected' : '' ?>>Tour guide</option>
              <option value="business" <?= $roleFilter === 'business' ? 'selected' : '' ?>>Business</option>
            </select>
          </div>
          <div class="col-md-4 col-lg-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
              <option value="" <?= $statusExplicit && $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
              <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
              <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
          </div>
          <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label">Joined from</label>
            <input type="date" class="form-control" name="from" value="<?= e($dateFrom) ?>">
          </div>
          <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label">Joined to</label>
            <input type="date" class="form-control" name="to" value="<?= e($dateTo) ?>">
          </div>
          <?php if ($hasFilters): ?>
            <div class="col-12 col-lg-auto">
              <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/admin/users.php">Clear</a>
            </div>
          <?php endif; ?>
        </form>
      </div>

      <div class="glass p-3" data-live-regs="users" data-live-version="<?= e($usersLiveVersion) ?>" data-live-query="<?= e(http_build_query($queryParams)) ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h3 class="panel-title mb-0">Users</h3>
            <p class="small text-muted mb-0"><span data-live-count="total"><?= count($users) ?></span> result<?= count($users) === 1 ? '' : 's' ?></p>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-ct align-middle mb-0">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th></th></tr></thead>
            <tbody data-live-tbody="users" data-live-empty="No users match these filters." data-live-cols="6">
              <?php
              $rowsHtml = admin_render_user_rows($users, (int) $user['id']);
              echo $rowsHtml !== ''
                  ? $rowsHtml
                  : '<tr class="live-empty-row"><td colspan="6" class="text-muted">No users match these filters.</td></tr>';
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="suspendModal" tabindex="-1" aria-labelledby="suspendModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" action="<?= e($usersListUrl) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="suspend">
      <input type="hidden" name="user_id" id="suspendUserId" value="">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="suspendModalLabel">Suspend account</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Suspending <strong id="suspendUserName"></strong>. They can still log in to see this reason and submit an appeal.</p>
        <label class="form-label" for="suspendReason">Reason <span class="text-danger">*</span></label>
        <textarea class="form-control" id="suspendReason" name="suspend_reason" rows="4" required maxlength="2000" placeholder="Explain why this account is being suspended…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning">Suspend account</button>
      </div>
    </form>
  </div>
</div>
<?php
$extraScripts = <<<'HTML'
<script>
document.getElementById('suspendModal')?.addEventListener('show.bs.modal', function (event) {
  const btn = event.relatedTarget;
  if (!btn) return;
  document.getElementById('suspendUserId').value = btn.getAttribute('data-user-id') || '';
  document.getElementById('suspendUserName').textContent = btn.getAttribute('data-user-name') || 'this user';
  document.getElementById('suspendReason').value = '';
});

(function () {
  const form = document.getElementById('usersFilterForm');
  const liveRoot = document.querySelector('[data-live-regs="users"]');
  const appUrl = (window.CulTour && window.CulTour.appUrl) || '';
  if (!form || !appUrl) return;

  let searchTimer = null;
  let requestSeq = 0;

  function buildParams() {
    const fd = new FormData(form);
    const params = new URLSearchParams();
    fd.forEach(function (value, key) {
      const v = String(value == null ? '' : value);
      if (key === 'status' || v.trim() !== '') {
        params.set(key, v);
      }
    });
    return params;
  }

  function setResultCount(n) {
    const countEl = liveRoot && liveRoot.querySelector('[data-live-count="total"]');
    if (!countEl || !countEl.parentElement) return;
    const parent = countEl.parentElement;
    parent.innerHTML = '';
    const span = document.createElement('span');
    span.setAttribute('data-live-count', 'total');
    span.textContent = String(n);
    parent.appendChild(span);
    parent.appendChild(document.createTextNode(n === 1 ? ' result' : ' results'));
  }

  function fillUsers(html, emptyMsg) {
    const tbody = document.querySelector('[data-live-tbody="users"]');
    if (!tbody) return;
    const empty = emptyMsg || tbody.getAttribute('data-live-empty') || 'No users match these filters.';
    const cols = tbody.getAttribute('data-live-cols') || '6';
    if (html) {
      tbody.innerHTML = html;
    } else {
      tbody.innerHTML =
        '<tr class="live-empty-row"><td colspan="' +
        cols +
        '" class="text-muted">' +
        empty.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
        '</td></tr>';
    }
  }

  function refreshResults(options) {
    const opts = options || {};
    const params = buildParams();
    const query = params.toString();
    const seq = ++requestSeq;
    const url = appUrl + '/api/admin/registrations.php?kind=users' + (query ? '&' + query : '');

    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (seq !== requestSeq || !data || !data.ok) return;

        fillUsers(data.html || '', data.empty || '');
        if (data.total != null) setResultCount(Number(data.total) || 0);

        if (liveRoot) {
          liveRoot.setAttribute('data-live-query', query);
          if (data.version) liveRoot.setAttribute('data-live-version', data.version);
        }

        const pageUrl = appUrl + '/admin/users.php' + (query ? '?' + query : '');
        if (window.history && window.history.replaceState) {
          window.history.replaceState({}, '', pageUrl);
        }

        const suspendForm = document.querySelector('#suspendModal form');
        if (suspendForm) suspendForm.action = pageUrl;
      })
      .catch(function () {});
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearTimeout(searchTimer);
    refreshResults();
  });

  form.querySelectorAll('select, input[type="date"]').forEach(function (el) {
    el.addEventListener('change', function () {
      clearTimeout(searchTimer);
      refreshResults();
    });
  });

  const search = form.querySelector('input[name="q"]');
  if (search) {
    search.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        refreshResults();
      }, 450);
    });
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimer);
        refreshResults();
      }
    });
  }
})();
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
