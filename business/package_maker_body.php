<?php
/** @var array $pkg @var array $biz @var int $days @var array $stops */
$byDay = [];
for ($d = 1; $d <= $days; $d++) {
    $byDay[$d] = [];
}
foreach ($stops as $stop) {
    $dayNo = (int) $stop['day_number'];
    if ($dayNo >= 1 && $dayNo <= $days) {
        $byDay[$dayNo][] = $stop;
    }
}
$places = package_maker_places((int) $biz['id']);
$stayCatalog = package_offer_catalog('rooms');
$rideCatalog = package_offer_catalog('vehicles');
$staySaved = json_decode((string) ($pkg['package_stay_json'] ?? ''), true);
$rideSaved = json_decode((string) ($pkg['package_ride_json'] ?? ''), true);
if (!is_array($staySaved)) {
    $staySaved = [];
}
if (!is_array($rideSaved)) {
    $rideSaved = [];
}
$guideRow = null;
if ((int) ($pkg['package_guide_id'] ?? 0) > 0) {
    $guideSt = db()->prepare(
        "SELECT g.id, g.languages, g.hourly_rate, u.first_name, u.last_name, u.avatar, u.phone
         FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id WHERE g.id=? LIMIT 1"
    );
    $guideSt->execute([(int) $pkg['package_guide_id']]);
    $guideRow = $guideSt->fetch() ?: null;
}
$guides = db()->query(
    "SELECT g.id, g.languages, g.hourly_rate, u.first_name, u.last_name, u.avatar, u.phone
     FROM tour_guide_profiles g
     JOIN users u ON u.id = g.user_id
     WHERE g.approval_status='approved' AND u.status='active'
     ORDER BY u.first_name, u.last_name"
)->fetchAll() ?: [];

$pkgPhoto = static function (?string $path): string {
    $path = trim((string) $path);
    return $path === '' ? '' : APP_URL . '/uploads/' . ltrim($path, '/');
};

