<?php
require_once __DIR__ . '/../includes/auth.php';
require_login(ROLE_TOURIST);
$params = [];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $params['q'] = $q;
}
$type = (int) ($_GET['type'] ?? 0);
if ($type > 0) {
    $params['type'] = $type;
}
redirect(APP_URL . '/tourist/places.php' . ($params ? ('?' . http_build_query($params)) : ''));
