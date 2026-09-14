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

$room = get_room_by_token($token);
if (!$room) {
    json_response(['ok' => false, 'error' => 'Unknown or regenerated QR code.', 'code' => 'not_found'], 404);
}

$reason = (string)($input['reason'] ?? '');
$details = (string)($input['details'] ?? '');

$res = schedule_force_open((int)$room['id'], (int)$user['id'], $reason, $details);
if (!$res['ok']) {
    json_response([
        'ok'    => false,
        'error' => $res['error'],
        'code'  => ($res['code'] ?? 400) === 409 ? 'no_slot' : 'bad_reason',
    ], (int)($res['code'] ?? 400));
}

json_response([
    'ok'      => true,
    'already' => $res['already'],
    'until'   => $res['until'],
    'message' => $res['message'],
]);
