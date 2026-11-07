<?php
/**
 * Answers from the replacement-guide pop-ups (guide offer + tourist confirm).
 */
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
$wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$role = (int) ($user['role_id'] ?? 0);
$back = $role === ROLE_TOUR_GUIDE ? APP_URL . '/tourguide/bookings.php' : APP_URL . '/tourist/bookings.php';

$finish = static function (array $res) use ($wantsJson, $back): never {
    if ($wantsJson) {
        json_response($res, $res['ok'] ? 200 : 400);
    }
    flash($res['ok'] ? 'success' : 'error', (string) $res['message']);
    redirect($back);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $finish(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$action = (string) ($_POST['action'] ?? '');

if ($role === ROLE_TOUR_GUIDE && in_array($action, ['guide_accept', 'guide_decline'], true)) {
    $gp = db()->prepare('SELECT id FROM tour_guide_profiles WHERE user_id=?');
    $gp->execute([(int) $user['id']]);
    $gid = (int) $gp->fetchColumn();
    $finish(booking_replacement_guide_respond(
        (int) ($_POST['offer_id'] ?? 0),
        $gid,
        $action === 'guide_accept'
    ));
}

if ($role === ROLE_TOURIST && in_array($action, ['tourist_accept', 'tourist_other', 'tourist_move'], true)) {
    $st = db()->prepare('SELECT * FROM bookings WHERE id=? AND tourist_id=? LIMIT 1');
    $st->execute([(int) ($_POST['id'] ?? 0), (int) $user['id']]);
    $booking = $st->fetch();
    if (!$booking) {
        $finish(['ok' => false, 'message' => 'Booking not found.']);
    }
    if ($action === 'tourist_accept') {
        $finish(booking_replacement_tourist_accept($booking, (int) $user['id']));
    }
    if ($action === 'tourist_other') {
        $finish(booking_replacement_tourist_other($booking));
    }
    $finish(booking_replacement_move_dates($booking, (string) ($_POST['new_start'] ?? '')));
}

$finish(['ok' => false, 'message' => 'That action is not available.']);
