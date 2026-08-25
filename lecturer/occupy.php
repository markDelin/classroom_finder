<?php
declare(strict_types=1);

/**
 * Classroom Finder — occupy a classroom (§11).
 *
 * POST lecturer/occupy.php  { token, minutes, csrf }
 *
 * The scanner dialog already showed availability, but the frontend is never
 * trusted: every check runs again here inside a transaction with a row lock,
 * so two lecturers confirming at the same instant cannot both win (§16).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$fail = function (string $msg): never {
    flash('error', $msg);
    redirect('scanner.php');
};

$user = require_approved_lecturer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf()) {
    $fail('Your session expired — scan the QR code again.');
}

if ($activeHold = get_active_session_for((int)$user['id'])) {
    $fail('You are still occupying room ' . $activeHold['room_number'] . '. Release it first.');
}

$token   = extract_qr_token((string)($_POST['token'] ?? ''));
$minutes = filter_var($_POST['minutes'] ?? '', FILTER_VALIDATE_INT);

if ($token === null) {
    $fail('Missing or malformed QR token.');
}

$minMinutes = get_setting_int('min_duration_minutes', 15);
$maxMinutes = get_setting_int('max_duration_minutes', 480);

if ($minutes === false || $minutes < $minMinutes || $minutes > $maxMinutes) {
    $fail("Invalid duration — choose between {$minMinutes} and {$maxMinutes} minutes.");
}

$now     = time();
$start   = date('Y-m-d H:i:s', $now);
$end     = date('Y-m-d H:i:s', $now + $minutes * 60);
$resSoon = date('Y-m-d H:i:s', $now + get_setting_int('reserve_window_minutes', 45) * 60);

$pdo = db();
try {
    $pdo->beginTransaction();

    // Lock the classroom row: serialises competing confirms for the same room.
    $st = $pdo->prepare('SELECT * FROM classrooms WHERE qr_token = ? LIMIT 1 FOR UPDATE');
    $st->execute([$token]);
    $room = $st->fetch();

    if (!$room) {
        throw new RuntimeException('Unknown QR code. Ask the administrator for the current poster.');
    }

    // 1. classroom usable?
    if ($room['status'] !== 'available') {
        throw new RuntimeException(
            $room['status'] === 'maintenance'
                ? 'Room ' . $room['room_number'] . ' is under maintenance and cannot be occupied.'
                : 'Room ' . $room['room_number'] . ' is currently disabled.'
        );
    }

    // 2. any active/overlapping session? (conflict detection §16)
    $st = $pdo->prepare(
        "SELECT s.id, s.start_time, s.end_time, u.full_name
         FROM classroom_sessions s JOIN users u ON u.id = s.user_id
         WHERE s.classroom_id = ? AND s.status = 'active'
           AND s.start_time < ? AND s.end_time > ?
         LIMIT 1"
    );
    $st->execute([(int)$room['id'], $end, $start]);
    if ($clash = $st->fetch()) {
        throw new RuntimeException(
            'Room ' . $room['room_number'] . ' is unavailable — ' . $clash['full_name']
            . ' holds a session from ' . fmt_range($clash['start_time'], $clash['end_time']) . '.'
        );
    }

    // 3. an active reservation overlapping our whole window?
    $st = $pdo->prepare(
        "SELECT id, start_time, end_time FROM reservations
         WHERE classroom_id = ? AND status = 'active'
           AND start_time < ? AND end_time > ?
         LIMIT 1"
    );
    $st->execute([(int)$room['id'], $end, $start]);
    if ($res = $st->fetch()) {
        throw new RuntimeException(
            'Room ' . $room['room_number'] . ' is reserved from '
            . fmt_range($res['start_time'], $res['end_time']) . '.'
        );
    }

    // All good — record the session.
    $pdo->prepare(
        "INSERT INTO classroom_sessions (classroom_id, user_id, start_time, end_time, status)
         VALUES (?, ?, ?, ?, 'active')"
    )->execute([(int)$room['id'], (int)$user['id'], $start, $end]);

    log_action(
        'OCCUPY_ROOM',
        (int)$user['id'],
        (int)$room['id'],
        'Occupied room ' . $room['room_number'] . ' (' . human_duration($minutes) . ', until ' . fmt_time($end) . ')'
    );

    $pdo->commit();
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $fail($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('occupy failed: ' . $e->getMessage());
    $fail('Something went wrong while recording the session. Please try again.');
}

flash('success', 'Room ' . $room['room_number'] . ' is yours until ' . fmt_time($end) . ' (' . human_duration($minutes) . ').');
redirect('dashboard.php');
