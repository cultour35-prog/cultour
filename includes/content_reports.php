<?php
/**
 * Tourist content reports / photo moderation helpers.
 */

function ensure_content_reports_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS content_reports (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                reporter_id INT UNSIGNED NOT NULL,
                target_type ENUM('destination','business','destination_image','business_photo','review','business_review','user','other') NOT NULL,
                target_id INT UNSIGNED NOT NULL,
                reason VARCHAR(255) NOT NULL,
                details TEXT NULL,
                status ENUM('pending','reviewed','dismissed','actioned') NOT NULL DEFAULT 'pending',
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                admin_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reports_status (status),
                INDEX idx_reports_target (target_type, target_id),
                CONSTRAINT fk_report_user FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $targetCol = db()->query("SHOW COLUMNS FROM content_reports LIKE 'target_type'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $targetType = (string) ($targetCol['Type'] ?? '');
        $missing = array_filter(
            ['destination', 'business', 'destination_image', 'business_photo', 'review', 'business_review', 'user', 'other'],
            static fn (string $v): bool => !str_contains($targetType, "'" . $v . "'")
        );
        if ($missing) {
            db()->exec(
                "ALTER TABLE content_reports
                 MODIFY target_type ENUM('destination','business','destination_image','business_photo','review','business_review','user','other') NOT NULL"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = db()->query('SHOW COLUMNS FROM content_reports')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('attachment', $cols, true)) {
            db()->exec('ALTER TABLE content_reports ADD COLUMN attachment VARCHAR(255) NULL AFTER details');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = db()->query('SHOW COLUMNS FROM destination_reviews')->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('status', $cols, true)) {
            db()->exec(
                "ALTER TABLE destination_reviews
                 ADD COLUMN status ENUM('active','hidden') NOT NULL DEFAULT 'active' AFTER comment"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = db()->query('SHOW COLUMNS FROM business_photos')->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('is_hidden', $cols, true)) {
            db()->exec('ALTER TABLE business_photos ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = db()->query('SHOW COLUMNS FROM destination_images')->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('is_hidden', $cols, true)) {
            db()->exec('ALTER TABLE destination_images ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function pending_content_report_count(): int
{
    ensure_content_reports_schema();
    try {
        return (int) db()->query(
            "SELECT COUNT(*) FROM content_reports WHERE status IN ('pending','reviewed')"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return string|null Error message or null on success
 */
function content_report_reasons(): array
{
    return [
        'Inaccurate information',
        'Inappropriate photo',
        'Spam or misleading',
        'Other',
    ];
}

function content_report_target_kind(string $targetType): string
{
    return match ($targetType) {
        'destination' => 'Government site',
        'business' => 'Business',
        'destination_image' => 'Government site photo',
        'business_photo' => 'Business photo',
        'review' => 'Government site review',
        'business_review' => 'Business review',
        'user' => 'Account',
        default => 'Other',
    };
}

/** Place, business, or person name for a report target. */
function content_report_target_name(string $targetType, int $targetId): string
{
    if ($targetId < 1) {
        return '';
    }
    $sql = match ($targetType) {
        'destination' => 'SELECT name FROM destinations WHERE id=?',
        'business' => 'SELECT name FROM businesses WHERE id=?',
        'destination_image' => 'SELECT d.name FROM destination_images i JOIN destinations d ON d.id = i.destination_id WHERE i.id=?',
        'business_photo' => 'SELECT b.name FROM business_photos p JOIN businesses b ON b.id = p.business_id WHERE p.id=?',
        'review' => 'SELECT d.name FROM destination_reviews r JOIN destinations d ON d.id = r.destination_id WHERE r.id=?',
        'business_review' => 'SELECT b.name FROM business_reviews r JOIN businesses b ON b.id = r.business_id WHERE r.id=?',
        'user' => "SELECT TRIM(CONCAT(first_name, ' ', last_name)) FROM users WHERE id=?",
        default => '',
    };
    if ($sql === '') {
        return '';
    }
    try {
        $st = db()->prepare($sql);
        $st->execute([$targetId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function content_report_target_exists(string $targetType, int $targetId): bool
{
    if ($targetId < 1) {
        return false;
    }
    $sql = [
        'destination' => 'SELECT id FROM destinations WHERE id=?',
        'business' => 'SELECT id FROM businesses WHERE id=?',
        'destination_image' => 'SELECT id FROM destination_images WHERE id=?',
        'business_photo' => 'SELECT id FROM business_photos WHERE id=?',
        'review' => 'SELECT id FROM destination_reviews WHERE id=?',
        'business_review' => 'SELECT id FROM business_reviews WHERE id=?',
        'user' => 'SELECT id FROM users WHERE id=?',
    ];
    if ($targetType === 'other') {
        return true;
    }
    if (!isset($sql[$targetType])) {
        return false;
    }
    try {
        $st = db()->prepare($sql[$targetType]);
        $st->execute([$targetId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Optional photo on a report form. No file is success with a null path.
 * @return array{ok:bool,path:?string,message?:string}
 */
function content_report_save_upload(?array $file): array
{
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    $path = upload_file($file, 'reports', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($path === null) {
        return ['ok' => false, 'path' => null, 'message' => upload_last_error() ?: 'Photo could not be uploaded.'];
    }
    return ['ok' => true, 'path' => $path];
}

function submit_content_report(int $reporterId, string $targetType, int $targetId, string $reason, string $details = '', ?string $attachment = null): ?string
{
    ensure_content_reports_schema();
    $allowed = ['destination', 'business', 'destination_image', 'business_photo', 'review', 'business_review', 'user', 'other'];
    if (!in_array($targetType, $allowed, true)) {
        return 'Invalid report target.';
    }
    $reason = trim($reason);
    if (!in_array($reason, content_report_reasons(), true)) {
        return 'Please choose a valid reason.';
    }
    if ($targetId < 1) {
        return 'Invalid target.';
    }
    if (!content_report_target_exists($targetType, $targetId)) {
        return 'That item could not be found.';
    }
    $details = trim($details);
    if (strlen($details) > 2000) {
        return 'Details are too long (max 2000 characters).';
    }
    $attachment = trim((string) $attachment);
    db()->prepare(
        'INSERT INTO content_reports (reporter_id, target_type, target_id, reason, details, attachment) VALUES (?,?,?,?,?,?)'
    )->execute([
        $reporterId,
        $targetType,
        $targetId,
        $reason,
        $details !== '' ? $details : null,
        $attachment !== '' ? $attachment : null,
    ]);
    return null;
}

/**
 * Hide or remove the reported content. Returns a short note for admin_notes.
 */
function moderate_reported_content(string $targetType, int $targetId): string
{
    ensure_content_reports_schema();
    if ($targetId < 1) {
        return 'Invalid target.';
    }
    try {
        if ($targetType === 'review') {
            db()->prepare("UPDATE destination_reviews SET status='hidden' WHERE id=?")->execute([$targetId]);
            $row = db()->prepare('SELECT destination_id FROM destination_reviews WHERE id=?');
            $row->execute([$targetId]);
            $destId = (int) ($row->fetchColumn() ?: 0);
            if ($destId > 0) {
                $avg = db()->prepare(
                    "SELECT AVG(rating), COUNT(*) FROM destination_reviews
                     WHERE destination_id=? AND COALESCE(status,'active')='active'"
                );
                $avg->execute([$destId]);
                [$a, $c] = $avg->fetch(PDO::FETCH_NUM) ?: [0, 0];
                db()->prepare('UPDATE destinations SET avg_rating=?, review_count=? WHERE id=?')
                    ->execute([round((float) $a, 2), (int) $c, $destId]);
            }
            return 'Government site review hidden.';
        }
        if ($targetType === 'business_review') {
            require_once __DIR__ . '/business_engagement.php';
            ensure_business_reviews_schema();
            db()->prepare("UPDATE business_reviews SET status='hidden' WHERE id=?")->execute([$targetId]);
            $row = db()->prepare('SELECT business_id FROM business_reviews WHERE id=?');
            $row->execute([$targetId]);
            $bizId = (int) ($row->fetchColumn() ?: 0);
            if ($bizId > 0) {
                refresh_business_rating($bizId);
            }
            return 'Business review hidden.';
        }
        if ($targetType === 'business_photo') {
            db()->prepare('UPDATE business_photos SET is_hidden=1 WHERE id=?')->execute([$targetId]);
            return 'Business photo hidden.';
        }
        if ($targetType === 'destination_image') {
            db()->prepare('UPDATE destination_images SET is_hidden=1 WHERE id=?')->execute([$targetId]);
            return 'Government site photo hidden.';
        }
        if ($targetType === 'business') {
            db()->prepare("UPDATE businesses SET status='inactive' WHERE id=?")->execute([$targetId]);
            return 'Business set inactive.';
        }
        if ($targetType === 'destination') {
            db()->prepare("UPDATE destinations SET status='draft' WHERE id=? AND status='published'")->execute([$targetId]);
            return 'Government site unpublished (draft).';
        }
    } catch (Throwable $e) {
        return 'Could not apply takedown.';
    }
    return 'No automatic takedown for this target type.';
}

/**
 * Pending → Review → Actioned or Dismissed. Notifies the reporter at each step.
 * @return string|null Error message, or null on success.
 */
function content_report_set_status(int $id, string $status, int $adminId, string $notes = ''): ?string
{
    ensure_content_reports_schema();
    if (!in_array($status, ['reviewed', 'actioned', 'dismissed'], true)) {
        return 'Invalid action.';
    }
    $st = db()->prepare('SELECT * FROM content_reports WHERE id=?');
    $st->execute([$id]);
    $report = $st->fetch();
    if (!$report) {
        return 'Report not found.';
    }
    $current = (string) ($report['status'] ?? '');
    if ($status === 'reviewed' && $current !== 'pending') {
        return 'Only a pending report can be moved to review.';
    }
    if (in_array($status, ['actioned', 'dismissed'], true) && $current !== 'reviewed') {
        return 'Review the report before marking it actioned or dismissed.';
    }
    $notes = trim($notes);
    $savedNotes = $notes !== '' ? $notes : ($report['admin_notes'] ?? null);
    db()->prepare(
        'UPDATE content_reports
         SET status=?, reviewed_by=?, reviewed_at=NOW(), admin_notes=?
         WHERE id=?'
    )->execute([$status, $adminId, $savedNotes, $id]);
    content_report_notify_reporter($report, $status, $notes);
    return null;
}

function content_report_notify_reporter(array $report, string $status, string $notes = ''): void
{
    $reporterId = (int) ($report['reporter_id'] ?? 0);
    if ($reporterId < 1 || !function_exists('notify_user')) {
        return;
    }
    $reason = trim((string) ($report['reason'] ?? ''));
    $about = content_report_target_name((string) ($report['target_type'] ?? ''), (int) ($report['target_id'] ?? 0));
    if ($about === '') {
        $about = content_report_target_kind((string) ($report['target_type'] ?? ''));
    }
    $reasonBit = $reason !== '' ? ' (' . $reason . ')' : '';
    if ($status === 'reviewed') {
        $title = 'Your report is under review';
        $body = 'The Tourism Office is reviewing your report' . $reasonBit . ' about ' . $about . '.';
    } elseif ($status === 'actioned') {
        $title = 'Your report was actioned';
        $body = 'The Tourism Office reviewed your report' . $reasonBit . ' about ' . $about . ' and took action.';
    } else {
        $title = 'Your report was dismissed';
        $body = 'The Tourism Office reviewed your report' . $reasonBit . ' about ' . $about . ' and found no violation.';
    }
    $notes = trim($notes);
    if ($notes !== '') {
        $body .= ' Note: ' . $notes;
    }
    notify_user($reporterId, 'content_report', $title, $body, APP_URL . '/notifications.php');
}
