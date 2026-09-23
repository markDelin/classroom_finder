<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const SCHEDULE_DAY_NAMES = [
    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'
];

function schedule_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM class_schedules WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function schedule_get_active_slot(int $classroomId, int $dayOfWeek, string $time): ?array
{
    if ($classroomId <= 0) {
        return null;
    }
    $st = db()->prepare(
        'SELECT id, subject, start_time, end_time, section, instructor
         FROM class_schedules
         WHERE classroom_id = ? AND is_active = 1 AND day_of_week = ?
           AND start_time <= ? AND end_time > ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $dayOfWeek, $time, $time]);
    $slot = $st->fetch();
    return $slot ?: null;
}

function schedule_fetch_by_room(int $classroomId): array
{
    if ($classroomId <= 0) {
        return [];
    }
    $st = db()->prepare(
        'SELECT * FROM class_schedules
         WHERE classroom_id = ?
         ORDER BY day_of_week ASC, start_time ASC'
    );
    $st->execute([$classroomId]);
    return $st->fetchAll();
}

function schedule_save_slot(array $data, ?int $id = null, ?int $adminId = null): array
{
    $classroomId = (int)($data['classroom_id'] ?? 0);
    $day         = (int)($data['day_of_week'] ?? 0);
    $startT      = trim((string)($data['start_time'] ?? ''));
    $endT        = trim((string)($data['end_time'] ?? ''));
    $subject     = trim((string)($data['subject'] ?? ''));
    $section     = trim((string)($data['section'] ?? ''));
    $instructor  = trim((string)($data['instructor'] ?? ''));

    if (!$classroomId || !isset(SCHEDULE_DAY_NAMES[$day])
        || !preg_match('/^\d{2}:\d{2}$/', $startT) || !preg_match('/^\d{2}:\d{2}$/', $endT)) {
        return ['ok' => false, 'error' => 'Please fill in the room, weekday and both times.'];
    }

    foreach ([$startT, $endT] as $t) {
        [$hh, $mm] = array_map('intval', explode(':', $t));
        if ($hh > 23 || $mm > 59) {
            return ['ok' => false, 'error' => 'Please use real clock times (HH:MM).'];
        }
    }

    if ($subject === '') {
        return ['ok' => false, 'error' => 'Please enter the subject / course.'];
    }

    $start = $startT . ':00';
    $end   = $endT . ':00';
    if (strcmp($end, $start) <= 0) {
        return ['ok' => false, 'error' => 'The end time must be after the start time.'];
    }

    $slotId = $id !== null && $id > 0 ? $id : 0;
    $st = db()->prepare(
        "SELECT subject FROM class_schedules
         WHERE classroom_id = ? AND day_of_week = ? AND is_active = 1 AND id <> ?
           AND start_time < ? AND end_time > ?
         LIMIT 1"
    );
    $st->execute([$classroomId, $day, $slotId, $end, $start]);
    if ($clash = $st->fetch()) {
        return [
            'ok' => false,
            'error' => "Overlaps an existing class ({$clash['subject']}) on " . SCHEDULE_DAY_NAMES[$day] . '. Adjust the times.',
        ];
    }

    if ($slotId === 0) {
        db()->prepare(
            'INSERT INTO class_schedules (classroom_id, day_of_week, start_time, end_time, subject, section, instructor)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$classroomId, $day, $start, $end, $subject, $section !== '' ? $section : null, $instructor !== '' ? $instructor : null]);
        $newId = (int)db()->lastInsertId();

        if (function_exists('log_action')) {
            log_action('SCHEDULE_CREATE', $adminId, $classroomId, SCHEDULE_DAY_NAMES[$day] . " {$startT}–{$endT} {$subject}");
        }

        return [
            'ok' => true,
            'message' => 'Class schedule added. The room is blocked during this slot every ' . SCHEDULE_DAY_NAMES[$day] . '.',
            'id' => $newId,
        ];
    }

    db()->prepare(
        'UPDATE class_schedules
         SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject = ?, section = ?, instructor = ?
         WHERE id = ?'
    )->execute([$classroomId, $day, $start, $end, $subject, $section !== '' ? $section : null, $instructor !== '' ? $instructor : null, $slotId]);

    if (function_exists('log_action')) {
        log_action('SCHEDULE_EDIT', $adminId, $classroomId, SCHEDULE_DAY_NAMES[$day] . " {$startT}–{$endT} {$subject}");
    }

    return ['ok' => true, 'message' => 'Class schedule updated.', 'id' => $slotId];
}

function schedule_delete_slot(int $id, ?int $adminId = null): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid schedule ID.'];
    }
    db()->prepare('DELETE FROM class_schedules WHERE id = ?')->execute([$id]);
    if (function_exists('log_action')) {
        log_action('SCHEDULE_DELETE', $adminId, null, 'Deleted schedule #' . $id);
    }
    return ['ok' => true, 'message' => 'Schedule removed.'];
}

