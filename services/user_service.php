<?php
declare(strict_types=1);

/**
 * Classroom Finder — User Service
 *
 * Encapsulates authentication, credential updates, account status changes,
 * user CRUD, and security validations.
 *
 * @package ClassroomFinder\Services
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Retrieve user record by primary key ID.
 *
 * @param int $id User ID.
 * @return array<string, mixed>|null
 */
function user_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $user = $st->fetch();
    return $user ?: null;
}

/**
 * Retrieve user record by unique username.
 *
 * @param string $username Username string.
 * @return array<string, mixed>|null
 */
function user_get_by_username(string $username): ?array
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();
    return $user ?: null;
}

/**
 * Authenticate user credentials with brute-force rate-limiting and timing-attack mitigation.
 *
 * @param string $username Provided username.
 * @param string $password Provided plaintext password.
 * @return array<string, mixed> Result array ['ok' => bool, 'user' => ?array, 'error' => string]
 */
function user_authenticate(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your username and password.', 'user' => null];
    }

    // Rate-limiting: 5 failed attempts in 10 minutes locks out
    $recentFailsStmt = db()->prepare(
        "SELECT COUNT(*) AS fail_count FROM activity_logs
         WHERE action = 'LOGIN_FAILED'
           AND details = ?
           AND timestamp >= (NOW() - INTERVAL 10 MINUTE)"
    );
    $recentFailsStmt->execute(['Username: ' . $username]);
    $failCount = (int)($recentFailsStmt->fetch()['fail_count'] ?? 0);
    if ($failCount >= 5) {
        if (function_exists('log_action')) {
            log_action('LOGIN_LOCKOUT', null, null, 'Username: ' . $username);
        }
        return [
            'ok' => false,
            'error' => 'Too many failed login attempts. Please wait 10 minutes before trying again.',
            'user' => null,
        ];
    }

    $st = db()->prepare(
        'SELECT id, password, role, account_status, full_name, username, department FROM users WHERE username = ? LIMIT 1'
    );
    $st->execute([$username]);
    $user = $st->fetch();

    $hash  = $user['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $valid = password_verify($password, (string)$hash) && $user !== null;

    usleep(random_int(20000, 60000));

    if (!$valid) {
        if (function_exists('log_action')) {
            log_action('LOGIN_FAILED', isset($user['id']) ? (int)$user['id'] : null, null, 'Username: ' . $username);
        }
        return ['ok' => false, 'error' => 'Incorrect username or password.', 'user' => null];
    }

    if ($user['account_status'] === 'pending') {
        if (function_exists('log_action')) {
            log_action('LOGIN_BLOCKED_PENDING', (int)$user['id']);
        }
        return [
            'ok' => false,
            'error' => 'Your account is awaiting administrator approval. You will be able to log in once approved.',
            'user' => null,
        ];
    }

    if (in_array($user['account_status'], ['rejected', 'suspended'], true)) {
        if (function_exists('log_action')) {
            log_action('LOGIN_BLOCKED_' . strtoupper((string)$user['account_status']), (int)$user['id']);
        }
        $msg = $user['account_status'] === 'suspended'
            ? 'Your account has been suspended. Please contact the administrator.'
            : 'Your registration request was not approved.';
        return ['ok' => false, 'error' => $msg, 'user' => null];
    }

    return ['ok' => true, 'user' => $user, 'error' => ''];
}

/**
 * Update user password with length validation and hashing.
 *
 * @param int $userId Target user ID.
 * @param string $newPassword New plaintext password.
 * @param int|null $actorUserId User performing the change (for audit log).
 * @return array<string, mixed> Result array ['ok' => bool, 'message' => string, 'error' => string]
 */
function user_update_password(int $userId, string $newPassword, ?int $actorUserId = null): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user ID.'];
    }
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters long.'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $st = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
    $st->execute([$hash, $userId]);

    if (function_exists('log_action')) {
        $actor = $actorUserId ?? $userId;
        log_action('CHANGE_PASSWORD', $actor, null, "Password updated for user #{$userId}");
    }

    return ['ok' => true, 'message' => 'Password updated successfully.'];
}

/**
 * Update account status (approve, reject, suspend, reactivate).
 *
 * @param int $targetUserId User ID to modify.
 * @param string $action One of 'approve', 'reject', 'suspend', 'reactivate'.
 * @param int $adminId Acting admin ID.
 * @return array<string, mixed>
 */
