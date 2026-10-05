<?php
declare(strict_types=1);

require_once __DIR__ . '/debug.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../services/room_service.php';
require_once __DIR__ . '/../services/session_service.php';
require_once __DIR__ . '/../services/user_service.php';
require_once __DIR__ . '/../services/schedule_service.php';

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Classroom Finder requires PHP 7.4 or higher. Current PHP version: ' . PHP_VERSION);
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int
    {
        return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s): string
    {
        return strtoupper($s);
    }
}

date_default_timezone_set('Asia/Manila');

// Ensure hardcoded brand assets (campus background & school logo) exist
(static function (): void {
    $imgDir = __DIR__ . '/../assets/img';
    if (!is_dir($imgDir)) {
        @mkdir($imgDir, 0777, true);
    }
    $bgWebp = $imgDir . '/campus-bg.webp';
    $bgUploadWebp = __DIR__ . '/../assets/uploads/campus-bg.webp';

    if (!is_file($bgWebp) || filesize($bgWebp) === 0) {
        if (is_file($bgUploadWebp) && filesize($bgUploadWebp) > 0) {
            @copy($bgUploadWebp, $bgWebp);
        }
    } elseif (!is_file($bgUploadWebp) || filesize($bgUploadWebp) === 0) {
        if (is_file($bgWebp) && filesize($bgWebp) > 0) {
            @copy($bgWebp, $bgUploadWebp);
        }
    }
    // Remove obsolete heavy jpg files if webp is active
    if (is_file($bgWebp) && filesize($bgWebp) > 0) {
        $oldJpg = $imgDir . '/campus-bg.jpg';
        if (is_file($oldJpg)) {
            @unlink($oldJpg);
        }
        $oldUploadJpg = __DIR__ . '/../assets/uploads/campus-bg.jpg';
        if (is_file($oldUploadJpg)) {
            @unlink($oldUploadJpg);
        }
    }
    $logoFile = $imgDir . '/logo.png';
    if (!file_exists($logoFile) || filesize($logoFile) === 0) {
        $trackedLogo = __DIR__ . '/../assets/uploads/logo-3146dc738a66.png';
        if (file_exists($trackedLogo)) {
            @copy($trackedLogo, $logoFile);
        }
    }
    $initScript = __DIR__ . '/../init_assets.php';
    if (file_exists($initScript)) {
        @unlink($initScript);
    }
})();

const APP_NAME = 'Classroom Finder';

function app_name(): string
{
    $v = trim(get_setting('app_name', ''));
    return $v !== '' ? $v : APP_NAME;
}

function school_name(): string
{
    $v = trim(get_setting('school_name', ''));
    return $v !== '' ? $v : 'Clarendon College';
}

function school_address(): string
{
    return trim(get_setting('school_address', ''));
}

function school_contact(): string
{
    return trim(get_setting('school_contact', ''));
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
}

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

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url)
{
    header('Location: ' . $url);
    exit;
}

function abort(int $code = 404, string $message = '')
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

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(?string $token = null): bool
{
    $token ??= (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known  = $_SESSION['csrf'] ?? '';
    return $known !== '' && $token !== '' && hash_equals($known, $token);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

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
            unset($_SESSION['user_id']);
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

$settings_cache ??= null;

function get_setting(string $key, string $default = ''): string
{
    global $settings_cache;
    if ($settings_cache === null) {
        $settings_cache = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
                $settings_cache[$row['skey']] = $row['svalue'];
            }
        } catch (Throwable $e) {
            app_log('WARNING', 'Failed to read settings from DB: ' . $e->getMessage());
        }
    }
    return $settings_cache[$key] ?? $default;
}

function get_setting_int(string $key, int $default): int
{
    $v = filter_var(get_setting($key, ''), FILTER_VALIDATE_INT);
    return $v === false ? $default : $v;
}

function bust_settings_cache(): void
{
    global $settings_cache;
    $settings_cache = null;
}

function set_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (skey, svalue) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
    )->execute([$key, $value]);
    bust_settings_cache();
}

