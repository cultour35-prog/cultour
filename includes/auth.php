<?php
/**
 * CulTour Auth Helpers
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/suspension.php';

function auth_login_url(bool $admin = false): string
{
    return APP_URL . ($admin ? '/admin/' : '/login.php');
}

function auth_portal(string $portal): string
{
    return $portal === 'admin' ? 'admin' : 'public';
}

function auth_portal_allows_role(int $roleId, string $portal): bool
{
    $isAdmin = $roleId === ROLE_ADMIN;
    return auth_portal($portal) === 'admin' ? $isAdmin : !$isAdmin;
}

function attempt_login(string $email, string $password, bool $remember = false, ?int $roleId = null, string $portal = 'public'): array
{
    ensure_suspension_schema();
    ensure_password_schema();
    ensure_user_identity_schema();
    ensure_email_role_unique_schema();

    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Enter both your email and your password to sign in.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'That email address looks incomplete. Use the full address, for example name@gmail.com.'];
    }

    $throttle = auth_throttle_status('login', $email);
    if ($throttle['locked']) {
        return [
            'ok' => false,
            'locked' => true,
            'retry_minutes' => $throttle['retry_minutes'],
            'message' => auth_locked_message($throttle['retry_minutes']),
        ];
    }

    if ($roleId !== null && $roleId > 0) {
        $stmt = db()->prepare(
            'SELECT u.*, r.name AS role_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = ? AND u.role_id = ? LIMIT 1'
        );
        $stmt->execute([$email, $roleId]);
        $candidates = $stmt->fetchAll() ?: [];
    } else {
        $stmt = db()->prepare(
            'SELECT u.*, r.name AS role_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = ?
             ORDER BY u.role_id ASC'
        );
        $stmt->execute([$email]);
        $candidates = $stmt->fetchAll() ?: [];
    }

    $candidates = array_values(array_filter(
        $candidates,
        static function (array $row) use ($portal): bool {
            return auth_portal_allows_role((int) ($row['role_id'] ?? 0), $portal);
        }
    ));

    $matched = [];
    foreach ($candidates as $row) {
        if (password_verify($password, (string) ($row['password_hash'] ?? ''))) {
            $matched[] = $row;
        }
    }

    if (!$matched) {
        auth_throttle_fail('login', $email);
        $after = auth_throttle_status('login', $email);
        if ($after['locked']) {
            return [
                'ok' => false,
                'locked' => true,
                'retry_minutes' => $after['retry_minutes'],
                'message' => auth_locked_message($after['retry_minutes']),
            ];
        }
        $msg = 'Incorrect email or password. Passwords are case-sensitive, so check Caps Lock, or use "Forgot password?" to set a new one.';
        if ($after['remaining'] <= 2) {
            $msg .= ' ' . ($after['remaining'] === 1 ? '1 attempt' : $after['remaining'] . ' attempts')
                . ' left before sign-in is paused for ' . AUTH_LOCK_MINUTES . ' minutes.';
        }
        return ['ok' => false, 'message' => $msg, 'remaining' => $after['remaining']];
    }
    auth_throttle_clear('login', $email);

    if (count($matched) > 1) {
        $options = [];
        foreach ($matched as $row) {
            $rid = (int) ($row['role_id'] ?? 0);
            $options[] = [
                'role_id' => $rid,
                'label' => role_label($rid),
            ];
        }
        return [
            'ok' => false,
            'need_role' => true,
            'message' => 'This email has more than one account. Choose which account type to sign in as.',
            'roles' => $options,
        ];
    }

    $user = $matched[0];

    if ($user['status'] === 'rejected') {
        return ['ok' => false, 'message' => 'Your registration was rejected. Contact the Tourism Office.'];
    }

    $isSuspended = ($user['status'] ?? '') === 'suspended';

    // Suspended accounts may always log in (dashboard + appeal only).
    // Pending / unapproved guides & businesses cannot — unless suspended.
    if (!$isSuspended) {
        if ($user['status'] === 'pending' && (int) $user['role_id'] !== ROLE_TOURIST) {
            return ['ok' => false, 'message' => 'Your account is still waiting for Tourism Office approval. You can sign in as soon as it is approved.'];
        }

        $gate = registration_approval_gate($user);
        if (!$gate['ok']) {
            return ['ok' => false, 'message' => $gate['message']];
        }
    }

    // Suspended users may log in, but only to see the suspension notice / appeal on their dashboard.
    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role_id'] = (int) $user['role_id'];
    $_SESSION['role_name'] = $user['role_name'];

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    log_activity(
        (int) $user['id'],
        'login',
        'auth',
        $user['status'] === 'suspended' ? 'Suspended user logged in' : 'User logged in'
    );

    maybe_flag_guide_setup_modal($user);
    maybe_flag_business_setup_modal($user);

    if ($remember && $user['status'] === 'active' && empty($user['must_change_password'])) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE users SET remember_token = ? WHERE id = ?')->execute([$token, $user['id']]);
        setcookie(
            REMEMBER_COOKIE,
            $user['id'] . ':' . $token,
            [
                'expires'  => time() + (REMEMBER_DAYS * 86400),
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    return ['ok' => true, 'user' => $user];
}

/**
 * Active guide and business accounts need an approved profile or listing.
 * Recreate a missing row so the account can be opened again.
 */
