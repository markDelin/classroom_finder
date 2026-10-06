<?php
declare(strict_types=1);

// API: Release Room endpoint: terminates an active occupancy session ahead of schedule.
define('CF_WANTS_JSON', true);
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';

// Authentication and CSRF verification
$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please log in first.'], 401);
}
if (!check_csrf()) {
    json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
}

// Extract session ID and release room
$sessionId = (int)(request_input()['session_id'] ?? 0);
if ($sessionId <= 0) {
    json_response(['ok' => false, 'error' => 'Missing session id.'], 400);
}

// Release session (authorizes session owner or administrator)
$res = session_release($sessionId, (int)$user['id'], (string)$user['role']);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => $res['error']], (int)($res['code'] ?? 400));
}

json_response(['ok' => true, 'message' => $res['message']]);
