<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

[$from, $to, $rangeLabel] = report_range();
$activeRange = (string)($_GET['range'] ?? '');
$rangesOn    = $activeRange !== '' || $from !== '' || $to !== '';

$where  = [];
$params = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[]  = 's.start_time >= ?';
    $params[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[]  = 's.start_time <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare(
    'SELECT c.id, c.room_number, c.building, c.room_type, c.capacity,
            COUNT(s.id) AS session_count,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)), 0) AS total_minutes
     FROM classrooms c
     JOIN classroom_sessions s ON s.classroom_id = c.id
     ' . $whereSql . '
     GROUP BY c.id, c.room_number, c.building, c.room_type, c.capacity
     ORDER BY session_count DESC, total_minutes DESC'
);
$st->execute($params);
$roomUsage = $st->fetchAll();

$totalSessions  = (int)array_sum(array_column($roomUsage, 'session_count'));
$totalMinutes   = (int)array_sum(array_column($roomUsage, 'total_minutes'));
$totalRoomsUsed = count($roomUsage);

if (wants_csv()) {
    stream_csv(
        'most-used-classrooms-' . date('Ymd-His') . '.csv',
        ['Rank', 'Room', 'Building', 'Type', 'Capacity', 'Sessions', 'Minutes', 'Hours', 'Usage Share'],
        array_map(static function (array $r, int $idx) use ($totalSessions): array {
            $cnt = (int)$r['session_count'];
            $pct = $totalSessions > 0 ? round(($cnt / $totalSessions) * 100, 1) : 0;
            return [
                $idx + 1,
                $r['room_number'],
                $r['building'],
                $r['room_type'],
                $r['capacity'],
                $cnt,
                (int)$r['total_minutes'],
                round((int)$r['total_minutes'] / 60, 1),
                $pct . '%',
            ];
        }, $roomUsage, array_keys($roomUsage))
    );
}

$chartItems = array_slice($roomUsage, 0, 5);
if (count($roomUsage) > 5) {
    $otherCount = 0;
    $otherMins  = 0;
    for ($i = 5, $len = count($roomUsage); $i < $len; $i++) {
        $otherCount += (int)$roomUsage[$i]['session_count'];
        $otherMins  += (int)$roomUsage[$i]['total_minutes'];
    }
    if ($otherCount > 0) {
        $chartItems[] = [
            'room_number'   => 'Other Rooms',
            'building'      => (count($roomUsage) - 5) . ' rooms',
            'session_count' => $otherCount,
            'total_minutes' => $otherMins,
        ];
    }
}
$donutColors = ['#0F3B6E', '#2563eb', '#0d9488', '#d97706', '#8b5cf6', '#94a3b8'];

$schoolLogo = '';
if (is_file(__DIR__ . '/../assets/img/logo.png')) {
    $schoolLogo = '../assets/img/logo.png';
} elseif (is_file(__DIR__ . '/../assets/uploads/logo-3146dc738a66.png')) {
    $schoolLogo = '../assets/uploads/logo-3146dc738a66.png';
}
$schoolName    = school_name();
$schoolAddr    = school_address();
$schoolContact = school_contact();

$presetUrl = static fn(string $r): string => 'reports.php' . ($r === '' ? '' : '?range=' . urlencode($r));

render_header('Most Used Classrooms Report', ['prefix' => '../', 'nav' => 'admin', 'active' => 'reports']);
?>

<style>
/* Controls bar */
.report-controls {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.report-controls__left {
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-wrap: wrap;
}

/* Minimal Paper Preview: A4 standard, no reflow on screen resize */
.report-stage {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding: 1.2rem 0 2rem;
  display: flex;
  justify-content: center;
}
.report-paper {
  width: 210mm;
  min-width: 210mm;
  max-width: 210mm;
  min-height: 297mm;
  background: #ffffff;
  color: #0f172a;
  padding: 8mm 10mm;
  box-shadow: 0 4px 16px rgb(15 23 42 / .08), 0 1px 3px rgb(15 23 42 / .04);
  border: 1px solid var(--border);
  box-sizing: border-box;
  font-family: Arial, Calibri, 'Segoe UI', sans-serif;
}
.report-paper,
.report-paper * {
  font-family: Arial, Calibri, 'Segoe UI', sans-serif;
}

/* Header (no extra separator lines) */
.rp-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: .35rem;
}
.rp-brand {
  display: flex;
  align-items: center;
  gap: .55rem;
}
.rp-logo {
  height: 1.6rem;
  width: auto;
  object-fit: contain;
}
.rp-school-name {
  font-weight: 700;
  font-size: .88rem;
  line-height: 1.15;
  letter-spacing: .02em;
  text-transform: uppercase;
  color: #0f172a;
}
.rp-subline {
  font-size: .68rem;
  color: #64748b;
  line-height: 1.25;
}
.rp-meta {
  text-align: right;
  font-size: .66rem;
  color: #64748b;
  line-height: 1.25;
  white-space: nowrap;
}

