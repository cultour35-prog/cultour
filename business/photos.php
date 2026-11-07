<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_BUSINESS);
$pageTitle = 'Photos';
$pageHeading = 'Business Photos';
$pageSub = 'Manage gallery photos and your cover image';
$activeNav = 'photos';

$biz = db()->prepare('SELECT * FROM businesses WHERE user_id=? LIMIT 1');
$biz->execute([$user['id']]);
$biz = $biz->fetch();
if ($biz && !business_listing_is_approved($biz)) {
    flash('error', 'Photos can be added after the Tourism Office approves your business.');
    redirect(APP_URL . '/business/dashboard.php');
}

if ($biz && $_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? 'upload';

    if ($action === 'delete') {
        $photoId = (int) ($_POST['id'] ?? 0);
        $p = db()->prepare('SELECT * FROM business_photos WHERE id=? AND business_id=?');
        $p->execute([$photoId, $biz['id']]);
        $photo = $p->fetch();
        if ($photo) {
            db()->prepare('DELETE FROM business_photos WHERE id=? AND business_id=?')->execute([$photoId, $biz['id']]);
            // If deleted photo was the cover, clear cover
            if (!empty($biz['cover_image']) && $biz['cover_image'] === $photo['image_path']) {
                db()->prepare('UPDATE businesses SET cover_image=NULL WHERE id=?')->execute([$biz['id']]);
            }
            flash('success', 'Photo removed.');
        }
    } elseif ($action === 'set_cover') {
        $photoId = (int) ($_POST['id'] ?? 0);
        $p = db()->prepare('SELECT image_path FROM business_photos WHERE id=? AND business_id=?');
        $p->execute([$photoId, $biz['id']]);
        $path = $p->fetchColumn();
        if ($path) {
            db()->prepare('UPDATE businesses SET cover_image=? WHERE id=?')->execute([$path, $biz['id']]);
            flash('success', 'Cover photo updated.');
        } else {
            flash('error', 'Photo not found.');
        }
    } elseif ($action === 'upload_cover') {
        $path = upload_file($_FILES['cover_image'] ?? [], 'businesses');
        if ($path) {
            db()->prepare('UPDATE businesses SET cover_image=? WHERE id=?')->execute([$path, $biz['id']]);
            // Also keep it in the gallery
            db()->prepare('INSERT INTO business_photos (business_id, image_path, caption) VALUES (?,?,?)')
                ->execute([$biz['id'], $path, 'Cover photo']);
            flash('success', 'Cover photo uploaded.');
        } else {
            flash('error', 'Cover upload failed. Use a valid image file.');
        }
    } else {
        $path = upload_file($_FILES['photo'] ?? [], 'businesses');
        if ($path) {
            db()->prepare('INSERT INTO business_photos (business_id, image_path, caption) VALUES (?,?,?)')
                ->execute([$biz['id'], $path, trim($_POST['caption'] ?? '')]);
            if (!empty($_POST['use_as_cover'])) {
                db()->prepare('UPDATE businesses SET cover_image=? WHERE id=?')->execute([$path, $biz['id']]);
                flash('success', 'Photo uploaded and set as cover.');
            } else {
                flash('success', 'Photo uploaded.');
            }
        } else {
            flash('error', 'Upload failed. Use a valid image file.');
        }
    }
    redirect(posted_return_to(APP_URL . '/business/photos.php'));
}

$rows = [];
$cover = null;
if ($biz) {
    $cover = $biz['cover_image'] ?? null;
    $s = db()->prepare('SELECT * FROM business_photos WHERE business_id=? ORDER BY created_at DESC');
    $s->execute([$biz['id']]);
    $rows = $s->fetchAll();
}

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php if (!$biz): ?>
        <div class="alert alert-warning">No business profile found.</div>
      <?php else: ?>

      <div class="row g-3 mb-4">
        <div class="col-lg-5">
          <div class="glass p-3 h-100" data-avatar-preview>
            <h3 class="panel-title">Cover photo</h3>
            <p class="small text-muted mb-3">Shown on your business page and tourist listings.</p>
            <div class="rounded overflow-hidden border mb-3" data-avatar-frame style="height:200px;background:linear-gradient(135deg,#0b5f6b,#c4a35a);<?= $cover ? 'background-image:url(' . APP_URL . '/uploads/' . e($cover) . ');background-size:cover;background-position:center' : '' ?>"></div>
            <?php if (!$cover): ?>
              <div class="small text-muted mb-3">No cover photo yet.</div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" data-unsaved-guard>
              <?= csrf_field() ?>
              <input type="hidden" name="return_to" value="">
              <input type="hidden" name="action" value="upload_cover">
              <div class="mb-2">
                <input type="file" class="form-control" name="cover_image" data-avatar-input accept="image/*" required>
              </div>
              <button class="btn btn-ct">Upload new cover</button>
            </form>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="glass p-3 h-100">
            <h3 class="panel-title">Add gallery photo</h3>
            <form method="post" enctype="multipart/form-data" data-unsaved-guard>
              <?= csrf_field() ?>
              <input type="hidden" name="return_to" value="">
              <input type="hidden" name="action" value="upload">
              <div class="mb-2"><input type="file" class="form-control" name="photo" accept="image/*" required></div>
              <div class="mb-2"><input class="form-control" name="caption" placeholder="Caption (optional)"></div>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="use_as_cover" value="1" id="useAsCover">
                <label class="form-check-label" for="useAsCover">Also set as cover photo</label>
              </div>
              <button class="btn btn-ct">Upload</button>
            </form>
          </div>
        </div>
      </div>

      <div class="glass p-3">
        <h3 class="panel-title">Gallery</h3>
        <?php if (!$rows): ?>
          <p class="text-muted mb-0">No photos yet. Upload one above.</p>
        <?php else: ?>
          <div class="row g-3">
            <?php foreach ($rows as $r):
              $isCover = $cover && $cover === $r['image_path'];
            ?>
              <div class="col-6 col-md-4 col-lg-3">
                <div class="glass overflow-hidden h-100">
                  <div class="position-relative">
                    <img src="<?= APP_URL ?>/uploads/<?= e($r['image_path']) ?>" class="w-100" style="height:160px;object-fit:cover" alt="">
                    <?php if ($isCover): ?>
                      <span class="badge text-bg-success position-absolute top-0 start-0 m-2">Cover</span>
                    <?php endif; ?>
                  </div>
                  <div class="p-2">
                    <div class="small text-muted mb-2" style="min-height:1.25rem"><?= e($r['caption'] ?? '') ?></div>
                    <div class="d-flex flex-wrap gap-1">
                      <?php if (!$isCover): ?>
                        <form method="post" class="flex-grow-1" data-unsaved-ignore>
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="set_cover">
                          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary w-100">Set as cover</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" data-unsaved-ignore onsubmit="return confirm('Delete this photo?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
if ($biz) {
    render_unsaved_changes_modal();
}
require __DIR__ . '/../includes/footer.php';
