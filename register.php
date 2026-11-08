<?php
require_once __DIR__ . '/includes/auth.php';
ensure_password_schema();
start_app_session();
if ($u = current_user()) {
    if (!empty($u['must_change_password'])) {
        redirect(APP_URL . '/change-password.php');
    }
    redirect(role_dashboard((int) $u['role_id']));
}

$types = [];
try {
    $types = db()->query('SELECT id, name FROM business_types ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    // DB may not be installed yet
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = csrf_error_message();
    } else {
        $rolePost = (int) ($_POST['role_id'] ?? 0);
        if (!$error && $rolePost === ROLE_TOUR_GUIDE && !registration_has_files($_FILES['resume_files'] ?? [])) {
            $error = 'Please upload at least one resume or credential file.';
        }
        if (!$error && $rolePost === ROLE_BUSINESS && !registration_has_files($_FILES['business_documents'] ?? [])) {
            $error = 'Please upload at least one business document.';
        }
        if (!$error) {
            $isBizPost = $rolePost === ROLE_BUSINESS;
            $regPayload = [
                'email' => $isBizPost ? ($_POST['business_email'] ?? '') : ($_POST['email'] ?? ''),
                'password' => $_POST['password'] ?? '',
                'password_confirm' => $_POST['password_confirm'] ?? '',
                'first_name' => $isBizPost ? ($_POST['owner_first_name'] ?? '') : ($_POST['first_name'] ?? ''),
                'last_name' => $isBizPost ? ($_POST['owner_last_name'] ?? '') : ($_POST['last_name'] ?? ''),
                'middle_name' => $isBizPost ? ($_POST['owner_middle_name'] ?? '') : ($_POST['middle_name'] ?? ''),
                'name_suffix' => resolve_posted_name_suffix($isBizPost ? 'owner_suffix' : 'name_suffix', $_POST),
                'sex' => $isBizPost ? ($_POST['owner_sex'] ?? '') : ($_POST['sex'] ?? ''),
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'nationality' => $_POST['nationality'] ?? '',
                'tourist_languages' => $_POST['tourist_languages'] ?? '',
                'languages' => $_POST['languages'] ?? '',
                'address' => $_POST['address'] ?? '',
                'emergency_first_name' => $_POST['emergency_first_name'] ?? '',
                'emergency_middle_name' => $_POST['emergency_middle_name'] ?? '',
                'emergency_last_name' => $_POST['emergency_last_name'] ?? '',
                'emergency_suffix' => resolve_posted_name_suffix('emergency_suffix', $_POST),
                'emergency_relationship' => resolve_posted_emergency_relationship($_POST),
                'emergency_phone_local' => $_POST['emergency_phone_local'] ?? '',
                'phone_local' => $isBizPost
                    ? ($_POST['business_phone_local'] ?? '')
                    : ($_POST['phone_local'] ?? ($_POST['phone'] ?? '')),
                'role_id' => $rolePost ?: ROLE_TOURIST,
                'credential_files' => $_FILES['resume_files'] ?? [],
                'languages' => $_POST['languages'] ?? '',
                'years_experience' => $_POST['years_experience'] ?? '',
                'representative_first_name' => $_POST['representative_first_name'] ?? '',
                'representative_middle_name' => $_POST['representative_middle_name'] ?? '',
                'representative_last_name' => $_POST['representative_last_name'] ?? '',
                'representative_suffix' => resolve_posted_name_suffix('representative_suffix', $_POST),
                'representative_sex' => $_POST['representative_sex'] ?? '',
                'owner_first_name' => $_POST['owner_first_name'] ?? '',
                'owner_middle_name' => $_POST['owner_middle_name'] ?? '',
                'owner_last_name' => $_POST['owner_last_name'] ?? '',
                'owner_suffix' => resolve_posted_name_suffix('owner_suffix', $_POST),
                'owner_sex' => $_POST['owner_sex'] ?? '',
                'business_name' => $_POST['business_name'] ?? '',
                'business_type_id' => $_POST['business_type_id'] ?? '',
                'business_description' => '',
                'business_address' => $_POST['business_address'] ?? '',
                'biz_address_city' => $_POST['biz_address_city'] ?? '',
                'biz_address_barangay' => $_POST['biz_address_barangay'] ?? '',
                'biz_address_street' => $_POST['biz_address_street'] ?? '',
                'business_phone' => $_POST['business_phone'] ?? '',
                'business_email' => $_POST['business_email'] ?? '',
                'permit_number' => $_POST['permit_number'] ?? '',
                'tax_id' => $_POST['tax_id'] ?? '',
                'logo_file' => [],
                'photo_files' => [],
                'document_files' => $_FILES['business_documents'] ?? [],
            ];
            if ($rolePost === ROLE_TOURIST || $rolePost === ROLE_TOUR_GUIDE) {
                $regPayload['use_structured_address'] = '1';
                $regPayload['address_region'] = $_POST['address_region'] ?? '';
                $regPayload['address_city'] = $_POST['address_city'] ?? '';
                $regPayload['address_barangay'] = $_POST['address_barangay'] ?? '';
                $regPayload['address_street'] = $_POST['address_street'] ?? '';
                $sameRaw = $_POST['current_same_as_permanent'] ?? '0';
                if (is_array($sameRaw)) {
                    $sameRaw = end($sameRaw);
                }
                $regPayload['current_same_as_permanent'] = ((string) $sameRaw !== '0') ? '1' : '0';
                $regPayload['current_address_city'] = $_POST['current_address_city'] ?? '';
                $regPayload['current_address_barangay'] = $_POST['current_address_barangay'] ?? '';
                $regPayload['current_address_street'] = $_POST['current_address_street'] ?? '';
                if ($rolePost === ROLE_TOUR_GUIDE) {
                    $regPayload['require_current_province'] = ph_guide_locked_province();
                    $regPayload['current_address_region'] = ph_guide_locked_province();
                } else {
                    $regPayload['current_address_region'] = $_POST['current_address_region'] ?? '';
                }
                $regPayload['link_token'] = $_POST['link_token'] ?? '';
                $regPayload['link_source_user_id'] = $_POST['link_source_user_id'] ?? '';
            }
            $result = register_user($regPayload);
            if ($result['ok']) {
                clear_pending_registration_link();
                flash('success', $result['message']);
                redirect(APP_URL . '/login.php');
            }
            $error = $result['message'];
        }
    }
}

