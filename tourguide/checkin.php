<?php
/**
 * Tour-day stop check-in: photo proof at each planned itinerary stop.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/live.php';
require_once __DIR__ . '/../includes/map_helpers.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_booking_payment_schema();
ensure_booking_stop_checkin_schema();

$pageTitle = 'Stop check-in';
$pageHeading = "Today's stops";
$pageSub = 'Check in at each stop with a photo. This only works on the tour day.';
$activeNav = 'checkin';

$profile = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$gid = (int) $profile->fetchColumn();
$today = app_today();
$fallback = APP_URL . '/tourguide/checkin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $returnTo = booking_stop_checkin_safe_return($_POST['return'] ?? '', $fallback);
    if ($gid < 1) {
        flash('error', 'Your tour guide profile is not ready yet.');
        redirect($returnTo);
    }
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $itemId = (int) ($_POST['itinerary_item_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');
    $st->execute([$bookingId]);
    $booking = $st->fetch();
    if (!$booking) {
        flash('error', 'Booking not found.');
        redirect($returnTo);
    }
    $posted = (string) ($_POST['action'] ?? 'checkin');
    if ($posted === 'start_tour') {
        $res = booking_tour_start_submit($booking, $gid);
    } elseif ($posted === 'start_stop') {
        $res = booking_stop_start_submit($booking, $gid, $itemId);
    } elseif ($posted === 'skip_stop') {
        $res = booking_stop_skip_submit(
            $booking,
            $gid,
            $itemId,
            (string) ($_POST['reason_preset'] ?? ''),
            (string) ($_POST['reason_detail'] ?? '')
        );
    } elseif ($posted === 'end_tour') {
        $res = booking_guide_end_day($booking, $gid, (string) ($_POST['visit_date'] ?? ''));
    } else {
        $file = $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'name' => '', 'tmp_name' => ''];
        $res = booking_stop_checkin_submit($booking, $gid, $itemId, is_array($file) ? $file : []);
    }
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    if ($posted === 'end_tour' && !empty($res['ok'])) {
        redirect(APP_URL . '/tourguide/checkin.php');
    }
    redirect($returnTo . (str_contains($returnTo, '#') ? '' : '#booking-' . $bookingId));
}

$live = live_guide_checkin_payload($gid);

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
map_leaflet_head();
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/tourguide/schedule.php?date=<?= e($today) ?>">Open today’s schedule</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/tourguide/bookings.php">Bookings</a>
      </div>

      <div data-live-page="guide_checkin" data-live-version="<?= e($live['version']) ?>">
        <?= $live['html'] ?>
      </div>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<?php
$extraScripts = map_leaflet_scripts() . <<<'HTML'
<script>
(function () {
  function parkEndTourModals(root) {
    (root || document).querySelectorAll('[data-tour-end-modal]').forEach(function (modal) {
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }
    });
  }
  function mount(root) {
    parkEndTourModals(root || document);
    if (!window.CulTourMap || !CulTourMap.renderItineraryRouteMap) return;
    (root || document).querySelectorAll('[data-checkin-map]').forEach(function (el) {
      var box = el.parentNode;
      if (!box) return;
      var stopsEl = box.querySelector('[data-checkin-map-stops]');
      var meetupEl = box.querySelector('[data-checkin-map-meetup]');
      var stops = [];
      var meetup = null;
      try { stops = JSON.parse(stopsEl ? stopsEl.textContent : '[]'); } catch (e) { stops = []; }
      try { meetup = JSON.parse(meetupEl ? meetupEl.textContent : 'null'); } catch (e) { meetup = null; }
      if (el._leaflet_map) {
        try { el._leaflet_map.remove(); } catch (err) {}
        el._leaflet_map = null;
      }
      var board = el.closest('[data-checkin-board]');
      function showStop(id) {
        if (!board) return;
        var empty = board.querySelector('[data-checkin-empty]');
        var items = board.querySelectorAll('[data-stop-id]');
        var found = false;
        items.forEach(function (item) {
          if (item.getAttribute('data-stop-id') === 'end') return;
          var on = String(item.getAttribute('data-stop-id')) === String(id);
          item.classList.toggle('is-on', on);
          if (on) found = true;
        });
        if (empty) empty.classList.toggle('is-hidden', found);
        var endItem = board.querySelector('[data-stop-id="end"]');
        if (endItem && !found) endItem.classList.add('is-on');
      }
      CulTourMap.renderItineraryRouteMap(el, stops, {
        dayCount: 1,
        meetup: meetup || undefined,
        zoom: 13,
        largePins: true,
        onStopClick: function (stop) {
          if (stop && stop.id != null) showStop(stop.id);
        }
      });
      var next = board && board.querySelector('[data-checkin-next]');
      if (next) showStop(next.getAttribute('data-stop-id'));
    });
  }
  mount(document);
  document.addEventListener('cultour:live-replaced', function (e) {
    mount(e.target || document);
  });
})();
</script>
HTML;
$jsV = @filemtime(ROOT_PATH . '/assets/js/ticket-scan.js') ?: time();
$extraScripts .= '<script src="' . e(APP_URL . '/assets/js/ticket-scan.js?v=' . $jsV) . '"></script>';
require __DIR__ . '/../includes/footer.php';
?>
