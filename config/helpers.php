<?php
declare(strict_types=1);

/**
 * Classroom Finder — shared helper utilities.
 *
 * Included by every page and API endpoint. Initializes PHP sessions, configures environment settings,
 * and provides application-wide functions for CSRF protection, flash messages, settings, logging,
 * room status querying, and formatting.
 *
 * @package ClassroomFinder\Config
 */

require_once __DIR__ . '/database.php';
// Lucide icon helper — pure functions, needed by layout.php and every page.
require_once __DIR__ . '/icons.php';
// layout.php (and through any entry point that includes helpers, the whole
// app) so every page can call render_header()/room_card() etc. directly.
require_once __DIR__ . '/layout.php';

// Domain services
require_once __DIR__ . '/../services/room_service.php';
require_once __DIR__ . '/../services/session_service.php';
require_once __DIR__ . '/../services/user_service.php';
require_once __DIR__ . '/../services/schedule_service.php';

/* Polyfills for PHP environments lacking ext-mbstring extension */
if (!function_exists('mb_strlen')) {
    /**
     * Polyfill for mb_strlen if mbstring extension is disabled.
     *
     * @param string $s Target string.
     * @return int Character length in UTF-8.
     */
    function mb_strlen(string $s): int
    {
        return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
if (!function_exists('mb_substr')) {
    /**
     * Polyfill for mb_substr if mbstring extension is disabled.
     *
     * @param string $s Target string.
     * @param int $start Starting character position.
     * @param int|null $length Substring character length.
     * @return string Extracted substring.
     */
    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strtoupper')) {
    /**
     * Polyfill for mb_strtoupper if mbstring extension is disabled.
     *
     * @param string $s Target string.
     * @return string Uppercase string.
     */
    function mb_strtoupper(string $s): string
    {
        return strtoupper($s);
    }
}

date_default_timezone_set('Asia/Manila');

/** Default application name fallback constant. */
const APP_NAME = 'Classroom Finder';

/**
 * Retrieve configured application branding title.
 *
 * @return string System name setting or APP_NAME constant fallback.
 */
function app_name(): string
{
    $v = trim(get_setting('app_name', ''));
    return $v !== '' ? $v : APP_NAME;
}

/**
 * Retrieve configured school or institution name.
 *
 * @return string Institution name setting or empty string.
 */
function school_name(): string
{
    return trim(get_setting('school_name', ''));
}

/**
 * Retrieve configured school or campus physical address.
 *
 * @return string Institution address setting or empty string.
 */
function school_address(): string
{
    return trim(get_setting('school_address', ''));
}

/**
 * Retrieve configured school contact info (phone/email).
 *
 * @return string Contact details setting or empty string.
 */
function school_contact(): string
{
    return trim(get_setting('school_contact', ''));
}

/* ==========================================================================
 * Session / output basics
 * ========================================================================*/

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
}

/**
 * Initializes and configures the secure session if not already active.
 * Sets HttpOnly and SameSite cookie options for security.
 */
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = is_https();
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_name('classroomfinder');
    session_start();
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self';");
        if ($secure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
boot_session();

/**
 * Safely HTML-escapes a string for XSS prevention.
 *
 * @param string|null $v String to escape
 * @return string Escaped string safe for HTML output
 */
function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Sends a HTTP Location header redirect and exits script execution.
 *
 * @param string $url Target URL to redirect to
 * @return never
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Terminates execution and renders an HTTP error page.
 *
 * @param int $code HTTP status code (e.g. 400, 401, 403, 404, 500, 503)
 * @param string $message Optional custom error message
 * @return never
 */
function abort(int $code = 404, string $message = ''): never
{
    http_response_code($code);
    $_GET['code'] = $code;
    if ($message !== '') {
        $_GET['message'] = $message;
    }
    if ($code === 403 && file_exists(__DIR__ . '/../403.php')) {
        require __DIR__ . '/../403.php';
    } elseif ($code === 404 && file_exists(__DIR__ . '/../404.php') && $message === '') {
        require __DIR__ . '/../404.php';
    } elseif (file_exists(__DIR__ . '/../error.php')) {
        require __DIR__ . '/../error.php';
    } else {
        echo 'Error ' . $code . ': ' . e($message !== '' ? $message : 'An error occurred.');
    }
    exit;
}

/* ==========================================================================
 * CSRF protection
 * ========================================================================*/

/**
 * Returns or generates the session CSRF protection token.
 *
 * @return string 64-character hex CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Returns a hidden HTML input containing the current CSRF token.
 *
 * @return string HTML hidden input tag markup
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Accepts the token from POST body or X-CSRF-Token header. */
function check_csrf(?string $token = null): bool
{
    $token ??= (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known  = $_SESSION['csrf'] ?? '';
    return $known !== '' && $token !== '' && hash_equals($known, $token);
}

/* ==========================================================================
 * Flash messages
 * ========================================================================*/

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $message];
}

/** @return array<int,array{t:string,m:string}> */
function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ==========================================================================
 * Current user (row for the signed-in account, or null)
 * ========================================================================*/

/**
 * Fetches the currently authenticated user's database record.
 * Returns null if unauthenticated, suspended, or deleted.
 *
 * @return array|null User record array or null
 */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $st = db()->prepare(
            'SELECT id, full_name, staff_id, email, username, department, role, account_status
             FROM users WHERE id = ? LIMIT 1'
        );
        $st->execute([(int)$_SESSION['user_id']]);
        $row = $st->fetch();
        if ($row && $row['account_status'] !== 'suspended') {
            $user = $row;
        } else {
            // deleted or suspended mid-session -> drop the session
            unset($_SESSION['user_id']);
        }
    }
    return $user;
}

