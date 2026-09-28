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

function schedule_upcoming_for_rooms(array $roomIds, ?int $now = null): array
{
    $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds), static fn(int $id): bool => $id > 0)));
    if (!$roomIds) {
        return [];
    }
    $now ??= time();
    $today = date('Y-m-d', $now);
    $horizon = strtotime('+7 days', $now);
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $result = array_fill_keys($roomIds, ['slots' => [], 'next' => null]);
    $st = db()->prepare("SELECT * FROM class_schedules
        WHERE classroom_id IN ($placeholders) AND is_active = 1
        ORDER BY day_of_week, start_time, id");
    $st->execute($roomIds);
    foreach ($st->fetchAll() as $slot) {
        $result[(int)$slot['classroom_id']]['slots'][] = $slot;
    }
    $st = db()->prepare("SELECT fo.schedule_id, fo.exc_date
        FROM schedule_force_open fo JOIN class_schedules cs ON cs.id = fo.schedule_id
        WHERE cs.classroom_id IN ($placeholders) AND fo.exc_date BETWEEN ? AND ?");
    $st->execute([...$roomIds, $today, date('Y-m-d', $horizon)]);
    $opened = [];
    foreach ($st->fetchAll() as $exception) {
        $opened[$exception['schedule_id'] . ':' . $exception['exc_date']] = true;
    }
    foreach ($result as &$room) {
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = strtotime($today . " +$offset days");
            $date = date('Y-m-d', $day);
            foreach ($room['slots'] as $slot) {
                if ((int)$slot['day_of_week'] !== (int)date('N', $day)
                    || isset($opened[$slot['id'] . ':' . $date])) {
                    continue;
                }
                $start = strtotime($date . ' ' . $slot['start_time']);
                $end = strtotime($date . ' ' . $slot['end_time']);
                if ($end <= $now || $start > $horizon) {
                    continue;
                }
                $room['next'] = $slot + [
                    'starts_at' => date('Y-m-d H:i:s', $start),
                    'ends_at' => date('Y-m-d H:i:s', $end),
                ];
                break 2;
            }
        }
    }
    unset($room);
    return $result;
}

function schedule_find_conflict(int $classroomId, int $day, string $start, string $end, int $excludeId): ?array
{
    $st = db()->prepare(
        'SELECT subject FROM class_schedules
         WHERE classroom_id = ? AND day_of_week = ? AND is_active = 1 AND id <> ?
           AND start_time < ? AND end_time > ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $day, $excludeId, $end, $start]);
    return $st->fetch() ?: null;
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
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? FOR UPDATE');
        $st->execute([$classroomId]);
        if (!$st->fetch()) {
            throw new RuntimeException('Classroom does not exist.');
        }
        if ($slotId > 0) {
            $st = $pdo->prepare('SELECT id FROM class_schedules WHERE id = ? FOR UPDATE');
            $st->execute([$slotId]);
            if (!$st->fetch()) {
                throw new RuntimeException('Schedule does not exist.');
            }
        }
        if ($clash = schedule_find_conflict($classroomId, $day, $start, $end, $slotId)) {
            throw new RuntimeException("Overlaps an existing class ({$clash['subject']}) on " . SCHEDULE_DAY_NAMES[$day] . '. Adjust the times.');
        }

        $values = [$classroomId, $day, $start, $end, $subject, $section !== '' ? $section : null, $instructor !== '' ? $instructor : null];
        $creating = $slotId === 0;
        if ($creating) {
            $pdo->prepare(
                'INSERT INTO class_schedules (classroom_id, day_of_week, start_time, end_time, subject, section, instructor)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute($values);
            $slotId = (int)$pdo->lastInsertId();
        } else {
            $values[] = $slotId;
            $pdo->prepare(
                'UPDATE class_schedules
                 SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject = ?, section = ?, instructor = ?
                 WHERE id = ?'
            )->execute($values);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log('schedule_save_slot failed: ' . $e->getMessage());
        }
        return ['ok' => false, 'error' => $e instanceof RuntimeException && !($e instanceof PDOException)
            ? $e->getMessage() : 'Database error while saving schedule.'];
    }

    if (function_exists('log_action')) {
        log_action($creating ? 'SCHEDULE_CREATE' : 'SCHEDULE_EDIT', $adminId, $classroomId, SCHEDULE_DAY_NAMES[$day] . " {$startT}–{$endT} {$subject}");
    }
    return [
        'ok' => true,
        'message' => $creating
            ? 'Class schedule added. The room is blocked during this slot every ' . SCHEDULE_DAY_NAMES[$day] . '.'
            : 'Class schedule updated.',
        'id' => $slotId,
    ];
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
    $slot = schedule_get($id);
    if (!$slot) {
        return ['ok' => false, 'error' => 'Schedule does not exist.'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? FOR UPDATE');
        $st->execute([(int)$slot['classroom_id']]);
        if (!$st->fetch()) {
            throw new RuntimeException('Classroom does not exist.');
        }
        $st = $pdo->prepare('SELECT * FROM class_schedules WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $current = $st->fetch();
        if (!$current || (int)$current['classroom_id'] !== (int)$slot['classroom_id']) {
            throw new RuntimeException('Schedule changed — reload and try again.');
        }
        if (!(int)$current['is_active'] && ($clash = schedule_find_conflict(
            (int)$current['classroom_id'], (int)$current['day_of_week'],
            $current['start_time'], $current['end_time'], $id
        ))) {
            throw new RuntimeException("Overlaps an existing class ({$clash['subject']}). Adjust the times before resuming.");
        }
        $pdo->prepare('UPDATE class_schedules SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log('schedule_toggle_slot failed: ' . $e->getMessage());
        }
        return ['ok' => false, 'error' => $e instanceof RuntimeException && !($e instanceof PDOException)
            ? $e->getMessage() : 'Database error while toggling schedule.'];
    }
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
    } catch (Throwable $e) {
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            try {
                $st = db()->prepare('SELECT id, user_id FROM schedule_force_open WHERE schedule_id = ? AND exc_date = ? LIMIT 1');
                $st->execute([(int)$slot['id'], $today]);
                if ($winner = $st->fetch()) {
                    return [
                        'ok' => true,
                        'message' => (int)$winner['user_id'] === $userId
                            ? 'Room is already open from your earlier report.'
                            : 'Room was just opened by another lecturer.',
                        'id' => (int)$winner['id'],
                        'already' => true,
                        'until' => $today . ' ' . $slot['end_time'],
                        'slot' => $slot,
                    ];
                }
            } catch (Throwable $lookupError) {
                error_log('schedule_force_open lookup failed: ' . $lookupError->getMessage());
            }
        }
        error_log('schedule_force_open failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not open the room. Please try again.', 'code' => 500];
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
