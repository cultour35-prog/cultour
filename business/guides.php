<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inhouse_guides.php';

$user = require_login(ROLE_BUSINESS);
inhouse_guides_ensure_schema();

$biz = db()->prepare(
    'SELECT b.id, b.name, bt.name AS type_name FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id WHERE b.user_id=? LIMIT 1'
);
$biz->execute([(int) $user['id']]);
$biz = $biz->fetch();
if (!$biz || business_catalog_mode($biz['type_name'] ?? '') !== 'packages') {
    flash('error', 'In-house guides are for tour and travel businesses.');
    redirect(APP_URL . '/business/dashboard.php');
}
$bizId = (int) $biz['id'];
$forceShowAdd = false;
$dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

$loadOwnGuide = static function (int $guideId) use ($bizId): ?array {
    if ($guideId < 1) {
        return null;
    }
    $st = db()->prepare(
        "SELECT * FROM tour_guide_profiles WHERE id=? AND guide_type='in_house' AND affiliated_business_id=? LIMIT 1"
    );
    $st->execute([$guideId, $bizId]);
    $row = $st->fetch();
    return $row ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['update', 'delete_document', 'upload_documents'], true)) {
        $owned = $loadOwnGuide((int) ($_POST['guide_id'] ?? 0));
        $profileUserRow = null;
        if ($owned) {
            $pu = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
            $pu->execute([(int) $owned['user_id']]);
            $profileUserRow = $pu->fetch() ?: null;
        }
        if (!$owned || !$profileUserRow) {
            flash('error', 'That guide is not on your team.');
            redirect(APP_URL . '/business/guides.php');
        }
        $GLOBALS['inhouseGuideAddressFree'] = true;
        guide_profile_apply_post(
            $profileUserRow,
            $owned,
            $dayLabels,
            APP_URL . '/business/guides.php?edit=' . (int) $owned['id'],
            false
        );
    }
    if ($action === 'create') {
        $plainPassword = admin_random_guide_password();
        $sameRaw = $_POST['current_same_as_permanent'] ?? '0';
        if (is_array($sameRaw)) {
            $sameRaw = end($sameRaw);
        }
        $result = register_user([
            'admin_created' => true,
            'approved_by' => (int) $user['id'],
            'password' => $plainPassword,
            'password_confirm' => $plainPassword,
            'role_id' => ROLE_TOUR_GUIDE,
            'email' => $_POST['email'] ?? '',
            'first_name' => $_POST['first_name'] ?? '',
            'last_name' => $_POST['last_name'] ?? '',
            'middle_name' => $_POST['middle_name'] ?? '',
            'name_suffix' => resolve_posted_name_suffix('name_suffix', $_POST),
            'sex' => $_POST['sex'] ?? '',
            'date_of_birth' => $_POST['date_of_birth'] ?? '',
            'nationality' => $_POST['nationality'] ?? '',
            'phone_local' => $_POST['phone_local'] ?? ($_POST['phone'] ?? ''),
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
            'languages' => $_POST['languages'] ?? '',
            'years_experience' => $_POST['years_experience'] ?? '',
            'credential_files' => $_FILES['resume_files'] ?? [],
            'skip_ormoc_residence' => true,
        ]);
        if (!$result['ok']) {
            flash('error', (string) ($result['message'] ?? 'Could not add this tour guide.'));
            $forceShowAdd = true;
        } else {
            db()->prepare(
                "UPDATE tour_guide_profiles
                 SET guide_type='in_house', affiliated_business_id=?, inhouse_active=1, approval_status='pending', approved_by=NULL, approved_at=NULL
                 WHERE user_id=?"
            )->execute([$bizId, (int) ($result['user_id'] ?? 0)]);
            $_SESSION['admin_created_guide'] = [
                'name' => trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')),
                'email' => (string) ($result['email'] ?? ''),
                'phone' => (string) ($result['phone'] ?? ''),
                'languages' => (string) ($result['languages'] ?? ''),
                'hourly_rate' => (int) ($_POST['hourly_rate'] ?? 0),
                'license' => trim((string) ($_POST['license_number'] ?? '')),
                'password' => $plainPassword,
                'login_url' => APP_URL . '/login.php',
            ];
            flash('success', 'In-house guide added. Tourism Admin still verifies them. Print the login card.');
            redirect(APP_URL . '/business/guides.php?created=1');
        }
    } elseif ($action === 'deactivate' || $action === 'activate') {
        $ok = inhouse_guide_set_active($bizId, (int) ($_POST['guide_id'] ?? 0), $action === 'activate');
        flash($ok ? 'success' : 'error', $ok ? 'Guide updated.' : 'That guide is not on your team.');
        redirect(APP_URL . '/business/guides.php');
    }
}

