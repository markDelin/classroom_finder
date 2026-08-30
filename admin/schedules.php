<?php
declare(strict_types=1);

/**
 * Classroom Finder — fixed weekly class schedules (admin).
 *
 * Recurring class timetable per classroom. While an active slot runs on its
 * weekday, the status engine reports the room as OCCUPIED and occupying is
 * rejected — unless someone reported that occurrence as "not meeting"
 * (schedule_force_open, filed instantly by lecturers from the scanner).
 * The bottom of the page renders one printable timetable sheet per room
 * (same @media print pipeline as the QR posters).
 */

require_once __DIR__ . '/../auth/auth_check.php';

const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

$admin = require_admin();
$fail  = function (string $m): never {
    flash('error', $m);
    redirect('schedules.php');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $fail('Session expired — please try again.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM class_schedules WHERE id = ?')->execute([$id]);
        log_action('SCHEDULE_DELETE', (int)$admin['id'], null, 'Deleted schedule #' . $id);
        flash('success', 'Schedule removed.');
        redirect('schedules.php');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE class_schedules SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        log_action('SCHEDULE_TOGGLE', (int)$admin['id'], null, 'Toggled schedule #' . $id);
        redirect('schedules.php');
    }

    if ($action === 'revert') {
        // remove a force-open report -> today's slot blocks the room again
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM schedule_force_open WHERE id = ?')->execute([$id]);
        log_action('FORCE_OPEN_REVERT', (int)$admin['id'], null, 'Reverted force-open #' . $id);
        flash('success', 'Force-open reverted — the scheduled class blocks the room again.');
        redirect('schedules.php');
    }

    if ($action === 'create' || $action === 'update') {
        $classroomId = (int)($_POST['classroom_id'] ?? 0);
        $day         = (int)($_POST['day_of_week'] ?? 0);
        $startT      = (string)($_POST['start_time'] ?? '');
        $endT        = (string)($_POST['end_time'] ?? '');
        $subject     = trim((string)($_POST['subject'] ?? ''));
        $section     = trim((string)($_POST['section'] ?? ''));
        $instructor  = trim((string)($_POST['instructor'] ?? ''));

        if (!$classroomId || !isset(DAY_NAMES[$day])
            || !preg_match('/^\d{2}:\d{2}$/', $startT) || !preg_match('/^\d{2}:\d{2}$/', $endT)) {
            $fail('Please fill in the room, weekday and both times.');
        }
        foreach ([$startT, $endT] as $t) {
            [$hh, $mm] = array_map('intval', explode(':', $t));
            if ($hh > 23 || $mm > 59) {
                $fail('Please use real clock times (HH:MM).');
            }
        }
        if ($subject === '') {
            $fail('Please enter the subject / course.');
        }

        $start = $startT . ':00';
        $end   = $endT . ':00';
        if (strcmp($end, $start) <= 0) {
            $fail('The end time must be after the start time.');
        }

        // no overlapping ACTIVE slot on the same room+weekday (update excludes itself)
        $id  = $action === 'update' ? (int)($_POST['id'] ?? 0) : 0;
        $st  = db()->prepare(
            "SELECT subject FROM class_schedules
             WHERE classroom_id = ? AND day_of_week = ? AND is_active = 1 AND id <> ?
               AND start_time < ? AND end_time > ?
             LIMIT 1"
        );
        $st->execute([$classroomId, $day, $id, $end, $start]);
        if ($clash = $st->fetch()) {
            $fail('Overlaps an existing class (' . $clash['subject'] . ') on '
                . DAY_NAMES[$day] . '. Adjust the times.');
        }

        if ($action === 'create') {
            db()->prepare(
                'INSERT INTO class_schedules (classroom_id, day_of_week, start_time, end_time, subject, section, instructor)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$classroomId, $day, $start, $end, $subject, $section ?: null, $instructor ?: null]);
            log_action('SCHEDULE_CREATE', (int)$admin['id'], $classroomId,
                DAY_NAMES[$day] . " {$startT}–{$endT} {$subject}");
            flash('success', 'Class schedule added. The room is blocked during this slot every ' . DAY_NAMES[$day] . '.');
        } else {
            if (!$id) {
                $fail('Missing schedule to update.');
            }
            db()->prepare(
                'UPDATE class_schedules
                 SET classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject = ?, section = ?, instructor = ?
                 WHERE id = ?'
            )->execute([$classroomId, $day, $start, $end, $subject, $section ?: null, $instructor ?: null, $id]);
            log_action('SCHEDULE_UPDATE', (int)$admin['id'], $classroomId,
                "Schedule #{$id}: " . DAY_NAMES[$day] . " {$startT}–{$endT} {$subject}");
            flash('success', 'Class schedule updated.');
        }
        redirect('schedules.php');
    }

    $fail('Unknown action.');
}

