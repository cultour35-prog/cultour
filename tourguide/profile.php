<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOUR_GUIDE);
ensure_guide_documents_schema();
$pageTitle = 'Guide Profile';
$pageHeading = 'Guide Profile';
$activeNav = 'profile';

$profile = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$profile = $profile->fetch() ?: null;

if (!$profile) {
    flash('error', 'Guide profile not found.');
    redirect(role_dashboard(ROLE_TOUR_GUIDE));
}

$dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', csrf_error_message());
    } else {
        guide_profile_apply_post($user, $profile, $dayLabels, APP_URL . '/tourguide/profile.php', true);
    }
}

$user = current_user();
ensure_user_identity_schema();
$profile = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=?');
$profile->execute([(int) $user['id']]);
$profile = $profile->fetch() ?: [];
extract(guide_profile_form_context($user, $profile), EXTR_OVERWRITE);
$profileUser = $user;
$guideProfileShowStatus = true;

$bodyClass = trim(($bodyClass ?? '') . ' guide-app');
$loadLangTags = true;
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/guide_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('warning')): ?><div class="alert alert-warning"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

      <?php require __DIR__ . '/../includes/guide_profile_form.php'; ?>
<?php require __DIR__ . '/../includes/guide_shell_end.php'; ?>
<?php
render_unsaved_changes_modal();
require __DIR__ . '/../includes/footer.php';
?>
