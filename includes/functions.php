<?php
/**
 * CulTour Helper Functions
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ph_address.php';

if (PHP_SAPI !== 'cli') {
    send_security_headers();
}

function app_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), geolocation=(self), microphone=(), payment=()');
    // Inline scripts and CDN assets are still used, so only the directives that cannot break pages are enforced.
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
    if (app_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Own folder so other XAMPP apps' shorter session cleanup can't delete CulTour logins.
        if (is_dir(SESSION_SAVE_PATH) || @mkdir(SESSION_SAVE_PATH, 0700, true)) {
            session_save_path(SESSION_SAVE_PATH);
        }
        ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_SECONDS);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => app_request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function ensure_auth_attempts_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS auth_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(20) NOT NULL,
                identifier VARCHAR(190) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_auth_attempts_ident (scope, identifier, ip_address, attempted_at),
                KEY idx_auth_attempts_ip (scope, ip_address, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM auth_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Failed-attempt lockout for sign-in and reset codes.
 * Locks one email from one IP after AUTH_MAX_FAILS, and one IP across all emails after AUTH_IP_MAX_FAILS.
 *
 * @return array{locked:bool,retry_minutes:int,remaining:int}
 */
function auth_throttle_status(string $scope, string $identifier): array
{
    ensure_auth_attempts_schema();
    $identifier = strtolower(trim($identifier));
    $ip = client_ip();
    $window = AUTH_LOCK_MINUTES;
    try {
        $st = db()->prepare(
            'SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ' . $window . ' MINUTE)) AS wait_s
             FROM auth_attempts
             WHERE scope = ? AND identifier = ? AND ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . $window . ' MINUTE)'
        );
        $st->execute([$scope, $identifier, $ip]);
        $row = $st->fetch() ?: ['n' => 0, 'wait_s' => 0];
        $n = (int) $row['n'];
        $waitS = (int) $row['wait_s'];

        $ipSt = db()->prepare(
            'SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ' . $window . ' MINUTE)) AS wait_s
             FROM auth_attempts
             WHERE scope = ? AND ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . $window . ' MINUTE)'
        );
        $ipSt->execute([$scope, $ip]);
        $ipRow = $ipSt->fetch() ?: ['n' => 0, 'wait_s' => 0];
    } catch (Throwable $e) {
        return ['locked' => false, 'retry_minutes' => 0, 'remaining' => AUTH_MAX_FAILS];
    }

    if ($n >= AUTH_MAX_FAILS || (int) $ipRow['n'] >= AUTH_IP_MAX_FAILS) {
        $wait = max($n >= AUTH_MAX_FAILS ? $waitS : 0, (int) $ipRow['n'] >= AUTH_IP_MAX_FAILS ? (int) $ipRow['wait_s'] : 0);
        return ['locked' => true, 'retry_minutes' => max(1, (int) ceil($wait / 60)), 'remaining' => 0];
    }
    return ['locked' => false, 'retry_minutes' => 0, 'remaining' => AUTH_MAX_FAILS - $n];
}

function auth_throttle_fail(string $scope, string $identifier): void
{
    ensure_auth_attempts_schema();
    try {
        db()->prepare('INSERT INTO auth_attempts (scope, identifier, ip_address, attempted_at) VALUES (?, ?, ?, NOW())')
            ->execute([$scope, strtolower(trim($identifier)), client_ip()]);
    } catch (Throwable $e) {
        // ignore
    }
}

function auth_throttle_clear(string $scope, string $identifier): void
{
    ensure_auth_attempts_schema();
    try {
        db()->prepare('DELETE FROM auth_attempts WHERE scope = ? AND identifier = ? AND ip_address = ?')
            ->execute([$scope, strtolower(trim($identifier)), client_ip()]);
    } catch (Throwable $e) {
        // ignore
    }
}

function auth_locked_message(int $minutes, string $what = 'sign-in'): string
{
    $label = $minutes === 1 ? '1 minute' : $minutes . ' minutes';
    return 'Too many failed ' . $what . ' attempts. For your security, please wait ' . $label
        . ' before trying again, or use "Forgot password?" to reset your password.';
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Laptop clock from the cultour_local_clock cookie ("tz|offset", or legacy "Y-m-d|minutes"), else PHP now.
 *
 * @return array{today:string,mins:int}
 */
function app_local_clock(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    $parsed = [
        'today' => date('Y-m-d'),
        'mins' => ((int) date('G')) * 60 + (int) date('i'),
    ];
    $raw = $_COOKIE['cultour_local_clock'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return $parsed;
    }
    $raw = urldecode(trim($raw));
    // Browser UTC offset (JS getTimezoneOffset); "now" comes from the server clock so it never goes stale.
    if (preg_match('/^tz\|(-?\d{1,4})$/', $raw, $m)) {
        $offset = (int) $m[1];
        if ($offset >= -14 * 60 && $offset <= 12 * 60) {
            $local = time() - $offset * 60;
            $parsed = [
                'today' => gmdate('Y-m-d', $local),
                'mins' => ((int) gmdate('G', $local)) * 60 + (int) gmdate('i', $local),
            ];
        }
        return $parsed;
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d{1,4})$/', $raw, $m)) {
        return $parsed;
    }
    $today = function_exists('normalize_tour_date')
        ? normalize_tour_date($m[1])
        : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $m[1]) ? $m[1] : null);
    $mins = (int) $m[2];
    if ($today === null || $mins < 0 || $mins > (23 * 60 + 59)) {
        return $parsed;
    }
    $parsed = ['today' => $today, 'mins' => $mins];
    return $parsed;
}

/** Calendar date on this PC (cookie) or the server clock. */
function app_today(): string
{
    return app_local_clock()['today'];
}

/** Minutes from midnight on this PC (cookie) or the server clock. */
function app_now_mins(): int
{
    return app_local_clock()['mins'];
}

/**
 * Capitalize the first letter of a name (rest unchanged aside from trim).
 */
function capitalize_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return '';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
    }
    return ucfirst($name);
}

/**
 * Normalize a PH mobile number to +63XXXXXXXXXX (10 local digits starting with 9).
 * @return array{ok:bool,phone:?string,message:?string}
 */
function normalize_ph_phone(string $raw, bool $required = false): array
{
    $rawTrim = trim($raw);
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    // Allow pasting +63… or 63… or 0…
    if (str_starts_with($digits, '63') && strlen($digits) >= 12) {
        $digits = substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        $digits = ltrim($digits, '0');
    }
    $digits = substr($digits, 0, 10);

    if ($digits === '') {
        if ($required || $rawTrim !== '') {
            return [
                'ok' => false,
                'phone' => null,
                'message' => $required && $rawTrim === ''
                    ? 'Phone number is required.'
                    : 'Enter a valid mobile number.',
            ];
        }
        return ['ok' => true, 'phone' => null, 'message' => null];
    }
    if (str_starts_with($digits, '0')) {
        return ['ok' => false, 'phone' => null, 'message' => 'Phone number should not start with 0.'];
    }
    if (strlen($digits) !== 10) {
        return ['ok' => false, 'phone' => null, 'message' => 'Phone number must be exactly 10 digits.'];
    }
    if ($digits[0] !== '9') {
        return ['ok' => false, 'phone' => null, 'message' => 'Enter a valid mobile number.'];
    }
    if (!preg_match('/^\d{10}$/', $digits)) {
        return ['ok' => false, 'phone' => null, 'message' => 'Enter a valid mobile number.'];
    }
    return ['ok' => true, 'phone' => '+63' . $digits, 'message' => null];
}

/**
 * True when another CulTour account already uses this mobile number.
 * Accounts with the same email (linked Tourist / Tour Guide) may share a number.
 * Business numbers are checked only against other businesses, so a business
 * phone can also be used on a Tourist or Tour Guide account.
 */
function phone_taken_by_other_account(?string $phone, string $email = '', ?int $exceptUserId = null, ?int $roleId = null): bool
{
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
    $digits = substr($digits, -10);
    if (strlen($digits) !== 10) {
        return false;
    }
    $sql = "SELECT id FROM users
            WHERE phone IS NOT NULL AND phone <> ''
              AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), 10) = ?";
    $params = [$digits];
    $email = strtolower(trim($email));
    if ($email !== '') {
        $sql .= ' AND LOWER(email) <> ?';
        $params[] = $email;
    }
    if ($exceptUserId !== null && $exceptUserId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptUserId;
    }
    if ($roleId !== null && $roleId > 0) {
        $sql .= $roleId === ROLE_BUSINESS ? ' AND role_id = ?' : ' AND role_id <> ?';
        $params[] = ROLE_BUSINESS;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return (bool) $stmt->fetch();
}

function phone_taken_message(): string
{
    return 'This phone number is already used by another CulTour account. Use a different number, or sign in to that account.';
}

/** Default temp passwords for admin-created accounts */
const DEFAULT_BUSINESS_PASSWORD = 'bsnss123';
const DEFAULT_GUIDE_PASSWORD = 'guide123';
const DEFAULT_TOURIST_PASSWORD = 'tourist123';

/** Admin-created tour guide password: "guide" + 3 random digits. */
function admin_random_guide_password(): string
{
    return 'guide' . random_int(0, 9) . random_int(0, 9) . random_int(0, 9);
}

/** Admin-created business owner password: "bsns" + 3 random digits. */
function admin_random_business_password(): string
{
    return 'bsns' . random_int(0, 9) . random_int(0, 9) . random_int(0, 9);
}

/** Shared Tourism Office daily rate, used for every tour guide. */
function guide_office_daily_rate(): int
{
    try {
        $rows = db()->query(
            'SELECT hourly_rate, COUNT(*) AS n FROM tour_guide_profiles GROUP BY hourly_rate'
        )->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }
    $positive = [];
    foreach ($rows as $row) {
        $rate = (int) $row['hourly_rate'];
        if ($rate > 0) {
            $positive[] = ['rate' => $rate, 'n' => (int) $row['n']];
        }
    }
    if (!$positive) {
        return 0;
    }
    usort($positive, static function (array $a, array $b): int {
        return $b['n'] <=> $a['n'] ?: $b['rate'] <=> $a['rate'];
    });
    return $positive[0]['rate'];
}

/** Give every tour guide the Tourism Office daily rate when their rate is still empty. */
function apply_office_daily_rate_to_unset_guides(): int
{
    $rate = guide_office_daily_rate();
    if ($rate < 1) {
        return 0;
    }
    $st = db()->prepare('UPDATE tour_guide_profiles SET hourly_rate=? WHERE hourly_rate IS NULL OR hourly_rate < 1');
    $st->execute([$rate]);
    return $st->rowCount();
}

/** Admin-created tourist password: "tourist" + 3 random digits. */
function admin_random_tourist_password(): string
{
    return 'tourist' . random_int(0, 9) . random_int(0, 9) . random_int(0, 9);
}

/** Max stay at one itinerary stop (opening hours still block leave-after-close). */
const ITIN_MAX_VISIT_MINUTES = 12 * 60;

/**
 * Ensure users.must_change_password exists.
 */
function ensure_password_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('must_change_password', $cols, true)) {
            db()->exec(
                'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER remember_token'
            );
            $cols[] = 'must_change_password';
        }
        if (!in_array('must_complete_profile', $cols, true)) {
            db()->exec(
                'ALTER TABLE users ADD COLUMN must_complete_profile TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password'
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    ensure_email_role_unique_schema();
}

/**
 * Allow the same email across different roles; unique only per (email, role_id).
 */
function ensure_email_role_unique_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $rows = db()->query('SHOW INDEX FROM users')->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || !$rows) {
            return;
        }
        $byKey = [];
        foreach ($rows as $row) {
            $key = (string) ($row['Key_name'] ?? '');
            if ($key === '' || $key === 'PRIMARY') {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                    'cols' => [],
                ];
            }
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            $byKey[$key]['cols'][$seq] = (string) ($row['Column_name'] ?? '');
        }
        $hasEmailRoleUnique = false;
        foreach ($byKey as $key => $info) {
            $cols = array_values($info['cols']);
            if ($info['unique'] && $cols === ['email', 'role_id']) {
                $hasEmailRoleUnique = true;
                continue;
            }
            // Drop email-only unique so one address can register under other roles.
            if ($info['unique'] && $cols === ['email']) {
                db()->exec('ALTER TABLE users DROP INDEX `' . str_replace('`', '``', $key) . '`');
            }
        }
        if (!$hasEmailRoleUnique) {
            db()->exec('ALTER TABLE users ADD UNIQUE KEY users_email_role (email, role_id)');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function role_label(int $roleId): string
{
    return match ($roleId) {
        ROLE_TOURIST => 'Tourist',
        ROLE_TOUR_GUIDE => 'Tour Guide',
        ROLE_BUSINESS => 'Local Business',
        ROLE_ADMIN => 'Admin',
        default => 'Account',
    };
}

/** Whether this email is already used for the given role. */
function email_taken_for_role(string $email, int $roleId, ?int $exceptUserId = null): bool
{
    ensure_email_role_unique_schema();
    $email = strtolower(trim($email));
    if ($email === '' || $roleId < 1) {
        return false;
    }
    if ($exceptUserId !== null && $exceptUserId > 0) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND role_id = ? AND id <> ? LIMIT 1');
        $stmt->execute([$email, $roleId, $exceptUserId]);
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND role_id = ? LIMIT 1');
        $stmt->execute([$email, $roleId]);
    }
    return (bool) $stmt->fetch();
}

/** True when the user's effective current address is Ormoc City, Leyte. */
function user_current_address_in_leyte(array $user): bool
{
    $lock = function_exists('ph_guide_locked_province') ? ph_guide_locked_province() : 'Leyte';
    $cityLock = function_exists('ph_guide_locked_city') ? ph_guide_locked_city() : 'Ormoc City';
    $same = !empty($user['current_same_as_permanent']);
    $prov = $same
        ? trim((string) ($user['address_region'] ?? ''))
        : trim((string) ($user['current_address_region'] ?? ''));
    $city = $same
        ? trim((string) ($user['address_city'] ?? ''))
        : trim((string) ($user['current_address_city'] ?? ''));
    if ($prov === '') {
        $prov = trim((string) ($user['address_region'] ?? ''));
    }
    if ($city === '') {
        $city = trim((string) ($user['address_city'] ?? ''));
    }
    return $prov !== '' && strcasecmp($prov, $lock) === 0
        && $city !== '' && strcasecmp($city, $cityLock) === 0;
}

/**
 * Personal identity columns on users (registration / profiles).
 */
function ensure_user_identity_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return;
        }
        $after = in_array('last_name', $cols, true) ? 'last_name' : null;
        $add = [
            'middle_name' => 'VARCHAR(100) NULL',
            'name_suffix' => 'VARCHAR(4) NULL',
            'sex' => "ENUM('female','male','prefer_not') NULL",
            'date_of_birth' => 'DATE NULL',
            'nationality' => 'VARCHAR(100) NULL',
            'languages' => 'VARCHAR(255) NULL',
            'address' => 'VARCHAR(255) NULL',
            'address_region' => 'VARCHAR(100) NULL',
            'address_city' => 'VARCHAR(120) NULL',
            'address_barangay' => 'VARCHAR(120) NULL',
            'address_street' => 'VARCHAR(150) NULL',
            'current_same_as_permanent' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'current_address' => 'VARCHAR(255) NULL',
            'current_address_region' => 'VARCHAR(100) NULL',
            'current_address_city' => 'VARCHAR(120) NULL',
            'current_address_barangay' => 'VARCHAR(120) NULL',
            'current_address_street' => 'VARCHAR(150) NULL',
            'emergency_contact_name' => 'VARCHAR(150) NULL',
            'emergency_contact_relationship' => 'VARCHAR(80) NULL',
            'emergency_contact_phone' => 'VARCHAR(30) NULL',
        ];
        foreach ($add as $col => $def) {
            if (in_array($col, $cols, true)) {
                $after = $col;
                continue;
            }
            $sql = 'ALTER TABLE users ADD COLUMN `' . $col . '` ' . $def;
            if ($after !== null) {
                $sql .= ' AFTER `' . $after . '`';
            }
            db()->exec($sql);
            $cols[] = $col;
            $after = $col;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Comma-separated languages a person speaks. */
function normalize_spoken_languages(string $raw): string
{
    $parts = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $parts[] = $part;
    }
    $out = implode(', ', $parts);
    if (strlen($out) > 255) {
        $out = rtrim(substr($out, 0, 255), " \t,");
    }
    return $out;
}

/** Spoken languages from a users row, falling back to a tour guide profile. */
function user_spoken_languages_from_row(array $src): string
{
    $langs = normalize_spoken_languages((string) ($src['languages'] ?? ''));
    if ($langs !== '') {
        return $langs;
    }
    $roleId = (int) ($src['role_id'] ?? 0);
    $userId = (int) ($src['id'] ?? $src['user_id'] ?? 0);
    if ($roleId !== ROLE_TOUR_GUIDE || $userId < 1) {
        return '';
    }
    try {
        $st = db()->prepare('SELECT languages FROM tour_guide_profiles WHERE user_id=? LIMIT 1');
        $st->execute([$userId]);
        return normalize_spoken_languages((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/** @return array<string,string> */
function user_sex_options(): array
{
    return [
        'female' => 'Female',
        'male' => 'Male',
        'prefer_not' => 'Prefer not to say',
    ];
}

function user_sex_label(?string $sex): string
{
    $sex = trim((string) $sex);
    $opts = user_sex_options();
    return $opts[$sex] ?? '—';
}

/**
 * Parse personal identity fields from a form/API payload.
 *
 * @return array{ok:bool,message?:string,data?:array{middle_name:?string,sex:?string,date_of_birth:?string,nationality:?string,languages:?string,address:?string,address_region:?string,address_city:?string,address_barangay:?string,address_street:?string,current_same_as_permanent:int,current_address:?string,current_address_region:?string,current_address_city:?string,current_address_barangay:?string,current_address_street:?string}}
 */
function user_parse_identity(array $input, bool $required = true): array
{
    ensure_user_identity_schema();
    $middle = capitalize_name((string) ($input['middle_name'] ?? ''));
    $suffixPosted = array_key_exists('name_suffix_choice', $input) || array_key_exists('name_suffix', $input);
    $suffix = $suffixPosted
        ? (array_key_exists('name_suffix_choice', $input)
            ? resolve_posted_name_suffix('name_suffix', $input)
            : (string) ($input['name_suffix'] ?? ''))
        : null;
    $sex = trim((string) ($input['sex'] ?? ''));
    $dobRaw = trim((string) ($input['date_of_birth'] ?? ''));
    $nationality = trim((string) ($input['nationality'] ?? ''));
    $languages = normalize_spoken_languages((string) ($input['tourist_languages'] ?? ''));
    if ($languages === '') {
        $languages = normalize_spoken_languages((string) ($input['languages'] ?? ''));
    }
    $address = trim((string) ($input['address'] ?? ''));
    $addrRegion = null;
    $addrCity = null;
    $addrBarangay = null;
    $addrStreet = null;
    $sameAsPermanent = 0;
    $curAddress = null;
    $curRegion = null;
    $curCity = null;
    $curBarangay = null;
    $curStreet = null;
    $sexOk = $sex !== '' && array_key_exists($sex, user_sex_options());
    $dob = $dobRaw !== '' ? (normalize_tour_date($dobRaw) ?? null) : null;

    if (input_has_structured_address($input)) {
        $permInput = $input;
        unset($permInput['address_lock_province']);
        $structured = parse_tourist_structured_address($permInput, $required);
        if (!$structured['ok']) {
            return ['ok' => false, 'message' => (string) ($structured['message'] ?? 'Please complete your permanent address.')];
        }
        $sd = $structured['data'];
        $address = (string) ($sd['address'] ?? '');
        $addrRegion = $sd['address_region'] !== '' ? $sd['address_region'] : null;
        $addrCity = $sd['address_city'] !== '' ? $sd['address_city'] : null;
        $addrBarangay = $sd['address_barangay'] !== '' ? $sd['address_barangay'] : null;
        $addrStreet = $sd['address_street'] !== '' ? $sd['address_street'] : null;

        $sameRaw = $input['current_same_as_permanent'] ?? '0';
        if (is_array($sameRaw)) {
            $sameRaw = end($sameRaw);
        }
        $sameRaw = (string) $sameRaw;
        $sameAsPermanent = ($sameRaw === '0' || strcasecmp($sameRaw, 'no') === 0) ? 0 : 1;
        $requireCurrentProv = trim((string) ($input['require_current_province'] ?? ''));

        if ($sameAsPermanent === 1) {
            $cityLock = $requireCurrentProv !== '' && function_exists('ph_guide_locked_city') ? ph_guide_locked_city() : '';
            if ($requireCurrentProv !== '' && (
                strcasecmp((string) $addrRegion, $requireCurrentProv) !== 0
                || ($cityLock !== '' && strcasecmp((string) $addrCity, $cityLock) !== 0)
            )) {
                return [
                    'ok' => false,
                    'message' => 'Only tour guides who currently live in Ormoc City can register on CulTour.',
                ];
            }
            $curAddress = $address !== '' ? $address : null;
            $curRegion = $addrRegion;
            $curCity = $addrCity;
            $curBarangay = $addrBarangay;
            $curStreet = $addrStreet;
        } else {
            $curInput = $input;
            if ($requireCurrentProv !== '') {
                $curInput['address_lock_province'] = $requireCurrentProv;
                $curInput['current_address_region'] = $requireCurrentProv;
            } else {
                unset($curInput['address_lock_province']);
            }
            $curStructured = parse_tourist_structured_address($curInput, $required, 'current_');
            if (!$curStructured['ok']) {
                return ['ok' => false, 'message' => (string) ($curStructured['message'] ?? 'Please complete your current address.')];
            }
            $cd = $curStructured['data'];
            $curAddress = $cd['address'] !== '' ? $cd['address'] : null;
            $curRegion = $cd['address_region'] !== '' ? $cd['address_region'] : null;
            $curCity = $cd['address_city'] !== '' ? $cd['address_city'] : null;
            $curBarangay = $cd['address_barangay'] !== '' ? $cd['address_barangay'] : null;
            $curStreet = $cd['address_street'] !== '' ? $cd['address_street'] : null;
            $cityLock = $requireCurrentProv !== '' && function_exists('ph_guide_locked_city') ? ph_guide_locked_city() : '';
            if ($cityLock !== '' && strcasecmp((string) $curCity, $cityLock) !== 0) {
                return [
                    'ok' => false,
                    'message' => 'Only tour guides who currently live in Ormoc City can register on CulTour.',
                ];
            }
        }
    }

    if ($required) {
        if (!$sexOk) {
            return ['ok' => false, 'message' => 'Please select sex.'];
        }
        if ($dob === null) {
            return ['ok' => false, 'message' => 'Enter a valid date of birth.'];
        }
        if ($dob >= date('Y-m-d')) {
            return ['ok' => false, 'message' => 'Date of birth must be in the past.'];
        }
        try {
            $age = (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Enter a valid date of birth.'];
        }
        if ($age < 18) {
            return ['ok' => false, 'message' => 'You must be at least 18 years old to register.'];
        }
        if ($nationality === '') {
            return ['ok' => false, 'message' => 'Nationality is required.'];
        }
        if ($languages === '' && (int) ($input['role_id'] ?? 0) !== ROLE_TOUR_GUIDE) {
            return ['ok' => false, 'message' => 'Please list the language(s) you speak.'];
        }
        if ($address === '') {
            return ['ok' => false, 'message' => 'Permanent address is required.'];
        }
    } else {
        if ($sex !== '' && !$sexOk) {
            return ['ok' => false, 'message' => 'Please select a valid sex option.'];
        }
        if ($dobRaw !== '' && $dob === null) {
            return ['ok' => false, 'message' => 'Enter a valid date of birth.'];
        }
        if ($dob !== null) {
            if ($dob >= date('Y-m-d')) {
                return ['ok' => false, 'message' => 'Date of birth must be in the past.'];
            }
            $age = (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
            if ($age < 18) {
                return ['ok' => false, 'message' => 'You must be at least 18 years old.'];
            }
        }
    }

    $data = [
            'middle_name' => $middle !== '' ? $middle : null,
            'sex' => $sexOk ? $sex : null,
            'date_of_birth' => $dob,
            'nationality' => $nationality !== '' ? $nationality : null,
            'languages' => $languages !== '' ? $languages : null,
            'address' => $address !== '' ? $address : null,
            'address_region' => $addrRegion,
            'address_city' => $addrCity,
            'address_barangay' => $addrBarangay,
            'address_street' => $addrStreet,
            'current_same_as_permanent' => $sameAsPermanent,
            'current_address' => $curAddress,
            'current_address_region' => $curRegion,
            'current_address_city' => $curCity,
            'current_address_barangay' => $curBarangay,
            'current_address_street' => $curStreet,
    ];
    if ($suffixPosted) {
        $data['name_suffix'] = normalize_name_suffix((string) $suffix);
    }

    return [
        'ok' => true,
        'data' => $data,
    ];
}

function current_address_same_radios_html(bool $sameAs = false, string $prefix = 'addrSame'): string
{
    $yesId = $prefix . 'Yes';
    ob_start();
    ?>
    <div class="address-same-head">
      <div class="address-same-head__title">Current address</div>
      <input type="hidden" name="current_same_as_permanent" value="0">
      <div class="form-check address-same-head__opt">
        <input class="form-check-input" type="checkbox" name="current_same_as_permanent" id="<?= e($yesId) ?>" value="1"<?= $sameAs ? ' checked' : '' ?>>
        <label class="form-check-label" for="<?= e($yesId) ?>">Same as permanent address</label>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function ph_phone_local_digits(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
    if (str_starts_with($digits, '63') && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }
    return substr(ltrim($digits, '0'), 0, 10);
}

/**
 * @return array{ok:bool,message?:string,data?:array{emergency_contact_name:?string,emergency_contact_relationship:?string,emergency_contact_phone:?string}}
 */
function user_parse_emergency_contact(array $input, bool $required = true): array
{
    ensure_user_identity_schema();

    $first = capitalize_name((string) ($input['emergency_first_name'] ?? ''));
    $middle = capitalize_name((string) ($input['emergency_middle_name'] ?? ''));
    $last = capitalize_name((string) ($input['emergency_last_name'] ?? ''));
    $suffix = array_key_exists('emergency_suffix_choice', $input)
        ? resolve_posted_name_suffix('emergency_suffix', $input)
        : (string) ($input['emergency_suffix'] ?? '');
    $relationship = array_key_exists('emergency_relationship_choice', $input)
        ? resolve_posted_emergency_relationship($input)
        : trim((string) ($input['emergency_relationship'] ?? $input['emergency_contact_relationship'] ?? ''));
    $usedSplit = $first !== '' || $middle !== '' || $last !== '' || trim($suffix) !== ''
        || array_key_exists('emergency_first_name', $input)
        || array_key_exists('emergency_last_name', $input);

    if ($usedSplit && (array_key_exists('emergency_first_name', $input) || array_key_exists('emergency_last_name', $input))) {
        foreach ([$first, $middle, $last, $suffix] as $part) {
            if (person_name_has_invalid_chars($part)) {
                return ['ok' => false, 'message' => 'Emergency contact name may only contain letters.'];
            }
        }
        if ($required && ($first === '' || $last === '')) {
            return ['ok' => false, 'message' => 'Emergency contact first and last name are required.'];
        }
        $name = join_person_name($first, $middle, $last, $suffix);
    } else {
        $name = capitalize_name((string) ($input['emergency_contact_name'] ?? ''));
        if (person_name_has_invalid_chars($name)) {
            return ['ok' => false, 'message' => 'Emergency contact name may only contain letters.'];
        }
        if ($required && $name === '') {
            return ['ok' => false, 'message' => 'Emergency contact name is required.'];
        }
    }

    if ($relationship !== '' && person_name_has_invalid_chars($relationship)) {
        return ['ok' => false, 'message' => 'Enter a valid relationship.'];
    }
    if ($required && $relationship === '') {
        return ['ok' => false, 'message' => 'Emergency contact relationship is required.'];
    }

    $phoneRaw = (string) ($input['emergency_phone_local'] ?? $input['emergency_contact_phone'] ?? '');
    $phoneCheck = normalize_ph_phone($phoneRaw, $required);
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'message' => $required
            ? 'Enter a valid emergency contact number.'
            : (string) ($phoneCheck['message'] ?? 'Enter a valid emergency contact number.')];
    }
    return [
        'ok' => true,
        'data' => [
            'emergency_contact_name' => $name !== '' ? $name : null,
            'emergency_contact_relationship' => $relationship !== '' ? capitalize_name($relationship) : null,
            'emergency_contact_phone' => $phoneCheck['phone'] ?? null,
        ],
    ];
}

/** Normalize a person name for equality checks. */
function normalize_person_name_key(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name) ?? '';
    return $name;
}

/**
 * Tourist account and emergency contact must not share the same name or phone.
 *
 * @param array{name?:string,phone?:string} $account
 * @param array{emergency_contact_name?:?string,emergency_contact_phone?:?string} $emergency
 * @return array{ok:bool,message?:string}
 */
function emergency_contact_differs_from_account(array $account, array $emergency): array
{
    $accName = normalize_person_name_key((string) ($account['name'] ?? ''));
    $emName = normalize_person_name_key((string) ($emergency['emergency_contact_name'] ?? ''));

    $accPhoneRaw = trim((string) ($account['phone'] ?? ''));
    $emPhoneRaw = trim((string) ($emergency['emergency_contact_phone'] ?? ''));
    $accPhoneNorm = normalize_ph_phone($accPhoneRaw, false);
    $emPhoneNorm = normalize_ph_phone($emPhoneRaw, false);
    $accPhone = (string) ($accPhoneNorm['phone'] ?? $accPhoneRaw);
    $emPhone = (string) ($emPhoneNorm['phone'] ?? $emPhoneRaw);

    $sameName = $accName !== '' && $emName !== '' && $accName === $emName;
    $samePhone = $accPhone !== '' && $emPhone !== '' && $accPhone === $emPhone;

    if ($sameName && $samePhone) {
        return ['ok' => false, 'message' => 'Emergency contact cannot use your same name and phone number.'];
    }
    if ($sameName) {
        return ['ok' => false, 'message' => 'Emergency contact cannot have the same name as you.'];
    }
    if ($samePhone) {
        return ['ok' => false, 'message' => 'Emergency contact cannot use your same phone number.'];
    }
    return ['ok' => true];
}

function user_save_emergency_contact(int $userId, array $data): void
{
    ensure_user_identity_schema();
    $name = $data['emergency_contact_name'] ?? null;
    $rel = $data['emergency_contact_relationship'] ?? null;
    $phone = $data['emergency_contact_phone'] ?? null;
    db()->prepare(
        'UPDATE users SET emergency_contact_name=?, emergency_contact_relationship=?, emergency_contact_phone=? WHERE id=?'
    )->execute([$name, $rel, $phone, $userId]);
    sync_linked_identity($userId, [
        'emergency_contact_name' => $name,
        'emergency_contact_relationship' => $rel,
        'emergency_contact_phone' => $phone,
    ]);
}

/**
 * @return array{ok:bool,message?:string}
 */
function user_save_identity(int $userId, array $ident, string $firstName, string $lastName, ?string $phone, ?string $avatar): array
{
    ensure_user_identity_schema();
    $firstName = capitalize_name($firstName);
    $lastName = capitalize_name($lastName);
    if ($firstName === '' || $lastName === '') {
        return ['ok' => false, 'message' => 'First name and last name are required.'];
    }
    $middleName = capitalize_name((string) ($ident['middle_name'] ?? ''));
    $ident['middle_name'] = $middleName !== '' ? $middleName : null;
    $suffix = array_key_exists('name_suffix', $ident)
        ? normalize_name_suffix((string) ($ident['name_suffix'] ?? ''))
        : null;
    foreach ([$firstName, $lastName, $middleName, (string) ($suffix ?? '')] as $namePart) {
        if (person_name_has_invalid_chars($namePart)) {
            return ['ok' => false, 'message' => 'Names may only contain letters.'];
        }
    }
    $phoneCheck = normalize_ph_phone((string) ($phone ?? ''), false);
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'message' => (string) ($phoneCheck['message'] ?? 'Enter a valid mobile number.')];
    }
    $phone = $phoneCheck['phone'];
    if ($phone !== null) {
        $selfStmt = db()->prepare('SELECT email, role_id FROM users WHERE id=? LIMIT 1');
        $selfStmt->execute([$userId]);
        $self = $selfStmt->fetch() ?: [];
        if (phone_taken_by_other_account($phone, (string) ($self['email'] ?? ''), $userId, (int) ($self['role_id'] ?? 0))) {
            return ['ok' => false, 'message' => phone_taken_message()];
        }
    }
    $sameAs = array_key_exists('current_same_as_permanent', $ident)
        ? ((int) $ident['current_same_as_permanent'] === 0 ? 0 : 1)
        : 0;
    $addrCols = 'address=?, address_region=?, address_city=?, address_barangay=?, address_street=?, current_same_as_permanent=?, current_address=?, current_address_region=?, current_address_city=?, current_address_barangay=?, current_address_street=?';
    $addrVals = [
        $ident['address'] ?? null,
        $ident['address_region'] ?? null,
        $ident['address_city'] ?? null,
        $ident['address_barangay'] ?? null,
        $ident['address_street'] ?? null,
        $sameAs,
        $ident['current_address'] ?? null,
        $ident['current_address_region'] ?? null,
        $ident['current_address_city'] ?? null,
        $ident['current_address_barangay'] ?? null,
        $ident['current_address_street'] ?? null,
    ];
    if (array_key_exists('name_suffix', $ident)) {
        db()->prepare(
            'UPDATE users SET first_name=?, last_name=?, middle_name=?, name_suffix=?, sex=?, date_of_birth=?, nationality=?, languages=?, ' . $addrCols . ', phone=?, avatar=? WHERE id=?'
        )->execute(array_merge(
            [
                $firstName,
                $lastName,
                $ident['middle_name'] ?? null,
                $suffix,
                $ident['sex'] ?? null,
                $ident['date_of_birth'] ?? null,
                $ident['nationality'] ?? null,
                $ident['languages'] ?? null,
            ],
            $addrVals,
            [
                $phone !== null && $phone !== '' ? $phone : null,
                $avatar !== null && $avatar !== '' ? $avatar : null,
                $userId,
            ]
        ));
    } else {
        db()->prepare(
            'UPDATE users SET first_name=?, last_name=?, middle_name=?, sex=?, date_of_birth=?, nationality=?, languages=?, ' . $addrCols . ', phone=?, avatar=? WHERE id=?'
        )->execute(array_merge(
            [
                $firstName,
                $lastName,
                $ident['middle_name'] ?? null,
                $ident['sex'] ?? null,
                $ident['date_of_birth'] ?? null,
                $ident['nationality'] ?? null,
                $ident['languages'] ?? null,
            ],
            $addrVals,
            [
                $phone !== null && $phone !== '' ? $phone : null,
                $avatar !== null && $avatar !== '' ? $avatar : null,
                $userId,
            ]
        ));
    }

    $fields = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'middle_name' => $ident['middle_name'] ?? null,
        'sex' => $ident['sex'] ?? null,
        'date_of_birth' => $ident['date_of_birth'] ?? null,
        'nationality' => $ident['nationality'] ?? null,
        'languages' => $ident['languages'] ?? null,
        'address' => $ident['address'] ?? null,
        'address_region' => $ident['address_region'] ?? null,
        'address_city' => $ident['address_city'] ?? null,
        'address_barangay' => $ident['address_barangay'] ?? null,
        'address_street' => $ident['address_street'] ?? null,
        'current_same_as_permanent' => $sameAs,
        'current_address' => $ident['current_address'] ?? null,
        'current_address_region' => $ident['current_address_region'] ?? null,
        'current_address_city' => $ident['current_address_city'] ?? null,
        'current_address_barangay' => $ident['current_address_barangay'] ?? null,
        'current_address_street' => $ident['current_address_street'] ?? null,
        'phone' => $phone !== null && $phone !== '' ? $phone : null,
        'avatar' => $avatar !== null && $avatar !== '' ? $avatar : null,
    ];
    if (array_key_exists('name_suffix', $ident)) {
        $fields['name_suffix'] = $suffix;
    }
    sync_linked_identity($userId, $fields);
    return ['ok' => true];
}

/** Roles that can auto-link by shared email. */
function linkable_role_ids(): array
{
    return [ROLE_TOURIST, ROLE_TOUR_GUIDE];
}

/** The other linkable role (Tourist <-> Tour Guide). 0 if not linkable. */
function counterpart_linkable_role(int $roleId): int
{
    if ($roleId === ROLE_TOURIST) {
        return ROLE_TOUR_GUIDE;
    }
    if ($roleId === ROLE_TOUR_GUIDE) {
        return ROLE_TOURIST;
    }
    return 0;
}

function registration_link_secret(): string
{
    $salt = defined('PAYMENT_WEBHOOK_SECRET') ? (string) PAYMENT_WEBHOOK_SECRET : 'cultour-link';
    return hash('sha256', SESSION_NAME . '|' . APP_URL . '|' . $salt);
}

function encode_registration_link_token(int $sourceUserId, string $email, int $targetRoleId): string
{
    $exp = time() + 1800;
    $payload = $sourceUserId . '|' . strtolower(trim($email)) . '|' . $targetRoleId . '|' . $exp;
    $mac = hash_hmac('sha256', $payload, registration_link_secret());
    return rtrim(strtr(base64_encode($payload . '|' . $mac), '+/', '-_'), '=');
}

/**
 * @return array{source_user_id:int,email:string,target_role_id:int}|null
 */
function decode_registration_link_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $raw = strtr($token, '-_', '+/');
    $pad = strlen($raw) % 4;
    if ($pad > 0) {
        $raw .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($raw, true);
    if (!is_string($decoded) || $decoded === '') {
        return null;
    }
    $parts = explode('|', $decoded);
    if (count($parts) !== 5) {
        return null;
    }
    [$id, $email, $role, $exp, $mac] = $parts;
    $payload = $id . '|' . $email . '|' . $role . '|' . $exp;
    $expect = hash_hmac('sha256', $payload, registration_link_secret());
    if (!hash_equals($expect, $mac) || (int) $exp < time()) {
        return null;
    }
    return [
        'source_user_id' => (int) $id,
        'email' => strtolower(trim($email)),
        'target_role_id' => (int) $role,
    ];
}

/**
 * Resolve a verified Tourist/Tour Guide sibling from a registration link token.
 *
 * @return array{ok:bool,message?:string,user?:array}
 */
function resolve_registration_link(array $data, int $targetRoleId): array
{
    $token = trim((string) ($data['link_token'] ?? ''));
    $sourceId = (int) ($data['link_source_user_id'] ?? 0);
    if ($token === '' && $sourceId < 1) {
        return ['ok' => false];
    }
    $parsed = decode_registration_link_token($token);
    if ($parsed === null || $sourceId < 1) {
        return ['ok' => false, 'message' => 'Your account link expired. Please link again.'];
    }
    if ($parsed['source_user_id'] !== $sourceId || $parsed['target_role_id'] !== $targetRoleId) {
        return ['ok' => false, 'message' => 'Your account link is not valid for this registration.'];
    }
    $expectedSrc = counterpart_linkable_role($targetRoleId);
    if ($expectedSrc < 1) {
        return ['ok' => false, 'message' => 'Only Tourist and Tour Guide accounts can be linked.'];
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$sourceId]);
    $src = $stmt->fetch();
    if (!$src) {
        return ['ok' => false, 'message' => 'That account was not found.'];
    }
    if ((int) ($src['role_id'] ?? 0) !== $expectedSrc) {
        return ['ok' => false, 'message' => 'You can only link a Tourist account with a Tour Guide account.'];
    }
    if (strtolower(trim((string) ($src['email'] ?? ''))) !== $parsed['email']) {
        return ['ok' => false, 'message' => 'Your account link expired. Please link again.'];
    }
    if (($src['status'] ?? '') === 'rejected') {
        return ['ok' => false, 'message' => 'That account was rejected. Contact the Tourism Office.'];
    }
    if (email_taken_for_role((string) $src['email'], $targetRoleId)) {
        return ['ok' => false, 'message' => 'This email already has a ' . role_label($targetRoleId) . ' account. Log in and switch from the profile menu.'];
    }
    return ['ok' => true, 'user' => $src];
}