$showAdd = isset($_GET['add']) || $forceShowAdd;
$editId = (int) ($_GET['edit'] ?? 0);
$profileUser = null;
$profile = [];
$editDocs = [];
$guideBlocks = [];
$availByDay = [];
$hasAvail = false;
if ($editId > 0 && !$showAdd) {
    $owned = $loadOwnGuide($editId);
    if (!$owned) {
        flash('error', 'That guide is not on your team.');
        redirect(APP_URL . '/business/guides.php');
    }
    $pu = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $pu->execute([(int) $owned['user_id']]);
    $profileUser = $pu->fetch() ?: null;
    $profile = $owned;
    if (!$profileUser) {
        flash('error', 'Guide account not found.');
        redirect(APP_URL . '/business/guides.php');
    }
    ensure_guide_documents_schema();
    $editDocs = guide_documents($editId);
    $availByDay = guide_weekly_availability_by_day($editId);
    $hasAvail = guide_has_weekly_availability($editId);
    try {
        $gb = db()->prepare('SELECT * FROM guide_calendar_blocks WHERE guide_id=? ORDER BY block_date');
        $gb->execute([$editId]);
        $guideBlocks = $gb->fetchAll() ?: [];
    } catch (Throwable $e) {
        $guideBlocks = [];
    }
    extract(guide_profile_form_context($profileUser, $profile), EXTR_OVERWRITE);
}

$guides = inhouse_guides_for_business($bizId);
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $guides = array_values(array_filter($guides, static function (array $row) use ($q): bool {
        $hay = strtolower($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['email'] . ' ' . $row['phone']);
        return str_contains($hay, strtolower($q));
    }));
}
$today = app_today();
$assigned = 0;
$available = 0;
$pending = 0;
$active = 0;
foreach ($guides as $i => $row) {
    $busy = inhouse_guide_busy_on((int) $row['guide_id'], $today);
    $guides[$i]['busy_today'] = $busy;
    if ((string) $row['status'] === 'pending') {
        $pending++;
    }
    if ((int) $row['inhouse_active'] === 1 && (string) $row['status'] === 'active') {
        $active++;
        if ($busy) {
            $assigned++;
        } else {
            $available++;
        }
    }
}

