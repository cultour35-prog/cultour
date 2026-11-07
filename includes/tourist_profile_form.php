<?php
$tpUser = $profileUser ?? $user;
$photoRequired = !empty($touristProfileRequirePhoto);
$addrRegion = trim((string) ($tpUser['address_region'] ?? ''));
$addrCity = trim((string) ($tpUser['address_city'] ?? ''));
$addrBarangay = trim((string) ($tpUser['address_barangay'] ?? ''));
$addrStreet = trim((string) ($tpUser['address_street'] ?? ''));
$legacyAddress = trim((string) ($tpUser['address'] ?? ''));
$hasStructured = $addrRegion !== '' && $addrCity !== '' && $addrBarangay !== '';
$curRegion = trim((string) ($tpUser['current_address_region'] ?? ''));
$curCity = trim((string) ($tpUser['current_address_city'] ?? ''));
$curBarangay = trim((string) ($tpUser['current_address_barangay'] ?? ''));
$curStreet = trim((string) ($tpUser['current_address_street'] ?? ''));
$sameAsPermanent = (int) ($tpUser['current_same_as_permanent'] ?? 0) === 1;
if ($curRegion === '' && $curCity === '' && $curBarangay === '' && $curStreet === '') {
    $curRegion = $addrRegion;
    $curCity = $addrCity;
    $curBarangay = $addrBarangay;
    $curStreet = $addrStreet;
}
$emergParts = split_person_name((string) ($tpUser['emergency_contact_name'] ?? ''));
$emergSuffix = (string) ($emergParts['suffix'] ?? '');
$emergRel = (string) ($tpUser['emergency_contact_relationship'] ?? '');
?>
      <div class="guide-profile-layout">
        <form method="post" enctype="multipart/form-data" autocomplete="off" data-unsaved-guard><?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="user_id" value="<?= (int) $tpUser['id'] ?>">
          <input type="hidden" name="use_structured_address" value="1">

          <div class="glass p-3 p-md-4 guide-profile-card">
            <div class="guide-profile-section">
              <div class="fw-semibold mb-3">Profile</div>
              <div class="guide-profile-photo">
                <label class="form-label">Profile photo<?= $photoRequired ? ' <span class="text-danger">*</span>' : '' ?></label>
                <div class="avatar-preview" data-avatar-preview>
                  <div class="avatar-preview__frame" data-avatar-frame>
                    <?php if (!empty($tpUser['avatar'])): ?>
                      <img src="<?= APP_URL ?>/uploads/<?= e((string) $tpUser['avatar']) ?>" alt="" class="avatar-preview__img">
                    <?php else: ?>
                      No photo
                    <?php endif; ?>
                  </div>
                  <div class="avatar-preview__fields">
                    <input type="file" class="form-control" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"<?= $photoRequired ? ' required' : '' ?>>
                    <div class="form-text">JPG, PNG, GIF, or WebP — preview updates when you pick a file.</div>
                  </div>
                </div>
              </div>
              <div class="guide-profile-fields guide-profile-fields--names">
                <div>
                  <label class="form-label">First name</label>
                  <input class="form-control name-cap" name="first_name" value="<?= e(capitalize_name((string) ($tpUser['first_name'] ?? ''))) ?>" required>
                </div>
                <div>
                  <label class="form-label">Middle name</label>
                  <input class="form-control name-cap" name="middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) ($tpUser['middle_name'] ?? ''))) ?>">
                </div>
                <div>
                  <label class="form-label">Last name</label>
                  <input class="form-control name-cap" name="last_name" value="<?= e(capitalize_name((string) ($tpUser['last_name'] ?? ''))) ?>" required>
                </div>
                <div class="guide-profile-suffix">
                  <label class="form-label">Suffix</label>
                  <?= name_suffix_field_html('name_suffix', (string) ($tpUser['name_suffix'] ?? '')) ?>
                </div>
              </div>
              <div class="guide-profile-fields guide-profile-fields--4">
                <div>
                  <label class="form-label">Email</label>
                  <input type="email" class="form-control" name="email" required maxlength="190" value="<?= e((string) ($tpUser['email'] ?? '')) ?>">
                </div>
                <div>
                  <label class="form-label">Phone</label>
                  <div class="phone-input">
                    <span class="phone-input__prefix" title="Philippines">
                      <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
                      <span>+63</span>
                    </span>
                    <input type="tel" class="form-control" name="phone" id="touristPhoneLocal"
                           inputmode="numeric" autocomplete="off" maxlength="10"
                           placeholder="9XXXXXXXXX"
                           aria-describedby="touristPhoneWarn"
                           value="<?= e(ph_phone_local_digits($tpUser['phone'] ?? '')) ?>">
                  </div>
                  <div class="form-text text-danger d-none" id="touristPhoneWarn"></div>
                </div>
                <div>
                  <label class="form-label">Gender</label>
                  <div class="select-chevron">
                    <select class="form-select" name="sex" required>
                      <?php foreach (user_sex_options() as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= (string) ($tpUser['sex'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div>
                  <label class="form-label">Date of birth</label>
                  <input type="date" class="form-control" name="date_of_birth" required
                         max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>"
                         value="<?= e((string) ($tpUser['date_of_birth'] ?? '')) ?>">
                </div>
              </div>
              <div class="guide-profile-fields guide-profile-fields--lang">
                <div>
                  <label class="form-label">Nationality</label>
                  <input class="form-control" name="nationality" required value="<?= e((string) ($tpUser['nationality'] ?? '')) ?>">
                </div>
                <div>
                  <label class="form-label">Languages</label>
                  <input class="form-control js-lang-tags" name="languages" required maxlength="255"
                         placeholder="Type a language, then press Enter"
                         value="<?= e((string) ($tpUser['languages'] ?? '')) ?>">
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
              <?= current_address_same_radios_html($sameAsPermanent, 'touristAddrSame') ?>
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
                    <input type="tel" class="form-control" name="emergency_phone_local" id="touristEmergPhone"
                           required inputmode="numeric" autocomplete="off" maxlength="10"
                           placeholder="9XXXXXXXXX" aria-describedby="touristEmergPhoneWarn"
                           value="<?= e(ph_phone_local_digits($tpUser['emergency_contact_phone'] ?? '')) ?>">
                  </div>
                  <div class="form-text text-danger d-none" id="touristEmergPhoneWarn"></div>
                </div>
              </div>
            </div>

            <div class="guide-profile-save">
              <button type="submit" class="btn btn-ct">Save</button>
            </div>
          </div>
        </form>
      </div>
<script src="<?= APP_URL ?>/assets/js/ph-address.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time() ?>"></script>
<script>
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('<?= APP_URL ?>/assets/data/ph-address.json');
  }
  (function () {
    function phoneMessage(d, onBlur) {
      if (!d) return '';
      if (d.charAt(0) !== '9') return 'Enter a valid mobile number.';
      if (onBlur && d.length !== 10) return 'Phone number must be exactly 10 digits.';
      return '';
    }
    function bindPhoneField(input, warnEl) {
      if (!input) return;
      function show(msg) {
        if (!warnEl) return;
        if (msg) {
          warnEl.textContent = msg;
          warnEl.classList.remove('d-none');
          input.classList.add('is-invalid');
        } else {
          warnEl.textContent = '';
          warnEl.classList.add('d-none');
          input.classList.remove('is-invalid');
        }
      }
      input.addEventListener('input', function () {
        var d = input.value.replace(/\D+/g, '');
        if (d.indexOf('63') === 0 && d.length > 10) d = d.slice(2);
        while (d.charAt(0) === '0') d = d.slice(1);
        input.value = d.slice(0, 10);
        show(phoneMessage(input.value, false));
      });
      input.addEventListener('blur', function () {
        show(phoneMessage(input.value, true));
      });
      input._showPhoneWarn = show;
    }
    var phone = document.getElementById('touristPhoneLocal');
    var emergPhone = document.getElementById('touristEmergPhone');
    bindPhoneField(phone, document.getElementById('touristPhoneWarn'));
    bindPhoneField(emergPhone, document.getElementById('touristEmergPhoneWarn'));
    var form = phone ? phone.form : null;
    if (form) {
      form.addEventListener('submit', function (e) {
        [phone, emergPhone].forEach(function (input) {
          if (!input || e.defaultPrevented) return;
          var msg = phoneMessage(input.value, true);
          if (!msg) return;
          e.preventDefault();
          if (input._showPhoneWarn) input._showPhoneWarn(msg);
          input.focus();
        });
      });
    }
  })();
</script>
