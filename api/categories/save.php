<?php
/**
 * Admin AJAX: save category (add/update/delete) without full page reload.
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

ensure_category_pin_colors();
$action = $_POST['action'] ?? 'save';

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $count = db()->prepare('SELECT COUNT(*) FROM destinations WHERE category_id=?');
    $count->execute([$id]);
    if ((int) $count->fetchColumn() > 0) {
        json_response(['ok' => false, 'message' => 'Category has destinations'], 422);
    }
    db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
} elseif ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $color = normalize_pin_color($_POST['pin_color'] ?? '#0e8a7a');
    if (!$id || $name === '') {
        json_response(['ok' => false, 'message' => 'Name required'], 422);
    }
    db()->prepare('UPDATE categories SET name=?, slug=?, description=?, pin_color=? WHERE id=?')
        ->execute([$name, slugify($name), $desc, $color, $id]);
} elseif ($action === 'color') {
    // Quick pin-color-only update
    $id = (int) ($_POST['id'] ?? 0);
    $color = normalize_pin_color($_POST['pin_color'] ?? '#0e8a7a');
    if (!$id) {
        json_response(['ok' => false, 'message' => 'Missing id'], 422);
    }
    db()->prepare('UPDATE categories SET pin_color=? WHERE id=?')->execute([$color, $id]);
} else {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $color = normalize_pin_color($_POST['pin_color'] ?? '#0e8a7a');
    if ($name === '') {
        json_response(['ok' => false, 'message' => 'Name required'], 422);
    }
    db()->prepare('INSERT INTO categories (name, slug, description, pin_color) VALUES (?,?,?,?)')
        ->execute([$name, slugify($name), $desc, $color]);
}

json_response([
    'ok' => true,
    'categories' => map_category_legend_items(),
    'version' => map_live_version(),
]);
