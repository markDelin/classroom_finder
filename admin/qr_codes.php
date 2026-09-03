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
        db()->prepare('UPDATE classrooms SET qr_token = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(16)), $id]);
        log_action('QR_REGENERATE', (int)$admin['id'], $id);
        flash('success', 'QR token regenerated. Old printed posters for this room no longer work.');
    }
    redirect('qr_codes.php');
}

$rooms = fetch_classrooms();

render_header('QR Codes', ['prefix' => '../', 'nav' => 'admin', 'active' => 'qr']);
?>

<div class="page-head no-print">
  <h1><?= icon('qr-code') ?> Classroom QR codes</h1>
  <button class="btn btn--primary btn--sm" type="button" onclick="window.print()"><?= icon('printer') ?> Print all</button>
  <p class="muted">Print these and place them outside each room. Lecturers scan them to record a session.</p>
</div>

<div class="qr-grid">
  <?php foreach ($rooms as $r): ?>
  <div class="card qr-poster" id="qr-<?= (int)$r['id'] ?>">
    <div class="qr-poster__head">
      <strong>ROOM <?= e($r['room_number']) ?></strong>
      <span class="muted small"><?= e($r['building']) ?> · Floor <?= (int)$r['floor'] ?></span>
    </div>
    <img class="qr-img" src="../qr/generate.php?id=<?= (int)$r['id'] ?>&size=9"
         alt="QR code for room <?= e($r['room_number']) ?>" width="360" height="360">
    <p class="qr-token muted small" title="Secret token — do not share publicly">Classroom ID: CF-<?= e($r['room_number']) ?><br><code><?= e(substr($r['qr_token'], 0, 8)) ?>…<?= e(substr($r['qr_token'], -4)) ?></code></p>
    <form method="post" class="no-print" data-confirm="Regenerate the QR token for room <?= e($r['room_number']) ?>? Any previously printed code stops working.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="regenerate">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh-cw') ?> Regenerate QR</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
