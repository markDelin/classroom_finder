<?php
declare(strict_types=1);

/**
 * Classroom Finder — release a classroom early (§14). JSON variant used by
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

$st = db()->prepare(
    'SELECT s.*, c.room_number FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     WHERE s.id = ? AND s.status = \'active\' LIMIT 1'
);
$st->execute([$sessionId]);
$session = $st->fetch();

if (!$session) {
    json_response(['ok' => false, 'error' => 'That session is not active anymore.'], 404);
}

$isOwner = (int)$session['user_id'] === (int)$user['id'];
$isAdmin = $user['role'] === 'admin';
if (!$isOwner && !$isAdmin) {
    json_response(['ok' => false, 'error' => 'You can only release your own session.'], 403);
}

if (!release_session($sessionId, $isAdmin && !$isOwner ? 'admin' : 'lecturer')) {
    json_response(['ok' => false, 'error' => 'Could not release the room — try again.'], 409);
}

log_action(
    $isAdmin && !$isOwner ? 'FORCE_END_SESSION' : 'RELEASE_ROOM',
    (int)$user['id'],
    (int)$session['classroom_id'],
    'Room ' . $session['room_number'] . ' released by ' . $user['full_name']
);

json_response(['ok' => true, 'message' => 'Room ' . $session['room_number'] . ' is now available.']);