$offerModal = static function (string $id, string $title, string $action, string $noun, array $catalog, array $saved) use ($pkg, $pkgPhoto): void {
    $selectedBiz = (int) ($saved['business_id'] ?? 0);
    $declined = !empty($saved['declined']);
    ?>
    <div class="modal fade stay-ride-modal" id="<?= e($id) ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content itin-book-review-card">
          <form method="post" data-pkg-offer-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= e($action) ?>">
            <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
            <input type="hidden" name="offer_business_id" value="<?= $declined ? 0 : $selectedBiz ?>" data-offer-business>
            <input type="hidden" name="offer_service_id" value="<?= $declined ? 0 : (int) ($saved['service_id'] ?? 0) ?>" data-offer-service>
            <input type="hidden" name="offer_declined" value="<?= $declined ? 1 : 0 ?>" data-offer-declined>
            <div class="modal-header border-0 pb-0">
              <div>
                <button type="button" class="stay-step-back" data-bs-dismiss="modal">Back</button>
                <h2 class="modal-title h5 mb-0"><?= e($title) ?></h2>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
              <div class="pick-board" data-pkg-loop data-selected="<?= $declined ? '' : $selectedBiz ?>">
                <label class="pick-radio">
                  <input type="checkbox" data-offer-none <?= $declined ? ' checked' : '' ?>>
                  <span>I don’t need <?= e($noun) ?></span>
                </label>
                <?php if ($catalog['businesses']): ?>
                <label class="pick-search">
                  <i class="bi bi-search" aria-hidden="true"></i>
                  <input type="search" data-pkg-search placeholder="Search a place" aria-label="Search a place" autocomplete="off">
                </label>
                <div class="pick-focus pick-focus--stay" data-pick-focus>
                  <p class="pick-search__empty" data-pick-empty hidden>No places match that search.</p>
                  <div class="pick-focus__stage">
                    <div class="pick-focus__slot" data-slot="left">
                      <button type="button" class="pick-focus__arrow pick-focus__arrow--prev" aria-label="Previous place"><i class="bi bi-chevron-up"></i></button>
                    </div>
                    <div class="pick-focus__slot pick-focus__slot--center" data-slot="center"></div>
                    <div class="pick-focus__slot" data-slot="right">
                      <button type="button" class="pick-focus__arrow pick-focus__arrow--next" aria-label="Next place"><i class="bi bi-chevron-down"></i></button>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
                <div class="pick-row pick-row--places" data-pkg-source>
                  <?php foreach ($catalog['businesses'] as $place): ?>
                    <?php $cover = $pkgPhoto($place['cover_image'] ?? '') ?: $pkgPhoto($place['logo'] ?? ''); ?>
                    <button type="button" class="pick-spot" data-pick-card="<?= (int) $place['id'] ?>">
                      <span class="pick-spot__photo<?= $cover === '' ? ' is-empty' : '' ?>"<?= $cover !== '' ? ' style="background-image:url(' . e($cover) . ')"' : '' ?>></span>
                      <span class="pick-spot__copy">
                        <span class="pick-spot__name"><?= e((string) $place['name']) ?></span>
                        <?php if (trim((string) ($place['address'] ?? '')) !== ''): ?><span class="pick-spot__place"><?= e((string) $place['address']) ?></span><?php endif; ?>
                      </span>
                    </button>
                  <?php endforeach; ?>
                </div>
                <div class="stay-build">
                  <div class="pick-shelf" data-pick-shelf hidden>
                    <div class="pick-shelf__label"><?= e(ucfirst($noun === 'accommodation' ? 'rooms' : 'vehicles')) ?></div>
                    <?php foreach ($catalog['businesses'] as $place): ?>
                      <div class="pick-row" data-pick-rooms="<?= (int) $place['id'] ?>" hidden>
                        <?php foreach ($catalog['offers'][(int) $place['id']] ?? [] as $offer): ?>
                          <?php $offerImg = $pkgPhoto($offer['image_path'] ?? ''); ?>
                          <article class="stay-room stay-room--wide" data-pkg-offer data-business-id="<?= (int) $place['id'] ?>" data-service-id="<?= (int) $offer['id'] ?>">
                            <span class="stay-room__hero<?= $offerImg === '' ? ' is-empty' : '' ?>"<?= $offerImg !== '' ? ' style="background-image:url(' . e($offerImg) . ')"' : '' ?>></span>
                            <span class="stay-room__body">
                              <strong><?= e((string) $offer['name']) ?></strong>
                              <small><?= e(format_money((float) ($offer['price'] ?? 0))) ?><?php if ((int) ($offer['capacity'] ?? 0) > 0): ?> · <?= (int) $offer['capacity'] ?> seats<?php endif; ?></small>
                            </span>
                          </article>
                        <?php endforeach; ?>
                        <?php if (empty($catalog['offers'][(int) $place['id']])): ?>
                          <p class="small text-muted mb-0">No <?= e($noun === 'accommodation' ? 'rooms' : 'vehicles') ?> listed yet.</p>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer border-0">
              <button class="btn btn-ct" type="submit">Save <?= e($noun) ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
};

