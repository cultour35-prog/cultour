<?php
/**
 * Seed Ormoc City destinations + local businesses (with coords, hours, nearby attractions).
 *
 * Usage (CLI):  C:\xampp\php\php.exe seed_ormoc.php
 * Or open in browser while logged out is fine (script is standalone).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = db();
ensure_place_schedule_schema();

$isCli = PHP_SAPI === 'cli';
$out = static function (string $msg) use ($isCli): void {
    if ($isCli) {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
};

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font:14px/1.45 Consolas,monospace;padding:1rem">';
}

$adminId = (int) ($pdo->query("SELECT id FROM users WHERE role_id=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
$bizOwnerId = (int) ($pdo->query("SELECT id FROM users WHERE role_id=4 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($bizOwnerId < 1) {
    // Create a business owner if missing
    $hash = password_hash('Password123!', PASSWORD_DEFAULT);
    $pdo->prepare(
        "INSERT INTO users (role_id, email, password_hash, first_name, last_name, phone, status, email_verified_at)
         VALUES (4, 'business@example.com', ?, 'Maria', 'Santos', '09201234567', 'active', NOW())"
    )->execute([$hash]);
    $bizOwnerId = (int) $pdo->lastInsertId();
    $out('Created business user business@example.com / Password123!');
}

$cats = [];
foreach ($pdo->query('SELECT id, slug FROM categories') as $r) {
    $cats[$r['slug']] = (int) $r['id'];
}
$types = [];
foreach ($pdo->query('SELECT id, name FROM business_types') as $r) {
    $types[strtolower($r['name'])] = (int) $r['id'];
}

$weekHours = static function (string $slot): string {
    return json_encode([
        'mon' => $slot, 'tue' => $slot, 'wed' => $slot, 'thu' => $slot,
        'fri' => $slot, 'sat' => $slot, 'sun' => $slot,
    ], JSON_UNESCAPED_UNICODE);
};

/**
 * Destinations — real Ormoc / Leyte tourism spots (approx. map pins).
 * nearby_attractions lists sister places for the detail page.
 */
