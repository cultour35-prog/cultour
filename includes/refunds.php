<?php
/**
 * Refund queue.
 * needs_details: the guide declined or cancelled a paid booking, the tourist still has to say where to send the money.
 * requested: the tourist gave a GCash number or QR, the Tourism Office sends it from Admin → Refunds.
 * sent: the Tourism Office sent the money (payment_status becomes refunded).
 */

const REFUND_NEEDS_DETAILS = 'needs_details';
const REFUND_REQUESTED = 'requested';
const REFUND_SENT = 'sent';

/** @return array{number:string,qr:string} */
function refund_saved_destination(int $userId): array
{
    if ($userId < 1) {
        return ['number' => '', 'qr' => ''];
    }
    ensure_booking_refund_schema();
    try {
        $st = db()->prepare('SELECT refund_gcash_number, refund_gcash_qr_path FROM users WHERE id=?');
        $st->execute([$userId]);
        $row = $st->fetch() ?: [];
    } catch (Throwable $e) {
        $row = [];
    }
    return [
        'number' => (string) ($row['refund_gcash_number'] ?? ''),
        'qr' => (string) ($row['refund_gcash_qr_path'] ?? ''),
    ];
}

function refund_remember_destination(int $userId, ?string $number, string $qrPath): void
{
    if ($userId < 1 || ($number === null && $qrPath === '')) {
        return;
    }
    ensure_booking_refund_schema();
    db()->prepare(
        'UPDATE users
         SET refund_gcash_number = COALESCE(?, refund_gcash_number),
             refund_gcash_qr_path = COALESCE(?, refund_gcash_qr_path)
         WHERE id=?'
    )->execute([$number, $qrPath !== '' ? $qrPath : null, $userId]);
}

/** Reads the save / use-saved-QR checkboxes from a web form or API body. */
function refund_opts_from(array $in): array
{
    $on = static fn ($v): bool => in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    return [
        'save' => $on($in['refund_save'] ?? ''),
        'use_saved_qr' => $on($in['refund_use_saved_qr'] ?? ''),
    ];
}

/**
 * GCash number and/or QR from the request, falling back to the saved QR when asked.
 * @return array{ok:bool,message:?string,number:?string,qr:string}
 */
function refund_read_destination(int $touristId, ?string $rawNumber, array $opts = []): array
{
    ensure_booking_refund_schema();
    $digits = preg_replace('/\D+/', '', (string) $rawNumber) ?? '';
    $number = $digits === '' ? null : booking_refund_number_ok((string) $rawNumber);
    if ($digits !== '' && $number === null) {
        return ['ok' => false, 'message' => 'Enter an 11-digit GCash number starting with 09.', 'number' => null, 'qr' => ''];
    }
    $upload = booking_save_refund_qr(null, false);
    if (!$upload['ok']) {
        return ['ok' => false, 'message' => (string) $upload['message'], 'number' => null, 'qr' => ''];
    }
    $qr = $upload['path'] !== null ? (string) $upload['path'] : '';
    if ($qr === '' && !empty($opts['use_saved_qr'])) {
        $qr = refund_saved_destination($touristId)['qr'];
    }
    if ($number === null && $qr === '') {
        return ['ok' => false, 'message' => 'Enter a GCash number or upload a QR.', 'number' => null, 'qr' => ''];
    }
    if (!empty($opts['save'])) {
        refund_remember_destination($touristId, $number, $qr);
    }
    return ['ok' => true, 'message' => null, 'number' => $number, 'qr' => $qr];
}

function refund_destination_label(?string $number, string $qr): string
{
    if ($number !== null && $number !== '') {
        return 'GCash ' . $number;
    }
    return $qr !== '' ? 'their GCash QR' : 'GCash';
}

