<?php
/**
 * Plain-data versions of web panels, for the Flutter app.
 * Each builder mirrors the HTML version named in its comment so both stay in step.
 */

/** Stop label/name for an itinerary item row. */
function mobile_stop_name(array $stop): string
{
    $name = trim((string) ($stop['stop_name'] ?? $stop['destination_name'] ?? 'Stop'));
    return $name !== '' ? $name : 'Stop';
}

/** One check-in card from a progress row (photo check-in or skip). */
function mobile_checkin_state(?array $row): array
{
    if (!is_array($row)) {
        return ['state' => 'open', 'checked_in_label' => '', 'photo_url' => null, 'skip_reason' => ''];
    }
    if (!empty($row['skipped'])) {
        $when = format_date($row['skipped_at'] ?? '', 'M j, g:i A');
        return [
            'state' => 'skipped',
            'checked_in_label' => $when !== '—' ? $when : '',
            'photo_url' => null,
            'skip_reason' => (string) ($row['reason'] ?? ''),
        ];
    }
    $when = format_date($row['checked_in_at'] ?? '', 'M j, g:i A');
    return [
        'state' => 'done',
        'checked_in_label' => $when !== '—' ? $when : '',
        'photo_url' => booking_stop_checkin_photo_url($row),
        'skip_reason' => '',
    ];
}

/**
 * Itinerary with stops grouped by day plus each day's date and meetup (mirrors the web builder).
 *
 * @return array<string,mixed>
 */
function mobile_itinerary_payload(int $id): array
{
    $row = itinerary_row_by_id($id);
    if (!$row) {
        return [];
    }
    $stops = itinerary_items_with_places($id);
    $row['stops'] = $stops;
    $groups = itinerary_group_stops_by_day($stops, $row['start_date'] ?? null, $row['end_date'] ?? null, $id, $row);
    $days = [];
    foreach ($groups as $g) {
        $n = (int) ($g['day'] ?? 1);
        $place = itinerary_day_meetup_place($id, $n, $row);
        $days[] = [
            'day' => $n,
            'date' => (string) ($g['date'] ?? ''),
            'label' => (string) ($g['label'] ?? ('Day ' . $n)),
            'meetup' => [
                'label' => (string) ($place['label'] ?? ''),
                'kind' => (string) ($place['kind'] ?? ''),
                'key' => $place['key'] ?? null,
                'is_default' => !empty($place['is_default']),
                'has_place' => !empty($place['has_place']),
                'time' => (string) ($place['meetup_display'] ?? ''),
                'lat' => (float) ($place['lat'] ?? 0),
                'lng' => (float) ($place['lng'] ?? 0),
            ],
            'stop_ids' => array_map(static fn ($s) => (int) ($s['id'] ?? 0), $g['stops'] ?? []),
        ];
    }
    $row['day_count'] = itinerary_logical_day_count($row);
    $row['is_multiday'] = $row['day_count'] > 1;
    $row['days'] = $days;
    $row['meetup_label'] = format_time_12h($row['meetup_time'] ?? null);
    $row['locked'] = (int) ($row['is_suggested'] ?? 0) === 0 && itinerary_schedule_is_locked($id);
    $row['accommodation_lines'] = stay_lines_list($row['accommodation_lines'] ?? null) ?? [];
    $row['transportation_lines'] = stay_lines_list($row['transportation_lines'] ?? null) ?? [];
    $active = itinerary_active_booking($id);
    $row['active_booking'] = $active ? [
        'id' => (int) ($active['id'] ?? 0),
        'booking_code' => (string) ($active['booking_code'] ?? ''),
        'status' => (string) ($active['status'] ?? ''),
    ] : null;
    return $row;
}

/**
 * Check-in history by day (mirrors booking_history_details_html).
 *
 * @return array<string,mixed>
 */
