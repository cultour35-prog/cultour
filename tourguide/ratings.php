<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_itinerary_items_schema();
$pageTitle = 'Ratings';
$pageHeading = 'Ratings & Completed Tours';
$pageSub = 'Click a tour to see its details';
$activeNav = 'ratings';

$profile = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([$user['id']]);
$gid = (int) $profile->fetchColumn();

$live = live_guide_ratings_payload($gid);
$openBookingId = (int) ($_GET['booking'] ?? 0);

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <div data-live-page="guide_ratings" data-live-version="<?= e($live['version']) ?>">
        <?= $live['html'] ?>
      </div>

      <div id="guideTourOverlay" class="admin-biz-proof-overlay" hidden data-live-slot="ratings_overlay">
        <?= $live['slots']['ratings_overlay'] ?? '' ?>
      </div>
<?php
$openJs = (int) $openBookingId;
$extraScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('guideTourOverlay');
  if (!overlay || overlay._guideTourBound) return;
  overlay._guideTourBound = true;
  document.body.appendChild(overlay);
  function closeTour() {
    overlay.hidden = true;
    overlay.querySelectorAll('[data-tour-card]').forEach(function (el) { el.hidden = true; });
    document.body.classList.remove('admin-biz-proof-open');
  }
  function openTour(id) {
    var card = overlay.querySelector('[data-tour-card="' + id + '"]');
    if (!card) return;
    overlay.querySelectorAll('[data-tour-card]').forEach(function (el) { el.hidden = true; });
    card.hidden = false;
    overlay.hidden = false;
    document.body.classList.add('admin-biz-proof-open');
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-open-tour]');
    if (!btn) return;
    openTour(btn.getAttribute('data-open-tour'));
  });
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay || e.target.closest('[data-close-tour]')) closeTour();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeTour();
  });
  if ({$openJs} > 0) openTour(String({$openJs}));
});
</script>
HTML;
require __DIR__ . '/../includes/guide_shell_end.php';
require __DIR__ . '/../includes/footer.php';
?>
