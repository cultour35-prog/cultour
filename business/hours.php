<?php
require_once __DIR__ . '/../includes/auth.php';
ensure_place_schedule_schema();
$user = require_login(ROLE_BUSINESS);
$pageTitle = 'Operating Hours';
$pageHeading = 'Operating Hours';
$activeNav = 'hours';

$biz = db()->prepare('SELECT * FROM businesses WHERE user_id=? LIMIT 1');
$biz->execute([$user['id']]);
$biz = $biz->fetch();
if ($biz && !business_listing_is_approved($biz)) {
    flash('error', 'Operating hours can be set after the Tourism Office approves your business.');
    redirect(APP_URL . '/business/dashboard.php');
}
$raw = $biz['opening_hours'] ?? null;
if (is_array($raw)) {
    $raw = json_encode($raw);
}
$hours = parse_opening_hours_value($raw) ?: [];
$availByDay = opening_hours_to_avail_by_day($hours);
$businessTypeName = '';
if ($biz) {
    $typeSt = db()->prepare('SELECT name FROM business_types WHERE id=?');
    $typeSt->execute([(int) ($biz['business_type_id'] ?? 0)]);
    $businessTypeName = (string) ($typeSt->fetchColumn() ?: '');
}
$showMinVisit = $biz && business_uses_min_visit($businessTypeName);

if ($biz && $_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $json = encode_opening_hours_from_post($_POST);
    if ($showMinVisit) {
        $minParsed = parse_min_visit_post($_POST);
        if (empty($minParsed['ok'])) {
            flash('error', (string) ($minParsed['message'] ?? 'Enter a valid minimum visit time.'));
            redirect(posted_return_to(APP_URL . '/business/hours.php'));
        }
        $minMinutes = $minParsed['minutes'];
        if ($minMinutes !== null) {
            $maxMinutes = place_visit_limit($biz['visit_duration_minutes'] ?? null);
            if ($maxMinutes !== null && $minMinutes > $maxMinutes) {
                flash('error', 'Minimum visit cannot be longer than the visit duration on your profile (' . format_visit_duration_label($maxMinutes) . ').');
                redirect(posted_return_to(APP_URL . '/business/hours.php'));
            }
        }
        db()->prepare('UPDATE businesses SET opening_hours=?, min_visit_minutes=? WHERE id=?')
            ->execute([$json, $minMinutes, $biz['id']]);
    } else {
        db()->prepare('UPDATE businesses SET opening_hours=? WHERE id=?')->execute([$json, $biz['id']]);
    }
    flash('success', 'Operating hours saved.');
    redirect(posted_return_to(APP_URL . '/business/hours.php'));
}

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <div class="glass p-4 guide-profile-avail guide-profile-avail--week">
        <p class="small text-muted mb-3">Select the days you are open and set a start and end time for each, or check 24 hrs if you never close that day. Unchecked days are closed.</p>
        <form method="post" data-unsaved-guard><?= csrf_field() ?>
          <input type="hidden" name="return_to" value="">
          <?= opening_hours_avail_rows_html('bizHours', $availByDay) ?>
          <?php if ($showMinVisit): ?>
            <div class="mt-4 pt-3 border-top border-secondary-subtle">
              <?= min_visit_field_html('bizMinVisit', $biz['min_visit_minutes'] ?? null) ?>
            </div>
          <?php endif; ?>
          <div class="form-text mt-2 mb-0">Each selected day uses its own start and end time.</div>
          <button class="btn btn-ct mt-3">Save hours</button>
        </form>
      </div>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
if ($biz) {
    render_unsaved_changes_modal();
}
require __DIR__ . '/../includes/footer.php';
