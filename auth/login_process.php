<?php
declare(strict_types=1);

/**
 * Classroom Finder — login POST handler (Feature: User Authentication).
 * Verifies credentials, account status, then routes by role.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../login.php');
}

$back = function (string $msg): never {
    flash('error', $msg);
    redirect('../login.php');
};

if (!check_csrf()) {
    $back('Session expired — please try logging in again.');
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $back('Enter your username and password.');
}

// Brute-force rate-limiting: 5 failed attempts within 10 minutes locks out for 10 minutes.
$recentFailsStmt = db()->prepare(
    "SELECT COUNT(*) AS fail_count FROM activity_logs
     WHERE action = 'LOGIN_FAILED'
       AND details = ?
       AND timestamp >= (NOW() - INTERVAL 10 MINUTE)"
);
$recentFailsStmt->execute(['Username: ' . $username]);
$failCount = (int)($recentFailsStmt->fetch()['fail_count'] ?? 0);
if ($failCount >= 5) {
    log_action('LOGIN_LOCKOUT', null, null, 'Username: ' . $username);
    $back('Too many failed login attempts. Please wait 10 minutes before trying again.');
}

// Brute-force damping: tiny constant-time-ish delay per failed attempt.
$st = db()->prepare(
    'SELECT id, password, role, account_status, full_name FROM users WHERE username = ? LIMIT 1'
);
$st->execute([$username]);
$user = $st->fetch();

// Always run a hash check so timing doesn't reveal whether the username exists.
$hash   = $user['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
$valid  = password_verify($password, $hash) && $user !== null;

usleep(random_int(20000, 60000));

if (!$valid) {
    log_action('LOGIN_FAILED', $user['id'] ?? null, null, 'Username: ' . $username);
    $back('Incorrect username or password.');
}

switch ($user['account_status']) {
    case 'pending':
        log_action('LOGIN_BLOCKED_PENDING', (int)$user['id']);
        $back('Your account is awaiting administrator approval. You will be able to log in once approved.');
        // no break needed, $back exits
    case 'rejected':
    case 'suspended':
        log_action('LOGIN_BLOCKED_' . strtoupper($user['account_status']), (int)$user['id']);
        $back('Your account is not active. Please contact an administrator.');
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
log_action('LOGIN', (int)$user['id'], null, ucfirst($user['role']) . ' logged in');

flash('success', 'Welcome back, ' . $user['full_name'] . '!');

redirect($user['role'] === 'admin' ? '../admin/dashboard.php' : '../lecturer/scanner.php');
