<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function session_get_active(int $sessionId): ?array
{
    if ($sessionId <= 0) {
        return null;
    }
    $st = db()->prepare(
        "SELECT s.*, c.room_number, c.building, u.full_name AS lecturer_name
         FROM classroom_sessions s
         JOIN classrooms c ON c.id = s.classroom_id
         JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND s.status = 'active'
         LIMIT 1"
    );
    $st->execute([$sessionId]);
    $row = $st->fetch();
    return $row ?: null;
}

function session_get_active_for_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $now = date('Y-m-d H:i:s');
    $st = db()->prepare(
        "SELECT s.*, c.room_number, c.building
         FROM classroom_sessions s
         JOIN classrooms c ON c.id = s.classroom_id
         WHERE s.user_id = ? AND s.status = 'active' AND s.end_time > ?
         ORDER BY s.end_time DESC LIMIT 1"
    );
    $st->execute([$userId, $now]);
    $session = $st->fetch();
    return $session ?: null;
}

function session_occupy(int $classroomId, int $userId, int $minutes): array
{
    if ($userId <= 0 || $classroomId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user or classroom ID.'];
    }

    if ($activeHold = session_get_active_for_user($userId)) {
        return [
            'ok' => false,
            'error' => 'You are still occupying room ' . $activeHold['room_number'] . '. Release it first.',
        ];
    }

    if (function_exists('is_within_scan_hours') && !is_within_scan_hours()) {
        [$scanStart, $scanEnd] = function_exists('get_scan_hours') ? get_scan_hours() : ['07:00', '19:00'];
        $rangeStr = function_exists('fmt_range') ? fmt_range($scanStart, $scanEnd) : "{$scanStart} – {$scanEnd}";
        return [
            'ok'    => false,
            'error' => "Room occupancy is closed for the day. Permitted only between {$rangeStr}.",
        ];
    }

    $minMinutes = function_exists('get_setting_int') ? get_setting_int('min_duration_minutes', 15) : 15;
    $maxMinutes = function_exists('get_setting_int') ? get_setting_int('max_duration_minutes', 480) : 480;

    if ($minutes < $minMinutes || $minutes > $maxMinutes) {
        return ['ok' => false, 'error' => "Invalid duration — choose between {$minMinutes} and {$maxMinutes} minutes."];
    }

    $now   = time();
    $start = date('Y-m-d H:i:s', $now);
    $end   = date('Y-m-d H:i:s', $now + $minutes * 60);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM classrooms WHERE id = ? LIMIT 1 FOR UPDATE');
        $st->execute([$classroomId]);
        $room = $st->fetch();

        if (!$room) {
            throw new RuntimeException('Classroom does not exist.');
        }

        if ($room['status'] !== 'available') {
            throw new RuntimeException(
                $room['status'] === 'maintenance'
                    ? 'Room ' . $room['room_number'] . ' is under maintenance and cannot be occupied.'
                    : 'Room ' . $room['room_number'] . ' is currently disabled.'
            );
        }

        $st = $pdo->prepare(
            "SELECT s.id, s.start_time, s.end_time, u.full_name
             FROM classroom_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.classroom_id = ? AND s.status = 'active'
               AND s.start_time < ? AND s.end_time > ?
             LIMIT 1"
        );
        $st->execute([$classroomId, $end, $start]);
        if ($clash = $st->fetch()) {
            throw new RuntimeException(
                'Room ' . $room['room_number'] . ' is unavailable — ' . $clash['full_name']
                . ' holds a session from ' . (function_exists('fmt_range') ? fmt_range($clash['start_time'], $clash['end_time']) : ($clash['start_time'] . ' - ' . $clash['end_time'])) . '.'
            );
        }

        $startT = date('H:i:s', $now);
        $endT   = date('H:i:s', $now + $minutes * 60);
        $st = $pdo->prepare(
            "SELECT cs.id, cs.subject, cs.start_time, cs.end_time
             FROM class_schedules cs
             LEFT JOIN schedule_force_open fo
                    ON fo.schedule_id = cs.id AND fo.exc_date = CURDATE()
             WHERE cs.classroom_id = ? AND cs.is_active = 1
               AND cs.day_of_week = ?
               AND cs.start_time < ? AND cs.end_time > ?
               AND fo.id IS NULL
             LIMIT 1"
        );
        $st->execute([$classroomId, (int)date('N', $now), $endT, $startT]);
        if ($cls = $st->fetch()) {
            throw new RuntimeException(
                'Room ' . $room['room_number'] . ' has a scheduled class ('
                . $cls['subject'] . '). If the class is not meeting, you can open the room from the scanner.'
            );
        }

        $ins = $pdo->prepare(
            "INSERT INTO classroom_sessions (classroom_id, user_id, start_time, end_time, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $ins->execute([$classroomId, $userId, $start, $end]);
        $newId = (int)$pdo->lastInsertId();

        if (function_exists('log_action')) {
            $durText = function_exists('human_duration') ? human_duration($minutes) : ($minutes . ' min');
            log_action('OCCUPY_ROOM', $userId, $classroomId, "Occupied room {$room['room_number']} ({$durText})");
        }

        $pdo->commit();

        return [
            'ok' => true,
            'message' => "Room {$room['room_number']} occupied successfully.",
            'id' => $newId,
            'room' => $room,
            'end_time' => $end,
        ];
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('session_occupy failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error while occupying room.'];
    }
}

function session_release(int $sessionId, int $actorUserId, string $actorRole = 'lecturer'): array
{
    if ($sessionId <= 0) {
        return ['ok' => false, 'error' => 'Invalid session ID.', 'code' => 400];
    }

    $session = session_get_active($sessionId);
    if (!$session) {
        return ['ok' => false, 'error' => 'That session is not active anymore.', 'code' => 404];
    }

    $isOwner = (int)$session['user_id'] === $actorUserId;
    $isAdmin = in_array($actorRole, ['admin', 'cron'], true);

    if (!$isOwner && !$isAdmin) {
        if (function_exists('log_action')) {
            log_action('RELEASE_DENIED', $actorUserId, (int)$session['classroom_id'], "Tried to release someone else's session #{$sessionId}");
        }
        return ['ok' => false, 'error' => 'You can only release your own session.', 'code' => 403];
    }

    $now = date('Y-m-d H:i:s');
    $st = db()->prepare(
        "UPDATE classroom_sessions
         SET status = 'released', released_at = ?, end_time = LEAST(end_time, ?)
         WHERE id = ? AND status = 'active'"
    );
    $st->execute([$now, $now, $sessionId]);

    if ($st->rowCount() === 0) {
        return ['ok' => false, 'error' => 'Could not release the room — try again.', 'code' => 409];
    }

    if (function_exists('log_action')) {
        $actionName = ($isAdmin && !$isOwner) ? 'FORCE_END_SESSION' : 'RELEASE_ROOM';
        log_action(
            $actionName,
            $actorUserId,
            (int)$session['classroom_id'],
            "Room {$session['room_number']} released early"
        );
    }

    return [
        'ok' => true,
        'message' => "Room {$session['room_number']} is now available.",
        'room_number' => $session['room_number'],
    ];
}

function session_expire_stale(): int
{
    $now = date('Y-m-d H:i:s');
    $st = db()->prepare(
        "UPDATE classroom_sessions
         SET status = 'completed'
         WHERE status = 'active' AND end_time <= ?"
    );
    $st->execute([$now]);
    return (int)$st->rowCount();
}
