<?php
declare(strict_types=1);

/**
 * Classroom Finder - Debug & Error Handling Subsystem
 *
 * Provides:
 * - Environment detection (CF_DEBUG)
 * - Centralized file logging (app_log)
 * - Exception, fatal error, and warning handlers
 * - Interactive visual debug inspector in development
 * - Secure error responses and Error IDs in production
 */

if (!defined('CF_DEBUG')) {
    $serverName = (string)($_SERVER['SERVER_NAME'] ?? '');
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $isLocal = in_array($serverName, ['localhost', '127.0.0.1', '::1', 'classroom-finder.test', 'classroom_finder.test'], true)
        || in_array($remoteAddr, ['127.0.0.1', '::1'], true)
        || (getenv('APP_DEBUG') === 'true')
        || (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true');

    define('CF_DEBUG', $isLocal);
}

if (CF_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0'); // Rendered via our custom exception & fatal handlers
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

/**
 * Central application logger.
 * Writes timestamped entries to logs/app.log and logs/app-YYYY-MM-DD.log.
 */
function app_log(string $level, string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }

    $time   = date('Y-m-d H:i:s');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $uri    = $_SERVER['REQUEST_URI'] ?? 'CLI';
    $ctx    = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

    $entry = sprintf("[%s] [%s] [%s %s] [IP: %s] %s%s\n", $time, strtoupper($level), $method, $uri, $ip, $message, $ctx);

    @file_put_contents($dir . '/app.log', $entry, FILE_APPEND | LOCK_EX);
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Determine if current request expects JSON response.
 */
function cf_is_json_request(): bool
{
    if (defined('CF_WANTS_JSON') && CF_WANTS_JSON) {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $ct     = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $uri    = (string)($_SERVER['REQUEST_URI'] ?? '');

    return stripos($accept, 'application/json') !== false
        || stripos($ct, 'application/json') !== false
        || (bool)preg_match('#/api/#', $uri);
}

/**
 * Extract source code context around a given file line.
 */
function cf_get_code_snippet(string $filePath, int $line, int $radius = 6): ?array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return null;
    }
    $lines = @file($filePath);
    if ($lines === false) {
        return null;
    }
    $total = count($lines);
    $start = max(1, $line - $radius);
    $end   = min($total, $line + $radius);

    $snippet = [];
    for ($i = $start; $i <= $end; $i++) {
        $snippet[$i] = [
            'line'      => $i,
            'code'      => rtrim($lines[$i - 1], "\r\n"),
            'is_target' => ($i === $line),
        ];
    }
    return $snippet;
}

/**
 * Render developer-friendly visual debug inspector page.
 */
function cf_render_debug_page(Throwable $e, string $errorId): void
{
    $class   = get_class($e);
    $message = $e->getMessage();
    $file    = $e->getFile();
    $line    = $e->getLine();
    $trace   = $e->getTrace();
    $snippet = cf_get_code_snippet($file, $line);

    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri     = $_SERVER['REQUEST_URI'] ?? '';
    $get     = $_GET;
    $post    = $_POST;
    $session = $_SESSION ?? [];

    // Clear output buffer to ensure clean debug display
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' — Debug Inspector</title>
  <style>
    :root {
      --bg: #090d16;
      --card: #111827;
      --border: #1f2937;
      --text: #f3f4f6;
      --muted: #9ca3af;
      --danger: #ef4444;
      --danger-bg: rgba(239, 68, 68, 0.12);
      --highlight: rgba(239, 68, 68, 0.22);
      --accent: #3b82f6;
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      line-height: 1.5;
      padding: 1.5rem;
    }
    .wrap { max-width: 72rem; margin: 0 auto; }
    .badge {
      display: inline-block;
      font-size: .75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
      padding: .2rem .55rem;
      border-radius: 4px;
      background: var(--danger-bg);
      color: var(--danger);
      border: 1px solid var(--danger);
    }
    .header {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 4px 12px rgba(0,0,0,.4);
    }
    .header h1 {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--danger);
      margin: .5rem 0 .25rem;
      word-break: break-word;
    }
    .header .msg {
      font-size: 1.05rem;
      color: var(--text);
      font-family: var(--mono);
      background: #000;
      padding: .75rem 1rem;
      border-radius: 6px;
      border-left: 4px solid var(--danger);
      margin-top: .75rem;
      overflow-x: auto;
    }
    .meta-line {
      font-size: .85rem;
      color: var(--muted);
      margin-top: .5rem;
      font-family: var(--mono);
    }
    .meta-line strong { color: #e5e7eb; }
    .box {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-bottom: 1.5rem;
      overflow: hidden;
    }
    .box-title {
      background: #1a2234;
      padding: .65rem 1rem;
      font-size: .85rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #cbd5e1;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .snippet {
      font-family: var(--mono);
      font-size: .82rem;
      background: #0d1117;
      overflow-x: auto;
      padding: .5rem 0;
    }
    .snippet-line {
      display: flex;
      padding: .15rem 1rem;
      white-space: pre;
    }
    .snippet-line.target {
      background: var(--highlight);
      border-left: 4px solid var(--danger);
      font-weight: 700;
      color: #fff;
    }
    .line-no {
      color: #64748b;
      min-width: 3.5rem;
      user-select: none;
    }
    .trace-item {
      padding: .75rem 1rem;
      border-bottom: 1px solid var(--border);
      font-family: var(--mono);
      font-size: .82rem;
    }
    .trace-item:last-child { border-bottom: none; }
    .trace-fn { color: var(--accent); font-weight: 600; }
    .trace-loc { color: var(--muted); font-size: .78rem; margin-top: .15rem; }
    table.data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .82rem;
      font-family: var(--mono);
    }
    table.data-table th, table.data-table td {
      padding: .5rem 1rem;
      border-bottom: 1px solid var(--border);
      text-align: left;
    }
    table.data-table th { color: var(--muted); width: 30%; background: #0e1420; }
    table.data-table td { word-break: break-all; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span class="badge">' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '</span>
        <span class="meta-line">Ref ID: <strong>' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</strong></span>
      </div>
      <h1>' . htmlspecialchars($message !== '' ? $message : 'Exception thrown without message', ENT_QUOTES, 'UTF-8') . '</h1>
      <div class="meta-line">Thrown in <strong>' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</strong> on line <strong>' . $line . '</strong></div>
    </div>';

    if ($snippet !== null) {
        echo '
    <div class="box">
      <div class="box-title">Source Context — ' . htmlspecialchars(basename($file), ENT_QUOTES, 'UTF-8') . '</div>
      <div class="snippet">';
        foreach ($snippet as $s) {
            $cls = $s['is_target'] ? 'snippet-line target' : 'snippet-line';
            echo '<div class="' . $cls . '"><span class="line-no">' . $s['line'] . '</span><span>' . htmlspecialchars($s['code'], ENT_QUOTES, 'UTF-8') . '</span></div>';
        }
        echo '
      </div>
    </div>';
    }

    echo '
    <div class="box">
      <div class="box-title">Stack Trace (' . count($trace) . ' frames)</div>';
    if (empty($trace)) {
        echo '<div class="trace-item" style="color:var(--muted)">No stack frames available.</div>';
    } else {
        foreach ($trace as $idx => $t) {
            $tFile = $t['file'] ?? '[internal function]';
            $tLine = isset($t['line']) ? ':' . $t['line'] : '';
            $tClass = $t['class'] ?? '';
            $tType = $t['type'] ?? '';
            $tFn = $t['function'] ?? '';
            $call = $tClass . $tType . $tFn . '()';

            echo '
      <div class="trace-item">
        <span style="color:#64748b;margin-right:.5rem;">#' . $idx . '</span>
        <span class="trace-fn">' . htmlspecialchars($call, ENT_QUOTES, 'UTF-8') . '</span>
        <div class="trace-loc">' . htmlspecialchars($tFile . $tLine, ENT_QUOTES, 'UTF-8') . '</div>
      </div>';
        }
    }
    echo '
    </div>

    <div class="box">
      <div class="box-title">Request Environment</div>
      <table class="data-table">
        <tr><th>Request URI</th><td>' . htmlspecialchars($method . ' ' . $uri, ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Client IP</th><td>' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>PHP Version</th><td>' . PHP_VERSION . '</td></tr>
        <tr><th>GET Params</th><td>' . htmlspecialchars(json_encode($get), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>POST Params</th><td>' . htmlspecialchars(json_encode($post), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Session Data</th><td>' . htmlspecialchars(json_encode($session), ENT_QUOTES, 'UTF-8') . '</td></tr>
      </table>
    </div>
  </div>
</body>
</html>';
    exit;
}

/**
 * Global Exception Handler.
 */
function cf_exception_handler(Throwable $e): void
{
    $errorId = 'err_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
    $msg = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());

    app_log('CRITICAL', $msg, [
        'error_id' => $errorId,
        'trace'    => explode("\n", $e->getTraceAsString()),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (cf_is_json_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = [
            'ok'       => false,
            'error'    => CF_DEBUG ? $e->getMessage() : 'An unexpected server error occurred.',
            'error_id' => $errorId,
            'code'     => 500,
        ];
        if (CF_DEBUG) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => explode("\n", $e->getTraceAsString()),
            ];
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (CF_DEBUG) {
        cf_render_debug_page($e, $errorId);
    } else {
        $_GET['code'] = 500;
        $_GET['message'] = 'An unexpected error occurred. Reference ID: ' . $errorId;
        $errorPage = dirname(__DIR__) . '/error.php';
        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo '<h1>Internal Server Error</h1><p>Reference: ' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        exit;
    }
}

/**
 * Standard PHP Error Handler (converts warnings/notices or logs them).
 */
function cf_error_handler(int $level, string $message, string $file, int $line): bool
{
    if (!(error_reporting() & $level)) {
        return false;
    }

    $isFatal = in_array($level, [E_USER_ERROR, E_RECOVERABLE_ERROR], true);
    if ($isFatal || (CF_DEBUG && in_array($level, [E_WARNING, E_USER_WARNING], true))) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }

    app_log('WARNING', sprintf('PHP [%d]: %s in %s:%d', $level, $message, $file, $line));
    return true;
}

/**
 * Shutdown Handler: intercepts Fatal Errors before they white-screen.
 */
function cf_shutdown_handler(): void
{
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        cf_exception_handler(new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
    }
}

// Register global handlers
set_exception_handler('cf_exception_handler');
set_error_handler('cf_error_handler');
register_shutdown_function('cf_shutdown_handler');
