<?php
declare(strict_types=1);

// Error 500: HTTP 500 Internal Server Error handler delegating to generic error renderer.
$_GET['code'] = 500;
require __DIR__ . '/error.php';