$destinations = [
    [
        'slug' => 'lake-danao',
        'category' => 'natural-attraction',
        'name' => 'Lake Danao Natural Park',
        'location' => 'Lake Danao, Ormoc City, Leyte',
        'lat' => 11.0727, 'lng' => 124.7007,
        'description' => 'Cool crater lake in the mountains of Ormoc — kayaking, camping, picnic grounds, and misty highland views.',
        'history' => 'A volcanic crater lake and flagship eco-tourism destination of Ormoc City.',
        'hours' => '06:00-17:00',
        'fee' => 50,
        'visit' => 120,
        'tips' => 'Bring a light jacket; it is cooler at elevation. Arrive early on weekends for kayak rentals.',
        'nearby' => 'Tongonan Hot Springs, Alto Peak Trail, Lake Danao Viewpoint',
        'emergency' => 'Park Rangers / City Tourism Office / 911',
        'featured' => 1,
    ],
    [
        'slug' => 'tongonan-hot-springs',
        'category' => 'natural-attraction',
        'name' => 'Tongonan Hot Springs',
        'location' => 'Tongonan, Ormoc City, Leyte',
        'lat' => 11.1150, 'lng' => 124.6750,
        'description' => 'Geothermal hot spring pools near Ormoc’s energy corridor — a short wellness day trip from the city.',
        'history' => 'Tied to Leyte’s geothermal heritage and renewable energy industry.',
        'hours' => '07:00-18:00',
        'fee' => 30,
        'visit' => 90,
        'tips' => 'Wear water shoes. Supervise children near pools.',
        'nearby' => 'Lake Danao Natural Park, Tongonan Geothermal Field viewpoints',
        'emergency' => 'Barangay Hall / Tourism Office',
        'featured' => 1,
    ],
    [
        'slug' => 'ormoc-baywalk',
        'category' => 'park',
        'name' => 'Ormoc Baywalk',
        'location' => 'Downtown Ormoc waterfront, Ormoc City',
        'lat' => 11.0060, 'lng' => 124.6055,
        'description' => 'Bayfront promenade for sunset walks, street food, and views over Ormoc Bay.',
        'history' => 'Public leisure waterfront celebrating Ormoc’s coastal identity.',
        'hours' => '05:00-22:00',
        'fee' => 0,
        'visit' => 60,
        'tips' => 'Best at sunset. Keep valuables secure in crowded spots.',
        'nearby' => 'Centennial Park, Ormoc City Port, Downtown shops',
        'emergency' => 'PNP Ormoc / City Tourism Office',
        'featured' => 1,
    ],
    [
        'slug' => 'centennial-park',
        'category' => 'park',
        'name' => 'Centennial Park',
        'location' => 'Ormoc City Proper',
        'lat' => 11.0095, 'lng' => 124.6078,
        'description' => 'Green urban park for families, jogs, and community events in the city center.',
        'history' => 'Named for historical milestones celebrated by the city.',
        'hours' => '05:00-21:00',
        'fee' => 0,
        'visit' => 45,
        'tips' => 'Great for morning exercise. Check Tourism Office for event schedules.',
        'nearby' => 'Ormoc Baywalk, City Hall area, Downtown cafés',
        'emergency' => 'City Hall Hotline / PNP Ormoc',
        'featured' => 1,
    ],
    [
        'slug' => 'ormoc-city-port',
        'category' => 'cultural',
        'name' => 'Ormoc City Port',
        'location' => 'Port of Ormoc, Ormoc City, Leyte',
        'lat' => 11.0054, 'lng' => 124.6072,
        'description' => 'Main ferry and meetup gateway for CulTour itineraries — start of many city tours.',
        'history' => 'Historic port linking Leyte to Cebu and neighboring islands.',
        'hours' => '05:00-20:00',
        'fee' => 0,
        'visit' => 30,
        'tips' => 'Arrive early for ferry schedules. This is the default meetup for CulTour tours.',
        'nearby' => 'Ormoc Baywalk, Downtown Ormoc, transport terminals',
        'emergency' => 'Port Police / PNP Ormoc / 911',
        'featured' => 1,
    ],
    [
        'slug' => 'alto-peak',
        'category' => 'natural-attraction',
        'name' => 'Alto Peak Trail Area',
        'location' => 'Ormoc highland trail corridor, Leyte',
        'lat' => 11.0900, 'lng' => 124.7300,
        'description' => 'Mountain trail area near the Ormoc highlands — for experienced hikers seeking ridge views.',
        'history' => 'Part of the volcanic highland landscape surrounding Lake Danao.',
        'hours' => '06:00-15:00',
        'fee' => 0,
        'visit' => 180,
        'tips' => 'Hire a local guide. Start early; trails can be steep and weather changes fast.',
        'nearby' => 'Lake Danao Natural Park, highland farms',
        'emergency' => 'Local guides / Barangay responders / 911',
        'featured' => 0,
    ],
    [
        'slug' => 'puertobelo-beach',
        'category' => 'park',
        'name' => 'Puertobelo Beach Area',
        'location' => 'Coastal Ormoc / nearby beach strip, Leyte',
        'lat' => 10.9800, 'lng' => 124.5900,
        'description' => 'Quiet coastal stretch south of the city for swimming and seaside meals on calmer days.',
        'history' => 'Local beach getaway popular with Ormocnons on weekends.',
        'hours' => '06:00-18:00',
        'fee' => 20,
        'visit' => 120,
        'tips' => 'Check tide and weather. Bring sun protection.',
        'nearby' => 'Ormoc Baywalk, coastal seafood eateries',
        'emergency' => 'Barangay Hall / Coast Guard / 911',
        'featured' => 0,
    ],
    [
        'slug' => 'pineapple-festival-grounds',
        'category' => 'cultural',
        'name' => 'Pineapple Festival Grounds',
        'location' => 'Ormoc City festival area',
        'lat' => 11.0120, 'lng' => 124.6095,
        'description' => 'Cultural venue linked to Ormoc’s Pineapple Festival and community heritage events.',
        'history' => 'Highlights Ormoc agricultural pride and city festivities.',
        'hours' => '08:00-20:00',
        'fee' => 0,
        'visit' => 60,
        'tips' => 'Confirm festival calendar with the Tourism Office before visiting.',
        'nearby' => 'Centennial Park, Downtown Ormoc, souvenir shops',
        'emergency' => 'Tourism Office / PNP Ormoc',
        'featured' => 0,
    ],
    [
        'slug' => 'ormoc-city-hall-plaza',
        'category' => 'historical-site',
        'name' => 'Ormoc City Hall Plaza',
        'location' => 'City Hall area, Ormoc City Proper',
        'lat' => 11.0112, 'lng' => 124.6070,
        'description' => 'Civic plaza and landmark area for photos, orientations, and short heritage walks.',
        'history' => 'Administrative heart of Ormoc City.',
        'hours' => '08:00-17:00',
        'fee' => 0,
        'visit' => 40,
        'tips' => 'Combine with Centennial Park and Baywalk on a downtown half-day.',
        'nearby' => 'Centennial Park, Ormoc Baywalk, Downtown cafés',
        'emergency' => 'City Hall / PNP Ormoc',
        'featured' => 0,
    ],
    [
        'slug' => 'sabin-resort-area',
        'category' => 'park',
        'name' => 'Sabin Resort Beachfront Area',
        'location' => 'Brgy. Combado / coastal Ormoc area',
        'lat' => 11.0205, 'lng' => 124.5750,
        'description' => 'Popular resort and beachfront zone west of downtown for swimming and leisure stays.',
        'history' => 'Long-time leisure destination for visitors to Ormoc.',
        'hours' => '08:00-18:00',
        'fee' => 100,
        'visit' => 150,
        'tips' => 'Day-use policies vary by property — confirm entrance rules on arrival.',
        'nearby' => 'Ormoc Baywalk, downtown dining',
        'emergency' => 'Resort security / PNP Ormoc / 911',
        'featured' => 1,
    ],
];

