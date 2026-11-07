<?php
require_once __DIR__ . '/includes/auth.php';
start_app_session();
$leaving = current_user();
$adminLogout = $leaving && (int) ($leaving['role_id'] ?? 0) === ROLE_ADMIN;
logout_user();
flash('success', 'You have been logged out.');
$next = auth_login_url($adminLogout);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8"><title>Logging out</title>'
    . '<p><a href="' . e($next) . '">Continue</a></p><script>'
    . 'try{sessionStorage.removeItem("cultour-itin-tutorial-step");'
    . 'sessionStorage.removeItem("cultour-guide-tutorial-step");}catch(e){}'
    . 'location.replace(' . json_encode($next) . ');</script>';
exit;
