<?php
declare(strict_types=1);

/**
 * Classroom Finder — QR scanner page.
 *
 * Uses the vendored html5-qrcode library. After a successful decode, JS posts
 * the token to api/scan_qr.php; if the room is free this dialog collects the
 * usage duration and submits to occupy.php, which validates everything again
 * server-side before creating a session.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();

$active = get_active_session_for((int)$user['id']);

render_header('Scan Classroom QR', ['prefix' => '../', 'nav' => 'lecturer', 'active' => 'scanner']);
?>
<div class="scanner-container">
<div class="page-head">
  <h1><?= icon('scan-line') ?> Scan a classroom QR code</h1>
  <p class="muted">Point your camera at the QR poster outside the room. You can also type the token manually below.</p>
</div>

<?php if ($active): ?>
<div class="card current-room">
  <div>
    <p class="eyebrow">YOU ARE OCCUPYING</p>
    <h2><?= e($active['room_number']) ?> <span class="muted">· <?= e($active['building']) ?></span></h2>
    <p><?= fmt_range($active['start_time'], $active['end_time']) ?></p>
  </div>
  <form method="post" action="release.php" data-confirm="Release <?= e($active['room_number']) ?> now?">
    <?= csrf_field() ?>
    <input type="hidden" name="session_id" value="<?= (int)$active['id'] ?>">
    <button class="btn btn--danger" type="submit">Release classroom</button>
  </form>
</div>
<p class="muted small center-note">You already hold an active session — you can’t occupy another room until it ends or is released.</p>
<?php endif; ?>

<div class="scanner-layout">
  <div class="card scanner-card">
    <div class="reader-wrap">
      <div id="reader" class="reader" aria-label="Camera preview"></div>
      <div class="reader-reticle" aria-hidden="true">
        <i></i><i></i><i></i><i></i>
        <span class="reader-reticle__beam"></span>
      </div>
      <!-- idle state: what users see before the camera runs (hidden via .is-live) -->
      <div class="reader-idle" aria-hidden="true">
        <?= icon('scan-line') ?>
        <span>Camera off</span>
        <small>Press “Start camera” to scan</small>
      </div>
    </div>
    <p class="scan-hint muted small">Align the QR poster inside the frame</p>
    <div class="reader__controls">
      <button id="startBtn" class="btn btn--primary" type="button">Start camera</button>
      <button id="stopBtn" class="btn btn--ghost" type="button" hidden>Stop</button>
      <label class="torch-toggle" hidden><input type="checkbox" id="torchToggle"> <?= icon('flashlight') ?> Torch</label>
    </div>
    <p id="scanStatus" class="scan-status muted"></p>

    <div class="scanner-divider">
      <span>or enter token manually</span>
    </div>

    <form id="manualForm" class="manual-token-form">
      <input name="token" placeholder="Paste or type 32-character token..." maxlength="120" autocomplete="off" required>
      <button class="btn btn--primary" type="submit">Look up</button>
    </form>
  </div>
</div>
</div>

<?php if ($active): ?>
<div class="flashes">
  <div class="flash flash--error">You still have an active session in <?= e($active['room_number']) ?>. Release it before occupying another room.</div>
</div>
<?php endif; ?>

<!-- confirmation: SweetAlert2 renders the dialog; this hidden form
     carries the actual POST to occupy.php, which re-validates server-side. -->
<form method="post" action="occupy.php" id="occupyForm" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="token" id="fToken" value="">
  <input type="hidden" name="minutes" id="fMinutes" value="">
</form>

<script src="../assets/js/vendor/html5-qrcode.min.js"></script>
<?php render_footer(['assets/js/scanner.js']); ?>
