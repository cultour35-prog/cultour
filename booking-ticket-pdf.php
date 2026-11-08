<?php
/**
 * Download booking ticket + QR as PDF.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/includes/lib/fpdf.php';
$user = require_login();
ensure_booking_payment_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT b.*,
            CONCAT(t.first_name,' ',t.last_name) AS tourist_name, t.email AS tourist_email, t.phone AS tourist_phone,
            CONCAT(gu.first_name,' ',gu.last_name) AS guide_name, gu.phone AS guide_phone,
            d.name AS destination_name, d.location AS destination_location,
            i.title AS itinerary_title
     FROM bookings b
     JOIN users t ON t.id = b.tourist_id
     JOIN tour_guide_profiles gp ON gp.id = b.guide_id
     JOIN users gu ON gu.id = gp.user_id
     LEFT JOIN destinations d ON d.id = b.destination_id
     LEFT JOIN itineraries i ON i.id = b.itinerary_id
     WHERE b.id = ?"
);
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    flash('error', 'Booking not found.');
    redirect(role_dashboard((int) $user['role_id']));
}

$allowed = booking_user_can_access_ticket($user, $b);
if (!$allowed) {
    flash('error', 'Access denied.');
    redirect(role_dashboard((int) $user['role_id']));
}

$ticketReady = in_array($b['status'] ?? '', ['approved', 'completed'], true);
if ($ticketReady) {
    booking_qr_for($b);
    $stmt->execute([$id]);
    $b = $stmt->fetch();
}

function ticket_pdf_text(?string $s): string
{
    $s = (string) $s;
    if ($s === '') {
        return '';
    }
    $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    return $out !== false ? $out : preg_replace('/[^\x20-\x7E]/', '?', $s);
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle('CulTour Ticket ' . ticket_pdf_text($b['booking_code'] ?? ''));
$pdf->SetAuthor('CulTour');
$pdf->SetMargins(16, 16, 16);
$pdf->AddPage();

// Header bar
$pdf->SetFillColor(11, 95, 107);
$pdf->Rect(0, 0, 210, 32, 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(16, 10);
$pdf->SetFont('Helvetica', 'B', 20);
$pdf->Cell(100, 8, 'CulTour', 0, 0, 'L');
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(78, 8, ticket_pdf_text(strtoupper((string) ($b['status'] ?? ''))), 0, 1, 'R');
$pdf->SetX(16);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(100, 5, 'Ormoc City Tourism Office', 0, 0, 'L');
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(78, 5, ticket_pdf_text($b['booking_code'] ?? ''), 0, 1, 'R');

$pdf->SetY(40);
$pdf->SetTextColor(20, 36, 42);

$schedule = format_date($b['booking_date'] ?? null, 'l, M j, Y')
    . ' · ' . format_time_12h($b['start_time'] ?? null);
if (!empty($b['end_time'])) {
    $schedule .= ' - ' . format_time_12h($b['end_time']);
}

$rows = [
    ['Tourist', $b['tourist_name'] ?? ''],
    ['Tour Guide', $b['guide_name'] ?? ''],
    ['Schedule', $schedule],
    ['Party size', ((int) ($b['party_size'] ?? 1)) . ' guest(s)'],
    ['Itinerary', $b['itinerary_title'] ?: '—'],
];
foreach (reservation_itinerary_map((int) ($b['itinerary_id'] ?? 0)) as $ticketReservation) {
    $rows[] = ['Reservation', implode(' · ', reservation_summary_lines($ticketReservation))];
}
if (booking_is_pwd($b)) {
    $pwdLine = 'Accessibility needs';
    if (!empty($b['pwd_notes'])) {
        $pwdLine .= ' · ' . $b['pwd_notes'];
    }
    if (!empty($b['fitness_certificate_path'])) {
        $pwdLine .= ' · Fitness-to-travel certificate on file';
    }
    $rows[] = ['Accessibility', $pwdLine];
}
if (!empty($b['notes'])) {
    $rows[] = ['Notes', $b['notes']];
}

$leftW = 110;
foreach ($rows as [$label, $value]) {
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(106, 124, 131);
    $pdf->Cell($leftW, 4, ticket_pdf_text(strtoupper($label)), 0, 1);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(20, 36, 42);
    $pdf->MultiCell($leftW, 6, ticket_pdf_text((string) $value), 0, 'L');
    $pdf->Ln(2);
}

// QR on the right
$qrPath = '';
if ($ticketReady && !empty($b['qr_code_path'])) {
    $candidate = UPLOAD_PATH . '/' . ltrim((string) $b['qr_code_path'], '/\\');
    if (is_file($candidate)) {
        $qrPath = $candidate;
    }
}

$qrX = 140;
$qrY = 42;
$pdf->SetXY($qrX, $qrY);
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->SetTextColor(11, 95, 107);
$pdf->Cell(50, 6, 'Scan QR', 0, 1, 'C');

if ($qrPath !== '') {
    try {
        $pdf->Image($qrPath, $qrX + 5, $qrY + 10, 40, 40);
    } catch (Throwable $e) {
        $pdf->SetXY($qrX, $qrY + 18);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(92, 111, 118);
        $pdf->MultiCell(50, 5, 'QR image could not be embedded.', 0, 'C');
    }
} elseif ($ticketReady) {
    $pdf->SetXY($qrX, $qrY + 18);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(92, 111, 118);
    $pdf->MultiCell(50, 5, ticket_pdf_text('QR unavailable. Use code ' . ($b['booking_code'] ?? '')), 0, 'C');
} else {
    $pdf->SetXY($qrX, $qrY + 18);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(92, 111, 118);
    $pdf->MultiCell(50, 5, 'Ticket & QR unlock after the guide accepts this booking.', 0, 'C');
}

$pdf->SetXY($qrX, $qrY + 55);
$pdf->SetFont('Helvetica', '', 8);
$pdf->SetTextColor(106, 124, 131);
$pdf->MultiCell(50, 4, ticket_pdf_text('Code ' . ($b['booking_code'] ?? '') . "\nScan to open this ticket"), 0, 'C');

$pdf->SetY(max($pdf->GetY(), 170));
$pdf->SetDrawColor(213, 224, 227);
$pdf->Line(16, $pdf->GetY(), 194, $pdf->GetY());
$pdf->Ln(6);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(92, 111, 118);
$pdf->Cell(100, 5, 'Present this ticket to your tour guide', 0, 0, 'L');
$pdf->Cell(78, 5, ticket_pdf_text('Generated ' . date('M j, Y g:i A')), 0, 1, 'R');

$filename = 'cultour-ticket-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($b['booking_code'] ?? $id)) . '.pdf';
$pdf->Output('D', $filename);
exit;
