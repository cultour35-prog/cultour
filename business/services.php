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
        $paxParsed = ($own && !empty($svc['ok']) && $daysParsed && $daysParsed['ok']) ? package_pax_from_post($_POST) : null;
        if (!$own) {
            flash('error', 'Package not found.');
        } elseif (empty($svc['ok'])) {
            flash('error', (string) ($svc['message'] ?? 'Name is required.'));
        } elseif (!$daysParsed['ok']) {
            flash('error', $daysParsed['message']);
        } elseif (!$paxParsed['ok']) {
            flash('error', $paxParsed['message']);
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
                 SET name=?, description=?, price=?, package_days=?, capacity=?, image_path=?
                 WHERE id=? AND business_id=?'
            )->execute([
                $d['name'],
                $d['description'],
                $d['price'],
                $daysParsed['days'],
                $paxParsed['pax'],
                $image !== '' ? $image : null,
                $svcId,
                $biz['id'],
            ]);
            flash('success', $copy['updated']);
        }
    } elseif (($_POST['action'] ?? '') === 'update_menu' && $mode === 'menu') {
        $svcId = (int) ($_POST['id'] ?? 0);
        $own = db()->prepare('SELECT image_path FROM business_services WHERE id=? AND business_id=? LIMIT 1');
        $own->execute([$svcId, $biz['id']]);
        $own = $own->fetch();
        $svc = $own ? parse_place_service_post($_POST) : ['ok' => false, 'message' => 'Menu item not found.'];
        if (!$own) {
            flash('error', 'Menu item not found.');
        } elseif (empty($svc['ok'])) {
            flash('error', (string) ($svc['message'] ?? 'Name is required.'));
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
                'UPDATE business_services SET name=?, description=?, price=?, image_path=?, menu_category=? WHERE id=? AND business_id=?'
            )->execute([
                $d['name'],
                $d['description'],
                $d['price'],
                $image !== '' ? $image : null,
                menu_category_key($_POST['menu_category'] ?? '', (int) $biz['id']),
                $svcId,
                $biz['id'],
            ]);
            flash('success', $copy['updated']);
        }
    } elseif (in_array(($_POST['action'] ?? ''), ['add_menu_category', 'rename_menu_category', 'remove_menu_category'], true) && $mode === 'menu') {
        $bizId = (int) $biz['id'];
        $action = (string) $_POST['action'];
        if ($action === 'add_menu_category') {
            $result = menu_category_add($bizId, (string) ($_POST['label'] ?? ''));
        } elseif ($action === 'rename_menu_category') {
            $result = menu_category_rename($bizId, (string) ($_POST['category_key'] ?? ''), (string) ($_POST['label'] ?? ''));
        } else {
            $result = menu_category_remove($bizId, (string) ($_POST['category_key'] ?? ''));
        }
        flash(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Could not update categories.'));
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
            $paxParsed = package_pax_from_post($_POST);
            if (!$paxParsed['ok']) {
                flash('error', $paxParsed['message']);
                redirect(posted_return_to(APP_URL . '/business/services.php'));
            }
            $d['capacity'] = $paxParsed['pax'];
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
            'INSERT INTO business_services (business_id, name, description, price, duration_minutes, package_days, capacity, unit_count, extra_guest_fee, extra_guest_limit, amenities, image_path, menu_category)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $biz['id'],
            $d['name'],
            in_array($mode, ['rooms', 'vehicles', 'packages', 'menu'], true) ? $d['description'] : null,
            in_array($mode, ['rooms', 'vehicles', 'packages', 'menu'], true) ? $d['price'] : 0,
            in_array($mode, ['rooms', 'vehicles', 'packages'], true) ? $d['duration_minutes'] : null,
            $packageDays,
            $d['capacity'],
            ($isRooms || $isVehicles) ? $d['unit_count'] : null,
            $isRooms ? $d['extra_guest_fee'] : null,
            $isRooms ? $d['extra_guest_limit'] : null,
            $d['amenities'],
            $image,
            $mode === 'menu' ? menu_category_key($_POST['menu_category'] ?? '', (int) $biz['id']) : null,
        ]);
        flash('success', $copy['added']);
    }
    redirect(posted_return_to(APP_URL . '/business/services.php'));
}

$rows = [];
$menuCategories = [];
if ($biz) {
    $s = db()->prepare('SELECT * FROM business_services WHERE business_id=? ORDER BY is_active DESC, name');
    $s->execute([$biz['id']]);
    $rows = $s->fetchAll();
    if ($mode === 'menu') {
        $menuCategories = menu_category_options((int) $biz['id']);
    }
}

