<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/business_engagement.php';
$user = require_login(ROLE_TOURIST);
ensure_business_favorites_schema();
$pageTitle = 'Places';
$pageHeading = 'Places';
$pageSub = 'CulTour · Ormoc City';
$activeNav = 'places';

ensure_destination_coords();
ensure_category_pin_colors();
ensure_business_type_pin_colors();

$categories = db()->query(
    "SELECT id, name, pin_color FROM categories
     WHERE LOWER(TRIM(name)) NOT IN ('meet up', 'meetup', 'meet-up')
     ORDER BY name"
)->fetchAll();
$types = db()->query('SELECT id, name, pin_color FROM business_types ORDER BY name')->fetchAll();

$q = trim($_GET['q'] ?? '');
$cat = (int) ($_GET['category'] ?? 0);
$type = (int) ($_GET['type'] ?? 0);
if ($cat && $type) {
    $type = 0;
}

$destSql = "SELECT d.*, c.name AS category_name, c.pin_color
        FROM destinations d
        JOIN categories c ON c.id = d.category_id
        WHERE d.status = 'published'
          AND LOWER(TRIM(c.name)) NOT IN ('meet up', 'meetup', 'meet-up')";
$destParams = [];
if ($q !== '') {
    $destSql .= ' AND (d.name LIKE ? OR d.location LIKE ? OR d.description LIKE ?)';
    $like = '%' . $q . '%';
    $destParams = [$like, $like, $like];
}
if ($cat) {
    $destSql .= ' AND d.category_id = ?';
    $destParams[] = $cat;
}
$destSql .= ' ORDER BY c.name ASC, d.is_featured DESC, d.name ASC';
$destRows = [];
if (!$type) {
    $stmt = db()->prepare($destSql);
    $stmt->execute($destParams);
    $destRows = $stmt->fetchAll() ?: [];
}

$bizSql = "SELECT b.*, bt.name AS type_name, bt.pin_color
        FROM businesses b
        JOIN business_types bt ON bt.id = b.business_type_id
        WHERE b.approval_status = 'approved' AND b.status = 'active'";
$bizParams = [];
if ($q !== '') {
    $bizSql .= ' AND (b.name LIKE ? OR b.address LIKE ? OR b.description LIKE ? OR bt.name LIKE ?)';
    $like = '%' . $q . '%';
    $bizParams = [$like, $like, $like, $like];
}
if ($type) {
    $bizSql .= ' AND b.business_type_id = ?';
    $bizParams[] = $type;
}
$bizSql .= ' ORDER BY bt.name ASC, b.name ASC';
$bizRows = [];
if (!$cat) {
    $stmt = db()->prepare($bizSql);
    $stmt->execute($bizParams);
    $bizRows = array_values(array_filter($stmt->fetchAll() ?: [], 'business_itinerary_ready'));
}

$favStmt = db()->prepare('SELECT destination_id FROM favorites WHERE user_id=?');
$favStmt->execute([(int) $user['id']]);
$favDestIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
$favStmt = db()->prepare('SELECT business_id FROM business_favorites WHERE user_id=?');
$favStmt->execute([(int) $user['id']]);
$favBizIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$featured = [];
$byCategory = [];
foreach ($destRows as $d) {
    $cid = (int) $d['category_id'];
    if (!isset($byCategory[$cid])) {
        $byCategory[$cid] = [
            'id' => $cid,
            'name' => (string) $d['category_name'],
            'color' => (string) ($d['pin_color'] ?: '#0e8a7a'),
            'items' => [],
        ];
    }
    $byCategory[$cid]['items'][] = $d;
    if (!empty($d['is_featured'])) {
        $featured[] = $d;
    }
}
$byType = [];
foreach ($bizRows as $b) {
    $tid = (int) $b['business_type_id'];
    if (!isset($byType[$tid])) {
        $byType[$tid] = [
            'id' => $tid,
            'name' => (string) $b['type_name'],
            'color' => (string) ($b['pin_color'] ?: '#c4a35a'),
            'items' => [],
        ];
    }
    $byType[$tid]['items'][] = $b;
}

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';

