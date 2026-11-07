<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/announcements.php';

$user = require_api_user();
$bundle = announcements_for_role((int) $user['role_id'], (int) $user['id']);

$banners = [];
foreach ($bundle['headers'] ?? [] as $row) {
    $banners[] = [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'banner_image' => (string) ($row['banner_image'] ?? ''),
        'banner_url' => api_upload_url($row['banner_image'] ?? null),
    ];
}

$cards = [];
foreach ($bundle['cards'] ?? [] as $row) {
    $at = $row['published_at'] ?? $row['created_at'] ?? null;
    $cards[] = [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'published_at' => $at,
        'published_label' => $at ? format_date((string) $at, 'M j, Y') : '',
    ];
}

json_response([
    'ok' => true,
    'banners' => $banners,
    'cards' => $cards,
]);
