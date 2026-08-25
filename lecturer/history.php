<?php
declare(strict_types=1);

/**
 * Classroom Finder — lecturer's own usage history (§5).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();

$st = db()->prepare(
    'SELECT s.*, c.room_number, c.building
     FROM classroom_sessions s JOIN classrooms c ON c.id = s.classroom_id
     WHERE s.user_id = ?
     ORDER BY s.start_time DESC
     LIMIT 200'
);
$st->execute([$user['id']]);
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

<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">No sessions yet. Scan a classroom QR to record your first one.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Room</th><th>Time</th><th>Duration</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $s): ?>
      <tr>
        <td><?= fmt_date($s['start_time']) ?></td>
        <td><strong><?= e($s['room_number']) ?></strong> <span class="muted small"><?= e($s['building']) ?></span></td>
        <td><?= fmt_range($s['start_time'], $s['end_time']) ?></td>
        <td><?=
            $s['status'] === 'active'
                ? human_duration(minutes_between($s['start_time'], date('Y-m-d H:i:s'))) . ' so far'
                : human_duration(minutes_between($s['start_time'], $s['end_time']))
        ?></td>
        <td><span class="pill pill--<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
