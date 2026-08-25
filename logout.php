<?php
declare(strict_types=1);

/**
 * Classroom Finder — logout.
 */

require_once __DIR__ . '/config/helpers.php';

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
