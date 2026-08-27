<?php
declare(strict_types=1);

/**
 * Classroom Finder — lecturer dashboard.
 * Shows the lecturer's current occupancy with a release button, quick stats
 * and recent sessions. Scanning happens on scanner.php.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();

$active = get_active_session_for((int)$user['id']);

// quick stats
$st = db()->prepare(
    "SELECT COUNT(*) AS sessions,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_time, IF(status = 'active', ?, end_time))), 0) AS minutes
     FROM classroom_sessions
     WHERE user_id = ? AND MONTH(start_time) = MONTH(?) AND YEAR(start_time) = YEAR(?)"
);
$nowSql = date('Y-m-d H:i:s');
$st->execute([$nowSql, $user['id'], $nowSql, $nowSql]);
$stats = $st->fetch();

$st = db()->prepare(
    'SELECT s.*, c.room_number, c.building
     FROM classroom_sessions s JOIN classrooms c ON c.id = s.classroom_id
     WHERE s.user_id = ? ORDER BY s.start_time DESC LIMIT 6'
);
$st->execute([$user['id']]);
$recent = $st->fetchAll();

render_header('Lecturer Dashboard', ['prefix' => '../', 'nav' => 'lecturer', 'active' => 'dashboard']);
?>

<div class="page-head">
  <h1>Welcome, <?= e($user['full_name']) ?> <?= icon('hand') ?></h1>
  <p class="muted">Scan the QR code outside a classroom to record your session.</p>
</div>

<?php if ($active): ?>
<div class="card current-room">
  <div>
    <p class="eyebrow">MY CURRENT CLASSROOM</p>
    <h2><?= e($active['room_number']) ?> <span class="muted">· <?= e($active['building']) ?></span></h2>
    <p>Occupied · <?= fmt_range($active['start_time'], $active['end_time']) ?>
       <span class="muted">(ends in <?= human_duration(minutes_until($active['end_time'])) ?>)</span></p>
  </div>
  <form method="post" action="release.php" data-confirm="Release <?= e($active['room_number']) ?> now? The room will show as AVAILABLE immediately.">
    <?= csrf_field() ?>
    <input type="hidden" name="session_id" value="<?= (int)$active['id'] ?>">
    <button class="btn btn--danger" type="submit">Release classroom</button>
  </form>
</div>
<?php else: ?>
<div class="card cta-card">
  <div>
    <p class="eyebrow">NOT IN A CLASSROOM RIGHT NOW</p>
    <p class="muted">Occupy a room by scanning its QR code — it only takes a few seconds.</p>
  </div>
  <a class="btn btn--primary" href="scanner.php"><?= icon('scan-line') ?> Scan classroom QR</a>
</div>
<?php endif; ?>

<div class="tiles">
  <div class="tile"><span class="tile__num"><?= human_duration((int)$stats['minutes']) ?></span><span class="tile__label">teaching time this month</span></div>
  <div class="tile"><span class="tile__num"><?= (int)$stats['sessions'] ?></span><span class="tile__label">sessions this month</span></div>
</div>

<div class="card">
  <div class="card__head">
    <h3>Recent sessions</h3>
    <a class="btn btn--ghost btn--sm" href="history.php">View full history <?= icon('arrow-right') ?></a>
  </div>
  <?php if (!$recent): ?>
    <p class="muted">No sessions yet — scan a classroom QR to get started.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Room</th><th>Date</th><th>Time</th><th>Duration</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $s): ?>
      <tr>
        <td class="nowrap"><strong><?= e($s['room_number']) ?></strong> <span class="muted small"><?= e($s['building']) ?></span></td>
        <td class="nowrap"><?= fmt_date($s['start_time']) ?></td>
        <td class="nowrap"><?= fmt_range($s['start_time'], $s['end_time']) ?></td>
        <td class="nowrap"><?= human_duration(minutes_between($s['start_time'], $s['end_time'])) ?></td>
        <td><span class="pill pill--<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
