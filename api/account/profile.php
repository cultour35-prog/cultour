<?php
/**
 * Profile for the mobile app (Bearer token, tourists and tour guides).
 * GET  → { ok, data: profile fields }
 * POST (JSON or multipart with optional `avatar` file) → same validation as the web profile pages.
 * Fields that are not sent keep their stored value.
 */
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = require_api_user();
$role = (int) $user['role_id'];
if (!in_array($role, [ROLE_TOURIST, ROLE_TOUR_GUIDE], true)) {
    json_response(['ok' => false, 'message' => 'Use the website to edit this account.'], 403);
}
ensure_user_identity_schema();
$uid = (int) $user['id'];

$loadUser = static function () use ($uid): array {
    $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $st->execute([$uid]);
    return $st->fetch() ?: [];
};
$loadGuide = static function () use ($uid): ?array {
    $st = db()->prepare('SELECT * FROM tour_guide_profiles WHERE user_id=? LIMIT 1');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
};

$payload = static function (array $u, ?array $g) use ($role): array {
    $keys = [
        'id', 'email', 'first_name', 'middle_name', 'last_name', 'name_suffix', 'phone', 'sex', 'date_of_birth',
        'nationality', 'languages', 'address', 'address_region', 'address_city', 'address_barangay', 'address_street',
        'current_same_as_permanent', 'current_address', 'current_address_region', 'current_address_city',
        'current_address_barangay', 'current_address_street', 'emergency_contact_name',
        'emergency_contact_relationship', 'emergency_contact_phone', 'must_complete_profile',
    ];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $u[$k] ?? null;
    }
    $out['role_id'] = $role;
    $out['avatar_url'] = api_upload_url($u['avatar'] ?? null);
    $out['sex_options'] = user_sex_options();
    if ($g) {
        $out['guide'] = [
            'id' => (int) $g['id'],
            'bio' => (string) ($g['bio'] ?? ''),
            'languages' => (string) ($g['languages'] ?? ''),
            'years_experience' => (int) ($g['years_experience'] ?? 0),
            'daily_rate' => (float) ($g['hourly_rate'] ?? 0),
            'daily_rate_label' => format_money((float) ($g['hourly_rate'] ?? 0)),
            'license_number' => (string) ($g['license_number'] ?? ''),
            'approval_status' => (string) ($g['approval_status'] ?? ''),
            'avg_rating' => round((float) ($g['avg_rating'] ?? 0), 1),
            'total_tours' => (int) ($g['total_tours'] ?? 0),
            'locked_province' => ph_guide_locked_province(),
            'locked_city' => ph_guide_locked_city(),
        ];
    }
    return $out;
};

$u = $loadUser();
$g = $role === ROLE_TOUR_GUIDE ? $loadGuide() : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => true, 'data' => $payload($u, $g)]);
}

$in = api_input();
$form = [
    'role_id' => $role,
    'first_name' => $u['first_name'] ?? '',
    'middle_name' => $u['middle_name'] ?? '',
    'last_name' => $u['last_name'] ?? '',
    'phone' => $u['phone'] ?? '',
    'sex' => $u['sex'] ?? '',
    'date_of_birth' => $u['date_of_birth'] ?? '',
    'nationality' => $u['nationality'] ?? '',
    'languages' => $u['languages'] ?? '',
    'address' => $u['address'] ?? '',
    'address_region' => $u['address_region'] ?? '',
    'address_city' => $u['address_city'] ?? '',
    'address_barangay' => $u['address_barangay'] ?? '',
    'address_street' => $u['address_street'] ?? '',
    'current_same_as_permanent' => (string) ((int) ($u['current_same_as_permanent'] ?? 1)),
    'current_address_region' => $u['current_address_region'] ?? '',
    'current_address_city' => $u['current_address_city'] ?? '',
    'current_address_barangay' => $u['current_address_barangay'] ?? '',
    'current_address_street' => $u['current_address_street'] ?? '',
    'emergency_contact_name' => $u['emergency_contact_name'] ?? '',
    'emergency_contact_relationship' => $u['emergency_contact_relationship'] ?? '',
    'emergency_contact_phone' => $u['emergency_contact_phone'] ?? '',
];
foreach (array_keys($form) as $k) {
    if ($k !== 'role_id' && array_key_exists($k, $in)) {
        $form[$k] = is_scalar($in[$k]) ? (string) $in[$k] : $form[$k];
    }
}
if (trim((string) $form['address_region']) !== '') {
    $form['use_structured_address'] = '1';
}
if ($role === ROLE_TOUR_GUIDE) {
    $form['require_current_province'] = ph_guide_locked_province();
    if ($form['current_same_as_permanent'] === '0') {
        $form['current_address_region'] = ph_guide_locked_province();
    }
}

