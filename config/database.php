<?php
declare(strict_types=1);

// Database Configuration: PDO connection factory with host fallback, auto-schema migration, and error handling.
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'classroom_finder';
const DB_USER = 'root';
const DB_PASS = '';

// Returns a singleton PDO instance with prepared statement emulation disabled
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Try primary host then fallback (127.0.0.1 <-> localhost)
    $hosts = [DB_HOST];
    if (DB_HOST === '127.0.0.1') {
        $hosts[] = 'localhost';
    } elseif (DB_HOST === 'localhost') {
        $hosts[] = '127.0.0.1';
    }

    $lastException = null;

    // Connect to MySQL with auto-creation fallback on local development
    foreach ($hosts as $host) {
        $dsn = 'mysql:host=' . $host . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            break;
        } catch (PDOException $e) {
            $lastException = $e;
            // If unknown database on local XAMPP/WAMP/Laragon setup, auto-create & import schema
            $isLocal = ($host === '127.0.0.1' || $host === 'localhost');
            if ($isLocal && DB_USER === 'root' && ($e->getCode() === 1049 || strpos($e->getMessage(), 'Unknown database') !== false)) {
                try {
                    $initPdo = new PDO('mysql:host=' . $host . ';port=' . DB_PORT, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    $initPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $schemaFile = __DIR__ . '/../schema.sql';
                    if (is_file($schemaFile)) {
                        $initPdo->exec("USE `" . DB_NAME . "`");
                        $initPdo->exec(file_get_contents($schemaFile));
                    }
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::ATTR_TIMEOUT            => 5,
                    ]);
                    break;
                } catch (Throwable $initErr) {
                    if (function_exists('app_log')) {
                        app_log('WARNING', 'Auto-schema init failed: ' . $initErr->getMessage());
                    }
                }
            }
        }
    }

    // Fail gracefully with helpful error screen or JSON if unconnectable
    if (!$pdo) {
        $msg = 'Could not connect to MySQL: ' . ($lastException ? $lastException->getMessage() : 'Unknown error');
        if (function_exists('app_log')) {
            app_log('CRITICAL', $msg);
        }
        if (defined('CF_WANTS_JSON')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
        } else {
            http_response_code(500);
            echo '<!doctype html><meta charset="utf-8"><title>Database error</title><body style="font-family:system-ui;max-width:42rem;margin:4rem auto;line-height:1.6;padding:1rem"><h1>Database connection failed</h1>'
               . '<p style="color:#b91c1c;background:#fee2e2;padding:0.75rem 1rem;border-radius:6px;word-break:break-all">' . htmlspecialchars($msg) . '</p>'
               . '<p><strong>How to fix:</strong></p><ul>'
               . '<li>Check credentials in <code>config/database.php</code> (DB_HOST, DB_NAME, DB_USER, DB_PASS). On hosting like InfinityFree, use the MySQL hostname from your control panel (e.g. <code>sqlXXX.infinityfree.com</code>).</li>'
               . '<li>Import <code>database/classroom_finder.sql</code> via phpMyAdmin into your database.</li>'
               . '<li>Ensure your database server is active and accessible.</li>'
               . '</ul></body>';
        }
        exit;
    }

    // Align MySQL session timezone with PHP server timezone
    try {
        $pdo->prepare('SET time_zone = ?')->execute([date('P')]);
    } catch (Throwable $tzErr) {
        if (function_exists('app_log')) {
            app_log('DEBUG', 'Timezone set notice: ' . $tzErr->getMessage());
        }
    }

    // Self-healing check: guarantee presence of schedule and exception tables
    static $checked = false;
    if (!$checked) {
        $checked = true;
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS class_schedules (
                  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  classroom_id INT UNSIGNED NOT NULL,
                  day_of_week  TINYINT UNSIGNED NOT NULL,
                  start_time   TIME NOT NULL,
                  end_time     TIME NOT NULL,
                  subject      VARCHAR(120) NOT NULL,
                  section      VARCHAR(80)  DEFAULT NULL,
                  instructor   VARCHAR(120) DEFAULT NULL,
                  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
                  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  KEY idx_sched_room_day (classroom_id, day_of_week, is_active)
                ) ENGINE = InnoDB;

                CREATE TABLE IF NOT EXISTS schedule_force_open (
                  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  classroom_id INT UNSIGNED NOT NULL,
                  schedule_id  INT UNSIGNED NOT NULL,
                  exc_date     DATE NOT NULL,
                  reason       ENUM('lecturer_absent','emergency','ended_early','other') NOT NULL,
                  details      VARCHAR(160) DEFAULT NULL,
                  user_id      INT UNSIGNED NOT NULL,
                  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_force_slot (schedule_id, exc_date)
                ) ENGINE = InnoDB;
            ");
        } catch (Throwable $schemaErr) {
            if (function_exists('app_log')) {
                app_log('DEBUG', 'Runtime table check notice: ' . $schemaErr->getMessage());
            }
        }
    }

    return $pdo;
}
