<?php
/**
 * Philippine address pickers for the mobile app.
 * GET → provinces; ?province= → cities; ?province=&city= → barangays
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ph_address.php';
header('Cache-Control: public, max-age=86400');

$province = trim((string) ($_GET['province'] ?? ''));
$city = trim((string) ($_GET['city'] ?? ''));
if ($province === '') {
    json_response(['ok' => true, 'level' => 'province', 'data' => ph_address_provinces()]);
}
if ($city === '') {
    json_response(['ok' => true, 'level' => 'city', 'data' => ph_address_cities($province)]);
}
json_response(['ok' => true, 'level' => 'barangay', 'data' => ph_address_barangays($province, $city)]);
