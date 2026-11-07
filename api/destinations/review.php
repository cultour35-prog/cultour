<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_api_user();
if ((int) $user['role_id'] !== ROLE_TOURIST) {
    json_response(['ok' => false, 'message' => 'Tourists only'], 403);
}

$in = api_input();
$id = (int) ($in['destination_id'] ?? $in['id'] ?? 0);
$rating = max(1, min(5, (int) ($in['rating'] ?? 0)));
$comment = trim((string) ($in['comment'] ?? ''));
if ($id < 1) {
    json_response(['ok' => false, 'message' => 'destination_id required'], 400);
}

$exists = db()->prepare("SELECT id FROM destinations WHERE id=? AND status='published'");
$exists->execute([$id]);
if (!$exists->fetch()) {
    json_response(['ok' => false, 'message' => 'Destination not found'], 404);
}

db()->prepare(
    'INSERT INTO destination_reviews (destination_id, user_id, rating, comment) VALUES (?,?,?,?)
     ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)'
)->execute([$id, $user['id'], $rating, $comment]);

$avg = db()->prepare('SELECT AVG(rating), COUNT(*) FROM destination_reviews WHERE destination_id=?');
$avg->execute([$id]);
[$a, $c] = $avg->fetch(PDO::FETCH_NUM);
db()->prepare('UPDATE destinations SET avg_rating=?, review_count=? WHERE id=?')
    ->execute([round((float) $a, 2), (int) $c, $id]);

json_response(['ok' => true, 'avg_rating' => round((float) $a, 2), 'review_count' => (int) $c]);