function ensure_active_role_record(array $user): void
{
    if (($user['status'] ?? '') !== 'active') {
        return;
    }
    $role = (int) ($user['role_id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);
    if ($userId < 1) {
        return;
    }

    if ($role === ROLE_TOUR_GUIDE) {
        $st = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=? LIMIT 1');
        $st->execute([$userId]);
        if ($st->fetchColumn()) {
            return;
        }
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === 'guide@example.com') {
            db()->prepare(
                'INSERT INTO tour_guide_profiles (user_id, bio, languages, years_experience, hourly_rate, license_number, approval_status, approved_by, approved_at)
                 VALUES (?, ?, ?, 5, 500.00, ?, \'approved\', 1, NOW())'
            )->execute([
                $userId,
                'Friendly local guide specializing in Lake Danao and heritage walks around Ormoc.',
                'English, Cebuano, Tagalog',
                'OTG-2024-001',
            ]);
        } else {
            db()->prepare(
                'INSERT INTO tour_guide_profiles (user_id, bio, languages, years_experience, hourly_rate, approval_status, approved_at)
                 VALUES (?, ?, ?, 0, ?, \'approved\', NOW())'
            )->execute([
                $userId,
                'Tour guide profile restored so this account can sign in.',
                '',
                guide_office_daily_rate(),
            ]);
        }
        $guideId = (int) db()->lastInsertId();
        if ($guideId > 0 && $email === 'guide@example.com') {
            $avail = db()->prepare(
                'INSERT INTO guide_availability (guide_id, day_of_week, start_time, end_time) VALUES (?, ?, \'08:00:00\', \'17:00:00\')'
            );
            for ($d = 1; $d <= 5; $d++) {
                $avail->execute([$guideId, $d]);
            }
        }
        return;
    }

    if ($role !== ROLE_BUSINESS) {
        return;
    }

    $st = db()->prepare('SELECT id FROM businesses WHERE user_id=? LIMIT 1');
    $st->execute([$userId]);
    if ($st->fetchColumn()) {
        return;
    }
    $typeId = (int) db()->query('SELECT id FROM business_types ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($typeId < 1) {
        return;
    }
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === 'business@example.com') {
        $hours = json_encode([
            'mon' => '08:00-22:00', 'tue' => '08:00-22:00', 'wed' => '08:00-22:00',
            'thu' => '08:00-22:00', 'fri' => '08:00-22:00', 'sat' => '08:00-22:00', 'sun' => '08:00-20:00',
        ]);
        db()->prepare(
            'INSERT INTO businesses (user_id, business_type_id, name, slug, description, address, phone, email, opening_hours, approval_status, approved_by, approved_at, status, latitude, longitude)
             VALUES (?,?,?,?,?,?,?,?,?,\'approved\',1,NOW(),\'active\',11.0072,124.6085)'
        )->execute([
            $userId,
            1,
            'Ormoc Bay Inn',
            'ormoc-bay-inn',
            'Comfortable budget-friendly hotel near downtown Ormoc.',
            'Real St., Ormoc City',
            '053-123-4567',
            'info@ormocbayinn.example',
            $hours,
        ]);
        $bizId = (int) db()->lastInsertId();
        if ($bizId > 0) {
            db()->prepare(
                'INSERT INTO business_services (business_id, name, description, price) VALUES (?,?,?,?),(?,?,?,?)'
            )->execute([
                $bizId, 'Standard Room', 'Air-conditioned room for 2', 1500.00,
                $bizId, 'Airport Transfer', 'Ormoc Airport pickup', 300.00,
            ]);
        }
        return;
    }

    $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
    if ($name === '') {
        $name = 'Local Business';
    }
    db()->prepare(
        'INSERT INTO businesses (user_id, business_type_id, name, slug, description, address, email, approval_status, approved_at, status)
         VALUES (?,?,?,?,?,?,?,\'approved\',NOW(),\'active\')'
    )->execute([
        $userId,
        $typeId,
        $name,
        'business-user-' . $userId,
        'Business listing restored so this account can sign in.',
        'Ormoc City',
        $user['email'] ?? null,
    ]);
}

/**
 * Guides/businesses may only use the app after Tourism Office approval
 * (users.status alone is not enough — profile/listing must be approved).
 *
 * @return array{ok:bool,message?:string,approval?:string}
 */
function registration_approval_gate(array $user): array
{
    $role = (int) ($user['role_id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);

    if ($role === ROLE_ADMIN || $role === ROLE_TOURIST || $userId < 1) {
        return ['ok' => true];
    }

    ensure_active_role_record($user);

    if ($role === ROLE_TOUR_GUIDE) {
        $st = db()->prepare('SELECT approval_status FROM tour_guide_profiles WHERE user_id=? LIMIT 1');
        $st->execute([$userId]);
        $approval = $st->fetchColumn();
        if ($approval === false || $approval === null || $approval === '') {
            return ['ok' => false, 'message' => 'Your tour guide profile was not found. Contact the Tourism Office.', 'approval' => 'missing'];
        }
        if ($approval === 'rejected') {
            return ['ok' => false, 'message' => 'Your guide registration was rejected. Contact the Tourism Office.', 'approval' => 'rejected'];
        }
        if ($approval !== 'approved') {
            // Keep users.status aligned with pending applications
            if (($user['status'] ?? '') === 'active') {
                db()->prepare("UPDATE users SET status='pending', remember_token=NULL WHERE id=?")->execute([$userId]);
            }
            return ['ok' => false, 'message' => 'Your tour guide account is pending approval. You can log in after the Tourism Office approves you.', 'approval' => (string) $approval];
        }
        return ['ok' => true, 'approval' => 'approved'];
    }

    if ($role === ROLE_BUSINESS) {
        $st = db()->prepare('SELECT approval_status FROM businesses WHERE user_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$userId]);
        $approval = $st->fetchColumn();
        if ($approval === false || $approval === null || $approval === '') {
            return ['ok' => false, 'message' => 'Your business listing was not found. Contact the Tourism Office.', 'approval' => 'missing'];
        }
        if ($approval === 'rejected') {
            return ['ok' => false, 'message' => 'Your business registration was rejected. Contact the Tourism Office.', 'approval' => 'rejected'];
        }
        if ($approval !== 'approved') {
            if (($user['status'] ?? '') === 'active') {
                db()->prepare("UPDATE users SET status='pending', remember_token=NULL WHERE id=?")->execute([$userId]);
            }
            return ['ok' => false, 'message' => 'Your business account is pending approval. You can log in after the Tourism Office approves you.', 'approval' => (string) $approval];
        }
        return ['ok' => true, 'approval' => 'approved'];
    }

    return ['ok' => true];
}

function try_remember_login(): void
{
    start_app_session();
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    if (empty($_COOKIE[REMEMBER_COOKIE])) {
        return;
    }

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) {
        return;
    }
    [$userId, $token] = $parts;

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND remember_token = ? AND status = ? LIMIT 1');
    $stmt->execute([(int) $userId, $token, 'active']);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $gate = registration_approval_gate($user);
    if (!$gate['ok']) {
        db()->prepare('UPDATE users SET remember_token = NULL WHERE id = ?')->execute([(int) $user['id']]);
        setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
        return;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role_id'] = (int) $user['role_id'];
    $_SESSION['role_name'] = role_slug((int) $user['role_id']);
}

/**
 * Paths a suspended user may still open.
 */
function suspended_allowed_script(): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $allowed = [
        'dashboard.php',
        'logout.php',
        'appeal.php',
        'login.php',
        'change-password.php',
    ];
    return in_array($script, $allowed, true);
}

function must_change_password_allowed_script(): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return in_array($script, ['change-password.php', 'logout.php', 'login.php'], true);
}

function user_must_change_password(?array $user): bool
{
    return $user && !empty($user['must_change_password']);
}

function user_must_complete_profile(?array $user): bool
{
    // Tourists are created with the full registration form, so they are not sent to a finish-profile screen.
    return false;
}

/** Where a signed-in user must go before the rest of the app. */
function account_setup_url(?array $user): ?string
{
    if (!$user) {
        return null;
    }
    if (user_must_change_password($user)) {
        return APP_URL . '/change-password.php';
    }
    if (($user['status'] ?? '') === 'suspended') {
        return null;
    }
    return null;
}

function require_login(?int $roleId = null): array
{
    ensure_suspension_schema();
    ensure_password_schema();
    try_remember_login();
    $user = current_user();

    if (!$user) {
        flash('error', 'Please log in to continue.');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $adminGate = $roleId === ROLE_ADMIN || str_contains($script, '/admin/');
        redirect(auth_login_url($adminGate));
    }

    if ($roleId !== null && (int) $user['role_id'] !== $roleId) {
        flash('error', 'You do not have permission to access that page.');
        redirect(role_dashboard((int) $user['role_id']));
    }

    // Suspended accounts: dashboard / appeal / logout only (skip registration gate)
    if (user_is_suspended($user)) {
        if (!suspended_allowed_script()) {
            flash('error', 'Your account is suspended. You can appeal from your dashboard.');
            redirect(role_dashboard((int) $user['role_id']));
        }
        return $user;
    }

    // Unapproved guides/businesses cannot stay logged in
    $gate = registration_approval_gate($user);
    if (!$gate['ok']) {
        $wasAdmin = (int) ($user['role_id'] ?? 0) === ROLE_ADMIN;
        logout_user();
        flash('error', $gate['message']);
        redirect(auth_login_url($wasAdmin));
    }

    // Admin-created accounts must set a new password before using the app.
    if (user_must_change_password($user) && !must_change_password_allowed_script()) {
        redirect(APP_URL . '/change-password.php');
    }

    // Approved tours whose itinerary/booking date has passed → completed
    bookings_auto_complete_past_tours();
    bookings_notify_guides_before_tours();

    return $user;
}

function logout_user(): void
{
    start_app_session();
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        require_once __DIR__ . '/suspension.php';
        // Hide appeal result banners permanently so they don't return next login
        dismiss_open_appeal_notices_for_user((int) $uid);
        db()->prepare('UPDATE users SET remember_token = NULL WHERE id = ?')->execute([$uid]);
        log_activity((int) $uid, 'logout', 'auth', 'User logged out');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
    session_destroy();
}

function register_user(array $data): array
{
    ensure_user_identity_schema();
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $roleId = (int) ($data['role_id'] ?? ROLE_TOURIST);
    $adminCreated = !empty($data['admin_created']);

    $allowedRoles = [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS];
    if (!in_array($roleId, $allowedRoles, true)) {
        $roleId = ROLE_TOURIST;
    }

    $linked = ['ok' => false];
    $reuseHash = null;
    if (in_array($roleId, linkable_role_ids(), true)) {
        $linked = resolve_registration_link($data, $roleId);
        if (!empty($data['link_token']) || (int) ($data['link_source_user_id'] ?? 0) > 0) {
            if (empty($linked['ok'])) {
                return ['ok' => false, 'message' => (string) ($linked['message'] ?? 'Your account link expired. Please link again.')];
            }
        }
    }
    if (!empty($linked['ok']) && !empty($linked['user'])) {
        $src = $linked['user'];
        $email = strtolower(trim((string) ($src['email'] ?? '')));
        $reuseHash = (string) ($src['password_hash'] ?? '');
        if ($reuseHash === '') {
            return ['ok' => false, 'message' => 'Could not reuse that account password. Please link again.'];
        }
        foreach ([
            'first_name', 'last_name', 'middle_name', 'name_suffix', 'sex', 'date_of_birth', 'nationality', 'languages',
        ] as $copyKey) {
            if (trim((string) ($data[$copyKey] ?? '')) === '' && isset($src[$copyKey])) {
                $data[$copyKey] = $src[$copyKey];
            }
        }
        $spoken = user_spoken_languages_from_row($src);
        if ($spoken !== '') {
            if (trim((string) ($data['tourist_languages'] ?? '')) === '') {
                $data['tourist_languages'] = $spoken;
            }
            if (trim((string) ($data['languages'] ?? '')) === '') {
                $data['languages'] = $spoken;
            }
        }
        if (trim((string) ($data['phone_local'] ?? $data['phone'] ?? '')) === '' && !empty($src['phone'])) {
            $data['phone_local'] = ph_phone_local_digits((string) $src['phone']);
        }
        foreach ([
            'address_region', 'address_city', 'address_barangay', 'address_street',
            'current_address_region', 'current_address_city', 'current_address_barangay', 'current_address_street',
        ] as $addrKey) {
            if (trim((string) ($data[$addrKey] ?? '')) === '' && isset($src[$addrKey]) && trim((string) $src[$addrKey]) !== '') {
                $data[$addrKey] = $src[$addrKey];
            }
        }
        if (empty($data['use_structured_address']) && trim((string) ($data['address_region'] ?? '')) !== '') {
            $data['use_structured_address'] = '1';
        }
        if (!isset($data['current_same_as_permanent']) && array_key_exists('current_same_as_permanent', $src)) {
            $data['current_same_as_permanent'] = !empty($src['current_same_as_permanent']) ? '1' : '0';
        }
        if (trim((string) ($data['emergency_first_name'] ?? '')) === '' && trim((string) ($src['emergency_contact_name'] ?? '')) !== '') {
            $emergParts = split_person_name((string) $src['emergency_contact_name']);
            $data['emergency_first_name'] = $emergParts['first'];
            $data['emergency_middle_name'] = $emergParts['middle'];
            $data['emergency_last_name'] = $emergParts['last'];
            if (trim((string) ($data['emergency_suffix'] ?? '')) === '') {
                $data['emergency_suffix'] = $emergParts['suffix'];
            }
        }
        if (trim((string) ($data['emergency_relationship'] ?? '')) === '' && trim((string) ($src['emergency_contact_relationship'] ?? '')) !== '') {
            $data['emergency_relationship'] = $src['emergency_contact_relationship'];
        }
        if (trim((string) ($data['emergency_phone_local'] ?? '')) === '' && trim((string) ($src['emergency_contact_phone'] ?? '')) !== '') {
            $data['emergency_phone_local'] = ph_phone_local_digits((string) $src['emergency_contact_phone']);
        }
    } else {
        $pwCheck = $adminCreated
            ? validate_password_rules($password, $password)
            : validate_password_rules($password, $data['password_confirm'] ?? '');
        if (!$pwCheck['ok']) {
            return ['ok' => false, 'message' => $pwCheck['message']];
        }
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a complete email address, for example name@gmail.com.'];
    }

    $firstName = capitalize_name((string) ($data['first_name'] ?? ''));
    $lastName = capitalize_name((string) ($data['last_name'] ?? ''));
    if ($firstName === '' || $lastName === '') {
        return ['ok' => false, 'message' => 'First name and last name are required.'];
    }
    $middleNameCheck = capitalize_name((string) ($data['middle_name'] ?? ''));
    $suffixCheck = (string) ($data['name_suffix'] ?? $data['owner_suffix'] ?? '');
    foreach ([$firstName, $lastName, $middleNameCheck, $suffixCheck] as $namePart) {
        if (person_name_has_invalid_chars($namePart)) {
            return ['ok' => false, 'message' => 'Names may only contain letters.'];
        }
    }
    $nameSuffix = normalize_name_suffix($suffixCheck);

    $ident = [
        'middle_name' => capitalize_name((string) ($data['middle_name'] ?? '')) ?: null,
        'sex' => null,
        'date_of_birth' => null,
        'nationality' => null,
        'languages' => null,
        'address' => null,
        'address_region' => null,
        'address_city' => null,
        'address_barangay' => null,
        'address_street' => null,
    ];
    if ($roleId !== ROLE_BUSINESS) {
        $identity = user_parse_identity($data, true);
        if (!$identity['ok']) {
            return ['ok' => false, 'message' => (string) ($identity['message'] ?? 'Please complete your personal details.')];
        }
        $ident = $identity['data'];
    }

    $phoneRaw = (string) ($data['phone_local'] ?? $data['phone'] ?? '');
    $phoneCheck = normalize_ph_phone($phoneRaw, $roleId === ROLE_BUSINESS);
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'message' => $phoneCheck['message']];
    }
    $phone = $phoneCheck['phone'];

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? AND role_id = ?');
    $exists->execute([$email, $roleId]);
    if ($exists->fetch()) {
        return ['ok' => false, 'message' => 'An account with this email already exists for ' . role_label($roleId) . '. Sign in instead, or use "Forgot password?" on the login page if you can\'t remember the password.'];
    }

    // Same email on Tourist/Tour Guide must go through account linking, not a second password.
    if (empty($linked['ok']) && in_array($roleId, linkable_role_ids(), true)) {
        $counterpartId = counterpart_linkable_role($roleId);
        if ($counterpartId > 0 && email_taken_for_role($email, $counterpartId)) {
            $canLink = true;
            $linkMsg = 'This email already exists as a ' . role_label($counterpartId)
                . '. Link that account to register as ' . role_label($roleId) . '.';
            // Tourist → Tour Guide only when the tourist currently resides in Leyte.
            if ($roleId === ROLE_TOUR_GUIDE && $counterpartId === ROLE_TOURIST) {
                $src = db()->prepare('SELECT * FROM users WHERE email=? AND role_id=? LIMIT 1');
                $src->execute([$email, ROLE_TOURIST]);
                $srcRow = $src->fetch() ?: null;
                if ($srcRow && !user_current_address_in_leyte($srcRow)) {
                    $canLink = false;
                    $linkMsg = 'Your Tourist account’s current address is not in Ormoc City, so you cannot link it to a Tour Guide account. Update that account’s current address to Ormoc City first, then try linking again.';
                }
            }
            return [
                'ok' => false,
                'message' => $linkMsg,
                'need_link' => $canLink,
                'counterpart_role_id' => $counterpartId,
                'counterpart_in_leyte' => $canLink || $roleId !== ROLE_TOUR_GUIDE,
            ];
        }
    }

    if ($phone !== null && phone_taken_by_other_account($phone, $email, null, $roleId)) {
        return ['ok' => false, 'message' => phone_taken_message(), 'phone_taken' => true];
    }

    $emergency = ['emergency_contact_name' => null, 'emergency_contact_phone' => null];
    $guideLanguages = null;
    $guideYears = 0;
    $bizListing = null;
    if ($roleId === ROLE_TOURIST || $roleId === ROLE_TOUR_GUIDE) {
        $emerg = user_parse_emergency_contact($data, true);
        if (!$emerg['ok']) {
            return $emerg;
        }
        $emergency = $emerg['data'];
        $selfName = join_person_name(
            $firstName,
            (string) ($ident['middle_name'] ?? ''),
            $lastName,
            (string) ($nameSuffix ?? '')
        );
        $diff = emergency_contact_differs_from_account(
            ['name' => $selfName, 'phone' => $phone],
            $emergency
        );
        if (!$diff['ok']) {
            return $diff;
        }
    }
    if ($roleId === ROLE_TOUR_GUIDE) {
        $guideLanguages = trim((string) ($data['languages'] ?? ''));
        if ($guideLanguages === '') {
            return ['ok' => false, 'message' => 'Please list the languages you can guide in.'];
        }
        $guideYears = max(0, min(80, (int) ($data['years_experience'] ?? 0)));
        if (!registration_has_files($data['credential_files'] ?? [])
            && empty($data['credential_file'])
        ) {
            return ['ok' => false, 'message' => 'Please upload at least one resume or credential file.'];
        }
    }
    if ($roleId === ROLE_BUSINESS) {
        $ownerSex = trim((string) ($data['owner_sex'] ?? $data['sex'] ?? ''));
        if ($ownerSex === '' || !array_key_exists($ownerSex, user_sex_options())) {
            return ['ok' => false, 'message' => 'Please select the business owner gender.'];
        }
        $ident['sex'] = $ownerSex;
        $parsedBiz = parse_business_registration_input($data, $firstName, $lastName, $ident, $phone, $email);
        if (!$parsedBiz['ok']) {
            return $parsedBiz;
        }
        $bizListing = $parsedBiz['data'];
        if (!$adminCreated && !registration_has_files($data['document_files'] ?? [])) {
            return ['ok' => false, 'message' => 'Please upload at least one business registration document.'];
        }
    }

    $avatar = null;
    // Tourist/guide photos are set on the profile after registration, not during sign-up.
    if ($adminCreated && $roleId !== ROLE_BUSINESS && !empty($data['avatar_file']) && is_array($data['avatar_file']) && !empty($data['avatar_file']['name'])) {
        $up = upload_file($data['avatar_file'], 'profiles', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!$up) {
            return ['ok' => false, 'message' => 'Could not upload profile photo. Use JPG, PNG, GIF, or WebP.'];
        }
        $avatar = $up;
    }
    if (($avatar === null || $avatar === '') && !empty($linked['ok']) && !empty($linked['user']['avatar'])) {
        $avatar = (string) $linked['user']['avatar'];
    }

    $status = ($roleId === ROLE_TOURIST || $adminCreated) ? 'active' : 'pending';
    $hash = ($reuseHash !== null && $reuseHash !== '')
        ? $reuseHash
        : password_hash($password, PASSWORD_DEFAULT);

    $stmt = db()->prepare(
        'INSERT INTO users (role_id, email, password_hash, first_name, last_name, middle_name, name_suffix, sex, date_of_birth, nationality, languages, address, address_region, address_city, address_barangay, address_street, current_same_as_permanent, current_address, current_address_region, current_address_city, current_address_barangay, current_address_street, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, phone, avatar, status, verification_token, email_verified_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $roleId,
        $email,
        $hash,
        $firstName,
        $lastName,
        $ident['middle_name'],
        $nameSuffix,
        $ident['sex'],
        $ident['date_of_birth'],
        $ident['nationality'],
        $ident['languages'] ?? null,
        $ident['address'],
        $ident['address_region'] ?? null,
        $ident['address_city'] ?? null,
        $ident['address_barangay'] ?? null,
        $ident['address_street'] ?? null,
        (int) ($ident['current_same_as_permanent'] ?? 0),
        $ident['current_address'] ?? null,
        $ident['current_address_region'] ?? null,
        $ident['current_address_city'] ?? null,
        $ident['current_address_barangay'] ?? null,
        $ident['current_address_street'] ?? null,
        $emergency['emergency_contact_name'] ?? null,
        $emergency['emergency_contact_relationship'] ?? null,
        $emergency['emergency_contact_phone'] ?? null,
        $phone,
        $avatar,
        $status,
        null,
        date('Y-m-d H:i:s'),
    ]);

    $userId = (int) db()->lastInsertId();
    if ($adminCreated) {
        db()->prepare('UPDATE users SET must_change_password=1 WHERE id=?')->execute([$userId]);
    }

    if (in_array($roleId, linkable_role_ids(), true)) {
        sync_linked_identity($userId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'middle_name' => $ident['middle_name'] ?? null,
            'name_suffix' => $nameSuffix,
            'sex' => $ident['sex'] ?? null,
            'date_of_birth' => $ident['date_of_birth'] ?? null,
            'nationality' => $ident['nationality'] ?? null,
            'languages' => $ident['languages'] ?? null,
            'address' => $ident['address'] ?? null,
            'address_region' => $ident['address_region'] ?? null,
            'address_city' => $ident['address_city'] ?? null,
            'address_barangay' => $ident['address_barangay'] ?? null,
            'address_street' => $ident['address_street'] ?? null,
            'current_same_as_permanent' => (int) ($ident['current_same_as_permanent'] ?? 0),
            'current_address' => $ident['current_address'] ?? null,
            'current_address_region' => $ident['current_address_region'] ?? null,
            'current_address_city' => $ident['current_address_city'] ?? null,
            'current_address_barangay' => $ident['current_address_barangay'] ?? null,
            'current_address_street' => $ident['current_address_street'] ?? null,
            'phone' => $phone,
            'avatar' => $avatar,
            'emergency_contact_name' => $emergency['emergency_contact_name'] ?? null,
            'emergency_contact_relationship' => $emergency['emergency_contact_relationship'] ?? null,
            'emergency_contact_phone' => $emergency['emergency_contact_phone'] ?? null,
        ]);
        // If registering the second linked role, pull any missing avatar from sibling.
        if ($avatar === null) {
            $siblings = linked_role_accounts($email, $userId);
            foreach ($siblings as $sib) {
                $sibRow = db()->prepare('SELECT avatar FROM users WHERE id=? LIMIT 1');
                $sibRow->execute([(int) $sib['id']]);
                $sibAvatar = trim((string) ($sibRow->fetchColumn() ?: ''));
                if ($sibAvatar !== '') {
                    db()->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$sibAvatar, $userId]);
                    break;
                }
            }
        }
    }

    if ($roleId === ROLE_TOUR_GUIDE) {
        ensure_guide_documents_schema();
        $guideApproval = $adminCreated ? 'approved' : 'pending';
        $approvedBy = $adminCreated ? ((int) ($data['approved_by'] ?? 0) ?: null) : null;
        db()->prepare(
            'INSERT INTO tour_guide_profiles (user_id, bio, languages, years_experience, hourly_rate, credential_file, approval_status, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            null,
            $guideLanguages,
            $guideYears,
            guide_office_daily_rate(),
            null,
            $guideApproval,
            $approvedBy,
            $adminCreated ? date('Y-m-d H:i:s') : null,
        ]);
        $guideId = (int) db()->lastInsertId();
        if (!empty($data['credential_files']) && is_array($data['credential_files'])) {
            save_guide_documents($guideId, $data['credential_files']);
        } elseif (!empty($data['credential_file'])) {
            db()->prepare(
                'INSERT INTO guide_documents (guide_id, file_path, original_name) VALUES (?,?,?)'
            )->execute([$guideId, $data['credential_file'], basename((string) $data['credential_file'])]);
            sync_guide_credential_file($guideId);
        }
        if (!$adminCreated) {
            // Notify all admins
            $admins = db()->query('SELECT id FROM users WHERE role_id=' . ROLE_ADMIN . " AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                notify_user(
                    (int)$adminId,
                    'guide_registration',
                    'New tour guide registration',
                    trim($firstName . ' ' . $lastName) . ' submitted a guide application.',
                    APP_URL . '/admin/guides.php'
                );
            }
        }
    }

    if ($roleId === ROLE_BUSINESS && $bizListing) {
        ensure_business_documents_schema();
        $logo = null;
        if (!empty($data['logo_file']) && is_array($data['logo_file']) && !empty($data['logo_file']['name'])) {
            $logo = upload_file($data['logo_file'], 'businesses', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        }

        db()->prepare(
            'INSERT INTO businesses (user_id, business_type_id, name, owner_name, representative_name, representative_sex, slug, description, address, address_region, address_city, address_barangay, address_street, phone, email, permit_number, tax_id, logo, document_file, approval_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $bizListing['type_id'],
            $bizListing['name'],
            $bizListing['owner_name'],
            $bizListing['representative_name'] ?? null,
            $bizListing['representative_sex'] ?? null,
            slugify($bizListing['name']) . '-' . $userId,
            $bizListing['description'],
            $bizListing['address'],
            $bizListing['address_region'] ?? null,
            $bizListing['address_city'] ?? null,
            $bizListing['address_barangay'] ?? null,
            $bizListing['address_street'] ?? null,
            $bizListing['phone'],
            $bizListing['email'],
            $bizListing['permit_number'],
            $bizListing['tax_id'],
            $logo,
            null,
            'pending',
        ]);
        $bizId = (int) db()->lastInsertId();
        if (!empty($data['document_files']) && is_array($data['document_files'])) {
            save_business_documents($bizId, $data['document_files']);
        }
        if (!empty($data['photo_files']) && is_array($data['photo_files'])) {
            save_business_gallery_photos($bizId, $data['photo_files']);
        }
        if ($logo === null) {
            $firstPhoto = db()->prepare('SELECT image_path FROM business_photos WHERE business_id=? ORDER BY id ASC LIMIT 1');
            $firstPhoto->execute([$bizId]);
            $photoPath = trim((string) ($firstPhoto->fetchColumn() ?: ''));
            if ($photoPath !== '') {
                db()->prepare('UPDATE businesses SET logo=? WHERE id=?')->execute([$photoPath, $bizId]);
            }
        }
        $admins = db()->query('SELECT id FROM users WHERE role_id=' . ROLE_ADMIN . " AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            notify_user(
                (int)$adminId,
                'business_registration',
                'New business registration',
                $bizListing['name'] . ' submitted a business listing for approval.',
                APP_URL . '/admin/businesses.php'
            );
        }
    }

    log_activity($userId, $adminCreated ? 'admin_create_guide' : 'register', 'auth', 'New user registered as ' . role_label($roleId));
    $notifyTitle = 'Welcome to CulTour';
    $notifyBody = 'Your account has been created successfully.';
    $notifyUrl = role_dashboard($roleId);
    if ($adminCreated) {
        $notifyUrl = APP_URL . '/change-password.php';
        if ($roleId === ROLE_TOURIST) {
            $notifyTitle = 'Tourist account created';
            $notifyBody = 'Your CulTour tourist account is ready. Log in with the temporary password and set a new one.';
        } else {
            $notifyTitle = 'Tour guide account created';
            $notifyBody = 'Your CulTour guide account is ready. Log in with the temporary password and set a new one.';
        }
    }
    notify_user($userId, $adminCreated ? 'approval' : 'welcome', $notifyTitle, $notifyBody, $notifyUrl);

    if (!$adminCreated) {
        send_welcome_email($email, $firstName, $roleId);
    }

    return [
        'ok' => true,
        'message' => $status === 'pending'
            ? 'Registration submitted. Please wait for Tourism Office approval.'
            : 'Account created successfully. You may now log in.',
        'user_id' => $userId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'languages' => (string) ($roleId === ROLE_TOUR_GUIDE ? ($guideLanguages ?? '') : ($ident['languages'] ?? '')),
    ];
}

