<?php
declare(strict_types=1);

/**
 * Classroom Finder — Database connection configuration and PDO initialization.
 *
 * Provides database connection parameters and a singleton PDO instance getter `db()`.
 * Handles self-healing schema creation for optional/extended tables when running.
 *
 * @package ClassroomFinder\Config
 */

/** @var string Database server hostname or IP address. */
const DB_HOST = '127.0.0.1';

/** @var string Database server TCP port. */
const DB_PORT = '3306';

/** @var string Database name. */
const DB_NAME = 'classroom_finder';

/** @var string Database connection username. */
const DB_USER = 'root';

/** @var string Database connection password. */
const DB_PASS = '';

/**
 * Local Unix socket tried first when present (e.g. MariaDB socket);
 * falls back to host/port TCP connection otherwise (e.g. stock XAMPP).
 *
 * @var string
 */
const DB_SOCKET = '/run/mysqld/mysqld.sock';

/**
 * Retreive or initialize the shared PDO database connection handle (singleton pattern).
 *
 * Initializes the PDO connection, sets timezone alignment with PHP, and ensures
 * optional tables exist. If connection fails, outputs appropriate JSON or HTML error response.
 *
 * @throws PDOException When PDO connection fails and execution terminates.
 * @return PDO Active database connection instance.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = file_exists(DB_SOCKET)
        ? 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4'
        : 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep MySQL's clock aligned with PHP's so every comparison in this
        // app can go through PHP-formatted datetimes.
        $pdo->prepare('SET time_zone = ?')->execute([date('P')]);

        // Auto-ensure required tables exist (self-healing schema). This is
        // a FRIENDLY FALLBACK for users who skip the SQL import in phpMyAdmin;
        // the canonical schema is database/classroom_finder.sql. Any column
        // added here MUST be added to that file too — and vice versa.
        static $checked = false;
        if (!$checked) {
            $checked = true;
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
        }
    } catch (PDOException $e) {
        $msg = 'Could not connect to MySQL: ' . $e->getMessage();
        if (defined('CF_WANTS_JSON')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
        } else {
            http_response_code(500);
            echo '<!doctype html><meta charset="utf-8"><title>Database error</title>'
               . '<body style="font-family:system-ui;max-width:42rem;margin:4rem auto;line-height:1.6">'
               . '<h1>Database connection failed</h1><p>' . htmlspecialchars($msg) . '</p>'
               . '<p>Start <strong>MySQL</strong> in the XAMPP control panel and import '
               . '<code>database/classroom_finder.sql</code> (via phpMyAdmin), then reload.</p></body>';
        }
        exit;
    }
    return $pdo;
}

