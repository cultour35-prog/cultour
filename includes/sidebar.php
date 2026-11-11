<?php
/**
 * Role sidebar
 * Expects: $user, $activeNav
 */
$role = (int) $user['role_id'];
$activeNav = $activeNav ?? '';
$pendingAppeals = 0;
$pendingGuides = 0;
$pendingBusinesses = 0;
$pendingContentReports = 0;
$pendingGuideBookings = 0;
$pendingRefunds = 0;
if ($role === ROLE_ADMIN) {
    require_once __DIR__ . '/suspension.php';
    require_once __DIR__ . '/admin_registrations.php';
    require_once __DIR__ . '/content_reports.php';
    $pendingAppeals = pending_appeal_count();
    $pendingGuides = pending_guide_application_count();
    $pendingBusinesses = pending_business_application_count();
    $pendingContentReports = pending_content_report_count();
    $pendingRefunds = refund_admin_pending_count();
}
if ($role === ROLE_TOUR_GUIDE) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([(int) $user['id']]);
    $guideProfileId = (int) $gp->fetchColumn();
    if ($guideProfileId > 0) {
        $pendingGuideBookings = guide_pending_booking_count($guideProfileId);
    }
}

$menus = [
  ROLE_ADMIN => [
    ['dashboard', 'bi-speedometer2', 'Dashboard', '/admin/dashboard.php'],
    ['tourists', 'bi-person', 'Tourists', '/admin/tourists.php'],
    ['guides', 'bi-person-badge', 'Tour Guides', '/admin/guides.php'],
    ['businesses', 'bi-shop', 'Businesses', '/admin/businesses.php'],
    ['destinations', 'bi-geo-alt', 'Government sites', '/admin/destinations.php'],
    ['appeals', 'bi-person-x', 'Suspended', '/admin/appeals.php'],
    ['categories', 'bi-tags', 'Categories', '/admin/categories.php'],
    ['bookings', 'bi-calendar-check', 'Bookings', '/admin/bookings.php'],
    ['refunds', 'bi-cash-coin', 'Refunds', '/admin/refunds.php'],
    ['tours', 'bi-geo-fill', 'Current tours', '/admin/tours.php'],
    ['itineraries', 'bi-signpost-2', 'Itineraries', '/admin/itineraries.php'],
    ['announcements', 'bi-megaphone', 'Announcements', '/admin/announcements.php'],
    ['reports', 'bi-file-earmark-bar-graph', 'Reports', '/admin/reports.php'],
    ['revenue', 'bi-cash-stack', 'Revenue', '/admin/revenue.php'],
    ['content_reports', 'bi-flag', 'Content reports', '/admin/content-reports.php'],
    ['logs', 'bi-clock-history', 'Activity Logs', '/admin/logs.php'],
  ],
  ROLE_TOURIST => [
    ['dashboard', 'bi-house', 'Dashboard', '/tourist/dashboard.php'],
    ['map', 'bi-map', 'Explore Map', '/map.php'],
    ['places', 'bi-geo-alt', 'Places', '/tourist/places.php'],
    ['itineraries', 'bi-signpost-2', 'Itineraries', '/tourist/itineraries.php'],
    ['packages', 'bi-suitcase-lg', 'Tour package', '/tourist/packages.php'],
    ['bookings', 'bi-calendar-check', 'My Bookings', '/tourist/bookings.php'],
  ],
  ROLE_TOUR_GUIDE => [
    ['dashboard', 'bi-speedometer2', 'Dashboard', '/tourguide/dashboard.php'],
    ['schedule', 'bi-calendar-week', 'Schedule', '/tourguide/schedule.php'],
    ['checkin', 'bi-camera', 'Check-in', '/tourguide/checkin.php'],
    ['bookings', 'bi-calendar-check', 'Bookings', '/tourguide/bookings.php'],
    ['availability', 'bi-calendar3', 'Availability', '/tourguide/availability.php'],
    ['ratings', 'bi-star', 'Ratings', '/tourguide/ratings.php'],
  ],
  ROLE_BUSINESS => [
    ['dashboard', 'bi-speedometer2', 'Dashboard', '/business/dashboard.php'],
    ['services', 'bi-list-check', 'Services', '/business/services.php'],
    ['photos', 'bi-images', 'Photos', '/business/photos.php'],
    ['hours', 'bi-clock', 'Operating Hours', '/business/hours.php'],
    ['inquiries', 'bi-inbox', 'Inquiries', '/business/inquiries.php'],
    ['chat', 'bi-chat-dots', 'Messages', '/business/chat.php'],
  ],
];

$navItems = $menus[$role] ?? [];
?>
<aside class="sidebar<?= $role === ROLE_ADMIN ? ' sidebar--admin' : ' ct-scroll' ?>" id="appSidebar">
  <a class="brand" href="<?= APP_URL ?>"><?= e(APP_NAME) ?></a>
  <div class="small mb-3 opacity-75">Ormoc City Tourism</div>
  <nav class="nav flex-column">
    <?php foreach ($navItems as [$key, $icon, $label, $path]): ?>
      <a class="nav-link <?= $activeNav === $key ? 'active' : '' ?>" href="<?= APP_URL . $path ?>">
        <i class="bi <?= e($icon) ?>"></i>
        <span class="flex-grow-1"><?= e($label) ?></span>
        <?php if ($key === 'bookings' && $role === ROLE_TOUR_GUIDE): ?>
          <span class="nav-dot" id="navBookingsPending" title="Pending booking requests" aria-label="Pending booking requests"<?= $pendingGuideBookings > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
        <?php if ($key === 'appeals'): ?>
          <span class="nav-dot" id="navAppealsUnread" title="Pending appeals" aria-label="Pending appeals"<?= $pendingAppeals > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
        <?php if ($key === 'guides'): ?>
          <span class="nav-dot" id="navGuidesPending" title="Pending tour guide applications" aria-label="Pending tour guide applications"<?= $pendingGuides > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
        <?php if ($key === 'businesses'): ?>
          <span class="nav-dot" id="navBusinessesPending" title="Pending business applications" aria-label="Pending business applications"<?= $pendingBusinesses > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
        <?php if ($key === 'refunds'): ?>
          <span class="nav-dot" title="Refunds to send" aria-label="Refunds to send"<?= $pendingRefunds > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
        <?php if ($key === 'content_reports'): ?>
          <span class="nav-dot" title="Content reports to handle" aria-label="Content reports to handle"<?= $pendingContentReports > 0 ? '' : ' hidden' ?>></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
