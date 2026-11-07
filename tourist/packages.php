<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business_engagement.php';
require_once __DIR__ . '/../includes/payments.php';
$user = require_login(ROLE_TOURIST);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && (string) ($_POST['action'] ?? '') === 'book_package') {
    $booked = package_order_create((int) $user['id'], (int) ($_POST['service_id'] ?? 0), (string) ($_POST['tour_date'] ?? ''));
    if (!$booked['ok'] || empty($booked['checkout_url'])) {
        flash('error', $booked['message'] !== '' ? $booked['message'] : 'Could not start payment.');
        redirect(APP_URL . '/tourist/packages.php');
    }
    redirect($booked['checkout_url']);
}
$pageTitle = 'Tour package';
$pageHeading = 'Tour package';
$pageSub = 'CulTour · Ormoc City';
$activeNav = 'packages';

ensure_business_reviews_schema();
ensure_business_favorites_schema();
ensure_place_schedule_schema();

$q = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT s.id, s.name AS package_name, s.description AS package_description, s.price, s.package_days, s.image_path,
               b.id AS business_id, b.name AS business_name, b.address, b.phone, b.email,
               b.avg_rating, b.review_count,
               bt.name AS type_name, " . business_itinerary_ready_columns() . "
        FROM business_services s
        JOIN businesses b ON b.id = s.business_id
        JOIN business_types bt ON bt.id = b.business_type_id
        WHERE s.is_active = 1 AND b.approval_status = 'approved' AND b.status = 'active'";
$params = [];
if ($q !== '') {
    $sql .= ' AND (s.name LIKE ? OR s.description LIKE ? OR b.name LIKE ? OR b.description LIKE ? OR b.address LIKE ? OR b.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, $like, $like];
}
$sql .= ' ORDER BY b.name ASC, s.name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$packages = array_values(array_filter($stmt->fetchAll() ?: [], static function (array $row): bool {
    return business_itinerary_ready($row)
        && business_catalog_mode($row['type_name'] ?? '') === 'packages';
}));
$packageGroups = [];
foreach ($packages as $row) {
    $bizId = (int) ($row['business_id'] ?? 0);
    if (!isset($packageGroups[$bizId])) {
        $packageGroups[$bizId] = [
            'name' => trim((string) ($row['business_name'] ?? '')) ?: 'Tour and Travel',
            'items' => [],
        ];
    }
    $packageGroups[$bizId]['items'][] = $row;
}