/* Table Title: Blue color, generous header padding */
.rp-title-block {
  padding: 1.5rem 0 1.1rem;
}
.rp-title {
  font-size: 1.1rem;
  font-weight: 700;
  letter-spacing: .02em;
  text-transform: uppercase;
  margin: 0 0 .25rem;
  color: var(--primary, #0F3B6E);
}
.rp-period {
  font-size: .7rem;
  color: #64748b;
  margin: 0;
}

/* Minimal inline stats (no bulky borders/boxes) */
.rp-stats-line {
  display: flex;
  gap: 1.2rem;
  font-size: .72rem;
  margin: .3rem 0 .45rem;
  flex-wrap: wrap;
}
.rp-stats-item {
  color: #64748b;
}
.rp-stats-item strong {
  color: #0f172a;
  font-weight: 600;
}

/* Compact Visual Section (no border separator) */
.rp-viz {
  display: flex;
  align-items: center;
  gap: 1.2rem;
  margin-bottom: .55rem;
}
.rp-viz__chart {
  width: 85px;
  height: 85px;
  flex-shrink: 0;
}
.rp-viz__legend {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: .25rem 1rem;
  flex: 1;
}
.rp-legend-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: .7rem;
  gap: .4rem;
}
.rp-legend-item__left {
  display: flex;
  align-items: center;
  gap: .35rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rp-legend-item__swatch {
  width: .48rem;
  height: .48rem;
  border-radius: 50%;
  flex-shrink: 0;
}
.rp-legend-item__val {
  font-variant-numeric: tabular-nums;
  font-size: .66rem;
  color: #64748b;
  white-space: nowrap;
}

/* Real Excel Spreadsheet Table */
.excel-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .72rem;
  line-height: 1.25;
  margin-top: .35rem;
  border: 1px solid #94a3b8;
}
.excel-table th {
  background: #f1f5f9;
  color: #0f172a;
  font-weight: 700;
  font-size: .66rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  text-align: left;
  padding: .32rem .45rem;
  border: 1px solid #cbd5e1;
  white-space: nowrap;
}
.excel-table td {
  padding: .28rem .45rem;
  border: 1px solid #cbd5e1;
  vertical-align: middle;
  background: #ffffff;
}
.excel-table tbody tr:nth-child(even) td {
  background: #f8fafc;
}
.excel-table tbody tr:hover td {
  background: #f1f5f9;
}
.excel-table .cell-rank {
  text-align: center;
  font-size: .66rem;
  color: #64748b;
  width: 2rem;
}
.excel-table .cell-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.excel-table .row-total td {
  font-weight: 700;
  background: #e2e8f0 !important;
  color: #0f172a;
  border-top: 1.5px solid #64748b;
  border-bottom: 1.5px solid #64748b;
}

