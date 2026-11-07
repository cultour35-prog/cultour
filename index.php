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

$featuredCards = array_slice($featured, 0, 4);
$multiHero = count($heroSlides) > 1;

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

<?php if ($featuredCards): ?>
<section class="landing-featured">
  <div class="landing-featured__inner">
    <div class="landing-featured__head fade-up">
      <div>
        <h2 class="landing-featured__title">Places of interest</h2>
        <p class="text-muted small mb-0">A taste of Ormoc — sign in for details, itineraries, and bookings.</p>
      </div>
    </div>
    <div class="row g-3">
      <?php foreach ($featuredCards as $i => $f): ?>
        <div class="col-md-6 col-lg-3 fade-up" style="animation-delay:<?= $i * 0.05 ?>s">
          <a class="public-card text-decoration-none h-100 d-block" href="<?= APP_URL ?>/login.php?next=<?= urlencode('/tourist/destination.php?id=' . (int) $f['id']) ?>">
            <div class="public-card__img" style="<?= !empty($f['cover_image']) ? 'background-image:url(' . APP_URL . '/uploads/' . e($f['cover_image']) . ')' : '' ?>"></div>
            <div class="public-card__body">
              <div class="fw-semibold"><?= e($f['name']) ?></div>
              <div class="small text-muted"><?= e($f['location'] ?? '') ?></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="landing-cta">
  <div class="landing-cta__inner fade-up">
    <h2 class="landing-cta__title">Ready for Ormoc?</h2>
    <p class="landing-cta__lead">Create a free CulTour account to save favorites, plan itineraries, and book guides.</p>
    <div class="d-flex flex-wrap justify-content-center gap-2">
      <a class="btn btn-light btn-lg" href="<?= APP_URL ?>/register.php">Create account</a>
      <a class="btn btn-outline-light btn-lg" href="<?= APP_URL ?>/login.php">Login</a>
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
require __DIR__ . '/includes/footer.php';
?>
