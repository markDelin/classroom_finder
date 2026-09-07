<?php
declare(strict_types=1);

/**
 * Classroom Finder — printable QR codes (Module: Room QR Code Generation & Management).
 *
 * One print-ready poster per classroom (image from qr/generate.php, admin-only).
 * "Regenerate" invalidates the old token: previously printed posters stop
 * working immediately.
 */

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
            ->execute([bin2hex(random_bytes(16)), $id]);
        log_action('QR_REGENERATE', (int)$admin['id'], $id);
        flash('success', 'QR token regenerated. Old printed posters for this room no longer work.');
        redirect('qr_codes.php' . ($returnPage > 1 ? '?page=' . $returnPage : ''));
    }
    redirect('qr_codes.php');
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 10;
$q        = trim((string)($_GET['q'] ?? ''));
$building = trim((string)($_GET['building'] ?? ''));
$filters  = [];
if ($q !== '')        { $filters['q'] = $q; }
if ($building !== '') { $filters['building'] = $building; }

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
    <select name="building">
      <option value="">All Buildings</option>
      <?php foreach ($buildings as $b): ?>
        <option value="<?= e($b) ?>" <?= $building === $b ? 'selected' : '' ?>><?= e($b) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn--primary btn--sm" type="submit">Filter</button>
    <?php if ($q !== '' || $building !== ''): ?>
      <a href="qr_codes.php" class="btn btn--ghost btn--sm">Reset</a>
    <?php endif; ?>
  </form>
  <div class="qr-bar__selection">
    <span class="muted small qr-selection-stats"><span id="selectedLabel"><?= count($rooms) ?></span> of <?= count($rooms) ?> on page</span>
    <button class="btn btn--ghost btn--sm" type="button" onclick="cfSelectAllQrs(true)">Select all</button>
    <button class="btn btn--ghost btn--sm" type="button" onclick="cfSelectAllQrs(false)">Deselect all</button>
  </div>
</div>

<div class="qr-grid">
  <?php if (!$allRooms): ?>
    <p class="muted" style="grid-column:1 / -1;padding:2rem 0;text-align:center;">No classrooms found matching the criteria.</p>
  <?php endif; ?>
  <?php
  $startIdx = $pP['offset'];
  $endIdx   = $pP['offset'] + count($rooms);
  foreach ($allRooms as $idx => $r):
    $isOnCurrentPage = ($idx >= $startIdx && $idx < $endIdx);
  ?>
  <div class="card qr-poster<?= !$isOnCurrentPage ? ' qr-poster--print-only' : '' ?>" id="qr-<?= (int)$r['id'] ?>" data-room-id="<?= (int)$r['id'] ?>">
    <div class="qr-poster__select no-print"<?= !$isOnCurrentPage ? ' style="display:none;"' : '' ?>>
      <label class="qr-select-label" title="Include in batch print">
        <input type="checkbox" class="qr-select-check" value="<?= (int)$r['id'] ?>" checked onchange="cfUpdateSelectedCount()">
        <span>Print</span>
      </label>
    </div>
    <div class="qr-poster__head">
      <strong>ROOM <?= e($r['room_number']) ?></strong>
      <span class="muted small"><?= e($r['building']) ?> · Floor <?= (int)$r['floor'] ?></span>
    </div>
    <img class="qr-img" src="../qr/generate.php?id=<?= (int)$r['id'] ?>&size=9"
         alt="QR code for room <?= e($r['room_number']) ?>" width="360" height="360" loading="eager">
    <p class="qr-token muted small" title="Secret token — do not share publicly">Classroom ID: CF-<?= e($r['room_number']) ?><br><code><?= e(substr($r['qr_token'], 0, 8)) ?>…<?= e(substr($r['qr_token'], -4)) ?></code></p>
    <?php if ($isOnCurrentPage): ?>
    <div class="qr-poster__actions no-print">
      <button class="btn btn--secondary btn--xs" type="button" onclick="cfPrintSingleQr(<?= (int)$r['id'] ?>)" title="Print only this QR code">
        <?= icon('printer') ?> Print
      </button>
      <form method="post" data-confirm="Regenerate the QR token for room <?= e($r['room_number']) ?>? Any previously printed code stops working." style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="page" value="<?= (int)$pP['page'] ?>">
        <button class="btn btn--ghost btn--xs" type="submit" title="Regenerate token"><?= icon('refresh-cw') ?> Regenerate</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="no-print">
  <?= page_nav($total, $pP['page'], $perPage) ?>
</div>

<script>
function cfUpdateSelectedCount() {
  var checks = document.querySelectorAll('.qr-select-check');
  var count = 0;
  checks.forEach(function (c) {
    var poster = c.closest('.qr-poster');
    if (c.checked) {
      count++;
      if (poster) { poster.classList.add('is-selected'); poster.classList.remove('is-unselected'); }
    } else {
      if (poster) { poster.classList.remove('is-selected'); poster.classList.add('is-unselected'); }
    }
  });
  var countEl = document.getElementById('selectedCount');
  if (countEl) countEl.textContent = count;
  var labelEl = document.getElementById('selectedLabel');
  if (labelEl) labelEl.textContent = count;
  var btn = document.getElementById('btnPrintSelected');
  if (btn) btn.disabled = (count === 0);
}

function cfSelectAllQrs(check) {
  document.querySelectorAll('.qr-select-check').forEach(function (c) {
    c.checked = check;
  });
  cfUpdateSelectedCount();
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

function cfRestorePrint() {
  document.querySelectorAll('.qr-print-spacer').forEach(function (s) {
    s.remove();
  });
  document.querySelectorAll('.qr-poster').forEach(function (p) {
    p.classList.remove('is-print-hidden');
    p.classList.remove('is-page-end');
    p.style.opacity = '';
  });
}

function cfPrintAllQrs() {
  cfPreparePrint(function () {
    return true;
  });
  window.print();
  setTimeout(cfRestorePrint, 1000);
}

function cfPrintSelected() {
  var selectedCards = {};
  document.querySelectorAll('.qr-select-check:checked').forEach(function (c) {
    selectedCards[c.value] = true;
  });
  if (Object.keys(selectedCards).length === 0) {
    if (window.cfToast) { cfToast('warning', 'Please select at least one QR code to print.'); }
    else { alert('Please select at least one QR code to print.'); }
    return;
  }
  cfPreparePrint(function (p) {
    return !!selectedCards[p.dataset.roomId];
  });
  window.print();
  setTimeout(cfRestorePrint, 1000);
}

function cfPrintSingleQr(roomId) {
  cfPreparePrint(function (p) {
    return String(p.dataset.roomId) === String(roomId);
  });
  window.print();
  setTimeout(cfRestorePrint, 1000);
}

window.addEventListener('beforeprint', function () {
  if (!document.querySelector('.qr-poster.is-print-hidden') && !document.querySelector('.qr-poster.is-page-end')) {
    cfPreparePrint(function () { return true; });
  }
});

window.addEventListener('afterprint', cfRestorePrint);
</script>

<?php render_footer(); ?>
