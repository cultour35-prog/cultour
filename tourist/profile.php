<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOURIST);
ensure_user_identity_schema();
$finishProfile = user_must_complete_profile($user);
$pageTitle = $finishProfile ? 'Finish your profile' : 'Profile';
$pageHeading = $finishProfile ? 'Finish your profile' : 'Edit Profile';
$pageSub = $finishProfile
    ? 'The Tourism Office created your account with basic details. Complete the rest before using CulTour.'
    : '';
$activeNav = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', csrf_error_message());
    } else {
    $avatar = $user['avatar'];
    if (!empty($_FILES['avatar']['name'])) {
        $up = upload_file($_FILES['avatar'], 'profiles', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if ($up) {
            $avatar = $up;
        } else {
            flash('error', upload_last_error() ?: 'Could not upload profile photo. Use JPG, PNG, GIF, or WebP under 8 MB.');
            redirect(APP_URL . '/tourist/profile.php');
        }
    }
    $_POST['use_structured_address'] = '1';
    $identity = user_parse_identity($_POST, true);
    if (!$identity['ok']) {
        flash('error', (string) ($identity['message'] ?? 'Please complete your personal details.'));
        redirect(APP_URL . '/tourist/profile.php');
    }
    $emerg = user_parse_emergency_contact($_POST, true);
    if (!$emerg['ok']) {
        flash('error', (string) ($emerg['message'] ?? 'Please complete your emergency contact.'));
        redirect(APP_URL . '/tourist/profile.php');
    }
    $first = capitalize_name((string) ($_POST['first_name'] ?? ''));
    $last = capitalize_name((string) ($_POST['last_name'] ?? ''));
    if ($first === '' || $last === '') {
        flash('error', 'First name and last name are required.');
        redirect(APP_URL . '/tourist/profile.php');
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
        flash('error', (string) ($diff['message'] ?? 'Emergency contact cannot match your details.'));
        redirect(APP_URL . '/tourist/profile.php');
    }
    $saved = user_save_identity(
        (int) $user['id'],
        $identity['data'],
        $first,
        $last,
        trim((string) ($_POST['phone'] ?? '')),
        $avatar
    );
    if (empty($saved['ok'])) {
        flash('error', (string) ($saved['message'] ?? 'Could not save profile details.'));
        redirect(APP_URL . '/tourist/profile.php');
    }
    user_save_emergency_contact((int) $user['id'], $emerg['data']);
    if ($finishProfile) {
        db()->prepare('UPDATE users SET must_complete_profile=0 WHERE id=?')->execute([(int) $user['id']]);
        flash('success', 'Profile saved. You can use CulTour now.');
        redirect(APP_URL . '/tourist/dashboard.php');
    }
    flash('success', 'Profile updated.');
    redirect(APP_URL . '/tourist/profile.php');
    }
}

