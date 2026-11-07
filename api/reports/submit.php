<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/content_reports.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'reasons' => content_report_reasons()]);
}
$user = require_api_user();
$in = api_input();
$photo = content_report_save_upload($_FILES['report_photo'] ?? null);
if (empty($photo['ok'])) {
    json_response(['ok' => false, 'message' => (string) ($photo['message'] ?? 'Photo could not be uploaded.')], 400);
}
$err = submit_content_report(
    (int) $user['id'],
    (string) ($in['target_type'] ?? ''),
    (int) ($in['target_id'] ?? 0),
    (string) ($in['reason'] ?? ''),
    (string) ($in['details'] ?? ''),
    $photo['path'] ?? null
);
if ($err) {
    json_response(['ok' => false, 'message' => $err], 400);
}
json_response(['ok' => true, 'message' => 'Thanks — your report was sent to the Tourism Office.']);
