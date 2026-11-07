<?php
/**
 * Shared listing fields for admin add/edit (matches business registration).
 * Expects: $types, $listing (assoc row or []), $repParts, $ownerParts.
 * Optional: $listingFieldsPaired — email/address/permit sit to the right of the name rows.
 * Optional: $listingHideLogo — omit the Business Logo uploader (admin add).
 * Optional: $listingMediaOptional — mark logo as optional.
 */
$listing = is_array($listing ?? null) ? $listing : [];
$repParts = is_array($repParts ?? null) ? $repParts : ['first' => '', 'middle' => '', 'last' => '', 'suffix' => ''];
$ownerParts = is_array($ownerParts ?? null) ? $ownerParts : ['first' => '', 'middle' => '', 'last' => '', 'suffix' => ''];
$repSuffix = (string) ($repParts['suffix'] ?? '');
$ownerSuffix = (string) ($ownerParts['suffix'] ?? '');
$bizBarangay = trim((string) ($listing['address_barangay'] ?? ''));
$bizStreet = trim((string) ($listing['address_street'] ?? $_POST['address_street'] ?? ''));
$bizLegacyAddr = trim((string) ($listing['address'] ?? ''));
$bizHasStructured = $bizBarangay !== '';
if (!$bizHasStructured) {
    $bizBarangay = trim((string) ($_POST['address_barangay'] ?? ''));
}
$listingFieldsPaired = !empty($listingFieldsPaired);
$listingHideLogo = !empty($listingHideLogo);
$listingMediaOptional = !empty($listingMediaOptional);
$listingLogoPath = trim((string) ($listing['logo'] ?? ''));
$listingLogoUrl = $listingLogoPath !== '' ? (APP_URL . '/uploads/' . ltrim($listingLogoPath, '/')) : '';
$listingPhoneLocal = ph_phone_local_digits((string) ($listing['phone'] ?? ''));
$ownerSexSelected = (string) ($listing['owner_sex'] ?? '');
$repSexSelected = (string) ($listing['representative_sex'] ?? '');
$listingSexField = static function (string $name, string $selected): void {
    ?>
    <div class="admin-biz-paired__gender">
      <label class="form-label">Gender</label>
      <select class="form-select" name="<?= e($name) ?>" required>
        <option value="" disabled <?= $selected === '' ? 'selected' : '' ?>>Select</option>
        <?php foreach (user_sex_options() as $val => $label): ?>
          <option value="<?= e($val) ?>" <?= $selected === $val ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
};
?>
<?php if ($listingFieldsPaired): ?>
<div class="col-12">
  <div class="admin-biz-paired">
    <?php if (!$listingHideLogo): ?>
    <div class="admin-biz-paired__logo<?= !empty($listingMarkLogo) ? ' is-incomplete' : '' ?>">
      <label class="form-label">Business Logo<?php if ($listingMediaOptional): ?> <span class="text-muted fw-normal">(optional)</span><?php endif; ?></label>
      <div class="avatar-preview" data-avatar-preview>
        <div class="avatar-preview__frame" data-avatar-frame>
          <?php if ($listingLogoUrl): ?>
            <img src="<?= e($listingLogoUrl) ?>" alt="" class="avatar-preview__img">
          <?php else: ?>
            No photo
          <?php endif; ?>
        </div>
        <div class="avatar-preview__fields">
          <input type="file" class="form-control" name="logo" data-avatar-input accept="image/jpeg,image/png,image/gif,image/webp">
          <div class="form-text"><?= $listingMediaOptional ? 'Optional. ' : '' ?>Shown on the business listing. JPG, PNG, GIF, or WebP — preview updates when you pick a file.</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <div class="admin-biz-paired__block">
      <div class="admin-biz-paired__title">Business owner name</div>
      <div class="admin-biz-paired__fields admin-biz-paired__fields--names">
        <div>
          <label class="form-label">First name</label>
          <input class="form-control name-cap" name="owner_first_name" id="listingOwnerFirst" required value="<?= e(capitalize_name((string) $ownerParts['first'])) ?>">
        </div>
        <div>
          <label class="form-label">Middle name</label>
          <input class="form-control name-cap" name="owner_middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) $ownerParts['middle'])) ?>">
        </div>
        <div>
          <label class="form-label">Last name</label>
          <input class="form-control name-cap" name="owner_last_name" id="listingOwnerLast" required value="<?= e(capitalize_name((string) $ownerParts['last'])) ?>">
        </div>
        <div class="admin-biz-paired__suffix">
          <label class="form-label">Suffix</label>
          <?= name_suffix_field_html('owner_suffix', $ownerSuffix) ?>
        </div>
        <?php $listingSexField('owner_sex', $ownerSexSelected); ?>
      </div>
    </div>

    <div class="admin-biz-paired__block">
      <div class="admin-biz-paired__title">Representative name</div>
      <div class="admin-biz-paired__fields admin-biz-paired__fields--names">
        <div>
          <label class="form-label">First name</label>
          <input class="form-control name-cap" name="representative_first_name" required value="<?= e(capitalize_name((string) $repParts['first'])) ?>">
        </div>
        <div>
          <label class="form-label">Middle name</label>
          <input class="form-control name-cap" name="representative_middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) $repParts['middle'])) ?>">
        </div>
        <div>
          <label class="form-label">Last name</label>
          <input class="form-control name-cap" name="representative_last_name" required value="<?= e(capitalize_name((string) $repParts['last'])) ?>">
        </div>
        <div class="admin-biz-paired__suffix">
          <label class="form-label">Suffix</label>
          <?= name_suffix_field_html('representative_suffix', $repSuffix) ?>
        </div>
        <?php $listingSexField('representative_sex', $repSexSelected); ?>
      </div>
      <div class="admin-biz-paired__fields admin-biz-paired__fields--2eq">
        <div>
          <label class="form-label">Business Email</label>
          <input type="email" class="form-control" name="email" required value="<?= e((string) ($listing['email'] ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Business contact number</label>
          <div class="phone-input">
            <span class="phone-input__prefix" title="Philippines">
              <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
              <span>+63</span>
            </span>
            <input
              type="tel"
              class="form-control"
              name="phone"
              inputmode="numeric"
              autocomplete="off"
              maxlength="10"
              placeholder="9XXXXXXXXX"
              required
              value="<?= e($listingPhoneLocal) ?>"
            >
          </div>
        </div>
      </div>
    </div>

    <div class="admin-biz-paired__block">
      <div class="admin-biz-paired__fields admin-biz-paired__fields--2eq">
        <div>
          <label class="form-label">Business Name</label>
          <input class="form-control name-cap" name="name" required value="<?= e(capitalize_name((string) ($listing['name'] ?? ''))) ?>">
        </div>
        <div>
          <label class="form-label">Business category</label>
          <select class="form-select" name="business_type_id" required>
            <option value="" <?= empty($listing['business_type_id']) ? 'selected' : '' ?> disabled>Select type</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int) $t['id'] ?>" data-pin-color="<?= e(normalize_pin_color($t['pin_color'] ?? '')) ?>" <?= (int) ($listing['business_type_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="admin-biz-paired__addr" data-ph-address data-ph-hide-province data-ph-hide-city
           data-ph-lock-province="<?= e(ph_guide_locked_province()) ?>"
           data-ph-lock-city="<?= e(ph_business_default_city()) ?>">
        <input type="hidden" data-ph-region name="address_region" value="<?= e(ph_guide_locked_province()) ?>">
        <input type="hidden" data-ph-city name="address_city" value="<?= e(ph_business_default_city()) ?>">
        <div class="admin-biz-paired__fields admin-biz-paired__fields--2eq">
          <div>
            <label class="form-label">Barangay</label>
            <select class="form-select" name="address_barangay" data-ph-barangay required disabled
                    data-initial="<?= e($bizBarangay) ?>">
              <option value="" selected disabled>Select barangay</option>
            </select>
          </div>
          <div>
            <label class="form-label">Street</label>
            <input class="form-control" name="address_street" required maxlength="150"
                   placeholder="House no., street name"
                   value="<?= e($bizStreet) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="admin-biz-paired__block">
      <div class="admin-biz-paired__fields admin-biz-paired__fields--2eq">
        <div>
          <label class="form-label">Business Permit/Registration Number</label>
          <input class="form-control" name="permit_number" value="<?= e((string) ($listing['permit_number'] ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Tax Identification Number</label>
          <input class="form-control" name="tax_id" placeholder="If applicable" value="<?= e((string) ($listing['tax_id'] ?? '')) ?>">
        </div>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<?php if (!$listingHideLogo): ?>
