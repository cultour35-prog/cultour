<?php
/**
 * Business reviews, inquiries, favorites, and open-now helpers.
 */

function ensure_business_reviews_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('avg_rating', $cols, true)) {
            $pdo->exec('ALTER TABLE businesses ADD COLUMN avg_rating DECIMAL(3,2) NOT NULL DEFAULT 0.00');
        }
        if (!in_array('review_count', $cols, true)) {
            $pdo->exec('ALTER TABLE businesses ADD COLUMN review_count INT UNSIGNED NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS business_reviews (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                business_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                rating TINYINT UNSIGNED NOT NULL,
                comment TEXT NULL,
                status ENUM('active','hidden') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_biz_review (business_id, user_id),
                INDEX idx_biz_review_status (business_id, status),
                CONSTRAINT fk_biz_review_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
                CONSTRAINT fk_biz_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }
}

function ensure_business_inquiries_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS business_inquiries (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                business_id INT UNSIGNED NOT NULL,
                tourist_id INT UNSIGNED NOT NULL,
                subject VARCHAR(160) NOT NULL,
                message TEXT NOT NULL,
                reply TEXT NULL,
                status ENUM('open','replied','closed') NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                replied_at DATETIME NULL,
                INDEX idx_biz_inq_biz (business_id, status),
                INDEX idx_biz_inq_tourist (tourist_id),
                CONSTRAINT fk_biz_inq_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
                CONSTRAINT fk_biz_inq_tourist FOREIGN KEY (tourist_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }
}

function ensure_business_favorites_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS business_favorites (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                business_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_biz_favorite (user_id, business_id),
                CONSTRAINT fk_biz_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_biz_fav_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }
}

function refresh_business_rating(int $businessId): void
{
    ensure_business_reviews_schema();
    $avg = db()->prepare(
        "SELECT AVG(rating), COUNT(*) FROM business_reviews WHERE business_id=? AND status='active'"
    );
    $avg->execute([$businessId]);
    [$a, $c] = $avg->fetch(PDO::FETCH_NUM) ?: [0, 0];
    db()->prepare('UPDATE businesses SET avg_rating=?, review_count=? WHERE id=?')
        ->execute([round((float) $a, 2), (int) $c, $businessId]);
}

/** True when opening hours cover the current local time. Unknown hours count as open. */
function place_is_open_now(mixed $openingHours): bool
{
    $hours = resolve_place_opening_hours($openingHours);
    if ($hours === null) {
        return true;
    }
    $dayKey = itinerary_day_key();
    $slot = (string) ($hours[$dayKey] ?? 'Closed');
    if (strcasecmp(trim($slot), 'Closed') === 0 || trim($slot) === '') {
        return false;
    }
    $mins = ((int) date('G') * 60) + (int) date('i');
    return opening_hours_covers_day($slot, $mins);
}

function business_open_inquiry_count(int $businessId): int
{
    ensure_business_inquiries_schema();
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM business_inquiries WHERE business_id=? AND status='open'");
        $s->execute([$businessId]);
        return (int) $s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Get or create a tourist ↔ peer conversation.
 * Peer user id is stored in conversations.guide_id (guides and business owners).
 */
function chat_get_or_create_conversation(int $touristId, int $peerUserId): int
{
    if ($touristId < 1 || $peerUserId < 1) {
        return 0;
    }
    $c = db()->prepare('SELECT id FROM conversations WHERE tourist_id=? AND guide_id=?');
    $c->execute([$touristId, $peerUserId]);
    $cid = (int) ($c->fetchColumn() ?: 0);
    if ($cid > 0) {
        return $cid;
    }
    db()->prepare(
        'INSERT INTO conversations (tourist_id, guide_id, last_message_at) VALUES (?,?,NOW())'
    )->execute([$touristId, $peerUserId]);
    return (int) db()->lastInsertId();
}

function chat_post_text_message(int $conversationId, int $senderId, string $body): int
{
    $body = trim($body);
    if ($conversationId < 1 || $senderId < 1 || $body === '') {
        return 0;
    }
    db()->prepare(
        'INSERT INTO messages (conversation_id, sender_id, message_type, body) VALUES (?,?,?,?)'
    )->execute([$conversationId, $senderId, 'text', $body]);
    $msgId = (int) db()->lastInsertId();
    db()->prepare('UPDATE conversations SET last_message_at=NOW() WHERE id=?')->execute([$conversationId]);
    return $msgId;
}

/**
 * Post an inquiry (or reply) into Messages. Returns conversation id.
 */
function business_inquiry_post_to_chat(
    int $touristId,
    int $businessOwnerId,
    int $senderId,
    string $subject,
    string $message
): int {
    $cid = chat_get_or_create_conversation($touristId, $businessOwnerId);
    if ($cid < 1) {
        return 0;
    }
    $subject = trim($subject);
    $message = trim($message);
    $body = $message;
    if ($subject !== '') {
        $body = $subject . ($message !== '' ? "\n\n" . $message : '');
    }
    chat_post_text_message($cid, $senderId, $body);
    return $cid;
}
