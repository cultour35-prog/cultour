<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
require_once __DIR__ . '/../includes/announcements.php';
require_once __DIR__ . '/../includes/suspension.php';
$user = require_login(ROLE_BUSINESS);
$bizVisitView = (string) ($_GET['view'] ?? '');
if (!in_array($bizVisitView, ['calendar', 'report'], true)) {
    $bizVisitView = '';
}
if ($bizVisitView === 'calendar') {
    $pageTitle = 'Visit calendar';
    $pageHeading = 'Visit calendar';
    $activeNav = 'calendar';
} elseif ($bizVisitView === 'report') {
    $pageTitle = 'Visit report';
    $pageHeading = 'Visit report';
    $activeNav = 'report';
} else {
    $pageTitle = 'Business Dashboard';
    $pageHeading = 'Business Dashboard';
    $activeNav = 'dashboard';
}

$annBundle = announcements_for_role(ROLE_BUSINESS, (int) $user['id']);
$biz = db()->prepare('SELECT b.*, bt.name AS type_name FROM businesses b JOIN business_types bt ON bt.id=b.business_type_id WHERE b.user_id=? LIMIT 1');
$biz->execute([$user['id']]);
$biz = $biz->fetch();

$svc = 0; $photos = 0;
$showEarnings = false;
$earnRows = [];
$earnDashTotal = 0.0;
$earnMode = '';
if ($biz) {
    $s = db()->prepare('SELECT COUNT(*) FROM business_services WHERE business_id=?'); $s->execute([$biz['id']]); $svc = (int)$s->fetchColumn();
    $s = db()->prepare('SELECT COUNT(*) FROM business_photos WHERE business_id=?'); $s->execute([$biz['id']]); $photos = (int)$s->fetchColumn();
    $earnMode = business_catalog_mode($biz['type_name'] ?? '');
    $showEarnings = in_array($earnMode, ['packages', 'rooms', 'vehicles'], true);
    if ($showEarnings) {
        require_once __DIR__ . '/../includes/business_earnings.php';
        $earnRows = business_earnings_rows((int) $biz['id'], $earnMode);
        $earnMonth = substr(app_today(), 0, 7);
        foreach ($earnRows as $earnRow) {
            if (str_starts_with($earnRow['sort_date'], $earnMonth)) {
                $earnDashTotal += (float) $earnRow['amount'];
            }
        }
    }
}

ensure_destination_coords();
$markers = array_merge(map_destination_markers(null), map_business_markers());
foreach ($markers as &$m) {
    unset($m['href']);
}
unset($m);

// Own business pin with "Your location" label (replace duplicate listing pin)
if ($biz && $biz['latitude'] !== null && $biz['longitude'] !== null) {
    $ownId = (int) $biz['id'];
    $markers = array_values(array_filter(
        $markers,
        static function ($m) use ($ownId) {
            return !(($m['kind'] ?? '') === 'business' && (int) ($m['id'] ?? 0) === $ownId);
        }
    ));
    $pinColor = $biz['pin_color'] ?? null;
    if (!$pinColor && !empty($biz['business_type_id'])) {
        $pc = db()->prepare('SELECT pin_color FROM business_types WHERE id=?');
        $pc->execute([(int) $biz['business_type_id']]);
        $pinColor = $pc->fetchColumn() ?: '#c4a35a';
    }
    array_unshift($markers, [
        'id' => $ownId,
        'type_id' => (int) ($biz['business_type_id'] ?? 0),
        'lat' => (float) $biz['latitude'],
        'lng' => (float) $biz['longitude'],
        'title' => $biz['name'],
        'subtitle' => 'Your location' . (!empty($biz['address']) ? ' · ' . $biz['address'] : ''),
        'label' => 'Your location',
        'kind' => 'business',
        'color' => $pinColor ?: '#c4a35a',
        'open' => true,
    ]);
}
$markersJson = json_encode($markers, JSON_UNESCAPED_SLASHES);

$bodyClass = trim(($bodyClass ?? '') . ' business-app');
require __DIR__ . '/../includes/header.php';
map_leaflet_head();
require __DIR__ . '/../includes/business_shell_start.php';
?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide="1"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
      <?php render_account_status_panel($user); ?>
      <?php if (user_is_suspended($user)): ?>
