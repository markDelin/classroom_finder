<?php
declare(strict_types=1);

/**
 * Classroom Finder — report "this scheduled class isn't meeting" (force open).
 *
 * POST api/force_open.php  { "token": "<32-hex>", "reason": "...", "details": "" }
 *   X-CSRF-Token header required.
 *
 * When a room is blocked by a fixed weekly class that is not actually taking
 * place (lecturer absent / emergency / ended early), an approved lecturer can
 * open it instantly — no admin approval. The report is recorded so the lift
 * only applies to TODAY's occurrence of that slot and admins can audit/revert.
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
    json_response(['ok' => false, 'error' => 'Missing or malformed QR token.', 'code' => 'bad_qr'], 400);
}

$reason = (string)($input['reason'] ?? '');
if (!in_array($reason, ['lecturer_absent', 'emergency', 'ended_early', 'other'], true)) {
    json_response(['ok' => false, 'error' => 'Please pick a reason for opening this room.', 'code' => 'bad_reason'], 400);
}
$details = mb_substr(trim((string)($input['details'] ?? '')), 0, 160);

$room = get_room_by_token($token);
if (!$room) {
    json_response(['ok' => false, 'error' => 'Unknown or regenerated QR code.', 'code' => 'not_found'], 404);
}

// The slot must be blocking right now: today's weekday, currently within it.
$st = db()->prepare(
    'SELECT id, subject, start_time, end_time FROM class_schedules
     WHERE classroom_id = ? AND is_active = 1 AND day_of_week = ?
       AND start_time <= ? AND end_time > ?
     LIMIT 1'
);
$st->execute([(int)$room['id'], (int)date('N'), date('H:i:s'), date('H:i:s')]);
$slot = $st->fetch();

if (!$slot) {
    json_response([
        'ok'    => false,
        'error' => 'There is no scheduled class in room ' . $room['room_number'] . ' right now.',
        'code'  => 'no_slot',
    ], 409);
}

$today = date('Y-m-d');

// Idempotent: an earlier report for this same slot/day already opened it.
// Re-read the user_id so we can tell the lecturer whose report it was.
$st = db()->prepare('SELECT id, user_id FROM schedule_force_open WHERE schedule_id = ? AND exc_date = ? LIMIT 1');
$st->execute([(int)$slot['id'], $today]);
if ($existing = $st->fetch()) {
    $isOurs = (int)$existing['user_id'] === (int)$user['id'];
    log_action('FORCE_OPEN_DUP', (int)$user['id'], (int)$room['id'],
        'Slot already opened today (#' . (int)$existing['id'] . ', ' . ($isOurs ? 'self' : 'other') . ')');
    json_response([
        'ok'      => true,
        'already' => true,
        'until'   => date('Y-m-d ') . $slot['end_time'],
        'message' => $isOurs
            ? 'Room ' . $room['room_number'] . ' is already open from your earlier report.'
            : 'Room ' . $room['room_number'] . ' was just opened by another lecturer.',
    ]);
}

try {
    db()->prepare(
        'INSERT INTO schedule_force_open (classroom_id, schedule_id, exc_date, reason, details, user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([(int)$room['id'], (int)$slot['id'], $today, $reason, $details ?: null, (int)$user['id']]);
} catch (Throwable $e) {
    // Lost the race against another reporter's unique (schedule_id, exc_date) row.
    // Re-read it so we can report whose report actually won.
    $st = db()->prepare('SELECT user_id FROM schedule_force_open WHERE schedule_id = ? AND exc_date = ? LIMIT 1');
    $st->execute([(int)$slot['id'], $today]);
    $winner = $st->fetch();
    $isOurs = $winner && (int)$winner['user_id'] === (int)$user['id'];
    json_response([
        'ok'      => true,
        'already' => true,
        'until'   => date('Y-m-d ') . $slot['end_time'],
        'message' => $isOurs
            ? 'Room ' . $room['room_number'] . ' is already open from your earlier report.'
            : 'Room ' . $room['room_number'] . ' was just opened by another lecturer.',
    ]);
}

log_action('FORCE_OPEN', (int)$user['id'], (int)$room['id'],
    'Opened room ' . $room['room_number'] . ' despite scheduled class "' . $slot['subject']
    . '" (' . $reason . ($details ? ': ' . $details : '') . ')');

json_response([
    'ok'      => true,
    'already' => false,
    'until'   => date('Y-m-d ') . $slot['end_time'],
    'message' => 'Room ' . $room['room_number'] . ' is open until ' . fmt_time($slot['end_time']) . '.',
]);
