<?php
declare(strict_types=1);

/**
 * Classroom Finder — system settings (§18).
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

$intKeys = [
    'reserve_window_minutes'  => [5, 720, 'Reserve window (minutes)'],
    'min_duration_minutes'    => [5, 480, 'Minimum occupancy (minutes)'],
    'max_duration_minutes'    => [15, 1440, 'Maximum occupancy (minutes)'],
    'duration_step_minutes'   => [5, 120, 'Duration picker step (minutes)'],
    'landing_refresh_seconds' => [5, 600, 'Landing page refresh (seconds)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fail = function (string $m): never {
        flash('error', $m);
        redirect('settings.php');
    };
    if (!check_csrf()) {
        $fail('Session expired — please try again.');
    }

    // ---------- logo upload / removal ----------
    if (($_POST['logo_action'] ?? '') === 'upload' && isset($_FILES['school_logo'])) {
        $f = $_FILES['school_logo'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $fail($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Logo is too large — 2 MB maximum.'
                : 'Upload failed — please try again.');
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            $fail('Logo is too large — 2 MB maximum.');
        }
        // raster formats only: an SVG logo would be same-origin executable,
        // so it's excluded on purpose. getimagesize() also rejects fakes.
        $info = @getimagesize($f['tmp_name']);
        $allowed = [IMAGETYPE_PNG => '.png', IMAGETYPE_JPEG => '.jpg', IMAGETYPE_WEBP => '.webp'];
        if ($info === false || !isset($allowed[$info[2]])) {
            $fail('Logo must be a PNG, JPG or WebP image.');
        }
        if (!is_dir(__DIR__ . '/../assets/uploads')
            && !mkdir(__DIR__ . '/../assets/uploads', 0775, true)) {
            $fail('Could not create the uploads directory.');
        }
        // unique name per upload -> browsers never show a stale cached logo;
        // the previous file is removed below once the new one is in place.
        $name    = 'logo-' . bin2hex(random_bytes(6)) . $allowed[$info[2]];
        $dest    = __DIR__ . '/../assets/uploads/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            $fail('Could not save the uploaded file.');
        }
        $old = get_setting('school_logo', '');
        if ($old !== '' && is_file(__DIR__ . '/../assets/uploads/' . $old)) {
            @unlink(__DIR__ . '/../assets/uploads/' . $old);
        }
        set_setting('school_logo', $name);
        log_action('LOGO_UPLOAD', (int)$admin['id'], null, 'School logo updated (' . $name . ')');
        flash('success', 'Logo uploaded.');
        redirect('settings.php');
    }
    if (($_POST['logo_action'] ?? '') === 'remove') {
        $old = get_setting('school_logo', '');
        if ($old !== '' && is_file(__DIR__ . '/../assets/uploads/' . $old)) {
            @unlink(__DIR__ . '/../assets/uploads/' . $old);
        }
        set_setting('school_logo', '');
        log_action('LOGO_REMOVE', (int)$admin['id']);
        flash('success', 'Logo removed.');
        redirect('settings.php');
    }

    // text settings
    $school = trim((string)($_POST['school_name'] ?? ''));
    if ($school !== '' && mb_strlen($school) <= 80) {
        set_setting('school_name', $school);
    }

    foreach ($intKeys as $key => [$min, $max, $label]) {
        $v = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT);
        if ($v !== false && $v >= $min && $v <= $max) {
            set_setting($key, (string)$v);
        } elseif ($_POST[$key] !== null && $_POST[$key] !== '') {
            flash('error', "$label must be between $min and $max — kept the previous value.");
        }
    }
    if (get_setting_int('min_duration_minutes', 15) > get_setting_int('max_duration_minutes', 480)) {
        set_setting('min_duration_minutes', '15');
        flash('error', 'Minimum occupancy cannot exceed maximum — minimum was reset.');
    }

    log_action('SETTINGS_UPDATE', (int)$admin['id']);
    flash('success', 'Settings saved.');
    redirect('settings.php');
}

render_header('Settings', ['prefix' => '../', 'nav' => 'admin', 'active' => 'settings']);
?>

<div class="page-head">
  <h1><?= icon('settings') ?> System settings</h1>
  <p class="muted">Tuning knobs for durations, reservations and the landing page.</p>
</div>

<div class="card">
  <h3>School logo</h3>
  <p class="muted small">Shown next to “Classroom Finder” in the top bar. PNG, JPG or WebP — up to 2&nbsp;MB.</p>
  <?php $logo = get_setting('school_logo', ''); ?>
  <?php if ($logo !== '' && is_file(__DIR__ . '/../assets/uploads/' . $logo)): ?>
    <div class="logo-preview">
      <img src="../assets/uploads/<?= e($logo) ?>" alt="Current school logo">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="logo_action" value="remove">
        <button class="btn btn--danger btn--sm" type="submit"
                data-confirm="Remove the current logo? The top bar falls back to the plain marker.">Remove logo</button>
      </form>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="logo-form">
    <?= csrf_field() ?>
    <input type="hidden" name="logo_action" value="upload">
    <input type="file" name="school_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required>
    <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?> Upload logo</button>
  </form>
</div>

<div class="card">
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label style="grid-column: 1 / -1">School / institution name
      <input name="school_name" maxlength="80" value="<?= e(get_setting('school_name', APP_NAME)) ?>">
    </label>

    <?php foreach ($intKeys as $key => [$min, $max, $label]): ?>
      <label><?= e($label) ?> <small>(<?= $min ?>–<?= $max ?>)</small>
        <input type="number" name="<?= $key ?>" min="<?= $min ?>" max="<?= $max ?>"
               value="<?= get_setting_int($key, match ($key) {
                   'reserve_window_minutes' => 45,
                   'min_duration_minutes'   => 15,
                   'max_duration_minutes'   => 480,
                   'duration_step_minutes'  => 30,
                   default                  => 15,
               }) ?>">
      </label>
    <?php endforeach; ?>

    <button class="btn btn--primary" type="submit" style="grid-column: 1 / -1">Save settings</button>
  </form>
</div>

<div class="card muted small">
  <h3>How these are used</h3>
  <ul>
    <li><strong>Reserve window</strong> — how many minutes before a booking a room flips to RESERVED on the landing page.</li>
    <li><strong>Min / max occupancy</strong> — bounds for the duration a lecturer can pick after scanning.</li>
    <li><strong>Picker step</strong> — the +/− increment in the scanner’s duration dialog.</li>
    <li><strong>Refresh</strong> — how often the public landing page reloads availability.</li>
  </ul>
</div>

<?php render_footer(); ?>
