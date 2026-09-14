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

$auth = user_authenticate($username, $password);
if (!$auth['ok']) {
    $back($auth['error']);
}

$user = $auth['user'];
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
log_action('LOGIN', (int)$user['id'], null, ucfirst($user['role']) . ' logged in');

flash('success', 'Welcome back, ' . $user['full_name'] . '!');

redirect($user['role'] === 'admin' ? '../admin/dashboard.php' : '../lecturer/scanner.php');
