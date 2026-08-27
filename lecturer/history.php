<?php
declare(strict_types=1);

/**
 * Classroom Finder — lecturer's own usage history.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();
$q    = trim((string)($_GET['q'] ?? ''));

$where  = ['s.user_id = ?'];
$params = [(int)$user['id']];
if ($q !== '') {
    $where[]  = '(c.room_number LIKE ? OR c.building LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}

$st = db()->prepare(
    'SELECT s.*, c.room_number, c.building
     FROM classroom_sessions s JOIN classrooms c ON c.id = s.classroom_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY s.start_time DESC
     LIMIT 200'
);
$st->execute($params);
$rows = $st->fetchAll();

$totalMinutes = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'active') {
        $totalMinutes += max(0, minutes_between($r['start_time'], date('Y-m-d H:i:s')));
    } else {
        $totalMinutes += minutes_between($r['start_time'], $r['end_time']);
    }
}

render_header('My Usage History', ['prefix' => '../', 'nav' => 'lecturer', 'active' => 'history']);
?>

<div class="page-head">
  <h1><?= icon('history') ?> My classroom history</h1>
  <p class="muted"><?= count($rows) ?> session(s) · <?= human_duration($totalMinutes) ?> total</p>
</div>

<div class="card" style="margin-bottom: 1.2rem;">
  <form method="get" class="filter-row">
    <input type="search" name="q" placeholder="Search room number, building…" value="<?= e($q) ?>">
    <button class="btn btn--sm" type="submit">Filter</button>
    <?php if ($q !== ''): ?>
      <a href="history.php" class="btn btn--ghost btn--sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">No sessions yet. Scan a classroom QR to record your first one.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Date</th><th>Room</th><th>Time</th><th>Duration</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $s): ?>
      <tr>
        <td class="nowrap"><?= fmt_date($s['start_time']) ?></td>
        <td class="nowrap"><strong><?= e($s['room_number']) ?></strong> <span class="muted small"><?= e($s['building']) ?></span></td>
        <td class="nowrap"><?= fmt_range($s['start_time'], $s['end_time']) ?></td>
        <td class="nowrap"><?=
            $s['status'] === 'active'
                ? human_duration(minutes_between($s['start_time'], date('Y-m-d H:i:s'))) . ' so far'
                : human_duration(minutes_between($s['start_time'], $s['end_time']))
        ?></td>
        <td><span class="pill pill--<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
