<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOUR_GUIDE);
$pageTitle = 'Availability';
$pageHeading = 'Availability & Calendar';
$activeNav = 'availability';

$profile = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$profile = $profile->fetch();
if (!$profile) {
    flash('error', 'Guide profile not found.');
    redirect(role_dashboard(ROLE_TOUR_GUIDE));
}
$gid = (int) $profile['id'];
$dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'weekly') {
        $result = guide_save_weekly_availability_from_post($gid, $_POST, $dayLabels);
        if ($result['errors']) {
            flash('error', implode(' ', $result['errors']));
        } elseif ($result['saved'] < 1) {
            flash('warning', 'Availability cleared. Select at least one day so tourists can book you.');
        } else {
            flash('success', 'Weekly availability saved. Pending requests still need Accept or Decline on Bookings.');
        }
        redirect(APP_URL . '/tourguide/availability.php');
    }

    if ($action === 'block') {
        $blockDate = normalize_tour_date((string) ($_POST['block_date'] ?? ''));
        if ($blockDate === null) {
            flash('error', 'Choose a valid date to block.');
            redirect(APP_URL . '/tourguide/availability.php');
        }
        db()->prepare('INSERT INTO guide_calendar_blocks (guide_id, block_date, reason) VALUES (?,?,?)')
            ->execute([$gid, $blockDate, trim((string) ($_POST['reason'] ?? 'Unavailable'))]);
        flash('success', 'Date blocked.');
        redirect(APP_URL . '/tourguide/availability.php');
    }

    if ($action === 'unblock') {
        db()->prepare('DELETE FROM guide_calendar_blocks WHERE id=? AND guide_id=?')
            ->execute([(int) ($_POST['id'] ?? 0), $gid]);
        flash('success', 'Date unblocked.');
        redirect(APP_URL . '/tourguide/availability.php');
    }

    redirect(APP_URL . '/tourguide/availability.php');
}

$availByDay = guide_weekly_availability_by_day($gid);
$hasAvail = guide_has_weekly_availability($gid);
$blocks = db()->prepare('SELECT * FROM guide_calendar_blocks WHERE guide_id=? ORDER BY block_date');
$blocks->execute([$gid]);
$blocks = $blocks->fetchAll() ?: [];

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('warning')): ?><div class="alert alert-warning"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <div class="row g-3 align-items-start">
        <div class="col-lg-7">
          <div class="glass p-4 guide-profile-avail<?= $hasAvail ? '' : ' is-incomplete' ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
              <div>
                <h3 class="h6 mb-1">Weekly schedule <span class="text-danger">*</span></h3>
                <div class="small text-muted">Set your own start and end time for each day you work.</div>
              </div>
              <?php if ($hasAvail): ?>
                <span class="badge bg-success">Set</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Not set</span>
              <?php endif; ?>
            </div>
            <form method="post" class="guide-profile-avail__form"><?= csrf_field() ?>
              <input type="hidden" name="action" value="weekly">
              <div class="guide-profile-avail__rows">
                <?php for ($i = 0; $i < 7; $i++): ?>
                  <?php $dayOn = !empty($availByDay[$i]['on']); ?>
                  <div class="guide-profile-avail__row<?= $dayOn ? ' is-on' : '' ?>">
                    <div class="form-check guide-profile-avail__day">
                      <input class="form-check-input" type="checkbox" name="day[]" value="<?= $i ?>" id="availDay<?= $i ?>"
                        <?= $dayOn ? 'checked' : '' ?>
                        data-avail-day-toggle>
                      <label class="form-check-label" for="availDay<?= $i ?>"><?= $dayLabels[$i] ?></label>
                    </div>
                    <div class="guide-profile-avail__times">
                      <div>
                        <label class="form-label small mb-0" for="availStart<?= $i ?>">Start</label>
                        <input type="time" class="form-control form-control-sm" id="availStart<?= $i ?>"
                               name="day_start[<?= $i ?>]"
                               value="<?= e(time_picker_value((string) $availByDay[$i]['start'], '8:00 AM')) ?>"
                               step="900"<?= $dayOn ? ' required' : ' disabled' ?>>
                      </div>
                      <div>
                        <label class="form-label small mb-0" for="availEnd<?= $i ?>">End</label>
                        <input type="time" class="form-control form-control-sm" id="availEnd<?= $i ?>"
                               name="day_end[<?= $i ?>]"
                               value="<?= e(time_picker_value((string) $availByDay[$i]['end'], '5:00 PM')) ?>"
                               step="900" data-avail-end<?= $dayOn ? ' required' : ' disabled' ?>>
                      </div>
                      <p class="guide-profile-avail__cutoff mb-0" data-avail-cutoff>
                        Same-day booking cutoff: <?= e(guide_booking_cutoff_display($availByDay[$i]['end']) ?: '—') ?>
                      </p>
                    </div>
                  </div>
                <?php endfor; ?>
              </div>
              <div class="form-text mt-2 mb-0">Each selected day uses its own start and end time. Same-day bookings close 2 hours before your end time.</div>
              <button class="btn btn-ct mt-3">Save weekly schedule</button>
            </form>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="glass p-4 mb-3">
            <h3 class="h6 mb-2">Block a date</h3>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="block">
              <div class="mb-2"><input type="date" class="form-control" name="block_date" required></div>
              <div class="mb-2"><input class="form-control" name="reason" placeholder="Reason"></div>
              <button class="btn btn-outline-secondary">Block</button>
            </form>
          </div>
          <div class="glass p-4">
            <h3 class="h6 mb-2">Blocked dates</h3>
            <?php if (!$blocks): ?>
              <div class="small text-muted">No blocked dates yet.</div>
            <?php endif; ?>
            <?php foreach ($blocks as $b): ?>
              <div class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom">
                <span class="small"><?= e(format_date($b['block_date'])) ?> · <?= e($b['reason'] ?? '') ?></span>
                <form method="post" class="m-0"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="unblock">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">×</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
