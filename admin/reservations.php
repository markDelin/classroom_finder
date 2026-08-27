<?php
declare(strict_types=1);

/**
 * Classroom Finder — reservations.
 * Admins book rooms in advance; upcoming bookings surface as RESERVED on
 * the landing page within the configured reserve window, and block occupying.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();
$fail  = function (string $m): never {
    flash('error', $m);
    redirect('reservations.php');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $fail('Session expired — please try again.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cancel') {
        db()->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ? AND status = 'active'")
            ->execute([(int)($_POST['id'] ?? 0)]);
        log_action('RESERVATION_CANCEL', (int)$admin['id'], null, 'Cancelled reservation #' . (int)($_POST['id'] ?? 0));
        flash('success', 'Reservation cancelled.');
        redirect('reservations.php');
    }

    if ($action === 'create') {
        $classroomId = (int)($_POST['classroom_id'] ?? 0);
        $userId      = (int)($_POST['user_id'] ?? 0) ?: null;
        $purpose     = trim((string)($_POST['purpose'] ?? ''));
        $date        = (string)($_POST['date'] ?? '');
        $startT      = (string)($_POST['start_time'] ?? '');
        $endT        = (string)($_POST['end_time'] ?? '');

        if (!$classroomId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !preg_match('/^\d{2}:\d{2}$/', $startT) || !preg_match('/^\d{2}:\d{2}$/', $endT)) {
            $fail('Please fill in the room, date and both times.');
        }

        $start = $date . ' ' . $startT . ':00';
        $end   = $date . ' ' . $endT . ':00';
        if (strtotime($end) <= strtotime($start)) {
            $fail('The end time must be after the start time.');
        }
        if (strtotime($end) - strtotime($start) > 24 * 3600) {
            $fail('A reservation cannot exceed 24 hours.');
        }
        if (strtotime($start) < time() - 300) {
            $fail('Reservations must be in the future — use the scanner for “right now”.');
        }

        // conflict vs other reservations
        $st = db()->prepare(
            "SELECT COUNT(*) AS n FROM reservations
             WHERE classroom_id = ? AND status = 'active' AND start_time < ? AND end_time > ?"
        );
        $st->execute([$classroomId, $end, $start]);
        if ((int)$st->fetch()['n'] > 0) {
            $fail('That room already has a reservation overlapping this slot.');
        }

        // conflict vs sessions running during the slot
        $st = db()->prepare(
            "SELECT COUNT(*) AS n FROM classroom_sessions
             WHERE classroom_id = ? AND status = 'active' AND start_time < ? AND end_time > ?"
        );
        $st->execute([$classroomId, $end, $start]);
        if ((int)$st->fetch()['n'] > 0) {
            $fail('That room is occupied during part of this slot. Try a different time.');
        }

        db()->prepare(
            'INSERT INTO reservations (classroom_id, user_id, purpose, start_time, end_time)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$classroomId, $userId, $purpose ?: null, $start, $end]);

        log_action('RESERVATION_CREATE', (int)$admin['id'], $classroomId,
            "Reserved {$start} – {$end}" . ($purpose ? ' (' . $purpose . ')' : ''));
        flash('success', 'Reservation created. The room shows as RESERVED shortly before it starts.');
        redirect('reservations.php');
    }

    $fail('Unknown action.');
}

expire_stale();

$q = trim((string)($_GET['q'] ?? ''));

$rooms = db()->query(
    "SELECT id, room_number, building FROM classrooms WHERE status <> 'disabled' ORDER BY building, room_number"
)->fetchAll();

$lecturers = db()->query(
    "SELECT id, full_name FROM users WHERE role IN ('admin','lecturer') AND account_status = 'approved' ORDER BY full_name"
)->fetchAll();

$whereUp   = ["r.status = 'active'"];
$upParams  = [];
if ($q !== '') {
    $whereUp[] = '(c.room_number LIKE ? OR c.building LIKE ? OR u.full_name LIKE ? OR r.purpose LIKE ?)';
    array_push($upParams, "%$q%", "%$q%", "%$q%", "%$q%");
}
$whereUpSql = implode(' AND ', $whereUp);

$st = db()->prepare("SELECT COUNT(*) AS n FROM reservations r JOIN classrooms c ON c.id = r.classroom_id LEFT JOIN users u ON u.id = r.user_id WHERE " . $whereUpSql);
$st->execute($upParams);
$upcomingTotal = (int)$st->fetch()['n'];
$upP           = page_params($upcomingTotal, (int)($_GET['up_page'] ?? 1));

$st = db()->prepare(
    'SELECT r.*, c.room_number, c.building, u.full_name
     FROM reservations r
     JOIN classrooms c ON c.id = r.classroom_id
     LEFT JOIN users u ON u.id = r.user_id
     WHERE ' . $whereUpSql . '
     ORDER BY r.start_time ASC
     LIMIT ' . $upP['limit'] . ' OFFSET ' . $upP['offset']
);
$st->execute($upParams);
$upcoming = $st->fetchAll();

$wherePast  = ["r.status <> 'active'"];
$pastParams = [];
if ($q !== '') {
    $wherePast[] = '(c.room_number LIKE ? OR c.building LIKE ? OR u.full_name LIKE ? OR r.purpose LIKE ?)';
    array_push($pastParams, "%$q%", "%$q%", "%$q%", "%$q%");
}
$wherePastSql = implode(' AND ', $wherePast);

$st = db()->prepare("SELECT COUNT(*) AS n FROM reservations r JOIN classrooms c ON c.id = r.classroom_id LEFT JOIN users u ON u.id = r.user_id WHERE " . $wherePastSql);
$st->execute($pastParams);
$pastTotal = (int)$st->fetch()['n'];
$pastP     = page_params($pastTotal, (int)($_GET['past_page'] ?? 1));

$st = db()->prepare(
    'SELECT r.*, c.room_number, c.building, u.full_name
     FROM reservations r
     JOIN classrooms c ON c.id = r.classroom_id
     LEFT JOIN users u ON u.id = r.user_id
     WHERE ' . $wherePastSql . '
     ORDER BY r.start_time DESC
     LIMIT ' . $pastP['limit'] . ' OFFSET ' . $pastP['offset']
);
$st->execute($pastParams);
$past = $st->fetchAll();

render_header('Reservations', ['prefix' => '../', 'nav' => 'admin', 'active' => 'reservations']);
?>

<div class="page-head">
  <h1><?= icon('calendar-days') ?> Reservations</h1>
  <button class="btn btn--primary btn--sm" type="button"
          data-modal-form="#reservationForm"
          data-title="New reservation"
          data-confirm-text="Reserve"><?= icon('plus') ?> New reservation</button>
  <p class="muted">Book rooms in advance. Rooms show as RESERVED on the landing page before the booking starts.</p>
</div>

<div class="card" style="margin-bottom: 1.2rem;">
  <form method="get" class="filter-row">
    <input type="search" name="q" placeholder="Search room, applicant, purpose…" value="<?= e($q) ?>">
    <button class="btn btn--sm" type="submit">Filter</button>
    <?php if ($q !== ''): ?>
      <a href="reservations.php" class="btn btn--ghost btn--sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

  <!-- shown as a SweetAlert2 modal by admin-modals.js -->
  <form method="post" class="form-grid form-grid--5" id="reservationForm" hidden style="text-align:left">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label class="full-width">Classroom
      <select name="classroom_id" required>
        <option value="">— choose classroom —</option>
        <?php foreach ($rooms as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= e($r['building']) ?> · <?= e($r['room_number']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="full-width">For (optional)
      <select name="user_id">
        <option value="">Unassigned</option>
        <?php foreach ($lecturers as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= e($l['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="full-width">Date <input type="date" name="date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
    <label>Starts <input type="time" name="start_time" required step="300"></label>
    <label>Ends <input type="time" name="end_time" required step="300"></label>
    <label class="full-width">Purpose <input name="purpose" maxlength="160" placeholder="e.g. Thesis defense panel"></label>
  </form>
</div>

<div class="card">
  <h3>Upcoming</h3>
  <?php if (!$upcoming): ?>
    <p class="muted">No upcoming reservations.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Date &amp; time</th><th>Room</th><th>For</th><th>Purpose</th><th style="text-align:right">Action</th></tr></thead>
    <tbody>
      <?php foreach ($upcoming as $r): ?>
      <tr>
        <td class="nowrap" data-label="When"><?= fmt_date($r['start_time']) ?> <span class="small muted">· <?= fmt_range($r['start_time'], $r['end_time']) ?></span></td>
        <td class="nowrap" data-label="Room"><strong><?= e($r['room_number']) ?></strong> <span class="muted small"><?= e($r['building']) ?></span></td>
        <td class="cell-truncate" data-label="Booked for"><?= e($r['full_name'] ?? '—') ?></td>
        <td class="small muted cell-truncate" data-label="Purpose"><?= e($r['purpose'] ?: '') ?></td>
        <td data-label="Action">
          <div class="actions-cell" style="justify-content:flex-end">
          <form method="post" data-confirm="Cancel this reservation?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit"><?= icon('ban') ?> Cancel</button>
          </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= page_nav($upcomingTotal, $upP['page'], ADMIN_PER_PAGE, 'up_page') ?>
  <?php endif; ?>
</div>

<?php if ($past): ?>
<div class="card">
  <h3>Completed / cancelled</h3>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Date</th><th>Room</th><th>Time</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($past as $r): ?>
      <tr>
        <td class="nowrap" data-label="Date"><?= fmt_date($r['start_time']) ?></td>
        <td class="nowrap" data-label="Room"><strong><?= e($r['room_number']) ?></strong></td>
        <td class="nowrap" data-label="Time"><?= fmt_range($r['start_time'], $r['end_time']) ?></td>
        <td data-label="Status"><span class="pill pill--<?= $r['status'] === 'cancelled' ? 'danger' : '' ?>"><?= e($r['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= page_nav($pastTotal, $pastP['page'], ADMIN_PER_PAGE, 'past_page') ?>
</div>
<?php endif; ?>

<?php render_footer(['assets/js/admin-modals.js']); ?>
