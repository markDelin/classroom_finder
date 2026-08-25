<?php
declare(strict_types=1);

/**
 * Classroom Finder — QR scan validation (§9, §10).
 *
 * POST api/scan_qr.php   { "token": "<32-hex from the QR>" }
 *   X-CSRF-Token header required.
 *
 * The scanner UI calls this after a successful decode. It validates the
 * lecturer's account AND the classroom token server-side, then reports the
 * room's live availability so the confirmation dialog can be shown.
 * NOTE: this endpoint never occupies a room by itself — that is done by
 * lecturer/occupy.php which re-validates everything again (§11).
 */

define('CF_WANTS_JSON', true);
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';

$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please log in first.', 'code' => 'auth'], 401);
}
if ($user['account_status'] !== 'approved') {
    json_response(['ok' => false, 'error' => 'Your account is awaiting administrator approval.', 'code' => 'pending'], 403);
}
if (!check_csrf()) {
    json_response(['ok' => false, 'error' => 'Invalid CSRF token. Reload the page and try again.', 'code' => 'csrf'], 403);
}

$input = request_input();
$token = extract_qr_token((string)($input['token'] ?? ''));
if ($token === null) {
    json_response(['ok' => false, 'error' => 'That does not look like a Classroom Finder QR code.', 'code' => 'bad_qr'], 400);
}

$room = get_room_by_token($token);
if (!$room) {
    log_action('SCAN_INVALID_TOKEN', (int)$user['id'], null, 'Token not found: ' . $token);
    json_response(['ok' => false, 'error' => 'Unknown or regenerated QR code. Ask the administrator for the current one.', 'code' => 'not_found'], 404);
}

// Live state for THIS room (fresh sweep + focused re-check)
$rooms = fetch_classrooms();
$live  = null;
foreach ($rooms as $r) {
    if ((int)$r['id'] === (int)$room['id']) {
        $live = $r;
        break;
    }
}

log_action('SCAN_QR', (int)$user['id'], (int)$room['id'], 'Scanned room ' . $room['room_number']);

$suggestions = [
    ['minutes' => 30,  'label' => '30 minutes'],
    ['minutes' => 60,  'label' => '1 hour'],
    ['minutes' => 90,  'label' => '1.5 hours'],
    ['minutes' => 120, 'label' => '2 hours'],
];

json_response([
    'ok'           => true,
    'server_now'   => date('c'),
    'available'    => $live['computed'] === 'available',
    'room_status'  => $live['computed'],
    'reason'       => match ($live['computed']) {
        'occupied'    => 'This room is currently occupied until ' . fmt_time($live['session_end']) . '.',
        'reserved'    => 'A reservation starts at ' . fmt_time($live['reservation_start']) . '.',
        'unavailable' => 'This room is marked unavailable (' . ($live['status'] === 'maintenance' ? 'maintenance' : 'disabled') . ').',
        default       => null,
    },
    'current_session' => empty($live['session_id']) ? null : [
        'lecturer' => $live['session_lecturer'],
        'start'    => $live['session_start'],
        'end'      => $live['session_end'],
    ],
    'room' => [
        'id'          => (int)$room['id'],
        'token'       => $token,
        'room_number' => $room['room_number'],
        'building'    => $room['building'],
        'floor'       => (int)$room['floor'],
        'capacity'    => (int)$room['capacity'],
        'room_type'   => $room['room_type'],
    ],
    'suggestions'  => $suggestions,
    'limits'       => [
        'min_minutes' => get_setting_int('min_duration_minutes', 15),
        'max_minutes' => get_setting_int('max_duration_minutes', 480),
        'step'        => get_setting_int('duration_step_minutes', 30),
    ],
]);
