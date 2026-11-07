<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/admin_registrations.php';
$user = require_login(ROLE_ADMIN);
ensure_password_schema();
ensure_business_documents_schema();
ensure_business_listing_schema();
ensure_user_identity_schema();
ensure_place_schedule_schema();
$pageTitle = 'Businesses';
$pageHeading = 'Local Businesses';
$pageSub = 'Add listings, approve registrations, and pin locations';
$activeNav = 'businesses';
$liveRegistrations = 'businesses';

ensure_business_type_pin_colors();
$types = db()->query('SELECT * FROM business_types ORDER BY name')->fetchAll();

$forceShowAdd = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['save_hours', 'add_service', 'delete_service'], true)) {
        $id = (int) ($_POST['business_id'] ?? $_POST['id'] ?? 0);
        $return = APP_URL . '/admin/businesses.php?' . (($_POST['return_mode'] ?? '') === 'edit' ? 'edit' : 'view') . '=' . $id;
        $stmt = db()->prepare('SELECT * FROM businesses WHERE id=?');
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            flash('error', 'Business not found.');
            redirect(APP_URL . '/admin/businesses.php');
        }
        if (($b['approval_status'] ?? '') === 'pending') {
            flash('error', 'Approve this business before managing hours or what it offers.');
            redirect($return);
        }
        $offerTypeName = '';
        foreach ($types as $typeRow) {
            if ((int) $typeRow['id'] === (int) ($b['business_type_id'] ?? 0)) {
                $offerTypeName = (string) $typeRow['name'];
                break;
            }
        }
        $offerCopy = business_catalog_copy($offerTypeName);
        if ($action === 'save_hours') {
            $json = encode_opening_hours_from_post($_POST);
            $visitParsed = parse_visit_duration_post($_POST);
            if (!$visitParsed['ok']) {
                flash('error', (string) ($visitParsed['message'] ?? 'Enter a valid visit duration.'));
                redirect($return);
            }
            db()->prepare('UPDATE businesses SET opening_hours=?, default_visit_minutes=?, visit_duration_minutes=? WHERE id=?')
                ->execute([$json, $visitParsed['limit'] ?? 60, $visitParsed['limit'], $id]);
            flash('success', 'Operating hours saved.');
        } elseif ($action === 'add_service') {
            $svc = parse_place_service_post($_POST);
            if (empty($svc['ok'])) {
                flash('error', (string) ($svc['message'] ?? 'Name is required.'));
                redirect($return);
            }
            $d = $svc['data'];
            $keepOfferMeta = in_array($offerCopy['mode'] ?? '', ['rooms', 'vehicles', 'packages'], true);
            db()->prepare(
                'INSERT INTO business_services (business_id, name, description, price, duration_minutes)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $id,
                $d['name'],
                $keepOfferMeta ? $d['description'] : null,
                $keepOfferMeta ? $d['price'] : 0,
                $keepOfferMeta ? $d['duration_minutes'] : null,
            ]);
            flash('success', $offerCopy['added']);
        } elseif ($action === 'delete_service') {
            db()->prepare('DELETE FROM business_services WHERE id=? AND business_id=?')
                ->execute([(int) ($_POST['service_id'] ?? 0), $id]);
            flash('success', $offerCopy['removed']);
        }
        redirect($return);
    }

    if ($action === 'create') {
        $plainPassword = admin_random_business_password();
        $parsed = parse_admin_business_listing_post($_POST);
        $createError = null;
        $d = [];
        $name = '';
        $typeId = 0;
        $address = '';
        $description = null;
        $phone = '';
        $bizEmail = '';
        $website = null;
        $facebookUrl = null;
        $instagramUrl = null;
        $ownerName = '';
        $permit = null;
        $taxId = null;
        $lat = null;
        $lng = null;
        $first = '';
        $last = '';

        if (!$parsed['ok']) {
            $createError = $parsed['message'] ?? 'Please complete the business details.';
        } else {
            $d = $parsed['data'];
            $name = $d['name'];
            $typeId = (int) $d['type_id'];
            $address = $d['address'];
            $description = $d['description'];
            $phone = $d['phone'];
            $bizEmail = $d['email'];
            $website = $d['facebook_url'] ?: $d['instagram_url'];
            $facebookUrl = $d['facebook_url'];
            $instagramUrl = $d['instagram_url'];
            $ownerName = $d['owner_name'];
            $permit = $d['permit_number'];
            $taxId = $d['tax_id'];
            $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null;
            $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;
            $first = $d['owner_first'];
            $last = $d['owner_last'];

            if ($name === '' || !$typeId || $address === '') {
                $createError = 'Business name, type, and address are required.';
            } else {
                $typeOk = db()->prepare('SELECT id FROM business_types WHERE id=?');
                $typeOk->execute([$typeId]);
                if (!$typeOk->fetch()) {
                    $createError = 'Invalid business category.';
                }
            }

            if ($createError === null) {
                $exists = db()->prepare('SELECT id FROM users WHERE email=? AND role_id=?');
                $exists->execute([$bizEmail, ROLE_BUSINESS]);
                if ($exists->fetch()) {
                    $createError = 'That business email is already registered as a Local Business.';
                }
            }
        }

        if ($createError !== null) {
            flash('error', $createError);
            $forceShowAdd = true;
        } else {
            db()->prepare(
                'INSERT INTO users (role_id, email, password_hash, first_name, last_name, middle_name, sex, phone, status, must_change_password, email_verified_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
            )->execute([
                ROLE_BUSINESS,
                $bizEmail,
                password_hash($plainPassword, PASSWORD_DEFAULT),
                $first,
                $last,
                $d['owner_middle'] !== '' ? $d['owner_middle'] : null,
                $d['owner_sex'],
                $phone !== '' ? $phone : null,
                'active',
                1,
            ]);
            $ownerId = (int) db()->lastInsertId();

            $slugBase = slugify($name);
            $slug = $slugBase . '-' . $ownerId;
            $n = 1;
            while (true) {
                $chk = db()->prepare('SELECT id FROM businesses WHERE slug=?');
                $chk->execute([$slug]);
                if (!$chk->fetch()) {
                    break;
                }
                $slug = $slugBase . '-' . $ownerId . '-' . $n;
                $n++;
            }

            $cover = null;
            $logo = null;
            $hoursJson = null;
            $visit = 60;

            db()->prepare(
                'INSERT INTO businesses
                 (user_id, business_type_id, name, owner_name, representative_name, representative_sex, slug, description, address, address_region, address_city, address_barangay, address_street, latitude, longitude, phone, email, permit_number, tax_id, website, facebook_url, instagram_url, cover_image, logo, opening_hours, default_visit_minutes, approval_status, approved_by, approved_at, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)'
            )->execute([
                $ownerId,
                $typeId,
                $name,
                $ownerName !== '' ? $ownerName : null,
                $d['representative_name'],
                $d['representative_sex'],
                $slug,
                $description,
                $address,
                $d['address_region'],
                $d['address_city'],
                $d['address_barangay'],
                $d['address_street'],
                $lat,
                $lng,
                $phone !== '' ? $phone : null,
                $bizEmail !== '' ? $bizEmail : null,
                $permit,
                $taxId,
                $website,
                $facebookUrl,
                $instagramUrl,
                $cover,
                $logo,
                $hoursJson,
                $visit,
                'approved',
                (int) $user['id'],
                'active',
            ]);
            $bizId = (int) db()->lastInsertId();
            if (!empty($_FILES['document_files']['name'])) {
                save_business_documents($bizId, $_FILES['document_files']);
            }

            notify_user(
                $ownerId,
                'approval',
                'Business listed on CulTour',
                $name . ' was added by the Tourism Office and is now live.',
                APP_URL . '/business/dashboard.php'
            );
            log_activity((int) $user['id'], 'create_business', 'businesses', 'Business #' . $bizId . ' · ' . $name);

            $_SESSION['admin_created_business'] = [
                'business' => $name,
                'name' => $ownerName,
                'email' => $bizEmail,
                'phone' => $phone,
                'password' => $plainPassword,
                'login_url' => APP_URL . '/login.php',
            ];
            flash('success', 'Business added and approved. Print the login card for them.');
            redirect(APP_URL . '/admin/businesses.php?created=1');
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['business_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM businesses WHERE id=?');
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            flash('error', 'Business not found.');
            redirect(APP_URL . '/admin/businesses.php');
        }
        if (($b['approval_status'] ?? '') === 'pending') {
            flash('error', 'Pending applications cannot be edited.');
            redirect(APP_URL . '/admin/businesses.php?tab=pending');
        }

        $parsed = parse_admin_business_listing_post($_POST);
        if (!$parsed['ok']) {
            flash('error', $parsed['message'] ?? 'Please complete the business details.');
            redirect(APP_URL . '/admin/businesses.php?edit=' . $id);
        }
        $d = $parsed['data'];
        $name = $d['name'];
        $typeId = (int) $d['type_id'];
        $address = $d['address'];
        $description = $d['description'];
        $phone = $d['phone'];
        $bizEmail = $d['email'];
        $website = $d['facebook_url'] ?: $d['instagram_url'];
        $facebookUrl = $d['facebook_url'];
        $instagramUrl = $d['instagram_url'];
        $ownerName = $d['owner_name'];
        $permit = $d['permit_number'];
        $taxId = $d['tax_id'];
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null;
        $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;

        if ($name === '' || !$typeId || $address === '') {
            flash('error', 'Business name, type, and address are required.');
            redirect(APP_URL . '/admin/businesses.php?edit=' . $id);
        }

        $typeOk = db()->prepare('SELECT id FROM business_types WHERE id=?');
        $typeOk->execute([$typeId]);
        if (!$typeOk->fetch()) {
            flash('error', 'Invalid business category.');
            redirect(APP_URL . '/admin/businesses.php?edit=' . $id);
        }

        $cover = $b['cover_image'];
        if (!empty($_FILES['cover_image']['name'])) {
            $up = upload_file($_FILES['cover_image'], 'businesses');
            if ($up) {
                $cover = $up;
            }
        }
        $logo = $b['logo'] ?? null;
        if (!empty($_FILES['logo']['name'])) {
            $upLogo = upload_file($_FILES['logo'], 'businesses', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($upLogo) {
                $logo = $upLogo;
            }
        }
        if (!empty($_FILES['business_photos']['name'])) {
            save_business_gallery_photos($id, $_FILES['business_photos']);
        }
        if (!empty($_FILES['document_files']['name'])) {
            save_business_documents($id, $_FILES['document_files']);
        }

        $slugBase = slugify($name);
        $slug = $slugBase . '-' . (int) $b['id'];
        $n = 1;
        while (true) {
            $chk = db()->prepare('SELECT id FROM businesses WHERE slug=? AND id<>?');
            $chk->execute([$slug, $id]);
            if (!$chk->fetch()) {
                break;
            }
            $slug = $slugBase . '-' . (int) $b['id'] . '-' . $n;
            $n++;
        }

        $hoursJson = encode_opening_hours_from_post($_POST);
        $visitParsed = parse_visit_duration_post($_POST);
        if (!$visitParsed['ok']) {
            flash('error', (string) ($visitParsed['message'] ?? 'Enter a valid visit duration.'));
            redirect(APP_URL . '/admin/businesses.php?edit=' . $id);
        }
        $visitLimit = $visitParsed['limit'];
        $visit = $visitLimit ?? 60;
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
             phone=?, email=?, permit_number=?, tax_id=?, website=?, facebook_url=?, instagram_url=?, cover_image=?, logo=?, latitude=?, longitude=?, status=?,
             opening_hours=?, default_visit_minutes=?, visit_duration_minutes=?
             WHERE id=?'
        )->execute([
            $typeId,
            $name,
            $ownerName !== '' ? $ownerName : null,
            $d['representative_name'],
            $d['representative_sex'],
            $slug,
            $description,
            $address,
            $d['address_region'],
            $d['address_city'],
            $d['address_barangay'],
            $d['address_street'],
            $phone !== '' ? $phone : null,
            $bizEmail !== '' ? $bizEmail : null,
            $permit,
            $taxId,
            $website,
            $facebookUrl,
            $instagramUrl,
            $cover,
            $logo,
            $lat,
            $lng,
            $status,
            $hoursJson,
            $visit,
            $visitLimit,
            $id,
        ]);
        db()->prepare('UPDATE users SET first_name=?, last_name=?, middle_name=?, sex=? WHERE id=?')->execute([
            $d['owner_first'],
            $d['owner_last'],
            $d['owner_middle'] !== '' ? $d['owner_middle'] : null,
            $d['owner_sex'],
            (int) $b['user_id'],
        ]);
        sync_business_document_file($id);
        log_business_update_activity((int) $user['id'], $name, $b, [
            'name' => $name,
            'business_type_id' => $typeId,
            'owner_name' => $ownerName !== '' ? $ownerName : null,
            'representative_name' => $d['representative_name'],
            'description' => $description,
            'address' => $address,
            'address_barangay' => $d['address_barangay'],
            'address_street' => $d['address_street'],
            'phone' => $phone !== '' ? $phone : null,
            'email' => $bizEmail !== '' ? $bizEmail : null,
            'permit_number' => $permit,
            'tax_id' => $taxId,
            'facebook_url' => $facebookUrl,
            'instagram_url' => $instagramUrl,
            'cover_image' => $cover,
            'logo' => $logo,
            'latitude' => $lat,
            'longitude' => $lng,
            'status' => $status,
            'opening_hours' => $hoursJson,
            'visit_duration_minutes' => $visitLimit,
        ], $extraChanged);
        flash('success', 'Business details updated.');
        redirect(posted_return_to(APP_URL . '/admin/businesses.php?view=' . $id));
    }

    $id = (int) ($_POST['business_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id=?');
    $stmt->execute([$id]);
    $b = $stmt->fetch();
    if ($b) {
        if ($action === 'approve') {
            db()->prepare("UPDATE businesses SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$user['id'], $id]);
            db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$b['user_id']]);
            notify_user((int) $b['user_id'], 'approval', 'Business approved', 'Your business is now listed on CulTour.', APP_URL . '/business/dashboard.php');
            flash('success', 'Business approved.');
        } elseif ($action === 'reject') {
            db()->prepare("UPDATE businesses SET approval_status='rejected', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$user['id'], $id]);
            db()->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$b['user_id']]);
            notify_user((int) $b['user_id'], 'approval', 'Business rejected', 'Please contact the Tourism Office.', APP_URL . '/business/profile.php');
            flash('success', 'Business rejected.');
        } elseif ($action === 'pin') {
            $coords = normalize_lat_lng($_POST['latitude'] ?? '', $_POST['longitude'] ?? '');
            if (empty($coords['ok'])) {
                flash('error', (string) ($coords['message'] ?? 'Invalid map coordinates.'));
                redirect(APP_URL . '/admin/businesses.php?pin=' . $id);
            }
            db()->prepare('UPDATE businesses SET latitude=?, longitude=? WHERE id=?')->execute([$coords['lat'], $coords['lng'], $id]);
            flash('success', 'Business location saved.');
            redirect(APP_URL . '/admin/businesses.php?pin=' . $id);
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM businesses WHERE id=?')->execute([$id]);
            log_activity((int) $user['id'], 'delete_business', 'businesses', 'Business #' . $id);
            flash('success', 'Business listing removed.');
        } elseif ($action === 'deactivate') {
            if (($b['approval_status'] ?? '') === 'approved' && ($b['status'] ?? '') !== 'inactive') {
                db()->prepare("UPDATE businesses SET status='inactive' WHERE id=?")->execute([$id]);
                log_activity((int) $user['id'], 'deactivate_business', 'businesses', 'Business #' . $id);
                flash('success', 'Business deactivated.');
                redirect(APP_URL . '/admin/businesses.php?tab=inactive');
            }
        } elseif ($action === 'activate') {
            $owner = db()->prepare('SELECT status FROM users WHERE id=?');
            $owner->execute([(int) $b['user_id']]);
            $ownerStatus = (string) ($owner->fetchColumn() ?: '');
            if (($b['approval_status'] ?? '') === 'approved' && $ownerStatus === 'suspended') {
                flash('error', 'Cannot activate while the owner account is suspended.');
            } elseif (($b['approval_status'] ?? '') === 'approved') {
                db()->prepare("UPDATE businesses SET status='active' WHERE id=?")->execute([$id]);
                log_activity((int) $user['id'], 'activate_business', 'businesses', 'Business #' . $id);
                flash('success', 'Business activated.');
                redirect(APP_URL . '/admin/businesses.php');
            }
        }
    }
    if (empty($forceShowAdd)) {
        redirect(APP_URL . '/admin/businesses.php');
    }
}

