<?php
declare(strict_types=1);

// Admin Index: gateway redirecting authenticated administrators to the dashboard.
require_once __DIR__ . '/../auth/auth_check.php';

require_admin();
redirect('dashboard.php');