<?php require __DIR__ . '/../includes/business_shell_end.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; return; endif; ?>

      <?php if ($bizVisitView === ''): ?>
      <div data-live-page="announcement_board" data-live-version="<?= e(announcement_board_version($annBundle)) ?>">
        <?php render_announcements_board($annBundle); ?>
      </div>
      <?php endif; ?>
      <?php if (!$biz): ?>
        <div class="alert alert-warning">No business profile found. Complete registration details.</div>
      <?php else: ?>
        <?php if (!business_listing_is_approved($biz)): ?>
          <div class="alert alert-warning">
            Approval status: <?= status_badge($biz['approval_status']) ?> · <?= e($biz['name']) ?> (<?= e($biz['type_name']) ?>)
            Logo, description, photos, and your map pin can be added after approval.
          </div>
        <?php endif; ?>
        <?php if ($bizVisitView === ''): ?>
        <div class="row g-3 mb-4" data-live-stats="business_dashboard">
          <div class="<?= $showEarnings ? 'col-6 col-lg-3' : 'col-md-4' ?>"><div class="glass stat-card"><div class="label"><?= e(business_catalog_copy($biz['type_name'] ?? '')['label']) ?></div><p class="value" data-stat="services"><?= $svc ?></p></div></div>
          <div class="<?= $showEarnings ? 'col-6 col-lg-3' : 'col-md-4' ?>"><div class="glass stat-card"><div class="label">Photos</div><p class="value" data-stat="photos"><?= $photos ?></p></div></div>
          <div class="<?= $showEarnings ? 'col-6 col-lg-3' : 'col-md-4' ?>"><div class="glass stat-card"><div class="label">Listing</div><p class="value" data-stat="listing" style="font-size:1.2rem"><?= e(ucfirst($biz['status'])) ?></p></div></div>
          <?php if ($showEarnings): ?>
          <div class="col-6 col-lg-3"><a class="glass stat-card" href="?view=report"><div class="label">Earnings</div><p class="value" style="font-size:1.35rem"><?= e(format_money($earnDashTotal)) ?></p></a></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($bizVisitView === ''): ?>
        <div class="glass map-panel map-panel--standalone fade-up mb-4">
          <div class="map-panel__bar">
            <div class="flex-grow-1" style="min-width:min(100%,16rem)">
              <h3 class="panel-title mb-1">Your location</h3>
              <div class="small text-muted mb-1"><?= e($biz['address']) ?><?= !empty($biz['phone']) ? ' · ' . e($biz['phone']) : '' ?></div>
              <?php if (!empty($biz['description'])): ?>
                <p class="small mb-0" style="max-width:42rem"><?= e($biz['description']) ?></p>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <a class="quick-chip" href="<?= APP_URL ?>/map.php">Full map</a>
              <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/business/profile.php">Edit business details</a>
            </div>
          </div>
          <div id="bizDashMap" class="ct-map ct-map--tall" style="border-radius:0;border-left:0;border-right:0;border-bottom:0"></div>
          <?php if ($biz['latitude'] === null || $biz['longitude'] === null): ?>
            <div class="small text-muted px-3 py-2">Your business is not pinned yet. Use <a href="<?= APP_URL ?>/business/profile.php">Edit business details</a> to drop a pin.</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
          $visitYm = trim((string) ($_GET['month'] ?? date('Y-m')));
          if (!preg_match('/^\d{4}-\d{2}$/', $visitYm)) {
              $visitYm = date('Y-m');
          }
          $visitMonthStart = $visitYm . '-01';
          $visitMonthTs = strtotime($visitMonthStart . ' 12:00:00');
          $visitDaysInMonth = (int) date('t', $visitMonthTs);
          $visitFirstDow = (int) date('w', $visitMonthTs);
          $visitPrevYm = date('Y-m', strtotime($visitMonthStart . ' -1 month'));
          $visitNextYm = date('Y-m', strtotime($visitMonthStart . ' +1 month'));
          $visitMonthLabel = date('F Y', $visitMonthTs);
          $visitToday = date('Y-m-d');
          $visitSelected = normalize_tour_date($_GET['day'] ?? '');
          if ($visitSelected === null || substr($visitSelected, 0, 7) !== $visitYm) {
              $visitSelected = $visitYm === date('Y-m') ? $visitToday : $visitMonthStart;
          }

          $visitStmt = db()->prepare(
              "SELECT b.id AS booking_id, b.booking_code, b.status, b.party_size, b.booking_date,
                      b.tour_start_date, b.start_time,
                      ii.visit_date, ii.start_time AS stop_time,
                      i.title AS tour_title, i.start_date AS itin_start, i.party_size AS itin_party,
                      CONCAT(u.first_name, ' ', u.last_name) AS tourist_name
               FROM itinerary_items ii
               JOIN bookings b ON b.itinerary_id = ii.itinerary_id
               JOIN users u ON u.id = b.tourist_id
               LEFT JOIN itineraries i ON i.id = ii.itinerary_id
               WHERE ii.business_id = ?
                 AND b.status IN ('pending','approved','completed')"
          );
          $visitStmt->execute([(int) $biz['id']]);
          $visitRows = $visitStmt->fetchAll() ?: [];

          /** @var array<string, array{coming:int,visited:int,groups:list<array>}> $visitsByDate */
          $visitsByDate = [];
          $seenVisit = [];
          foreach ($visitRows as $vr) {
              $vDate = normalize_tour_date($vr['visit_date'] ?? null)
                  ?? normalize_tour_date($vr['tour_start_date'] ?? null)
                  ?? normalize_tour_date($vr['booking_date'] ?? null)
                  ?? normalize_tour_date($vr['itin_start'] ?? null);
              if ($vDate === null || substr($vDate, 0, 7) !== $visitYm) {
                  continue;
              }
              $dedupe = (int) $vr['booking_id'] . '|' . $vDate;
              if (isset($seenVisit[$dedupe])) {
                  continue;
              }
              $seenVisit[$dedupe] = true;
              $party = (int) ($vr['party_size'] ?? 0);
              if ($party < 1) {
                  $party = (int) ($vr['itin_party'] ?? 0);
              }
              if ($party < 1) {
                  $party = 1;
              }
              if (!isset($visitsByDate[$vDate])) {
                  $visitsByDate[$vDate] = ['coming' => 0, 'visited' => 0, 'groups' => []];
              }
              $status = (string) ($vr['status'] ?? '');
              if ($status === 'completed') {
                  $visitsByDate[$vDate]['visited'] += $party;
              } else {
                  $visitsByDate[$vDate]['coming'] += $party;
              }
              $when = format_time_12h($vr['stop_time'] ?? null);
              if ($when === '—') {
                  $when = format_time_12h($vr['start_time'] ?? null);
              }
              if ($when === '—') {
                  $when = '';
              }
              $visitsByDate[$vDate]['groups'][] = [
                  'code' => (string) ($vr['booking_code'] ?? ''),
                  'tourist' => trim((string) ($vr['tourist_name'] ?? '')),
                  'tour' => trim((string) ($vr['tour_title'] ?? '')),
                  'party' => $party,
                  'status' => $status,
                  'time' => $when,
              ];
          }

          $monthComing = 0;
          $monthVisited = 0;
          $monthGroups = 0;
          $busiestDate = '';
          $busiestPeople = 0;
          foreach ($visitsByDate as $dKey => $bucket) {
              $people = $bucket['coming'] + $bucket['visited'];
              $monthComing += $bucket['coming'];
              $monthVisited += $bucket['visited'];
              $monthGroups += count($bucket['groups']);
              if ($people > $busiestPeople) {
                  $busiestPeople = $people;
                  $busiestDate = $dKey;
              }
          }
          $selectedBucket = $visitsByDate[$visitSelected] ?? ['coming' => 0, 'visited' => 0, 'groups' => []];
          ksort($visitsByDate);
          $visitKeep = $bizVisitView !== '' ? ('view=' . rawurlencode($bizVisitView) . '&') : '';
        ?>

        <?php if ($bizVisitView === 'calendar'): ?>
        <div class="row g-3 mb-4 biz-visits">
          <div class="col-lg-7">
            <div class="glass p-3 p-md-4 h-100">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                  <h3 class="panel-title mb-1">Who is coming</h3>
                  <p class="small text-muted mb-0">People booked to visit <?= e($biz['name']) ?> on each day.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <a class="btn btn-sm btn-outline-secondary" href="?<?= $visitKeep ?>month=<?= e($visitPrevYm) ?>">&larr;</a>
                  <strong><?= e($visitMonthLabel) ?></strong>
                  <a class="btn btn-sm btn-outline-secondary" href="?<?= $visitKeep ?>month=<?= e($visitNextYm) ?>">&rarr;</a>
                </div>
              </div>
              <div class="biz-cal__weekdays">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?>
                  <div class="biz-cal__wd"><?= $wd ?></div>
                <?php endforeach; ?>
              </div>
              <div class="biz-cal__grid">
                <?php for ($i = 0; $i < $visitFirstDow; $i++): ?>
                  <div class="biz-cal__cell is-empty"></div>
                <?php endfor; ?>
                <?php for ($d = 1; $d <= $visitDaysInMonth; $d++):
                    $cellDate = sprintf('%s-%02d', $visitYm, $d);
                    $cell = $visitsByDate[$cellDate] ?? null;
                    $cellPeople = $cell ? ($cell['coming'] + $cell['visited']) : 0;
                    $cellClass = 'biz-cal__cell';
                    if ($cellDate === $visitToday) {
                        $cellClass .= ' is-today';
                    }
                    if ($cellDate === $visitSelected) {
                        $cellClass .= ' is-selected';
                    }
                    if ($cellPeople > 0) {
                        $cellClass .= ' has-people';
                    }
                ?>
                  <a class="<?= $cellClass ?>" href="?<?= $visitKeep ?>month=<?= e($visitYm) ?>&amp;day=<?= e($cellDate) ?>">
                    <span class="biz-cal__num"><?= $d ?></span>
                    <?php if ($cell && $cell['coming'] > 0): ?>
                      <span class="biz-cal__count"><?= (int) $cell['coming'] ?> booked</span>
                    <?php elseif ($cell && $cell['visited'] > 0): ?>
                      <span class="biz-cal__count is-past"><?= (int) $cell['visited'] ?> visited</span>
                    <?php endif; ?>
                  </a>
                <?php endfor; ?>
              </div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="glass p-3 p-md-4 h-100">
              <h3 class="panel-title mb-1"><?= e(format_date($visitSelected)) ?></h3>
              <p class="small text-muted mb-3">
                <?php
                  $selComing = (int) $selectedBucket['coming'];
                  $selVisited = (int) $selectedBucket['visited'];
                  $selGroups = count($selectedBucket['groups']);
                ?>
                <?= $selGroups ?> group<?= $selGroups === 1 ? '' : 's' ?>
                · <?= $selComing ?> coming
                <?php if ($selVisited > 0): ?> · <?= $selVisited ?> already visited<?php endif; ?>
              </p>
              <?php if (!$selectedBucket['groups']): ?>
                <p class="text-muted mb-0">No one is booked to visit on this day.</p>
              <?php else: ?>
                <div class="biz-visit-list">
                  <?php foreach ($selectedBucket['groups'] as $g): ?>
                    <div class="biz-visit-list__row">
                      <div>
                        <div class="fw-semibold"><?= e($g['tourist'] !== '' ? $g['tourist'] : 'Guest') ?></div>
                        <div class="small text-muted">
                          <?= e($g['code']) ?>
                          <?php if ($g['tour'] !== ''): ?> · <?= e($g['tour']) ?><?php endif; ?>
                          <?php if ($g['time'] !== ''): ?> · <?= e($g['time']) ?><?php endif; ?>
                        </div>
                      </div>
                      <div class="text-end">
                        <div class="fw-semibold"><?= (int) $g['party'] ?> <?= (int) $g['party'] === 1 ? 'person' : 'people' ?></div>
                        <div><?= status_badge($g['status']) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($bizVisitView === 'report'): ?>
        <div class="glass p-3 p-md-4 mb-4 biz-report">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h3 class="panel-title mb-0">Visit report · <?= e($visitMonthLabel) ?></h3>
            <?php if ($bizVisitView === 'report'): ?>
              <div class="d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="?view=report&amp;month=<?= e($visitPrevYm) ?>">&larr;</a>
                <a class="btn btn-sm btn-outline-secondary" href="?view=report&amp;month=<?= e($visitNextYm) ?>">&rarr;</a>
              </div>
            <?php endif; ?>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <div class="biz-report__stat">
                <div class="label">People coming</div>
                <div class="value"><?= (int) $monthComing ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="biz-report__stat">
                <div class="label">People who visited</div>
                <div class="value"><?= (int) $monthVisited ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="biz-report__stat">
                <div class="label">Busiest day</div>
                <div class="value biz-report__stat-sm"><?= $busiestDate !== '' ? e(format_date($busiestDate)) . ' · ' . (int) $busiestPeople : '—' ?></div>
              </div>
            </div>
          </div>
          <?php if ($showEarnings): ?>
            <?php
              $earnMonthRows = array_values(array_filter(
                  $earnRows,
                  static fn (array $row): bool => str_starts_with($row['sort_date'], $visitYm)
              ));
              $earnMonthTotal = array_sum(array_column($earnMonthRows, 'amount'));
              $earnItemLabel = match ($earnMode) {
                  'rooms' => 'Room',
                  'vehicles' => 'Vehicle',
                  default => 'Package',
              };
              $earnPayLabel = static function (string $status): string {
                  return match ($status) {
                      'paid' => 'Paid',
                      'pay_at_office' => 'Pay at Tourism Office',
                      'pending_review' => 'Waiting for confirmation',
                      default => 'Booked',
                  };
              };
            ?>
            <div class="biz-report__stat mb-3">
              <div class="label">Earnings</div>
              <div class="value"><?= e(format_money((float) $earnMonthTotal)) ?></div>
            </div>
            <?php if (!$earnMonthRows): ?>
              <p class="text-muted">No earnings this month.</p>
            <?php else: ?>
              <div class="table-responsive mb-4">
                <table class="table table-sm table-ct align-middle mb-0 biz-report__table">
                  <thead>
                    <tr>
                      <th>Tourist</th>
                      <th><?= e($earnItemLabel) ?></th>
                      <th>Date</th>
                      <th>Amount</th>
                      <th>Payment</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($earnMonthRows as $earnRow): ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?= e($earnRow['tourist']) ?></div>
                          <?php if ($earnRow['phone'] !== ''): ?>
                            <div class="small text-muted"><?= e($earnRow['phone']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?= e($earnRow['item']) ?>
                          <?php if ($earnRow['code'] !== ''): ?>
                            <div class="small text-muted"><?= e($earnRow['code']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= e($earnRow['when']) ?></td>
                        <td><?= e(format_money((float) $earnRow['amount'])) ?></td>
                        <td><?= e($earnPayLabel($earnRow['payment'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!$visitsByDate): ?>
            <p class="text-muted mb-0">No visits are booked at your business this month.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-ct align-middle mb-0 biz-report__table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Groups</th>
                    <th>Coming</th>
                    <th>Visited</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($visitsByDate as $dKey => $bucket): ?>
                    <tr>
                      <td><a href="?view=calendar&amp;month=<?= e($visitYm) ?>&amp;day=<?= e($dKey) ?>"><?= e(format_date($dKey)) ?></a></td>
                      <td><?= count($bucket['groups']) ?></td>
                      <td><?= (int) $bucket['coming'] ?></td>
                      <td><?= (int) $bucket['visited'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="biz-report__total">
                    <td>Month total</td>
                    <td><?= (int) $monthGroups ?></td>
                    <td><?= (int) $monthComing ?></td>
                    <td><?= (int) $monthVisited ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
<?php
require __DIR__ . '/../includes/business_shell_end.php';
$extraScripts = '';
if ($bizVisitView === '' && $biz) {
    $extraScripts = map_leaflet_scripts() . <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  CulTourMap.renderExploreMap('bizDashMap', {$markersJson});
});
</script>
HTML;
}
require __DIR__ . '/../includes/footer.php';
?>
