<?php
declare(strict_types=1);

/**
 * Classroom Finder — release a classroom early (§14). Plain-form variant of
 * api/release_room.php; only the owner can release their own session.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$fail = function (string $msg): never {
    flash('error', $msg);
    redirect('dashboard.php');
};

$user = require_approved_lecturer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf()) {
    $fail('Session expired — please try again.');
}

$sessionId = (int)($_POST['session_id'] ?? 0);

$st = db()->prepare(
    'SELECT s.*, c.room_number FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     WHERE s.id = ? AND s.status = \'active\' LIMIT 1'
);
$st->execute([$sessionId]);
$session = $st->fetch();

if (!$session) {
    $fail('That session is not active anymore.');
}
if ((int)$session['user_id'] !== (int)$user['id']) {
    log_action('RELEASE_DENIED', (int)$user['id'], (int)$session['classroom_id'], 'Tried to release someone else\'s session #' . $sessionId);
    $fail('You can only release your own session.');
}

if (release_session($sessionId, 'lecturer')) {
    log_action(
        'RELEASE_ROOM',
        (int)$user['id'],
        (int)$session['classroom_id'],
        'Released room ' . $session['room_number'] . ' early'
    );
    flash('success', 'Room ' . $session['room_number'] . ' released. It is now available.');
} else {
    $fail('Could not release the room — please try again.');
}

redirect('dashboard.php');
