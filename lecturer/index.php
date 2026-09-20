<?php
declare(strict_types=1);

/**
 * Lecturer root redirect to lecturer dashboard.
 */

require_once __DIR__ . '/../auth/auth_check.php';

require_approved_lecturer();
redirect('dashboard.php');
