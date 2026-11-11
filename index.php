<?php
require_once __DIR__ . '/includes/auth.php';
ensure_password_schema();
start_app_session();
try_remember_login();
$user = current_user();
if ($user) {
    if ($setup = account_setup_url($user)) {
        redirect($setup);
    }
    redirect(role_dashboard((int) $user['role_id']));
}

$featured = [];
try {
    $featured = db()->query(
        "SELECT id, name, location, cover_image, description
         FROM destinations WHERE status='published' AND is_featured=1
         ORDER BY name LIMIT 6"
    )->fetchAll() ?: [];
} catch (Throwable $e) {
    $featured = [];
}

$heroSlides = [];
foreach ($featured as $f) {
    if (empty($f['cover_image'])) {
        continue;
    }
    $heroSlides[] = [
        'src' => APP_URL . '/uploads/' . ltrim((string) $f['cover_image'], '/'),
        'name' => (string) ($f['name'] ?? ''),
        'location' => (string) ($f['location'] ?? ''),
    ];
}
if (!$heroSlides) {
    $heroSlides[] = [
        'src' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=2000&q=80',
        'name' => '',
        'location' => '',
    ];
}

$multiHero = count($heroSlides) > 1;
$wallPlaces = $featured;
try {
    if (function_exists('ensure_business_listing_schema')) {
        ensure_business_listing_schema();
    }
    $wallBiz = db()->query(
        "SELECT id, name, address AS location, cover_image
         FROM businesses
         WHERE approval_status='approved' AND status='active'
         ORDER BY name"
    )->fetchAll() ?: [];
    $allSites = db()->query(
        "SELECT id, name, location, cover_image
         FROM destinations WHERE status='published'
         ORDER BY name"
    )->fetchAll() ?: [];
    $wallPlaces = [];
    $n = max(count($allSites), count($wallBiz));
    for ($i = 0; $i < $n; $i++) {
        if (isset($allSites[$i])) {
            $wallPlaces[] = $allSites[$i];
        }
        if (isset($wallBiz[$i])) {
            $wallPlaces[] = $wallBiz[$i];
        }
    }
} catch (Throwable $e) {
    $wallPlaces = $featured;
}
$wallCols = [];
for ($c = 0; $c < 5; $c++) {
    $col = $wallPlaces;
    if ($col) {
        shuffle($col);
    }
    $wallCols[] = $col;
}

$pageTitle = 'Welcome';
$bodyClass = 'landing-page';
require __DIR__ . '/includes/header.php';
?>
<section class="landing-hero" id="landingHero" aria-roledescription="carousel" aria-label="Featured Ormoc places">
  <div class="landing-hero__slides">
    <?php foreach ($heroSlides as $i => $slide): ?>
      <div class="landing-hero__slide<?= $i === 0 ? ' is-active' : '' ?>"
           data-hero-slide
           data-name="<?= e($slide['name']) ?>"
           data-location="<?= e($slide['location']) ?>"
           style="background-image:url('<?= e($slide['src']) ?>')"
           role="group"
           aria-roledescription="slide"
           aria-label="<?= e(($slide['name'] !== '' ? $slide['name'] : 'Ormoc') . ' (' . ($i + 1) . ' of ' . count($heroSlides) . ')') ?>"
           aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"></div>
    <?php endforeach; ?>
  </div>

  <div class="landing-hero__inner">
    <div class="landing-hero__bar">
      <span class="landing-hero__org">Ormoc City Tourism Office</span>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-light" href="<?= APP_URL ?>/login.php">Login</a>
      </div>
    </div>
    <div class="landing-hero__body fade-up">
      <div class="brand">CulTour</div>
      <h1 class="landing-hero__headline">Ormoc, mapped for your next day out</h1>
      <p class="landing-hero__lead">Discover places on the city map, build a day tour, and book a licensed guide — in one tourism system.</p>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-ct btn-lg" href="<?= APP_URL ?>/register.php">Create account</a>
      </div>
      <?php if ($multiHero): ?>
        <div class="landing-hero__caption" data-hero-caption>
          <span data-hero-caption-name><?= e($heroSlides[0]['name']) ?></span>
          <span class="landing-hero__caption-loc" data-hero-caption-loc<?= $heroSlides[0]['location'] === '' ? ' hidden' : '' ?>><?= e($heroSlides[0]['location']) ?></span>
        </div>
        <div class="landing-hero__dots" role="tablist" aria-label="Featured slides">
          <?php foreach ($heroSlides as $i => $slide): ?>
            <button type="button"
                    class="landing-hero__dot<?= $i === 0 ? ' is-active' : '' ?>"
                    data-hero-dot="<?= (int) $i ?>"
                    aria-label="Show <?= e($slide['name'] !== '' ? $slide['name'] : 'slide ' . ($i + 1)) ?>"
                    aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="landing-how">
  <div class="landing-how__inner">
    <div class="landing-how__head fade-up">
      <h2 class="landing-how__title">How CulTour works</h2>
      <p class="text-muted mb-0">Three simple steps from curiosity to a booked Ormoc day.</p>
    </div>
    <div class="landing-how__steps">
      <div class="landing-how__step fade-up" style="animation-delay:.05s">
        <div class="landing-how__num">01</div>
        <h3>Explore the map</h3>
        <p>Browse destinations and local businesses pinned across Ormoc.</p>
      </div>
      <div class="landing-how__step fade-up" style="animation-delay:.1s">
        <div class="landing-how__num">02</div>
        <h3>Build an itinerary</h3>
        <p>Line up stops, times, and a meetup at the city port.</p>
      </div>
      <div class="landing-how__step fade-up" style="animation-delay:.15s">
        <div class="landing-how__num">03</div>
        <h3>Book a guide</h3>
        <p>Request a licensed tour guide and manage your booking in CulTour.</p>
      </div>
    </div>
  </div>