/**
 * Verify email/password of an existing counterpart account so registration can link to it.
 *
 * @return array{ok:bool,message?:string,token?:string,source_user_id?:int,email?:string,source_role_label?:string,identity?:array<string,mixed>}
 */
function verify_account_for_registration_link(string $email, string $password, int $targetRoleId): array
{
    ensure_email_role_unique_schema();
    $email = strtolower(trim($email));
    $sourceRole = counterpart_linkable_role($targetRoleId);
    if ($sourceRole < 1 || !in_array($targetRoleId, linkable_role_ids(), true)) {
        return ['ok' => false, 'message' => 'Only Tourist and Tour Guide accounts can be linked.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter the email of your existing account.'];
    }
    if ($password === '') {
        return ['ok' => false, 'message' => 'Enter your current password.'];
    }
    if (email_taken_for_role($email, $targetRoleId)) {
        return ['ok' => false, 'message' => 'This email already has a ' . role_label($targetRoleId) . ' account. Log in and switch from the profile menu.'];
    }

    $stmt = db()->prepare(
        'SELECT * FROM users WHERE email=? AND role_id=? LIMIT 1'
    );
    $stmt->execute([$email, $sourceRole]);
    $src = $stmt->fetch();
    if (!$src || !password_verify($password, (string) ($src['password_hash'] ?? ''))) {
        return [
            'ok' => false,
            'message' => 'No ' . role_label($sourceRole) . ' account matches that email and password.',
        ];
    }
    if (($src['status'] ?? '') === 'rejected') {
        return ['ok' => false, 'message' => 'That account was rejected. Contact the Tourism Office.'];
    }

    // Tourist → Tour Guide only when that tourist currently resides in Leyte.
    if ($targetRoleId === ROLE_TOUR_GUIDE && $sourceRole === ROLE_TOURIST && !user_current_address_in_leyte($src)) {
        return [
            'ok' => false,
            'need_leyte' => true,
            'message' => 'Your Tourist account’s current address is not in Ormoc City, so you cannot link it to a Tour Guide account. Update that account’s current address to Ormoc City first, then try linking again.',
        ];
    }

    $identity = user_link_identity($src);
    $identity['email'] = $email;
    return [
        'ok' => true,
        'token' => encode_registration_link_token((int) $src['id'], $email, $targetRoleId),
        'source_user_id' => (int) $src['id'],
        'email' => $email,
        'source_role_label' => role_label($sourceRole),
        'target_role_id' => $targetRoleId,
        'identity' => $identity,
        'missing' => registration_link_missing_fields($identity, $targetRoleId),
    ];
}

/**
 * Shared identity fields copied from a Tourist/Tour Guide row for account linking.
 *
 * @param array<string,mixed> $src
 * @return array<string,mixed>
 */
function user_link_identity(array $src): array
{
    $emerg = split_person_name((string) ($src['emergency_contact_name'] ?? ''));
    $rel = trim((string) ($src['emergency_contact_relationship'] ?? ''));
    $relOpts = function_exists('emergency_relationship_options') ? emergency_relationship_options() : [];
    $relChoice = ($rel !== '' && isset($relOpts[$rel])) ? $rel : ($rel !== '' ? 'other' : '');
    return [
        'first_name' => (string) ($src['first_name'] ?? ''),
        'last_name' => (string) ($src['last_name'] ?? ''),
        'middle_name' => (string) ($src['middle_name'] ?? ''),
        'name_suffix' => (string) ($src['name_suffix'] ?? ''),
        'sex' => (string) ($src['sex'] ?? ''),
        'date_of_birth' => (string) ($src['date_of_birth'] ?? ''),
        'nationality' => (string) ($src['nationality'] ?? ''),
        'languages' => user_spoken_languages_from_row($src),
        'phone_local' => ph_phone_local_digits((string) ($src['phone'] ?? '')),
        'address_region' => (string) ($src['address_region'] ?? ''),
        'address_city' => (string) ($src['address_city'] ?? ''),
        'address_barangay' => (string) ($src['address_barangay'] ?? ''),
        'address_street' => (string) ($src['address_street'] ?? ''),
        'current_same_as_permanent' => !empty($src['current_same_as_permanent']),
        'current_address_region' => (string) ($src['current_address_region'] ?? ''),
        'current_address_city' => (string) ($src['current_address_city'] ?? ''),
        'current_address_barangay' => (string) ($src['current_address_barangay'] ?? ''),
        'current_address_street' => (string) ($src['current_address_street'] ?? ''),
        'emergency_first_name' => $emerg['first'],
        'emergency_middle_name' => $emerg['middle'],
        'emergency_last_name' => $emerg['last'],
        'emergency_suffix' => $emerg['suffix'],
        'emergency_relationship' => $rel,
        'emergency_relationship_choice' => $relChoice,
        'emergency_phone_local' => ph_phone_local_digits((string) ($src['emergency_contact_phone'] ?? '')),
        'avatar_url' => !empty($src['avatar']) ? (APP_URL . '/uploads/' . ltrim((string) $src['avatar'], '/')) : null,
        'avatar' => (string) ($src['avatar'] ?? ''),
    ];
}

/**
 * Linked identity fields that the target role still requires.
 *
 * @param array<string,mixed> $identity
 * @return list<array{key:string,label:string}>
 */
function registration_link_missing_fields(array $identity, int $targetRoleId): array
{
    $blank = static function ($value): bool {
        return trim((string) $value) === '';
    };
    $missing = [];

    if ($blank($identity['first_name'] ?? '') || $blank($identity['last_name'] ?? '')) {
        $missing[] = ['key' => 'name', 'label' => 'First and last name'];
    }
    if ($blank($identity['phone_local'] ?? '')) {
        $missing[] = ['key' => 'phone', 'label' => 'Phone number'];
    }
    if ($blank($identity['sex'] ?? '')) {
        $missing[] = ['key' => 'sex', 'label' => 'Gender'];
    }
    if ($blank($identity['date_of_birth'] ?? '')) {
        $missing[] = ['key' => 'dob', 'label' => 'Date of birth'];
    }
    if ($targetRoleId === ROLE_TOURIST && $blank($identity['languages'] ?? '')) {
        $missing[] = ['key' => 'tourist_languages', 'label' => 'Languages you speak'];
    }
    if (
        $blank($identity['address_region'] ?? '')
        || $blank($identity['address_city'] ?? '')
        || $blank($identity['address_barangay'] ?? '')
        || $blank($identity['address_street'] ?? '')
    ) {
        $missing[] = ['key' => 'permanent_address', 'label' => 'Permanent address'];
    }

    if (in_array($targetRoleId, [ROLE_TOURIST, ROLE_TOUR_GUIDE], true)) {
        $emergRel = trim((string) ($identity['emergency_relationship'] ?? ''));
        $emergChoice = trim((string) ($identity['emergency_relationship_choice'] ?? ''));
        if (
            $blank($identity['emergency_first_name'] ?? '')
            || $blank($identity['emergency_last_name'] ?? '')
            || $blank($identity['emergency_phone_local'] ?? '')
            || ($emergRel === '' && ($emergChoice === '' || $emergChoice === 'other'))
        ) {
            $missing[] = ['key' => 'emergency', 'label' => 'Emergency contact (name, relationship, and phone)'];
        }
    }

    if ($targetRoleId === ROLE_TOUR_GUIDE) {
        $leyte = ph_guide_locked_province();
        $same = !empty($identity['current_same_as_permanent']);
        $curRegion = $same
            ? trim((string) ($identity['address_region'] ?? ''))
            : trim((string) ($identity['current_address_region'] ?? ''));
        $curCity = $same
            ? trim((string) ($identity['address_city'] ?? ''))
            : trim((string) ($identity['current_address_city'] ?? ''));
        $curBarangay = $same
            ? trim((string) ($identity['address_barangay'] ?? ''))
            : trim((string) ($identity['current_address_barangay'] ?? ''));
        $curStreet = $same
            ? trim((string) ($identity['address_street'] ?? ''))
            : trim((string) ($identity['current_address_street'] ?? ''));
        $cityLock = function_exists('ph_guide_locked_city') ? ph_guide_locked_city() : 'Ormoc City';
        $leyteOk = strcasecmp($curRegion, $leyte) === 0
            && strcasecmp($curCity, $cityLock) === 0
            && $curBarangay !== ''
            && $curStreet !== '';
        if (!$leyteOk) {
            $missing[] = ['key' => 'leyte_address', 'label' => 'Current address in Ormoc City'];
        }
        if ($blank($identity['languages'] ?? '')) {
            $missing[] = ['key' => 'languages', 'label' => 'Languages you can guide in'];
        }
        $missing[] = ['key' => 'credentials', 'label' => 'Resume or credential files'];
    }

    return $missing;
}

function store_pending_registration_link(array $payload): void
{
    start_app_session();
    $_SESSION['pending_registration_link'] = [
        'target_role_id' => (int) ($payload['target_role_id'] ?? 0),
        'token' => (string) ($payload['token'] ?? ''),
        'source_user_id' => (int) ($payload['source_user_id'] ?? 0),
        'email' => (string) ($payload['email'] ?? ''),
        'source_role_label' => (string) ($payload['source_role_label'] ?? ''),
        'identity' => is_array($payload['identity'] ?? null) ? $payload['identity'] : [],
        'missing' => is_array($payload['missing'] ?? null) ? $payload['missing'] : [],
        'stored_at' => time(),
    ];
}

function peek_pending_registration_link(): ?array
{
    start_app_session();
    $row = $_SESSION['pending_registration_link'] ?? null;
    if (!is_array($row) || trim((string) ($row['token'] ?? '')) === '') {
        return null;
    }
    if ((int) ($row['stored_at'] ?? 0) < time() - 1800) {
        unset($_SESSION['pending_registration_link']);
        return null;
    }
    return $row;
}

function clear_pending_registration_link(): void
{
    start_app_session();
    unset($_SESSION['pending_registration_link']);
}

/**
 * Payload the register page JS already expects from sessionStorage.
 *
 * @param array<string,mixed> $stored
 * @return array<string,mixed>
 */
function pending_registration_link_client_payload(array $stored): array
{
    $ident = is_array($stored['identity'] ?? null) ? $stored['identity'] : [];
    if (empty($ident['email']) && !empty($stored['email'])) {
        $ident['email'] = $stored['email'];
    }
    return [
        'role' => (string) ($stored['target_role_id'] ?? ''),
        'token' => (string) ($stored['token'] ?? ''),
        'source_user_id' => (int) ($stored['source_user_id'] ?? 0),
        'email' => (string) ($stored['email'] ?? ''),
        'source_role_label' => (string) ($stored['source_role_label'] ?? ''),
        'ident' => $ident,
        'missing' => is_array($stored['missing'] ?? null) ? $stored['missing'] : [],
    ];
}

/**
 * @param array<string,mixed> $src
 * @return array<string,mixed>
 */
function register_payload_from_linked_source(array $src, int $targetRoleId, string $token): array
{
    $ident = user_link_identity($src);
    $same = !empty($ident['current_same_as_permanent']);
    return [
        'email' => strtolower(trim((string) ($src['email'] ?? ''))),
        'role_id' => $targetRoleId,
        'link_token' => $token,
        'link_source_user_id' => (int) ($src['id'] ?? 0),
        'first_name' => $ident['first_name'],
        'last_name' => $ident['last_name'],
        'middle_name' => $ident['middle_name'],
        'name_suffix' => $ident['name_suffix'],
        'sex' => $ident['sex'],
        'date_of_birth' => $ident['date_of_birth'],
        'nationality' => $ident['nationality'],
        'tourist_languages' => $ident['languages'],
        'languages' => $ident['languages'],
        'phone_local' => $ident['phone_local'],
        'use_structured_address' => '1',
        'address_region' => $ident['address_region'],
        'address_city' => $ident['address_city'],
        'address_barangay' => $ident['address_barangay'],
        'address_street' => $ident['address_street'],
        'current_same_as_permanent' => $same ? '1' : '0',
        'current_address_region' => $ident['current_address_region'],
        'current_address_city' => $ident['current_address_city'],
        'current_address_barangay' => $ident['current_address_barangay'],
        'current_address_street' => $ident['current_address_street'],
        'emergency_first_name' => $ident['emergency_first_name'],
        'emergency_middle_name' => $ident['emergency_middle_name'],
        'emergency_last_name' => $ident['emergency_last_name'],
        'emergency_suffix' => $ident['emergency_suffix'],
        'emergency_relationship' => $ident['emergency_relationship'],
        'emergency_phone_local' => $ident['emergency_phone_local'],
    ];
}

function complete_linked_registration(string $token, int $sourceId, int $targetRoleId): array
{
    if (!function_exists('register_user')) {
        require_once __DIR__ . '/auth.php';
    }
    $resolved = resolve_registration_link([
        'link_token' => $token,
        'link_source_user_id' => $sourceId,
    ], $targetRoleId);
    if (empty($resolved['ok']) || empty($resolved['user'])) {
        return [
            'ok' => false,
            'message' => (string) ($resolved['message'] ?? 'Your account link expired. Please link again.'),
        ];
    }
    $src = $resolved['user'];
    $ident = user_link_identity($src);
    $ident['email'] = strtolower(trim((string) ($src['email'] ?? '')));
    $missing = registration_link_missing_fields($ident, $targetRoleId);
    if ($missing) {
        return [
            'ok' => false,
            'needs_form' => true,
            'missing' => $missing,
            'message' => 'This role still needs extra details. Continue on the registration form.',
        ];
    }
    return register_user(register_payload_from_linked_source($src, $targetRoleId, $token));
}

/**
 * Other Tourist/Tour Guide accounts sharing this email.
 *
 * @return list<array<string,mixed>>
 */
function linked_role_accounts(string $email, ?int $exceptUserId = null): array
{
    ensure_email_role_unique_schema();
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }
    $roles = linkable_role_ids();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $params = array_merge([$email], $roles);
    $sql = "SELECT u.id, u.role_id, u.email, u.first_name, u.last_name, u.status, r.name AS role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.email = ? AND u.role_id IN ($placeholders)
              AND u.status IN ('active','pending','suspended')";
    if ($exceptUserId !== null && $exceptUserId > 0) {
        $sql .= ' AND u.id <> ?';
        $params[] = $exceptUserId;
    }
    $sql .= ' ORDER BY u.role_id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Switch the current session to a linked Tourist/Tour Guide account (same email).
 *
 * @return array{ok:bool,message?:string,user?:array}
 */
function switch_to_linked_account(int $targetUserId): array
{
    ensure_email_role_unique_schema();
    $current = current_user();
    if (!$current) {
        return ['ok' => false, 'message' => 'Please log in to continue.'];
    }
    $currentRole = (int) ($current['role_id'] ?? 0);
    if (!in_array($currentRole, linkable_role_ids(), true)) {
        return ['ok' => false, 'message' => 'Account switching is only available for Tourist and Tour Guide.'];
    }

    $stmt = db()->prepare(
        'SELECT u.*, r.name AS role_name FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$targetUserId]);
    $target = $stmt->fetch();
    if (!$target) {
        return ['ok' => false, 'message' => 'That account was not found.'];
    }

    $email = strtolower(trim((string) ($current['email'] ?? '')));
    $targetEmail = strtolower(trim((string) ($target['email'] ?? '')));
    $targetRole = (int) ($target['role_id'] ?? 0);
    if ($email === '' || $email !== $targetEmail || !in_array($targetRole, linkable_role_ids(), true)) {
        return ['ok' => false, 'message' => 'You can only switch to a linked Tourist or Tour Guide account.'];
    }
    if ((int) $target['id'] === (int) $current['id']) {
        return ['ok' => true, 'user' => $current];
    }
    if (($target['status'] ?? '') === 'rejected') {
        return ['ok' => false, 'message' => 'That linked account was rejected.'];
    }

    $gate = registration_approval_gate($target);
    if (!$gate['ok'] && ($target['status'] ?? '') !== 'suspended') {
        return ['ok' => false, 'message' => (string) ($gate['message'] ?? 'That linked account is not available yet.')];
    }

    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $target['id'];
    $_SESSION['role_id'] = $targetRole;
    $_SESSION['role_name'] = $target['role_name'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $target['id']]);
    log_activity((int) $target['id'], 'switch_account', 'auth', 'Switched to ' . role_label($targetRole));

    return ['ok' => true, 'user' => $target];
}

/**
 * Copy shared identity fields to sibling Tourist/Tour Guide rows with the same email.
 *
 * @param array<string,mixed> $fields
 */
function sync_linked_identity(int $fromUserId, array $fields): void
{
    ensure_email_role_unique_schema();
    $stmt = db()->prepare('SELECT id, email, role_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$fromUserId]);
    $from = $stmt->fetch();
    if (!$from || !in_array((int) ($from['role_id'] ?? 0), linkable_role_ids(), true)) {
        return;
    }
    $siblings = linked_role_accounts((string) $from['email'], $fromUserId);
    if (!$siblings) {
        return;
    }

    $allowed = [
        'first_name', 'last_name', 'middle_name', 'name_suffix', 'sex', 'date_of_birth', 'nationality', 'languages',
        'address', 'address_region', 'address_city', 'address_barangay', 'address_street',
        'current_same_as_permanent', 'current_address', 'current_address_region', 'current_address_city',
        'current_address_barangay', 'current_address_street', 'phone', 'avatar',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
    ];
    $sets = [];
    $values = [];
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $fields)) {
            continue;
        }
        $sets[] = '`' . $col . '`=?';
        $values[] = $fields[$col];
    }
    if (!$sets) {
        return;
    }
    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id=?';
    $upd = db()->prepare($sql);
    foreach ($siblings as $sib) {
        $params = $values;
        $params[] = (int) $sib['id'];
        $upd->execute($params);
    }
}

/**
 * Linked Tourist/Tour Guide accounts the current user can switch into.
 *
 * @return list<array<string,mixed>>
 */
