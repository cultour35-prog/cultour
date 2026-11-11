<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inhouse_guides.php';
require_once __DIR__ . '/../includes/payments.php';
ensure_package_order_schema();
$user = require_login(ROLE_ADMIN);
inhouse_guides_ensure_schema();
$pageTitle = 'Tour guide requests';
$pageHeading = 'Tour guide requests';
$pageSub = 'Accept a travel business request before the tour guide is notified';
$activeNav = 'guides';

mark_notifications_read((int) $user['id'], 'guide_request');
mark_notifications_read((int) $user['id'], 'booking_replacement');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $row = db()->prepare(
        "SELECT a.*, o.package_name, o.business_id, b.user_id AS business_user_id, b.name AS business_name,
                u.first_name, u.last_name
         FROM package_guide_assignments a
         JOIN package_orders o ON o.id=a.order_id
         JOIN businesses b ON b.id=o.business_id
         JOIN tour_guide_profiles g ON g.id=a.guide_id
         JOIN users u ON u.id=g.user_id
         WHERE a.order_id=? LIMIT 1"
    );
    $row->execute([$orderId]);
    $row = $row->fetch();
    if (!$row) {
        flash('error', 'Request not found.');
        redirect(APP_URL . '/admin/guide-requests.php');
    }
    if ($action === 'accept' && (string) $row['status'] === 'pending_admin') {
        db()->prepare("UPDATE package_guide_assignments SET status='assigned', admin_note=NULL WHERE order_id=?")->execute([$orderId]);
        $guideUser = (int) db()->query('SELECT user_id FROM tour_guide_profiles WHERE id=' . (int) $row['guide_id'])->fetchColumn();
        if ($guideUser > 0) {
            notify_user($guideUser, 'guide_assignment', 'New package assignment', 'The Tourism Office accepted a package request for you.', APP_URL . '/tourguide/bookings.php');
        }
        flash('success', 'Accepted. The tour guide was notified.');
    } elseif ($action === 'reject' && (string) $row['status'] === 'pending_admin') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            flash('error', 'Enter a reason for rejecting this request.');
        } else {
            db()->prepare("UPDATE package_guide_assignments SET status='rejected', admin_note=? WHERE order_id=?")
                ->execute([mb_substr($reason, 0, 255), $orderId]);
            notify_user(
                (int) $row['business_user_id'],
                'guide_request',
                'Tour guide request declined',
                'The Tourism Office declined this tour guide. ' . $reason,
                APP_URL . '/business/bookings.php?guide=' . $orderId
            );
            flash('success', 'Request declined. The travel business can choose another guide.');
        }
    } elseif ($action === 'replace' && (string) $row['status'] === 'needs_replacement') {
        $guideId = (int) ($_POST['guide_id'] ?? 0);
        $pub = db()->prepare(
            "SELECT g.id, g.user_id, u.first_name, u.last_name
             FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id
             WHERE g.id=? AND g.approval_status='approved' AND u.status='active'
               AND g.guide_type <> 'in_house' AND " . guide_profile_complete_sql('g', 'u') . "
             LIMIT 1"
        );
        $pub->execute([$guideId]);
        $next = $pub->fetch();
        if (!$next) {
            flash('error', 'Choose an available system tour guide.');
        } else {
            db()->prepare("UPDATE package_guide_assignments SET guide_id=?, assigned_by=?, status='assigned', admin_note=NULL, assigned_at=NOW() WHERE order_id=?")
                ->execute([$guideId, (int) $user['id'], $orderId]);
            db()->prepare('INSERT INTO package_guide_assignment_history (order_id, previous_guide_id, new_guide_id, changed_by, change_reason) VALUES (?,?,?,?,?)')
                ->execute([$orderId, (int) $row['guide_id'], $guideId, (int) $user['id'], 'Replacement assigned by the Tourism Office']);
            notify_user((int) $next['user_id'], 'guide_assignment', 'New package assignment', 'The Tourism Office assigned you to a tour package.', APP_URL . '/tourguide/bookings.php');
            $nextName = trim((string) $next['first_name'] . ' ' . (string) $next['last_name']);
            notify_user(
                (int) $row['business_user_id'],
                'guide_assignment',
                'Tour guide changed',
                'The Tourism Office assigned ' . $nextName . ' to this package.',
                APP_URL . '/business/bookings.php'
            );
            flash('success', 'Replacement assigned. The travel business was told.');
        }
    }
    redirect(APP_URL . '/admin/guide-requests.php');
}

