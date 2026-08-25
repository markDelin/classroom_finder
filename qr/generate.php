<?php
declare(strict_types=1);

/**
 * Classroom Finder — classroom QR image generator (§9, §20).
 *
 * GET qr/generate.php?id=<classroom id>[&size=8]
 *
 * Admin-only: the QR payload contains the room's secret token, so the image
 * is never served to anonymous visitors. Uses the bundled phpqrcode library;
 * emits a PNG when the PHP GD extension is available (stock XAMPP), otherwise
 * a crisp SVG that prints just as well.
 */

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';

$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Admin login required to view QR codes.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing classroom id.');
}

$st = db()->prepare('SELECT id, room_number, building, qr_token FROM classrooms WHERE id = ? LIMIT 1');
$st->execute([$id]);
$room = $st->fetch();
if (!$room) {
    http_response_code(404);
    exit('Classroom not found.');
}

// Human-readable + machine-parseable payload: the token is what matters,
// extract_qr_token() finds it even inside this labelled string.
$payload = 'CLASSROOM-FINDER | ' . $room['building'] . ' ' . $room['room_number'] . ' | TOKEN:' . $room['qr_token'];

$size   = min(12, max(3, (int)($_GET['size'] ?? 6)));
$margin = 2;

// phpqrcode is an older library: silence its PHP 8 deprecation notices so
// they never leak into (or corrupt) the image stream.
$oldLevel = error_reporting();
error_reporting($oldLevel & ~(E_DEPRECATED | E_NOTICE | E_WARNING));
require_once __DIR__ . '/lib/qrlib.php';

// make sure the library's frame cache directory exists / is writable
if (defined('QR_CACHE_DIR') && !is_dir(QR_CACHE_DIR)) {
    @mkdir(QR_CACHE_DIR, 0775, true);
}

header('Cache-Control: private, max-age=0, no-cache');

if (function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/png');
    QRcode::png($payload, false, QR_ECLEVEL_M, $size, $margin);
} else {
    header('Content-Type: image/svg+xml');
    QRcode::svg($payload, false, QR_ECLEVEL_M, $size, $margin);
}
error_reporting($oldLevel);
