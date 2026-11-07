<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_BUSINESS);
ensure_place_schedule_schema();

$biz = db()->prepare(
    'SELECT b.*, bt.name AS type_name
     FROM businesses b
     JOIN business_types bt ON bt.id = b.business_type_id
     WHERE b.user_id=? LIMIT 1'
);
$biz->execute([$user['id']]);
$biz = $biz->fetch();
$copy = business_catalog_copy($biz['type_name'] ?? '');
$mode = $copy['mode'];
$isRooms = $mode === 'rooms';
$isVehicles = $mode === 'vehicles';
$pageTitle = $copy['label'];
$pageHeading = $copy['heading'];
$pageSub = $copy['sub'];
$activeNav = 'services';

if ($biz && !business_listing_is_approved($biz)) {
    flash('error', 'This can be added after the Tourism Office approves your business.');
    redirect(APP_URL . '/business/dashboard.php');
}

if ($biz && $_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    flash('error', csrf_error_message());
} elseif ($biz && $_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (($_POST['action'] ?? '') === 'update_units' && ($isRooms || $isVehicles)) {
        $svcId = (int) ($_POST['id'] ?? 0);
        $unitsRaw = trim((string) ($_POST['unit_count'] ?? ''));
        $feeRaw = trim((string) ($_POST['extra_guest_fee'] ?? ''));
        $limitRaw = trim((string) ($_POST['extra_guest_limit'] ?? ''));
        $capRaw = trim((string) ($_POST['capacity'] ?? ''));
        if ($feeRaw !== '' && (float) $feeRaw < 0) {
            flash('error', 'Extra-person fee cannot be negative.');
        } elseif ($isVehicles && ($capRaw === '' || (int) $capRaw < 1)) {
            flash('error', 'Enter how many seats this vehicle has.');
        } elseif ($capRaw !== '' && (int) $capRaw < 1) {
            flash('error', 'Guests included must be at least 1.');
        } elseif ($isRooms && ($unitsRaw === '' || (int) $unitsRaw < 1)) {
            flash('error', 'Enter how many of this room you have.');
        } elseif ($isVehicles && $unitsRaw !== '' && (int) $unitsRaw < 1) {
            flash('error', 'Enter how many of this vehicle you have, or leave it blank for no limit.');
        } else {
            $amenityRaw = trim((string) ($_POST['amenities'] ?? ''));
            if (strlen($amenityRaw) > 255) {
                $amenityRaw = substr($amenityRaw, 0, 255);
            }
            db()->prepare(
                'UPDATE business_services
                 SET unit_count=?, capacity=?, extra_guest_fee=?, extra_guest_limit=?, amenities=?
                 WHERE id=? AND business_id=?'
            )->execute([
                $unitsRaw === '' ? null : (int) $unitsRaw,
                $capRaw === '' ? null : (int) $capRaw,
                $isVehicles ? null : ($feeRaw === '' ? null : round((float) $feeRaw, 2)),
                $isVehicles ? null : ($limitRaw === '' ? null : max(0, (int) $limitRaw)),
                $amenityRaw !== '' ? $amenityRaw : null,
                $svcId,
                $biz['id'],
            ]);
            flash('success', $copy['updated']);
        }
    } elseif (($_POST['action'] ?? '') === 'toggle_package' && $mode === 'packages') {
        $svcId = (int) ($_POST['id'] ?? 0);
        $own = db()->prepare('SELECT is_active FROM business_services WHERE id=? AND business_id=? LIMIT 1');
        $own->execute([$svcId, $biz['id']]);
        $own = $own->fetch();
        if (!$own) {
            flash('error', 'Package not found.');
        } else {
            $visible = (int) ($own['is_active'] ?? 0) === 1 ? 0 : 1;
            db()->prepare('UPDATE business_services SET is_active=? WHERE id=? AND business_id=?')
                ->execute([$visible, $svcId, $biz['id']]);
            flash('success', $visible ? 'Package is visible to tourists again.' : 'Package hidden. Tourists will not see it.');
        }
    } elseif (($_POST['action'] ?? '') === 'update_package' && $mode === 'packages') {
        $svcId = (int) ($_POST['id'] ?? 0);
        $own = db()->prepare('SELECT image_path FROM business_services WHERE id=? AND business_id=? LIMIT 1');
        $own->execute([$svcId, $biz['id']]);
        $own = $own->fetch();
        $svc = $own ? parse_place_service_post($_POST) : ['ok' => false, 'message' => 'Package not found.'];
        $daysParsed = ($own && !empty($svc['ok'])) ? package_days_from_post($_POST) : null;
        if (!$own) {
            flash('error', 'Package not found.');
        } elseif (empty($svc['ok'])) {
            flash('error', (string) ($svc['message'] ?? 'Name is required.'));
        } elseif (!$daysParsed['ok']) {
            flash('error', $daysParsed['message']);
        } else {
            $image = trim((string) ($own['image_path'] ?? ''));
            if (!empty($_FILES['image']['name'])) {
                $uploaded = upload_file($_FILES['image'], 'businesses');
                if (!$uploaded) {
                    flash('error', 'Photo upload failed. Use a JPG, PNG, or WebP image.');
                    redirect(posted_return_to(APP_URL . '/business/services.php'));
                }
                $image = $uploaded;
            }
            $d = $svc['data'];
            db()->prepare(
                'UPDATE business_services
                 SET name=?, description=?, price=?, package_days=?, image_path=?
                 WHERE id=? AND business_id=?'
            )->execute([
                $d['name'],
                $d['description'],
                $d['price'],
                $daysParsed['days'],
                $image !== '' ? $image : null,
                $svcId,
                $biz['id'],
            ]);
            flash('success', $copy['updated']);
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM business_services WHERE id=? AND business_id=?')->execute([(int) $_POST['id'], $biz['id']]);
        flash('success', $copy['removed']);
    } else {
        $svc = parse_place_service_post($_POST);
        if (empty($svc['ok'])) {
            flash('error', (string) ($svc['message'] ?? 'Name is required.'));
            redirect(posted_return_to(APP_URL . '/business/services.php'));
        }
        $d = $svc['data'];
        if ($isRooms && (int) ($d['unit_count'] ?? 0) < 1) {
            flash('error', 'Enter how many of this room you have.');
            redirect(posted_return_to(APP_URL . '/business/services.php'));
        }
        if ($isVehicles && (int) ($d['capacity'] ?? 0) < 1) {
            flash('error', 'Enter how many seats this vehicle has.');
            redirect(posted_return_to(APP_URL . '/business/services.php'));
        }
        $packageDays = null;
        if ($mode === 'packages') {
            $daysParsed = package_days_from_post($_POST);
            if (!$daysParsed['ok']) {
                flash('error', $daysParsed['message']);
                redirect(posted_return_to(APP_URL . '/business/services.php'));
            }
            $packageDays = $daysParsed['days'];
        }
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $image = upload_file($_FILES['image'], 'businesses');
            if (!$image) {
                flash('error', 'Photo upload failed. Use a JPG, PNG, or WebP image.');
                redirect(posted_return_to(APP_URL . '/business/services.php'));
            }
        }
        db()->prepare(
            'INSERT INTO business_services (business_id, name, description, price, duration_minutes, package_days, capacity, unit_count, extra_guest_fee, extra_guest_limit, amenities, image_path)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $biz['id'],
            $d['name'],
            in_array($mode, ['rooms', 'vehicles', 'packages'], true) ? $d['description'] : null,
            in_array($mode, ['rooms', 'vehicles', 'packages'], true) ? $d['price'] : 0,
            in_array($mode, ['rooms', 'vehicles', 'packages'], true) ? $d['duration_minutes'] : null,
            $packageDays,
            $d['capacity'],
            ($isRooms || $isVehicles) ? $d['unit_count'] : null,
            $isRooms ? $d['extra_guest_fee'] : null,
            $isRooms ? $d['extra_guest_limit'] : null,
            $d['amenities'],
            $image,
        ]);
        flash('success', $copy['added']);
    }
    redirect(posted_return_to(APP_URL . '/business/services.php'));
}

