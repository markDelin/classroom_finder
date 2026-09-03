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
$settings_cache = null;

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
    $now = date('Y-m-d H:i:s');
    try {
        db()->prepare("UPDATE classroom_sessions SET status = 'completed'
                       WHERE status = 'active' AND end_time <= ?")->execute([$now]);
        db()->prepare("UPDATE reservations SET status = 'completed'
                       WHERE status = 'active' AND end_time <= ?")->execute([$now]);
    } catch (Throwable) {
        // ignore — page should still render
    }
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
    expire_stale();

    $now  = date('Y-m-d H:i:s');
    $soon = date('Y-m-d H:i:s', time() + get_setting_int('reserve_window_minutes', 45) * 60);

    // Today's fixed-schedule slot (recurring weekly class timetable). MySQL's
    // session timezone is synced from PHP on connect, so CURDATE()/CURTIME()
    // match the date('...') values used everywhere else.
    $dow     = (int)date('N');           // 1=Mon … 7=Sun
    $curtime = date('H:i:s');
    $today   = date('Y-m-d');

    $sql = "SELECT c.*,
                   MAX(s.id)         AS session_id,
                   MAX(s.start_time) AS session_start,
                   MAX(s.end_time)   AS session_end,
                   MAX(su.full_name) AS session_lecturer,
                   MAX(r.id)         AS reservation_id,
                   MIN(r.start_time) AS reservation_start,
                   MAX(r.end_time)   AS reservation_end,
                   MAX(r.purpose)    AS reservation_purpose,
                   MAX(ru.full_name) AS reservation_by,
                   MAX(cs.id)          AS sched_id,
                   MAX(cs.subject)     AS sched_subject,
                   MAX(cs.section)     AS sched_section,
                   MAX(cs.instructor)  AS sched_instructor,
                   MAX(cs.start_time)  AS sched_start,
                   MAX(cs.end_time)    AS sched_end,
                   MAX(fo.id)          AS force_open_id
            FROM classrooms c
            LEFT JOIN classroom_sessions s
                   ON s.classroom_id = c.id AND s.status = 'active'
                  AND s.start_time <= :now1 AND s.end_time > :now2
            LEFT JOIN users su ON su.id = s.user_id
            LEFT JOIN reservations r
                   ON r.classroom_id = c.id AND r.status = 'active'
                  AND r.start_time <= :soon AND r.end_time > :now3
            LEFT JOIN users ru ON ru.id = r.user_id
            LEFT JOIN class_schedules cs
                   ON cs.classroom_id = c.id AND cs.is_active = 1
                  AND cs.day_of_week = :dow
                  AND cs.start_time <= :curtime1 AND cs.end_time > :curtime2
            LEFT JOIN schedule_force_open fo
                   ON fo.schedule_id = cs.id AND fo.exc_date = :today";

    $where  = [];
    $params = [
        ':now1'     => $now,
        ':now2'     => $now,
        ':now3'     => $now,
        ':soon'     => $soon,
        ':dow'      => $dow,
        ':curtime1' => $curtime,
        ':curtime2' => $curtime,
        ':today'    => $today,
    ];

    if (!empty($f['q'])) {
        $where[]              = '(c.room_number LIKE :q OR c.building LIKE :q OR c.room_type LIKE :q OR c.note LIKE :q OR cs.subject LIKE :q OR cs.instructor LIKE :q OR su.full_name LIKE :q OR ru.full_name LIKE :q)';
        $params[':q']         = '%' . trim((string)$f['q']) . '%';
    }
    if (!empty($f['building'])) {
        $where[]              = 'c.building = :building';
        $params[':building']  = (string)$f['building'];
    }
    if (isset($f['floor']) && $f['floor'] !== '' && $f['floor'] !== null) {
        $where[]              = 'c.floor = :floor';
        $params[':floor']     = (int)$f['floor'];
    }
    if (!empty($f['type'])) {
        $where[]              = 'c.room_type = :type';
        $params[':type']      = (string)$f['type'];
    }
    if (!empty($f['mincap'])) {
        $where[]              = 'c.capacity >= :mincap';
        $params[':mincap']    = (int)$f['mincap'];
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY c.id ORDER BY c.building, c.room_number';

    $st = db()->prepare($sql);
    $st->execute($params);

    $rooms = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['status'] !== 'available') {
            $r['computed']     = 'unavailable';
            $r['available_at'] = null;
        } elseif (!empty($r['session_id'])) {
            $r['computed']     = 'occupied';
            $r['available_at'] = $r['session_end'];
        } elseif (!empty($r['sched_id']) && empty($r['force_open_id'])) {
            // fixed weekly class is in session (unless reported as not meeting)
            $r['computed']     = 'occupied';
            $r['available_at'] = $r['sched_end'];
        } elseif (!empty($r['reservation_id'])) {
            $r['computed']     = 'reserved';
            $r['available_at'] = null;
        } else {
            $r['computed']     = 'available';
            $r['available_at'] = null;
        }
        $rooms[] = $r;
    }

    if (!empty($f['status'])) {
        $rooms = array_values(array_filter($rooms, fn($r) => $r['computed'] === $f['status']));
    }
    return $rooms;
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
    if ($id <= 0) {
        return null;
    }
    expire_stale();

    $now  = date('Y-m-d H:i:s');
    $soon = date('Y-m-d H:i:s', time() + get_setting_int('reserve_window_minutes', 45) * 60);
    $dow  = (int)date('N');
    $curt = date('H:i:s');
    $today = date('Y-m-d');

    $st = db()->prepare(
        "SELECT c.*,
                s.id            AS session_id,
                s.start_time    AS session_start,
                s.end_time      AS session_end,
                su.full_name    AS session_lecturer,
                r.id            AS reservation_id,
                r.start_time    AS reservation_start,
                r.end_time      AS reservation_end,
                r.purpose       AS reservation_purpose,
                ru.full_name    AS reservation_by,
                cs.id           AS sched_id,
                cs.subject      AS sched_subject,
                cs.section      AS sched_section,
                cs.instructor   AS sched_instructor,
                cs.start_time   AS sched_start,
                cs.end_time     AS sched_end,
                fo.id           AS force_open_id
         FROM classrooms c
         LEFT JOIN classroom_sessions s
                ON s.classroom_id = c.id AND s.status = 'active'
               AND s.start_time <= :now1 AND s.end_time > :now2
         LEFT JOIN users su ON su.id = s.user_id
         LEFT JOIN reservations r
                ON r.classroom_id = c.id AND r.status = 'active'
               AND r.start_time <= :soon AND r.end_time > :now3
         LEFT JOIN users ru ON ru.id = r.user_id
         LEFT JOIN class_schedules cs
                ON cs.classroom_id = c.id AND cs.is_active = 1
               AND cs.day_of_week = :dow
               AND cs.start_time <= :curt1 AND cs.end_time > :curt2
         LEFT JOIN schedule_force_open fo
                ON fo.schedule_id = cs.id AND fo.exc_date = :today
         WHERE c.id = :id
         LIMIT 1"
    );
    $st->execute([
        ':now1' => $now, ':now2' => $now, ':now3' => $now, ':soon' => $soon,
        ':dow'  => $dow, ':curt1' => $curt, ':curt2' => $curt,
        ':today' => $today, ':id' => $id,
    ]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }

    // Same status-priority logic as fetch_classrooms()
    if ($r['status'] !== 'available') {
        $r['computed']     = 'unavailable';
        $r['available_at'] = null;
    } elseif (!empty($r['session_id'])) {
        $r['computed']     = 'occupied';
        $r['available_at'] = $r['session_end'];
    } elseif (!empty($r['sched_id']) && empty($r['force_open_id'])) {
        $r['computed']     = 'occupied';
        $r['available_at'] = $r['sched_end'];
    } elseif (!empty($r['reservation_id'])) {
        $r['computed']     = 'reserved';
        $r['available_at'] = null;
    } else {
        $r['computed']     = 'available';
        $r['available_at'] = null;
    }
    return $r;
}