function switchable_linked_accounts(array $user): array
{
    $roleId = (int) ($user['role_id'] ?? 0);
    if (!in_array($roleId, linkable_role_ids(), true)) {
        return [];
    }
    $out = [];
    foreach (linked_role_accounts((string) ($user['email'] ?? ''), (int) ($user['id'] ?? 0)) as $linked) {
        $linkedFull = db()->prepare(
            'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1'
        );
        $linkedFull->execute([(int) $linked['id']]);
        $row = $linkedFull->fetch();
        if (!$row) {
            continue;
        }
        if (($row['status'] ?? '') === 'suspended') {
            $out[] = $row;
            continue;
        }
        if (function_exists('registration_approval_gate')) {
            $gate = registration_approval_gate($row);
            if (!$gate['ok']) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Allow itinerary stops to be a destination or a business (XOR),
 * plus travel/visit timing columns and itineraries.meetup_time.
 */
function ensure_itinerary_items_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM itinerary_items')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('business_id', $cols, true)) {
            $pdo->exec('ALTER TABLE itinerary_items ADD COLUMN business_id INT UNSIGNED NULL AFTER destination_id');
            try {
                $pdo->exec(
                    'ALTER TABLE itinerary_items
                     ADD CONSTRAINT fk_item_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE'
                );
            } catch (Throwable $e) {
                // constraint may already exist
            }
        }

        $destCol = $pdo->query("SHOW COLUMNS FROM itinerary_items LIKE 'destination_id'")->fetch(PDO::FETCH_ASSOC);
        if ($destCol && strtoupper((string) ($destCol['Null'] ?? 'NO')) === 'NO') {
            $fkName = null;
            $fkStmt = $pdo->prepare(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'itinerary_items'
                   AND COLUMN_NAME = 'destination_id'
                   AND REFERENCED_TABLE_NAME IS NOT NULL
                 LIMIT 1"
            );
            $fkStmt->execute();
            $fkName = $fkStmt->fetchColumn() ?: null;
            if ($fkName) {
                $pdo->exec('ALTER TABLE itinerary_items DROP FOREIGN KEY `' . str_replace('`', '``', (string) $fkName) . '`');
            }
            $pdo->exec('ALTER TABLE itinerary_items MODIFY destination_id INT UNSIGNED NULL');
            try {
                $pdo->exec(
                    'ALTER TABLE itinerary_items
                     ADD CONSTRAINT fk_item_dest FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE'
                );
            } catch (Throwable $e) {
                // ignore
            }
        }

        // Ensure destination FK cascades so admin can delete places used on itineraries
        try {
            $fkRule = $pdo->prepare(
                "SELECT rc.DELETE_RULE, kcu.CONSTRAINT_NAME
                 FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                 JOIN information_schema.KEY_COLUMN_USAGE kcu
                   ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                  AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                 WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                   AND kcu.TABLE_NAME = 'itinerary_items'
                   AND kcu.COLUMN_NAME = 'destination_id'
                   AND kcu.REFERENCED_TABLE_NAME = 'destinations'
                 LIMIT 1"
            );
            $fkRule->execute();
            $fkInfo = $fkRule->fetch(PDO::FETCH_ASSOC);
            if ($fkInfo && strtoupper((string) ($fkInfo['DELETE_RULE'] ?? '')) !== 'CASCADE') {
                $fkName = (string) $fkInfo['CONSTRAINT_NAME'];
                $pdo->exec('ALTER TABLE itinerary_items DROP FOREIGN KEY `' . str_replace('`', '``', $fkName) . '`');
                $pdo->exec(
                    'ALTER TABLE itinerary_items
                     ADD CONSTRAINT fk_item_dest FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE'
                );
            } elseif (!$fkInfo) {
                $pdo->exec(
                    'ALTER TABLE itinerary_items
                     ADD CONSTRAINT fk_item_dest FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE'
                );
            }
        } catch (Throwable $e) {
            // ignore
        }

        $cols = $pdo->query('SHOW COLUMNS FROM itinerary_items')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('travel_minutes', $cols, true)) {
            $pdo->exec('ALTER TABLE itinerary_items ADD COLUMN travel_minutes INT UNSIGNED NULL AFTER end_time');
        }
        if (!in_array('visit_minutes', $cols, true)) {
            $pdo->exec(
                'ALTER TABLE itinerary_items ADD COLUMN visit_minutes INT UNSIGNED NOT NULL DEFAULT 60 AFTER travel_minutes'
            );
        }
        if (!in_array('visit_date', $cols, true)) {
            $pdo->exec('ALTER TABLE itinerary_items ADD COLUMN visit_date DATE NULL AFTER sort_order');
        }

        $itinCols = $pdo->query('SHOW COLUMNS FROM itineraries')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('meetup_time', $itinCols, true)) {
            $pdo->exec(
                "ALTER TABLE itineraries ADD COLUMN meetup_time TIME NULL DEFAULT '08:00:00' AFTER end_date"
            );
            $itinCols[] = 'meetup_time';
        }
        if (!in_array('is_suggested', $itinCols, true)) {
            $pdo->exec(
                'ALTER TABLE itineraries ADD COLUMN is_suggested TINYINT(1) NOT NULL DEFAULT 0 AFTER meetup_time'
            );
            $itinCols[] = 'is_suggested';
        }
        if (!in_array('created_by_guide_id', $itinCols, true)) {
            $pdo->exec(
                'ALTER TABLE itineraries ADD COLUMN created_by_guide_id INT UNSIGNED NULL AFTER is_suggested'
            );
            try {
                $pdo->exec(
                    'ALTER TABLE itineraries
                     ADD CONSTRAINT fk_itin_guide FOREIGN KEY (created_by_guide_id)
                     REFERENCES tour_guide_profiles(id) ON DELETE SET NULL'
                );
            } catch (Throwable $e) {
                // ignore
            }
            $itinCols[] = 'created_by_guide_id';
        }
        if (!in_array('kept_as_draft', $itinCols, true)) {
            $pdo->exec(
                'ALTER TABLE itineraries ADD COLUMN kept_as_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER status'
            );
            $itinCols[] = 'kept_as_draft';
        }
        if (!in_array('template_day_count', $itinCols, true)) {
            $after = in_array('meetup_time', $itinCols, true) ? 'meetup_time' : 'end_date';
            $pdo->exec(
                "ALTER TABLE itineraries ADD COLUMN template_day_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER {$after}"
            );
            $itinCols[] = 'template_day_count';
        }
        if (!in_array('party_size', $itinCols, true)) {
            $pdo->exec(
                'ALTER TABLE itineraries ADD COLUMN party_size TINYINT UNSIGNED NULL AFTER template_day_count'
            );
            $itinCols[] = 'party_size';
        }
        if (!in_array('draft_guide_id', $itinCols, true)) {
            $pdo->exec(
                'ALTER TABLE itineraries ADD COLUMN draft_guide_id INT UNSIGNED NULL AFTER party_size'
            );
            $itinCols[] = 'draft_guide_id';
        }
        $stayCols = [
            'accommodation_business_id' => 'INT UNSIGNED NULL',
            'accommodation_service_id' => 'INT UNSIGNED NULL',
            'accommodation_declined' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'transportation_business_id' => 'INT UNSIGNED NULL',
            'transportation_service_id' => 'INT UNSIGNED NULL',
            'transportation_declined' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'accommodation_lines' => 'TEXT NULL',
            'transportation_lines' => 'TEXT NULL',
        ];
        foreach ($stayCols as $stayCol => $stayDef) {
            if (!in_array($stayCol, $itinCols, true)) {
                $pdo->exec('ALTER TABLE itineraries ADD COLUMN ' . $stayCol . ' ' . $stayDef);
                $itinCols[] = $stayCol;
            }
        }
        $typeExists = $pdo->prepare('SELECT id FROM business_types WHERE name=? LIMIT 1');
        $typeInsert = $pdo->prepare('INSERT INTO business_types (name, pin_color) VALUES (?,?)');
        $pdo->exec(
            "UPDATE business_types SET name='Accommodation' WHERE name='Hotel'
             AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM business_types WHERE name='Accommodation') acc)"
        );
        foreach (['Accommodation' => '#2b6cb0', 'Transportation' => '#805ad5'] as $typeName => $typeColor) {
            $typeExists->execute([$typeName]);
            if (!$typeExists->fetch()) {
                $typeInsert->execute([$typeName, $typeColor]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    ensure_itinerary_day_meetups_schema();
}

/**
 * Per-day meetup places (destination/business) for an itinerary.
 */
function ensure_itinerary_day_meetups_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS itinerary_day_meetups (
                itinerary_id INT UNSIGNED NOT NULL,
                day_number TINYINT UNSIGNED NOT NULL,
                destination_id INT UNSIGNED NULL,
                business_id INT UNSIGNED NULL,
                PRIMARY KEY (itinerary_id, day_number),
                KEY idx_day_meetup_dest (destination_id),
                KEY idx_day_meetup_biz (business_id),
                CONSTRAINT fk_day_meetup_itin FOREIGN KEY (itinerary_id)
                    REFERENCES itineraries(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = db()->query('SHOW COLUMNS FROM itinerary_day_meetups')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('meetup_time', $cols, true)) {
            db()->exec('ALTER TABLE itinerary_day_meetups ADD COLUMN meetup_time TIME NULL AFTER business_id');
            $cols[] = 'meetup_time';
        }
        if (is_array($cols) && !in_array('visit_date', $cols, true)) {
            db()->exec('ALTER TABLE itinerary_day_meetups ADD COLUMN visit_date DATE NULL AFTER meetup_time');
            $cols[] = 'visit_date';
        }
        if (is_array($cols) && !in_array('is_port', $cols, true)) {
            db()->exec(
                'ALTER TABLE itinerary_day_meetups ADD COLUMN is_port TINYINT(1) NOT NULL DEFAULT 0 AFTER visit_date'
            );
            // Existing dest/biz-null rows were created by “Use port”.
            db()->exec(
                'UPDATE itinerary_day_meetups SET is_port=1
                 WHERE destination_id IS NULL AND business_id IS NULL'
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Saved meetup place rows for an itinerary, keyed by day number.
 *
 * @return array<int, array{day_number:int,destination_id:?int,business_id:?int,name:string,lat:float,lng:float,key:string,kind:string,cover_url:?string,meetup_time:?string,visit_date:?string}>
 */
function itinerary_day_meetups_map(int $itineraryId): array
{
    ensure_itinerary_day_meetups_schema();
    if ($itineraryId < 1) {
        return [];
    }
    $st = db()->prepare(
        'SELECT m.day_number, m.destination_id, m.business_id, m.meetup_time, m.visit_date, m.is_port,
                d.name AS dest_name, d.latitude AS dest_lat, d.longitude AS dest_lng, d.cover_image AS dest_cover,
                b.name AS biz_name, b.latitude AS biz_lat, b.longitude AS biz_lng, b.cover_image AS biz_cover
         FROM itinerary_day_meetups m
         LEFT JOIN destinations d ON d.id = m.destination_id
         LEFT JOIN businesses b ON b.id = m.business_id
         WHERE m.itinerary_id = ?
         ORDER BY m.day_number ASC'
    );
    $st->execute([$itineraryId]);
    $out = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $day = (int) ($row['day_number'] ?? 0);
        if ($day < 1) {
            continue;
        }
        $did = (int) ($row['destination_id'] ?? 0);
        $bid = (int) ($row['business_id'] ?? 0);
        $timeRaw = isset($row['meetup_time']) && $row['meetup_time'] !== null && $row['meetup_time'] !== ''
            ? (string) $row['meetup_time']
            : null;
        $visitDate = normalize_tour_date($row['visit_date'] ?? null);
        $isPort = !empty($row['is_port']);
        if ($did > 0 && !empty($row['dest_name'])) {
            $cover = trim((string) ($row['dest_cover'] ?? ''));
            $out[$day] = [
                'day_number' => $day,
                'destination_id' => $did,
                'business_id' => null,
                'name' => (string) $row['dest_name'],
                'lat' => $row['dest_lat'] !== null ? (float) $row['dest_lat'] : 0.0,
                'lng' => $row['dest_lng'] !== null ? (float) $row['dest_lng'] : 0.0,
                'key' => 'd:' . $did,
                'kind' => 'destination',
                'cover_url' => $cover !== '' ? (APP_URL . '/uploads/' . ltrim($cover, '/')) : null,
                'meetup_time' => $timeRaw,
                'visit_date' => $visitDate,
            ];
        } elseif ($bid > 0 && !empty($row['biz_name'])) {
            $cover = trim((string) ($row['biz_cover'] ?? ''));
            $out[$day] = [
                'day_number' => $day,
                'destination_id' => null,
                'business_id' => $bid,
                'name' => (string) $row['biz_name'],
                'lat' => $row['biz_lat'] !== null ? (float) $row['biz_lat'] : 0.0,
                'lng' => $row['biz_lng'] !== null ? (float) $row['biz_lng'] : 0.0,
                'key' => 'b:' . $bid,
                'kind' => 'business',
                'cover_url' => $cover !== '' ? (APP_URL . '/uploads/' . ltrim($cover, '/')) : null,
                'meetup_time' => $timeRaw,
                'visit_date' => $visitDate,
            ];
        } elseif ($isPort) {
            $port = ormoc_port_coords();
            $out[$day] = [
                'day_number' => $day,
                'destination_id' => null,
                'business_id' => null,
                'name' => (string) $port['title'],
                'lat' => (float) $port['lat'],
                'lng' => (float) $port['lng'],
                'key' => 'port',
                'kind' => 'port',
                'cover_url' => null,
                'meetup_time' => $timeRaw,
                'visit_date' => $visitDate,
            ];
        }
    }
    return $out;
}

/**
 * Stored meetup clock for a day, even when no place is set yet.
 */
function itinerary_day_stored_meetup_time(int $itineraryId, int $dayNumber): ?string
{
    ensure_itinerary_day_meetups_schema();
    if ($itineraryId < 1 || $dayNumber < 1) {
        return null;
    }
    $st = db()->prepare(
        'SELECT meetup_time FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number=? LIMIT 1'
    );
    $st->execute([$itineraryId, $dayNumber]);
    $raw = $st->fetchColumn();
    if ($raw === false || $raw === null || trim((string) $raw) === '') {
        return null;
    }
    return (string) $raw;
}

/**
 * @return array{meetup_time:?string,meetup_mins:?int,meetup_display:string}
 */
function itinerary_day_meetup_time_bits(?string $raw, int $dayNumber, ?array $itinRow): array
{
    if (($raw === null || trim($raw) === '') && $dayNumber === 1 && is_array($itinRow)) {
        $raw = isset($itinRow['meetup_time']) ? (string) $itinRow['meetup_time'] : null;
    }
    $mins = itin_time_to_minutes($raw);
    return [
        'meetup_time' => $raw,
        'meetup_mins' => $mins,
        'meetup_display' => $mins !== null ? format_minutes_12h($mins) : '',
    ];
}

/**
 * Effective meetup place for a day (Day 1 defaults to Ormoc City Port).
 *
 * @return array{day:int,label:string,lat:float,lng:float,key:?string,kind:string,is_default:bool,cover_url:?string,has_place:bool,meetup_time:?string,meetup_mins:?int,meetup_display:string}
 */
function itinerary_day_meetup_place(int $itineraryId, int $dayNumber, ?array $itinRow = null): array
{
    $dayNumber = max(1, min(itinerary_max_days(), $dayNumber));
    $port = ormoc_port_coords();
    $map = itinerary_day_meetups_map($itineraryId);
    $storedTime = itinerary_day_stored_meetup_time($itineraryId, $dayNumber);
    if (isset($map[$dayNumber])) {
        $m = $map[$dayNumber];
        $rawTime = isset($m['meetup_time']) && trim((string) $m['meetup_time']) !== ''
            ? (string) $m['meetup_time']
            : $storedTime;
        $time = itinerary_day_meetup_time_bits($rawTime, $dayNumber, $itinRow);
        return [
            'day' => $dayNumber,
            'label' => (string) $m['name'],
            'lat' => (float) $m['lat'],
            'lng' => (float) $m['lng'],
            'key' => (string) $m['key'],
            'kind' => (string) $m['kind'],
            'is_default' => false,
            'cover_url' => $m['cover_url'] ?? null,
            'has_place' => true,
            'meetup_time' => $time['meetup_time'],
            'meetup_mins' => $time['meetup_mins'],
            'meetup_display' => $time['meetup_display'],
        ];
    }
    $time = itinerary_day_meetup_time_bits($storedTime, $dayNumber, $itinRow);
    if ($dayNumber === 1) {
        return [
            'day' => 1,
            'label' => (string) $port['title'],
            'lat' => (float) $port['lat'],
            'lng' => (float) $port['lng'],
            'key' => null,
            'kind' => 'port',
            'is_default' => true,
            'cover_url' => null,
            'has_place' => true,
            'meetup_time' => $time['meetup_time'],
            'meetup_mins' => $time['meetup_mins'],
            'meetup_display' => $time['meetup_display'],
        ];
    }
    return [
        'day' => $dayNumber,
        'label' => 'Meetup not set',
        'lat' => (float) $port['lat'],
        'lng' => (float) $port['lng'],
        'key' => null,
        'kind' => 'none',
        'is_default' => true,
        'cover_url' => null,
        'has_place' => false,
        'meetup_time' => $time['meetup_time'],
        'meetup_mins' => $time['meetup_mins'],
        'meetup_display' => $time['meetup_display'],
    ];
}

/**
 * Save or clear a day's meetup place.
 * Pass $asPort true to store Ormoc City Port. Pass null $ref (and $asPort false) to clear
 * (Day 1 falls back to Port; later days become “Meetup not set”).
 *
 * @param array{destination_id:?int,business_id:?int}|null $ref
 */
function itinerary_set_day_meetup(int $itineraryId, int $dayNumber, ?array $ref, bool $asPort = false, ?string $meetupTime = null): ?string
{
    ensure_itinerary_day_meetups_schema();
    $dayNumber = max(1, min(itinerary_max_days(), $dayNumber));
    if ($itineraryId < 1) {
        return 'Itinerary not found.';
    }
    $normTime = ($meetupTime !== null && trim($meetupTime) !== '') ? normalize_time_input($meetupTime) : null;
    if ($asPort) {
        db()->prepare(
            'INSERT INTO itinerary_day_meetups (itinerary_id, day_number, destination_id, business_id, meetup_time, is_port)
             VALUES (?,?,NULL,NULL,?,1)
             ON DUPLICATE KEY UPDATE destination_id=NULL, business_id=NULL, is_port=1,
                meetup_time=IFNULL(VALUES(meetup_time), meetup_time)'
        )->execute([$itineraryId, $dayNumber, $normTime]);
        return null;
    }
    if ($ref === null) {
        $keep = db()->prepare(
            'SELECT visit_date FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number=? LIMIT 1'
        );
        $keep->execute([$itineraryId, $dayNumber]);
        $keepDate = normalize_tour_date($keep->fetchColumn() ?: null);
        if ($keepDate !== null) {
            db()->prepare(
                'UPDATE itinerary_day_meetups
                 SET destination_id=NULL, business_id=NULL, meetup_time=NULL, is_port=0
                 WHERE itinerary_id=? AND day_number=?'
            )->execute([$itineraryId, $dayNumber]);
        } else {
            db()->prepare('DELETE FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number=?')
                ->execute([$itineraryId, $dayNumber]);
        }
        return null;
    }
    $place = itinerary_place_coords($ref);
    if (!$place) {
        return 'That place is not available.';
    }
    $did = !empty($ref['destination_id']) ? (int) $ref['destination_id'] : null;
    $bid = !empty($ref['business_id']) ? (int) $ref['business_id'] : null;
    if ($did === null && $bid === null) {
        return 'Choose a destination or business.';
    }
    db()->prepare(
        'INSERT INTO itinerary_day_meetups (itinerary_id, day_number, destination_id, business_id, meetup_time, is_port)
         VALUES (?,?,?,?,?,0)
         ON DUPLICATE KEY UPDATE destination_id=VALUES(destination_id), business_id=VALUES(business_id), is_port=0,
            meetup_time=IFNULL(VALUES(meetup_time), meetup_time)'
    )->execute([$itineraryId, $dayNumber, $did, $bid, $normTime]);
    return null;
}

/**
 * Set a day's meetup clock without changing the meetup place.
 */
function itinerary_set_day_meetup_time(int $itineraryId, int $dayNumber, string $meetupTime, bool $allowPast = false): ?string
{
    ensure_itinerary_day_meetups_schema();
    $dayNumber = max(1, min(itinerary_max_days(), $dayNumber));
    if ($itineraryId < 1) {
        return 'Itinerary not found.';
    }
    $norm = normalize_time_input($meetupTime);
    if ($norm === null) {
        if (trim($meetupTime) === '') {
            db()->prepare(
                'UPDATE itinerary_day_meetups SET meetup_time=NULL WHERE itinerary_id=? AND day_number=?'
            )->execute([$itineraryId, $dayNumber]);
            if ($dayNumber === 1) {
                db()->prepare('UPDATE itineraries SET meetup_time=NULL WHERE id=?')->execute([$itineraryId]);
            }
            return null;
        }
        return 'Enter a valid meetup time (e.g. 8:00 AM).';
    }
    $itin = itinerary_row_by_id($itineraryId);
    $visitDate = itinerary_date_for_logical_day($itineraryId, $dayNumber, $itin)
        ?: normalize_tour_date(is_array($itin) ? ($itin['start_date'] ?? null) : null);
    if ($visitDate !== null && !$allowPast) {
        $pastMsg = tour_datetime_past_message($visitDate, $norm, 'Meetup time');
        if ($pastMsg !== null) {
            return $pastMsg;
        }
    }
    db()->prepare(
        'INSERT INTO itinerary_day_meetups (itinerary_id, day_number, destination_id, business_id, meetup_time, visit_date, is_port)
         VALUES (?,?,NULL,NULL,?,?,0)
         ON DUPLICATE KEY UPDATE meetup_time=VALUES(meetup_time)'
    )->execute([$itineraryId, $dayNumber, $norm, $visitDate]);
    if ($dayNumber === 1) {
        db()->prepare('UPDATE itineraries SET meetup_time=? WHERE id=?')->execute([$norm, $itineraryId]);
    }
    return null;
}

/**
 * Opening hours / services / default visit minutes for destinations & businesses.
 */
function ensure_place_schedule_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();

        $destCols = $pdo->query('SHOW COLUMNS FROM destinations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('default_visit_minutes', $destCols, true)) {
            $pdo->exec(
                'ALTER TABLE destinations ADD COLUMN default_visit_minutes INT UNSIGNED NOT NULL DEFAULT 60 AFTER opening_hours'
            );
        }
        $oh = $pdo->query("SHOW COLUMNS FROM destinations LIKE 'opening_hours'")->fetch(PDO::FETCH_ASSOC);
        if ($oh && stripos((string) ($oh['Type'] ?? ''), 'varchar') !== false) {
            $pdo->exec('ALTER TABLE destinations MODIFY opening_hours TEXT NULL');
        }

        $bizCols = $pdo->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('default_visit_minutes', $bizCols, true)) {
            $pdo->exec(
                'ALTER TABLE businesses ADD COLUMN default_visit_minutes INT UNSIGNED NOT NULL DEFAULT 60 AFTER opening_hours'
            );
        }
        // NULL visit_duration_minutes = no limit on how long a stop may stay.
        if (!in_array('visit_duration_minutes', $destCols, true)) {
            $pdo->exec('ALTER TABLE destinations ADD COLUMN visit_duration_minutes INT UNSIGNED NULL AFTER default_visit_minutes');
        }
        if (!in_array('visit_duration_minutes', $bizCols, true)) {
            $pdo->exec('ALTER TABLE businesses ADD COLUMN visit_duration_minutes INT UNSIGNED NULL AFTER default_visit_minutes');
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS destination_images (
              id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
              destination_id INT UNSIGNED NOT NULL,
              image_path VARCHAR(255) NOT NULL,
              caption VARCHAR(255) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_dest_img FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS destination_services (
              id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
              destination_id INT UNSIGNED NOT NULL,
              name VARCHAR(150) NOT NULL,
              description TEXT NULL,
              price DECIMAL(10,2) NULL,
              duration_minutes INT UNSIGNED NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              CONSTRAINT fk_dest_svc FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );

        $svcCols = $pdo->query('SHOW COLUMNS FROM business_services')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('duration_minutes', $svcCols, true)) {
            $pdo->exec(
                'ALTER TABLE business_services ADD COLUMN duration_minutes INT UNSIGNED NULL AFTER price'
            );
            $svcCols[] = 'duration_minutes';
        }
        $offerCols = [
            'capacity' => 'INT UNSIGNED NULL',
            'unit_count' => 'INT UNSIGNED NULL',
            'amenities' => 'VARCHAR(255) NULL',
            'image_path' => 'VARCHAR(255) NULL',
            'extra_guest_fee' => 'DECIMAL(10,2) NULL',
            'extra_guest_limit' => 'INT UNSIGNED NULL',
            'package_days' => 'INT UNSIGNED NULL',
            'menu_category' => "VARCHAR(40) NULL",
        ];
        foreach ($offerCols as $offerCol => $offerDef) {
            if (!in_array($offerCol, $svcCols, true)) {
                $pdo->exec('ALTER TABLE business_services ADD COLUMN ' . $offerCol . ' ' . $offerDef);
                $svcCols[] = $offerCol;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Fetch itinerary stops with destination or business place info.
 * @return list<array<string,mixed>>
 */
function itinerary_items_with_places(int $itineraryId): array
{
    ensure_itinerary_items_schema();
    $s = db()->prepare(
        "SELECT ii.*,
                COALESCE(d.name, b.name) AS stop_name,
                COALESCE(d.location, b.address) AS stop_location,
                COALESCE(d.latitude, b.latitude) AS latitude,
                COALESCE(d.longitude, b.longitude) AS longitude,
                COALESCE(d.cover_image, b.cover_image) AS cover_image,
                CASE WHEN ii.business_id IS NOT NULL THEN 'business' ELSE 'destination' END AS stop_kind,
                d.name AS destination_name,
                d.location AS destination_location,
                bt.name AS business_type_name,
                b.opening_hours AS business_hours,
                b.user_id AS business_user_id
         FROM itinerary_items ii
         LEFT JOIN destinations d ON d.id = ii.destination_id
         LEFT JOIN businesses b ON b.id = ii.business_id
         LEFT JOIN business_types bt ON bt.id = b.business_type_id
         WHERE ii.itinerary_id = ?
         ORDER BY COALESCE(ii.visit_date, '1970-01-01'), ii.sort_order, ii.id"
    );
    $s->execute([$itineraryId]);
    $rows = $s->fetchAll() ?: [];
    foreach ($rows as &$row) {
        // Back-compat aliases used by existing templates
        if (empty($row['destination_name'])) {
            $row['destination_name'] = $row['stop_name'] ?? 'Stop';
        }
        if (!isset($row['location']) || $row['location'] === null || $row['location'] === '') {
            $row['location'] = $row['stop_location'] ?? '';
        }
        $coverPath = trim((string) ($row['cover_image'] ?? ''));
        $row['cover_url'] = $coverPath !== ''
            ? (APP_URL . '/uploads/' . ltrim($coverPath, '/'))
            : null;
    }
    unset($row);
    return $rows;
}

/**
 * Parse stop form key "d:12" / "b:5" (or legacy destination_id / business_id fields).
 * @return array{destination_id:?int,business_id:?int}|null
 */
function parse_itinerary_stop_ref(array $post): ?array
{
    $key = trim((string) ($post['stop_key'] ?? ''));
    if ($key !== '' && preg_match('/^(d|b):(\d+)$/', $key, $m)) {
        $id = (int) $m[2];
        if ($id < 1) {
            return null;
        }
        return $m[1] === 'b'
            ? ['destination_id' => null, 'business_id' => $id]
            : ['destination_id' => $id, 'business_id' => null];
    }

    $did = (int) ($post['destination_id'] ?? 0);
    $bid = (int) ($post['business_id'] ?? 0);
    if ($did > 0 && $bid === 0) {
        return ['destination_id' => $did, 'business_id' => null];
    }
    if ($bid > 0 && $did === 0) {
        return ['destination_id' => null, 'business_id' => $bid];
    }
    return null;
}

/** Stable place key for a stop ref, e.g. "d:12" / "b:5". */
function itinerary_stop_ref_key(array $ref): ?string
{
    $did = (int) ($ref['destination_id'] ?? 0);
    $bid = (int) ($ref['business_id'] ?? 0);
    if ($did > 0) {
        return 'd:' . $did;
    }
    if ($bid > 0) {
        return 'b:' . $bid;
    }
    return null;
}

/** Stable place key from an itinerary_items row. */
function itinerary_item_place_key(array $item): ?string
{
    return itinerary_stop_ref_key([
        'destination_id' => $item['destination_id'] ?? null,
        'business_id' => $item['business_id'] ?? null,
    ]);
}

/**
 * Duplicate-stop check when appending a place to a day.
 * - adjacent: same place as the last stop on that day (hard block)
 * - exists: place already appears somewhere on the tour (needs user confirm)
 *
 * @return array{key:?string,exists:bool,adjacent:bool,name:string}
 */
function itinerary_duplicate_stop_status(int $itineraryId, array $ref, ?string $visitDate): array
{
    $key = itinerary_stop_ref_key($ref);
    $out = ['key' => $key, 'exists' => false, 'adjacent' => false, 'name' => 'This place'];
    if ($key === null) {
        return $out;
    }

    $place = itinerary_place_coords($ref);
    if ($place && !empty($place['name'])) {
        $out['name'] = (string) $place['name'];
    }

    $items = itinerary_items_with_places($itineraryId);
    $lastOnDay = null;
    foreach ($items as $it) {
        $itKey = itinerary_item_place_key($it);
        if ($itKey === $key) {
            $out['exists'] = true;
        }
        $itDate = normalize_tour_date($it['visit_date'] ?? null);
        $targetDate = normalize_tour_date($visitDate);
        if ($targetDate !== null) {
            if ($itDate === $targetDate) {
                $lastOnDay = $it;
            }
        } else {
            // Draft / no dates — treat whole tour as one sequence
            $lastOnDay = $it;
        }
    }
    if ($lastOnDay && itinerary_item_place_key($lastOnDay) === $key) {
        $out['adjacent'] = true;
        $out['exists'] = true;
    }
    return $out;
}

/**
 * True when any two consecutive stops (same visit day) share the same place.
 * @param list<int|string> $orderedIds Item ids in proposed sort order
 */
function itinerary_order_has_adjacent_duplicate(int $itineraryId, array $orderedIds): bool
{
    if (count($orderedIds) < 2) {
        return false;
    }
    $items = itinerary_items_with_places($itineraryId);
    $byId = [];
    foreach ($items as $it) {
        $byId[(int) $it['id']] = $it;
    }
    $prevKey = null;
    $prevDate = null;
    foreach ($orderedIds as $rawId) {
        $id = (int) $rawId;
        if (!isset($byId[$id])) {
            continue;
        }
        $it = $byId[$id];
        $key = itinerary_item_place_key($it);
        $date = normalize_tour_date($it['visit_date'] ?? null) ?? '';
        if ($prevKey !== null && $key !== null && $key === $prevKey && $date === $prevDate) {
            return true;
        }
        $prevKey = $key;
        $prevDate = $date;
    }
    return false;
}

/** Ormoc City Port — default meetup / tour start for itinerary timing */
function ormoc_port_coords(): array
{
    return [
        'lat' => 11.0039142,
        'lng' => 124.6058115,
        'title' => 'Ormoc City Port',
    ];
}

/**
 * Parse a clock time into minutes from midnight.
 * Accepts 24h ("08:00", "08:00:00") and 12h ("8:00 AM", "8 AM", "8:00pm").
 */
function itin_time_to_minutes(?string $t): ?int
{
    if ($t === null || trim($t) === '') {
        return null;
    }
    $t = trim($t);

    if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', $t, $m)) {
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $ampm = strtoupper($m[3]);
        if ($h < 1 || $h > 12 || $min < 0 || $min > 59) {
            return null;
        }
        if ($ampm === 'AM') {
            $h = ($h === 12) ? 0 : $h;
        } else {
            $h = ($h === 12) ? 12 : $h + 12;
        }
        return $h * 60 + $min;
    }

    $parts = explode(':', $t);
    if (count($parts) < 2) {
        return null;
    }
    $h = (int) $parts[0];
    $m = (int) $parts[1];
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
        return null;
    }
    return $h * 60 + $m;
}

/** Storage / 24h "HH:MM" (for TIME columns and internal math). */
function itin_minutes_to_time(int $mins): string
{
    $mins = max(0, min(23 * 60 + 59, $mins));
    return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
}

/** Standard 12-hour display, e.g. "8:00 AM". */
function format_minutes_12h(int $mins): string
{
    $mins = max(0, min(23 * 60 + 59, $mins));
    $h24 = intdiv($mins, 60);
    $m = $mins % 60;
    $ampm = $h24 >= 12 ? 'PM' : 'AM';
    $h12 = $h24 % 12;
    if ($h12 === 0) {
        $h12 = 12;
    }
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

/** Value for <input type="time"> (24h HH:MM). Falls back when the stored string is not a clock time. */
function time_picker_value(?string $t, string $fallback = '08:00'): string
{
    $mins = itin_time_to_minutes($t);
    if ($mins === null) {
        $mins = itin_time_to_minutes($fallback);
    }
    if ($mins === null) {
        return '08:00';
    }
    return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
}

/** Format a stored TIME / "HH:MM" / 12h string for display. */
function format_time_12h(?string $t): string
{
    if ($t === null || trim($t) === '') {
        return '—';
    }
    $mins = itin_time_to_minutes($t);
    if ($mins === null) {
        return '—';
    }
    return format_minutes_12h($mins);
}

/**
 * Normalize any accepted clock string to storage "HH:MM", or null if invalid.
 */
function normalize_time_input(?string $t): ?string
{
    $mins = itin_time_to_minutes($t);
    return $mins === null ? null : itin_minutes_to_time($mins);
}

/**
 * Reject tour start / Port meetup when the date+time is already in the past.
 * Example: tour date = today, meetup = 8:00 AM, and it is now after 8:00 AM.
 *
 * @return string|null Error message, or null if OK
 */
function tour_datetime_past_message(?string $date, ?string $time, string $label = 'Meetup time'): ?string
{
    $date = normalize_tour_date($date);
    if ($date === null) {
        return null; // caller handles missing date separately
    }
    $today = app_today();
    if ($date < $today) {
        return 'Tour date cannot be in the past. Choose today or a future date.';
    }

    $timeNorm = normalize_time_input($time);
    if ($timeNorm === null) {
        return null; // caller handles invalid time separately
    }

    if ($date === $today) {
        $reqMins = itin_time_to_minutes($timeNorm);
        $nowMins = app_now_mins();
        if ($reqMins !== null && $reqMins < $nowMins) {
            return $label . ' (' . format_time_12h($timeNorm) . ') is already past for today. Choose a later time or another date.';
        }
    }
    return null;
}

/**
 * Display an hours slot in 12h, e.g. "8:00 AM-5:00 PM" or "Closed".
 */
function format_hours_slot_12h(string $slot): string
{
    $slot = trim($slot);
    if ($slot === '' || strcasecmp($slot, 'Closed') === 0) {
        return 'Closed';
    }
    if (opening_hours_slot_is_24h($slot)) {
        return 'Open 24 hours';
    }
    $parts = preg_split('/\s*,\s*/', $slot) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (!preg_match('/^(.+?)\s*-\s*(.+)$/', $part, $m)) {
            $out[] = $part;
            continue;
        }
        $a = itin_time_to_minutes(trim($m[1]));
        $b = itin_time_to_minutes(trim($m[2]));
        if ($a === null || $b === null) {
            $out[] = $part;
            continue;
        }
        $out[] = format_minutes_12h($a) . '-' . format_minutes_12h($b);
    }
    return $out ? implode(', ', $out) : 'Closed';
}

/**
 * Normalize a day hours string to stored 24h ranges ("08:00-17:00") or "Closed".
 */
function normalize_hours_slot(string $slot): string
{
    $slot = trim($slot);
    if ($slot === '' || strcasecmp($slot, 'Closed') === 0) {
        return 'Closed';
    }
    $parts = preg_split('/\s*,\s*/', $slot) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (!preg_match('/^(.+?)\s*-\s*(.+)$/', $part, $m)) {
            return 'Closed';
        }
        $a = itin_time_to_minutes(trim($m[1]));
        $b = itin_time_to_minutes(trim($m[2]));
        if ($a === null || $b === null) {
            return 'Closed';
        }
        $out[] = itin_minutes_to_time($a) . '-' . itin_minutes_to_time($b);
    }
    return $out ? implode(',', $out) : 'Closed';
}

function itin_suggest_arrival(int $prevEndMins, int $travelMins): int
{
    return min(23 * 60 + 59, $prevEndMins + max(0, $travelMins));
}

function itin_compute_end(int $startMins, int $visitMins): int
{
    return min(23 * 60 + 59, $startMins + max(1, $visitMins));
}

/**
 * Fast road-time estimate from haversine distance (~30 km/h + small buffer).
 * Used for live reorder reflow; OSRM is used when adding stops / drawing routes.
 */
function itin_estimate_travel_minutes(float $fromLat, float $fromLng, float $toLat, float $toLng): int
{
    $km = itin_haversine_km($fromLat, $fromLng, $toLat, $toLng);
    if ($km < 0.05) {
        return 3;
    }
    return max(5, (int) ceil(($km / 30.0) * 60) + 2);
}

/**
 * Driving minutes between two points via OSRM (ceil). Fallback 45 on failure.
 */
function itin_osrm_travel_minutes(float $fromLat, float $fromLng, float $toLat, float $toLng): int
{
    $fallback = 45;
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false',
        $fromLng,
        $fromLat,
        $toLng,
        $toLat
    );
    try {
        $ctx = stream_context_create([
            'http' => ['timeout' => 4, 'header' => "User-Agent: CulTour/1.0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return $fallback;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0]['duration'])) {
            return $fallback;
        }
        return max(1, (int) ceil(((float) $data['routes'][0]['duration']) / 60));
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Previous leave point for the next stop: last stop end/start+visit, or Port meetup.
 * @return array{lat:float,lng:float,leave_mins:int,label:string}
 */
function itinerary_prev_leg_context(int $itineraryId, ?array $itinRow = null, ?string $forVisitDate = null): array
{
    ensure_itinerary_items_schema();
    $port = ormoc_port_coords();
    if ($itinRow === null) {
        $s = db()->prepare('SELECT meetup_time, start_date FROM itineraries WHERE id=?');
        $s->execute([$itineraryId]);
        $itinRow = $s->fetch() ?: [];
    }
    $meetupMins = itin_time_to_minutes(isset($itinRow['meetup_time']) ? (string) $itinRow['meetup_time'] : null);
    if ($meetupMins === null) {
        $meetupMins = 8 * 60; // 08:00
    }

    $portCtx = [
        'lat' => (float) $port['lat'],
        'lng' => (float) $port['lng'],
        'leave_mins' => $meetupMins,
        'label' => $port['title'],
    ];

    $stops = itinerary_items_with_places($itineraryId);
    if ($forVisitDate) {
        $forVisitDate = normalize_tour_date($forVisitDate);
        $stops = array_values(array_filter($stops, static function ($s) use ($forVisitDate, $itinRow) {
            $vd = normalize_tour_date($s['visit_date'] ?? null)
                ?: normalize_tour_date($itinRow['start_date'] ?? null);
            return $vd === $forVisitDate;
        }));
    }
    if (!$stops) {
        $start = normalize_tour_date($itinRow['start_date'] ?? null);
        $dayNum = 1;
        if ($forVisitDate && $start) {
            $dayNum = itinerary_day_number($start, $forVisitDate);
        }
        $meetupPlace = itinerary_day_meetup_place($itineraryId, $dayNum, $itinRow);
        $leaveMins = $meetupPlace['meetup_mins'] ?? null;
        if ($leaveMins === null) {
            $leaveMins = $meetupMins;
        }
        if ($dayNum === 1 || !empty($meetupPlace['has_place'])) {
            return [
                'lat' => (float) $meetupPlace['lat'],
                'lng' => (float) $meetupPlace['lng'],
                'leave_mins' => (int) $leaveMins,
                'label' => (string) $meetupPlace['label'],
                'skip_travel' => false,
                'suggest_mins' => (int) $leaveMins,
            ];
        }
        // Day 2+: no meetup place yet — first stop starts a fresh day.
        return [
            'lat' => (float) $port['lat'],
            'lng' => (float) $port['lng'],
            'leave_mins' => 0,
            'label' => 'start of day',
            'skip_travel' => true,
            'suggest_mins' => $meetupMins,
        ];
    }

    $last = $stops[count($stops) - 1];
    $startMins = itin_time_to_minutes(isset($last['start_time']) ? (string) $last['start_time'] : null);
    $endMins = itin_time_to_minutes(isset($last['end_time']) ? (string) $last['end_time'] : null);
    $visit = (int) ($last['visit_minutes'] ?? 60);
    if ($visit < 1) {
        $visit = 60;
    }
    if ($endMins === null && $startMins !== null) {
        $endMins = itin_compute_end($startMins, $visit);
    }
    if ($endMins === null) {
        $endMins = $meetupMins;
    }

    $lat = isset($last['latitude']) && $last['latitude'] !== null
        ? (float) $last['latitude']
        : (float) $port['lat'];
    $lng = isset($last['longitude']) && $last['longitude'] !== null
        ? (float) $last['longitude']
        : (float) $port['lng'];

    return [
        'lat' => $lat,
        'lng' => $lng,
        'leave_mins' => $endMins,
        'label' => (string) ($last['stop_name'] ?? $last['destination_name'] ?? 'Previous stop'),
    ];
}

/**
 * Resolve place coordinates + schedule metadata for a stop ref.
 * @param array{destination_id:?int,business_id:?int} $ref
 * @return array{lat:float,lng:float,name:string,opening_hours:?string,default_visit_minutes:int}|null
 */
function itinerary_place_coords(array $ref): ?array
{
    ensure_place_schedule_schema();
    if (!empty($ref['destination_id'])) {
        $s = db()->prepare(
            "SELECT name, latitude, longitude, opening_hours, default_visit_minutes, visit_duration_minutes
             FROM destinations WHERE id=? AND status='published'"
        );
        $s->execute([(int) $ref['destination_id']]);
        $row = $s->fetch();
        if (!$row) {
            return null;
        }
        $visit = (int) ($row['default_visit_minutes'] ?? 60);
        if ($visit < 15) {
            $visit = 60;
        }
        return [
            'lat' => $row['latitude'] !== null ? (float) $row['latitude'] : 0.0,
            'lng' => $row['longitude'] !== null ? (float) $row['longitude'] : 0.0,
            'name' => (string) $row['name'],
            'opening_hours' => isset($row['opening_hours']) ? (string) $row['opening_hours'] : null,
            'default_visit_minutes' => $visit,
            'visit_limit_minutes' => place_visit_limit($row['visit_duration_minutes'] ?? null),
        ];
    }
    if (!empty($ref['business_id'])) {
        $s = db()->prepare(
            "SELECT name, latitude, longitude, opening_hours, default_visit_minutes, visit_duration_minutes FROM businesses
             WHERE id=? AND approval_status='approved' AND status='active'"
        );
        $s->execute([(int) $ref['business_id']]);
        $row = $s->fetch();
        if (!$row) {
            return null;
        }
        $visit = (int) ($row['default_visit_minutes'] ?? 60);
        if ($visit < 15) {
            $visit = 60;
        }
        $oh = $row['opening_hours'] ?? null;
        if (is_array($oh)) {
            $oh = json_encode($oh);
        }
        return [
            'lat' => $row['latitude'] !== null ? (float) $row['latitude'] : 0.0,
            'lng' => $row['longitude'] !== null ? (float) $row['longitude'] : 0.0,
            'name' => (string) $row['name'],
            'opening_hours' => $oh !== null ? (string) $oh : null,
            'default_visit_minutes' => $visit,
            'visit_limit_minutes' => place_visit_limit($row['visit_duration_minutes'] ?? null),
        ];
    }
    return null;
}

/** Stored visit duration → limit in minutes, or null when the place has no limit. */
function place_visit_limit(mixed $raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $mins = (int) $raw;
    if ($mins <= 0) {
        return null;
    }
    return max(15, min(ITIN_MAX_VISIT_MINUTES, $mins));
}

function format_visit_duration_label(int $mins): string
{
    $mins = max(0, $mins);
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    if ($h > 0 && $m > 0) {
        return $h . ' hr ' . $m . ' min';
    }
    if ($h > 0) {
        return $h . ' hr';
    }
    return $m . ' min';
}

function place_visit_duration_display(mixed $raw): string
{
    $limit = place_visit_limit($raw);
    return $limit === null ? 'No limit' : format_visit_duration_label($limit);
}

/** Error when a stop's stay is longer than the place allows, or null if OK. */
function itinerary_visit_limit_message(string $name, ?int $limit, int $visitMins): ?string
{
    if ($limit === null || $visitMins <= $limit) {
        return null;
    }
    $label = format_visit_duration_label($limit);
    return ($name !== '' ? $name : 'This place') . ' has a visit duration of ' . $label
        . ' only. Set the stay to ' . $label . ' or less.';
}

/**
 * Visit duration from admin/business forms: "HH:MM" in visit_duration, or visit_no_limit.
 * @return array{ok:bool,limit:?int,message?:string}
 */
function parse_visit_duration_post(array $post): array
{
    if (!empty($post['visit_no_limit'])) {
        return ['ok' => true, 'limit' => null];
    }
    $raw = trim((string) ($post['visit_duration'] ?? ''));
    if ($raw === '') {
        return ['ok' => false, 'limit' => null, 'message' => 'Enter a visit duration or check No limit.'];
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        return ['ok' => false, 'limit' => null, 'message' => 'Enter the visit duration as hours and minutes, like 01:30.'];
    }
    $mins = ((int) $m[1] * 60) + (int) $m[2];
    if ((int) $m[2] > 59) {
        return ['ok' => false, 'limit' => null, 'message' => 'Minutes must be between 00 and 59.'];
    }
    if ($mins < 15) {
        return ['ok' => false, 'limit' => null, 'message' => 'Visit duration must be at least 15 minutes.'];
    }
    if ($mins > ITIN_MAX_VISIT_MINUTES) {
        return ['ok' => false, 'limit' => null, 'message' => 'Visit duration can be at most ' . format_visit_duration_label(ITIN_MAX_VISIT_MINUTES) . '.'];
    }
    return ['ok' => true, 'limit' => $mins];
}

function visit_duration_field_html(string $idPrefix, mixed $storedLimit, string $formId = ''): string
{
    $limit = place_visit_limit($storedLimit);
    $noLimit = $limit === null;
    $formAttr = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    $id = e($idPrefix);
    $clock = $noLimit ? '' : sprintf('%02d:%02d', intdiv($limit, 60), $limit % 60);
    $unit = $noLimit ? '' : (intdiv($limit, 60) < 1 ? 'mins' : (intdiv($limit, 60) === 1 ? 'hour' : 'hours'));
    $html = '<div class="visit-duration-field" data-visit-duration>';
    $html .= '<label class="form-label small mb-1" for="' . $id . 'Clock">Visit duration</label>';
    $html .= '<div class="visit-duration-field__row">';
    $html .= '<div class="itin-visit-clock-wrap' . ($unit === '' ? ' is-empty' : '') . '">';
    $html .= '<input type="text" class="form-control form-control-sm itin-visit-clock" id="' . $id . 'Clock" name="visit_duration"'
        . ' data-visit-duration-clock inputmode="numeric" autocomplete="off" placeholder="--:--" maxlength="5"'
        . ' aria-label="Visit duration hours and minutes" value="' . e($clock) . '"' . ($noLimit ? ' disabled' : '') . $formAttr . '>';
    $html .= '<span class="itin-visit-clock__unit" data-itin-visit-unit>' . e($unit) . '</span>';
    $html .= '</div>';
    $html .= '<div class="form-check mb-0">';
    $html .= '<input class="form-check-input" type="checkbox" name="visit_no_limit" value="1" id="' . $id . 'NoLimit"'
        . ' data-visit-no-limit' . ($noLimit ? ' checked' : '') . $formAttr . '>';
    $html .= '<label class="form-check-label small" for="' . $id . 'NoLimit">No limit</label>';
    $html .= '</div></div>';
    $html .= '<div class="form-text text-danger d-none" data-visit-duration-warn></div>';
    $html .= '<div class="form-text">Longest stay allowed in an itinerary. Check No limit to allow any length.</div>';
    $html .= '</div>';
    return $html;
}

/** @return list<string> */
function opening_hours_day_keys(): array
{
    return ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
}

const OPENING_HOURS_24H_SLOT = '00:00-23:59';

function opening_hours_slot_is_24h(string $slot): bool
{
    $slot = trim($slot);
    if ($slot === '' || str_contains($slot, ',')) {
        return false;
    }
    if (!preg_match('/^(.+?)\s*-\s*(.+)$/', $slot, $m)) {
        return false;
    }
    $a = itin_time_to_minutes(trim($m[1]));
    $b = itin_time_to_minutes(trim($m[2]));
    return $a === 0 && $b !== null && $b >= 23 * 60 + 59;
}

/**
 * Decode structured hours JSON (mon→sun). Free-text legacy returns null.
 * @return array<string,string>|null
 */
function parse_opening_hours_value(mixed $raw): ?array
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_array($raw)) {
        $decoded = $raw;
    } else {
        $decoded = json_decode((string) $raw, true);
    }
    if (!is_array($decoded)) {
        return null;
    }
    $days = opening_hours_day_keys();
    $out = [];
    $hasDay = false;
    foreach ($days as $d) {
        if (array_key_exists($d, $decoded)) {
            $hasDay = true;
            $out[$d] = trim((string) $decoded[$d]);
        } else {
            $out[$d] = 'Closed';
        }
    }
    return $hasDay ? $out : null;
}

/**
 * Try to pull an open/close range from free-text hours (legacy destination text).
 * @return array{open:int,close:int}|null minutes from midnight
 */
function parse_free_text_hours_range(?string $raw): ?array
{
    if ($raw === null) {
        return null;
    }
    $text = trim($raw);
    if ($text === '' || strcasecmp($text, 'Closed') === 0) {
        return null;
    }
    if (preg_match('/24\s*hours|open\s*all\s*day|always\s*open/i', $text)) {
        return ['open' => 0, 'close' => 23 * 60 + 59];
    }

    // "8:00 AM-5:00 PM", "7:00 AM – 6:00 PM", "7:00 AM ? 6:00 PM", "08:00-17:00"
    $pattern = '/(\d{1,2}(?::\d{2})?\s*(?:AM|PM)?)\s*(?:[-–—to]+|[^\dA-Za-z]{1,5})\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM)?)/i';
    if (!preg_match($pattern, $text, $m)) {
        return null;
    }
    $openRaw = trim($m[1]);
    $closeRaw = trim($m[2]);
    // If both lack AM/PM, keep as 24h; if only one has it, don't guess.
    $open = itin_time_to_minutes($openRaw);
    $close = itin_time_to_minutes($closeRaw);
    if ($open === null || $close === null || $close <= $open) {
        return null;
    }
    return ['open' => $open, 'close' => $close];
}

/**
 * Hours map used for hard-blocking: structured JSON, or free-text range applied to every day.
 * @return array<string,string>|null
 */
function resolve_place_opening_hours(mixed $raw): ?array
{
    $structured = parse_opening_hours_value($raw);
    if ($structured !== null) {
        return $structured;
    }
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        return null;
    }
    $range = parse_free_text_hours_range(is_string($raw) ? $raw : (string) json_encode($raw));
    if ($range === null) {
        return null;
    }
    $slot = itin_minutes_to_time($range['open']) . '-' . itin_minutes_to_time($range['close']);
    $out = [];
    foreach (opening_hours_day_keys() as $d) {
        $out[$d] = $slot;
    }
    return $out;
}

/** Operating hours are set: at least one open day, or legacy free text with a readable time range. */
function place_has_operating_hours(mixed $raw): bool
{
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        return false;
    }
    $structured = parse_opening_hours_value($raw);
    if ($structured === null) {
        return is_string($raw) && parse_free_text_hours_range($raw) !== null;
    }
    foreach ($structured as $slot) {
        if (trim((string) $slot) !== '' && strcasecmp(trim((string) $slot), 'Closed') !== 0) {
            return true;
        }
    }
    return false;
}

/** Business row needs approval_status, description, logo, cover_image, latitude, longitude, opening_hours, photo_count. */
function business_itinerary_ready(array $biz): bool
{
    return business_listing_unavailable_message($biz) === null;
}

/** Why a tourist cannot see or book this listing. Null when the profile and hours are finished. */
function business_listing_unavailable_message(array $biz): ?string
{
    if (!business_listing_is_approved($biz)) {
        return 'This listing isn’t available.';
    }
    $name = trim((string) ($biz['business_name'] ?? $biz['name'] ?? ''));
    $who = $name !== '' ? $name : 'This listing';
    if (business_listing_finish_gaps($biz, (int) ($biz['photo_count'] ?? 0))) {
        return $who . ' isn’t finished yet. It can’t be booked until the profile is complete.';
    }
    if (!place_has_operating_hours($biz['opening_hours'] ?? null)) {
        return $who . ' can’t be booked until operating hours are set.';
    }
    return null;
}

function destination_itinerary_ready(array $dest): bool
{
    return place_has_operating_hours($dest['opening_hours'] ?? null);
}

/** SQL select list for business_itinerary_ready(), aliasing businesses as b. */
function business_itinerary_ready_columns(): string
{
    return "b.approval_status, b.description, b.logo, b.cover_image, b.latitude, b.longitude, b.opening_hours,
            (SELECT COUNT(*) FROM business_photos bp WHERE bp.business_id = b.id) AS photo_count";
}

/** @param array{destination_id:?int,business_id:?int} $ref */
function itinerary_place_unavailable_message(array $ref, array $place): ?string
{
    $name = trim((string) ($place['name'] ?? ''));
    $msg = ($name !== '' ? $name : 'This place') . ' isn’t available for itineraries yet.';
    if (!empty($ref['business_id'])) {
        $st = db()->prepare('SELECT ' . business_itinerary_ready_columns() . ' FROM businesses b WHERE b.id=?');
        $st->execute([(int) $ref['business_id']]);
        $row = $st->fetch();
        return $row && business_itinerary_ready($row) ? null : $msg;
    }
    return place_has_operating_hours($place['opening_hours'] ?? null) ? null : $msg;
}

/**
 * Build hours JSON from POST day fields.
 * @param array<string,mixed> $post
 */
function encode_opening_hours_from_post(array $post): string
{
    if (isset($post['day']) || isset($post['day_start'])) {
        return encode_opening_hours_from_avail_post($post);
    }
    $data = [];
    foreach (opening_hours_day_keys() as $d) {
        $val = trim((string) ($post[$d] ?? 'Closed'));
        $data[$d] = $val !== '' ? normalize_hours_slot($val) : 'Closed';
    }
    return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
}

/** Map PHP date('w') 0=Sunday … 6=Saturday to opening_hours JSON keys. */
function opening_hours_key_for_dow(int $dow): string
{
    return ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][$dow] ?? 'mon';
}

/**
 * Convert stored hours JSON into the same per-day shape as tour guide availability.
 * @param array<string,string>|null $hours
 * @return array<int,array{on:bool,start:string,end:string}>
 */
function opening_hours_to_avail_by_day(?array $hours): array
{
    $byDay = [];
    for ($i = 0; $i < 7; $i++) {
        $key = opening_hours_key_for_dow($i);
        $slot = trim((string) ($hours[$key] ?? ''));
        $on = $slot !== '' && strcasecmp($slot, 'Closed') !== 0;
        $start = '8:00 AM';
        $end = '5:00 PM';
        if ($on && opening_hours_slot_is_24h($slot)) {
            $byDay[$i] = ['on' => true, 'start' => $start, 'end' => $end, 'all_day' => true];
            continue;
        }
        if ($on && preg_match('/^(.+?)\s*-\s*(.+)$/', $slot, $m)) {
            $a = itin_time_to_minutes(trim($m[1]));
            $b = itin_time_to_minutes(trim($m[2]));
            if ($a !== null) {
                $start = format_minutes_12h($a);
            }
            if ($b !== null) {
                $end = format_minutes_12h($b);
            }
        }
        $byDay[$i] = ['on' => $on, 'start' => $start, 'end' => $end, 'all_day' => false];
    }
    return $byDay;
}

/**
 * Build hours JSON from availability-style POST: day[] + day_start[N] / day_end[N].
 * Unchecked days are stored as Closed.
 * @param array<string,mixed> $post
 */
function encode_opening_hours_from_avail_post(array $post): string
{
    $daysOn = $post['day'] ?? [];
    if (!is_array($daysOn)) {
        $daysOn = [];
    }
    $daysOn = array_map('intval', $daysOn);
    $starts = is_array($post['day_start'] ?? null) ? $post['day_start'] : [];
    $ends = is_array($post['day_end'] ?? null) ? $post['day_end'] : [];
    $allDay = is_array($post['day_24h'] ?? null) ? array_map('intval', $post['day_24h']) : [];
    $data = [];
    for ($i = 0; $i < 7; $i++) {
        $key = opening_hours_key_for_dow($i);
        if (!in_array($i, $daysOn, true)) {
            $data[$key] = 'Closed';
            continue;
        }
        if (in_array($i, $allDay, true)) {
            $data[$key] = OPENING_HOURS_24H_SLOT;
            continue;
        }
        $start = trim((string) ($starts[$i] ?? '8:00 AM'));
        $end = trim((string) ($ends[$i] ?? '5:00 PM'));
        $data[$key] = normalize_hours_slot($start . '-' . $end);
    }
    return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * Checkbox + start/end rows matching tour guide Availability.
 * @param array<int,array{on:bool,start:string,end:string}> $byDay
 */
function opening_hours_avail_rows_html(string $idPrefix, array $byDay, bool $showCutoff = false, string $formId = ''): string
{
    $labels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $formAttr = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    $html = '<div class="guide-profile-avail__rows">';
    for ($i = 0; $i < 7; $i++) {
        $on = !empty($byDay[$i]['on']);
        $allDay = $on && !empty($byDay[$i]['all_day']);
        $timesOn = $on && !$allDay;
        $start = e(time_picker_value((string) ($byDay[$i]['start'] ?? ''), '8:00 AM'));
        $end = e(time_picker_value((string) ($byDay[$i]['end'] ?? ''), '5:00 PM'));
        $id = e($idPrefix);
        $html .= '<div class="guide-profile-avail__row guide-profile-avail__row--hours' . ($on ? ' is-on' : '') . ($allDay ? ' is-24h' : '') . '">';
        $html .= '<div class="form-check guide-profile-avail__day">';
        $html .= '<input class="form-check-input" type="checkbox" name="day[]" value="' . $i . '" id="' . $id . 'Day' . $i . '"'
            . ($on ? ' checked' : '') . ' data-avail-day-toggle' . $formAttr . '>';
        $html .= '<label class="form-check-label" for="' . $id . 'Day' . $i . '">' . e($labels[$i]) . '</label>';
        $html .= '</div><div class="guide-profile-avail__times"><div>';
        $html .= '<label class="form-label small mb-0" for="' . $id . 'Start' . $i . '">Start</label>';
        $html .= '<input type="time" class="form-control form-control-sm" id="' . $id . 'Start' . $i . '" name="day_start[' . $i . ']" value="'
            . $start . '" step="60"' . ($timesOn ? ' required' : ' disabled') . $formAttr . '>';
        $html .= '</div><div>';
        $html .= '<label class="form-label small mb-0" for="' . $id . 'End' . $i . '">End</label>';
        $html .= '<input type="time" class="form-control form-control-sm" id="' . $id . 'End' . $i . '" name="day_end[' . $i . ']" value="'
            . $end . '" step="60" data-avail-end' . ($timesOn ? ' required' : ' disabled') . $formAttr . '>';
        $html .= '</div>';
        $html .= '<div class="form-check guide-profile-avail__24h mb-0">';
        $html .= '<input class="form-check-input" type="checkbox" name="day_24h[]" value="' . $i . '" id="' . $id . 'AllDay' . $i . '"'
            . ($allDay ? ' checked' : '') . ' data-avail-24h' . $formAttr . '>';
        $html .= '<label class="form-check-label small" for="' . $id . 'AllDay' . $i . '">24 hrs</label>';
        $html .= '</div>';
        if ($showCutoff) {
            $cutoff = function_exists('guide_booking_cutoff_display')
                ? (string) guide_booking_cutoff_display((string) ($byDay[$i]['end'] ?? ''))
                : '';
            $html .= '<p class="guide-profile-avail__cutoff mb-0" data-avail-cutoff>Same-day booking cutoff: '
                . e($cutoff !== '' ? $cutoff : '—') . '</p>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';
    return $html;
}

function itinerary_day_key(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    if ($ts === false) {
        $ts = time();
    }
    $map = [
        'Mon' => 'mon',
        'Tue' => 'tue',
        'Wed' => 'wed',
        'Thu' => 'thu',
        'Fri' => 'fri',
        'Sat' => 'sat',
        'Sun' => 'sun',
    ];
    return $map[date('D', $ts)] ?? 'mon';
}

/**
 * Whether minutes-from-midnight fall inside a day's hours string.
 * Supports 24h or 12h ranges, comma-separated windows, and "Closed".
 */
function opening_hours_covers_day(string $slot, int $mins): bool
{
    $slot = trim($slot);
    if ($slot === '' || strcasecmp($slot, 'Closed') === 0) {
        return false;
    }
    $parts = preg_split('/\s*,\s*/', $slot) ?: [];
    foreach ($parts as $part) {
        if (!preg_match('/^(.+?)\s*-\s*(.+)$/', trim($part), $m)) {
            continue;
        }
        $a = itin_time_to_minutes(trim($m[1]));
        $b = itin_time_to_minutes(trim($m[2]));
        if ($a === null || $b === null) {
            continue;
        }
        if ($mins >= $a && $mins <= $b) {
            return true;
        }
    }
    return false;
}

/**
 * Hard-block validation for itinerary stop times.
 * @return string|null Error message, or null if OK
 */
function itinerary_hard_block_message(
    array $itinRow,
    array $prev,
    int $travelMins,
    int $arriveMins,
    int $leaveMins,
    array $place
): ?string {
    $suggest = itin_suggest_arrival((int) $prev['leave_mins'], $travelMins);
    $label = (string) ($prev['label'] ?? 'previous stop');
    $name = (string) ($place['name'] ?? 'This place');

    if ($arriveMins < $suggest) {
        return 'Schedule too tight: travel from ' . $label . ' takes about ' . $travelMins .
            ' min. Earliest allowed arrival is ' . format_minutes_12h($suggest) . '.';
    }

    $hours = resolve_place_opening_hours($place['opening_hours'] ?? null);
    if ($hours === null) {
        // No usable hours on file — cannot enforce a closing time for this place.
        return null;
    }

    $day = itinerary_day_key(
        !empty($itinRow['start_date']) ? (string) $itinRow['start_date'] : null
    );
    $daySlot = $hours[$day] ?? 'Closed';
    $dayLabel = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ][$day] ?? $day;
    $hoursLabel = format_hours_slot_12h($daySlot);

    // Closing time is the last moment you may still be there — arrival must be before close.
    if (strcasecmp(trim($daySlot), 'Closed') === 0) {
        return $name . ' is closed on ' . $dayLabel . '.';
    }
    if (!opening_hours_covers_day($daySlot, $arriveMins) || !opening_hours_open_before_close($daySlot, $arriveMins)) {
        return $name . ' is closed at your arrival time (' . format_minutes_12h($arriveMins) . ') on ' . $dayLabel .
            ' (hours: ' . $hoursLabel . '). Choose a time before closing, or check the day’s meetup time and AM/PM.';
    }
    if (!opening_hours_covers_day($daySlot, $leaveMins)) {
        return 'Leave time falls outside ' . $name . ' operating hours on ' . $dayLabel .
            ' (hours: ' . $hoursLabel . '). Shorten the visit or arrive earlier.';
    }
    return null;
}

/**
 * Update one stop's stay (visit) minutes and cascade arrival/leave for later stops
 * on the same day only. Arrival of the edited stop stays fixed; leave shifts with stay.
 * @return string|null Error message, or null on success
 */
function itinerary_update_stop_stay_and_reflow_day(int $itineraryId, int $itemId, int $newVisitMinutes): ?string
{
    ensure_itinerary_items_schema();
    $visitMins = (int) $newVisitMinutes;
    if ($visitMins < 15) {
        $visitMins = 15;
    }
    if ($visitMins > ITIN_MAX_VISIT_MINUTES) {
        $visitMins = ITIN_MAX_VISIT_MINUTES;
    }

    $itin = db()->prepare('SELECT id, start_date, meetup_time FROM itineraries WHERE id=?');
    $itin->execute([$itineraryId]);
    $itin = $itin->fetch();
    if (!$itin) {
        return 'Itinerary not found.';
    }

    $all = itinerary_items_with_places($itineraryId);
    $edited = null;
    foreach ($all as $row) {
        if ((int) $row['id'] === $itemId) {
            $edited = $row;
            break;
        }
    }
    if (!$edited) {
        return 'Stop not found.';
    }

    $editedPlace = itinerary_place_coords([
        'destination_id' => !empty($edited['destination_id']) ? (int) $edited['destination_id'] : null,
        'business_id' => !empty($edited['business_id']) ? (int) $edited['business_id'] : null,
    ]);
    if ($editedPlace !== null) {
        $limitMsg = itinerary_visit_limit_message(
            (string) $editedPlace['name'],
            $editedPlace['visit_limit_minutes'] ?? null,
            $visitMins
        );
        if ($limitMsg !== null) {
            return $limitMsg;
        }
    }

    $dayKey = normalize_tour_date($edited['visit_date'] ?? null)
        ?: normalize_tour_date($itin['start_date'] ?? null);

    $dayStops = [];
    foreach ($all as $row) {
        $rowDay = normalize_tour_date($row['visit_date'] ?? null)
            ?: normalize_tour_date($itin['start_date'] ?? null);
        if ($rowDay === $dayKey) {
            $dayStops[] = $row;
        }
    }
    if (!$dayStops) {
        return 'No stops on this day.';
    }

    $idx = -1;
    foreach ($dayStops as $i => $row) {
        if ((int) $row['id'] === $itemId) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        return 'Stop not found on this day.';
    }

    $startMins = itin_time_to_minutes(isset($edited['start_time']) ? (string) $edited['start_time'] : null);
    if ($startMins === null) {
        $startMins = 8 * 60;
    }
    $endMins = itin_compute_end($startMins, $visitMins);
    $travelKept = (int) ($edited['travel_minutes'] ?? 0);

    db()->prepare(
        'UPDATE itinerary_items SET visit_minutes=?, start_time=?, end_time=?, travel_minutes=? WHERE id=? AND itinerary_id=?'
    )->execute([
        $visitMins,
        itin_minutes_to_time($startMins),
        itin_minutes_to_time($endMins),
        $travelKept,
        $itemId,
        $itineraryId,
    ]);

    $updFollowing = db()->prepare(
        'UPDATE itinerary_items SET start_time=?, end_time=?, travel_minutes=? WHERE id=? AND itinerary_id=?'
    );

    $prevLeave = $endMins;
    $prevLat = isset($edited['latitude']) && $edited['latitude'] !== null
        ? (float) $edited['latitude']
        : null;
    $prevLng = isset($edited['longitude']) && $edited['longitude'] !== null
        ? (float) $edited['longitude']
        : null;

    for ($i = $idx + 1; $i < count($dayStops); $i++) {
        $stop = $dayStops[$i];
        $visit = max(15, (int) ($stop['visit_minutes'] ?? 60));
        $lat = isset($stop['latitude']) && $stop['latitude'] !== null ? (float) $stop['latitude'] : null;
        $lng = isset($stop['longitude']) && $stop['longitude'] !== null ? (float) $stop['longitude'] : null;

        $travel = (int) ($stop['travel_minutes'] ?? 0);
        if ($travel <= 0 && $prevLat !== null && $prevLng !== null && $lat !== null && $lng !== null) {
            $travel = itin_osrm_travel_minutes($prevLat, $prevLng, $lat, $lng);
        }
        if ($travel < 0) {
            $travel = 0;
        }

        $arrive = itin_suggest_arrival($prevLeave, $travel);
        $leave = itin_compute_end($arrive, $visit);
        $updFollowing->execute([
            itin_minutes_to_time($arrive),
            itin_minutes_to_time($leave),
            $travel,
            (int) $stop['id'],
            $itineraryId,
        ]);

        $prevLeave = $leave;
        if ($lat !== null && $lng !== null) {
            $prevLat = $lat;
            $prevLng = $lng;
        }
    }

    return null;
}

/**
 * Recalculate travel + arrival/leave for every stop on a calendar day (in sort order).
 * Day 1 starts from Port @ meetup; Day 2+ first stop starts at meetup with no Port drive.
 */
function itinerary_reflow_day_times(int $itineraryId, ?string $visitDate = null): void
{
    ensure_itinerary_items_schema();
    $itin = db()->prepare('SELECT id, start_date, meetup_time FROM itineraries WHERE id=?');
    $itin->execute([$itineraryId]);
    $itin = $itin->fetch();
    if (!$itin) {
        return;
    }

    $startDate = normalize_tour_date($itin['start_date'] ?? null);
    $dayKey = normalize_tour_date($visitDate) ?: $startDate;
    $meetupMins = itin_time_to_minutes(isset($itin['meetup_time']) ? (string) $itin['meetup_time'] : null);
    if ($meetupMins === null) {
        $meetupMins = 8 * 60;
    }
    $isDayOne = $dayKey === null || $startDate === null || $dayKey === $startDate;

    $all = itinerary_items_with_places($itineraryId);
    $dayStops = [];
    foreach ($all as $row) {
        $rowDay = normalize_tour_date($row['visit_date'] ?? null) ?: $startDate;
        if ($dayKey === null || $rowDay === $dayKey) {
            $dayStops[] = $row;
        }
    }
    if (!$dayStops) {
        return;
    }

    $port = ormoc_port_coords();
    $prevLat = (float) $port['lat'];
    $prevLng = (float) $port['lng'];
    $prevLeave = $meetupMins;
    $upd = db()->prepare(
        'UPDATE itinerary_items SET start_time=?, end_time=?, travel_minutes=? WHERE id=? AND itinerary_id=?'
    );

    foreach ($dayStops as $i => $stop) {
        $visit = max(15, (int) ($stop['visit_minutes'] ?? 60));
        $lat = isset($stop['latitude']) && $stop['latitude'] !== null ? (float) $stop['latitude'] : null;
        $lng = isset($stop['longitude']) && $stop['longitude'] !== null ? (float) $stop['longitude'] : null;
        $hasCoords = $lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0);

        if ($i === 0 && !$isDayOne) {
            $travel = 0;
            $arrive = $meetupMins;
        } else {
            $travel = 0;
            if ($hasCoords) {
                // Fast estimate on reorder (OSM routing is used on the map / add-stop)
                $travel = itin_estimate_travel_minutes($prevLat, $prevLng, $lat, $lng);
            } else {
                $kept = (int) ($stop['travel_minutes'] ?? 0);
                $travel = $kept > 0 ? $kept : 45;
            }
            if ($travel < 0) {
                $travel = 0;
            }
            $arrive = itin_suggest_arrival($prevLeave, $travel);
        }

        $leave = itin_compute_end($arrive, $visit);
        $upd->execute([
            itin_minutes_to_time($arrive),
            itin_minutes_to_time($leave),
            $travel,
            (int) $stop['id'],
            $itineraryId,
        ]);

        $prevLeave = $leave;
        if ($hasCoords) {
            $prevLat = $lat;
            $prevLng = $lng;
        }
    }
}

/**
 * Apply a new global stop order, then reflow arrival times for each affected day.
 * @param list<int|string> $orderedIds
 * @return string|null Error message, or null on success
 */
function itinerary_reorder_stops_and_reflow(int $itineraryId, array $orderedIds): ?string
{
    ensure_itinerary_items_schema();
    if (itinerary_order_has_adjacent_duplicate($itineraryId, $orderedIds)) {
        return 'You can’t put the same place next to itself. Keep a different stop in between.';
    }

    $upd = db()->prepare('UPDATE itinerary_items SET sort_order=? WHERE id=? AND itinerary_id=?');
    $i = 0;
    foreach ($orderedIds as $itemId) {
        $upd->execute([$i, (int) $itemId, $itineraryId]);
        $i++;
    }

    $itin = db()->prepare('SELECT start_date FROM itineraries WHERE id=?');
    $itin->execute([$itineraryId]);
    $itin = $itin->fetch() ?: [];
    $startDate = normalize_tour_date($itin['start_date'] ?? null);

    $days = [];
    foreach (itinerary_items_with_places($itineraryId) as $row) {
        $d = normalize_tour_date($row['visit_date'] ?? null) ?: $startDate ?: '';
        $days[$d] = true;
    }
    foreach (array_keys($days) as $day) {
        itinerary_reflow_day_times($itineraryId, $day !== '' ? $day : null);
    }

    return null;
}

/** True when $mins is strictly before the day's closing time (arrival at closing is too late). */
function opening_hours_open_before_close(string $slot, int $mins): bool
{
    $slot = trim($slot);
    if ($slot === '' || strcasecmp($slot, 'Closed') === 0) {
        return false;
    }
    $parts = preg_split('/\s*,\s*/', $slot) ?: [];
    foreach ($parts as $part) {
        if (!preg_match('/^(.+?)\s*-\s*(.+)$/', trim($part), $m)) {
            continue;
        }
        $a = itin_time_to_minutes(trim($m[1]));
        $b = itin_time_to_minutes(trim($m[2]));
        if ($a === null || $b === null) {
            continue;
        }
        if ($mins >= $a && $mins < $b) {
            return true;
        }
    }
    return false;
}

/**
 * Password policy rules (shared by register / change / reset).
 * @return list<string>
 */
function password_rule_list(): array
{
    return [
        'At least 5 characters',
        'Include at least one letter (a–z)',
        'Include at least one number (0–9)',
        'No spaces',
    ];
}

/**
 * Validate a password against CulTour rules.
 * @return array{ok:bool,message:?string}
 */
function validate_password_rules(string $password, ?string $confirm = null): array
{
    if (strlen($password) < 5) {
        return ['ok' => false, 'message' => 'Password must be at least 5 characters.'];
    }
    if (preg_match('/\s/', $password)) {
        return ['ok' => false, 'message' => 'Password cannot contain spaces.'];
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        return ['ok' => false, 'message' => 'Password must include at least one letter.'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['ok' => false, 'message' => 'Password must include at least one number.'];
    }
    if ($confirm !== null && $confirm !== $password) {
        return ['ok' => false, 'message' => 'Passwords do not match.'];
    }
    return ['ok' => true, 'message' => null];
}

/**
 * HTML hint list for password fields (shown on focus / when invalid).
 */
function password_rules_hint_html(string $forId = 'password'): string
{
    $checks = [
        ['len', 'At least 5 characters'],
        ['letter', 'Include at least one letter (a–z)'],
        ['number', 'Include at least one number (0–9)'],
        ['space', 'No spaces'],
    ];
    $items = '';
    foreach ($checks as [$key, $label]) {
        $items .= '<li data-rule="' . e($key) . '">' . e($label) . '</li>';
    }
    return '<div class="password-rules-anchor">'
        . '<div class="password-rules" id="' . e($forId) . 'Rules" hidden>'
        . '<div class="small fw-semibold mb-1">Password must have:</div>'
        . '<ul class="small mb-0 password-rules__list">' . $items . '</ul>'
        . '<div class="password-rules__warn small text-danger mt-2 mb-0" id="' . e($forId) . 'Warn" hidden></div>'
        . '</div></div>';
}

/**
 * Inline warning under the confirm-password field.
 */
function password_confirm_warn_html(string $forId = 'password'): string
{
    return '<div class="invalid-feedback password-confirm-warn" id="' . e($forId) . 'ConfirmWarn">'
        . 'Passwords do not match.'
        . '</div>';
}

/**
 * JS: show password rules on focus; live-check and warn when invalid / mismatch.
 */
function password_rules_focus_script(string $inputId = 'password', string $rulesId = 'passwordRules'): string
{
    $inputIdJs = json_encode($inputId);
    $rulesIdJs = json_encode($rulesId);
    $warnIdJs = json_encode($inputId . 'Warn');
    $confirmWarnIdJs = json_encode($inputId . 'ConfirmWarn');
    $confirmIdJs = json_encode($inputId . '_confirm');
    return <<<HTML
<script>
(function () {
  var input = document.getElementById({$inputIdJs});
  var rules = document.getElementById({$rulesIdJs});
  var warn = document.getElementById({$warnIdJs});
  var confirmWarn = document.getElementById({$confirmWarnIdJs});
  if (!input || !rules) return;

  var form = input.form;
  var confirm = document.getElementById({$confirmIdJs})
    || (form && form.querySelector('input[name="password_confirm"]'));

  function checks(v) {
    return {
      len: v.length >= 5,
      letter: /[A-Za-z]/.test(v),
      number: /[0-9]/.test(v),
      space: v.length > 0 && !/\\s/.test(v)
    };
  }

  function ruleFail(v) {
    if (v.length < 5) return 'Password must be at least 5 characters.';
    if (/\\s/.test(v)) return 'Password cannot contain spaces.';
    if (!/[A-Za-z]/.test(v)) return 'Password must include at least one letter.';
    if (!/[0-9]/.test(v)) return 'Password must include at least one number.';
    return '';
  }

  function isMismatch() {
    return !!(confirm && confirm.value !== '' && confirm.value !== (input.value || ''));
  }

  function setConfirmWarn(on) {
    if (!confirm) return;
    confirm.classList.toggle('is-invalid', on);
    if (confirmWarn) {
      confirmWarn.style.display = on ? 'block' : '';
      if (on) confirmWarn.hidden = false;
    }
  }

  function paint() {
    var v = input.value || '';
    var c = checks(v);
    rules.querySelectorAll('[data-rule]').forEach(function (li) {
      var ok = !!c[li.getAttribute('data-rule')];
      li.classList.toggle('is-ok', ok && v.length > 0);
      li.classList.toggle('is-bad', !ok && v.length > 0);
    });
    var msg = v.length ? ruleFail(v) : '';
    if (warn) {
      if (msg) {
        warn.textContent = msg;
        warn.hidden = false;
      } else {
        warn.textContent = '';
        warn.hidden = true;
      }
    }
    input.classList.toggle('is-invalid', !!msg);
    setConfirmWarn(isMismatch());
    syncRulesVisibility();
    return !msg && !isMismatch();
  }

  function syncRulesVisibility() {
    var v = input.value || '';
    var fail = ruleFail(v);
    // Only while typing in the password field and something still fails.
    var inPasswordField = document.activeElement === input;
    rules.hidden = !(inPasswordField && v && !!fail);
  }

  function showRules() {
    var v = input.value || '';
    if (!(v && ruleFail(v))) {
      rules.hidden = true;
      return;
    }
    rules.hidden = false;
  }

  input.addEventListener('focus', function () { paint(); });
  input.addEventListener('input', function () { paint(); });
  input.addEventListener('blur', function (e) {
    // Eye toggle uses mousedown preventDefault; still ignore blur-to-toggle.
    var to = e.relatedTarget;
    if (to && to.closest && to.closest('.password-toggle')) return;
    paint();
    setTimeout(syncRulesVisibility, 150);
  });
  if (confirm) {
    confirm.addEventListener('input', paint);
    confirm.addEventListener('blur', function (e) {
      var to = e.relatedTarget;
      if (to && to.closest && to.closest('.password-toggle')) return;
      paint();
      setTimeout(syncRulesVisibility, 150);
    });
    confirm.addEventListener('focus', function () {
      paint();
    });
  }
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!paint()) {
        e.preventDefault();
        showRules();
        if (isMismatch() && confirm) confirm.focus();
        else input.focus();
      }
    });
  }
  if (document.querySelector('.alert-danger')) {
    paint();
    showRules();
  }
})();
</script>
HTML;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Same-origin return URL under APP_URL only (open-redirect safe). */
function safe_app_return_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    $base = rtrim(APP_URL, '/');
    if ($url !== $base && !str_starts_with($url, $base . '/')) {
        return null;
    }
    if (preg_match('/[\s<>"\']/', $url)) {
        return null;
    }
    return $url;
}

