<?php
/**
 * Redirect stub. Destination services are edited on admin/destinations.php
 * (open a destination, then the Services tab). Kept so old bookmarks still work.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login(ROLE_ADMIN);
$id = (int) ($_GET['id'] ?? 0);
redirect(APP_URL . '/admin/destination.php?id=' . $id);