$loadLangTags = true;
$pageTitle = 'In-house Guides';
$pageHeading = $profileUser ? full_name($profileUser) : 'In-house Guides';
$pageSub = $profileUser ? 'Edit in-house guide' : (string) $biz['name'];
$activeNav = 'inhouse';
if (!$showAdd && !$profileUser) {
    $pageHeadAction = '<a class="btn btn-ct" href="' . e(APP_URL) . '/business/guides.php?add=1"><i class="bi bi-plus-lg"></i> Add in-house guide</a>';
}
$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php if (!$showAdd): ?>
      <div class="row g-2 mb-3">
        <?php foreach (['Total' => count($guides), 'Active' => $active, 'Available' => $available, 'Assigned today' => $assigned, 'Pending invitations' => $pending] as $label => $n): ?>
          <div class="col-6 col-md">
            <div class="glass p-3">
              <div class="small text-muted"><?= e($label) ?></div>
              <div class="h4 mb-0"><?= (int) $n ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($showAdd): ?>
      <div class="glass p-3 p-md-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0">Add in-house guide</h2>
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/business/guides.php">Cancel</a>
        </div>
        <?php $inhouseGuideAddressFree = true; ?>
        <form method="post" enctype="multipart/form-data" id="adminAddGuideForm"><?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input type="hidden" id="roleSelect" value="<?= (int) ROLE_TOUR_GUIDE ?>">
          <?php require __DIR__ . '/../includes/admin_guide_create_form.php'; ?>
          <div class="mt-4">
            <button class="btn btn-ct" type="submit">Add in-house guide</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
      <?php if ($profileUser): ?>
      <div class="mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/business/guides.php">&larr; Back to in-house guides</a>
      </div>
      <?php
        $guideProfileShowStatus = true;
        $guideProfileRequirePhoto = false;
        $guideProfileShowBlocks = true;
        $guideProfileAvailNote = 'Same-day bookings close 2 hours before end.';
        $inhouseGuideAddressFree = true;
        require __DIR__ . '/../includes/guide_profile_form.php';
      ?>
      <?php elseif (!$showAdd): ?>
      <div class="guide-sections">
        <div class="glass guide-section is-active">
          <div class="guide-section__head">
            <div class="guide-section__titles">
              <h3 class="panel-title mb-0">Active in-house guides</h3>
              <p class="small text-muted mb-0">Guides employed by <?= e((string) $biz['name']) ?></p>
            </div>
            <span class="badge text-bg-success guide-section__count"><?= count($guides) ?></span>
          </div>
          <div class="guide-section__list table-responsive">
            <table class="table table-ct align-middle mb-0 guide-table">
              <thead>
                <tr>
                  <th>Guide</th>
                  <th>Languages</th>
                  <th>Status</th>
                  <th>Joined</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$guides): ?>
                  <tr><td colspan="5" class="text-muted">No in-house guides yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($guides as $row): ?>
                  <?php
                    $name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
                    $avatar = trim((string) ($row['avatar'] ?? ''));
                    $avatarUrl = $avatar !== '' ? (APP_URL . '/uploads/' . $avatar) : '';
                    $statusRow = $row;
                    $statusRow['role_id'] = ROLE_TOUR_GUIDE;
                    $statusRow['status'] = (int) $row['inhouse_active'] !== 1 ? 'suspended' : (string) ($row['status'] ?? 'active');
                    $statusRow['guide_approval_status'] = (string) ($row['approval_status'] ?? '');
                    $statusRow['guide_id'] = (int) $row['guide_id'];
                  ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2 min-w-0">
                        <?php if ($avatarUrl !== ''): ?>
                          <img class="admin-guide-thumb" src="<?= e($avatarUrl) ?>" alt="">
                        <?php else: ?>
                          <span class="admin-guide-thumb admin-guide-thumb--empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($row['first_name'] ?? 'G'), 0, 1))) ?></span>
                        <?php endif; ?>
                        <div class="min-w-0">
                          <div class="fw-semibold text-truncate"><?= e($name) ?></div>
                          <div class="small text-muted text-truncate"><?= e((string) $row['email']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= e(trim((string) ($row['languages'] ?? '')) !== '' ? (string) $row['languages'] : '—') ?></td>
                    <td><?= admin_user_status_badge($statusRow) ?></td>
                    <td><?= e(format_date((string) ($row['created_at'] ?? ''))) ?></td>
                    <td class="text-nowrap">
                      <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/business/guides.php?edit=<?= (int) $row['guide_id'] ?>">Edit</a>
                      <form method="post" class="d-inline" onsubmit="return confirm('<?= (int) $row['inhouse_active'] === 1 ? 'Deactivate this guide? Past assignments stay on record.' : 'Activate this guide?' ?>');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= (int) $row['inhouse_active'] === 1 ? 'deactivate' : 'activate' ?>">
                        <input type="hidden" name="guide_id" value="<?= (int) $row['guide_id'] ?>">
                        <button class="btn btn-sm <?= (int) $row['inhouse_active'] === 1 ? 'btn-warning' : 'btn-outline-secondary' ?>" type="submit"><?= (int) $row['inhouse_active'] === 1 ? 'Suspend' : 'Activate' ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>
<?php
$createdGuide = null;
if ((string) ($_GET['created'] ?? '') === '1' && !empty($_SESSION['admin_created_guide']) && is_array($_SESSION['admin_created_guide'])) {
    $createdGuide = $_SESSION['admin_created_guide'];
    unset($_SESSION['admin_created_guide']);
}
if ($createdGuide):
    $cgName = trim((string) ($createdGuide['name'] ?? ''));
    $cgEmail = trim((string) ($createdGuide['email'] ?? ''));
    $cgPhone = trim((string) ($createdGuide['phone'] ?? ''));
    $cgLang = trim((string) ($createdGuide['languages'] ?? ''));
    $cgRate = (int) ($createdGuide['hourly_rate'] ?? 0);
    $cgLicense = trim((string) ($createdGuide['license'] ?? ''));
    $cgPass = (string) ($createdGuide['password'] ?? '');
?>
  <div id="adminCreatedGuideOverlay" class="admin-biz-proof-overlay admin-created-cred-overlay" hidden>
    <div class="admin-biz-proof-card admin-created-cred-card admin-created-cred-print-sheet">
      <div class="admin-biz-proof-card__head">
        <div>
          <div class="small text-muted"><?= e(APP_NAME) ?></div>
          <h2 class="h6 mb-0">Tour guide account</h2>
        </div>
        <button type="button" class="admin-biz-proof-card__close admin-created-cred-actions" data-close-created-guide aria-label="Close">&times;</button>
      </div>
      <div class="admin-biz-proof-card__body admin-created-cred-body">
        <p class="small text-muted mb-3">Give this slip to the guide. They must change the password on first login.</p>
        <dl class="admin-created-cred-dl">
          <dt>Name</dt>
          <dd><?= e($cgName !== '' ? $cgName : '—') ?></dd>
          <dt>Email</dt>
          <dd><?= e($cgEmail !== '' ? $cgEmail : '—') ?></dd>
          <dt>Phone</dt>
          <dd><?= e($cgPhone !== '' ? $cgPhone : '—') ?></dd>
          <?php if ($cgLang !== ''): ?>
            <dt>Languages</dt>
            <dd><?= e($cgLang) ?></dd>
          <?php endif; ?>
          <?php if ($cgRate > 0): ?>
            <dt>Daily rate</dt>
            <dd>₱<?= e(number_format($cgRate)) ?></dd>
          <?php endif; ?>
          <?php if ($cgLicense !== ''): ?>
            <dt>License no.</dt>
            <dd><?= e($cgLicense) ?></dd>
          <?php endif; ?>
        </dl>
        <div class="admin-created-cred-passbox">
          <div class="admin-created-cred-passbox__label">Temporary password</div>
          <div class="admin-created-cred-passbox__value"><?= e($cgPass) ?></div>
        </div>
        <div class="admin-created-cred-actions d-flex flex-wrap gap-2 mt-3">
          <button type="button" class="btn btn-ct" data-print-created-guide>
            <i class="bi bi-printer"></i> Print
          </button>
          <button type="button" class="btn btn-outline-secondary" data-close-created-guide>Done</button>
        </div>
      </div>
    </div>
  </div>
<?php
    $extraScripts = ($extraScripts ?? '') . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('adminCreatedGuideOverlay');
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
    if (e.target === overlay || e.target.closest('[data-close-created-guide]')) closeCreated();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeCreated();
  });
  var printBtn = overlay.querySelector('[data-print-created-guide]');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
});
</script>
HTML;
endif;
require __DIR__ . '/../includes/business_shell_end.php';
if ($showAdd) {
    require __DIR__ . '/../includes/guide_leyte_same_modal.php';
    $appUrl = APP_URL;
    $phJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time());
    $addJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/admin-add-guide.js') ?: time());
    $extraScripts = ($extraScripts ?? '') . <<<HTML
<script src="{$appUrl}/assets/js/ph-address.js?v={$phJsVer}"></script>
<script>
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('{$appUrl}/assets/data/ph-address.json');
  }
</script>
<script src="{$appUrl}/assets/js/admin-add-guide.js?v={$addJsVer}"></script>
<script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify@4.31.6/dist/tagify.min.js"></script>
<script src="{$appUrl}/assets/js/lang-tags.js?v={$phJsVer}"></script>
<script>
  if (window.CulTourLangTags) CulTourLangTags.boot();
</script>
HTML;
}
require __DIR__ . '/../includes/footer.php';
