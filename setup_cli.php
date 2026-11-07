<?php
/**
 * CLI installer: php setup_cli.php
 */
error_reporting(E_ALL);
$host = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'cultour_db';
$sqlFile = __DIR__ . '/database/cultour_db.sql';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = file_get_contents($sqlFile);
    // Execute multi-statement SQL
    $pdo->exec($sql);
    echo "Schema OK\n";

    $pdo->exec("USE `$dbName`");
    $hash = password_hash('Password123!', PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ?')->execute([$hash]);
    echo "Passwords set to Password123!\n";

    $count = (int)$pdo->query('SELECT COUNT(*) FROM destinations')->fetchColumn();
    if ($count < 2) {
        $pdo->exec("INSERT INTO destinations (category_id, name, slug, location, description, history, opening_hours, entrance_fee, travel_tips, nearby_attractions, emergency_contacts, is_featured, status, created_by) VALUES
        (1, 'Lake Danao', 'lake-danao', 'Lake Danao Natural Park, Ormoc City, Leyte', 'A serene crater lake popular for kayaking and camping.', 'Volcanic crater lake and flagship eco-tourism site.', '6:00 AM – 5:00 PM', 50.00, 'Bring a light jacket.', 'Tongonan Hot Springs', 'Tourism Desk / 911', 1, 'published', 1),
        (2, 'Tongonan Hot Springs', 'tongonan-hot-springs', 'Tongonan, Ormoc City, Leyte', 'Geothermal hot springs near Ormoc energy corridor.', 'Linked to Leyte geothermal heritage.', '7:00 AM – 6:00 PM', 30.00, 'Wear water shoes.', 'Lake Danao', 'Barangay Hall', 1, 'published', 1),
        (3, 'Ormoc Baywalk', 'ormoc-baywalk', 'Downtown Ormoc City Waterfront', 'Bayfront promenade for evening strolls.', 'Public leisure waterfront.', 'Open 24 hours', 0.00, 'Visit at sunset.', 'City Proper', 'PNP Ormoc', 1, 'published', 1),
        (4, 'Centennial Park', 'centennial-park', 'Ormoc City Proper', 'Urban park for families and exercise.', 'City landmark park.', '5:00 AM – 9:00 PM', 0.00, 'Morning jogs recommended.', 'Baywalk', 'City Hall', 0, 'published', 1),
        (5, 'Pineapple Festival Grounds', 'pineapple-festival', 'Ormoc City Festival Area', 'Cultural venue for Pineapple Festival events.', 'Celebration of Ormoc agriculture.', 'Varies by event', 0.00, 'Check Tourism calendar.', 'City Proper', 'Tourism Office', 0, 'published', 1)");
        echo "Destinations seeded\n";
    }

    $g = $pdo->query('SELECT id FROM tour_guide_profiles WHERE user_id=3')->fetch();
    if (!$g) {
        $pdo->exec("INSERT INTO tour_guide_profiles (user_id, bio, languages, years_experience, hourly_rate, license_number, approval_status, approved_by, approved_at)
            VALUES (3, 'Local Ormoc guide for nature and heritage walks.', 'English, Cebuano, Tagalog', 5, 500.00, 'OTG-2024-001', 'approved', 1, NOW())");
        $guideId = (int)$pdo->lastInsertId();
        for ($d = 1; $d <= 5; $d++) {
            $pdo->exec("INSERT INTO guide_availability (guide_id, day_of_week, start_time, end_time) VALUES ($guideId, $d, '08:00:00', '17:00:00')");
        }
        echo "Guide seeded\n";
    } else {
        $pdo->exec("UPDATE tour_guide_profiles SET approval_status='approved' WHERE user_id=3");
    }

    $b = $pdo->query('SELECT id FROM businesses WHERE user_id=4')->fetch();
    if (!$b) {
        $hours = $pdo->quote(json_encode(['mon'=>'08:00-22:00','tue'=>'08:00-22:00','wed'=>'08:00-22:00','thu'=>'08:00-22:00','fri'=>'08:00-22:00','sat'=>'08:00-22:00','sun'=>'08:00-20:00']));
        $pdo->exec("INSERT INTO businesses (user_id, business_type_id, name, slug, description, address, phone, email, opening_hours, approval_status, approved_by, approved_at, status)
            VALUES (4, 1, 'Ormoc Bay Inn', 'ormoc-bay-inn', 'Budget-friendly hotel near downtown.', 'Real St., Ormoc City', '053-123-4567', 'info@ormocbayinn.example', $hours, 'approved', 1, NOW(), 'active')");
        echo "Business seeded\n";
    }

    $a = (int)$pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
    if ($a === 0) {
        $pdo->exec("INSERT INTO announcements (title, body, audience, is_published, published_at, created_by) VALUES
            ('Welcome to CulTour', 'Explore Ormoc destinations, book guides, and discover local businesses.', 'all', 1, NOW(), 1)");
    }

    foreach (['destinations','businesses','credentials','chat','profiles','qr'] as $dir) {
        $path = __DIR__ . '/uploads/' . $dir;
        if (!is_dir($path)) mkdir($path, 0755, true);
    }
    echo "DONE\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
