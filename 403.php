<?php
declare(strict_types=1);

// Error 403: HTTP 403 Forbidden handler delegating to generic error renderer.
$_GET['code'] = 403;
require __DIR__ . '/error.php';
