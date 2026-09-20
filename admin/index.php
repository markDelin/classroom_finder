<?php
declare(strict_types=1);

/**
 * Admin root redirect to admin dashboard.
 */

require_once __DIR__ . '/../auth/auth_check.php';

require_admin();
redirect('dashboard.php');
