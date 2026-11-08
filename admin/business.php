<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/business_engagement.php';
$user = require_login(ROLE_ADMIN);
ensure_place_schedule_schema();
ensure_business_documents_schema();
ensure_business_listing_schema();
ensure_business_reviews_schema();

$id = (int) ($_GET['id'] ?? 0);
$s = db()->prepare(
    "SELECT b.*, bt.name AS type_name, bt.pin_color,
            u.first_name AS account_first_name,
            u.last_name AS account_last_name,
            u.email AS account_email,
            u.status AS account_status
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     JOIN users u ON u.id = b.user_id
     WHERE b.id = ?"
);
$s->execute([$id]);
$biz = $s->fetch();
if (!$biz) {
    flash('error', 'Business not found.');
    redirect(APP_URL . '/admin/businesses.php');
}

$pageTitle = $biz['name'];
$pageHeading = $biz['name'];
$isPendingBiz = ($biz['approval_status'] ?? '') === 'pending';
$pageSub = ($biz['type_name'] ?? 'Business') . ' · Full listing details';
$activeNav = 'businesses';

$raw = $biz['opening_hours'] ?? null;
if (is_array($raw)) {
    $raw = json_encode($raw);
}
$hours = parse_opening_hours_value($raw) ?: [];
$availByDay = opening_hours_to_avail_by_day($hours);
$dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$services = db()->prepare('SELECT * FROM business_services WHERE business_id=? ORDER BY name');
$services->execute([$id]);
$services = $services->fetchAll() ?: [];
$bizDocs = business_documents($id);
$ps = db()->prepare('SELECT id, image_path FROM business_photos WHERE business_id=? ORDER BY created_at DESC');
$ps->execute([$id]);
$bizPhotos = $ps->fetchAll() ?: [];

$repName = trim((string) ($biz['representative_name'] ?? ''));
$ownerDisplay = trim((string) ($biz['owner_name'] ?? ''));
if ($ownerDisplay === '') {
    $ownerDisplay = trim(($biz['account_first_name'] ?? '') . ' ' . ($biz['account_last_name'] ?? ''));
}
$logoUrl = business_logo_url($biz, $bizPhotos, $bizDocs);
$coverUrl = !empty($biz['cover_image']) ? (APP_URL . '/uploads/' . $biz['cover_image']) : '';
$initLat = $biz['latitude'] ?? '';
$initLng = $biz['longitude'] ?? '';
$listingStatus = (string) ($biz['status'] ?? 'active');

$val = static function ($v): string {
    $v = trim((string) $v);
    return $v !== '' ? e($v) : '—';
};