</section>

<section class="landing-flow" aria-label="Places in Ormoc">
  <div class="landing-flow__cols">
    <?php foreach ($wallCols as $col): ?>
      <?php
        if (!$col) { continue; }
        while (count($col) < 4) {
            $col = array_merge($col, $col);
        }
      ?>
      <div class="landing-flow__col">
        <div class="landing-flow__track">
          <?php foreach ($col as $place):
            $src = !empty($place['cover_image'])
                ? APP_URL . '/uploads/' . ltrim((string) $place['cover_image'], '/')
                : '';
            $size = ['is-s', 'is-m', 'is-l'][random_int(0, 2)];
          ?>
            <div class="landing-flow__card <?= $size ?>"<?= $src !== '' ? ' style="background-image:url(\'' . e($src) . '\')"' : '' ?>>
              <span class="landing-flow__label"><?= e((string) ($place['name'] ?? '')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="landing-flow__overlay">
    <div class="landing-cta__inner">
      <h2 class="landing-cta__title">Ready for Ormoc?</h2>
      <p class="landing-cta__lead">Create a free CulTour account to save favorites, plan itineraries, and book guides.</p>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <a class="btn btn-light btn-lg" href="<?= APP_URL ?>/register.php">Create account</a>
        <a class="btn btn-outline-light btn-lg" href="<?= APP_URL ?>/login.php">Login</a>
      </div>
    </div>
  </div>
</section>
<?php
if ($multiHero) {
    ob_start();
    ?>
<script>
(function () {
  var root = document.getElementById('landingHero');
  if (!root) return;
  var slides = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
  if (slides.length < 2) return;

  var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
  var nameEl = root.querySelector('[data-hero-caption-name]');
  var locEl = root.querySelector('[data-hero-caption-loc]');
  var idx = 0;
  var timer = null;
  var touchX = null;
  var INTERVAL = 2000;

  function show(next) {
    idx = (next + slides.length) % slides.length;
    slides.forEach(function (el, i) {
      var on = i === idx;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-hidden', on ? 'false' : 'true');
    });
    dots.forEach(function (dot, i) {
      var on = i === idx;
      dot.classList.toggle('is-active', on);
      dot.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    var name = slides[idx].getAttribute('data-name') || '';
    var loc = slides[idx].getAttribute('data-location') || '';
    if (nameEl) nameEl.textContent = name;
    if (locEl) {
      if (loc) {
        locEl.textContent = loc;
        locEl.hidden = false;
      } else {
        locEl.textContent = '';
        locEl.hidden = true;
      }
    }
  }

  function next() { show(idx + 1); }
  function prev() { show(idx - 1); }
  function start() {
    stop();
    timer = window.setInterval(next, INTERVAL);
  }
  function stop() {
    if (timer) window.clearInterval(timer);
    timer = null;
  }

  dots.forEach(function (dot) {
    dot.addEventListener('click', function () {
      var n = parseInt(dot.getAttribute('data-hero-dot'), 10);
      if (!isNaN(n)) show(n);
      start();
    });
  });

  root.addEventListener('touchstart', function (e) {
    if (!e.changedTouches || !e.changedTouches[0]) return;
    touchX = e.changedTouches[0].clientX;
  }, { passive: true });

  root.addEventListener('touchend', function (e) {
    if (touchX == null || !e.changedTouches || !e.changedTouches[0]) return;
    var dx = e.changedTouches[0].clientX - touchX;
    touchX = null;
    if (Math.abs(dx) > 40) {
      if (dx < 0) next();
      else prev();
      start();
    }
  }, { passive: true });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop();
    else start();
  });

  start();
})();
</script>
    <?php
    $extraScripts = ($extraScripts ?? '') . ob_get_clean();
}
ob_start();
?>
<script>
(function () {
  var sizes = ['is-s', 'is-m', 'is-l'];
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function step(el) {
    return el.offsetHeight + (parseFloat(getComputedStyle(el).marginBottom) || 0);
  }
  function paint(card) {
    sizes.forEach(function (s) { card.classList.remove(s); });
    card.classList.add(sizes[Math.floor(Math.random() * sizes.length)]);
  }
  document.querySelectorAll('.landing-flow__col').forEach(function (col, index) {
    var track = col.querySelector('.landing-flow__track');
    if (!track || !track.firstElementChild) return;
    var down = index % 2 === 1;
    var guard = 0;
    while (track.scrollHeight < col.clientHeight * 2.2 && guard < 8) {
      Array.prototype.slice.call(track.children).forEach(function (card) {
        var clone = card.cloneNode(true);
        paint(clone);
        track.appendChild(clone);
      });
      guard++;
    }
    if (reduced) return;
    var y = 0;
    if (down) {
      var seed = track.lastElementChild;
      track.insertBefore(seed, track.firstElementChild);
      y = -step(seed);
    }
    var speed = 0.55;
    function frame() {
      var edge = down ? track.lastElementChild : track.firstElementChild;
      if (!edge) return;
      var h = step(edge);
      if (down) {
        y += speed;
        if (y >= 0) {
          var over = y;
          paint(edge);
          track.insertBefore(edge, track.firstElementChild);
          y = -step(edge) + over;
        }
      } else {
        y -= speed;
        if (-y >= h) {
          paint(edge);
          y += h;
          track.appendChild(edge);
        }
      }
      track.style.transform = 'translateY(' + y + 'px)';
      requestAnimationFrame(frame);
    }
    track.style.transform = 'translateY(' + y + 'px)';
    requestAnimationFrame(frame);
  });
})();
</script>
<?php
$extraScripts = ($extraScripts ?? '') . ob_get_clean();
require __DIR__ . '/includes/footer.php';
?>
