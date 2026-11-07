<?php
/**
 * Dismiss business listing setup modal for this session.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_BUSINESS);
start_app_session();
$_SESSION['business_setup_dismissed'] = 1;
$back = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/business/dashboard.php');
if (!is_string($back) || strpos($back, APP_URL) !== 0) {
    $back = APP_URL . '/business/dashboard.php';
}
redirect($back);