$pageTitle = 'Register';
$loadLangTags = true;
require __DIR__ . '/includes/header.php';
$pendingLinkClient = null;
$pendingStored = peek_pending_registration_link();
if ($pendingStored) {
    $pendingLinkClient = pending_registration_link_client_payload($pendingStored);
    if (!isset($_GET['role']) && !isset($_GET['role_id']) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $_GET['role'] = (string) ($pendingStored['target_role_id'] ?? '');
    }
}
$roleSelected = (int) ($_POST['role_id'] ?? $_GET['role'] ?? $_GET['role_id'] ?? ROLE_TOURIST);
if (!in_array($roleSelected, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
    $roleSelected = ROLE_TOURIST;
}
$sexSelected = (string) ($_POST['sex'] ?? '');
$natVal = (string) ($_POST['nationality'] ?? '');
$phonePosted = preg_replace('/\D+/', '', (string) ($_POST['phone_local'] ?? '')) ?? '';
if (str_starts_with($phonePosted, '63') && strlen($phonePosted) > 10) {
    $phonePosted = substr($phonePosted, 2);
}
$phonePosted = substr(ltrim($phonePosted, '0'), 0, 10);
$emergPhonePosted = ph_phone_local_digits((string) ($_POST['emergency_phone_local'] ?? ''));
$bizPhonePosted = ph_phone_local_digits((string) ($_POST['business_phone_local'] ?? ''));
?>
<div class="auth-shell auth-shell--back">
  <a class="auth-back" href="<?= APP_URL ?>/">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>
    <span>Back</span>
  </a>
  <div class="auth-hero d-none d-lg-flex">
    <?php
      if ($roleSelected === ROLE_TOUR_GUIDE) {
          $heroRoleLabel = 'Tour Guide';
      } elseif ($roleSelected === ROLE_BUSINESS) {
          $heroRoleLabel = 'Local Business';
      } else {
          $heroRoleLabel = 'Tourist';
      }
    ?>
    <div class="auth-hero__role" id="authHeroRole" data-role="<?= (int) $roleSelected ?>">
      <span class="auth-hero__role-kicker">Currently registering as</span>
      <span class="auth-hero__role-label" id="authHeroRoleLabel"><?= e($heroRoleLabel) ?></span>
    </div>
    <div class="auth-hero__copy" id="authHeroCopy">
      <div class="auth-hero__panel<?= $roleSelected === ROLE_TOURIST ? '' : ' d-none' ?>" data-hero-role="<?= ROLE_TOURIST ?>">
        <h1>Discover Ormoc Your Way</h1>
        <p class="auth-hero__lead opacity-90">Plan your next adventure in Ormoc City.</p>
        <p class="opacity-90 mb-3">Discover beautiful destinations, find local experiences, connect with tour guides, and create memorable trips.</p>
        <p class="mb-0 opacity-90">Register now and start exploring Ormoc with CulTour.</p>
      </div>
      <div class="auth-hero__panel<?= $roleSelected === ROLE_TOUR_GUIDE ? '' : ' d-none' ?>" data-hero-role="<?= ROLE_TOUR_GUIDE ?>">
        <h1>Help Visitors Discover the Best of Ormoc.</h1>
        <p class="auth-hero__lead opacity-90">Become a trusted local tour guide in Ormoc City.</p>
        <p class="mb-0 opacity-90">Share your local knowledge, connect with tourists, and create memorable experiences.</p>
      </div>
      <div class="auth-hero__panel<?= $roleSelected === ROLE_BUSINESS ? '' : ' d-none' ?>" data-hero-role="<?= ROLE_BUSINESS ?>">
        <h1>Grow Your Business With Ormoc Tourism</h1>
        <p class="auth-hero__lead opacity-90">Showcase your business to visitors exploring Ormoc City.</p>
        <p class="mb-0 opacity-90">Promote your products and services, reach more tourists, and connect with new customers through CulTour.</p>
      </div>
    </div>
    <div class="auth-hero__footer">
      <span class="auth-hero__footer-kicker">Join Ormoc’s</span>
      <span class="auth-hero__footer-title">Growing Tourism Network</span>
    </div>
  </div>
  <div class="auth-panel">
    <div class="glass p-4 p-md-5 w-100 fade-up auth-register-card">
      <h2 class="h3 mb-1">Register</h2>
      <p class="text-muted mb-4">Choose your role and complete your details.</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" id="registerForm" data-live-validate>
        <?= csrf_field() ?>
        <div class="fw-semibold mb-2">Account</div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">I am a</label>
            <select class="form-select" name="role_id" id="roleSelect">
              <option value="<?= ROLE_TOURIST ?>" <?= $roleSelected === ROLE_TOURIST ? 'selected' : '' ?>>Tourist</option>
              <option value="<?= ROLE_TOUR_GUIDE ?>" <?= $roleSelected === ROLE_TOUR_GUIDE ? 'selected' : '' ?>>Tour Guide</option>
              <option value="<?= ROLE_BUSINESS ?>" <?= $roleSelected === ROLE_BUSINESS ? 'selected' : '' ?>>Local Business</option>
            </select>
            <div class="form-text text-danger mt-2 <?= $roleSelected === ROLE_BUSINESS ? '' : 'd-none' ?>" id="bizOrmocNote"><strong>Note:</strong> Only local businesses in Ormoc City can register on CulTour.</div>
            <?php
              $linkRestore = null;
              if (
                  ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                  && in_array($roleSelected, [ROLE_TOURIST, ROLE_TOUR_GUIDE], true)
              ) {
                  $chk = resolve_registration_link($_POST, $roleSelected);
                  if (!empty($chk['ok'])) {
                      $linkRestore = $chk;
                  }
              }
              $linkRestoreUser = is_array($linkRestore['user'] ?? null) ? $linkRestore['user'] : null;
            ?>
            <div id="linkAccountWrap" class="mt-3 <?= $linkRestoreUser ? '' : 'd-none' ?>">
              <div id="linkAccountBanner" class="alert alert-success py-2 small mt-2 mb-0<?= $linkRestoreUser ? '' : ' d-none' ?>">
                Linked to your <span id="linkAccountBannerRole"><?= $linkRestoreUser ? e(role_label((int) $linkRestoreUser['role_id'])) : '' ?></span> account
                (<strong id="linkAccountBannerEmail"><?= $linkRestoreUser ? e((string) $linkRestoreUser['email']) : '' ?></strong>).
                Shared details were filled in. Complete any remaining fields for this role.
                <span id="linkAccountBannerMissing"></span>
                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="linkAccountClear">Unlink</button>
              </div>
              <input type="hidden" name="link_source_user_id" id="linkSourceUserId" value="<?= $linkRestoreUser ? (int) $linkRestoreUser['id'] : '' ?>">
              <input type="hidden" name="link_token" id="linkToken" value="<?= e((string) ($_POST['link_token'] ?? '')) ?>">
            </div>
          </div>
        </div>

        <div id="stdAccountFields" class="<?= $roleSelected === ROLE_BUSINESS ? 'd-none' : '' ?>" data-link-gap="name">
          <div class="row g-3 mt-0 name-fields-row">
            <div class="col-12 col-md name-field">
            <label class="form-label">First name</label>
              <input class="form-control name-cap name-letters" name="first_name" id="firstName" maxlength="80" value="<?= e($_POST['first_name'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? '' : 'required' ?> autocomplete="given-name">
              <div class="form-text text-danger d-none name-letters-warn"></div>
          </div>
            <div class="col-12 col-md name-field">
              <label class="form-label">Middle name</label>
              <input class="form-control name-cap name-letters" name="middle_name" maxlength="80" placeholder="Optional" value="<?= e($_POST['middle_name'] ?? '') ?>" autocomplete="additional-name">
              <div class="form-text text-danger d-none name-letters-warn"></div>
            </div>
            <div class="col-12 col-md name-field">
            <label class="form-label">Last name</label>
              <input class="form-control name-cap name-letters" name="last_name" id="lastName" maxlength="80" value="<?= e($_POST['last_name'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? '' : 'required' ?> autocomplete="family-name">
              <div class="form-text text-danger d-none name-letters-warn"></div>
          </div>
            <div class="col-12 col-md-2 name-field">
              <label class="form-label">Suffix</label>
              <?= name_suffix_field_html('name_suffix', resolve_posted_name_suffix('name_suffix', $_POST)) ?>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-7">
            <label class="form-label">Email</label>
              <input type="email" class="form-control email-check" name="email" id="accountEmail" value="<?= e($_POST['email'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? '' : 'required' ?> autocomplete="email"<?= $linkRestoreUser ? ' readonly' : '' ?>>
              <div class="form-text text-danger d-none email-check-warn"></div>
              <div class="form-text text-danger d-none" id="emailCounterpartWarn" role="status"></div>
          </div>
            <div class="col-md-5" data-link-gap="phone">
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
        </div>

        <div id="personalFields" class="<?= $roleSelected === ROLE_BUSINESS ? 'd-none' : '' ?>" data-link-gap="sex dob">
        <div class="row g-3 mt-0">
          <div class="col-md-4">
            <label class="form-label">Gender</label>
            <div class="select-chevron">
              <select class="form-select" name="sex" <?= $roleSelected === ROLE_BUSINESS ? '' : 'required' ?>>
                <option value="" disabled <?= $sexSelected === '' ? 'selected' : '' ?>>Select</option>
                <?php foreach (user_sex_options() as $val => $label): ?>
                  <option value="<?= e($val) ?>" <?= $sexSelected === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date of birth</label>
            <input type="date" class="form-control" name="date_of_birth" <?= $roleSelected === ROLE_BUSINESS ? '' : 'required' ?>
                   max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>"
                   value="<?= e($_POST['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Nationality</label>
            <input class="form-control" name="nationality" <?= $roleSelected === ROLE_BUSINESS ? '' : 'required' ?> maxlength="100" value="<?= e($natVal) ?>">
          </div>
          <div class="col-12 <?= $roleSelected === ROLE_TOURIST ? '' : 'd-none' ?>" id="touristLangWrap" data-link-gap="tourist_languages">
            <label class="form-label">Languages</label>
            <input class="form-control js-lang-tags" name="tourist_languages" id="touristLanguages" maxlength="255"
                   value="<?= e($_POST['tourist_languages'] ?? '') ?>"
                   placeholder="Type a language, then press Enter"
                   <?= $roleSelected === ROLE_TOURIST ? 'required' : 'disabled' ?>>
          </div>
          <div class="col-12" id="addressSimple" hidden>
            <label class="form-label">Address</label>
            <input class="form-control" name="address" id="addrSimple" maxlength="255" placeholder="Street, barangay, Ormoc City"
                   value="<?= e($_POST['address'] ?? '') ?>" disabled>
          </div>
          <?php
            $useStructuredAddr = $roleSelected === ROLE_TOURIST || $roleSelected === ROLE_TOUR_GUIDE;
            $guideLockProv = ph_guide_locked_province();
            $provInitial = (string) ($_POST['address_region'] ?? '');
            $sameAsPermanent = false;
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $sameRaw = $_POST['current_same_as_permanent'] ?? '0';
                if (is_array($sameRaw)) {
                    $sameRaw = end($sameRaw);
                }
                $sameAsPermanent = (string) $sameRaw !== '0';
            }
            $curProvInitial = $roleSelected === ROLE_TOUR_GUIDE
                ? $guideLockProv
                : (string) ($_POST['current_address_region'] ?? '');
          ?>
          <div class="col-12 <?= $useStructuredAddr ? '' : 'd-none' ?>" id="addressTourist">
            <input type="hidden" name="use_structured_address" id="useStructuredAddress" value="<?= $useStructuredAddr ? '1' : '' ?>" <?= $useStructuredAddr ? '' : 'disabled' ?>>
            <div data-same-as-permanent id="sameAsAddressWrap" data-same-lock-province="<?= $roleSelected === ROLE_TOUR_GUIDE ? e($guideLockProv) : '' ?>" data-same-lock-city="<?= $roleSelected === ROLE_TOUR_GUIDE ? e(ph_guide_locked_city()) : '' ?>">

            <div class="mb-3" data-ph-address id="permanentAddressBlock" data-same-permanent data-link-gap="permanent_address">
              <div class="fw-semibold mb-2">Permanent address</div>
              <div class="row g-3">
          <div class="col-md-6">
                  <label class="form-label">Province</label>
                  <div class="select-chevron">
                    <select class="form-select" name="address_region" id="addrRegion" data-ph-region
                            data-initial="<?= e($provInitial) ?>"
                            <?= $useStructuredAddr ? 'required' : 'disabled' ?>>
                      <option value="" selected disabled>Select province</option>
            </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
          </div>
          <div class="col-md-6">
                  <label class="form-label">City / Municipality</label>
                  <div class="select-chevron">
                    <select class="form-select" name="address_city" id="addrCity" data-ph-city
                            data-initial="<?= e($_POST['address_city'] ?? '') ?>"
                            <?= $useStructuredAddr ? 'required' : '' ?> disabled>
                      <option value="" selected disabled>Select city / municipality</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
          </div>
          <div class="col-md-6">
                  <label class="form-label">Barangay</label>
                  <div class="select-chevron">
                    <select class="form-select" name="address_barangay" id="addrBarangay" data-ph-barangay
                            data-initial="<?= e($_POST['address_barangay'] ?? '') ?>"
                            <?= $useStructuredAddr ? 'required' : '' ?> disabled>
                      <option value="" selected disabled>Select barangay</option>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Street</label>
                  <input class="form-control" name="address_street" id="addrStreet" maxlength="150"
                         placeholder="House no., street name"
                         value="<?= e($_POST['address_street'] ?? '') ?>"
                         <?= $useStructuredAddr ? 'required' : 'disabled' ?>>
                </div>
          </div>
        </div>

            <div class="mb-3" id="currentAddressFields" data-ph-address data-same-current data-link-gap="leyte_address"
                 data-ph-lock-province="<?= $roleSelected === ROLE_TOUR_GUIDE ? e($guideLockProv) : '' ?>"
                 data-ph-lock-city="<?= $roleSelected === ROLE_TOUR_GUIDE ? e(ph_guide_locked_city()) : '' ?>">
              <?= current_address_same_radios_html($sameAsPermanent, 'addrSame') ?>
              <p class="form-text text-danger mb-2<?= $roleSelected === ROLE_TOUR_GUIDE ? '' : ' d-none' ?>" id="guideOrmocNote"><strong>Note:</strong> Only tour guides who currently live in Ormoc City can register.</p>
          <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Province</label>
                  <div class="select-chevron">
                    <select class="form-select" name="current_address_region" id="curAddrRegion" data-ph-region
                            data-initial="<?= e($curProvInitial) ?>"
                            <?= ($useStructuredAddr && !$sameAsPermanent) ? 'required' : '' ?>
                            <?= $sameAsPermanent ? 'disabled' : '' ?>>
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
                            <?= ($useStructuredAddr && !$sameAsPermanent) ? 'required' : '' ?> disabled>
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
                            <?= ($useStructuredAddr && !$sameAsPermanent) ? 'required' : '' ?> disabled>
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
                         <?= ($useStructuredAddr && !$sameAsPermanent) ? 'required' : '' ?>
                         <?= $sameAsPermanent ? 'disabled' : '' ?>>
                </div>
              </div>
            </div>
            </div>
          </div>
        </div>
        </div>

        <div id="bizHeadFields" class="row g-3 mt-0 <?= $roleSelected === ROLE_BUSINESS ? '' : 'd-none' ?>">
            <div class="col-12">
            <div class="fw-semibold biz-section-title">Representative name</div>
            <div class="row g-3">
              <div class="col-12 col-md name-field">
                <label class="form-label">First name</label>
                <input class="form-control name-cap name-letters" name="representative_first_name" id="repFirst" maxlength="80"
                       value="<?= e($_POST['representative_first_name'] ?? '') ?>"
                       <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                <div class="form-text text-danger d-none name-letters-warn"></div>
            </div>
              <div class="col-12 col-md name-field">
                <label class="form-label">Middle name</label>
                <input class="form-control name-cap name-letters" name="representative_middle_name" maxlength="80" placeholder="Optional" value="<?= e($_POST['representative_middle_name'] ?? '') ?>">
                <div class="form-text text-danger d-none name-letters-warn"></div>
              </div>
              <div class="col-12 col-md name-field">
                <label class="form-label">Last name</label>
                <input class="form-control name-cap name-letters" name="representative_last_name" id="repLast" maxlength="80"
                       value="<?= e($_POST['representative_last_name'] ?? '') ?>"
                       <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                <div class="form-text text-danger d-none name-letters-warn"></div>
              </div>
              <div class="col-12 col-md-2 name-field">
                <label class="form-label">Suffix</label>
                <?= name_suffix_field_html('representative_suffix', resolve_posted_name_suffix('representative_suffix', $_POST)) ?>
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="row g-3 align-items-start">
              <div class="col-auto biz-gender-col">
                <label class="form-label">Gender</label>
                <div class="select-chevron">
                  <select class="form-select" name="representative_sex" id="repSex" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                    <option value="" disabled <?= (string) ($_POST['representative_sex'] ?? '') === '' ? 'selected' : '' ?>>Select</option>
                    <?php foreach (user_sex_options() as $val => $label): ?>
                      <option value="<?= e($val) ?>" <?= (string) ($_POST['representative_sex'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                </div>
              </div>
              <div class="col min-w-0 biz-name-col">
                <label class="form-label">Business name</label>
                <input class="form-control" name="business_name" id="bizName" value="<?= e($_POST['business_name'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
              </div>
              <div class="col min-w-0 biz-type-col">
                <label class="form-label">Business category</label>
                <div class="select-chevron">
                  <select class="form-select" name="business_type_id" id="bizType" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                    <option value="" <?= (string) ($_POST['business_type_id'] ?? '') === '' ? 'selected' : '' ?> disabled>Select type</option>
              <?php foreach ($types as $t): ?>
                      <option value="<?= (int) $t['id'] ?>" <?= (string) ($_POST['business_type_id'] ?? '') === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
                  <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
          </div>
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="fw-semibold biz-section-title">Business owner name</div>
            <div class="row g-3">
              <div class="col-12 col-md name-field">
                <label class="form-label">First name</label>
                <input class="form-control name-cap name-letters" name="owner_first_name" id="ownerFirst" maxlength="80"
                       value="<?= e($_POST['owner_first_name'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                <div class="form-text text-danger d-none name-letters-warn"></div>
              </div>
              <div class="col-12 col-md name-field">
                <label class="form-label">Middle name</label>
                <input class="form-control name-cap name-letters" name="owner_middle_name" maxlength="80" placeholder="Optional" value="<?= e($_POST['owner_middle_name'] ?? '') ?>">
                <div class="form-text text-danger d-none name-letters-warn"></div>
              </div>
              <div class="col-12 col-md name-field">
                <label class="form-label">Last name</label>
                <input class="form-control name-cap name-letters" name="owner_last_name" id="ownerLast" maxlength="80"
                       value="<?= e($_POST['owner_last_name'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                <div class="form-text text-danger d-none name-letters-warn"></div>
              </div>
              <div class="col-12 col-md-2 name-field">
                <label class="form-label">Suffix</label>
                <?= name_suffix_field_html('owner_suffix', resolve_posted_name_suffix('owner_suffix', $_POST)) ?>
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="row g-3 align-items-start">
              <div class="col-6 col-md-auto biz-gender-col">
                <label class="form-label">Gender</label>
                <div class="select-chevron">
                  <select class="form-select" name="owner_sex" id="ownerSex" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
                    <option value="" disabled <?= (string) ($_POST['owner_sex'] ?? '') === '' ? 'selected' : '' ?>>Select</option>
                    <?php foreach (user_sex_options() as $val => $label): ?>
                      <option value="<?= e($val) ?>" <?= (string) ($_POST['owner_sex'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                </div>
              </div>
              <div class="col-12 col-md">
                <label class="form-label">Business email</label>
                <input type="email" class="form-control email-check" name="business_email" id="bizEmail" value="<?= e($_POST['business_email'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?> autocomplete="email">
                <div class="form-text text-danger d-none email-check-warn"></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-auto biz-contact-col">
            <label class="form-label">Business contact number</label>
            <div class="phone-input">
              <span class="phone-input__prefix" title="Philippines">
                <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
                <span>+63</span>
              </span>
              <input
                type="tel"
                class="form-control"
                name="business_phone_local"
                id="bizPhoneLocal"
                inputmode="numeric"
                autocomplete="off"
                maxlength="10"
                placeholder="9XXXXXXXXX"
                value="<?= e($bizPhonePosted) ?>"
                aria-describedby="bizPhoneWarn"
                <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>
              >
            </div>
            <div class="form-text text-danger d-none" id="bizPhoneWarn"></div>
          </div>
          <div class="col-md">
            <label class="form-label">Registration Number</label>
            <input class="form-control" name="permit_number" id="bizPermit" value="<?= e($_POST['permit_number'] ?? '') ?>" <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
          </div>
          <div class="col-md">
            <label class="form-label">Tax Identification Number</label>
            <input class="form-control js-tax-digits" name="tax_id" inputmode="numeric" autocomplete="off" pattern="[0-9]*" placeholder="If applicable" value="<?= e($_POST['tax_id'] ?? '') ?>">
            <div class="form-text text-danger d-none js-tax-digits-warn"></div>
          </div>
          <div class="col-12">
            <label class="form-label">Business Address</label>
            <div class="row g-3" id="bizAddressBlock" data-ph-address data-ph-hide-province data-ph-hide-city
                 data-ph-lock-province="<?= e(ph_guide_locked_province()) ?>"
                 data-ph-lock-city="<?= e(ph_business_default_city()) ?>">
              <input type="hidden" data-ph-region name="biz_address_region" value="<?= e(ph_guide_locked_province()) ?>">
              <input type="hidden" data-ph-city name="biz_address_city" id="bizAddrCity" value="<?= e(ph_business_default_city()) ?>">
              <div class="col-md-6">
                <label class="form-label">Barangay</label>
                <div class="select-chevron">
                  <select class="form-select" name="biz_address_barangay" id="bizAddrBarangay" data-ph-barangay
                          data-initial="<?= e($_POST['biz_address_barangay'] ?? '') ?>"
                          <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?> disabled>
                    <option value="" selected disabled>Select barangay</option>
                  </select>
                  <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Street</label>
                <input class="form-control" name="biz_address_street" id="bizAddrStreet" maxlength="150"
                       placeholder="House no., street name"
                       value="<?= e($_POST['biz_address_street'] ?? '') ?>"
                       <?= $roleSelected === ROLE_BUSINESS ? 'required' : '' ?>>
              </div>
            </div>
          </div>
        </div>

        <?php
          $showEmergency = $roleSelected === ROLE_TOURIST || $roleSelected === ROLE_TOUR_GUIDE;
          $emergRequired = $showEmergency ? 'required' : '';
          $guideYearsPosted = preg_replace('/\D+/', '', (string) ($_POST['years_experience'] ?? '')) ?? '';
        ?>
        <div id="guideLangFields" class="mt-3 <?= $roleSelected === ROLE_TOUR_GUIDE ? '' : 'd-none' ?>" data-link-gap="languages">
          <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label">Years of experience</label>
              <input type="number" class="form-control" name="years_experience" id="guideYearsExp"
                     value="<?= e($guideYearsPosted) ?>" min="0" max="80" step="1" inputmode="numeric"
                     placeholder="0">
            </div>
            <div class="col-12 col-md-9">
              <label class="form-label">Languages <span class="text-danger">*</span></label>
              <input class="form-control js-lang-tags" name="languages" id="guideLanguages" maxlength="255"
                     value="<?= e($_POST['languages'] ?? '') ?>"
                     placeholder="Type a language, then press Enter"
                     <?= $roleSelected === ROLE_TOUR_GUIDE ? 'required' : '' ?>>
            </div>
          </div>
        </div>

        <div id="emergencyFields" class="mt-3 <?= $showEmergency ? '' : 'd-none' ?>" data-link-gap="emergency">
          <hr>
          <div class="fw-semibold mb-2">Emergency contact</div>
          <div class="row g-3 name-fields-row">
            <div class="col-12 col-md name-field">
              <label class="form-label">First name</label>
              <input class="form-control name-cap name-letters" name="emergency_first_name" id="emergFirst" maxlength="80"
                     value="<?= e($_POST['emergency_first_name'] ?? '') ?>"
                     <?= $emergRequired ?>>
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
                     value="<?= e($_POST['emergency_last_name'] ?? '') ?>"
                     <?= $emergRequired ?>>
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
                  <?= $emergRequired ?>
                >
              </div>
              <div class="form-text text-danger d-none" id="emergPhoneWarn"></div>
            </div>
            <div class="col-12">
              <div class="form-text text-danger d-none" id="emergDupWarn" role="alert"></div>
            </div>
          </div>
        </div>

        <div id="guideFields" class="mt-3 <?= $roleSelected === ROLE_TOUR_GUIDE ? '' : 'd-none' ?>" data-link-gap="credentials">
          <hr>
          <div class="mb-2 fw-semibold">Credentials</div>
          <p class="small text-muted mb-3">Upload one or more resumes or credentials for Tourism Office review. You can finish your guide profile after approval.</p>
          <div class="mb-0">
            <label class="form-label">Resume / credentials (PDF, DOC, or image) <span class="text-danger">*</span></label>
            <div class="file-upload-field" data-file-upload>
              <input
                type="file"
                class="form-control js-multi-files"
                name="resume_files[]"
                id="resumeFiles"
                accept=".pdf,.doc,.docx,image/*"
                multiple
                data-list="resumeFilesList"
                data-count="resumeFilesCount"
                data-menu="resumeFilesMenu"
                data-toggle="resumeFilesToggle"
              >
              <button type="button" class="file-upload-field__edge" id="resumeFilesToggle" aria-expanded="false" aria-controls="resumeFilesMenu" title="View uploaded files">
                <span class="file-upload-field__count" id="resumeFilesCount">0</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
              </button>
              <div class="file-upload-field__menu d-none" id="resumeFilesMenu" hidden>
                <div class="file-upload-field__menu-head">Uploaded files</div>
                <ul class="list-unstyled mb-0" id="resumeFilesList"></ul>
                <div class="file-upload-field__empty small text-muted" id="resumeFilesEmpty">No files uploaded yet.</div>
              </div>
            </div>
            <div class="form-text">You can select multiple files. Open the dropdown on the right to review or remove them.</div>
            <div class="form-text text-danger d-none" id="resumeFilesWarn">Please upload at least one file.</div>
          </div>
        </div>

        <div id="bizFields" class="mt-3 <?= $roleSelected === ROLE_BUSINESS ? '' : 'd-none' ?>">
          <hr>
          <p class="small text-muted">After the Tourism Office approves your account, sign in and finish your listing with a logo, description, photos, map pin, operating hours, and services.</p>
          <div class="mb-3">
            <label class="form-label">Proof of Business Registration <span class="text-danger">*</span></label>
            <div class="file-upload-field" data-file-upload>
              <input
                type="file"
                class="form-control js-multi-files"
                name="business_documents[]"
                id="bizDocs"
                accept=".pdf,.doc,.docx,image/*"
                multiple
                data-list="bizFilesList"
                data-count="bizDocsCount"
                data-menu="bizDocsMenu"
                data-toggle="bizDocsToggle"
              >
              <button type="button" class="file-upload-field__edge" id="bizDocsToggle" aria-expanded="false" aria-controls="bizDocsMenu" title="View uploaded files">
                <span class="file-upload-field__count" id="bizDocsCount">0</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
              </button>
              <div class="file-upload-field__menu d-none" id="bizDocsMenu" hidden>
                <div class="file-upload-field__menu-head">Uploaded files</div>
                <ul class="list-unstyled mb-0" id="bizFilesList"></ul>
                <div class="file-upload-field__empty small text-muted">No files uploaded yet.</div>
              </div>
            </div>
            <div class="form-text">You can select multiple files. Open the dropdown on the right to review or remove them.</div>
            <div class="form-text text-danger d-none" id="bizDocsWarn">Please upload at least one proof of business registration.</div>
          </div>
        </div>

        <hr id="passwordDivider" class="<?= $roleSelected === ROLE_TOURIST && !$linkRestoreUser ? '' : 'd-none' ?>">
        <div class="row g-3 mt-3<?= $linkRestoreUser ? ' d-none' : '' ?>" id="passwordFields">
          <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" id="password" <?= $linkRestoreUser ? '' : 'required' ?> minlength="5" autocomplete="new-password">
            <?= password_rules_hint_html('password') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirm password</label>
            <input type="password" class="form-control" name="password_confirm" id="password_confirm" <?= $linkRestoreUser ? '' : 'required' ?> minlength="5" autocomplete="new-password">
            <?= password_confirm_warn_html('password') ?>
          </div>
        </div>

        <button class="btn btn-ct w-100 mt-4" type="submit">Create account</button>
        <div class="form-text text-warning mt-2 d-none" id="emergSuffixNote" role="status"></div>
      </form>
      <p class="mt-3 mb-0 small text-center">Already registered? <a href="<?= APP_URL ?>/login.php">Login</a></p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/guide_leyte_same_modal.php'; ?>

<div class="login-role-backdrop" id="registerLinkPromptModal" hidden>
  <div class="login-role-card glass" role="dialog" aria-modal="true" aria-labelledby="registerLinkPromptTitle">
    <h3 class="h5 mb-2" id="registerLinkPromptTitle">This email already has an account</h3>
    <p class="text-muted small mb-3" id="registerLinkPromptBody">This email exists on another CulTour role. You can link them instead of creating a separate account.</p>
    <button type="button" class="btn btn-ct w-100 mb-2" id="registerLinkPromptLink">Link them</button>
    <button type="button" class="btn btn-link btn-sm text-muted w-100" id="registerLinkPromptCancel">Cancel</button>
  </div>
</div>

<div class="login-role-backdrop" id="registerLeyteBlockModal" hidden>
  <div class="login-role-card glass" role="dialog" aria-modal="true" aria-labelledby="registerLeyteBlockTitle">
    <h3 class="h5 mb-2" id="registerLeyteBlockTitle">Cannot link this account</h3>
    <p class="text-muted small mb-3" id="registerLeyteBlockBody">Your Tourist account’s current address is not in Ormoc City, so you cannot link it to a Tour Guide account. Change that account’s current address to Ormoc City first, then come back and link them.</p>
    <button type="button" class="btn btn-ct w-100" id="registerLeyteBlockClose">Close</button>
  </div>
</div>

<div class="login-role-backdrop" id="registerLinkSignInModal" hidden>
  <div class="login-role-card login-role-card--form glass" role="dialog" aria-modal="true" aria-labelledby="registerLinkSignInTitle">
    <div class="login-role-card__head">
      <div>
        <h3 class="h5 mb-1" id="registerLinkSignInTitle">Sign in to your existing account</h3>
        <p class="small text-muted mb-0" id="registerLinkSignInHint">Enter that account’s password to link it.</p>
      </div>
      <button type="button" class="login-role-card__close" id="registerLinkSignInClose" aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <div class="alert alert-danger py-2 small d-none" id="registerLinkSignInError" role="alert"></div>
    <div class="mb-3">
      <label class="form-label" for="registerLinkSignInEmail">Email</label>
      <input type="email" class="form-control" id="registerLinkSignInEmail" autocomplete="username" maxlength="190" readonly>
    </div>
    <div class="mb-3">
      <label class="form-label" for="registerLinkSignInPassword">Password</label>
      <input type="password" class="form-control" id="registerLinkSignInPassword" autocomplete="current-password">
    </div>
    <button type="button" class="btn btn-ct w-100" id="registerLinkSignInContinue">Continue</button>
    <button type="button" class="btn btn-link btn-sm text-muted mt-2 w-100" id="registerLinkSignInCancel">Cancel</button>
  </div>
</div>

<script>
window.isRegisterLinked = function () {
  const tok = document.getElementById('linkToken');
  return !!(tok && String(tok.value || '').trim());
};
const roleSelect = document.getElementById('roleSelect');
let lastRole = roleSelect ? roleSelect.value : '';
const heroRoleLabels = {
  '<?= ROLE_TOURIST ?>': 'Tourist',
  '<?= ROLE_TOUR_GUIDE ?>': 'Tour Guide',
  '<?= ROLE_BUSINESS ?>': 'Local Business'
};
function syncAuthHeroRole() {
  if (!roleSelect) return;
  const label = heroRoleLabels[roleSelect.value] || 'Tourist';
  const heroLabel = document.getElementById('authHeroRoleLabel');
  const heroRole = document.getElementById('authHeroRole');
  if (heroLabel) heroLabel.textContent = label;
  if (heroRole) heroRole.setAttribute('data-role', roleSelect.value);
  document.querySelectorAll('#authHeroCopy [data-hero-role]').forEach(function (panel) {
    panel.classList.toggle('d-none', panel.getAttribute('data-hero-role') !== roleSelect.value);
  });
}
roleSelect.addEventListener('change', function () {
  syncAuthHeroRole();
  const v = this.value;
  const isBiz = v === '<?= ROLE_BUSINESS ?>';
  const isTourist = v === '<?= ROLE_TOURIST ?>';
  const wasGuide = lastRole === '<?= ROLE_TOUR_GUIDE ?>';
  const isGuide = v === '<?= ROLE_TOUR_GUIDE ?>';
  document.getElementById('guideFields').classList.toggle('d-none', !isGuide);
  const guideLangFields = document.getElementById('guideLangFields');
  if (guideLangFields) guideLangFields.classList.toggle('d-none', !isGuide);
  document.getElementById('bizFields').classList.toggle('d-none', !isBiz);
  const stdAcc = document.getElementById('stdAccountFields');
  const bizHead = document.getElementById('bizHeadFields');
  if (stdAcc) stdAcc.classList.toggle('d-none', isBiz);
  if (bizHead) bizHead.classList.toggle('d-none', !isBiz);
  const bizNote = document.getElementById('bizOrmocNote');
  if (bizNote) bizNote.classList.toggle('d-none', !isBiz);
  const passwordDivider = document.getElementById('passwordDivider');
  if (passwordDivider) passwordDivider.classList.toggle('d-none', !isTourist || window.isRegisterLinked());
  const first = document.getElementById('firstName');
  const last = document.getElementById('lastName');
  const accEmail = document.getElementById('accountEmail');
  if (first) first.required = !isBiz;
  if (last) last.required = !isBiz;
  if (accEmail) accEmail.required = !isBiz;
  const repFirst = document.getElementById('repFirst');
  const repLast = document.getElementById('repLast');
  const bizEmail = document.getElementById('bizEmail');
  const bizPhoneLocal = document.getElementById('bizPhoneLocal');
  const ownerFirst = document.getElementById('ownerFirst');
  const ownerLast = document.getElementById('ownerLast');
  if (repFirst) repFirst.required = isBiz;
  if (repLast) repLast.required = isBiz;
  if (bizEmail) bizEmail.required = isBiz;
  const bizName = document.getElementById('bizName');
  if (bizName) bizName.required = isBiz;
  if (bizPhoneLocal) bizPhoneLocal.required = isBiz;
  const bizType = document.getElementById('bizType');
  if (bizType) bizType.required = isBiz;
  const repSex = document.getElementById('repSex');
  if (repSex) repSex.required = isBiz;
  if (ownerFirst) ownerFirst.required = isBiz;
  if (ownerLast) ownerLast.required = isBiz;
  const ownerSex = document.getElementById('ownerSex');
  if (ownerSex) ownerSex.required = isBiz;
  const bizPermit = document.getElementById('bizPermit');
  if (bizPermit) bizPermit.required = isBiz;
  const bizAddrBarangay = document.getElementById('bizAddrBarangay');
  const bizAddrStreet = document.getElementById('bizAddrStreet');
  if (bizAddrBarangay) bizAddrBarangay.required = isBiz;
  if (bizAddrStreet) {
    bizAddrStreet.required = isBiz;
    bizAddrStreet.disabled = !isBiz;
  }
  if (isBiz && window.CulTourPhAddress && CulTourPhAddress.refresh) {
    CulTourPhAddress.refresh();
  }
  const needsEmergency = isTourist || v === '<?= ROLE_TOUR_GUIDE ?>';
  const emergencyFields = document.getElementById('emergencyFields');
  if (emergencyFields) emergencyFields.classList.toggle('d-none', !needsEmergency);
  const emergFirst = document.getElementById('emergFirst');
  const emergLast = document.getElementById('emergLast');
  const emergRelationship = document.getElementById('emergRelationship');
  const emergPhone = document.getElementById('emergPhone');
  if (emergFirst) emergFirst.required = needsEmergency;
  if (emergLast) emergLast.required = needsEmergency;
  if (emergPhone) emergPhone.required = needsEmergency;
  // Relationship uses custom picker; required checked on submit / server.
  const guideLang = document.getElementById('guideLanguages');
  if (guideLang) {
    guideLang.required = v === '<?= ROLE_TOUR_GUIDE ?>';
    guideLang.disabled = v !== '<?= ROLE_TOUR_GUIDE ?>';
    if (window.CulTourLangTags) CulTourLangTags.sync(guideLang);
  }
  const touristLangWrap = document.getElementById('touristLangWrap');
  const touristLang = document.getElementById('touristLanguages');
  if (touristLangWrap) touristLangWrap.classList.toggle('d-none', !isTourist);
  if (touristLang) {
    touristLang.required = isTourist;
    touristLang.disabled = !isTourist;
    if (window.CulTourLangTags) CulTourLangTags.sync(touristLang);
  }
  const personal = document.getElementById('personalFields');
  if (personal) personal.classList.toggle('d-none', isBiz);
  if (personal) {
    personal.querySelectorAll('[name="sex"], [name="date_of_birth"], [name="nationality"]').forEach(function (el) {
      el.required = !isBiz;
    });
  }
  const addrSimpleWrap = document.getElementById('addressSimple');
  const addrTouristWrap = document.getElementById('addressTourist');
  const permanentBlock = document.getElementById('permanentAddressBlock');
  const currentBlock = document.getElementById('currentAddressFields');
  const addrSimple = document.getElementById('addrSimple');
  const addrRegion = document.getElementById('addrRegion');
  const addrCity = document.getElementById('addrCity');
  const addrBarangay = document.getElementById('addrBarangay');
  const addrStreet = document.getElementById('addrStreet');
  const curAddrRegion = document.getElementById('curAddrRegion');
  const curAddrCity = document.getElementById('curAddrCity');
  const curAddrBarangay = document.getElementById('curAddrBarangay');
  const curAddrStreet = document.getElementById('curAddrStreet');
  const useStructured = (isTourist || isGuide) && !isBiz;
  const lockProv = '<?= e(ph_guide_locked_province()) ?>';
  const lockCity = '<?= e(ph_guide_locked_city()) ?>';
  if (addrSimpleWrap) addrSimpleWrap.setAttribute('hidden', '');
  if (addrTouristWrap) {
    addrTouristWrap.classList.toggle('d-none', !useStructured);
  }
  if (permanentBlock) {
    permanentBlock.setAttribute('data-ph-lock-province', '');
  }
  if (currentBlock) {
    currentBlock.setAttribute('data-ph-lock-province', isGuide ? lockProv : '');
    currentBlock.setAttribute('data-ph-lock-city', isGuide ? lockCity : '');
  }
  const sameWrap = document.getElementById('sameAsAddressWrap');
  if (sameWrap) {
    sameWrap.setAttribute('data-same-lock-province', isGuide ? lockProv : '');
    sameWrap.setAttribute('data-same-lock-city', isGuide ? lockCity : '');
  }
  const guideOrmocNote = document.getElementById('guideOrmocNote');
  if (guideOrmocNote) guideOrmocNote.classList.toggle('d-none', !isGuide);
  if (isGuide) {
    if (curAddrRegion) curAddrRegion.setAttribute('data-initial', lockProv);
    if (curAddrCity) curAddrCity.setAttribute('data-initial', lockCity);
  } else if (wasGuide) {
    [addrRegion, addrCity, addrBarangay, curAddrRegion, curAddrCity, curAddrBarangay].forEach(function (el) {
      if (!el) return;
      el.setAttribute('data-initial', '');
      el.value = '';
    });
  }
  if (!wasGuide) {
    if (addrCity) addrCity.setAttribute('data-initial', '');
    if (addrBarangay) addrBarangay.setAttribute('data-initial', '');
    if (curAddrCity) curAddrCity.setAttribute('data-initial', '');
    if (curAddrBarangay) curAddrBarangay.setAttribute('data-initial', '');
  }
  if (addrSimple) {
    addrSimple.required = false;
    addrSimple.disabled = true;
  }
  if (addrRegion) {
    addrRegion.required = useStructured;
    addrRegion.disabled = !useStructured;
  }
  if (addrCity) {
    addrCity.required = useStructured;
    if (!useStructured) addrCity.disabled = true;
  }
  if (addrBarangay) {
    addrBarangay.required = useStructured;
    if (!useStructured) addrBarangay.disabled = true;
  }
  if (addrStreet) {
    addrStreet.required = useStructured;
    addrStreet.disabled = !useStructured;
  }
  const useStructuredFlag = document.getElementById('useStructuredAddress');
  if (useStructuredFlag) {
    useStructuredFlag.value = useStructured ? '1' : '';
    useStructuredFlag.disabled = !useStructured;
  }
  if (window.syncCurrentAddressSame) window.syncCurrentAddressSame();
  document.querySelectorAll('#addressTourist input[name="current_same_as_permanent"]').forEach(function (el) {
    el.disabled = !useStructured;
  });
  if (useStructured && window.CulTourPhAddress && CulTourPhAddress.refresh) {
    CulTourPhAddress.refresh();
  }
  lastRole = v;
  if (window.syncFileRequired) window.syncFileRequired();
  if (window.syncTouristEmergencyDupWarn) window.syncTouristEmergencyDupWarn();
  if (window.syncRegisterLinkRole) window.syncRegisterLinkRole();
});

(function () {
  const GUIDE_LOCK_PROV = '<?= e(ph_guide_locked_province()) ?>';
  const GUIDE_LOCK_CITY = '<?= e(ph_guide_locked_city()) ?>';
  const sameYes = document.getElementById('addrSameYes');
  const addrRegion = document.getElementById('addrRegion');
  const addrCity = document.getElementById('addrCity');
  const roleEl = document.getElementById('roleSelect');
  const backdrop = document.getElementById('guideLeyteSameModal');
  const okBtn = document.getElementById('guideLeyteSameOk');

  function isGuideRole() {
    return roleEl && roleEl.value === '<?= ROLE_TOUR_GUIDE ?>';
  }

  function permanentProvince() {
    return addrRegion ? String(addrRegion.value || '').trim() : '';
  }

  function openLeyteSameModal() {
    if (!backdrop) return;
    backdrop.hidden = false;
    requestAnimationFrame(function () {
      backdrop.classList.add('is-open');
    });
    document.body.classList.add('login-role-open');
  }

  function closeLeyteSameModal() {
    if (!backdrop) return;
    backdrop.classList.remove('is-open');
    document.body.classList.remove('login-role-open');
    setTimeout(function () {
      if (!backdrop.classList.contains('is-open')) backdrop.hidden = true;
    }, 200);
  }

  function permanentCity() {
    return addrCity ? String(addrCity.value || '').trim() : '';
  }

  function guideSameAsNeedsLeyte() {
    if (!isGuideRole() || !sameYes || !sameYes.checked) return false;
    const prov = permanentProvince();
    const city = permanentCity();
    if (prov !== '' && prov.toLowerCase() !== GUIDE_LOCK_PROV.toLowerCase()) return true;
    return city !== '' && city.toLowerCase() !== GUIDE_LOCK_CITY.toLowerCase();
  }

  function enforceGuideSameAsLeyte(fromUserToggle) {
    if (!guideSameAsNeedsLeyte()) return false;
    if (sameYes) {
      sameYes.checked = false;
      if (window.syncCurrentAddressSame) window.syncCurrentAddressSame();
    }
    openLeyteSameModal();
    return true;
  }

  window.enforceGuideSameAsLeyte = enforceGuideSameAsLeyte;
  window.guideSameAsNeedsLeyte = guideSameAsNeedsLeyte;

  function syncCurrentAddressSame() {
    if (window.CulTourPhAddress && typeof CulTourPhAddress.applySameAs === 'function') {
      CulTourPhAddress.applySameAs();
    }
  }
  window.syncCurrentAddressSame = syncCurrentAddressSame;

  if (sameYes) {
    sameYes.addEventListener('change', function () {
      if (sameYes.checked && enforceGuideSameAsLeyte(true)) return;
      syncCurrentAddressSame();
    });
  }
  if (addrRegion) {
    addrRegion.addEventListener('change', function () {
      if (sameYes && sameYes.checked) enforceGuideSameAsLeyte(false);
    });
  }
  if (addrCity) {
    addrCity.addEventListener('change', function () {
      if (sameYes && sameYes.checked) enforceGuideSameAsLeyte(false);
    });
  }
  if (okBtn) okBtn.addEventListener('click', closeLeyteSameModal);
  if (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeLeyteSameModal();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop && backdrop.classList.contains('is-open')) {
      closeLeyteSameModal();
    }
  });
  syncCurrentAddressSame();
})();

(function () {
  function capFirst(el) {
    if (!el) return;
    const v = el.value;
    if (!v) return;
    el.value = v.charAt(0).toUpperCase() + v.slice(1);
  }
  document.querySelectorAll('.name-cap').forEach(function (el) {
    el.addEventListener('blur', function () { capFirst(el); });
    el.addEventListener('input', function () {
      if (el.value.length === 1) capFirst(el);
    });
  });

  function nameLettersLabel(input) {
    const n = (input && input.name) || '';
    if (/first_name$/.test(n) || n === 'first_name') return 'first name';
    if (/middle_name$/.test(n) || n === 'middle_name') return 'middle name';
    if (/last_name$/.test(n) || n === 'last_name') return 'last name';
    if (/suffix$/.test(n) || n === 'name_suffix') return 'suffix';
    if (n === 'emergency_relationship') return 'relationship';
    return 'name';
  }
  function nameLettersMessage(input) {
    const v = (input && input.value) || '';
    if (!v) return '';
    if (/\d/.test(v) || !/^[A-Za-zÀ-ÖØ-öø-ÿÑñ\s.'-]+$/.test(v)) {
      return 'Enter a valid ' + nameLettersLabel(input) + '.';
    }
    return '';
  }
  function bindNameLetters(input) {
    if (!input) return;
    const warnEl = input.parentElement && input.parentElement.querySelector('.name-letters-warn');
    function show(msg) {
      if (msg) {
        if (warnEl) {
          warnEl.textContent = msg;
          warnEl.classList.remove('d-none');
        }
        input.classList.add('is-invalid');
      } else {
        if (warnEl) {
          warnEl.textContent = '';
          warnEl.classList.add('d-none');
        }
        input.classList.remove('is-invalid');
      }
    }
    input.addEventListener('input', function () {
      show(nameLettersMessage(input));
    });
    input.addEventListener('blur', function () {
      show(nameLettersMessage(input));
    });
    input._showNameLettersWarn = show;
    input._nameLettersMessage = function () { return nameLettersMessage(input); };
  }
  document.querySelectorAll('.name-letters').forEach(bindNameLetters);

  document.querySelectorAll('.select-chevron > select').forEach(function (sel) {
    if (sel.getAttribute('data-chevron-bound') === '1') return;
    sel.setAttribute('data-chevron-bound', '1');
    const wrap = sel.closest('.select-chevron');
    if (!wrap) return;
    sel.addEventListener('mousedown', function () {
      wrap.classList.add('is-open');
    });
    sel.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
        wrap.classList.add('is-open');
      }
    });
    sel.addEventListener('blur', function () {
      wrap.classList.remove('is-open');
    });
    sel.addEventListener('change', function () {
      wrap.classList.remove('is-open');
    });
  });

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
    function selectValue(value) {
      if (!choice || !label) return;
      choice.value = value;
      picker.querySelectorAll('.suffix-picker__option').forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-value') === value);
      });
      if (value === 'other') {
        if (specify) specify.classList.remove('d-none');
        label.textContent = (other && other.value.trim()) ? other.value.trim() : 'Other';
        setOpen(true);
        if (other) setTimeout(function () { other.focus(); }, 0);
      } else {
        if (specify) specify.classList.add('d-none');
        if (other) {
          other.value = '';
          other.classList.remove('is-invalid');
        }
        label.textContent = labels[value] || emptyLabel;
        setOpen(false);
      }
    }

    if (toggle) {
      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (picker.classList.contains('is-disabled') || toggle.disabled) return;
        setOpen(menu.classList.contains('d-none'));
      });
    }
    picker.querySelectorAll('.suffix-picker__option').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        selectValue(btn.getAttribute('data-value') || '');
      });
    });
    if (other) {
      other.addEventListener('click', function (e) { e.stopPropagation(); });
      other.addEventListener('input', function () {
        if (choice && choice.value === 'other') {
          label.textContent = other.value.trim() || 'Other';
        }
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

  function emailMessage(v) {
    const email = (v || '').trim();
    if (!email) return '';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Enter a valid email.';
    return '';
  }
  const emailExistsUrl = <?= json_encode(APP_URL . '/api/auth/email_available.php') ?>;
  function bindEmailCheck(input) {
    if (!input) return;
    const warnEl = input.parentElement && input.parentElement.querySelector('.email-check-warn');
    const counterpartEl = document.getElementById('emailCounterpartWarn');
    let checkSeq = 0;
    function showCounterpart(msg) {
      if (!counterpartEl || input.id !== 'accountEmail') return;
      if (msg && !window.isRegisterLinked()) {
        counterpartEl.textContent = msg;
        counterpartEl.classList.remove('d-none');
      } else {
        counterpartEl.textContent = '';
        counterpartEl.classList.add('d-none');
      }
    }
    function show(msg) {
      if (msg) {
        if (warnEl) {
          warnEl.textContent = msg;
          warnEl.classList.remove('d-none');
        }
        input.classList.add('is-invalid');
      } else {
        if (warnEl) {
          warnEl.textContent = '';
          warnEl.classList.add('d-none');
        }
        input.classList.remove('is-invalid');
      }
    }
    function currentRoleId() {
      const roleEl = document.getElementById('roleSelect');
      return roleEl && roleEl.value ? roleEl.value : '<?= ROLE_TOURIST ?>';
    }
    function checkExists() {
      const formatMsg = emailMessage(input.value);
      if (formatMsg) {
        input._emailTaken = false;
        input._emailCounterpart = false;
        input._emailCounterpartLabel = '';
        input._emailCounterpartInLeyte = null;
        showCounterpart('');
        show(formatMsg);
        return;
      }
      const email = (input.value || '').trim().toLowerCase();
      if (!email) {
        input._emailTaken = false;
        input._emailCounterpart = false;
        input._emailCounterpartLabel = '';
        input._emailCounterpartInLeyte = null;
        showCounterpart('');
        show('');
        return;
      }
      const seq = ++checkSeq;
      const roleId = currentRoleId();
      fetch(emailExistsUrl + '?email=' + encodeURIComponent(email) + '&role_id=' + encodeURIComponent(roleId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (seq !== checkSeq) return;
          if ((input.value || '').trim().toLowerCase() !== email) return;
          if (String(currentRoleId()) !== String(roleId)) return;
          if (data && data.ok && data.available === false) {
            input._emailTaken = true;
            input._emailCounterpart = false;
            input._emailCounterpartLabel = '';
            input._emailCounterpartInLeyte = null;
            showCounterpart('');
            show((data.message && String(data.message)) || 'This email is already registered for this account type.');
          } else {
            input._emailTaken = false;
            show('');
            const counterpart = !!(data && data.counterpart_taken);
            input._emailCounterpart = counterpart;
            input._emailCounterpartLabel = counterpart
              ? String(data.counterpart_role_label || '')
              : '';
            input._emailCounterpartInLeyte = counterpart
              ? (data.counterpart_in_leyte === true)
              : null;
            showCounterpart(
              counterpart
                ? ((data.counterpart_message && String(data.counterpart_message))
                  || ('This email already exists as a ' + input._emailCounterpartLabel + '. You can link them.'))
                : ''
            );
          }
        })
        .catch(function () {
          if (seq !== checkSeq) return;
          input._emailTaken = false;
          input._emailCounterpart = false;
          input._emailCounterpartLabel = '';
          input._emailCounterpartInLeyte = null;
        });
    }
    input.addEventListener('blur', checkExists);
    input.addEventListener('input', function () {
      input._emailTaken = false;
      input._emailCounterpart = false;
      input._emailCounterpartLabel = '';
      input._emailCounterpartInLeyte = null;
      showCounterpart('');
      if (input.classList.contains('is-invalid') && !emailMessage(input.value)) {
        show('');
      }
    });
    input._showEmailWarn = show;
    input._showCounterpartWarn = showCounterpart;
    input._checkEmailExists = checkExists;
    input._emailMessage = function () {
      const formatMsg = emailMessage(input.value);
      if (formatMsg) return formatMsg;
      if (input._emailTaken) return (warnEl && warnEl.textContent) || 'This email is already registered for this account type.';
      return '';
    };
  }
  document.querySelectorAll('.email-check').forEach(bindEmailCheck);
  if (roleSelect) {
    roleSelect.addEventListener('change', function () {
      document.querySelectorAll('.email-check').forEach(function (el) {
        if (el._checkEmailExists && (el.value || '').trim()) el._checkEmailExists();
      });
    });
  }
  <?php if (!empty($error) && stripos((string) $error, 'email already') !== false): ?>
  (function () {
    const isBiz = <?= json_encode(((int) ($_POST['role_id'] ?? 0)) === ROLE_BUSINESS) ?>;
    const el = document.getElementById(isBiz ? 'bizEmail' : 'accountEmail');
    if (el && el._showEmailWarn) {
      el._emailTaken = true;
      el._showEmailWarn(<?= json_encode((string) $error) ?>);
    }
  })();
  <?php endif; ?>

  function phoneMessage(d, onBlur) {
    if (!d) return '';
    if (d.charAt(0) !== '9') return 'Enter a valid mobile number.';
    if (onBlur && d.length !== 10) return 'Phone number must be exactly 10 digits.';
    return '';
  }

  const phone = document.getElementById('phoneLocal');
  const phoneWarn = document.getElementById('phoneLocalWarn');
  const emergPhone = document.getElementById('emergPhone');
  const emergPhoneWarn = document.getElementById('emergPhoneWarn');
  const phoneExistsUrl = <?= json_encode(APP_URL . '/api/auth/phone_available.php') ?>;
  function bindPhoneField(input, warnEl, emailInputId, isBusinessPhone) {
    if (!input) return;
    let checkSeq = 0;
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
    function checkTaken() {
      if (!emailInputId) return;
      const d = String(input.value || '');
      if (d.length !== 10 || phoneMessage(d, true)) return;
      const emailEl = document.getElementById(emailInputId);
      const email = emailEl ? String(emailEl.value || '').trim().toLowerCase() : '';
      const seq = ++checkSeq;
      const phoneRole = isBusinessPhone ? '<?= ROLE_BUSINESS ?>' : '<?= ROLE_TOURIST ?>';
      fetch(phoneExistsUrl + '?phone=' + encodeURIComponent(d) + '&email=' + encodeURIComponent(email) + '&role_id=' + phoneRole, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (seq !== checkSeq || String(input.value || '') !== d) return;
          if (data && data.ok && data.available === false) {
            input._phoneTaken = true;
            show((data.message && String(data.message)) || 'This phone number is already used by another CulTour account.');
          } else {
            input._phoneTaken = false;
          }
        })
        .catch(function () {
          if (seq === checkSeq) input._phoneTaken = false;
        });
    }
    input.addEventListener('input', function () {
      let d = input.value.replace(/\D+/g, '');
      if (d.indexOf('63') === 0 && d.length > 10) d = d.slice(2);
      while (d.charAt(0) === '0') d = d.slice(1);
      input.value = d.slice(0, 10);
      input._phoneTaken = false;
      show(phoneMessage(input.value, false));
    });
    input.addEventListener('blur', function () {
      const msg = phoneMessage(input.value, true);
      show(msg);
      if (!msg) checkTaken();
    });
    if (emailInputId) {
      const emailEl = document.getElementById(emailInputId);
      if (emailEl) {
        emailEl.addEventListener('blur', function () {
          if (String(input.value || '').length === 10) checkTaken();
        });
      }
    }
    input._showPhoneWarn = show;
    input._checkPhoneTaken = checkTaken;
  }
  bindPhoneField(phone, phoneWarn, 'accountEmail');
  bindPhoneField(emergPhone, emergPhoneWarn);
  bindPhoneField(document.getElementById('bizPhoneLocal'), document.getElementById('bizPhoneWarn'), 'bizEmail', true);
  <?php if (!empty($error) && stripos((string) $error, 'phone number is already used') !== false): ?>
  (function () {
    const isBiz = <?= json_encode(((int) ($_POST['role_id'] ?? 0)) === ROLE_BUSINESS) ?>;
    const el = isBiz ? document.getElementById('bizPhoneLocal') : phone;
    if (el && el._showPhoneWarn) {
      el._phoneTaken = true;
      el._showPhoneWarn(<?= json_encode((string) $error) ?>);
    }
  })();
  <?php endif; ?>

  const emergDupWarn = document.getElementById('emergDupWarn');
  const emergSuffixNote = document.getElementById('emergSuffixNote');
  function suffixPickerValue(base) {
    const picker = document.querySelector('[data-suffix-base="' + base + '"]');
    if (!picker) return '';
    const choice = picker.querySelector('.suffix-picker__choice');
    const other = picker.querySelector('.suffix-picker__other');
    const v = choice ? choice.value : '';
    if (!v || v === 'none') return '';
    if (v === 'other') return other ? other.value.trim() : '';
    return v;
  }
  function joinNameKey(first, middle, last, suffix) {
    return [first, middle, last, suffix]
      .map(function (p) { return String(p || '').trim().replace(/\s+/g, ' '); })
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
  }
  function touristEmergencyNameParts() {
    const roleEl = document.getElementById('roleSelect');
    if (!roleEl || (roleEl.value !== '<?= ROLE_TOURIST ?>' && roleEl.value !== '<?= ROLE_TOUR_GUIDE ?>')) return null;
    const emergencyFields = document.getElementById('emergencyFields');
    if (!emergencyFields || emergencyFields.classList.contains('d-none')) return null;
    const selfFirst = document.getElementById('firstName');
    const selfLast = document.getElementById('lastName');
    const selfMiddle = document.querySelector('#stdAccountFields [name="middle_name"]');
    const emergFirstEl = document.getElementById('emergFirst');
    const emergLastEl = document.getElementById('emergLast');
    const emergMiddleEl = document.querySelector('#emergencyFields [name="emergency_middle_name"]');
    if (!selfFirst || !selfLast || !emergFirstEl || !emergLastEl || !phone || !emergPhone) return null;
    const selfSuffix = suffixPickerValue('name_suffix');
    const emergSuffix = suffixPickerValue('emergency_suffix');
    return {
      selfBase: joinNameKey(selfFirst.value, selfMiddle && selfMiddle.value, selfLast.value, ''),
      emergBase: joinNameKey(emergFirstEl.value, emergMiddleEl && emergMiddleEl.value, emergLastEl.value, ''),
      selfFull: joinNameKey(selfFirst.value, selfMiddle && selfMiddle.value, selfLast.value, selfSuffix),
      emergFull: joinNameKey(emergFirstEl.value, emergMiddleEl && emergMiddleEl.value, emergLastEl.value, emergSuffix),
      selfSuffix: selfSuffix,
      emergSuffix: emergSuffix,
      selfDigits: String(phone.value || '').replace(/\D+/g, ''),
      emergDigits: String(emergPhone.value || '').replace(/\D+/g, '')
    };
  }
  function touristEmergencyDupMessage() {
    const p = touristEmergencyNameParts();
    if (!p) return '';
    const sameFullName = p.selfFull !== '' && p.emergFull !== '' && p.selfFull === p.emergFull;
    const samePhone = p.selfDigits.length === 10 && p.emergDigits.length === 10 && p.selfDigits === p.emergDigits;
    if (sameFullName && samePhone) return 'Emergency contact cannot use your same name and phone number.';
    if (sameFullName) return 'Emergency contact cannot have the same name as you.';
    if (samePhone) return 'Emergency contact cannot use your same phone number.';
    return '';
  }
  function touristEmergencySuffixNote() {
    const p = touristEmergencyNameParts();
    if (!p) return '';
    if (p.selfBase === '' || p.emergBase === '' || p.selfBase !== p.emergBase) return '';
    const selfS = String(p.selfSuffix || '').toLowerCase();
    const emergS = String(p.emergSuffix || '').toLowerCase();
    if (selfS === emergS) return '';
    if (selfS && !emergS) {
      return 'Note: Emergency contact has the same name as you but without a suffix. You can still create your account.';
    }
    if (!selfS && emergS) {
      return 'Note: Emergency contact has the same name as you but with a suffix. You can still create your account.';
    }
    return 'Note: Your name and emergency contact name match except for the suffix. You can still create your account.';
  }
  function syncTouristEmergencyDupWarn() {
    const blockMsg = touristEmergencyDupMessage();
    if (emergDupWarn) {
      if (blockMsg) {
        emergDupWarn.textContent = blockMsg;
        emergDupWarn.classList.remove('d-none');
      } else {
        emergDupWarn.textContent = '';
        emergDupWarn.classList.add('d-none');
      }
    }
    if (emergSuffixNote) {
      const note = blockMsg ? '' : touristEmergencySuffixNote();
      if (note) {
        emergSuffixNote.textContent = note;
        emergSuffixNote.classList.remove('d-none');
      } else {
        emergSuffixNote.textContent = '';
        emergSuffixNote.classList.add('d-none');
      }
    }
    return blockMsg;
  }
  [
    document.getElementById('firstName'),
    document.getElementById('lastName'),
    document.querySelector('#stdAccountFields [name="middle_name"]'),
    document.getElementById('emergFirst'),
    document.getElementById('emergLast'),
    document.querySelector('#emergencyFields [name="emergency_middle_name"]'),
    phone,
    emergPhone
  ].forEach(function (el) {
    if (!el) return;
    el.addEventListener('input', syncTouristEmergencyDupWarn);
    el.addEventListener('blur', syncTouristEmergencyDupWarn);
    el.addEventListener('change', syncTouristEmergencyDupWarn);
  });
  document.querySelectorAll('[data-suffix-base="name_suffix"] .suffix-picker__choice, [data-suffix-base="emergency_suffix"] .suffix-picker__choice').forEach(function (el) {
    el.addEventListener('change', syncTouristEmergencyDupWarn);
  });
  document.querySelectorAll('[data-suffix-base="name_suffix"] .suffix-picker__other, [data-suffix-base="emergency_suffix"] .suffix-picker__other').forEach(function (el) {
    el.addEventListener('input', syncTouristEmergencyDupWarn);
    el.addEventListener('blur', syncTouristEmergencyDupWarn);
  });
  document.querySelectorAll('[data-suffix-base="name_suffix"] .suffix-picker__option, [data-suffix-base="emergency_suffix"] .suffix-picker__option').forEach(function (btn) {
    btn.addEventListener('click', function () {
      setTimeout(syncTouristEmergencyDupWarn, 0);
    });
  });
  syncTouristEmergencyDupWarn();
  window.syncTouristEmergencyDupWarn = syncTouristEmergencyDupWarn;

  const fileBags = {};

  function showFileWarn(id, show) {
    const warn = document.getElementById(id);
    if (!warn) return;
    warn.classList.toggle('d-none', !show);
  }

  function bindMultiFiles(input) {
    if (!input || !window.DataTransfer) return;
    const listId = input.getAttribute('data-list');
    const listEl = listId ? document.getElementById(listId) : null;
    const countEl = input.getAttribute('data-count') ? document.getElementById(input.getAttribute('data-count')) : null;
    const menuEl = input.getAttribute('data-menu') ? document.getElementById(input.getAttribute('data-menu')) : null;
    const toggleEl = input.getAttribute('data-toggle') ? document.getElementById(input.getAttribute('data-toggle')) : null;
    const emptyEl = menuEl ? menuEl.querySelector('.file-upload-field__empty') : null;
    const key = input.id || listId || input.name;
    fileBags[key] = [];

    function syncInput() {
      const dt = new DataTransfer();
      fileBags[key].forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
    }
    input._syncFiles = syncInput;

    function setMenuOpen(open) {
      if (!menuEl || !toggleEl) return;
      menuEl.classList.toggle('d-none', !open);
      menuEl.hidden = !open;
      toggleEl.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggleEl.classList.toggle('is-open', open);
    }

    function updateCount() {
      const n = fileBags[key].length;
      if (countEl) countEl.textContent = String(n);
      if (toggleEl) {
        toggleEl.disabled = false;
        toggleEl.title = n ? ('View ' + n + ' uploaded file' + (n === 1 ? '' : 's')) : 'View uploaded files';
      }
      if (emptyEl) emptyEl.classList.toggle('d-none', n > 0);
    }

    function render() {
      if (!listEl) return;
      listEl.innerHTML = '';
      fileBags[key].forEach(function (f, i) {
        const li = document.createElement('li');
        li.className = 'file-upload-field__item';
        const name = document.createElement('span');
        name.className = 'file-upload-field__name text-truncate';
        name.title = f.name;
        name.innerHTML = '<i class="bi bi-file-earmark" aria-hidden="true"></i> ';
        name.appendChild(document.createTextNode(f.name));
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-link text-danger p-0 file-upload-field__remove';
        btn.textContent = 'Remove';
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          fileBags[key].splice(i, 1);
          syncInput();
          render();
          const stillEmpty = fileBags[key].length === 0 && (input.id === 'bizDocs' || input.id === 'resumeFiles');
          input.classList.toggle('is-invalid', stillEmpty);
          const wrap = input.closest('[data-file-upload]');
          if (wrap) wrap.classList.toggle('is-invalid', stillEmpty);
          if (input.id === 'resumeFiles') showFileWarn('resumeFilesWarn', stillEmpty);
          if (input.id === 'bizDocs') showFileWarn('bizDocsWarn', stillEmpty);
        });
        li.appendChild(name);
        li.appendChild(btn);
        listEl.appendChild(li);
      });
      updateCount();
    }

    if (toggleEl) {
      toggleEl.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const open = menuEl && menuEl.classList.contains('d-none');
        setMenuOpen(!!open);
      });
    }

    input.addEventListener('change', function () {
      const incoming = Array.prototype.slice.call(input.files || []);
      incoming.forEach(function (f) {
        const k = f.name + ':' + f.size + ':' + f.lastModified;
        const exists = fileBags[key].some(function (x) {
          return (x.name + ':' + x.size + ':' + x.lastModified) === k;
        });
        if (!exists) fileBags[key].push(f);
      });
      syncInput();
      render();
      if (fileBags[key].length) setMenuOpen(true);
      input.classList.remove('is-invalid');
      const pickedWrap = input.closest('[data-file-upload]');
      if (pickedWrap) pickedWrap.classList.remove('is-invalid');
      if (input.id === 'resumeFiles') showFileWarn('resumeFilesWarn', false);
      if (input.id === 'bizDocs') showFileWarn('bizDocsWarn', false);
      try {
        input.value = '';
        syncInput();
      } catch (e) { /* ignore */ }
    });

    input._fileCount = function () {
      return (fileBags[key] || []).length;
    };
    updateCount();
  }
  document.querySelectorAll('.js-multi-files').forEach(bindMultiFiles);
  document.addEventListener('click', function (e) {
    document.querySelectorAll('[data-file-upload]').forEach(function (wrap) {
      if (wrap.contains(e.target)) return;
      const menu = wrap.querySelector('.file-upload-field__menu');
      const toggle = wrap.querySelector('.file-upload-field__edge');
      if (!menu || !toggle) return;
      menu.classList.add('d-none');
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.classList.remove('is-open');
    });
  });

  window.syncFileRequired = function syncFileRequired() {
    showFileWarn('resumeFilesWarn', false);
    showFileWarn('bizDocsWarn', false);
    const resume = document.getElementById('resumeFiles');
    const biz = document.getElementById('bizDocs');
    if (resume) resume.classList.remove('is-invalid');
    if (biz) biz.classList.remove('is-invalid');
  };
  syncFileRequired();

  const bizPhoneLocal = document.getElementById('bizPhoneLocal');

  const form = document.getElementById('registerForm');

  function reqAnchor(el) {
    return el.closest('.select-chevron') || el.closest('.phone-input, .ph-search-select, .password-field') || el;
  }
  function reqWarnFor(el, create) {
    const anchor = reqAnchor(el);
    let warn = anchor.nextElementSibling;
    if (warn && warn.classList.contains('ct-req-warn')) return warn;
    if (!create) return null;
    warn = document.createElement('div');
    warn.className = 'form-text text-danger ct-req-warn';
    anchor.insertAdjacentElement('afterend', warn);
    return warn;
  }
  function markReq(el, msg) {
    if (window.CulTourForms) {
      window.CulTourForms.showError(el, msg);
      const s = el.closest('.ph-search-select');
      const si = s ? s.querySelector('.ph-search-select__input') : null;
      if (si) si.classList.add('is-invalid');
      return;
    }
    el.classList.add('is-invalid');
    const search = el.closest('.ph-search-select');
    const searchInput = search ? search.querySelector('.ph-search-select__input') : null;
    if (searchInput) searchInput.classList.add('is-invalid');
    const warn = reqWarnFor(el, true);
    warn.textContent = msg;
    warn.classList.remove('d-none');
  }
  function clearReq(el) {
    if (window.CulTourForms) window.CulTourForms.clearError(el);
    el.classList.remove('is-invalid');
    const search = el.closest('.ph-search-select');
    const searchInput = search ? search.querySelector('.ph-search-select__input') : null;
    if (searchInput) searchInput.classList.remove('is-invalid');
    const warn = reqWarnFor(el, false);
    if (warn) warn.classList.add('d-none');
  }
  function fieldIsActive(el) {
    if (el.type === 'hidden' || el.type === 'file') return false;
    const waitingOnParent = el.tagName === 'SELECT' && el.required
      && (el.hasAttribute('data-ph-city') || el.hasAttribute('data-ph-barangay'))
      && !el.closest('fieldset[disabled]');
    if (el.disabled && !waitingOnParent) return false;
    return !el.closest('.d-none, [hidden]');
  }
  function requiredMessage(el) {
    const v = String(el.value || '').trim();
    const cf = window.CulTourForms;
    const label = cf ? cf.label(el) : '';
    if (v === '') {
      if (el.tagName === 'SELECT') return 'Choose an option for ' + (label || 'this field') + '.';
      return (label || 'This field') + ' is required.';
    }
    if (el.closest('.phone-input') && !/^9\d{9}$/.test(v)) {
      return 'Enter the 10-digit mobile number after +63, starting with 9 (for example 9171234567).';
    }
    if (el.type === 'email' && el.validity && el.validity.typeMismatch) return 'Enter a complete email address, for example name@gmail.com.';
    if (el.minLength > 0 && v.length < el.minLength) {
      return (label || 'This field') + ' needs at least ' + el.minLength + ' characters (you have ' + v.length + ').';
    }
    return '';
  }
  function validateRequiredFields() {
    let first = null;
    form.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (el) {
      if (!fieldIsActive(el)) {
        clearReq(el);
        return;
      }
      const msg = requiredMessage(el);
      if (msg) {
        markReq(el, msg);
        if (!first) first = el;
      } else {
        clearReq(el);
      }
    });
    return first;
  }

  if (form) {
    form.noValidate = true;
    ['input', 'change'].forEach(function (evt) {
      form.addEventListener(evt, function (e) {
        const el = e.target;
        if (!el || !el.matches || !el.matches('input[required], select[required], textarea[required]')) return;
        if (!el.classList.contains('is-invalid')) return;
        const msg = requiredMessage(el);
        if (!msg) clearReq(el);
        else markReq(el, msg);
      });
    });
    form.addEventListener('focusout', function (e) {
      const el = e.target;
      if (!el || !el.matches || !el.matches('input[required], select[required], textarea[required]')) return;
      if (el.getAttribute('data-ct-touched') !== '1' || !fieldIsActive(el)) return;
      const msg = requiredMessage(el);
      if (msg) markReq(el, msg);
      else if (el.classList.contains('is-invalid') && el.checkValidity()) clearReq(el);
    });
    form.addEventListener('submit', function (e) {
      document.querySelectorAll('.js-multi-files').forEach(function (input) {
        if (typeof input._syncFiles === 'function') input._syncFiles();
      });
      const roleNow = document.getElementById('roleSelect').value;
      const firstMissing = validateRequiredFields();
      const needFile = roleNow === '<?= ROLE_BUSINESS ?>' ? document.getElementById('bizDocs')
        : (roleNow === '<?= ROLE_TOUR_GUIDE ?>' ? document.getElementById('resumeFiles') : null);
      const fileMissing = needFile && !(needFile._fileCount && needFile._fileCount());
      if (fileMissing) {
        needFile.classList.add('is-invalid');
        const fw = needFile.closest('[data-file-upload]');
        if (fw) fw.classList.add('is-invalid');
        showFileWarn(needFile.id === 'bizDocs' ? 'bizDocsWarn' : 'resumeFilesWarn', true);
      }
      if (firstMissing || fileMissing) {
        e.preventDefault();
        const target = firstMissing ? reqAnchor(firstMissing) : (needFile.closest('[data-file-upload]') || needFile);
        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        if (firstMissing) {
          const search = firstMissing.closest('.ph-search-select');
          const focusEl = (search && search.querySelector('.ph-search-select__input')) || firstMissing;
          try { focusEl.focus({ preventScroll: true }); } catch (err) {}
        }
        return;
      }
      document.querySelectorAll('.name-cap').forEach(capFirst);
      const nameInputs = Array.prototype.slice.call(document.querySelectorAll('.name-letters'));
      for (let i = 0; i < nameInputs.length; i++) {
        const el = nameInputs[i];
        if (el.disabled || el.offsetParent === null) continue;
        const msg = el._nameLettersMessage ? el._nameLettersMessage() : '';
        if (msg) {
          e.preventDefault();
          if (el._showNameLettersWarn) el._showNameLettersWarn(msg);
          el.focus();
          return;
        }
      }
      const emailInputs = Array.prototype.slice.call(document.querySelectorAll('.email-check'));
      for (let i = 0; i < emailInputs.length; i++) {
        const el = emailInputs[i];
        if (el.disabled || el.offsetParent === null) continue;
        const msg = el._emailMessage ? el._emailMessage() : '';
        if (msg) {
          e.preventDefault();
          if (el._showEmailWarn) el._showEmailWarn(msg);
          el.focus();
          return;
        }
      }
      const suffixPickers = Array.prototype.slice.call(document.querySelectorAll('.suffix-picker'));
      for (let i = 0; i < suffixPickers.length; i++) {
        const picker = suffixPickers[i];
        if (picker.offsetParent === null) continue;
        const choice = picker.querySelector('.suffix-picker__choice');
        const other = picker.querySelector('.suffix-picker__other');
        const specify = picker.querySelector('.suffix-picker__specify');
        if (!choice || choice.value !== 'other') continue;
        if (other && !other.value.trim()) {
          e.preventDefault();
          if (specify) specify.classList.remove('d-none');
          const menu = picker.querySelector('.suffix-picker__menu');
          const toggle = picker.querySelector('.suffix-picker__toggle');
          if (menu) menu.classList.remove('d-none');
          if (toggle) toggle.setAttribute('aria-expanded', 'true');
          picker.classList.add('is-open');
          other.classList.add('is-invalid');
          const warn = picker.querySelector('.name-letters-warn');
          if (warn) {
            warn.textContent = 'Please specify.';
            warn.classList.remove('d-none');
          }
          other.focus();
          return;
        }
      }
      const role = document.getElementById('roleSelect').value;
      if (role === '<?= ROLE_TOUR_GUIDE ?>' && window.guideSameAsNeedsLeyte && window.guideSameAsNeedsLeyte()) {
        e.preventDefault();
        if (window.enforceGuideSameAsLeyte) window.enforceGuideSameAsLeyte(true);
        return;
      }
      const accEmailEl = document.getElementById('accountEmail');
      if (
        (role === '<?= ROLE_TOURIST ?>' || role === '<?= ROLE_TOUR_GUIDE ?>')
        && accEmailEl
        && accEmailEl._emailCounterpart
        && !window.isRegisterLinked()
      ) {
        e.preventDefault();
        // Tourist → Guide: only link when that tourist currently resides in Leyte.
        if (role === '<?= ROLE_TOUR_GUIDE ?>' && accEmailEl._emailCounterpartInLeyte === false) {
          if (window.openRegisterLeyteBlock) window.openRegisterLeyteBlock();
        } else if (window.openRegisterLinkPrompt) {
          window.openRegisterLinkPrompt(accEmailEl._emailCounterpartLabel || '');
        }
        return;
      }
      if (role === '<?= ROLE_TOURIST ?>' || role === '<?= ROLE_TOUR_GUIDE ?>') {
        const relPicker = document.querySelector('[data-suffix-base="emergency_relationship"]');
        if (relPicker && relPicker.offsetParent !== null) {
          const choice = relPicker.querySelector('.suffix-picker__choice');
          const other = relPicker.querySelector('.suffix-picker__other');
          const val = choice ? choice.value : '';
          if (!val || (val === 'other' && other && !other.value.trim())) {
            e.preventDefault();
            const menu = relPicker.querySelector('.suffix-picker__menu');
            const toggle = relPicker.querySelector('.suffix-picker__toggle');
            const specify = relPicker.querySelector('.suffix-picker__specify');
            if (menu) menu.classList.remove('d-none');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
            relPicker.classList.add('is-open');
            if (val === 'other' && other) {
              if (specify) specify.classList.remove('d-none');
              other.classList.add('is-invalid');
              other.focus();
            } else if (toggle) {
              toggle.focus();
            }
            return;
          }
        }
      }
      if (role !== '<?= ROLE_BUSINESS ?>' && phone) {
        const msg = phoneMessage(phone.value, true);
        if (msg) {
          e.preventDefault();
          if (phone._showPhoneWarn) phone._showPhoneWarn(msg);
          phone.focus();
          return;
        }
        if (phone._phoneTaken && !window.isRegisterLinked()) {
          e.preventDefault();
          if (phone._showPhoneWarn) phone._showPhoneWarn('This phone number is already used by another CulTour account. Use a different number, or sign in to that account.');
          phone.focus();
          return;
        }
      }
      if ((role === '<?= ROLE_TOURIST ?>' || role === '<?= ROLE_TOUR_GUIDE ?>') && emergPhone) {
        const emsg = phoneMessage(emergPhone.value, true);
        if (emsg) {
          e.preventDefault();
          if (emergPhone._showPhoneWarn) emergPhone._showPhoneWarn(emsg);
          emergPhone.focus();
          return;
        }
        const dupMsg = syncTouristEmergencyDupWarn();
        if (dupMsg) {
          e.preventDefault();
          if (emergDupWarn) emergDupWarn.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          const emergFirstEl = document.getElementById('emergFirst');
          if (dupMsg.indexOf('phone') !== -1 && emergPhone) emergPhone.focus();
          else if (emergFirstEl) emergFirstEl.focus();
          return;
        }
      }
      if (role === '<?= ROLE_BUSINESS ?>' && bizPhoneLocal) {
        const bmsg = phoneMessage(bizPhoneLocal.value, true);
        if (bmsg) {
          e.preventDefault();
          if (bizPhoneLocal._showPhoneWarn) bizPhoneLocal._showPhoneWarn(bmsg);
          bizPhoneLocal.focus();
          return;
        }
        if (bizPhoneLocal._phoneTaken) {
          e.preventDefault();
          if (bizPhoneLocal._showPhoneWarn) bizPhoneLocal._showPhoneWarn('This phone number is already used by another CulTour account. Use a different number, or sign in to that account.');
          bizPhoneLocal.focus();
          return;
        }
      }
      const resume = document.getElementById('resumeFiles');
      const biz = document.getElementById('bizDocs');
      if (role === '<?= ROLE_TOUR_GUIDE ?>' && resume && !(resume._fileCount && resume._fileCount())) {
        e.preventDefault();
        resume.classList.add('is-invalid');
        const resumeWrap = resume.closest('[data-file-upload]');
        if (resumeWrap) resumeWrap.classList.add('is-invalid');
        showFileWarn('resumeFilesWarn', true);
        (resumeWrap || resume).scrollIntoView({ block: 'center', behavior: 'smooth' });
        return;
      }
      if (role === '<?= ROLE_BUSINESS ?>' && biz && !(biz._fileCount && biz._fileCount())) {
        e.preventDefault();
        biz.classList.add('is-invalid');
        const bizWrap = biz.closest('[data-file-upload]');
        if (bizWrap) bizWrap.classList.add('is-invalid');
        showFileWarn('bizDocsWarn', true);
        (bizWrap || biz).scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    });
  }
})();
</script>
<script src="<?= APP_URL ?>/assets/js/ph-address.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time() ?>"></script>
<script>
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('<?= APP_URL ?>/assets/data/ph-address.json');
  }