$identity = user_parse_identity($form, true);
if (!$identity['ok']) {
    json_response(['ok' => false, 'message' => (string) ($identity['message'] ?? 'Please complete your personal details.')], 400);
}
$emerg = user_parse_emergency_contact($form, true);
if (!$emerg['ok']) {
    json_response(['ok' => false, 'message' => (string) ($emerg['message'] ?? 'Please complete your emergency contact.')], 400);
}
$first = capitalize_name((string) $form['first_name']);
$last = capitalize_name((string) $form['last_name']);
if ($first === '' || $last === '') {
    json_response(['ok' => false, 'message' => 'First name and last name are required.'], 400);
}
$selfName = join_person_name($first, capitalize_name((string) $form['middle_name']), $last, (string) ($u['name_suffix'] ?? ''));
$diff = emergency_contact_differs_from_account(['name' => $selfName, 'phone' => (string) $form['phone']], $emerg['data']);
if (!$diff['ok']) {
    json_response(['ok' => false, 'message' => (string) ($diff['message'] ?? 'Emergency contact cannot match your details.')], 400);
}

$avatar = (string) ($u['avatar'] ?? '');
if (!empty($_FILES['avatar']['name'])) {
    $up = upload_file($_FILES['avatar'], 'profiles', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if (!$up) {
        json_response(['ok' => false, 'message' => upload_last_error() ?: 'Could not upload profile photo. Use JPG, PNG, GIF, or WebP under 8 MB.'], 400);
    }
    $avatar = $up;
}

$saved = user_save_identity($uid, $identity['data'], $first, $last, trim((string) $form['phone']), $avatar !== '' ? $avatar : null);
if (empty($saved['ok'])) {
    json_response(['ok' => false, 'message' => (string) ($saved['message'] ?? 'Could not save profile details.')], 400);
}
user_save_emergency_contact($uid, $emerg['data']);

$message = 'Profile updated.';
if ($role === ROLE_TOUR_GUIDE && $g) {
    $bio = array_key_exists('bio', $in) ? trim((string) $in['bio']) : (string) ($g['bio'] ?? '');
    $langs = array_key_exists('guide_languages', $in) ? trim((string) $in['guide_languages']) : (string) ($g['languages'] ?? '');
    $years = array_key_exists('years_experience', $in) ? max(0, (int) $in['years_experience']) : (int) ($g['years_experience'] ?? 0);
    $license = array_key_exists('license_number', $in) ? trim((string) $in['license_number']) : (string) ($g['license_number'] ?? '');
    $rate = (int) ($g['hourly_rate'] ?? 0);
    if ($rate < 1) {
        $rate = guide_office_daily_rate();
    }
    db()->prepare(
        'UPDATE tour_guide_profiles SET bio=?, languages=?, years_experience=?, hourly_rate=?, license_number=? WHERE user_id=?'
    )->execute([$bio !== '' ? $bio : null, $langs !== '' ? $langs : null, $years, $rate, $license !== '' ? $license : null, $uid]);
    if ($langs === '' || $bio === '') {
        $message = 'Saved, but bio and languages are still required.';
    } elseif ($avatar === '') {
        $message = 'Saved. Upload a profile photo so tourists can book you.';
    }
}
if (!empty($u['must_complete_profile'])) {
    db()->prepare('UPDATE users SET must_complete_profile=0 WHERE id=?')->execute([$uid]);
}

json_response(['ok' => true, 'message' => $message, 'data' => $payload($loadUser(), $role === ROLE_TOUR_GUIDE ? $loadGuide() : null)]);
