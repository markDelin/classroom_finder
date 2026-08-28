<?php
declare(strict_types=1);

/**
 * Classroom Finder — change account password.
 *
 * Allows any authenticated user (admin or lecturer) to update their account password.
 * Requires verifying the current password and confirming the new password.
 */

require_once __DIR__ . '/auth/auth_check.php';

$user = require_login();

// Re-fetch user record from database to get fresh password hash & status
$st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$st->execute([(int)$user['id']]);
$userDb = $st->fetch();

if (!$userDb || $userDb['account_status'] !== 'approved') {
    flash('error', 'Account is inactive or not found.');
    redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'lecturer/scanner.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $errors[] = 'Session expired — please try again.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '') {
            $errors[] = 'Current password is required.';
        } elseif (!password_verify($currentPassword, (string)$userDb['password'])) {
            $errors[] = 'Current password is incorrect.';
        }

        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        }

        if (empty($errors)) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([$newHash, (int)$user['id']]);

            log_action('CHANGE_PASSWORD', (int)$user['id'], null, 'User changed their account password');

            flash('success', 'Your password has been changed successfully.');
            redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'lecturer/dashboard.php');
        }
    }
}

$navRole = $user['role'] === 'admin' ? 'admin' : 'lecturer';
render_header('Change Password', [
    'prefix' => '',
    'nav'    => $navRole,
    'active' => 'change_password',
]);
?>

<div class="page-head">
  <h1><?= icon('key-round') ?> Change Password</h1>
  <p class="muted">Update your account login password.</p>
</div>

<div class="card auth-card" style="max-width: 28rem; margin: 0 auto;">
  <?php foreach ($errors as $err): ?>
    <div class="flash flash--error">
      <?= icon('triangle-alert') ?> <span><?= e($err) ?></span>
    </div>
  <?php endforeach; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>

    <div class="form-group">
      <label for="current_password">Current Password</label>
      <div class="password-toggle-wrapper">
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        <button type="button" class="password-toggle-btn" aria-label="Toggle password visibility">
          <?= icon('eye', 'icon-eye') ?>
          <?= icon('eye-off', 'icon-eye-off') ?>
        </button>
      </div>
    </div>

    <div class="form-group">
      <label for="new_password">New Password</label>
      <div class="password-toggle-wrapper">
        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
        <button type="button" class="password-toggle-btn" aria-label="Toggle password visibility">
          <?= icon('eye', 'icon-eye') ?>
          <?= icon('eye-off', 'icon-eye-off') ?>
        </button>
      </div>
      <span class="form-hint">Minimum 8 characters.</span>
    </div>

    <div class="form-group">
      <label for="confirm_password">Confirm New Password</label>
      <div class="password-toggle-wrapper">
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
        <button type="button" class="password-toggle-btn" aria-label="Toggle password visibility">
          <?= icon('eye', 'icon-eye') ?>
          <?= icon('eye-off', 'icon-eye-off') ?>
        </button>
      </div>
    </div>

    <div class="form-actions">
      <a href="<?= $user['role'] === 'admin' ? 'admin/dashboard.php' : 'lecturer/dashboard.php' ?>" class="btn btn--ghost">Cancel</a>
      <button type="submit" class="btn btn--primary"><?= icon('check') ?> Save New Password</button>
    </div>
  </form>
</div>

<?php render_footer(['prefix' => '']); ?>
