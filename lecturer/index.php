<?php
declare(strict_types=1);

// Lecturer Index: gateway redirecting approved faculty to their dashboard.
require_once __DIR__ . '/../auth/auth_check.php';

require_approved_lecturer();
redirect('dashboard.php');