function booking_progress_payload(array $booking): array
{
    $bid = (int) ($booking['id'] ?? 0);
    $dates = booking_visit_dates($booking);
    $progress = booking_stop_progress_map($bid);
    if (!$dates) {
        foreach ($progress as $row) {
            $vd = normalize_tour_date($row['visit_date'] ?? null);
            if ($vd !== null) {
                $dates[] = $vd;
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
    }
    $days = [];
    foreach ($dates as $i => $date) {
        $items = [];
        if ($i === 0) {
            $meetup = booking_checkin_meetup($booking, $date);
            $items[] = [
                'item_id' => 0,
                'label' => 'Meetup',
                'name' => (string) ($meetup['title'] ?? 'Meetup'),
                'scheduled' => format_time_12h($meetup['time'] ?? null),
            ] + mobile_checkin_state($progress[0] ?? null);
        }
        $n = 0;
        foreach (booking_stops_on_calendar_date($booking, $date) as $stop) {
            $n++;
            $sid = (int) ($stop['id'] ?? 0);
            $sched = '';
            if (!empty($stop['start_time'])) {
                $sched = format_time_12h($stop['start_time']);
                if (!empty($stop['end_time'])) {
                    $sched .= ' – ' . format_time_12h($stop['end_time']);
                }
            }
            $items[] = [
                'item_id' => $sid,
                'label' => 'Stop ' . $n,
                'name' => mobile_stop_name($stop),
                'scheduled' => $sched,
            ] + mobile_checkin_state($progress[$sid] ?? null);
        }
        $days[] = [
            'day' => $i + 1,
            'date' => $date,
            'date_label' => format_date($date),
            'ended' => booking_day_is_ended($bid, $date) || (string) ($booking['status'] ?? '') === 'completed',
            'items' => $items,
        ];
    }
    $title = trim((string) ($booking['itinerary_title'] ?? ''));
    return [
        'booking_id' => $bid,
        'booking_code' => (string) ($booking['booking_code'] ?? ''),
        'title' => $title !== '' ? $title : 'Tour ' . (string) ($booking['booking_code'] ?? ''),
        'guide_name' => trim((string) ($booking['guide_name'] ?? '')),
        'status' => (string) ($booking['status'] ?? ''),
        'days' => $days,
    ];
}

/**
 * Today's check-in board for a guide (mirrors live_tours_html + booking_stop_checkin_panel_html in guide mode).
 *
 * @return array<string,mixed>
 */
function guide_checkin_board_payload(int $guideProfileId): array
{
    ensure_booking_stop_checkin_schema();
    $today = app_today();
    $tours = $guideProfileId > 0 ? booking_tours_happening_on($today, $guideProfileId, ['approved']) : [];
    $out = [];
    foreach ($tours as $tour) {
        $bid = (int) $tour['id'];
        $progress = booking_stop_progress_map($bid);
        $canSubmit = booking_guide_can_checkin_on($tour, $guideProfileId, $today);
        $started = booking_tour_is_started($tour);
        $meetup = booking_checkin_meetup($tour, $today);
        $stops = array_merge(
            [[
                'id' => 0,
                'stop_name' => (string) $meetup['title'],
                'stop_location' => 'Meetup',
                'start_time' => $meetup['time'],
                'latitude' => $meetup['lat'] ?: null,
                'longitude' => $meetup['lng'] ?: null,
            ]],
            booking_stops_on_calendar_date($tour, $today)
        );
        $nextId = -1;
        if ($started) {
            foreach ($stops as $s) {
                if (!isset($progress[(int) ($s['id'] ?? 0)])) {
                    $nextId = (int) ($s['id'] ?? 0);
                    break;
                }
            }
        }
        $allDone = $started && $nextId < 0;
        $starts = booking_stop_starts_map($bid, $today);
        $items = [];
        $n = 0;
        foreach ($stops as $s) {
            $sid = (int) ($s['id'] ?? 0);
            $row = $progress[$sid] ?? null;
            $isNext = $sid === $nextId;
            $state = mobile_checkin_state($row);
            $wait = '';
            if ($row === null) {
                if (!$started) {
                    $state['state'] = 'not_started';
                } elseif (!$isNext) {
                    $state['state'] = 'locked';
                } elseif (!$canSubmit) {
                    $state['state'] = 'open';
                } elseif (booking_checkin_needs_early_start(
                    isset($s['start_time']) ? (string) $s['start_time'] : null,
                    $bid,
                    $sid,
                    $today,
                    $starts
                )) {
                    $state['state'] = 'wait';
                    $wait = booking_checkin_wait_label(isset($s['start_time']) ? (string) $s['start_time'] : null);
                } else {
                    $state['state'] = 'current';
                }
            }
            $sched = '';
            if (!empty($s['start_time'])) {
                $sched = format_time_12h($s['start_time']);
                if (!empty($s['end_time'])) {
                    $sched .= ' – ' . format_time_12h($s['end_time']);
                }
            }
            if ($sid !== 0) {
                $n++;
            }
            $lat = $s['latitude'] ?? null;
            $lng = $s['longitude'] ?? null;
            $items[] = [
                'item_id' => $sid,
                'label' => $sid === 0 ? 'Meetup' : 'Stop ' . $n,
                'name' => mobile_stop_name($s),
                'location' => trim((string) ($s['stop_location'] ?? $s['location'] ?? '')) ?: 'Ormoc area',
                'scheduled' => $sched,
                'lat' => ($lat !== null && $lat !== '') ? (float) $lat : null,
                'lng' => ($lng !== null && $lng !== '') ? (float) $lng : null,
                'is_meetup' => $sid === 0,
                'is_next' => $isNext,
                'wait_until' => $wait,
            ] + $state;
        }
        $laterDays = false;
        foreach (booking_visit_dates($tour) as $d) {
            if ($d > $today) {
                $laterDays = true;
                break;
            }
        }
        $title = trim((string) ($tour['itinerary_title'] ?? ''));
        $out[] = [
            'booking_id' => $bid,
            'booking_code' => (string) $tour['booking_code'],
            'title' => $title !== '' ? $title : 'Tour with ' . trim((string) ($tour['tourist_name'] ?? 'tourist')),
            'tourist_name' => (string) ($tour['tourist_name'] ?? ''),
            'tourist_user_id' => (int) ($tour['tourist_id'] ?? 0),
            'meetup_label' => format_time_12h($tour['start_time'] ?? $tour['itin_meetup'] ?? null),
            'party_size' => (int) ($tour['party_size'] ?? 1),
            'is_pwd' => !empty($tour['is_pwd']),
            'pwd_notes' => (string) ($tour['pwd_notes'] ?? ''),
            'can_submit' => $canSubmit,
            'tour_started' => $started,
            'all_done' => $allDone,
            'day_ended' => booking_day_is_ended($bid, $today),
            'has_later_days' => $laterDays,
            'end_label' => $laterDays ? 'End this day' : 'End tour',
            'items' => $items,
        ];
    }
    return [
        'date' => $today,
        'date_label' => format_date($today),
        'skip_reasons' => booking_stop_skip_reasons(),
        'tours' => $out,
    ];
}