$destUpsert = $pdo->prepare(
    "INSERT INTO destinations
     (category_id, name, slug, location, latitude, longitude, description, history, opening_hours,
      default_visit_minutes, entrance_fee, travel_tips, nearby_attractions, emergency_contacts,
      is_featured, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?)
     ON DUPLICATE KEY UPDATE
       category_id=VALUES(category_id),
       name=VALUES(name),
       location=VALUES(location),
       latitude=VALUES(latitude),
       longitude=VALUES(longitude),
       description=VALUES(description),
       history=VALUES(history),
       opening_hours=VALUES(opening_hours),
       default_visit_minutes=VALUES(default_visit_minutes),
       entrance_fee=VALUES(entrance_fee),
       travel_tips=VALUES(travel_tips),
       nearby_attractions=VALUES(nearby_attractions),
       emergency_contacts=VALUES(emergency_contacts),
       is_featured=VALUES(is_featured),
       status='published'"
);

$destCount = 0;
foreach ($destinations as $d) {
    $catId = $cats[$d['category']] ?? ($cats['natural-attraction'] ?? 1);
    $destUpsert->execute([
        $catId,
        $d['name'],
        $d['slug'],
        $d['location'],
        $d['lat'],
        $d['lng'],
        $d['description'],
        $d['history'],
        $d['hours'],
        $d['visit'],
        $d['fee'],
        $d['tips'],
        $d['nearby'],
        $d['emergency'],
        $d['featured'],
        $adminId,
    ]);
    $destCount++;
}
$out("Destinations upserted: {$destCount}");

/**
 * Businesses — hotels, restaurants, cafés, transport near Ormoc.
 * Attached to business owner user; approved + active with map pins.
 */
