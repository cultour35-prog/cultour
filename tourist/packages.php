<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business_engagement.php';
require_once __DIR__ . '/../includes/payments.php';
$user = require_login(ROLE_TOURIST);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && (string) ($_POST['action'] ?? '') === 'book_package') {
    $partySize = (int) ($_POST['party_size'] ?? 0);
    $partyNotes = package_party_notes_from_post($partySize);
    if (!$partyNotes['ok']) {
        flash('error', $partyNotes['message']);
        redirect(APP_URL . '/tourist/packages.php');
    }
    $booked = package_order_create((int) $user['id'], (int) ($_POST['service_id'] ?? 0), (string) ($_POST['tour_date'] ?? ''), $partySize, $partyNotes['json']);
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
package_stops_ensure_schema();
$sql = "SELECT s.id, s.name AS package_name, s.description AS package_description, s.price, s.package_days, s.capacity, s.package_min_pax, s.image_path, s.package_inclusions,
               s.package_date_mode, s.package_fixed_date, s.package_close_date, s.package_itinerary_id,
               b.id AS business_id, b.name AS business_name, b.address, b.phone, b.email,
               b.avg_rating, b.review_count,
               bt.name AS type_name, " . business_itinerary_ready_columns() . "
        FROM business_services s
        JOIN businesses b ON b.id = s.business_id
        JOIN business_types bt ON bt.id = b.business_type_id
        WHERE s.is_active = 1 AND s.package_made = 1 AND b.approval_status = 'approved' AND b.status = 'active'";
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
$sort = (string) ($_GET['sort'] ?? 'name');
$sortOptions = [
    'name' => 'Name A–Z',
    'name_desc' => 'Name Z–A',
    'price' => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'days' => 'Shortest trip',
    'business' => 'Business name',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'name';
}
usort($packages, static function (array $a, array $b) use ($sort): int {
    $name = static fn (array $row): string => (string) ($row['package_name'] ?? '');
    $biz = static fn (array $row): string => (string) ($row['business_name'] ?? '');
    return match ($sort) {
        'name_desc' => strcasecmp($name($b), $name($a)),
        'price' => ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0)),
        'price_desc' => ((float) ($b['price'] ?? 0)) <=> ((float) ($a['price'] ?? 0)),
        'days' => ((int) ($a['package_days'] ?? 0)) <=> ((int) ($b['package_days'] ?? 0)),
        'business' => strcasecmp($biz($a), $biz($b)) ?: strcasecmp($name($a), $name($b)),
        default => strcasecmp($name($a), $name($b)),
    };
});

$GLOBALS['packageSlotsTaken'] = $packages ? package_slots_taken_map(array_map(static fn (array $row): int => (int) $row['id'], $packages)) : [];
$packagePlans = [];
if ($packages) {
    package_stops_ensure_schema();
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $packages);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $planStmt = db()->prepare(
        "SELECT ps.service_id, ps.day_number, ps.visit_minutes, COALESCE(d.name, b.name, 'Place') AS place_name
         FROM package_stops ps
         LEFT JOIN destinations d ON d.id = ps.destination_id
         LEFT JOIN businesses b ON b.id = ps.business_id
         WHERE ps.service_id IN ($placeholders)
         ORDER BY ps.day_number ASC, ps.sort_order ASC, ps.id ASC"
    );
    $planStmt->execute($ids);
    foreach ($planStmt->fetchAll() ?: [] as $stop) {
        $packagePlans[(int) $stop['service_id']][] = [
            'day' => (int) $stop['day_number'],
            'name' => (string) $stop['place_name'],
            'minutes' => (int) $stop['visit_minutes'],
        ];
    }
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

