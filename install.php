<?php
/**
 * CulTour Installer
 * Visit once: http://localhost/cultour/install.php
 * Creates DB, imports schema, sets seed passwords to Password123!
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'cultour_db';
$sqlFile = __DIR__ . '/database/cultour_db.sql';

$done = false;
$error = null;
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if (!is_readable($sqlFile)) {
            throw new RuntimeException('SQL file not found: database/cultour_db.sql');
        }

        $sql = file_get_contents($sqlFile);
        // Split on semicolons carefully enough for our seed file
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            // Skip pure comment blocks
            $clean = preg_replace('/^--.*$/m', '', $statement);
            if (trim($clean) === '') {
                continue;
            }
            $pdo->exec($statement);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $messages[] = 'Database schema imported successfully.';

        $pdo->exec("USE `$dbName`");
        $hash = password_hash('Password123!', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ?');
        $stmt->execute([$hash]);
        $messages[] = 'All seed users password set to: Password123!';

        // Extra destinations
        $pdo->exec("UPDATE destinations SET created_by = 1 WHERE created_by IS NULL");
        // Seed / repair Ormoc coordinates when missing
        $pdo->exec("UPDATE destinations SET latitude=11.0727, longitude=124.7007 WHERE latitude IS NULL AND name LIKE '%Lake Danao%'");
        $pdo->exec("UPDATE destinations SET latitude=11.1150, longitude=124.6750 WHERE latitude IS NULL AND name LIKE '%Tongonan%'");
        $pdo->exec("UPDATE destinations SET latitude=11.0060, longitude=124.6055 WHERE latitude IS NULL AND name LIKE '%Baywalk%'");
        $pdo->exec("UPDATE destinations SET latitude=11.0095, longitude=124.6078 WHERE latitude IS NULL AND name LIKE '%Centennial%'");
        $pdo->exec("UPDATE destinations SET latitude=11.0065, longitude=124.6075 WHERE latitude IS NULL AND name LIKE '%Plaza%'");
        $count = (int) $pdo->query('SELECT COUNT(*) FROM destinations')->fetchColumn();
        if ($count < 2) {
            $pdo->exec("INSERT INTO destinations (category_id, name, slug, location, description, history, opening_hours, entrance_fee, travel_tips, nearby_attractions, emergency_contacts, is_featured, status, created_by, latitude, longitude) VALUES
            (1, 'Lake Danao', 'lake-danao', 'Lake Danao Natural Park, Ormoc City, Leyte',
             'A serene crater lake nestled in the mountains of Ormoc, popular for kayaking, camping, and cool climate escapes.',
             'Lake Danao is a volcanic crater lake and a flagship eco-tourism destination for Ormoc City.',
             '6:00 AM – 5:00 PM', 50.00,
             'Bring light jackets; weather is cooler at elevation. Rent kayaks early on weekends.',
             'Tongonan Hot Springs, Alto Peak',
             'Tourism Desk: (053) 255-XXXX | Emergency: 911',
             1, 'published', 1, 11.0727, 124.7007),
            (2, 'Tongonan Hot Springs', 'tongonan-hot-springs', 'Tongonan, Ormoc City, Leyte',
             'Natural geothermal hot springs near Ormoc’s energy corridor — ideal for wellness and short day trips.',
             'Tongonan is tied to Leyte’s geothermal heritage and the region’s renewable energy industry.',
             '7:00 AM – 6:00 PM', 30.00,
             'Wear water shoes. Supervise children near pools.',
             'Lake Danao, Geothermal Visitor Sites',
             'Barangay Hall / Tourism Office',
             1, 'published', 1, 11.1150, 124.6750),
            (3, 'Ormoc Baywalk', 'ormoc-baywalk', 'Downtown Ormoc City Waterfront',
             'A scenic bayfront promenade for evening strolls, local food, and city views over Ormoc Bay.',
             'Developed as a public leisure space celebrating Ormoc’s coastal identity.',
             'Open 24 hours (best 5:00 PM – 10:00 PM)', 0.00,
             'Visit at sunset. Keep valuables secure in crowded spots.',
             'City Proper, Ports Area',
             'PNP Ormoc / City Tourism Office',
             1, 'published', 1, 11.0060, 124.6055),
            (4, 'Centennial Park', 'centennial-park', 'Ormoc City Proper',
             'A green urban park for families, exercise, and community events in the heart of the city.',
             'Named in observance of historical milestones celebrated by the city.',
             '5:00 AM – 9:00 PM', 0.00,
             'Great for morning jogs. Check event schedules with Tourism Office.',
             'Ormoc Baywalk, Downtown shops',
             'City Hall Hotline',
             0, 'published', 1, 11.0095, 124.6078),
            (5, 'Pineapple Festival Grounds', 'pineapple-festival', 'Ormoc City Festival Area',
             'Cultural venue linked to Ormoc’s Pineapple Festival celebrations and local heritage showcase.',
             'The Pineapple Festival highlights Ormoc agricultural pride and community festivities.',
             'Varies by event schedule', 0.00,
             'Confirm festival calendar with the Tourism Office before visiting.',
             'City Proper attractions',
             'Tourism Office',
             0, 'published', 1, 11.0120, 124.6095)");
            $messages[] = 'Sample Ormoc destinations inserted.';
        }

        // Guide profile
        $g = $pdo->query("SELECT id FROM tour_guide_profiles WHERE user_id = 3")->fetch();
        if (!$g) {
            $pdo->exec("INSERT INTO tour_guide_profiles (user_id, bio, languages, years_experience, hourly_rate, license_number, approval_status, approved_by, approved_at)
                VALUES (3, 'Friendly local guide specializing in Lake Danao and heritage walks around Ormoc.', 'English, Cebuano, Tagalog', 5, 500.00, 'OTG-2024-001', 'approved', 1, NOW())");
            $guideId = (int) $pdo->lastInsertId();
            for ($d = 1; $d <= 5; $d++) {
                $pdo->exec("INSERT INTO guide_availability (guide_id, day_of_week, start_time, end_time) VALUES ($guideId, $d, '08:00:00', '17:00:00')");
            }
            $messages[] = 'Tour guide profile and availability seeded.';
        } else {
            $pdo->exec("UPDATE tour_guide_profiles SET approval_status='approved', approved_by=1, approved_at=NOW() WHERE user_id=3");
            $messages[] = 'Tour guide profile approved.';
        }

        // Business
        $b = $pdo->query("SELECT id FROM businesses WHERE user_id = 4")->fetch();
        if (!$b) {
            $hours = $pdo->quote(json_encode([
                'mon' => '08:00-22:00', 'tue' => '08:00-22:00', 'wed' => '08:00-22:00',
                'thu' => '08:00-22:00', 'fri' => '08:00-22:00', 'sat' => '08:00-22:00', 'sun' => '08:00-20:00',
            ]));
            $pdo->exec("INSERT INTO businesses (user_id, business_type_id, name, slug, description, address, phone, email, opening_hours, approval_status, approved_by, approved_at, status, latitude, longitude)
                VALUES (4, 1, 'Ormoc Bay Inn', 'ormoc-bay-inn', 'Comfortable budget-friendly hotel near downtown Ormoc.', 'Real St., Ormoc City', '053-123-4567', 'info@ormocbayinn.example', $hours, 'approved', 1, NOW(), 'active', 11.0072, 124.6085)");
            $bizId = (int) $pdo->lastInsertId();
            $pdo->exec("UPDATE businesses SET latitude=11.0072, longitude=124.6085 WHERE user_id=4 AND latitude IS NULL");
            $pdo->exec("INSERT INTO business_services (business_id, name, description, price) VALUES
                ($bizId, 'Standard Room', 'Air-conditioned room for 2', 1500.00),
                ($bizId, 'Airport Transfer', 'Ormoc Airport pickup', 300.00)");
            $messages[] = 'Sample business seeded.';
        } else {
            $pdo->exec("UPDATE businesses SET approval_status='approved', approved_by=1, approved_at=NOW() WHERE user_id=4");
            $pdo->exec("UPDATE businesses SET latitude=11.0072, longitude=124.6085 WHERE user_id=4 AND latitude IS NULL");
            $messages[] = 'Business approved.';
        }

        // Announcement
        $a = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
        if ($a === 0) {
            $pdo->exec("INSERT INTO announcements (title, body, audience, is_published, published_at, created_by) VALUES
                ('Welcome to CulTour', 'Explore Ormoc City destinations, book licensed tour guides, and discover local businesses through CulTour.', 'all', 1, NOW(), 1)");
            $messages[] = 'Welcome announcement created.';
        }

        $dirs = [
            __DIR__ . '/uploads/destinations',
            __DIR__ . '/uploads/businesses',
            __DIR__ . '/uploads/credentials',
            __DIR__ . '/uploads/chat',
            __DIR__ . '/uploads/profiles',
            __DIR__ . '/uploads/qr',
            __DIR__ . '/uploads/announcements',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        $messages[] = 'Upload directories ready.';
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install CulTour</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; background: linear-gradient(145deg, #0b3d4a 0%, #1a6b5c 45%, #c4a35a 100%); }
    .card { border: 0; border-radius: 1.25rem; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
  </style>
</head>
<body class="d-flex align-items-center py-5">
  <div class="container" style="max-width:640px">
    <div class="card p-4 p-md-5 bg-white">
      <h1 class="h3 mb-1">CulTour Installer</h1>
      <p class="text-muted mb-4">Sets up MySQL schema and sample Ormoc City data for XAMPP.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($done): ?>
        <div class="alert alert-success">
          <strong>Installation complete.</strong>
          <ul class="mb-0 mt-2">
            <?php foreach ($messages as $m): ?>
              <li><?= htmlspecialchars($m) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="bg-light rounded-3 p-3 mb-3 small">
          <strong>Demo logins</strong> (password: <code>Password123!</code>)<br>
          Admin: admin@cultour.ormoc.gov.ph<br>
          Tourist: tourist@example.com<br>
          Guide: guide@example.com<br>
          Business: business@example.com
        </div>
        <a class="btn btn-success w-100" href="login.php">Go to Login</a>
        <p class="text-danger small mt-3 mb-0">Delete or protect <code>install.php</code> after setup.</p>
      <?php else: ?>
        <form method="post">
          <p class="small text-muted">Requires MySQL running in XAMPP with user <code>root</code> and empty password (default).</p>
          <button class="btn btn-primary w-100 btn-lg" type="submit">Install CulTour Database</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
