<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$user = require_approved_lecturer();

render_header('Scan Classroom QR', ['prefix' => '../', 'nav' => 'lecturer', 'active' => 'scanner']);
?>
<div class="scanner-container">
<div class="page-head">
  <h1><?= icon('scan-line') ?> Scan a classroom QR code</h1>
  <p class="muted">Point your camera at the QR poster outside the room. You can also type the token manually below.</p>
</div>

<?php if (!is_within_scan_hours()): ?>
  <?php [$scanStart, $scanEnd] = get_scan_hours(); ?>
  <div class="scanner-hours-banner" style="background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0); border-left: 4px solid #eab308; padding: .85rem 1.1rem; border-radius: 8px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: .75rem;">
    <span style="color: #eab308; display: flex; align-items: center;"><?= icon('clock') ?></span>
    <div>
      <strong>Room scanning is closed for the day.</strong>
      <div class="muted small">Daily room occupancy is permitted between <?= e(fmt_range($scanStart, $scanEnd)) ?>. Existing active sessions continue until their scheduled end time.</div>
    </div>
  </div>
<?php endif; ?>

<div class="scanner-layout">
  <div class="card scanner-card">
    <div class="reader-wrap">
      <div id="reader" class="reader" aria-label="Camera preview"></div>
      <div class="reader-reticle" aria-hidden="true">
        <i></i><i></i><i></i><i></i>
        <span class="reader-reticle__beam"></span>
      </div>
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
      <input name="token" placeholder="Enter 8-character token (e.g. 7b2e3f1a)..." maxlength="32" autocomplete="off" required>
      <button class="btn btn--primary" type="submit">Look up</button>
    </form>
  </div>
</div>
</div>

<form method="post" action="occupy.php" id="occupyForm" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="token" id="fToken" value="">
  <input type="hidden" name="minutes" id="fMinutes" value="">
</form>

<audio id="scanSuccessSound" src="../assets/sound/success.mp3" preload="auto"></audio>
<audio id="scanErrorSound" src="../assets/sound/error.mp3" preload="auto"></audio>

<script src="../assets/js/vendor/html5-qrcode.min.js"></script>
<?php render_footer(['assets/js/scanner.js']); ?>
