<?php
/**
 * API bootstrap helpers
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/mobile_api.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function api_input(): array
{
    $json = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (is_array($json) && $json) {
        return $json;
    }
    return array_merge($_GET, $_POST);
}

function api_bearer_user(): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(\w+)/', $header, $m)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.*, r.name AS role_name FROM api_tokens t
         JOIN users u ON u.id=t.user_id
         JOIN roles r ON r.id=u.role_id
         WHERE t.token=? AND t.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$m[1]]);
    return $stmt->fetch() ?: null;
}

function require_api_user(): array
{
    $user = api_bearer_user();
    if (!$user) {
        json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
    }
    if (!function_exists('user_is_suspended')) {
        require_once __DIR__ . '/../includes/suspension.php';
    }
    if (!function_exists('registration_approval_gate')) {
        require_once __DIR__ . '/../includes/auth.php';
    }
    if (user_is_suspended($user)) {
        json_response(['ok' => false, 'message' => 'Your account is suspended.', 'suspended' => true], 403);
    }
    $status = (string) ($user['status'] ?? '');
    if ($status !== 'active' && !($status === 'pending' && (int) ($user['role_id'] ?? 0) === ROLE_TOURIST)) {
        json_response(['ok' => false, 'message' => 'Account not active'], 403);
    }
    $gate = registration_approval_gate($user);
    if (!$gate['ok']) {
        json_response(['ok' => false, 'message' => (string) ($gate['message'] ?? 'Account not approved.')], 403);
    }
    if (function_exists('user_must_change_password') && user_must_change_password($user)) {
        json_response(['ok' => false, 'message' => 'Please set a new password to continue.', 'must_change_password' => true], 403);
    }
    return $user;
}

/** Public site origin reachable by the current client (not always APP_URL localhost). */
function api_public_origin(): string
{
    $app = rtrim((string) APP_URL, '/');
    $appHost = strtolower((string) (parse_url($app, PHP_URL_HOST) ?: ''));
    $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $appIsLoopback = in_array($appHost, ['localhost', '127.0.0.1', '::1'], true);
    $reqIsLoopback = $reqHost !== '' && (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:|$)/i', $reqHost);
    if ($appIsLoopback && $reqHost !== '' && !$reqIsLoopback) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $path = rtrim((string) (parse_url($app, PHP_URL_PATH) ?: ''), '/');
        return $scheme . '://' . $reqHost . $path;
    }
    return $app;
}

function api_upload_url(?string $path): ?string
{
    $path = str_replace('\\', '/', trim((string) $path));
    if ($path === '') {
        return null;
    }
    $origin = api_public_origin();
    if (preg_match('#^https?://#i', $path)) {
        $parts = parse_url($path);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['localhost', '127.0.0.1', '::1', '10.0.2.2'], true)) {
            return $path;
        }
        $o = parse_url($origin);
        $port = isset($o['port']) ? ':' . $o['port'] : '';
        $url = ($o['scheme'] ?? 'http') . '://' . ($o['host'] ?? 'localhost') . $port . ($parts['path'] ?? '');
        if (!empty($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        return $url;
    }
    $rel = ltrim($path, '/');
    $rel = preg_replace('#^cultour/+#i', '', $rel) ?? $rel;
    $rel = preg_replace('#^uploads/+#i', '', $rel) ?? $rel;
    return $origin . '/uploads/' . ltrim($rel, '/');
}

/** Session cookie first (web), then Bearer token (Flutter). */
function api_resolve_user(): ?array
{
    if (function_exists('start_app_session') && function_exists('current_user')) {
        start_app_session();
        if (function_exists('try_remember_login')) {
            try_remember_login();
        }
        $sessionUser = current_user();
        if ($sessionUser) {
            return $sessionUser;
        }
    }
    return api_bearer_user();
}

function api_issue_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute([$userId, $token]);
    return $token;
}

function api_public_user(array $user): array
{
    unset($user['password_hash'], $user['remember_token'], $user['reset_token'], $user['verification_token']);
    $user['avatar_url'] = api_upload_url($user['avatar'] ?? null);
    return $user;
}

function api_linked_payload(array $src, int $targetRoleId, string $token, array $extra = []): array
{
    $payload = register_payload_from_linked_source($src, $targetRoleId, $token);
    $payload['api_linked'] = 1;

    $region = trim((string) ($payload['address_region'] ?? ''));
    $city = trim((string) ($payload['address_city'] ?? ''));
    $barangay = trim((string) ($payload['address_barangay'] ?? ''));
    $street = trim((string) ($payload['address_street'] ?? ''));
    $structuredOk = $street !== '' && ph_address_is_valid($region, $city, $barangay);
    if ($targetRoleId === ROLE_TOUR_GUIDE && $structuredOk && (
        strcasecmp($region, ph_guide_locked_province()) !== 0
        || strcasecmp($city, ph_guide_locked_city()) !== 0
    )) {
        $structuredOk = false;
    }

    if ($structuredOk) {
        $payload['use_structured_address'] = '1';
        $payload['current_same_as_permanent'] = '1';
        if ($targetRoleId === ROLE_TOUR_GUIDE) {
            $payload['require_current_province'] = ph_guide_locked_province();
            $payload['current_address_region'] = ph_guide_locked_province();
        }
    } else {
        unset(
            $payload['use_structured_address'],
            $payload['require_current_province'],
            $payload['address_region'],
            $payload['address_city'],
            $payload['address_barangay'],
            $payload['address_street'],
            $payload['current_address_region'],
            $payload['current_address_city'],
            $payload['current_address_barangay'],
            $payload['current_address_street']
        );
        $fallback = trim((string) ($src['address'] ?? ''));
        if ($fallback === '') {
            $fallback = trim("$street, $barangay, $city, $region", " ,");
        }
        $payload['address'] = $fallback !== '' ? $fallback : 'Ormoc City, Leyte';
        $payload['current_same_as_permanent'] = '1';
    }

    if ($targetRoleId === ROLE_TOUR_GUIDE) {
        $payload['years_experience'] = max(0, (int) ($extra['years_experience'] ?? 0));
        $langs = trim((string) ($extra['languages'] ?? ''));
        if ($langs !== '') {
            $payload['languages'] = $langs;
            $payload['tourist_languages'] = $langs;
        }
    }
    if (trim((string) ($payload['sex'] ?? '')) === '') {
        $payload['sex'] = 'prefer_not';
    }
    return $payload;
}

function api_decorate_place(array $row, string $imageKey = 'cover_image'): array
{
    $row['cover_url'] = api_upload_url($row[$imageKey] ?? null);
    $row['avatar_url'] = api_upload_url($row['avatar'] ?? null);
    if (isset($row['latitude'])) {
        $row['latitude'] = $row['latitude'] !== null ? (float) $row['latitude'] : null;
    }
    if (isset($row['longitude'])) {
        $row['longitude'] = $row['longitude'] !== null ? (float) $row['longitude'] : null;
    }
    return $row;
}
