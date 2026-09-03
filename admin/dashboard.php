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
    "SELECT id, full_name, username, email, staff_id, department, created_at
     FROM users WHERE role = 'lecturer' AND account_status = 'pending'
     ORDER BY created_at ASC"
)->fetchAll();
$pendingN = count($pending);

$activeSessions = array_filter($rooms, fn($r) => $r['computed'] === 'occupied');

$recentLogs = db()->query(
    'SELECT l.*, u.full_name FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.timestamp DESC LIMIT 5'
)->fetchAll();

// System Analytics
$totalCapacity = array_reduce($rooms, fn($sum, $r) => $sum + (int)($r['capacity'] ?? 0), 0);
$totalRooms = count($rooms);
$activeRoomsCount = $count['occupied'] + $count['reserved'];
$utilizationRate = $totalRooms > 0 ? (int)round(($activeRoomsCount / $totalRooms) * 100) : 0;

$userStats = db()->query(
    "SELECT 
        COUNT(*) AS total_users,
        SUM(CASE WHEN role = 'lecturer' AND account_status = 'approved' THEN 1 ELSE 0 END) AS approved_lecturers,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins
     FROM users"
)->fetch();

$todayDayOfWeek = (int)date('N');
$schedulesToday = (int)db()->query(
    "SELECT COUNT(*) FROM class_schedules WHERE day_of_week = {$todayDayOfWeek} AND is_active = 1"
)->fetchColumn();

$reservationsToday = (int)db()->query(
    "SELECT COUNT(*) FROM reservations WHERE DATE(start_time) = CURDATE() AND status != 'cancelled'"
)->fetchColumn();

$sessionsToday = (int)db()->query(
    "SELECT COUNT(*) FROM classroom_sessions WHERE DATE(start_time) = CURDATE()"
)->fetchColumn();

$forceOpensToday = (int)db()->query(
    "SELECT COUNT(*) FROM schedule_force_open WHERE exc_date = CURDATE()"
)->fetchColumn();

render_header('Admin Dashboard', ['prefix' => '../', 'nav' => 'admin', 'active' => 'dashboard']);
?>

<div class="page-head">
  <h1><?= icon('layout-dashboard') ?> Administrator Dashboard</h1>
  <p class="muted">Live overview of classrooms, accounts and system activity.</p>
</div>

<div class="tiles tiles--5">
  <div class="tile"><span class="tile__num"><?= count($rooms) ?></span><span class="tile__label">Total classrooms</span></div>
  <div class="tile tile--ok"><span class="tile__num"><?= icon('circle-check') ?> <?= $count['available'] ?></span><span class="tile__label">Available</span></div>
  <div class="tile tile--danger"><span class="tile__num"><?= icon('clock') ?> <?= $count['occupied'] ?></span><span class="tile__label">Occupied</span></div>
  <div class="tile tile--warn"><span class="tile__num"><?= icon('calendar-clock') ?> <?= $count['reserved'] ?></span><span class="tile__label">Reserved</span></div>
  <div class="tile tile--off"><span class="tile__num"><?= icon('ban') ?> <?= $count['unavailable'] ?></span><span class="tile__label">Unavailable</span></div>
</div>

<div class="card" style="margin-bottom:1.2rem;">
  <div class="card__head">
    <h3><?= icon('chart-column') ?> System Analytics & Insights</h3>
    <span class="muted small">Real-time metrics and operations overview</span>
  </div>

  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .85rem; margin-top: .3rem;">
    <!-- Room Utilization -->
    <div style="background: var(--bg); padding: .85rem 1rem; border-radius: var(--radius); border: 1px solid var(--border);">
      <div class="muted small" style="margin-bottom: .4rem; display: flex; align-items: center; justify-content: space-between;">
        <span>Room Utilization Rate</span>
        <strong style="color: var(--fg);"><?= $utilizationRate ?>%</strong>
      </div>
      <div style="width: 100%; background: var(--border); height: 7px; border-radius: 4px; overflow: hidden;">
        <div style="width: <?= min(100, $utilizationRate) ?>%; background: var(--primary); height: 100%;"></div>
      </div>
      <div class="muted small" style="margin-top: .4rem;">
        <?= $activeRoomsCount ?> of <?= $totalRooms ?> rooms in use
      </div>
    </div>

    <!-- Seating & Timetable -->
    <div style="background: var(--bg); padding: .85rem 1rem; border-radius: var(--radius); border: 1px solid var(--border);">
      <div class="muted small" style="margin-bottom: .25rem;"><?= icon('building-2') ?> Campus Seating & Schedules</div>
      <div style="font-size: 1.35rem; font-weight: 700; color: var(--fg); font-family: var(--font-display); line-height: 1.2;">
        <?= number_format($totalCapacity) ?> <span style="font-size: .8rem; font-weight: normal; color: var(--muted);">total seats</span>
      </div>
      <div class="muted small" style="margin-top: .25rem;">
        <?= icon('calendar-clock') ?> <?= $schedulesToday ?> timetable slots today
      </div>
    </div>

    <!-- Operations Today -->
    <div style="background: var(--bg); padding: .85rem 1rem; border-radius: var(--radius); border: 1px solid var(--border);">
      <div class="muted small" style="margin-bottom: .25rem;"><?= icon('clock') ?> Operations Today</div>
      <div style="font-size: 1.35rem; font-weight: 700; color: var(--fg); font-family: var(--font-display); line-height: 1.2;">
        <?= $sessionsToday ?> <span style="font-size: .8rem; font-weight: normal; color: var(--muted);">sessions started</span>
      </div>
      <div class="muted small" style="margin-top: .25rem;">
        <?= icon('calendar-days') ?> <?= $reservationsToday ?> reservations &bull; <?= $forceOpensToday ?> force-opens
      </div>
    </div>

    <!-- Registered Accounts -->
    <div style="background: var(--bg); padding: .85rem 1rem; border-radius: var(--radius); border: 1px solid var(--border);">
      <div class="muted small" style="margin-bottom: .25rem;"><?= icon('users') ?> System Accounts</div>
      <div style="font-size: 1.35rem; font-weight: 700; color: var(--fg); font-family: var(--font-display); line-height: 1.2;">
        <?= (int)($userStats['total_users'] ?? 0) ?> <span style="font-size: .8rem; font-weight: normal; color: var(--muted);">registered users</span>
      </div>
      <div class="muted small" style="margin-top: .25rem;">
        <?= (int)($userStats['approved_lecturers'] ?? 0) ?> lecturers &bull; <?= (int)($userStats['admins'] ?? 0) ?> admins &bull; <?= $pendingN ?> pending
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <h3>Pending lecturer approvals</h3>
    <span class="pill <?= $pendingN ? 'pill--pending' : '' ?>"><?= $pendingN ?> pending</span>
  </div>
  <?php if (!$pending): ?>
    <p class="muted">No registrations waiting — all caught up. <?= icon('party-popper') ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Staff ID</th>
        <th>Email</th>
        <th>Department</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $p): ?>
      <tr>
        <td class="nowrap" data-label="Name"><strong><?= e($p['full_name']) ?></strong><?php if (!empty($p['username'])): ?> <span class="muted small">@<?= e($p['username']) ?></span><?php endif; ?></td>
        <td class="nowrap" data-label="Staff ID"><?= e($p['staff_id']) ?></td>
        <td class="cell-truncate" data-label="Email"><?= e($p['email']) ?></td>
        <td class="cell-truncate" data-label="Department"><?= e($p['department'] ?: '—') ?></td>
        <td data-label="Actions">
          <div class="actions-cell" style="justify-content:flex-end">
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
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
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
