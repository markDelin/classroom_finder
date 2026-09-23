<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

$intKeys = [
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
        $info = @getimagesize($f['tmp_name']);
        $allowed = [IMAGETYPE_PNG => '.png', IMAGETYPE_JPEG => '.jpg', IMAGETYPE_WEBP => '.webp'];
        if ($info === false || !isset($allowed[$info[2]])) {
            $fail('Logo must be a PNG, JPG or WebP image.');
        }
        if (!is_dir(__DIR__ . '/../assets/uploads')
            && !mkdir(__DIR__ . '/../assets/uploads', 0775, true)) {
            $fail('Could not create the uploads directory.');
        }
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

    $appName  = trim((string)($_POST['app_name'] ?? ''));
    if ($appName !== '' && mb_strlen($appName) <= 80) {
        set_setting('app_name', $appName);
    }
    $schoolName = trim((string)($_POST['school_name'] ?? ''));
    if (mb_strlen($schoolName) <= 80) {
        set_setting('school_name', $schoolName);
    }
    $schoolAddress = trim((string)($_POST['school_address'] ?? ''));
    if (mb_strlen($schoolAddress) <= 120) {
        set_setting('school_address', $schoolAddress);
    }
    $schoolContact = trim((string)($_POST['school_contact'] ?? ''));
    if (mb_strlen($schoolContact) <= 120) {
        set_setting('school_contact', $schoolContact);
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

    $dayStart = trim((string)($_POST['scan_day_start'] ?? ''));
    $dayEnd   = trim((string)($_POST['scan_day_end'] ?? ''));
    if ($dayStart !== '' && $dayEnd !== '') {
        $dayStart5 = substr($dayStart, 0, 5);
        $dayEnd5   = substr($dayEnd, 0, 5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dayStart5) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dayEnd5)) {
            flash('error', 'Operating hours must be valid times (HH:MM).');
        } elseif ($dayStart5 >= $dayEnd5) {
            flash('error', 'Daily scanning start time must be earlier than end time.');
        } else {
            set_setting('scan_day_start', $dayStart5);
            set_setting('scan_day_end', $dayEnd5);
        }
    }

    log_action('SETTINGS_UPDATE', (int)$admin['id']);
    flash('success', 'Settings saved.');
    redirect('settings.php');
}

render_header('Settings', ['prefix' => '../', 'nav' => 'admin', 'active' => 'settings']);
?>

<div class="page-head">
  <h1><?= icon('settings') ?> System settings</h1>
  <p class="muted">Tuning knobs for durations and the landing page.</p>
</div>

<div class="card">
  <h3>School logo</h3>
  <p class="muted small">Shown next to “<?= e(app_name()) ?>” in the top bar. PNG, JPG or WebP — up to 2&nbsp;MB.</p>
  <?php $logo = get_setting('school_logo', ''); ?>
  <?php if ($logo !== '' && is_file(__DIR__ . '/../assets/uploads/' . $logo)): ?>
    <div class="logo-preview">
      <img src="../assets/uploads/<?= e($logo) ?>" alt="Current school logo">
      <form method="post" class="inline-form">
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
    <div class="file-upload-group">
      <input type="file" name="school_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required>
      <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?> Upload logo</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card__head">
    <h3>System parameters</h3>
    <p class="muted small">Configure durations and live feed intervals.</p>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.2rem;">
      <label>
        <span style="font-weight:600;display:block;margin-bottom:.35rem">App name</span>
        <input name="app_name" maxlength="80" value="<?= e(app_name()) ?>" style="width:100%">
        <small class="muted" style="display:block;margin-top:.3rem">This system’s own name — top bar, browser title.</small>
      </label>
      <label>
        <span style="font-weight:600;display:block;margin-bottom:.35rem">School name</span>
        <input name="school_name" maxlength="80" value="<?= e(school_name()) ?>" style="width:100%">
        <small class="muted" style="display:block;margin-top:.3rem">Your institution — landing page hero and printed sheets.</small>
      </label>
      <label>
        <span style="font-weight:600;display:block;margin-bottom:.35rem">School address</span>
        <input name="school_address" maxlength="120" value="<?= e(school_address()) ?>" style="width:100%" placeholder="e.g. Odiong, Roxas, Oriental Mindoro">
        <small class="muted" style="display:block;margin-top:.3rem">Printed under the school name on sheets.</small>
      </label>
      <label>
        <span style="font-weight:600;display:block;margin-bottom:.35rem">School contact</span>
        <input name="school_contact" maxlength="120" value="<?= e(school_contact()) ?>" style="width:100%" placeholder="e.g. Tel No. (043) 289-7056 / email@school.com">
        <small class="muted" style="display:block;margin-top:.3rem">Tel / email line printed under the address.</small>
      </label>
    </div>

    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
      <?php foreach ($intKeys as $key => [$min, $max, $label]): ?>
        <label>
          <span style="font-weight:600;display:block;margin-bottom:.35rem"><?= e($label) ?></span>
          <input type="number" name="<?= $key ?>" min="<?= $min ?>" max="<?= $max ?>" style="width:100%"
                 value="<?= get_setting_int($key, match ($key) {
                     'min_duration_minutes'   => 15,
                     'max_duration_minutes'   => 480,
                     'duration_step_minutes'  => 30,
                     default                  => 15,
                 }) ?>">
          <small class="muted" style="display:block;margin-top:.3rem">Allowed range: <?= $min ?> – <?= $max ?></small>
        </label>
      <?php endforeach; ?>
      <?php [$scanStart, $scanEnd] = get_scan_hours(); ?>
      <label>
        <span style="font-weight:600;display:block;margin-bottom:.35rem">Scanning start time</span>
        <input type="time" name="scan_day_start" value="<?= e($scanStart) ?>" style="width:100%" required>
        <small class="muted" style="display:block;margin-top:.3rem">Start of daily room scanning (default: 07:00 AM)</small>
      </label>
      <label>
        <span style="font-weight:600;display:block;margin-bottom:.35rem">Scanning end time</span>
        <input type="time" name="scan_day_end" value="<?= e($scanEnd) ?>" style="width:100%" required>
        <small class="muted" style="display:block;margin-top:.3rem">End of daily room scanning (default: 07:00 PM)</small>
      </label>
    </div>

    <div style="margin-top: 1.3rem; display: flex; justify-content: flex-end;">
      <button class="btn btn--primary" type="submit"><?= icon('circle-check') ?> Save settings</button>
    </div>
  </form>
</div>

<div class="card muted small">
  <h3 style="margin-bottom:.5rem">Parameter reference</h3>
  <ul style="margin:0;padding-left:1.2rem;display:flex;flex-direction:column;gap:.35rem">
    <li><strong>Min / max occupancy</strong> — bounds for the duration a lecturer can pick after scanning.</li>
    <li><strong>Picker step</strong> — the +/− increment in the scanner’s duration dialog.</li>
    <li><strong>Refresh</strong> — how often the public landing page reloads availability.</li>
    <li><strong>Scanning operating hours</strong> — daily window when lecturers can scan and occupy classrooms (e.g. 07:00 to 19:00). Sessions occupied before closing continue until their scheduled end time.</li>
  </ul>
</div>

<?php render_footer(); ?>
