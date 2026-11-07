<?php
/**
 * Redirect stub. Destination details live on admin/destinations.php?view={id}
 * (hours and services are tabs on that page). Kept so old bookmarks still work.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login(ROLE_ADMIN);
$id = (int) ($_GET['id'] ?? $_POST['destination_id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    redirect(APP_URL . '/admin/destinations.php');
}
redirect(APP_URL . '/admin/destinations.php?view=' . $id);
