<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/announcements.php';
$user = require_login(ROLE_ADMIN);
$pageTitle = 'Announcements';
$pageHeading = 'Announcements';
$pageSub = 'Banners never notify; announcements can post to the board or notify only';
$activeNav = 'announcements';

ensure_announcements_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = db()->prepare('SELECT banner_image FROM announcements WHERE id=?');
        $row->execute([$id]);
        $old = $row->fetch();
        db()->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);
        if ($old && !empty($old['banner_image'])) {
            $path = UPLOAD_PATH . '/' . $old['banner_image'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        flash('success', 'Deleted.');
        redirect(APP_URL . '/admin/announcements.php');
    }

    if ($action === 'pin') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = db()->prepare("SELECT id, kind, is_pinned FROM announcements WHERE id=? AND kind='card'");
        $row->execute([$id]);
        $ann = $row->fetch();
        if (!$ann) {
            flash('error', 'Announcement not found.');
        } elseif (!empty($ann['is_pinned'])) {
            db()->prepare('UPDATE announcements SET is_pinned=0, pinned_at=NULL WHERE id=?')->execute([$id]);
            flash('success', 'Announcement unpinned.');
        } else {
            db()->prepare('UPDATE announcements SET is_pinned=1, pinned_at=NOW() WHERE id=?')->execute([$id]);
            flash('success', 'Announcement pinned to the top.');
        }
        redirect(APP_URL . '/admin/announcements.php');
    }

    $aud = $_POST['audience'] ?? 'all';
    $allowedAud = ['all', 'tourist', 'tour_guide', 'business', 'user'];
    if (!in_array($aud, $allowedAud, true)) {
        $aud = 'all';
    }
    $targetUserId = null;
    if ($aud === 'user') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $picked = db()->prepare(
            "SELECT id FROM users WHERE id=? AND status='active' AND role_id IN (?,?,?)"
        );
        $picked->execute([$targetUserId, ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS]);
        if (!$picked->fetchColumn()) {
            flash('error', 'Choose the user this announcement is for.');
            redirect(APP_URL . '/admin/announcements.php');
        }
    }
    $kind = ($_POST['kind'] ?? 'header') === 'card' ? 'card' : 'header';

    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $banner = null;
    $isPublished = 1;
    $sendNotify = 0;

    if ($kind === 'header') {
        if (empty($_FILES['banner_image']['name'])) {
            flash('error', 'Please choose a banner image.');
            redirect(APP_URL . '/admin/announcements.php');
        }
        $banner = upload_file($_FILES['banner_image'], 'announcements', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!$banner) {
            flash('error', 'Could not upload that image. Use JPG, PNG, GIF, or WebP.');
            redirect(APP_URL . '/admin/announcements.php');
        }
        $title = $title !== '' ? $title : 'Banner';
        $body = '';
        $isPublished = 1;
        $sendNotify = 0;
    } else {
        if ($title === '' || $body === '') {
            flash('error', 'Title and message are required for announcements.');
            redirect(APP_URL . '/admin/announcements.php');
        }
        // delivery=notify → notify only; otherwise post to dashboards only
        $notifyOnly = ($_POST['delivery'] ?? 'board') === 'notify';
        $isPublished = $notifyOnly ? 0 : 1;
        $sendNotify = $notifyOnly ? 1 : 0;
    }

    db()->prepare(
        'INSERT INTO announcements (title, body, kind, banner_image, audience, target_user_id, is_published, send_notify, published_at, created_by)
         VALUES (?,?,?,?,?,?,?,?,NOW(),?)'
    )->execute([
        $title,
        $body,
        $kind,
        $banner,
        $aud,
        $targetUserId,
        $isPublished,
        $sendNotify,
        $user['id'],
    ]);

    if ($kind === 'card' && $sendNotify) {
        notify_announcement_audience($aud, $title, $body, (int) $targetUserId);
    }

    if ($kind === 'header') {
        flash('success', 'Banner posted (users were not notified).');
    } elseif ($isPublished) {
        flash('success', 'Announcement posted to dashboards (users were not notified).');
    } else {
        flash('success', 'Users notified (not shown on dashboards).');
    }
    redirect(APP_URL . '/admin/announcements.php');
}