function tourist_package_card(array $row): void
{
    global $favBizIds;
    $bizId = (int) ($row['business_id'] ?? 0);
    $businessName = trim((string) ($row['business_name'] ?? ''));
    $packageName = trim((string) ($row['package_name'] ?? ''));
    $packageDesc = trim((string) ($row['package_description'] ?? ''));
    $serviceId = (int) ($row['id'] ?? 0);
    $packageDays = (int) ($row['package_days'] ?? 0);
    $packagePax = (int) ($row['capacity'] ?? 0);
    $packageMinPax = (int) ($row['package_min_pax'] ?? 0);
    $coverUrl = tourist_package_cover($row);
    $isFav = isset($favBizIds[$bizId]);
    $plan = $GLOBALS['packagePlans'][$serviceId] ?? [];
    $dateMode = (string) ($row['package_date_mode'] ?? 'tourist') === 'fixed' ? 'fixed' : 'tourist';
    $packageInclusive = $dateMode === 'fixed' && trim((string) ($row['package_close_date'] ?? '')) !== '';
    $packageSlotsLeft = max(0, $packagePax - (int) ($GLOBALS['packageSlotsTaken'][(int) ($row['id'] ?? 0)] ?? 0));
    $aria = $packageName !== '' ? $packageName : 'Package';
    if ($businessName !== '') {
        $aria .= ' from ' . $businessName;
    }
    ?>
    <article class="offer-manage-card glass pkg-card"
             data-package-view
             data-package-id="<?= $serviceId ?>"
             data-package-name="<?= e($packageName !== '' ? $packageName : 'Package') ?>"
             data-package-desc="<?= e($packageDesc) ?>"
             data-package-inclusions="<?= e((string) ($row['package_inclusions'] ?? '')) ?>"
             data-package-photo="<?= e($coverUrl) ?>"
             data-package-price="<?= e(format_money($row['price'] ?? 0)) ?>"
             data-package-amount="<?= e((string) round((float) ($row['price'] ?? 0), 2)) ?>"
             data-package-days="<?= $packageDays ?>"
             data-package-pax="<?= $packagePax ?>"
             data-package-min-pax="<?= $packageMinPax ?>"
             data-package-date-mode="<?= e((string) ($row['package_date_mode'] ?? 'tourist') === 'fixed' ? 'fixed' : 'tourist') ?>"
             data-package-fixed-date="<?= e((string) ($row['package_fixed_date'] ?? '')) ?>"
             data-package-plan="<?= e(json_encode($plan, JSON_UNESCAPED_UNICODE)) ?>"
             data-package-itinerary="<?= (int) ($row['package_itinerary_id'] ?? 0) ?>"
             <?= $packageInclusive ? 'data-package-slots="' . (int) $packageSlotsLeft . '"' : '' ?>
             tabindex="0"
             data-package-offer="<?= $packageInclusive ? 'inclusive' : 'exclusive' ?>"
             role="button"
             aria-label="<?= e($aria) ?>">
      <div class="pkg-card__media">
        <?php if ($coverUrl !== ''): ?>
          <img class="offer-manage-card__photo" src="<?= e($coverUrl) ?>" alt="">
        <?php else: ?>
          <div class="offer-manage-card__photo pkg-card__empty">No photo yet</div>
        <?php endif; ?>
        <span class="pkg-card__offer pkg-card__offer--<?= $packageInclusive ? 'inclusive' : 'exclusive' ?>"><?= $packageInclusive ? 'Inclusive' : 'Exclusive' ?></span>
        <button type="button" class="pkg-card__fav<?= $isFav ? ' is-on' : '' ?>"
                data-fav-btn data-fav-type="business" data-fav-id="<?= $bizId ?>"
                aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                aria-label="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>">
          <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
        </button>
      </div>
      <div class="offer-manage-card__body">
        <h3 class="h6 mb-1 fw-bold"><?= e($packageName !== '' ? $packageName : 'Package') ?><?php if ($packageInclusive): ?> <span class="fw-normal">· <?= (int) $packageSlotsLeft ?> slot<?= $packageSlotsLeft === 1 ? '' : 's' ?> left</span><?php endif; ?></h3>
        <div class="small mb-1">Price per person · <strong><?= e(format_money($row['price'] ?? 0)) ?></strong></div>
        <?php
          $closeDate = trim((string) ($row['package_close_date'] ?? ''));
          $fixedLabel = $dateMode === 'fixed' && !empty($row['package_fixed_date'])
              ? 'Tour date · ' . format_date((string) $row['package_fixed_date'])
              : 'You choose the date';
        ?>
        <div class="small text-muted mb-1"><?= e($businessName !== '' ? $businessName : 'Tour and Travel') ?><?= $packageDays > 0 ? ' · ' . e(package_days_label($packageDays)) : '' ?></div>
        <?php if (!$packageInclusive && $packagePax > 0): ?>
        <div class="small text-muted mb-1"><?= e(package_pax_label($packagePax, $packageMinPax)) ?></div>
        <?php endif; ?>
        <div class="small text-muted mb-1"><?= e($fixedLabel) ?></div>
        <?php if ($packageInclusive && $closeDate !== ''): ?>
        <div class="small text-muted mb-1">Cut off · <?= e(format_date($closeDate)) ?></div>
        <?php endif; ?>
        <div class="offer-manage-card__actions">
          <button type="button" class="btn btn-sm btn-outline-secondary">View</button>
          <p class="ct-rail__fav-note" data-fav-note></p>
        </div>
      </div>
    </article>
    <?php
}
?>
<?php
$sortSelect = '<select class="booking-list-sort" id="packageSort" name="sort" aria-label="Sort packages" onchange="this.form.submit()">';
foreach ($sortOptions as $value => $label) {
    $sortSelect .= '<option value="' . e($value) . '"' . ($sort === $value ? ' selected' : '') . '>' . e($label) . '</option>';
}
$sortSelect .= '</select>';
$pageHeadingTools = '<form class="booking-list-tools" method="get" id="packageSearchForm">'
    . '<label class="guide-bookings-search">'
    . '<i class="bi bi-search" aria-hidden="true"></i>'
    . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search packages" aria-label="Search packages" autocomplete="off">'
    . '</label>'
    . $sortSelect
    . '</form>';