/**
 * Checks whether a valid user session is active.
 *
 * @return bool True if a user is logged in
 */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/* ==========================================================================
 * Settings
 * ========================================================================*/

/** @var array<string,string>|null $settings_cache request-wide settings cache */
$settings_cache ??= null;

/**
 * Retrieve key-value configuration setting from system settings table.
 *
 * @param string $key Setting key name.
 * @param string $default Fallback value if setting key is missing.
 * @return string Setting value or default value.
 */
function get_setting(string $key, string $default = ''): string
{
    global $settings_cache;
    if ($settings_cache === null) {
        $settings_cache = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
                $settings_cache[$row['skey']] = $row['svalue'];
            }
        } catch (Throwable) {
            // settings table missing -> fall back to defaults
        }
    }
    return $settings_cache[$key] ?? $default;
}

/**
 * Retrieve integer configuration setting from system settings table.
 *
 * @param string $key Setting key name.
 * @param int $default Fallback integer value.
 * @return int Integer value of setting or default value.
 */
function get_setting_int(string $key, int $default): int
{
    $v = filter_var(get_setting($key, ''), FILTER_VALIDATE_INT);
    return $v === false ? $default : $v;
}

/**
 * Invalidate the request-local settings cache. Called automatically by
 * set_setting() so a write in this request is immediately visible.
 *
 * @return void
 */
function bust_settings_cache(): void
{
    global $settings_cache;
    $settings_cache = null;
}

/**
 * Create or update a key-value setting in the system settings table.
 *
 * @param string $key Setting key name.
 * @param string $value New setting string value.
 * @return void
 */
function set_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (skey, svalue) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
    )->execute([$key, $value]);
    bust_settings_cache();
}

/* ==========================================================================
 * Activity logging
 * ========================================================================*/

/**
 * Log administrative or user action into activity logs table.
 *
 * @param string $action Action description keyword (e.g. 'occupy_room', 'update_setting').
 * @param int|null $userId User ID associated with action or null for system action.
 * @param int|null $classroomId Optional associated classroom ID.
 * @param string $details Additional details or metadata context string.
 * @return void
 */
