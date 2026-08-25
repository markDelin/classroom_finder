<?php
declare(strict_types=1);

/**
 * Classroom Finder — activity logs (§22).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

$q        = trim((string)($_GET['q'] ?? ''));
$actionF  = trim((string)($_GET['action'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = ADMIN_PER_PAGE;
[$from, $to, $rangeLabel] = report_range();
$activeRange = (string)($_GET['range'] ?? '');

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(l.details LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($actionF !== '') {
    $where[]  = 'l.action = ?';
    $params[] = $actionF;
}
if ($from !== '') {
    $where[]  = 'l.timestamp >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[]  = 'l.timestamp <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// CSV download — everything matching the current filters (generous cap).
if (wants_csv()) {
    $st = db()->prepare(
        'SELECT l.*, u.full_name, c.room_number
         FROM activity_logs l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN classrooms c ON c.id = l.classroom_id' .
        $whereSql .
        ' ORDER BY l.timestamp DESC LIMIT 20000'
    );
    $st->execute($params);
    stream_csv(
        'activity-logs-' . date('Ymd-His') . '.csv',
        ['Timestamp', 'User', 'Action', 'Details', 'Room'],
        array_map(static fn(array $l): array => [
            date('Y-m-d H:i:s', strtotime($l['timestamp'])),
            $l['full_name'] ?? 'System',
            $l['action'],
            (string)$l['details'],
            (string)($l['room_number'] ?? ''),
        ], $st->fetchAll())
    );
}

$total = db()->prepare('SELECT COUNT(*) AS n FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id' . $whereSql);
$total->execute($params);
$total = (int)$total->fetch()['n'];

$st = db()->prepare(
    'SELECT l.*, u.full_name, c.room_number
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN classrooms c ON c.id = l.classroom_id'
    . $whereSql .
    ' ORDER BY l.timestamp DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$st->execute($params);
$logs = $st->fetchAll();

$actions = db()->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll();

render_header('Activity Logs', ['prefix' => '../', 'nav' => 'admin', 'active' => 'logs']);
?>

<?php
$keepQ     = array_filter(['q' => $q, 'action' => $actionF], static fn($v) => $v !== '');
$presetUrl = static fn(string $r): string => 'logs.php?' . http_build_query(array_merge($keepQ, $r === '' ? [] : ['range' => $r]));
$rangesOn  = $activeRange !== '' || $from !== '' || $to !== '';
?>
<div class="page-head">
  <h1><?= icon('scroll-text') ?> Activity logs</h1>
  <a class="btn btn--primary btn--sm" href="logs.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
    <?= icon('download') ?> Export CSV</a>
  <p class="muted"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> recorded<?= $rangesOn ? ' · ' . e($rangeLabel) : '' ?>.</p>
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
    <input type="search" name="q" placeholder="Search details or user…" value="<?= e($q) ?>">
    <select name="action">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a['action']) ?>" <?= $actionF === $a['action'] ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $a['action'])) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="date-input-group">
      <span class="small muted">From</span>
      <input type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="date-input-group">
      <span class="small muted">To</span>
      <input type="date" name="to" value="<?= e($to) ?>">
    </div>
    <button class="btn btn--sm" type="submit">Filter</button>
  </form>

  <?php if (!$logs): ?>
    <p class="muted">No log entries match.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td class="cell-main" data-label="Who">
          <strong><?= e($l['full_name'] ?? 'System') ?></strong>
          <span class="muted small">· <?= fmt_date($l['timestamp']) ?> <?= fmt_time($l['timestamp']) ?></span>
        </td>
        <td class="cell-status" data-label="Action"><span class="pill pill--log"><?= e(strtolower(str_replace('_', ' ', $l['action']))) ?></span></td>
        <td class="cell-sub small" data-label="Details">
          <?= e($l['details'] ?: '') ?>
          <?= !empty($l['room_number']) ? '<span class="pill pill--lecturer">' . e($l['room_number']) . '</span>' : '' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?= page_nav($total, $page, $perPage) ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
