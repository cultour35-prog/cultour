<?php
/**
 * Admin AJAX: save business types (add/color/delete).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/map_helpers.php';

header('Cache-Control: no-store');

start_app_session();
try_remember_login();
$user = current_user();
if (!$user || (int) $user['role_id'] !== ROLE_ADMIN) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    json_response(['ok' => false, 'message' => csrf_error_message()], 400);
}

ensure_business_type_pin_colors();
$action = $_POST['action'] ?? 'save';

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $count = db()->prepare('SELECT COUNT(*) FROM businesses WHERE business_type_id=?');
    $count->execute([$id]);
    if ((int) $count->fetchColumn() > 0) {
        json_response(['ok' => false, 'message' => 'Type is used by businesses'], 422);
    }
    db()->prepare('DELETE FROM business_types WHERE id=?')->execute([$id]);
} elseif ($action === 'color') {
    $id = (int) ($_POST['id'] ?? 0);
    $color = normalize_pin_color($_POST['pin_color'] ?? '#c4a35a');
    if (!$id) {
        json_response(['ok' => false, 'message' => 'Missing id'], 422);
    }
    db()->prepare('UPDATE business_types SET pin_color=? WHERE id=?')->execute([$color, $id]);
} else {
    $name = trim($_POST['name'] ?? '');
    $color = normalize_pin_color($_POST['pin_color'] ?? '#c4a35a');
    if ($name === '') {
        json_response(['ok' => false, 'message' => 'Name required'], 422);
    }
    $exists = db()->prepare('SELECT id FROM business_types WHERE name=?');
    $exists->execute([$name]);
    if ($exists->fetch()) {
        json_response(['ok' => false, 'message' => 'That business category already exists'], 422);
    }
    db()->prepare('INSERT INTO business_types (name, pin_color) VALUES (?,?)')->execute([$name, $color]);
}

json_response([
    'ok' => true,
    'business_types' => map_business_type_legend_items(),
    'version' => map_live_version(),
]);
