<?php
/**
 * Download itinerary as PDF (separate from browser print).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lib/fpdf.php';
$user = require_login();
ensure_itinerary_items_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT i.*, CONCAT(u.first_name,' ',u.last_name) AS owner_name
     FROM itineraries i JOIN users u ON u.id=i.user_id WHERE i.id=?"
);
$stmt->execute([$id]);
$itin = $stmt->fetch();
if (!$itin) {
    flash('error', 'Itinerary not found.');
    redirect(role_dashboard((int) $user['role_id']));
}

$role = (int) $user['role_id'];
$allowed = $role === ROLE_ADMIN
    || (int) $user['id'] === (int) $itin['user_id']
    || itinerary_is_published_suggested($itin);
if (!$allowed && $role === ROLE_TOUR_GUIDE) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([(int) $user['id']]);
    $gid = (int) $gp->fetchColumn();
    if ($gid > 0) {
        if ((int) ($itin['created_by_guide_id'] ?? 0) === $gid && (int) ($itin['is_suggested'] ?? 0) === 1) {
            $allowed = true;
        } else {
            $link = db()->prepare(
                'SELECT id FROM bookings WHERE guide_id=? AND itinerary_id=? LIMIT 1'
            );
            $link->execute([$gid, $id]);
            $allowed = (bool) $link->fetchColumn();
        }
    }
}
if (!$allowed) {
    flash('error', 'Access denied.');
    redirect(role_dashboard($role));
}

$stops = itinerary_items_with_places($id);
$meetup = format_time_12h((string) ($itin['meetup_time'] ?? '08:00:00'));
$dayCount = itinerary_logical_day_count($itin);
$isMultiday = $dayCount > 1;
$stopsByDay = itinerary_group_stops_by_day($stops, $itin['start_date'] ?? null, $itin['end_date'] ?? null, $id, $itin);

/** Convert UTF-8 text to FPDF-safe latin1 (best-effort). */
function pdf_text(?string $s): string
{
    $s = (string) $s;
    if ($s === '') {
        return '';
    }
    $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    return $out !== false ? $out : preg_replace('/[^\x20-\x7E]/', '?', $s);
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle(pdf_text($itin['title'] ?? 'CulTour Itinerary'));
$pdf->SetAuthor('CulTour');
$pdf->SetMargins(16, 16, 16);
$pdf->AddPage();

// Header
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetTextColor(11, 95, 107);
$pdf->Cell(0, 6, 'CulTour itinerary', 0, 1, 'L');
$pdf->SetTextColor(20, 36, 42);
$pdf->SetFont('Helvetica', 'B', 18);
$pdf->MultiCell(0, 9, pdf_text($itin['title'] ?? 'Untitled tour'), 0, 'L');
$pdf->Ln(2);

$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(92, 111, 118);
$meta = ((int) ($itin['is_suggested'] ?? 0) ? 'Suggested tour' : 'Personal plan')
    . ' · ' . ($itin['owner_name'] ?? '');
$pdf->MultiCell(0, 5, pdf_text($meta), 0, 'L');

if ($isMultiday) {
    $dates = $dayCount . ' days · starts ' . format_date($itin['start_date'] ?? null);
} else {
    $dates = format_date($itin['start_date'] ?? null);
}
$pdf->MultiCell(0, 5, pdf_text($dates . ' · Meetup ' . $meetup . ' at Ormoc City Port'), 0, 'L');
$pdf->Ln(3);

if (!empty($itin['description'])) {
    $pdf->SetTextColor(20, 36, 42);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->MultiCell(0, 5, pdf_text($itin['description']), 0, 'L');
    $pdf->Ln(2);
}

$pdf->SetDrawColor(213, 224, 227);
$pdf->SetTextColor(20, 36, 42);

foreach ($stopsByDay as $g) {
    if ($pdf->GetY() > 260) {
        $pdf->AddPage();
    }

    if ($isMultiday) {
        $pdf->SetFillColor(238, 245, 246);
        $pdf->SetFont('Helvetica', 'B', 11);
        $label = $g['label'] . '  ·  ' . format_date($g['date']);
        $pdf->Cell(0, 8, pdf_text($label), 0, 1, 'L', true);
        $pdf->Ln(1);
    }

    if ((int) $g['day'] === 1) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(106, 124, 131);
        $pdf->Cell(0, 5, pdf_text('Start · ' . $meetup), 0, 1);
        $pdf->SetTextColor(20, 36, 42);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'Ormoc City Port', 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(106, 124, 131);
        $pdf->Cell(0, 5, 'Meetup point', 0, 1);
        $pdf->SetDrawColor(230, 236, 238);
        $pdf->Line(16, $pdf->GetY() + 1, 194, $pdf->GetY() + 1);
        $pdf->Ln(4);
        $pdf->SetTextColor(20, 36, 42);
    }

    foreach ($g['stops'] as $idx => $it) {
        if ($pdf->GetY() > 265) {
            $pdf->AddPage();
        }
        $kind = ($it['stop_kind'] ?? '') === 'business' ? 'Business' : 'Destination';
        $arrive = format_time_12h(isset($it['start_time']) ? (string) $it['start_time'] : null);
        $leave = format_time_12h(isset($it['end_time']) ? (string) $it['end_time'] : null);
        $visit = (int) ($it['visit_minutes'] ?? 60);
        $name = $it['stop_name'] ?? $it['destination_name'] ?? 'Stop';
        $loc = $it['location'] ?? '';

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(106, 124, 131);
        $pdf->Cell(0, 5, pdf_text('Stop ' . ($idx + 1) . ' · ' . $kind), 0, 1);
        $pdf->SetTextColor(20, 36, 42);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(0, 6, pdf_text($name), 0, 'L');
        if ($loc !== '') {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(106, 124, 131);
            $pdf->MultiCell(0, 5, pdf_text($loc), 0, 'L');
        }
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(20, 36, 42);
        $pdf->Cell(0, 5, pdf_text('Estimated time of arrival ' . $arrive . ' · Leave ' . $leave . ' · Stay ' . $visit . ' min'), 0, 1);
        $pdf->SetDrawColor(230, 236, 238);
        $pdf->Line(16, $pdf->GetY() + 1, 194, $pdf->GetY() + 1);
        $pdf->Ln(4);
    }

    if (!$g['stops']) {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(92, 111, 118);
        $pdf->Cell(0, 6, $isMultiday ? 'No stops on this day.' : 'No stops on this itinerary yet.', 0, 1);
        $pdf->Ln(2);
        $pdf->SetTextColor(20, 36, 42);
    }
}

$pdf->Ln(4);
$pdf->SetFont('Helvetica', '', 8);
$pdf->SetTextColor(92, 111, 118);
$pdf->MultiCell(0, 4, pdf_text('Generated by CulTour · Ormoc City Tourism'), 0, 'L');

$safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower((string) ($itin['title'] ?? 'itinerary')));
$safeTitle = trim($safeTitle, '-') ?: 'itinerary';
$filename = 'cultour-' . $safeTitle . '-' . $id . '.pdf';

$pdf->Output('D', $filename);
exit;