function request_password_reset(string $email, string $portal = 'public'): array
{
    ensure_email_role_unique_schema();
    $portal = auth_portal($portal);
    $email = strtolower(trim($email));
    $stmt = db()->prepare('SELECT id, email, first_name, role_id FROM users WHERE email = ? ORDER BY id ASC');
    $stmt->execute([$email]);
    $users = array_values(array_filter(
        $stmt->fetchAll() ?: [],
        static function (array $user) use ($portal): bool {
            return auth_portal_allows_role((int) ($user['role_id'] ?? 0), $portal);
        }
    ));

    $mailEnabled = defined('MAIL_FROM') && MAIL_FROM !== ''
        && defined('MAIL_SMTP_PASS') && MAIL_SMTP_PASS !== '';
    $msg = 'If an account exists for that email, we sent a 6-digit code. Enter it below to set a new password.';

    if (!$users) {
        return ['ok' => true, 'message' => $msg, 'emailed' => false];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = hash('sha256', $code);
    $upd = db()->prepare(
        'UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?'
    );
    foreach ($users as $user) {
        $upd->execute([$codeHash, (int) $user['id']]);
    }
    $user = $users[0];

    $emailed = false;
    if ($mailEnabled) {
        $name = trim((string) ($user['first_name'] ?? ''));
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';
        $emailed = send_app_mail(
            (string) $user['email'],
            'Your CulTour password reset code',
            $greeting . "\n\n"
            . "Your password reset code is: {$code}\n\n"
            . "Enter this code on the CulTour reset page to choose a new password.\n"
            . "This code expires in 15 minutes.\n\n"
            . "If you did not request this, you can ignore this email.\n\n— " . APP_NAME
        );
    }

    return [
        'ok' => true,
        'message' => $msg,
        'emailed' => $emailed,
        'user_id' => (int) $user['id'],
    ];
}

/**
 * Confirm the emailed 6-digit reset code before allowing a new password.
 */
function verify_password_reset_code(string $email, string $code, string $portal = 'public'): array
{
    $email = strtolower(trim($email));
    $code = preg_replace('/\D+/', '', (string) $code) ?? '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter the email you used for the reset code.'];
    }
    if (strlen($code) !== 6) {
        return ['ok' => false, 'message' => 'Enter the 6-digit code from your email.'];
    }

    $throttle = auth_throttle_status('reset', $email);
    if ($throttle['locked']) {
        return ['ok' => false, 'message' => auth_locked_message($throttle['retry_minutes'], 'code')];
    }

    $badCode = 'That code is incorrect or has expired (codes last 15 minutes). Check the latest email from CulTour, or request a new code.';
    $portal = auth_portal($portal);
    $stmt = db()->prepare(
        'SELECT id, role_id, reset_token FROM users
         WHERE email = ? AND reset_token IS NOT NULL AND reset_token_expires > NOW()'
    );
    $stmt->execute([$email]);
    $users = array_values(array_filter(
        $stmt->fetchAll() ?: [],
        static function (array $user) use ($portal): bool {
            return auth_portal_allows_role((int) ($user['role_id'] ?? 0), $portal);
        }
    ));
    if (!$users) {
        auth_throttle_fail('reset', $email);
        return ['ok' => false, 'message' => $badCode];
    }

    $codeHash = hash('sha256', $code);
    foreach ($users as $user) {
        $stored = (string) ($user['reset_token'] ?? '');
        if ($stored !== '' && hash_equals($stored, $codeHash)) {
            auth_throttle_clear('reset', $email);
            return ['ok' => true, 'message' => 'Code confirmed. Choose a new password.', 'code_hash' => $codeHash];
        }
    }

    auth_throttle_fail('reset', $email);
    return ['ok' => false, 'message' => $badCode];
}

