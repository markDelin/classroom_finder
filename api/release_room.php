<?php
declare(strict_types=1);

/**
 * Classroom Finder — release a classroom early (Feature: Early Session Termination). JSON variant used by
 * fetch() callers; lecturer/release.php is the plain-form equivalent.
 *
 * POST api/release_room.php   { "session_id": 123 }
 *   X-CSRF-Token header required.
 * Lecturers may only release their OWN active session; admins may force-end any.
 */

define('CF_WANTS_JSON', true);
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';

$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please log in first.'], 401);
}
if (!check_csrf()) {
    json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
}

$sessionId = (int)(request_input()['session_id'] ?? 0);
if ($sessionId <= 0) {
    json_response(['ok' => false, 'error' => 'Missing session id.'], 400);
}

$res = session_release($sessionId, (int)$user['id'], (string)$user['role']);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => $res['error']], (int)($res['code'] ?? 400));
}

json_response(['ok' => true, 'message' => $res['message']]);
