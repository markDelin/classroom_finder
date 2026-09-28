<?php
declare(strict_types=1);

require_once __DIR__ . '/config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    abort(405, 'Use the sign-out button to log out.');
}
if (!check_csrf()) {
    abort(403, 'Session expired — please submit the form again.');
}

if (!empty($_SESSION['user_id'])) {
    log_action('LOGOUT', (int)$_SESSION['user_id']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

redirect('index.php');
