<?php
declare(strict_types=1);

/**
 * Classroom Finder — background session expiry task.
 *
 * Usage:
 *   php cron/expire.php
 *
 * Safe to run as a scheduled task (Windows Task Scheduler / Linux Cron) every minute.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/helpers.php';

try {
    expire_stale();
    $now = date('Y-m-d H:i:s');
    echo "[{$now}] Classroom Finder expire_stale sweep completed successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    exit(1);
}
