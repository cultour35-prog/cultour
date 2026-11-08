<?php
/**
 * Business owner — edit listing (same layout as admin edit listing).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
$user = require_login(ROLE_BUSINESS);
ensure_business_documents_schema();
ensure_business_listing_schema();
ensure_place_schedule_schema();
$pageTitle = 'Business details';
$pageHeading = 'Business details';
$pageSub = 'Update your listing information and map pin';
$activeNav = 'profile';
$profileUrl = APP_URL . '/business/profile.php';

ensure_business_type_pin_colors();
$types = db()->query('SELECT * FROM business_types ORDER BY name')->fetchAll();
$biz = db()->prepare('SELECT * FROM businesses WHERE user_id=? LIMIT 1');
$biz->execute([$user['id']]);
$biz = $biz->fetch();
$bizTypeName = '';
if ($biz) {
    foreach ($types as $typeRow) {
        if ((int) $typeRow['id'] === (int) ($biz['business_type_id'] ?? 0)) {
            $bizTypeName = (string) $typeRow['name'];
            break;
        }
    }
}
$catalogCopy = business_catalog_copy($bizTypeName);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $biz && !verify_csrf()) {
    flash('error', csrf_error_message());
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && $biz) {
    $action = $_POST['action'] ?? 'update';
    $bizId = (int) $biz['id'];

    if ($action === 'delete_document') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        if ($docId && delete_business_document($docId, $bizId)) {
            flash('success', 'Document removed.');
        } else {
            flash('error', 'Document not found.');
        }
        redirect($profileUrl);
    }

    if ($action === 'add_service' || $action === 'delete_service') {
        if (!business_listing_is_approved($biz)) {
            flash('error', $catalogCopy['locked']);
            redirect($profileUrl);
        }
    }

    if ($action === 'add_service') {
        $svc = parse_place_service_post($_POST);
        if (empty($svc['ok'])) {
            flash('error', (string) ($svc['message'] ?? 'Name is required.'));
            redirect($profileUrl);
        }
        $d = $svc['data'];
        $keepOfferMeta = in_array(business_catalog_mode($bizTypeName), ['rooms', 'vehicles', 'packages', 'menu'], true);
        db()->prepare(
            'INSERT INTO business_services (business_id, name, description, price, duration_minutes)
             VALUES (?,?,?,?,?)'
        )->execute([
            $bizId,
            $d['name'],
            $keepOfferMeta ? $d['description'] : null,
            $keepOfferMeta ? $d['price'] : 0,
            $keepOfferMeta ? $d['duration_minutes'] : null,
        ]);
        flash('success', $catalogCopy['added']);
        redirect($profileUrl);
    }

    if ($action === 'delete_service') {
        db()->prepare('DELETE FROM business_services WHERE id=? AND business_id=?')
            ->execute([(int) ($_POST['service_id'] ?? 0), $bizId]);
        flash('success', $catalogCopy['removed']);
        redirect($profileUrl);
    }

    $parsed = parse_admin_business_listing_post($_POST);
    if (!$parsed['ok']) {
        flash('error', $parsed['message'] ?? 'Please complete the business details.');
        redirect($profileUrl);
    }
    $d = $parsed['data'];
    if (trim((string) ($d['permit_number'] ?? '')) === '') {
        flash('error', 'Business permit / registration number is required.');
        redirect($profileUrl);
    }

    $typeOk = db()->prepare('SELECT id FROM business_types WHERE id=?');
    $typeOk->execute([(int) $d['type_id']]);
    if (!$typeOk->fetch()) {
        flash('error', 'Invalid business category.');
        redirect($profileUrl);
    }

    $listingApproved = business_listing_is_approved($biz);
    $cover = $biz['cover_image'] ?? null;
    $logo = $biz['logo'] ?? null;
    if ($listingApproved && !empty($_FILES['cover_image']['name'])) {
        $up = upload_file($_FILES['cover_image'], 'businesses', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if ($up) {
            $cover = $up;
        } else {
            flash('error', upload_last_error() ?: 'Could not upload the cover image.');
            redirect($profileUrl);
        }
    }
    if ($listingApproved && !empty($_FILES['logo']['name'])) {
        $upLogo = upload_file($_FILES['logo'], 'businesses', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if ($upLogo) {
            $logo = $upLogo;
        } else {
            flash('error', upload_last_error() ?: 'Could not upload business logo. Use JPG, PNG, GIF, or WebP.');
            redirect($profileUrl);
        }
    }
    if ($listingApproved && !empty($_FILES['business_photos']['name'])) {
        save_business_gallery_photos($bizId, $_FILES['business_photos']);
    }
    if (!empty($_FILES['document_files']['name'])) {
        save_business_documents($bizId, $_FILES['document_files']);
    }

    $slugBase = slugify($d['name']);
    $slug = $slugBase . '-' . $bizId;
    $n = 1;
    while (true) {
        $chk = db()->prepare('SELECT id FROM businesses WHERE slug=? AND id<>?');
        $chk->execute([$slug, $bizId]);
        if (!$chk->fetch()) {
            break;
        }
        $slug = $slugBase . '-' . $bizId . '-' . $n;
        $n++;
    }

    if ($listingApproved) {
        $coords = normalize_lat_lng($_POST['latitude'] ?? '', $_POST['longitude'] ?? '');
        if (empty($coords['ok'])) {
            flash('error', (string) ($coords['message'] ?? 'Invalid map coordinates.'));
            redirect($profileUrl);
        }
        $lat = $coords['lat'];
        $lng = $coords['lng'];
        $hoursJson = encode_opening_hours_from_post($_POST);
        $visitParsed = parse_visit_duration_post($_POST);
        if (!$visitParsed['ok']) {
            flash('error', (string) ($visitParsed['message'] ?? 'Enter a valid visit duration.'));
            redirect($profileUrl);
        }
        $visitLimit = $visitParsed['limit'];
        $visit = $visitLimit ?? 60;
    } else {
        $d['description'] = $biz['description'] ?? null;
        $d['facebook_url'] = $biz['facebook_url'] ?? null;
        $d['instagram_url'] = $biz['instagram_url'] ?? null;
        $lat = $biz['latitude'] ?? null;
        $lng = $biz['longitude'] ?? null;
        $hoursJson = $biz['opening_hours'] ?? null;
        $visit = (int) ($biz['default_visit_minutes'] ?? 60);
        $visitLimit = place_visit_limit($biz['visit_duration_minutes'] ?? null);
    }
    $extraChanged = [];
    if (files_field_has_upload($_FILES['business_photos'] ?? null)) {
        $extraChanged[] = 'business photos';
    }
    if (files_field_has_upload($_FILES['document_files'] ?? null)) {
        $extraChanged[] = 'proof of registration';
    }

    db()->prepare(
        'UPDATE businesses SET business_type_id=?, name=?, owner_name=?, representative_name=?, representative_sex=?, slug=?, description=?, address=?,
         address_region=?, address_city=?, address_barangay=?, address_street=?,
         phone=?, email=?, permit_number=?, tax_id=?, website=?, facebook_url=?, instagram_url=?, cover_image=?, logo=?, latitude=?, longitude=?,
         opening_hours=?, default_visit_minutes=?, visit_duration_minutes=?
         WHERE id=? AND user_id=?'
    )->execute([
        (int) $d['type_id'],
        $d['name'],
        $d['owner_name'] !== '' ? $d['owner_name'] : null,
        $d['representative_name'],
        $d['representative_sex'],
        $slug,
        $d['description'],
        $d['address'],
        $d['address_region'],
        $d['address_city'],
        $d['address_barangay'],
        $d['address_street'],
        $d['phone'] !== '' ? $d['phone'] : null,
        $d['email'] !== '' ? $d['email'] : null,
        $d['permit_number'],
        $d['tax_id'],
        $d['facebook_url'] ?: $d['instagram_url'],
        $d['facebook_url'],
        $d['instagram_url'],
        $cover,
        $logo,
        $lat,
        $lng,
        $hoursJson,
        $visit,
        $visitLimit,
        $bizId,
        (int) $user['id'],
    ]);
    db()->prepare('UPDATE users SET first_name=?, last_name=?, middle_name=?, sex=? WHERE id=?')->execute([
        $d['owner_first'],
        $d['owner_last'],
        $d['owner_middle'] !== '' ? $d['owner_middle'] : null,
        $d['owner_sex'],
        (int) $user['id'],
    ]);
    sync_business_document_file($bizId);
    log_business_update_activity((int) $user['id'], (string) $d['name'], $biz, [
        'name' => $d['name'],
        'business_type_id' => (int) $d['type_id'],
        'owner_name' => $d['owner_name'] !== '' ? $d['owner_name'] : null,
        'representative_name' => $d['representative_name'],
        'description' => $d['description'],
        'address' => $d['address'],
        'address_barangay' => $d['address_barangay'],
        'address_street' => $d['address_street'],
        'phone' => $d['phone'] !== '' ? $d['phone'] : null,
        'email' => $d['email'] !== '' ? $d['email'] : null,
        'permit_number' => $d['permit_number'],
        'tax_id' => $d['tax_id'],
        'facebook_url' => $d['facebook_url'],
        'instagram_url' => $d['instagram_url'],
        'cover_image' => $cover,
        'logo' => $logo,
        'latitude' => $lat,
        'longitude' => $lng,
        'opening_hours' => $hoursJson,
        'visit_duration_minutes' => $visitLimit,
    ], $extraChanged);
    flash('success', 'Business details updated.');
    redirect(posted_return_to($profileUrl));
}

$biz = db()->prepare('SELECT * FROM businesses WHERE user_id=? LIMIT 1');
$biz->execute([$user['id']]);
$biz = $biz->fetch();
$bizDocs = $biz ? business_documents((int) $biz['id']) : [];
$bizPhotos = [];
$availByDay = opening_hours_to_avail_by_day([]);
if ($biz) {
    $ps = db()->prepare('SELECT id, image_path FROM business_photos WHERE business_id=? ORDER BY created_at DESC');
    $ps->execute([(int) $biz['id']]);
    $bizPhotos = $ps->fetchAll() ?: [];
    $rawHours = $biz['opening_hours'] ?? null;
    if (is_array($rawHours)) {
        $rawHours = json_encode($rawHours);
    }
    $availByDay = opening_hours_to_avail_by_day(parse_opening_hours_value($rawHours) ?: []);
}

$initLat = $biz['latitude'] ?? '';
$initLng = $biz['longitude'] ?? '';
$ownerParts = split_person_name((string) ($biz['owner_name'] ?? ''));
if ($ownerParts['first'] === '' && $ownerParts['last'] === '') {
    $ownerParts = [
        'first' => (string) ($user['first_name'] ?? ''),
        'middle' => (string) ($user['middle_name'] ?? ''),
        'last' => (string) ($user['last_name'] ?? ''),
        'suffix' => (string) ($ownerParts['suffix'] ?? ''),
    ];
}
$repParts = split_person_name((string) ($biz['representative_name'] ?? ''));

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
map_leaflet_head();
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php if (!$biz): ?>
        <div class="alert alert-danger">Business record missing. Contact the Tourism Office.</div>
      <?php else: ?>
        <div class="glass p-3 p-md-4 fade-up">
          <form method="post" enctype="multipart/form-data" id="bizProfileForm" data-unsaved-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="latitude" id="bizLat" value="<?= e((string) $initLat) ?>">
            <input type="hidden" name="longitude" id="bizLng" value="<?= e((string) $initLng) ?>">
          <div class="row g-3">
              <?php
                $listing = $biz;
                $listing['owner_sex'] = (string) ($user['sex'] ?? '');
                $listingFieldsPaired = true;
                $listingApproved = business_listing_is_approved($biz);
                $listingHideLogo = !$listingApproved;
                $listingMarkLogo = $listingApproved && trim((string) ($biz['logo'] ?? '')) === '';
                $listingMarkDesc = $listingApproved && trim((string) ($biz['description'] ?? '')) === '';
                $listingMarkPhotos = $listingApproved
                    && trim((string) ($biz['cover_image'] ?? '')) === ''
                    && count($bizPhotos) < 1;
                $bizLatRaw = $biz['latitude'] ?? null;
                $bizLngRaw = $biz['longitude'] ?? null;
                $listingMarkPin = $listingApproved && ($bizLatRaw === null || $bizLngRaw === null || $bizLatRaw === '' || $bizLngRaw === '');
                require __DIR__ . '/../admin/_business_listing_fields.php';
                $listingFieldsPaired = false;
                $listingHideLogo = false;
              ?>
              <div class="col-12">
                <label class="form-label">Proof of Business Registration</label>
                <div class="admin-biz-proof-actions">
                  <div class="admin-biz-proof-actions__file">
                    <input type="file" class="form-control form-control-sm" name="document_files[]" accept=".pdf,.doc,.docx,image/*" multiple>
                  </div>
                  <button type="button" class="btn btn-sm btn-ct admin-biz-proof-view" data-open-proof-card>View</button>
                </div>
              </div>
              <?php if (!$listingApproved): ?>
                <div class="col-12">
                  <div class="alert alert-warning mb-0">Logo, description, photos, map pin, operating hours, and <?= e($catalogCopy['plural']) ?> can be added after the Tourism Office approves your business.</div>
                </div>
              <?php else: ?>
              <div class="col-12">
                <div class="admin-biz-media">
                  <div class="admin-biz-media__desc">
                    <label class="form-label">Business Description</label>
                    <textarea class="form-control<?= !empty($listingMarkDesc) ? ' is-incomplete' : '' ?>" name="description" rows="8"><?= e((string) ($biz['description'] ?? '')) ?></textarea>
                  </div>
                  <div class="admin-biz-media__files">
                    <div class="admin-biz-media__cover<?= !empty($listingMarkPhotos) ? ' is-incomplete' : '' ?>"<?= !empty($listingMarkPhotos) ? ' data-incomplete-group="biz-photos"' : '' ?> data-avatar-preview>
                      <label class="form-label">Cover photo</label>
                      <div class="admin-biz-preview admin-biz-preview--cover" data-avatar-frame>
                        <?php if (!empty($biz['cover_image'])): ?>
                          <img src="<?= APP_URL ?>/uploads/<?= e($biz['cover_image']) ?>" alt="Cover photo">
                        <?php else: ?>
                          <span class="admin-biz-preview__empty"><i class="bi bi-image"></i> No cover</span>
                        <?php endif; ?>
                      </div>
                      <input type="file" class="form-control form-control-sm" name="cover_image" data-avatar-input accept="image/*">
                    </div>
                    <div class="admin-biz-media__photos<?= !empty($listingMarkPhotos) ? ' is-incomplete' : '' ?>"<?= !empty($listingMarkPhotos) ? ' data-incomplete-group="biz-photos"' : '' ?>>
                      <label class="form-label">Business Photos</label>
                      <div class="admin-biz-proof-actions">
                        <div class="admin-biz-proof-actions__file">
                          <input type="file" class="form-control form-control-sm" name="business_photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                        </div>
                        <button type="button" class="btn btn-sm btn-ct admin-biz-proof-view" data-open-photos-card>View</button>
                      </div>
                    </div>
                    <?= admin_business_social_fields_html($biz, 'bizProfileForm') ?>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </form>

          <?php if ($listingApproved): ?>
          <div class="admin-biz-place-split admin-biz-place-split--map-hours mt-2">
            <div class="admin-biz-place-split__map<?= !empty($listingMarkPin) ? ' is-incomplete' : '' ?>">
              <h3 class="h6 mb-1">Pin on map</h3>
              <p class="small text-muted mb-2">The pin is locked. Click Edit pin on the map to move it, then save changes.</p>
              <div id="bizPinMap" class="ct-map"></div>
            </div>
            <div class="admin-biz-place-split__hours">
              <h3 class="h6 mb-1">Operating hours</h3>
              <p class="small text-muted mb-2">Select open days and set start / end times, or check 24 hrs. Unchecked days are closed.</p>
              <div class="guide-profile-avail">
                <?= opening_hours_avail_rows_html('bizHours', $availByDay, false, 'bizProfileForm') ?>
                <div class="admin-biz-place-split__visit mt-2">
                  <?= visit_duration_field_html('bizVisit', $biz['visit_duration_minutes'] ?? null, 'bizProfileForm') ?>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div class="mt-3">
            <button type="submit" form="bizProfileForm" class="btn btn-ct">Save changes</button>
          </div>
        </div>

        <div id="bizProofCard" class="admin-biz-proof-overlay" hidden>
          <div class="admin-biz-proof-card" role="dialog" aria-labelledby="bizProofCardTitle" aria-modal="true">
            <div class="admin-biz-proof-card__head">
              <h2 class="h6 mb-0" id="bizProofCardTitle">Proof of Business Registration</h2>
              <button type="button" class="admin-biz-proof-card__close" data-close-proof-card aria-label="Close">&times;</button>
            </div>
            <div class="admin-biz-proof-card__body">
              <?php if ($bizDocs): ?>
                <ul class="admin-biz-proof-card__links">
                  <?php foreach ($bizDocs as $d):
                    $docName = $d['original_name'] ?: basename((string) $d['file_path']);
                    $docHref = APP_URL . '/uploads/' . $d['file_path'];
                    $ext = strtolower(pathinfo((string) $d['file_path'], PATHINFO_EXTENSION));
                    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                  ?>
                    <li>
                      <a class="admin-biz-proof-link"
                         href="<?= e($docHref) ?>"
                         <?= $isImg ? 'data-proof-preview="' . e($docHref) . '"' : 'target="_blank" rel="noopener"' ?>>
                        <i class="bi bi-file-earmark-text"></i> <?= e($docName) ?>
                      </a>
                    </li>
                <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="small text-muted mb-0">No proof file is saved for this listing yet. Choose a file, then click Save changes.</p>
              <?php endif; ?>
              <div class="admin-biz-proof-card__pic" hidden>
                <img alt="Proof of business registration">
              </div>
            </div>
          </div>
        </div>
        <div id="bizPhotosCard" class="admin-biz-proof-overlay" hidden>
          <div class="admin-biz-proof-card admin-biz-proof-card--photos" role="dialog" aria-labelledby="bizPhotosCardTitle" aria-modal="true">
            <div class="admin-biz-proof-card__head">
              <h2 class="h6 mb-0" id="bizPhotosCardTitle">Business Photos</h2>
              <button type="button" class="admin-biz-proof-card__close" data-close-photos-card aria-label="Close">&times;</button>
            </div>
            <div class="admin-biz-proof-card__body">
              <?php if ($bizPhotos):
                $photoUrls = array_values(array_map(static function ($ph) {
                    return APP_URL . '/uploads/' . $ph['image_path'];
                }, $bizPhotos));
                $firstPhotoHref = $photoUrls[0];
                $photoCount = count($photoUrls);
              ?>
                <div class="admin-biz-photo-stage<?= $photoCount < 2 ? ' admin-biz-photo-stage--single' : '' ?>" data-photo-index="0">
                  <div class="admin-biz-photo-stage__viewport">
                    <?php if ($photoCount > 1): ?>
                      <button type="button" class="ct-rail__arrow ct-rail__arrow--prev" data-photo-prev aria-label="Previous photo">
                        <i class="bi bi-chevron-left"></i>
                      </button>
                    <?php endif; ?>
                    <div class="admin-biz-photo-stage__track">
                      <div class="admin-biz-proof-card__pic admin-biz-photo-stage__main">
                        <img src="<?= e($firstPhotoHref) ?>" alt="Business photo">
                      </div>
                      <?php if ($photoCount > 1): ?>
                        <button type="button" class="admin-biz-photo-stage__preview" data-photo-peek="1" aria-label="Next photo">
                          <img src="<?= e($photoUrls[1]) ?>" alt="">
                        </button>
                      <?php endif; ?>
                      <?php if ($photoCount > 2): ?>
                        <button type="button" class="admin-biz-photo-stage__preview" data-photo-peek="2" aria-label="Photo after next">
                          <img src="<?= e($photoUrls[2]) ?>" alt="">
                        </button>
                      <?php endif; ?>
                    </div>
                    <?php if ($photoCount > 1): ?>
                      <button type="button" class="ct-rail__arrow ct-rail__arrow--next" data-photo-next aria-label="Next photo">
                        <i class="bi bi-chevron-right"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                  <div class="d-none" data-photo-sources>
                    <?php foreach ($photoUrls as $photoHref): ?>
                      <a href="<?= e($photoHref) ?>" data-proof-preview="<?= e($photoHref) ?>"></a>
                    <?php endforeach; ?>
                  </div>
      </div>
              <?php else: ?>
                <p class="small text-muted mb-0">No photos are saved for this listing yet. Choose files, then click Save changes.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
      <?php endif; ?>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
if ($biz) {
    render_unsaved_changes_modal();
}
$latJs = $initLat !== '' && $initLat !== null ? (float) $initLat : 'null';
$lngJs = $initLng !== '' && $initLng !== null ? (float) $initLng : 'null';
$appUrl = APP_URL;
$phJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time());
$extraScripts = map_leaflet_scripts() . <<<HTML
<script src="{$appUrl}/assets/js/ph-address.js?v={$phJsVer}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('{$appUrl}/assets/data/ph-address.json');
  }
  if (document.getElementById('bizPinMap')) {
    CulTourMap.renderPinPicker('bizPinMap', {
      latInput: '#bizLat',
      lngInput: '#bizLng',
      initialLat: {$latJs},
      initialLng: {$lngJs},
      lockUntilEdit: true,
      kind: 'business',
      colorSelect: '#bizProfileForm select[name="business_type_id"]'
    });
  }
  function bindBizFileCard(cardId, openAttr, closeAttr) {
    const card = document.getElementById(cardId);
    if (!card) return;
    document.body.appendChild(card);
    const dialog = card.querySelector('.admin-biz-proof-card');
    const picWrap = card.querySelector('.admin-biz-proof-card__pic');
    const img = card.querySelector('.admin-biz-proof-card__pic img');
    const openSel = '[' + openAttr + ']';
    const closeSel = '[' + closeAttr + ']';
    function syncBodyLock() {
      const anyOpen = document.querySelector('.admin-biz-proof-overlay:not([hidden])');
      document.body.classList.toggle('admin-biz-proof-open', !!anyOpen);
    }
    function photoSources() {
      return Array.prototype.map.call(card.querySelectorAll('[data-proof-preview]'), function (a) {
        return a.getAttribute('data-proof-preview');
      }).filter(Boolean);
    }
    function showPreview(src) {
      if (!src) return;
      const list = photoSources();
      let idx = list.indexOf(src);
      if (idx < 0) idx = 0;
      if (img) img.src = list[idx] || src;
      if (picWrap) picWrap.hidden = false;
      if (dialog) dialog.classList.add('is-previewing');
      const stage = card.querySelector('.admin-biz-photo-stage');
      if (stage) stage.setAttribute('data-photo-index', String(idx));
      card.querySelectorAll('[data-photo-peek]').forEach(function (el) {
        const offset = parseInt(el.getAttribute('data-photo-peek'), 10) || 0;
        const peekImg = el.querySelector('img');
        if (list.length <= offset) {
          el.hidden = true;
          return;
        }
        const peekSrc = list[(idx + offset) % list.length];
        el.hidden = false;
        if (peekImg) peekImg.src = peekSrc;
      });
    }
    function showPhotoByOffset(step) {
      const list = photoSources();
      if (list.length < 2) return;
      const current = img ? img.getAttribute('src') : '';
      let idx = list.indexOf(current);
      if (idx < 0) idx = 0;
      showPreview(list[(idx + step + list.length) % list.length]);
    }
    function openCard() {
      card.hidden = false;
      const first = card.querySelector('[data-proof-preview]');
      if (first) showPreview(first.getAttribute('data-proof-preview'));
      syncBodyLock();
    }
    function closeCard() {
      card.hidden = true;
      if (dialog) dialog.classList.remove('is-previewing');
      if (picWrap) picWrap.hidden = true;
      if (img) img.removeAttribute('src');
      syncBodyLock();
    }
    document.querySelectorAll(openSel).forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openCard();
      });
    });
    const prevBtn = card.querySelector('[data-photo-prev]');
    const nextBtn = card.querySelector('[data-photo-next]');
    if (prevBtn) {
      prevBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showPhotoByOffset(-1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showPhotoByOffset(1);
      });
    }
    card.querySelectorAll('[data-photo-peek]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const peekImg = el.querySelector('img');
        if (peekImg && peekImg.getAttribute('src')) showPreview(peekImg.getAttribute('src'));
      });
    });
    document.addEventListener('click', function (e) {
      if (e.target.closest(openSel)) {
        e.preventDefault();
        e.stopPropagation();
        openCard();
        return;
      }
      if (e.target.closest('[data-photo-prev], [data-photo-next], [data-photo-peek]')) return;
      const link = e.target.closest('#' + cardId + ' [data-proof-preview]');
      if (link) {
        e.preventDefault();
        e.stopPropagation();
        showPreview(link.getAttribute('data-proof-preview'));
        return;
      }
      if (e.target === card || e.target.closest(closeSel)) {
        closeCard();
      }
    }, true);
    document.addEventListener('keydown', function (e) {
      if (card.hidden) return;
      if (e.key === 'Escape') closeCard();
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        showPhotoByOffset(1);
      }
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        showPhotoByOffset(-1);
      }
    });
  }
  bindBizFileCard('bizProofCard', 'data-open-proof-card', 'data-close-proof-card');
  bindBizFileCard('bizPhotosCard', 'data-open-photos-card', 'data-close-photos-card');
});
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