$businesses = [
    [
        'slug' => 'ormoc-bay-inn',
        'type' => 'hotel',
        'name' => 'Ormoc Bay Inn',
        'address' => 'Real St., Downtown Ormoc City',
        'lat' => 11.0072, 'lng' => 124.6085,
        'description' => 'Budget-friendly hotel near downtown and the Baywalk.',
        'phone' => '053-123-4567',
        'email' => 'info@ormocbayinn.example',
        'hours' => $weekHours('08:00-22:00'),
        'visit' => 30,
        'services' => [
            ['Standard Room', 'Air-conditioned room for 2', 1500],
            ['Airport / Port Transfer', 'Local pickup within Ormoc', 300],
        ],
    ],
    [
        'slug' => 'sabin-resort-hotel',
        'type' => 'resort',
        'name' => 'Sabin Resort Hotel',
        'address' => 'Brgy. Combado, Ormoc City',
        'lat' => 11.0210, 'lng' => 124.5740,
        'description' => 'Beachfront resort with pools and dining west of downtown Ormoc.',
        'phone' => '053-255-5555',
        'email' => 'stay@sabinresort.example',
        'hours' => $weekHours('07:00-22:00'),
        'visit' => 60,
        'services' => [
            ['Day Pass', 'Pool / beach day use (when available)', 500],
            ['Seafood Lunch', 'Set lunch by the bay', 450],
        ],
    ],
    [
        'slug' => 'don-felipe-hotel',
        'type' => 'hotel',
        'name' => 'Don Felipe Hotel',
        'address' => 'Bonifacio St., Ormoc City Proper',
        'lat' => 11.0088, 'lng' => 124.6092,
        'description' => 'City hotel convenient for business travelers and downtown sightseeing.',
        'phone' => '053-255-2468',
        'email' => 'front@donfelipe.example',
        'hours' => $weekHours('00:00-23:59'),
        'visit' => 20,
        'services' => [
            ['Deluxe Room', 'Queen bed, breakfast optional', 2200],
        ],
    ],
    [
        'slug' => 'zuzane-resto',
        'type' => 'food and beverages',
        'name' => 'Zuzane Restaurant',
        'address' => 'Downtown Ormoc City',
        'lat' => 11.0080, 'lng' => 124.6080,
        'description' => 'Popular local restaurant for Filipino favorites and group meals.',
        'phone' => '053-255-1001',
        'email' => 'hello@zuzane.example',
        'hours' => $weekHours('10:00-21:00'),
        'visit' => 75,
        'services' => [
            ['Family Platter', 'Shared meals for 4–6', 899],
            ['Iced Tea Pitcher', 'Refillable pitcher', 120],
        ],
    ],
    [
        'slug' => 'baybayon-seafood',
        'type' => 'food and beverages',
        'name' => 'Baybayon Seafood Grill',
        'address' => 'Near Ormoc Baywalk, Ormoc City',
        'lat' => 11.0055, 'lng' => 124.6060,
        'description' => 'Casual seafood grill with bay breeze — good stop after a Baywalk stroll.',
        'phone' => '0917-555-0102',
        'email' => 'orders@baybayon.example',
        'hours' => $weekHours('11:00-21:00'),
        'visit' => 90,
        'services' => [
            ['Grilled Catch', 'Market-price grilled fish', 350],
            ['Kinilaw', 'Fresh ceviche-style appetizer', 220],
        ],
    ],
    [
        'slug' => 'brew-ormoc-cafe',
        'type' => 'food and beverages',
        'name' => 'Brew Ormoc Café',
        'address' => 'Aviles St., Ormoc City Proper',
        'lat' => 11.0100, 'lng' => 124.6088,
        'description' => 'Specialty coffee and light bites — ideal mid-tour break downtown.',
        'phone' => '0918-555-0203',
        'email' => 'hi@brewormoc.example',
        'hours' => $weekHours('07:00-20:00'),
        'visit' => 40,
        'services' => [
            ['Espresso / Latte', 'House coffee drinks', 120],
            ['Pastry Set', 'Coffee + pastry', 180],
        ],
    ],
    [
        'slug' => 'ormoc-souvenir-corner',
        'type' => 'souvenir and handicraft',
        'name' => 'Ormoc Souvenir Corner',
        'address' => 'Near Centennial Park, Ormoc City',
        'lat' => 11.0098, 'lng' => 124.6075,
        'description' => 'Local crafts, pineapple products, and Leyte pasalubong.',
        'phone' => '0919-555-0304',
        'email' => 'shop@ormocsouvenir.example',
        'hours' => $weekHours('09:00-19:00'),
        'visit' => 35,
        'services' => [
            ['Pasalubong Pack', 'Assorted local treats', 350],
            ['Handicraft Gift', 'Woven / carved souvenirs', 250],
        ],
    ],
    [
        'slug' => 'ormoc-van-hire',
        'type' => 'transportation',
        'name' => 'Ormoc Van Hire',
        'address' => 'Near Ormoc City Port terminal area',
        'lat' => 11.0058, 'lng' => 124.6078,
        'description' => 'Van and multicab hire for Lake Danao, Tongonan, and city transfers.',
        'phone' => '0920-555-0405',
        'email' => 'book@ormocvan.example',
        'hours' => $weekHours('06:00-19:00'),
        'visit' => 15,
        'services' => [
            ['Half-day Hire', 'Up to 4 hours within Ormoc', 2500],
            ['Lake Danao Roundtrip', 'Port/hotel pickup to Lake Danao', 3500],
        ],
    ],
    [
        'slug' => 'leyte-travel-desk',
        'type' => 'tour and travel',
        'name' => 'Leyte Travel Desk Ormoc',
        'address' => 'Downtown Ormoc City',
        'lat' => 11.0075, 'lng' => 124.6090,
        'description' => 'Tour packages, ferry booking help, and same-day Ormoc sightseeing arrangements.',
        'phone' => '053-255-7788',
        'email' => 'desk@leytetravel.example',
        'hours' => $weekHours('08:00-18:00'),
        'visit' => 25,
        'services' => [
            ['City Orientation', 'Half-day downtown + Baywalk', 800],
            ['Nature Combo', 'Lake Danao day assistance', 1500],
        ],
    ],
    [
        'slug' => 'highland-view-lodge',
        'type' => 'resort',
        'name' => 'Highland View Lodge',
        'address' => 'Near Lake Danao road, Ormoc highlands',
        'lat' => 11.0680, 'lng' => 124.6900,
        'description' => 'Simple lodge and dining stop en route to Lake Danao.',
        'phone' => '0917-555-0607',
        'email' => 'lodge@highlandview.example',
        'hours' => $weekHours('07:00-20:00'),
        'visit' => 45,
        'services' => [
            ['Day Cottage', 'Picnic cottage rental', 800],
            ['Merienda Set', 'Snacks and drinks', 250],
        ],
    ],
];

