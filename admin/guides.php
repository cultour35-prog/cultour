<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_registrations.php';
$user = require_login(ROLE_ADMIN);
ensure_password_schema();
ensure_guide_documents_schema();
apply_office_daily_rate_to_unset_guides();
$pageTitle = 'Tour Guides';
$pageHeading = 'Tour Guides';
$pageSub = 'Add guides, review applications, and manage credentials';
$activeNav = 'guides';
$liveRegistrations = 'guides';

$edit = null;
$editDocs = [];
$isEditingGuide = false;
$viewId = (int) ($_GET['view'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$detailId = $editId > 0 ? $editId : $viewId;
$dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$profileUser = null;
$guideBlocks = [];
$availByDay = [];
$hasAvail = false;
if ($detailId > 0) {
    ensure_user_identity_schema();
    $s = db()->prepare(
        'SELECT g.*, u.first_name, u.last_name, u.middle_name, u.sex, u.date_of_birth, u.nationality,
                u.address AS user_address, u.email, u.phone, u.avatar, u.status AS user_status,
                (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.guide_id = g.id) AS review_count
         FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id WHERE g.id=?'
    );
    $s->execute([$detailId]);
    $edit = $s->fetch() ?: null;
    if (!$edit) {
        flash('error', 'Guide not found.');
        redirect(APP_URL . '/admin/guides.php');
    }
    $isEditingGuide = $editId > 0;
    $editDocs = guide_documents((int) $edit['id']);
    $pageTitle = full_name($edit);
    $pageHeading = $pageTitle !== '' ? $pageTitle : 'Tour guide';
    $pageSub = $isEditingGuide ? 'Edit tour guide profile' : 'Tour guide profile';
    $liveRegistrations = '';
    $pu = db()->prepare('SELECT * FROM users WHERE id=?');
    $pu->execute([(int) $edit['user_id']]);
    $profileUser = $pu->fetch() ?: null;
    $pr = db()->prepare('SELECT * FROM tour_guide_profiles WHERE id=?');
    $pr->execute([(int) $edit['id']]);
    $profile = $pr->fetch() ?: [];
    $availByDay = guide_weekly_availability_by_day((int) $edit['id']);
    $hasAvail = guide_has_weekly_availability((int) $edit['id']);
    $gb = db()->prepare('SELECT * FROM guide_calendar_blocks WHERE guide_id=? ORDER BY block_date');
    $gb->execute([(int) $edit['id']]);
    $guideBlocks = $gb->fetchAll() ?: [];
    if ($isEditingGuide && $profileUser && $profile) {
        extract(guide_profile_form_context($profileUser, $profile), EXTR_OVERWRITE);
    }
}

$forceShowAdd = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'suspend') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $reason = trim((string) ($_POST['suspend_reason'] ?? ''));
        $result = admin_try_suspend_role_user($id, (int) $user['id'], ROLE_TOUR_GUIDE, $reason);
        if ($result['ok']) {
            log_activity((int) $user['id'], 'suspend_user', 'guides', 'Tour guide user #' . $id . ': ' . $reason);
            flash('success', 'Tour guide suspended.');
            redirect(APP_URL . '/admin/guides.php?tab=suspended');
        }
        flash('error', $result['message']);
        redirect(APP_URL . '/admin/guides.php');
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
            'current_address_region' => ph_guide_locked_province(),
            'current_address_city' => $_POST['current_address_city'] ?? '',
            'current_address_barangay' => $_POST['current_address_barangay'] ?? '',
            'current_address_street' => $_POST['current_address_street'] ?? '',
            'require_current_province' => ph_guide_locked_province(),
            'emergency_first_name' => $_POST['emergency_first_name'] ?? '',
            'emergency_middle_name' => $_POST['emergency_middle_name'] ?? '',
            'emergency_last_name' => $_POST['emergency_last_name'] ?? '',
            'emergency_suffix' => resolve_posted_name_suffix('emergency_suffix', $_POST),
            'emergency_relationship' => resolve_posted_emergency_relationship($_POST),
            'emergency_phone_local' => $_POST['emergency_phone_local'] ?? '',
            'languages' => $_POST['languages'] ?? '',
            'years_experience' => $_POST['years_experience'] ?? '',
            'credential_files' => $_FILES['resume_files'] ?? [],
        ]);
        if (!$result['ok']) {
            flash('error', (string) ($result['message'] ?? 'Could not add this tour guide.'));
            $forceShowAdd = true;
        } else {
            log_activity((int) $user['id'], 'create_guide', 'guides', 'Guide user #' . (int) ($result['user_id'] ?? 0));
            $_SESSION['admin_created_guide'] = [
                'name' => trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')),
                'email' => (string) ($result['email'] ?? ''),
                'phone' => (string) ($result['phone'] ?? ''),
                'languages' => (string) ($result['languages'] ?? ''),
                'password' => $plainPassword,
                'login_url' => APP_URL . '/login.php',
            ];
            flash('success', 'Tour guide added and approved. Print the login card for them.');
            redirect(APP_URL . '/admin/guides.php?created=1');
        }
    }

    if ($action === 'set_rate') {
        $rate = (int) ($_POST['hourly_rate'] ?? 0);
        if ($rate < 1) {
            flash('error', 'Enter a daily rate greater than 0.');
        } else {
            db()->prepare('UPDATE tour_guide_profiles SET hourly_rate=?')->execute([$rate]);
            log_activity((int) $user['id'], 'set_guide_rate', 'guides', 'Office daily rate set to ' . $rate);
            flash('success', 'Daily rate updated for all tour guides.');
        }
        redirect(APP_URL . '/admin/guides.php');
    }

    if ($action === 'delete_document' || $action === 'upload_documents' || $action === 'update') {
        $gid = (int) ($_POST['guide_id'] ?? 0);
        $pr = db()->prepare('SELECT * FROM tour_guide_profiles WHERE id=?');
        $pr->execute([$gid]);
        $profileRow = $pr->fetch();
        $profileUserRow = null;
        if ($profileRow) {
            $pu = db()->prepare('SELECT * FROM users WHERE id=?');
            $pu->execute([(int) $profileRow['user_id']]);
            $profileUserRow = $pu->fetch() ?: null;
        }
        if (!$profileRow || !$profileUserRow) {
            flash('error', 'Guide not found.');
            redirect(APP_URL . '/admin/guides.php');
        }
        if ($action === 'update') {
            log_activity((int) $user['id'], 'update_guide', 'guides', 'Guide #' . $gid);
        }
        guide_profile_apply_post(
            $profileUserRow,
            $profileRow,
            $dayLabels,
            APP_URL . '/admin/guides.php?edit=' . $gid,
            false
        );
    }

    if ($action === 'block' || $action === 'unblock') {
        $gid = (int) ($_POST['guide_id'] ?? 0);
        $pr = db()->prepare('SELECT id FROM tour_guide_profiles WHERE id=?');
        $pr->execute([$gid]);
        if (!$pr->fetch()) {
            flash('error', 'Guide not found.');
            redirect(APP_URL . '/admin/guides.php');
        }
        if ($action === 'block') {
            $blockSave = guide_save_posted_block_date($gid);
            if (!empty($blockSave['error'])) {
                flash('error', (string) $blockSave['error']);
            } elseif (!empty($blockSave['saved'])) {
                flash('success', 'Date blocked.');
            }
        } else {
            db()->prepare('DELETE FROM guide_calendar_blocks WHERE id=? AND guide_id=?')
                ->execute([(int) ($_POST['id'] ?? 0), $gid]);
            flash('success', 'Date unblocked.');
        }
        redirect(APP_URL . '/admin/guides.php?edit=' . $gid);
    }

    $gid = (int) ($_POST['guide_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM tour_guide_profiles WHERE id=?');
    $stmt->execute([$gid]);
    $g = $stmt->fetch();
    if ($g) {
        if ($action === 'approve') {
            db()->prepare("UPDATE tour_guide_profiles SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$user['id'], $gid]);
            db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$g['user_id']]);
            notify_user((int) $g['user_id'], 'approval', 'Guide application approved', 'Complete your guide profile to start accepting bookings.', APP_URL . '/tourguide/profile.php');
            flash('success', 'Guide application updated.');
        } elseif ($action === 'reject') {
            db()->prepare("UPDATE tour_guide_profiles SET approval_status='rejected', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$user['id'], $gid]);
            db()->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$g['user_id']]);
            notify_user((int) $g['user_id'], 'approval', 'Guide profile rejected', 'Please contact the Tourism Office.', APP_URL . '/tourguide/profile.php');
            flash('success', 'Guide application updated.');
        }
    }
    if (empty($forceShowAdd)) {
    redirect(APP_URL . '/admin/guides.php');
}
}