<script>
(function () {
  function endToMins(raw) {
    var t = String(raw || '').trim();
    if (!t) return null;
    var m12 = t.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i);
    if (m12) {
      var h = parseInt(m12[1], 10);
      var min = m12[2] !== undefined ? parseInt(m12[2], 10) : 0;
      if (h < 1 || h > 12 || min < 0 || min > 59) return null;
      var ap = m12[3].toUpperCase();
      if (ap === 'AM') h = h === 12 ? 0 : h;
      else h = h === 12 ? 12 : h + 12;
      return h * 60 + min;
    }
    var parts = t.split(':');
    if (parts.length < 2) return null;
    var h24 = parseInt(parts[0], 10);
    var mi = parseInt(parts[1], 10);
    if (!Number.isFinite(h24) || !Number.isFinite(mi) || h24 < 0 || h24 > 23 || mi < 0 || mi > 59) return null;
    return h24 * 60 + mi;
  }
  function minsTo12h(mins) {
    mins = Math.max(0, Math.min(23 * 60 + 59, mins));
    var h24 = Math.floor(mins / 60);
    var m = mins % 60;
    var ap = h24 >= 12 ? 'PM' : 'AM';
    var h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + ap;
  }
  function paintCutoff(row) {
    var endInp = row.querySelector('[data-avail-end]');
    var el = row.querySelector('[data-avail-cutoff]');
    if (!el) return;
    var mins = endToMins(endInp ? endInp.value : '');
    var label = mins == null ? '—' : minsTo12h(Math.max(0, mins - 120));
    el.textContent = 'Same-day booking cutoff: ' + label;
  }
  document.querySelectorAll('[data-avail-day-toggle]').forEach(function (cb) {
    var row = cb.closest('.guide-profile-avail__row');
    if (!row) return;
    function sync() {
      var on = !!cb.checked;
      row.classList.toggle('is-on', on);
      row.querySelectorAll('input[type="time"]').forEach(function (inp) {
        inp.disabled = !on;
        if (on) inp.setAttribute('required', 'required');
        else inp.removeAttribute('required');
      });
    }
    cb.addEventListener('change', sync);
    sync();
    paintCutoff(row);
    var endInp = row.querySelector('[data-avail-end]');
    if (endInp) {
      endInp.addEventListener('input', function () { paintCutoff(row); });
      endInp.addEventListener('change', function () { paintCutoff(row); });
    }
  });
})();
</script>
<script src="<?= APP_URL ?>/assets/js/avail-time.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/avail-time.js') ?: time() ?>"></script>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
