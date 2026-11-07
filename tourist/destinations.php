<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/business_engagement.php';
$user = require_login(ROLE_TOURIST);
$pageTitle = 'Destinations';
$pageHeading = 'Destinations';
$pageSub = 'CulTour · Ormoc City';
$activeNav = 'destinations';

ensure_destination_coords();
ensure_category_pin_colors();

$categories = db()->query(
    "SELECT id, name, pin_color FROM categories
     WHERE LOWER(TRIM(name)) NOT IN ('meet up', 'meetup', 'meet-up')
     ORDER BY name"
)->fetchAll();
$q = trim($_GET['q'] ?? '');
$cat = (int) ($_GET['category'] ?? 0);
$openNow = !empty($_GET['open_now']);
$sort = (string) ($_GET['sort'] ?? 'name');
if (!in_array($sort, ['name', 'rating'], true)) {
    $sort = 'name';
}

$sql = "SELECT d.*, c.name AS category_name, c.pin_color
        FROM destinations d
        JOIN categories c ON c.id = d.category_id
        WHERE d.status = 'published'
          AND LOWER(TRIM(c.name)) NOT IN ('meet up', 'meetup', 'meet-up')";
$params = [];
if ($q !== '') {
    $sql .= ' AND (d.name LIKE ? OR d.location LIKE ? OR d.description LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
if ($cat) {
    $sql .= ' AND d.category_id = ?';
    $params[] = $cat;
}
if ($sort === 'rating') {
    $sql .= ' ORDER BY d.avg_rating DESC, d.name ASC';
} else {
    $sql .= ' ORDER BY c.name ASC, d.is_featured DESC, d.name ASC';
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];
if ($openNow) {
    $rows = array_values(array_filter($rows, static function ($d) {
        return place_is_open_now($d['opening_hours'] ?? null);
    }));
}
$favStmt = db()->prepare('SELECT destination_id FROM favorites WHERE user_id=?');
$favStmt->execute([(int) $user['id']]);
$favDestIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$featured = [];
$byCategory = [];
foreach ($rows as $d) {
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

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';

function tourist_dest_excerpt(?string $text, int $max = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
    if ($text === '') {
        return 'Explore this Ormoc destination — open for full details, hours, and reviews.';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }
    return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
}

/**
 * @param array $d destination row
 * @param bool $active
 */
function tourist_dest_rail_card(array $d, bool $active = false): void
{
    global $favDestIds;
    $href = APP_URL . '/tourist/destination.php?id=' . (int) $d['id'];
    $cover = !empty($d['cover_image'])
        ? 'background-image:url(' . APP_URL . '/uploads/' . e($d['cover_image']) . ')'
        : '';
    $excerpt = tourist_dest_excerpt($d['description'] ?? '');
    $isFav = isset($favDestIds[(int) $d['id']]);
    ?>
    <article class="ct-rail__card<?= $active ? ' is-active' : '' ?>"
             data-href="<?= e($href) ?>"
             tabindex="0"
             role="button"
             aria-label="<?= e($d['name']) ?>">
      <div class="ct-rail__poster" style="<?= $cover ?>">
        <div class="ct-rail__shade"></div>
        <div class="ct-rail__fav-wrap">
          <button type="button" class="ct-rail__fav<?= $isFav ? ' is-on' : '' ?>"
                  data-fav-btn data-fav-type="destination" data-fav-id="<?= (int) $d['id'] ?>"
                  aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                  aria-label="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>">
            <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
          </button>
          <p class="ct-rail__fav-note" data-fav-note></p>
        </div>
        <div class="ct-rail__meta">
          <div class="ct-rail__kicker"><?= e($d['category_name'] ?? 'Destination') ?></div>
          <div class="ct-rail__title"><?= e($d['name']) ?></div>
          <div class="ct-rail__sub"><?= e($d['location'] ?? '') ?></div>
          <p class="ct-rail__desc"><?= e($excerpt) ?></p>
          <div class="ct-rail__stats">
            <span><?= e(format_money($d['entrance_fee'])) ?></span>
            <span>·</span>
            <span>★ <?= e($d['avg_rating']) ?></span>
            <?php if (!empty($d['review_count'])): ?>
              <span>·</span>
              <span><?= (int) $d['review_count'] ?> review<?= (int) $d['review_count'] === 1 ? '' : 's' ?></span>
            <?php endif; ?>
          </div>
          <span class="ct-rail__cta">View details <i class="bi bi-arrow-right"></i></span>
        </div>
      </div>
    </article>
    <?php
}

/**
 * @param string $title
 * @param string $railId
 * @param array $items
 * @param string|null $accent
 */
function tourist_dest_rail(string $title, string $railId, array $items, ?string $accent = null): void
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
        <button type="button" class="ct-rail__arrow ct-rail__arrow--prev" aria-label="Previous destination">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ct-rail__track" id="<?= e($railId) ?>">
          <?php foreach ($items as $i => $d): ?>
            <?php tourist_dest_rail_card($d, $i === 0); ?>
          <?php endforeach; ?>
        </div>
        <button type="button" class="ct-rail__arrow ct-rail__arrow--next" aria-label="Next destination">
          <i class="bi bi-chevron-right"></i>
        </button>
      </div>
    </section>
    <?php
}
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>
    <div class="page-content page-content--rails">
      <form class="ct-rail-filters glass p-3 mb-4" method="get">
        <div class="row g-2 align-items-center">
          <div class="col-md-5">
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search destinations, places, keywords…">
          </div>
          <div class="col-md-5">
            <div class="ct-chip-row" role="list">
              <a class="ct-chip <?= $cat === 0 ? 'is-active' : '' ?>" href="?q=<?= urlencode($q) ?>" role="listitem">All</a>
              <?php foreach ($categories as $c): ?>
                <a class="ct-chip <?= $cat === (int) $c['id'] ? 'is-active' : '' ?>"
                   href="?q=<?= urlencode($q) ?>&amp;category=<?= (int) $c['id'] ?>"
                   role="listitem"
                   style="<?= $cat === (int) $c['id'] ? '--chip-accent:' . e($c['pin_color'] ?: '#0e8a7a') : '' ?>">
                  <?= e($c['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="category" value="<?= (int) $cat ?>">
          </div>
          <div class="col-md-2">
            <button class="btn btn-ct w-100" type="submit">Search</button>
          </div>
        </div>
      </form>

      <div data-live-self="tourist-destinations">
      <?php if (!$rows): ?>
        <div class="glass p-4 text-muted">No destinations found. Try another search or category.</div>
      <?php else: ?>
        <?php if ($featured && $cat === 0 && $q === ''): ?>
          <?php tourist_dest_rail('Featured in Ormoc', 'rail-featured', $featured); ?>
        <?php endif; ?>

        <?php foreach ($byCategory as $group): ?>
          <?php
          tourist_dest_rail(
              $group['name'],
              'rail-cat-' . $group['id'],
              $group['items'],
              $group['color']
          );
          ?>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  var SLIDE_MS = 620;

  function initRail(rail) {
    var viewport = rail.querySelector('.ct-rail__viewport');
    var track = rail.querySelector('.ct-rail__track');
    var prev = rail.querySelector('.ct-rail__arrow--prev');
    var next = rail.querySelector('.ct-rail__arrow--next');
    if (!track) return;

    var busy = false;

    function cards() {
      return Array.prototype.slice.call(track.querySelectorAll('.ct-rail__card'));
    }

    function gapPx() {
      return parseFloat(getComputedStyle(track).gap) || 16;
    }

    function markFirstActive() {
      cards().forEach(function (c, i) {
        c.classList.toggle('is-active', i === 0);
      });
      track.scrollLeft = 0;
    }

    function unlock() {
      if (viewport) viewport.classList.remove('is-locked');
      busy = false;
    }

    function afterLayout(fn) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(fn);
      });
    }

    function waitSlide(onDone) {
      var done = false;
      function finish(e) {
        if (e) {
          if (e.target !== track) return;
          if (e.propertyName && e.propertyName !== 'transform') return;
        }
        if (done) return;
        done = true;
        track.removeEventListener('transitionend', finish);
        onDone();
      }
      track.addEventListener('transitionend', finish);
      window.setTimeout(finish, SLIDE_MS);
    }

    function slideNextOnce(then) {
      var list = cards();
      if (list.length < 2) {
        if (then) then();
        return;
      }
      // Slide by the inactive width the outgoing card will settle to
      var delta = -Math.round(list[1].getBoundingClientRect().width + gapPx());
      list[0].classList.remove('is-active');
      list[1].classList.add('is-active');
      afterLayout(function () {
        track.classList.add('is-sliding');
        track.style.transform = 'translateX(' + delta + 'px)';
        waitSlide(function () {
          track.classList.remove('is-sliding');
          track.style.transition = 'none';
          track.style.transform = 'translateX(0)';
          track.appendChild(cards()[0]);
          markFirstActive();
          void track.offsetWidth;
          track.style.transition = '';
          if (then) then();
        });
      });
    }

    function rotate(dir) {
      var list = cards();
      if (busy || list.length < 2) return;
      busy = true;
      if (viewport) viewport.classList.add('is-locked');

      if (dir > 0) {
        slideNextOnce(unlock);
        return;
      }

      var last = list[list.length - 1];
      var back = -Math.round(last.getBoundingClientRect().width + gapPx());
      list[0].classList.remove('is-active');
      track.style.transition = 'none';
      track.insertBefore(last, track.firstChild);
      track.style.transform = 'translateX(' + back + 'px)';
      void track.offsetWidth;
      cards()[0].classList.add('is-active');
      afterLayout(function () {
        track.classList.add('is-sliding');
        track.style.transition = '';
        track.style.transform = 'translateX(0)';
        waitSlide(function () {
          track.classList.remove('is-sliding');
          track.style.transform = 'translateX(0)';
          markFirstActive();
          unlock();
        });
      });
    }

    function bringToFront(steps) {
      if (busy || steps <= 0) return;
      busy = true;
      if (viewport) viewport.classList.add('is-locked');

      function step() {
        if (steps <= 0) {
          markFirstActive();
          unlock();
          return;
        }
        slideNextOnce(function () {
          steps -= 1;
          step();
        });
      }
      step();
    }

    if (prev) prev.addEventListener('click', function () { rotate(-1); });
    if (next) next.addEventListener('click', function () { rotate(1); });

    track.addEventListener('click', function (e) {
      if (e.target.closest('[data-fav-btn]')) return;
      var card = e.target.closest('.ct-rail__card');
      if (!card || !track.contains(card)) return;
      var list = cards();
      var i = list.indexOf(card);
      if (i < 0) return;
      if (i === 0) {
        var href = card.getAttribute('data-href');
        if (href) window.location.href = href;
        return;
      }
      bringToFront(i);
    });

    track.addEventListener('keydown', function (e) {
      if (e.target.closest('[data-fav-btn]')) return;
      var card = e.target.closest('.ct-rail__card');
      if (!card) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        card.click();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        rotate(1);
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        rotate(-1);
      }
    });

    markFirstActive();
  }

  document.querySelectorAll('[data-focus-rail]').forEach(initRail);
  document.addEventListener('cultour:live-replaced', function (e) {
    if (e.target && e.target.querySelectorAll) e.target.querySelectorAll('[data-focus-rail]').forEach(initRail);
  });
})();
</script>
HTML;
$extraScripts .= '<script>window.CulTourFav={csrf:' . json_encode(csrf_token())
  . ',url:' . json_encode(APP_URL . '/api/favorites/toggle_web.php') . '};</script>'
  . '<script src="' . APP_URL . '/assets/js/fav-heart.js?v='
  . (@filemtime(ROOT_PATH . '/assets/js/fav-heart.js') ?: time()) . '"></script>';
require __DIR__ . '/../includes/footer.php';
?>
