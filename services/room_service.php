<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const ROOM_SERVICE_TYPES = ['Lecture', 'Laboratory', 'Seminar', 'Auditorium'];

function room_get(int $id): ?array
{
    return room_get_with_status($id);
}

function room_get_raw(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM classrooms WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function room_get_by_token(string $token): ?array
{
    $token = strtolower(trim($token));
    if ($token === '') {
        return null;
    }
    $st = db()->prepare('SELECT * FROM classrooms WHERE qr_token = ? LIMIT 1');
    $st->execute([$token]);
    $room = $st->fetch();
    return $room ?: null;
}

function room_generate_token(): string
{
    do {
        $token = bin2hex(random_bytes(4));
        $exists = db()->prepare('SELECT 1 FROM classrooms WHERE qr_token = ? LIMIT 1');
        $exists->execute([$token]);
    } while ($exists->fetch());
    return $token;
}

function room_extract_qr_token(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $raw = trim($raw);
    if (preg_match('/TOKEN:\s*([0-9a-f]{4,32})/i', $raw, $m)) {
        return strtolower($m[1]);
    }
    return preg_match('/[0-9a-f]{4,32}/i', $raw, $m) ? strtolower($m[0]) : null;
}

function room_get_with_status(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    if (function_exists('expire_stale')) {
        expire_stale();
    } elseif (function_exists('session_expire_stale')) {
        session_expire_stale();
    }

    $now   = date('Y-m-d H:i:s');
    $dow   = (int)date('N');
    $curt  = date('H:i:s');
    $today = date('Y-m-d');

    $st = db()->prepare(
        "SELECT c.*,
                s.id            AS session_id,
                s.start_time    AS session_start,
                s.end_time      AS session_end,
                su.full_name    AS session_lecturer,
                cs.id           AS sched_id,
                cs.subject      AS sched_subject,
                cs.section      AS sched_section,
                cs.instructor   AS sched_instructor,
                cs.start_time   AS sched_start,
                cs.end_time     AS sched_end,
                fo.id           AS force_open_id
         FROM classrooms c
         LEFT JOIN classroom_sessions s
                ON s.classroom_id = c.id AND s.status = 'active'
               AND s.start_time <= :now1 AND s.end_time > :now2
         LEFT JOIN users su ON su.id = s.user_id
         LEFT JOIN class_schedules cs
                ON cs.classroom_id = c.id AND cs.is_active = 1
               AND cs.day_of_week = :dow
               AND cs.start_time <= :curt1 AND cs.end_time > :curt2
         LEFT JOIN schedule_force_open fo
                ON fo.schedule_id = cs.id AND fo.exc_date = :today
         WHERE c.id = :id
         LIMIT 1"
    );
    $st->execute([
        ':now1' => $now, ':now2' => $now,
        ':dow'  => $dow, ':curt1' => $curt, ':curt2' => $curt,
        ':today' => $today, ':id' => $id,
    ]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }

    if ($r['status'] !== 'available') {
        $r['computed']     = 'unavailable';
        $r['available_at'] = null;
    } elseif (!empty($r['session_id'])) {
        $r['computed']     = 'occupied';
        $r['available_at'] = $r['session_end'];
    } elseif (!empty($r['sched_id']) && empty($r['force_open_id'])) {
        $r['computed']     = 'occupied';
        $r['available_at'] = $r['sched_end'];
    } else {
        $r['computed']     = 'available';
        $r['available_at'] = null;
    }
    return $r;
}

function room_fetch_all(array $f = []): array
{
    if (function_exists('expire_stale')) {
        expire_stale();
    } elseif (function_exists('session_expire_stale')) {
        session_expire_stale();
    }

    $now  = date('Y-m-d H:i:s');
    $dow     = (int)date('N');
    $curtime = date('H:i:s');
    $today   = date('Y-m-d');

    $sql = "SELECT c.*,
                   MAX(s.id)         AS session_id,
                   MAX(s.start_time) AS session_start,
                   MAX(s.end_time)   AS session_end,
                   MAX(su.full_name) AS session_lecturer,
                   MAX(cs.id)          AS sched_id,
                   MAX(cs.subject)     AS sched_subject,
                   MAX(cs.section)     AS sched_section,
                   MAX(cs.instructor)  AS sched_instructor,
                   MAX(cs.start_time)  AS sched_start,
                   MAX(cs.end_time)    AS sched_end,
                   MAX(fo.id)          AS force_open_id
            FROM classrooms c
            LEFT JOIN classroom_sessions s
                   ON s.classroom_id = c.id AND s.status = 'active'
                  AND s.start_time <= :now1 AND s.end_time > :now2
            LEFT JOIN users su ON su.id = s.user_id
            LEFT JOIN class_schedules cs
                   ON cs.classroom_id = c.id AND cs.is_active = 1
                  AND cs.day_of_week = :dow
                  AND cs.start_time <= :curtime1 AND cs.end_time > :curtime2
            LEFT JOIN schedule_force_open fo
                   ON fo.schedule_id = cs.id AND fo.exc_date = :today";

    $where  = [];
    $params = [
        ':now1'     => $now,
        ':now2'     => $now,
        ':dow'      => $dow,
        ':curtime1' => $curtime,
        ':curtime2' => $curtime,
        ':today'    => $today,
    ];

    if (!empty($f['q'])) {
        $qTerm = '%' . trim((string)$f['q']) . '%';
        $where[] = '(c.room_number LIKE :q1 OR c.building LIKE :q2 OR c.room_type LIKE :q3 OR c.note LIKE :q4 OR cs.subject LIKE :q5 OR cs.instructor LIKE :q6 OR su.full_name LIKE :q7)';
        $params[':q1'] = $qTerm;
        $params[':q2'] = $qTerm;
        $params[':q3'] = $qTerm;
        $params[':q4'] = $qTerm;
        $params[':q5'] = $qTerm;
        $params[':q6'] = $qTerm;
        $params[':q7'] = $qTerm;
    }
    if (!empty($f['building'])) {
        $where[]              = 'c.building = :building';
        $params[':building']  = (string)$f['building'];
    }
    if (isset($f['floor']) && $f['floor'] !== '' && $f['floor'] !== null) {
        $where[]              = 'c.floor = :floor';
        $params[':floor']     = (int)$f['floor'];
    }
    if (!empty($f['type'])) {
        $where[]              = 'c.room_type = :type';
        $params[':type']      = (string)$f['type'];
    }
    if (!empty($f['mincap'])) {
        $where[]              = 'c.capacity >= :mincap';
        $params[':mincap']    = (int)$f['mincap'];
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY c.id ORDER BY c.building, c.room_number';

    $st = db()->prepare($sql);
    $st->execute($params);

    $rooms = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['status'] !== 'available') {
            $r['computed']     = 'unavailable';
            $r['available_at'] = null;
        } elseif (!empty($r['session_id'])) {
            $r['computed']     = 'occupied';
            $r['available_at'] = $r['session_end'];
        } elseif (!empty($r['sched_id']) && empty($r['force_open_id'])) {
            $r['computed']     = 'occupied';
            $r['available_at'] = $r['sched_end'];
        } else {
            $r['computed']     = 'available';
            $r['available_at'] = null;
        }
        $rooms[] = $r;
    }

    if (!empty($f['status'])) {
        $stFilter = (string)$f['status'];
        $rooms = array_values(array_filter($rooms, function ($r) use ($stFilter) {
            if ($stFilter === 'maintenance' || $stFilter === 'disabled') {
                return $r['status'] === $stFilter;
            }
            return $r['computed'] === $stFilter;
        }));
    }

    return $rooms;
}

