<?php
declare(strict_types=1);

/**
 * Classroom Finder — Room Service
 *
 * Encapsulates all database operations, queries, status calculations,
 * and mutations for classrooms.
 *
 * @package ClassroomFinder\Services
 */

require_once __DIR__ . '/../config/database.php';

/** Supported valid classroom types */
const ROOM_SERVICE_TYPES = ['Lecture', 'Laboratory', 'Seminar', 'Auditorium'];

/**
 * Retrieve a classroom by primary key ID.
 *
 * @param int $id Classroom primary key.
 * @return array<string, mixed>|null
 */
function room_get(int $id): ?array
{
    return room_get_with_status($id);
}

/**
 * Retrieve a classroom by its raw uncomputed table record.
 *
 * @param int $id Classroom primary key.
 * @return array<string, mixed>|null
 */
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

/**
 * Look up a classroom by secret QR code token string.
 *
 * @param string $token Secret QR token.
 * @return array<string, mixed>|null
 */
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

/**
 * Parse and extract 32-character hex QR token from scanned raw input payload or URL string.
 *
 * @param string|null $raw Scanned text payload or URL string.
 * @return string|null Extracted 32-char hex token or null if unparseable.
 */
function room_extract_qr_token(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    return preg_match('/[0-9a-f]{32}/i', trim($raw), $m) ? strtolower($m[0]) : null;
}

/**
 * Single room lookup by primary key with computed live status fields.
 *
 * @param int $id Classroom primary key ID.
 * @return array<string, mixed>|null
 */
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

    $reserveWindow = function_exists('get_setting_int') ? get_setting_int('reserve_window_minutes', 45) : 45;

    $now   = date('Y-m-d H:i:s');
    $soon  = date('Y-m-d H:i:s', time() + $reserveWindow * 60);
    $dow   = (int)date('N');
    $curt  = date('H:i:s');
    $today = date('Y-m-d');

    $st = db()->prepare(
        "SELECT c.*,
                s.id            AS session_id,
                s.start_time    AS session_start,
                s.end_time      AS session_end,
                su.full_name    AS session_lecturer,
                r.id            AS reservation_id,
                r.start_time    AS reservation_start,
                r.end_time      AS reservation_end,
                r.purpose       AS reservation_purpose,
                ru.full_name    AS reservation_by,
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
         LEFT JOIN reservations r
                ON r.classroom_id = c.id AND r.status = 'active'
               AND r.start_time <= :soon AND r.end_time > :now3
         LEFT JOIN users ru ON ru.id = r.user_id
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
        ':now1' => $now, ':now2' => $now, ':now3' => $now, ':soon' => $soon,
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
    } elseif (!empty($r['reservation_id'])) {
        $r['computed']     = 'reserved';
        $r['available_at'] = null;
    } else {
        $r['computed']     = 'available';
        $r['available_at'] = null;
    }
    return $r;
}

/**
 * Retrieve list of classrooms with live calculated occupancy and reservation statuses.
 *
 * @param array<string, mixed> $f Filter parameters (q, building, floor, type, mincap, status).
 * @return array<int, array<string, mixed>>
 */
function room_fetch_all(array $f = []): array
{
    if (function_exists('expire_stale')) {
        expire_stale();
    } elseif (function_exists('session_expire_stale')) {
        session_expire_stale();
    }

    $reserveWindow = function_exists('get_setting_int') ? get_setting_int('reserve_window_minutes', 45) : 45;

    $now  = date('Y-m-d H:i:s');
    $soon = date('Y-m-d H:i:s', time() + $reserveWindow * 60);

    $dow     = (int)date('N');
    $curtime = date('H:i:s');
    $today   = date('Y-m-d');

    $sql = "SELECT c.*,
                   MAX(s.id)         AS session_id,
                   MAX(s.start_time) AS session_start,
                   MAX(s.end_time)   AS session_end,
                   MAX(su.full_name) AS session_lecturer,
                   MAX(r.id)         AS reservation_id,
                   MIN(r.start_time) AS reservation_start,
                   MAX(r.end_time)   AS reservation_end,
                   MAX(r.purpose)    AS reservation_purpose,
                   MAX(ru.full_name) AS reservation_by,
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
            LEFT JOIN reservations r
                   ON r.classroom_id = c.id AND r.status = 'active'
                  AND r.start_time <= :soon AND r.end_time > :now3
            LEFT JOIN users ru ON ru.id = r.user_id
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
        ':now3'     => $now,
        ':soon'     => $soon,
        ':dow'      => $dow,
        ':curtime1' => $curtime,
        ':curtime2' => $curtime,
        ':today'    => $today,
    ];

    if (!empty($f['q'])) {
        $where[]              = '(c.room_number LIKE :q OR c.building LIKE :q OR c.room_type LIKE :q OR c.note LIKE :q OR cs.subject LIKE :q OR cs.instructor LIKE :q OR su.full_name LIKE :q OR ru.full_name LIKE :q)';
        $params[':q']         = '%' . trim((string)$f['q']) . '%';
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
        } elseif (!empty($r['reservation_id'])) {
            $r['computed']     = 'reserved';
            $r['available_at'] = null;
        } else {
            $r['computed']     = 'available';
            $r['available_at'] = null;
        }
        $rooms[] = $r;
    }

    if (!empty($f['status'])) {
        $rooms = array_values(array_filter($rooms, fn($r) => $r['computed'] === $f['status']));
    }

    return $rooms;
}

/**
 * Create or update a classroom record.
 *
 * @param array<string, mixed> $data Input payload (room_number, building, floor, capacity, room_type, note).
 * @param int|null $id Existing classroom ID for update, or null for creation.
 * @param int|null $actorUserId Admin ID performing the operation.
 * @return array<string, mixed> Result contract ['ok' => bool, 'message' => string, 'id' => int] or ['ok' => false, 'error' => string]
 */
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

    // Uniqueness check for (building, room_number)
    $st = db()->prepare('SELECT id FROM classrooms WHERE building = ? AND room_number = ? AND id <> ?');
    $st->execute([$building, $roomNumber, (int)($id ?? 0)]);
    if ($st->fetch()) {
        return ['ok' => false, 'error' => "{$building} already has a room {$roomNumber}."];
    }

    if ($id === null || $id <= 0) {
        $qrToken = bin2hex(random_bytes(16));
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

/**
 * Set status for a classroom (available, maintenance, disabled).
 *
 * @param int $id Classroom ID.
 * @param string $status New status value.
 * @param int|null $actorUserId Admin ID performing change.
 * @return array<string, mixed>
 */
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

/**
 * Delete a classroom if no usage history exists.
 *
 * @param int $id Classroom primary key ID.
 * @param int|null $actorUserId Admin ID performing deletion.
 * @return array<string, mixed>
 */
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

    db()->prepare('DELETE FROM reservations WHERE classroom_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM class_schedules WHERE classroom_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM classrooms WHERE id = ?')->execute([$id]);

    if (function_exists('log_action')) {
        log_action('CLASSROOM_DELETE', $actorUserId, null, "Deleted classroom #{$id}");
    }

    return ['ok' => true, 'message' => 'Classroom deleted.', 'id' => $id];
}