$rows = [];
if ($biz) {
    $s = db()->prepare('SELECT * FROM business_services WHERE business_id=? ORDER BY is_active DESC, name');
    $s->execute([$biz['id']]);
    $rows = $s->fetchAll();
}

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
$loadLangTags = $isRooms || $isVehicles;
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
$noun = $copy['noun'];
$priceHint = $isRooms ? 'Price per night' : ($isVehicles ? 'Price per day' : 'Price');
$capacityHint = $isRooms ? 'Guests' : 'Seats';
$detailHint = $isRooms ? 'Amenities, separated by commas' : 'Features, separated by commas';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="glass p-3 p-md-4">
            <h2 class="h6 mb-3">Add a <?= e($noun) ?></h2>
            <form method="post" enctype="multipart/form-data" data-unsaved-guard><?= csrf_field() ?>
              <input type="hidden" name="return_to" value="">
              <div class="mb-2">
                <label class="form-label"><?= e($copy['nameLabel']) ?></label>
                <input class="form-control" name="name"<?= in_array($mode, ['rooms', 'vehicles', 'packages'], true) ? '' : ' placeholder="' . e($copy['placeholder']) . '"' ?> required>
              </div>
              <?php if (in_array($mode, ['rooms', 'vehicles', 'packages'], true)): ?>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"></textarea>
              </div>
              <?php endif; ?>
              <?php if (in_array($mode, ['rooms', 'vehicles', 'packages'], true)): ?>
              <div class="row g-2 mb-2">
                <div class="col-6">
                  <label class="form-label"><?= e($priceHint) ?></label>
                  <input type="number" step="0.01" min="0" class="form-control" name="price">
                </div>
                <?php if ($isRooms || $isVehicles): ?>
                  <div class="col-6">
                    <label class="form-label"><?= $isRooms ? 'How many rooms' : 'How many vehicles' ?></label>
                    <input type="number" min="1" class="form-control" name="unit_count"<?= $isRooms ? ' required' : '' ?>>
                  </div>
                  <div class="col-6">
                    <label class="form-label"><?= e($capacityHint) ?></label>
                    <input type="number" min="1" class="form-control" name="capacity"<?= $isVehicles ? ' required' : '' ?>>
                  </div>
                  <?php if ($isRooms): ?>
                  <div class="col-6">
                    <label class="form-label">Extra-person fee</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="extra_guest_fee">
                  </div>
                  <div class="col-6">
                    <label class="form-label">Extra guests allowed</label>
                    <input type="number" min="0" class="form-control" name="extra_guest_limit">
                  </div>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($mode === 'packages'): ?>
                  <div class="col-6">
                    <label class="form-label">How many days</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" class="form-control" name="package_days" required autocomplete="off" oninput="this.value=this.value.replace(/\D/g,'')">
                  </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if ($isRooms || $isVehicles): ?>
                <div class="mb-2">
                  <label class="form-label"><?= $isRooms ? 'Amenities' : 'Features' ?></label>
                  <input class="form-control js-tag-input" name="amenities" maxlength="255"<?= $isRooms ? ' placeholder="Queen bed"' : '' ?>>
                  <div class="form-text">Type one <?= $isRooms ? 'amenity' : 'feature' ?>, then a comma.</div>
                </div>
              <?php endif; ?>
              <div class="mb-3">
                <label class="form-label">Photo</label>
                <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp">
              </div>
              <button class="btn btn-ct w-100">Add <?= e($noun) ?></button>
            </form>
          </div>
        </div>
        <div class="col-lg-8">
          <?php if (!$rows): ?>
            <div class="glass p-4 text-muted"><?= e($copy['empty']) ?></div>
          <?php else: ?>
            <div class="offer-manage-grid<?= $mode === 'packages' ? ' offer-manage-grid--paper' : '' ?>">
              <?php foreach ($rows as $r): ?>
                <?php
                  $img = trim((string) ($r['image_path'] ?? ''));
                  $imgUrl = $img !== '' ? APP_URL . '/uploads/' . ltrim($img, '/') : '';
                  $pkgHidden = $mode === 'packages' && (int) ($r['is_active'] ?? 1) !== 1;
                  $pkgDays = (int) ($r['package_days'] ?? 0);
                ?>
                <article class="offer-manage-card glass<?= $pkgHidden ? ' is-hidden' : '' ?>">
                  <?php if ($imgUrl !== ''): ?>
                    <img class="offer-manage-card__photo" src="<?= e($imgUrl) ?>" alt="">
                  <?php endif; ?>
                  <div class="offer-manage-card__body">
                    <div class="d-flex justify-content-between gap-2">
                      <h3 class="h6 mb-1">
                        <?= e($r['name']) ?>
                        <?php if ($pkgHidden): ?><span class="offer-manage-card__hidden">Hidden</span><?php endif; ?>
                      </h3>
                      <?php if (in_array($mode, ['rooms', 'vehicles', 'packages'], true)): ?>
                        <strong><?= e(format_money($r['price'])) ?></strong>
                      <?php endif; ?>
                    </div>
                    <?php if ($isRooms || $isVehicles): ?>
                      <div class="small text-muted mb-1">
                        <?= $isRooms ? 'Per night' : 'Per day' ?>
                        <?php if (!empty($r['capacity'])): ?>
                          · <?= (int) $r['capacity'] ?> <?= $isRooms ? 'guest' : 'seat' ?><?= (int) $r['capacity'] === 1 ? '' : 's' ?>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mode === 'packages'): ?>
                      <div class="small text-muted mb-1"><?= $pkgDays > 0 ? e(package_days_label($pkgDays)) : 'Days not set' ?></div>
                    <?php endif; ?>
                    <?php if (in_array($mode, ['rooms', 'vehicles', 'packages'], true) && !empty($r['description'])): ?>
                      <p class="small mb-1"><?= e($r['description']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($r['amenities']) && !($isRooms || $isVehicles)): ?>
                      <p class="small text-muted mb-2"><?= e($r['amenities']) ?></p>
                    <?php endif; ?>
                    <?php if ($isRooms || $isVehicles): ?>
                      <?php
                        $unitTotal = ($r['unit_count'] ?? null) === null || $r['unit_count'] === '' ? null : (int) $r['unit_count'];
                        $unitLeft = $unitTotal === null ? null : offering_units_free((int) $r['id'], date('Y-m-d'), date('Y-m-d'));
                        $feeValue = ($r['extra_guest_fee'] ?? null) === null || $r['extra_guest_fee'] === '' ? '' : (string) $r['extra_guest_fee'];
                        $limitValue = ($r['extra_guest_limit'] ?? null) === null || $r['extra_guest_limit'] === '' ? '' : (string) (int) $r['extra_guest_limit'];
                        $capValue = ($r['capacity'] ?? null) === null || $r['capacity'] === '' ? '' : (string) (int) $r['capacity'];
                      ?>
                      <form method="post" class="row g-2 align-items-end mb-2" data-unsaved-ignore><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <div class="col-6 col-lg-3">
                          <label class="form-label small mb-1"><?= $isRooms ? 'Rooms of this type' : 'Vehicles' ?></label>
                          <input type="number" min="1" class="form-control form-control-sm" name="unit_count" value="<?= $unitTotal ?? '' ?>"<?= $isRooms ? ' required' : '' ?>>
                        </div>
                        <div class="col-6 col-lg-3">
                          <label class="form-label small mb-1"><?= $isRooms ? 'Guests included' : 'Seats' ?></label>
                          <input type="number" min="1" class="form-control form-control-sm" name="capacity" value="<?= e($capValue) ?>"<?= $isVehicles ? ' required' : '' ?>>
                        </div>
                        <?php if ($isRooms): ?>
                        <div class="col-6 col-lg-3">
                          <label class="form-label small mb-1">Extra-person fee</label>
                          <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="extra_guest_fee" value="<?= e($feeValue) ?>">
                        </div>
                        <div class="col-6 col-lg-3">
                          <label class="form-label small mb-1">Extra guests</label>
                          <input type="number" min="0" class="form-control form-control-sm" name="extra_guest_limit" value="<?= e($limitValue) ?>">
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                          <label class="form-label small mb-1"><?= $isRooms ? 'Amenities' : 'Features' ?></label>
                          <input class="form-control form-control-sm js-tag-input" name="amenities" maxlength="255" value="<?= e((string) ($r['amenities'] ?? '')) ?>"<?= $isRooms ? ' placeholder="Queen bed"' : '' ?>>
                          <div class="form-text">Type one <?= $isRooms ? 'amenity' : 'feature' ?>, then a comma.</div>
                        </div>
                        <div class="col-12">
                          <button name="action" value="update_units" class="btn btn-sm btn-outline-secondary">Save</button>
                        </div>
                      </form>
                      <?php if ($isRooms && $unitLeft === null): ?>
                        <p class="small text-muted mb-2">Set how many rooms you have. Bookings will use them for those dates.</p>
                      <?php elseif ($isVehicles && $unitLeft === null): ?>
                        <p class="small text-muted mb-2">Leave the vehicle count blank for no limit.</p>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($mode === 'packages'): ?>
                      <div class="offer-manage-card__actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#packageEditModal"
                                data-pkg-id="<?= (int) $r['id'] ?>"
                                data-pkg-name="<?= e($r['name']) ?>"
                                data-pkg-desc="<?= e((string) ($r['description'] ?? '')) ?>"
                                data-pkg-price="<?= e((string) ($r['price'] ?? '0')) ?>"
                                data-pkg-days="<?= $pkgDays > 0 ? $pkgDays : '' ?>"
                                data-pkg-photo="<?= e($imgUrl) ?>">Edit</button>
                        <form method="post" data-unsaved-ignore><?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <button name="action" value="toggle_package" class="btn btn-sm btn-outline-secondary"><?= $pkgHidden ? 'Show' : 'Hide' ?></button>
                        </form>
                        <form method="post" data-unsaved-ignore><?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <button name="action" value="delete" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                      </div>
                    <?php else: ?>
                    <form method="post" data-unsaved-ignore><?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button name="action" value="delete" class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