function room_save(array $data, ?int $id = null, ?int $actorUserId = null): array
{
    $roomNumber = strtoupper(trim((string)($data['room_number'] ?? '')));
    $building   = trim((string)($data['building'] ?? ''));
    $floor      = max(1, (int)($data['floor'] ?? 1));
    $capacity   = max(1, min(9999, (int)($data['capacity'] ?? 0)));
    $type       = in_array($data['room_type'] ?? '', ROOM_SERVICE_TYPES, true) ? (string)$data['room_type'] : ROOM_SERVICE_TYPES[0];
    $note       = trim((string)($data['note'] ?? ''));

    if ($roomNumber === '' || $building === '') {
        return ['ok' => false, 'error' => 'Room number and building are required.'];
    }
    if (!preg_match('/^[A-Za-z0-9\- ]{1,20}$/', $roomNumber)) {
        return ['ok' => false, 'error' => 'Room number: letters, numbers, spaces and dashes only (max 20).'];
    }

    $st = db()->prepare('SELECT id FROM classrooms WHERE building = ? AND room_number = ? AND id <> ?');
    $st->execute([$building, $roomNumber, (int)($id ?? 0)]);
    if ($st->fetch()) {
        return ['ok' => false, 'error' => "{$building} already has a room {$roomNumber}."];
    }

    if ($id === null || $id <= 0) {
        $qrToken = room_generate_token();
        $ins = db()->prepare(
            'INSERT INTO classrooms (room_number, building, floor, capacity, room_type, qr_token, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $roomNumber, $building, $floor, $capacity, $type,
            $qrToken,
            $note !== '' ? $note : null,
        ]);
        $newId = (int)db()->lastInsertId();

        if (function_exists('log_action')) {
            log_action('CLASSROOM_ADD', $actorUserId, $newId, "Added room {$building} {$roomNumber}");
        }

        return ['ok' => true, 'message' => "Classroom {$roomNumber} added.", 'id' => $newId];
    }

    $upd = db()->prepare(
        'UPDATE classrooms SET room_number = ?, building = ?, floor = ?, capacity = ?, room_type = ?, note = ?
         WHERE id = ?'
    );
    $upd->execute([
        $roomNumber, $building, $floor, $capacity, $type,
        $note !== '' ? $note : null,
        $id,
    ]);

    if (function_exists('log_action')) {
        log_action('CLASSROOM_EDIT', $actorUserId, $id, "Updated room {$building} {$roomNumber}");
    }

    return ['ok' => true, 'message' => "Classroom {$roomNumber} updated.", 'id' => $id];
}

