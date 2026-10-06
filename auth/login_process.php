<?php
declare(strict_types=1);

// Login Processor: handles credential verification, session creation, and role-based redirects.
require_once __DIR__ . '/../config/helpers.php';

// Reject direct GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../login.php');
}

$back = function (string $msg) {
    flash('error', $msg);
    redirect('../login.php');
};

// Anti-CSRF verification
if (!check_csrf()) {
    $back('Session expired — please try logging in again.');
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

// Verify credentials via user_service
$auth = user_authenticate($username, $password);
if (!$auth['ok']) {
    $back($auth['error']);
}

// Regenerate session ID to prevent session fixation attacks
$user = $auth['user'];
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
log_action('LOGIN', (int)$user['id'], null, ucfirst($user['role']) . ' logged in');

flash('success', 'Welcome back, ' . $user['full_name'] . '!');

// Role redirect: administrators to admin panel, lecturers to QR scanner
redirect($user['role'] === 'admin' ? '../admin/dashboard.php' : '../lecturer/scanner.php');