<div class="col-12">
  <label class="form-label">Business Logo</label>
  <div class="avatar-preview mb-1" data-avatar-preview>
    <div class="avatar-preview__frame" data-avatar-frame>
      <?php if ($listingLogoUrl): ?>
        <img src="<?= e($listingLogoUrl) ?>" alt="" class="avatar-preview__img">
      <?php else: ?>
        No photo
      <?php endif; ?>
    </div>
    <div class="avatar-preview__fields">
      <input type="file" class="form-control" name="logo" data-avatar-input accept="image/jpeg,image/png,image/gif,image/webp">
      <div class="form-text">Shown on the business listing. JPG, PNG, GIF, or WebP — preview updates when you pick a file.</div>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="col-12"><div class="fw-semibold pt-1">Representative name</div></div>
<div class="col-md-4">
  <label class="form-label">First name</label>
  <input class="form-control name-cap" name="representative_first_name" required value="<?= e(capitalize_name((string) $repParts['first'])) ?>">
</div>
<div class="col-md-4">
  <label class="form-label">Middle name</label>
  <input class="form-control name-cap" name="representative_middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) $repParts['middle'])) ?>">
</div>
<div class="col-md-4">
  <label class="form-label">Last name</label>
  <input class="form-control name-cap" name="representative_last_name" required value="<?= e(capitalize_name((string) $repParts['last'])) ?>">
