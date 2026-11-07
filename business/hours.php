<?php
require_once __DIR__ . '/../includes/auth.php';
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

if ($biz && $_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $json = encode_opening_hours_from_post($_POST);
    db()->prepare('UPDATE businesses SET opening_hours=? WHERE id=?')->execute([$json, $biz['id']]);
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