$user = current_user();
$addrRegion = trim((string) ($user['address_region'] ?? ''));
$addrCity = trim((string) ($user['address_city'] ?? ''));
$addrBarangay = trim((string) ($user['address_barangay'] ?? ''));
$addrStreet = trim((string) ($user['address_street'] ?? ''));
$legacyAddress = trim((string) ($user['address'] ?? ''));
$hasStructured = $addrRegion !== '' && $addrCity !== '' && $addrBarangay !== '';
$curRegion = trim((string) ($user['current_address_region'] ?? ''));
$curCity = trim((string) ($user['current_address_city'] ?? ''));
$curBarangay = trim((string) ($user['current_address_barangay'] ?? ''));
$curStreet = trim((string) ($user['current_address_street'] ?? ''));
$sameAsPermanent = (int) ($user['current_same_as_permanent'] ?? 0) === 1;
if ($curRegion === '' && $curCity === '' && $curBarangay === '' && $curStreet === '') {
    $curRegion = $addrRegion;
    $curCity = $addrCity;
    $curBarangay = $addrBarangay;
    $curStreet = $addrStreet;
}
$emergParts = split_person_name((string) ($user['emergency_contact_name'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emergParts = [
        'first' => (string) ($_POST['emergency_first_name'] ?? ''),
        'middle' => (string) ($_POST['emergency_middle_name'] ?? ''),
        'last' => (string) ($_POST['emergency_last_name'] ?? ''),
    ];
}
$emergSuffix = resolve_posted_name_suffix('emergency_suffix', $_POST);
$emergRel = resolve_posted_emergency_relationship($_POST);
if ($emergRel === '') {
    $emergRel = (string) ($user['emergency_contact_relationship'] ?? '');
}
$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
$loadLangTags = true;
require __DIR__ . '/../includes/header.php';
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="guide-profile-layout">
      <form method="post" enctype="multipart/form-data" autocomplete="off" data-unsaved-guard>
        <?= csrf_field() ?>
        <input type="hidden" name="use_structured_address" value="1">

        <div class="glass p-3 p-md-4 guide-profile-card">
          <div class="guide-profile-section">
            <div class="fw-semibold mb-3">Profile</div>
            <div class="guide-profile-photo">
              <label class="form-label">Profile photo</label>
              <div class="avatar-preview" data-avatar-preview>
                <div class="avatar-preview__frame" data-avatar-frame>
                  <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= e((string) $user['avatar']) ?>" alt="" class="avatar-preview__img">
                  <?php else: ?>
                    No photo
                  <?php endif; ?>
                </div>
                <div class="avatar-preview__fields">
                  <input type="file" class="form-control" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                  <div class="form-text">JPG, PNG, GIF, or WebP — preview updates when you pick a file.</div>
                </div>
              </div>
            </div>
            <div class="guide-profile-fields guide-profile-fields--names">
              <div>
                <label class="form-label">First name</label>
                <input class="form-control name-cap" name="first_name" value="<?= e(capitalize_name((string) $user['first_name'])) ?>" required>
              </div>
              <div>
                <label class="form-label">Middle name</label>
                <input class="form-control name-cap" name="middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) ($user['middle_name'] ?? ''))) ?>">
              </div>
              <div>
                <label class="form-label">Last name</label>
                <input class="form-control name-cap" name="last_name" value="<?= e(capitalize_name((string) $user['last_name'])) ?>" required>
              </div>
              <div class="guide-profile-suffix">
                <label class="form-label">Suffix</label>
                <?= name_suffix_field_html('name_suffix', (string) ($user['name_suffix'] ?? '')) ?>
              </div>
            </div>
            <div class="guide-profile-fields guide-profile-fields--4">
              <div>
                <label class="form-label">Email</label>
                <input class="form-control" value="<?= e($user['email']) ?>" disabled>
              </div>
              <div>
                <label class="form-label">Phone</label>
                <input class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>">
              </div>
              <div>
                <label class="form-label">Gender</label>
                <div class="select-chevron">
                  <select class="form-select" name="sex" required>
                    <?php foreach (user_sex_options() as $val => $label): ?>
                      <option value="<?= e($val) ?>" <?= (string) ($user['sex'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                </div>
              </div>
              <div>
                <label class="form-label">Date of birth</label>
                <input type="date" class="form-control" name="date_of_birth" required
                       max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>"
                       value="<?= e((string) ($user['date_of_birth'] ?? '')) ?>">
              </div>
            </div>
            <div class="guide-profile-fields guide-profile-fields--lang">
              <div>
                <label class="form-label">Nationality</label>
                <input class="form-control" name="nationality" required value="<?= e((string) ($user['nationality'] ?? '')) ?>">
              </div>
              <div>
                <label class="form-label">Languages</label>
                <input class="form-control js-lang-tags" name="languages" required maxlength="255"
                       placeholder="Type a language, then press Enter"
                       value="<?= e((string) ($user['languages'] ?? '')) ?>">
              </div>
            </div>
          </div>

          <div data-same-as-permanent>
            <div class="guide-profile-section">
              <?php if (!$hasStructured && $legacyAddress !== ''): ?>
                <div class="alert alert-secondary small mb-3">Previously saved: <?= e($legacyAddress) ?>. Choose province, city, and barangay below to update.</div>
              <?php endif; ?>
              <div data-ph-address data-same-permanent>
                <div class="fw-semibold mb-3">Permanent address</div>
                <div class="guide-profile-fields guide-profile-fields--2">
                  <div>
                    <label class="form-label">Province</label>
                    <select class="form-select" name="address_region" data-ph-region required data-initial="<?= e($addrRegion) ?>">
                      <option value="" selected disabled>Select province</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">City / Municipality</label>
                    <select class="form-select" name="address_city" data-ph-city required disabled data-initial="<?= e($addrCity) ?>">
                      <option value="" selected disabled>Select city / municipality</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Barangay</label>
                    <select class="form-select" name="address_barangay" data-ph-barangay required disabled data-initial="<?= e($addrBarangay) ?>">
                      <option value="" selected disabled>Select barangay</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Street</label>
                    <input class="form-control" name="address_street" required maxlength="150"
                           placeholder="House no., street name" value="<?= e($addrStreet) ?>">
                  </div>
                </div>
              </div>
            </div>

            <div class="guide-profile-section" data-ph-address data-same-current>
              <?= current_address_same_radios_html($sameAsPermanent, 'touristProfileAddrSame') ?>
              <div class="guide-profile-fields guide-profile-fields--2">
                <div>
                  <label class="form-label">Province</label>
                  <select class="form-select" name="current_address_region" data-ph-region required data-initial="<?= e($curRegion) ?>">
                    <option value="" selected disabled>Select province</option>
                  </select>
                </div>
                <div>
                  <label class="form-label">City / Municipality</label>
                  <select class="form-select" name="current_address_city" data-ph-city required disabled data-initial="<?= e($curCity) ?>">
                    <option value="" selected disabled>Select city / municipality</option>
                  </select>
                </div>
                <div>
                  <label class="form-label">Barangay</label>
                  <select class="form-select" name="current_address_barangay" data-ph-barangay required disabled data-initial="<?= e($curBarangay) ?>">
                    <option value="" selected disabled>Select barangay</option>
                  </select>
                </div>
                <div>
                  <label class="form-label">Street</label>
                  <input class="form-control" name="current_address_street" required maxlength="150"
                         placeholder="House no., street name" value="<?= e($curStreet) ?>">
                </div>
              </div>
            </div>
          </div>

          <hr class="guide-profile-split__rule">

          <div class="guide-profile-section">
            <div class="fw-semibold mb-3">Emergency contact</div>
            <div class="guide-profile-fields guide-profile-fields--names">
              <div>
                <label class="form-label">First name</label>
                <input class="form-control name-cap" name="emergency_first_name" required maxlength="80" value="<?= e(capitalize_name((string) $emergParts['first'])) ?>">
              </div>
              <div>
                <label class="form-label">Middle name</label>
                <input class="form-control name-cap" name="emergency_middle_name" maxlength="80" placeholder="Optional" value="<?= e(capitalize_name((string) $emergParts['middle'])) ?>">
              </div>
              <div>
                <label class="form-label">Last name</label>
                <input class="form-control name-cap" name="emergency_last_name" required maxlength="80" value="<?= e(capitalize_name((string) $emergParts['last'])) ?>">
              </div>
              <div class="guide-profile-suffix">
                <label class="form-label">Suffix</label>
                <?= name_suffix_field_html('emergency_suffix', $emergSuffix) ?>
              </div>
            </div>
            <div class="guide-profile-fields guide-profile-fields--2">
              <div>
                <label class="form-label">Relationship</label>
                <?= emergency_relationship_field_html($emergRel) ?>
              </div>
              <div>
                <label class="form-label">Phone</label>
                <div class="phone-input">
                  <span class="phone-input__prefix" title="Philippines">
                    <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
                    <span>+63</span>
                  </span>
                  <input type="tel" class="form-control" name="emergency_phone_local" required
                         inputmode="numeric" maxlength="10" placeholder="9XXXXXXXXX"
                         value="<?= e(ph_phone_local_digits($user['emergency_contact_phone'] ?? '')) ?>">
                </div>
              </div>
            </div>
          </div>

          <div class="guide-profile-save">
            <button type="submit" class="btn btn-ct">Save</button>
          </div>
        </div>
      </form>
      </div>


<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<script src="<?= APP_URL ?>/assets/js/ph-address.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time() ?>"></script>
<script>
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('<?= APP_URL ?>/assets/data/ph-address.json');
  }
  document.querySelectorAll('.suffix-picker').forEach(function (picker) {
    if (picker.getAttribute('data-bound') === '1') return;
    picker.setAttribute('data-bound', '1');
    const toggle = picker.querySelector('.suffix-picker__toggle');
    const menu = picker.querySelector('.suffix-picker__menu');
    const choice = picker.querySelector('.suffix-picker__choice');
    const label = picker.querySelector('.suffix-picker__label');
    const specify = picker.querySelector('.suffix-picker__specify');
    const other = picker.querySelector('.suffix-picker__other');
    const emptyLabel = picker.getAttribute('data-empty-label') || 'None';
    const labels = {};
    picker.querySelectorAll('.suffix-picker__option').forEach(function (btn) {
      labels[btn.getAttribute('data-value') || ''] = (btn.textContent || '').trim();
    });
    labels.none = labels.none || 'None';
    labels.other = labels.other || 'Other';
    labels[''] = emptyLabel;
    function setOpen(open) {
      if (!menu || !toggle) return;
      menu.classList.toggle('d-none', !open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      picker.classList.toggle('is-open', open);
    }
    if (toggle) {
      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        setOpen(menu.classList.contains('d-none'));
      });
    }
    picker.querySelectorAll('.suffix-picker__option').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const value = btn.getAttribute('data-value') || '';
        choice.value = value;
        picker.querySelectorAll('.suffix-picker__option').forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
        });
        if (value === 'other') {
          if (specify) specify.classList.remove('d-none');
          label.textContent = (other && other.value.trim()) ? other.value.trim() : 'Other';
          setOpen(true);
          if (other) other.focus();
        } else {
          if (specify) specify.classList.add('d-none');
          if (other) other.value = '';
          label.textContent = labels[value] || emptyLabel;
          setOpen(false);
        }
      });
    });
    if (other) {
      other.addEventListener('click', function (e) { e.stopPropagation(); });
      other.addEventListener('input', function () {
        if (choice.value === 'other') label.textContent = other.value.trim() || 'Other';
      });
    }
  });
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.suffix-picker.is-open').forEach(function (picker) {
      if (!picker.contains(e.target)) {
        const menu = picker.querySelector('.suffix-picker__menu');
        const toggle = picker.querySelector('.suffix-picker__toggle');
        if (menu) menu.classList.add('d-none');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        picker.classList.remove('is-open');
      }
    });
  });
</script>
<?php
render_unsaved_changes_modal();
require __DIR__ . '/../includes/footer.php';
?>
