<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

require_admin();
redirect('dashboard.php');
