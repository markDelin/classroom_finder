<?php
declare(strict_types=1);

/**
 * Classroom Finder — user management.
 *
 * Approve / reject / suspend / reactivate accounts, create lecturer or admin
 * accounts directly, and reset passwords. Guards: an admin can never suspend
 * or demote themselves, and the last active admin cannot be suspended.
 */

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();
$back  = $_POST['back'] ?? 'users.php';
$fail  = function (string $msg) use ($back): never {
    flash('error', $msg);
    redirect($back);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        $fail('Session expired — please try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $target = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // ---------- account status changes ----------
    if (in_array($action, ['approve', 'reject', 'suspend', 'reactivate'], true)) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$target]);
        $u = $st->fetch();
        if (!$u) {
            $fail('User not found.');
        }

        $newStatus = match ($action) {
            'approve'    => 'approved',
            'reject'     => 'rejected',
            'suspend'    => 'suspended',
            'reactivate' => 'approved',
        };

        if ($action === 'suspend') {
            if ((int)$u['id'] === (int)$admin['id']) {
                $fail('You cannot suspend your own account.');
            }
            if ($u['role'] === 'admin') {
                $n = db()->query(
                    "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND account_status = 'approved'"
                )->fetch()['n'];
                if ((int)$n <= 1) {
                    $fail('Cannot suspend the last active administrator.');
                }
            }
        }
        if ($action === 'reject' && $u['account_status'] === 'approved') {
            $fail('Use "Suspend" for accounts that are already approved.');
        }

        db()->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$newStatus, $target]);
        log_action('USER_' . strtoupper($action), (int)$admin['id'], null,
            ucfirst($u['role']) . ' "' . $u['username'] . '" -> ' . $newStatus);
        flash('success', ucfirst($u['role']) . ' “' . $u['full_name'] . '” is now ' . $newStatus . '.');
        redirect($back);
    }

    // ---------- create account ----------
    if ($action === 'create') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $staffId  = trim((string)($_POST['staff_id'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $dept     = trim((string)($_POST['department'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $role     = ($_POST['role'] ?? 'lecturer') === 'admin' ? 'admin' : 'lecturer';
        $password = (string)($_POST['password'] ?? '');

        if (mb_strlen($fullName) < 3)                   $fail('Enter the full name.');
        if ($staffId === '')                            $fail('Enter a staff ID.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Enter a valid email.');
        if (!preg_match('/^[a-zA-Z0-9_.]{3,40}$/', $username)) $fail('Invalid username (3–40 chars: letters, numbers, dot, underscore).');
        if (strlen($password) < 8)                      $fail('Password must be at least 8 characters.');

        $st = db()->prepare('SELECT
               (SELECT COUNT(*) FROM users WHERE username = ?) AS u,
               (SELECT COUNT(*) FROM users WHERE email = ?)    AS e,
               (SELECT COUNT(*) FROM users WHERE staff_id = ?) AS s');
        $st->execute([$username, $email, $staffId]);
        $dup = $st->fetch();
        if ((int)$dup['u']) $fail('Username already taken.');
        if ((int)$dup['e']) $fail('Email already registered.');
        if ((int)$dup['s']) $fail('Staff ID already registered.');

        db()->prepare(
            'INSERT INTO users (full_name, staff_id, email, username, password, department, role, account_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'approved\')'
        )->execute([$fullName, $staffId, $email, $username, password_hash($password, PASSWORD_DEFAULT), $dept ?: null, $role]);

        log_action('USER_CREATE', (int)$admin['id'], null, 'Created ' . $role . ': ' . $username);
        flash('success', ucfirst($role) . ' account created for ' . $fullName . '.');
        redirect($back);
    }

    // ---------- reset password ----------
    if ($action === 'reset_password') {
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) {
            $fail('New password must be at least 8 characters.');
        }
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $target]);
        log_action('USER_RESET_PASSWORD', (int)$admin['id'], null, 'Password reset for user #' . $target);
        flash('success', 'Password updated.');
        redirect($back);
    }

    // ---------- delete account ----------
    // Hard delete: sessions cascade away (rooms free up instantly), their
    // reservations are unlinked (user_id -> NULL) and the activity log keeps
    // its rows as the audit trail.
    if ($action === 'delete') {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$target]);
        $u = $st->fetch();
        if (!$u) {
            $fail('User not found.');
        }
        if ((int)$u['id'] === (int)$admin['id']) {
            $fail('You cannot delete your own account.');
        }
        if ($u['role'] === 'admin') {
            $n = db()->query(
                "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND account_status = 'approved'"
            )->fetch()['n'];
            if ((int)$n <= 1) {
                $fail('Cannot delete the last active administrator.');
            }
        }

        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$target]);
        log_action('USER_DELETE', (int)$admin['id'], null,
            'Deleted ' . ucfirst($u['role']) . ' "' . $u['username'] . '"');
        flash('success', ucfirst($u['role']) . ' “' . $u['full_name'] . '” was deleted.');
        redirect($back);
    }

    // ---------- edit account details ----------
    if ($action === 'update') {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$target]);
        $u = $st->fetch();
        if (!$u) {
            $fail('User not found.');
        }

        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $staffId  = trim((string)($_POST['staff_id'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $dept     = trim((string)($_POST['department'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $role     = ($_POST['role'] ?? $u['role']) === 'admin' ? 'admin' : 'lecturer';

        if (mb_strlen($fullName) < 3)                   $fail('Enter the full name.');
        if ($staffId === '')                            $fail('Enter a staff ID.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Enter a valid email.');
        if (!preg_match('/^[a-zA-Z0-9_.]{3,40}$/', $username)) $fail('Invalid username (3–40 chars: letters, numbers, dot, underscore).');

        // uniqueness — but only against OTHER accounts
        $st = db()->prepare('SELECT
               (SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?) AS u,
               (SELECT COUNT(*) FROM users WHERE email    = ? AND id <> ?) AS e,
               (SELECT COUNT(*) FROM users WHERE staff_id = ? AND id <> ?) AS s');
        $st->execute([$username, $target, $email, $target, $staffId, $target]);
        $dup = $st->fetch();
        if ((int)$dup['u']) $fail('Username already taken.');
        if ((int)$dup['e']) $fail('Email already registered.');
        if ((int)$dup['s']) $fail('Staff ID already registered.');

        // role guards mirror suspend/delete: never lock yourself or the campus out
        if ((int)$u['id'] === (int)$admin['id'] && $role !== 'admin') {
            $fail('You cannot change your own role.');
        }
        if ($u['role'] === 'admin' && $role === 'lecturer') {
            $n = db()->query(
                "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND account_status = 'approved'"
            )->fetch()['n'];
            if ((int)$n <= 1) {
                $fail('Cannot demote the last active administrator.');
            }
        }

        db()->prepare(
            'UPDATE users SET full_name = ?, staff_id = ?, email = ?, department = ?, username = ?, role = ?
             WHERE id = ?'
        )->execute([$fullName, $staffId, $email, $dept ?: null, $username, $role, $target]);

        log_action('USER_UPDATE', (int)$admin['id'], null,
            'Updated ' . $role . ' "' . $username . '" (#' . $target . ')');
        flash('success', 'Account details updated for ' . $fullName . '.');
        redirect($back);
    }

    $fail('Unknown action.');
}

// ---------------- list + filters ----------------
$q       = trim((string)($_GET['q'] ?? ''));
$statusF = (string)($_GET['status'] ?? '');

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR staff_id LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if (in_array($statusF, ['pending', 'approved', 'rejected', 'suspended'], true)) {
    $where[]  = 'account_status = ?';
    $params[] = $statusF;
}
$whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';

$st = db()->prepare('SELECT COUNT(*) AS n FROM users WHERE 1=1' . $whereSql);
$st->execute($params);
$totalUsers = (int)$st->fetch()['n'];
$pp         = page_params($totalUsers, (int)($_GET['page'] ?? 1));

$st = db()->prepare(
    'SELECT * FROM users WHERE 1=1' . $whereSql .
    ' ORDER BY FIELD(account_status, \'pending\',\'suspended\',\'approved\',\'rejected\'), role DESC, full_name' .
    ' LIMIT ' . $pp['limit'] . ' OFFSET ' . $pp['offset']
);
$st->execute($params);
$users = $st->fetchAll();

render_header('Users', ['prefix' => '../', 'nav' => 'admin', 'active' => 'users']);
?>

<div class="page-head">
  <h1><?= icon('users') ?> Users</h1>
  <button class="btn btn--primary btn--sm" type="button"
          data-modal-form="#userForm"
          data-title="Create an account"
          data-confirm-text="Create account"><?= icon('user-plus') ?> Add user</button>
  <p class="muted">Approve new lecturers, manage, delete accounts and reset passwords.</p>
</div>

<form method="get" class="filter-row">
  <input type="search" name="q" placeholder="Search name, username, email, staff ID…" value="<?= e($q) ?>">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'suspended' => 'Suspended', 'rejected' => 'Rejected'] as $k => $lbl): ?>
      <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">

  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>User</th><th>Role &amp; Status</th><th>Contact</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td class="cell-main" data-label="Name">
          <strong><?= e($u['full_name']) ?></strong> <span class="muted small">@<?= e($u['username']) ?></span>
          <?php if ($u['department']): ?><span class="muted small"> · <?= e($u['department']) ?></span><?php endif; ?>
        </td>
        <td class="cell-status nowrap" data-label="Role & Status">
          <span class="pill pill--<?= $u['role'] === 'admin' ? 'admin' : 'lecturer' ?>"><?= e($u['role']) ?></span>
          <?php $badge = ['pending' => 'warn', 'approved' => 'ok', 'suspended' => 'danger', 'rejected' => 'off']; ?>
          <span class="pill pill--<?= $badge[$u['account_status']] ?>"><?= e($u['account_status']) ?></span>
        </td>
        <td class="cell-sub small" data-label="Contact">
          <?= e($u['email']) ?>
          <span class="muted small"> · ID: <?= e($u['staff_id']) ?></span>
        </td>
        <td data-label="Actions">
          <div class="actions-cell" style="justify-content:flex-end">
          <?php if ($u['account_status'] === 'pending'): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
              <button class="btn btn--ok btn--sm" type="submit" title="Approve"><?= icon('circle-check') ?> <span class="btn-text">Approve</span></button></form>
            <form method="post" class="inline-form" data-confirm="Reject this registration?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
              <button class="btn btn--danger btn--sm" type="submit" title="Reject"><?= icon('ban') ?> <span class="btn-text">Reject</span></button></form>
          <?php elseif ($u['account_status'] === 'suspended'): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
              <button class="btn btn--ok btn--sm" type="submit" title="Reactivate"><?= icon('refresh-cw') ?> <span class="btn-text">Reactivate</span></button></form>
          <?php endif; ?>

          <?php $editPrefill = e(json_encode([
              'action' => 'update', 'id' => (int)$u['id'],
              'full_name' => $u['full_name'], 'staff_id' => $u['staff_id'],
              'email' => $u['email'], 'department' => (string)($u['department'] ?? ''),
              'username' => $u['username'], 'role' => $u['role'],
          ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>
          <button class="btn btn--ghost btn--sm" type="button"
                  data-modal-form="#userEditForm"
                  data-title="Edit <?= e($u['full_name']) ?>"
                  data-confirm-text="Save changes"
                  title="Edit user"
                  data-prefill='<?= $editPrefill ?>'><?= icon('pencil') ?> <span class="btn-text">Edit</span></button>
          <details class="mini-details">
            <summary class="btn btn--ghost btn--sm" title="Reset password"><?= icon('lock') ?> <span class="btn-text">Reset pw</span></summary>
            <div class="mini-menu">
              <form method="post" class="reset-form" data-confirm="Set a new password for this user?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                <div class="password-toggle-wrapper">
                  <input type="password" name="password" placeholder="New password (min 8)" minlength="8" required>
                  <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
                    <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
                  </button>
                </div>
                <button class="btn btn--sm" type="submit">Reset</button></form>
            </div>
          </details>
          <?php if (!((int)$u['id'] === (int)$admin['id'])): ?>
            <?php if ($u['account_status'] === 'approved'): ?>
              <form method="post" class="inline-form" data-confirm="Suspend this account? They will be logged out and blocked."><?= csrf_field() ?>
                <input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                <button class="btn btn--ghost btn--sm" type="submit" title="Suspend user"><?= icon('ban') ?> <span class="btn-text">Suspend</span></button></form>
            <?php endif; ?>
            <form method="post" class="inline-form"
                  data-confirm="Permanently delete <?= e($u['full_name']) ?>? Their sessions end immediately and their reservations are unlinked. This cannot be undone."><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
              <button class="btn btn--ghost-danger btn--sm" type="submit" title="Delete user"><?= icon('trash-2') ?> <span class="btn-text">Delete</span></button></form>
          <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (!$users): ?><p class="muted">No users match.</p><?php endif; ?>
  <?= page_nav($totalUsers, $pp['page']) ?>
</div>

  <!-- shown as a SweetAlert2 modal by admin-modals.js -->
  <form method="post" class="form-grid" id="userForm" hidden style="text-align:left">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label class="full-width">Full name <input name="full_name" required maxlength="120" placeholder="e.g.John Doe"></label>
    <label>Staff ID <input name="staff_id" required maxlength="40" placeholder="STF-1024"></label>
    <label>Department <input name="department" maxlength="80" placeholder="BS Information Systems"></label>
    <label class="full-width">Email <input type="email" name="email" required maxlength="120" placeholder="delinrf24000987@clarendoncollege.edu.ph"></label>
    <label>Username <input name="username" required pattern="[A-Za-z0-9_.]{3,40}" maxlength="40" placeholder="Mck"></label>
    <label>Password
      <div class="password-toggle-wrapper">
        <input type="password" name="password" required minlength="8" placeholder="••••••••">
        <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password">
          <?= icon('eye', 'icon-eye') ?><?= icon('eye-off', 'icon-eye-off') ?>
        </button>
      </div>
    </label>
    <label class="full-width">Role
      <select name="role">
        <option value="lecturer">Lecturer</option>
        <option value="admin">Administrator</option>
      </select>
    </label>
  </form>

<!-- shown as a SweetAlert2 modal by admin-modals.js (Edit buttons prefill it) -->
<form method="post" class="form-grid" id="userEditForm" hidden style="text-align:left">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="id" value="">
  <label class="full-width">Full name <input name="full_name" required maxlength="120"></label>
  <label>Staff ID <input name="staff_id" required maxlength="40"></label>
  <label>Department <input name="department" maxlength="80"></label>
  <label class="full-width">Email <input type="email" name="email" required maxlength="120"></label>
  <label>Username <input name="username" required pattern="[A-Za-z0-9_.]{3,40}" maxlength="40"></label>
  <label>Role
    <select name="role">
      <option value="lecturer">Lecturer</option>
      <option value="admin">Administrator</option>
    </select>
  </label>
  <p class="muted small" style="grid-column:1/-1;margin:0">Passwords are changed separately via Manage → Reset password.</p>
</form>

<?php render_footer(['assets/js/admin-modals.js']); ?>