function log_action(string $action, ?int $userId = null, ?int $classroomId = null, string $details = ''): void
{
    try {
        db()->prepare('INSERT INTO activity_logs (user_id, classroom_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $classroomId, $action, mb_substr($details, 0, 255)]);
    } catch (Throwable $e) {
        app_log('ERROR', 'Failed to insert activity_log: ' . $e->getMessage(), ['action' => $action, 'user_id' => $userId]);
    }
}

function expire_stale(): void
{
    session_expire_stale();
}

function fetch_classrooms(array $f = []): array
{
    return room_fetch_all($f);
}

function get_room_with_status(int $id): ?array
{
    return room_get_with_status($id);
}

function get_room(int $id): ?array
{
    return room_get($id);
}

function get_room_by_token(string $token): ?array
{
    return room_get_by_token($token);
}

function generate_qr_token(): string
{
    return room_generate_token();
}

function extract_qr_token(?string $raw): ?string
{
    return room_extract_qr_token($raw);
}

function get_active_session_for(int $userId): ?array
{
    return session_get_active_for_user($userId);
}

function release_session(int $sessionId, string $via = 'lecturer'): bool
{
    $u = current_user();
    $userId = $u ? (int)$u['id'] : 0;
    $role = ($u && $u['role'] === 'admin') ? 'admin' : $via;
    $res = session_release($sessionId, $userId, $role);
    return (bool)($res['ok'] ?? false);
}

function fmt_time(?string $sqlDateTime): string
{
    if (!$sqlDateTime) {
        return '';
    }
    $t = strtotime($sqlDateTime);
    return $t === false ? '' : date('g:i A', $t);
}

function fmt_date(?string $sqlDateTime): string
{
    if (!$sqlDateTime) {
        return '';
    }
    $t = strtotime($sqlDateTime);
    return $t === false ? '' : date('M j, Y', $t);
}

function fmt_range(?string $start, ?string $end): string
{
    return fmt_time($start) . ' – ' . fmt_time($end);
}

function fmt_iso(?string $sqlDateTime): string
{
    if (!$sqlDateTime) {
        return '';
    }
    $t = strtotime($sqlDateTime);
    return $t === false ? '' : date('Y-m-d\TH:i:sP', $t);
}

function minutes_between(string $start, string $end): int
{
    $s = strtotime($start);
    $e = strtotime($end);
    return ($s === false || $e === false) ? 0 : max(0, (int)(($e - $s) / 60));
}

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

function round_to_half_hour(?int $ts = null): int
{
    $ts ??= time();
    $minute = (int)date('i', $ts);
    $second = (int)date('s', $ts);
    $totalSecondsPastHour = ($minute * 60) + $second;
    $roundedHalfHour = (int)round($totalSecondsPastHour / 1800) * 1800;
    $hourTimestamp = strtotime(date('Y-m-d H:00:00', $ts));
    return $hourTimestamp + $roundedHalfHour;
}

function minutes_until(string $futureSqlDateTime): int
{
    $t = strtotime($futureSqlDateTime);
    return $t === false ? 0 : max(0, (int)ceil(($t - time()) / 60));
}

function get_scan_hours(): array
{
    $start = substr(trim(get_setting('scan_day_start', '07:00')), 0, 5);
    $end   = substr(trim(get_setting('scan_day_end', '19:00')), 0, 5);
    return [
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) ? $start : '07:00',
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) ? $end : '19:00',
    ];
}

function is_within_scan_hours(?int $time = null): bool
{
    $time ??= time();
    $current = date('H:i', $time);
    [$start, $end] = get_scan_hours();
    return $current >= $start && $current < $end;
}

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

function wants_csv(): bool
{
    return ($_GET['export'] ?? '') === 'csv';
}

function stream_csv(string $filename, array $headers, iterable $rows)
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
            return preg_match('/^\s*[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        }, array_values((array)$row)));
    }
    fclose($out);
    exit;
}

function json_response(array $data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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
