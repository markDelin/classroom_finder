<?php
declare(strict_types=1);

/**
 * Classroom Finder — release a classroom early. Plain-form variant of
 * api/release_room.php; only the owner can release their own session.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf()) {
    flash('error', 'Session expired — please try again.');
    redirect('dashboard.php');
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$res = session_release($sessionId, (int)$user['id'], 'lecturer');

if ($res['ok']) {
    flash('success', 'Room ' . ($res['room_number'] ?? '') . ' released. It is now available.');
} else {
    flash('error', $res['error']);
}

redirect('dashboard.php');
