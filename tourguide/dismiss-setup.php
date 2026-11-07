<?php
/**
 * Dismiss guide setup modal for this session.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login(ROLE_TOUR_GUIDE);
start_app_session();
$_SESSION['guide_setup_dismissed'] = 1;
$back = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/tourguide/dashboard.php');
if (!is_string($back) || strpos($back, APP_URL) !== 0) {
    $back = APP_URL . '/tourguide/dashboard.php';
}
redirect($back);