$audienceUsers = db()->query(
    'SELECT id, first_name, last_name, email, role_id FROM users
     WHERE status=\'active\' AND role_id IN (' . (int) ROLE_TOURIST . ',' . (int) ROLE_TOUR_GUIDE . ',' . (int) ROLE_BUSINESS . ')
     ORDER BY first_name, last_name, email'
)->fetchAll() ?: [];
$rows = admin_announcements_rows();
$annListHtml = admin_announcements_list_html($rows);
$annListVersion = admin_announcements_version($rows);
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="row g-3">
        <div class="col-lg-5">
          <div class="glass p-3 p-md-4">
            <h3 class="panel-title">Create post</h3>
            <p class="text-muted small mb-3">Banners never notify. For announcements, choose post to dashboards or notification only.</p>
            <form method="post" enctype="multipart/form-data" id="annForm"><?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label">Type</label>
                <div class="d-flex flex-wrap gap-3">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="kind" id="kindCard" value="card" checked>
                    <label class="form-check-label" for="kindCard">Announcement</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="kind" id="kindHeader" value="header">
                    <label class="form-check-label" for="kindHeader">Banner image</label>
                  </div>
                </div>
              </div>

              <div id="textFields">
                <div class="mb-2">
                  <label class="form-label">Title</label>
                  <input class="form-control" name="title" id="annTitle" placeholder="Title">
                </div>
                <div class="mb-3">
                  <label class="form-label">Message</label>
                  <textarea class="form-control" name="body" id="annBody" rows="4" placeholder="Message"></textarea>
                </div>
                <div class="mb-3" id="deliveryField">
                  <label class="form-label">Delivery</label>
                  <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="delivery" id="deliveryBoard" value="board" checked>
                      <label class="form-check-label" for="deliveryBoard">Post to dashboards</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="delivery" id="deliveryNotify" value="notify">
                      <label class="form-check-label" for="deliveryNotify">Notification only</label>
                    </div>
                  </div>
                  <div class="form-text">Notification only alerts users and stays hidden from dashboards.</div>
                </div>
              </div>

              <div class="mb-3" id="bannerField" hidden>
                <label class="form-label">Banner image</label>
                <input type="file" class="form-control" name="banner_image" accept="image/*" id="bannerInput">
                <div class="form-text">Recommended wide image. Text can be part of the image itself. No notifications.</div>
              </div>

              <div class="mb-3">
                <label class="form-label" for="annAudience">Audience</label>
                <select class="form-select" name="audience" id="annAudience">
                  <option value="all">All users</option>
                  <option value="tourist">Tourists</option>
                  <option value="tour_guide">Tour Guides</option>
                  <option value="business">Businesses</option>
                  <option value="user">One user</option>
                </select>
              </div>
              <div class="mb-3" id="annUserField" hidden>
                <label class="form-label" for="annUserFilter">User</label>
                <input type="search" class="form-control" id="annUserFilter" placeholder="Search name or email" autocomplete="off">
                <input type="hidden" name="target_user_id" id="annUserId" value="">
                <div class="ann-user-results" id="annUserResults" hidden></div>
                <div class="form-text text-danger d-none" id="annUserWarn">Choose a user from the search results.</div>
              </div>

              <button class="btn btn-ct w-100">Post</button>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="glass p-3 p-md-4">
            <h3 class="panel-title">All posts</h3>
            <div data-live-page="admin_announcements" data-live-version="<?= e($annListVersion) ?>">
              <?= $annListHtml ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const header = document.getElementById('kindHeader');
  const card = document.getElementById('kindCard');
  const banner = document.getElementById('bannerField');
  const bannerInput = document.getElementById('bannerInput');
  const textFields = document.getElementById('textFields');
  const title = document.getElementById('annTitle');
  const body = document.getElementById('annBody');
  const audience = document.getElementById('annAudience');
  const userField = document.getElementById('annUserField');
  const userFilter = document.getElementById('annUserFilter');
  const userId = document.getElementById('annUserId');
  const userResults = document.getElementById('annUserResults');
  const userWarn = document.getElementById('annUserWarn');
  const users = <?= json_encode(array_map(static function ($au) {
      $roles = [ROLE_TOURIST => 'Tourist', ROLE_TOUR_GUIDE => 'Tour guide', ROLE_BUSINESS => 'Business'];
      return [
          'id' => (int) $au['id'],
          'name' => trim($au['first_name'] . ' ' . $au['last_name']),
          'email' => (string) $au['email'],
          'role' => $roles[(int) $au['role_id']] ?? 'User',
      ];
  }, $audienceUsers), JSON_UNESCAPED_UNICODE) ?>;
  function sync() {
    const isHeader = !!(header && header.checked);
    const oneUser = audience && audience.value === 'user';
    if (banner) banner.hidden = !isHeader;
    if (textFields) textFields.hidden = isHeader;
    if (bannerInput) bannerInput.required = isHeader;
    if (title) title.required = !isHeader;
    if (body) body.required = !isHeader;
    if (userField) userField.hidden = !oneUser;
  }
  function showMatches() {
    if (!userResults || !userFilter || !userId) return;
    const q = userFilter.value.trim().toLowerCase();
    if (userId.value && userFilter.dataset.picked !== userFilter.value) {
      userId.value = '';
    }
    userResults.innerHTML = '';
    if (q === '') {
      userResults.hidden = true;
      return;
    }
    const hits = users.filter(function (u) {
      return (u.name + ' ' + u.email + ' ' + u.role).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 8);
    if (!hits.length) {
      userResults.hidden = false;
      userResults.textContent = 'No matching user.';
      return;
    }
    hits.forEach(function (u) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ann-user-results__item';
      btn.textContent = u.name + ' · ' + u.role + ' · ' + u.email;
      btn.addEventListener('click', function () {
        userId.value = String(u.id);
        userFilter.value = u.name;
        userFilter.dataset.picked = u.name;
        userResults.hidden = true;
        userResults.innerHTML = '';
        if (userWarn) userWarn.classList.add('d-none');
      });
      userResults.appendChild(btn);
    });
    userResults.hidden = false;
  }
  const form = document.getElementById('annForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      const oneUser = audience && audience.value === 'user';
      if (oneUser && userId && !userId.value) {
        e.preventDefault();
        if (userWarn) userWarn.classList.remove('d-none');
        if (userFilter) userFilter.focus();
      }
    });
  }
  if (header) header.addEventListener('change', sync);
  if (card) card.addEventListener('change', sync);
  if (audience) audience.addEventListener('change', sync);
  if (userFilter) userFilter.addEventListener('input', showMatches);
  sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
