<?php
declare(strict_types=1);

// Lecturer Release: processes early termination of a lecturer's own active room session.
require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();

// Enforce POST method and anti-CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf()) {
    flash('error', 'Session expired — please try again.');
    redirect('dashboard.php');
}

// Release active session and log event
$sessionId = (int)($_POST['session_id'] ?? 0);
$res = session_release($sessionId, (int)$user['id'], 'lecturer');

if ($res['ok']) {
    flash('success', 'Room ' . ($res['room_number'] ?? '') . ' released. It is now available.');
} else {
    flash('error', $res['error']);
}

redirect('dashboard.php');