$pinId = (int) ($_GET['pin'] ?? 0);
$pinBiz = null;
if ($pinId) {
    $s = db()->prepare(
        'SELECT b.*, bt.pin_color FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.id=?'
    );
    $s->execute([$pinId]);
    $pinBiz = $s->fetch();
}

$edit = null;
$isEditing = true;
$viewId = (int) ($_GET['view'] ?? $_GET['edit'] ?? 0);
if ($viewId) {
    $s = db()->prepare('SELECT b.*, u.sex AS owner_sex FROM businesses b JOIN users u ON u.id = b.user_id WHERE b.id=?');
    $s->execute([$viewId]);
    $edit = $s->fetch() ?: null;
    if (!$edit) {
        flash('error', 'Business not found.');
        redirect(APP_URL . '/admin/businesses.php');
    }
    if (($edit['approval_status'] ?? '') === 'pending') {
        flash('error', 'Pending applications cannot be edited.');
        redirect(APP_URL . '/admin/businesses.php?tab=pending');
    }
}
$formLocked = false;
$showAdd = !$edit && !$pinBiz && (isset($_GET['add']) || !empty($forceShowAdd));

$createdBusiness = null;
if ((string) ($_GET['created'] ?? '') === '1' && !empty($_SESSION['admin_created_business']) && is_array($_SESSION['admin_created_business'])) {
    $createdBusiness = $_SESSION['admin_created_business'];
} else {
    unset($_SESSION['admin_created_business']);
}

