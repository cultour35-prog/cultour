<?php
/**
 * Philippines address helpers — province / city / barangay validation + compose.
 * DB column address_region stores the province name.
 */

function ph_address_data_path(): string
{
    return dirname(__DIR__) . '/assets/data/ph-address.json';
}

/**
 * @return array<string, array<string, list<string>>>
 */
function ph_address_tree(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }
    $path = ph_address_data_path();
    if (!is_file($path)) {
        $tree = [];
        return $tree;
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $tree = is_array($decoded) ? $decoded : [];
    return $tree;
}

/** @return list<string> */
function ph_address_provinces(): array
{
    return array_keys(ph_address_tree());
}

/** Tour guides may only pick cities/barangays in this province. */
function ph_guide_locked_province(): string
{
    return 'Leyte';
}

/** Tour guides must currently live in this city. */
function ph_guide_locked_city(): string
{
    return 'Ormoc City';
}

/** Business listings are Leyte-based; city defaults to Ormoc City. */
function ph_business_default_city(): string
{
    return 'Ormoc City';
}

/** @deprecated use ph_address_provinces() */
function ph_address_regions(): array
{
    return ph_address_provinces();
}

/**
 * @return list<string>
 */
function ph_address_cities(string $province): array
{
    $tree = ph_address_tree();
    if (!isset($tree[$province]) || !is_array($tree[$province])) {
        return [];
    }
    return array_keys($tree[$province]);
}

/**
 * @return list<string>
 */
function ph_address_barangays(string $province, string $city): array
{
    $tree = ph_address_tree();
    if (!isset($tree[$province][$city]) || !is_array($tree[$province][$city])) {
        return [];
    }
    return array_values($tree[$province][$city]);
}

function ph_address_is_valid(string $province, string $city, string $barangay): bool
{
    if ($province === '' || $city === '' || $barangay === '') {
        return false;
    }
    $list = ph_address_barangays($province, $city);
    return in_array($barangay, $list, true);
}

function compose_tourist_address(string $province, string $city, string $barangay, string $street): string
{
    $parts = [];
    $street = trim($street);
    if ($street !== '') {
        $parts[] = $street;
    }
    if ($barangay !== '') {
        $parts[] = $barangay;
    }
    if ($city !== '') {
        $parts[] = $city;
    }
    if ($province !== '') {
        $parts[] = $province;
    }
    return implode(', ', $parts);
}

/**
 * Parse structured tourist address fields from a form payload.
 *
 * @param string $prefix Field name prefix ('' or 'current_')
 * @return array{ok:bool,message?:string,data?:array{address_region:string,address_city:string,address_barangay:string,address_street:string,address:string}}
 */
function parse_tourist_structured_address(array $input, bool $required = true, string $prefix = ''): array
{
    $provinceKey = $prefix . 'address_region';
    $cityKey = $prefix . 'address_city';
    $barangayKey = $prefix . 'address_barangay';
    $streetKey = $prefix . 'address_street';
    $label = $prefix === 'current_' ? 'current address' : 'permanent address';

    $province = trim((string) ($input[$provinceKey] ?? ''));
    $city = trim((string) ($input[$cityKey] ?? ''));
    $barangay = trim((string) ($input[$barangayKey] ?? ''));
    $street = trim((string) ($input[$streetKey] ?? ''));

    $any = $province !== '' || $city !== '' || $barangay !== '' || $street !== '';
    if (!$required && !$any) {
        return [
            'ok' => true,
            'data' => [
                'address_region' => '',
                'address_city' => '',
                'address_barangay' => '',
                'address_street' => '',
                'address' => '',
            ],
        ];
    }

    if ($province === '') {
        return ['ok' => false, 'message' => 'Please select a province for your ' . $label . '.'];
    }
    if ($city === '') {
        return ['ok' => false, 'message' => 'Please select a city or municipality for your ' . $label . '.'];
    }
    if ($barangay === '') {
        return ['ok' => false, 'message' => 'Please select a barangay for your ' . $label . '.'];
    }
    if ($street === '') {
        return ['ok' => false, 'message' => 'Please enter your street / house number for your ' . $label . '.'];
    }
    $lockProvince = trim((string) ($input['address_lock_province'] ?? ''));
    if ($lockProvince !== '' && strcasecmp($province, $lockProvince) !== 0) {
        return ['ok' => false, 'message' => 'Province must be ' . $lockProvince . '.'];
    }
    if (!ph_address_is_valid($province, $city, $barangay)) {
        return ['ok' => false, 'message' => 'Please select a valid province, city, and barangay for your ' . $label . '.'];
    }
    if (mb_strlen($street) > 150) {
        return ['ok' => false, 'message' => 'Street address is too long.'];
    }

    return [
        'ok' => true,
        'data' => [
            'address_region' => $province,
            'address_city' => $city,
            'address_barangay' => $barangay,
            'address_street' => $street,
            'address' => compose_tourist_address($province, $city, $barangay, $street),
        ],
    ];
}

/** True when the payload includes tourist structured address fields. */
function input_has_structured_address(array $input): bool
{
    if (!empty($input['use_structured_address'])) {
        return true;
    }
    foreach (['address_region', 'address_city', 'address_barangay', 'address_street'] as $key) {
        if (trim((string) ($input[$key] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}
