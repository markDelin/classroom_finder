<?php
declare(strict_types=1);

/**
 * Classroom Finder — usage history (§21).
 * Filterable record of every session: who used which room, when, how long.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

$roomId = (string)($_GET['room'] ?? '');
$userId = (string)($_GET['user'] ?? '');
[$from, $to, $rangeLabel] = report_range();
$activeRange = (string)($_GET['range'] ?? '');

$where  = [];
$params = [];
if ($roomId !== '') {
    $where[]  = 's.classroom_id = ?';
    $params[] = (int)$roomId;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[]  = 's.start_time >= ?';
    $params[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[]  = 's.start_time <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($userId !== '') {
    $where[]  = 's.user_id = ?';
    $params[] = (int)$userId;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// CSV download — the same filtered dataset, without the 500-row screen cap.
if (wants_csv()) {
    $st = db()->prepare(
        'SELECT s.*, c.room_number, c.building, u.full_name
         FROM classroom_sessions s
         JOIN classrooms c ON c.id = s.classroom_id
         JOIN users u ON u.id = s.user_id' .
        $whereSql .
        ' ORDER BY s.start_time DESC'
    );
    $st->execute($params);
    stream_csv(
        'usage-history-' . date('Ymd-His') . '.csv',
        ['Date', 'Room', 'Building', 'Lecturer', 'Start', 'End', 'Minutes', 'Status'],
        array_map(static fn(array $s): array => [
            date('Y-m-d', strtotime($s['start_time'])),
            $s['room_number'],
            $s['building'],
            $s['full_name'],
            date('H:i', strtotime($s['start_time'])),
            date('H:i', strtotime($s['end_time'])),
            minutes_between($s['start_time'], $s['end_time']),
            $s['status'],
        ], $st->fetchAll())
    );
}

// totals for the whole filtered set (not just the visible page)
$st = db()->prepare(
    'SELECT COUNT(*) AS n, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)), 0) AS mins
     FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     JOIN users u ON u.id = s.user_id' .
    $whereSql
);
$st->execute($params);
$tot           = $st->fetch();
$totalSessions = (int)$tot['n'];
$totalMinutes  = (int)$tot['mins'];

$pp = page_params($totalSessions, (int)($_GET['page'] ?? 1));

$st = db()->prepare(
    'SELECT s.*, c.room_number, c.building, u.full_name
     FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     JOIN users u ON u.id = s.user_id' .
    $whereSql .
    ' ORDER BY s.start_time DESC LIMIT ' . $pp['limit'] . ' OFFSET ' . $pp['offset']
);
$st->execute($params);
$rows = $st->fetchAll();

$rooms       = db()->query('SELECT id, room_number, building FROM classrooms ORDER BY building, room_number')->fetchAll();
$lecturers   = db()->query("SELECT id, full_name FROM users WHERE role IN ('lecturer','admin') ORDER BY full_name")->fetchAll();

render_header('Usage History', ['prefix' => '../', 'nav' => 'admin', 'active' => 'history']);
?>

<?php
$keepQ    = array_filter(['room' => $roomId, 'user' => $userId], static fn($v) => $v !== '');
$presetUrl = static fn(string $r): string => 'history.php?' . http_build_query(array_merge($keepQ, $r === '' ? [] : ['range' => $r]));
$rangesOn  = $activeRange !== '' || $from !== '' || $to !== '';
?>
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
  <div>
    <h1><?= icon('chart-column') ?> Usage history</h1>
    <p class="muted">
      <?= $totalSessions ?> session<?= $totalSessions === 1 ? '' : 's' ?> · <?= human_duration($totalMinutes) ?> of room time
      <?= $rangesOn ? ' · ' . e($rangeLabel) : '' ?>
    </p>
  </div>
  <a class="btn btn--primary btn--sm" href="history.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
    <?= icon('download') ?> Export CSV</a>
</div>

<div class="card">
  <div class="range-presets">
    <span class="muted small">Range</span>
    <a class="chip-btn <?= $activeRange === 'today' ? 'is-active' : '' ?>" href="<?= $presetUrl('today') ?>">Today</a>
    <a class="chip-btn <?= $activeRange === 'week' ? 'is-active' : '' ?>" href="<?= $presetUrl('week') ?>">This week</a>
    <a class="chip-btn <?= $activeRange === 'month' ? 'is-active' : '' ?>" href="<?= $presetUrl('month') ?>">This month</a>
    <a class="chip-btn <?= !$rangesOn ? 'is-active' : '' ?>" href="<?= $presetUrl('') ?>">All time</a>
  </div>
  <form method="get" class="filter-row">
    <select name="room">
      <option value="">All rooms</option>
      <?php foreach ($rooms as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= $roomId === (string)$r['id'] ? 'selected' : '' ?>>
          <?= e($r['building']) ?> · <?= e($r['room_number']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="user">
      <option value="">All lecturers</option>
      <?php foreach ($lecturers as $l): ?>
        <option value="<?= (int)$l['id'] ?>" <?= $userId === (string)$l['id'] ? 'selected' : '' ?>><?= e($l['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="stacked">From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="stacked">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn--sm" type="submit">Apply</button>
    <a class="btn btn--ghost btn--sm" href="history.php">Reset</a>
  </form>

  <?php if (!$rows): ?>
    <p class="muted">No usage recorded for this filter.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Room</th><th>Lecturer</th><th>Time</th><th>Duration</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $s): ?>
      <tr>
        <td data-label="Date"><?= fmt_date($s['start_time']) ?></td>
        <td data-label="Room"><strong>ROOM <?= e($s['room_number']) ?></strong> <span class="muted small"><?= e($s['building']) ?></span></td>
        <td data-label="Lecturer"><?= e($s['full_name']) ?></td>
        <td data-label="Time"><?= fmt_range($s['start_time'], $s['end_time']) ?></td>
        <td data-label="Duration"><?= human_duration(minutes_between($s['start_time'], $s['end_time'])) ?></td>
        <td data-label="Status"><span class="pill pill--<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?= page_nav($totalSessions, $pp['page']) ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
