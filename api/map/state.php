<?php
/**
 * Live map state for realtime legends + pin colors (session auth).
 * GET → { ok, version, categories, destinations }
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/map_helpers.php';
require_once __DIR__ . '/../bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$user = api_resolve_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

ensure_destination_coords();
ensure_category_pin_colors();
ensure_business_type_pin_colors();

$isTourist = (int) $user['role_id'] === ROLE_TOURIST;
$destinations = map_destination_markers(
    $isTourist ? (APP_URL . '/tourist/destination.php?id=') : null
);
$businesses = map_business_markers(
    $isTourist ? (APP_URL . '/tourist/business.php?id=') : null,
    (int) $user['role_id'] !== ROLE_ADMIN
);

json_response([
    'ok' => true,
    'version' => map_live_version(),
    'categories' => map_category_legend_items(),
    'business_types' => map_business_type_legend_items(),
    'destinations' => $destinations,
    'businesses' => $businesses,
    'ts' => time(),
]);