function log_action(string $action, ?int $userId = null, ?int $classroomId = null, string $details = ''): void
{
    try {
        db()->prepare('INSERT INTO activity_logs (user_id, classroom_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $classroomId, $action, mb_substr($details, 0, 255)]);
    } catch (Throwable) {
        // logging must never break the app
    }
}

/* ==========================================================================
 * Automatic expiration (Feature: Auto Session & Booking Expiry)
 * Sessions past their end time become 'completed'; past reservations too.
 * Called before any status computation.
 * ========================================================================*/

/**
 * Mark expired occupancy sessions and completed reservations as completed.
 * Automatically run prior to classroom status queries.
 *
 * @return void
 */
function expire_stale(): void
{
    session_expire_stale();
}

/* ==========================================================================
 * Classroom status engine (Feature: Real-Time Availability & Status Hierarchy)
 * priority: unavailable (maintenance/disabled) > occupied > reserved > available
 * ========================================================================*/

/**
 * Retrieve list of classrooms with live calculated occupancy and reservation statuses.
 *
 * @param array<string, mixed> $f Filter parameters (q, building, floor, type, mincap, status).
 * @return array<int, array<string, mixed>> Matching room records with computed status fields.
 */
function fetch_classrooms(array $f = []): array
{
    return room_fetch_all($f);
}

/**
 * Single room lookup by primary key with computed live status fields.
 * Targeted single-row query without full table scans.
 *
 * @param int $id Classroom primary key ID.
 * @return array<string, mixed>|null Classroom record array or null if not found.
 */
function get_room_with_status(int $id): ?array
{
    return room_get_with_status($id);
}

/**
 * Retrieve classroom record with live status (wrapper for `get_room_with_status`).
 *
 * @param int $id Classroom primary key ID.
 * @return array<string, mixed>|null Room record array or null if missing.
 */
function get_room(int $id): ?array
{
    return room_get($id);
}

/**
 * Look up classroom by secret QR code token string.
 *
 * @param string $token Secret QR token.
 * @return array<string, mixed>|null Classroom record or null if invalid token.
 */
function get_room_by_token(string $token): ?array
{
    return room_get_by_token($token);
}

/**
 * Parse and extract 32-character hex QR token from scanned raw input payload or URL string.
 *
 * @param string|null $raw Scanned text payload or URL string.
 * @return string|null Extracted 32-char hex token or null if unparseable.
 */
function extract_qr_token(?string $raw): ?string
{
    return room_extract_qr_token($raw);
}

/**
 * Retrieve active room session for a specified user ID.
 *
 * @param int $userId Target user ID.
 * @return array<string, mixed>|null Active session record array or null if none active.
 */
function get_active_session_for(int $userId): ?array
{
    return session_get_active_for_user($userId);
}

/**
 * Early-release active room session before scheduled end time.
 *
 * @param int $sessionId Session primary key ID.
 * @param string $via Role or channel initiating release ('lecturer', 'admin', 'cron').
 * @return bool True if session released successfully.
 */
function release_session(int $sessionId, string $via = 'lecturer'): bool
{
    $u = current_user();
    $userId = $u ? (int)$u['id'] : 0;
    $role = ($u && $u['role'] === 'admin') ? 'admin' : $via;
    $res = session_release($sessionId, $userId, $role);
    return (bool)($res['ok'] ?? false);
}

/* ==========================================================================
 * Time formatting
 * ========================================================================*/

/**
 * Format SQL datetime or time string into 12-hour AM/PM time format.
 *
 * @param string|null $sqlDateTime Raw datetime or time string.
 * @return string Formatted time string (e.g. "9:00 AM") or empty string.
 */
function fmt_time(?string $sqlDateTime): string
{
    if (!$sqlDateTime) {
        return '';
    }
    $t = strtotime($sqlDateTime);
    return $t === false ? '' : date('g:i A', $t);
}

/**
 * Format SQL date string into human-readable date format.
 *
 * @param string|null $sqlDateTime Raw date or datetime string.
 * @return string Formatted date string (e.g. "Oct 24, 2026") or empty string.
 */
function fmt_date(?string $sqlDateTime): string
{
    if (!$sqlDateTime) {
        return '';
    }
    $t = strtotime($sqlDateTime);
    return $t === false ? '' : date('M j, Y', $t);
}

/**
 * Format pair of times/datetimes into readable range string.
 *
 * @param string|null $start Start time or datetime.
 * @param string|null $end End time or datetime.
 * @return string Formatted time range string (e.g. "9:00 AM – 10:30 AM").
 */
function fmt_range(?string $start, ?string $end): string
{
    return fmt_time($start) . ' – ' . fmt_time($end);
}

/**
 * Convert SQL datetime to ISO-8601 string carrying server UTC offset.
 *
 * @param string|null $sqlDateTime Raw SQL datetime string.
 * @return string ISO-8601 formatted datetime string.
 */
function fmt_iso(?string $sqlDateTime): string
{
    if (!$sqlDateTime) {
        return '';
    }
    $t = strtotime($sqlDateTime);
    return $t === false ? '' : date('Y-m-d\TH:i:sP', $t);
}

/**
 * Calculate duration in elapsed minutes between two SQL datetimes.
 *
 * @param string $start Start datetime string.
 * @param string $end End datetime string.
 * @return int Minutes count between start and end.
 */
function minutes_between(string $start, string $end): int
{
    $s = strtotime($start);
    $e = strtotime($end);
    return ($s === false || $e === false) ? 0 : max(0, (int)(($e - $s) / 60));
}

/**
 * Format minutes count into shorthand human readable duration.
 *
 * @param int $minutes Target minutes count.
 * @return string Formatted duration string (e.g., "1h 30m").
 */
function human_duration(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h && $m) {
        return "{$h}h {$m}m";
    }
    if ($h) {
        return "{$h}h";
    }
    return "{$m}m";
}

