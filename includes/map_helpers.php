<?php
/**
 * Shared map helpers — Ormoc City explore data + Leaflet asset tags
 */

const ORMOC_LAT = 11.0063;
const ORMOC_LNG = 124.6075;

function map_leaflet_head(): void
{
    echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">' . "\n";
}

function map_leaflet_scripts(): string
{
    $ver = @filemtime(ROOT_PATH . '/assets/js/map.js') ?: time();
    $cfg = json_encode(['appUrl' => APP_URL], JSON_UNESCAPED_SLASHES);
    return '<script>window.CULTOUR=' . $cfg . ';</script>' . "\n"
        . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>' . "\n"
        . '<script src="' . APP_URL . '/assets/js/map.js?v=' . $ver . '"></script>' . "\n";
}

/**
 * Categories for map legend (live).
 */
function map_category_legend_items(): array
{
    ensure_category_pin_colors();
    $rows = db()->query(
        'SELECT id, name, pin_color FROM categories ORDER BY name'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'color' => $r['pin_color'] ?: '#0e8a7a',
            'kind' => 'destination',
        ];
    }
    return $out;
}

/**
 * Business types for map legend / filters (live).
 */
function map_business_type_legend_items(): array
{
    ensure_business_type_pin_colors();
    $rows = db()->query(
        'SELECT id, name, pin_color FROM business_types ORDER BY name'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'color' => $r['pin_color'] ?: '#c4a35a',
            'kind' => 'business',
        ];
    }
    return $out;
}

/**
 * Fingerprint for live polling — changes when categories/pins change.
 */
function map_live_version(): string
{
    ensure_category_pin_colors();
    ensure_business_type_pin_colors();
    $cats = db()->query('SELECT id, name, pin_color FROM categories ORDER BY id')->fetchAll();
    $types = db()->query('SELECT id, name, pin_color FROM business_types ORDER BY id')->fetchAll();
    $dest = db()->query(
        "SELECT id, category_id, latitude, longitude FROM destinations
         WHERE status='published' AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY id"
    )->fetchAll();
    $biz = db()->query(
        "SELECT id, business_type_id, latitude, longitude FROM businesses
         WHERE approval_status='approved' AND status='active'
           AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY id"
    )->fetchAll();
    return md5(json_encode([$cats, $types, $dest, $biz]));
}

/**
 * Published destinations with coordinates for explore maps.
 */
function map_destination_markers(?string $detailBase = '__default__'): array
{
    ensure_category_pin_colors();
    if ($detailBase === '__default__') {
        $detailBase = APP_URL . '/tourist/destination.php?id=';
    }
    $rows = db()->query(
        "SELECT d.id, d.category_id, d.name, d.location, d.latitude, d.longitude,
                c.name AS category_name, c.pin_color
         FROM destinations d
         JOIN categories c ON c.id = d.category_id
         WHERE d.status='published' AND d.latitude IS NOT NULL AND d.longitude IS NOT NULL"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $marker = [
            'id' => (int) $r['id'],
            'category_id' => (int) ($r['category_id'] ?? 0),
            'lat' => (float) $r['latitude'],
            'lng' => (float) $r['longitude'],
            'title' => $r['name'],
            'subtitle' => trim(($r['category_name'] ?? '') . ($r['location'] ? ' · ' . $r['location'] : ''), ' ·'),
            'group' => (string) ($r['category_name'] ?? ''),
            'kind' => 'destination',
            'color' => $r['pin_color'] ?: '#0e8a7a',
        ];
        if ($detailBase) {
            $marker['href'] = $detailBase . (int) $r['id'];
        }
        $out[] = $marker;
    }
    return $out;
}

/**
 * Approved businesses with coordinates.
 * Pass null / '' for $detailBase to omit View details links.
 */
function map_business_markers(?string $detailBase = '__default__', bool $readyOnly = false): array
{
    ensure_business_type_pin_colors();
    if ($detailBase === '__default__') {
        $detailBase = APP_URL . '/tourist/business.php?id=';
    }
    $readySql = $readyOnly ? ', ' . business_itinerary_ready_columns() : '';
    $rows = db()->query(
        "SELECT b.id, b.business_type_id, b.name, b.address, b.latitude, b.longitude,
                bt.name AS type_name, bt.pin_color{$readySql}
         FROM businesses b
         JOIN business_types bt ON bt.id = b.business_type_id
         WHERE b.approval_status='approved' AND b.status='active'
           AND b.latitude IS NOT NULL AND b.longitude IS NOT NULL"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($readyOnly && !business_itinerary_ready($r)) {
            continue;
        }
        $marker = [
            'id' => (int) $r['id'],
            'type_id' => (int) ($r['business_type_id'] ?? 0),
            'lat' => (float) $r['latitude'],
            'lng' => (float) $r['longitude'],
            'title' => $r['name'],
            'subtitle' => ($r['type_name'] ?? 'Business') . ($r['address'] ? ' · ' . $r['address'] : ''),
            'group' => (string) ($r['type_name'] ?? 'Business'),
            'kind' => 'business',
            'color' => $r['pin_color'] ?: '#c4a35a',
        ];
        if ($detailBase) {
            $marker['href'] = $detailBase . (int) $r['id'];
        }
        $out[] = $marker;
    }
    return $out;
}

