<?php
declare(strict_types=1);

/**
 * Classroom Finder — classroom management (Module: Classrooms).
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
        $id = $action === 'update' ? (int)($_POST['id'] ?? 0) : null;
        $res = room_save($_POST, $id, (int)$admin['id']);
        if (!$res['ok']) {
            $fail($res['error']);
        }
        flash('success', $res['message']);
        redirect('classrooms.php');
    }

    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $res = room_set_status($id, $status, (int)$admin['id']);
        if (!$res['ok']) {
            $fail($res['error']);
        }
        flash('success', $res['message']);
        redirect('classrooms.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $res = room_delete($id, (int)$admin['id']);
        if (!$res['ok']) {
            $fail($res['error']);
        }
        flash('success', $res['message']);
        redirect('classrooms.php');
    }

    $fail('Unknown action.');
}

// list (paged) + full set for the datalist
$q        = trim((string)($_GET['q'] ?? ''));
$statusF  = (string)($_GET['status'] ?? '');
$filters  = [];
if ($q !== '')       $filters['q'] = $q;
if ($statusF !== '') $filters['status'] = $statusF;

$allRooms = fetch_classrooms($filters);
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

<form method="get" class="filter-row">
  <input type="search" name="q" placeholder="Search room number, building, type or note…" value="<?= e($q) ?>">
  <select name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <option value="available" <?= $statusF === 'available' ? 'selected' : '' ?>>Available</option>
    <option value="occupied" <?= $statusF === 'occupied' ? 'selected' : '' ?>>Occupied</option>
    <option value="reserved" <?= $statusF === 'reserved' ? 'selected' : '' ?>>Reserved</option>
    <option value="maintenance" <?= $statusF === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
    <option value="disabled" <?= $statusF === 'disabled' ? 'selected' : '' ?>>Disabled</option>
  </select>
  <button class="btn btn--primary" type="submit">Filter</button>
  <?php if ($q !== '' || $statusF !== ''): ?>
    <a href="classrooms.php" class="btn btn--ghost">Reset</a>
  <?php endif; ?>
</form>

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
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Room &amp; Location</th><th>Type / Seats</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$rooms): ?>
      <tr>
        <td colspan="4" class="muted" style="text-align:center;padding:2rem 1rem;">
          No classrooms match the selected filters.
          <?php if ($q !== '' || $statusF !== ''): ?>
            <a href="classrooms.php">Clear filters</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php else: ?>
      <?php foreach ($rooms as $r): ?>
      <tr>
        <td class="cell-main nowrap" data-label="Room">
          <strong><?= e($r['room_number']) ?></strong> <span class="muted small">· <?= e($r['building']) ?> · F<?= (int)$r['floor'] ?></span>
          <?php if ($r['note']): ?><span class="muted small"> · <?= icon('file-text') ?> <?= e($r['note']) ?></span><?php endif; ?>
        </td>
        <td class="cell-sub nowrap" data-label="Type"><?= e($r['room_type']) ?> · <span class="muted small"><?= (int)$r['capacity'] ?> seats</span></td>
        <td class="cell-status nowrap" data-label="Status"><?php [$lbl, $stIcon] = room_status_meta($r['computed']); ?>
          <span class="pill pill--<?= $r['computed'] === 'unavailable' ? 'off' : $r['computed'] ?>"><?= icon($stIcon) ?> <?= ucfirst(e($r['status'])) ?></span>
          <?php if ($r['computed'] === 'occupied'): ?>
            <span class="muted small"> · <?= e($r['session_lecturer'] ?? '') ?> until <?= fmt_time($r['session_end']) ?></span>
          <?php endif; ?>
        </td>
        <td data-label="Actions">
          <div class="actions-cell" style="justify-content:flex-end">
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
          <details class="mini-details" name="status-dropdown">
            <summary class="btn btn--ghost btn--sm" title="Change Status"><?= icon('settings') ?> <span class="btn-text">Status</span></summary>
            <div class="mini-menu">
              <?php foreach (['available' => 'Set Available', 'maintenance' => 'Maintenance', 'disabled' => 'Disable Room'] as $k => $lbl2): ?>
                <?php if ($r['status'] !== $k): ?>
                  <form method="post" class="inline-form" style="width:100%" data-confirm="Change status of classroom <?= e($r['room_number']) ?> to <?= e($lbl2) ?>?"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="status" value="<?= $k ?>">
                    <button class="btn btn--ghost btn--sm" type="submit" style="width:100%;justify-content:flex-start"><?= $lbl2 ?></button></form>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </details>
          <form method="post" class="inline-form" data-confirm="Delete this classroom permanently? Only possible while it has no usage history."><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit" title="Delete classroom"><?= icon('trash-2') ?> <span class="btn-text">Delete</span></button></form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?= page_nav(count($allRooms), $pp['page']) ?>
</div>

<?php render_footer(['assets/js/admin-modals.js']); ?>