function room_set_status(int $id, string $status, ?int $actorUserId = null): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid classroom ID.'];
    }
    if (!in_array($status, ['available', 'maintenance', 'disabled'], true)) {
        return ['ok' => false, 'error' => 'Unknown status value.'];
    }

    $st = db()->prepare('UPDATE classrooms SET status = ? WHERE id = ?');
    $st->execute([$status, $id]);

    if (function_exists('log_action')) {
        log_action('CLASSROOM_STATUS', $actorUserId, $id, 'Status set to ' . $status);
    }

    return ['ok' => true, 'message' => 'Classroom marked as ' . $status . '.', 'id' => $id];
}

function room_delete(int $id, ?int $actorUserId = null): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid classroom ID.'];
    }

    $nSessions = db()->prepare('SELECT COUNT(*) AS n FROM classroom_sessions WHERE classroom_id = ?');
    $nSessions->execute([$id]);
    if ((int)$nSessions->fetch()['n'] > 0) {
        return ['ok' => false, 'error' => 'This room has usage history and cannot be deleted. Set it to "disabled" instead to keep the records.'];
    }

    db()->prepare('DELETE FROM class_schedules WHERE classroom_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM classrooms WHERE id = ?')->execute([$id]);

    if (function_exists('log_action')) {
        log_action('CLASSROOM_DELETE', $actorUserId, null, "Deleted classroom #{$id}");
    }

    return ['ok' => true, 'message' => 'Classroom deleted.', 'id' => $id];
}
