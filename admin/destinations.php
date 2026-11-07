<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
$user = require_login(ROLE_ADMIN);
ensure_place_schedule_schema();
ensure_itinerary_items_schema();
$pageTitle = 'Destinations';
$pageHeading = 'Manage Destinations';
$pageSub = 'Add places and pin them on the interactive map';
$activeNav = 'destinations';

ensure_category_pin_colors();
$categories = db()->query('SELECT id, name, pin_color FROM categories ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'deactivate' || $action === 'activate') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            flash('error', 'Invalid destination.');
            redirect(APP_URL . '/admin/destinations.php');
        }
        $st = db()->prepare('SELECT id, status FROM destinations WHERE id=?');
        $st->execute([$id]);
        $dest = $st->fetch();
        if (!$dest) {
            flash('error', 'Destination not found.');
            redirect(APP_URL . '/admin/destinations.php');
        }
        if ($action === 'deactivate') {
            db()->prepare("UPDATE destinations SET status='draft' WHERE id=?")->execute([$id]);
            log_activity((int) $user['id'], 'deactivate_destination', 'destinations', 'Destination #' . $id);
            flash('success', 'Destination deactivated.');
            redirect(APP_URL . '/admin/destinations.php?tab=inactive');
        }
        db()->prepare("UPDATE destinations SET status='published' WHERE id=?")->execute([$id]);
        log_activity((int) $user['id'], 'activate_destination', 'destinations', 'Destination #' . $id);
        flash('success', 'Destination activated.');
        redirect(APP_URL . '/admin/destinations.php');
    }

    if (in_array($action, ['add_service', 'delete_service'], true)) {
        $id = (int) ($_POST['destination_id'] ?? $_POST['id'] ?? 0);
        $return = APP_URL . '/admin/destinations.php?edit=' . $id;
        $st = db()->prepare('SELECT id FROM destinations WHERE id=?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            flash('error', 'Destination not found.');
            redirect(APP_URL . '/admin/destinations.php');
        }
        if ($action === 'add_service') {
            $svc = parse_place_service_post($_POST);
            if (empty($svc['ok'])) {
                flash('error', (string) ($svc['message'] ?? 'Service name is required.'));
                redirect($return);
            }
            $d = $svc['data'];
            db()->prepare(
                'INSERT INTO destination_services (destination_id, name, description, price, duration_minutes)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $id,
                $d['name'],
                $d['description'],
                $d['price'],
                $d['duration_minutes'],
            ]);
            flash('success', 'Service added.');
        } else {
            db()->prepare('DELETE FROM destination_services WHERE id=? AND destination_id=?')
                ->execute([(int) ($_POST['service_id'] ?? 0), $id]);
            flash('success', 'Service removed.');
        }
        redirect($return);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $coords = normalize_lat_lng($_POST['latitude'] ?? '', $_POST['longitude'] ?? '');
    if (empty($coords['ok'])) {
        flash('error', (string) ($coords['message'] ?? 'Invalid map coordinates.'));
        redirect(APP_URL . '/admin/destinations.php' . ($id ? ('?edit=' . $id) : '?add=1'));
    }
    $lat = $coords['lat'];
    $lng = $coords['lng'];
    $name = trim((string) ($_POST['name'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    if ($name === '' || $location === '' || $description === '') {
        flash('error', 'Name, location, and description are required.');
        redirect(APP_URL . '/admin/destinations.php' . ($id ? ('?edit=' . $id) : '?add=1'));
    }
    $catOk = db()->prepare('SELECT id FROM categories WHERE id=?');
    $catOk->execute([$categoryId]);
    if ($categoryId < 1 || !$catOk->fetch()) {
        flash('error', 'Please choose a valid category.');
        redirect(APP_URL . '/admin/destinations.php' . ($id ? ('?edit=' . $id) : '?add=1'));
    }
    $postedStatus = (string) ($_POST['status'] ?? 'published');
    $saveStatus = in_array($postedStatus, ['draft', 'archived', 'inactive'], true) ? 'draft' : 'published';
    $visitParsed = parse_visit_duration_post($_POST);
    if (!$visitParsed['ok']) {
        flash('error', (string) ($visitParsed['message'] ?? 'Enter a valid visit duration.'));
        redirect(APP_URL . '/admin/destinations.php' . ($id ? ('?edit=' . $id) : '?add=1'));
    }
    $visitLimit = $visitParsed['limit'];
    $visitMins = $visitLimit ?? 60;
    $data = [
        $categoryId,
        $name,
        slugify($name) . ($id ? '' : '-' . time()),
        $location,
        $description,
        trim($_POST['history'] ?? ''),
        encode_opening_hours_from_post($_POST),
        (float)($_POST['entrance_fee'] ?? 0),
        trim($_POST['travel_tips'] ?? ''),
        trim($_POST['nearby_attractions'] ?? ''),
        trim($_POST['emergency_contacts'] ?? ''),
        isset($_POST['is_featured']) ? 1 : 0,
        $saveStatus,
        $lat,
        $lng,
        $visitMins,
        $visitLimit,
    ];

    $cover = null;
    if (!empty($_FILES['cover_image']['name'])) {
        $cover = upload_file($_FILES['cover_image'], 'destinations', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!$cover) {
            flash('error', upload_last_error() ?: 'Could not upload the cover image.');
            redirect(APP_URL . '/admin/destinations.php' . ($id ? ('?edit=' . $id) : '?add=1'));
        }
    }

    if ($id) {
        $sql = 'UPDATE destinations SET category_id=?, name=?, location=?, description=?, history=?, opening_hours=?, entrance_fee=?, travel_tips=?, nearby_attractions=?, emergency_contacts=?, is_featured=?, status=?, latitude=?, longitude=?, default_visit_minutes=?, visit_duration_minutes=?';
        $params = [$data[0], $data[1], $data[3], $data[4], $data[5], $data[6], $data[7], $data[8], $data[9], $data[10], $data[11], $data[12], $data[13], $data[14], $data[15], $data[16]];
        if ($cover) {
            $sql .= ', cover_image=?';
            $params[] = $cover;
        }
        $sql .= ' WHERE id=?';
        $params[] = $id;
        db()->prepare($sql)->execute($params);
        if (!empty($_FILES['destination_photos']['name'])) {
            save_destination_gallery_photos($id, $_FILES['destination_photos']);
        }
        flash('success', 'Destination updated.');
        redirect(posted_return_to(APP_URL . '/admin/destinations.php?edit=' . $id));
    } else {
        db()->prepare(
            'INSERT INTO destinations (category_id, name, slug, location, description, history, opening_hours, entrance_fee, travel_tips, nearby_attractions, emergency_contacts, cover_image, is_featured, status, created_by, latitude, longitude, default_visit_minutes, visit_duration_minutes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $data[7],
            $data[8], $data[9], $data[10], $cover, $data[11], $data[12], $user['id'], $data[13], $data[14], $data[15], $data[16],
        ]);
        $newId = (int) db()->lastInsertId();
        if (!empty($_FILES['destination_photos']['name'])) {
            save_destination_gallery_photos($newId, $_FILES['destination_photos']);
        }
        flash('success', 'Destination created.');
        redirect(posted_return_to(APP_URL . '/admin/destinations.php?edit=' . $newId));
    }
}

$edit = null;
$isEditing = !empty($_GET['edit']);
$viewId = (int) ($_GET['view'] ?? $_GET['edit'] ?? 0);
if ($viewId) {
    $s = db()->prepare(
    'SELECT d.*, c.name AS category_name, c.pin_color
     FROM destinations d
     LEFT JOIN categories c ON c.id=d.category_id
     WHERE d.id=?'
);
    $s->execute([$viewId]);
    $edit = $s->fetch() ?: null;
    if (!$edit) {
        flash('error', 'Destination not found.');
        redirect(APP_URL . '/admin/destinations.php');
    }
}
$showAdd = !$edit && isset($_GET['add']);
$showView = (bool) ($edit && !$isEditing);
$showForm = $showAdd || ($edit && $isEditing);
$formLocked = false;
$availByDay = opening_hours_to_avail_by_day([]);
$destServices = [];
$destPhotos = [];
$coverUrl = '';
$dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
if ($edit) {
    $pageTitle = (string) ($edit['name'] ?? 'Destination');
    $pageHeading = $pageTitle !== '' ? $pageTitle : 'Destination';
    $pageSub = $isEditing ? 'Edit destination' : 'Destination details';
    $rawHours = $edit['opening_hours'] ?? null;
    if (is_array($rawHours)) {
        $rawHours = json_encode($rawHours);
    }
    $availByDay = opening_hours_to_avail_by_day(parse_opening_hours_value($rawHours) ?: []);
    $ss = db()->prepare('SELECT * FROM destination_services WHERE destination_id=? ORDER BY name');
    $ss->execute([(int) $edit['id']]);
    $destServices = $ss->fetchAll() ?: [];
    $ps = db()->prepare('SELECT id, image_path FROM destination_images WHERE destination_id=? ORDER BY sort_order ASC, id ASC');
    $ps->execute([(int) $edit['id']]);
    $destPhotos = $ps->fetchAll() ?: [];
    $coverPath = trim((string) ($edit['cover_image'] ?? ''));
    $coverUrl = $coverPath !== '' ? (APP_URL . '/uploads/' . ltrim($coverPath, '/')) : '';
}

$rows = db()->query(
    'SELECT d.*, c.name AS category_name FROM destinations d JOIN categories c ON c.id=d.category_id ORDER BY d.name'
)->fetchAll() ?: [];
$activeDestinations = [];
$inactiveDestinations = [];
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'published') {
        $activeDestinations[] = $r;
    } else {
        $inactiveDestinations[] = $r;
    }
}
$destSections = [
    [
        'id' => 'active',
        'title' => 'Active destinations',
        'sub' => 'Published places on CulTour',
        'badge' => 'text-bg-success',
        'btn' => 'Active destinations',
        'rows' => $activeDestinations,
        'empty' => 'No active destinations yet. Click Add destination to create one.',
    ],
    [
        'id' => 'inactive',
        'title' => 'Inactive destinations',
        'sub' => 'Hidden from the explore map and tourist lists',
        'badge' => 'text-bg-warning',
        'btn' => 'Inactive destinations',
        'rows' => $inactiveDestinations,
        'empty' => 'No inactive destinations.',
    ],
];
$defaultDestTab = 'active';
if (!empty($_GET['tab']) && in_array($_GET['tab'], ['active', 'inactive'], true)) {
    $defaultDestTab = $_GET['tab'];
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

      <?php if (!$edit): ?>
      <div class="glass p-3 mb-4 fade-up">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h3 class="panel-title mb-1">Destinations</h3>
            <p class="small text-muted mb-0">Choose a list to view active or inactive destinations</p>
          </div>
          <?php if (!$showAdd): ?>
            <a class="btn btn-ct" href="<?= APP_URL ?>/admin/destinations.php?add=1">
              <i class="bi bi-plus-lg"></i> Add destination
            </a>
          <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/admin/destinations.php">Cancel</a>
          <?php endif; ?>
        </div>
        <div class="guide-tabs" role="tablist" aria-label="Destination lists">
          <?php foreach ($destSections as $section): ?>
            <button
              type="button"
              class="guide-tabs__btn<?= $defaultDestTab === $section['id'] ? ' is-active' : '' ?>"
              data-dest-tab="<?= e($section['id']) ?>"
              role="tab"
              aria-selected="<?= $defaultDestTab === $section['id'] ? 'true' : 'false' ?>"
            >
              <span><?= e($section['btn']) ?></span>
              <span class="badge <?= e($section['badge']) ?>"><?= count($section['rows']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($showView && $edit): ?>
        <?php
          $destVal = static function ($v): string {
              $v = trim((string) $v);
              return $v !== '' ? e($v) : '—';
          };
          $isActiveDestView = ($edit['status'] ?? '') === 'published';
          $viewInitial = mb_strtoupper(mb_substr((string) ($edit['name'] ?? 'D'), 0, 1));
        ?>
        <div class="mb-3 d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/destinations.php">&larr; Back to destinations</a>
          <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/destinations.php?edit=<?= (int) $edit['id'] ?>">Edit details</a>
        </div>

        <div class="glass p-3 p-md-4 mb-3 fade-up admin-guide-profile">
          <div class="admin-guide-profile__hero admin-guide-profile__hero--dest">
            <div class="admin-dest-details__cover">
              <?php if ($coverUrl): ?>
                <img src="<?= e($coverUrl) ?>" alt="<?= e($edit['name'] ?? '') ?>">
              <?php else: ?>
                <div class="admin-dest-details__cover-empty" aria-hidden="true"><?= e($viewInitial) ?></div>
              <?php endif; ?>
            </div>
            <div class="admin-guide-profile__intro">
              <div class="small text-muted mb-1">
                <?= e($edit['category_name'] ?? 'Destination') ?> · <?= status_badge($isActiveDestView ? 'active' : 'inactive') ?>
                <?= !empty($edit['is_featured']) ? ' · <span class="badge text-bg-primary">Featured</span>' : '' ?>
              </div>
              <h2 class="h4 mb-1"><?= e((string) ($edit['name'] ?? 'Destination')) ?></h2>
              <div class="text-muted"><?= $destVal($edit['location'] ?? '') ?></div>
              <div class="admin-guide-profile__rating">
                <?= rating_stars_html($edit['avg_rating'] ?? 0, (int) ($edit['review_count'] ?? 0), 'lg') ?>
              </div>
            </div>
          </div>
        </div>

        <div class="glass p-3 p-md-4 mb-3 fade-up">
          <h3 class="panel-title mb-3">Destination details</h3>
          <dl class="admin-guide-profile__dl mb-0">
              <div>
                <dt>Name</dt>
                <dd><?= $destVal($edit['name'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Category</dt>
                <dd><?= $destVal($edit['category_name'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Average rating</dt>
                <dd><?= rating_stars_html($edit['avg_rating'] ?? 0, (int) ($edit['review_count'] ?? 0), 'sm') ?></dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>Location</dt>
                <dd><?= $destVal($edit['location'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Entrance fee</dt>
                <dd><?= e(format_money($edit['entrance_fee'] ?? 0)) ?></dd>
              </div>
              <div>
                <dt>Visit duration</dt>
                <dd><?= e(place_visit_duration_display($edit['visit_duration_minutes'] ?? null)) ?></dd>
              </div>
              <div>
                <dt>Status</dt>
                <dd><?= $isActiveDestView ? 'Active' : 'Inactive' ?></dd>
              </div>
              <div>
                <dt>Featured</dt>
                <dd><?= !empty($edit['is_featured']) ? 'Yes' : 'No' ?></dd>
              </div>
              <div>
                <dt>Emergency contacts</dt>
                <dd><?= $destVal($edit['emergency_contacts'] ?? '') ?></dd>
              </div>
              <div>
                <dt>Map pin</dt>
                <dd>
                  <?php if ($edit['latitude'] !== null && $edit['latitude'] !== '' && $edit['longitude'] !== null && $edit['longitude'] !== ''): ?>
                    <?= e(number_format((float) $edit['latitude'], 6)) ?>, <?= e(number_format((float) $edit['longitude'], 6)) ?>
                  <?php else: ?>
                    Not pinned
                  <?php endif; ?>
                </dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>Description</dt>
                <dd><?= trim((string) ($edit['description'] ?? '')) !== '' ? nl2br(e((string) $edit['description'])) : '—' ?></dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>History</dt>
                <dd><?= trim((string) ($edit['history'] ?? '')) !== '' ? nl2br(e((string) $edit['history'])) : '—' ?></dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>Travel tips</dt>
                <dd><?= trim((string) ($edit['travel_tips'] ?? '')) !== '' ? nl2br(e((string) $edit['travel_tips'])) : '—' ?></dd>
              </div>
              <div class="admin-guide-profile__dl-full">
                <dt>Nearby attractions</dt>
                <dd><?= trim((string) ($edit['nearby_attractions'] ?? '')) !== '' ? nl2br(e((string) $edit['nearby_attractions'])) : '—' ?></dd>
              </div>
            </dl>
          <div class="mt-4">
            <h3 class="h6 mb-2">Destination photos</h3>
            <?php if ($destPhotos): ?>
              <div class="admin-dest-photo-thumbs">
                <?php foreach ($destPhotos as $ph): ?>
                  <a href="<?= APP_URL ?>/uploads/<?= e($ph['image_path']) ?>" target="_blank" rel="noopener">
                    <img src="<?= APP_URL ?>/uploads/<?= e($ph['image_path']) ?>" alt="">
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-muted small mb-0">No photos uploaded.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-5">
            <div class="glass p-3 p-md-4 h-100 fade-up">
              <h3 class="panel-title mb-1">Pin on map</h3>
              <p class="small text-muted mb-2">Saved location for this destination.</p>
              <div id="destInfoMap" class="ct-map"></div>
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
              <h3 class="panel-title mb-3">Listing</h3>
              <?php if ($destServices): ?>
                <ul class="list-unstyled small mb-0">
                  <?php foreach ($destServices as $svc): ?>
                    <li class="py-1 border-bottom">
                      <span class="fw-semibold"><?= e($svc['name']) ?></span>
                      <span class="text-muted"> · <?= e(format_money($svc['price'])) ?></span>
                      <?= !empty($svc['duration_minutes']) ? ' · ' . (int) $svc['duration_minutes'] . ' min' : '' ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="text-muted small mb-0">No listings yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($showForm): ?>
        <?php if ($edit): ?>
          <div class="mb-3 d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/destinations.php">&larr; Back to destinations</a>
            <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/admin/destinations.php?view=<?= (int) $edit['id'] ?>">View destination details</a>
          </div>
        <?php endif; ?>
      <div class="glass p-3 p-md-4 mb-4 fade-up">
        <?php if (!$edit): ?>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="panel-title mb-0">Add destination</h3>
            <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/destinations.php">Cancel</a>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="destForm" data-unsaved-guard>
          <?= csrf_field() ?>
          <input type="hidden" name="return_to" value="">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <input type="hidden" name="latitude" id="destLat" value="<?= e((string) ($edit['latitude'] ?? '')) ?>">
          <input type="hidden" name="longitude" id="destLng" value="<?= e((string) ($edit['longitude'] ?? '')) ?>">

          <div class="row g-3">
            <div class="col-12">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="is_featured" id="feat" <?= !empty($edit['is_featured']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="feat">Featured destination</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Name</label>
              <input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select class="form-select" name="category_id" required>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" data-pin-color="<?= e(normalize_pin_color($c['pin_color'] ?? '')) ?>" <?= (($edit['category_id'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Location</label>
              <input class="form-control" name="location" required value="<?= e($edit['location'] ?? '') ?>">
            </div>
            <div class="col-12">
              <div class="admin-dest-cover-row">
                <div class="admin-dest-cover-row__bio">
                  <label class="form-label">Description</label>
                  <textarea class="form-control" name="description" rows="8" required><?= e($edit['description'] ?? '') ?></textarea>
                </div>
                <div class="admin-dest-cover-row__cover">
                  <label class="form-label">Cover photo</label>
                  <div class="admin-dest-cover-row__preview" data-avatar-preview>
                    <div class="admin-biz-preview admin-biz-preview--cover" data-avatar-frame>
                      <?php if ($coverUrl): ?>
                        <img src="<?= e($coverUrl) ?>" alt="Cover photo">
                      <?php else: ?>
                        <span class="admin-biz-preview__empty"><i class="bi bi-image"></i> No cover</span>
                      <?php endif; ?>
                    </div>
                    <input type="file" class="form-control form-control-sm" name="cover_image" data-avatar-input accept="image/jpeg,image/png,image/gif,image/webp">
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Emergency contacts</label>
              <input class="form-control" name="emergency_contacts" value="<?= e($edit['emergency_contacts'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Destination photos</label>
              <div class="admin-biz-proof-actions">
                <div class="admin-biz-proof-actions__file">
                  <input type="file" class="form-control" name="destination_photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                </div>
                <button type="button" class="btn btn-ct admin-biz-proof-view" data-open-dest-photos-card>View</button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">History</label>
              <textarea class="form-control" name="history" rows="3"><?= e($edit['history'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Travel tips</label>
              <textarea class="form-control" name="travel_tips" rows="3"><?= e($edit['travel_tips'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nearby attractions</label>
              <textarea class="form-control" name="nearby_attractions" rows="3"><?= e($edit['nearby_attractions'] ?? '') ?></textarea>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <option value="published" <?= (($edit['status'] ?? 'published') === 'published') ? 'selected' : '' ?>>Active</option>
                <option value="draft" <?= (($edit['status'] ?? 'published') !== 'published') ? 'selected' : '' ?>>Inactive</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Entrance fee</label>
              <input type="number" step="0.01" class="form-control" name="entrance_fee" value="<?= e($edit['entrance_fee'] ?? '0') ?>">
            </div>
          </div>
        </form>

        <div class="admin-biz-place-split mt-3">
          <div class="admin-biz-place-split__map">
            <h3 class="h6 mb-1">Pin on map</h3>
            <p class="small text-muted mb-2"><?= $edit ? 'The pin is locked. Click Edit pin on the map to move it, then save changes.' : 'Click the map to drop a pin · drag to fine-tune' ?></p>
            <div id="destPinMap" class="ct-map"></div>
          </div>
          <div class="admin-biz-place-split__hours">
            <h3 class="h6 mb-1">Operating hours</h3>
            <p class="small text-muted mb-2">Select open days and set start / end times, or check 24 hrs. Unchecked days are closed.</p>
            <div class="guide-profile-avail">
              <?= opening_hours_avail_rows_html('adminDestHours', $availByDay, false, 'destForm') ?>
              <div class="admin-biz-place-split__visit mt-2">
                <?= visit_duration_field_html('destVisit', $edit['visit_duration_minutes'] ?? null, 'destForm') ?>
              </div>
            </div>
          </div>
          <div class="admin-biz-place-split__services">
            <h3 class="h6 mb-1">Listing</h3>
            <p class="small text-muted mb-2">Add a name. New listings appear in the list below.</p>
            <div class="admin-biz-place-split__services-body">
            <?php if ($edit): ?>
              <form method="post" class="row g-2 mb-3" data-unsaved-ignore>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_service">
                <input type="hidden" name="destination_id" value="<?= (int) $edit['id'] ?>">
                <div class="col-12">
                  <input class="form-control form-control-sm" name="name" placeholder="Service name" required>
                </div>
                <div class="col-6">
                  <input type="number" step="0.01" class="form-control form-control-sm" name="price" placeholder="Price" value="0">
                </div>
                <div class="col-6">
                  <input type="number" class="form-control form-control-sm" name="duration_minutes" placeholder="Min" min="15" step="15">
                </div>
                <div class="col-12">
                  <button class="btn btn-sm btn-ct">Add listing</button>
                </div>
              </form>
            <?php else: ?>
              <p class="small text-muted mb-3">Save the destination first, then you can add listings.</p>
            <?php endif; ?>
            <?php if ($destServices): ?>
              <ul class="list-unstyled small mb-0">
                <?php foreach ($destServices as $svc): ?>
                  <li class="d-flex justify-content-between align-items-start gap-2 py-1 border-bottom">
                    <span>
                      <span class="fw-semibold"><?= e($svc['name']) ?></span>
                      <span class="text-muted"> · <?= e(format_money($svc['price'])) ?></span>
                      <?= !empty($svc['duration_minutes']) ? ' · ' . (int) $svc['duration_minutes'] . ' min' : '' ?>
                    </span>
                    <form method="post" class="m-0" data-unsaved-ignore onsubmit="return confirm('Remove this listing?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_service">
                      <input type="hidden" name="destination_id" value="<?= (int) $edit['id'] ?>">
                      <input type="hidden" name="service_id" value="<?= (int) $svc['id'] ?>">
                      <button class="btn btn-sm btn-link text-danger p-0">Remove</button>
                    </form>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="small text-muted mb-0"><?= $edit ? 'No listings yet.' : '' ?></p>
            <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" form="destForm" class="btn btn-ct"><?= $edit ? 'Save changes' : 'Create destination' ?></button>
        </div>
      </div>
      <?php render_unsaved_changes_modal(); ?>
      <div id="destPhotosCard" class="admin-biz-proof-overlay" hidden>
        <div class="admin-biz-proof-card admin-biz-proof-card--photos" role="dialog" aria-labelledby="destPhotosCardTitle" aria-modal="true">
          <div class="admin-biz-proof-card__head">
            <h2 class="h6 mb-0" id="destPhotosCardTitle">Destination photos</h2>
            <button type="button" class="admin-biz-proof-card__close" data-close-dest-photos-card aria-label="Close">&times;</button>
          </div>
          <div class="admin-biz-proof-card__body">
            <?php if ($destPhotos):
              $photoUrls = array_values(array_map(static function ($ph) {
                  return APP_URL . '/uploads/' . $ph['image_path'];
              }, $destPhotos));
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
                      <img src="<?= e($firstPhotoHref) ?>" alt="Destination photo">
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
              <p class="small text-muted mb-0">No photos are saved for this destination yet. Choose files, then click Save changes.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$edit): ?>
      <div class="guide-sections">
        <?php foreach ($destSections as $section): ?>
          <div
            class="glass guide-section<?= $defaultDestTab === $section['id'] ? ' is-active' : '' ?>"
            data-dest-panel="<?= e($section['id']) ?>"
            role="tabpanel"
            <?= $defaultDestTab === $section['id'] ? '' : 'hidden' ?>
          >
            <div class="guide-section__head">
              <div class="guide-section__titles">
                <h3 class="panel-title mb-0"><?= e($section['title']) ?></h3>
                <p class="small text-muted mb-0"><?= e($section['sub']) ?></p>
              </div>
              <span class="badge <?= e($section['badge']) ?> guide-section__count"><?= count($section['rows']) ?></span>
            </div>
            <div class="guide-section__list table-responsive">
              <table class="table table-ct align-middle mb-0">
                <thead>
                  <tr>
                    <th>Destination</th>
                    <th>Category</th>
                    <th>Coordinates</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($section['rows'] as $r): ?>
                    <?php $isActiveDest = ($r['status'] ?? '') === 'published'; ?>
                    <tr>
                      <td>
                        <div class="d-flex align-items-center gap-2 min-w-0">
                          <?= admin_list_photo_html((string) ($r['cover_image'] ?? '')) ?>
                          <div class="min-w-0">
                            <a class="fw-semibold text-decoration-none" href="<?= APP_URL ?>/admin/destinations.php?view=<?= (int) $r['id'] ?>">
                              <?= e($r['name']) ?>
                            </a>
                            <div class="small text-muted text-truncate"><?= e($r['location']) ?></div>
                          </div>
                        </div>
                      </td>
                      <td><?= e($r['category_name']) ?></td>
                      <td class="small text-muted">
                        <?php if ($r['latitude'] !== null && $r['longitude'] !== null): ?>
                          <?= e(number_format((float) $r['latitude'], 4)) ?>, <?= e(number_format((float) $r['longitude'], 4)) ?>
                        <?php else: ?>
                          <span class="text-warning">Not pinned</span>
                        <?php endif; ?>
                      </td>
                      <td><?= e(format_money($r['entrance_fee'])) ?></td>
                      <td>
                        <?= status_badge($isActiveDest ? 'active' : 'inactive') ?>
                        <?= $r['is_featured'] ? ' <span class="badge text-bg-primary">Featured</span>' : '' ?>
                      </td>
                      <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/destinations.php?edit=<?= (int) $r['id'] ?>">Edit</a>
                        <?php if ($isActiveDest): ?>
                          <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button name="action" value="deactivate" class="btn btn-sm btn-warning">Deactivate</button>
                          </form>
                        <?php else: ?>
                          <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button name="action" value="activate" class="btn btn-sm btn-success">Activate</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$section['rows']): ?>
                    <tr><td colspan="6" class="text-muted"><?= e($section['empty']) ?></td></tr>
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
$extraScripts = '';
if (!$edit) {
    $extraScripts .= <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const buttons = Array.prototype.slice.call(document.querySelectorAll('[data-dest-tab]'));
  const panels = Array.prototype.slice.call(document.querySelectorAll('[data-dest-panel]'));
  if (!buttons.length || !panels.length) return;

  function showTab(id) {
    buttons.forEach(function (btn) {
      const on = btn.getAttribute('data-dest-tab') === id;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      const on = panel.getAttribute('data-dest-panel') === id;
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
      showTab(btn.getAttribute('data-dest-tab'));
    });
  });
});
</script>
HTML;
}
if ($showView) {
    $latJs = isset($edit['latitude']) && $edit['latitude'] !== null && $edit['latitude'] !== '' ? (float) $edit['latitude'] : 'null';
    $lngJs = isset($edit['longitude']) && $edit['longitude'] !== null && $edit['longitude'] !== '' ? (float) $edit['longitude'] : 'null';
    $pinColorJs = json_encode(normalize_pin_color($edit['pin_color'] ?? ''));
    $extraScripts .= map_leaflet_scripts() . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.CulTourMap) return;
  CulTourMap.renderPinPicker('destInfoMap', {
    latInput: null,
    lngInput: null,
    initialLat: {$latJs},
    initialLng: {$lngJs},
    readOnly: true,
    kind: 'destination',
    color: {$pinColorJs}
  });
});
</script>
HTML;
}
if ($showForm) {
    $latJs = isset($edit['latitude']) && $edit['latitude'] !== null && $edit['latitude'] !== '' ? (float) $edit['latitude'] : 'null';
    $lngJs = isset($edit['longitude']) && $edit['longitude'] !== null && $edit['longitude'] !== '' ? (float) $edit['longitude'] : 'null';
    $lockPin = $edit ? 'true' : 'false';
    $extraScripts .= map_leaflet_scripts() . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  CulTourMap.renderPinPicker('destPinMap', {
    latInput: '#destLat',
    lngInput: '#destLng',
    initialLat: {$latJs},
    initialLng: {$lngJs},
    lockUntilEdit: {$lockPin},
    kind: 'destination',
    colorSelect: '#destForm select[name="category_id"]'
  });

  const card = document.getElementById('destPhotosCard');
  if (card) {
    document.body.appendChild(card);
    const dialog = card.querySelector('.admin-biz-proof-card');
    const img = card.querySelector('.admin-biz-photo-stage__main img') || card.querySelector('.admin-biz-proof-card__pic img');
    function sources() {
      return Array.prototype.map.call(card.querySelectorAll('[data-proof-preview]'), function (a) {
        return a.getAttribute('data-proof-preview');
      }).filter(Boolean);
    }
    function showPreview(src) {
      if (!src || !img) return;
      const list = sources();
      let idx = list.indexOf(src);
      if (idx < 0) idx = 0;
      img.src = list[idx] || src;
      const stage = card.querySelector('.admin-biz-photo-stage');
      if (stage) stage.setAttribute('data-photo-index', String(idx));
      card.querySelectorAll('[data-photo-peek]').forEach(function (el) {
        const offset = parseInt(el.getAttribute('data-photo-peek'), 10) || 0;
        const peekImg = el.querySelector('img');
        if (list.length <= offset) {
          el.hidden = true;
          return;
        }
        el.hidden = false;
        if (peekImg) peekImg.src = list[(idx + offset) % list.length];
      });
    }
    function step(delta) {
      const list = sources();
      if (list.length < 2 || !img) return;
      let idx = list.indexOf(img.getAttribute('src'));
      if (idx < 0) idx = 0;
      showPreview(list[(idx + delta + list.length) % list.length]);
    }
    function openCard() {
      card.hidden = false;
      const first = card.querySelector('[data-proof-preview]');
      if (first) showPreview(first.getAttribute('data-proof-preview'));
      document.body.classList.add('admin-biz-proof-open');
    }
    function closeCard() {
      card.hidden = true;
      document.body.classList.remove('admin-biz-proof-open');
    }
    document.querySelectorAll('[data-open-dest-photos-card]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        openCard();
      });
    });
    const prevBtn = card.querySelector('[data-photo-prev]');
    const nextBtn = card.querySelector('[data-photo-next]');
    if (prevBtn) prevBtn.addEventListener('click', function (e) { e.preventDefault(); step(-1); });
    if (nextBtn) nextBtn.addEventListener('click', function (e) { e.preventDefault(); step(1); });
    card.querySelectorAll('[data-photo-peek]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        const peekImg = el.querySelector('img');
        if (peekImg && peekImg.getAttribute('src')) showPreview(peekImg.getAttribute('src'));
      });
    });
    card.addEventListener('click', function (e) {
      if (e.target === card || e.target.closest('[data-close-dest-photos-card]')) closeCard();
    });
    document.addEventListener('keydown', function (e) {
      if (card.hidden) return;
      if (e.key === 'Escape') closeCard();
      if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
    });
  }
});
</script>
HTML;
}
require __DIR__ . '/../includes/footer.php';
?>