function user_set_status(int $targetUserId, string $action, int $adminId): array
{
    $u = user_get($targetUserId);
    if (!$u) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $newStatus = match ($action) {
        'approve'    => 'approved',
        'reject'     => 'rejected',
        'suspend'    => 'suspended',
        'reactivate' => 'approved',
        default      => null,
    };

    if ($newStatus === null) {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }

    if ($action === 'suspend') {
        if ($targetUserId === $adminId) {
            return ['ok' => false, 'error' => 'You cannot suspend your own account.'];
        }
        if ($u['role'] === 'admin') {
            $n = db()->query(
                "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND account_status = 'approved'"
            )->fetch()['n'];
            if ((int)$n <= 1) {
                return ['ok' => false, 'error' => 'Cannot suspend the last active administrator.'];
            }
        }
    }

    if ($action === 'reject' && $u['account_status'] === 'approved') {
        return ['ok' => false, 'error' => 'Use "Suspend" for accounts that are already approved.'];
    }

    db()->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$newStatus, $targetUserId]);

    if (function_exists('log_action')) {
        log_action(
            'USER_' . strtoupper($action),
            $adminId,
            null,
            ucfirst($u['role']) . ' "' . $u['username'] . '" -> ' . $newStatus
        );
    }

    return [
        'ok' => true,
        'message' => ucfirst($u['role']) . " “{$u['full_name']}” is now {$newStatus}.",
        'new_status' => $newStatus,
    ];
}

/**
 * Create a new user account with duplicate checks and hashing.
 *
 * @param array<string, mixed> $data Account fields (full_name, staff_id, email, department, username, role, password).
 * @param int $adminId Acting admin ID.
 * @return array<string, mixed>
 */
function user_create(array $data, int $adminId): array
{
    $fullName = trim((string)($data['full_name'] ?? ''));
    $staffId  = trim((string)($data['staff_id'] ?? ''));
    $email    = trim((string)($data['email'] ?? ''));
    $dept     = trim((string)($data['department'] ?? ''));
    $username = trim((string)($data['username'] ?? ''));
    $role     = ($data['role'] ?? 'lecturer') === 'admin' ? 'admin' : 'lecturer';
    $password = (string)($data['password'] ?? '');

    if (mb_strlen($fullName) < 3) {
        return ['ok' => false, 'error' => 'Enter the full name.'];
    }
    if ($staffId === '') {
        return ['ok' => false, 'error' => 'Enter a staff ID.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_.]{3,40}$/', $username)) {
        return ['ok' => false, 'error' => 'Invalid username (3–40 chars: letters, numbers, dot, underscore).'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $st = db()->prepare(
        'SELECT
           (SELECT COUNT(*) FROM users WHERE username = ?) AS u,
           (SELECT COUNT(*) FROM users WHERE email = ?)    AS e,
           (SELECT COUNT(*) FROM users WHERE staff_id = ?) AS s'
    );
    $st->execute([$username, $email, $staffId]);
    $dup = $st->fetch();
    if ((int)$dup['u']) {
        return ['ok' => false, 'error' => 'Username already taken.'];
    }
    if ((int)$dup['e']) {
        return ['ok' => false, 'error' => 'Email already registered.'];
    }
    if ((int)$dup['s']) {
        return ['ok' => false, 'error' => 'Staff ID already registered.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare(
        'INSERT INTO users (full_name, staff_id, email, username, password, department, role, account_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'approved\')'
    )->execute([$fullName, $staffId, $email, $username, $hash, $dept ?: null, $role]);
    $newId = (int)db()->lastInsertId();

    if (function_exists('log_action')) {
        log_action('USER_CREATE', $adminId, null, "Created {$role}: {$username}");
    }

    return ['ok' => true, 'message' => ucfirst($role) . " account created for {$fullName}.", 'id' => $newId];
}

/**
 * Delete a user account with safety checks (prevent self-deletion and last admin deletion).
 *
 * @param int $targetUserId User ID to delete.
 * @param int $adminId Acting admin ID.
 * @return array<string, mixed>
 */
function user_delete(int $targetUserId, int $adminId): array
{
    $u = user_get($targetUserId);
    if (!$u) {
        return ['ok' => false, 'error' => 'User not found.'];
    }
    if ($targetUserId === $adminId) {
        return ['ok' => false, 'error' => 'You cannot delete your own account.'];
    }
    if ($u['role'] === 'admin') {
        $n = db()->query(
            "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND account_status = 'approved'"
        )->fetch()['n'];
        if ((int)$n <= 1) {
            return ['ok' => false, 'error' => 'Cannot delete the last active administrator.'];
        }
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$targetUserId]);

    if (function_exists('log_action')) {
        log_action('USER_DELETE', $adminId, null, "Deleted user #{$targetUserId} ({$u['username']})");
    }

    return ['ok' => true, 'message' => 'User account deleted.'];
}

/**
 * Retrieve list of users with optional filtering.
 *
 * @param array<string, mixed> $filters Filtering parameters (q, role, status).
 * @return array<int, array<string, mixed>>
 */
function user_fetch_all(array $filters = []): array
{
    $where  = [];
    $params = [];

    if (!empty($filters['q'])) {
        $where[]  = '(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR staff_id LIKE ? OR department LIKE ?)';
        $term     = '%' . trim((string)$filters['q']) . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    if (!empty($filters['role'])) {
        $where[]  = 'role = ?';
        $params[] = (string)$filters['role'];
    }
    if (!empty($filters['status'])) {
        $where[]  = 'account_status = ?';
        $params[] = (string)$filters['status'];
    }

    $sql = 'SELECT * FROM users';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