$showAdd = !$edit && (isset($_GET['add']) || !empty($forceShowAdd));

$createdGuide = null;
if ((string) ($_GET['created'] ?? '') === '1' && !empty($_SESSION['admin_created_guide']) && is_array($_SESSION['admin_created_guide'])) {
    $createdGuide = $_SESSION['admin_created_guide'];
} else {
    unset($_SESSION['admin_created_guide']);
}

$rows = $edit ? [] : admin_guides_fetch_rows();
$docsByGuide = [];
$split = $edit ? ['pending' => [], 'registered' => [], 'rejected' => [], 'suspended' => []] : admin_guides_split($rows);
$pendingGuides = $split['pending'];
$registeredGuides = $split['registered'];
$rejectedGuides = $split['rejected'];
$suspendedGuides = $split['suspended'];
$guidesLiveVersion = $edit ? '' : admin_guides_version($rows);

$guideSections = [
    [
        'id' => 'registered',
        'title' => 'Active tour guides',
        'sub' => 'Approved guides on CulTour',
        'badge' => 'text-bg-success',
        'btn' => 'Active tour guides',
        'rows' => $registeredGuides,
        'approve' => false,
        'empty' => 'No active tour guides yet. Click Add tour guide to create one.',
    ],
    [
        'id' => 'pending',
        'title' => 'Pending applications',
        'sub' => 'Guides waiting for Tourism Office approval',
        'badge' => 'text-bg-secondary',
        'btn' => 'Pending applications',
        'rows' => $pendingGuides,
        'approve' => true,
        'empty' => 'No pending applications.',
    ],
    [
        'id' => 'suspended',
        'title' => 'Suspended',
        'sub' => 'Guides who cannot accept bookings until restored',
        'badge' => 'text-bg-warning',
        'btn' => 'Suspended',
        'rows' => $suspendedGuides,
        'approve' => false,
        'empty' => 'No suspended tour guides.',
    ],
    [
        'id' => 'rejected',
        'title' => 'Rejected',
        'sub' => 'Applications that were not approved',
        'badge' => 'text-bg-danger',
        'btn' => 'Rejected',
        'rows' => $rejectedGuides,
        'approve' => false,
        'empty' => 'No rejected applications.',
    ],
];
$defaultGuideTab = 'registered';
if (!empty($_GET['tab']) && in_array($_GET['tab'], ['registered', 'pending', 'rejected', 'suspended'], true)) {
    $defaultGuideTab = $_GET['tab'];
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

      <?php if ($edit && $isEditingGuide && $profileUser): ?>
        <div class="mb-3 d-flex flex-wrap gap-2 admin-profile-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/guides.php">&larr; Back to tour guides</a>
          <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/guides.php?view=<?= (int) $edit['id'] ?>">View profile</a>
        </div>
        <?php
          $guideProfileShowStatus = true;
          $guideProfileRequirePhoto = false;
          $guideProfileShowBlocks = true;
          $guideProfileAvailNote = 'Same-day bookings close 2 hours before end.';
          require __DIR__ . '/../includes/guide_profile_form.php';
        ?>
      <?php elseif ($edit): ?>
        <?php
          $viewName = full_name($edit);
          $viewAvatar = trim((string) ($edit['avatar'] ?? ''));
          $viewAvatarUrl = $viewAvatar !== '' ? (APP_URL . '/uploads/' . ltrim($viewAvatar, '/')) : '';
          $viewInitial = mb_strtoupper(mb_substr($edit['first_name'] ?: ($viewName ?: 'G'), 0, 1));
        ?>
        <div class="mb-3 d-flex flex-wrap gap-2 admin-profile-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/guides.php">&larr; Back to tour guides</a>
          <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/guides.php?edit=<?= (int) $edit['id'] ?>">Edit details</a>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#activityLogModal">Activity logs</button>
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
              <div class="small text-muted mb-1">Tour guide · <?php
                if (($edit['approval_status'] ?? '') === 'approved') {
                    $accountStatus = (string) ($edit['user_status'] ?? 'active');
                    echo status_badge($accountStatus === 'suspended' ? 'suspended' : 'active');
                } else {
                    echo status_badge((string) $edit['approval_status']);
                }
              ?></div>
              <h2 class="h4 mb-1"><?= e($viewName !== '' ? $viewName : 'Unnamed guide') ?></h2>
              <div class="text-muted"><?= e($edit['email']) ?><?= !empty($edit['phone']) ? ' · ' . e($edit['phone']) : '' ?></div>
              <div class="admin-guide-profile__rating">
                <?= rating_stars_html($edit['avg_rating'] ?? 0, (int) ($edit['review_count'] ?? 0), 'lg') ?>
              </div>
            </div>
          </div>
        </div>

        <div class="glass p-3 p-md-4 fade-up">
          <h3 class="panel-title mb-3">Profile details</h3>
          <dl class="admin-guide-profile__dl mb-0">
                <div class="admin-guide-profile__col">
                  <div>
                    <dt>First name</dt>
                    <dd><?= e((string) ($profileUser['first_name'] ?? $edit['first_name'] ?? '—') ?: '—') ?></dd>
                  </div>
                  <div>
                    <dt>Middle name</dt>
                    <dd><?= e(trim((string) ($profileUser['middle_name'] ?? $edit['middle_name'] ?? '')) !== '' ? (string) ($profileUser['middle_name'] ?? $edit['middle_name']) : '—') ?></dd>
                  </div>
                  <div>
                    <dt>Last name</dt>
                    <dd><?= e((string) ($profileUser['last_name'] ?? $edit['last_name'] ?? '—') ?: '—') ?></dd>
                  </div>
                  <div>
                    <dt>Suffix</dt>
                    <dd><?= e(trim((string) ($profileUser['name_suffix'] ?? '')) !== '' ? (string) $profileUser['name_suffix'] : '—') ?></dd>
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
                    <dt>Years of experience</dt>
                    <dd><?= (int) ($edit['years_experience'] ?? 0) ?></dd>
                  </div>
                  <div>
                    <dt>Average rating</dt>
                    <dd><?= rating_stars_html($edit['avg_rating'] ?? 0, (int) ($edit['review_count'] ?? 0), 'sm') ?></dd>
                  </div>
                  <div>
                    <dt>License no.</dt>
                    <dd><?= e($edit['license_number'] ?: '—') ?></dd>
                  </div>
                  <div>
                    <dt>Credentials / documents</dt>
                    <dd>
                      <?php if ($editDocs): ?>
                        <button type="button" class="btn btn-sm btn-ct" data-open-guide-uploads>
                          View uploads
                        </button>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </dd>
                  </div>
                </div>
                <div class="admin-guide-profile__col">
                  <div>
                    <dt>Phone</dt>
                    <dd><?= e(!empty($edit['phone']) ? (string) $edit['phone'] : '—') ?></dd>
                  </div>
                  <div>
                    <dt>Permanent address</dt>
                    <dd><?php
                      $permParts = array_filter([
                          trim((string) ($profileUser['address_street'] ?? '')),
                          trim((string) ($profileUser['address_barangay'] ?? '')),
                          trim((string) ($profileUser['address_city'] ?? '')),
                          trim((string) ($profileUser['address_region'] ?? '')),
                      ], static fn ($p) => $p !== '');
                      $perm = $permParts ? implode(', ', $permParts) : trim((string) ($edit['user_address'] ?? ''));
                      echo e($perm !== '' ? $perm : '—');
                    ?></dd>
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
                  <div>
                    <dt>Current address</dt>
                    <dd><?php
                      $curParts = array_filter([
                          trim((string) ($profileUser['current_address_street'] ?? '')),
                          trim((string) ($profileUser['current_address_barangay'] ?? '')),
                          trim((string) ($profileUser['current_address_city'] ?? '')),
                          trim((string) ($profileUser['current_address_region'] ?? ph_guide_locked_province())),
                      ], static fn ($p) => $p !== '');
                      echo e($curParts ? implode(', ', $curParts) : '—');
                    ?></dd>
                  </div>
                  <div>
                    <dt>Languages</dt>
                    <dd><?= e($edit['languages'] ?: '—') ?></dd>
                  </div>
                  <div>
                    <dt>Daily rate</dt>
                    <dd><?= e(format_money($edit['hourly_rate'])) ?>/day</dd>
                  </div>
                  <div>
                    <dt>Bio</dt>
                    <dd><?= ($edit['bio'] !== null && $edit['bio'] !== '') ? nl2br(e($edit['bio'])) : '—' ?></dd>
                  </div>
                </div>
              </dl>
        </div>
        <div class="glass p-3 p-md-4 mt-3 fade-up guide-profile-avail">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div>
              <h3 class="h6 mb-1">Availability</h3>
              <div class="small text-muted">Weekly hours</div>
            </div>
            <?php if (!empty($hasAvail)): ?>
              <span class="badge bg-success">Set</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Not set</span>
            <?php endif; ?>
          </div>
          <div class="admin-guide-hours admin-guide-hours--week mb-3">
                <?php for ($i = 0; $i < 7; $i++): ?>
                  <?php
                    $dayOn = !empty($availByDay[$i]['on']);
                    $start = (string) ($availByDay[$i]['start'] ?? '');
                    $end = (string) ($availByDay[$i]['end'] ?? '');
                  ?>
                  <div class="admin-guide-hours__row<?= $dayOn ? ' is-on' : '' ?>">
                    <span class="admin-guide-hours__day"><?= e($dayLabels[$i]) ?></span>
                    <span class="admin-guide-hours__time">
                      <?php if ($dayOn): ?>
                        <?= e($start) ?> – <?= e($end) ?>
                      <?php else: ?>
                        Off
                      <?php endif; ?>
                    </span>
                  </div>
                <?php endfor; ?>
              </div>
              <hr class="my-3 opacity-50">
              <h3 class="h6 mb-2">Block dates</h3>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-open-guide-blocks>
                View block dates
              </button>
            </div>
        <?php
          $activityLogs = user_activity_logs((int) ($edit['user_id'] ?? 0));
          require __DIR__ . '/../includes/admin_subject_activity_logs.php';
        ?>
        <div id="guideBlocksCard" class="admin-guide-uploads-overlay" hidden>
          <div class="admin-guide-uploads-card" role="dialog" aria-labelledby="guideBlocksCardTitle" aria-modal="true">
            <div class="admin-guide-uploads-card__head">
              <h2 class="h6 mb-0" id="guideBlocksCardTitle">Blocked dates</h2>
              <button type="button" class="admin-guide-uploads-card__close" data-close-guide-blocks aria-label="Close">&times;</button>
            </div>
            <div class="admin-guide-uploads-card__body">
              <?php if (empty($guideBlocks)): ?>
                <p class="small text-muted mb-0">No blocked dates yet.</p>
              <?php else: ?>
                <ul class="admin-guide-uploads-card__list">
                  <?php foreach ($guideBlocks as $b): ?>
                    <li>
                      <?= e(format_date($b['block_date'])) ?><?= trim((string) ($b['reason'] ?? '')) !== '' ? ' · ' . e($b['reason']) : '' ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
$extraScripts = ($extraScripts ?? '') . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const overlay = document.getElementById('guideBlocksCard');
  if (!overlay) return;
  document.body.appendChild(overlay);
  function openCard() {
    overlay.hidden = false;
    document.body.classList.add('admin-guide-uploads-open');
  }
  function closeCard() {
    overlay.hidden = true;
    document.body.classList.remove('admin-guide-uploads-open');
  }
  document.querySelectorAll('[data-open-guide-blocks]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      openCard();
    });
  });
  overlay.querySelectorAll('[data-close-guide-blocks]').forEach(function (btn) {
    btn.addEventListener('click', closeCard);
  });
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeCard();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeCard();
  });
});
</script>
HTML;
        ?>
        <?php if ($editDocs): ?>
        <div id="guideUploadsCard" class="admin-guide-uploads-overlay" hidden>
          <div class="admin-guide-uploads-card" role="dialog" aria-labelledby="guideUploadsCardTitle" aria-modal="true">
            <div class="admin-guide-uploads-card__head">
              <h2 class="h6 mb-0" id="guideUploadsCardTitle">Uploaded credentials</h2>
              <button type="button" class="admin-guide-uploads-card__close" data-close-guide-uploads aria-label="Close">&times;</button>
            </div>
            <div class="admin-guide-uploads-card__body">
              <ul class="admin-guide-uploads-card__list">
                <?php foreach ($editDocs as $d): ?>
                  <li>
                    <a href="<?= APP_URL ?>/uploads/<?= e($d['file_path']) ?>" target="_blank" rel="noopener">
                      <i class="bi bi-file-earmark-person"></i>
                      <?= e($d['original_name'] ?: basename((string) $d['file_path'])) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>
        <?php