require __DIR__ . '/../includes/tourist_shell_start.php';
?>
    <div class="page-content page-content--rails">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <script>
      (function () {
        var form = document.getElementById('packageSearchForm');
        var input = form ? form.querySelector('input[name="q"]') : null;
        if (!form || !input) return;
        var timer = null;
        input.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () { form.submit(); }, 350);
        });
      })();
      </script>
      <?php if ($packages): ?>
        <div class="pkg-offer-tabs" role="tablist" aria-label="Package type">
          <button type="button" class="pkg-offer-tabs__btn is-on" data-offer-tab="all">All</button>
          <button type="button" class="pkg-offer-tabs__btn" data-offer-tab="inclusive">Inclusive</button>
          <button type="button" class="pkg-offer-tabs__btn" data-offer-tab="exclusive">Exclusive</button>
        </div>
      <?php endif; ?>

      <?php if (!$packages): ?>
        <div class="glass p-4 text-muted">No tour packages yet. Packages appear here after a tour and travel business uploads them.</div>
      <?php else: ?>
        <div class="pkg-grid offer-manage-grid offer-manage-grid--paper">
          <?php foreach ($packages as $row): ?>
            <?php tourist_package_card($row); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
    <div class="modal fade package-view-modal" id="packageViewModal" tabindex="-1" aria-labelledby="packageViewLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content package-view-card">
          <div class="package-view-card__media" id="packageViewMedia"></div>
          <div class="package-view-card__panel">
            <div class="modal-header border-0">
              <div>
                <h2 class="modal-title h4 mb-0" id="packageViewLabel">Package</h2>
                <p class="package-view-card__length mb-0" id="packageViewLength"></p>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0 d-flex flex-column">
              <div class="package-view-card__desc mb-0" id="packageViewDesc"></div>
              <p class="package-view-card__days" id="packageViewDays"></p>
              <div id="packageViewPlan"></div>
              <form method="post" action="<?= APP_URL ?>/tourist/packages.php" class="package-view-card__book">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="book_package">
                <input type="hidden" name="service_id" id="packageBookService" value="">
                <input type="hidden" name="package_adults" id="packageAdultsField" value="">
                <input type="hidden" name="package_children" id="packageChildrenField" value="">
                <input type="hidden" name="package_infants" id="packageInfantsField" value="0">
                <input type="hidden" name="package_special" id="packageSpecialField" value="">
                <input type="hidden" name="package_party_needs" id="packageNeedsField" value="">
                <label class="form-label" for="packageTourDate" id="packageTourDateLabel">Tour date</label>
                <input type="date" class="form-control" name="tour_date" id="packageTourDate" required min="<?= e(package_earliest_tour_date()) ?>">
                <p class="small text-muted mb-2" id="packageTourDateHint">The earliest start is <?= e(format_date(package_earliest_tour_date())) ?>.</p>
                <p class="small mb-2 d-none" id="packageTourDateSet"></p>
                <p class="package-view-card__span" id="packageTourSpan"></p>
                <label class="form-label" for="packagePartySize">Party size</label>
                <input type="number" class="form-control" name="party_size" id="packagePartySize" required min="1" inputmode="numeric">
                <p class="small text-muted mb-2" id="packagePartyHint"></p>
                <div class="package-view-card__price" id="packageViewPrice"></div>
                <button class="btn btn-ct w-100" type="button" id="packageBookBtn">Proceed</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="packageGuestModal" tabindex="-1" aria-labelledby="packageGuestLabel" data-bs-backdrop="false" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h2 class="modal-title h5 mb-0" id="packageGuestLabel">1 guest joining</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body pt-0">
            <p class="mb-2" id="packageInfantQuestion">1. Is your group traveling with an infant/toddler (3 months to 3 years old)?</p>
            <div class="pkg-choice mb-3">
              <label><input type="radio" name="packageInfantChoice" value="yes"> Yes</label>
              <label><input type="radio" name="packageInfantChoice" value="no"> No</label>
            </div>
            <div class="row g-2 mb-3">
              <div class="col" id="packageAdultsCol">
                <label class="form-label" for="packageAdults">Adults</label>
                <input type="number" min="0" class="form-control" id="packageAdults" inputmode="numeric">
              </div>
              <div class="col" id="packageChildrenCol">
                <label class="form-label" for="packageChildren">Children</label>
                <input type="number" min="0" class="form-control" id="packageChildren" inputmode="numeric">
              </div>
              <div class="col d-none" id="packageInfantsCol">
                <label class="form-label" for="packageInfants">Infant/toddler</label>
                <input type="number" min="1" class="form-control" id="packageInfants" inputmode="numeric">
              </div>
            </div>
            <p class="mb-2" id="packageSpecialQuestion">2. Does anyone in your group have special requirements?</p>
            <div class="pkg-choice">
              <label><input type="radio" name="packageSpecialChoice" value="yes"> Yes</label>
              <label><input type="radio" name="packageSpecialChoice" value="no"> No</label>
            </div>
            <p class="small text-danger mt-2 mb-0" id="packageGuestError" hidden></p>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-ct w-100" id="packageGuestBook">Proceed</button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="packageNeedsModal" tabindex="-1" aria-labelledby="packageNeedsLabel" data-bs-backdrop="false" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h2 class="modal-title h5 mb-0" id="packageNeedsLabel">Special requirements</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body pt-0">
            <?php
            $packageNeedGroups = [
                'Accessibility & Assistance Needs' => [
                    ['Wheelchair assistance', false],
                    ['Difficulty walking or standing for long periods', false],
                    ['Visual impairment assistance', false],
                    ['Hearing impairment assistance', false],
                    ['Senior citizen assistance', false],
                    ['Pregnant tourist', false],
                    ['Assistance with stairs or uneven terrain', false],
                    ['Other accessibility requirements', true],
                ],
                'Dietary Requirements & Food Preferences' => [
                    ['No pork', false],
                    ['No beef', false],
                    ['Vegetarian', false],
                    ['Vegan', false],
                    ['Seafood allergy', false],
                    ['Peanut allergy', false],
                    ['Dairy-free', false],
                    ['Gluten-free', false],
                    ['Other food allergies or dietary restrictions', true],
                ],
                'Medical & Health Considerations' => [
                    ['Motion sickness', false],
                    ['Easily fatigued', false],
                    ['Requires regular medication breaks', false],
                    ['Requires frequent restroom access', false],
                    ['Other health-related travel considerations', true],
                ],
            ];
            $needIndex = 0;
            foreach ($packageNeedGroups as $groupTitle => $groupItems):
            ?>
              <h3 class="h6 mt-3 mb-2"><?= e($groupTitle) ?></h3>
              <?php foreach ($groupItems as $item): $needIndex++; ?>
                <div class="pkg-need">
                  <label>
                    <input type="checkbox" data-need-check value="<?= e($item[0]) ?>"<?= $item[1] ? ' data-need-other="1"' : '' ?>>
                    <span><?= e($item[0]) ?></span>
                  </label>
                  <input type="number" min="1" class="form-control form-control-sm pkg-need__count" placeholder="How many" hidden>
                  <?php if ($item[1]): ?>
                    <input type="text" class="form-control form-control-sm pkg-need__detail" maxlength="160" placeholder="What is it?" hidden>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
            <h3 class="h6 mt-3 mb-2">Other special requests</h3>
            <textarea class="form-control" id="packageOtherRequest" rows="3" maxlength="300" placeholder="Write any other request"></textarea>
            <p class="small text-danger mt-2 mb-0" id="packageNeedsError" hidden></p>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-ct w-100" id="packageNeedsBook">Proceed</button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="packageSummaryModal" tabindex="-1" aria-labelledby="packageSummaryLabel" data-bs-backdrop="false" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <div class="itin-book-review-heading">
              <h2 class="modal-title h5 mb-0" id="packageSummaryLabel">Booking summary</h2>
              <a class="itin-book-review-view" id="packageSummaryItinerary" href="#" target="_blank" rel="noopener" hidden>View package itinerary</a>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body pt-0" id="packageSummaryBody"></div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-ct w-100" id="packageSummaryPay">Payment</button>
          </div>
        </div>
      </div>
    </div>
