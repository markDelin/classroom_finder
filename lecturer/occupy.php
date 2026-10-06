<?php
declare(strict_types=1);

// Lecturer Occupy: processes classroom check-in requests via pessimistic lock transaction.
require_once __DIR__ . '/../auth/auth_check.php';

$fail = function (string $msg) {
    flash('error', $msg);
    redirect('scanner.php');
};

$user = require_approved_lecturer();

// Enforce POST method and anti-CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf()) {
    $fail('Your session expired — scan the QR code again.');
}

// Validate token and selected occupancy duration
$token   = extract_qr_token((string)($_POST['token'] ?? ''));
$minutes = filter_var($_POST['minutes'] ?? '', FILTER_VALIDATE_INT);

if ($token === null) {
    $fail('Missing or malformed QR token.');
}

if ($minutes === false) {
    $fail('Invalid duration.');
}

$room = get_room_by_token($token);
if (!$room) {
    $fail('Unknown QR code. Ask the administrator for the current poster.');
}

$res = session_occupy((int)$room['id'], (int)$user['id'], (int)$minutes);
if (!$res['ok']) {
    $fail($res['error']);
}

flash('success', 'Room ' . $room['room_number'] . ' is yours until ' . fmt_time($res['end_time']) . ' (' . human_duration($minutes) . ').');
redirect('dashboard.php');
