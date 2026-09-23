<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth/auth_check.php';
    require_admin();
}

$classrooms = [
    ['room_number' => '101', 'building' => 'New Building', 'floor' => 1, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '102', 'building' => 'New Building', 'floor' => 1, 'capacity' => 35, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '103', 'building' => 'New Building', 'floor' => 1, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '104', 'building' => 'New Building', 'floor' => 1, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '105', 'building' => 'New Building', 'floor' => 1, 'capacity' => 25, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '201', 'building' => 'New Building', 'floor' => 2, 'capacity' => 35, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '202', 'building' => 'New Building', 'floor' => 2, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '203', 'building' => 'New Building', 'floor' => 2, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '204', 'building' => 'New Building', 'floor' => 2, 'capacity' => 35, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '301', 'building' => 'New Building', 'floor' => 3, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '302', 'building' => 'New Building', 'floor' => 3, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '303', 'building' => 'New Building', 'floor' => 3, 'capacity' => 30, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],

    ['room_number' => 'C10', 'building' => 'New Building', 'floor' => 1, 'capacity' => 45, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => 'C11', 'building' => 'New Building', 'floor' => 1, 'capacity' => 45, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => 'C9', 'building' => 'New Building', 'floor' => 2, 'capacity' => 40, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => 'C8', 'building' => 'New Building', 'floor' => 2, 'capacity' => 40, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => 'College Computer Lab 2'],
    ['room_number' => '2hs1', 'building' => 'New Building', 'floor' => 3, 'capacity' => 50, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],
    ['room_number' => '2hs2', 'building' => 'New Building', 'floor' => 3, 'capacity' => 80, 'room_type' => 'Lecture Room', 'status' => 'available', 'note' => null],

];

$stmtCheck = db()->prepare('SELECT id FROM classrooms WHERE building = ? AND room_number = ?');
$stmtInsert = db()->prepare(
    'INSERT INTO classrooms (room_number, building, floor, capacity, room_type, qr_token, status, note)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmtUpdate = db()->prepare(
    'UPDATE classrooms SET floor = ?, capacity = ?, room_type = ?, note = COALESCE(?, note)
     WHERE id = ?'
);

$inserted = 0;
$updated = 0;

foreach ($classrooms as $c) {
    $stmtCheck->execute([$c['building'], $c['room_number']]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $stmtUpdate->execute([$c['floor'], $c['capacity'], $c['room_type'], $c['note'], $existing['id']]);
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
        $inserted++;
    }
}

$summary = "Seeding completed: {$inserted} inserted, {$updated} updated. Total seed classrooms: " . count($classrooms);

if (PHP_SAPI === 'cli') {
    echo $summary . PHP_EOL;
} else {
    flash('success', $summary);
    redirect('admin/classrooms.php');
}
