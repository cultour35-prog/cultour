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

function booking_detail_row(string $label, string $valueHtml): void
{
    if (trim(strip_tags($valueHtml)) === '') {
        return;
    }
    echo '<div class="bkd-line"><div class="bkd-label">' . e($label) . '</div><div class="bkd-value">' . $valueHtml . '</div></div>';
}
