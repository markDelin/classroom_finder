<?php
declare(strict_types=1);

/**
 * Classroom Finder — administrator dashboard (§18).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

$rooms = fetch_classrooms();
$count = ['available' => 0, 'occupied' => 0, 'reserved' => 0, 'unavailable' => 0];
foreach ($rooms as $r) {
    $count[$r['computed']]++;
}

$pending = db()->query(
    "SELECT id, full_name, email, staff_id, department, created_at
     FROM users WHERE role = 'lecturer' AND account_status = 'pending'
     ORDER BY created_at ASC"
)->fetchAll();
$pendingN = count($pending);

$activeSessions = array_filter($rooms, fn($r) => $r['computed'] === 'occupied');

$recentLogs = db()->query(
    'SELECT l.*, u.full_name FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.timestamp DESC LIMIT 8'
)->fetchAll();

render_header('Admin Dashboard', ['prefix' => '../', 'nav' => 'admin', 'active' => 'dashboard']);
?>

<div class="page-head">
  <h1><?= icon('layout-dashboard') ?> Administrator Dashboard</h1>
  <p class="muted">Live overview of classrooms, accounts and system activity.</p>
</div>

<div class="tiles">
  <div class="tile"><span class="tile__num"><?= count($rooms) ?></span><span class="tile__label">Total classrooms</span></div>
  <div class="tile tile--ok"><span class="tile__num"><?= icon('circle-check') ?> <?= $count['available'] ?></span><span class="tile__label">Available</span></div>
  <div class="tile tile--danger"><span class="tile__num"><?= icon('clock') ?> <?= $count['occupied'] ?></span><span class="tile__label">Occupied</span></div>
  <div class="tile tile--warn"><span class="tile__num"><?= icon('calendar-clock') ?> <?= $count['reserved'] ?></span><span class="tile__label">Reserved</span></div>
  <div class="tile tile--off"><span class="tile__num"><?= icon('ban') ?> <?= $count['unavailable'] ?></span><span class="tile__label">Unavailable</span></div>
</div>

<div class="card">
  <div class="card__head">
    <h3>Pending lecturer approvals</h3>
    <span class="pill <?= $pendingN ? 'pill--pending' : '' ?>"><?= $pendingN ?> pending</span>
  </div>
  <?php if (!$pending): ?>
    <p class="muted">No registrations waiting — all caught up. <?= icon('party-popper') ?></p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Staff ID</th><th>Email</th><th>Department</th><th style="width:13rem">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($pending as $p): ?>
      <tr>
        <td data-label="Name"><?= e($p['full_name']) ?></td>
        <td data-label="Staff ID"><?= e($p['staff_id']) ?></td>
        <td class="small" data-label="Email"><?= e($p['email']) ?></td>
        <td class="muted small" data-label="Department"><?= e($p['department'] ?: '—') ?></td>
        <td class="actions-cell">
          <form method="post" action="users.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
            <button class="btn btn--ok btn--sm" type="submit">Approve</button>
          </form>
          <form method="post" action="users.php" class="inline-form" data-confirm="Reject this registration? They will not be able to log in.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
            <button class="btn btn--danger btn--sm" type="submit">Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card__head">
      <h3><?= icon('clock') ?> Active sessions now</h3>
      <a class="btn btn--ghost btn--sm" href="sessions.php">All sessions <?= icon('arrow-right') ?></a>
    </div>
    <?php if (!$activeSessions): ?>
      <p class="muted">Every room is free right now.</p>
    <?php else: ?>
      <ul class="plain-list">
        <?php foreach ($activeSessions as $s): ?>
        <li>
          <strong><?= e($s['room_number']) ?></strong>
          <span class="muted small"><?= e($s['building']) ?></span> —
          <?= e($s['session_lecturer'] ?? '?') ?>
          <span class="muted small">(until <?= fmt_time($s['session_end']) ?>)</span>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <h3>Recent activity</h3>
      <a class="btn btn--ghost btn--sm" href="logs.php">Full log <?= icon('arrow-right') ?></a>
    </div>
    <?php if (!$recentLogs): ?>
      <p class="muted">Nothing logged yet.</p>
    <?php else: ?>
      <ul class="plain-list">
        <?php foreach ($recentLogs as $l): ?>
        <li>
          <strong><?= e(ucwords(strtolower(str_replace('_', ' ', $l['action'])))) ?></strong>
          <span class="muted">· <?= e($l['full_name'] ?? 'System') ?></span>
          <div class="muted small"><?= e($l['details'] ?: '') ?> · <?= fmt_time($l['timestamp']) ?></div>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
