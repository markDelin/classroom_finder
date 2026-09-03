<?php
declare(strict_types=1);

/**
 * Classroom Finder — login page.
 */

require_once __DIR__ . '/config/helpers.php';

// First-time setup: if no account exists at all, point visitors at setup.
$needsSetup = false;
try {
    $needsSetup = (int)db()->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'] === 0;
} catch (Throwable) {
    // database not imported yet — the connection helper already explained it
}

// Already signed in? Straight to the right home — admins to their dashboard,
// lecturers straight to the scanner (their most frequent action).
if ($u = current_user()) {
    require_once __DIR__ . '/auth/auth_check.php';
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'lecturer/scanner.php');
}

render_header('Log in', ['prefix' => '']);
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h1 class="auth-card__title"><?= icon('lock') ?> Log in</h1>
    <p class="muted">Lecturers and administrators sign in here. Students don’t need an account —
      the <a href="index.php"><?= e(app_name()) ?></a> is open to everyone.</p>

    <?php if ($needsSetup): ?>
      <div class="flash flash--warn">
        No administrator exists yet. <a href="setup.php"><strong>Run initial setup →</strong></a>
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