expire_stale();

// room filter (also scopes the printed sheets)
$rooms = db()->query(
    "SELECT id, room_number, building, floor, capacity, room_type FROM classrooms ORDER BY building, room_number"
)->fetchAll();
$roomMap = [];
foreach ($rooms as $r) {
    $roomMap[(int)$r['id']] = $r;
}

$q          = trim((string)($_GET['q'] ?? ''));
$filterRoom = (int)($_GET['room'] ?? 0);
$where      = [];
$filterParams = [];
if ($filterRoom && isset($roomMap[$filterRoom])) {
    $where[]         = 'cs.classroom_id = ?';
    $filterParams[]  = $filterRoom;
}
if ($q !== '') {
    $where[]         = '(cs.subject LIKE ? OR cs.section LIKE ? OR cs.instructor LIKE ? OR c.room_number LIKE ? OR c.building LIKE ?)';
    array_push($filterParams, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
$filterSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stc = db()->prepare('SELECT COUNT(*) AS n FROM class_schedules cs JOIN classrooms c ON c.id = cs.classroom_id' . $filterSql);
$stc->execute($filterParams);
$total = (int)$stc->fetch()['n'];
$pP = page_params($total, (int)($_GET['page'] ?? 1));

$st = db()->prepare(
    'SELECT cs.*, c.room_number, c.building, fo.id AS force_open_id
     FROM class_schedules cs
     JOIN classrooms c ON c.id = cs.classroom_id
     LEFT JOIN schedule_force_open fo ON fo.schedule_id = cs.id AND fo.exc_date = CURDATE()
     ' . $filterSql . '
     ORDER BY c.building, c.room_number, cs.day_of_week, cs.start_time
     LIMIT ' . $pP['limit'] . ' OFFSET ' . $pP['offset']
);
$st->execute($filterParams);
$slots = $st->fetchAll();

// this week's "not meeting" reports
$forceOpen = db()->query(
    'SELECT fo.*, cs.subject, cs.section, cs.instructor, cs.start_time, cs.end_time, cs.day_of_week,
            c.room_number, c.building, u.full_name
     FROM schedule_force_open fo
     JOIN class_schedules cs ON cs.id = fo.schedule_id
     JOIN classrooms c       ON c.id = fo.classroom_id
     JOIN users u            ON u.id = fo.user_id
     WHERE fo.exc_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
     ORDER BY fo.created_at DESC
     LIMIT 20'
)->fetchAll();

// full week per room for the printable sheets (honours the same room filter)
$st = db()->prepare(
    'SELECT cs.* FROM class_schedules cs
     WHERE cs.is_active = 1' . ($filterRoom && isset($roomMap[$filterRoom]) ? ' AND cs.classroom_id = ' . $filterRoom : '') . '
     ORDER BY cs.classroom_id, cs.day_of_week, cs.start_time'
);
$st->execute();
$weekByRoom = [];
foreach ($st->fetchAll() as $row) {
    $weekByRoom[(int)$row['classroom_id']][$row['day_of_week']][] = $row;
}
// one printable sheet, only for the room the admin picked — printing never
// dumps every room's timetable at once
$sheetRoom = ($filterRoom && isset($roomMap[$filterRoom])) ? $roomMap[$filterRoom] : null;

$school = school_name();

render_header('Fixed Schedules', ['prefix' => '../', 'nav' => 'admin', 'active' => 'schedules']);
?>

<div class="page-head">
  <h1><?= icon('calendar-days') ?> Fixed class schedules</h1>
  <div class="page-head__actions">
    <button class="btn btn--primary btn--sm" type="button"
            data-modal-form="#slotForm"
            data-title="Add class schedule"
            data-confirm-text="Add"><?= icon('plus') ?> Add <span class="hide-mobile">schedule</span></button>
    <button class="btn btn--ghost btn--sm" type="button" onclick="window.print()"><?= icon('printer') ?> Print <span class="hide-mobile">schedule</span></button>
  </div>
  <p class="muted">Rooms are shown as OCCUPIED and cannot be taken during their scheduled class times. Lecturers can open a room instantly when a class is not meeting; those reports appear below.</p>
</div>

<div class="no-print">
<form method="get" class="filter-row">
  <input type="search" name="q" placeholder="Search subject, section, instructor, room…" value="<?= e($q) ?>">
  <select name="room" onchange="this.form.submit()" style="flex:1; max-width:24rem">
    <option value="">All classrooms</option>
    <?php foreach ($rooms as $r): ?>
      <option value="<?= (int)$r['id'] ?>" <?= $filterRoom === (int)$r['id'] ? 'selected' : '' ?>>
        <?= e($r['building']) ?> · <?= e($r['room_number']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn--primary" type="button">Filter</button>
  <?php if ($q !== '' || $filterRoom): ?>
    <a href="schedules.php" class="btn btn--ghost">Reset</a>
  <?php endif; ?>
</form>

<div class="card">
  <h3>Weekly slots</h3>
  <?php if (!$slots): ?>
    <p class="muted">No fixed schedules yet<?= $filterRoom ? ' for this room' : '' ?>.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table table--sched">
    <thead><tr><th>Room</th><th>Days</th><th>Time</th><th>Subject</th><th>Course</th><th>Lecturer</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
    <tbody>
      <?php foreach ($slots as $s): ?>
      <tr>
        <td class="cell-main" data-label="Room"><strong><?= e($s['room_number']) ?></strong> <span class="muted small"><?= e($s['building']) ?></span></td>
        <td data-label="Days"><?= DAY_NAMES[(int)$s['day_of_week']] ?></td>
        <td data-label="Time"><?= e(fmt_range($s['start_time'], $s['end_time'])) ?></td>
        <td data-label="Subject"><?= e($s['subject']) ?></td>
        <td data-label="Course"><?= e($s['section'] ?: '—') ?></td>
        <td data-label="Lecturer"><?= e($s['instructor'] ?: '—') ?></td>
        <td class="cell-status" data-label="Status">
          <?php if (!empty($s['force_open_id'])): ?>
            <span class="pill pill--released">opened today</span>
          <?php elseif ((int)$s['is_active'] === 1): ?>
            <span class="pill pill--ok">active</span>
          <?php else: ?>
            <span class="pill">paused</span>
          <?php endif; ?>
        </td>
        <td data-label="Action">
          <div class="actions-cell" style="justify-content:flex-end">
          <button class="btn btn--ghost btn--sm" type="button"
                  data-modal-form="#slotForm"
                  data-title="Edit schedule — Room <?= e($s['room_number']) ?>"
                  data-confirm-text="Save"
                  title="Edit schedule"
                  data-prefill='<?= e(json_encode([
                      'action'      => 'update',
                      'id'          => (int)$s['id'],
                      'classroom_id'=> (int)$s['classroom_id'],
                      'day_of_week' => (int)$s['day_of_week'],
                      'start_time'  => substr((string)$s['start_time'], 0, 5),
                      'end_time'    => substr((string)$s['end_time'], 0, 5),
                      'subject'     => $s['subject'],
                      'section'     => (string)$s['section'],
                      'instructor'  => (string)$s['instructor'],
                  ])) ?>'><?= icon('pencil') ?> <span class="btn-text">Edit</span></button>
          <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn--ghost btn--sm" type="submit" title="<?= (int)$s['is_active'] === 1 ? 'Pause slot' : 'Resume slot' ?>"><?= (int)$s['is_active'] === 1 ? icon('pause') . ' <span class="btn-text">Pause</span>' : icon('play') . ' <span class="btn-text">Resume</span>' ?></button>
          </form>
          <form method="post" class="inline-form" data-confirm="Delete the <?= DAY_NAMES[(int)$s['day_of_week']] ?> <?= e(fmt_time($s['start_time'])) ?> slot for room <?= e($s['room_number']) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit" title="Delete slot"><?= icon('trash-2') ?> <span class="btn-text">Delete</span></button>
          </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= page_nav($total, $pP['page'], ADMIN_PER_PAGE, 'page') ?>
  <?php endif; ?>