$extraScripts = ($extraScripts ?? '') . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const overlay = document.getElementById('guideUploadsCard');
  if (!overlay) return;
  document.body.appendChild(overlay);
  function openCard() {
    overlay.hidden = false;
    document.body.classList.add('admin-guide-uploads-open');
  }
  function closeCard() {
    overlay.hidden = true;
    document.body.classList.remove('admin-guide-uploads-open');
  }
  document.querySelectorAll('[data-open-guide-uploads]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      openCard();
    });
  });
  overlay.querySelectorAll('[data-close-guide-uploads]').forEach(function (btn) {
    btn.addEventListener('click', closeCard);
  });
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeCard();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeCard();
  });
});
</script>
HTML;
        ?>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!$edit): ?>
      <div class="glass p-3 mb-4 fade-up">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h3 class="panel-title mb-1"><?= $showAdd ? 'Add tour guide' : 'Tour guides' ?></h3>
            <?php if (!$showAdd): ?>
            <p class="small text-muted mb-0">Choose a list to view active, pending, suspended, or rejected guides</p>
            <?php endif; ?>
          </div>
          <?php if (!$showAdd): ?>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editDailyRateModal">Edit daily rate</button>
              <a class="btn btn-ct" href="<?= APP_URL ?>/admin/guides.php?add=1">
                <i class="bi bi-plus-lg"></i> Add tour guide
              </a>
            </div>
          <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/admin/guides.php">Cancel</a>
          <?php endif; ?>
        </div>
        <?php if (!$showAdd): ?>
        <div class="guide-tabs" role="tablist" aria-label="Tour guide lists" data-live-regs="guides" data-live-version="<?= e($guidesLiveVersion) ?>">
          <?php foreach ($guideSections as $section): ?>
            <button
              type="button"
              class="guide-tabs__btn<?= $defaultGuideTab === $section['id'] ? ' is-active' : '' ?>"
              data-guide-tab="<?= e($section['id']) ?>"
              role="tab"
              aria-selected="<?= $defaultGuideTab === $section['id'] ? 'true' : 'false' ?>"
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
          <h3 class="panel-title mb-0">Add tour guide</h3>
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/guides.php">Cancel</a>
        </div>
        <p class="form-text text-danger mb-3"><strong>Note:</strong> Only tour guides who currently live in Ormoc City can be added.</p>
        <form method="post" enctype="multipart/form-data" id="adminAddGuideForm"><?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input type="hidden" id="roleSelect" value="<?= (int) ROLE_TOUR_GUIDE ?>">
          <?php require __DIR__ . '/../includes/admin_guide_create_form.php'; ?>
          <div class="mt-4">
            <button class="btn btn-ct" type="submit">Add tour guide</button>
          </div>
                      </form>
      </div>
                    <?php endif; ?>

      <?php if (!$edit && !$showAdd): ?>
      <div class="guide-sections">
        <?php foreach ($guideSections as $section): ?>
          <div
            class="glass guide-section<?= $defaultGuideTab === $section['id'] ? ' is-active' : '' ?>"
            data-guide-panel="<?= e($section['id']) ?>"
            role="tabpanel"
            <?= $defaultGuideTab === $section['id'] ? '' : 'hidden' ?>
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
                    <th>Guide</th>
                    <th>Languages</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody data-live-tbody="<?= e($section['id']) ?>" data-live-empty="<?= e($section['empty']) ?>" data-live-cols="5">
                  <?= admin_render_guide_rows($section['rows'], $docsByGuide, $section['approve']) ?>
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

