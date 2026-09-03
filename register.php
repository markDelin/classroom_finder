<?php
declare(strict_types=1);

/**
 * Classroom Finder — lecturer self-registration (disabled).
 */

require_once __DIR__ . '/config/helpers.php';

flash('warn', 'Public registration is currently disabled. Please contact an administrator to create your account.');
redirect('login.php');

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

// Auto-determine step to show if there are server-side validation errors
$initialStep = 1;
if (!empty($errors)) {
    $errStr = implode(' ', $errors);
    if (str_contains($errStr, 'username') || str_contains($errStr, 'Password') || str_contains($errStr, 'passwords')) {
        $initialStep = 3;
    } elseif (str_contains($errStr, 'email') || str_contains($errStr, 'department')) {
        $initialStep = 2;
    }
}

render_header('Lecturer Registration', ['prefix' => '']);
?>
<div class="auth-wrap">
  <div class="card auth-card auth-card--wide">
    <p class="eyebrow">LECTURER ACCOUNT</p>
    <h1 class="auth-card__title"><?= icon('graduation-cap') ?> Register Account</h1>
    <p class="muted">An administrator reviews every registration before scanning access is granted.</p>

    <?php foreach ($errors as $er): ?>
      <div class="flash flash--error"><?= e($er) ?></div>
    <?php endforeach; ?>

    <!-- Segmented Wizard Tabs -->
    <nav class="wizard-nav" aria-label="Registration progress">
      <button type="button" class="wizard-step is-active" data-step="1" onclick="goToStep(1)">
        <span class="wizard-step__num">01</span>
        <span class="wizard-step__title">Personal</span>
      </button>
      <button type="button" class="wizard-step" data-step="2" onclick="goToStep(2)">
        <span class="wizard-step__num">02</span>
        <span class="wizard-step__title">Affiliation</span>
      </button>
      <button type="button" class="wizard-step" data-step="3" onclick="goToStep(3)">
        <span class="wizard-step__num">03</span>
        <span class="wizard-step__title">Account</span>
      </button>
    </nav>

    <form id="registerForm" method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>

      <!-- STEP 1: Personal Info -->
      <div class="form-step is-active" data-step="1">
        <div class="form-step__header">
          <h3 class="form-step__title">Step 1: Personal Details</h3>
          <p class="form-step__subtitle">Enter your official name and university staff identification.</p>
        </div>
        <div class="form-grid">
          <label class="full-width">Full name
            <input name="full_name" required maxlength="120" value="<?= $old('full_name') ?>" placeholder="Dr. Jane Doe">
          </label>
          <label class="full-width">Staff / Employee ID
            <input name="staff_id" required maxlength="40" value="<?= $old('staff_id') ?>" placeholder="STF-2024-001">
          </label>
        </div>
        <div class="form-step__actions">
          <div></div>
          <button type="button" class="btn btn--primary btn--next" onclick="goToStep(2)">
            Next: Affiliation &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 2: Affiliation & Contact -->
      <div class="form-step" data-step="2">
        <div class="form-step__header">
          <h3 class="form-step__title">Step 2: Department & Contact</h3>
          <p class="form-step__subtitle">Provide your institutional email and academic department.</p>
        </div>
        <div class="form-grid">
          <label class="full-width">Institutional email
            <input type="email" name="email" required maxlength="120" value="<?= $old('email') ?>" placeholder="j.doe@university.edu">
          </label>
          <label class="full-width">Department <small>(optional)</small>
            <input name="department" maxlength="80" placeholder="Faculty of Information Systems" value="<?= $old('department') ?>">
          </label>
        </div>
        <div class="form-step__actions">
          <button type="button" class="btn btn--outline" onclick="goToStep(1)">&larr; Back</button>
          <button type="button" class="btn btn--primary btn--next" onclick="goToStep(3)">
            Next: Credentials &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 3: Security & Credentials -->
      <div class="form-step" data-step="3">
        <div class="form-step__header">
          <h3 class="form-step__title">Step 3: Account Credentials</h3>
          <p class="form-step__subtitle">Create your account username and password.</p>
        </div>
        <div class="form-grid">
          <label class="full-width">Username
            <input name="username" required maxlength="40" pattern="[A-Za-z0-9_.]{3,40}" value="<?= $old('username') ?>" placeholder="janedoe">
          </label>
          <label>Password <small>(min. 8 characters)</small>
            <div class="password-toggle-wrapper">
              <input type="password" name="password" required minlength="8" placeholder="••••••••">
              <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
                <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
              </button>
            </div>
          </label>
          <label>Confirm password
            <div class="password-toggle-wrapper">
              <input type="password" name="confirm_password" required minlength="8" placeholder="••••••••">
              <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
                <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
              </button>
            </div>
          </label>
        </div>
        <div class="form-step__actions">
          <button type="button" class="btn btn--outline" onclick="goToStep(2)">&larr; Back</button>
          <button class="btn btn--primary" type="submit">Complete Registration &rarr;</button>
        </div>
      </div>
    </form>

    <p class="muted small" style="margin-top: 1.2rem; text-align: center;">Already approved? <a href="login.php">Log in</a>.</p>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var currentStep = 1;
  var maxVisitedStep = 1;
  var totalSteps = 3;
  var form = document.getElementById('registerForm');

  function clearError(input) {
    input.classList.remove('is-invalid');
    var wrapper = input.closest('label') || input.parentElement;
    var err = wrapper.querySelector('.field-error-msg');
    if (err) err.remove();
  }

  function showError(input, message) {
    clearError(input);
    input.classList.add('is-invalid');
    var wrapper = input.closest('label') || input.parentElement;
    var errSpan = document.createElement('span');
    errSpan.className = 'field-error-msg';
    errSpan.textContent = message;
    wrapper.appendChild(errSpan);
  }

  function validateStep(step) {
    var stepEl = document.querySelector('.form-step[data-step="' + step + '"]');
    if (!stepEl) return true;

    var valid = true;
    var firstInvalid = null;

    if (step === 1) {
      var fullName = stepEl.querySelector('input[name="full_name"]');
      if (fullName && fullName.value.trim().length < 3) {
        showError(fullName, 'Please enter your full name (at least 3 characters).');
        valid = false;
        if (!firstInvalid) firstInvalid = fullName;
      }
      var staffId = stepEl.querySelector('input[name="staff_id"]');
      if (staffId && !staffId.value.trim()) {
        showError(staffId, 'Please enter your staff or employee ID.');
        valid = false;
        if (!firstInvalid) firstInvalid = staffId;
      }
    } else if (step === 2) {
      var email = stepEl.querySelector('input[name="email"]');
      var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (email && !emailRegex.test(email.value.trim())) {
        showError(email, 'Please enter a valid institutional email address.');
        valid = false;
        if (!firstInvalid) firstInvalid = email;
      }
    } else if (step === 3) {
      var username = stepEl.querySelector('input[name="username"]');
      var userRegex = /^[a-zA-Z0-9_.]{3,40}$/;
      if (username && !userRegex.test(username.value.trim())) {
        showError(username, 'Username must be 3–40 characters (letters, numbers, dot, underscore).');
        valid = false;
        if (!firstInvalid) firstInvalid = username;
      }
      var password = stepEl.querySelector('input[name="password"]');
      if (password && password.value.length < 8) {
        showError(password, 'Password must be at least 8 characters long.');
        valid = false;
        if (!firstInvalid) firstInvalid = password;
      }
      var confirm = stepEl.querySelector('input[name="confirm_password"]');
      if (confirm && confirm.value !== (password ? password.value : '')) {
        showError(confirm, 'Passwords do not match.');
        valid = false;
        if (!firstInvalid) firstInvalid = confirm;
      }
    }

    if (firstInvalid) {
      firstInvalid.focus();
    }
    return valid;
  }

  function showStep(step) {
    if (step < 1 || step > totalSteps) return;

    // Moving forward requires validating current step
    if (step > currentStep) {
      for (var s = currentStep; s < step; s++) {
        if (!validateStep(s)) return;
      }
    }

    currentStep = step;
    if (currentStep > maxVisitedStep) {
      maxVisitedStep = currentStep;
    }

    // Toggle visible step
    document.querySelectorAll('.form-step').forEach(function (el) {
      var s = parseInt(el.dataset.step, 10);
      el.classList.toggle('is-active', s === currentStep);
    });

    // Update wizard step tabs
    document.querySelectorAll('.wizard-step').forEach(function (el) {
      var s = parseInt(el.dataset.step, 10);
      el.classList.toggle('is-active', s === currentStep);
      el.classList.toggle('is-completed', s < currentStep);
      var numSpan = el.querySelector('.wizard-step__num');
      if (numSpan) {
        numSpan.textContent = (s < currentStep) ? '✓' : '0' + s;
      }
    });

    // Focus first field in new step
    var newStepEl = document.querySelector('.form-step.is-active');
    if (newStepEl) {
      var firstInput = newStepEl.querySelector('input');
      if (firstInput) firstInput.focus();
    }
  }

  window.goToStep = showStep;

  // Real-time error clearing on typing
  if (form) {
    form.addEventListener('input', function (e) {
      if (e.target && e.target.tagName === 'INPUT') {
        clearError(e.target);
      }
    });

    // Submit validation
    form.addEventListener('submit', function (e) {
      for (var s = 1; s <= totalSteps; s++) {
        if (!validateStep(s)) {
          e.preventDefault();
          showStep(s);
          return false;
        }
      }
    });

    // Enter key navigation
    form.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
        if (currentStep < totalSteps) {
          e.preventDefault();
          showStep(currentStep + 1);
        }
      }
    });
  }

  // Set initial step (shows relevant step on server error reload)
  showStep(<?= (int)$initialStep ?>);
});
</script>
<?php render_footer(); ?>
