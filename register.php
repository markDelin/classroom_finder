<?php
declare(strict_types=1);

/**
 * Classroom Finder — lecturer self-registration.
 *
 * New accounts start as Role=Lecturer / Status=Pending and cannot use any
 * scanning feature until an administrator approves them.
 */

require_once __DIR__ . '/config/helpers.php';

$errors = [];
$old    = fn(string $k) => e((string)($_POST[$k] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $errors[] = 'Session expired — please submit the form again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $staffId  = trim((string)($_POST['staff_id'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $dept     = trim((string)($_POST['department'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if (mb_strlen($fullName) < 3)                    $errors[] = 'Enter your full name.';
        if ($staffId === '')                             $errors[] = 'Enter your staff / employee ID.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Enter a valid institutional email.';
        if (!preg_match('/^[a-zA-Z0-9_.]{3,40}$/', $username))
                                                         $errors[] = 'Username must be 3–40 characters (letters, numbers, dot, underscore).';
        if (strlen($password) < 8)                       $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)                      $errors[] = 'Passwords do not match.';

        if (!$errors) {
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
                'INSERT INTO users (full_name, staff_id, email, username, password, department, role, account_status)
                 VALUES (?, ?, ?, ?, ?, ?, \'lecturer\', \'pending\')'
            )->execute([
                $fullName,
                $staffId,
                $email,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $dept !== '' ? $dept : null,
            ]);
            log_action('REGISTER', (int)db()->lastInsertId(), null, 'Lecturer registered: ' . $username);
            flash('success', 'Registration received! You can log in once an administrator approves your account.');
            redirect('login.php');
        }
    }
}

render_header('Lecturer Registration', ['prefix' => '']);
?>
<div class="auth-wrap">
  <div class="card auth-card auth-card--wide">
    <h1 class="auth-card__title"><?= icon('graduation-cap') ?> Lecturer Registration</h1>
    <p class="muted">Create your account. An administrator reviews every registration before scanning is enabled.</p>

    <?php foreach ($errors as $er): ?>
      <div class="flash flash--error"><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label>Full name
          <input name="full_name" required maxlength="120" value="<?= $old('full_name') ?>">
        </label>
        <label>Staff / Employee ID
          <input name="staff_id" required maxlength="40" value="<?= $old('staff_id') ?>">
        </label>
        <label>Institutional email
          <input type="email" name="email" required maxlength="120" value="<?= $old('email') ?>">
        </label>
        <label>Department
          <input name="department" maxlength="80" placeholder="e.g. Bachelor of Science in Information Systems" value="<?= $old('department') ?>">
        </label>
        <label>Username
          <input name="username" required maxlength="40" pattern="[A-Za-z0-9_.]{3,40}" value="<?= $old('username') ?>">
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
      </div>
      <button class="btn btn--primary btn--block" type="submit">Create Account</button>
    </form>

    <p class="muted small">Already approved? <a href="login.php">Log in</a>.</p>
  </div>
</div>
<?php render_footer(); ?>