/** Tells the tourist and the Tourism Office that a refund is ready to send. */
function refund_notify_requested(array $booking, float $amount, ?string $number, string $why): void
{
    $code = (string) ($booking['booking_code'] ?? '');
    $to = $number !== null && $number !== '' ? $number : 'your GCash QR';
    notify_user(
        (int) $booking['tourist_id'],
        'refund_requested',
        'Refund requested',
        'Your ' . format_money($amount) . ' refund for ' . $code . ' is with the Tourism Office. It will be sent to ' . $to . '.',
        APP_URL . '/tourist/bookings.php'
    );
    notify_active_admins(
        'refund_requested',
        'Refund needed',
        $code . ': ' . trim($why) . ' Send ' . format_money($amount) . ' to ' . ($number !== null && $number !== '' ? 'GCash ' . $number : 'the tourist\'s GCash QR') . '.',
        APP_URL . '/admin/refunds.php'
    );
}

/** A paid booking ended on the guide's side; the tourist is asked where to send the money. */
function refund_open_awaiting_details(array $booking, float $amount, string $why): void
{
    ensure_booking_refund_schema();
    $id = (int) ($booking['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    db()->prepare(
        "UPDATE bookings
         SET refund_status=?, refund_amount=?, refund_note=?, refund_requested_at=NULL
         WHERE id=? AND payment_status='paid' AND (refund_status IS NULL OR refund_status='')"
    )->execute([REFUND_NEEDS_DETAILS, $amount, mb_substr(trim($why), 0, 255), $id]);
    notify_active_admins(
        'refund_requested',
        'Refund pending',
        ($booking['booking_code'] ?? '') . ': ' . trim($why) . ' Waiting for the tourist\'s GCash details (' . format_money($amount) . ').',
        APP_URL . '/admin/refunds.php?status=' . REFUND_NEEDS_DETAILS
    );
}

/**
 * Tourist sends GCash details for a refund the guide triggered.
 * @return array{ok:bool,message:string}
 */
function refund_submit_details(array $booking, int $touristId, ?string $rawNumber, array $opts = []): array
{
    ensure_booking_refund_schema();
    if ((int) ($booking['tourist_id'] ?? 0) !== $touristId) {
        return ['ok' => false, 'message' => 'Booking not found.'];
    }
    $state = (string) ($booking['refund_status'] ?? '');
    if (!in_array($state, [REFUND_NEEDS_DETAILS, REFUND_REQUESTED], true)) {
        return ['ok' => false, 'message' => 'This booking has no refund waiting for your details.'];
    }
    $dest = refund_read_destination($touristId, $rawNumber, $opts);
    if (!$dest['ok']) {
        return ['ok' => false, 'message' => (string) $dest['message']];
    }
    $st = db()->prepare(
        "UPDATE bookings
         SET refund_status=?, refund_number=?, refund_qr_path=?, refund_requested_at=NOW()
         WHERE id=? AND refund_status IN (?, ?)"
    );
    $st->execute([REFUND_REQUESTED, $dest['number'], $dest['qr'], (int) $booking['id'], REFUND_NEEDS_DETAILS, REFUND_REQUESTED]);
    if ($st->rowCount() < 1 && $state !== REFUND_REQUESTED) {
        return ['ok' => false, 'message' => 'This refund could not be updated.'];
    }
    $amount = (float) ($booking['refund_amount'] ?? $booking['payment_amount'] ?? 0);
    $why = trim((string) ($booking['refund_note'] ?? ''));
    refund_notify_requested($booking, $amount, $dest['number'], $why !== '' ? $why : 'Refund details received.');
    $to = $dest['number'] !== null ? $dest['number'] : 'your GCash QR';
    return ['ok' => true, 'message' => 'Refund details sent. The Tourism Office will send ' . format_money($amount) . ' to ' . $to . '.'];
}

/**
 * Admin sent the money.
 * @return array{ok:bool,message:string}
 */
function refund_mark_sent(int $bookingId, int $adminId, string $reference = '', string $note = ''): array
{
    ensure_booking_refund_schema();
    $st = db()->prepare('SELECT * FROM bookings WHERE id=?');
    $st->execute([$bookingId]);
    $b = $st->fetch();
    if (!$b || ($b['refund_status'] ?? '') !== REFUND_REQUESTED) {
        return ['ok' => false, 'message' => 'This refund is not ready to send.'];
    }
    $ref = strtoupper(trim($reference));
    if ($ref === '') {
        $ref = 'RF-' . strtoupper(bin2hex(random_bytes(4)));
    }
    if (strlen($ref) > 40) {
        return ['ok' => false, 'message' => 'Keep the reference number under 40 characters.'];
    }
    $note = mb_substr(trim($note), 0, 255);
    $up = db()->prepare(
        "UPDATE bookings
         SET payment_status='refunded', refund_status=?, refund_reference=?, refund_sent_at=NOW(), refund_sent_by=?,
             refund_note=COALESCE(NULLIF(?, ''), refund_note)
         WHERE id=? AND refund_status=?"
    );
    $up->execute([REFUND_SENT, $ref, $adminId, $note, $bookingId, REFUND_REQUESTED]);
    if ($up->rowCount() < 1) {
        return ['ok' => false, 'message' => 'This refund was already handled.'];
    }
    $touristId = (int) ($b['tourist_id'] ?? 0);
    $amount = (float) ($b['refund_amount'] ?? $b['payment_amount'] ?? 0);
    $code = (string) ($b['booking_code'] ?? '');
    $to = !empty($b['refund_number']) ? (string) $b['refund_number'] : 'your GCash QR';
    $body = format_money($amount) . ' for ' . $code . ' was sent to ' . $to . '. Reference ' . $ref . '.'
        . ($note !== '' ? ' Note: ' . $note : '');
    if ($touristId > 0) {
        try {
            notify_user(
                $touristId,
                'refund_sent',
                'Your refund was sent',
                $body,
                APP_URL . '/tourist/bookings.php'
            );
        } catch (Throwable $e) {
            // Refund is already marked sent; do not roll it back if the notice fails.
        }
    }
    if (function_exists('log_activity')) {
        log_activity($adminId, 'refund_sent', 'bookings', $code . ': ' . format_money($amount) . ' refunded (' . $ref . ')');
    }
    return ['ok' => true, 'message' => 'Refund ' . $ref . ' marked as sent. The tourist was notified.'];
}

/** @return array{ok:bool,message:string} */
function refund_remind_tourist(int $bookingId): array
{
    ensure_booking_refund_schema();
    $st = db()->prepare('SELECT * FROM bookings WHERE id=?');
    $st->execute([$bookingId]);
    $b = $st->fetch();
    if (!$b || ($b['refund_status'] ?? '') !== REFUND_NEEDS_DETAILS) {
        return ['ok' => false, 'message' => 'This refund is no longer waiting for the tourist.'];
    }
    $amount = (float) ($b['refund_amount'] ?? $b['payment_amount'] ?? 0);
    notify_user(
        (int) $b['tourist_id'],
        'refund_details',
        'Add your refund details',
        'Enter your GCash number or QR to receive your ' . format_money($amount) . ' refund for ' . $b['booking_code'] . '.',
        APP_URL . '/tourist/bookings.php'
    );
    return ['ok' => true, 'message' => 'Reminder sent to the tourist.'];
}

function refund_admin_pending_count(): int
{
    try {
        ensure_booking_refund_schema();
        $st = db()->prepare('SELECT COUNT(*) FROM bookings WHERE refund_status=?');
        $st->execute([REFUND_REQUESTED]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array<string,int> */
function refund_admin_counts(): array
{
    ensure_booking_refund_schema();
    $out = [REFUND_REQUESTED => 0, REFUND_NEEDS_DETAILS => 0, REFUND_SENT => 0];
    $rows = db()->query(
        "SELECT refund_status, COUNT(*) AS n FROM bookings WHERE refund_status IS NOT NULL AND refund_status<>'' GROUP BY refund_status"
    )->fetchAll() ?: [];
    foreach ($rows as $r) {
        $out[(string) $r['refund_status']] = (int) $r['n'];
    }
    return $out;
}

/** @return list<array> */
function refund_admin_rows(string $filter): array
{
    ensure_booking_refund_schema();
    $states = [REFUND_REQUESTED, REFUND_NEEDS_DETAILS, REFUND_SENT];
    $where = in_array($filter, $states, true) ? 'b.refund_status = ?' : 'b.refund_status IN (?, ?, ?)';
    $params = in_array($filter, $states, true) ? [$filter] : $states;
    $order = $filter === REFUND_REQUESTED
        ? 'b.refund_requested_at ASC, b.id ASC'
        : 'COALESCE(b.refund_sent_at, b.refund_requested_at, b.updated_at, b.created_at) DESC, b.id DESC';
    $st = db()->prepare(
        "SELECT b.*,
                CONCAT(t.first_name,' ',t.last_name) AS tourist_name, t.email AS tourist_email,
                CONCAT(gu.first_name,' ',gu.last_name) AS guide_name,
                CONCAT(a.first_name,' ',a.last_name) AS sent_by_name
         FROM bookings b
         JOIN users t ON t.id = b.tourist_id
         LEFT JOIN tour_guide_profiles gp ON gp.id = b.guide_id
         LEFT JOIN users gu ON gu.id = gp.user_id
         LEFT JOIN users a ON a.id = b.refund_sent_by
         WHERE $where
         ORDER BY $order
         LIMIT 200"
    );
    $st->execute($params);
    return $st->fetchAll() ?: [];
}

/** @return list<array> */
function refund_tourist_awaiting(int $touristId): array
{
    if ($touristId < 1) {
        return [];
    }
    ensure_booking_refund_schema();
    $st = db()->prepare(
        'SELECT id, booking_code, status, payment_amount, refund_amount, refund_note
         FROM bookings WHERE tourist_id=? AND refund_status=? ORDER BY id ASC LIMIT 10'
    );
    $st->execute([$touristId, REFUND_NEEDS_DETAILS]);
    return $st->fetchAll() ?: [];
}

function refund_amount_of(array $b): float
{
    return (float) (($b['refund_amount'] ?? null) !== null ? $b['refund_amount'] : ($b['payment_amount'] ?? 0));
}

/** Short refund line for the tourist's booking card. */
function refund_tourist_status_html(array $b): string
{
    $state = (string) ($b['refund_status'] ?? '');
    $amount = format_money(refund_amount_of($b));
    if ($state === REFUND_NEEDS_DETAILS) {
        return '<div class="col-12"><div class="refund-line refund-line--ask">'
            . '<i class="bi bi-cash-coin"></i><span>Refund of ' . e($amount) . ' is waiting for your GCash details.</span>'
            . '<button type="button" class="btn btn-sm btn-ct" data-refund-details="' . (int) $b['id'] . '"'
            . ' data-refund-code="' . e((string) $b['booking_code']) . '" data-refund-amount="' . e($amount) . '">Add refund details</button>'
            . '</div></div>';
    }
    if ($state === REFUND_REQUESTED) {
        $to = !empty($b['refund_number']) ? (string) $b['refund_number'] : 'your GCash QR';
        return '<div class="col-12"><div class="refund-line"><i class="bi bi-hourglass-split"></i>'
            . '<span>Refund of ' . e($amount) . ' requested. The Tourism Office will send it to ' . e($to) . '.</span></div></div>';
    }
    if ($state === REFUND_SENT) {
        return '<div class="col-12"><div class="refund-line refund-line--done"><i class="bi bi-check-circle-fill"></i>'
            . '<span>Refunded ' . e($amount)
            . (!empty($b['refund_reference']) ? ' · Ref ' . e((string) $b['refund_reference']) : '')
            . '</span></div></div>';
    }
    return '';
}

/** Popup on tourist pages when a guide-side refund still needs a GCash number or QR. */
function refund_tourist_popup_html(array $user): string
{
    if ((int) ($user['role_id'] ?? 0) !== ROLE_TOURIST) {
        return '';
    }
    $awaiting = refund_tourist_awaiting((int) $user['id']);
    $first = $awaiting[0] ?? null;
    $return = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $html = '<div class="modal fade refund-ask-modal" id="ctRefundDetailsModal" tabindex="-1" aria-labelledby="ctRefundDetailsLabel" aria-hidden="true"'
        . ($first ? ' data-auto-open="' . (int) $first['id'] . '"' : '') . '>';
    $html .= '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">';
    $html .= '<form method="post" action="' . e(APP_URL . '/tourist/refund-details.php') . '" enctype="multipart/form-data">' . csrf_field();
    $html .= '<input type="hidden" name="booking_id" value="' . ($first ? (int) $first['id'] : '') . '" data-refund-ask-id>';
    $html .= '<input type="hidden" name="return" value="' . e($return) . '">';
    $html .= '<div class="modal-header border-0"><div>'
        . '<h2 class="modal-title h5 mb-1" id="ctRefundDetailsLabel"><i class="bi bi-cash-coin me-1"></i>Where should we send your refund?</h2>'
        . '<div class="small text-muted" data-refund-ask-lead>'
        . ($first
            ? e((string) $first['booking_code']) . ' · ' . e(format_money(refund_amount_of($first)))
                . (!empty($first['refund_note']) ? ' · ' . e((string) $first['refund_note']) : '')
            : '')
        . '</div></div>';
    $html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>';
    $html .= '<div class="modal-body pt-0">';
    $html .= '<p class="small text-muted">Your booking did not push through, so the Tourism Office will refund your payment. Enter your GCash number or upload your GCash QR.</p>';
    $html .= booking_refund_destination_fields('refundAskNumber', refund_saved_destination((int) $user['id']));
    $html .= '</div>';
    $html .= '<div class="modal-footer border-0 pt-0">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-refund-ask-later>Later</button>'
        . '<button type="submit" class="btn btn-ct">Send refund details</button></div>';
    $html .= '</form></div></div></div>';
    $html .= booking_refund_destination_script();
    $html .= <<<'HTML'
<script>
(function () {
  var modal = document.getElementById('ctRefundDetailsModal');
  if (!modal || typeof bootstrap === 'undefined') return;
  if (modal.parentElement !== document.body) document.body.appendChild(modal);
  var idInput = modal.querySelector('[data-refund-ask-id]');
  var lead = modal.querySelector('[data-refund-ask-lead]');
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-refund-details]');
    if (!btn) return;
    e.preventDefault();
    if (idInput) idInput.value = btn.getAttribute('data-refund-details') || '';
    if (lead) lead.textContent = (btn.getAttribute('data-refund-code') || '') + ' · ' + (btn.getAttribute('data-refund-amount') || '');
    bootstrap.Modal.getOrCreateInstance(modal).show();
  });
  modal.addEventListener('click', function (e) {
    if (!e.target.closest('[data-refund-ask-later]')) return;
    try { sessionStorage.setItem('ctRefundLater:' + (idInput ? idInput.value : ''), '1'); } catch (err) {}
  });
  var auto = modal.getAttribute('data-auto-open');
  var later = false;
  try { later = sessionStorage.getItem('ctRefundLater:' + auto) === '1'; } catch (err) {}
  if (auto && !later) {
    document.addEventListener('DOMContentLoaded', function () {
      bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    if (document.readyState !== 'loading') bootstrap.Modal.getOrCreateInstance(modal).show();
  }
})();
</script>
HTML;
    return $html;
}

