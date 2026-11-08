<?php
require_once __DIR__ . '/../includes/auth.php';
require_login(ROLE_TOURIST);
$params = [];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $params['q'] = $q;
}
$category = (int) ($_GET['category'] ?? 0);
if ($category > 0) {
    $params['category'] = $category;
}
redirect(APP_URL . '/tourist/places.php' . ($params ? ('?' . http_build_query($params)) : ''));