</div>

<?php if ($forceOpen): ?>
<div class="card">
  <h3>"Class isn't meeting" reports — this week</h3>
  <p class="muted small">Rooms were opened despite a scheduled class. Reverting blocks the room again for the rest of today's slot.</p>
  <div class="table-wrap">
  <table class="table table--sched">
    <thead><tr><th>Room</th><th>Days</th><th>Time</th><th>Subject</th><th>Course</th><th>Lecturer</th><th>Reported by</th><th>Reason</th><th style="text-align:right">Action</th></tr></thead>
    <tbody>
      <?php foreach ($forceOpen as $fo): ?>
      <tr>
        <td class="cell-main nowrap" data-label="Room"><strong><?= e($fo['room_number']) ?></strong> <span class="muted small"><?= e($fo['building']) ?></span></td>
        <td class="nowrap" data-label="Days"><?= DAY_NAMES[(int)$fo['day_of_week']] ?></td>
        <td class="nowrap" data-label="Time"><?= e(fmt_range($fo['start_time'], $fo['end_time'])) ?></td>
        <td class="cell-truncate" data-label="Subject"><?= e($fo['subject']) ?></td>
        <td class="nowrap" data-label="Course"><?= e($fo['section'] ?: '—') ?></td>
        <td class="cell-truncate" data-label="Lecturer"><?= e($fo['instructor'] ?: '—') ?></td>
        <td class="cell-truncate" data-label="Reported by"><?= e($fo['full_name']) ?></td>
        <td class="cell-status nowrap" data-label="Reason"><span class="pill"><?= e(str_replace('_', ' ', $fo['reason'])) ?></span></td>
        <td data-label="Action">
          <div class="actions-cell" style="justify-content:flex-end">
          <form method="post" class="inline-form" data-confirm="Block room <?= e($fo['room_number']) ?> again for the rest of today's slot?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revert"><input type="hidden" name="id" value="<?= (int)$fo['id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit" title="Undo this force-open"><?= icon('x') ?> <span class="btn-text">Revert</span></button>
          </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
