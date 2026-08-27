<?php
declare(strict_types=1);

/**
 * Classroom Finder — classroom management (§19).
 * Add / edit rooms, toggle maintenance & availability, delete (only when a
 * room has never been used — otherwise disable it to preserve history).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();
$fail  = function (string $msg, string $back = 'classrooms.php'): never {
    flash('error', $msg);
    redirect($back);
};

const ROOM_TYPES = ['Lecture Room', 'Highschool Comlab', 'College Comlab', 'Highschool Room', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $fail('Session expired — please try again.');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'update') {
        $roomNumber = strtoupper(trim((string)($_POST['room_number'] ?? '')));
        $building   = trim((string)($_POST['building'] ?? ''));
        $floor      = max(1, (int)($_POST['floor'] ?? 1));
        $capacity   = max(1, min(9999, (int)($_POST['capacity'] ?? 0)));
        $type       = in_array($_POST['room_type'] ?? '', ROOM_TYPES, true) ? $_POST['room_type'] : ROOM_TYPES[0];
        $note       = trim((string)($_POST['note'] ?? ''));

        if ($roomNumber === '' || $building === '') {
            $fail('Room number and building are required.');
        }
        if (!preg_match('/^[A-Za-z0-9\- ]{1,20}$/', $roomNumber)) {
            $fail('Room number: letters, numbers, spaces and dashes only (max 20).');
        }

        // uniqueness of (building, room_number)
        $st = db()->prepare('SELECT id FROM classrooms WHERE building = ? AND room_number = ? AND id <> ?');
        $st->execute([$building, $roomNumber, (int)($_POST['id'] ?? 0)]);
        if ($st->fetch()) {
            $fail("{$building} already has a room {$roomNumber}.");
        }

        if ($action === 'add') {
            db()->prepare(
                'INSERT INTO classrooms (room_number, building, floor, capacity, room_type, qr_token, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $roomNumber, $building, $floor, $capacity, $type,
                bin2hex(random_bytes(16)),            // unique QR token (§20)
                $note ?: null,
            ]);
            log_action('CLASSROOM_ADD', (int)$admin['id'], null, "Added room {$building} {$roomNumber}");
            flash('success', "Classroom {$roomNumber} added. Print its QR code from the QR Codes page.");
        } else {
            db()->prepare(
                'UPDATE classrooms SET room_number = ?, building = ?, floor = ?, capacity = ?, room_type = ?, note = ?
                 WHERE id = ?'
            )->execute([$roomNumber, $building, $floor, $capacity, $type, $note ?: null, (int)$_POST['id']]);
            log_action('CLASSROOM_EDIT', (int)$admin['id'], (int)$_POST['id'], "Updated room {$building} {$roomNumber}");
            flash('success', "Classroom {$roomNumber} updated.");
        }
        redirect('classrooms.php');
    }

    if ($action === 'set_status') {
        $new = $_POST['status'] ?? '';
        if (!in_array($new, ['available', 'maintenance', 'disabled'], true)) {
            $fail('Unknown status.');
        }
        db()->prepare('UPDATE classrooms SET status = ? WHERE id = ?')->execute([$new, (int)$_POST['id']]);
        // $new was validated above; it doubles as the human-readable label
        $label = $new;
        log_action('CLASSROOM_STATUS', (int)$admin['id'], (int)$_POST['id'], 'Status set to ' . $label);
        flash('success', 'Classroom marked as ' . $label . '.');
        redirect('classrooms.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $nSessions = db()->prepare('SELECT COUNT(*) AS n FROM classroom_sessions WHERE classroom_id = ?');
        $nSessions->execute([$id]);
        if ((int)$nSessions->fetch()['n'] > 0) {
            $fail('This room has usage history and cannot be deleted. Set it to “disabled” instead to keep the records.');
        }
        db()->prepare('DELETE FROM reservations WHERE classroom_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM classrooms WHERE id = ?')->execute([$id]);
        log_action('CLASSROOM_DELETE', (int)$admin['id'], null, "Deleted classroom #{$id}");
        flash('success', 'Classroom deleted.');
        redirect('classrooms.php');
    }

    $fail('Unknown action.');
}

// list (paged) + full set for the datalist
$allRooms = fetch_classrooms();
$pp       = page_params(count($allRooms), (int)($_GET['page'] ?? 1));
$rooms    = array_slice($allRooms, $pp['offset'], $pp['limit']);

render_header('Classrooms', ['prefix' => '../', 'nav' => 'admin', 'active' => 'classrooms']);
?>

<div class="page-head">
  <h1><?= icon('door-open') ?> Classrooms</h1>
  <button class="btn btn--primary btn--sm" type="button"
          data-modal-form="#classroomForm"
          data-title="Add a classroom"
          data-confirm-text="Add classroom"><?= icon('plus') ?> Add classroom</button>
  <p class="muted">Every registered room gets a unique QR token automatically.</p>
</div>

<datalist id="buildingList">
  <?php foreach (array_unique(array_column($allRooms, 'building')) as $b): ?>
    <option value="<?= e($b) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- add/edit lives in this template; SweetAlert2 shows it as a modal -->
<form method="post" class="form-grid" id="classroomForm" hidden
      data-default-action="add" style="text-align:left">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <input type="hidden" name="id" value="">
  <label>Room number <input name="room_number" required maxlength="20" placeholder="201"></label>
  <label>Building
    <input name="building" required maxlength="80" list="buildingList" placeholder="Main Building">
  </label>
  <label>Floor <input type="number" name="floor" min="1" max="99" value="1"></label>
  <label>Capacity <input type="number" name="capacity" min="1" max="9999" value="40"></label>
  <label class="full-width">Room type
    <select name="room_type">
      <?php foreach (ROOM_TYPES as $t): ?>
        <option><?= e($t) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="full-width">Note <small>(optional — shown on the landing page)</small>
    <input name="note" maxlength="160" placeholder="e.g. undergoing maintenance"></label>
</form>

<div class="card">
  <table class="table">
    <thead><tr><th>Room &amp; Location</th><th>Type / Seats</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($rooms as $r): ?>
      <tr>
        <td class="cell-main" data-label="Room">
          <strong><?= e($r['room_number']) ?></strong> <span class="muted small">· <?= e($r['building']) ?> · F<?= (int)$r['floor'] ?></span>
          <?php if ($r['note']): ?><div class="muted small"><?= icon('file-text') ?> <?= e($r['note']) ?></div><?php endif; ?>
        </td>
        <td class="cell-sub" data-label="Type"><?= e($r['room_type']) ?> · <span class="muted small"><?= (int)$r['capacity'] ?> seats</span></td>
        <td class="cell-status" data-label="Status"><?php [$lbl, $stIcon] = room_status_meta($r['computed']); ?>
          <span class="pill pill--<?= $r['computed'] === 'unavailable' ? 'off' : $r['computed'] ?>"><?= icon($stIcon) ?> <?= ucfirst(e($r['status'])) ?></span>
          <?php if ($r['computed'] === 'occupied'): ?>
            <div class="muted small"><?= e($r['session_lecturer'] ?? '') ?> until <?= fmt_time($r['session_end']) ?></div>
          <?php endif; ?>
        </td>
        <td class="actions-cell" style="justify-content:flex-end" data-label="Actions">
          <button class="btn btn--ghost btn--sm" type="button"
                  data-modal-form="#classroomForm"
                  data-title="Edit classroom <?= e($r['room_number']) ?>"
                  data-confirm-text="Save changes"
                  title="Edit classroom"
                  data-prefill='<?= e(json_encode([
                      'action'      => 'update',
                      'id'          => (int)$r['id'],
                      'room_number' => $r['room_number'],
                      'building'    => $r['building'],
                      'floor'       => (int)$r['floor'],
                      'capacity'    => (int)$r['capacity'],
                      'room_type'   => $r['room_type'],
                      'note'        => (string)($r['note'] ?? ''),
                  ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>'><?= icon('pencil') ?> <span class="btn-text">Edit</span></button>
          <a class="btn btn--ghost btn--sm" href="qr_codes.php#qr-<?= (int)$r['id'] ?>" title="QR code"><?= icon('qr-code') ?> <span class="btn-text">QR</span></a>
          <details class="mini-details">
            <summary class="btn btn--ghost btn--sm" title="Change Status"><?= icon('settings') ?> <span class="btn-text">Status</span></summary>
            <div class="mini-menu">
              <?php foreach (['available' => 'Set Available', 'maintenance' => 'Maintenance', 'disabled' => 'Disable Room'] as $k => $lbl2): ?>
                <?php if ($r['status'] !== $k): ?>
                  <form method="post" class="inline-form" style="width:100%"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="status" value="<?= $k ?>">
                    <button class="btn btn--ghost btn--sm" type="submit" style="width:100%;justify-content:flex-start"><?= $lbl2 ?></button></form>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </details>
          <form method="post" class="inline-form" data-confirm="Delete this classroom permanently? Only possible while it has no usage history."><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn--ghost-danger btn--sm" type="submit" title="Delete classroom"><?= icon('trash-2') ?> <span class="btn-text">Delete</span></button></form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?= page_nav(count($allRooms), $pp['page']) ?>
</div>

<?php render_footer(['assets/js/admin-modals.js']); ?>
