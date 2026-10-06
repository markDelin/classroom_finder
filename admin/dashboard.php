<?php
declare(strict_types=1);

// Admin Dashboard: real-time room availability KPIs, pending lecturer approvals, and usage stats.
require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

// Fetch all classrooms and compute live availability breakdown
$rooms = fetch_classrooms();
$count = ['available' => 0, 'occupied' => 0, 'unavailable' => 0];
foreach ($rooms as $r) {
    $count[$r['computed']]++;
}

// Pending lecturer accounts awaiting administrator review
$pending = db()->query(
    "SELECT id, full_name, username, email, staff_id, department, created_at
     FROM users WHERE role = 'lecturer' AND account_status = 'pending'
     ORDER BY created_at ASC"
)->fetchAll();
$pendingN = count($pending);

// Rooms currently occupied by active sessions or scheduled classes
$activeSessions = array_filter($rooms, fn($r) => $r['computed'] === 'occupied');

// 5 most recent security and operational activity log entries
$recentLogs = db()->query(
    'SELECT l.*, u.full_name FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.timestamp DESC LIMIT 5'
)->fetchAll();

// Capacity and utilization KPI calculations
$totalCapacity = array_reduce($rooms, fn($sum, $r) => $sum + (int)($r['capacity'] ?? 0), 0);
$totalRooms = count($rooms);
$activeRoomsCount = $count['occupied'];
$utilizationRate = $totalRooms > 0 ? (int)round(($activeRoomsCount / $totalRooms) * 100) : 0;

// User role distribution counts
$userStats = db()->query(
    "SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN role = 'lecturer' AND account_status = 'approved' THEN 1 ELSE 0 END) AS approved_lecturers,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins
     FROM users"
)->fetch();

// Daily schedule slots, sessions initiated, and force-open counts
$todayDayOfWeek = (int)date('N');
$schedulesToday = (int)db()->query(
    "SELECT COUNT(*) FROM class_schedules WHERE day_of_week = {$todayDayOfWeek} AND is_active = 1"
)->fetchColumn();

$sessionsToday = (int)db()->query(
    "SELECT COUNT(*) FROM classroom_sessions WHERE DATE(start_time) = CURDATE()"
)->fetchColumn();

$forceOpensToday = (int)db()->query(
    "SELECT COUNT(*) FROM schedule_force_open WHERE exc_date = CURDATE()"
)->fetchColumn();

// Top 5 classrooms with highest usage over past 30 days
$topRooms = db()->query(
    'SELECT c.id, c.room_number, c.building,
            COUNT(s.id) AS session_count,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)), 0) AS total_minutes
     FROM classroom_sessions s
     JOIN classrooms c ON c.id = s.classroom_id
     GROUP BY c.id, c.room_number, c.building
     ORDER BY session_count DESC, total_minutes DESC
     LIMIT 5'
)->fetchAll();
$totalTopSessions = (int)array_sum(array_column($topRooms, 'session_count'));
$donutColors = ['#0F3B6E', '#2563eb', '#0d9488', '#d97706', '#64748b'];

render_header('Admin Dashboard', ['prefix' => '../', 'nav' => 'admin', 'active' => 'dashboard']);
?>

<div class="page-head">
  <h1><?= icon('layout-dashboard') ?> Administrator Dashboard</h1>
  <p class="muted">Live overview of classrooms, accounts and system activity.</p>
</div>

<div class="tiles tiles--4">
  <div class="tile"><span class="tile__num"><?= count($rooms) ?></span><span class="tile__label">Total classrooms</span></div>
  <div class="tile tile--ok"><span class="tile__num"><?= icon('circle-check') ?> <?= $count['available'] ?></span><span class="tile__label">Available</span></div>
  <div class="tile tile--danger"><span class="tile__num"><?= icon('clock') ?> <?= $count['occupied'] ?></span><span class="tile__label">Occupied</span></div>
  <div class="tile tile--off"><span class="tile__num"><?= icon('ban') ?> <?= $count['unavailable'] ?></span><span class="tile__label">Unavailable</span></div>
</div>

