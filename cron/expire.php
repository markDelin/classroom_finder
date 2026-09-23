<?php
declare(strict_types=1);

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
