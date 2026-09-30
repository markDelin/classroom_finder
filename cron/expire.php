<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

try {
    expire_stale();
    $now = date('Y-m-d H:i:s');
    $msg = "[{$now}] Classroom Finder expire_stale sweep completed successfully.\n";
    if (PHP_SAPI === 'cli') {
        echo $msg;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit;
}