<div class="card" style="margin-bottom:1.2rem;">
  <div class="card__head">
    <h3><?= icon('chart-column') ?> System Analytics & Insights</h3>
    <span class="muted small">Real-time metrics and operations overview</span>
  </div>

  <div class="analytics-grid">
    <div class="analytics-card">
      <div class="muted small" style="margin-bottom: .4rem; display: flex; align-items: center; justify-content: space-between;">
        <span>Room Utilization Rate</span>
        <strong style="color: var(--text);"><?= $utilizationRate ?>%</strong>
      </div>
      <div style="width: 100%; background: var(--border); height: 7px; border-radius: 4px; overflow: hidden;">
        <div style="width: <?= min(100, $utilizationRate) ?>%; background: var(--primary); height: 100%;"></div>
      </div>
      <div class="muted small" style="margin-top: .4rem;">
        <?= $activeRoomsCount ?> of <?= $totalRooms ?> rooms in use
      </div>
    </div>

    <div class="analytics-card">
      <div class="muted small" style="margin-bottom: .25rem;"><?= icon('building-2') ?> Campus Seating & Schedules</div>
      <div style="font-size: 1.35rem; font-weight: 700; color: var(--text); font-family: var(--font-display); line-height: 1.2;">
        <?= number_format($totalCapacity) ?> <span style="font-size: .8rem; font-weight: normal; color: var(--muted);">total seats</span>
      </div>
      <div class="muted small" style="margin-top: .25rem;">
        <?= icon('calendar-clock') ?> <?= $schedulesToday ?> timetable slots today
      </div>
    </div>

    <div class="analytics-card">
      <div class="muted small" style="margin-bottom: .25rem;"><?= icon('clock') ?> Operations Today</div>
      <div style="font-size: 1.35rem; font-weight: 700; color: var(--text); font-family: var(--font-display); line-height: 1.2;">
        <?= $sessionsToday ?> <span style="font-size: .8rem; font-weight: normal; color: var(--muted);">sessions started</span>
      </div>
      <div class="muted small" style="margin-top: .25rem;">
        <?= icon('unlock') ?> <?= $forceOpensToday ?> schedule force-opens today
      </div>
    </div>

    <div class="analytics-card">
      <div class="muted small" style="margin-bottom: .25rem;"><?= icon('users') ?> System Accounts</div>
      <div style="font-size: 1.35rem; font-weight: 700; color: var(--text); font-family: var(--font-display); line-height: 1.2;">
        <?= (int)($userStats['total_users'] ?? 0) ?> <span style="font-size: .8rem; font-weight: normal; color: var(--muted);">registered users</span>
      </div>
      <div class="muted small" style="margin-top: .25rem;">
        <?= (int)($userStats['approved_lecturers'] ?? 0) ?> lecturers &bull; <?= (int)($userStats['admins'] ?? 0) ?> admins &bull; <?= $pendingN ?> pending
      </div>
    </div>
  </div>
</div>
<div class="two-col" style="margin-bottom:1.2rem;">
  <div class="card">
    <div class="card__head">
      <h3><?= icon('chart-column') ?> Most used classrooms</h3>
      <a class="btn btn--ghost btn--sm" href="history.php">Usage history</a>
    </div>
    <?php if (!$topRooms || $totalTopSessions === 0): ?>
      <p class="muted">No room usage recorded yet.</p>
    <?php else:
      $radius = 40;
      $circumference = 2 * M_PI * $radius;
      $runningOffset = 0.0;
      $hasMultiple = count($topRooms) > 1;
      $gap = $hasMultiple ? 2.5 : 0.0;
    ?>
      <div class="donut-container">
        <div class="donut-chart-box">
          <svg viewBox="0 0 120 120" class="donut-svg" role="img" aria-label="Donut chart showing most used classrooms">
            <circle cx="60" cy="60" r="<?= $radius ?>" fill="none" stroke="var(--track)" stroke-width="14" />
            <?php foreach ($topRooms as $i => $room):
              $cnt = (int)$room['session_count'];
              $fraction = $cnt / $totalTopSessions;
              $arc = $fraction * $circumference;
              $dashLength = $arc > $gap ? ($arc - $gap) : max(0.5, $arc * 0.8);
              $dashSpace = max(0.0, $circumference - $dashLength);
              $dashOffset = -($runningOffset + ($arc - $dashLength) / 2);
              $runningOffset += $arc;
              $pct = (int)round($fraction * 100);
              $color = $donutColors[$i % count($donutColors)];
            ?>
              <circle
                class="donut-slice"
                cx="60"
                cy="60"
                r="<?= $radius ?>"
                fill="none"
                stroke="<?= $color ?>"
                stroke-width="14"
                stroke-dasharray="<?= sprintf('%.2f %.2f', $dashLength, $dashSpace) ?>"
                stroke-dashoffset="<?= sprintf('%.2f', $dashOffset) ?>"
                transform="rotate(-90 60 60)"
              >
                <title><?= e($room['room_number']) ?>: <?= $cnt ?> <?= $cnt === 1 ? 'session' : 'sessions' ?> (<?= $pct ?>%)</title>
              </circle>
            <?php endforeach; ?>
            <text x="60" y="56" text-anchor="middle" font-family="var(--font-display)" font-size="19" font-weight="700" fill="var(--text)"><?= $totalTopSessions ?></text>
            <text x="60" y="69" text-anchor="middle" font-family="var(--font-mono)" font-size="7.5" font-weight="600" fill="var(--muted)" letter-spacing="0.5">SESSIONS</text>
          </svg>
        </div>
        <div class="donut-legend">
          <?php foreach ($topRooms as $i => $room):
            $cnt = (int)$room['session_count'];
            $pct = (int)round(($cnt / $totalTopSessions) * 100);
            $color = $donutColors[$i % count($donutColors)];
          ?>
            <div class="donut-legend__item">
              <span class="donut-legend__swatch" style="background-color: <?= $color ?>;"></span>
              <div class="donut-legend__info">
                <span class="donut-legend__name"><strong><?= e($room['room_number']) ?></strong> <span class="muted small"><?= e($room['building']) ?></span></span>
                <span class="donut-legend__val"><?= $cnt ?> (<?= $pct ?>%)</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <h3><?= icon('clock') ?> Active sessions now</h3>
      <a class="btn btn--ghost btn--sm" href="sessions.php">All sessions</a>
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
</div>

<div class="card">
  <div class="card__head">
    <h3>Recent activity</h3>
    <a class="btn btn--ghost btn--sm" href="logs.php">Full log</a>
  </div>
  <?php if (!$recentLogs): ?>
    <p class="muted">No activity logged yet.</p>
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

<?php render_footer(); ?>