</script>
<script>
(function () {
  const ROLE_TOURIST = '<?= ROLE_TOURIST ?>';
  const ROLE_GUIDE = '<?= ROLE_TOUR_GUIDE ?>';
  const serverHandoff = <?= json_encode($pendingLinkClient, JSON_UNESCAPED_SLASHES) ?>;
  const stashClearUrl = <?= json_encode(APP_URL . '/api/auth/stash_link.php') ?>;
  const csrfToken = <?= json_encode(csrf_token()) ?>;
  const wrap = document.getElementById('linkAccountWrap');
  const banner = document.getElementById('linkAccountBanner');
  const bannerRole = document.getElementById('linkAccountBannerRole');
  const bannerEmail = document.getElementById('linkAccountBannerEmail');
  const sourceInput = document.getElementById('linkSourceUserId');
  const tokenInput = document.getElementById('linkToken');
  const clearBtn = document.getElementById('linkAccountClear');
  const roleEl = document.getElementById('roleSelect');
  const passwordFields = document.getElementById('passwordFields');
  const passwordDivider = document.getElementById('passwordDivider');
  const password = document.getElementById('password');
  const passwordConfirm = document.getElementById('passwordConfirm') || document.getElementById('password_confirm');

  function currentRole() {
    return roleEl ? String(roleEl.value || '') : ROLE_TOURIST;
  }
  function counterpartLabel(role) {
    return role === ROLE_GUIDE ? 'Tourist' : 'Tour Guide';
  }
  function isLinked() {
    return !!(tokenInput && String(tokenInput.value || '').trim());
  }
  window.isRegisterLinked = isLinked;

  function setSuffix(base, value) {
    const picker = document.querySelector('[data-suffix-base="' + base + '"]');
    if (!picker) return;
    const choice = picker.querySelector('.suffix-picker__choice');
    const label = picker.querySelector('.suffix-picker__label');
    const specify = picker.querySelector('.suffix-picker__specify');
    const other = picker.querySelector('.suffix-picker__other');
    const emptyLabel = picker.getAttribute('data-empty-label') || 'None';
    const v = String(value || '').trim();
    const known = {};
    picker.querySelectorAll('.suffix-picker__option').forEach(function (opt) {
      const dv = opt.getAttribute('data-value') || '';
      if (dv && dv !== 'other') known[dv] = (opt.textContent || '').trim();
    });
    let selected = Object.prototype.hasOwnProperty.call(known, 'none') ? 'none' : '';
    if (v && known[v]) selected = v;
    else if (v) selected = 'other';
    if (choice) choice.value = selected;
    picker.querySelectorAll('.suffix-picker__option').forEach(function (opt) {
      opt.classList.toggle('is-active', opt.getAttribute('data-value') === selected);
    });
    if (selected === 'other') {
      if (specify) specify.classList.remove('d-none');
      if (other) other.value = v;
      if (label) label.textContent = v || 'Other';
    } else {
      if (specify) specify.classList.add('d-none');
      if (other) other.value = '';
      if (label) label.textContent = known[selected] || emptyLabel;
    }
  }

  function setVal(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = value == null ? '' : String(value);
  }
  function setSelectInitial(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    const v = value == null ? '' : String(value);
    el.setAttribute('data-initial', v);
    el.value = v;
  }
  function setLinkedLock(on) {
    ['firstName', 'lastName', 'accountEmail', 'phoneLocal'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.readOnly = !!on;
    });
    const middle = document.querySelector('#stdAccountFields [name="middle_name"]');
    if (middle) middle.readOnly = !!on;
    const sex = document.querySelector('#personalFields [name="sex"]');
    const dob = document.querySelector('#personalFields [name="date_of_birth"]');
    const nat = document.querySelector('#personalFields [name="nationality"]');
    const touristLang = document.getElementById('touristLanguages');
    [sex, dob, nat].forEach(function (el) {
      if (el) el.disabled = false;
      if (el && el.tagName === 'SELECT') el.toggleAttribute('data-linked-lock', !!on);
    });
    if (touristLang) {
      touristLang.readOnly = !!on;
      if (window.CulTourLangTags) CulTourLangTags.sync(touristLang);
    }
    const suffix = document.querySelector('[data-suffix-base="name_suffix"]');
    if (suffix) suffix.classList.toggle('is-disabled', !!on);
    const toggle = suffix && suffix.querySelector('.suffix-picker__toggle');
    if (toggle) toggle.disabled = !!on;
  }
  function setPasswordVisible(show) {
    if (passwordFields) passwordFields.classList.toggle('d-none', !show);
    if (passwordDivider) {
      passwordDivider.classList.toggle('d-none', !show || currentRole() !== ROLE_TOURIST);
    }
    if (password) password.required = !!show;
    if (passwordConfirm) passwordConfirm.required = !!show;
  }

  function showBanner(on, roleLabel, email) {
    if (wrap) wrap.classList.toggle('d-none', !on);
    if (!banner) return;
    banner.classList.toggle('d-none', !on);
    if (bannerRole && roleLabel) bannerRole.textContent = roleLabel;
    if (bannerEmail && email) bannerEmail.textContent = email;
  }

  function unlink(resetFields) {
    if (sourceInput) sourceInput.value = '';
    if (tokenInput) tokenInput.value = '';
    showBanner(false);
    setLinkedLock(false);
    setPasswordVisible(true);
    clearMissingMarks();
    if (resetFields) {
      if (password) password.value = '';
      if (passwordConfirm) passwordConfirm.value = '';
    }
    try { sessionStorage.removeItem('cultour_registration_link'); } catch (err) {}
    fetch(stashClearUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ csrf_token: csrfToken, action: 'clear' })
    }).catch(function () {});
  }

  function clearMissingMarks() {
    document.querySelectorAll('.register-link-needed').forEach(function (el) {
      el.classList.remove('register-link-needed');
    });
    const missEl = document.getElementById('linkAccountBannerMissing');
    if (missEl) missEl.textContent = '';
  }

  function markMissing(items) {
    clearMissingMarks();
    const list = Array.isArray(items) ? items : [];
    const keys = list.map(function (item) {
      return item && item.key ? String(item.key) : '';
    }).filter(Boolean);
    if (!keys.length) return;
    document.querySelectorAll('[data-link-gap]').forEach(function (el) {
      const gaps = String(el.getAttribute('data-link-gap') || '').split(/\s+/);
      el.classList.toggle('register-link-needed', keys.some(function (k) { return gaps.indexOf(k) !== -1; }));
    });
    const missEl = document.getElementById('linkAccountBannerMissing');
    if (missEl) {
      missEl.textContent = ' Still needed: ' + list.map(function (item) {
        return (item && item.label) || String(item || '');
      }).filter(Boolean).join(', ') + '.';
    }
    const first = document.querySelector('.register-link-needed');
    if (first) {
      window.setTimeout(function () {
        first.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }, 280);
    }
  }

  function applyIdentity(ident, opts) {
    opts = opts || {};
    if (!ident) return;
    setVal('firstName', ident.first_name);
    setVal('lastName', ident.last_name);
    const middle = document.querySelector('#stdAccountFields [name="middle_name"]');
    if (middle) middle.value = ident.middle_name || '';
    setSuffix('name_suffix', ident.name_suffix);
    setVal('accountEmail', ident.email || '');
    const accEmail = document.getElementById('accountEmail');
    if (accEmail && ident.email) accEmail.value = ident.email;
    setVal('phoneLocal', ident.phone_local);
    const sex = document.querySelector('#personalFields [name="sex"]');
    if (sex && ident.sex) sex.value = ident.sex;
    const dob = document.querySelector('#personalFields [name="date_of_birth"]');
    if (dob) dob.value = ident.date_of_birth || '';
    const nat = document.querySelector('#personalFields [name="nationality"]');
    if (nat) nat.value = ident.nationality || '';
    const touristLang = document.getElementById('touristLanguages');
    if (touristLang && (ident.languages || ident.tourist_languages)) {
      touristLang.value = ident.languages || ident.tourist_languages || '';
      if (window.CulTourLangTags) CulTourLangTags.load(touristLang);
    }
    const guideLang = document.getElementById('guideLanguages');
    if (guideLang && ident.languages && !guideLang.value) {
      guideLang.value = ident.languages;
      if (window.CulTourLangTags) CulTourLangTags.load(guideLang);
    }
    setSelectInitial('addrRegion', ident.address_region);
    setSelectInitial('addrCity', ident.address_city);
    setSelectInitial('addrBarangay', ident.address_barangay);
    setVal('addrStreet', ident.address_street);
    setSelectInitial('curAddrRegion', ident.current_address_region);
    setSelectInitial('curAddrCity', ident.current_address_city);
    setSelectInitial('curAddrBarangay', ident.current_address_barangay);
    setVal('curAddrStreet', ident.current_address_street);
    const sameYes = document.getElementById('addrSameYes');
    if (sameYes) {
      sameYes.checked = !!ident.current_same_as_permanent;
      if (window.syncCurrentAddressSame) window.syncCurrentAddressSame();
    }
    setVal('emergFirst', ident.emergency_first_name);
    const emergMiddle = document.querySelector('#emergencyFields [name="emergency_middle_name"]');
    if (emergMiddle) emergMiddle.value = ident.emergency_middle_name || '';
    setVal('emergLast', ident.emergency_last_name);
    setSuffix('emergency_suffix', ident.emergency_suffix);
    setSuffix('emergency_relationship', ident.emergency_relationship || ident.emergency_relationship_choice);
    setVal('emergPhone', ident.emergency_phone_local);
    if (window.CulTourPhAddress && CulTourPhAddress.refresh) {
      CulTourPhAddress.refresh();
    }
    if (currentRole() === ROLE_GUIDE && window.guideSameAsNeedsLeyte && window.guideSameAsNeedsLeyte()) {
      const sameYesEl = document.getElementById('addrSameYes');
      if (sameYesEl) {
        sameYesEl.checked = false;
        if (window.syncCurrentAddressSame) window.syncCurrentAddressSame();
      }
      if (!opts.skipLeyteModal && window.enforceGuideSameAsLeyte) {
        window.enforceGuideSameAsLeyte(false);
      }
    }
    if (window.syncTouristEmergencyDupWarn) window.syncTouristEmergencyDupWarn();
  }

  let linkBoundRole = currentRole();
  function syncRole() {
    const role = currentRole();
    const canLink = role === ROLE_TOURIST || role === ROLE_GUIDE;
    if (isLinked() && linkBoundRole && linkBoundRole !== role) {
      unlink(false);
    }
    linkBoundRole = role;
    if (!canLink && isLinked()) unlink(false);
    else if (isLinked()) {
      setLinkedLock(true);
      setPasswordVisible(false);
      showBanner(true);
    } else if (wrap) {
      wrap.classList.add('d-none');
    }
  }
  window.syncRegisterLinkRole = syncRole;

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      unlink(true);
    });
  }

  function consumeLoginHandoff() {
    let pending = serverHandoff;
    if (!pending || !pending.token) {
      try {
        const raw = sessionStorage.getItem('cultour_registration_link');
        if (raw) pending = JSON.parse(raw);
      } catch (err) {
        pending = null;
      }
    }
    try { sessionStorage.removeItem('cultour_registration_link'); } catch (err) {}
    if (!pending || !pending.token || !pending.ident) return;
    applyRegisterLinkHandoff(pending);
  }

  function applyRegisterLinkHandoff(pending) {
    if (!pending || !pending.token || !pending.ident) return false;
    const role = String(pending.role || '');
    if (role && role !== ROLE_TOURIST && role !== ROLE_GUIDE) return false;
    if (role && roleEl && String(roleEl.value) !== role) {
      roleEl.value = role;
      roleEl.dispatchEvent(new Event('change'));
    }
    if (sourceInput) sourceInput.value = String(pending.source_user_id || '');
    if (tokenInput) tokenInput.value = String(pending.token || '');
    linkBoundRole = role || currentRole();
    applyIdentity(pending.ident, { skipLeyteModal: true });
    showBanner(true, pending.source_role_label || counterpartLabel(linkBoundRole), pending.email || pending.ident.email || '');
    setLinkedLock(true);
    setPasswordVisible(false);
    markMissing(pending.missing || []);
    const accEmail = document.getElementById('accountEmail');
    if (accEmail) {
      accEmail._emailCounterpart = false;
      accEmail._emailCounterpartLabel = '';
      if (accEmail._showCounterpartWarn) accEmail._showCounterpartWarn('');
    }
    return true;
  }
  window.applyRegisterLinkHandoff = applyRegisterLinkHandoff;

  consumeLoginHandoff();
  syncRole();
})();
</script>
<script>
(function () {
  const ROLE_TOURIST = '<?= ROLE_TOURIST ?>';
  const ROLE_GUIDE = '<?= ROLE_TOUR_GUIDE ?>';
  const csrf = <?= json_encode(csrf_token()) ?>;
  const linkUrl = <?= json_encode(APP_URL . '/api/auth/link_account.php') ?>;
  const stashUrl = <?= json_encode(APP_URL . '/api/auth/stash_link.php') ?>;
  const promptModal = document.getElementById('registerLinkPromptModal');
  const leyteBlockModal = document.getElementById('registerLeyteBlockModal');
  const signInModal = document.getElementById('registerLinkSignInModal');
  const promptBody = document.getElementById('registerLinkPromptBody');
  const promptLink = document.getElementById('registerLinkPromptLink');
  const promptCancel = document.getElementById('registerLinkPromptCancel');
  const leyteBlockClose = document.getElementById('registerLeyteBlockClose');
  const signInClose = document.getElementById('registerLinkSignInClose');
  const signInCancel = document.getElementById('registerLinkSignInCancel');
  const signInContinue = document.getElementById('registerLinkSignInContinue');
  const signInEmail = document.getElementById('registerLinkSignInEmail');
  const signInPassword = document.getElementById('registerLinkSignInPassword');
  const signInError = document.getElementById('registerLinkSignInError');
  const signInTitle = document.getElementById('registerLinkSignInTitle');
  const signInHint = document.getElementById('registerLinkSignInHint');
  const roleEl = document.getElementById('roleSelect');

  function roleLabel(role) {
    return String(role) === ROLE_GUIDE ? 'Tour Guide' : 'Tourist';
  }
  function sourceLabel(role) {
    return String(role) === ROLE_GUIDE ? 'Tourist' : 'Tour Guide';
  }
  function openBackdrop(el) {
    if (!el) return;
    el.hidden = false;
    requestAnimationFrame(function () { el.classList.add('is-open'); });
    document.body.classList.add('login-role-open');
  }
  function closeBackdrop(el) {
    if (!el) return;
    el.classList.remove('is-open');
    setTimeout(function () {
      if (!el.classList.contains('is-open')) el.hidden = true;
      if (
        (!promptModal || !promptModal.classList.contains('is-open'))
        && (!leyteBlockModal || !leyteBlockModal.classList.contains('is-open'))
        && (!signInModal || !signInModal.classList.contains('is-open'))
        && (!document.getElementById('guideLeyteSameModal') || !document.getElementById('guideLeyteSameModal').classList.contains('is-open'))
      ) {
        document.body.classList.remove('login-role-open');
      }
    }, 200);
  }
  function showSignInError(msg) {
    if (!signInError) return;
    if (msg) {
      signInError.textContent = msg;
      signInError.classList.remove('d-none');
    } else {
      signInError.textContent = '';
      signInError.classList.add('d-none');
    }
  }

  window.openRegisterLinkPrompt = function (counterpartLabel) {
    const role = roleEl ? String(roleEl.value || '') : ROLE_TOURIST;
    const from = counterpartLabel || sourceLabel(role);
    const to = roleLabel(role);
    if (promptBody) {
      promptBody.textContent = 'This email already exists as a ' + from
        + '. Link that account to register as ' + to
        + ', or cancel and use a different email.';
    }
    openBackdrop(promptModal);
  };

  window.openRegisterLeyteBlock = function () {
    openBackdrop(leyteBlockModal);
  };

  function openSignIn() {
    const role = roleEl ? String(roleEl.value || '') : ROLE_TOURIST;
    const emailEl = document.getElementById('accountEmail');
    const email = emailEl ? String(emailEl.value || '').trim() : '';
    showSignInError('');
    if (signInPassword) signInPassword.value = '';
    if (signInEmail) signInEmail.value = email;
    if (signInTitle) signInTitle.textContent = 'Sign in to your ' + sourceLabel(role) + ' account';
    if (signInHint) {
      signInHint.textContent = role === ROLE_GUIDE
        ? 'We’ll reuse your Tourist details. You still need an Ormoc City current address, languages, and credentials.'
        : 'We’ll reuse your Tour Guide details. Tourist validations still apply, including emergency contact.';
    }
    closeBackdrop(promptModal);
    openBackdrop(signInModal);
    if (signInPassword) signInPassword.focus();
  }

  if (promptLink) promptLink.addEventListener('click', openSignIn);
  if (promptCancel) promptCancel.addEventListener('click', function () { closeBackdrop(promptModal); });
  if (leyteBlockClose) leyteBlockClose.addEventListener('click', function () { closeBackdrop(leyteBlockModal); });
  if (signInClose) signInClose.addEventListener('click', function () { closeBackdrop(signInModal); });
  if (signInCancel) signInCancel.addEventListener('click', function () { closeBackdrop(signInModal); });
  if (promptModal) {
    promptModal.addEventListener('click', function (e) {
      if (e.target === promptModal) closeBackdrop(promptModal);
    });
  }
  if (leyteBlockModal) {
    leyteBlockModal.addEventListener('click', function (e) {
      if (e.target === leyteBlockModal) closeBackdrop(leyteBlockModal);
    });
  }
  if (signInModal) {
    signInModal.addEventListener('click', function (e) {
      if (e.target === signInModal) closeBackdrop(signInModal);
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (signInModal && signInModal.classList.contains('is-open')) closeBackdrop(signInModal);
    else if (leyteBlockModal && leyteBlockModal.classList.contains('is-open')) closeBackdrop(leyteBlockModal);
    else if (promptModal && promptModal.classList.contains('is-open')) closeBackdrop(promptModal);
  });

  if (signInContinue) {
    signInContinue.addEventListener('click', function () {
      const role = roleEl ? String(roleEl.value || '') : '';
      if (role !== ROLE_TOURIST && role !== ROLE_GUIDE) return;
      const email = signInEmail ? String(signInEmail.value || '').trim() : '';
      const passwordVal = signInPassword ? String(signInPassword.value || '') : '';
      showSignInError('');
      signInContinue.disabled = true;
      fetch(linkUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          csrf_token: csrf,
          email: email,
          password: passwordVal,
          target_role_id: Number(role)
        })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          signInContinue.disabled = false;
          if (!data || !data.ok) {
            showSignInError((data && data.message) || 'Could not link that account.');
            return;
          }
          const ident = data.identity || {};
          ident.email = data.email || ident.email || email;
          const handoff = {
            role: role,
            token: data.token || '',
            source_user_id: data.source_user_id || '',
            email: data.email || email,
            source_role_label: data.source_role_label || sourceLabel(role),
            ident: ident,
            missing: Array.isArray(data.missing) ? data.missing : []
          };
          fetch(stashUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
              csrf_token: csrf,
              action: 'stash',
              token: handoff.token,
              source_user_id: handoff.source_user_id,
              target_role_id: Number(role),
              complete: false
            })
          }).catch(function () {});
          if (window.applyRegisterLinkHandoff) window.applyRegisterLinkHandoff(handoff);
          closeBackdrop(signInModal);
          const banner = document.getElementById('linkAccountBanner');
          if (banner) banner.scrollIntoView({ block: 'center', behavior: 'smooth' });
        })
        .catch(function () {
          signInContinue.disabled = false;
          showSignInError('Could not reach CulTour. Try again.');
        });
    });
  }
})();
</script>
<?= password_rules_focus_script('password', 'passwordRules') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
