<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        flash('error', 'Session expired — please try again.');
        redirect('qr_codes.php');
    }
    if (($_POST['action'] ?? '') === 'regenerate') {
        $id = (int)($_POST['id'] ?? 0);
        $returnPage = max(1, (int)($_POST['page'] ?? 1));
        db()->prepare('UPDATE classrooms SET qr_token = ? WHERE id = ?')
            ->execute([generate_qr_token(), $id]);
        log_action('QR_REGENERATE', (int)$admin['id'], $id);
        flash('success', 'QR token regenerated. Old printed posters for this room no longer work.');
        redirect('qr_codes.php' . ($returnPage > 1 ? '?page=' . $returnPage : ''));
    }
    redirect('qr_codes.php');
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = admin_per_page();
$q        = trim((string)($_GET['q'] ?? ''));
$building = trim((string)($_GET['building'] ?? ''));
$filters  = [];
if ($q !== '')        { $filters['q'] = $q; }
if ($building !== '') { $filters['building'] = $building; }

try {
    $stLegacy = db()->query('SELECT id FROM classrooms WHERE LENGTH(qr_token) != 8');
    if ($stLegacy) {
        $legacy = $stLegacy->fetchAll();
        if ($legacy) {
            $updToken = db()->prepare('UPDATE classrooms SET qr_token = ? WHERE id = ?');
            foreach ($legacy as $lr) {
                $updToken->execute([generate_qr_token(), (int)$lr['id']]);
            }
        }
    }
} catch (Throwable) {}

$allRooms  = fetch_classrooms($filters);
$buildings = db()->query('SELECT DISTINCT building FROM classrooms WHERE building IS NOT NULL AND building != "" ORDER BY building')->fetchAll(PDO::FETCH_COLUMN);
$total     = count($allRooms);
$pP        = page_params($total, $page, $perPage);
$rooms     = array_slice($allRooms, $pP['offset'], $pP['limit']);

render_header('QR Codes', ['prefix' => '../', 'nav' => 'admin', 'active' => 'qr']);
?>

<div class="page-head no-print">
  <h1><?= icon('qr-code') ?> <span class="hide-mobile">Classroom </span>QR codes</h1>
  <div class="page-head__actions">
    <button class="btn btn--primary btn--sm" type="button" onclick="cfPrintAllQrs()" title="Print all <?= $total ?> QR codes">
      <?= icon('printer') ?> <span class="hide-mobile">Print all QRs</span><span class="show-mobile">Print all</span> (<?= $total ?>)
    </button>
    <button class="btn btn--secondary btn--sm" type="button" id="btnPrintSelected" onclick="cfPrintSelected()" title="Print selected QR codes on this page">
      <?= icon('check-square') ?> <span class="hide-mobile">Print selected</span><span class="show-mobile">Selected</span> (<span id="selectedCount"><?= count($rooms) ?></span>)
    </button>
  </div>
  <p class="muted">Select specific rooms to print replacement posters, or print all classrooms at once.</p>
</div>

<div class="qr-bar no-print">
  <form method="get" class="qr-bar__filters">
    <input type="search" name="q" placeholder="Search room number, building…" value="<?= e($q) ?>">
    <?php if ($buildings): ?>
    <select name="building" onchange="this.form.submit()">
      <option value="">All Buildings</option>
      <?php foreach ($buildings as $b): ?>
        <option value="<?= e($b) ?>" <?= $building === $b ? 'selected' : '' ?>><?= e($b) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn--primary" type="submit">Filter</button>
    <?php if ($q !== '' || $building !== ''): ?>
      <a href="qr_codes.php" class="btn btn--ghost">Reset</a>
    <?php endif; ?>
  </form>
  <div class="qr-bar__selection">
    <button class="btn btn--ghost btn--xs" type="button" onclick="cfSelectAllQrs(true)">Select all</button>
    <button class="btn btn--ghost btn--xs" type="button" onclick="cfSelectAllQrs(false)">Deselect all</button>
  </div>
</div>

