<?php
declare(strict_types=1);

/**
 * Verification test for error pages (403, 500, dynamic error handler).
 */

// Initialize settings cache so DB is not called during testing
$GLOBALS['settings_cache'] = [
    'school_logo' => '',
    'app_name'    => 'Classroom Finder',
];

require_once __DIR__ . '/../config/helpers.php';

// Test 1: abort function exists
assert(function_exists('abort'), 'abort() function must exist');

// Test 2: 403.php output
ob_start();
$_GET = [];
require __DIR__ . '/../403.php';
$output403 = ob_get_clean();

assert(http_response_code() === 403, '403.php must set HTTP status 403');
assert(str_contains($output403, '403'), '403.php must contain 403 code');
assert(str_contains($output403, 'Access Forbidden'), '403.php must display Access Forbidden');
assert(str_contains($output403, 'Go to Home'), '403.php must have Go to Home link');

// Test 3: error.php with 500
ob_start();
$_GET = ['code' => '500'];
require __DIR__ . '/../error.php';
$output500 = ob_get_clean();

assert(http_response_code() === 500, 'error.php with 500 must set HTTP status 500');
assert(str_contains($output500, 'Internal Server Error'), 'error.php with 500 must display Internal Server Error');

// Test 4: error.php with 503
ob_start();
$_GET = ['code' => '503'];
require __DIR__ . '/../error.php';
$output503 = ob_get_clean();

assert(http_response_code() === 503, 'error.php with 503 must set HTTP status 503');
assert(str_contains($output503, 'Service Unavailable'), 'error.php with 503 must display Service Unavailable');

// Test 5: 500.php entrypoint
ob_start();
$_GET = [];
require __DIR__ . '/../500.php';
$output500File = ob_get_clean();

assert(http_response_code() === 500, '500.php must set HTTP status 500');
assert(str_contains($output500File, 'Internal Server Error'), '500.php must display Internal Server Error');

echo "All error page tests passed successfully!\n";