function account_change_return_url(?string $openModal = null): ?string
{
    $return = safe_app_return_url($_POST['return_to'] ?? null);
    if ($return === null) {
        return null;
    }
    if ($openModal !== null && $openModal !== '') {
        $sep = str_contains($return, '?') ? '&' : '?';
        $return .= $sep . 'account=' . rawurlencode($openModal);
    }
    return $return;
}

function flash(string $key, ?string $message = null): ?string
{
    start_app_session();
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    // Background live re-fetches must not consume a message meant for the next real page view.
    if (!empty($_SERVER['HTTP_X_CULTOUR_LIVE'])) {
        return null;
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(csrf_token()) . '">';
}

function csrf_error_message(): string
{
    return 'This page was open too long or your session ended, so it could not be submitted safely. Refresh the page and try again.';
}

function verify_csrf(): bool
{
    start_app_session();
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'item';
}

function booking_code(): string
{
    return 'CT' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Ensure payment columns exist on bookings.
 */
function ensure_booking_payment_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_status', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE bookings ADD COLUMN payment_status ENUM('unpaid','pending_review','paid','pay_at_office','refunded') NOT NULL DEFAULT 'unpaid' AFTER rejection_reason"
            );
        } else {
            $type = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'payment_status'")->fetch();
            $enum = strtolower((string) ($type['Type'] ?? ''));
            if ($enum !== '' && !str_contains($enum, 'pending_review')) {
                $pdo->exec(
                    "ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('unpaid','pending_review','paid','pay_at_office','refunded') NOT NULL DEFAULT 'unpaid'"
                );
            } elseif ($enum !== '' && !str_contains($enum, 'refunded')) {
                $pdo->exec(
                    "ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('unpaid','pending_review','paid','pay_at_office','refunded') NOT NULL DEFAULT 'unpaid'"
                );
            }
        }
        if (!in_array('payment_reference', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE bookings ADD COLUMN payment_reference VARCHAR(120) NULL AFTER payment_status"
            );
        }
        if (!in_array('payment_intent_id', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE bookings ADD COLUMN payment_intent_id VARCHAR(64) NULL AFTER payment_reference"
            );
        }
        if (!in_array('payment_amount', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE bookings ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER payment_intent_id"
            );
        }
        if (!in_array('checked_in_at', $cols, true)) {
            $pdo->exec('ALTER TABLE bookings ADD COLUMN checked_in_at DATETIME NULL AFTER qr_code_path');
        }
        // Snapshot of tour span at booking time (guide schedule must not follow later itinerary edits)
        $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tour_start_date', $cols, true)) {
            $pdo->exec(
                'ALTER TABLE bookings ADD COLUMN tour_start_date DATE NULL AFTER booking_date'
            );
        }
        if (!in_array('tour_end_date', $cols, true)) {
            $pdo->exec(
                'ALTER TABLE bookings ADD COLUMN tour_end_date DATE NULL AFTER tour_start_date'
            );
            $cols[] = 'tour_end_date';
        }
        $stayCols = [
            'accommodation_business_id' => 'INT UNSIGNED NULL',
            'accommodation_service_id' => 'INT UNSIGNED NULL',
            'accommodation_declined' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'transportation_business_id' => 'INT UNSIGNED NULL',
            'transportation_service_id' => 'INT UNSIGNED NULL',
            'transportation_declined' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($stayCols as $stayCol => $stayDef) {
            if (!in_array($stayCol, $cols, true)) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN ' . $stayCol . ' ' . $stayDef);
                $cols[] = $stayCol;
            }
        }
        $billCols = [
            'accommodation_units' => 'INT UNSIGNED NULL',
            'accommodation_extra_guests' => 'INT UNSIGNED NULL',
            'accommodation_amount' => 'DECIMAL(10,2) NULL',
            'transportation_units' => 'INT UNSIGNED NULL',
            'transportation_extra_guests' => 'INT UNSIGNED NULL',
            'transportation_amount' => 'DECIMAL(10,2) NULL',
            'guide_amount' => 'DECIMAL(10,2) NULL',
            'accommodation_lines' => 'TEXT NULL',
            'transportation_lines' => 'TEXT NULL',
        ];
        foreach ($billCols as $billCol => $billDef) {
            if (!in_array($billCol, $cols, true)) {
                $pdo->exec('ALTER TABLE bookings ADD COLUMN ' . $billCol . ' ' . $billDef);
                $cols[] = $billCol;
            }
        }
        // Backfill once for older rows — prefer real stop visit dates over later multi-day edits
        $pdo->exec(
            "UPDATE bookings b
             LEFT JOIN itineraries i ON i.id = b.itinerary_id
             LEFT JOIN (
               SELECT itinerary_id,
                      MIN(visit_date) AS first_stop,
                      MAX(visit_date) AS last_stop
               FROM itinerary_items
               WHERE visit_date IS NOT NULL
               GROUP BY itinerary_id
             ) stops ON stops.itinerary_id = b.itinerary_id
             SET b.tour_start_date = COALESCE(
                     b.tour_start_date,
                     stops.first_stop,
                     i.start_date,
                     b.booking_date
                 ),
                 b.tour_end_date = COALESCE(
                     b.tour_end_date,
                     stops.last_stop,
                     NULLIF(i.end_date, '0000-00-00'),
                     i.start_date,
                     b.booking_date
                 )
             WHERE b.tour_start_date IS NULL OR b.tour_end_date IS NULL"
        );
    } catch (Throwable $e) {
        // ignore
    }
    // Keep past approved tours completed whenever booking schema is touched
    if (function_exists('bookings_auto_complete_past_tours')) {
        bookings_auto_complete_past_tours();
    }
    if (function_exists('ensure_booking_replacement_schema')) {
        ensure_booking_replacement_schema();
    }
    if (function_exists('ensure_booking_accessibility_schema')) {
        ensure_booking_accessibility_schema();
    }
    if (function_exists('ensure_booking_stop_checkin_schema')) {
        ensure_booking_stop_checkin_schema();
    }
}

/**
 * Validate guide availability for a booking date/time (and multi-day span).
 * Checks calendar blocks, weekly hours, and time-window clashes vs other bookings.
 * A tour only blocks from meetup/start until that day’s computed end (itinerary last stop,
 * or start + 4h fallback), so the guide can be booked again after the tour ends.
 *
 * @param int|null $excludeBookingId Ignore this booking id when checking clashes (e.g. on Accept)
 * @param int|null $itineraryId Proposed itinerary (for span + stop end times)
 * @param string|null $tourEndDate Optional explicit tour end date Y-m-d
 * @return string|null Error message, or null if OK
 */
function guide_booking_availability_message(
    int $guideId,
    string $date,
    string $time,
    ?int $excludeBookingId = null,
    ?int $itineraryId = null,
    ?string $tourEndDate = null
): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Enter a valid booking date.';
    }
    $mins = itin_time_to_minutes($time);
    if ($mins === null) {
        return 'Enter a valid start time (e.g. 9:00 AM).';
    }
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return 'Enter a valid booking date.';
    }
    if ($date < app_today()) {
        return 'Tour date cannot be in the past. Choose today or a future date.';
    }

    $itinId = ($itineraryId && $itineraryId > 0) ? $itineraryId : null;
    [$resolvedStart, $resolvedEnd] = booking_resolve_tour_span($itinId, $date);
    $explicitEnd = normalize_tour_date($tourEndDate);
    $spanStart = $date;
    $spanEnd = $resolvedEnd ?: $spanStart;
    if ($explicitEnd !== null && $explicitEnd >= $spanStart) {
        $spanEnd = $explicitEnd;
    }
    if ($spanEnd < $spanStart) {
        $spanEnd = $spanStart;
    }
    $checkDates = itinerary_span_dates($itinId, $spanStart, $spanEnd);
    if (!$checkDates) {
        $checkDates = [$spanStart];
    }

    $itinMeetup = null;
    if ($itinId) {
        try {
            $m = db()->prepare('SELECT meetup_time FROM itineraries WHERE id=?');
            $m->execute([$itinId]);
            $itinMeetup = $m->fetchColumn() ?: null;
        } catch (Throwable $e) {
            $itinMeetup = null;
        }
    }

    $proposed = [
        'booking_date' => $date,
        'start_time' => normalize_time_input($time) ?? $time,
        'tour_start_date' => $spanStart,
        'tour_end_date' => $spanEnd,
        'itinerary_id' => $itinId,
        'itin_meetup' => $itinMeetup,
        'itin_start' => $spanStart,
        'itin_end' => $spanEnd,
    ];

    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    foreach ($checkDates as $di => $dayDate) {
        $d = $di + 1;
        $dayDate = normalize_tour_date($dayDate);
        if ($dayDate === null) {
            continue;
        }
        $dayTs = strtotime($dayDate . ' 12:00:00');
        if ($dayTs === false) {
            continue;
        }

        $block = db()->prepare(
            'SELECT reason FROM guide_calendar_blocks WHERE guide_id=? AND block_date=? LIMIT 1'
        );
        $block->execute([$guideId, $dayDate]);
        $blocked = $block->fetch();
        if ($blocked) {
            $why = trim((string) ($blocked['reason'] ?? ''));
            return 'This tour guide is unavailable on ' . format_date($dayDate)
                . ($why !== '' ? ' (' . $why . ')' : '') . '.';
        }

        $dow = (int) date('w', $dayTs);
        $avail = db()->prepare(
            'SELECT start_time, end_time FROM guide_availability
             WHERE guide_id=? AND day_of_week=? AND is_available=1 LIMIT 1'
        );
        $avail->execute([$guideId, $dow]);
        $slot = $avail->fetch();
        if (!$slot) {
            return 'This tour guide is not available on ' . ($dayNames[$dow] ?? 'that day')
                . ' (' . format_date($dayDate) . ').';
        }
        $open = itin_time_to_minutes((string) $slot['start_time']);
        $close = itin_time_to_minutes((string) $slot['end_time']);
        if ($open === null || $close === null) {
            return 'Guide weekly hours are invalid for ' . format_date($dayDate) . '.';
        }

        $hoursLabel = format_time_12h((string) $slot['start_time']) . '–' . format_time_12h((string) $slot['end_time']);
        if ($d === 1 && function_exists('guide_same_day_booking_cutoff_message')) {
            $cutoffMsg = guide_same_day_booking_cutoff_message($guideId, $dayDate);
            if ($cutoffMsg !== null) {
                return $cutoffMsg;
            }
        }
        $existing = guide_busy_windows_on_date($guideId, $dayDate, $excludeBookingId, $itinId);
        foreach ($existing as $exTourist) {
            if (!empty($exTourist['as_tourist'])) {
                return 'This tour guide is already on a tour as a tourist on ' . format_date($dayDate)
                    . ', so they can’t be booked that day.';
            }
        }
        // Day 2+: any existing tour that calendar day blocks the whole multi-day pick.
        if ($d > 1 && $existing) {
            $ex = $existing[0];
            $busyFrom = (int) ($ex['start_mins'] ?? 0);
            $busyUntil = (int) ($ex['end_mins'] ?? 0);
            $range = ($busyUntil > $busyFrom)
                ? ' (' . format_minutes_12h($busyFrom) . '–' . format_minutes_12h($busyUntil) . ')'
                : '';
            return 'This tour guide already has a tour on ' . format_date($dayDate)
                . $range
                . '. You can’t book them for a multi-day tour that includes that day.';
        }

        $propWin = booking_busy_window_for_date($proposed, $dayDate);
        if ($propWin === null) {
            continue;
        }

        // Every tour day (not only day 1) must start inside the guide’s weekly hours.
        $dayStartMins = (int) $propWin['start_mins'];
        if ($dayStartMins < $open || $dayStartMins >= $close) {
            $whenLabel = $d === 1 ? 'Your meetup' : 'Day ' . $d . ' start';
            return 'This tour guide is available '
                . $hoursLabel
                . ' on ' . format_date($dayDate)
                . '. ' . $whenLabel . ' (' . format_minutes_12h($dayStartMins) . ') is outside those hours — try a time in that window.';
        }

        // A planned itinerary day may not run past the guide's weekly end time.
        if ($itinId && booking_stops_on_calendar_date($proposed, $dayDate) && (int) $propWin['end_mins'] > $close) {
            $whenLabel = count($checkDates) > 1 ? 'Day ' . $d . ' of your itinerary' : 'Your itinerary';
            return 'This tour guide is available until ' . format_minutes_12h($close)
                . ' on ' . format_date($dayDate) . ' (hours ' . $hoursLabel . '). '
                . $whenLabel . ' ends at ' . format_minutes_12h((int) $propWin['end_mins'])
                . ' — start earlier, shorten or remove a stop, or choose another guide.';
        }

        // Cap proposed busy end at weekly close when known
        if ($propWin['end_mins'] > $close) {
            $propWin['end_mins'] = max($propWin['start_mins'] + 30, $close);
        }

        foreach ($existing as $ex) {
            if (booking_busy_windows_overlap(
                (int) $propWin['start_mins'],
                (int) $propWin['end_mins'],
                (int) $ex['start_mins'],
                (int) $ex['end_mins'],
                GUIDE_BOOKING_BUFFER_MINS
            )) {
                $busyUntil = (int) $ex['end_mins'];
                $busyFrom = (int) $ex['start_mins'];
                $freeFrom = $busyUntil + GUIDE_BOOKING_BUFFER_MINS;
                if ($freeFrom > 24 * 60 - 1) {
                    $freeFrom = 24 * 60 - 1;
                }
                if ($freeFrom < $close) {
                    $bufferNote = GUIDE_BOOKING_BUFFER_MINS > 0
                        ? ' (' . GUIDE_BOOKING_BUFFER_MINS . '-minute buffer after their previous tour ends at '
                            . format_minutes_12h($busyUntil) . ')'
                        : '';
                    return 'This tour guide is busy until ' . format_minutes_12h($busyUntil)
                        . ' on ' . format_date($dayDate)
                        . '. They are available from ' . format_minutes_12h($freeFrom)
                        . $bufferNote
                        . ' — hours ' . $hoursLabel . '. Try a meetup at '
                        . format_minutes_12h($freeFrom) . ' or later.';
                }
                return 'This tour guide is fully booked for ' . format_date($dayDate)
                    . ' during their hours (' . $hoursLabel . ').';
            }
        }
    }

    return null;
}

