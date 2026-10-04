<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth/auth_check.php';
    require_admin();
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/helpers.php';
}

$pdo = db();

$stUser = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
$userId = (int)($stUser ? $stUser->fetchColumn() : 0);
if ($userId === 0) {
    $pdo->prepare("INSERT INTO users (staff_id, email, username, password, role, account_status)
                   VALUES ('ADMIN-001', 'admin@example.com', 'admin', 'seeded', 'admin', 'approved')")
        ->execute();
    $userId = (int)$pdo->lastInsertId();
}

$buildings = [
    'Main Academic Building' => [
        'code' => 'M',
        'floors' => [
            1 => 10,
            2 => 10,
            3 => 10,
            4 => 10,
        ],
    ],
    'Science & Technology Wing' => [
        'code' => 'ST',
        'floors' => [
            1 => 10,
            2 => 10,
            3 => 10,
        ],
    ],
    'Engineering Complex' => [
        'code' => 'EC',
        'floors' => [
            1 => 10,
            2 => 10,
            3 => 10,
        ],
    ],
];

$roomTypes = ['Lecture Room', 'Computer Laboratory', 'Science Laboratory', 'Seminar Room', 'Multimedia Hall'];
$capacities = [30, 35, 40, 45, 50, 60];

$classrooms = [];
$totalTarget = 100;

foreach ($buildings as $bName => $bData) {
    foreach ($bData['floors'] as $floor => $roomCount) {
        for ($i = 1; $i <= $roomCount; $i++) {
            if (count($classrooms) >= $totalTarget) {
                break 2;
            }
            $rNum = sprintf('%s%d%02d', $bData['code'], $floor, $i);
            $type = $roomTypes[($i + $floor) % count($roomTypes)];
            $cap  = $capacities[($i * 7 + $floor) % count($capacities)];
            $note = ($type === 'Computer Laboratory') ? "Lab equipped with {$cap} PCs" : null;
            $classrooms[] = [
                'room_number' => $rNum,
                'building'    => $bName,
                'floor'       => $floor,
                'capacity'    => $cap,
                'room_type'   => $type,
                'status'      => 'available',
                'note'        => $note,
            ];
        }
    }
}

$stmtCheck = $pdo->prepare('SELECT id FROM classrooms WHERE building = ? AND room_number = ?');
$stmtInsert = $pdo->prepare(
    'INSERT INTO classrooms (room_number, building, floor, capacity, room_type, qr_token, status, note)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmtUpdate = $pdo->prepare(
    'UPDATE classrooms SET floor = ?, capacity = ?, room_type = ?, note = COALESCE(?, note)
     WHERE id = ?'
);
$stmtCheckSession = $pdo->prepare('SELECT COUNT(*) FROM classroom_sessions WHERE classroom_id = ?');
$stmtInsertSession = $pdo->prepare(
    'INSERT INTO classroom_sessions (classroom_id, user_id, start_time, end_time, status)
     VALUES (?, ?, ?, ?, "completed")'
);

$inserted = 0;
$updated = 0;
$sessionsAdded = 0;

$pdo->beginTransaction();
try {
    foreach ($classrooms as $c) {
        $stmtCheck->execute([$c['building'], $c['room_number']]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $roomId = (int)$existing['id'];
            $stmtUpdate->execute([$c['floor'], $c['capacity'], $c['room_type'], $c['note'], $roomId]);
            $updated++;
        } else {
            $token = bin2hex(random_bytes(4));
            $stmtInsert->execute([
                $c['room_number'],
                $c['building'],
                $c['floor'],
                $c['capacity'],
                $c['room_type'],
                $token,
                $c['status'],
                $c['note']
            ]);
            $roomId = (int)$pdo->lastInsertId();
            $inserted++;
        }

        $stmtCheckSession->execute([$roomId]);
        $hasSessions = (int)$stmtCheckSession->fetchColumn();
        if ($hasSessions === 0) {
            $numSessions = (($roomId % 4) + 2);
            for ($s = 0; $s < $numSessions; $s++) {
                $daysAgo = ($s * 2) + ($roomId % 5);
                $hour = 8 + (($s * 2 + $roomId) % 8);
                $duration = [60, 90, 120][($roomId + $s) % 3];
                $startTime = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days {$hour}:00:00"));
                $endTime = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days {$hour}:00:00 +{$duration} minutes"));
                $stmtInsertSession->execute([$roomId, $userId, $startTime, $endTime]);
                $sessionsAdded++;
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$summary = "100 classrooms seeded successfully ({$inserted} inserted, {$updated} updated, {$sessionsAdded} sessions generated).";

if (PHP_SAPI === 'cli') {
    echo $summary . PHP_EOL;
} else {
    flash('success', $summary);
    redirect('../admin/classrooms.php');
}