/**
 * Retrieve classroom record with live status (wrapper for `get_room_with_status`).
 *
 * @param int $id Classroom primary key ID.
 * @return array<string, mixed>|null Room record array or null if missing.
 */
function get_room(int $id): ?array
{
    return get_room_with_status($id);
}

/**
 * Look up classroom by secret QR code token string.
 *
 * @param string $token Secret QR token.
 * @return array<string, mixed>|null Classroom record or null if invalid token.
 */
function get_room_by_token(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM classrooms WHERE qr_token = ? LIMIT 1');
    $st->execute([strtolower($token)]);
    $room = $st->fetch();
    return $room ?: null;
}

/**
 * Parse and extract 32-character hex QR token from scanned raw input payload or URL string.
 *
 * @param string|null $raw Scanned text payload or URL string.
 * @return string|null Extracted 32-char hex token or null if unparseable.
 */
function extract_qr_token(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    return preg_match('/[0-9a-f]{32}/i', trim($raw), $m) ? strtolower($m[0]) : null;
}

/**
 * Retrieve active room session for a specified user ID.
 *
 * @param int $userId Target user ID.
 * @return array<string, mixed>|null Active session record array or null if none active.
 */
function get_active_session_for(int $userId): ?array
{
    $now = date('Y-m-d H:i:s');
    $st  = db()->prepare(
        "SELECT s.*, c.room_number, c.building, c.floor
         FROM classroom_sessions s
         JOIN classrooms c ON c.id = s.classroom_id
         WHERE s.user_id = ? AND s.status = 'active' AND s.start_time <= ? AND s.end_time > ?
         ORDER BY s.start_time DESC LIMIT 1"
    );
    $st->execute([$userId, $now, $now]);
    $s = $st->fetch();
    return $s ?: null;
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
    $now = date('Y-m-d H:i:s');
    $st  = db()->prepare(
        "UPDATE classroom_sessions
         SET status = 'released', released_at = ?, end_time = LEAST(end_time, ?)
         WHERE id = ? AND status = 'active'"
    );
    $st->execute([$now, $now, $sessionId]);
    return $st->rowCount() > 0;
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
