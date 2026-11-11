<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
$in = api_input();
$roleId = (int) ($in['role_id'] ?? ROLE_TOURIST);
$payload = [
    'email' => $in['email'] ?? '',
    'password' => $in['password'] ?? '',
    'password_confirm' => $in['password_confirm'] ?? ($in['password'] ?? ''),
    'first_name' => $in['first_name'] ?? '',
    'last_name' => $in['last_name'] ?? '',
    'middle_name' => $in['middle_name'] ?? '',
    'name_suffix' => resolve_posted_name_suffix(
        !empty($in['owner_suffix_choice']) || !empty($in['owner_suffix_other']) ? 'owner_suffix' : 'name_suffix',
        array_merge($in, [
            'name_suffix' => $in['name_suffix'] ?? ($in['owner_suffix'] ?? ''),
            'owner_suffix' => $in['owner_suffix'] ?? ($in['name_suffix'] ?? ''),
        ])
    ),
    'sex' => $in['sex'] ?? '',
    'date_of_birth' => $in['date_of_birth'] ?? '',
    'nationality' => $in['nationality'] ?? '',
    'tourist_languages' => $in['tourist_languages'] ?? ($in['languages'] ?? ''),
    'languages' => $in['languages'] ?? '',
    'address' => $in['address'] ?? '',
    'emergency_first_name' => $in['emergency_first_name'] ?? '',
    'emergency_middle_name' => $in['emergency_middle_name'] ?? '',
    'emergency_last_name' => $in['emergency_last_name'] ?? '',
    'emergency_suffix' => resolve_posted_name_suffix('emergency_suffix', $in),
    'emergency_relationship' => resolve_posted_emergency_relationship($in),
    'emergency_contact_name' => $in['emergency_contact_name'] ?? '',
    'emergency_contact_phone' => $in['emergency_contact_phone'] ?? ($in['emergency_phone_local'] ?? ''),
    'emergency_phone_local' => $in['emergency_phone_local'] ?? ($in['emergency_contact_phone'] ?? ''),
    'phone' => $in['phone'] ?? '',
    'role_id' => $roleId,
    'credential_files' => $_FILES['resume_files'] ?? [],
    'languages' => $in['languages'] ?? '',
    'years_experience' => $in['years_experience'] ?? '',
    'license_number' => $in['license_number'] ?? '',
    'representative_first_name' => $in['representative_first_name'] ?? '',
    'representative_middle_name' => $in['representative_middle_name'] ?? '',
    'representative_last_name' => $in['representative_last_name'] ?? '',
    'representative_suffix' => resolve_posted_name_suffix('representative_suffix', $in),
    'representative_sex' => $in['representative_sex'] ?? '',
    'owner_first_name' => $in['owner_first_name'] ?? ($in['first_name'] ?? ''),
    'owner_middle_name' => $in['owner_middle_name'] ?? ($in['middle_name'] ?? ''),
    'owner_last_name' => $in['owner_last_name'] ?? ($in['last_name'] ?? ''),
    'owner_suffix' => resolve_posted_name_suffix('owner_suffix', array_merge($in, [
        'owner_suffix' => $in['owner_suffix'] ?? ($in['name_suffix'] ?? ''),
    ])),
    'owner_sex' => $in['owner_sex'] ?? ($in['sex'] ?? ''),
    'business_name' => $in['business_name'] ?? '',
    'business_type_id' => $in['business_type_id'] ?? '',
    'business_description' => $in['business_description'] ?? '',
    'business_address' => $in['business_address'] ?? '',
    'biz_address_city' => $in['biz_address_city'] ?? ($in['address_city'] ?? ''),
    'biz_address_barangay' => $in['biz_address_barangay'] ?? ($in['address_barangay'] ?? ''),
    'biz_address_street' => $in['biz_address_street'] ?? ($in['address_street'] ?? ''),
    'business_phone' => $in['business_phone'] ?? '',
    'business_email' => $in['business_email'] ?? '',
    'permit_number' => $in['permit_number'] ?? '',
    'tax_id' => $in['tax_id'] ?? '',
    'logo_file' => $_FILES['business_logo'] ?? [],
    'photo_files' => $_FILES['business_photos'] ?? [],
    'document_files' => $_FILES['business_documents'] ?? [],
];
$hasStructured = !empty($in['use_structured_address'])
    || trim((string) ($in['address_region'] ?? '')) !== ''
    || trim((string) ($in['address_city'] ?? '')) !== '';
if (($roleId === ROLE_TOURIST || $roleId === ROLE_TOUR_GUIDE) && $hasStructured) {
    $payload['use_structured_address'] = '1';
    $payload['address_region'] = $in['address_region'] ?? '';
    $payload['address_city'] = $in['address_city'] ?? '';
    $payload['address_barangay'] = $in['address_barangay'] ?? '';
    $payload['address_street'] = $in['address_street'] ?? '';
    $sameRaw = $in['current_same_as_permanent'] ?? '0';
    if (is_array($sameRaw)) {
        $sameRaw = end($sameRaw);
    }
    $payload['current_same_as_permanent'] = ((string) $sameRaw !== '0') ? '1' : '0';
    $payload['current_address_city'] = $in['current_address_city'] ?? '';
    $payload['current_address_barangay'] = $in['current_address_barangay'] ?? '';
    $payload['current_address_street'] = $in['current_address_street'] ?? '';
    if ($roleId === ROLE_TOUR_GUIDE) {
        $payload['require_current_province'] = ph_guide_locked_province();
        $payload['current_address_region'] = ph_guide_locked_province();
    } else {
        $payload['current_address_region'] = $in['current_address_region'] ?? '';
    }
    $payload['link_token'] = $in['link_token'] ?? '';
    $payload['link_source_user_id'] = $in['link_source_user_id'] ?? '';
}
$result = register_user($payload);
json_response($result, $result['ok'] ? 201 : 422);
