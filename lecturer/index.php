<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

require_approved_lecturer();
redirect('dashboard.php');