function schedule_toggle_slot(int $id, ?int $adminId = null): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid schedule ID.'];
    }
    db()->prepare('UPDATE class_schedules SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
    if (function_exists('log_action')) {
        log_action('SCHEDULE_TOGGLE', $adminId, null, 'Toggled schedule #' . $id);
    }
    return ['ok' => true, 'message' => 'Schedule status toggled.'];
}

function schedule_force_open(int $classroomId, int $userId, string $reason, string $details = ''): array
{
    if ($classroomId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request parameters.', 'code' => 400];
    }

    if (!in_array($reason, ['lecturer_absent', 'emergency', 'ended_early', 'other'], true)) {
        return ['ok' => false, 'error' => 'Please pick a valid reason for opening this room.', 'code' => 400];
    }

    $nowTime = date('H:i:s');
    $dayOfWeek = (int)date('N');
    $slot = schedule_get_active_slot($classroomId, $dayOfWeek, $nowTime);

    if (!$slot) {
        return ['ok' => false, 'error' => 'There is no scheduled class in this room right now.', 'code' => 409];
    }

    $today = date('Y-m-d');
    $st = db()->prepare('SELECT id, user_id FROM schedule_force_open WHERE schedule_id = ? AND exc_date = ? LIMIT 1');
    $st->execute([(int)$slot['id'], $today]);
    $prior = $st->fetch();

    if ($prior) {
        $isOurs = (int)$prior['user_id'] === $userId;
        return [
            'ok' => true,
            'message' => $isOurs
                ? 'Room is already open from your earlier report.'
                : 'Room was just opened by another lecturer.',
            'id' => (int)$prior['id'],
            'already' => true,
            'until' => date('Y-m-d ') . $slot['end_time'],
            'slot' => $slot,
        ];
    }

    $detailsClean = mb_substr(trim($details), 0, 160);
    try {
        $ins = db()->prepare(
            'INSERT INTO schedule_force_open (classroom_id, schedule_id, exc_date, user_id, reason, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([(int)$classroomId, (int)$slot['id'], $today, $userId, $reason, $detailsClean !== '' ? $detailsClean : null]);
        $newId = (int)db()->lastInsertId();
    } catch (Throwable) {
        // Lost race against concurrent insert
        $st = db()->prepare('SELECT id, user_id FROM schedule_force_open WHERE schedule_id = ? AND exc_date = ? LIMIT 1');
        $st->execute([(int)$slot['id'], $today]);
        $winner = $st->fetch();
        $isOurs = $winner && (int)$winner['user_id'] === $userId;
        return [
            'ok' => true,
            'message' => $isOurs
                ? 'Room is already open from your earlier report.'
                : 'Room was just opened by another lecturer.',
            'id' => $winner ? (int)$winner['id'] : 0,
            'already' => true,
            'until' => date('Y-m-d ') . $slot['end_time'],
            'slot' => $slot,
        ];
    }

    if (function_exists('log_action')) {
        log_action(
            'FORCE_OPEN',
            $userId,
            $classroomId,
            "Opened room ({$slot['subject']}, reason: {$reason})"
        );
    }

    return [
        'ok' => true,
        'message' => 'Room is open until ' . (function_exists('fmt_time') ? fmt_time($slot['end_time']) : $slot['end_time']) . '.',
        'id' => $newId,
        'already' => false,
        'until' => date('Y-m-d ') . $slot['end_time'],
        'slot' => $slot,
    ];
}

function schedule_revert_force_open(int $forceOpenId, ?int $adminId = null): array
{
    if ($forceOpenId <= 0) {
        return ['ok' => false, 'error' => 'Invalid force-open ID.'];
    }
    db()->prepare('DELETE FROM schedule_force_open WHERE id = ?')->execute([$forceOpenId]);
    if (function_exists('log_action')) {
        log_action('FORCE_OPEN_REVERT', $adminId, null, 'Reverted force-open #' . $forceOpenId);
    }
    return ['ok' => true, 'message' => 'Force-open reverted — the scheduled class blocks the room again.'];
}
