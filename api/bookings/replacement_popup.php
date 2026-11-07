<?php
/**
 * Current replacement pop-up for the signed-in guide or tourist (polled by the footer).
 */
require_once __DIR__ . '/../../includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Please log in.'], 401);
}

booking_replacement_sweep();
$popup = booking_replacement_popup($user);
json_response(['ok' => true] + $popup);
