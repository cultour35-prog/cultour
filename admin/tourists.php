<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_registrations.php';
$user = require_login(ROLE_ADMIN);
ensure_password_schema();
ensure_user_identity_schema();
$pageTitle = 'Tourists';
$pageHeading = 'Tourists';
$pageSub = 'Add tourists and manage registered accounts';
$activeNav = 'tourists';
$liveRegistrations = 'tourists';

$edit = null;
$isEditingTourist = false;
$viewId = (int) ($_GET['view'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$detailId = $editId > 0 ? $editId : $viewId;
if ($detailId > 0) {
    $s = db()->prepare('SELECT * FROM users WHERE id=? AND role_id=?');
    $s->execute([$detailId, ROLE_TOURIST]);
    $edit = $s->fetch() ?: null;
    if (!$edit) {
        flash('error', 'Tourist not found.');
        redirect(APP_URL . '/admin/tourists.php');
    }
    $isEditingTourist = $editId > 0;
    $pageTitle = full_name($edit);
    $pageHeading = $pageTitle !== '' ? $pageTitle : 'Tourist';
    $pageSub = $isEditingTourist ? 'Edit tourist profile' : 'Tourist profile';
    $liveRegistrations = '';
}

$forceShowAdd = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'suspend') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $reason = trim((string) ($_POST['suspend_reason'] ?? ''));
        $result = admin_try_suspend_role_user($id, (int) $user['id'], ROLE_TOURIST, $reason);
        if ($result['ok']) {
            log_activity((int) $user['id'], 'suspend_user', 'tourists', 'Tourist #' . $id . ': ' . $reason);
            flash('success', 'Tourist suspended.');
            redirect(APP_URL . '/admin/tourists.php?tab=suspended');
        }
        flash('error', $result['message']);
        redirect(APP_URL . '/admin/tourists.php');
    }

    if ($action === 'create') {
        $plainPassword = admin_random_tourist_password();
        $sameRaw = $_POST['current_same_as_permanent'] ?? '0';
        if (is_array($sameRaw)) {
            $sameRaw = end($sameRaw);
        }
        $result = register_user([
            'admin_created' => true,
            'password' => $plainPassword,
            'password_confirm' => $plainPassword,
            'role_id' => ROLE_TOURIST,
            'email' => $_POST['email'] ?? '',
            'first_name' => $_POST['first_name'] ?? '',
            'last_name' => $_POST['last_name'] ?? '',
            'middle_name' => $_POST['middle_name'] ?? '',
            'name_suffix' => resolve_posted_name_suffix('name_suffix', $_POST),
            'sex' => $_POST['sex'] ?? '',
            'date_of_birth' => $_POST['date_of_birth'] ?? '',
            'nationality' => $_POST['nationality'] ?? '',
            'phone_local' => $_POST['phone_local'] ?? '',
            'tourist_languages' => $_POST['tourist_languages'] ?? '',
            'use_structured_address' => '1',
            'address_region' => $_POST['address_region'] ?? '',
            'address_city' => $_POST['address_city'] ?? '',
            'address_barangay' => $_POST['address_barangay'] ?? '',
            'address_street' => $_POST['address_street'] ?? '',
            'current_same_as_permanent' => ((string) $sameRaw !== '0') ? '1' : '0',
            'current_address_region' => $_POST['current_address_region'] ?? '',
            'current_address_city' => $_POST['current_address_city'] ?? '',
            'current_address_barangay' => $_POST['current_address_barangay'] ?? '',
            'current_address_street' => $_POST['current_address_street'] ?? '',
            'emergency_first_name' => $_POST['emergency_first_name'] ?? '',
            'emergency_middle_name' => $_POST['emergency_middle_name'] ?? '',
            'emergency_last_name' => $_POST['emergency_last_name'] ?? '',
            'emergency_suffix' => resolve_posted_name_suffix('emergency_suffix', $_POST),
            'emergency_relationship' => resolve_posted_emergency_relationship($_POST),
            'emergency_phone_local' => $_POST['emergency_phone_local'] ?? '',
        ]);
        if (!$result['ok']) {
            flash('error', (string) ($result['message'] ?? 'Could not add this tourist.'));
            $forceShowAdd = true;
        } else {
            $uid = (int) ($result['user_id'] ?? 0);
            log_activity((int) $user['id'], 'create_tourist', 'tourists', 'Tourist user #' . $uid);
            $_SESSION['admin_created_tourist'] = [
                'name' => trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')),
                'email' => (string) ($result['email'] ?? ''),
                'phone' => (string) ($result['phone'] ?? ''),
                'languages' => (string) ($result['languages'] ?? ''),
                'password' => $plainPassword,
                'login_url' => APP_URL . '/login.php',
            ];
            flash('success', 'Tourist added. Print the login card for them.');
            redirect(APP_URL . '/admin/tourists.php?created=1');
        }
    }

    if ($action === 'update') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $st = db()->prepare('SELECT * FROM users WHERE id=? AND role_id=?');
        $st->execute([$uid, ROLE_TOURIST]);
        $profileUser = $st->fetch() ?: null;
        if (!$profileUser) {
            flash('error', 'Tourist not found.');
            redirect(APP_URL . '/admin/tourists.php');
        }
        $_POST['use_structured_address'] = '1';
        $identity = user_parse_identity($_POST, true);
        if (!$identity['ok']) {
            flash('error', (string) ($identity['message'] ?? 'Please complete the personal details.'));
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        $emerg = user_parse_emergency_contact($_POST, true);
        if (!$emerg['ok']) {
            flash('error', (string) ($emerg['message'] ?? 'Please complete the emergency contact.'));
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        $first = capitalize_name((string) ($_POST['first_name'] ?? ''));
        $last = capitalize_name((string) ($_POST['last_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($first === '' || $last === '') {
            flash('error', 'First name and last name are required.');
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'A valid email is required.');
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        $emailTaken = db()->prepare('SELECT id FROM users WHERE email=? AND role_id=? AND id<>?');
        $emailTaken->execute([$email, ROLE_TOURIST, $uid]);
        if ($emailTaken->fetch()) {
            flash('error', 'That email is already registered as a Tourist.');
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        $selfName = join_person_name(
            $first,
            capitalize_name((string) ($_POST['middle_name'] ?? ($identity['data']['middle_name'] ?? ''))),
            $last,
            resolve_posted_name_suffix('name_suffix', $_POST)
        );
        $diff = emergency_contact_differs_from_account(
            ['name' => $selfName, 'phone' => (string) ($_POST['phone'] ?? '')],
            $emerg['data']
        );
        if (!$diff['ok']) {
            flash('error', (string) ($diff['message'] ?? 'Emergency contact cannot match the tourist details.'));
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        $avatar = trim((string) ($profileUser['avatar'] ?? ''));
        if (!empty($_FILES['avatar']['name'])) {
            $up = upload_file($_FILES['avatar'], 'profiles', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($up) {
                $avatar = $up;
            } else {
                flash('error', upload_last_error() ?: 'Could not upload profile photo. Use JPG, PNG, GIF, or WebP under 8 MB.');
                redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
            }
        }
        $saved = user_save_identity($uid, $identity['data'], $first, $last, trim((string) ($_POST['phone'] ?? '')), $avatar !== '' ? $avatar : null);
        if (empty($saved['ok'])) {
            flash('error', (string) ($saved['message'] ?? 'Could not save tourist details.'));
            redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
        }
        user_save_emergency_contact($uid, $emerg['data']);
        db()->prepare('UPDATE users SET email=?, must_complete_profile=0 WHERE id=? AND role_id=?')->execute([$email, $uid, ROLE_TOURIST]);
        log_activity((int) $user['id'], 'update_tourist', 'tourists', 'Tourist #' . $uid);
        flash('success', 'Tourist profile saved.');
        redirect(APP_URL . '/admin/tourists.php?edit=' . $uid);
    }

    if (empty($forceShowAdd)) {
        redirect(APP_URL . '/admin/tourists.php');
    }
}

$showAdd = !$edit && (isset($_GET['add']) || !empty($forceShowAdd));

$createdTourist = null;
if ((string) ($_GET['created'] ?? '') === '1' && !empty($_SESSION['admin_created_tourist']) && is_array($_SESSION['admin_created_tourist'])) {
    $createdTourist = $_SESSION['admin_created_tourist'];
} else {
    unset($_SESSION['admin_created_tourist']);
}
$touristRows = $edit ? [] : admin_tourists_fetch_rows();
$touristSplit = $edit ? ['registered' => [], 'suspended' => []] : admin_tourists_split($touristRows);
$touristsLiveVersion = $edit ? '' : admin_tourists_version($touristRows);
$touristSections = [
    [
        'id' => 'registered',
        'title' => 'Registered',
        'sub' => 'Active tourist accounts',
        'badge' => 'text-bg-success',
        'btn' => 'Registered',
        'rows' => $touristSplit['registered'],
        'empty' => 'No registered tourists yet. Click Add tourist to create one.',
    ],
    [
        'id' => 'suspended',
        'title' => 'Suspended',
        'sub' => 'Tourists who cannot book until restored',
        'badge' => 'text-bg-warning',
        'btn' => 'Suspended',
        'rows' => $touristSplit['suspended'],
        'empty' => 'No suspended tourists.',
    ],
];
$defaultTouristTab = 'registered';
if (!empty($_GET['tab']) && in_array($_GET['tab'], ['registered', 'suspended'], true)) {
    $defaultTouristTab = $_GET['tab'];
}

$loadLangTags = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('warning')): ?><div class="alert alert-warning"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div id="registrationsFlash"></div>

      <?php if ($edit && $isEditingTourist): ?>
        <div class="mb-3 d-flex flex-wrap gap-2 admin-profile-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/tourists.php">&larr; Back to tourists</a>
          <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/tourists.php?view=<?= (int) $edit['id'] ?>">View profile</a>
        </div>
        <?php
          $profileUser = $edit;
          $touristProfileRequirePhoto = false;
          require __DIR__ . '/../includes/tourist_profile_form.php';
        ?>
      <?php elseif ($edit): ?>
        <?php
          $viewName = full_name($edit);
          $viewAvatar = trim((string) ($edit['avatar'] ?? ''));
          $viewAvatarUrl = $viewAvatar !== '' ? (APP_URL . '/uploads/' . ltrim($viewAvatar, '/')) : '';
          $viewInitial = mb_strtoupper(mb_substr($edit['first_name'] ?: ($viewName ?: 'T'), 0, 1));
          $permLine = admin_tourist_address_line($edit);
          $curLine = admin_tourist_address_line($edit, 'current_');
          $emergName = trim((string) ($edit['emergency_contact_name'] ?? ''));
        ?>
        <div class="mb-3 d-flex flex-wrap gap-2 admin-profile-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/tourists.php">&larr; Back to tourists</a>
          <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/tourists.php?edit=<?= (int) $edit['id'] ?>">Edit details</a>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#activityLogModal">Activity logs</button>
          <?= admin_suspend_button((int) $edit['id'], $viewName, (string) ($edit['status'] ?? '')) ?>
        </div>

        <div class="glass p-3 p-md-4 mb-3 fade-up admin-guide-profile">
          <div class="admin-guide-profile__hero">
            <div class="admin-guide-profile__photo">
              <?php if ($viewAvatarUrl): ?>
                <img src="<?= e($viewAvatarUrl) ?>" alt="<?= e($viewName) ?>">
              <?php else: ?>
                <div class="admin-guide-profile__photo-fallback" aria-hidden="true"><?= e($viewInitial) ?></div>
              <?php endif; ?>
            </div>
            <div class="admin-guide-profile__intro">
              <div class="small text-muted mb-1">Tourist · <?= admin_user_status_badge($edit) ?></div>
              <h2 class="h4 mb-1"><?= e($viewName !== '' ? $viewName : 'Unnamed tourist') ?></h2>
              <div class="text-muted"><?= e($edit['email']) ?><?= !empty($edit['phone']) ? ' · ' . e($edit['phone']) : '' ?></div>
            </div>
          </div>
        </div>

        <div class="glass p-3 p-md-4 fade-up">
          <h3 class="panel-title mb-3">Profile details</h3>
          <dl class="admin-guide-profile__dl mb-0">
            <div class="admin-guide-profile__col">
              <div>
                <dt>First name</dt>
                <dd><?= e((string) ($edit['first_name'] ?? '—') ?: '—') ?></dd>
              </div>
              <div>
                <dt>Middle name</dt>
                <dd><?= e(trim((string) ($edit['middle_name'] ?? '')) !== '' ? (string) $edit['middle_name'] : '—') ?></dd>
              </div>
              <div>
                <dt>Last name</dt>
                <dd><?= e((string) ($edit['last_name'] ?? '—') ?: '—') ?></dd>
              </div>
              <div>
                <dt>Suffix</dt>
                <dd><?= e(trim((string) ($edit['name_suffix'] ?? '')) !== '' ? (string) $edit['name_suffix'] : '—') ?></dd>
              </div>
              <div>
                <dt>Gender</dt>
                <dd><?= e(user_sex_label($edit['sex'] ?? null)) ?></dd>
              </div>
              <div>
                <dt>Nationality</dt>
                <dd><?= e(trim((string) ($edit['nationality'] ?? '')) !== '' ? (string) $edit['nationality'] : '—') ?></dd>
              </div>
              <div>
                <dt>Languages</dt>
                <dd><?= e(trim((string) ($edit['languages'] ?? '')) !== '' ? (string) $edit['languages'] : '—') ?></dd>
              </div>
              <div>
                <dt>Date of birth</dt>
                <dd><?php
                  $dob = trim((string) ($edit['date_of_birth'] ?? ''));
                  if ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                      $ts = strtotime($dob);
                      echo e($ts ? date('M j, Y', $ts) : $dob);
                  } else {
                      echo '—';
                  }
                ?></dd>
              </div>
            </div>
            <div class="admin-guide-profile__col">
              <div>
                <dt>Email</dt>
                <dd><?= e(trim((string) ($edit['email'] ?? '')) !== '' ? (string) $edit['email'] : '—') ?></dd>
              </div>
              <div>
                <dt>Phone</dt>
                <dd><?= e(!empty($edit['phone']) ? (string) $edit['phone'] : '—') ?></dd>
              </div>
              <div>
                <dt>Permanent address</dt>
                <dd><?= e($permLine !== '' ? $permLine : '—') ?></dd>
              </div>
              <div>
                <dt>Current address</dt>
                <dd><?= e($curLine !== '' ? $curLine : '—') ?></dd>
              </div>
              <div>
                <dt>Emergency contact</dt>
                <dd><?= e($emergName !== '' ? $emergName : '—') ?></dd>
              </div>
              <div>
                <dt>Relationship</dt>
                <dd><?= e(trim((string) ($edit['emergency_contact_relationship'] ?? '')) !== '' ? (string) $edit['emergency_contact_relationship'] : '—') ?></dd>
              </div>
              <div>
                <dt>Emergency phone</dt>
                <dd><?= e(trim((string) ($edit['emergency_contact_phone'] ?? '')) !== '' ? (string) $edit['emergency_contact_phone'] : '—') ?></dd>
              </div>
              <div>
                <dt>Joined</dt>
                <dd><?= e(format_date($edit['created_at'] ?? '')) ?></dd>
              </div>
            </div>
          </dl>
        </div>
        <?php
          $activityLogs = user_activity_logs((int) $edit['id']);
          require __DIR__ . '/../includes/admin_subject_activity_logs.php';
        ?>
      <?php endif; ?>

      <?php if (!$edit): ?>
      <div class="glass p-3 mb-4 fade-up">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h3 class="panel-title mb-1"><?= $showAdd ? 'Add tourist' : 'Tourists' ?></h3>
            <?php if (!$showAdd): ?>
            <p class="small text-muted mb-0">Choose a list to view registered or suspended tourists</p>
            <?php endif; ?>
          </div>
          <?php if (!$showAdd): ?>
            <a class="btn btn-ct" href="<?= APP_URL ?>/admin/tourists.php?add=1">
              <i class="bi bi-plus-lg"></i> Add tourist
            </a>
          <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/admin/tourists.php">Cancel</a>
          <?php endif; ?>
        </div>
        <?php if (!$showAdd): ?>
        <div class="guide-tabs" role="tablist" aria-label="Tourist lists" data-live-regs="tourists" data-live-version="<?= e($touristsLiveVersion) ?>">
          <?php foreach ($touristSections as $section): ?>
            <button
              type="button"
              class="guide-tabs__btn<?= $defaultTouristTab === $section['id'] ? ' is-active' : '' ?>"
              data-guide-tab="<?= e($section['id']) ?>"
              role="tab"
              aria-selected="<?= $defaultTouristTab === $section['id'] ? 'true' : 'false' ?>"
            >
              <span><?= e($section['btn']) ?></span>
              <span class="badge <?= e($section['badge']) ?>" data-live-count="<?= e($section['id']) ?>"><?= count($section['rows']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($showAdd): ?>
      <div class="glass p-3 p-md-4 mb-4 fade-up">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h3 class="panel-title mb-0">Add tourist</h3>
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/tourists.php">Cancel</a>
        </div>
        <form method="post" id="adminAddTouristForm"><?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <?php require __DIR__ . '/../includes/admin_tourist_create_form.php'; ?>
          <div class="mt-4">
            <button class="btn btn-ct" type="submit">Add tourist</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!$edit && !$showAdd): ?>
      <div class="guide-sections">
        <?php foreach ($touristSections as $section): ?>
          <div
            class="glass guide-section<?= $defaultTouristTab === $section['id'] ? ' is-active' : '' ?>"
            data-guide-panel="<?= e($section['id']) ?>"
            role="tabpanel"
            <?= $defaultTouristTab === $section['id'] ? '' : 'hidden' ?>
          >
            <div class="guide-section__head">
              <div class="guide-section__titles">
                <h3 class="panel-title mb-0"><?= e($section['title']) ?></h3>
                <p class="small text-muted mb-0"><?= e($section['sub']) ?></p>
              </div>
              <span class="badge <?= e($section['badge']) ?> guide-section__count" data-live-panel-count="<?= e($section['id']) ?>"><?= count($section['rows']) ?></span>
            </div>
            <div class="guide-section__list table-responsive">
              <table class="table table-ct align-middle mb-0 guide-table">
                <colgroup>
                  <col class="guide-col-guide">
                  <col class="guide-col-lang">
                  <col class="guide-col-status">
                  <col class="guide-col-rate">
                  <col class="guide-col-actions">
                </colgroup>
                <thead>
                  <tr>
                    <th>Tourist</th>
                    <th>Languages</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody data-live-tbody="<?= e($section['id']) ?>" data-live-empty="<?= e($section['empty']) ?>" data-live-cols="5">
                  <?= admin_render_tourist_rows($section['rows']) ?>
                  <?php if (!$section['rows']): ?>
                    <tr class="live-empty-row"><td colspan="5" class="text-muted"><?= e($section['empty']) ?></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php
$extraScripts = ($extraScripts ?? '') . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const buttons = Array.prototype.slice.call(document.querySelectorAll('[data-guide-tab]'));
  const panels = Array.prototype.slice.call(document.querySelectorAll('[data-guide-panel]'));
  if (!buttons.length || !panels.length) return;

  function showTab(id) {
    buttons.forEach(function (btn) {
      const on = btn.getAttribute('data-guide-tab') === id;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      const on = panel.getAttribute('data-guide-panel') === id;
      panel.classList.toggle('is-active', on);
      if (on) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
    });
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      window.history.replaceState({}, '', url);
    } catch (e) { /* ignore */ }
  }

  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      showTab(btn.getAttribute('data-guide-tab'));
    });
  });
});
</script>
HTML;
      ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!empty($createdTourist) && is_array($createdTourist)): ?>
  <?php
    $ctName = trim((string) ($createdTourist['name'] ?? ''));
    $ctEmail = trim((string) ($createdTourist['email'] ?? ''));
    $ctPhone = trim((string) ($createdTourist['phone'] ?? ''));
    $ctLang = trim((string) ($createdTourist['languages'] ?? ''));
    $ctPass = (string) ($createdTourist['password'] ?? '');
  ?>
  <div id="adminCreatedTouristOverlay" class="admin-biz-proof-overlay admin-created-cred-overlay">
    <div class="admin-biz-proof-card admin-created-cred-card admin-created-cred-print-sheet">
      <div class="admin-biz-proof-card__head">
        <div>
          <div class="small text-muted"><?= e(APP_NAME) ?></div>
          <h2 class="h6 mb-0">Tourist account</h2>
        </div>
        <button type="button" class="admin-biz-proof-card__close admin-created-cred-actions" data-close-created-tourist aria-label="Close">&times;</button>
      </div>
      <div class="admin-biz-proof-card__body admin-created-cred-body">
        <p class="small text-muted mb-3">Give this slip to the tourist. They must change the password on first login.</p>
        <dl class="admin-created-cred-dl">
          <dt>Name</dt>
          <dd><?= e($ctName !== '' ? $ctName : '—') ?></dd>
          <dt>Email</dt>
          <dd><?= e($ctEmail !== '' ? $ctEmail : '—') ?></dd>
          <dt>Phone</dt>
          <dd><?= e($ctPhone !== '' ? $ctPhone : '—') ?></dd>
          <?php if ($ctLang !== ''): ?>
            <dt>Languages</dt>
            <dd><?= e($ctLang) ?></dd>
          <?php endif; ?>
        </dl>
        <div class="admin-created-cred-passbox">
          <div class="admin-created-cred-passbox__label">Temporary password</div>
          <div class="admin-created-cred-passbox__value"><?= e($ctPass) ?></div>
        </div>
        <div class="admin-created-cred-actions d-flex flex-wrap gap-2 mt-3">
          <button type="button" class="btn btn-ct" data-print-created-tourist>
            <i class="bi bi-printer"></i> Print
          </button>
          <button type="button" class="btn btn-outline-secondary" data-close-created-tourist>Done</button>
        </div>
      </div>
    </div>
  </div>
