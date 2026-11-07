<?php
/**
 * Live HTML/stats poll (session auth). No CSRF — GET only, no writes.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/live.php';
require_once __DIR__ . '/../../includes/content_reports.php';
require_once __DIR__ . '/../../includes/business_engagement.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user) {
    live_unauth();
}
if (function_exists('user_is_suspended') && user_is_suspended($user) && !in_array(
    (string) ($_GET['page'] ?? ''),
    ['notifications', 'tourist_dashboard', 'guide_dashboard', 'business_dashboard'],
    true
)) {
    json_response(['ok' => false, 'message' => 'Your account is suspended.', 'suspended' => true], 403);
}

bookings_notify_guides_before_tours();
booking_replacement_sweep();

$page = preg_replace('/[^a-z_]/', '', (string) ($_GET['page'] ?? '')) ?: '';
$since = trim((string) ($_GET['since'] ?? ''));
$query = $_GET;
unset($query['page'], $query['since']);

json_response(live_page_dispatch($user, $page, $query, $since));
