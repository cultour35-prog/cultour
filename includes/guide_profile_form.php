<?php
$gpUser = $profileUser ?? $user;
$dayLabels = $dayLabels ?? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$guideProfileShowStatus = $guideProfileShowStatus ?? true;
$guideProfileRequirePhoto = $guideProfileRequirePhoto ?? true;
$guideProfileAvailNote = $guideProfileAvailNote ?? ('Same-day bookings close 2 hours before end. Block dates on <a href="' . APP_URL . '/tourguide/availability.php">Availability</a>.');
$photoRequired = $needsPhoto && $guideProfileRequirePhoto;
$markGuideIncomplete = ($guideProfileShowStatus ?? true)
    && (($profile['approval_status'] ?? '') === 'approved')
    && !guide_profile_is_complete($profileCompleteCheck, false);
?>
      <div class="guide-profile-layout">
        <?php if ($guideProfileShowStatus): ?>
        <div class="mb-3">
          <div class="small text-muted mb-1">Tourism Office application</div>
          <?= status_badge($profile['approval_status'] ?? 'pending') ?>
          <?php if (($profile['approval_status'] ?? '') === 'approved'): ?>
            <div class="small text-muted mt-1">Approved — you can accept bookings once your profile details below are complete.</div>
          <?php elseif (($profile['approval_status'] ?? '') === 'pending'): ?>
            <div class="small text-muted mt-1">Waiting for Tourism Office approval. Filling this form does not approve your account.</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" autocomplete="off" id="guideProfileForm" data-unsaved-guard><?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="guide_id" value="<?= (int) $gid ?>">
          <input type="hidden" name="use_structured_address" value="1">

          <div class="glass p-3 p-md-4 guide-profile-card">
            <div class="guide-profile-section">
              <div class="fw-semibold mb-3">Profile</div>
              <div class="guide-profile-photo<?= ($markGuideIncomplete && $needsPhoto) ? ' is-incomplete' : '' ?>">
                <label class="form-label">Profile photo<?= $photoRequired ? ' <span class="text-danger">*</span>' : '' ?></label>
                <div class="avatar-preview" data-avatar-preview>
                  <div class="avatar-preview__frame" data-avatar-frame>
                    <?php if (!empty($gpUser['avatar'])): ?>
                      <img src="<?= APP_URL ?>/uploads/<?= e((string) $gpUser['avatar']) ?>" alt="" class="avatar-preview__img">
                    <?php else: ?>
                      No photo
                    <?php endif; ?>
                  </div>
                  <div class="avatar-preview__fields">
                    <input type="file" class="form-control" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"<?= $photoRequired ? ' required' : '' ?>>
                    <div class="form-text">Tourists see this on the booking carousel. JPG, PNG, GIF, or WebP — preview updates when you pick a file.</div>
                  </div>
                </div>
              </div>
              <div class="guide-profile-fields guide-profile-fields--names">
                <div>
                  <label class="form-label">First name</label>
                  <input class="form-control name-cap" name="first_name" value="<?= e(capitalize_name((string) ($gpUser['first_name'] ?? ''))) ?>" required>
                </div>
                <div>
                  <label class="form-label">Middle name</label>
                  <input class="form-control name-cap" name="middle_name" placeholder="Optional" value="<?= e(capitalize_name((string) ($gpUser['middle_name'] ?? ''))) ?>">
                </div>
                <div>
                  <label class="form-label">Last name</label>
                  <input class="form-control name-cap" name="last_name" value="<?= e(capitalize_name((string) ($gpUser['last_name'] ?? ''))) ?>" required>
                </div>
                <div class="guide-profile-suffix">
                  <label class="form-label">Suffix</label>
                  <?= name_suffix_field_html('name_suffix', (string) ($gpUser['name_suffix'] ?? '')) ?>
                </div>
              </div>
              <div class="guide-profile-fields guide-profile-fields--4">
                <div>
                  <label class="form-label">Phone</label>
                  <input class="form-control" name="phone" value="<?= e((string) ($gpUser['phone'] ?? '')) ?>">
                </div>
                <div>
                  <label class="form-label">Gender</label>
                  <div class="select-chevron">
                    <select class="form-select" name="sex" required>
                      <?php foreach (user_sex_options() as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= (string) ($gpUser['sex'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <i class="bi bi-chevron-down select-chevron__icon" aria-hidden="true"></i>
                  </div>
                </div>
                <div>
                  <label class="form-label">Date of birth</label>
                  <input type="date" class="form-control" name="date_of_birth" required
                         max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>"
                         value="<?= e((string) ($gpUser['date_of_birth'] ?? '')) ?>">
                </div>
                <div>
                  <label class="form-label">Nationality</label>
                  <input class="form-control" name="nationality" required value="<?= e((string) ($gpUser['nationality'] ?? '')) ?>">
                </div>
              </div>
            </div>

            <div data-same-as-permanent data-same-lock-province="<?= e($guideLockProv) ?>" data-same-lock-city="<?= e(ph_guide_locked_city()) ?>">
            <div class="guide-profile-section">
              <?php if (!$hasStructured && $legacyAddress !== ''): ?>
                <div class="alert alert-secondary small mb-3">Previously saved: <?= e($legacyAddress) ?>. Choose province, city, and barangay below to update.</div>
              <?php endif; ?>
              <div data-ph-address data-same-permanent>
                <div class="fw-semibold mb-3">Permanent address</div>
                <div class="guide-profile-fields guide-profile-fields--2">
                  <div>
                    <label class="form-label">Province</label>
                    <select class="form-select" name="address_region" data-ph-region required
                            data-initial="<?= e($addrRegion) ?>">
                      <option value="" selected disabled>Select province</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">City / Municipality</label>
                    <select class="form-select" name="address_city" data-ph-city required disabled
                            data-initial="<?= e($addrCity) ?>">
                      <option value="" selected disabled>Select city / municipality</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Barangay</label>
                    <select class="form-select" name="address_barangay" data-ph-barangay required disabled
                            data-initial="<?= e($addrBarangay) ?>">
                      <option value="" selected disabled>Select barangay</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Street</label>
                    <input class="form-control" name="address_street" required maxlength="150"
                           placeholder="House no., street name"
                           value="<?= e($addrStreet) ?>">
                  </div>
                </div>
              </div>
            </div>

            <div class="guide-profile-section" data-ph-address data-same-current data-ph-lock-province="<?= e($guideLockProv) ?>" data-ph-lock-city="<?= e(ph_guide_locked_city()) ?>">
              <?= current_address_same_radios_html(!empty($sameAsPermanent), 'guideAddrSame') ?>
              <div class="guide-profile-fields guide-profile-fields--2">
                <div>
                  <label class="form-label">Province</label>
                  <select class="form-select" name="current_address_region" id="curAddrRegion" data-ph-region required
                          data-initial="<?= e($curRegion) ?>">
                    <option value="<?= e($guideLockProv) ?>" selected><?= e($guideLockProv) ?></option>
                  </select>
                </div>
                <div>
                  <label class="form-label">City / Municipality</label>
                  <select class="form-select" name="current_address_city" id="curAddrCity" data-ph-city required disabled
                          data-initial="<?= e($curCity) ?>">
                    <option value="" selected disabled>Select city / municipality</option>
                  </select>
                </div>
                <div>
                  <label class="form-label">Barangay</label>
                  <select class="form-select" name="current_address_barangay" id="curAddrBarangay" data-ph-barangay required disabled
                          data-initial="<?= e($curBarangay) ?>">
                    <option value="" selected disabled>Select barangay</option>
                  </select>
                </div>
                <div>
                  <label class="form-label">Street</label>
                  <input class="form-control" name="current_address_street" id="curAddrStreet" required maxlength="150"
                         placeholder="House no., street name"
                         value="<?= e($curStreet) ?>">
                </div>
              </div>
            </div>
            </div>

            <div class="guide-profile-section">
              <div class="fw-semibold mb-3">Emergency contact</div>
              <?php
                $emergParts = $emergParts ?? split_person_name((string) ($gpUser['emergency_contact_name'] ?? ''));
                $emergSuffix = (string) ($emergParts['suffix'] ?? '');
                $emergRel = $emergRel ?? (string) ($gpUser['emergency_contact_relationship'] ?? '');
              ?>
              <div class="guide-profile-fields guide-profile-fields--names">
                <div>
                  <label class="form-label">First name</label>
                  <input class="form-control name-cap" name="emergency_first_name" required maxlength="80" value="<?= e(capitalize_name((string) ($emergParts['first'] ?? ''))) ?>">
                </div>
                <div>
                  <label class="form-label">Middle name</label>
                  <input class="form-control name-cap" name="emergency_middle_name" maxlength="80" placeholder="Optional" value="<?= e(capitalize_name((string) ($emergParts['middle'] ?? ''))) ?>">
                </div>
                <div>
                  <label class="form-label">Last name</label>
                  <input class="form-control name-cap" name="emergency_last_name" required maxlength="80" value="<?= e(capitalize_name((string) ($emergParts['last'] ?? ''))) ?>">
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
                           value="<?= e(ph_phone_local_digits($gpUser['emergency_contact_phone'] ?? '')) ?>">
                  </div>
                </div>
              </div>
            </div>

            <hr class="guide-profile-split__rule">

            <div class="guide-profile-section">
                <div class="fw-semibold mb-3">Guide details</div>
                <div class="guide-details-grid">
                  <div class="guide-details-grid__years">
                    <label class="form-label">Years exp.</label>
                    <input type="number" class="form-control" name="years_experience" value="<?= e($yearsVal) ?>" min="0" step="1">
                  </div>
                  <div class="guide-details-grid__langs">
                    <label class="form-label">Languages <span class="text-danger">*</span></label>
                    <input class="form-control js-lang-tags<?= ($markGuideIncomplete && trim($langVal) === '') ? ' is-incomplete' : '' ?>" name="languages" value="<?= e($langVal) ?>" placeholder="Type a language, then press Enter" required>
                  </div>
                  <div class="guide-details-grid__license">
                    <label class="form-label" for="guideLicenseNumber">License no.</label>
                    <input class="form-control" id="guideLicenseNumber" name="license_number" value="<?= e($licenseVal) ?>">
                  </div>
                  <div class="guide-details-grid__files">
                    <div class="guide-creds__pick-labels">
                      <label class="form-label mb-0" for="guideCredFiles">Credentials / documents</label>
                      <span class="guide-creds__pick-spacer" aria-hidden="true"></span>
                      <label class="form-label mb-0" for="guideUploadedSummary">Uploaded</label>
                    </div>
                    <div class="guide-creds__pick">
                      <div class="file-upload-field guide-creds__chooser" data-file-upload>
                        <input
                          type="file"
                          class="form-control js-multi-files"
                          form="uploadGuideDocsForm"
                          name="credential_files[]"
                          id="guideCredFiles"
                          accept=".pdf,.doc,.docx,image/*"
                          multiple
                          data-list="guideCredFilesList"
                          data-count="guideCredFilesCount"
                          data-menu="guideCredFilesMenu"
                          data-toggle="guideCredFilesToggle"
                        >
                        <button type="button" class="file-upload-field__edge" id="guideCredFilesToggle" aria-expanded="false" aria-controls="guideCredFilesMenu" title="View selected files">
                          <span class="file-upload-field__count" id="guideCredFilesCount">0</span>
                          <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="file-upload-field__menu d-none" id="guideCredFilesMenu" hidden>
                          <div class="file-upload-field__menu-head">Selected files</div>
                          <ul class="list-unstyled mb-0" id="guideCredFilesList"></ul>
                          <div class="file-upload-field__empty small text-muted">No files selected yet.</div>
                        </div>
                      </div>
                      <button type="submit" class="btn btn-ct guide-creds__upload-btn" form="uploadGuideDocsForm">Upload</button>
                      <?php
                        $guideDocCount = count($guideDocs);
                        $guideUploadedSummary = $guideDocCount === 0
                          ? 'No files uploaded'
                          : ($guideDocCount === 1
                            ? (string) (($guideDocs[0]['original_name'] ?? '') ?: basename((string) ($guideDocs[0]['file_path'] ?? '1 file')))
                            : $guideDocCount . ' files uploaded');
                      ?>
                      <div class="file-upload-field guide-creds__uploaded" data-uploaded-dropdown>
                        <input
                          type="text"
                          class="form-control guide-creds__uploaded-summary"
                          id="guideUploadedSummary"
                          value="<?= e($guideUploadedSummary) ?>"
                          readonly
                          tabindex="-1"
                          aria-readonly="true"
                        >
                        <button type="button" class="file-upload-field__edge guide-creds__uploaded-toggle" id="guideUploadedToggle" aria-expanded="false" aria-controls="guideUploadedMenu" title="View uploaded files">
                          <span class="file-upload-field__count"><?= (int) $guideDocCount ?></span>
                          <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="file-upload-field__menu d-none" id="guideUploadedMenu" hidden>
                          <div class="file-upload-field__menu-head">Uploaded files</div>
                          <?php if ($guideDocs): ?>
                            <ul class="list-unstyled mb-0">
                              <?php foreach ($guideDocs as $d): ?>
                                <li class="file-upload-field__item">
                                  <a class="file-upload-field__name text-truncate" href="<?= APP_URL ?>/uploads/<?= e($d['file_path']) ?>" target="_blank" rel="noopener" title="<?= e($d['original_name'] ?: basename($d['file_path'])) ?>">
                                    <i class="bi bi-file-earmark-person" aria-hidden="true"></i>
                                    <?= e($d['original_name'] ?: basename($d['file_path'])) ?>
                                  </a>
                                  <button type="submit" form="deleteGuideDocForm" class="btn btn-sm btn-link text-danger p-0 file-upload-field__remove"
                                    name="document_id" value="<?= (int) $d['id'] ?>"
                                    onclick="return confirm('Remove this document?');">Remove</button>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          <?php else: ?>
                            <div class="file-upload-field__empty small text-muted">No documents uploaded yet.</div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="guide-details-grid__bio">
                    <label class="form-label">Bio <span class="text-danger">*</span></label>
                    <textarea class="form-control<?= ($markGuideIncomplete && trim($bioVal) === '') ? ' is-incomplete' : '' ?>" name="bio" rows="4" required><?= e($bioVal) ?></textarea>
                  </div>
                </div>
            </div>
          </div>

          <div class="glass p-3 p-md-4 guide-profile-card guide-profile-avail guide-profile-avail--week mt-3<?= ($markGuideIncomplete && !$hasAvail) ? ' is-incomplete' : '' ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
              <div>
                <h3 class="h6 mb-1">Availability <span class="text-danger">*</span></h3>
                <div class="small text-muted">Set hours for each day you work.</div>
              </div>
              <?php if ($hasAvail): ?>
                <span class="badge bg-success">Set</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Not set</span>
              <?php endif; ?>
            </div>
            <div class="guide-profile-avail__rows">
              <?php for ($i = 0; $i < 7; $i++): ?>
                <?php $dayOn = !empty($availByDay[$i]['on']); ?>
                <div class="guide-profile-avail__row<?= $dayOn ? ' is-on' : '' ?>">
                  <div class="form-check guide-profile-avail__day">
                    <input class="form-check-input" type="checkbox" name="day[]" value="<?= $i ?>" id="profileDay<?= $i ?>"
                      <?= $dayOn ? 'checked' : '' ?>
                      data-avail-day-toggle>
                    <label class="form-check-label" for="profileDay<?= $i ?>"><?= $dayLabels[$i] ?></label>
                  </div>
                  <div class="guide-profile-avail__times">
                    <div>
                      <label class="form-label small mb-0" for="dayStart<?= $i ?>">Start</label>
                      <input type="time" class="form-control form-control-sm" id="dayStart<?= $i ?>"
                             name="day_start[<?= $i ?>]"
                             value="<?= e(time_picker_value((string) $availByDay[$i]['start'], '8:00 AM')) ?>"
                             step="60"<?= $dayOn ? ' required' : ' disabled' ?>>
                    </div>
                    <div>
                      <label class="form-label small mb-0" for="dayEnd<?= $i ?>">End</label>
                         <input type="time" class="form-control form-control-sm" id="dayEnd<?= $i ?>"
                             name="day_end[<?= $i ?>]"
                             value="<?= e(time_picker_value((string) $availByDay[$i]['end'], '5:00 PM')) ?>"
                             step="60" data-avail-end<?= $dayOn ? ' required' : ' disabled' ?>>
                    </div>
                    <p class="guide-profile-avail__cutoff mb-0" data-avail-cutoff data-cutoff-prefix="Cutoff: ">
                      Cutoff: <?= e(guide_booking_cutoff_display($availByDay[$i]['end']) ?: '—') ?>
                    </p>
                  </div>
                </div>
              <?php endfor; ?>
            </div>
            <div class="form-text mt-2 mb-0"><?= $guideProfileAvailNote ?></div>
            <?php if (!empty($guideProfileShowBlocks)): ?>
              <hr class="my-3 opacity-50">
              <h3 class="h6 mb-2">Block dates</h3>
              <div class="guide-block-dates-row">
                <div class="guide-block-dates-form">
                  <input type="date" class="form-control form-control-sm" name="block_date" aria-label="Block date">
                  <input class="form-control form-control-sm" name="block_reason" placeholder="Reason" aria-label="Block reason">
                  <button type="submit" class="btn btn-sm btn-ct guide-block-dates-form__block" name="block_intent" value="1">Block</button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary guide-block-dates-form__view" data-open-guide-blocks>
                  View block dates
                </button>
              </div>
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
                      <ul class="admin-guide-uploads-card__list admin-guide-blocks-list">
                        <?php foreach ($guideBlocks as $b): ?>
                          <li class="admin-guide-blocks-list__item">
                            <span class="small">
                              <?= e(format_date($b['block_date'])) ?><?= trim((string) ($b['reason'] ?? '')) !== '' ? ' · ' . e($b['reason']) : '' ?>
                            </span>
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" type="submit" form="unblockBlock<?= (int) $b['id'] ?>" aria-label="Remove blocked date">&times;</button>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
            <div class="guide-profile-save">
              <button type="submit" class="btn btn-ct">Save</button>
            </div>
          </div>
        </form>
        <?php if (!empty($guideBlocks)): ?>
          <?php foreach ($guideBlocks as $b): ?>
            <form id="unblockBlock<?= (int) $b['id'] ?>" method="post" class="d-none" data-unsaved-ignore>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="unblock">
              <input type="hidden" name="guide_id" value="<?= (int) $gid ?>">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
        <form id="uploadGuideDocsForm" method="post" enctype="multipart/form-data" class="d-none">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_documents">
          <input type="hidden" name="guide_id" value="<?= (int) $gid ?>">
        </form>
        <form id="deleteGuideDocForm" method="post" class="d-none"><?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_document">
          <input type="hidden" name="guide_id" value="<?= (int) $gid ?>">
        </form>
      </div>
<?php
$guideLeyteSameBody = 'Only tour guides who currently live in Ormoc City can register on CulTour. Uncheck “Same as permanent address” and enter an Ormoc City current address.';
require __DIR__ . '/guide_leyte_same_modal.php';
?>
<script>
(function () {
  function endToMins(raw) {
    var t = String(raw || '').trim();
    if (!t) return null;
    var m12 = t.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i);
    if (m12) {
      var h = parseInt(m12[1], 10);
      var min = m12[2] !== undefined ? parseInt(m12[2], 10) : 0;
      if (h < 1 || h > 12 || min < 0 || min > 59) return null;
      var ap = m12[3].toUpperCase();
      if (ap === 'AM') h = h === 12 ? 0 : h;
      else h = h === 12 ? 12 : h + 12;
      return h * 60 + min;
    }
    var parts = t.split(':');
    if (parts.length < 2) return null;
    var h24 = parseInt(parts[0], 10);
    var mi = parseInt(parts[1], 10);
    if (!Number.isFinite(h24) || !Number.isFinite(mi) || h24 < 0 || h24 > 23 || mi < 0 || mi > 59) return null;
    return h24 * 60 + mi;
  }
  function minsTo12h(mins) {
    mins = Math.max(0, Math.min(23 * 60 + 59, mins));
    var h24 = Math.floor(mins / 60);
    var m = mins % 60;
    var ap = h24 >= 12 ? 'PM' : 'AM';
    var h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + ap;
  }
  function paintCutoff(row) {
    var endInp = row.querySelector('[data-avail-end]');
    var el = row.querySelector('[data-avail-cutoff]');
    if (!el) return;
    var mins = endToMins(endInp ? endInp.value : '');
    var label = mins == null ? '—' : minsTo12h(Math.max(0, mins - 120));
    var prefix = el.getAttribute('data-cutoff-prefix') || 'Same-day booking cutoff: ';
    el.textContent = prefix + label;
  }
  document.querySelectorAll('[data-avail-day-toggle]').forEach(function (cb) {
    var row = cb.closest('.guide-profile-avail__row');
    if (!row) return;
    function sync() {
      var on = !!cb.checked;
      row.classList.toggle('is-on', on);
      row.querySelectorAll('input[type="time"]').forEach(function (inp) {
        inp.disabled = !on;
        if (on) inp.setAttribute('required', 'required');
        else inp.removeAttribute('required');
      });
    }
    cb.addEventListener('change', sync);
    sync();
    paintCutoff(row);
    var endInp = row.querySelector('[data-avail-end]');
    if (endInp) {
      endInp.addEventListener('input', function () { paintCutoff(row); });
      endInp.addEventListener('change', function () { paintCutoff(row); });
    }
  });
})();
</script>
<script src="<?= APP_URL ?>/assets/js/ph-address.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time() ?>"></script>
<script>
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('<?= APP_URL ?>/assets/data/ph-address.json');
  }
  (function () {
    var fileBags = {};
    var MENU_PAD = 12;
    var MENU_MIN = 96;

    function fitCredMenuInCard(menuEl, anchorEl) {
      if (!menuEl || !anchorEl) return;
      var card = anchorEl.closest('.guide-profile-card');
      if (!card) {
        menuEl.style.maxHeight = '12rem';
        return;
      }
      var cardRect = card.getBoundingClientRect();
      var anchorRect = anchorEl.getBoundingClientRect();
      var space = Math.floor(cardRect.bottom - anchorRect.bottom - MENU_PAD);
      var maxPx = Math.max(MENU_MIN, space);
      menuEl.style.maxHeight = maxPx + 'px';
      menuEl.style.overflowY = 'auto';
    }

    function bindMultiFiles(input) {
      if (!input || !window.DataTransfer) return;
      var listId = input.getAttribute('data-list');
      var listEl = listId ? document.getElementById(listId) : null;
      var countEl = input.getAttribute('data-count') ? document.getElementById(input.getAttribute('data-count')) : null;
      var menuEl = input.getAttribute('data-menu') ? document.getElementById(input.getAttribute('data-menu')) : null;
      var toggleEl = input.getAttribute('data-toggle') ? document.getElementById(input.getAttribute('data-toggle')) : null;
      var emptyEl = menuEl ? menuEl.querySelector('.file-upload-field__empty') : null;
      var wrapEl = input.closest('.file-upload-field') || input;
      var key = input.id || listId || input.name;
      fileBags[key] = [];

      function syncInput() {
        var dt = new DataTransfer();
        fileBags[key].forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
      }

      function setMenuOpen(open) {
        if (!menuEl || !toggleEl) return;
        menuEl.classList.toggle('d-none', !open);
        menuEl.hidden = !open;
        toggleEl.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggleEl.classList.toggle('is-open', open);
        if (open) fitCredMenuInCard(menuEl, wrapEl);
      }

      function updateCount() {
        var n = fileBags[key].length;
        if (countEl) countEl.textContent = String(n);
        if (toggleEl) {
          toggleEl.disabled = false;
          toggleEl.title = n ? ('View ' + n + ' selected file' + (n === 1 ? '' : 's')) : 'View selected files';
        }
        if (emptyEl) emptyEl.classList.toggle('d-none', n > 0);
      }

      function render() {
        if (!listEl) return;
        listEl.innerHTML = '';
        fileBags[key].forEach(function (f, i) {
          var li = document.createElement('li');
          li.className = 'file-upload-field__item';
          var name = document.createElement('span');
          name.className = 'file-upload-field__name text-truncate';
          name.title = f.name;
          name.innerHTML = '<i class="bi bi-file-earmark" aria-hidden="true"></i> ';
          name.appendChild(document.createTextNode(f.name));
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-sm btn-link text-danger p-0 file-upload-field__remove';
          btn.textContent = 'Remove';
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fileBags[key].splice(i, 1);
            syncInput();
            render();
          });
          li.appendChild(name);
          li.appendChild(btn);
          listEl.appendChild(li);
        });
        updateCount();
        if (menuEl && !menuEl.classList.contains('d-none')) fitCredMenuInCard(menuEl, wrapEl);
      }

      if (toggleEl) {
        toggleEl.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var open = menuEl && menuEl.classList.contains('d-none');
          setMenuOpen(!!open);
        });
      }

      input.addEventListener('change', function () {
        var incoming = Array.prototype.slice.call(input.files || []);
        incoming.forEach(function (f) {
          var k = f.name + ':' + f.size + ':' + f.lastModified;
          var exists = fileBags[key].some(function (x) {
            return (x.name + ':' + x.size + ':' + x.lastModified) === k;
          });
          if (!exists) fileBags[key].push(f);
        });
        syncInput();
        render();
        if (fileBags[key].length) setMenuOpen(true);
        try {
          input.value = '';
          syncInput();
        } catch (err) { /* ignore */ }
      });

      updateCount();
    }

    document.querySelectorAll('.js-multi-files').forEach(bindMultiFiles);
    document.querySelectorAll('[data-uploaded-dropdown]').forEach(function (wrap) {
      var toggle = wrap.querySelector('.guide-creds__uploaded-toggle');
      var summary = wrap.querySelector('.guide-creds__uploaded-summary');
      var menu = wrap.querySelector('.file-upload-field__menu');
      if (!toggle || !menu) return;
      function toggleUploadedMenu(e) {
        e.preventDefault();
        e.stopPropagation();
        var open = menu.classList.contains('d-none');
        menu.classList.toggle('d-none', !open);
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.classList.toggle('is-open', open);
        if (open) fitCredMenuInCard(menu, wrap);
      }
      toggle.addEventListener('click', toggleUploadedMenu);
      if (summary) summary.addEventListener('click', toggleUploadedMenu);
    });
    function refitOpenCredMenus() {
      document.querySelectorAll('[data-file-upload] .file-upload-field__menu:not(.d-none), [data-uploaded-dropdown] .file-upload-field__menu:not(.d-none)').forEach(function (menu) {
        var wrap = menu.closest('.file-upload-field');
        if (wrap) fitCredMenuInCard(menu, wrap);
      });
    }
    window.addEventListener('resize', refitOpenCredMenus);
    document.addEventListener('click', function (e) {
      document.querySelectorAll('[data-file-upload], [data-uploaded-dropdown]').forEach(function (wrap) {
        if (wrap.contains(e.target)) return;
        var menu = wrap.querySelector('.file-upload-field__menu');
        var toggle = wrap.querySelector('.file-upload-field__edge, .guide-creds__uploaded-toggle');
        if (!menu || !toggle) return;
        menu.classList.add('d-none');
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.classList.remove('is-open');
      });
    });
  })();
  (function () {
    var overlay = document.getElementById('guideBlocksCard');
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
  })();
</script>