<?php
$extraScripts = ($extraScripts ?? '') . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('adminCreatedTouristOverlay');
  if (!overlay) return;
  document.body.appendChild(overlay);
  overlay.hidden = false;
  document.body.classList.add('admin-biz-proof-open', 'admin-created-cred-open');
  function closeCreated() {
    overlay.hidden = true;
    document.body.classList.remove('admin-biz-proof-open', 'admin-created-cred-open');
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete('created');
      window.history.replaceState({}, '', url);
    } catch (e) {}
  }
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay || e.target.closest('[data-close-created-tourist]')) closeCreated();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeCreated();
  });
  var printBtn = overlay.querySelector('[data-print-created-tourist]');
  if (printBtn) {
    printBtn.addEventListener('click', function () { window.print(); });
  }
});
</script>
HTML;
endif;
?>
<?php if (!empty($showAdd)): ?>
<?php
  $appUrl = APP_URL;
  $phJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time());
  $addJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/admin-add-tourist.js') ?: time());
  $extraScripts = ($extraScripts ?? '') . <<<HTML
<script src="{$appUrl}/assets/js/ph-address.js?v={$phJsVer}"></script>
<script>
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('{$appUrl}/assets/data/ph-address.json');
  }
</script>
<script src="{$appUrl}/assets/js/admin-add-tourist.js?v={$addJsVer}"></script>
HTML;
endif;
?>
<?php
if ($edit && $isEditingTourist) {
    render_unsaved_changes_modal();
}
$suspendFormAction = APP_URL . '/admin/tourists.php';
require __DIR__ . '/_suspend_modal.php';
require __DIR__ . '/../includes/footer.php';
?>