function map_format_km(float $km): string
{
    if ($km < 0.1) {
        return '< 100 m';
    }
    if ($km < 1) {
        return (string) ((int) round($km * 1000)) . ' m';
    }
    return number_format($km, 1) . ' km';
}

/**
 * Rank markers by distance from a point (nearest first).
 * Every place within $maxKm (default 3 km) is kept.
 *
 * @param list<array<string,mixed>> $markers
 * @return list<array<string,mixed>>
 */
function map_rank_nearby(
    array $markers,
    float $lat,
    float $lng,
    int $excludeId = 0,
    float $maxKm = 3.0
): array {
    $ranked = [];
    foreach ($markers as $m) {
        if ($excludeId > 0 && (int) ($m['id'] ?? 0) === $excludeId) {
            continue;
        }
        if (!isset($m['lat'], $m['lng'])) {
            continue;
        }
        $km = itin_haversine_km($lat, $lng, (float) $m['lat'], (float) $m['lng']);
        if ($km > $maxKm) {
            continue;
        }
        $m['km'] = $km;
        $ranked[] = $m;
    }
    usort($ranked, static function ($a, $b) {
        return $a['km'] <=> $b['km'];
    });
    return $ranked;
}

/**
 * Mix destinations and businesses, then sort by km only.
 *
 * @param list<array<string,mixed>> $destinations
 * @param list<array<string,mixed>> $businesses
 * @return list<array<string,mixed>>
 */
function map_nearby_combined(array $destinations, array $businesses): array
{
    $all = array_merge($destinations, $businesses);
    usort($all, static function ($a, $b) {
        return ($a['km'] ?? 0) <=> ($b['km'] ?? 0);
    });
    return $all;
}

/**
 * Legend rows for nearby places (name + kind + distance).
 *
 * @param list<array<string,mixed>> $places
 * @return list<array{name:string,color:string,href:string,sub:string}>
 */
function map_nearby_legend_items(array $places): array
{
    $out = [];
    foreach ($places as $p) {
        $kind = (($p['kind'] ?? '') === 'business') ? 'Business' : 'Destination';
        $group = trim((string) ($p['group'] ?? ''));
        $kmLabel = isset($p['km']) ? map_format_km((float) $p['km']) : '';
        $parts = array_values(array_filter([$kind, $group, $kmLabel], static function ($part) {
            return $part !== '';
        }));
        $out[] = [
            'name' => (string) ($p['title'] ?? ''),
            'color' => (string) ($p['color'] ?? '#0e8a7a'),
            'href' => (string) ($p['href'] ?? ''),
            'sub' => implode(' · ', $parts),
        ];
    }
    return $out;
}

/**
 * Ensure categories.pin_color exists and has defaults (for older databases).
 */
function ensure_category_pin_colors(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM categories LIKE 'pin_color'")->fetch();
        if (!$cols) {
            db()->exec("ALTER TABLE categories ADD COLUMN pin_color VARCHAR(7) NOT NULL DEFAULT '#0e8a7a' AFTER slug");
        }
        // Drop unused icon column if present
        $icon = db()->query("SHOW COLUMNS FROM categories LIKE 'icon'")->fetch();
        if ($icon) {
            db()->exec('ALTER TABLE categories DROP COLUMN icon');
        }
        $bySlug = db()->prepare('UPDATE categories SET pin_color=? WHERE slug=? AND (pin_color IS NULL OR pin_color=\'\' OR pin_color=\'#0e8a7a\')');
        $bySlug->execute(['#0e8a7a', 'natural-attraction']);
        $bySlug->execute(['#c4a35a', 'historical-site']);
        $bySlug->execute(['#2b6cb0', 'beach']);
        $bySlug->execute(['#38a169', 'park']);
        $bySlug->execute(['#9b2c2c', 'cultural']);
    } catch (Throwable $e) {
        // Ignore migration failures on locked schemas
    }
}

