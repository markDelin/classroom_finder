<?php
declare(strict_types=1);

// Error 404: HTTP 404 Not Found handler delegating to generic error renderer.
$_GET['code'] = 404;
require __DIR__ . '/error.php';

