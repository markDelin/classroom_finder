<?php
declare(strict_types=1);

/**
 * Classroom Finder — active & recent sessions.
 * Admins can force-end a session (e.g. a lecturer left the room occupied).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_end') {
    if (!check_csrf()) {
        flash('error', 'Session expired — please try again.');
        redirect('sessions.php');
    }
    $id = (int)($_POST['session_id'] ?? 0);
    $st = db()->prepare(
        'SELECT s.*, c.room_number FROM classroom_sessions s
         JOIN classrooms c ON c.id = s.classroom_id
         WHERE s.id = ? AND s.status = \'active\' LIMIT 1'
    );
    $st->execute([$id]);
    if ($s = $st->fetch()) {
        release_session($id, 'admin');
        log_action('FORCE_END_SESSION', (int)$admin['id'], (int)$s['classroom_id'],
            'Admin ended session of room ' . $s['room_number']);
        flash('success', 'Session for room ' . $s['room_number'] . ' was ended.');
    } else {
        flash('error', 'That session is not active.');
    }
    redirect('sessions.php');
}

expire_stale();

$now = date('Y-m-d H:i:s');

$st = db()->prepare(
    "SELECT s.*, c.room_number, c.building, u.full_name AS lecturer
     FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     JOIN users u ON u.id = s.user_id
     WHERE s.status = 'active' AND s.end_time > ?
     ORDER BY s.end_time ASC"
);
$st->execute([$now]);
$active = $st->fetchAll();

$st = db()->prepare("SELECT COUNT(*) AS n FROM classroom_sessions WHERE status <> 'active'");
$st->execute();
$pastTotal = (int)$st->fetch()['n'];
$pp        = page_params($pastTotal, (int)($_GET['page'] ?? 1));

$st = db()->prepare(
    "SELECT s.*, c.room_number, c.building, u.full_name AS lecturer
     FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     JOIN users u ON u.id = s.user_id
     WHERE s.status <> 'active'
     ORDER BY COALESCE(s.released_at, s.end_time) DESC
     LIMIT " . $pp['limit'] . ' OFFSET ' . $pp['offset']
);
$st->execute();
$past = $st->fetchAll();

render_header('Active Sessions', ['prefix' => '../', 'nav' => 'admin', 'active' => 'sessions']);
?>

<div class="page-head">
  <h1><?= icon('timer') ?> Active sessions</h1>
  <p class="muted">Sessions expire automatically at their end time — no cleanup needed.</p>
</div>

<div class="card">
  <?php if (!$active): ?>
    <p class="muted">No active sessions right now.</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Room</th>
        <th>Lecturer</th>
        <th>Window</th>
        <th>Ends in</th>
        <th style="text-align:right">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($active as $s): ?>
      <tr>
        <td data-label="Room"><strong>Room <?= e($s['room_number']) ?></strong> <span class="muted small">· <?= e($s['building']) ?></span></td>
        <td data-label="Lecturer"><?= icon('user') ?> <?= e($s['lecturer']) ?></td>
        <td class="nowrap" data-label="Window"><?= fmt_range($s['start_time'], $s['end_time']) ?></td>
        <td data-label="Ends in"><span class="pill pill--warn"><?= human_duration(minutes_until($s['end_time'])) ?> left</span></td>
        <td class="actions-cell" style="justify-content:flex-end" data-label="Action">
          <form method="post" data-confirm="Force-end this session now?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="force_end">
            <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit"><?= icon('ban') ?> Force end</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Recently finished</h3>
  <?php if (!$past): ?>
    <p class="muted">Nothing recorded yet.</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Room</th>
        <th>Lecturer</th>
        <th>Time</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($past as $s): ?>
      <tr>
        <td data-label="Date"><?= fmt_date($s['start_time']) ?></td>
        <td data-label="Room"><strong>Room <?= e($s['room_number']) ?></strong> <span class="muted small">· <?= e($s['building']) ?></span></td>
        <td data-label="Lecturer"><?= e($s['lecturer']) ?></td>
        <td class="nowrap" data-label="Time"><?= fmt_range($s['start_time'], $s['end_time']) ?></td>
        <td data-label="Status"><span class="pill pill--<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?= page_nav($pastTotal, $pp['page']) ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
