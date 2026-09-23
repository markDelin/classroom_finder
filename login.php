<?php
declare(strict_types=1);

require_once __DIR__ . '/config/helpers.php';

$needsSetup = false;
try {
    $needsSetup = (int)db()->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'] === 0;
} catch (Throwable) {
}

if ($u = current_user()) {
    require_once __DIR__ . '/auth/auth_check.php';
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'lecturer/scanner.php');
}

$flashes = take_flashes();
render_header('Log in', ['prefix' => '']);
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h1 class="auth-card__title"><?= icon('lock') ?> Log in</h1>
    <p class="muted">Lecturers and administrators sign in here. Students don’t need an account —
      the <a href="index.php"><?= e(app_name()) ?></a> is open to everyone.</p>

    <div class="card-flashes">
      <?php foreach ($flashes as $f): ?>
        <div class="flash flash--<?= e($f['t']) ?>">
          <?= icon($f['t'] === 'success' ? 'circle-check' : ($f['t'] === 'error' ? 'triangle-alert' : 'info')) ?>
          <span><?= e($f['m']) ?></span>
          <button type="button" class="flash__close" aria-label="Dismiss">&times;</button>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($needsSetup): ?>
      <div class="flash flash--warn">
        <?= icon('triangle-alert') ?>
        <span>No administrator exists yet. <a href="setup.php"><strong>Run initial setup</strong></a></span>
        <button type="button" class="flash__close" aria-label="Dismiss">&times;</button>
      </div>
    <?php endif; ?>

    <form method="post" action="auth/login_process.php">
      <?= csrf_field() ?>
      <label>Username
        <input name="username" required autofocus autocomplete="username" maxlength="40">
      </label>
      <label>Password
        <div class="password-toggle-wrapper">
          <input type="password" name="password" required autocomplete="current-password">
          <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
            <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
          </button>
        </div>
      </label>
      <button class="btn btn--primary btn--block" type="submit">Log in</button>
    </form>

    <p class="muted small">Need an account? Contact an administrator to create one for you.</p>
  </div>
</div>
<?php render_footer(); ?>