</div><!-- /.no-print -->

<?php if ($sheetRoom): ?>
<!-- printable schedule sheet for the filtered room — registrar-form layout -->
<h3 class="tt-heading">Printable schedule sheet · <?= e($sheetRoom['building']) ?> — Room <?= e($sheetRoom['room_number']) ?></h3>
<div class="tt-sheets">
  <?php
    $rid = (int)$sheetRoom['id'];
    $roomSlots = [];
    foreach ($weekByRoom[$rid] ?? [] as $daySlots) {
        foreach ($daySlots as $s) {
            $roomSlots[] = $s;
        }
    }
    $totalMin = 0;
    foreach ($roomSlots as $s) {
        $totalMin += (int)((strtotime((string)$s['end_time']) - strtotime((string)$s['start_time'])) / 60);
    }
    $weeklyHours = rtrim(rtrim(number_format($totalMin / 60, 1), '0'), '.');
    $sheetLogo    = get_setting('school_logo', '');
    $hasLogoImg   = $sheetLogo !== '' && is_file(__DIR__ . '/../assets/uploads/' . $sheetLogo);
    $sheetAddr    = school_address();
    $sheetContact = school_contact();
  ?>
  <div class="card tt-sheet">
    <header class="tt-head">
      <div class="tt-head__brand">
        <?php if ($hasLogoImg): ?>
          <img class="tt-head__logo" src="../assets/uploads/<?= e($sheetLogo) ?>" alt="">
        <?php endif; ?>
        <div>
          <div class="tt-head__school"><?= e($school !== '' ? $school : APP_NAME) ?></div>
          <?php if ($sheetAddr !== ''): ?><div class="tt-head__line"><?= e($sheetAddr) ?></div><?php endif; ?>
          <?php if ($sheetContact !== ''): ?><div class="tt-head__line"><?= e($sheetContact) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="tt-head__meta">
        <div>Schedule No.: SC-<?= str_pad((string)$rid, 3, '0', STR_PAD_LEFT) ?>-<?= date('Ymd') ?></div>
        <div>Date: <?= date('Y-m-d H:i:s') ?></div>
      </div>
    </header>

    <h2 class="tt-title">Room Weekly Class Schedule</h2>

    <?php if (empty($roomSlots)): ?>
      <p class="muted" style="margin: 1.2rem 0; text-align: center;">No scheduled classes recorded for this classroom.</p>
    <?php else: ?>
    <section>
      <div class="table-wrap">
        <table class="tt-table">
          <thead>
            <tr>
              <th style="width: 12%; text-align: center;">Days</th>
              <th style="width: 22%">Time</th>
              <th style="width: 36%">Course Description</th>
              <th style="width: 15%">Year / Section</th>
              <th style="width: 15%">Instructor</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($weekByRoom[$rid] ?? [] as $dayNum => $daySlots): ?>
              <?php $rowCount = count($daySlots); ?>
              <?php foreach ($daySlots as $idx => $s): ?>
              <tr>
                <?php if ($idx === 0): ?>
                  <td rowspan="<?= $rowCount ?>" class="cell-day"><?= DAY_NAMES[(int)$dayNum] ?></td>
                <?php endif; ?>
                <td class="nowrap"><?= e(fmt_range($s['start_time'], $s['end_time'])) ?></td>
                <td><?= e($s['subject']) ?></td>
                <td><?= e($s['section'] ?: '—') ?></td>
                <td><?= e($s['instructor'] ?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            <tr class="tt-table__total">
              <td colspan="2" style="text-align: right;">Total weekly class hours:</td>
              <td colspan="3"><?= $weeklyHours ?> hrs</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- shared add/edit form, opened inside a SweetAlert2 modal -->
<form method="post" class="form-grid form-grid--5" id="slotForm" hidden style="text-align:left" data-default-action="create">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <input type="hidden" name="id" value="">
  <label class="full-width">Classroom
    <select name="classroom_id" required>
      <option value="">— choose classroom —</option>
      <?php foreach ($rooms as $r): ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['building']) ?> · <?= e($r['room_number']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="full-width">Weekday
    <select name="day_of_week" required>
      <?php foreach (DAY_NAMES as $n => $label): ?>
        <option value="<?= $n ?>"><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Starts <input type="time" name="start_time" required step="300"></label>
  <label>Ends <input type="time" name="end_time" required step="300"></label>
  <label class="full-width">Subject / course <input name="subject" maxlength="120" required placeholder="e.g. IT 301 — Data Structures"></label>
  <label>Course / Section <input name="section" maxlength="80" placeholder="e.g. BSCS 3-A"></label>
  <label>Instructor <input name="instructor" maxlength="120" placeholder="Name on the program"></label>
</form>

<?php render_footer(['assets/js/admin-modals.js']); ?>