$bizSel = $pdo->prepare('SELECT id FROM businesses WHERE slug=? LIMIT 1');
$bizIns = $pdo->prepare(
    "INSERT INTO businesses
     (user_id, business_type_id, name, slug, description, address, latitude, longitude, phone, email,
      opening_hours, default_visit_minutes, approval_status, approved_by, approved_at, status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'approved', ?, NOW(), 'active')"
);
$bizUpd = $pdo->prepare(
    "UPDATE businesses SET
       business_type_id=?, name=?, description=?, address=?, latitude=?, longitude=?, phone=?, email=?,
       opening_hours=?, default_visit_minutes=?, approval_status='approved', approved_by=?, approved_at=NOW(), status='active'
     WHERE id=?"
);
$svcDel = $pdo->prepare('DELETE FROM business_services WHERE business_id=?');
$svcIns = $pdo->prepare(
    'INSERT INTO business_services (business_id, name, description, price, is_active) VALUES (?,?,?,?,1)'
);

$bizCount = 0;
foreach ($businesses as $b) {
    $typeId = $types[$b['type']] ?? ($types['hotel'] ?? 1);
    $bizSel->execute([$b['slug']]);
    $existingId = (int) ($bizSel->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $bizUpd->execute([
            $typeId, $b['name'], $b['description'], $b['address'], $b['lat'], $b['lng'],
            $b['phone'], $b['email'], $b['hours'], $b['visit'], $adminId, $existingId,
        ]);
        $bizId = $existingId;
    } else {
        $bizIns->execute([
            $bizOwnerId, $typeId, $b['name'], $b['slug'], $b['description'], $b['address'],
            $b['lat'], $b['lng'], $b['phone'], $b['email'], $b['hours'], $b['visit'], $adminId,
        ]);
        $bizId = (int) $pdo->lastInsertId();
    }
    $svcDel->execute([$bizId]);
    foreach ($b['services'] as $svc) {
        $svcIns->execute([$bizId, $svc[0], $svc[1], $svc[2]]);
    }
    $bizCount++;
}
$out("Businesses upserted: {$bizCount}");

// Ensure Port meetup coords align with seeded port destination if helper differs slightly
$out('Done. Refresh destinations, businesses, map, and try Auto-generate tour.');

if (!$isCli) {
    echo '</pre>';
    echo '<p><a href="tourist/destinations.php">Destinations</a> · <a href="tourist/businesses.php">Businesses</a> · <a href="map.php">Map</a></p>';
}