<div class="card no-print">
  <div class="table-wrap">
    <table class="table qr-table">
      <thead>
        <tr>
          <th style="width:38px;text-align:center;">
            <label class="qr-select-th-label" title="Select / deselect all on page">
              <input type="checkbox" id="checkAllQrs" class="qr-select-check" checked onchange="cfToggleAllOnPage(this.checked)">
            </label>
          </th>
          <th style="width:54px;">QR</th>
          <th>Room &amp; Location</th>
          <th class="hide-mobile">Classroom ID &amp; Token</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rooms): ?>
        <tr>
          <td colspan="5" class="muted" style="text-align:center;padding:2rem 1rem;">
            No classrooms found matching the criteria.
            <?php if ($q !== '' || $building !== ''): ?>
              <a href="qr_codes.php">Clear filters</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($rooms as $r): ?>
        <tr id="qr-row-<?= (int)$r['id'] ?>" class="is-selected" data-room-id="<?= (int)$r['id'] ?>" data-room-number="<?= e($r['room_number']) ?>">
          <td style="text-align:center;">
            <input type="checkbox" class="qr-select-check qr-row-check" value="<?= (int)$r['id'] ?>" checked onchange="cfUpdateSelectedCount()">
          </td>
          <td class="qr-table__thumb-cell">
            <a href="../qr/generate.php?id=<?= (int)$r['id'] ?>&size=12" target="_blank" class="qr-table__thumb-link" title="View high-resolution QR">
              <img src="../qr/generate.php?id=<?= (int)$r['id'] ?>&size=3" width="40" height="40" alt="QR <?= e($r['room_number']) ?>" class="qr-table__thumb" loading="lazy">
            </a>
          </td>
          <td class="cell-main" data-label="Room">
            <strong>Room <?= e($r['room_number']) ?></strong>
            <span class="muted small">· <?= e($r['building']) ?> · Floor <?= (int)$r['floor'] ?></span>
            <?php if (!empty($r['room_type'])): ?>
              <span class="muted small hide-mobile">· <?= e($r['room_type']) ?></span>
            <?php endif; ?>
            <span class="show-mobile qr-mobile-meta muted small">
              <br>CF-<?= e($r['room_number']) ?> · <code><?= e($r['qr_token']) ?></code>
            </span>
          </td>
          <td class="cell-sub nowrap hide-mobile" data-label="Classroom ID">
            <span class="qr-table__id">CF-<?= e($r['room_number']) ?></span>
            <span class="muted small"><code><?= e($r['qr_token']) ?></code></span>
          </td>
          <td data-label="Actions">
            <div class="actions-cell" style="justify-content:flex-end">
              <button class="btn btn--secondary btn--sm" type="button" onclick="cfPrintSingleQr(<?= (int)$r['id'] ?>)" title="Print QR poster for Room <?= e($r['room_number']) ?>">
                <?= icon('printer') ?> <span class="hide-mobile">Print</span>
              </button>
              <form method="post" class="inline-form qr-regen-form" data-confirm="Regenerate the QR token for room <?= e($r['room_number']) ?>? Any previously printed code stops working.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="regenerate">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="page" value="<?= (int)$pP['page'] ?>">
                <button class="btn btn--ghost btn--sm" type="submit" title="Regenerate token">
                  <?= icon('refresh-cw') ?> <span class="hide-mobile">Regenerate</span><span class="show-mobile">Regen</span>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="qr-grid qr-print-sheet" aria-hidden="true">
  <?php foreach ($allRooms as $r): ?>
  <div class="card qr-poster" id="qr-<?= (int)$r['id'] ?>" data-room-id="<?= (int)$r['id'] ?>" data-room-number="<?= e($r['room_number']) ?>">
    <div class="qr-poster__head">
      <strong>ROOM <?= e($r['room_number']) ?></strong>
      <span class="muted small"><?= e($r['building']) ?> · Floor <?= (int)$r['floor'] ?></span>
    </div>
    <img class="qr-img" src="../qr/generate.php?id=<?= (int)$r['id'] ?>&size=9"
         alt="QR code for room <?= e($r['room_number']) ?>" width="360" height="360" loading="eager">
    <p class="qr-token muted small" title="Token for manual entry">Classroom ID: CF-<?= e($r['room_number']) ?><br>Token: <code><?= e($r['qr_token']) ?></code></p>
  </div>
  <?php endforeach; ?>
</div>

<div class="no-print">
  <?= page_nav($total, $pP['page'], $perPage, 'page', 'QR codes') ?>
</div>