/**
 * Set a new password after the reset code has been verified.
 */
function reset_password(string $email, string $codeHash, string $password, string $confirm, string $portal = 'public'): array
{
    $email = strtolower(trim($email));
    $codeHash = trim((string) $codeHash);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter the email you used for the reset code.'];
    }
    if ($codeHash === '' || !preg_match('/^[a-f0-9]{64}$/', $codeHash)) {
        return ['ok' => false, 'message' => 'Enter the 6-digit code from your email first.'];
    }

    $pwCheck = validate_password_rules($password, $confirm);
    if (!$pwCheck['ok']) {
        return ['ok' => false, 'message' => $pwCheck['message']];
    }

    $throttle = auth_throttle_status('reset', $email);
    if ($throttle['locked']) {
        return ['ok' => false, 'message' => auth_locked_message($throttle['retry_minutes'], 'code')];
    }

    $badCode = 'Your reset code has expired (codes last 15 minutes). Request a new code and try again.';
    $portal = auth_portal($portal);
    $stmt = db()->prepare(
        'SELECT id, role_id, reset_token, password_hash FROM users
         WHERE email = ? AND reset_token IS NOT NULL AND reset_token_expires > NOW()'
    );
    $stmt->execute([$email]);
    $users = array_values(array_filter(
        $stmt->fetchAll() ?: [],
        static function (array $user) use ($portal): bool {
            return auth_portal_allows_role((int) ($user['role_id'] ?? 0), $portal);
        }
    ));
    if (!$users) {
        auth_throttle_fail('reset', $email);
        return ['ok' => false, 'message' => $badCode];
    }

    $matched = [];
    foreach ($users as $user) {
        $stored = (string) ($user['reset_token'] ?? '');
        if ($stored !== '' && hash_equals($stored, $codeHash)) {
            $matched[] = $user;
        }
    }
    if (!$matched) {
        auth_throttle_fail('reset', $email);
        return ['ok' => false, 'message' => $badCode];
    }

    foreach ($matched as $user) {
        $currentHash = (string) ($user['password_hash'] ?? '');
        if ($currentHash !== '' && password_verify($password, $currentHash)) {
            return ['ok' => false, 'message' => 'New password must be different from your current password.'];
        }
    }

    ensure_password_schema();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upd = db()->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 0, reset_token = NULL, reset_token_expires = NULL WHERE id = ?'
    );
    foreach ($matched as $user) {
        $upd->execute([$hash, (int) $user['id']]);
        log_activity((int) $user['id'], 'password_reset', 'auth');
    }
    auth_throttle_clear('reset', $email);
    auth_throttle_clear('login', $email);

    return ['ok' => true, 'message' => 'Password updated. You can now log in.'];
}
