<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/business_engagement.php';
$user = require_login(ROLE_TOURIST);
ensure_business_favorites_schema();
$pageTitle = 'Local Businesses';
$pageHeading = 'Local Businesses';
$pageSub = 'CulTour · Ormoc City';
$activeNav = 'businesses';

ensure_business_type_pin_colors();

$types = db()->query('SELECT id, name, pin_color FROM business_types ORDER BY name')->fetchAll();
$q = trim($_GET['q'] ?? '');
$type = (int) ($_GET['type'] ?? 0);

$sql = "SELECT b.*, bt.name AS type_name, bt.pin_color,
               (SELECT COUNT(*) FROM business_photos bp WHERE bp.business_id = b.id) AS photo_count
        FROM businesses b
        JOIN business_types bt ON bt.id = b.business_type_id
        WHERE b.approval_status = 'approved' AND b.status = 'active'";
$params = [];
if ($q !== '') {
    $sql .= ' AND (b.name LIKE ? OR b.address LIKE ? OR b.description LIKE ? OR bt.name LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
}
if ($type) {
    $sql .= ' AND b.business_type_id = ?';
    $params[] = $type;
}
$sql .= ' ORDER BY bt.name ASC, b.name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = array_values(array_filter($stmt->fetchAll() ?: [], 'business_itinerary_ready'));

$favStmt = db()->prepare('SELECT business_id FROM business_favorites WHERE user_id=?');
$favStmt->execute([(int) $user['id']]);
$favBizIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$byType = [];
foreach ($rows as $b) {
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

function tourist_biz_excerpt(?string $text, int $max = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
    if ($text === '') {
        return 'Discover this local Ormoc business — open for hours, contact, and more.';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }
    return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
}

/**
 * @param array $b business row
 * @param bool $active
 */
function tourist_biz_rail_card(array $b, bool $active = false): void
{
    global $favBizIds;
    $href = APP_URL . '/tourist/business.php?id=' . (int) $b['id'];
    $cover = !empty($b['cover_image'])
        ? 'background-image:url(' . APP_URL . '/uploads/' . e($b['cover_image']) . ')'
        : '';
    $excerpt = tourist_biz_excerpt($b['description'] ?? '');
    $contact = trim(implode(' · ', array_filter([
        trim((string) ($b['phone'] ?? '')),
        trim((string) ($b['email'] ?? '')),
    ])));
    $isFav = isset($favBizIds[(int) $b['id']]);
    ?>
    <article class="ct-rail__card<?= $active ? ' is-active' : '' ?>"
             data-href="<?= e($href) ?>"
             tabindex="0"
             role="button"
             aria-label="<?= e($b['name']) ?>">
      <div class="ct-rail__poster" style="<?= $cover ?>">
        <div class="ct-rail__shade"></div>
        <div class="ct-rail__fav-wrap">
          <button type="button" class="ct-rail__fav<?= $isFav ? ' is-on' : '' ?>"
                  data-fav-btn data-fav-type="business" data-fav-id="<?= (int) $b['id'] ?>"
                  aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                  aria-label="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>">
            <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
          </button>
          <p class="ct-rail__fav-note" data-fav-note></p>
        </div>
        <div class="ct-rail__meta">
          <div class="ct-rail__kicker"><?= e($b['type_name'] ?? 'Business') ?></div>
          <div class="ct-rail__title"><?= e($b['name']) ?></div>
          <div class="ct-rail__sub"><?= e($b['address'] ?? '') ?></div>
          <p class="ct-rail__desc"><?= e($excerpt) ?></p>
          <div class="ct-rail__stats">
            <?php if ($contact !== ''): ?>
              <span><?= e($contact) ?></span>
            <?php else: ?>
              <span>Local business</span>
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
function tourist_biz_rail(string $title, string $railId, array $items, ?string $accent = null): void
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
        <button type="button" class="ct-rail__arrow ct-rail__arrow--prev" aria-label="Previous business">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ct-rail__track" id="<?= e($railId) ?>">
          <?php foreach ($items as $i => $b): ?>
            <?php tourist_biz_rail_card($b, $i === 0); ?>
          <?php endforeach; ?>
        </div>
        <button type="button" class="ct-rail__arrow ct-rail__arrow--next" aria-label="Next business">
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
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search businesses, addresses, keywords…">
          </div>
          <div class="col-md-5">
            <div class="ct-chip-row" role="list">
              <a class="ct-chip <?= $type === 0 ? 'is-active' : '' ?>" href="?q=<?= urlencode($q) ?>" role="listitem">All</a>
              <?php foreach ($types as $t): ?>
                <a class="ct-chip <?= $type === (int) $t['id'] ? 'is-active' : '' ?>"
                   href="?q=<?= urlencode($q) ?>&amp;type=<?= (int) $t['id'] ?>"
                   role="listitem"
                   style="<?= $type === (int) $t['id'] ? '--chip-accent:' . e($t['pin_color'] ?: '#c4a35a') : '' ?>">
                  <?= e($t['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="type" value="<?= (int) $type ?>">
          </div>
          <div class="col-md-2">
            <button class="btn btn-ct w-100" type="submit">Search</button>
          </div>
        </div>
      </form>

      <div data-live-self="tourist-businesses">
      <?php if (!$rows): ?>
        <div class="glass p-4 text-muted">No businesses found. Try another search or type.</div>
      <?php else: ?>
        <?php foreach ($byType as $group): ?>
          <?php
          tourist_biz_rail(
              $group['name'],
              'rail-biz-' . $group['id'],
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