<script>
function cfUpdateSelectedCount() {
  var checks = document.querySelectorAll('.qr-row-check');
  var count = 0;
  var allChecked = (checks.length > 0);
  checks.forEach(function (c) {
    var row = c.closest('tr');
    if (c.checked) {
      count++;
      if (row) { row.classList.add('is-selected'); row.classList.remove('is-unselected'); }
    } else {
      allChecked = false;
      if (row) { row.classList.remove('is-selected'); row.classList.add('is-unselected'); }
    }
  });
  var countEl = document.getElementById('selectedCount');
  if (countEl) countEl.textContent = count;
  var labelEl = document.getElementById('selectedLabel');
  if (labelEl) labelEl.textContent = count;
  var btn = document.getElementById('btnPrintSelected');
  if (btn) btn.disabled = (count === 0);
  var master = document.getElementById('checkAllQrs');
  if (master) {
    master.checked = allChecked;
    master.indeterminate = (count > 0 && !allChecked);
  }
}

function cfToggleAllOnPage(checked) {
  document.querySelectorAll('.qr-row-check').forEach(function (c) {
    c.checked = checked;
  });
  cfUpdateSelectedCount();
}

function cfSelectAllQrs(check) {
  cfToggleAllOnPage(check);
}

function cfPreparePrint(filterFn) {
  document.querySelectorAll('.qr-print-spacer').forEach(function (s) {
    s.remove();
  });
  var posters = document.querySelectorAll('.qr-poster');
  var visibleIndex = 0;
  posters.forEach(function (p) {
    p.classList.remove('is-page-end');
    if (filterFn(p)) {
      p.classList.remove('is-print-hidden');
      p.style.opacity = '1';
      visibleIndex++;
      if (visibleIndex % 6 === 1) {
        var spacer = document.createElement('div');
        spacer.className = 'qr-print-spacer';
        p.parentNode.insertBefore(spacer, p);
      }
      if (visibleIndex % 6 === 0) {
        p.classList.add('is-page-end');
      }
    } else {
      p.classList.add('is-print-hidden');
    }
  });
  return visibleIndex;
}

var cfOriginalTitle = document.title;

function cfRestorePrint() {
  document.querySelectorAll('.qr-print-spacer').forEach(function (s) {
    s.remove();
  });
  document.querySelectorAll('.qr-poster').forEach(function (p) {
    p.classList.remove('is-print-hidden');
    p.classList.remove('is-page-end');
    p.style.opacity = '';
  });
  if (cfOriginalTitle) {
    document.title = cfOriginalTitle;
  }
}

function cfPrintAllQrs() {
  cfOriginalTitle = document.title;
  document.title = 'Classroom-QR-Codes';
  cfPreparePrint(function () {
    return true;
  });
  window.print();
  setTimeout(cfRestorePrint, 1000);
}

function cfPrintSelected() {
  var selectedCards = {};
  var selectedRoomNumbers = [];
  document.querySelectorAll('.qr-row-check:checked').forEach(function (c) {
    selectedCards[c.value] = true;
    var row = c.closest('tr');
    if (row && row.dataset.roomNumber) {
      selectedRoomNumbers.push(row.dataset.roomNumber);
    }
  });
  if (Object.keys(selectedCards).length === 0) {
    if (window.cfToast) { cfToast('warning', 'Please select at least one QR code to print.'); }
    else { alert('Please select at least one QR code to print.'); }
    return;
  }
  cfOriginalTitle = document.title;
  if (selectedRoomNumbers.length === 1) {
    document.title = 'Classroom-QR-Room-' + selectedRoomNumbers[0];
  } else {
    document.title = 'Classroom-QR-Codes';
  }
  cfPreparePrint(function (p) {
    return !!selectedCards[p.dataset.roomId];
  });
  window.print();
  setTimeout(cfRestorePrint, 1000);
}

function cfPrintSingleQr(roomId) {
  cfOriginalTitle = document.title;
  var card = document.getElementById('qr-' + roomId);
  var roomNo = (card && card.dataset.roomNumber) ? card.dataset.roomNumber : roomId;
  document.title = 'Classroom-QR-Room-' + roomNo;
  cfPreparePrint(function (p) {
    return String(p.dataset.roomId) === String(roomId);
  });
  window.print();
  setTimeout(cfRestorePrint, 1000);
}

window.addEventListener('beforeprint', function () {
  if (!document.querySelector('.qr-poster.is-print-hidden') && !document.querySelector('.qr-poster.is-page-end')) {
    cfOriginalTitle = document.title;
    document.title = 'Classroom-QR-Codes';
    cfPreparePrint(function () { return true; });
  }
});

window.addEventListener('afterprint', cfRestorePrint);
</script>

<?php render_footer(); ?>
