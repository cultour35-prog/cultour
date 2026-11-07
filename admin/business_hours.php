<?php
require_once __DIR__ . '/../includes/auth.php';
require_login(ROLE_ADMIN);
$id = (int) ($_GET['id'] ?? 0);
redirect(APP_URL . '/admin/business.php?id=' . $id);
