<?php
declare(strict_types=1);

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

if (!is_within_scan_hours()) {
    [$scanStart, $scanEnd] = get_scan_hours();
    json_response([
        'ok'    => false,
        'error' => 'Scanning is closed for the day. Room occupancy is permitted only between ' . fmt_range($scanStart, $scanEnd) . '.',
        'code'  => 'outside_hours',
    ], 403);
}

if ($activeSession = get_active_session_for((int)$user['id'])) {
    json_response([
        'ok'    => false,
        'error' => 'You already hold an active session in room ' . $activeSession['room_number'] . '. Release it before occupying another room.',
        'code'  => 'active_session',
    ], 400);
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

$live = room_get_with_status((int)$room['id']);

log_action('SCAN_QR', (int)$user['id'], (int)$room['id'], 'Scanned room ' . $room['room_number']);

$suggestions = [
    ['minutes' => 30,  'label' => '30m'],
    ['minutes' => 60,  'label' => '1h'],
    ['minutes' => 90,  'label' => '1.5h'],
    ['minutes' => 120, 'label' => '2h'],
];

json_response([
    'ok'           => true,
    'server_now'   => date('c'),
    'available'    => $live['computed'] === 'available',
    'room_status'  => $live['computed'],
    'reason'       => match ($live['computed']) {
        'occupied'    => empty($live['session_id'])
            ? 'This room has a scheduled class (' . $live['sched_subject'] . ') until '
              . fmt_time($live['sched_end']) . '.'
            : 'This room is currently occupied until ' . fmt_time($live['session_end']) . '.',
        'unavailable' => 'This room is marked unavailable (' . ($live['status'] === 'maintenance' ? 'maintenance' : 'disabled') . ').',
        default       => null,
    },
    'fixed_class' => ($live['computed'] === 'occupied' && empty($live['session_id']) && !empty($live['sched_id'])) ? [
        'schedule_id' => (int)$live['sched_id'],
        'subject'     => $live['sched_subject'],
        'section'     => $live['sched_section'],
        'instructor'  => $live['sched_instructor'],
        'start'       => $live['sched_start'],
        'end'         => $live['sched_end'],
    ] : null,
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