if ($mode === 'menu') {
    $pageHeadAction = '<button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#menuCatModal">Edit categories</button>';
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
      <div class="<?= $isRooms ? 'room-manage' : 'row g-3' ?>">
        <div class="<?= $isRooms ? 'room-manage__add' : 'col-lg-4' ?>">
          <div class="glass p-3 p-md-4">
            <h2 class="h6 mb-3">Add a <?= e($noun) ?></h2>
            <form method="post" enctype="multipart/form-data" class="<?= $isRooms ? 'room-add-form' : '' ?>" data-unsaved-guard><?= csrf_field() ?>
              <input type="hidden" name="return_to" value="">
              <?php if ($isRooms): ?><div class="room-add-form__fields"><?php endif; ?>
              <div class="mb-2">
                <label class="form-label"><?= e($copy['nameLabel']) ?></label>
                <input class="form-control" name="name"<?= in_array($mode, ['rooms', 'vehicles', 'packages'], true) ? '' : ' placeholder="' . e($copy['placeholder']) . '"' ?> required>
              </div>
              <?php if (in_array($mode, ['rooms', 'vehicles', 'packages', 'menu'], true)): ?>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="<?= $isRooms ? '2' : '3' ?>"></textarea>
              </div>
              <?php endif; ?>
              <?php if ($mode === 'menu'): ?>
              <div class="mb-2">
                <label class="form-label">Price</label>
                <input type="number" step="0.01" min="0" class="form-control" name="price" required>
              </div>
              <div class="mb-2">
                <label class="form-label">Category</label>
                <select class="form-select" name="menu_category">
                  <?php foreach ($menuCategories as $catKey => $catLabel): ?>
                    <option value="<?= e($catKey) ?>"><?= e($catLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <?php if (in_array($mode, ['rooms', 'vehicles', 'packages'], true)): ?>
              <div class="row g-2 mb-2">
                <div class="<?= $isRooms ? 'room-add-stat' : 'col-6' ?>">
                  <label class="form-label"><?= e($priceHint) ?></label>
                  <input type="number" step="0.01" min="0" class="form-control" name="price">
                </div>
                <?php if ($isRooms || $isVehicles): ?>
                  <div class="<?= $isRooms ? 'room-add-stat' : 'col-6' ?>">
                    <label class="form-label"><?= $isRooms ? 'How many rooms' : 'How many vehicles' ?></label>
                    <input type="number" min="1" class="form-control" name="unit_count"<?= $isRooms ? ' required' : '' ?>>
                  </div>
                  <div class="<?= $isRooms ? 'room-add-stat' : 'col-6' ?>">
                    <label class="form-label"><?= e($capacityHint) ?></label>
                    <input type="number" min="1" class="form-control" name="capacity"<?= $isVehicles ? ' required' : '' ?>>
                  </div>
                  <?php if ($isRooms): ?>
                  <div class="room-add-stat">
                    <label class="form-label">Extra-person fee</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="extra_guest_fee">
                  </div>
                  <div class="room-add-stat">
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
                  <div class="col-6">
                    <label class="form-label">Pax</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" class="form-control" name="capacity" required autocomplete="off" oninput="this.value=this.value.replace(/\D/g,'')">
                    <div class="form-text">How many people this package is for.</div>
                  </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if ($isRooms || $isVehicles): ?>
                <div class="<?= $isRooms ? 'room-add-amenities' : 'mb-2' ?>">
                  <label class="form-label"><?= $isRooms ? 'Amenities' : 'Features' ?></label>
                  <input class="form-control js-tag-input" name="amenities" maxlength="255"<?= $isRooms ? ' placeholder="Queen bed"' : '' ?>>
                  <?php if (!$isRooms): ?>
                  <div class="form-text">Type one feature, then a comma.</div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($mode === 'menu'): ?>
              <div class="mb-3">
                <label class="form-label">Food photo</label>
                <div class="room-photo-stage" id="menuAddStage">
                  <span class="room-photo-stage__empty">Photo preview</span>
                  <img id="menuAddPreview" alt="" hidden>
                </div>
                <input class="form-control js-room-photo mt-2" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-preview="menuAddPreview">
              </div>
              <?php elseif (!$isRooms): ?>
              <div class="mb-3">
                <label class="form-label">Photo</label>
                <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp">
              </div>
              <?php endif; ?>
              <?php if (!$isRooms): ?>
              <button class="btn btn-ct w-100">Add <?= e($noun) ?></button>
              <?php else: ?>
              </div>
              <div class="room-add-photo">
                <label class="form-label">Photo</label>
                <div class="room-photo-stage" id="roomAddStage">
                  <span class="room-photo-stage__empty">Photo preview</span>
                  <img id="roomAddPreview" alt="" hidden>
                </div>
                <input class="form-control js-room-photo" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-preview="roomAddPreview">
              </div>
              <p class="form-text room-add-hint">Type one amenity, then a comma.</p>
              <button class="btn btn-ct room-add-submit">Add <?= e($noun) ?></button>
              <?php endif; ?>
            </form>
          </div>
        </div>
        <div class="<?= $isRooms ? 'room-manage__list' : 'col-lg-8' ?>">
          <?php if (!$rows): ?>
            <div class="glass p-4 text-muted"><?= e($copy['empty']) ?></div>
          <?php else: ?>
            <?php if ($mode === 'menu'): ?>
              <?php
                $menuGroups = [];
                foreach ($rows as $menuRow) {
                    $menuGroups[menu_category_key($menuRow['menu_category'] ?? '', (int) $biz['id'])] = true;
                }
                $firstMenuCat = '';
                foreach (array_keys($menuCategories) as $catKey) {
                    if (!empty($menuGroups[$catKey])) {
                        $firstMenuCat = $catKey;
                        break;
                    }
                }
              ?>
              <div class="kiosk" data-kiosk-root>
                <div class="kiosk__cats" role="tablist">
                  <?php foreach ($menuCategories as $catKey => $catLabel): ?>
                    <?php if (empty($menuGroups[$catKey])) { continue; } ?>
                    <button type="button" class="kiosk__cat<?= $catKey === $firstMenuCat ? ' is-on' : '' ?>" data-kiosk-cat="<?= e($catKey) ?>"><?= e($catLabel) ?></button>
                  <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="offer-manage-grid<?= $mode === 'packages' ? ' offer-manage-grid--paper' : '' ?><?= $mode === 'menu' ? ' offer-manage-grid--menu' : '' ?>">
              <?php foreach ($rows as $r): ?>
                <?php
                  $img = trim((string) ($r['image_path'] ?? ''));
                  $imgUrl = $img !== '' ? APP_URL . '/uploads/' . ltrim($img, '/') : '';
                  $pkgHidden = $mode === 'packages' && (int) ($r['is_active'] ?? 1) !== 1;
                  $pkgDays = (int) ($r['package_days'] ?? 0);
                  $pkgPax = (int) ($r['capacity'] ?? 0);
                ?>
                <article class="offer-manage-card glass<?= $isRooms ? ' room-manage-card' : '' ?><?= $mode === 'menu' ? ' menu-food-card' : '' ?><?= $pkgHidden ? ' is-hidden' : '' ?>"<?= $mode === 'menu' ? ' data-kiosk-item="' . e(menu_category_key($r['menu_category'] ?? '', (int) $biz['id'])) . '"' . (menu_category_key($r['menu_category'] ?? '', (int) $biz['id']) === ($firstMenuCat ?? '') ? '' : ' hidden') : '' ?>>
                  <?php if ($mode === 'menu'): ?>
                    <div class="menu-food-card__photo<?= $imgUrl === '' ? ' is-empty' : '' ?>"<?= $imgUrl !== '' ? ' style="background-image:url(\'' . e($imgUrl) . '\')"' : '' ?>>
                      <?php if ($imgUrl === ''): ?><span>No photo yet</span><?php endif; ?>
                    </div>
                    <?php if ($imgUrl !== ''): ?>
                      <div class="menu-food-card__zoom" style="background-image:url('<?= e($imgUrl) ?>')"></div>
                    <?php endif; ?>
                  <?php elseif ($isRooms): ?>
                    <?php if ($imgUrl !== ''): ?>
                      <img class="offer-manage-card__photo" src="<?= e($imgUrl) ?>" alt="">
                    <?php else: ?>
                      <div class="room-photo-empty">No photo yet</div>
                    <?php endif; ?>
                  <?php elseif ($imgUrl !== ''): ?>
                    <img class="offer-manage-card__photo" src="<?= e($imgUrl) ?>" alt="">
                  <?php endif; ?>
                  <div class="offer-manage-card__body">
                    <div class="d-flex justify-content-between gap-2">
                      <h3 class="h6 mb-1">
                        <?= e($r['name']) ?>
                        <?php if ($pkgHidden): ?><span class="offer-manage-card__hidden">Hidden</span><?php endif; ?>
                      </h3>
                      <?php if (in_array($mode, ['rooms', 'vehicles', 'packages', 'menu'], true)): ?>
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
                      <div class="small text-muted mb-1"><?= $pkgDays > 0 ? e(package_days_label($pkgDays)) : 'Days not set' ?><?= $pkgPax > 0 ? ' · ' . e(package_pax_label($pkgPax)) : '' ?></div>
                    <?php endif; ?>
                    <?php if (in_array($mode, ['rooms', 'vehicles', 'packages', 'menu'], true) && !empty($r['description'])): ?>
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
                    <?php if ($mode === 'menu'): ?>
                      <div class="offer-manage-card__actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#menuEditModal"
                                data-menu-id="<?= (int) $r['id'] ?>"
                                data-menu-name="<?= e($r['name']) ?>"
                                data-menu-desc="<?= e((string) ($r['description'] ?? '')) ?>"
                                data-menu-price="<?= e((string) ($r['price'] ?? '0')) ?>"
                                data-menu-cat="<?= e(menu_category_key($r['menu_category'] ?? '', (int) $biz['id'])) ?>"
                                data-menu-photo="<?= e($imgUrl) ?>">Edit</button>
                        <form method="post" data-unsaved-ignore><?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <button name="action" value="delete" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                      </div>
                    <?php elseif ($mode === 'packages'): ?>
                      <div class="offer-manage-card__actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#packageEditModal"
                                data-pkg-id="<?= (int) $r['id'] ?>"
                                data-pkg-name="<?= e($r['name']) ?>"
                                data-pkg-desc="<?= e((string) ($r['description'] ?? '')) ?>"
                                data-pkg-price="<?= e((string) ($r['price'] ?? '0')) ?>"
                                data-pkg-days="<?= $pkgDays > 0 ? $pkgDays : '' ?>"
                                data-pkg-pax="<?= $pkgPax > 0 ? $pkgPax : '' ?>"
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
            <?php if ($mode === 'menu'): ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
?>
      <?php if ($mode === 'menu'): ?>
        <div class="modal fade" id="menuEditModal" tabindex="-1" aria-labelledby="menuEditLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <form method="post" enctype="multipart/form-data" data-unsaved-ignore>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_menu">
                <input type="hidden" name="id" id="menuEditId" value="">
                <div class="modal-header">
                  <h2 class="modal-title h5 mb-0" id="menuEditLabel">Edit menu item</h2>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-2">
                    <label class="form-label" for="menuEditName">Menu item</label>
                    <input class="form-control" id="menuEditName" name="name" required>
                  </div>
                  <div class="mb-2">
                    <label class="form-label" for="menuEditDesc">Description</label>
                    <textarea class="form-control" id="menuEditDesc" name="description" rows="3"></textarea>
                  </div>
                  <div class="mb-2">
                    <label class="form-label" for="menuEditPrice">Price</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="menuEditPrice" name="price" required>
                  </div>
                  <div class="mb-2">
                    <label class="form-label" for="menuEditCat">Category</label>
                    <select class="form-select" id="menuEditCat" name="menu_category">
                      <?php foreach ($menuCategories as $catKey => $catLabel): ?>
                        <option value="<?= e($catKey) ?>"><?= e($catLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="mb-2">
                    <label class="form-label" for="menuEditPhoto">Food photo</label>
                    <div class="room-photo-stage mb-2" id="menuEditStage">
                      <span class="room-photo-stage__empty">Photo preview</span>
                      <img id="menuEditPreview" alt="" hidden>
                    </div>
                    <input class="form-control js-room-photo" id="menuEditPhoto" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-preview="menuEditPreview">
                    <div class="form-text">Leave this blank to keep the current photo.</div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-ct" type="submit">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="modal fade" id="menuCatModal" tabindex="-1" aria-labelledby="menuCatLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h2 class="modal-title h5 mb-0" id="menuCatLabel">Edit categories</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <form method="post" class="menu-cat-add" data-unsaved-ignore>
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="add_menu_category">
                  <label class="form-label" for="menuCatNew">Add a category</label>
                  <div class="d-flex gap-2">
                    <input class="form-control" id="menuCatNew" name="label" maxlength="40" required placeholder="Soups">
                    <button class="btn btn-ct" type="submit">Add</button>
                  </div>
                </form>
                <div class="menu-cat-list">
                  <?php foreach ($menuCategories as $catKey => $catLabel): ?>
                    <div class="menu-cat-row">
                      <form method="post" class="menu-cat-rename" data-unsaved-ignore>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rename_menu_category">
                        <input type="hidden" name="category_key" value="<?= e($catKey) ?>">
                        <input class="form-control" name="label" maxlength="40" required value="<?= e($catLabel) ?>" aria-label="Category name">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Save</button>
                      </form>
                      <form method="post" data-unsaved-ignore onsubmit="return confirm('Remove this category? Its dishes move to another category.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_menu_category">
                        <input type="hidden" name="category_key" value="<?= e($catKey) ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
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
                    <div class="col-6">
                      <label class="form-label" for="packageEditPax">Pax</label>
                      <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" class="form-control" id="packageEditPax" name="capacity" required autocomplete="off" oninput="this.value=this.value.replace(/\D/g,'')">
                      <div class="form-text">How many people this package is for.</div>
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
if ($isRooms || $mode === 'menu') {
    $extraScripts = '<script>
      document.querySelectorAll(".js-room-photo").forEach(function (input) {
        input.addEventListener("change", function () {
          var preview = document.getElementById(input.getAttribute("data-preview") || "");
          var file = input.files && input.files[0];
          if (!preview) return;
          var stage = preview.closest(".room-photo-stage");
          if (!file) {
            preview.hidden = true;
            preview.removeAttribute("src");
            if (stage) stage.classList.remove("has-photo");
            return;
          }
          var reader = new FileReader();
          reader.onload = function () {
            preview.src = String(reader.result || "");
            preview.hidden = false;
            if (stage) stage.classList.add("has-photo");
          };
          reader.readAsDataURL(file);
        });
      });
    </script>';
}
if ($mode === 'menu') {
    $extraScripts = ($extraScripts ?? '') . '<script>
      document.addEventListener("click", function (event) {
        var btn = event.target.closest("[data-kiosk-cat]");
        if (!btn) return;
        var kiosk = btn.closest("[data-kiosk-root]");
        if (!kiosk) return;
        var cat = btn.getAttribute("data-kiosk-cat");
        kiosk.querySelectorAll("[data-kiosk-cat]").forEach(function (tab) {
          tab.classList.toggle("is-on", tab === btn);
        });
        var any = false;
        kiosk.querySelectorAll("[data-kiosk-item]").forEach(function (item) {
          var show = item.getAttribute("data-kiosk-item") === cat;
          item.hidden = !show;
          if (show) any = true;
        });
        var empty = kiosk.querySelector("[data-kiosk-empty]");
        if (empty) empty.hidden = any;
      });
      document.getElementById("menuEditModal")?.addEventListener("show.bs.modal", function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var id = document.getElementById("menuEditId");
        var name = document.getElementById("menuEditName");
        var desc = document.getElementById("menuEditDesc");
        var price = document.getElementById("menuEditPrice");
        var photo = document.getElementById("menuEditPhoto");
        if (id) id.value = btn.getAttribute("data-menu-id") || "";
        if (name) name.value = btn.getAttribute("data-menu-name") || "";
        if (desc) desc.value = btn.getAttribute("data-menu-desc") || "";
        if (price) price.value = btn.getAttribute("data-menu-price") || "0";
        var cat = document.getElementById("menuEditCat");
        if (cat) cat.value = btn.getAttribute("data-menu-cat") || "main";
        if (photo) photo.value = "";
        var preview = document.getElementById("menuEditPreview");
        var stage = document.getElementById("menuEditStage");
        var src = btn.getAttribute("data-menu-photo") || "";
        if (preview) {
          preview.hidden = src === "";
          if (src !== "") preview.src = src;
          else preview.removeAttribute("src");
        }
        if (stage) stage.classList.toggle("has-photo", src !== "");
      });
    </script>';
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
        var pax = document.getElementById("packageEditPax");
        var photo = document.getElementById("packageEditPhoto");
        var preview = document.getElementById("packageEditPreview");
        if (id) id.value = btn.getAttribute("data-pkg-id") || "";
        if (name) name.value = btn.getAttribute("data-pkg-name") || "";
        if (desc) desc.value = btn.getAttribute("data-pkg-desc") || "";
        if (price) price.value = btn.getAttribute("data-pkg-price") || "0";
        if (days) days.value = btn.getAttribute("data-pkg-days") || "";
        if (pax) pax.value = btn.getAttribute("data-pkg-pax") || "";
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