<?php
$extraScripts = '<script>window.CulTourFav={csrf:' . json_encode(csrf_token())
    . ',url:' . json_encode(APP_URL . '/api/favorites/toggle_web.php') . '};</script>'
    . '<script src="' . APP_URL . '/assets/js/fav-heart.js?v='
    . (@filemtime(ROOT_PATH . '/assets/js/fav-heart.js') ?: time()) . '"></script>'
    . '<script>
      document.querySelectorAll("[data-offer-tab]").forEach(function (tab) {
        tab.addEventListener("click", function () {
          document.querySelectorAll("[data-offer-tab]").forEach(function (btn) {
            btn.classList.toggle("is-on", btn === tab);
          });
          var offer = tab.getAttribute("data-offer-tab") || "all";
          document.querySelectorAll("[data-package-offer]").forEach(function (card) {
            card.hidden = offer !== "all" && card.getAttribute("data-package-offer") !== offer;
          });
        });
      });
      document.addEventListener("click", function (e) {
        if (e.target.closest("[data-fav-btn]")) return;
        var card = e.target.closest("[data-package-view]");
        if (!card) return;
        document.dispatchEvent(new CustomEvent("ct-package-view", { detail: { card: card } }));
      });
      document.addEventListener("keydown", function (e) {
        var card = e.target.closest && e.target.closest("[data-package-view]");
        if (!card || (e.key !== "Enter" && e.key !== " ")) return;
        e.preventDefault();
        card.click();
      });
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
        var pax = parseInt(card.getAttribute("data-package-pax") || "0", 10);
        if (!pax || pax < 1) pax = 0;
        var minPax = parseInt(card.getAttribute("data-package-min-pax") || "0", 10);
        if (!minPax || minPax < 1) minPax = 0;
        var title = document.getElementById("packageViewLabel");
        var lengthEl = document.getElementById("packageViewLength");
        var body = document.getElementById("packageViewDesc");
        var media = document.getElementById("packageViewMedia");
        var priceEl = document.getElementById("packageViewPrice");
        var daysEl = document.getElementById("packageViewDays");
        var planEl = document.getElementById("packageViewPlan");
        var serviceInput = document.getElementById("packageBookService");
        var bookBtn = document.getElementById("packageBookBtn");
        var dateInput = document.getElementById("packageTourDate");
        var dateLabel = document.getElementById("packageTourDateLabel");
        var dateHint = document.getElementById("packageTourDateHint");
        var dateSet = document.getElementById("packageTourDateSet");
        var partyInput = document.getElementById("packagePartySize");
        var partyHint = document.getElementById("packagePartyHint");
        var unitPrice = parseFloat(card.getAttribute("data-package-amount") || "0");
        if (!isFinite(unitPrice) || unitPrice < 0) unitPrice = 0;
        var spanEl = document.getElementById("packageTourSpan");
        var dateMode = card.getAttribute("data-package-date-mode") || "tourist";
        var fixedDate = card.getAttribute("data-package-fixed-date") || "";
        var guestModalEl = document.getElementById("packageGuestModal");
        if (guestModalEl) guestModalEl.dataset.exclusive = card.hasAttribute("data-package-slots") ? "0" : "1";
        if (title) title.textContent = name;
        var inclusions = card.getAttribute("data-package-inclusions") || "";
        if (body) {
          body.replaceChildren();
          var descriptionBlock = document.createElement("div");
          descriptionBlock.className = "package-view-card__description";
          var descriptionLabel = document.createElement("strong");
          descriptionLabel.textContent = "Description";
          var descriptionText = document.createElement("p");
          descriptionText.textContent = desc !== "" ? desc : "No description yet.";
          descriptionBlock.appendChild(descriptionLabel);
          descriptionBlock.appendChild(descriptionText);
          body.appendChild(descriptionBlock);
          var inclusionBlock = document.createElement("div");
          inclusionBlock.className = "package-view-card__inclusions";
          var inclusionLabel = document.createElement("strong");
          inclusionLabel.textContent = "Inclusions";
          var inclusionList = document.createElement("p");
          inclusionList.textContent = inclusions !== "" ? inclusions : "None";
          inclusionBlock.appendChild(inclusionLabel);
          inclusionBlock.appendChild(inclusionList);
          body.appendChild(inclusionBlock);
        }
        if (partyInput) {
          var low = minPax > 0 ? minPax : 1;
          var high = pax > 0 ? pax : low;
          var slotsRaw = card.getAttribute("data-package-slots");
          var slotsLeft = slotsRaw === null || slotsRaw === "" ? null : parseInt(slotsRaw, 10);
          if (slotsLeft !== null && slotsLeft >= 0 && slotsLeft < high) high = slotsLeft;
          if (high < low) low = high > 0 ? high : 0;
          partyInput.min = String(Math.max(low, 0));
          partyInput.max = String(Math.max(high, 0));
          partyInput.value = high > 0 ? String(low > 0 ? low : 1) : "0";
          partyInput.disabled = high < 1;
        }
        function paintPrice() {
          var count = partyInput ? parseInt(partyInput.value || "0", 10) : 0;
          if (!count || count < 1) count = 1;
          var total = unitPrice * count;
          if (priceEl) {
            priceEl.replaceChildren();
            priceEl.appendChild(document.createTextNode("Total price · "));
            var amount = document.createElement("strong");
            amount.textContent = "₱" + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            priceEl.appendChild(amount);
          }
        }
        if (partyHint) {
          if (card.hasAttribute("data-package-slots")) {
            var slotsLeft = parseInt(card.getAttribute("data-package-slots") || "0", 10) || 0;
            partyHint.textContent = slotsLeft === 1 ? "1 slot left." : (slotsLeft + " slots left.");
          } else {
            partyHint.textContent = (minPax > 0 && pax > minPax)
              ? ("Choose " + minPax + " to " + pax + " people.")
              : (pax > 0 ? ("Up to " + pax + " people.") : "");
          }
        }
        if (partyInput) {
          partyInput.oninput = paintPrice;
        }
        paintPrice();
        if (lengthEl) lengthEl.textContent = days > 0 ? (days === 1 ? "1 day" : days + " days") : "";
        if (daysEl) {
          daysEl.textContent = pax > 0
            ? (minPax > 0 && minPax < pax ? (minPax + " – " + pax + " pax") : (pax === 1 ? "1 pax" : pax + " pax"))
            : "";
        }
        if (planEl) {
          planEl.replaceChildren();
          var itinId = card.getAttribute("data-package-itinerary") || "";
          if (itinId !== "" && itinId !== "0") {
            var viewLink = document.createElement("a");
            viewLink.className = "btn btn-outline-secondary btn-sm w-100";
            viewLink.target = "_blank";
            viewLink.rel = "noopener";
            viewLink.textContent = "View itinerary";
            viewLink.href = ' . json_encode(APP_URL . '/itinerary-share.php?from=package&id=') . ' + encodeURIComponent(itinId);
            planEl.appendChild(viewLink);
          }
        }
        if (serviceInput) serviceInput.value = serviceId;
        if (dateInput) {
          var locked = dateMode === "fixed" && fixedDate !== "";
          dateInput.value = locked ? fixedDate : "";
          dateInput.readOnly = locked;
          dateInput.disabled = !locked && days < 1;
          dateInput.classList.remove("d-none");
          dateInput.classList.toggle("is-locked", locked);
          if (dateLabel) dateLabel.classList.remove("d-none");
          if (dateHint) dateHint.classList.toggle("d-none", locked);
          if (dateSet) dateSet.classList.add("d-none");
        }
        if (spanEl) spanEl.textContent = "";
        if (bookBtn) bookBtn.disabled = serviceId === "" || days < 1 || (card.hasAttribute("data-package-slots") && (parseInt(card.getAttribute("data-package-slots") || "0", 10) || 0) < 1);
        var hintText = dateHint ? dateHint.textContent : "";
        function showSpan() {
          if (spanEl) spanEl.textContent = "";
          if (!dateHint) return;
          if (!dateInput || !dateInput.value || days < 1) {
            dateHint.textContent = hintText;
            return;
          }
          var start = new Date(dateInput.value + "T00:00:00");
          if (isNaN(start.getTime())) {
            dateHint.textContent = hintText;
            return;
          }
          var fmt = function (d) {
            return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
          };
          if (days === 1) {
            dateHint.textContent = fmt(start);
            return;
          }
          var end = new Date(start.getTime());
          end.setDate(end.getDate() + days - 1);
          dateHint.textContent = fmt(start) + " – " + fmt(end);
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
      (function () {
        var proceed = document.getElementById("packageBookBtn");
        var form = proceed ? proceed.closest("form") : null;
        var guestModal = document.getElementById("packageGuestModal");
        var needsModal = document.getElementById("packageNeedsModal");
        var guestTitle = document.getElementById("packageGuestLabel");
        var adults = document.getElementById("packageAdults");
        var children = document.getElementById("packageChildren");
        var infants = document.getElementById("packageInfants");
        var infantCol = document.getElementById("packageInfantsCol");
        var guestError = document.getElementById("packageGuestError");
        var guestBook = document.getElementById("packageGuestBook");
        var needsError = document.getElementById("packageNeedsError");
        var needsBook = document.getElementById("packageNeedsBook");
        var summaryModal = document.getElementById("packageSummaryModal");
        var summaryBody = document.getElementById("packageSummaryBody");
        var summaryPay = document.getElementById("packageSummaryPay");
        var pendingBooking = { special: false, needs: [] };
        if (!proceed || !form || !guestModal || !needsModal || !summaryModal) return;
        function partyCount() {
          var n = parseInt((document.getElementById("packagePartySize") || {}).value || "0", 10);
          return n > 0 ? n : 0;
        }
        function showGuestError(message) {
          if (!guestError) return;
          guestError.hidden = message === "";
          guestError.textContent = message;
        }
        function infantChoice() {
          var picked = guestModal.querySelector("input[name=packageInfantChoice]:checked");
          return picked ? picked.value : "";
        }
        function onePersonTrip() {
          return guestModal.dataset.exclusive !== "1" && partyCount() === 1;
        }
        function infantsOn() {
          return infantChoice() === "yes";
        }
        function guestSplitOk() {
          var total = partyCount();
          var a = parseInt(adults.value || "", 10);
          var c = parseInt(children.value || "", 10);
          var pickedInfant = infantChoice();
          if (pickedInfant === "") {
            showGuestError("Choose Yes or No for infants.");
            return false;
          }
          if (onePersonTrip()) {
            adults.value = "1";
            children.value = "0";
          }
          var i = infantsOn() ? parseInt(infants.value || "", 10) : 0;
          if (!onePersonTrip() && (adults.value === "" || children.value === "" || !isFinite(a) || !isFinite(c) || a < 0 || c < 0)) {
            showGuestError("Enter the number of adults and children.");
            return false;
          }
          if (infantsOn() && (infants.value === "" || !isFinite(i) || i < 1)) {
            showGuestError("Enter the number of infants/toddlers.");
            return false;
          }
          if (!onePersonTrip() && a + c !== total) {
            showGuestError("Adults and children must add up to " + total + ". Infants and toddlers are not counted.");
            return false;
          }
          showGuestError("");
          return true;
        }
        function choice() {
          var picked = guestModal.querySelector("input[name=packageSpecialChoice]:checked");
          return picked ? picked.value : "";
        }
        function countFields() {
          return [adults, children];
        }
        function capGuestFields(changed) {
          var total = partyCount();
          adults.max = String(total);
          children.max = String(total);
          if (!changed || changed === infants || changed.value === "") return;
          var n = parseInt(changed.value, 10);
          if (!isFinite(n) || n < 0) n = 0;
          if (n > total) n = total;
          changed.value = String(n);
          if (changed === adults) {
            children.value = String(Math.max(total - n, 0));
          } else if (changed === children) {
            adults.value = String(Math.max(total - n, 0));
          }
        }
        function syncInfantRow() {
          var show = infantsOn();
          if (infantCol) infantCol.classList.toggle("d-none", !show);
          if (!show && infants) infants.value = "";
          capGuestFields(null);
        }
        proceed.addEventListener("click", function () {
          if (!form.reportValidity()) return;
          var total = partyCount();
          var oneInclusive = guestModal.dataset.exclusive !== "1" && total === 1;
          var adultsCol = document.getElementById("packageAdultsCol");
          var childrenCol = document.getElementById("packageChildrenCol");
          if (adultsCol) adultsCol.classList.toggle("d-none", oneInclusive);
          if (childrenCol) childrenCol.classList.toggle("d-none", oneInclusive);
          if (guestTitle) guestTitle.textContent = oneInclusive ? "1 person traveling" : (total === 1 ? "1 guest joining" : (total + " guests joining"));
          var infantQuestion = document.getElementById("packageInfantQuestion");
          var specialQuestion = document.getElementById("packageSpecialQuestion");
          if (infantQuestion) {
            infantQuestion.textContent = oneInclusive
              ? "1. Are you traveling with an infant/toddler (3 months to 3 years old)?"
              : "1. Is your group traveling with an infant/toddler (3 months to 3 years old)?";
          }
          if (specialQuestion) {
            specialQuestion.textContent = oneInclusive
              ? "2. Do you have any special requirements?"
              : "2. Does anyone in your group have special requirements?";
          }
          adults.value = "";
          children.value = "";
          if (infants) infants.value = "";
          guestModal.querySelectorAll("input[name=packageInfantChoice]").forEach(function (el) { el.checked = false; });
          if (infantCol) infantCol.classList.add("d-none");
          adults.max = String(total);
          children.max = String(total);
          if (infants) infants.removeAttribute("max");
          guestModal.querySelectorAll("input[name=packageSpecialChoice]").forEach(function (el) { el.checked = false; });
          showGuestError("");
          bootstrap.Modal.getOrCreateInstance(guestModal).show();
        });
        guestModal.addEventListener("shown.bs.modal", function () {
          var backs = document.querySelectorAll(".modal-backdrop");
          var last = backs[backs.length - 1];
          if (last) last.classList.add("pkg-guest-backdrop");
        });
        needsModal.addEventListener("shown.bs.modal", function () {
          var backs = document.querySelectorAll(".modal-backdrop");
          var last = backs[backs.length - 1];
          if (last) last.classList.add("pkg-needs-backdrop");
        });
        summaryModal.addEventListener("shown.bs.modal", function () {
          var backs = document.querySelectorAll(".modal-backdrop");
          var last = backs[backs.length - 1];
          if (last) last.classList.add("pkg-summary-backdrop");
        });
        adults.addEventListener("input", function () { capGuestFields(adults); });
        children.addEventListener("input", function () { capGuestFields(children); });
        if (infants) infants.addEventListener("input", function () { capGuestFields(infants); });
        guestModal.querySelectorAll("input[name=packageInfantChoice]").forEach(function (el) {
          el.addEventListener("change", syncInfantRow);
        });
        needsModal.querySelectorAll("[data-need-check]").forEach(function (box) {
          box.addEventListener("change", function () {
            var row = box.closest(".pkg-need");
            if (!row) return;
            row.querySelectorAll(".pkg-need__count, .pkg-need__detail").forEach(function (field) {
              var hideCount = field.classList.contains("pkg-need__count") && onePersonTrip();
              field.hidden = !box.checked || hideCount;
              if (!box.checked) field.value = "";
            });
          });
        });
        needsModal.querySelectorAll(".pkg-need__count").forEach(function (field) {
          field.addEventListener("input", function () {
            var cap = partyCount();
            var n = parseInt(field.value || "", 10);
            if (field.value !== "" && (!isFinite(n) || n < 1)) field.value = "1";
            if (isFinite(n) && n > cap) field.value = String(cap);
          });
        });
        function fillAndSubmit(special, needs) {
          document.getElementById("packageAdultsField").value = String(parseInt(adults.value, 10));
          document.getElementById("packageChildrenField").value = String(parseInt(children.value, 10));
          document.getElementById("packageInfantsField").value = infantsOn() ? String(parseInt(infants.value, 10) || 0) : "0";
          document.getElementById("packageSpecialField").value = special ? "yes" : "no";
          document.getElementById("packageNeedsField").value = JSON.stringify(needs);
          form.submit();
        }
        if (guestBook) guestBook.addEventListener("click", function () {
          var picked = choice();
          if (!guestSplitOk()) return;
          if (picked === "") {
            showGuestError("Choose Yes or No.");
            return;
          }
          if (picked === "yes") {
            if (needsError) { needsError.hidden = true; needsError.textContent = ""; }
            var otherRequest = document.getElementById("packageOtherRequest");
            if (otherRequest) otherRequest.value = "";
            var cap = String(partyCount());
            needsModal.querySelectorAll(".pkg-need__count").forEach(function (field) { field.max = cap; });
            showAfterClose(needsModal, [guestModal]);
            return;
          }
          openSummary(false, []);
        });
        needsModal.addEventListener("input", function (e) {
          if (e.target && e.target.classList) e.target.classList.remove("is-invalid");
          if (needsError) needsError.hidden = true;
        });
        needsModal.addEventListener("change", function () {
          if (needsError) needsError.hidden = true;
        });
        if (needsBook) needsBook.addEventListener("click", function () {
          if (!guestSplitOk()) {
            if (needsError) {
              needsError.hidden = false;
              needsError.textContent = guestError ? guestError.textContent : "Enter the number of adults and children.";
            }
            return;
          }
          if (needsError) needsError.hidden = true;
          var total = partyCount();
          var needs = [];
          var firstBad = null;
          needsModal.querySelectorAll(".is-invalid").forEach(function (el) { el.classList.remove("is-invalid"); });
          needsModal.querySelectorAll("[data-need-check]").forEach(function (box) {
            if (!box.checked) return;
            var row = box.closest(".pkg-need");
            var countEl = row ? row.querySelector(".pkg-need__count") : null;
            var detailEl = row ? row.querySelector(".pkg-need__detail") : null;
            var count = onePersonTrip() ? 1 : (countEl ? parseInt(countEl.value || "", 10) : 0);
            var bad = null;
            if (!onePersonTrip() && (!countEl || !count || count < 1 || count > total)) bad = countEl;
            var detail = detailEl ? detailEl.value.trim() : "";
            if (!bad && box.getAttribute("data-need-other") === "1" && detail === "") bad = detailEl;
            if (bad) {
              bad.classList.add("is-invalid");
              if (!firstBad) firstBad = bad;
              return;
            }
            needs.push({ label: box.value, count: count, detail: detail, other: box.getAttribute("data-need-other") === "1" });
          });
          var otherRequest = document.getElementById("packageOtherRequest");
          var otherText = otherRequest ? otherRequest.value.trim() : "";
          if (otherText !== "") {
            needs.push({ label: "Other special requests", count: 1, detail: otherText, other: true });
          }
          if (firstBad) {
            firstBad.scrollIntoView({ block: "center", behavior: "smooth" });
            firstBad.focus();
            return;
          }
          if (!needs.length) {
            if (needsError) {
              needsError.hidden = false;
              needsError.textContent = "Check at least one requirement, or write an other request.";
              needsError.scrollIntoView({ block: "center", behavior: "smooth" });
            }
            return;
          }
          openSummary(true, needs);
        });
        function openSummary(special, needs) {
          pendingBooking = { special: special, needs: needs };
          var dateValue = (document.getElementById("packageTourDate") || {}).value || "";
          var dateParts = dateValue.split("-");
          var dateText = dateParts.length === 3 ? (dateParts[1] + "/" + dateParts[2] + "/" + dateParts[0].slice(-2)) : dateValue;
          var lines = [
            ["Package", (document.getElementById("packageViewLabel") || {}).textContent || "Package"],
            ["Tour date", dateText],
            ["Length", (document.getElementById("packageViewLength") || {}).textContent || ""],
            ["Adults", adults.value],
            ["Children", children.value],
            ["Infant/toddler", infantsOn() ? (infants.value + " (not counted)") : ""],
            ["Total", (document.getElementById("packageViewPrice") || {}).textContent || ""]
          ];
          if (summaryBody) {
            summaryBody.replaceChildren();
            lines.forEach(function (pair) {
              if (!pair[1]) return;
              var row = document.createElement("p");
              row.className = "mb-1";
              var strong = document.createElement("strong");
              strong.textContent = pair[0];
              row.appendChild(strong);
              row.appendChild(document.createTextNode(" · " + pair[1]));
              summaryBody.appendChild(row);
            });
            var req = document.createElement("p");
            req.className = "mb-1";
            var reqLabel = document.createElement("strong");
            reqLabel.textContent = "Special requirements";
            req.appendChild(reqLabel);
            if (!special || !needs.length) {
              req.appendChild(document.createTextNode(" · None"));
              summaryBody.appendChild(req);
            } else {
              summaryBody.appendChild(req);
              var list = document.createElement("ul");
              list.className = "mb-0 ps-3";
              needs.forEach(function (item) {
                var li = document.createElement("li");
                li.textContent = item.label + " · " + item.count + (item.detail ? " · " + item.detail : "");
                list.appendChild(li);
              });
              summaryBody.appendChild(list);
            }
          }
          var summaryItinerary = document.getElementById("packageSummaryItinerary");
          var planLink = document.querySelector("#packageViewPlan a");
          if (summaryItinerary) {
            if (planLink && planLink.href) {
              summaryItinerary.href = planLink.href;
              summaryItinerary.hidden = false;
            } else {
              summaryItinerary.hidden = true;
            }
          }
          document.body.appendChild(summaryModal);
          summaryModal.style.zIndex = "2000";
          showAfterClose(summaryModal, [guestModal, needsModal]);
        }
        function showAfterClose(next, previous) {
          var open = previous.filter(function (el) { return el && el.classList.contains("show"); });
          function showNext() {
            bootstrap.Modal.getOrCreateInstance(next).show();
          }
          if (!open.length) {
            showNext();
            return;
          }
          var left = open.length;
          open.forEach(function (el) {
            el.addEventListener("hidden.bs.modal", function once() {
              el.removeEventListener("hidden.bs.modal", once);
              left -= 1;
              if (left === 0) showNext();
            });
            bootstrap.Modal.getOrCreateInstance(el).hide();
          });
        }
        if (summaryPay) summaryPay.addEventListener("click", function () {
          fillAndSubmit(pendingBooking.special, pendingBooking.needs);
        });
      })();
    </script>';
require __DIR__ . '/../includes/footer.php';