$availByDay = opening_hours_to_avail_by_day([]);
$bizPhotos = [];
$bizDocs = [];
if ($edit) {
    $rawHours = $edit['opening_hours'] ?? null;
    if (is_array($rawHours)) {
        $rawHours = json_encode($rawHours);
    }
    $availByDay = opening_hours_to_avail_by_day(parse_opening_hours_value($rawHours) ?: []);
    $ps = db()->prepare('SELECT id, image_path FROM business_photos WHERE business_id=? ORDER BY created_at DESC');
    $ps->execute([(int) $edit['id']]);
    $bizPhotos = $ps->fetchAll() ?: [];
    $bizDocs = business_documents((int) $edit['id']);
} elseif ($showAdd && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'create')) {
    $availByDay = opening_hours_to_avail_by_day(parse_opening_hours_value(encode_opening_hours_from_post($_POST)) ?: []);
}

$rows = admin_businesses_fetch_rows();
$docsByBiz = [];
$split = admin_registrations_split($rows);
$pendingBusinesses = $split['pending'];
$registeredBusinesses = $split['registered'];
$rejectedBusinesses = $split['rejected'];
$inactiveBusinesses = $split['inactive'];
$bizLiveVersion = admin_businesses_version($rows);

$bizSections = [
    [
        'id' => 'registered',
        'title' => 'Active businesses',
        'sub' => 'Approved listings on CulTour',
        'badge' => 'text-bg-success',
        'btn' => 'Active businesses',
        'rows' => $registeredBusinesses,
        'approve' => false,
        'empty' => 'No active businesses yet. Click Add business to create one.',
    ],
    [
        'id' => 'pending',
        'title' => 'Pending applications',
        'sub' => 'Businesses waiting for Tourism Office approval',
        'badge' => 'text-bg-secondary',
        'btn' => 'Pending applications',
        'rows' => $pendingBusinesses,
        'approve' => true,
        'empty' => 'No pending applications.',
    ],
    [
        'id' => 'inactive',
        'title' => 'Inactive businesses',
        'sub' => 'Listings hidden from the explore map',
        'badge' => 'text-bg-warning',
        'btn' => 'Inactive businesses',
        'rows' => $inactiveBusinesses,
        'approve' => false,
        'empty' => 'No inactive businesses.',
    ],
    [
        'id' => 'rejected',
        'title' => 'Rejected',
        'sub' => 'Applications that were not approved',
        'badge' => 'text-bg-danger',
        'btn' => 'Rejected',
        'rows' => $rejectedBusinesses,
        'approve' => false,
        'empty' => 'No rejected applications.',
    ],
];
$defaultBizTab = 'registered';
if (!empty($_GET['tab']) && in_array($_GET['tab'], ['registered', 'pending', 'rejected', 'inactive'], true)) {
    $defaultBizTab = $_GET['tab'];
}

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
      <div id="registrationsFlash"></div>

      <?php if ($pinBiz): ?>
        <div class="glass p-3 p-md-4 mb-4 fade-up">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="panel-title mb-0">Pin location · <?= e($pinBiz['name']) ?></h3>
            <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/businesses.php">Close</a>
          </div>
          <p class="small text-muted">Click the map to drop a pin for this business.</p>
          <form method="post" class="row g-3 align-items-end"><?= csrf_field() ?>
            <input type="hidden" name="business_id" value="<?= (int) $pinBiz['id'] ?>">
            <input type="hidden" name="action" value="pin">
            <div class="col-md-3"><label class="form-label">Latitude</label><input type="number" step="any" class="form-control" name="latitude" id="adminBizLat" value="<?= e((string) ($pinBiz['latitude'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Longitude</label><input type="number" step="any" class="form-control" name="longitude" id="adminBizLng" value="<?= e((string) ($pinBiz['longitude'] ?? '')) ?>"></div>
            <div class="col-md-3"><button class="btn btn-ct">Save pin</button></div>
                      </form>
          <div id="adminBizPinMap" class="ct-map ct-map--tall mt-3"></div>
        </div>
      <?php endif; ?>

      <?php if ($edit): ?>
        <div class="mb-3 d-flex flex-wrap gap-2 admin-profile-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/businesses.php">&larr; Back to businesses</a>
          <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/business.php?id=<?= (int) $edit['id'] ?>">View business details</a>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#activityLogModal">Activity logs</button>
        </div>
        <?php
          $activityLogs = user_activity_logs((int) ($edit['user_id'] ?? 0));
          require __DIR__ . '/../includes/admin_subject_activity_logs.php';
        ?>
        <div class="glass p-3 p-md-4 mb-4 fade-up">
          <form method="post" enctype="multipart/form-data" id="editBizListingForm" data-required-check<?= $formLocked ? '' : ' data-unsaved-guard' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="business_id" value="<?= (int) $edit['id'] ?>">
            <input type="hidden" name="latitude" id="editBizLat" value="<?= e((string) ($edit['latitude'] ?? '')) ?>">
            <input type="hidden" name="longitude" id="editBizLng" value="<?= e((string) ($edit['longitude'] ?? '')) ?>">
            <fieldset class="admin-locked-form<?= $formLocked ? ' is-locked' : '' ?>">
            <div class="row g-3">
              <?php
                $listing = $edit;
                $repParts = split_person_name((string) ($edit['representative_name'] ?? ''));
                $ownerParts = split_person_name((string) ($edit['owner_name'] ?? ''));
                $listingFieldsPaired = true;
                require __DIR__ . '/_business_listing_fields.php';
                $listingFieldsPaired = false;
              ?>
              <div class="col-12">
                <div class="admin-biz-media">
                  <div class="admin-biz-media__desc">
                    <label class="form-label">Business Description</label>
                    <textarea class="form-control" name="description" rows="8"><?= e((string) ($edit['description'] ?? '')) ?></textarea>
                  </div>
                  <div class="admin-biz-media__files">
                    <div class="admin-biz-media__cover" data-avatar-preview>
                      <label class="form-label">Cover photo</label>
                      <div class="admin-biz-preview admin-biz-preview--cover" data-avatar-frame>
                        <?php if (!empty($edit['cover_image'])): ?>
                          <img src="<?= APP_URL ?>/uploads/<?= e($edit['cover_image']) ?>" alt="Cover photo">
                        <?php else: ?>
                          <span class="admin-biz-preview__empty"><i class="bi bi-image"></i> No cover</span>
                        <?php endif; ?>
                      </div>
                      <input type="file" class="form-control form-control-sm" name="cover_image" data-avatar-input accept="image/*">
                    </div>
                    <div class="admin-biz-media__proof">
                      <label class="form-label">Proof of Business Registration</label>
                      <div class="admin-biz-proof-actions">
                        <div class="admin-biz-proof-actions__file">
                          <input type="file" class="form-control form-control-sm" name="document_files[]" accept=".pdf,.doc,.docx,image/*" multiple>
                        </div>
                        <button type="button" class="btn btn-sm btn-ct admin-biz-proof-view" data-open-proof-card>View</button>
                      </div>
                    </div>
                    <div class="admin-biz-media__photos">
                      <label class="form-label">Business Photos</label>
                      <div class="admin-biz-proof-actions">
                        <div class="admin-biz-proof-actions__file">
                          <input type="file" class="form-control form-control-sm" name="business_photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                        </div>
                        <button type="button" class="btn btn-sm btn-ct admin-biz-proof-view" data-open-photos-card>View</button>
                      </div>
                    </div>
                    <?= admin_business_social_fields_html($edit, 'editBizListingForm') ?>
                    <div class="admin-biz-media__status">
                      <label class="form-label">Listing status</label>
                      <select class="form-select" name="status">
                        <option value="active" <?= ($edit['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>
            </fieldset>
          </form>

          <div class="admin-biz-place-split admin-biz-place-split--map-hours mt-2">
            <div class="admin-biz-place-split__map">
              <h3 class="h6 mb-1">Pin on map</h3>
              <p class="small text-muted mb-2"><?= $formLocked ? 'Saved location for this listing.' : 'The pin is locked. Click Edit pin on the map to move it, then save changes.' ?></p>
              <div id="editBizPinMap" class="ct-map"></div>
            </div>
            <div class="admin-biz-place-split__hours">
              <h3 class="h6 mb-1">Operating hours</h3>
              <p class="small text-muted mb-2">Select open days and set start / end times, or check 24 hrs. Unchecked days are closed.</p>
              <div class="guide-profile-avail">
                <?= opening_hours_avail_rows_html('adminBizHours', $availByDay, false, 'editBizListingForm') ?>
                <div class="admin-biz-place-split__visit mt-2">
                  <?= visit_duration_field_html('editBizVisit', $edit['visit_duration_minutes'] ?? null, 'editBizListingForm') ?>
                </div>
              </div>
            </div>
          </div>
          <?php if (!$formLocked): ?>
            <div class="mt-3">
              <button type="submit" form="editBizListingForm" class="btn btn-ct">Save changes</button>
            </div>
          <?php endif; ?>
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
      <?php if ($edit && !$formLocked) {
          render_unsaved_changes_modal();
      } ?>

      <?php if (!$edit && !$pinBiz): ?>
      <div class="glass p-3 mb-4 fade-up">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h3 class="panel-title mb-1"><?= $showAdd ? 'Add business' : 'Businesses' ?></h3>
            <p class="small text-muted mb-0"><?= $showAdd
              ? 'Create an approved listing, then give them the printed login card'
              : 'Choose a list to view active, pending, inactive, or rejected businesses' ?></p>
          </div>
          <?php if (!$showAdd): ?>
            <a class="btn btn-ct" href="<?= APP_URL ?>/admin/businesses.php?add=1">
              <i class="bi bi-plus-lg"></i> Add business
            </a>
          <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/admin/businesses.php">Cancel</a>
          <?php endif; ?>
        </div>
        <?php if (!$showAdd): ?>
        <div class="guide-tabs" role="tablist" aria-label="Business lists" data-live-regs="businesses" data-live-version="<?= e($bizLiveVersion) ?>">
          <?php foreach ($bizSections as $section): ?>
            <button
              type="button"
              class="guide-tabs__btn<?= $defaultBizTab === $section['id'] ? ' is-active' : '' ?>"
              data-biz-tab="<?= e($section['id']) ?>"
              role="tab"
              aria-selected="<?= $defaultBizTab === $section['id'] ? 'true' : 'false' ?>"
            >
              <span><?= e($section['btn']) ?></span>
              <span class="badge <?= e($section['badge']) ?>" data-live-count="<?= e($section['id']) ?>"><?= count($section['rows']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($showAdd): ?>
      <?php
        $postedAdd = $forceShowAdd && $_SERVER['REQUEST_METHOD'] === 'POST';
        $listing = $postedAdd ? [
            'name' => (string) ($_POST['name'] ?? ''),
            'business_type_id' => (int) ($_POST['business_type_id'] ?? 0),
            'email' => (string) ($_POST['email'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'permit_number' => (string) ($_POST['permit_number'] ?? ''),
            'tax_id' => (string) ($_POST['tax_id'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'facebook_url' => (string) ($_POST['facebook_url'] ?? ''),
            'instagram_url' => (string) ($_POST['instagram_url'] ?? ''),
            'address_barangay' => (string) ($_POST['address_barangay'] ?? ''),
            'address_street' => (string) ($_POST['address_street'] ?? ''),
            'owner_sex' => (string) ($_POST['owner_sex'] ?? ''),
            'representative_sex' => (string) ($_POST['representative_sex'] ?? ''),
            'latitude' => (string) ($_POST['latitude'] ?? ''),
            'longitude' => (string) ($_POST['longitude'] ?? ''),
        ] : [];
        $repParts = $postedAdd ? [
            'first' => (string) ($_POST['representative_first_name'] ?? ''),
            'middle' => (string) ($_POST['representative_middle_name'] ?? ''),
            'last' => (string) ($_POST['representative_last_name'] ?? ''),
            'suffix' => resolve_posted_name_suffix('representative_suffix', $_POST),
        ] : ['first' => '', 'middle' => '', 'last' => '', 'suffix' => ''];
        $ownerParts = $postedAdd ? [
            'first' => (string) ($_POST['owner_first_name'] ?? ''),
            'middle' => (string) ($_POST['owner_middle_name'] ?? ''),
            'last' => (string) ($_POST['owner_last_name'] ?? ''),
            'suffix' => resolve_posted_name_suffix('owner_suffix', $_POST),
        ] : ['first' => '', 'middle' => '', 'last' => '', 'suffix' => ''];
      ?>
      <div class="glass p-3 p-md-4 mb-4 fade-up">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h3 class="panel-title mb-0">Add business</h3>
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/businesses.php">Cancel</a>
        </div>
        <p class="small text-muted mb-3">Creates an approved listing. A one-time password is generated when you add them — print the card so they can log in and change it. The business email is their login.</p>
        <form method="post" enctype="multipart/form-data" id="addBizListingForm" data-required-check><?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="latitude" id="addBizLat" value="<?= e((string) ($listing['latitude'] ?? '')) ?>">
          <input type="hidden" name="longitude" id="addBizLng" value="<?= e((string) ($listing['longitude'] ?? '')) ?>">
          <div class="row g-3">
            <?php
              $listingFieldsPaired = true;
              $listingHideLogo = true;
              require __DIR__ . '/_business_listing_fields.php';
              $listingFieldsPaired = false;
              $listingMediaOptional = false;
            ?>
            <div class="col-12">
              <p class="small text-muted mb-2">Logo, description, cover photo, social links, map pin, operating hours, and services are added by the business after they sign in.</p>
              <div class="admin-biz-media__proof">
                <label class="form-label">Proof of Business Registration</label>
                <div class="admin-biz-proof-actions">
                  <div class="admin-biz-proof-actions__file">
                    <input type="file" class="form-control form-control-sm" name="document_files[]" accept=".pdf,.doc,.docx,image/*" multiple>
                  </div>
                  <button type="button" class="btn btn-sm btn-ct admin-biz-proof-view" data-open-proof-card>View</button>
                </div>
              </div>
            </div>
          </div>
        </form>
        <div class="mt-3">
          <button type="submit" form="addBizListingForm" class="btn btn-ct">Add business</button>
        </div>
      </div>
      <div id="bizProofCard" class="admin-biz-proof-overlay" hidden>
        <div class="admin-biz-proof-card" role="dialog" aria-labelledby="addBizProofCardTitle" aria-modal="true">
          <div class="admin-biz-proof-card__head">
            <h2 class="h6 mb-0" id="addBizProofCardTitle">Proof of Business Registration</h2>
            <button type="button" class="admin-biz-proof-card__close" data-close-proof-card aria-label="Close">&times;</button>
          </div>
          <div class="admin-biz-proof-card__body">
            <p class="small text-muted mb-0">No proof file is saved for this listing yet. Choose a file, then add the business.</p>
            <div class="admin-biz-proof-card__pic" hidden>
              <img alt="Proof of business registration">
            </div>
          </div>
        </div>
      </div>
      <div id="bizPhotosCard" class="admin-biz-proof-overlay" hidden>
        <div class="admin-biz-proof-card admin-biz-proof-card--photos" role="dialog" aria-labelledby="addBizPhotosCardTitle" aria-modal="true">
          <div class="admin-biz-proof-card__head">
            <h2 class="h6 mb-0" id="addBizPhotosCardTitle">Business Photos</h2>
            <button type="button" class="admin-biz-proof-card__close" data-close-photos-card aria-label="Close">&times;</button>
          </div>
          <div class="admin-biz-proof-card__body">
            <p class="small text-muted mb-0">No photos are saved for this listing yet. Choose files, then add the business.</p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$edit && !$pinBiz && !$showAdd): ?>
      <div class="guide-sections">
        <?php foreach ($bizSections as $section): ?>
          <div
            class="glass guide-section<?= $defaultBizTab === $section['id'] ? ' is-active' : '' ?>"
            data-biz-panel="<?= e($section['id']) ?>"
            role="tabpanel"
            <?= $defaultBizTab === $section['id'] ? '' : 'hidden' ?>
          >
            <div class="guide-section__head">
              <div class="guide-section__titles">
                <h3 class="panel-title mb-0"><?= e($section['title']) ?></h3>
                <p class="small text-muted mb-0"><?= e($section['sub']) ?></p>
              </div>
              <span class="badge <?= e($section['badge']) ?> guide-section__count" data-live-panel-count="<?= e($section['id']) ?>"><?= count($section['rows']) ?></span>
            </div>
            <div class="guide-section__list table-responsive">
              <table class="table table-ct align-middle mb-0 biz-table">
                <colgroup>
                  <col class="biz-col-biz">
                  <col class="biz-col-type">
                  <col class="biz-col-owner">
                  <col class="biz-col-map">
                  <col class="biz-col-status">
                  <col class="biz-col-actions">
                </colgroup>
                <thead>
                  <tr>
                    <th>Business</th>
                    <th>Type</th>
                    <th>Owner</th>
                    <th>Map</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody data-live-tbody="<?= e($section['id']) ?>" data-live-empty="<?= e($section['empty']) ?>" data-live-cols="6">
                  <?= admin_render_business_rows($section['rows'], $docsByBiz, $section['approve']) ?>
                  <?php if (!$section['rows']): ?>
                    <tr class="live-empty-row"><td colspan="6" class="text-muted"><?= e($section['empty']) ?></td></tr>
                  <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$pinScripts = '';
if ($pinBiz) {
    $latJs = $pinBiz['latitude'] !== null && $pinBiz['latitude'] !== '' ? (float) $pinBiz['latitude'] : 'null';
    $lngJs = $pinBiz['longitude'] !== null && $pinBiz['longitude'] !== '' ? (float) $pinBiz['longitude'] : 'null';
    $pinColorJs = json_encode(normalize_pin_color($pinBiz['pin_color'] ?? ''));
    $pinScripts = <<<HTML
  CulTourMap.renderPinPicker('adminBizPinMap', {
    latInput: '#adminBizLat',
    lngInput: '#adminBizLng',
    initialLat: {$latJs},
    initialLng: {$lngJs},
    kind: 'business',
    color: {$pinColorJs}
  });
HTML;
}

$editScripts = '';
if ($edit) {
    $eLat = $edit['latitude'] !== null && $edit['latitude'] !== '' ? (float) $edit['latitude'] : 'null';
    $eLng = $edit['longitude'] !== null && $edit['longitude'] !== '' ? (float) $edit['longitude'] : 'null';
    $mapReadOnly = !empty($formLocked) ? 'true' : 'false';
    $editScripts = <<<HTML
  CulTourMap.renderPinPicker('editBizPinMap', {
    latInput: '#editBizLat',
    lngInput: '#editBizLng',
    initialLat: {$eLat},
    initialLng: {$eLng},
    readOnly: {$mapReadOnly},
    lockUntilEdit: true,
    kind: 'business',
    colorSelect: '#editBizListingForm select[name="business_type_id"]'
  });
HTML;
}

$appUrl = APP_URL;
$phJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/ph-address.js') ?: time());
$reqJsVer = (string) (@filemtime(ROOT_PATH . '/assets/js/form-required.js') ?: time());
$addLatJs = 'null';
$addLngJs = 'null';
if ($showAdd) {
    $postedLat = trim((string) ($_POST['latitude'] ?? ''));
    $postedLng = trim((string) ($_POST['longitude'] ?? ''));
    if ($postedLat !== '') {
        $addLatJs = (float) $postedLat;
    }
    if ($postedLng !== '') {
        $addLngJs = (float) $postedLng;
    }
}
$extraScripts = map_leaflet_scripts() . <<<HTML
<script src="{$appUrl}/assets/js/ph-address.js?v={$phJsVer}"></script>
<script src="{$appUrl}/assets/js/form-required.js?v={$reqJsVer}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.CulTourPhAddress) {
    CulTourPhAddress.init('{$appUrl}/assets/data/ph-address.json');
  }
  if (document.getElementById('addBizPinMap')) {
    CulTourMap.renderPinPicker('addBizPinMap', {
      latInput: '#addBizLat',
      lngInput: '#addBizLng',
      initialLat: {$addLatJs},
      initialLng: {$addLngJs},
      kind: 'business',
      colorSelect: '#addBizListingForm select[name="business_type_id"]'
    });
  }
{$pinScripts}
{$editScripts}

  document.querySelectorAll('.admin-locked-form.is-locked').forEach(function (fs) {
    fs.querySelectorAll('input, select, textarea, button').forEach(function (el) {
      if (el.hasAttribute('data-open-proof-card') || el.hasAttribute('data-open-photos-card') || el.classList.contains('admin-biz-proof-view')) return;
      el.disabled = true;
    });
  });

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
      btn.disabled = false;
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

  const buttons = Array.prototype.slice.call(document.querySelectorAll('[data-biz-tab]'));
  const panels = Array.prototype.slice.call(document.querySelectorAll('[data-biz-panel]'));
  function showBizTab(id) {
    buttons.forEach(function (btn) {
      const on = btn.getAttribute('data-biz-tab') === id;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      const on = panel.getAttribute('data-biz-panel') === id;
      panel.classList.toggle('is-active', on);
      if (on) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
    });
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      window.history.replaceState({}, '', url);
    } catch (e) { /* ignore */ }
  }
  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      showBizTab(btn.getAttribute('data-biz-tab'));
    });
  });
});
</script>
HTML;
?>
<?php if (!empty($createdBusiness) && is_array($createdBusiness)): ?>
  <?php
    $cbBiz = trim((string) ($createdBusiness['business'] ?? ''));
    $cbName = trim((string) ($createdBusiness['name'] ?? ''));
    $cbEmail = trim((string) ($createdBusiness['email'] ?? ''));
    $cbPhone = trim((string) ($createdBusiness['phone'] ?? ''));
    $cbPass = (string) ($createdBusiness['password'] ?? '');
  ?>
  <div id="adminCreatedBizOverlay" class="admin-biz-proof-overlay admin-created-cred-overlay">
    <div class="admin-biz-proof-card admin-created-cred-card admin-created-cred-print-sheet">
      <div class="admin-biz-proof-card__head">
        <div>
          <div class="small text-muted"><?= e(APP_NAME) ?></div>
          <h2 class="h6 mb-0">Business account</h2>
        </div>
        <button type="button" class="admin-biz-proof-card__close admin-created-cred-actions" data-close-created-biz aria-label="Close">&times;</button>
      </div>
      <div class="admin-biz-proof-card__body admin-created-cred-body">
        <p class="small text-muted mb-3">Give this slip to the owner. They must change the password on first login.</p>
        <dl class="admin-created-cred-dl">
          <dt>Business</dt>
          <dd><?= e($cbBiz !== '' ? $cbBiz : '—') ?></dd>
          <dt>Owner</dt>
          <dd><?= e($cbName !== '' ? $cbName : '—') ?></dd>
          <dt>Email</dt>
          <dd><?= e($cbEmail !== '' ? $cbEmail : '—') ?></dd>
          <dt>Phone</dt>
          <dd><?= e($cbPhone !== '' ? $cbPhone : '—') ?></dd>
        </dl>
        <div class="admin-created-cred-passbox">
          <div class="admin-created-cred-passbox__label">Temporary password</div>
          <div class="admin-created-cred-passbox__value"><?= e($cbPass) ?></div>
        </div>
        <div class="admin-created-cred-actions d-flex flex-wrap gap-2 mt-3">
          <button type="button" class="btn btn-ct" data-print-created-biz>
            <i class="bi bi-printer"></i> Print
          </button>
          <button type="button" class="btn btn-outline-secondary" data-close-created-biz>Done</button>
        </div>
      </div>
    </div>
  </div>
<?php
$extraScripts = ($extraScripts ?? '') . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('adminCreatedBizOverlay');
  if (!overlay) return;
  document.body.appendChild(overlay);
  overlay.hidden = false;
  document.body.classList.add('admin-biz-proof-open', 'admin-created-cred-open');
  function closeCreated() {
    overlay.hidden = true;
    document.body.classList.remove('admin-biz-proof-open', 'admin-created-cred-open');
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete('created');
      window.history.replaceState({}, '', url);
    } catch (e) {}
  }
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay || e.target.closest('[data-close-created-biz]')) closeCreated();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeCreated();
  });
  var printBtn = overlay.querySelector('[data-print-created-biz]');
  if (printBtn) {
    printBtn.addEventListener('click', function () { window.print(); });
  }
});
</script>
HTML;
endif;
?>
<?php
admin_application_viewer_mount();
require __DIR__ . '/../includes/footer.php';
?>