$requests = db()->query(
    "SELECT a.order_id, a.status, a.admin_note, o.order_code, o.package_name, o.tour_date, o.booking_status,
            o.amount, o.package_days, o.package_pax, o.package_party_notes,
            o.accommodation_business_id, o.accommodation_service_id, o.accommodation_declined,
            o.transportation_business_id, o.transportation_service_id, o.transportation_declined,
            COALESCE(NULLIF(o.snapshot_itinerary_id, 0), s.package_itinerary_id) AS itinerary_id,
            i.meetup_time, i.start_date AS itin_start, i.end_date AS itin_end, i.template_day_count, i.is_suggested,
            g.hourly_rate, a.assigned_at, b.name AS business_name, u.first_name, u.last_name
     FROM package_guide_assignments a
     JOIN package_orders o ON o.id=a.order_id
     LEFT JOIN business_services s ON s.id=o.service_id
     LEFT JOIN itineraries i ON i.id=COALESCE(NULLIF(o.snapshot_itinerary_id, 0), s.package_itinerary_id)
     JOIN businesses b ON b.id=o.business_id
     JOIN tour_guide_profiles g ON g.id=a.guide_id
     JOIN users u ON u.id=g.user_id
     ORDER BY a.assigned_at DESC"
)->fetchAll() ?: [];
$guides = db()->query(
    "SELECT g.id, u.first_name, u.last_name
     FROM tour_guide_profiles g JOIN users u ON u.id=g.user_id
     WHERE g.approval_status='approved' AND u.status='active' AND g.guide_type <> 'in_house'
       AND " . guide_profile_complete_sql('g', 'u') . "
     ORDER BY u.last_name, u.first_name"
)->fetchAll() ?: [];

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/guides.php">Back to tour guides</a>
        <div class="booking-list-tools">
          <label class="guide-bookings-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="guideRequestSearch" placeholder="Search requests" aria-label="Search requests" autocomplete="off">
          </label>
          <select class="booking-list-sort" id="guideRequestSort" aria-label="Sort requests">
            <option value="date-desc">Newest date</option>
            <option value="date-asc">Oldest date</option>
            <option value="status">Status</option>
            <option value="name">Name A–Z</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>
      <?php if (!$requests): ?>
        <div class="glass p-4 text-muted">No tour guide requests.</div>
      <?php else: ?>
        <div class="row g-3" id="guideRequestList">
          <?php foreach ($requests as $request): ?>
            <?php
              $requestStatus = (string) $request['status'];
              $listStatus = $requestStatus === 'assigned' ? 'approved' : ($requestStatus === 'rejected' ? 'cancelled' : 'pending');
              $sortName = strtolower(trim((string) $request['package_name'] . ' ' . (string) $request['business_name'] . ' ' . (string) $request['first_name'] . ' ' . (string) $request['last_name']));
              $sortDate = trim((string) ($request['tour_date'] ?? '')) !== '' ? (string) $request['tour_date'] : substr((string) ($request['assigned_at'] ?? ''), 0, 10);
            ?>
            <div class="col-12" data-request-card data-sort-status="<?= e($listStatus) ?>" data-sort-name="<?= e($sortName) ?>" data-sort-date="<?= e($sortDate) ?>">
              <div class="glass p-3">
                <?php
                  $when = trim((string) ($request['tour_date'] ?? ''));
                ?>
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                  <div>
                    <div class="fw-semibold"><code><?= e((string) $request['order_code']) ?></code> · <?= e((string) $request['package_name']) ?></div>
                    <div class="small text-muted">
                      <?= e((string) $request['business_name']) ?>
                      · <?= e(trim((string) $request['first_name'] . ' ' . (string) $request['last_name'])) ?>
                      <?= $when !== '' ? ' · ' . e(format_date($when)) : '' ?>
                    </div>
                  </div>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#guideRequest<?= (int) $request['order_id'] ?>">
                    <i class="bi bi-card-list"></i> View details
                  </button>
                </div>
                <?php if ($requestStatus === 'pending_admin'): ?>
                  <div class="d-flex flex-wrap gap-2 guide-booking-actions">
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="accept">
                      <input type="hidden" name="order_id" value="<?= (int) $request['order_id'] ?>">
                      <button class="btn btn-sm btn-ct" type="submit">Accept</button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectGuide<?= (int) $request['order_id'] ?>">Reject</button>
                  </div>
                <?php elseif ($requestStatus === 'assigned'): ?>
                  <div class="small">Approved. The tour guide was notified.</div>
                <?php elseif ($requestStatus === 'rejected'): ?>
                  <div class="small">Cancelled. <?= e((string) ($request['admin_note'] ?? '')) ?></div>
                <?php else: ?>
                  <div class="small mb-2">The tour guide declined. <?= e((string) ($request['admin_note'] ?? '')) ?></div>
                  <form method="post" class="d-flex flex-wrap gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="replace">
                    <input type="hidden" name="order_id" value="<?= (int) $request['order_id'] ?>">
                    <select class="form-select form-select-sm" name="guide_id" required style="max-width:16rem">
                      <option value="">Choose a replacement</option>
                      <?php foreach ($guides as $guide): ?>
                        <option value="<?= (int) $guide['id'] ?>"><?= e(trim((string) $guide['first_name'] . ' ' . (string) $guide['last_name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-ct" type="submit">Assign replacement</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php
              $days = max(1, (int) ($request['package_days'] ?? 1));
              $pax = (int) ($request['package_pax'] ?? 0);
              $party = json_decode((string) ($request['package_party_notes'] ?? ''), true);
              $party = is_array($party) ? $party : [];
              $guestText = (int) ($party['adults'] ?? 0) . ' adults, ' . (int) ($party['children'] ?? 0) . ' children';
              if ((int) ($party['infants'] ?? 0) > 0) {
                  $guestText .= ', ' . (int) $party['infants'] . ' infant/toddler (not counted)';
              }
              if ((int) ($party['adults'] ?? 0) + (int) ($party['children'] ?? 0) < 1) {
                  $guestText = $pax > 0 ? ($pax . ' guest' . ($pax === 1 ? '' : 's')) : 'Not set';
              }
              $needBits = [];
              foreach ($party['needs'] ?? [] as $need) {
                  if (!is_array($need)) {
                      continue;
                  }
                  $bit = trim((string) ($need['label'] ?? ''));
                  if ($bit === '') {
                      continue;
                  }
                  $bit .= ' · ' . (int) ($need['count'] ?? 0);
                  if (trim((string) ($need['detail'] ?? '')) !== '') {
                      $bit .= ' · ' . trim((string) $need['detail']);
                  }
                  $needBits[] = $bit;
              }
              $needsText = $needBits ? implode('; ', $needBits) : 'None';
              $itinId = (int) ($request['itinerary_id'] ?? 0);
              $meetupPlace = $itinId > 0 ? itinerary_day_meetup_place($itinId, 1, [
                  'meetup_time' => $request['meetup_time'] ?? null,
                  'start_date' => $request['itin_start'] ?? $when,
              ]) : [];
              $meetupText = trim(format_time_12h((string) ($request['meetup_time'] ?? '')) . ((($meetupPlace['label'] ?? '') !== '') ? ' · ' . (string) $meetupPlace['label'] : ''));
              if ($meetupText === '') {
                  $meetupText = 'Not set';
              }
              $stopCount = $itinId > 0 ? count(itinerary_items_with_places($itinId)) : 0;
              $stayText = stay_ride_label($request, 'accommodation');
              $rideText = stay_ride_label($request, 'transportation');
              $guideRate = round((float) ($request['hourly_rate'] ?? 0), 2);
              $guideLine = $guideRate > 0 ? format_money($guideRate) . ' · ' . $days . ($days === 1 ? ' day' : ' days') : 'Included';
              $orderStatus = strtoupper(str_replace('_', ' ', (string) ($request['booking_status'] ?? 'pending')));
            ?>
            <div class="modal fade booking-detail-modal" id="guideRequest<?= (int) $request['order_id'] ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content itin-book-review-card">
                  <div class="modal-header border-0 pb-0">
                    <div>
                      <div class="itin-book-review-heading">
                        <h2 class="modal-title h5 mb-0">Booking summary</h2>
                        <?php if ($itinId > 0): ?>
                          <a class="itin-book-review-view" href="<?= APP_URL ?>/itinerary-share.php?from=package&order=<?= (int) $request['order_id'] ?>" target="_blank" rel="noopener">View itinerary</a>
                        <?php endif; ?>
                      </div>
                      <div class="small text-muted">Review the booking</div>
                      <div class="small" style="color:var(--ct-gold);font-weight:700;"><?= e((string) $request['order_code']) ?> · <?= e($orderStatus) ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body pt-2">
                    <dl class="itin-book-review-list mb-0">
                      <div><dt>Tour guide</dt><dd><?= e(trim((string) $request['first_name'] . ' ' . (string) $request['last_name'])) ?></dd></div>
                      <div><dt>Tour name</dt><dd><?= e((string) $request['package_name']) ?></dd></div>
                      <div><dt>Date</dt><dd><?= $when !== '' ? e(format_date($when)) : 'Not set' ?></dd></div>
                      <div><dt>Days</dt><dd><?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?></dd></div>
                      <div><dt>Meetup</dt><dd><?= e($meetupText) ?></dd></div>
                      <div><dt>Guests</dt><dd><?= e($guestText) ?></dd></div>
                      <div><dt>Special requirements</dt><dd><?= e($needsText) ?></dd></div>
                      <div><dt>Stay</dt><dd><?= e($stayText) ?></dd></div>
                      <div><dt>Ride</dt><dd><?= e($rideText) ?></dd></div>
                      <div><dt>Stops</dt><dd><?= (int) $stopCount ?> stop<?= $stopCount === 1 ? '' : 's' ?></dd></div>
                    </dl>
                    <div class="guide-book-bill">
                      <ul class="guide-book-bill__rows">
                        <li><span>Guests</span><strong><?= $pax > 0 ? (int) $pax : '—' ?></strong></li>
                        <li><span>Guide</span><strong><?= e($guideLine) ?></strong></li>
                        <li><span>Stay</span><strong><?= e($stayText) ?></strong></li>
                        <li><span>Transportation</span><strong><?= e($rideText) ?></strong></li>
                        <li class="is-total"><span>Total</span><strong><?= e(format_money((float) $request['amount'])) ?></strong></li>
                      </ul>
                      <label class="itin-book-review-footer__note"><span>Note for the guide</span>
                        <div class="form-control form-control-sm" style="min-height:4.75rem;white-space:pre-wrap;">No note</div>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php if ($requestStatus === 'pending_admin'): ?>
            <div class="modal fade" id="rejectGuide<?= (int) $request['order_id'] ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="order_id" value="<?= (int) $request['order_id'] ?>">
                    <div class="modal-header">
                      <h2 class="modal-title h5">Reject <?= e((string) $request['order_code']) ?></h2>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <label class="form-label" for="rejectGuideReason<?= (int) $request['order_id'] ?>">Reason</label>
                      <textarea class="form-control" id="rejectGuideReason<?= (int) $request['order_id'] ?>" name="reason" rows="3" required placeholder="Why are you rejecting this tour guide?"></textarea>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button class="btn btn-outline-danger" type="submit">Reject</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div class="glass p-4 text-muted" id="guideRequestEmpty" hidden>No requests match this search.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('guideRequestSearch');
  var sort = document.getElementById('guideRequestSort');
  var list = document.getElementById('guideRequestList');
  var empty = document.getElementById('guideRequestEmpty');
  if (!input || !list) return;
  var rank = { pending: 0, approved: 1, cancelled: 2 };
  function apply() {
    var q = input.value.trim().toLowerCase();
    var mode = sort ? sort.value : 'date-desc';
    var filter = (mode === 'pending' || mode === 'approved' || mode === 'cancelled') ? mode : '';
    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-request-card]'));
    var shown = 0;
    cards.forEach(function (card) {
      var text = (card.textContent || '').toLowerCase();
      var status = card.getAttribute('data-sort-status') || '';
      var hide = (q !== '' && text.indexOf(q) === -1) || (filter !== '' && status !== filter);
      card.hidden = hide;
      if (!hide) shown++;
    });
    cards.sort(function (a, b) {
      if (mode === 'name') return (a.getAttribute('data-sort-name') || '').localeCompare(b.getAttribute('data-sort-name') || '');
      if (mode === 'status' || filter !== '') return (rank[a.getAttribute('data-sort-status')] ?? 9) - (rank[b.getAttribute('data-sort-status')] ?? 9);
      var ad = a.getAttribute('data-sort-date') || '';
      var bd = b.getAttribute('data-sort-date') || '';
      return mode === 'date-asc' ? ad.localeCompare(bd) : bd.localeCompare(ad);
    });
    cards.forEach(function (card) { list.appendChild(card); });
    if (empty) empty.hidden = shown > 0;
  }
  input.addEventListener('input', apply);
  if (sort) sort.addEventListener('change', apply);
})();
</script>
<style>
  .booking-detail-modal .modal-dialog { max-width: min(760px, 94vw); }
</style>
<?php require __DIR__ . '/../includes/footer.php'; ?>