<?php if (!empty($createdGuide) && is_array($createdGuide)): ?>
  <?php
    $cgName = trim((string) ($createdGuide['name'] ?? ''));
    $cgEmail = trim((string) ($createdGuide['email'] ?? ''));
    $cgPhone = trim((string) ($createdGuide['phone'] ?? ''));
    $cgLang = trim((string) ($createdGuide['languages'] ?? ''));
    $cgRate = (int) ($createdGuide['hourly_rate'] ?? 0);
    $cgLicense = trim((string) ($createdGuide['license'] ?? ''));
    $cgPass = (string) ($createdGuide['password'] ?? '');
  ?>
  <div id="adminCreatedGuideOverlay" class="admin-biz-proof-overlay admin-created-cred-overlay">
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
  if (printBtn) {
    printBtn.addEventListener('click', function () { window.print(); });
  }
});
</script>
HTML;
endif;
?>
<?php if (!empty($showAdd)): ?>
  <?php require __DIR__ . '/../includes/guide_leyte_same_modal.php'; ?>
<?php
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
HTML;
endif;
?>
<?php
if ($edit && $isEditingGuide) {
    render_unsaved_changes_modal();
}
?>
<?php $officeDailyRate = guide_office_daily_rate(); ?>
<div class="modal fade" id="editDailyRateModal" tabindex="-1" aria-labelledby="editDailyRateLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_rate">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title fs-5" id="editDailyRateLabel">Edit daily rate</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">This is the Tourism Office tariff. It applies to every tour guide.</p>
        <label class="form-label" for="editDailyRateInput">Daily rate (₱)</label>
        <input type="number" class="form-control" name="hourly_rate" id="editDailyRateInput" min="1" step="1" required value="<?= $officeDailyRate > 0 ? (int) $officeDailyRate : '' ?>">
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-ct">Save rate</button>
      </div>
    </form>
  </div>
</div>
<?php
$suspendFormAction = APP_URL . '/admin/guides.php';
require __DIR__ . '/_suspend_modal.php';
admin_application_viewer_mount();
require __DIR__ . '/../includes/footer.php';
?>