function tourist_place_excerpt(?string $text, string $fallback, int $max = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
    if ($text === '') {
        return $fallback;
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }
    return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
}

/**
 * @param array<string,mixed> $place
 */
function tourist_place_card(array $place, bool $active = false): void
{
    global $favDestIds, $favBizIds;
    $kind = (string) ($place['kind'] ?? 'destination');
    $id = (int) ($place['id'] ?? 0);
    $isBiz = $kind === 'business';
    $href = APP_URL . ($isBiz ? '/tourist/business.php?id=' : '/tourist/destination.php?id=') . $id;
    $cover = !empty($place['cover_image'])
        ? 'background-image:url(' . APP_URL . '/uploads/' . e((string) $place['cover_image']) . ')'
        : '';
    $excerpt = tourist_place_excerpt(
        $place['description'] ?? '',
        $isBiz
            ? 'Discover this place in Ormoc — open for hours, contact, and more.'
            : 'Explore this place in Ormoc — open for full details, hours, and reviews.'
    );
    $isFav = $isBiz ? isset($favBizIds[$id]) : isset($favDestIds[$id]);
    $kicker = $isBiz ? (string) ($place['type_name'] ?? 'Place') : (string) ($place['category_name'] ?? 'Place');
    $where = $isBiz ? (string) ($place['address'] ?? '') : (string) ($place['location'] ?? '');
    ?>
    <article class="ct-rail__card<?= $active ? ' is-active' : '' ?>"
             data-href="<?= e($href) ?>"
             tabindex="0"
             role="button"
             aria-label="<?= e((string) ($place['name'] ?? 'Place')) ?>">
      <div class="ct-rail__poster" style="<?= $cover ?>">
        <div class="ct-rail__shade"></div>
        <div class="ct-rail__fav-wrap">
          <button type="button" class="ct-rail__fav<?= $isFav ? ' is-on' : '' ?>"
                  data-fav-btn data-fav-type="<?= $isBiz ? 'business' : 'destination' ?>" data-fav-id="<?= $id ?>"
                  aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                  aria-label="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>">
            <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
          </button>
          <p class="ct-rail__fav-note" data-fav-note></p>
        </div>
        <div class="ct-rail__meta">
          <div class="ct-rail__kicker"><?= e($kicker) ?></div>
          <div class="ct-rail__title"><?= e((string) ($place['name'] ?? '')) ?></div>
          <div class="ct-rail__sub"><?= e($where) ?></div>
          <p class="ct-rail__desc"><?= e($excerpt) ?></p>
          <div class="ct-rail__stats">
            <?php if ($isBiz): ?>
              <?php
                $contact = trim(implode(' · ', array_filter([
                    trim((string) ($place['phone'] ?? '')),
                    trim((string) ($place['email'] ?? '')),
                ])));
              ?>
              <span><?= e($contact !== '' ? $contact : 'Ormoc place') ?></span>
            <?php else: ?>
              <span><?= e(format_money($place['entrance_fee'] ?? 0)) ?></span>
              <span>·</span>
              <span>★ <?= e((string) ($place['avg_rating'] ?? '0')) ?></span>
              <?php if (!empty($place['review_count'])): ?>
                <span>·</span>
                <span><?= (int) $place['review_count'] ?> review<?= (int) $place['review_count'] === 1 ? '' : 's' ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <span class="ct-rail__cta">View details <i class="bi bi-arrow-right"></i></span>
        </div>
      </div>
    </article>
    <?php
}

/**
 * @param list<array<string,mixed>> $items
 */
function tourist_place_rail(string $title, string $railId, array $items, ?string $accent = null): void
{
    if (!$items) {
        return;
    }
    ?>
    <section class="ct-rail fade-up" data-focus-rail aria-label="<?= e($title) ?>">
      <div class="ct-rail__head">
        <h2 class="ct-rail__heading">
          <?php if ($accent): ?>
            <span class="ct-rail__dot" style="background:<?= e($accent) ?>"></span>
          <?php endif; ?>
          <?= e($title) ?>
        </h2>
      </div>
      <div class="ct-rail__viewport">
        <button type="button" class="ct-rail__arrow ct-rail__arrow--prev" aria-label="Previous place">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ct-rail__track" id="<?= e($railId) ?>">
          <?php foreach ($items as $i => $place): ?>
            <?php tourist_place_card($place, $i === 0); ?>
          <?php endforeach; ?>
        </div>
        <button type="button" class="ct-rail__arrow ct-rail__arrow--next" aria-label="Next place">
          <i class="bi bi-chevron-right"></i>
        </button>
      </div>
    </section>
    <?php
}