$favStmt = db()->prepare('SELECT business_id FROM business_favorites WHERE user_id=?');
$favStmt->execute([(int) $user['id']]);
$favBizIds = array_flip(array_map('intval', $favStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';

function tourist_package_cover(array $row): string
{
    foreach (['image_path', 'cover_image', 'logo'] as $key) {
        $path = trim((string) ($row[$key] ?? ''));
        if ($path !== '') {
            return APP_URL . '/uploads/' . ltrim($path, '/');
        }
    }
    return '';
}

function tourist_package_card(array $row, bool $active = false): void
{
    global $favBizIds;
    $bizId = (int) ($row['business_id'] ?? 0);
    $businessName = trim((string) ($row['business_name'] ?? ''));
    $packageName = trim((string) ($row['package_name'] ?? ''));
    $packageDesc = trim((string) ($row['package_description'] ?? ''));
    $serviceId = (int) ($row['id'] ?? 0);
    $packageDays = (int) ($row['package_days'] ?? 0);
    $coverUrl = tourist_package_cover($row);
    $cover = $coverUrl !== '' ? 'background-image:url(' . e($coverUrl) . ')' : '';
    $isFav = isset($favBizIds[$bizId]);
    $aria = $packageName !== '' ? $packageName : 'Package';
    if ($businessName !== '') {
        $aria .= ' from ' . $businessName;
    }
    ?>
    <article class="ct-rail__card<?= $active ? ' is-active' : '' ?>"
             data-package-view
             data-package-id="<?= $serviceId ?>"
             data-package-name="<?= e($packageName !== '' ? $packageName : 'Package') ?>"
             data-package-desc="<?= e($packageDesc) ?>"
             data-package-photo="<?= e($coverUrl) ?>"
             data-package-price="<?= e(format_money($row['price'] ?? 0)) ?>"
             data-package-days="<?= $packageDays ?>"
             tabindex="0"
             role="button"
             aria-label="<?= e($aria) ?>">
      <div class="ct-rail__poster" style="<?= $cover ?>">
        <div class="ct-rail__shade"></div>
        <div class="ct-rail__fav-wrap">
          <button type="button" class="ct-rail__fav<?= $isFav ? ' is-on' : '' ?>"
                  data-fav-btn data-fav-type="business" data-fav-id="<?= $bizId ?>"
                  aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                  aria-label="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>">
            <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
          </button>
          <p class="ct-rail__fav-note" data-fav-note></p>
        </div>
        <div class="ct-rail__meta">
          <div class="ct-rail__kicker">Package</div>
          <div class="ct-rail__title"><?= e($packageName !== '' ? $packageName : 'Package') ?></div>
          <div class="ct-rail__stats">
            <span><?= e(format_money($row['price'] ?? 0)) ?></span>
          </div>
          <span class="ct-rail__cta">View details <i class="bi bi-arrow-right"></i></span>
        </div>
      </div>
    </article>
    <?php
}
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>
    <div class="page-content page-content--rails">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <form class="ct-rail-filters glass p-3 mb-4" method="get">
        <div class="row g-2 align-items-center">
          <div class="col-md-10">
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search packages, businesses, or places…">
          </div>
          <div class="col-md-2">
            <button class="btn btn-ct w-100" type="submit">Search</button>
          </div>
        </div>
      </form>

      <?php if (!$packages): ?>
        <div class="glass p-4 text-muted">No tour packages yet. Packages appear here after a tour and travel business uploads them.</div>
      <?php else: ?>
        <?php foreach ($packageGroups as $bizId => $group): ?>
          <section class="ct-rail ct-rail--paper fade-up" data-focus-rail aria-label="<?= e($group['name']) ?>">
            <div class="ct-rail__head">
              <h2 class="ct-rail__heading"><?= e($group['name']) ?></h2>
            </div>
            <div class="ct-rail__viewport">
              <button type="button" class="ct-rail__arrow ct-rail__arrow--prev" aria-label="Previous package">
                <i class="bi bi-chevron-left"></i>
              </button>
              <div class="ct-rail__track" id="rail-packages-<?= (int) $bizId ?>">
                <?php foreach ($group['items'] as $i => $row): ?>
                  <?php tourist_package_card($row, $i === 0); ?>
                <?php endforeach; ?>
              </div>
              <button type="button" class="ct-rail__arrow ct-rail__arrow--next" aria-label="Next package">
                <i class="bi bi-chevron-right"></i>
              </button>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
    <div class="modal fade package-view-modal" id="packageViewModal" tabindex="-1" aria-labelledby="packageViewLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content package-view-card">
          <div class="package-view-card__media" id="packageViewMedia"></div>
          <div class="package-view-card__panel">
            <div class="modal-header border-0">
              <h2 class="modal-title h4 mb-0" id="packageViewLabel">Package</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0 d-flex flex-column">
              <p class="package-view-card__desc mb-0" id="packageViewDesc"></p>
              <div class="package-view-card__price" id="packageViewPrice"></div>
              <p class="package-view-card__days" id="packageViewDays"></p>
              <form method="post" action="<?= APP_URL ?>/tourist/packages.php" class="package-view-card__book">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="book_package">
                <input type="hidden" name="service_id" id="packageBookService" value="">
                <label class="form-label" for="packageTourDate">Tour date</label>
                <input type="date" class="form-control" name="tour_date" id="packageTourDate" required min="<?= e(app_today()) ?>">
                <p class="package-view-card__span" id="packageTourSpan"></p>
                <button class="btn btn-ct w-100" type="submit" id="packageBookBtn">Book</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
<?php
$extraScripts = '<script src="' . APP_URL . '/assets/js/focus-rail.js?v='
    . (@filemtime(ROOT_PATH . '/assets/js/focus-rail.js') ?: time()) . '"></script>'
    . '<script>window.CulTourFav={csrf:' . json_encode(csrf_token())
    . ',url:' . json_encode(APP_URL . '/api/favorites/toggle_web.php') . '};</script>'
    . '<script src="' . APP_URL . '/assets/js/fav-heart.js?v='
    . (@filemtime(ROOT_PATH . '/assets/js/fav-heart.js') ?: time()) . '"></script>'
    . '<script>
      document.addEventListener("ct-package-view", function (event) {
        var card = event.detail && event.detail.card;
        var modalEl = document.getElementById("packageViewModal");
        if (!card || !modalEl || typeof bootstrap === "undefined") return;
        var name = card.getAttribute("data-package-name") || "Package";
        var desc = card.getAttribute("data-package-desc") || "";
        var photo = card.getAttribute("data-package-photo") || "";
        var price = card.getAttribute("data-package-price") || "";
        var serviceId = card.getAttribute("data-package-id") || "";
        var days = parseInt(card.getAttribute("data-package-days") || "0", 10);
        if (!days || days < 1) days = 0;
        var title = document.getElementById("packageViewLabel");
        var body = document.getElementById("packageViewDesc");
        var media = document.getElementById("packageViewMedia");
        var priceEl = document.getElementById("packageViewPrice");
        var daysEl = document.getElementById("packageViewDays");
        var serviceInput = document.getElementById("packageBookService");
        var bookBtn = document.getElementById("packageBookBtn");
        var dateInput = document.getElementById("packageTourDate");
        var spanEl = document.getElementById("packageTourSpan");
        if (title) title.textContent = name;
        if (body) body.textContent = desc !== "" ? desc : "No description for this package yet.";
        if (priceEl) priceEl.textContent = price;
        if (daysEl) daysEl.textContent = days > 0 ? (days === 1 ? "1 day" : days + " days") : "This package does not have a length yet.";
        if (serviceInput) serviceInput.value = serviceId;
        if (dateInput) {
          dateInput.value = "";
          dateInput.disabled = days < 1;
        }
        if (spanEl) spanEl.textContent = "";
        if (bookBtn) bookBtn.disabled = serviceId === "" || days < 1;
        function showSpan() {
          if (!spanEl || !dateInput || !dateInput.value || days < 1) {
            if (spanEl) spanEl.textContent = "";
            return;
          }
          var start = new Date(dateInput.value + "T00:00:00");
          if (isNaN(start.getTime())) {
            spanEl.textContent = "";
            return;
          }
          var fmt = function (d) {
            return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
          };
          if (days === 1) {
            spanEl.textContent = fmt(start);
            return;
          }
          var end = new Date(start.getTime());
          end.setDate(end.getDate() + days - 1);
          spanEl.textContent = fmt(start) + " – " + fmt(end);
        }
        if (dateInput) {
          dateInput.oninput = showSpan;
          dateInput.onchange = showSpan;
        }
        if (media) {
          media.replaceChildren();
          if (photo !== "") {
            var img = document.createElement("img");
            img.className = "package-view-card__photo";
            img.alt = "";
            img.src = photo;
            media.appendChild(img);
          }
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      });
    </script>';
require __DIR__ . '/../includes/footer.php';
