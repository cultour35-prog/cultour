<?php
/**
 * Card stack shown on the phone ticket page and in the admin booking popup.
 */

function booking_detail_fetch(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    ensure_booking_payment_schema();
    ensure_booking_accessibility_schema();
    $st = db()->prepare(
        "SELECT b.*,
                CONCAT(t.first_name,' ',t.last_name) AS tourist_name, t.email AS tourist_email, t.phone AS tourist_phone,
                CONCAT(gu.first_name,' ',gu.last_name) AS guide_name, gu.phone AS guide_phone,
                d.name AS destination_name, d.location AS destination_location,
                i.title AS itinerary_title, i.description AS itinerary_description,
                i.start_date AS itin_start, i.end_date AS itin_end, i.meetup_time,
                i.is_suggested, i.template_day_count,
                accb.name AS accommodation_name, trnb.name AS transportation_name
         FROM bookings b
         JOIN users t ON t.id = b.tourist_id
         JOIN tour_guide_profiles gp ON gp.id = b.guide_id
         JOIN users gu ON gu.id = gp.user_id
         LEFT JOIN destinations d ON d.id = b.destination_id
         LEFT JOIN itineraries i ON i.id = b.itinerary_id
         LEFT JOIN businesses accb ON accb.id = b.accommodation_business_id
         LEFT JOIN businesses trnb ON trnb.id = b.transportation_business_id
         WHERE b.id = ?
         LIMIT 1"
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function booking_detail_cards_html(array $booking, string $certUrl = ''): string
{
    $stopsByDay = [];
    $dayCount = 1;
    if (!empty($booking['itinerary_id'])) {
        ensure_itinerary_items_schema();
        $itinId = (int) $booking['itinerary_id'];
        $stops = itinerary_items_with_places($itinId);
        $itinRow = [
            'start_date' => $booking['itin_start'] ?? null,
            'end_date' => $booking['itin_end'] ?? null,
            'meetup_time' => $booking['meetup_time'] ?? null,
            'template_day_count' => $booking['template_day_count'] ?? null,
            'is_suggested' => $booking['is_suggested'] ?? 0,
        ];
        $dayCount = itinerary_logical_day_count($itinRow);
        $stopsByDay = itinerary_group_stops_by_day(
            $stops,
            $booking['itin_start'] ?? null,
            $booking['itin_end'] ?? null,
            $itinId,
            $itinRow
        );
    }

    $certImage = false;
    if ($certUrl !== '' && booking_is_pwd($booking)) {
        $ext = strtolower(pathinfo((string) ($booking['fitness_certificate_path'] ?? ''), PATHINFO_EXTENSION));
        $certImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    ob_start();
    ?>
    <div class="bkd">
      <section class="bkd-card">
        <div class="bkd-section">Ticket</div>
        <?php
          booking_detail_row('Tourist', e((string) ($booking['tourist_name'] ?? '')));
          $contact = trim((string) ($booking['tourist_phone'] ?? ''));
          if (!empty($booking['tourist_email'])) {
              $contact .= ($contact !== '' ? ' · ' : '') . (string) $booking['tourist_email'];
          }
          booking_detail_row('Contact', e($contact));
          booking_detail_row('Tour guide', e((string) ($booking['guide_name'] ?? '')));
          booking_detail_row('Guide phone', e((string) ($booking['guide_phone'] ?? '')));
          $when = format_date($booking['booking_date'] ?? null, 'l, M j, Y') . ' · ' . format_time_12h($booking['start_time'] ?? null);
          if (!empty($booking['end_time'])) {
              $when .= ' – ' . format_time_12h($booking['end_time']);
          }
          booking_detail_row('Schedule', e($when));
          booking_detail_row('Party size', e((int) ($booking['party_size'] ?? 1) . ' guest(s)'));
          if (!empty($booking['itinerary_id'])) {
              booking_detail_row('Accommodation', e(stay_ride_label($booking, 'accommodation')));
              booking_detail_row('Transportation', e(stay_ride_label($booking, 'transportation')));
          }
          $place = (string) ($booking['destination_name'] ?? '');
          if (!empty($booking['destination_location'])) {
              $place .= ($place !== '' ? ' · ' : '') . (string) $booking['destination_location'];
          }
          booking_detail_row('Destination', e($place));
          $pay = ucwords(str_replace('_', ' ', (string) ($booking['payment_status'] ?? 'unpaid')));
          if (!empty($booking['payment_reference'])) {
              $pay .= ' · Ref ' . (string) $booking['payment_reference'];
          }
          booking_detail_row('Payment', e($pay));
          booking_detail_row(
              'Check-in',
              !empty($booking['checked_in_at']) ? e(format_date($booking['checked_in_at'], 'M j, Y g:i A')) : 'Not yet'
          );
        ?>
      </section>

      <section class="bkd-card">
        <div class="bkd-section">Accessibility needs</div>
        <?php if (booking_is_pwd($booking)): ?>
          <div class="bkd-line">
            <div class="bkd-value">Accessibility needs</div>
            <?php if (!empty($booking['pwd_notes'])): ?>
              <div class="bkd-value bkd-plain"><?= e((string) $booking['pwd_notes']) ?></div>
            <?php endif; ?>
            <?php if ($certUrl !== ''): ?>
              <?php if ($certImage): ?>
                <img class="bkd-cert" src="<?= e($certUrl) ?>" alt="Fitness-to-travel certificate">
              <?php else: ?>
                <div class="bkd-cert-link"><a href="<?= e($certUrl) ?>" target="_blank" rel="noopener">Fitness-to-travel certificate</a></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="bkd-muted">No accessibility needs on this booking.</div>
        <?php endif; ?>
      </section>

      <section class="bkd-card">
        <div class="bkd-section">Booking note</div>
        <?php if (trim((string) ($booking['notes'] ?? '')) !== ''): ?>
          <div class="bkd-value bkd-plain"><?= e((string) $booking['notes']) ?></div>
        <?php else: ?>
          <div class="bkd-muted">No note on this booking.</div>
        <?php endif; ?>
      </section>

      <section class="bkd-card">
        <div class="bkd-section">Itinerary</div>
        <?php if (empty($booking['itinerary_id'])): ?>
          <div class="bkd-muted">No itinerary on this booking.</div>
        <?php else: ?>
          <div class="bkd-value" style="margin-bottom:.35rem"><?= e((string) ($booking['itinerary_title'] ?: 'Itinerary')) ?></div>
          <div class="bkd-muted" style="margin-bottom:.6rem">
            <?= $dayCount > 1 ? e((int) $dayCount . ' days') : '1 day' ?>
            <?php if (!empty($booking['itin_start'])): ?>
              · starts <?= e(format_date($booking['itin_start'])) ?>
            <?php endif; ?>
          </div>
          <?php if (trim((string) ($booking['itinerary_description'] ?? '')) !== ''): ?>
            <div class="bkd-value bkd-plain" style="margin-bottom:.75rem"><?= e((string) $booking['itinerary_description']) ?></div>
          <?php endif; ?>
          <?php $daySwitch = 'bkdDays' . (int) ($booking['id'] ?? 0); ?>
          <div class="bkd-days">
          <?php $dayOpen = true; ?>
          <?php foreach ($stopsByDay as $g): ?>
            <?php
              $dayNum = (int) $g['day'];
              $dayInputId = $daySwitch . 'd' . $dayNum;
              $meetup = itinerary_day_meetup_place((int) $booking['itinerary_id'], $dayNum, [
                  'meetup_time' => $booking['meetup_time'] ?? null,
                  'start_date' => $booking['itin_start'] ?? null,
              ]);
            ?>
            <input class="bkd-day-radio" type="radio" name="<?= e($daySwitch) ?>" id="<?= e($dayInputId) ?>"<?= $dayOpen ? ' checked' : '' ?>>
            <label class="bkd-day-tab" for="<?= e($dayInputId) ?>"><?= e((string) $g['label']) ?></label>
            <div class="bkd-day-panel">
              <div class="bkd-day">
                <span><?= e((string) $g['label']) ?></span>
                <span class="bkd-muted"><?= e(format_date($g['date'] ?? null)) ?></span>
              </div>
            <?php if (!empty($meetup['has_place']) || ($meetup['meetup_display'] ?? '') !== ''): ?>
              <div class="bkd-stop">
                <div class="bkd-muted">Meetup<?= ($meetup['meetup_display'] ?? '') !== '' ? ' · ' . e($meetup['meetup_display']) : '' ?></div>
                <strong><?= e((string) ($meetup['label'] ?? '')) ?></strong>
              </div>
            <?php endif; ?>
            <?php foreach ($g['stops'] as $idx => $it): ?>
              <div class="bkd-stop">
                <div class="bkd-muted">
                  Stop <?= (int) $idx + 1 ?>
                  · <?= ($it['stop_kind'] ?? '') === 'business' ? 'Business' : 'Destination' ?>
                </div>
                <strong><?= e((string) ($it['stop_name'] ?? $it['destination_name'] ?? 'Stop')) ?></strong>
                <?php if (trim((string) ($it['location'] ?? '')) !== ''): ?>
                  <div class="bkd-muted"><?= e((string) $it['location']) ?></div>
                <?php endif; ?>
                <div class="bkd-muted">
                  Arrive <?= e(format_time_12h(isset($it['start_time']) ? (string) $it['start_time'] : null)) ?>
                  · Leave <?= e(format_time_12h(isset($it['end_time']) ? (string) $it['end_time'] : null)) ?>
                  · Stay <?= (int) ($it['visit_minutes'] ?? 60) ?> min
                  <?php if (trim((string) ($it['transportation'] ?? '')) !== ''): ?>
                    · <?= e((string) $it['transportation']) ?>
                  <?php endif; ?>
                </div>
                <?php if (trim((string) ($it['notes'] ?? '')) !== ''): ?>
                  <div class="bkd-value bkd-plain" style="margin-top:.25rem"><?= e((string) $it['notes']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$g['stops']): ?>
              <p class="bkd-muted">No stops on this day.</p>
            <?php endif; ?>
            </div>
            <?php $dayOpen = false; ?>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** The booking summary the tourist confirms, for the guide's View details popup. */
function booking_tourist_summary_html(array $booking): string
{
    $itinId = (int) ($booking['itinerary_id'] ?? 0);
    $itinRow = [
        'start_date' => $booking['itin_start'] ?? $booking['tour_start_date'] ?? null,
        'end_date' => $booking['itin_end'] ?? $booking['tour_end_date'] ?? null,
        'meetup_time' => $booking['meetup_time'] ?? null,
        'template_day_count' => $booking['template_day_count'] ?? null,
        'is_suggested' => $booking['is_suggested'] ?? 0,
    ];
    $dayCount = $itinId > 0 ? itinerary_logical_day_count($itinRow) : 1;
    $start = normalize_tour_date((string) ($itinRow['start_date'] ?? '')) ?: '';
    $meetup = $itinId > 0
        ? itinerary_day_meetup_place($itinId, 1, [
            'meetup_time' => $booking['meetup_time'] ?? null,
            'start_date' => $start,
        ])
        : [];
    $meetupTime = format_time_12h((string) ($booking['start_time'] ?? $booking['meetup_time'] ?? ''));
    $meetupText = trim($meetupTime . ((($meetup['label'] ?? '') !== '') ? ' · ' . (string) $meetup['label'] : ''));
    if ($meetupText === '') {
        $meetupText = 'Not set';
    }
    $parsed = booking_summary_parse_notes((string) ($booking['notes'] ?? ''));
    $party = max(1, (int) ($booking['party_size'] ?? 1));
    $guestText = $parsed['guests'] !== '' ? $parsed['guests'] : ($party . ' guest' . ($party === 1 ? '' : 's'));
    $needsText = $parsed['needs'] !== '' ? $parsed['needs'] : 'None';
    $stayText = stay_ride_label($booking, 'accommodation');
    $rideText = stay_ride_label($booking, 'transportation');
    $stayBill = booking_summary_side_bill($booking, 'accommodation', 'room');
    $rideBill = booking_summary_side_bill($booking, 'transportation', 'vehicle');
    $guideAmount = round((float) ($booking['guide_amount'] ?? 0), 2);
    $total = round((float) ($booking['payment_amount'] ?? ($guideAmount + $stayBill['amount'] + $rideBill['amount'])), 2);
    $stops = $itinId > 0 ? count(itinerary_items_with_places($itinId)) : 0;
    $title = trim((string) ($booking['itinerary_title'] ?? ''));
    if ($title === '') {
        $title = 'Unnamed';
    }
    $rows = [
        ['Tour guide', (string) ($booking['guide_name'] ?? 'Not chosen')],
        ['Tour name', $title],
        ['Date', $start !== '' ? format_date($start) : 'Not set'],
        ['Days', $dayCount . ' day' . ($dayCount === 1 ? '' : 's')],
        ['Meetup', $meetupText],
        ['Guests', $guestText],
        ['Special requirements', $needsText],
        ['Stay', $stayText],
        ['Ride', $rideText],
        ['Stops', $stops . ' stop' . ($stops === 1 ? '' : 's')],
    ];
    ob_start();
    echo '<dl class="itin-book-review-list mb-0">';
    foreach ($rows as [$label, $value]) {
        echo '<div><dt>' . e($label) . '</dt><dd>' . e($value) . '</dd></div>';
    }
    echo '</dl>';
    if ($itinId > 0 && function_exists('reservation_itinerary_map')) {
        $reservations = reservation_itinerary_map($itinId);
        if ($reservations) {
            echo '<div class="itin-book-reserves">';
            foreach ($reservations as $reservation) {
                if (!is_array($reservation)) {
                    continue;
                }
                $copy = is_array($reservation['copy'] ?? null) ? $reservation['copy'] : reservation_place_copy((string) ($reservation['kind'] ?? 'food'));
                $mode = ($reservation['order_mode'] ?? '') === 'advance_order'
                    ? (string) ($copy['ahead'] ?? 'Booked ahead')
                    : (string) ($copy['at_place_short'] ?? 'Choose on arrival');
                $dayLabel = '';
                if ($dayCount > 1) {
                    $visit = (string) ($reservation['visit_date'] ?? $reservation['stop_visit_date'] ?? '');
                    $dayNum = $visit !== '' && $start !== '' ? itinerary_day_number($start, $visit) : 1;
                    $dayLabel = ' · Day ' . $dayNum;
                }
                $items = [];
                foreach ($reservation['lines'] ?? [] as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $items[] = (int) ($line['qty'] ?? 0) . ' × ' . (string) ($line['item_name'] ?? '');
                }
                echo '<article class="itin-book-reserve">';
                echo '<div class="itin-book-reserve__label">Reservation' . e($dayLabel) . '</div>';
                echo '<strong>' . e((string) ($reservation['business_name'] ?? '')) . '</strong>';
                echo '<div>' . e($mode) . '</div>';
                if ($items) {
                    echo '<div>' . e(implode(', ', $items)) . '</div>';
                    echo '<div class="itin-book-reserve__total">' . e((string) ($copy['total'] ?? 'Total')) . ' ' . e(format_money((float) ($reservation['food_total'] ?? 0))) . '</div>';
                }
                echo '<div class="itin-book-reserve__note">Paid at the place. Not included in the CulTour booking total.</div>';
                echo '</article>';
            }
            echo '</div>';
        }
    }
    echo '<div class="guide-book-bill" data-guide-bill>';
    echo '<ul class="guide-book-bill__rows">';
    echo '<li><span>Guests</span><strong>' . e((string) $party) . '</strong></li>';
    echo '<li><span>Guide</span><strong>' . e(format_money($guideAmount) . ' · ' . $dayCount . ($dayCount === 1 ? ' day' : ' days')) . '</strong></li>';
    echo '<li><span>Stay</span><strong>' . e($stayBill['headline']) . '</strong></li>';
    if ($stayBill['detail'] !== '') {
        echo '<li class="is-math">' . e($stayBill['detail']) . '</li>';
    }
    echo '<li><span>Transportation</span><strong>' . e($rideBill['headline']) . '</strong></li>';
    if ($rideBill['detail'] !== '') {
        echo '<li class="is-math">' . e($rideBill['detail']) . '</li>';
    }
    echo '<li class="is-total"><span>Total</span><strong>' . e(format_money($total)) . '</strong></li>';
    echo '</ul>';
    echo '<label class="itin-book-review-footer__note"><span>Note for the guide</span>';
    echo '<div class="form-control form-control-sm" style="min-height:4.75rem;white-space:pre-wrap;">' . e($parsed['note'] !== '' ? $parsed['note'] : 'No note') . '</div>';
    echo '</label></div>';
    return (string) ob_get_clean();
}

/** @return array{guests:string,needs:string,note:string} */
function booking_summary_parse_notes(string $notes): array
{
    $adults = null;
    $children = null;
    $infants = null;
    $needs = [];
    $user = [];
    $seen = false;
    foreach (preg_split('/\r\n|\n/', $notes) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^Adults:\s*(\d+)/', $line, $m)) {
            $seen = true;
            $adults = (int) $m[1];
            continue;
        }
        if (preg_match('/^Children:\s*(\d+)/', $line, $m)) {
            $seen = true;
            $children = (int) $m[1];
            continue;
        }
        if (preg_match('/^Infant\/toddler:\s*(\d+)/', $line, $m)) {
            $seen = true;
            $infants = (int) $m[1];
            continue;
        }
        if ($seen) {
            $needs[] = $line;
        } else {
            $user[] = $line;
        }
    }
    $guest = '';
    if ($adults !== null || $children !== null || $infants) {
        $guest = (int) $adults . ' adults, ' . (int) $children . ' children';
        if ($infants) {
            $guest .= ', ' . $infants . ' infant/toddler (not counted)';
        }
    }
    return [
        'guests' => $guest,
        'needs' => implode('; ', $needs),
        'note' => implode("\n", $user),
    ];
}

/** @return array{headline:string,detail:string,amount:float} */
function booking_summary_side_bill(array $booking, string $kind, string $noun): array
{
    $declined = (int) ($booking[$kind . '_declined'] ?? 0) === 1;
    $amount = round((float) ($booking[$kind . '_amount'] ?? 0), 2);
    if ($declined) {
        return ['headline' => 'Not needed', 'detail' => '', 'amount' => 0.0];
    }
    $column = $kind === 'transportation' ? 'transportation' : 'accommodation';
    $lines = stay_lines_list($booking[$column . '_lines'] ?? null) ?: [];
    $labels = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !empty($line['skip'])) {
            continue;
        }
        $label = trim((string) ($line['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (isset($line['amount'])) {
            $label .= ' · ' . format_money((float) $line['amount']);
        }
        $labels[] = $label;
    }
    $units = (int) ($booking[$column . '_units'] ?? 0);
    $extras = (int) ($booking[$column . '_extra_guests'] ?? 0);
    if ($units < 1 && $labels === []) {
        return ['headline' => 'Not chosen', 'detail' => '', 'amount' => $amount];
    }
    $headline = $units . ' ' . $noun . ($units === 1 ? '' : 's');
    if ($kind === 'accommodation' && $extras > 0) {
        $headline .= ' · ' . $extras . ' extra guest' . ($extras === 1 ? '' : 's');
    }
    $headline .= ' · ' . format_money($amount);
    return ['headline' => $headline, 'detail' => implode("\n", $labels), 'amount' => $amount];
}

function booking_detail_row(string $label, string $valueHtml): void
{
    if (trim(strip_tags($valueHtml)) === '') {
        return;
    }
    echo '<div class="bkd-line"><div class="bkd-label">' . e($label) . '</div><div class="bkd-value">' . $valueHtml . '</div></div>';
}