$pageTitle = 'Package maker';
$pageHeading = 'Package maker';
$pageSub = (string) ($pkg['name'] ?? 'Package');
$activeNav = 'package-maker';
$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <p class="mb-3"><a href="<?= APP_URL ?>/business/services.php">Packages made</a></p>
      <div class="pkg-maker">
        <div>
          <form method="post" class="glass p-4 pkg-maker__price mb-3" data-unsaved-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
            <h2 class="h5 mb-1">Package price</h2>
            <p class="small text-muted">Set what this tour costs. Building the package does not take a payment.</p>
            <div class="mb-2">
              <label class="form-label" for="pkgName">Package name</label>
              <input class="form-control" id="pkgName" name="name" required value="<?= e((string) $pkg['name']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label" for="pkgPrice">Price</label>
              <input class="form-control" id="pkgPrice" type="number" name="price" min="0" step="0.01" required value="<?= e((string) ($pkg['price'] ?? '0')) ?>">
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label" for="pkgDays">How many days</label>
                <input class="form-control" id="pkgDays" name="package_days" inputmode="numeric" required value="<?= (int) $days ?>">
              </div>
              <div class="col-6">
                <label class="form-label" for="pkgPax">Pax</label>
                <input class="form-control" id="pkgPax" name="capacity" inputmode="numeric" required value="<?= (int) ($pkg['capacity'] ?? 1) ?>">
              </div>
            </div>
            <div class="mt-2 mb-3">
              <label class="form-label" for="pkgDesc">Description</label>
              <textarea class="form-control" id="pkgDesc" name="description" rows="3"><?= e((string) ($pkg['description'] ?? '')) ?></textarea>
            </div>
            <button class="btn btn-ct" type="submit">Save price</button>
          </form>

          <div class="glass p-3 mb-3">
            <button type="button" class="itin-guide-preview<?= $guideRow ? '' : ' is-empty' ?> w-100" data-bs-toggle="modal" data-bs-target="#pkgGuideModal">
              <span class="itin-guide-preview__avatar">
                <?php if ($guideRow && !empty($guideRow['avatar'])): ?>
                  <img src="<?= e($pkgPhoto((string) $guideRow['avatar'])) ?>" alt="">
                <?php else: ?>
                  <span><?= e($guideRow ? strtoupper(substr((string) $guideRow['first_name'], 0, 1)) : '?') ?></span>
                <?php endif; ?>
              </span>
              <span class="itin-guide-preview__text">
                <span class="itin-guide-preview__label">Tour guide</span>
                <span class="itin-guide-preview__name"><?= $guideRow ? e(trim($guideRow['first_name'] . ' ' . $guideRow['last_name'])) : 'No guide yet' ?></span>
              </span>
            </button>
            <div class="d-grid gap-2 mt-3">
              <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pkgStayModal">Accommodation<?= !empty($staySaved['business_name']) ? ': ' . e((string) $staySaved['business_name']) : (!empty($staySaved['declined']) ? ': not needed' : '') ?></button>
              <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pkgRideModal">Transportation<?= !empty($rideSaved['business_name']) ? ': ' . e((string) $rideSaved['business_name']) : (!empty($rideSaved['declined']) ? ': not needed' : '') ?></button>
            </div>
          </div>
        </div>

        <div class="pkg-maker__plan">
          <form method="post" class="glass p-3 mb-3" id="pkgAddStop">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_stop">
            <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
            <input type="hidden" name="place_key" id="pkgPlaceKey" value="">
            <h2 class="h6 mb-2">Add a place</h2>
            <div class="row g-2 align-items-end mb-2">
              <div class="col-md-4">
                <label class="form-label" for="pkgDay">Day</label>
                <select class="form-select" id="pkgDay" name="day_number">
                  <?php for ($d = 1; $d <= $days; $d++): ?>
                    <option value="<?= $d ?>">Day <?= $d ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="pkgVisit">Visit time (minutes)</label>
                <input class="form-control" id="pkgVisit" type="number" name="visit_minutes" min="15" max="<?= (int) ITIN_MAX_VISIT_MINUTES ?>" value="60" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="pkgPlaceSearch">Search</label>
                <input class="form-control" id="pkgPlaceSearch" type="search" placeholder="Search places…" autocomplete="off">
              </div>
            </div>
            <div class="pkg-place-list" id="pkgPlaceList">
              <?php foreach ($places['destinations'] as $place): ?>
                <button type="button" class="pkg-place-list__item" data-place-key="d:<?= (int) $place['id'] ?>" data-place-name="<?= e($place['name']) ?>">
                  <strong><?= e($place['name']) ?></strong>
                  <span><?= e($place['subtitle']) ?></span>
                </button>
              <?php endforeach; ?>
              <?php foreach ($places['businesses'] as $place): ?>
                <button type="button" class="pkg-place-list__item" data-place-key="b:<?= (int) $place['id'] ?>" data-place-name="<?= e($place['name']) ?>" data-place-popup="<?= !empty($place['popup']) ? '1' : '0' ?>" data-place-json="<?= e(json_encode($place, JSON_UNESCAPED_UNICODE)) ?>">
                  <strong><?= e($place['name']) ?></strong>
                  <span><?= e($place['subtitle']) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </form>
        </div>

          <?php foreach ($byDay as $day => $dayStops): ?>
            <section class="glass p-3 pkg-maker__day">
              <h2 class="h6 mb-2">Day <?= (int) $day ?></h2>
              <?php if (!$dayStops): ?>
                <p class="small text-muted mb-0">No places on this day yet.</p>
              <?php else: ?>
                <ol class="pkg-maker__stops">
                  <?php foreach ($dayStops as $i => $stop): ?>
                    <li>
                      <div>
                        <strong><?= e((string) $stop['place_name']) ?></strong>
                        <span class="small text-muted"> · <?= (int) $stop['visit_minutes'] ?> min</span>
                      </div>
                      <div class="pkg-maker__stop-actions">
                        <form method="post"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="move_stop">
                          <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
                          <input type="hidden" name="stop_id" value="<?= (int) $stop['id'] ?>">
                          <input type="hidden" name="dir" value="up">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"<?= $i === 0 ? ' disabled' : '' ?>>Up</button>
                        </form>
                        <form method="post"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="move_stop">
                          <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
                          <input type="hidden" name="stop_id" value="<?= (int) $stop['id'] ?>">
                          <input type="hidden" name="dir" value="down">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"<?= $i === count($dayStops) - 1 ? ' disabled' : '' ?>>Down</button>
                        </form>
                        <form method="post"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="remove_stop">
                          <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
                          <input type="hidden" name="stop_id" value="<?= (int) $stop['id'] ?>">
                          <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                        </form>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
      </div>

      <div class="jb-pop" id="pkgPlacePop" hidden>
        <button type="button" class="jb-pop__scrim" data-pkg-pop-close aria-label="Close"></button>
        <div class="jb-pop__card" role="dialog" aria-modal="true" aria-labelledby="pkgPopTitle">
          <button type="button" class="jb-pop__x" data-pkg-pop-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
          <div class="jb-pop__photo" id="pkgPopPhoto"></div>
          <div class="p-3">
            <h2 class="h5 mb-1" id="pkgPopTitle">Place</h2>
            <p class="small text-muted" id="pkgPopKind"></p>
            <div class="pkg-pop-menu" id="pkgPopMenu"></div>
            <button type="button" class="btn btn-ct w-100 mt-3" id="pkgPopAdd">Add to this day</button>
          </div>
        </div>
      </div>

      <div class="modal fade guide-book-modal" id="pkgGuideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_guide">
              <input type="hidden" name="service_id" value="<?= (int) $pkg['id'] ?>">
              <div class="modal-header border-0">
                <h2 class="modal-title h5">Tour guide</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body pt-0">
                <p class="small text-muted">Pick the guide included with this package. This does not book or charge the guide.</p>
                <label class="pick-radio mb-2">
                  <input type="radio" name="guide_id" value="0" <?= $guideRow ? '' : 'checked' ?>>
                  <span>No tour guide</span>
                </label>
                <div class="pkg-guide-list">
                  <?php foreach ($guides as $guide): ?>
                    <label class="pkg-guide-list__item">
                      <input type="radio" name="guide_id" value="<?= (int) $guide['id'] ?>" <?= $guideRow && (int) $guideRow['id'] === (int) $guide['id'] ? 'checked' : '' ?>>
                      <span>
                        <strong><?= e(trim($guide['first_name'] . ' ' . $guide['last_name'])) ?></strong>
                        <small><?= e(trim((string) ($guide['languages'] ?? '')) ?: 'Languages not listed') ?><?php if ($guide['hourly_rate'] !== null && $guide['hourly_rate'] !== ''): ?> · <?= e(format_money($guide['hourly_rate'])) ?>/day<?php endif; ?></small>
                      </span>
                    </label>
                  <?php endforeach; ?>
                  <?php if (!$guides): ?>
                    <p class="small text-muted mb-0">No approved tour guides yet.</p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="modal-footer border-0">
                <button class="btn btn-ct" type="submit">Save tour guide</button>
              </div>
            </form>
          </div>
        </div>
      </div>
<?php
$offerModal('pkgStayModal', 'Where will guests stay?', 'set_stay', 'accommodation', $stayCatalog, $staySaved);
$offerModal('pkgRideModal', 'How will guests get around?', 'set_ride', 'transportation', $rideCatalog, $rideSaved);
require __DIR__ . '/../includes/business_shell_end.php';
$extraScripts = '<script src="' . APP_URL . '/assets/js/package-maker.js?v=' . (@filemtime(ROOT_PATH . '/assets/js/package-maker.js') ?: time()) . '"></script>';
require __DIR__ . '/../includes/footer.php';