require __DIR__ . '/../includes/header.php';
map_leaflet_head();
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="mb-3 d-flex flex-wrap gap-2 admin-profile-actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/businesses.php">&larr; Back to businesses</a>
        <?php if (!$isPendingBiz): ?>
        <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/businesses.php?view=<?= $id ?>">Edit listing</a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#activityLogModal">Activity logs</button>
      </div>

      <div class="glass p-3 p-md-4 mb-3 fade-up admin-guide-profile">
        <div class="admin-guide-profile__hero">
          <div class="admin-guide-profile__photo<?= $logoUrl ? ' admin-guide-profile__photo--logo' : '' ?>">
            <?php if ($logoUrl): ?>
              <img src="<?= e($logoUrl) ?>" alt="<?= e($biz['name']) ?>">
            <?php else: ?>
              <div class="admin-guide-profile__photo-fallback" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $biz['name'], 0, 1))) ?></div>
            <?php endif; ?>
          </div>
          <div class="admin-guide-profile__intro">
            <div class="small text-muted mb-1">
              <?= e($biz['type_name'] ?? 'Business') ?> · <?= status_badge($biz['approval_status']) ?>
              <?php if (($biz['approval_status'] ?? '') === 'approved'): ?>
                · <?= status_badge($listingStatus === 'inactive' || ($biz['account_status'] ?? '') === 'suspended' ? 'hidden on map' : $listingStatus) ?>
              <?php endif; ?>
            </div>
            <h2 class="h4 mb-1"><?= e($biz['name']) ?></h2>
            <div class="text-muted">
              <?= $val($biz['email'] ?? '') ?>
              <?= trim((string) ($biz['phone'] ?? '')) !== '' ? ' · ' . e($biz['phone']) : '' ?>
            </div>
            <div class="admin-guide-profile__rating">
              <?= rating_stars_html($biz['avg_rating'] ?? 0, (int) ($biz['review_count'] ?? 0), 'lg') ?>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-7">
          <div class="glass p-3 p-md-4 h-100 fade-up">
            <h3 class="panel-title mb-3">Listing details</h3>
            <dl class="admin-guide-profile__dl mb-0">
              <div>
                <dt>Representative name</dt>
                <dd><?= $val($repName) ?></dd>
              </div>
              <div>
                <dt>Business owner name</dt>
                <dd><?= $val($ownerDisplay) ?></dd>
              </div>
              <div>
                <dt>Business category</dt>
                <dd><?= $val($biz['type_name'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Average rating</dt>
                <dd><?= rating_stars_html($biz['avg_rating'] ?? 0, (int) ($biz['review_count'] ?? 0), 'sm') ?></dd>
              </div>
              <div>
                <dt>Owner account email</dt>
                <dd><?= $val($biz['account_email'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Business email</dt>
                <dd><?= $val($biz['email'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Business contact number</dt>
                <dd><?= $val($biz['phone'] ?? '') ?></dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>Business address</dt>
                <dd>
                  <?= $val($biz['address'] ?? '') ?>
                  <?php if (trim((string) ($biz['address_street'] ?? '')) !== '' || trim((string) ($biz['address_barangay'] ?? '')) !== ''): ?>
                    <div class="small text-muted mt-1">
                      <?= e(trim((string) ($biz['address_street'] ?? ''))) ?>
                      <?= trim((string) ($biz['address_street'] ?? '')) !== '' && trim((string) ($biz['address_barangay'] ?? '')) !== '' ? ' · ' : '' ?>
                      <?= e(trim((string) ($biz['address_barangay'] ?? ''))) ?>
                      <?= trim((string) ($biz['address_city'] ?? '')) !== '' ? ' · ' . e((string) $biz['address_city']) : '' ?>
                    </div>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>Business permit / registration number</dt>
                <dd><?= $val($biz['permit_number'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Tax identification number</dt>
                <dd><?= $val($biz['tax_id'] ?? '') ?></dd>
              </div>
              <?php $social = business_social_hrefs($biz); ?>
              <?php if ($social['facebook'] !== ''): ?>
              <div>
                <dt>Facebook</dt>
                <dd>
                  <a href="<?= e($social['facebook']) ?>" target="_blank" rel="noopener"><?= e(public_url_label($social['facebook'])) ?></a>
                </dd>
              </div>
              <?php endif; ?>
              <?php if ($social['instagram'] !== ''): ?>
              <div>
                <dt>Instagram</dt>
                <dd>
                  <a href="<?= e($social['instagram']) ?>" target="_blank" rel="noopener"><?= e(public_url_label($social['instagram'])) ?></a>
                </dd>
              </div>
              <?php endif; ?>
              <div>
                <dt>Listing status</dt>
                <dd><?= e(ucfirst($listingStatus)) ?></dd>
              </div>
              <div>
                <dt>Map pin</dt>
                <dd>
                  <?php if ($initLat !== '' && $initLat !== null && $initLng !== '' && $initLng !== null): ?>
                    <?= e(number_format((float) $initLat, 6)) ?>, <?= e(number_format((float) $initLng, 6)) ?>
                  <?php else: ?>
                    Not pinned
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>Visit duration</dt>
                <dd><?= e(place_visit_duration_display($biz['visit_duration_minutes'] ?? null)) ?></dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>Business description</dt>
                <dd><?= trim((string) ($biz['description'] ?? '')) !== '' ? nl2br(e((string) $biz['description'])) : '—' ?></dd>
              </div>
            </dl>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="glass p-3 p-md-4 mb-3 fade-up">
            <h3 class="panel-title mb-3">Cover photo</h3>
            <?php if ($coverUrl): ?>
              <img src="<?= e($coverUrl) ?>" alt="" class="rounded border w-100" style="max-height:220px;object-fit:cover">
            <?php else: ?>
              <p class="text-muted small mb-0">No cover photo.</p>
            <?php endif; ?>
          </div>
          <div class="glass p-3 p-md-4 mb-3 fade-up">
            <h3 class="panel-title mb-3">Business photos</h3>
            <?php if ($bizPhotos): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($bizPhotos as $ph): ?>
                  <a href="<?= APP_URL ?>/uploads/<?= e($ph['image_path']) ?>" target="_blank" rel="noopener">
                    <img src="<?= APP_URL ?>/uploads/<?= e($ph['image_path']) ?>" alt="" class="rounded border" style="width:72px;height:72px;object-fit:cover">
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-muted small mb-0">No photos uploaded.</p>
            <?php endif; ?>
          </div>
          <div class="glass p-3 p-md-4 fade-up">
            <h3 class="panel-title mb-3">Proof of Business Registration</h3>
            <?php if ($bizDocs): ?>
              <ul class="list-unstyled mb-0 admin-guide-profile__docs">
                <?php foreach ($bizDocs as $d): ?>
                  <li>
                    <a href="<?= APP_URL ?>/uploads/<?= e($d['file_path']) ?>" target="_blank" rel="noopener">
                      <i class="bi bi-file-earmark-text"></i>
                      <?= e($d['original_name'] ?: basename((string) $d['file_path'])) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-muted small mb-0">No documents uploaded.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-5">
          <div class="glass p-3 p-md-4 h-100 fade-up">
            <h3 class="panel-title mb-1">Pin on map</h3>
            <p class="small text-muted mb-2">Saved location for this listing.</p>
            <div id="bizInfoMap" class="ct-map"></div>
          </div>
        </div>
        <div class="col-lg-3">
          <div class="glass p-3 p-md-4 h-100 fade-up">
            <h3 class="panel-title mb-3">Operating hours</h3>
            <ul class="list-unstyled small mb-0">
              <?php for ($i = 0; $i < 7; $i++): ?>
                <li class="d-flex justify-content-between gap-2 py-1 border-bottom">
                  <span><?= e($dayLabels[$i]) ?></span>
                  <span class="text-muted">
                    <?php if (!empty($availByDay[$i]['on'])): ?>
                      <?= e($availByDay[$i]['start']) ?> – <?= e($availByDay[$i]['end']) ?>
                    <?php else: ?>
                      Closed
                    <?php endif; ?>
                  </span>
                </li>
              <?php endfor; ?>
            </ul>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="glass p-3 p-md-4 h-100 fade-up">
            <h3 class="panel-title mb-3"><?= e(business_catalog_copy($biz['type_name'] ?? '')['label']) ?></h3>
            <?php if ($services): ?>
              <ul class="list-unstyled small mb-0">
                <?php foreach ($services as $svc): ?>
                  <li class="py-1 border-bottom">
                    <?php $offerMode = business_catalog_mode($biz['type_name'] ?? ''); ?>
                    <span class="fw-semibold"><?= e($svc['name']) ?></span>
                    <?php if (in_array($offerMode, ['rooms', 'vehicles', 'packages', 'menu'], true)): ?>
                      <span class="text-muted"> · <?= e(format_money($svc['price'])) ?></span>
                      <?php if ($offerMode === 'packages' && (int) ($svc['package_days'] ?? 0) > 0): ?>
                        · <?= e(package_days_label((int) $svc['package_days'])) ?>
                        <?php if ((int) ($svc['capacity'] ?? 0) > 0): ?>
                          · <?= e(package_pax_label((int) $svc['capacity'])) ?>
                        <?php endif; ?>
                      <?php elseif (!empty($svc['duration_minutes'])): ?>
                        · <?= (int) $svc['duration_minutes'] ?> min
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if (in_array($offerMode, ['rooms', 'vehicles', 'packages', 'menu'], true) && trim((string) ($svc['description'] ?? '')) !== ''): ?>
                      <div class="text-muted"><?= e($svc['description']) ?></div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-muted small mb-0">No <?= e(business_catalog_copy($biz['type_name'] ?? '')['plural']) ?> listed yet.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php
        $activityLogs = user_activity_logs((int) ($biz['user_id'] ?? 0));
        require __DIR__ . '/../includes/admin_subject_activity_logs.php';
      ?>
    </div>
  </div>
</div>
<?php
$latJs = $initLat !== '' && $initLat !== null ? (float) $initLat : 'null';
$lngJs = $initLng !== '' && $initLng !== null ? (float) $initLng : 'null';
$pinColorJs = json_encode(normalize_pin_color($biz['pin_color'] ?? ''));
$extraScripts = map_leaflet_scripts() . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.CulTourMap) return;
  CulTourMap.renderPinPicker('bizInfoMap', {
    latInput: null,
    lngInput: null,
    initialLat: {$latJs},
    initialLng: {$lngJs},
    readOnly: true,
    kind: 'business',
    color: {$pinColorJs}
  });
});
</script>
HTML;
require __DIR__ . '/../includes/footer.php';
?>