/* Print Styles */
@media print {
  @page {
    size: A4 portrait;
    margin: 5mm 8mm;
  }
  body, body.has-sidebar, .main, .main--with-nav {
    background: #ffffff !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }
  .no-print {
    display: none !important;
  }
  .report-stage {
    padding: 0 !important;
    background: none !important;
    overflow: visible !important;
    display: block !important;
  }
  .report-paper,
  .report-paper * {
    font-family: Arial, Calibri, 'Segoe UI', sans-serif !important;
  }
  .report-paper {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    min-height: auto !important;
    padding: 0 !important;
    box-shadow: none !important;
    border: none !important;
  }
  .excel-table,
  .excel-table th,
  .excel-table td {
    border: 1pt solid #000000 !important;
  }
  .excel-table th {
    background: #f1f5f9 !important;
    color: #000000 !important;
  }
  .excel-table .row-total td {
    background: #e5e7eb !important;
    border-top: 1.5pt solid #000000 !important;
    border-bottom: 1.5pt solid #000000 !important;
  }
}
</style>

<div class="no-print">
  <div class="page-head">
    <h1><?= icon('scroll-text') ?> Most Used Classrooms Report</h1>
    <div class="page-head__actions">
      <a class="btn btn--ghost btn--sm" href="reports.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
        <?= icon('download') ?> Export CSV
      </a>
      <button class="btn btn--primary btn--sm" type="button" onclick="window.print()">
        <?= icon('printer') ?> Print Report
      </button>
    </div>
    <p class="muted">Printable summary of classroom utilization and room frequencies.</p>
  </div>

  <div class="report-controls">
    <div class="report-controls__left">
      <span class="muted small">Range:</span>
      <a class="chip-btn <?= $activeRange === 'today' ? 'is-active' : '' ?>" href="<?= $presetUrl('today') ?>">Today</a>
      <a class="chip-btn <?= $activeRange === 'week' ? 'is-active' : '' ?>" href="<?= $presetUrl('week') ?>">This week</a>
      <a class="chip-btn <?= $activeRange === 'month' ? 'is-active' : '' ?>" href="<?= $presetUrl('month') ?>">This month</a>
      <a class="chip-btn <?= !$rangesOn ? 'is-active' : '' ?>" href="<?= $presetUrl('') ?>">All time</a>
    </div>
    <form method="get" class="filter-row" style="margin: 0;">
      <div class="date-input-group">
        <span class="small muted">From</span>
        <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()">
      </div>
      <div class="date-input-group">
        <span class="small muted">To</span>
        <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()">
      </div>
      <?php if ($rangesOn): ?>
        <a href="reports.php" class="btn btn--ghost btn--sm">Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="report-stage">
  <article class="report-paper">
    <header class="rp-head">
      <div class="rp-brand">
        <?php if ($schoolLogo !== ''): ?>
          <img class="rp-logo" src="<?= e($schoolLogo) ?>" alt="Logo">
        <?php endif; ?>
        <div>
          <div class="rp-school-name"><?= e($schoolName !== '' ? $schoolName : APP_NAME) ?></div>
          <?php if ($schoolAddr !== ''): ?><div class="rp-subline"><?= e($schoolAddr) ?></div><?php endif; ?>
          <?php if ($schoolContact !== ''): ?><div class="rp-subline"><?= e($schoolContact) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="rp-meta">
        <div><strong>Doc Ref:</strong> REP-ROOMS-<?= date('Ymd') ?></div>
        <div><strong>Date:</strong> <?= date('F j, Y · H:i') ?></div>
        <div><strong>Scope:</strong> <?= e($rangesOn ? $rangeLabel : 'All Recorded Time') ?></div>
      </div>
    </header>

    <div class="rp-title-block">
      <h2 class="rp-title">Classroom Utilization &amp; Frequency Report</h2>
      <div class="rp-period">
        <?php if ($from !== '' || $to !== ''): ?>
          Coverage: <?= e($from ?: 'Start') ?> to <?= e($to ?: 'Present') ?>
        <?php else: ?>
          Coverage: Complete historical record
        <?php endif; ?>
      </div>
    </div>

    <div class="rp-stats-line">
      <div class="rp-stats-item">Total Sessions: <strong><?= number_format($totalSessions) ?></strong></div>
      <div class="rp-stats-item">Total Room Time: <strong><?= rtrim(rtrim(number_format($totalMinutes / 60, 1), '0'), '.') ?> hrs</strong></div>
      <div class="rp-stats-item">Rooms Used: <strong><?= $totalRoomsUsed ?></strong></div>
      <div class="rp-stats-item">Peak Room: <strong><?= $roomUsage ? e($roomUsage[0]['room_number']) : '—' ?></strong></div>
    </div>

    <?php if (!$roomUsage || $totalSessions === 0): ?>
      <p class="muted" style="text-align: center; margin: 2rem 0;">No classroom session activity recorded for this period.</p>
    <?php else:
      $radius = 40;
      $circumference = 2 * M_PI * $radius;
      $runningOffset = 0.0;
      $hasMultiple = count($chartItems) > 1;
      $gap = $hasMultiple ? 2.5 : 0.0;
    ?>
      <div class="rp-viz">
        <div class="rp-viz__chart">
          <svg viewBox="0 0 120 120" style="width: 100%; height: 100%;" role="img" aria-label="Donut chart showing most used classrooms">
            <circle cx="60" cy="60" r="<?= $radius ?>" fill="none" stroke="#f1f5f9" stroke-width="14" />
            <?php foreach ($chartItems as $i => $item):
              $cnt = (int)$item['session_count'];
              $fraction = $cnt / $totalSessions;
              $arc = $fraction * $circumference;
              $dashLength = $arc > $gap ? ($arc - $gap) : max(0.5, $arc * 0.8);
              $dashSpace = max(0.0, $circumference - $dashLength);
              $dashOffset = -($runningOffset + ($arc - $dashLength) / 2);
              $runningOffset += $arc;
              $pct = (int)round($fraction * 100);
              $color = $donutColors[$i % count($donutColors)];
            ?>
              <circle
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
                <title><?= e($item['room_number']) ?>: <?= $cnt ?> sessions (<?= $pct ?>%)</title>
              </circle>
            <?php endforeach; ?>
            <text x="60" y="56" text-anchor="middle" font-family="Arial, Calibri, sans-serif" font-size="18" font-weight="700" fill="#0f172a"><?= $totalSessions ?></text>
            <text x="60" y="69" text-anchor="middle" font-family="Arial, Calibri, sans-serif" font-size="7.5" font-weight="600" fill="#64748b" letter-spacing="0.5">SESSIONS</text>
          </svg>
        </div>
        <div class="rp-viz__legend">
          <?php foreach ($chartItems as $i => $item):
            $cnt = (int)$item['session_count'];
            $pct = $totalSessions > 0 ? (int)round(($cnt / $totalSessions) * 100) : 0;
            $color = $donutColors[$i % count($donutColors)];
          ?>
            <div class="rp-legend-item">
              <div class="rp-legend-item__left">
                <span class="rp-legend-item__swatch" style="background-color: <?= $color ?>;"></span>
                <span><strong><?= e($item['room_number']) ?></strong> <span style="color:#64748b; font-size:.7rem;">(<?= e($item['building']) ?>)</span></span>
              </div>
              <span class="rp-legend-item__val"><?= $cnt ?> (<?= $pct ?>%)</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <table class="excel-table">
        <thead>
          <tr>
            <th class="cell-rank">#</th>
            <th>Classroom</th>
            <th>Building</th>
            <th>Room Type</th>
            <th class="cell-num">Capacity</th>
            <th class="cell-num">Sessions</th>
            <th class="cell-num">Total Time</th>
            <th class="cell-num">Share</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($roomUsage as $idx => $r):
            $cnt = (int)$r['session_count'];
            $mins = (int)$r['total_minutes'];
            $pct = $totalSessions > 0 ? round(($cnt / $totalSessions) * 100, 1) : 0;
          ?>
          <tr>
            <td class="cell-rank"><?= str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) ?></td>
            <td><strong><?= e($r['room_number']) ?></strong></td>
            <td><?= e($r['building']) ?></td>
            <td><?= e($r['room_type']) ?></td>
            <td class="cell-num"><?= (int)$r['capacity'] ?></td>
            <td class="cell-num"><strong><?= $cnt ?></strong></td>
            <td class="cell-num"><?= human_duration($mins) ?></td>
            <td class="cell-num"><?= $pct ?>%</td>
          </tr>
          <?php endforeach; ?>
          <tr class="row-total">
            <td colspan="5" style="text-align: right;">Total:</td>
            <td class="cell-num"><?= number_format($totalSessions) ?></td>
            <td class="cell-num"><?= human_duration($totalMinutes) ?></td>
            <td class="cell-num">100%</td>
          </tr>
        </tbody>
      </table>
    <?php endif; ?>
  </article>
</div>

<?php render_footer(); ?>
