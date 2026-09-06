<?php
declare(strict_types=1);

/**
 * Classroom Finder — first-time setupS.
 *
 * Only reachable while NO user exists at all. The very first visitor creates
 * the initial administrator; afterwards this page permanently redirects away,
 * so nobody can self-register as an admin later.
 */

require_once __DIR__ . '/config/helpers.php';

// Lock check: once installed or any user exists, close setup forever.
if (is_file(__DIR__ . '/installed.lock')) {
    redirect('index.php');
}
$st  = db()->query('SELECT COUNT(*) AS n FROM users');
$any = (int)($st->fetch()['n'] ?? 0);
if ($any > 0) {
    @file_put_contents(__DIR__ . '/installed.lock', date('c'));
    redirect('index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $errors[] = 'Session expired — please submit the form again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $staffId  = trim((string)($_POST['staff_id'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if (mb_strlen($fullName) < 3)                       $errors[] = 'Enter the administrator’s full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = 'Enter a valid email address.';
        if ($staffId === '')                                $errors[] = 'Enter a staff / employee ID.';
        if (!preg_match('/^[a-zA-Z0-9_.]{3,40}$/', $username))
                                                            $errors[] = 'Username must be 3–40 characters (letters, numbers, dot, underscore).';
        if (strlen($password) < 8)                          $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)                         $errors[] = 'Passwords do not match.';

        if (!$errors) {
            // unique checks
            $st = db()->prepare('SELECT
                   (SELECT COUNT(*) FROM users WHERE username = ?) AS u,
                   (SELECT COUNT(*) FROM users WHERE email = ?)    AS e,
                   (SELECT COUNT(*) FROM users WHERE staff_id = ?) AS s');
            $st->execute([$username, $email, $staffId]);
            $dup = $st->fetch();
            if ((int)$dup['u']) $errors[] = 'That username is already taken.';
            if ((int)$dup['e']) $errors[] = 'That email is already registered.';
            if ((int)$dup['s']) $errors[] = 'That staff ID is already registered.';
        }

        if (!$errors) {
            db()->prepare(
                'INSERT INTO users (full_name, staff_id, email, username, password, role, account_status)
                 VALUES (?, ?, ?, ?, ?, \'admin\', \'approved\')'
            )->execute([$fullName, $staffId, $email, $username, password_hash($password, PASSWORD_DEFAULT)]);

            $id = (int)db()->lastInsertId();
            log_action('SETUP_ADMIN', $id, null, 'Initial administrator created: ' . $username);

            @file_put_contents(__DIR__ . '/installed.lock', date('c'));
            session_regenerate_id(true);
            $_SESSION['user_id'] = $id;
            flash('success', 'Welcome! Your administrator account is ready.');
            redirect('admin/dashboard.php');
        }
    }
}

render_header('Initial System Setup', ['prefix' => '']);
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h1 class="auth-card__title"><?= icon('settings') ?> Initial System Setup</h1>
    <p class="muted">No administrator account exists yet. Create the first one to start using Classroom Finder.</p>

    <?php foreach ($errors as $er): ?>
      <div class="flash flash--error"><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Full name
        <input name="full_name" required maxlength="120" value="<?= e($_POST['full_name'] ?? '') ?>">
      </label>
      <label>Email
        <input type="email" name="email" required maxlength="120" value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <label>Staff / Employee ID
        <input name="staff_id" required maxlength="40" value="<?= e($_POST['staff_id'] ?? '') ?>">
      </label>
      <label>Username
        <input name="username" required maxlength="40" pattern="[A-Za-z0-9_.]{3,40}" value="<?= e($_POST['username'] ?? '') ?>">
      </label>
      <label>Password <small>(min. 8 characters)</small>
        <div class="password-toggle-wrapper">
          <input type="password" name="password" required minlength="8">
          <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
            <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
          </button>
        </div>
      </label>
      <label>Confirm password
        <div class="password-toggle-wrapper">
          <input type="password" name="confirm_password" required minlength="8">
          <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
            <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
          </button>
        </div>
      </label>
      <button class="btn btn--primary btn--block" type="submit">Create Administrator</button>
    </form>
    <p class="muted small">After setup this page locks automatically — new administrators can only be created from the Admin panel.</p>
  </div>
</div>
<?php render_footer(); ?>