/**
 * Ensure business_types.pin_color exists with defaults.
 */
function ensure_business_type_pin_colors(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM business_types LIKE 'pin_color'")->fetch();
        if (!$cols) {
            db()->exec(
                "ALTER TABLE business_types ADD COLUMN pin_color VARCHAR(7) NOT NULL DEFAULT '#c4a35a' AFTER name"
            );
        }
        merge_food_business_types();
        rename_souvenir_business_type();
        $defaults = [
            'Accommodation' => '#2b6cb0',
            'Food and Beverages' => '#dd6b20',
            'Transportation' => '#805ad5',
            'Souvenir and Handicraft' => '#c4a35a',
            'Tour and Travel' => '#38a169',
        ];
        $upd = db()->prepare(
            "UPDATE business_types SET pin_color=? WHERE name=? AND (pin_color IS NULL OR pin_color='' OR pin_color='#c4a35a')"
        );
        foreach ($defaults as $name => $color) {
            $upd->execute([$color, $name]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Coffee Shop and Restaurant are one listing type. */
function merge_food_business_types(): void
{
    $rows = db()->query('SELECT id, name FROM business_types')->fetchAll() ?: [];
    $byName = [];
    foreach ($rows as $row) {
        $byName[(string) $row['name']] = (int) $row['id'];
    }
    $foodId = $byName['Food and Beverages'] ?? 0;
    $restaurantId = $byName['Restaurant'] ?? 0;
    $coffeeId = $byName['Coffee Shop'] ?? 0;
    if ($foodId < 1 && $restaurantId < 1 && $coffeeId < 1) {
        db()->prepare('INSERT INTO business_types (name, pin_color) VALUES (?,?)')
            ->execute(['Food and Beverages', '#dd6b20']);
        return;
    }
    if ($foodId < 1) {
        $foodId = $restaurantId > 0 ? $restaurantId : $coffeeId;
        db()->prepare('UPDATE business_types SET name=?, pin_color=? WHERE id=?')
            ->execute(['Food and Beverages', '#dd6b20', $foodId]);
    }
    $move = db()->prepare('UPDATE businesses SET business_type_id=? WHERE business_type_id=?');
    $drop = db()->prepare('DELETE FROM business_types WHERE id=? AND id<>?');
    foreach ([$restaurantId, $coffeeId] as $oldId) {
        if ($oldId < 1 || $oldId === $foodId) {
            continue;
        }
        $move->execute([$foodId, $oldId]);
        $drop->execute([$oldId, $foodId]);
    }
}

/** Souvenir Shop is listed as Souvenir and Handicraft. */
function rename_souvenir_business_type(): void
{
    $rows = db()->query('SELECT id, name FROM business_types')->fetchAll() ?: [];
    $byName = [];
    foreach ($rows as $row) {
        $byName[(string) $row['name']] = (int) $row['id'];
    }
    $newName = 'Souvenir and Handicraft';
    $newId = $byName[$newName] ?? 0;
    $oldId = $byName['Souvenir Shop'] ?? 0;
    if ($newId < 1 && $oldId < 1) {
        return;
    }
    if ($newId < 1) {
        db()->prepare('UPDATE business_types SET name=? WHERE id=?')->execute([$newName, $oldId]);
        return;
    }
    if ($oldId > 0 && $oldId !== $newId) {
        db()->prepare('UPDATE businesses SET business_type_id=? WHERE business_type_id=?')
            ->execute([$newId, $oldId]);
        db()->prepare('DELETE FROM business_types WHERE id=?')->execute([$oldId]);
    }
}

function normalize_pin_color(?string $color): string
{
    $color = trim((string) $color);
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return strtolower($color);
    }
    return '#0e8a7a';
}

/**
 * Seed / repair Ormoc landmark coordinates when missing (by name match).
 */
function ensure_destination_coords(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $known = [
        'Lake Danao' => [11.0727, 124.7007],
        'Tongonan' => [11.1150, 124.6750],
        'Ormoc City Plaza' => [11.0065, 124.6075],
        'Port of Ormoc' => [11.0039142, 124.6058115],
        'Alto Peak' => [11.0900, 124.7300],
        'Baybayon Beach' => [10.9850, 124.5950],
        'Puente Mabini' => [11.0080, 124.6100],
    ];
    $stmt = db()->prepare(
        'UPDATE destinations SET latitude=?, longitude=? WHERE latitude IS NULL AND name LIKE ?'
    );
    foreach ($known as $name => [$lat, $lng]) {
        $stmt->execute([$lat, $lng, '%' . $name . '%']);
    }
}