</div>
<div class="col-md-6">
  <label class="form-label">Business Name</label>
  <input class="form-control name-cap" name="name" required value="<?= e(capitalize_name((string) ($listing['name'] ?? ''))) ?>">
</div>
<div class="col-md-6">
  <label class="form-label">Business category</label>
  <select class="form-select" name="business_type_id" required>
    <option value="" <?= empty($listing['business_type_id']) ? 'selected' : '' ?> disabled>Select type</option>
    <?php foreach ($types as $t): ?>
      <option value="<?= (int) $t['id'] ?>" data-pin-color="<?= e(normalize_pin_color($t['pin_color'] ?? '')) ?>" <?= (int) ($listing['business_type_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="col-12"><div class="fw-semibold pt-1">Business owner name</div></div>
<div class="col-md-4">
  <label class="form-label">First name</label>
  <input class="form-control name-cap" name="owner_first_name" id="listingOwnerFirst" required value="<?= e(capitalize_name((string) $ownerParts['first'])) ?>">
</div>
<div class="col-md-4">
  <label class="form-label">Middle name</label>
  <input class="form-control name-cap" name="owner_middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) $ownerParts['middle'])) ?>">
</div>
<div class="col-md-4">
  <label class="form-label">Last name</label>
  <input class="form-control name-cap" name="owner_last_name" id="listingOwnerLast" required value="<?= e(capitalize_name((string) $ownerParts['last'])) ?>">
</div>
<div class="col-md-7">
  <label class="form-label">Business Email</label>
  <input type="email" class="form-control" name="email" required value="<?= e((string) ($listing['email'] ?? '')) ?>">
</div>
<div class="col-md-5">
  <label class="form-label">Business contact number</label>
  <div class="phone-input">
    <span class="phone-input__prefix" title="Philippines">
      <span class="phone-input__flag" aria-hidden="true">🇵🇭</span>
      <span>+63</span>
    </span>
    <input
      type="tel"
      class="form-control"
      name="phone"
      inputmode="numeric"
      autocomplete="off"
      maxlength="10"
      placeholder="9XXXXXXXXX"
      required
      value="<?= e($listingPhoneLocal) ?>"
    >
  </div>
</div>
<div class="col-12">
  <label class="form-label">Business Address</label>
  <?php if (!$bizHasStructured && $bizLegacyAddr !== ''): ?>
    <div class="form-text mb-2">Previously saved: <?= e($bizLegacyAddr) ?>. Choose barangay and street below to update.</div>
  <?php endif; ?>
  <div class="row g-3" data-ph-address data-ph-hide-province data-ph-hide-city
       data-ph-lock-province="<?= e(ph_guide_locked_province()) ?>"
       data-ph-lock-city="<?= e(ph_business_default_city()) ?>">
    <input type="hidden" data-ph-region name="address_region" value="<?= e(ph_guide_locked_province()) ?>">
    <input type="hidden" data-ph-city name="address_city" value="<?= e(ph_business_default_city()) ?>">
    <div class="col-md-6">
      <label class="form-label">Barangay</label>
      <select class="form-select" name="address_barangay" data-ph-barangay required disabled
              data-initial="<?= e($bizBarangay) ?>">
        <option value="" selected disabled>Select barangay</option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Street</label>
      <input class="form-control" name="address_street" required maxlength="150"
             placeholder="House no., street name"
             value="<?= e($bizStreet) ?>">
    </div>
  </div>
  <div class="form-text mt-2">CulTour is for local businesses in Ormoc City only.</div>
</div>
<div class="col-md-6">
  <label class="form-label">Business Permit/Registration Number</label>
  <input class="form-control" name="permit_number" value="<?= e((string) ($listing['permit_number'] ?? '')) ?>">
</div>
<div class="col-md-6">
  <label class="form-label">Tax Identification Number</label>
  <input class="form-control" name="tax_id" placeholder="If applicable" value="<?= e((string) ($listing['tax_id'] ?? '')) ?>">
</div>
<div class="col-12">
  <label class="form-label">Business Description</label>
  <textarea class="form-control" name="description" rows="3"><?= e((string) ($listing['description'] ?? '')) ?></textarea>
</div>
<?php endif; ?>
