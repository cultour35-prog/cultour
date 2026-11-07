<?php
/**
 * Tourist actions on a business listing (Bearer token).
 * POST { business_id, action: favorite | review | inquiry | report, ... }
 *   review:  rating (1-5), comment
 *   inquiry: subject (<=160), message
 *   report:  reason, details, optional `report_photo` file (multipart)
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/business_engagement.php';
require_once __DIR__ . '/../../includes/content_reports.php';

$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}
ensure_business_listing_schema();
ensure_business_reviews_schema();
ensure_business_inquiries_schema();
ensure_business_favorites_schema();

$in = api_input();
$id = (int) ($in['business_id'] ?? $in['id'] ?? 0);
$uid = (int) $user['id'];
$st = db()->prepare("SELECT * FROM businesses WHERE id=? AND approval_status='approved' AND status='active'");
$st->execute([$id]);
$b = $st->fetch();
if (!$b) {
    json_response(['ok' => false, 'message' => 'Business not found.'], 404);
}
$action = (string) ($in['action'] ?? '');

if ($action === 'favorite') {
    $has = db()->prepare('SELECT 1 FROM business_favorites WHERE user_id=? AND business_id=?');
    $has->execute([$uid, $id]);
    if ($has->fetchColumn()) {
        db()->prepare('DELETE FROM business_favorites WHERE user_id=? AND business_id=?')->execute([$uid, $id]);
        json_response(['ok' => true, 'favorited' => false, 'message' => 'Removed from favorites.']);
    }
    db()->prepare('INSERT INTO business_favorites (user_id, business_id) VALUES (?,?)')->execute([$uid, $id]);
    json_response(['ok' => true, 'favorited' => true, 'message' => 'Saved to favorites.']);
}

if ($action === 'review') {
    $rating = max(1, min(5, (int) ($in['rating'] ?? 0)));
    $comment = trim((string) ($in['comment'] ?? ''));
    db()->prepare(
        "INSERT INTO business_reviews (business_id, user_id, rating, comment, status) VALUES (?,?,?,?, 'active')
         ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), status='active'"
    )->execute([$id, $uid, $rating, $comment !== '' ? $comment : null]);
    refresh_business_rating($id);
    json_response(['ok' => true, 'message' => 'Review saved.']);
}

if ($action === 'inquiry') {
    $subject = trim((string) ($in['subject'] ?? ''));
    $message = trim((string) ($in['message'] ?? ''));
    if ($subject === '' || strlen($subject) > 160) {
        json_response(['ok' => false, 'message' => 'Enter a short subject for your message.'], 400);
    }
    if ($message === '') {
        json_response(['ok' => false, 'message' => 'Please enter a message.'], 400);
    }
    db()->prepare('INSERT INTO business_inquiries (business_id, tourist_id, subject, message) VALUES (?,?,?,?)')
        ->execute([$id, $uid, $subject, $message]);
    $ownerId = (int) ($b['user_id'] ?? 0);
    if ($ownerId > 0) {
        business_inquiry_post_to_chat($uid, $ownerId, $uid, $subject, $message);
        notify_user(
            $ownerId,
            'inquiry',
            'New inquiry about ' . $b['name'],
            full_name($user) . ': ' . $subject,
            APP_URL . '/business/chat.php?with=' . $uid
        );
    }
    json_response(['ok' => true, 'owner_user_id' => $ownerId, 'message' => 'Message sent. When they reply, continue in Messages.']);
}

if ($action === 'report') {
    $photo = content_report_save_upload($_FILES['report_photo'] ?? null);
    if (empty($photo['ok'])) {
        json_response(['ok' => false, 'message' => (string) ($photo['message'] ?? 'Photo could not be uploaded.')], 400);
    }
    $err = submit_content_report($uid, 'business', $id, trim((string) ($in['reason'] ?? '')), trim((string) ($in['details'] ?? '')), $photo['path'] ?? null);
    if ($err) {
        json_response(['ok' => false, 'message' => $err], 400);
    }
    json_response(['ok' => true, 'message' => 'Thanks — your report was sent to the Tourism Office.']);
}

json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
