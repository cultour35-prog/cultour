<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business_engagement.php';
require_once __DIR__ . '/../includes/live.php';
$user = require_login(ROLE_TOURIST);
ensure_business_favorites_schema();
$pageTitle = 'Favorites';
$pageHeading = 'Saved Places';
$activeNav = 'favorites';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'remove_dest') {
        db()->prepare('DELETE FROM favorites WHERE user_id=? AND destination_id=?')
            ->execute([(int) $user['id'], (int) ($_POST['id'] ?? 0)]);
    }
    if ($action === 'remove_biz') {
        db()->prepare('DELETE FROM business_favorites WHERE user_id=? AND business_id=?')
            ->execute([(int) $user['id'], (int) ($_POST['id'] ?? 0)]);
    }
    redirect(APP_URL . '/tourist/favorites.php');
}

$live = live_tourist_favorites_payload((int) $user['id']);

$bodyClass = trim(($bodyClass ?? '') . ' tourist-app');
require __DIR__ . '/../includes/header.php';
?>
<?php require __DIR__ . '/../includes/tourist_shell_start.php'; ?>

      <div data-live-page="tourist_favorites" data-live-version="<?= e($live['version']) ?>">
        <?= $live['html'] ?>
      </div>
<?php require __DIR__ . '/../includes/tourist_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
