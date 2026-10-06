<?php
declare(strict_types=1);

// Logout: terminates authenticated user session, deletes cookies, and logs activity.
require_once __DIR__ . '/config/helpers.php';

// Enforce POST method to prevent CSRF logout attacks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    abort(405, 'Use the sign-out button to log out.');
}
if (!check_csrf()) {
    abort(403, 'Session expired — please submit the form again.');
}

// Audit logout event
if (!empty($_SESSION['user_id'])) {
    log_action('LOGOUT', (int)$_SESSION['user_id']);
}

// Clear session variables and destroy cookie
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

redirect('index.php');
