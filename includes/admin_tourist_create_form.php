<?php
/**
 * Admin “Add tourist” fields — same required details as public tourist registration.
 */
$sexSelected = (string) ($_POST['sex'] ?? '');
$natVal = (string) ($_POST['nationality'] ?? '');
$phonePosted = ph_phone_local_digits((string) ($_POST['phone_local'] ?? ''));
$emergPhonePosted = ph_phone_local_digits((string) ($_POST['emergency_phone_local'] ?? ''));
$sameAsPermanent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sameRaw = $_POST['current_same_as_permanent'] ?? '0';
    if (is_array($sameRaw)) {
        $sameRaw = end($sameRaw);
    }
    $sameAsPermanent = (string) $sameRaw !== '0';
}
$provInitial = (string) ($_POST['address_region'] ?? '');
$curProvInitial = (string) ($_POST['current_address_region'] ?? '');
?>
        <div class="row g-3 mt-0 name-fields-row">
          <div class="col-12 col-md name-field">
            <label class="form-label">First name</label>
            <input class="form-control name-cap name-letters" name="first_name" id="firstName" maxlength="80" value="<?= e($_POST['first_name'] ?? '') ?>" required autocomplete="given-name">
            <div class="form-text text-danger d-none name-letters-warn"></div>
          </div>
          <div class="col-12 col-md name-field">
            <label class="form-label">Middle name</label>
            <input class="form-control name-cap name-letters" name="middle_name" maxlength="80" placeholder="Optional" value="<?= e($_POST['middle_name'] ?? '') ?>" autocomplete="additional-name">
            <div class="form-text text-danger d-none name-letters-warn"></div>
          </div>
          <div class="col-12 col-md name-field">
            <label class="form-label">Last name</label>
            <input class="form-control name-cap name-letters" name="last_name" id="lastName" maxlength="80" value="<?= e($_POST['last_name'] ?? '') ?>" required autocomplete="family-name">
            <div class="form-text text-danger d-none name-letters-warn"></div>
          </div>
          <div class="col-12 col-md-2 name-field">
            <label class="form-label">Suffix</label>
            <?= name_suffix_field_html('name_suffix', resolve_posted_name_suffix('name_suffix', $_POST)) ?>
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-md-7">
            <label class="form-label">Email</label>
            <input type="email" class="form-control email-check" name="email" id="accountEmail" value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="email" data-role-id="<?= (int) ROLE_TOURIST ?>">
            <div class="form-text text-danger d-none email-check-warn"></div>
          </div>
          <div class="col-md-5">
            <label class="form-label">Phone</label>
            <div class="phone-input">
              <span class="phone-input__prefix" title="Philippines">
                <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
                <span>+63</span>
              </span>
              <input
                type="tel"
                class="form-control"
                name="phone_local"
                id="phoneLocal"
                inputmode="numeric"
                autocomplete="off"
                maxlength="10"
                placeholder="9XXXXXXXXX"
                value="<?= e($phonePosted) ?>"
                aria-describedby="phoneLocalWarn"
              >
            </div>
            <div class="form-text text-danger d-none" id="phoneLocalWarn"></div>
          </div>
        </div>

        <div class="row g-3 mt-0">
          <div class="col-md-3">
            <label class="form-label">Gender</label>
            <div class="select-chevron">
              <select class="form-select" name="sex" required>
                <option value="" disabled <?= $sexSelected === '' ? 'selected' : '' ?>>Select</option>
                <?php foreach (user_sex_options() as $val => $label): ?>
                  <option value="<?= e($val) ?>" <?= $sexSelected === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Date of birth</label>
            <input type="date" class="form-control" name="date_of_birth" required
                   max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>"
                   value="<?= e($_POST['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Nationality</label>
            <input class="form-control" name="nationality" required maxlength="100" value="<?= e($natVal) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Languages</label>
            <input class="form-control js-lang-tags" name="tourist_languages" id="touristLanguages" maxlength="255"
                   value="<?= e($_POST['tourist_languages'] ?? '') ?>"
                   placeholder="Type a language, then press Enter"
                   required>
          </div>
        </div>

        <div class="mt-3" id="addressTourist">
          <input type="hidden" name="use_structured_address" value="1">
          <div data-same-as-permanent id="sameAsAddressWrap">
            <div class="mb-3" data-ph-address id="permanentAddressBlock" data-same-permanent>
              <div class="fw-semibold mb-2">Permanent address</div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Province</label>
                  <div class="select-chevron">
                    <select class="form-select" name="address_region" id="addrRegion" data-ph-region
                            data-initial="<?= e($provInitial) ?>" required>
                      <option value="" selected disabled>Select province</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">City / Municipality</label>
                  <div class="select-chevron">
                    <select class="form-select" name="address_city" id="addrCity" data-ph-city
                            data-initial="<?= e($_POST['address_city'] ?? '') ?>" required disabled>
                      <option value="" selected disabled>Select city / municipality</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Barangay</label>
                  <div class="select-chevron">
                    <select class="form-select" name="address_barangay" id="addrBarangay" data-ph-barangay
                            data-initial="<?= e($_POST['address_barangay'] ?? '') ?>" required disabled>
                      <option value="" selected disabled>Select barangay</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Street</label>
                  <input class="form-control" name="address_street" id="addrStreet" maxlength="150"
                         placeholder="House no., street name"
                         value="<?= e($_POST['address_street'] ?? '') ?>" required>
                </div>
              </div>
            </div>

            <div class="mb-3" id="currentAddressFields" data-ph-address data-same-current>
              <?= current_address_same_radios_html($sameAsPermanent, 'addrSame') ?>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Province</label>
                  <div class="select-chevron">
                    <select class="form-select" name="current_address_region" id="curAddrRegion" data-ph-region
                            data-initial="<?= e($curProvInitial) ?>"
                            <?= $sameAsPermanent ? 'disabled' : 'required' ?>>
                      <option value="" selected disabled>Select province</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">City / Municipality</label>
                  <div class="select-chevron">
                    <select class="form-select" name="current_address_city" id="curAddrCity" data-ph-city
                            data-initial="<?= e($_POST['current_address_city'] ?? '') ?>"
                            <?= $sameAsPermanent ? 'disabled' : 'required' ?> disabled>
                      <option value="" selected disabled>Select city / municipality</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Barangay</label>
                  <div class="select-chevron">
                    <select class="form-select" name="current_address_barangay" id="curAddrBarangay" data-ph-barangay
                            data-initial="<?= e($_POST['current_address_barangay'] ?? '') ?>"
                            <?= $sameAsPermanent ? 'disabled' : 'required' ?> disabled>
                      <option value="" selected disabled>Select barangay</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Street</label>
                  <input class="form-control" name="current_address_street" id="curAddrStreet" maxlength="150"
                         placeholder="House no., street name"
                         value="<?= e($_POST['current_address_street'] ?? '') ?>"
                         <?= $sameAsPermanent ? 'disabled' : 'required' ?>>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-3" id="emergencyFields">
          <hr>
          <div class="fw-semibold mb-2">Emergency contact</div>
          <div class="row g-3 name-fields-row">
            <div class="col-12 col-md name-field">
              <label class="form-label">First name</label>
              <input class="form-control name-cap name-letters" name="emergency_first_name" id="emergFirst" maxlength="80"
                     value="<?= e($_POST['emergency_first_name'] ?? '') ?>" required>
              <div class="form-text text-danger d-none name-letters-warn"></div>
            </div>
            <div class="col-12 col-md name-field">
              <label class="form-label">Middle name</label>
              <input class="form-control name-cap name-letters" name="emergency_middle_name" maxlength="80"
                     placeholder="Optional"
                     value="<?= e($_POST['emergency_middle_name'] ?? '') ?>">
              <div class="form-text text-danger d-none name-letters-warn"></div>
            </div>
            <div class="col-12 col-md name-field">
              <label class="form-label">Last name</label>
              <input class="form-control name-cap name-letters" name="emergency_last_name" id="emergLast" maxlength="80"
                     value="<?= e($_POST['emergency_last_name'] ?? '') ?>" required>
              <div class="form-text text-danger d-none name-letters-warn"></div>
            </div>
            <div class="col-12 col-md-2 name-field">
              <label class="form-label">Suffix</label>
              <?= name_suffix_field_html('emergency_suffix', resolve_posted_name_suffix('emergency_suffix', $_POST)) ?>
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label">Relationship</label>
              <?= emergency_relationship_field_html(resolve_posted_emergency_relationship($_POST)) ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <div class="phone-input">
                <span class="phone-input__prefix" title="Philippines">
                  <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
                  <span>+63</span>
                </span>
                <input
                  type="tel"
                  class="form-control"
                  name="emergency_phone_local"
                  id="emergPhone"
                  inputmode="numeric"
                  autocomplete="off"
                  maxlength="10"
                  placeholder="9XXXXXXXXX"
                  value="<?= e($emergPhonePosted) ?>"
                  aria-describedby="emergPhoneWarn emergDupWarn"
                  required
                >
              </div>
              <div class="form-text text-danger d-none" id="emergPhoneWarn"></div>
            </div>
            <div class="col-12">
              <div class="form-text text-danger d-none" id="emergDupWarn" role="alert"></div>
            </div>
          </div>
        </div>
        <div class="form-text text-warning mt-2 d-none" id="emergSuffixNote" role="status"></div>