/**
 * Normalize and validate a tour/itinerary date (Y-m-d).
 */
function normalize_tour_date(?string $date): ?string
{
    $date = trim((string) $date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/** Number of calendar days in an itinerary (min 1). */
function itinerary_max_days(): int
{
    return 30;
}

function itinerary_day_count(?string $startDate, ?string $endDate = null): int
{
    $start = normalize_tour_date($startDate);
    if ($start === null) {
        return 1;
    }
    $end = normalize_tour_date($endDate) ?: $start;
    if ($end < $start) {
        $end = $start;
    }
    $days = (int) round((strtotime($end . ' 12:00:00') - strtotime($start . ' 12:00:00')) / 86400) + 1;
    return max(1, min(itinerary_max_days(), $days));
}

/**
 * Fixed anchor date for undated suggested-tour templates (Day 1 = this date).
 * Never shown to users as a real tour date.
 */
function itinerary_template_day_base(): string
{
    return '2000-01-01';
}

/** Day count stored on the tour (1 up to itinerary_max_days()). */
function itinerary_template_day_count(?array $itin): int
{
    if (!$itin) {
        return 1;
    }
    $n = (int) ($itin['template_day_count'] ?? 0);
    if ($n < 1) {
        // Fallback: derive from stored calendar range if present
        $n = itinerary_day_count($itin['start_date'] ?? null, $itin['end_date'] ?? null);
    }
    return max(1, min(itinerary_max_days(), $n));
}

/**
 * Number of logical day sections (2-day tour with a gap is still 2, not the calendar span).
 */
function itinerary_logical_day_count(?array $itin): int
{
    return itinerary_template_day_count($itin);
}

/**
 * Load itinerary row when callers only have an id.
 *
 * @return array<string,mixed>
 */
function itinerary_row_by_id(int $itinId): array
{
    if ($itinId < 1) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM itineraries WHERE id=? LIMIT 1');
    $st->execute([$itinId]);
    return $st->fetch() ?: [];
}

/**
 * Date per logical day 1..N. Missing meetup visit_date falls back to consecutive start+(n-1).
 *
 * @return array<int, string> day number => Y-m-d (empty string if undated)
 */
function itinerary_day_dates_map(int $itinId, ?array $itinRow = null): array
{
    ensure_itinerary_day_meetups_schema();
    if (!$itinRow) {
        $itinRow = itinerary_row_by_id($itinId);
    }
    $n = itinerary_logical_day_count($itinRow);
    $start = normalize_tour_date($itinRow['start_date'] ?? null);
    $stored = [];
    if ($itinId > 0) {
        $st = db()->prepare(
            'SELECT day_number, visit_date FROM itinerary_day_meetups WHERE itinerary_id=? ORDER BY day_number ASC'
        );
        $st->execute([$itinId]);
        foreach ($st->fetchAll() ?: [] as $row) {
            $day = (int) ($row['day_number'] ?? 0);
            $ymd = normalize_tour_date($row['visit_date'] ?? null);
            if ($day >= 1 && $ymd !== null) {
                $stored[$day] = $ymd;
            }
        }
    }
    $out = [];
    for ($d = 1; $d <= $n; $d++) {
        if (!empty($stored[$d])) {
            $out[$d] = $stored[$d];
        } elseif ($start !== null) {
            $out[$d] = itinerary_date_for_day($start, $d) ?: '';
        } else {
            $out[$d] = '';
        }
    }
    return $out;
}

/**
 * Actual tour days (gaps omitted), in day-number order.
 *
 * @return list<string>
 */
function itinerary_visit_dates_list(int $itinId, ?array $itinRow = null): array
{
    $dates = [];
    foreach (itinerary_day_dates_map($itinId, $itinRow) as $ymd) {
        $n = normalize_tour_date(is_string($ymd) ? $ymd : null);
        if ($n !== null) {
            $dates[] = $n;
        }
    }
    return array_values(array_unique($dates));
}

/**
 * Dates to check for guide availability / busy notes.
 * Prefers the itinerary’s stored day dates so gap days are skipped.
 *
 * @return list<string>
 */
function itinerary_span_dates(?int $itineraryId, ?string $startDate, ?string $endDate = null): array
{
    if ($itineraryId && $itineraryId > 0) {
        $list = itinerary_visit_dates_list($itineraryId);
        if ($list) {
            return $list;
        }
    }
    $start = normalize_tour_date($startDate);
    $end = normalize_tour_date($endDate) ?: $start;
    if ($start === null) {
        return [];
    }
    $n = itinerary_day_count($start, $end);
    $out = [];
    for ($d = 1; $d <= $n; $d++) {
        $day = itinerary_date_for_day($start, $d);
        if ($day) {
            $out[] = $day;
        }
    }
    return $out;
}

/** Stored or fallback date for logical Day N. */
function itinerary_date_for_logical_day(int $itinId, int $dayNumber, ?array $itinRow = null): ?string
{
    $map = itinerary_day_dates_map($itinId, $itinRow);
    $ymd = $map[$dayNumber] ?? '';
    return $ymd !== '' ? $ymd : null;
}

/**
 * @return list<array{day:int,date:string,label:string}>
 */
function itinerary_day_dates_payload(int $itinId, ?array $itinRow = null): array
{
    $days = [];
    foreach (itinerary_day_dates_map($itinId, $itinRow) as $day => $ymd) {
        $days[] = [
            'day' => (int) $day,
            'date' => $ymd,
            'label' => $ymd !== '' ? format_date($ymd) : 'Date not set',
        ];
    }
    return $days;
}

/**
 * JSON extras after a schedule / day-date save.
 *
 * @return array{start_date:?string,end_date:?string,day_count:int,is_multiday:bool,days:list,meetup_time:string}
 */
function itinerary_schedule_json_extra(int $itinId, ?array $itinRow = null, ?string $meetupTime = null): array
{
    if (!$itinRow) {
        $itinRow = itinerary_row_by_id($itinId);
    }
    $start = normalize_tour_date($itinRow['start_date'] ?? null);
    $end = normalize_tour_date($itinRow['end_date'] ?? null) ?: $start;
    $n = itinerary_logical_day_count($itinRow);
    $meetup = $meetupTime;
    if ($meetup === null) {
        $raw = trim((string) ($itinRow['meetup_time'] ?? ''));
        $meetup = $raw !== '' ? (format_time_12h($raw) ?: $raw) : '';
    }
    return [
        'start_date' => $start,
        'end_date' => $end,
        'day_count' => $n,
        'is_multiday' => $n > 1,
        'days' => itinerary_day_dates_payload($itinId, $itinRow),
        'meetup_time' => $meetup,
    ];
}

/** Rewrite itineraries.start_date / end_date from stored logical day dates. */
function itinerary_sync_span_from_day_dates(int $itinId): void
{
    $row = itinerary_row_by_id($itinId);
    if (!$row) {
        return;
    }
    $dates = itinerary_visit_dates_list($itinId, $row);
    if (!$dates) {
        return;
    }
    sort($dates);
    db()->prepare('UPDATE itineraries SET start_date=?, end_date=? WHERE id=?')
        ->execute([$dates[0], $dates[count($dates) - 1], $itinId]);
}

/**
 * Remove stops that belong to logical days after $keepDays.
 * Call before shrinking the tour so those days are still grouped.
 */
function itinerary_drop_stops_after_day(int $itinId, int $keepDays): void
{
    $keepDays = max(1, $keepDays);
    $row = itinerary_row_by_id($itinId);
    if (!$row) {
        return;
    }
    $stops = itinerary_items_with_places($itinId);
    $groups = itinerary_group_stops_by_day(
        $stops,
        $row['start_date'] ?? null,
        $row['end_date'] ?? null,
        $itinId,
        $row
    );
    $ids = [];
    foreach ($groups as $group) {
        if ((int) ($group['day'] ?? 1) <= $keepDays) {
            continue;
        }
        foreach ($group['stops'] as $stop) {
            $id = (int) ($stop['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("DELETE FROM itinerary_items WHERE itinerary_id=? AND id IN ($placeholders)")
        ->execute(array_merge([$itinId], $ids));
}

/**
 * Grow or shrink logical days. New days get last+1; extra meetup rows are removed.
 */
function itinerary_apply_logical_day_count(int $itinId, int $newCount): void
{
    ensure_itinerary_day_meetups_schema();
    $newCount = max(1, min(itinerary_max_days(), $newCount));
    $row = itinerary_row_by_id($itinId);
    if (!$row) {
        return;
    }
    $old = itinerary_logical_day_count($row);
    db()->prepare('UPDATE itineraries SET template_day_count=? WHERE id=?')
        ->execute([$newCount, $itinId]);
    $row['template_day_count'] = $newCount;

    if ($newCount > $old) {
        $map = itinerary_day_dates_map($itinId, $row);
        $last = null;
        for ($d = 1; $d <= $old; $d++) {
            $ymd = normalize_tour_date($map[$d] ?? null);
            if ($ymd !== null) {
                $last = $ymd;
            }
        }
        if ($last === null) {
            $last = normalize_tour_date($row['start_date'] ?? null);
        }
        for ($d = $old + 1; $d <= $newCount; $d++) {
            if ($last === null) {
                break;
            }
            $next = date('Y-m-d', strtotime($last . ' 12:00:00 +1 day'));
            db()->prepare(
                'INSERT INTO itinerary_day_meetups (itinerary_id, day_number, destination_id, business_id, meetup_time, visit_date, is_port)
                 VALUES (?,?,NULL,NULL,NULL,?,0)
                 ON DUPLICATE KEY UPDATE visit_date=VALUES(visit_date)'
            )->execute([$itinId, $d, $next]);
            $last = $next;
        }
    } elseif ($newCount < $old) {
        db()->prepare('DELETE FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number>?')
            ->execute([$itinId, $newCount]);
    }

    itinerary_sync_span_from_day_dates($itinId);
}

/**
 * Assign a calendar date to one logical day, then sort packages by date
 * (meetup + stops stay with the package). Duplicate date swaps those two days.
 * If the swapped dates skip a day, the displaced date fills that hole
 * (15→13 makes the original 13 become 14). Consecutive days only swap.
 *
 * @return array{error:?string,reordered:bool}
 */
function itinerary_set_day_date(int $itinId, int $dayNum, string $ymd): array
{
    ensure_itinerary_day_meetups_schema();
    $ymd = normalize_tour_date($ymd);
    if ($ymd === null) {
        return ['error' => 'Choose a valid date.', 'reordered' => false];
    }
    if ($ymd < date('Y-m-d')) {
        return ['error' => 'Choose a tour date that is today or later.', 'reordered' => false];
    }
    $itin = itinerary_row_by_id($itinId);
    if (!$itin) {
        return ['error' => 'Itinerary not found.', 'reordered' => false];
    }
    $n = itinerary_logical_day_count($itin);
    $dayNum = max(1, min($n, $dayNum));
    $dates = itinerary_day_dates_map($itinId, $itin);
    $start = normalize_tour_date($itin['start_date'] ?? null) ?: ($dates[1] ?? $ymd);

    $meetupRows = [];
    $st = db()->prepare('SELECT * FROM itinerary_day_meetups WHERE itinerary_id=?');
    $st->execute([$itinId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $d = (int) ($row['day_number'] ?? 0);
        if ($d >= 1) {
            $meetupRows[$d] = $row;
        }
    }

    $stops = [];
    try {
        $si = db()->prepare('SELECT id, visit_date FROM itinerary_items WHERE itinerary_id=?');
        $si->execute([$itinId]);
        $stops = $si->fetchAll() ?: [];
    } catch (Throwable $e) {
        $stops = [];
    }

    $packages = [];
    $assigned = [];
    for ($d = 1; $d <= $n; $d++) {
        $oldDate = $dates[$d] ?? '';
        $stopIds = [];
        foreach ($stops as $s) {
            $id = (int) ($s['id'] ?? 0);
            if ($id < 1 || isset($assigned[$id])) {
                continue;
            }
            $svd = normalize_tour_date($s['visit_date'] ?? null);
            if ($oldDate !== '' && $svd === $oldDate) {
                $stopIds[] = $id;
                $assigned[$id] = true;
            }
        }
        $packages[] = [
            'old' => $d,
            'date' => $oldDate,
            'meetup' => $meetupRows[$d] ?? null,
            'stop_ids' => $stopIds,
        ];
    }
    foreach ($stops as $s) {
        $id = (int) ($s['id'] ?? 0);
        if ($id < 1 || isset($assigned[$id])) {
            continue;
        }
        $dn = itinerary_day_number($start, $s['visit_date'] ?? $start);
        $dn = max(1, min($n, $dn));
        $packages[$dn - 1]['stop_ids'][] = $id;
        $assigned[$id] = true;
    }

    $idx = $dayNum - 1;
    $oldDateOfTarget = $packages[$idx]['date'];
    $packages[$idx]['date'] = $ymd;
    $swappedIdx = null;
    foreach ($packages as $i => $p) {
        if ($i === $idx) {
            continue;
        }
        if ($p['date'] === $ymd) {
            $packages[$i]['date'] = $oldDateOfTarget;
            $swappedIdx = $i;
            break;
        }
    }

    // Swapped dates with a hole (13 and 15): move the displaced day onto 14.
    // Consecutive swap (13 and 14) stays 13 and 14.
    if ($swappedIdx !== null) {
        $displaced = normalize_tour_date($packages[$swappedIdx]['date'] ?: null);
        if ($displaced !== null && $displaced !== $ymd) {
            $pickedTs = strtotime($ymd . ' 12:00:00');
            $dispTs = strtotime($displaced . ' 12:00:00');
            $dayDiff = (int) round(($dispTs - $pickedTs) / 86400);
            if (abs($dayDiff) > 1) {
                if ($dayDiff > 1) {
                    $fill = date('Y-m-d', strtotime($ymd . ' 12:00:00 +1 day'));
                } else {
                    $fill = date('Y-m-d', strtotime($ymd . ' 12:00:00 -1 day'));
                    $today = date('Y-m-d');
                    if ($fill < $today) {
                        $fill = $today;
                    }
                    if ($fill === $ymd) {
                        $fill = date('Y-m-d', strtotime($ymd . ' 12:00:00 +1 day'));
                    }
                }
                $packages[$swappedIdx]['date'] = $fill;
            }
        }
    }

    $known = null;
    $knownIdx = 0;
    foreach ($packages as $i => $p) {
        if ($p['date'] !== '') {
            $knownIdx = $i;
            $known = $p['date'];
            break;
        }
    }
    if ($known !== null) {
        foreach ($packages as $i => $p) {
            if ($p['date'] !== '') {
                continue;
            }
            $packages[$i]['date'] = date(
                'Y-m-d',
                strtotime($known . ' 12:00:00 ' . ($i - $knownIdx) . ' days')
            );
        }
    }

    usort($packages, static function ($a, $b) {
        if ($a['date'] === $b['date']) {
            return $a['old'] <=> $b['old'];
        }
        if ($a['date'] === '') {
            return 1;
        }
        if ($b['date'] === '') {
            return -1;
        }
        return strcmp($a['date'], $b['date']);
    });

    // If gap-fill landed on a third day, push later days so dates stay unique.
    $prevDate = null;
    foreach ($packages as $i => $p) {
        $cur = normalize_tour_date($p['date'] ?: null);
        if ($prevDate === null) {
            if ($cur !== null) {
                $prevDate = $cur;
            }
            continue;
        }
        $minNext = date('Y-m-d', strtotime($prevDate . ' 12:00:00 +1 day'));
        if ($cur === null || $cur < $minNext) {
            $packages[$i]['date'] = $minNext;
            $prevDate = $minNext;
        } else {
            $prevDate = $cur;
        }
    }

    $reordered = false;
    foreach ($packages as $i => $p) {
        if ((int) $p['old'] !== $i + 1) {
            $reordered = true;
            break;
        }
    }

    $allDates = [];
    foreach ($packages as $pkg) {
        $d = normalize_tour_date($pkg['date'] ?: null);
        if ($d !== null) {
            $allDates[] = $d;
        }
    }
    $allDates = array_values(array_unique($allDates));
    sort($allDates);
    if ($allDates && function_exists('tourist_date_range_booking_conflict_message')) {
        $ownerId = (int) ($itin['user_id'] ?? 0);
        if ($ownerId > 0) {
            $conflict = tourist_date_range_booking_conflict_message(
                $ownerId,
                $allDates[0],
                $allDates[count($allDates) - 1],
                $itinId,
                null,
                $allDates
            );
            if ($conflict !== null) {
                return ['error' => $conflict, 'reordered' => false];
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE itinerary_day_meetups SET day_number = day_number + 100 WHERE itinerary_id=?'
        )->execute([$itinId]);

        foreach ($packages as $i => $pkg) {
            $newNum = $i + 1;
            $dateVal = $pkg['date'] !== '' ? $pkg['date'] : null;
            if (!empty($pkg['meetup'])) {
                $pdo->prepare(
                    'UPDATE itinerary_day_meetups SET day_number=?, visit_date=? WHERE itinerary_id=? AND day_number=?'
                )->execute([$newNum, $dateVal, $itinId, (int) $pkg['old'] + 100]);
            } else {
                $pdo->prepare(
                    'INSERT INTO itinerary_day_meetups
                     (itinerary_id, day_number, destination_id, business_id, meetup_time, visit_date, is_port)
                     VALUES (?,?,NULL,NULL,NULL,?,0)'
                )->execute([$itinId, $newNum, $dateVal]);
            }
            if ($dateVal !== null) {
                foreach ($pkg['stop_ids'] as $sid) {
                    $pdo->prepare(
                        'UPDATE itinerary_items SET visit_date=? WHERE id=? AND itinerary_id=?'
                    )->execute([$dateVal, (int) $sid, $itinId]);
                }
            }
        }

        $pdo->prepare(
            'DELETE FROM itinerary_day_meetups WHERE itinerary_id=? AND day_number>=100'
        )->execute([$itinId]);

        if ($allDates) {
            $pdo->prepare('UPDATE itineraries SET start_date=?, end_date=? WHERE id=?')
                ->execute([$allDates[0], $allDates[count($allDates) - 1], $itinId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['error' => 'Could not update the day date.', 'reordered' => false];
    }

    return ['error' => null, 'reordered' => $reordered];
}

/** Visit date stored for Day N on an undated template. */
function itinerary_template_visit_date(int $dayNumber): string
{
    return itinerary_date_for_day(itinerary_template_day_base(), $dayNumber) ?: itinerary_template_day_base();
}

/** Calendar date for Day N (1-based) of a tour starting on $startDate. */
function itinerary_date_for_day(string $startDate, int $dayNumber): ?string
{
    $start = normalize_tour_date($startDate);
    if ($start === null) {
        return null;
    }
    $dayNumber = max(1, min(itinerary_max_days(), $dayNumber));
    return date('Y-m-d', strtotime($start . ' 12:00:00 +' . ($dayNumber - 1) . ' days'));
}

/** Day number (1-based) for a visit_date within a tour. */
function itinerary_day_number(string $startDate, ?string $visitDate): int
{
    $start = normalize_tour_date($startDate);
    $visit = normalize_tour_date($visitDate) ?: $start;
    if ($start === null || $visit === null) {
        return 1;
    }
    if ($visit < $start) {
        return 1;
    }
    return max(1, (int) round((strtotime($visit . ' 12:00:00') - strtotime($start . ' 12:00:00')) / 86400) + 1);
}

/** Strip boilerplate auto-generate descriptions for display / storage. */
function itinerary_display_description(?string $description): string
{
    $desc = trim((string) $description);
    if ($desc === '') {
        return '';
    }
    if (preg_match('/^Auto-generated\b.+\bfrom featured places\.?$/i', $desc)) {
        return '';
    }
    return $desc;
}

/** True when title is empty or the default "Unnamed" placeholder. */
function itinerary_is_placeholder_title(?string $title): bool
{
    $t = trim((string) $title);
    return $t === '' || strcasecmp($t, 'Unnamed') === 0;
}

/**
 * SQL fragment (alias i): itinerary has a real name or at least one stop.
 * Used so blank Unnamed drafts are not listed as saved tours.
 */
function itinerary_sql_is_saved_tour(string $alias = 'i'): string
{
    $a = preg_replace('/[^a-zA-Z_]/', '', $alias) ?: 'i';
    return "(
        (TRIM(COALESCE({$a}.title,'')) <> '' AND LOWER(TRIM({$a}.title)) <> 'unnamed')
        OR EXISTS (
            SELECT 1 FROM itinerary_items ii_saved
            WHERE ii_saved.itinerary_id = {$a}.id
        )
    )";
}

/** Suggested tour is visible to tourists (guide pressed Save). */
function itinerary_is_published_suggested(?array $itin): bool
{
    if (!$itin) {
        return false;
    }
    return (int) ($itin['is_suggested'] ?? 0) === 1
        && (string) ($itin['status'] ?? '') === 'active';
}

/** SQL: published suggested tours tourists may browse / clone. */
function itinerary_sql_published_suggested(string $alias = 'i'): string
{
    $a = preg_replace('/[^a-zA-Z_]/', '', $alias);
    $prefix = $a !== '' ? ($a . '.') : '';
    return "{$prefix}is_suggested = 1 AND {$prefix}status = 'active'";
}

/** Reuse an empty Unnamed draft for this tourist, if any. */
function itinerary_find_blank_draft_id(int $userId): int
{
    $stmt = db()->prepare(
        "SELECT i.id
         FROM itineraries i
         LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.id
         WHERE i.user_id = ? AND i.is_suggested = 0
           AND (TRIM(COALESCE(i.title,'')) = '' OR LOWER(TRIM(i.title)) = 'unnamed')
         GROUP BY i.id
         HAVING COUNT(ii.id) = 0
         ORDER BY i.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/** Delete extra empty Unnamed drafts (optionally keep one id). */
function itinerary_prune_blank_drafts(int $userId, ?int $keepId = null): void
{
    $stmt = db()->prepare(
        "SELECT i.id
         FROM itineraries i
         LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.id
         LEFT JOIN bookings b ON b.itinerary_id = i.id
         WHERE i.user_id = ? AND i.is_suggested = 0
           AND (TRIM(COALESCE(i.title,'')) = '' OR LOWER(TRIM(i.title)) = 'unnamed')
         GROUP BY i.id
         HAVING COUNT(ii.id) = 0 AND COUNT(b.id) = 0"
    );
    $stmt->execute([$userId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach ($ids as $id) {
        if ($keepId !== null && $id === (int) $keepId) {
            continue;
        }
        db()->prepare('DELETE FROM itineraries WHERE id=? AND user_id=?')->execute([$id, $userId]);
    }
}

/** Max listed drafts. Tourists may keep 10; tour guides stay at 7. */
function itinerary_max_drafts(string $kind = 'tourist'): int
{
    return $kind === 'guide' ? 7 : 10;
}

function itinerary_draft_limit_message(string $kind = 'tourist'): string
{
    return 'You can keep up to ' . itinerary_max_drafts($kind) . ' drafts. Delete one from View drafts to make another.';
}

function itinerary_request_wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
}

function itinerary_reject_draft_limit(string $redirectUrl, string $kind = 'tourist'): void
{
    $msg = itinerary_draft_limit_message($kind);
    if (itinerary_request_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('error', $msg);
    redirect($redirectUrl);
}

/**
 * Ids shown under View drafts.
 *
 * @param 'tourist'|'guide' $kind
 * @return list<int>
 */
function itinerary_listed_draft_ids(int $ownerId, string $kind): array
{
    if ($kind === 'guide') {
        $st = db()->prepare(
        'SELECT i.id FROM itineraries i
         WHERE i.created_by_guide_id=? AND i.is_suggested=1
           AND i.kept_as_draft=1
           AND i.status <> \'active\'
           AND ' . itinerary_sql_is_saved_tour('i') . '
             ORDER BY i.created_at DESC'
        );
        $st->execute([$ownerId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    $st = db()->prepare(
        'SELECT i.id FROM itineraries i
         WHERE i.user_id=? AND i.is_suggested=0
           AND i.kept_as_draft=1
           AND COALESCE(i.status, \'draft\') NOT IN (\'completed\', \'archived\')
           AND ' . itinerary_sql_is_saved_tour('i') . '
           AND NOT EXISTS (
               SELECT 1 FROM bookings b
               WHERE b.itinerary_id = i.id AND b.status IN (\'pending\',\'approved\')
           )
         ORDER BY i.created_at DESC'
    );
    $st->execute([$ownerId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** One View drafts row with a remove button. */
function itinerary_draft_menu_item(array $row, bool $active = false): void
{
    $id = (int) ($row['id'] ?? 0);
    $title = trim((string) ($row['title'] ?? ''));
    if ($title === '') {
        $title = 'Unnamed';
    }
    ?>
    <li class="itin-draft-dropdown__row">
      <a class="dropdown-item<?= $active ? ' active' : '' ?>" href="?id=<?= $id ?>&drafts=1"><?= e($title) ?></a>
      <form method="post" class="itin-draft-remove"><?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" aria-label="Remove <?= e($title) ?>" title="Remove draft">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </form>
    </li>
    <?php
}

/** @param 'tourist'|'guide' $kind */
function itinerary_listed_draft_count(int $ownerId, string $kind): int
{
    return count(itinerary_listed_draft_ids($ownerId, $kind));
}

/**
 * Null if this itinerary may stay or become a listed draft.
 *
 * @param 'tourist'|'guide' $kind
 */
function itinerary_guard_new_draft(int $ownerId, string $kind, int $itinId = 0): ?string
{
    if ($itinId > 0 && in_array($itinId, itinerary_listed_draft_ids($ownerId, $kind), true)) {
        return null;
    }
    if (itinerary_listed_draft_count($ownerId, $kind) >= itinerary_max_drafts($kind)) {
        return itinerary_draft_limit_message($kind);
    }
    return null;
}

/**
 * Resolve end_date from multi-day day count (Day 1…N).
 * @return array{0:?string,1:?string,2:int} [start, end, dayCount]
 */
function itinerary_resolve_dates_from_post(array $post): array
{
    $start = normalize_tour_date($post['start_date'] ?? '');
    $isMultiday = !empty($post['is_multiday']);
    $dayCount = 1;
    if ($isMultiday) {
        $dayCount = (int) ($post['day_count'] ?? 0);
        if ($dayCount < 2) {
            // Back-compat: end_date posted instead of day_count
            $endPosted = normalize_tour_date($post['end_date'] ?? '');
            if ($start && $endPosted) {
                $dayCount = itinerary_day_count($start, $endPosted);
            } else {
                $dayCount = 2;
            }
        }
        $dayCount = max(2, min(itinerary_max_days(), $dayCount));
    }
    if ($start === null) {
        return [null, null, $dayCount];
    }
    $end = itinerary_date_for_day($start, $dayCount);
    return [$start, $end, $dayCount];
}

/**
 * Dates for auto-generate: uses the posted tour date when present, otherwise
 * tomorrow (+ day_count when multi-day) so generation does not require picking a date.
 * @return array{0:?string,1:?string,2:int,3:bool} start, end, dayCount, usedDefaultDate
 */
function itinerary_dates_for_autogen(array $post): array
{
    [$start, $end, $dayCount] = itinerary_resolve_dates_from_post($post);
    $usedDefault = false;
    if ($start === null) {
        $usedDefault = true;
        $start = date('Y-m-d', strtotime('+1 day'));
        $dayCount = max(1, (int) $dayCount);
        $end = itinerary_date_for_day($start, $dayCount) ?: $start;
    }
    if ($end === null) {
        $end = $start;
    }
    return [$start, $end, $dayCount, $usedDefault];
}

/**
 * Group stops under Day 1…N for display.
 * When $itineraryId is set, uses stored per-day dates (gaps allowed) instead of start+(n-1).
 *
 * @return array<int, array{day:int,date:string,label:string,stops:array}>
 */
function itinerary_group_stops_by_day(
    array $stops,
    ?string $startDate,
    ?string $endDate = null,
    ?int $itineraryId = null,
    ?array $itinRow = null
): array {
    $start = normalize_tour_date($startDate);
    $dayDates = [];
    $total = 1;
    if ($itineraryId && $itineraryId > 0) {
        if (!$itinRow) {
            $itinRow = itinerary_row_by_id($itineraryId);
        }
        $total = itinerary_logical_day_count($itinRow);
        $dayDates = itinerary_day_dates_map($itineraryId, $itinRow);
        if ($start === null) {
            $start = normalize_tour_date($itinRow['start_date'] ?? null);
        }
    } else {
        $start = $start ?: date('Y-m-d');
        $total = itinerary_day_count($start, $endDate);
        for ($d = 1; $d <= $total; $d++) {
            $dayDates[$d] = itinerary_date_for_day($start, $d) ?: '';
        }
    }
    if ($start === null) {
        $start = date('Y-m-d');
    }

    $groups = [];
    $dateToDay = [];
    for ($d = 1; $d <= $total; $d++) {
        $date = $dayDates[$d] ?? '';
        if ($date === '' && $start !== '') {
            $date = itinerary_date_for_day($start, $d) ?: '';
        }
        $groups[$d] = [
            'day' => $d,
            'date' => $date,
            'label' => 'Day ' . $d,
            'stops' => [],
        ];
        if ($date !== '' && !isset($dateToDay[$date])) {
            $dateToDay[$date] = $d;
        }
    }
    foreach ($stops as $stop) {
        $vd = normalize_tour_date($stop['visit_date'] ?? null);
        if ($vd !== null && isset($dateToDay[$vd])) {
            $day = $dateToDay[$vd];
        } else {
            $day = itinerary_day_number($start, $stop['visit_date'] ?? $start);
            if ($day > $total) {
                $day = $total;
            }
        }
        if (!isset($groups[$day])) {
            $date = $dayDates[$day] ?? (itinerary_date_for_day($start, $day) ?: '');
            $groups[$day] = [
                'day' => $day,
                'date' => $date,
                'label' => 'Day ' . $day,
                'stops' => [],
            ];
        }
        $groups[$day]['stops'][] = $stop;
    }
    ksort($groups);
    return $groups;
}

/**
 * Clone a suggested (or any) itinerary into a tourist-owned draft.
 * @return int New itinerary id
 */
function clone_itinerary_for_tourist(int $sourceId, int $touristUserId): int
{
    ensure_itinerary_items_schema();
    $src = db()->prepare('SELECT * FROM itineraries WHERE id=?');
    $src->execute([$sourceId]);
    $itin = $src->fetch();
    if (!$itin) {
        throw new RuntimeException('Itinerary not found.');
    }
    $title = trim((string) $itin['title']);
    if ($title === '') {
        $title = 'My Trip';
    }
    if ((int) ($itin['is_suggested'] ?? 0) === 1 && stripos($title, 'copy') === false) {
        $title .= ' (copy)';
    }

    $isSuggestedSrc = (int) ($itin['is_suggested'] ?? 0) === 1;
    $newStart = normalize_tour_date($itin['start_date'] ?? null);
    $newEnd = normalize_tour_date($itin['end_date'] ?? null);
    $templateBase = itinerary_template_day_base();
    $templateDays = itinerary_template_day_count($itin);

    $items = db()->prepare('SELECT * FROM itinerary_items WHERE itinerary_id=? ORDER BY sort_order ASC, id ASC');
    $items->execute([$sourceId]);
    $itemRows = $items->fetchAll() ?: [];
    foreach ($itemRows as $it) {
        $vd = normalize_tour_date($it['visit_date'] ?? null);
        if ($vd === null) {
            continue;
        }
        $anchor = ($isSuggestedSrc || strncmp($vd, '2000-', 5) === 0)
            ? $templateBase
            : ($newStart ?: $vd);
        $day = itinerary_day_number($anchor, $vd);
        if ($day > $templateDays) {
            $templateDays = $day;
        }
    }
    ensure_itinerary_day_meetups_schema();
    try {
        $mx = db()->prepare('SELECT COALESCE(MAX(day_number),0) FROM itinerary_day_meetups WHERE itinerary_id=?');
        $mx->execute([$sourceId]);
        $maxMeetupDay = (int) $mx->fetchColumn();
        if ($maxMeetupDay > $templateDays) {
            $templateDays = $maxMeetupDay;
        }
    } catch (Throwable $e) {
        // ignore
    }
    $templateDays = max(1, min(itinerary_max_days(), $templateDays));

    if ($isSuggestedSrc) {
        // Suggested templates are undated — give the tourist a real starting range they can edit.
        $newStart = date('Y-m-d', strtotime('+1 day'));
        $newEnd = itinerary_date_for_day($newStart, $templateDays) ?: $newStart;
    } elseif ($newStart === null) {
        $newEnd = null;
    } else {
        $spanEnd = itinerary_date_for_day($newStart, $templateDays);
        $newEnd = $newEnd ?: $newStart;
        if ($spanEnd !== null && $newEnd < $spanEnd) {
            $newEnd = $spanEnd;
        }
    }

    db()->prepare(
        'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, template_day_count, is_suggested, created_by_guide_id, status)
         VALUES (?,?,?,?,?,?,?,0,NULL,?)'
    )->execute([
        $touristUserId,
        $title,
        $itin['description'] ?? null,
        $newStart,
        $newEnd,
        $itin['meetup_time'] ?? '08:00:00',
        $templateDays,
        'draft',
    ]);
    $newId = (int) db()->lastInsertId();

    $ins = db()->prepare(
        'INSERT INTO itinerary_items
         (itinerary_id, destination_id, business_id, sort_order, visit_date, start_time, end_time, travel_minutes, visit_minutes, transportation, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($itemRows as $it) {
        $visitDate = normalize_tour_date($it['visit_date'] ?? null);
        if ($isSuggestedSrc && $newStart !== null) {
            $day = $visitDate !== null
                ? itinerary_day_number($templateBase, $visitDate)
                : 1;
            if ($day < 1) {
                $day = 1;
            }
            if ($day > $templateDays) {
                $day = $templateDays;
            }
            $visitDate = itinerary_date_for_day($newStart, $day) ?: $newStart;
        }
        $ins->execute([
            $newId,
            $it['destination_id'] ?? null,
            $it['business_id'] ?? null,
            (int) ($it['sort_order'] ?? 0),
            $visitDate,
            $it['start_time'] ?? null,
            $it['end_time'] ?? null,
            $it['travel_minutes'] ?? null,
            (int) ($it['visit_minutes'] ?? 60),
            $it['transportation'] ?? null,
            $it['notes'] ?? null,
        ]);
    }

    $meetRows = [];
    try {
        $ms = db()->prepare('SELECT * FROM itinerary_day_meetups WHERE itinerary_id=? ORDER BY day_number ASC');
        $ms->execute([$sourceId]);
        $meetRows = $ms->fetchAll() ?: [];
    } catch (Throwable $e) {
        $meetRows = [];
    }
    if ($meetRows) {
        $insMeet = db()->prepare(
            'INSERT INTO itinerary_day_meetups
             (itinerary_id, day_number, destination_id, business_id, meetup_time, visit_date, is_port)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                destination_id=VALUES(destination_id),
                business_id=VALUES(business_id),
                meetup_time=VALUES(meetup_time),
                visit_date=VALUES(visit_date),
                is_port=VALUES(is_port)'
        );
        foreach ($meetRows as $m) {
            $day = max(1, min(itinerary_max_days(), (int) ($m['day_number'] ?? 1)));
            $visitDate = $newStart !== null
                ? (itinerary_date_for_day($newStart, $day) ?: $newStart)
                : normalize_tour_date($m['visit_date'] ?? null);
            $insMeet->execute([
                $newId,
                $day,
                $m['destination_id'] ?? null,
                $m['business_id'] ?? null,
                $m['meetup_time'] ?? null,
                $visitDate,
                !empty($m['is_port']) ? 1 : 0,
            ]);
        }
    }
    itinerary_apply_logical_day_count($newId, $templateDays);
    db()->prepare('UPDATE itineraries SET kept_as_draft=1 WHERE id=?')->execute([$newId]);

    return $newId;
}

/**
 * Approximate great-circle distance in km (for nearest-next ranking).
 */
function itin_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * asin(min(1, sqrt($a)));
}

/**
 * Parse posted per-day meetup clocks. Empty string means intentionally unset.
 *
 * @return array<int, ?string> day_number => 'HH:MM'|null
 */
function itinerary_posted_day_meetup_times(array $post): array
{
    if (!isset($post['day_meetup']) || !is_array($post['day_meetup'])) {
        return [];
    }
    $out = [];
    foreach ($post['day_meetup'] as $d => $raw) {
        $d = (int) $d;
        if ($d < 1) {
            continue;
        }
        $raw = trim((string) $raw);
        $out[$d] = $raw === '' ? null : (normalize_time_input($raw) ?: null);
    }
    return $out;
}

/**
 * Auto-build a tourist itinerary from featured destinations (+ businesses).
 * Pass $existingItineraryId to rebuild an owned tour (e.g. switch to multi-day).
 * Empty meetup stays empty (not 8:00 AM); stop packing still uses 8:00 when unset.
 * Optional $opts['day_meetup'] is day_number => 'HH:MM'|null for per-day clocks.
 *
 * @return array{id:int,stops:int,warning:?string}
 */
function auto_generate_itinerary_for_tourist(
    int $touristUserId,
    string $title,
    string $description,
    string $startDate,
    string $endDate,
    ?string $meetupTime,
    ?int $existingItineraryId = null,
    array $opts = []
): array {
    ensure_itinerary_items_schema();
    ensure_place_schedule_schema();

    $guideProfileId = (int) ($opts['guide_profile_id'] ?? 0);
    $asSuggested = $guideProfileId > 0;
    $keepUndated = !empty($opts['keep_undated']) || $asSuggested;
    $startDate = normalize_tour_date($startDate);
    $endDate = normalize_tour_date($endDate) ?: $startDate;
    if ($startDate === null) {
        throw new InvalidArgumentException('Invalid start date.');
    }
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }
    $dayMeetupTimes = [];
    if (!empty($opts['day_meetup']) && is_array($opts['day_meetup'])) {
        foreach ($opts['day_meetup'] as $d => $raw) {
            $d = (int) $d;
            if ($d < 1) {
                continue;
            }
            if ($raw === null || trim((string) $raw) === '') {
                $dayMeetupTimes[$d] = null;
                continue;
            }
            $dayMeetupTimes[$d] = normalize_time_input((string) $raw);
        }
    }
    $meetupNorm = array_key_exists(1, $dayMeetupTimes)
        ? $dayMeetupTimes[1]
        : normalize_time_input($meetupTime);
    $packMinsDefault = 8 * 60;
    $title = trim($title) !== '' ? trim($title) : 'Ormoc auto tour';
    $dayCount = itinerary_day_count($startDate, $endDate);
    if ($asSuggested) {
        // Guide templates may be multi-day, but stay undated — cap at 7 sample days.
        $dayCount = max(1, min(7, $dayCount));
        $endDate = itinerary_date_for_day($startDate, $dayCount) ?: $startDate;
    }
    $stopsPerDay = 4;
    $dayEndCap = 17 * 60; // prefer finishing by 5:00 PM

    $port = ormoc_port_coords();

    $destRows = db()->query(
        "SELECT id, name, latitude, longitude, opening_hours, default_visit_minutes, is_featured, avg_rating
         FROM destinations
         WHERE status='published'
           AND latitude IS NOT NULL AND longitude IS NOT NULL
         ORDER BY is_featured DESC, avg_rating DESC, name ASC
         LIMIT 24"
    )->fetchAll() ?: [];

    $bizRows = db()->query(
        "SELECT b.id, b.name, b.default_visit_minutes, " . business_itinerary_ready_columns() . "
         FROM businesses b
         WHERE b.approval_status='approved' AND b.status='active'
           AND b.latitude IS NOT NULL AND b.longitude IS NOT NULL
         ORDER BY b.name ASC
         LIMIT 40"
    )->fetchAll() ?: [];

    /** @var list<array{key:string,destination_id:?int,business_id:?int,name:string,lat:float,lng:float,opening_hours:?string,default_visit_minutes:int}> */
    $pool = [];
    $destRows = array_values(array_filter($destRows, 'destination_itinerary_ready'));
    $bizRows = array_values(array_filter($bizRows, 'business_itinerary_ready'));
    foreach ($destRows as $d) {
        $pool[] = [
            'key' => 'd:' . (int) $d['id'],
            'destination_id' => (int) $d['id'],
            'business_id' => null,
            'name' => (string) $d['name'],
            'lat' => (float) $d['latitude'],
            'lng' => (float) $d['longitude'],
            'opening_hours' => $d['opening_hours'] ?? null,
            'default_visit_minutes' => max(15, (int) ($d['default_visit_minutes'] ?? 60)),
        ];
    }
    // Mix a few businesses after featured destinations
    $bizTake = min(8, count($bizRows));
    for ($i = 0; $i < $bizTake; $i++) {
        $b = $bizRows[$i];
        $pool[] = [
            'key' => 'b:' . (int) $b['id'],
            'destination_id' => null,
            'business_id' => (int) $b['id'],
            'name' => (string) $b['name'],
            'lat' => (float) $b['latitude'],
            'lng' => (float) $b['longitude'],
            'opening_hours' => $b['opening_hours'] ?? null,
            'default_visit_minutes' => max(15, (int) ($b['default_visit_minutes'] ?? 60)),
        ];
    }

    if (!$pool) {
        throw new RuntimeException('No mapped destinations or businesses available to generate a tour.');
    }

    $onlyDay = max(0, (int) ($opts['only_day'] ?? 0));
    $preserveOtherDays = false;
    $forcedVisitDate = null;
    $used = [];
    $desc = trim($description);

    if ($existingItineraryId) {
        if ($asSuggested) {
            $own = db()->prepare(
                'SELECT id, title, description FROM itineraries WHERE id=? AND created_by_guide_id=? AND is_suggested=1 LIMIT 1'
            );
            $own->execute([$existingItineraryId, $guideProfileId]);
        } else {
            $own = db()->prepare(
                'SELECT id, title, description FROM itineraries WHERE id=? AND user_id=? AND is_suggested=0 LIMIT 1'
            );
            $own->execute([$existingItineraryId, $touristUserId]);
        }
        $existing = $own->fetch();
        if (!$existing) {
            throw new InvalidArgumentException('Itinerary not found.');
        }
        $itinId = (int) $existing['id'];
        if ($onlyDay >= 1) {
            $onlyDay = max(1, min($dayCount, $onlyDay));
            $preserveOtherDays = true;
            $savedRow = itinerary_row_by_id($itinId);
            $savedItems = itinerary_items_with_places($itinId);
            $groups = itinerary_group_stops_by_day(
                $savedItems,
                $savedRow['start_date'] ?? null,
                $savedRow['end_date'] ?? null,
                $itinId,
                $savedRow
            );
            $dropIds = [];
            foreach ($groups as $g) {
                $gDay = (int) ($g['day'] ?? 0);
                if ($gDay === $onlyDay) {
                    $forcedVisitDate = normalize_tour_date($g['date'] ?? null);
                }
                foreach ($g['stops'] ?? [] as $s) {
                    $sid = (int) ($s['id'] ?? 0);
                    if ($gDay === $onlyDay) {
                        if ($sid > 0) {
                            $dropIds[] = $sid;
                        }
                        continue;
                    }
                    $destId = (int) ($s['destination_id'] ?? 0);
                    $bizId = (int) ($s['business_id'] ?? 0);
                    if ($destId > 0) {
                        $used['d:' . $destId] = true;
                    }
                    if ($bizId > 0) {
                        $used['b:' . $bizId] = true;
                    }
                }
            }
            if ($dropIds) {
                $placeholders = implode(',', array_fill(0, count($dropIds), '?'));
                db()->prepare("DELETE FROM itinerary_items WHERE itinerary_id=? AND id IN ($placeholders)")
                    ->execute(array_merge([$itinId], $dropIds));
            }
        }
        if (!$preserveOtherDays) {
        $incomingTitle = trim($title);
        $existingTitle = trim((string) ($existing['title'] ?? ''));
        if ($incomingTitle !== '' && strcasecmp($incomingTitle, 'Unnamed') !== 0 && $incomingTitle !== 'Ormoc auto tour') {
            $keepTitle = $incomingTitle;
        } elseif ($existingTitle !== '' && strcasecmp($existingTitle, 'Unnamed') !== 0) {
            $keepTitle = $existingTitle;
        } else {
            $keepTitle = $dayCount > 1 ? ('Ormoc ' . $dayCount . '-day tour') : 'Ormoc day tour';
        }
        $keepDesc = itinerary_display_description(
            trim($description) !== ''
                ? $desc
                : (string) ($existing['description'] ?? '')
        );
        if ($asSuggested) {
            db()->prepare(
                'UPDATE itineraries SET title=?, description=?, start_date=NULL, end_date=NULL, meetup_time=?, template_day_count=? WHERE id=? AND created_by_guide_id=?'
            )->execute([$keepTitle, $keepDesc, $meetupNorm, $dayCount, $itinId, $guideProfileId]);
        } elseif ($keepUndated) {
            db()->prepare(
                'UPDATE itineraries SET title=?, description=?, start_date=NULL, end_date=NULL, meetup_time=?, template_day_count=? WHERE id=? AND user_id=?'
            )->execute([$keepTitle, $keepDesc, $meetupNorm, $dayCount, $itinId, $touristUserId]);
        } else {
            db()->prepare(
                'UPDATE itineraries SET title=?, description=?, start_date=?, end_date=?, meetup_time=? WHERE id=? AND user_id=?'
            )->execute([$keepTitle, $keepDesc, $startDate, $endDate, $meetupNorm, $itinId, $touristUserId]);
        }
        db()->prepare('DELETE FROM itinerary_items WHERE itinerary_id=?')->execute([$itinId]);
        }
    } else {
        $insertTitle = trim($title);
        if ($insertTitle === '' || strcasecmp($insertTitle, 'Unnamed') === 0) {
            $insertTitle = $dayCount > 1 ? ('Ormoc ' . $dayCount . '-day tour') : 'Ormoc day tour';
        }
        if ($asSuggested) {
            db()->prepare(
                'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, template_day_count, is_suggested, created_by_guide_id, status)
                 VALUES (?,?,?,?,?,?,?,1,?,?)'
            )->execute([
                $touristUserId,
                $insertTitle,
                $desc,
                null,
                null,
                $meetupNorm,
                $dayCount,
                $guideProfileId,
                'draft',
            ]);
        } elseif ($keepUndated) {
            db()->prepare(
                'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, template_day_count, is_suggested, status)
                 VALUES (?,?,?,?,?,?,?,0,?)'
            )->execute([
                $touristUserId,
                $insertTitle,
                $desc,
                null,
                null,
                $meetupNorm,
                $dayCount,
                'draft',
            ]);
        } else {
            db()->prepare(
                'INSERT INTO itineraries (user_id, title, description, start_date, end_date, meetup_time, is_suggested, status)
                 VALUES (?,?,?,?,?,?,0,?)'
            )->execute([
                $touristUserId,
                $insertTitle,
                $desc,
                $startDate,
                $endDate,
                $meetupNorm,
                'draft',
            ]);
        }
        $itinId = (int) db()->lastInsertId();
    }

    $itinRow = [
        'id' => $itinId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'meetup_time' => $meetupNorm,
    ];

    $sortOrder = $preserveOtherDays ? 100000 : 0;
    $placed = 0;
    /** @var array<int,int> day => meetup minutes, for days that got no stops */
    $emptyDays = [];
    $ins = db()->prepare(
        'INSERT INTO itinerary_items
         (itinerary_id, destination_id, business_id, sort_order, visit_date, start_time, end_time, travel_minutes, visit_minutes)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );

    $fromDay = $preserveOtherDays ? $onlyDay : 1;
    $toDay = $preserveOtherDays ? $onlyDay : $dayCount;
    for ($day = $fromDay; $day <= $toDay; $day++) {
        if ($preserveOtherDays) {
            $visitDate = $forcedVisitDate
                ?: (($asSuggested || $keepUndated)
                    ? itinerary_template_visit_date($day)
                    : (itinerary_date_for_day($startDate, $day) ?: $startDate));
        } else {
            $visitDate = itinerary_date_for_day($startDate, $day) ?: $startDate;
        }
        $isDayOne = $day === 1;
        $dayMeetupNorm = array_key_exists($day, $dayMeetupTimes)
            ? $dayMeetupTimes[$day]
            : $meetupNorm;
        $dayMeetupMins = itin_time_to_minutes($dayMeetupNorm) ?? $packMinsDefault;

        $curLat = (float) $port['lat'];
        $curLng = (float) $port['lng'];
        $leaveMins = $dayMeetupMins;
        $skipTravel = !$isDayOne; // first stop of Day 2+ has no Port lock
        $dayStops = 0;

        while ($dayStops < $stopsPerDay) {
            $remaining = [];
            foreach ($pool as $c) {
                if (isset($used[$c['key']])) {
                    continue;
                }
                $remaining[] = $c;
            }
            if (!$remaining) {
                break;
            }

            // Rank by haversine, try nearest first
            usort($remaining, static function ($a, $b) use ($curLat, $curLng) {
                $da = itin_haversine_km($curLat, $curLng, $a['lat'], $a['lng']);
                $db = itin_haversine_km($curLat, $curLng, $b['lat'], $b['lng']);
                return $da <=> $db;
            });

            $picked = null;
            $pickedTravel = 0;
            $pickedArrive = $leaveMins;
            $pickedLeave = $leaveMins;

            $tryLimit = min(8, count($remaining));
            for ($t = 0; $t < $tryLimit; $t++) {
                $cand = $remaining[$t];
                $travel = $skipTravel
                    ? 0
                    : itin_osrm_travel_minutes($curLat, $curLng, $cand['lat'], $cand['lng']);
                $arrive = $skipTravel
                    ? $dayMeetupMins
                    : itin_suggest_arrival($leaveMins, $travel);
                $visitMins = min(ITIN_MAX_VISIT_MINUTES, max(15, (int) $cand['default_visit_minutes']));
                $endMins = itin_compute_end($arrive, $visitMins);

                if ($arrive > $dayEndCap) {
                    continue;
                }

                $place = [
                    'name' => $cand['name'],
                    'lat' => $cand['lat'],
                    'lng' => $cand['lng'],
                    'opening_hours' => $cand['opening_hours'],
                    'default_visit_minutes' => $visitMins,
                ];
                $itinForHours = $itinRow;
                $itinForHours['start_date'] = $visitDate;
                $itinForHours['meetup_time'] = $dayMeetupNorm ?? $meetupNorm;
                $prev = [
                    'lat' => $curLat,
                    'lng' => $curLng,
                    'leave_mins' => $skipTravel ? 0 : $leaveMins,
                    'label' => $skipTravel ? 'start of day' : ($dayStops === 0 && $isDayOne ? $port['title'] : 'previous stop'),
                    'skip_travel' => $skipTravel,
                ];
                $block = itinerary_hard_block_message($itinForHours, $prev, $travel, $arrive, $endMins, $place);
                if ($block !== null) {
                    continue;
                }

                $picked = $cand;
                $pickedTravel = $travel;
                $pickedArrive = $arrive;
                $pickedLeave = $endMins;
                break;
            }

            if ($picked === null) {
                break; // no more places fit this day
            }

            $ins->execute([
                $itinId,
                $picked['destination_id'],
                $picked['business_id'],
                $sortOrder,
                $visitDate,
                itin_minutes_to_time($pickedArrive),
                itin_minutes_to_time($pickedLeave),
                $pickedTravel,
                min(ITIN_MAX_VISIT_MINUTES, max(15, (int) $picked['default_visit_minutes'])),
            ]);
            $used[$picked['key']] = true;
            $sortOrder++;
            $placed++;
            $dayStops++;

            $curLat = $picked['lat'];
            $curLng = $picked['lng'];
            $leaveMins = $pickedLeave;
            $skipTravel = false;
        }
        if ($dayStops === 0) {
            $emptyDays[$day] = $dayMeetupMins;
        }
    }

    $warning = null;
    $lateDays = [];
    foreach ($emptyDays as $d => $mins) {
        if ($mins >= $dayEndCap) {
            $lateDays[] = 'Day ' . $d . ' (meetup ' . format_minutes_12h($mins) . ')';
        }
    }
    if ($lateDays) {
        $warning = 'No places are open after the meetup time on ' . implode(', ', $lateDays)
            . '. Set an earlier meetup time (check AM/PM), then auto-generate again.';
    } elseif ($placed === 0) {
        $warning = 'Tour created, but no stops fit the schedule (hours/travel). Add places manually.';
    } elseif ($emptyDays) {
        $warning = 'Tour generated with ' . $placed . ' stop(s). There weren’t enough open places left for Day '
            . implode(', Day ', array_keys($emptyDays)) . ' — add places manually.';
    }

    if ($dayMeetupTimes) {
        foreach ($dayMeetupTimes as $d => $norm) {
            itinerary_set_day_meetup_time($itinId, (int) $d, $norm !== null ? $norm : '', true);
        }
    } elseif ($meetupNorm === null) {
        itinerary_set_day_meetup_time($itinId, 1, '', true);
    }

    if ($keepUndated && !$preserveOtherDays) {
        // Undated draft / guide template — remap stop days onto the template day base.
        db()->prepare(
            'UPDATE itineraries SET start_date=NULL, end_date=NULL, template_day_count=? WHERE id=?'
        )->execute([$dayCount, $itinId]);
        $items = db()->prepare(
            'SELECT id, visit_date FROM itinerary_items WHERE itinerary_id=? ORDER BY sort_order ASC, id ASC'
        );
        $items->execute([$itinId]);
        $upd = db()->prepare('UPDATE itinerary_items SET visit_date=? WHERE id=? AND itinerary_id=?');
        foreach ($items->fetchAll() ?: [] as $row) {
            $day = itinerary_day_number($startDate, $row['visit_date'] ?? $startDate);
            if ($day > $dayCount) {
                $day = $dayCount;
            }
            $upd->execute([itinerary_template_visit_date($day), (int) $row['id'], $itinId]);
        }
    }

    if ($preserveOtherDays) {
        $seq = db()->prepare(
            "SELECT id FROM itinerary_items WHERE itinerary_id=? ORDER BY COALESCE(visit_date, '9999-12-31'), start_time, id"
        );
        $seq->execute([$itinId]);
        $ord = 0;
        $setOrd = db()->prepare('UPDATE itinerary_items SET sort_order=? WHERE id=? AND itinerary_id=?');
        foreach ($seq->fetchAll() ?: [] as $row) {
            $setOrd->execute([$ord, (int) $row['id'], $itinId]);
            $ord++;
        }
    }

    return ['id' => $itinId, 'stops' => $placed, 'warning' => $warning];
}

/**
 * Send a plain-text email when mail is configured. Returns false if skipped/failed.
 * Prefers SMTP (Gmail) when MAIL_SMTP_* is set; otherwise falls back to PHP mail().
 */
function send_app_mail(string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $from = defined('MAIL_FROM') ? (string) MAIL_FROM : '';
    if ($from === '') {
        return false;
    }
    $fromName = defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : APP_NAME;
    $fromName = preg_replace('/[\r\n]+/', '', $fromName) ?: 'CulTour';

    $smtpHost = defined('MAIL_SMTP_HOST') ? trim((string) MAIL_SMTP_HOST) : '';
    $smtpUser = defined('MAIL_SMTP_USER') ? trim((string) MAIL_SMTP_USER) : '';
    $smtpPass = defined('MAIL_SMTP_PASS') ? (string) MAIL_SMTP_PASS : '';
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        return send_app_mail_smtp($to, $subject, $body, $from, $fromName);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . sprintf('%s <%s>', $fromName, $from),
        'Reply-To: ' . $from,
        'X-Mailer: CulTour',
    ];
    try {
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    } catch (Throwable $e) {
        return false;
    }
}

function send_user_verification_email(int $userId, string $email, string $firstName, ?string $token = null): bool
{
    if ($token === null || $token === '') {
        $token = bin2hex(random_bytes(16));
        db()->prepare('UPDATE users SET verification_token=?, email_verified_at=NULL WHERE id=?')->execute([$token, $userId]);
    }
    $url = APP_URL . '/verify-email.php?uid=' . $userId . '&token=' . rawurlencode($token);
    $name = trim($firstName) !== '' ? trim($firstName) : 'there';
    $body = "Hi {$name},\n\nPlease confirm your CulTour email address:\n{$url}\n\nIf you did not create this account, you can ignore this message.\n";
    return send_app_mail($email, 'Verify your CulTour email', $body);
}

function send_welcome_email(string $email, string $firstName, int $roleId): bool
{
    $name = trim($firstName) !== '' ? trim($firstName) : 'there';
    $role = function_exists('role_label') ? role_label($roleId) : 'CulTour';
    $signIn = function_exists('auth_login_url')
        ? auth_login_url($roleId === ROLE_ADMIN)
        : APP_URL . ($roleId === ROLE_ADMIN ? '/admin/' : '/login.php');
    $body = "Hi {$name},\n\nWelcome to CulTour (Ormoc City Tourism). Your {$role} account is ready.\n\nSign in: {$signIn}\n";
    return send_app_mail($email, 'Welcome to CulTour', $body);
}

/**
 * Minimal SMTP client (AUTH LOGIN) for Gmail / similar providers.
 */
function send_app_mail_smtp(string $to, string $subject, string $body, string $from, string $fromName): bool
{
    $host = (string) MAIL_SMTP_HOST;
    $port = defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 587;
    $user = trim((string) MAIL_SMTP_USER);
    // Gmail App Passwords are often shown with spaces — strip them.
    $pass = preg_replace('/\s+/', '', (string) MAIL_SMTP_PASS) ?? '';
    $secure = defined('MAIL_SMTP_SECURE') ? strtolower((string) MAIL_SMTP_SECURE) : 'tls';

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = static function () use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = static function (string $resp, string $codes): bool {
        $ok = explode(',', $codes);
        foreach ($ok as $c) {
            if (str_starts_with($resp, trim($c))) {
                return true;
            }
        }
        return false;
    };

    try {
        $banner = $read();
        if (!$expect($banner, '220')) {
            fclose($fp);
            return false;
        }

        $write('EHLO localhost');
        $ehlo = $read();
        if (!$expect($ehlo, '250')) {
            $write('HELO localhost');
            if (!$expect($read(), '250')) {
                fclose($fp);
                return false;
            }
        }

        if ($secure === 'tls') {
            $write('STARTTLS');
            if (!$expect($read(), '220')) {
                fclose($fp);
                return false;
            }
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
            if (!$crypto) {
                $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$crypto) {
                fclose($fp);
                return false;
            }
            $write('EHLO localhost');
            if (!$expect($read(), '250')) {
                fclose($fp);
                return false;
            }
        }

        $write('AUTH LOGIN');
        if (!$expect($read(), '334')) {
            fclose($fp);
            return false;
        }
        $write(base64_encode($user));
        if (!$expect($read(), '334')) {
            fclose($fp);
            return false;
        }
        $write(base64_encode($pass));
        if (!$expect($read(), '235')) {
            fclose($fp);
            return false;
        }

        $write('MAIL FROM:<' . $from . '>');
        if (!$expect($read(), '250')) {
            fclose($fp);
            return false;
        }
        $write('RCPT TO:<' . $to . '>');
        if (!$expect($read(), '250,251')) {
            fclose($fp);
            return false;
        }
        $write('DATA');
        if (!$expect($read(), '354')) {
            fclose($fp);
            return false;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . sprintf('%s <%s>', $fromName, $from),
            'To: <' . $to . '>',
            'Reply-To: ' . $from,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: CulTour',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body);
        $write($payload);
        $write('.');
        if (!$expect($read(), '250')) {
            fclose($fp);
            return false;
        }
        $write('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        return false;
    }
}

/**
 * Email a user by id when MAIL_FROM is set.
 */
function email_user_by_id(int $userId, string $subject, string $body): bool
{
    $s = db()->prepare('SELECT email, first_name FROM users WHERE id=?');
    $s->execute([$userId]);
    $u = $s->fetch();
    if (!$u || empty($u['email'])) {
        return false;
    }
    $name = trim((string) ($u['first_name'] ?? ''));
    $full = ($name !== '' ? "Hi {$name},\n\n" : '') . $body . "\n\n— " . APP_NAME;
    return send_app_mail((string) $u['email'], $subject, $full);
}

function log_activity(?int $userId, string $action, ?string $module = null, ?string $details = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $module,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Silently ignore logging failures
    }
}

/** Human-readable activity-log details (roles instead of user ids). */
function format_activity_log_details(array $log): string
{
    $details = trim((string) ($log['details'] ?? ''));
    $action = (string) ($log['action'] ?? '');
    $details = preg_replace_callback(
        '/\brole\s+(\d+)\b/i',
        static function (array $match): string {
            return role_label((int) $match[1]);
        },
        $details
    ) ?? $details;
    if ($action === 'switch_account') {
        if ($details === '' || preg_match('/^Switched from user\s+\d+$/i', $details)) {
            $roleId = (int) ($log['role_id'] ?? 0);
            if ($roleId < 1 && !empty($log['user_id'])) {
                try {
                    $stmt = db()->prepare('SELECT role_id FROM users WHERE id=? LIMIT 1');
                    $stmt->execute([(int) $log['user_id']]);
                    $roleId = (int) ($stmt->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $roleId = 0;
                }
            }
            if ($roleId > 0) {
                return 'Switched to ' . role_label($roleId);
            }
            return 'Switched to linked account';
        }
    }
    return $details;
}

/**
 * Recent activity rows for one user account.
 * @return list<array<string,mixed>>
 */
function user_activity_logs(int $userId, int $limit = 80): array
{
    if ($userId < 1) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    try {
        $stmt = db()->prepare(
            'SELECT l.id, l.user_id, l.action, l.module, l.details, l.ip_address, l.created_at, u.role_id
             FROM activity_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.user_id=? ORDER BY l.created_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function notify_user(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $type, $title, $body, $link]);

    // Also email when SMTP/mail is configured (MAIL_FROM set in config).
    $mailTypes = [
        'booking_request', 'booking_approved', 'booking_rejected', 'booking_cancelled',
        'booking_replacement', 'approval', 'password_reset', 'welcome',
        'refund_requested', 'refund_sent', 'refund_details',
    ];
    if (in_array($type, $mailTypes, true) && defined('MAIL_FROM') && MAIL_FROM !== '') {
        $text = trim((string) ($body ?? $title));
        if ($link) {
            $text .= "\n\nOpen: " . $link;
        }
        email_user_by_id($userId, $title, $text !== '' ? $text : $title);
    }
}

function unread_notifications(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Mark notifications as read for a user, optionally filtered by type and/or link substring.
 */
function mark_notifications_read(int $userId, ?string $type = null, ?string $linkContains = null): void
{
    $sql = 'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0';
    $params = [$userId];
    if ($type !== null) {
        $sql .= ' AND type = ?';
        $params[] = $type;
    }
    if ($linkContains !== null && $linkContains !== '') {
        $sql .= ' AND link LIKE ?';
        $params[] = '%' . $linkContains . '%';
    }
    db()->prepare($sql)->execute($params);
}

/**
 * Resolve a relative notification link to an absolute APP_URL path when needed.
 */
function notification_href(?string $link): string
{
    if ($link === null || $link === '') {
        return APP_URL . '/notifications.php';
    }
    if (preg_match('#^https?://#i', $link)) {
        return $link;
    }
    return rtrim(APP_URL, '/') . '/' . ltrim($link, '/');
}

/**
 * Unread chat messages for a user (received, not yet seen).
 */
function unread_messages(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM messages m
         JOIN conversations c ON c.id = m.conversation_id
         WHERE m.sender_id != ? AND m.is_seen = 0
           AND (c.tourist_id = ? OR c.guide_id = ?)'
    );
    $stmt->execute([$userId, $userId, $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Unread count for one conversation (messages from the other person).
 */
function unread_in_conversation(int $conversationId, int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND sender_id != ? AND is_seen = 0'
    );
    $stmt->execute([$conversationId, $userId]);
    return (int) $stmt->fetchColumn();
}

function format_money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

const UPLOAD_MAX_BYTES = 8388608;
const CHAT_MESSAGE_MAX_LEN = 2000;

function upload_last_error(?string $set = null): ?string
{
    static $err = null;
    if (func_num_args() > 0) {
        $err = $set;
    }
    return $err;
}

function upload_file_is_readable(string $tmp): bool
{
    if ($tmp === '' || !is_file($tmp)) {
        return false;
    }
    if (PHP_SAPI === 'cli') {
        return true;
    }
    return is_uploaded_file($tmp);
}

function upload_file_content_ok(string $tmp, string $ext): bool
{
    $imageMap = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'gif' => IMAGETYPE_GIF,
        'webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18,
    ];
    if (isset($imageMap[$ext])) {
        $info = @getimagesize($tmp);
        if ($info === false) {
            return false;
        }
        return (int) ($info[2] ?? 0) === $imageMap[$ext];
    }
    $fh = @fopen($tmp, 'rb');
    if ($fh === false) {
        return false;
    }
    $magic = (string) fread($fh, 8);
    fclose($fh);
    if ($ext === 'pdf') {
        return str_starts_with($magic, '%PDF-');
    }
    if ($ext === 'docx') {
        return str_starts_with($magic, 'PK');
    }
    if ($ext === 'doc') {
        return strlen($magic) >= 4 && $magic[0] === "\xD0" && $magic[1] === "\xCF";
    }
    return true;
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '—';
    }
    return date($format, strtotime($date));
}

function upload_file(array $file, string $subdir, array $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx']): ?string
{
    upload_last_error(null);
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        upload_last_error('No file was uploaded.');
        return null;
    }
    $allowedLabel = strtoupper(implode(', ', array_unique(array_diff($allowed, ['jpeg']))));
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        upload_last_error('That file is larger than 8 MB. Choose a smaller file or compress it, then upload again.');
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        upload_last_error('The upload was interrupted. Check your connection and choose the file again.');
        return null;
    }

    $size = (int) ($file['size'] ?? 0);
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($size < 1 || $size > UPLOAD_MAX_BYTES) {
        upload_last_error($size < 1
            ? 'That file is empty. Choose a different file.'
            : 'That file is larger than 8 MB. Choose a smaller file or compress it, then upload again.');
        return null;
    }
    if (!upload_file_is_readable($tmp)) {
        upload_last_error('The upload was interrupted. Check your connection and choose the file again.');
        return null;
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        upload_last_error('That file type is not accepted here. Upload one of: ' . $allowedLabel . '.');
        return null;
    }
    if (!upload_file_content_ok($tmp, $ext)) {
        upload_last_error('That file looks damaged or was renamed to .' . $ext . '. Open it, save or export it again as ' . strtoupper($ext) . ', then upload the new copy.');
        return null;
    }

    $dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = uniqid($subdir . '_', true) . '.' . $ext;
    $dest = $dir . '/' . $filename;

    if (is_uploaded_file($tmp)) {
        if (!move_uploaded_file($tmp, $dest)) {
            upload_last_error('The file could not be saved.');
            return null;
        }
    } elseif (!@copy($tmp, $dest)) {
        upload_last_error('The file could not be saved.');
        return null;
    }

    return $subdir . '/' . $filename;
}

/**
 * Whether a multi/single file input received at least one upload.
 */
function registration_has_files(array $files): bool
{
    if (!isset($files['name'])) {
        return false;
    }
    if (is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            if ($name === '' || $name === null) {
                continue;
            }
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ((int) $err === UPLOAD_ERR_OK) {
                return true;
            }
        }
        return false;
    }
    return $files['name'] !== ''
        && (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

/**
 * Upload one or many files from a multi-file input (name="files[]").
 * @return list<string> Relative paths under uploads/
 */
function upload_files(array $files, string $subdir, array $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx']): array
{
    if (!isset($files['name'])) {
        return [];
    }
    $names = $files['name'];
    if (!is_array($names)) {
        $path = upload_file($files, $subdir, $allowed);
        return $path ? [$path] : [];
    }

    $paths = [];
    foreach ($names as $i => $name) {
        if ($name === '' || $name === null) {
            continue;
        }
        $one = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        $path = upload_file($one, $subdir, $allowed);
        if ($path) {
            $paths[] = $path;
        }
    }
    return $paths;
}

/**
 * @return array{ok:bool,message?:string,data?:array{name:string,description:?string,price:float,duration_minutes:?int}}
 */
function parse_place_service_post(array $post): array
{
    $name = trim((string) ($post['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Name is required.'];
    }
    $price = (float) ($post['price'] ?? 0);
    if ($price < 0) {
        return ['ok' => false, 'message' => 'Price cannot be negative.'];
    }
    if (isset($post['unit_count']) && $post['unit_count'] !== '' && (int) $post['unit_count'] < 1) {
        return ['ok' => false, 'message' => 'Enter how many of this room you have.'];
    }
    $durRaw = trim((string) ($post['duration_minutes'] ?? ''));
    if ($durRaw !== '' && !preg_match('/^\d+$/', $durRaw)) {
        return ['ok' => false, 'message' => 'Duration must be a number.'];
    }
    $dur = $durRaw !== '' ? (int) $durRaw : null;
    if ($dur !== null && $dur < 15) {
        return ['ok' => false, 'message' => 'Duration must be at least 15 minutes.'];
    }
    return [
        'ok' => true,
        'data' => [
            'name' => $name,
            'description' => trim((string) ($post['description'] ?? '')) ?: null,
            'price' => $price,
            'duration_minutes' => ($dur && $dur > 0) ? $dur : null,
            'capacity' => (isset($post['capacity']) && $post['capacity'] !== '' && (int) $post['capacity'] > 0)
                ? (int) $post['capacity']
                : null,
            'unit_count' => (isset($post['unit_count']) && $post['unit_count'] !== '')
                ? (int) $post['unit_count']
                : null,
            'extra_guest_fee' => (isset($post['extra_guest_fee']) && $post['extra_guest_fee'] !== '')
                ? round(max(0, (float) $post['extra_guest_fee']), 2)
                : null,
            'extra_guest_limit' => (isset($post['extra_guest_limit']) && $post['extra_guest_limit'] !== '')
                ? max(0, (int) $post['extra_guest_limit'])
                : null,
            'amenities' => trim((string) ($post['amenities'] ?? '')) ?: null,
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,days:?int}
 */
function package_days_from_post(array $post): array
{
    $raw = trim((string) ($post['package_days'] ?? ''));
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return ['ok' => false, 'message' => 'How many days must be a number.', 'days' => null];
    }
    $days = (int) $raw;
    if ($days < 1) {
        return ['ok' => false, 'message' => 'A package must last at least 1 day.', 'days' => null];
    }
    if ($days > 30) {
        return ['ok' => false, 'message' => 'A package can last up to 30 days.', 'days' => null];
    }
    return ['ok' => true, 'message' => '', 'days' => $days];
}

/**
 * How many people a tour package is for.
 *
 * @return array{ok:bool,message:string,pax:?int}
 */
function package_pax_from_post(array $post): array
{
    $raw = trim((string) ($post['capacity'] ?? ''));
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return ['ok' => false, 'message' => 'Pax must be a number.', 'pax' => null];
    }
    $pax = (int) $raw;
    if ($pax < 1) {
        return ['ok' => false, 'message' => 'A package must include at least 1 person.', 'pax' => null];
    }
    if ($pax > 99) {
        return ['ok' => false, 'message' => 'A package can include up to 99 people.', 'pax' => null];
    }
    return ['ok' => true, 'message' => '', 'pax' => $pax];
}

function package_pax_label(int $pax): string
{
    if ($pax < 1) {
        return '';
    }
    return $pax === 1 ? '1 pax' : $pax . ' pax';
}

/** Earliest tour start: two days from today. Today and tomorrow stay closed. */
function package_earliest_tour_date(): string
{
    $dt = DateTime::createFromFormat('!Y-m-d', app_today());
    if (!$dt) {
        $dt = new DateTime('today');
    }
    $dt->modify('+2 days');
    return $dt->format('Y-m-d');
}

/**
 * @return array{ok:bool,message:string,date:?string}
 */
function package_tour_date_from_post(array $post): array
{
    $raw = trim((string) ($post['tour_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return ['ok' => false, 'message' => 'Choose the date this tour starts.', 'date' => null];
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return ['ok' => false, 'message' => 'Choose a valid tour date.', 'date' => null];
    }
    if ($raw < package_earliest_tour_date()) {
        return ['ok' => false, 'message' => 'This date is closed. A tour cannot start today or tomorrow.', 'date' => null];
    }
    return ['ok' => true, 'message' => '', 'date' => $raw];
}

function package_days_label(int $days): string
{
    if ($days < 1) {
        return '';
    }
    return $days === 1 ? '1 day' : $days . ' days';
}

function package_tour_span_label(string $start, int $days): string
{
    $days = max(1, $days);
    $ts = strtotime($start);
    if ($ts === false) {
        return '';
    }
    if ($days === 1) {
        return format_date($start);
    }
    $end = date('Y-m-d', strtotime('+' . ($days - 1) . ' days', $ts));
    return format_date($start) . ' – ' . format_date($end);
}

/** rooms, vehicles, packages, or services. */
function business_catalog_mode(?string $typeName): string
{
    $type = strtolower(trim((string) $typeName));
    if (in_array($type, ['accommodation', 'hotel', 'resort'], true)) {
        return 'rooms';
    }
    if ($type === 'transportation') {
        return 'vehicles';
    }
    if (in_array($type, ['tour and travel', 'tours and travel', 'travel and tours', 'travel agency'], true)) {
        return 'packages';
    }
    if (str_contains($type, 'food') || str_contains($type, 'beach') || str_contains($type, 'wellness') || str_contains($type, 'recreation')) {
        return 'menu';
    }
    return 'services';
}

/** Accommodation, transportation, and tour businesses are not itinerary stops. */
function business_excluded_from_itinerary_stops(?string $typeName): bool
{
    return in_array(business_catalog_mode($typeName), ['rooms', 'vehicles', 'packages'], true);
}

function itinerary_stop_business_blocked(int $businessId): ?string
{
    if ($businessId < 1) {
        return null;
    }
    $st = db()->prepare(
        'SELECT bt.name FROM businesses b JOIN business_types bt ON bt.id = b.business_type_id WHERE b.id=? LIMIT 1'
    );
    $st->execute([$businessId]);
    $name = $st->fetchColumn();
    if ($name === false || !business_excluded_from_itinerary_stops((string) $name)) {
        return null;
    }
    return 'Accommodation, transportation, and tour and travel businesses are not added as stops.';
}

/**
 * Navbar and form wording for a business type.
 *
 * @return array{mode:string,label:string,noun:string,plural:string,heading:string,sub:string,icon:string,added:string,removed:string,updated:string,nameLabel:string,placeholder:string,desc:string,empty:string,locked:string,help:string}
 */
function business_catalog_copy(?string $typeName): array
{
    $mode = business_catalog_mode($typeName);
    $copies = [
        'rooms' => [
            'label' => 'Rooms',
            'noun' => 'room',
            'plural' => 'rooms',
            'heading' => 'Manage rooms',
            'sub' => '',
            'icon' => 'bi-door-open',
            'added' => 'Room added.',
            'removed' => 'Room removed.',
            'updated' => 'Room updated.',
            'nameLabel' => 'Room name',
            'placeholder' => 'Deluxe Queen',
            'desc' => 'What the room includes',
            'empty' => 'No rooms yet. Add one so tourists can see it when they book a tour.',
            'locked' => 'Rooms can be added after the Tourism Office approves your business.',
            'help' => 'Add a name and price. Room counts and guest fees are set on the Rooms page.',
        ],
        'vehicles' => [
            'label' => 'Vehicles',
            'noun' => 'vehicle',
            'plural' => 'vehicles',
            'heading' => 'Manage vehicles',
            'sub' => '',
            'icon' => 'bi-car-front',
            'added' => 'Vehicle added.',
            'removed' => 'Vehicle removed.',
            'updated' => 'Vehicle updated.',
            'nameLabel' => 'Vehicle',
            'placeholder' => 'Toyota Hiace van',
            'desc' => 'Route, driver, or pickup details',
            'empty' => 'No vehicles yet. Add one so tourists can see it when they book a tour.',
            'locked' => 'Vehicles can be added after the Tourism Office approves your business.',
            'help' => 'Add a name and price. Seats and how many vehicles you have are set on the Vehicles page.',
        ],
        'menu' => [
            'label' => 'Menu',
            'noun' => 'menu item',
            'plural' => 'menu items',
            'heading' => 'Manage menu',
            'sub' => 'Dishes and drinks you serve.',
            'icon' => 'bi-cup-hot',
            'added' => 'Menu item added.',
            'removed' => 'Menu item removed.',
            'updated' => 'Menu item updated.',
            'nameLabel' => 'Menu item',
            'placeholder' => 'Halo-halo',
            'desc' => 'What is in this dish or drink',
            'empty' => 'No menu items yet. Add one so tourists can see it when they book a tour.',
            'locked' => 'Your menu can be added after the Tourism Office approves your business.',
            'help' => 'Add a name and price. New menu items appear in the list below.',
        ],
        'products' => [
            'label' => 'Products',
            'noun' => 'product',
            'plural' => 'products',
            'heading' => 'Manage products',
            'sub' => 'What you sell.',
            'icon' => 'bi-bag',
            'added' => 'Product added.',
            'removed' => 'Product removed.',
            'updated' => 'Product updated.',
            'nameLabel' => 'Product name',
            'placeholder' => 'Pasalubong pack',
            'desc' => 'What this product is',
            'empty' => 'No products yet. Add one so tourists can see it when they book a tour.',
            'locked' => 'Products can be added after the Tourism Office approves your business.',
            'help' => 'Add a name and price. New products appear in the list below.',
        ],
        'activities' => [
            'label' => 'Service and Activities',
            'noun' => 'service or activity',
            'plural' => 'services and activities',
            'heading' => 'Manage service and activities',
            'sub' => 'Services and activities guests can book.',
            'icon' => 'bi-stars',
            'added' => 'Service or activity added.',
            'removed' => 'Service or activity removed.',
            'updated' => 'Service or activity updated.',
            'nameLabel' => 'Name',
            'placeholder' => 'Spa session',
            'desc' => 'What this service or activity includes',
            'empty' => 'No services or activities yet. Add one so tourists can see it when they book a tour.',
            'locked' => 'Service and activities can be added after the Tourism Office approves your business.',
            'help' => 'Add a name, price, and minutes. New services and activities appear in the list below.',
        ],
        'packages' => [
            'label' => 'Packages',
            'noun' => 'package',
            'plural' => 'packages',
            'heading' => 'Manage packages',
            'sub' => '',
            'icon' => 'bi-suitcase-lg',
            'added' => 'Package added.',
            'removed' => 'Package removed.',
            'updated' => 'Package updated.',
            'nameLabel' => 'Package name',
            'placeholder' => 'Ormoc day tour',
            'desc' => 'What this package includes',
            'empty' => 'No packages yet. Add one so tourists can see it when they book a tour.',
            'locked' => 'Packages can be added after the Tourism Office approves your business.',
            'help' => 'Add a name, price, how many days, how many people, and a photo. New packages appear in the list below.',
        ],
    ];
    $copy = $copies[$mode] ?? [
        'label' => 'Listing',
        'noun' => 'listing',
        'plural' => 'listings',
        'heading' => 'Manage listings',
        'sub' => 'What your business offers',
        'icon' => 'bi-list-check',
        'added' => 'Listing added.',
        'removed' => 'Listing removed.',
        'updated' => 'Listing updated.',
        'nameLabel' => 'Listing name',
        'placeholder' => 'Listing name',
        'desc' => 'Short description',
        'empty' => 'No listings yet. Add one so tourists can see it when they book a tour.',
        'locked' => 'Listings can be added after the Tourism Office approves your business.',
        'help' => 'Add a name and a photo. New listings appear in the list below.',
    ];
    if ($mode === 'menu') {
        $kind = revenue_bookable_kind($typeName);
        if ($kind === 'beach') {
            $copy = array_merge($copy, [
                'label' => 'Beach offerings',
                'noun' => 'offering',
                'plural' => 'offerings',
                'heading' => 'Manage beach offerings',
                'sub' => 'Rentals, activities, and cottages.',
                'icon' => 'bi-water',
                'added' => 'Offering added.',
                'removed' => 'Offering removed.',
                'updated' => 'Offering updated.',
                'nameLabel' => 'Offering',
                'placeholder' => 'Umbrella set',
                'desc' => 'What this includes',
                'empty' => 'Nothing listed yet. Add one so tourists can reserve it.',
                'locked' => 'Beach offerings can be added after the Tourism Office approves your business.',
                'help' => 'Add a name and price. New offerings appear in the list below.',
            ]);
        } elseif ($kind === 'wellness') {
            $copy = array_merge($copy, [
                'label' => 'Services',
                'noun' => 'service',
                'plural' => 'services',
                'heading' => 'Manage services',
                'sub' => 'Treatments, classes, and facilities.',
                'icon' => 'bi-heart-pulse',
                'added' => 'Service added.',
                'removed' => 'Service removed.',
                'updated' => 'Service updated.',
                'nameLabel' => 'Service',
                'placeholder' => 'Swedish massage',
                'desc' => 'What this service includes',
                'empty' => 'No services yet. Add one so tourists can book it.',
                'locked' => 'Services can be added after the Tourism Office approves your business.',
                'help' => 'Add a name and price. New services appear in the list below.',
            ]);
        }
    }
    $copy['mode'] = $mode;
    return $copy;
}

/**
 * @return array{ok:bool,message?:string,lat:?float,lng:?float}
 */
function normalize_lat_lng(mixed $latRaw, mixed $lngRaw): array
{
    $latStr = trim((string) ($latRaw ?? ''));
    $lngStr = trim((string) ($lngRaw ?? ''));
    if ($latStr === '' && $lngStr === '') {
        return ['ok' => true, 'lat' => null, 'lng' => null];
    }
    if ($latStr === '' || $lngStr === '' || !is_numeric($latStr) || !is_numeric($lngStr)) {
        return ['ok' => false, 'message' => 'Enter both latitude and longitude, or leave both blank.'];
    }
    $lat = (float) $latStr;
    $lng = (float) $lngStr;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['ok' => false, 'message' => 'Latitude must be between -90 and 90, longitude between -180 and 180.'];
    }
    return ['ok' => true, 'lat' => $lat, 'lng' => $lng];
}

/**
 * @return array{ok:bool,message?:string,code?:int,tourist_id?:int,peer_id?:int,peer_role?:int}
 */
function chat_resolve_conversation_peers(array $user, int $otherId): array
{
    $role = (int) ($user['role_id'] ?? 0);
    $myId = (int) ($user['id'] ?? 0);
    if ($otherId < 1) {
        return ['ok' => false, 'message' => 'Choose someone to message.', 'code' => 422];
    }
    if ($otherId === $myId) {
        return ['ok' => false, 'message' => 'You cannot message yourself.', 'code' => 422];
    }
    $st = db()->prepare('SELECT id, role_id FROM users WHERE id=? LIMIT 1');
    $st->execute([$otherId]);
    $peer = $st->fetch();
    if (!$peer) {
        return ['ok' => false, 'message' => 'That account was not found.', 'code' => 404];
    }
    $peerRole = (int) ($peer['role_id'] ?? 0);
    if ($role === ROLE_TOURIST && !in_array($peerRole, [ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
        return ['ok' => false, 'message' => 'Tourists can message tour guides or businesses.', 'code' => 403];
    }
    if (in_array($role, [ROLE_TOUR_GUIDE, ROLE_BUSINESS], true) && $peerRole !== ROLE_TOURIST) {
        return ['ok' => false, 'message' => 'Guides and businesses can message tourists.', 'code' => 403];
    }
    if (!in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE, ROLE_BUSINESS], true)) {
        return ['ok' => false, 'message' => 'Forbidden', 'code' => 403];
    }
    if ($role === ROLE_TOURIST) {
        return ['ok' => true, 'tourist_id' => $myId, 'peer_id' => $otherId, 'peer_role' => $peerRole];
    }
    return ['ok' => true, 'tourist_id' => $otherId, 'peer_id' => $myId, 'peer_role' => $peerRole];
}

/** Allowed extensions for business credential documents */
function business_document_allowed_ext(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
}

/**
 * Ensure business_documents table exists; migrate legacy businesses.document_file rows.
 */
function ensure_business_documents_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS business_documents (
              id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
              business_id INT UNSIGNED NOT NULL,
              file_path VARCHAR(255) NOT NULL,
              original_name VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_biz_docs (business_id),
              CONSTRAINT fk_biz_doc FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );
        // Migrate single document_file into the new table when present
        $cols = db()->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('document_file', $cols, true)) {
            $legacy = db()->query(
                "SELECT id, document_file FROM businesses
                 WHERE document_file IS NOT NULL AND document_file <> ''
                   AND NOT EXISTS (
                     SELECT 1 FROM business_documents d WHERE d.business_id = businesses.id AND d.file_path = businesses.document_file
                   )"
            )->fetchAll();
            $ins = db()->prepare(
                'INSERT INTO business_documents (business_id, file_path, original_name) VALUES (?,?,?)'
            );
            foreach ($legacy as $row) {
                $ins->execute([(int) $row['id'], $row['document_file'], basename((string) $row['document_file'])]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    ensure_business_listing_schema();
}

function join_person_name(string $first, string $middle, string $last, string $suffix = ''): string
{
    $parts = [];
    foreach ([$first, $middle, $last] as $p) {
        $p = trim($p);
        if ($p !== '') {
            $parts[] = $p;
        }
    }
    $name = implode(' ', $parts);
    $suffix = normalize_name_suffix($suffix) ?? '';
    if ($suffix !== '') {
        $name = $name !== '' ? ($name . ' ' . $suffix) : $suffix;
    }
    return $name;
}

/** True when a person name contains digits or non-letter symbols (spaces, hyphen, apostrophe, period OK). */
function person_name_has_invalid_chars(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (preg_match('/\d/u', $name)) {
        return true;
    }
    return !preg_match('/^[\p{L}\s.\'\-]+$/u', $name);
}

/** @return array<string,string> value => label */
function name_suffix_preset_options(): array
{
    return [
        'none' => 'None',
        'Jr' => 'Jr.',
        'Sr' => 'Sr.',
        'II' => 'II',
        'III' => 'III',
        'IV' => 'IV',
        'V' => 'V',
        'other' => 'Other',
    ];
}

/** Resolve select (+ optional Other text) into a raw suffix string. */
function resolve_posted_name_suffix(string $base, array $post): string
{
    $choice = trim((string) ($post[$base . '_choice'] ?? ''));
    if ($choice === '' && array_key_exists($base, $post)) {
        return trim((string) $post[$base]);
    }
    if ($choice === '' || $choice === 'none') {
        return '';
    }
    if ($choice === 'other') {
        return trim((string) ($post[$base . '_other'] ?? ''));
    }
    return $choice;
}

/**
 * Custom suffix dropdown — “Please specify” appears under Other in the menu.
 * @param array<string,mixed> $attrs
 */
function name_suffix_field_html(string $base, string $current = '', array $attrs = []): string
{
    $normalized = normalize_name_suffix($current) ?? '';
    $presets = ['Jr', 'Sr', 'II', 'III', 'IV', 'V'];
    $isOther = $normalized !== '' && !in_array($normalized, $presets, true);
    $selected = $normalized === '' ? 'none' : ($isOther ? 'other' : $normalized);
    $disabled = !empty($attrs['disabled']);
    $dropup = !empty($attrs['dropup']);
    $options = name_suffix_preset_options();
    $display = $options[$selected] ?? 'None';
    if ($isOther && $normalized !== '') {
        $display = $normalized;
    }

    $html = '<div class="suffix-picker'
        . ($dropup ? ' suffix-picker--dropup' : '')
        . ($disabled ? ' is-disabled' : '')
        . '" data-suffix-base="' . e($base) . '">';
    $html .= '<input type="hidden" class="suffix-picker__choice" name="' . e($base) . '_choice" value="' . e($selected) . '">';
    $html .= '<button type="button" class="form-select suffix-picker__toggle" aria-haspopup="listbox" aria-expanded="false"'
        . ($disabled ? ' disabled' : '') . '>';
    $html .= '<span class="suffix-picker__label">' . e($display) . '</span>';
    $html .= '<i class="bi bi-chevron-down suffix-picker__chevron" aria-hidden="true"></i>';
    $html .= '</button>';
    $html .= '<div class="suffix-picker__menu d-none" role="listbox">';
    foreach ($options as $value => $label) {
        if ($value === 'other') {
            continue;
        }
        $html .= '<button type="button" class="suffix-picker__option' . ($selected === $value ? ' is-active' : '') . '"'
            . ' data-value="' . e($value) . '" role="option">' . e($label) . '</button>';
    }
    $html .= '<button type="button" class="suffix-picker__option' . ($selected === 'other' ? ' is-active' : '') . '"'
        . ' data-value="other" role="option">Other</button>';
    $html .= '<div class="suffix-picker__specify' . ($isOther ? '' : ' d-none') . '">';
    $html .= '<input type="text" class="form-control form-control-sm suffix-picker__other name-cap name-letters" name="'
        . e($base) . '_other" maxlength="4" placeholder="Please specify" autocomplete="off"'
        . ' value="' . e($isOther ? $normalized : '') . '"' . ($disabled ? ' disabled' : '') . '>';
    $html .= '<div class="form-text text-danger d-none name-letters-warn"></div>';
    $html .= '</div>';
    $html .= '</div></div>';
    return $html;
}

/** @deprecated Use inline specify in name_suffix_field_html */
function name_suffix_other_html(string $base, string $current = '', array $attrs = []): string
{
    return '';
}

/** @return array<string,string> value => label */
function emergency_relationship_options(): array
{
    return [
        'Father' => 'Father',
        'Mother' => 'Mother',
        'Brother' => 'Brother',
        'Sister' => 'Sister',
        'Spouse' => 'Spouse',
        'Son' => 'Son',
        'Daughter' => 'Daughter',
        'Friend' => 'Friend',
        'Guardian' => 'Guardian',
        'Grandparent' => 'Grandparent',
        'Cousin' => 'Cousin',
        'Aunt' => 'Aunt',
        'Uncle' => 'Uncle',
        'other' => 'Other',
    ];
}

function resolve_posted_emergency_relationship(array $post): string
{
    if (!array_key_exists('emergency_relationship_choice', $post)) {
        return trim((string) ($post['emergency_relationship'] ?? $post['emergency_contact_relationship'] ?? ''));
    }
    $choice = trim((string) ($post['emergency_relationship_choice'] ?? ''));
    if ($choice === '' || $choice === 'none') {
        return '';
    }
    if ($choice === 'other') {
        return trim((string) ($post['emergency_relationship_other'] ?? ''));
    }
    return $choice;
}

/**
 * Relationship dropdown — “Please specify” under Other (same picker UX as suffix).
 * @param array<string,mixed> $attrs
 */
function emergency_relationship_field_html(string $current = '', array $attrs = []): string
{
    $options = emergency_relationship_options();
    $presets = array_values(array_filter(array_keys($options), static fn ($k) => $k !== 'other'));
    $current = trim($current);
    $isOther = $current !== '' && !in_array($current, $presets, true);
    $selected = $current === '' ? '' : ($isOther ? 'other' : $current);
    $disabled = !empty($attrs['disabled']);
    $dropup = !empty($attrs['dropup']);
    $display = $selected === '' ? 'Select' : ($options[$selected] ?? $current);
    if ($isOther && $current !== '') {
        $display = $current;
    }

    $html = '<div class="suffix-picker suffix-picker--wide'
        . ($dropup ? ' suffix-picker--dropup' : '')
        . ($disabled ? ' is-disabled' : '')
        . '" data-suffix-base="emergency_relationship" data-empty-label="Select">';
    $html .= '<input type="hidden" class="suffix-picker__choice" name="emergency_relationship_choice" value="' . e($selected) . '">';
    $html .= '<button type="button" class="form-select suffix-picker__toggle" id="emergRelationship" aria-haspopup="listbox" aria-expanded="false"'
        . ($disabled ? ' disabled' : '') . '>';
    $html .= '<span class="suffix-picker__label">' . e($display) . '</span>';
    $html .= '<i class="bi bi-chevron-down suffix-picker__chevron" aria-hidden="true"></i>';
    $html .= '</button>';
    $html .= '<div class="suffix-picker__menu d-none" role="listbox">';
    foreach ($options as $value => $label) {
        if ($value === 'other') {
            continue;
        }
        $html .= '<button type="button" class="suffix-picker__option' . ($selected === $value ? ' is-active' : '') . '"'
            . ' data-value="' . e($value) . '" role="option">' . e($label) . '</button>';
    }
    $html .= '<button type="button" class="suffix-picker__option' . ($selected === 'other' ? ' is-active' : '') . '"'
        . ' data-value="other" role="option">Other</button>';
    $html .= '<div class="suffix-picker__specify' . ($isOther ? '' : ' d-none') . '">';
    $html .= '<input type="text" class="form-control form-control-sm suffix-picker__other name-cap name-letters" name="emergency_relationship_other" maxlength="80"'
        . ' placeholder="Please specify" autocomplete="off"'
        . ' value="' . e($isOther ? $current : '') . '"' . ($disabled ? ' disabled' : '') . '>';
    $html .= '<div class="form-text text-danger d-none name-letters-warn"></div>';
    $html .= '</div>';
    $html .= '</div></div>';
    return $html;
}

/** Optional name suffix (Jr, Sr, II–V, or custom) — max 4 letters. */
function normalize_name_suffix(?string $raw): ?string
{
    $s = trim((string) $raw);
    if ($s === '' || strcasecmp($s, 'none') === 0 || strcasecmp($s, 'other') === 0) {
        return null;
    }
    $letters = preg_replace('/[^A-Za-z]/', '', $s) ?? '';
    $letters = substr($letters, 0, 4);
    if ($letters === '') {
        return null;
    }
    $map = [
        'JR' => 'Jr',
        'SR' => 'Sr',
        'II' => 'II',
        'III' => 'III',
        'IV' => 'IV',
        'V' => 'V',
    ];
    $upper = strtoupper($letters);
    if (isset($map[$upper])) {
        return $map[$upper];
    }
    return capitalize_name($letters);
}

/** @return array{first:string,middle:string,last:string,suffix:string} */
function split_person_name(?string $full): array
{
    $empty = ['first' => '', 'middle' => '', 'last' => '', 'suffix' => ''];
    $full = trim((string) $full);
    if ($full === '') {
        return $empty;
    }
    $parts = preg_split('/\s+/', $full) ?: [];
    $suffix = '';
    if (count($parts) >= 2) {
        $lastTok = (string) $parts[count($parts) - 1];
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $lastTok) ?? '');
        $known = ['JR' => 'Jr', 'SR' => 'Sr', 'II' => 'II', 'III' => 'III', 'IV' => 'IV', 'V' => 'V'];
        if (isset($known[$letters])) {
            $suffix = $known[$letters];
            array_pop($parts);
        }
    }
    if (!$parts) {
        return ['first' => '', 'middle' => '', 'last' => '', 'suffix' => $suffix];
    }
    if (count($parts) === 1) {
        return ['first' => $parts[0], 'middle' => '', 'last' => '', 'suffix' => $suffix];
    }
    if (count($parts) === 2) {
        return ['first' => $parts[0], 'middle' => '', 'last' => $parts[1], 'suffix' => $suffix];
    }
    return [
        'first' => $parts[0],
        'middle' => implode(' ', array_slice($parts, 1, -1)),
        'last' => $parts[count($parts) - 1],
        'suffix' => $suffix,
    ];
}

/** Empty is allowed. Any letter or symbol is rejected. */
function tax_id_digits_error(string $taxId): ?string
{
    if ($taxId === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $taxId)) {
        return 'Tax identification number must contain numbers only.';
    }
    return null;
}

/**
 * Admin add/edit listing fields (same set as business registration / profile).
 * @param array<string,mixed> $post
 * @return array{ok:bool,message?:string,data?:array<string,mixed>}
 */
function parse_admin_business_listing_post(array $post): array
{
    $name = trim((string) ($post['name'] ?? ''));
    $typeId = (int) ($post['business_type_id'] ?? 0);
    $province = ph_guide_locked_province();
    $city = ph_business_default_city();
    $barangay = trim((string) ($post['address_barangay'] ?? ''));
    $street = trim((string) ($post['address_street'] ?? ''));
    $address = '';
    if ($city !== '' && $barangay !== '' && $street !== '' && ph_address_is_valid($province, $city, $barangay)) {
        $address = compose_tourist_address($province, $city, $barangay, $street);
    }
    $ownerFirst = capitalize_name((string) ($post['owner_first_name'] ?? ''));
    $ownerMiddle = capitalize_name((string) ($post['owner_middle_name'] ?? ''));
    $ownerLast = capitalize_name((string) ($post['owner_last_name'] ?? ''));
    $ownerSuffix = resolve_posted_name_suffix('owner_suffix', $post);
    $repFirst = capitalize_name((string) ($post['representative_first_name'] ?? ''));
    $repMiddle = capitalize_name((string) ($post['representative_middle_name'] ?? ''));
    $repLast = capitalize_name((string) ($post['representative_last_name'] ?? ''));
    $repSuffix = resolve_posted_name_suffix('representative_suffix', $post);
    foreach ([$ownerFirst, $ownerLast, $ownerMiddle, $ownerSuffix, $repFirst, $repLast, $repMiddle, $repSuffix] as $namePart) {
        if (person_name_has_invalid_chars($namePart)) {
            return ['ok' => false, 'message' => 'Names may only contain letters.'];
        }
    }
    $phone = trim((string) ($post['phone'] ?? ''));
    $email = strtolower(trim((string) ($post['email'] ?? '')));
    $permit = trim((string) ($post['permit_number'] ?? ''));
    $taxId = trim((string) ($post['tax_id'] ?? ''));
    if ($taxErr = tax_id_digits_error($taxId)) {
        return ['ok' => false, 'message' => $taxErr];
    }
    $description = trim((string) ($post['description'] ?? ''));
    $facebookUrl = trim((string) ($post['facebook_url'] ?? ''));
    $instagramUrl = trim((string) ($post['instagram_url'] ?? ''));
    if ($facebookUrl !== '' && !preg_match('#^https?://#i', $facebookUrl)) {
        $facebookUrl = 'https://' . $facebookUrl;
    }
    if ($instagramUrl !== '' && !preg_match('#^https?://#i', $instagramUrl)) {
        $instagramUrl = 'https://' . $instagramUrl;
    }

    if ($name === '' || !$typeId) {
        return ['ok' => false, 'message' => 'Business name and type are required.'];
    }
    if ($address === '') {
        return ['ok' => false, 'message' => 'Barangay and street are required.'];
    }
    if ($repFirst === '' || $repLast === '') {
        return ['ok' => false, 'message' => 'Representative first name and last name are required.'];
    }
    if ($ownerFirst === '' || $ownerLast === '') {
        return ['ok' => false, 'message' => 'Business owner first name and last name are required.'];
    }
    $sexOptions = user_sex_options();
    $ownerSex = trim((string) ($post['owner_sex'] ?? ''));
    $repSex = trim((string) ($post['representative_sex'] ?? ''));
    if (!isset($sexOptions[$ownerSex])) {
        return ['ok' => false, 'message' => 'Select the business owner gender.'];
    }
    if (!isset($sexOptions[$repSex])) {
        return ['ok' => false, 'message' => 'Select the representative gender.'];
    }
    $phoneCheck = normalize_ph_phone($phone, true);
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'message' => (string) ($phoneCheck['message'] ?? 'Enter a valid business contact number.')];
    }
    $phone = (string) $phoneCheck['phone'];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid business email.'];
    }

    return [
        'ok' => true,
        'data' => [
            'name' => $name,
            'type_id' => $typeId,
            'address' => $address,
            'address_region' => $province,
            'address_city' => $city,
            'address_barangay' => $barangay,
            'address_street' => $street,
            'owner_name' => join_person_name($ownerFirst, $ownerMiddle, $ownerLast, $ownerSuffix),
            'owner_first' => $ownerFirst,
            'owner_middle' => $ownerMiddle,
            'owner_last' => $ownerLast,
            'owner_sex' => $ownerSex,
            'representative_name' => join_person_name($repFirst, $repMiddle, $repLast, $repSuffix),
            'representative_sex' => $repSex,
            'phone' => $phone,
            'email' => $email,
            'permit_number' => $permit !== '' ? $permit : null,
            'tax_id' => $taxId !== '' ? $taxId : null,
            'description' => $description !== '' ? $description : null,
            'facebook_url' => $facebookUrl !== '' ? $facebookUrl : null,
            'instagram_url' => $instagramUrl !== '' ? $instagramUrl : null,
        ],
    ];
}

function files_field_has_upload(mixed $files): bool
{
    if (!is_array($files) || !isset($files['name'])) {
        return false;
    }
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    foreach ($names as $name) {
        if (trim((string) $name) !== '') {
            return true;
        }
    }
    return false;
}

function activity_scalar(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
    return trim((string) $value);
}

function activity_coord(mixed $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || !is_numeric($raw)) {
        return '';
    }
    return number_format((float) $raw, 6, '.', '');
}

function activity_hours_scalar(mixed $value): string
{
    $parsed = parse_opening_hours_value($value);
    if (is_array($parsed) && $parsed) {
        ksort($parsed);
        return (string) json_encode($parsed, JSON_UNESCAPED_UNICODE);
    }
    return trim((string) ($value ?? ''));
}

/**
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 * @param list<string> $extraLabels
 * @return list<string>
 */
function business_updated_field_labels(array $before, array $after, array $extraLabels = []): array
{
    $labels = [];
    $fields = [
        'name' => 'business name',
        'business_type_id' => 'business category',
        'owner_name' => 'owner name',
        'representative_name' => 'representative name',
        'description' => 'description',
        'phone' => 'phone',
        'email' => 'email',
        'permit_number' => 'permit number',
        'tax_id' => 'tax identification number',
        'facebook_url' => 'Facebook',
        'instagram_url' => 'Instagram',
        'cover_image' => 'cover photo',
        'logo' => 'business logo',
        'status' => 'listing status',
        'visit_duration_minutes' => 'visit duration',
    ];
    foreach ($fields as $key => $label) {
        if (!array_key_exists($key, $after)) {
            continue;
        }
        if (activity_scalar($before[$key] ?? '') !== activity_scalar($after[$key] ?? '')) {
            $labels[] = $label;
        }
    }
    $addrChanged = false;
    foreach (['address', 'address_barangay', 'address_street'] as $key) {
        if (!array_key_exists($key, $after)) {
            continue;
        }
        if (activity_scalar($before[$key] ?? '') !== activity_scalar($after[$key] ?? '')) {
            $addrChanged = true;
            break;
        }
    }
    if ($addrChanged) {
        $labels[] = 'address';
    }
    if (array_key_exists('latitude', $after) || array_key_exists('longitude', $after)) {
        $oldPin = activity_coord($before['latitude'] ?? '') . ',' . activity_coord($before['longitude'] ?? '');
        $newPin = activity_coord($after['latitude'] ?? '') . ',' . activity_coord($after['longitude'] ?? '');
        if ($oldPin !== $newPin) {
            $labels[] = 'map pin';
        }
    }
    if (array_key_exists('opening_hours', $after)
        && activity_hours_scalar($before['opening_hours'] ?? '') !== activity_hours_scalar($after['opening_hours'] ?? '')) {
        $labels[] = 'operating hours';
    }
    foreach ($extraLabels as $label) {
        $label = trim((string) $label);
        if ($label !== '' && !in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }
    return $labels;
}

function business_update_activity_details(string $businessName, array $labels): string
{
    $prefix = trim($businessName) !== '' ? trim($businessName) . ': ' : '';
    if (!$labels) {
        return $prefix . 'saved details';
    }
    if (count($labels) === 1) {
        return $prefix . 'updated ' . $labels[0];
    }
    $last = array_pop($labels);
    return $prefix . 'updated ' . implode(', ', $labels) . ' and ' . $last;
}

function log_business_update_activity(int $actorId, string $businessName, array $before, array $after, array $extraLabels = []): void
{
    $details = business_update_activity_details(
        $businessName,
        business_updated_field_labels($before, $after, $extraLabels)
    );
    log_activity($actorId, 'update_business', 'businesses', $details);
}

/**
 * Facebook + Instagram URL fields for add/edit listing media row.
 */
function admin_business_social_fields_html(array $listing, string $formId = ''): string
{
    $fb = e((string) ($listing['facebook_url'] ?? ''));
    $ig = e((string) ($listing['instagram_url'] ?? ''));
    $formAttr = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    return '<div class="admin-biz-media__social">'
        . '<div class="admin-biz-media__social-col">'
        . '<label class="form-label">Facebook</label>'
        . '<input class="form-control" name="facebook_url"' . $formAttr . ' placeholder="Paste Facebook link" value="' . $fb . '">'
        . '</div>'
        . '<div class="admin-biz-media__social-col">'
        . '<label class="form-label">Instagram</label>'
        . '<input class="form-control" name="instagram_url"' . $formAttr . ' placeholder="Paste Instagram link" value="' . $ig . '">'
        . '</div></div>';
}

/** Prefix https:// when a pasted social/website URL has no scheme. */
function http_href(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    return preg_match('#^https?://#i', $url) ? $url : ('https://' . $url);
}

/**
 * @return array{facebook:string,instagram:string}
 */
function business_social_hrefs(array $row): array
{
    $fb = trim((string) ($row['facebook_url'] ?? ''));
    $ig = trim((string) ($row['instagram_url'] ?? ''));
    $web = trim((string) ($row['website'] ?? ''));
    if ($fb === '' && $web !== '' && preg_match('#facebook\.com#i', $web)) {
        $fb = $web;
    }
    if ($ig === '' && $web !== '' && preg_match('#instagram\.com#i', $web)) {
        $ig = $web;
    }
    return [
        'facebook' => http_href($fb),
        'instagram' => http_href($ig),
    ];
}

/** Strip scheme so a pasted URL can show like an email address. */
function public_url_label(string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return '';
    }
    return (string) preg_replace('#^https?://(www\.)?#i', '', $href);
}

/**
 * Facebook / Instagram rows for tourist contact cards.
 * Only networks with a saved link are shown.
 */
function business_social_facts_html(array $row): string
{
    $social = business_social_hrefs($row);
    $icons = [
        'facebook' => 'bi-facebook',
        'instagram' => 'bi-instagram',
    ];
    $html = '';
    foreach ($icons as $key => $icon) {
        $href = $social[$key];
        if ($href === '') {
            continue;
        }
        $label = $key === 'facebook' ? 'Facebook' : 'Instagram';
        $html .= '<a class="place-detail-contact__link" href="' . e($href) . '" target="_blank" rel="noopener">'
            . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
            . '<span>' . e(public_url_label($href)) . '</span>'
            . '<span class="visually-hidden"> ' . $label . '</span>'
            . '</a>';
    }
    return $html;
}

/**
 * @return array{ok:bool,message?:string,data?:array{name:string,owner_name:string,representative_name:string,type_id:int,address:string,phone:?string,email:string,permit_number:string,tax_id:?string,description:?string}}
 */
function parse_business_registration_input(array $data, string $firstName, string $lastName, array $ident, string $accountPhone, string $accountEmail): array
{
    $name = trim((string) ($data['business_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Business name is required.'];
    }
    $repFirst = capitalize_name((string) ($data['representative_first_name'] ?? ''));
    $repMiddle = capitalize_name((string) ($data['representative_middle_name'] ?? ''));
    $repLast = capitalize_name((string) ($data['representative_last_name'] ?? ''));
    $repSuffix = (string) ($data['representative_suffix'] ?? '');
    $rep = join_person_name($repFirst, $repMiddle, $repLast, $repSuffix);
    if ($repFirst === '' || $repLast === '') {
        return ['ok' => false, 'message' => 'Representative first name and last name are required.'];
    }
    $repSex = trim((string) ($data['representative_sex'] ?? ''));
    if ($repSex === '' || !array_key_exists($repSex, user_sex_options())) {
        return ['ok' => false, 'message' => 'Please select the representative gender.'];
    }
    $ownerFirst = capitalize_name((string) ($data['owner_first_name'] ?? $firstName));
    $ownerMiddle = capitalize_name((string) ($data['owner_middle_name'] ?? ($ident['middle_name'] ?? '')));
    $ownerLast = capitalize_name((string) ($data['owner_last_name'] ?? $lastName));
    $ownerSuffix = (string) ($data['owner_suffix'] ?? $data['name_suffix'] ?? '');
    $ownerName = join_person_name($ownerFirst, $ownerMiddle, $ownerLast, $ownerSuffix);
    if ($ownerFirst === '' || $ownerLast === '') {
        return ['ok' => false, 'message' => 'Business owner first name and last name are required.'];
    }
    foreach ([$repFirst, $repLast, $repMiddle, $repSuffix, $ownerFirst, $ownerLast, $ownerMiddle, $ownerSuffix] as $namePart) {
        if (person_name_has_invalid_chars($namePart)) {
            return ['ok' => false, 'message' => 'Names may only contain letters.'];
        }
    }
    $permit = trim((string) ($data['permit_number'] ?? ''));
    if ($permit === '') {
        return ['ok' => false, 'message' => 'Business permit / registration number is required.'];
    }
    $province = ph_guide_locked_province();
    $city = ph_business_default_city();
    $barangay = trim((string) ($data['biz_address_barangay'] ?? $data['address_barangay'] ?? ''));
    $street = trim((string) ($data['biz_address_street'] ?? $data['address_street'] ?? ''));
    if ($barangay === '') {
        return ['ok' => false, 'message' => 'Please select a barangay.'];
    }
    if ($street === '') {
        return ['ok' => false, 'message' => 'Please enter the street / house number.'];
    }
    if (!ph_address_is_valid($province, $city, $barangay)) {
        return ['ok' => false, 'message' => 'Please select a valid city and barangay.'];
    }
    $address = compose_tourist_address($province, $city, $barangay, $street);
    $typeId = (int) ($data['business_type_id'] ?? 0);
    if ($typeId < 1) {
        return ['ok' => false, 'message' => 'Please select a business category.'];
    }
    $phone = trim((string) ($data['business_phone'] ?? ''));
    if ($phone === '') {
        $phone = $accountPhone;
    }
    $phoneCheck = normalize_ph_phone($phone, true);
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'message' => (string) ($phoneCheck['message'] ?? 'Enter a valid business contact number.')];
    }
    $phone = (string) $phoneCheck['phone'];
    $email = strtolower(trim((string) ($data['business_email'] ?? '')));
    if ($email === '') {
        $email = $accountEmail;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid business email.'];
    }
    $taxId = trim((string) ($data['tax_id'] ?? ''));
    if ($taxErr = tax_id_digits_error($taxId)) {
        return ['ok' => false, 'message' => $taxErr];
    }
    $description = trim((string) ($data['business_description'] ?? ''));
    return [
        'ok' => true,
        'data' => [
            'name' => $name,
            'owner_name' => $ownerName,
            'representative_name' => $rep,
            'representative_sex' => $repSex,
            'type_id' => $typeId,
            'address' => $address,
            'address_region' => $province,
            'address_city' => $city,
            'address_barangay' => $barangay,
            'address_street' => $street,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email,
            'permit_number' => $permit,
            'tax_id' => $taxId !== '' ? $taxId : null,
            'description' => $description !== '' ? $description : null,
        ],
    ];
}

function business_listing_is_approved(?array $biz): bool
{
    return is_array($biz) && ($biz['approval_status'] ?? '') === 'approved';
}

/**
 * Public listing pieces the business fills in after approval.
 * @return list<string>
 */
function business_listing_finish_gaps(array $biz, int $photoCount = 0): array
{
    if (!business_listing_is_approved($biz)) {
        return [];
    }
    $gaps = [];
    if (trim((string) ($biz['description'] ?? '')) === '') {
        $gaps[] = 'a description';
    }
    if (trim((string) ($biz['logo'] ?? '')) === '') {
        $gaps[] = 'a logo';
    }
    if (trim((string) ($biz['cover_image'] ?? '')) === '' && $photoCount < 1) {
        $gaps[] = 'photos';
    }
    $lat = $biz['latitude'] ?? null;
    $lng = $biz['longitude'] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        $gaps[] = 'a map pin';
    }
    return $gaps;
}

/** Extra listing fields collected at business registration. */
function ensure_business_listing_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return;
        }
        $add = [
            'owner_name' => "VARCHAR(200) NULL AFTER `name`",
            'representative_name' => "VARCHAR(200) NULL AFTER `owner_name`",
            'representative_sex' => "ENUM('female','male','prefer_not') NULL AFTER `representative_name`",
            'permit_number' => "VARCHAR(100) NULL AFTER `email`",
            'tax_id' => "VARCHAR(50) NULL AFTER `permit_number`",
            'logo' => "VARCHAR(255) NULL AFTER `cover_image`",
            'facebook_url' => "VARCHAR(500) NULL AFTER `website`",
            'instagram_url' => "VARCHAR(500) NULL AFTER `facebook_url`",
            'address_region' => "VARCHAR(100) NULL AFTER `address`",
            'address_city' => "VARCHAR(120) NULL AFTER `address_region`",
            'address_barangay' => "VARCHAR(120) NULL AFTER `address_city`",
            'address_street' => "VARCHAR(150) NULL AFTER `address_barangay`",
        ];
        foreach ($add as $col => $def) {
            if (in_array($col, $cols, true)) {
                continue;
            }
            db()->exec('ALTER TABLE businesses ADD COLUMN `' . $col . '` ' . $def);
            $cols[] = $col;
        }
        foreach (['facebook_url', 'instagram_url'] as $urlCol) {
            $colInfo = db()->query("SHOW COLUMNS FROM businesses LIKE '" . $urlCol . "'")->fetch(PDO::FETCH_ASSOC) ?: [];
            if (strtolower((string) ($colInfo['Type'] ?? '')) !== 'varchar(500)') {
                db()->exec('ALTER TABLE businesses MODIFY `' . $urlCol . '` VARCHAR(500) NULL');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Save gallery images from a multi-file input. First photo becomes cover if none is set.
 * @return int Number of photos saved
 */
function save_business_gallery_photos(int $businessId, array $filesField, bool $setCoverIfEmpty = true): int
{
    $paths = upload_files($filesField, 'businesses', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$paths) {
        return 0;
    }
    $ins = db()->prepare('INSERT INTO business_photos (business_id, image_path, caption) VALUES (?,?,?)');
    foreach ($paths as $path) {
        $ins->execute([$businessId, $path, null]);
    }
    if ($setCoverIfEmpty) {
        try {
            $cur = db()->prepare('SELECT cover_image, logo FROM businesses WHERE id=?');
            $cur->execute([$businessId]);
            $row = $cur->fetch() ?: [];
            if (trim((string) ($row['cover_image'] ?? '')) === '') {
                db()->prepare('UPDATE businesses SET cover_image=? WHERE id=?')->execute([$paths[0], $businessId]);
            }
            if (trim((string) ($row['logo'] ?? '')) === '') {
                db()->prepare('UPDATE businesses SET logo=? WHERE id=?')->execute([$paths[0], $businessId]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return count($paths);
}

/**
 * Save gallery images for a destination from a multi-file input.
 * @return int Number of photos saved
 */
function save_destination_gallery_photos(int $destinationId, array $filesField): int
{
    $paths = upload_files($filesField, 'destinations', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$paths) {
        return 0;
    }
    $ins = db()->prepare('INSERT INTO destination_images (destination_id, image_path, caption) VALUES (?,?,?)');
    foreach ($paths as $path) {
        $ins->execute([$destinationId, $path, null]);
    }
    return count($paths);
}

/** @return list<array{id:int,business_id:int,file_path:string,original_name:?string,created_at:string}> */
function business_documents(int $businessId): array
{
    ensure_business_documents_schema();
    $stmt = db()->prepare(
        'SELECT id, business_id, file_path, original_name, created_at
         FROM business_documents WHERE business_id=? ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$businessId]);
    $rows = $stmt->fetchAll() ?: [];
    if ($rows) {
        return $rows;
    }
    try {
        $legacy = db()->prepare('SELECT document_file FROM businesses WHERE id=?');
        $legacy->execute([$businessId]);
        $path = trim((string) ($legacy->fetchColumn() ?: ''));
        if ($path !== '') {
            return [[
                'id' => 0,
                'business_id' => $businessId,
                'file_path' => $path,
                'original_name' => basename($path),
                'created_at' => null,
            ]];
        }
    } catch (Throwable $e) {
        // ignore missing column
    }
    return [];
}

/**
 * Save uploaded credential files for a business. Updates legacy document_file to the first file.
 * @return int Number of files saved
 */
function save_business_documents(int $businessId, array $filesField): int
{
    ensure_business_documents_schema();
    if (!isset($filesField['name'])) {
        return 0;
    }

    $items = [];
    if (!is_array($filesField['name'])) {
        $items[] = $filesField;
    } else {
        foreach ($filesField['name'] as $i => $name) {
            if ($name === '' || $name === null) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'type' => $filesField['type'][$i] ?? '',
                'tmp_name' => $filesField['tmp_name'][$i] ?? '',
                'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $filesField['size'][$i] ?? 0,
            ];
        }
    }

    $ins = db()->prepare(
        'INSERT INTO business_documents (business_id, file_path, original_name) VALUES (?,?,?)'
    );
    $saved = 0;
    foreach ($items as $one) {
        $path = upload_file($one, 'credentials', business_document_allowed_ext());
        if (!$path) {
            continue;
        }
        $ins->execute([$businessId, $path, $one['name'] ?: basename($path)]);
        $saved++;
    }
    if ($saved > 0) {
        sync_business_document_file($businessId);
    }
    return $saved;
}

function sync_business_document_file(int $businessId): void
{
    ensure_business_documents_schema();
    try {
        $cols = db()->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('document_file', $cols, true)) {
            return;
        }
        $first = db()->prepare(
            'SELECT file_path FROM business_documents WHERE business_id=? ORDER BY created_at ASC, id ASC LIMIT 1'
        );
        $first->execute([$businessId]);
        $path = $first->fetchColumn() ?: null;
        db()->prepare('UPDATE businesses SET document_file=? WHERE id=?')->execute([$path, $businessId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function delete_business_document(int $documentId, int $businessId): bool
{
    ensure_business_documents_schema();
    $stmt = db()->prepare('SELECT * FROM business_documents WHERE id=? AND business_id=?');
    $stmt->execute([$documentId, $businessId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return false;
    }
    db()->prepare('DELETE FROM business_documents WHERE id=? AND business_id=?')
        ->execute([$documentId, $businessId]);
    $full = UPLOAD_PATH . '/' . $doc['file_path'];
    if (is_file($full)) {
        @unlink($full);
    }
    sync_business_document_file($businessId);
    return true;
}

/** Map of business_id => list of documents (for admin list). */
function business_documents_map(array $businessIds): array
{
    ensure_business_documents_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $businessIds))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, business_id, file_path, original_name, created_at
         FROM business_documents WHERE business_id IN ($placeholders)
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['business_id']][] = $row;
    }
    return $map;
}

/**
 * Ensure guide_documents table exists; migrate legacy tour_guide_profiles.credential_file rows.
 */
function ensure_guide_documents_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS guide_documents (
              id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
              guide_id INT UNSIGNED NOT NULL,
              file_path VARCHAR(255) NOT NULL,
              original_name VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_guide_docs (guide_id),
              CONSTRAINT fk_guide_doc FOREIGN KEY (guide_id) REFERENCES tour_guide_profiles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );
        $cols = db()->query('SHOW COLUMNS FROM tour_guide_profiles')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('credential_file', $cols, true)) {
            $legacy = db()->query(
                "SELECT id, credential_file FROM tour_guide_profiles
                 WHERE credential_file IS NOT NULL AND credential_file <> ''
                   AND NOT EXISTS (
                     SELECT 1 FROM guide_documents d
                     WHERE d.guide_id = tour_guide_profiles.id AND d.file_path = tour_guide_profiles.credential_file
                   )"
            )->fetchAll();
            $ins = db()->prepare(
                'INSERT INTO guide_documents (guide_id, file_path, original_name) VALUES (?,?,?)'
            );
            foreach ($legacy as $row) {
                $ins->execute([(int) $row['id'], $row['credential_file'], basename((string) $row['credential_file'])]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return list<array{id:int,guide_id:int,file_path:string,original_name:?string,created_at:string}> */
function guide_documents(int $guideId): array
{
    ensure_guide_documents_schema();
    $stmt = db()->prepare(
        'SELECT id, guide_id, file_path, original_name, created_at
         FROM guide_documents WHERE guide_id=? ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$guideId]);
    return $stmt->fetchAll() ?: [];
}

/** @return int Number of files saved */
function save_guide_documents(int $guideId, array $filesField): int
{
    ensure_guide_documents_schema();
    if (!isset($filesField['name'])) {
        return 0;
    }

    $items = [];
    if (!is_array($filesField['name'])) {
        $items[] = $filesField;
    } else {
        foreach ($filesField['name'] as $i => $name) {
            if ($name === '' || $name === null) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'type' => $filesField['type'][$i] ?? '',
                'tmp_name' => $filesField['tmp_name'][$i] ?? '',
                'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $filesField['size'][$i] ?? 0,
            ];
        }
    }

    $ins = db()->prepare(
        'INSERT INTO guide_documents (guide_id, file_path, original_name) VALUES (?,?,?)'
    );
    $saved = 0;
    foreach ($items as $one) {
        $path = upload_file($one, 'credentials', business_document_allowed_ext());
        if (!$path) {
            continue;
        }
        $ins->execute([$guideId, $path, $one['name'] ?: basename($path)]);
        $saved++;
    }
    if ($saved > 0) {
        sync_guide_credential_file($guideId);
    }
    return $saved;
}

/**
 * Form values + address context for the shared guide profile editor.
 *
 * @return array<string, mixed>
 */
function guide_profile_form_context(array $profileUser, array $profile): array
{
    $guideLockProv = ph_guide_locked_province();
    $addrRegion = trim((string) ($profileUser['address_region'] ?? ''));
    $addrCity = trim((string) ($profileUser['address_city'] ?? ''));
    $addrBarangay = trim((string) ($profileUser['address_barangay'] ?? ''));
    $addrStreet = trim((string) ($profileUser['address_street'] ?? ''));
    $legacyAddress = trim((string) ($profileUser['address'] ?? ''));
    $hasStructured = $addrRegion !== '' && $addrCity !== '' && $addrBarangay !== '';
    $curRegion = $guideLockProv;
    $curCity = trim((string) ($profileUser['current_address_city'] ?? ''));
    $curBarangay = trim((string) ($profileUser['current_address_barangay'] ?? ''));
    $curStreet = trim((string) ($profileUser['current_address_street'] ?? ''));
    if ($curCity === '' && $curBarangay === '' && $curStreet === '') {
        if (strcasecmp($addrRegion, $guideLockProv) === 0) {
            $curCity = $addrCity;
            $curBarangay = $addrBarangay;
            $curStreet = $addrStreet;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $curCity = trim((string) ($_POST['current_address_city'] ?? $curCity));
        $curBarangay = trim((string) ($_POST['current_address_barangay'] ?? $curBarangay));
        $curStreet = trim((string) ($_POST['current_address_street'] ?? $curStreet));
    }
    $gid = (int) ($profile['id'] ?? 0);
    $guideDocs = $gid > 0 ? guide_documents($gid) : [];
    $needsPhoto = trim((string) ($profileUser['avatar'] ?? '')) === '';
    $hasAvail = $gid > 0 && guide_has_weekly_availability($gid);
    $availByDay = guide_weekly_availability_by_day($gid);
    return [
        'guideLockProv' => $guideLockProv,
        'addrRegion' => $addrRegion,
        'addrCity' => $addrCity,
        'addrBarangay' => $addrBarangay,
        'addrStreet' => $addrStreet,
        'legacyAddress' => $legacyAddress,
        'hasStructured' => $hasStructured,
        'curRegion' => $curRegion,
        'curCity' => $curCity,
        'curBarangay' => $curBarangay,
        'curStreet' => $curStreet,
        'sameAsPermanent' => (int) ($profileUser['current_same_as_permanent'] ?? 0) === 1,
        'gid' => $gid,
        'guideDocs' => $guideDocs,
        'needsPhoto' => $needsPhoto,
        'hasAvail' => $hasAvail,
        'availByDay' => $availByDay,
        'bioVal' => (string) ($profile['bio'] ?? ''),
        'langVal' => (string) ($profile['languages'] ?? ''),
        'licenseVal' => (string) ($profile['license_number'] ?? ''),
        'yearsVal' => (string) (int) ($profile['years_experience'] ?? 0),
        'rateVal' => (string) (int) ($profile['hourly_rate'] ?? 0),
        'profileCompleteCheck' => array_merge($profile, ['avatar' => (string) ($profileUser['avatar'] ?? '')]),
        'emergParts' => split_person_name((string) ($profileUser['emergency_contact_name'] ?? '')),
        'emergRel' => (string) ($profileUser['emergency_contact_relationship'] ?? ''),
    ];
}

/**
 * Save a blocked date when the profile form includes one. An empty date is skipped.
 * @return array{saved:bool,error:?string}
 */
function guide_save_posted_block_date(int $guideId): array
{
    $raw = trim((string) ($_POST['block_date'] ?? ''));
    if ($guideId < 1 || $raw === '') {
        return ['saved' => false, 'error' => null];
    }
    $blockDate = normalize_tour_date($raw);
    if ($blockDate === null) {
        return ['saved' => false, 'error' => 'Choose a valid date to block.'];
    }
    $reason = trim((string) ($_POST['block_reason'] ?? $_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Unavailable';
    }
    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }
    $exists = db()->prepare(
        'SELECT id FROM guide_calendar_blocks WHERE guide_id=? AND block_date=? AND start_time IS NULL LIMIT 1'
    );
    $exists->execute([$guideId, $blockDate]);
    $existingId = (int) $exists->fetchColumn();
    if ($existingId > 0) {
        db()->prepare('UPDATE guide_calendar_blocks SET reason=? WHERE id=? AND guide_id=?')
            ->execute([$reason, $existingId, $guideId]);
    } else {
        db()->prepare('INSERT INTO guide_calendar_blocks (guide_id, block_date, reason) VALUES (?,?,?)')
            ->execute([$guideId, $blockDate, $reason]);
    }
    return ['saved' => true, 'error' => null];
}

function guide_profile_apply_post(array $profileUser, array $profile, array $dayLabels, string $redirectUrl, bool $requirePhoto = true): void
{
    $action = (string) ($_POST['action'] ?? 'update');
    $gid = (int) ($profile['id'] ?? 0);
    $userId = (int) ($profileUser['id'] ?? 0);
    if ($gid < 1 || $userId < 1) {
        flash('error', 'Guide profile not found.');
        redirect($redirectUrl);
    }

    if ($action === 'delete_document') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        if ($docId && delete_guide_document($docId, $gid)) {
            flash('success', 'Document removed.');
        } else {
            flash('error', 'Document not found.');
        }
        redirect($redirectUrl);
    }

    if ($action === 'upload_documents') {
        $hasFiles = !empty($_FILES['credential_files']['name'])
            && (
                is_array($_FILES['credential_files']['name'])
                    ? implode('', (array) $_FILES['credential_files']['name']) !== ''
                    : (string) $_FILES['credential_files']['name'] !== ''
            );
        if (!$hasFiles) {
            flash('error', 'Choose one or more files to upload.');
            redirect($redirectUrl);
        }
        $saved = save_guide_documents($gid, $_FILES['credential_files']);
        if ($saved > 0) {
            flash('success', $saved === 1 ? 'Document uploaded.' : $saved . ' documents uploaded.');
        } else {
            flash('error', 'Could not upload documents. Use PDF, DOC, DOCX, or an image.');
        }
        redirect($redirectUrl);
    }

    $beforeCheck = array_merge($profile, ['avatar' => (string) ($profileUser['avatar'] ?? '')]);
    $wasComplete = guide_profile_is_complete($beforeCheck);

    $bio = trim((string) ($_POST['bio'] ?? ''));
    $languages = trim((string) ($_POST['languages'] ?? ''));
    $years = max(0, (int) ($_POST['years_experience'] ?? 0));
    $rate = (int) ($profile['hourly_rate'] ?? 0);
    if ($rate < 1) {
        $rate = guide_office_daily_rate();
    }
    $license = trim((string) ($_POST['license_number'] ?? ''));
    $first = capitalize_name((string) ($_POST['first_name'] ?? ''));
    $last = capitalize_name((string) ($_POST['last_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $_POST['use_structured_address'] = '1';
    $_POST['require_current_province'] = ph_guide_locked_province();
    $_POST['current_address_region'] = ph_guide_locked_province();
    unset($_POST['address_lock_province']);
    $identity = user_parse_identity($_POST, true);
    if (!$identity['ok']) {
        flash('error', (string) ($identity['message'] ?? 'Please complete your personal details.'));
        redirect($redirectUrl);
    }
    $emerg = user_parse_emergency_contact($_POST, true);
    if (!$emerg['ok']) {
        flash('error', (string) ($emerg['message'] ?? 'Please complete your emergency contact.'));
        redirect($redirectUrl);
    }
    if ($first === '' || $last === '') {
        flash('error', 'First name and last name are required.');
        redirect($redirectUrl);
    }

    $avatar = trim((string) ($profileUser['avatar'] ?? ''));
    $photoUploaded = false;
    if (!empty($_FILES['avatar']['name'])) {
        $up = upload_file($_FILES['avatar'], 'profiles', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if ($up) {
            $avatar = $up;
            $photoUploaded = true;
        } else {
            flash('error', upload_last_error() ?: 'Could not upload profile photo. Use JPG, PNG, GIF, or WebP under 8 MB.');
            redirect($redirectUrl);
        }
    }

    $saved = user_save_identity(
        $userId,
        $identity['data'],
        $first !== '' ? $first : (string) $profileUser['first_name'],
        $last !== '' ? $last : (string) $profileUser['last_name'],
        $phone,
        $avatar !== '' ? $avatar : null
    );
    if (empty($saved['ok'])) {
        flash('error', (string) ($saved['message'] ?? 'Could not save profile details.'));
        redirect($redirectUrl);
    }
    $selfName = join_person_name(
        $first !== '' ? $first : (string) $profileUser['first_name'],
        capitalize_name((string) ($_POST['middle_name'] ?? ($identity['data']['middle_name'] ?? ''))),
        $last !== '' ? $last : (string) $profileUser['last_name'],
        (string) ($profileUser['name_suffix'] ?? '')
    );
    $diff = emergency_contact_differs_from_account(
        ['name' => $selfName, 'phone' => $phone],
        $emerg['data']
    );
    if (!$diff['ok']) {
        flash('error', (string) ($diff['message'] ?? 'Emergency contact cannot match your details.'));
        redirect($redirectUrl);
    }
    user_save_emergency_contact($userId, $emerg['data']);
    db()->prepare(
        'UPDATE tour_guide_profiles SET bio=?, languages=?, years_experience=?, hourly_rate=?, license_number=? WHERE user_id=?'
    )->execute([
        $bio !== '' ? $bio : null,
        $languages !== '' ? $languages : null,
        $years,
        $rate,
        $license !== '' ? $license : null,
        $userId,
    ]);

    sync_guide_credential_file($gid);

    $availResult = guide_save_weekly_availability_from_post($gid, $_POST, $dayLabels);
    $hasAvailNow = guide_has_weekly_availability($gid);
    $blockSave = guide_save_posted_block_date($gid);
    $blockNote = '';
    if (!empty($blockSave['error'])) {
        $blockNote = ' ' . $blockSave['error'];
    } elseif (!empty($blockSave['saved'])) {
        $blockNote = ' Blocked date saved.';
    }

    if ($availResult['errors']) {
        flash('warning', 'Profile saved, but availability needs fixing: ' . implode(' ', $availResult['errors']) . $blockNote);
    } elseif ($languages === '' || $bio === '') {
        flash('warning', 'Saved, but bio and languages are still required.' . $blockNote);
    } elseif ($requirePhoto && $avatar === '') {
        flash('warning', 'Saved. Upload a profile photo so tourists can book you.' . $blockNote);
    } elseif (!$hasAvailNow) {
        flash('warning', 'Saved. Select at least one availability day so tourists can book you.' . $blockNote);
    } else {
        $savedMsg = $photoUploaded ? 'Profile and availability updated with new photo.' : 'Profile and availability saved.';
        flash('success', $savedMsg . $blockNote);
    }

    $afterCheck = [
        'id' => $gid,
        'user_id' => $userId,
        'languages' => $languages,
        'bio' => $bio,
        'hourly_rate' => $rate,
        'avatar' => $avatar,
    ];
    $actor = current_user();
    $selfGuide = $actor
        && (int) ($actor['id'] ?? 0) === $userId
        && (int) ($actor['role_id'] ?? 0) === ROLE_TOUR_GUIDE;
    if ($selfGuide && !$wasComplete && guide_profile_is_complete($afterCheck)) {
        redirect(APP_URL . '/tourguide/dashboard.php');
    }
    redirect($redirectUrl);
}

function sync_guide_credential_file(int $guideId): void
{
    ensure_guide_documents_schema();
    try {
        $cols = db()->query('SHOW COLUMNS FROM tour_guide_profiles')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('credential_file', $cols, true)) {
            return;
        }
        $first = db()->prepare(
            'SELECT file_path FROM guide_documents WHERE guide_id=? ORDER BY created_at ASC, id ASC LIMIT 1'
        );
        $first->execute([$guideId]);
        $path = $first->fetchColumn() ?: null;
        db()->prepare('UPDATE tour_guide_profiles SET credential_file=? WHERE id=?')->execute([$path, $guideId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function delete_guide_document(int $documentId, int $guideId): bool
{
    ensure_guide_documents_schema();
    $stmt = db()->prepare('SELECT * FROM guide_documents WHERE id=? AND guide_id=?');
    $stmt->execute([$documentId, $guideId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return false;
    }
    db()->prepare('DELETE FROM guide_documents WHERE id=? AND guide_id=?')
        ->execute([$documentId, $guideId]);
    $full = UPLOAD_PATH . '/' . $doc['file_path'];
    if (is_file($full)) {
        @unlink($full);
    }
    sync_guide_credential_file($guideId);
    return true;
}

/** Map of guide_id => list of documents (for admin list). */
function guide_documents_map(array $guideIds): array
{
    ensure_guide_documents_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $guideIds))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, guide_id, file_path, original_name, created_at
         FROM guide_documents WHERE guide_id IN ($placeholders)
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['guide_id']][] = $row;
    }
    return $map;
}

function current_user(): ?array
{
    start_app_session();
    ensure_user_identity_schema();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cached = null;
    if ($cached !== null && (int) $cached['id'] === (int) $_SESSION['user_id']) {
        return $cached;
    }

    $stmt = db()->prepare(
        'SELECT u.*, r.name AS role_name FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function full_name(?array $user): string
{
    if (!$user) {
        return 'Guest';
    }
    $parts = [];
    foreach (['first_name', 'middle_name', 'last_name'] as $key) {
        $p = trim((string) ($user[$key] ?? ''));
        if ($p !== '') {
            $parts[] = $p;
        }
    }
    $name = $parts ? implode(' ', $parts) : '';
    $suffix = normalize_name_suffix((string) ($user['name_suffix'] ?? ''));
    if ($suffix !== null && $suffix !== '') {
        $name = $name !== '' ? ($name . ' ' . $suffix) : $suffix;
    }
    return $name !== '' ? $name : 'Guest';
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function admin_list_photo_html(?string $path): string
{
    $path = trim((string) $path);
    if ($path !== '') {
        return '<img class="admin-place-thumb" src="' . e(APP_URL . '/uploads/' . ltrim($path, '/')) . '" alt="">';
    }
    return '<span class="admin-place-thumb admin-place-thumb--empty" aria-hidden="true"><i class="bi bi-image"></i></span>';
}

/**
 * Public URL for a business logo. Falls back to cover, gallery photos, then image documents.
 *
 * @param array<string,mixed> $biz
 * @param list<array<string,mixed>> $photos
 * @param list<array<string,mixed>> $docs
 */
function business_logo_url(array $biz, array $photos = [], array $docs = []): string
{
    $candidates = [
        trim((string) ($biz['logo'] ?? '')),
        trim((string) ($biz['cover_image'] ?? '')),
    ];
    foreach ($photos as $ph) {
        $candidates[] = trim((string) ($ph['image_path'] ?? ''));
    }
    foreach ($docs as $d) {
        $path = trim((string) ($d['file_path'] ?? ''));
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($path !== '' && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $candidates[] = $path;
        }
    }
    $root = defined('UPLOAD_PATH') ? rtrim((string) UPLOAD_PATH, '/\\') : (ROOT_PATH . '/uploads');
    foreach ($candidates as $path) {
        if ($path === '') {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', $path), '/');
        return APP_URL . '/uploads/' . $rel;
    }
    return '';
}

/**
 * Read-only average rating: five stars plus the numeric score.
 *
 * @param 'sm'|'md'|'lg' $size
 */
function rating_stars_html(float|int|string|null $avg, int $count = 0, string $size = 'md'): string
{
    $avg = (float) $avg;
    if ($avg < 0) {
        $avg = 0.0;
    }
    if ($avg > 5) {
        $avg = 5.0;
    }
    $has = $count > 0 || $avg > 0;
    $rounded = round($avg * 2) / 2;
    $mod = $size === 'sm' ? ' ct-rating--sm' : ($size === 'lg' ? ' ct-rating--lg' : '');
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($has && $rounded >= $i) {
            $icon = 'bi-star-fill';
        } elseif ($has && $rounded >= ($i - 0.5)) {
            $icon = 'bi-star-half';
        } else {
            $icon = 'bi-star';
        }
        $stars .= '<i class="bi ' . $icon . '" aria-hidden="true"></i>';
    }
    $num = $has ? number_format($avg, 2) : '—';
    $label = $has
        ? ($count > 0
            ? number_format($avg, 2) . ' out of 5 from ' . $count . ' rating' . ($count === 1 ? '' : 's')
            : number_format($avg, 2) . ' out of 5')
        : 'No ratings yet';
    $countHtml = $count > 0
        ? ' <span class="ct-rating__count">(' . $count . ')</span>'
        : ($has || $size === 'sm' ? '' : ' <span class="ct-rating__none">No ratings</span>');

    return '<span class="ct-rating' . $mod . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
        . '<span class="ct-rating__stars">' . $stars . '</span>'
        . '<span class="ct-rating__num">' . e($num) . '</span>'
        . $countHtml
        . '</span>';
}

function status_badge(string $status): string
{
    $map = [
        'pending'   => 'warning',
        'reviewed'  => 'info',
        'actioned'  => 'success',
        'dismissed' => 'secondary',
        'active'    => 'success',
        'approved'  => 'success',
        'published' => 'success',
        'denied'    => 'danger',
        'rejected'  => 'danger',
        'cancelled' => 'secondary',
        'completed' => 'primary',
        'confirmed' => 'success',
        'no_show' => 'warning',
        'unpaid' => 'warning',
        'paid' => 'success',
        'overdue' => 'danger',
        'suspended' => 'danger',
        'draft'     => 'secondary',
        'inactive'  => 'warning',
        'hidden on map' => 'secondary',
        'archived'  => 'dark',
        'profile not completed' => 'warning',
    ];
    $class = $map[strtolower($status)] ?? 'secondary';
    $label = match (strtolower($status)) {
        'profile not completed' => 'Profile not completed',
        'hidden on map' => 'Hidden on map',
        'no_show' => 'No-show',
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'confirmed' => 'Confirmed',
        'reviewed' => 'Review',
        default => ucfirst($status),
    };
    return '<span class="badge bg-' . $class . '">' . e($label) . '</span>';
}

/**
 * Whether a tour guide has finished profile setup (after approval).
 * Accepts a tour_guide_profiles row or a users row with guide_* aliases.
 * Avatar may be on the same row as `avatar` (from users join) or `guide_avatar`.
 * Weekly availability must also be set.
 */
function guide_profile_is_complete(?array $row, bool $requireRate = true): bool
{
    if (!$row) {
        return false;
    }
    $languages = trim((string) ($row['guide_languages'] ?? $row['languages'] ?? ''));
    $bio = trim((string) ($row['bio'] ?? $row['guide_bio'] ?? ''));
    $rate = (float) ($row['hourly_rate'] ?? $row['guide_hourly_rate'] ?? 0);
    $avatar = trim((string) ($row['avatar'] ?? $row['guide_avatar'] ?? ''));
    if ($languages === '' || $bio === '' || $avatar === '' || ($requireRate && $rate <= 0)) {
        return false;
    }

    $guideId = (int) ($row['guide_id'] ?? $row['guide_profile_id'] ?? 0);
    if ($guideId < 1) {
        // Profile rows have user_id + approval_status; users rows have role_id.
        if (isset($row['user_id']) && array_key_exists('approval_status', $row) && !isset($row['role_id'])) {
            $guideId = (int) ($row['id'] ?? 0);
        } elseif (!empty($row['id']) && isset($row['role_id']) && function_exists('db')) {
            try {
                $st = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=? LIMIT 1');
                $st->execute([(int) $row['id']]);
                $guideId = (int) $st->fetchColumn();
            } catch (Throwable $e) {
                $guideId = 0;
            }
        } else {
            $guideId = (int) ($row['id'] ?? 0);
        }
    }
    if ($guideId < 1) {
        return false;
    }
    if (!function_exists('guide_has_weekly_availability')) {
        return false;
    }
    return guide_has_weekly_availability($guideId);
}

/** SQL fragment: guide profile has required setup fields filled (needs users join for avatar). */
function guide_profile_complete_sql(string $alias = 'g', string $userAlias = 'u'): string
{
    return "TRIM(IFNULL({$alias}.languages,'')) <> ''
        AND TRIM(IFNULL({$alias}.bio,'')) <> ''
        AND {$alias}.hourly_rate > 0
        AND TRIM(IFNULL({$userAlias}.avatar,'')) <> ''
        AND EXISTS (
            SELECT 1 FROM guide_availability ga
            WHERE ga.guide_id = {$alias}.id AND ga.is_available = 1
        )";
}

/** Tourist account has the same required details as registration. */
function tourist_profile_is_complete(?array $row): bool
{
    if (!$row || !empty($row['must_complete_profile'])) {
        return false;
    }
    foreach (['first_name', 'last_name', 'sex', 'date_of_birth', 'nationality', 'languages'] as $key) {
        if (trim((string) ($row[$key] ?? '')) === '') {
            return false;
        }
    }
    $address = trim((string) ($row['address'] ?? ''));
    $structured = trim((string) ($row['address_street'] ?? '')) !== ''
        && trim((string) ($row['address_barangay'] ?? '')) !== ''
        && trim((string) ($row['address_city'] ?? '')) !== ''
        && trim((string) ($row['address_region'] ?? '')) !== '';
    if ($address === '' && !$structured) {
        return false;
    }
    if (trim((string) ($row['emergency_contact_name'] ?? '')) === '') {
        return false;
    }
    if (trim((string) ($row['emergency_contact_relationship'] ?? '')) === '') {
        return false;
    }
    if (trim((string) ($row['emergency_contact_phone'] ?? '')) === '') {
        return false;
    }
    return true;
}

/**
 * Status badge for admin Users list (guides may show pending / profile not completed).
 */
function admin_user_status_badge(array $u): string
{
    if (($u['status'] ?? '') === 'suspended') {
        return status_badge('suspended');
    }
    if ((int) ($u['role_id'] ?? 0) === ROLE_TOURIST) {
        $status = (string) ($u['status'] ?? 'active');
        if ($status === 'rejected' || $status === 'pending') {
            return status_badge($status);
        }
        if (!tourist_profile_is_complete($u)) {
            return status_badge('profile not completed');
        }
        return status_badge($status !== '' ? $status : 'active');
    }
    if ((int) ($u['role_id'] ?? 0) === ROLE_TOUR_GUIDE) {
        $approval = (string) ($u['guide_approval_status'] ?? '');
        if ($approval === 'rejected' || ($u['status'] ?? '') === 'rejected') {
            return status_badge('rejected');
        }
        if ($approval === 'pending' || ($u['status'] ?? '') === 'pending') {
            return status_badge('pending');
        }
        if (($u['status'] ?? '') === 'active' && !guide_profile_is_complete($u, false)) {
            return status_badge('profile not completed');
        }
    }
    return status_badge((string) ($u['status'] ?? ''));
}

/**
 * Clear dismiss flag so the setup modal can show again after login.
 */
function maybe_flag_guide_setup_modal(array $user): void
{
    if ((int) ($user['role_id'] ?? 0) !== ROLE_TOUR_GUIDE) {
        return;
    }
    unset($_SESSION['guide_setup_dismissed'], $_SESSION['show_guide_setup_modal']);
}

function maybe_flag_business_setup_modal(array $user): void
{
    if ((int) ($user['role_id'] ?? 0) !== ROLE_BUSINESS) {
        return;
    }
    unset($_SESSION['business_setup_dismissed']);
}

/**
 * Fetch the logged-in guide profile when a setup prompt is needed.
 * @return array{profile:array,approved:bool}|null
 */
function guide_setup_prompt_state(?array $user): ?array
{
    if (!$user || (int) ($user['role_id'] ?? 0) !== ROLE_TOUR_GUIDE) {
        return null;
    }
    if (!empty($_SESSION['guide_setup_dismissed'])) {
        return null;
    }

    // Password comes first. The profile prompt waits until they reach the dashboard.
    if (function_exists('user_must_change_password') && user_must_change_password($user)) {
        return null;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'profile.php' || $script === 'change-password.php') {
        return null;
    }

    try {
        $st = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=? LIMIT 1');
        $st->execute([(int) $user['id']]);
        $profile = $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }

    if (!$profile) {
        return null;
    }

    $approved = ($profile['approval_status'] ?? '') === 'approved';
    $profileCheck = array_merge($profile, ['avatar' => (string) ($user['avatar'] ?? '')]);
    if ($approved && guide_profile_is_complete($profileCheck, false)) {
        return null;
    }

    // Prompt when not yet approved, or approved but profile incomplete.
    // Daily rate is a Tourism Office tariff, so the guide is not asked to set it.
    if (!$approved || !guide_profile_is_complete($profileCheck, false)) {
        return ['profile' => $profileCheck, 'approved' => $approved];
    }

    return null;
}

/**
 * Bootstrap modal prompting guides to finish profile setup / wait for approval.
 * Returns true when the modal markup was rendered.
 */
function posted_return_to(string $fallback): string
{
    $raw = trim((string) ($_POST['return_to'] ?? ''));
    if ($raw === '') {
        return $fallback;
    }
    $app = rtrim((string) APP_URL, '/');
    if ($raw === $app || str_starts_with($raw, $app . '/')) {
        return $raw;
    }
    if (isset($raw[0]) && $raw[0] === '/' && !str_starts_with($raw, '//')) {
        return $app . $raw;
    }
    return $fallback;
}

function render_unsaved_changes_modal(
    string $title = 'Save your changes?',
    string $text = 'You have unsaved edits on this page. Save them, discard them, or keep editing.'
): void {
    ?>
<div class="modal fade itin-leave-overlay" id="unsavedChangesModal" tabindex="-1" aria-labelledby="unsavedChangesModalLabel" aria-hidden="true" data-bs-backdrop="true">
  <div class="modal-dialog modal-dialog-centered itin-leave-overlay__dialog">
    <div class="modal-content itin-leave-card">
      <div class="itin-leave-card__copy">
        <h2 class="itin-leave-card__title" id="unsavedChangesModalLabel"><?= e($title) ?></h2>
        <p class="itin-leave-card__text"><?= e($text) ?></p>
      </div>
      <div class="itin-leave-card__actions">
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--primary" data-unsaved-save>Save changes</button>
        <button type="button" class="itin-leave-card__btn itin-leave-card__btn--danger" data-unsaved-discard>Discard changes</button>
        <button type="button" class="itin-leave-card__btn" data-bs-dismiss="modal" data-unsaved-keep>Keep editing</button>
      </div>
    </div>
  </div>
</div>
    <?php
}

function render_guide_setup_modal(?array $user): bool
{
    $state = guide_setup_prompt_state($user);
    if (!$state) {
        return false;
    }

    $approved = $state['approved'];
    $complete = guide_profile_is_complete($state['profile']);
    $profileUrl = APP_URL . '/tourguide/profile.php';
    $dismissUrl = APP_URL . '/tourguide/dismiss-setup.php';

    if (!$approved) {
        $title = 'Guide application pending';
        $body = $complete
            ? 'Your application is waiting for Tourism Office approval. You will be notified once it is reviewed.'
            : 'Your application is waiting for approval. You can already complete your guide profile (photo, bio, languages, and weekly availability) so you are ready once approved.';
    } else {
        $title = 'Complete your guide profile';
        $body = 'Your account is approved, but your profile is not completed yet. Add a profile photo, bio, languages, and weekly availability so tourists can find and book you.';
    }
    ?>
<div class="modal fade" id="guideSetupModal" tabindex="-1" aria-labelledby="guideSetupModalLabel" aria-hidden="true" data-bs-backdrop="static" data-guide-setup="<?= $complete ? 'ready' : 'incomplete' ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title fs-5" id="guideSetupModalLabel"><?= e($title) ?></h2>
      </div>
      <div class="modal-body pt-2">
        <p class="mb-0"><?= e($body) ?></p>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a class="btn btn-outline-secondary" href="<?= e($dismissUrl) ?>">Later</a>
        <?php if (!$complete): ?>
          <a class="btn btn-ct" href="<?= e($profileUrl) ?>">Complete profile</a>
        <?php else: ?>
          <button type="button" class="btn btn-ct" data-bs-dismiss="modal" id="guideSetupOkBtn">OK</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
    <?php
    return true;
}

/**
 * Popup when an approved business still needs description, logo, photos, or a map pin.
 * @return array{gaps:list<string>}|null
 */
function business_setup_prompt_state(?array $user): ?array
{
    if (!$user || (int) ($user['role_id'] ?? 0) !== ROLE_BUSINESS) {
        return null;
    }
    if (!empty($_SESSION['business_setup_dismissed'])) {
        return null;
    }
    if (function_exists('user_must_change_password') && user_must_change_password($user)) {
        return null;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'profile.php' || $script === 'change-password.php') {
        return null;
    }
    try {
        $st = db()->prepare('SELECT * FROM businesses WHERE user_id=? LIMIT 1');
        $st->execute([(int) $user['id']]);
        $biz = $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
    if (!$biz || !business_listing_is_approved($biz)) {
        return null;
    }
    $photos = 0;
    try {
        $pc = db()->prepare('SELECT COUNT(*) FROM business_photos WHERE business_id=?');
        $pc->execute([(int) $biz['id']]);
        $photos = (int) $pc->fetchColumn();
    } catch (Throwable $e) {
        $photos = 0;
    }
    $gaps = business_listing_finish_gaps($biz, $photos);
    if (!$gaps) {
        return null;
    }
    return ['gaps' => $gaps];
}

function render_business_setup_modal(?array $user): bool
{
    $state = business_setup_prompt_state($user);
    if (!$state) {
        return false;
    }
    $gaps = $state['gaps'];
    $profileUrl = APP_URL . '/business/profile.php';
    $dismissUrl = APP_URL . '/business/dismiss-setup.php';
    $body = 'Finish your business listing. Still needed: ' . implode(', ', $gaps) . '.';
    ?>
<div class="modal fade" id="businessSetupModal" tabindex="-1" aria-labelledby="businessSetupModalLabel" aria-hidden="true" data-bs-backdrop="static" data-business-setup="incomplete">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title fs-5" id="businessSetupModalLabel">Complete your business listing</h2>
      </div>
      <div class="modal-body pt-2">
        <p class="mb-0"><?= e($body) ?></p>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a class="btn btn-outline-secondary" href="<?= e($dismissUrl) ?>">Later</a>
        <a class="btn btn-ct" href="<?= e($profileUrl) ?>">Complete listing</a>
      </div>
    </div>
  </div>
</div>
    <?php
    return true;
}

require_once __DIR__ . '/revenue.php';