/**
 * Calculate remaining minutes until a future datetime.
 *
 * @param string $futureSqlDateTime Target future SQL datetime.
 * @return int Remaining minutes count.
 */
function minutes_until(string $futureSqlDateTime): int
{
    $t = strtotime($futureSqlDateTime);
    return $t === false ? 0 : max(0, (int)ceil(($t - time()) / 60));
}

/**
 * Return configured operating hours for room scanning: [start, end].
 * Format: 'H:i' (e.g. '07:00', '19:00').
 *
 * @return array{0: string, 1: string}
 */
function get_scan_hours(): array
{
    $start = substr(trim(get_setting('scan_day_start', '07:00')), 0, 5);
    $end   = substr(trim(get_setting('scan_day_end', '19:00')), 0, 5);
    return [
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) ? $start : '07:00',
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) ? $end : '19:00',
    ];
}

/**
 * Check whether a timestamp (or current time) falls within daily room scanning operating hours.
 *
 * @param int|null $time Unix timestamp (defaults to current time).
 * @return bool
 */
function is_within_scan_hours(?int $time = null): bool
{
    $time ??= time();
    $current = date('H:i', $time);
    [$start, $end] = get_scan_hours();
    return $current >= $start && $current < $end;
}

/* ==========================================================================
 * Reporting: date ranges + CSV export
 * ========================================================================*/

/**
 * Resolve the report date range from the request.
 * ?range=today|week|month wins; otherwise ?from/?to (Y-m-d) are used as-is;
 * with neither, both bounds are empty (= everything).
 *
 * @return array{0:string,1:string,2:string} [from Y-m-d, to Y-m-d, label]
 */
function report_range(): array
{
    $range = (string)($_GET['range'] ?? '');
    $today = date('Y-m-d');
    if ($range === 'today') {
        return [$today, $today, 'Today'];
    }
    if ($range === 'week') {
        return [date('Y-m-d', strtotime('monday this week')), $today, 'This week'];
    }
    if ($range === 'month') {
        return [date('Y-m-01'), $today, 'This month'];
    }
    $ok  = static fn(string $v): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to'] ?? '');
    return [$ok($from) ? $from : '', $ok($to) ? $to : '', 'Custom range'];
}

/**
 * Check whether request requests CSV output via `?export=csv`.
 *
 * @return bool True if CSV export is requested.
 */
function wants_csv(): bool
{
    return ($_GET['export'] ?? '') === 'csv';
}

/**
 * Stream rows as a downloadable CSV file and terminate execution.
 * Prepends UTF-8 BOM for Microsoft Excel compatibility and escapes formula characters.
 *
 * @param string $filename Output CSV filename.
 * @param array<int,string> $headers Column header titles.
 * @param iterable<array<int,mixed>> $rows Data rows dataset.
 * @return never
 */
function stream_csv(string $filename, array $headers, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($v): string {
            $v = (string)$v;
            // Excel/Sheets strip leading whitespace before evaluating a formula,
            // so prefix the apostrophe if the FIRST non-space char is dangerous.
            return preg_match('/^\s*[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        }, array_values((array)$row)));
    }
    fclose($out);
    exit;
}

/* ==========================================================================
 * JSON responses (api/)
 * ========================================================================*/

/**
 * Emit JSON response payload with HTTP status code and terminate script execution.
 *
 * @param array<string, mixed> $data Response payload array.
 * @param int $code HTTP response status code (default: 200).
 * @return never
 */
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read request payload by merging standard `$_POST` and JSON request body parameters.
 *
 * @return array<string, mixed> Key-value input parameters dataset.
 */
function request_input(): array
{
    $json = [];
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $json = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($json)) {
            $json = [];
        }
    }
    return array_merge($_POST, $json);
}