$placesQuery = static function (string $q, int $category = 0, int $type = 0): string {
    $params = [];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($category) {
        $params['category'] = $category;
    }
    if ($type) {
        $params['type'] = $type;
    }
    return $params ? ('?' . http_build_query($params)) : '?';
};
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>
    <div class="page-content page-content--rails">
      <form class="ct-rail-filters glass p-3 mb-4" method="get">
        <div class="row g-2 align-items-center">
          <div class="col-md-5">
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search places…">
          </div>
          <div class="col-md-5">
            <div class="ct-chip-row" role="list">
              <a class="ct-chip <?= $cat === 0 && $type === 0 ? 'is-active' : '' ?>" href="<?= e($placesQuery($q)) ?>" role="listitem">All</a>
              <?php foreach ($categories as $c): ?>
                <a class="ct-chip <?= $cat === (int) $c['id'] ? 'is-active' : '' ?>"
                   href="<?= e($placesQuery($q, (int) $c['id'])) ?>"
                   role="listitem"
                   style="<?= $cat === (int) $c['id'] ? '--chip-accent:' . e($c['pin_color'] ?: '#0e8a7a') : '' ?>">
                  <?= e($c['name']) ?>
                </a>
              <?php endforeach; ?>
              <?php foreach ($types as $t): ?>
                <a class="ct-chip <?= $type === (int) $t['id'] ? 'is-active' : '' ?>"
                   href="<?= e($placesQuery($q, 0, (int) $t['id'])) ?>"
                   role="listitem"
                   style="<?= $type === (int) $t['id'] ? '--chip-accent:' . e($t['pin_color'] ?: '#c4a35a') : '' ?>">
                  <?= e($t['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="category" value="<?= (int) $cat ?>">
            <input type="hidden" name="type" value="<?= (int) $type ?>">
          </div>
          <div class="col-md-2">
            <button class="btn btn-ct w-100" type="submit">Search</button>
          </div>
        </div>
      </form>

      <?php if (!$destRows && !$bizRows): ?>
        <div class="glass p-4 text-muted">No places found. Try another search.</div>
      <?php else: ?>
        <?php if ($featured && $cat === 0 && $type === 0 && $q === ''): ?>
          <?php
            foreach ($featured as &$featuredPlace) {
                $featuredPlace['kind'] = 'destination';
            }
            unset($featuredPlace);
            tourist_place_rail('Featured in Ormoc', 'rail-featured', $featured);
          ?>
        <?php endif; ?>
        <?php foreach ($byCategory as $group): ?>
          <?php
            foreach ($group['items'] as &$item) {
                $item['kind'] = 'destination';
            }
            unset($item);
            tourist_place_rail($group['name'], 'rail-cat-' . $group['id'], $group['items'], $group['color']);
          ?>
        <?php endforeach; ?>
        <?php foreach ($byType as $group): ?>
          <?php
            foreach ($group['items'] as &$item) {
                $item['kind'] = 'business';
            }
            unset($item);
            tourist_place_rail($group['name'], 'rail-biz-' . $group['id'], $group['items'], $group['color']);
          ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php
$extraScripts = '<script src="' . APP_URL . '/assets/js/place-rails.js?v='
  . (@filemtime(ROOT_PATH . '/assets/js/place-rails.js') ?: time()) . '"></script>'
  . '<script>window.CulTourFav={csrf:' . json_encode(csrf_token())
  . ',url:' . json_encode(APP_URL . '/api/favorites/toggle_web.php') . '};</script>'
  . '<script src="' . APP_URL . '/assets/js/fav-heart.js?v='
  . (@filemtime(ROOT_PATH . '/assets/js/fav-heart.js') ?: time()) . '"></script>';
require __DIR__ . '/../includes/footer.php';