?>
      <?php if ($mode === 'packages'): ?>
        <div class="modal fade" id="packageEditModal" tabindex="-1" aria-labelledby="packageEditLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <form method="post" enctype="multipart/form-data" data-unsaved-ignore>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_package">
                <input type="hidden" name="id" id="packageEditId" value="">
                <div class="modal-header">
                  <h2 class="modal-title h5 mb-0" id="packageEditLabel">Edit package</h2>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-2">
                    <label class="form-label" for="packageEditName">Package name</label>
                    <input class="form-control" id="packageEditName" name="name" required>
                  </div>
                  <div class="mb-2">
                    <label class="form-label" for="packageEditDesc">Description</label>
                    <textarea class="form-control" id="packageEditDesc" name="description" rows="3"></textarea>
                  </div>
                  <div class="row g-2 mb-2">
                    <div class="col-6">
                      <label class="form-label" for="packageEditPrice">Price</label>
                      <input type="number" step="0.01" min="0" class="form-control" id="packageEditPrice" name="price" required>
                    </div>
                    <div class="col-6">
                      <label class="form-label" for="packageEditDays">How many days</label>
                      <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" class="form-control" id="packageEditDays" name="package_days" required autocomplete="off" oninput="this.value=this.value.replace(/\D/g,'')">
                    </div>
                  </div>
                  <div class="mb-1">
                    <label class="form-label" for="packageEditPhoto">Photo</label>
                    <input class="form-control" id="packageEditPhoto" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text">Leave this blank to keep the current photo.</div>
                  </div>
                  <img id="packageEditPreview" class="offer-manage-card__photo mt-2 rounded" alt="" hidden>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-ct" type="submit">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>
<?php
if ($biz) {
    render_unsaved_changes_modal();
}
if ($mode === 'packages') {
    $extraScripts = '<script>
      document.getElementById("packageEditModal")?.addEventListener("show.bs.modal", function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var id = document.getElementById("packageEditId");
        var name = document.getElementById("packageEditName");
        var desc = document.getElementById("packageEditDesc");
        var price = document.getElementById("packageEditPrice");
        var days = document.getElementById("packageEditDays");
        var photo = document.getElementById("packageEditPhoto");
        var preview = document.getElementById("packageEditPreview");
        if (id) id.value = btn.getAttribute("data-pkg-id") || "";
        if (name) name.value = btn.getAttribute("data-pkg-name") || "";
        if (desc) desc.value = btn.getAttribute("data-pkg-desc") || "";
        if (price) price.value = btn.getAttribute("data-pkg-price") || "0";
        if (days) days.value = btn.getAttribute("data-pkg-days") || "";
        if (photo) photo.value = "";
        var src = btn.getAttribute("data-pkg-photo") || "";
        if (preview) {
          preview.hidden = src === "";
          preview.src = src;
        }
      });
    </script>';
}
require __DIR__ . '/../includes/footer.php';
