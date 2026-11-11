<?php
/**
 * In-house tour guides for Tour and Travel businesses.
 * They stay Tour Guide accounts. guide_type separates them from OLGA guides.
 */

function inhouse_guides_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $cols = $pdo->query('SHOW COLUMNS FROM tour_guide_profiles')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('guide_type', $cols, true)) {
        $pdo->exec("ALTER TABLE tour_guide_profiles ADD guide_type VARCHAR(20) NOT NULL DEFAULT 'olga' AFTER user_id");
    }
    if (!in_array('affiliated_business_id', $cols, true)) {
        $pdo->exec('ALTER TABLE tour_guide_profiles ADD affiliated_business_id INT UNSIGNED NULL AFTER guide_type');
    }
    if (!in_array('inhouse_active', $cols, true)) {
        $pdo->exec('ALTER TABLE tour_guide_profiles ADD inhouse_active TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER affiliated_business_id');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS guide_invitations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_guide_invite_business (business_id),
            KEY idx_guide_invite_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS package_guide_assignments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            guide_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'assigned',
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_package_guide_order (order_id),
            KEY idx_package_guide_guide (guide_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $assignCols = $pdo->query('SHOW COLUMNS FROM package_guide_assignments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('admin_note', $assignCols, true)) {
        $pdo->exec('ALTER TABLE package_guide_assignments ADD COLUMN admin_note VARCHAR(255) NULL AFTER status');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS package_guide_assignment_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            previous_guide_id INT UNSIGNED NULL,
            new_guide_id INT UNSIGNED NULL,
            changed_by INT UNSIGNED NOT NULL,
            change_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS package_guide_checkins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            guide_id INT UNSIGNED NOT NULL,
            checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_package_guide_checkin (order_id, guide_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS olga_guide_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            tour_date DATE NULL,
            notes VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_olga_request_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function inhouse_guide_is_inhouse(?array $profile): bool
{
    return is_array($profile) && (string) ($profile['guide_type'] ?? 'olga') === 'in_house';
}

/** @return list<array<string,mixed>> */
function inhouse_guides_for_business(int $businessId, bool $completeOnly = false): array
{
    inhouse_guides_ensure_schema();
    $complete = $completeOnly ? ' AND ' . guide_profile_complete_sql('g', 'u') : '';
    $st = db()->prepare(
        "SELECT g.id AS guide_id, g.guide_type, g.approval_status, g.inhouse_active, g.created_at,
                g.languages, g.bio, g.hourly_rate,
                u.id AS user_id, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.avatar, u.status,
                u.email_verified_at
         FROM tour_guide_profiles g
         JOIN users u ON u.id = g.user_id
         WHERE g.guide_type='in_house' AND g.affiliated_business_id=?{$complete}
         ORDER BY u.last_name, u.first_name"
    );
    $st->execute([$businessId]);
    return $st->fetchAll() ?: [];
}

/** @return list<int> */
function guides_busy_ids_on(string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }
    inhouse_guides_ensure_schema();
    $ids = [];
    $pkg = db()->prepare(
        "SELECT a.guide_id FROM package_guide_assignments a
         JOIN package_orders o ON o.id = a.order_id
         WHERE a.status='assigned' AND o.tour_date=?"
    );
    $pkg->execute([$date]);
    foreach ($pkg->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $ids[(int) $id] = (int) $id;
    }
    $book = db()->prepare(
        "SELECT guide_id FROM bookings WHERE status IN ('pending','approved') AND (
            booking_date=? OR (tour_start_date IS NOT NULL AND tour_start_date<=? AND (tour_end_date IS NULL OR tour_end_date>=?))
         )"
    );
    $book->execute([$date, $date, $date]);
    foreach ($book->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $ids[(int) $id] = (int) $id;
    }
    return array_values($ids);
}

function inhouse_guide_busy_on(int $guideId, string $date): bool
{
    if ($guideId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    inhouse_guides_ensure_schema();
    $st = db()->prepare(
        "SELECT 1 FROM package_guide_assignments a
         JOIN package_orders o ON o.id = a.order_id
         WHERE a.guide_id=? AND a.status='assigned' AND o.tour_date=? LIMIT 1"
    );
    $st->execute([$guideId, $date]);
    if ($st->fetchColumn()) {
        return true;
    }
    $book = db()->prepare(
        "SELECT 1 FROM bookings WHERE guide_id=? AND status IN ('pending','approved') AND (
            booking_date=? OR (tour_start_date IS NOT NULL AND tour_start_date<=? AND (tour_end_date IS NULL OR tour_end_date>=?))
         ) LIMIT 1"
    );
    $book->execute([$guideId, $date, $date, $date]);
    return (bool) $book->fetchColumn();
}

/**
 * @return array{ok:bool,message:string,activate_url:?string}
 */
function inhouse_guide_invite(int $businessId, int $actorUserId, array $post): array
{
    inhouse_guides_ensure_schema();
    $first = capitalize_name(trim((string) ($post['first_name'] ?? '')));
    $middle = capitalize_name(trim((string) ($post['middle_name'] ?? '')));
    $last = capitalize_name(trim((string) ($post['last_name'] ?? '')));
    $email = strtolower(trim((string) ($post['email'] ?? '')));
    $phone = ph_phone_local_digits((string) ($post['phone_local'] ?? ''));
    if ($first === '' || $last === '') {
        return ['ok' => false, 'message' => 'First name and last name are required.', 'activate_url' => null];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.', 'activate_url' => null];
    }
    if ($phone !== '' && (strlen($phone) !== 10 || $phone[0] !== '9')) {
        return ['ok' => false, 'message' => 'Phone must be 10 digits and start with 9.', 'activate_url' => null];
    }
    $dup = db()->prepare('SELECT id, role_id FROM users WHERE email=? LIMIT 1');
    $dup->execute([$email]);
    $existing = $dup->fetch();
    if ($existing) {
        return ['ok' => false, 'message' => 'That email already has a CulTour account.', 'activate_url' => null];
    }
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    db()->beginTransaction();
    try {
        db()->prepare(
            'INSERT INTO users (role_id, email, password_hash, first_name, last_name, middle_name, phone, status, verification_token, must_change_password)
             VALUES (?,?,?,?,?,?,?,?,?,1)'
        )->execute([
            ROLE_TOUR_GUIDE,
            $email,
            $password,
            $first,
            $last,
            $middle !== '' ? $middle : null,
            $phone !== '' ? $phone : null,
            'pending',
            $token,
        ]);
        $userId = (int) db()->lastInsertId();
        db()->prepare(
            "INSERT INTO tour_guide_profiles (user_id, guide_type, affiliated_business_id, inhouse_active, bio, languages, years_experience, hourly_rate, approval_status)
             VALUES (?,?,?,1,?,?,0,?, 'pending')"
        )->execute([
            $userId,
            'in_house',
            $businessId,
            'In-house guide',
            '',
            guide_office_daily_rate(),
        ]);
        db()->prepare(
            'INSERT INTO guide_invitations (business_id, user_id, token_hash, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
        )->execute([$businessId, $userId, $hash]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return ['ok' => false, 'message' => 'Could not create the guide account.', 'activate_url' => null];
    }
    $url = APP_URL . '/activate-inhouse.php?uid=' . $userId . '&token=' . $token;
    $sent = @mail(
        $email,
        'CulTour in-house guide invitation',
        "A Tour and Travel business added you as an in-house guide.\n\nActivate your account and set a password:\n" . $url . "\n\nThis link expires in 7 days.",
        'Content-Type: text/plain; charset=UTF-8'
    );
    notify_user($userId, 'guide_invite', 'You were added as an in-house guide', 'Activate your account to open the tour guide dashboard.', $url);
    return [
        'ok' => true,
        'message' => $sent
            ? 'Invitation sent to ' . $email . '.'
            : 'Guide account created. Email could not be sent, so share this activation link once: ' . $url,
        'activate_url' => $sent ? null : $url,
    ];
}

function inhouse_guide_set_active(int $businessId, int $guideId, bool $active): bool
{
    inhouse_guides_ensure_schema();
    $st = db()->prepare(
        "UPDATE tour_guide_profiles SET inhouse_active=? WHERE id=? AND guide_type='in_house' AND affiliated_business_id=?"
    );
    $st->execute([$active ? 1 : 0, $guideId, $businessId]);
    return $st->rowCount() > 0;
}
