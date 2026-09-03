<?php
declare(strict_types=1);

/**
 * Classroom Finder — activity logs.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = ADMIN_PER_PAGE;

$q = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(l.action LIKE ? OR l.details LIKE ? OR u.full_name LIKE ? OR c.room_number LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$totalSt = db()->prepare('SELECT COUNT(*) AS n FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN classrooms c ON c.id = l.classroom_id' . $whereSql);
$totalSt->execute($params);
$total = (int)($totalSt ? $totalSt->fetch()['n'] : 0);

$pP = page_params($total, $page);

$st = db()->prepare(
    'SELECT l.*, u.full_name, c.room_number
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN classrooms c ON c.id = l.classroom_id
     ' . $whereSql . '
     ORDER BY l.timestamp DESC LIMIT ' . $pP['limit'] . ' OFFSET ' . $pP['offset']
);
$st->execute($params);
$logs = $st->fetchAll();

render_header('Activity Logs', ['prefix' => '../', 'nav' => 'admin', 'active' => 'logs']);
?>

<div class="page-head">
  <h1><?= icon('file-text') ?> Activity logs</h1>
  <p class="muted">System events, logins, modifications, and administrative audit trails.</p>
</div>

<form method="get" class="filter-row">
  <input type="search" name="q" placeholder="Search logs by action, details, user, or room…" value="<?= e($q) ?>">
  <button class="btn btn--primary" type="submit">Filter</button>
  <?php if ($q !== ''): ?>
    <a href="logs.php" class="btn btn--ghost">Reset</a>
  <?php endif; ?>
</form>

<div class="card">
  <div class="simple-logs-container">
    <?php if (!$logs): ?>
      <p class="muted">No log entries recorded.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table table--simple-logs">
      <thead>
        <tr>
          <th>WHEN</th>
          <th>WHO</th>
          <th>ACTION</th>
          <th>DETAILS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td class="nowrap">
            <?= date('M j, Y', strtotime($l['timestamp'])) ?>
            <span class="muted small"><?= date('g:i A', strtotime($l['timestamp'])) ?></span>
          </td>
          <td class="nowrap"><strong><?= e($l['full_name'] ?? 'System') ?></strong></td>
          <td class="nowrap"><span class="simple-log-action"><?= e(strtolower(str_replace('_', ' ', $l['action']))) ?></span></td>
          <td class="cell-truncate">
            <?= e($l['details'] ?: '') ?>
            <?php if (!empty($l['room_number'])): ?>
              <span class="simple-log-room"><?= e($l['room_number']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?= page_nav($total, $page, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
