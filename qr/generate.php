<?php
declare(strict_types=1);

// QR Generator: renders PNG or SVG QR code images for classroom tokens (admin authenticated).
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';

// Guard: admin authorization required
$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Admin login required to view QR codes.');
}

// Validate classroom ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing classroom id.');
}

// Fetch classroom details and token
$st = db()->prepare('SELECT id, room_number, building, qr_token FROM classrooms WHERE id = ? LIMIT 1');
$st->execute([$id]);
$room = $st->fetch();
if (!$room) {
    http_response_code(404);
    exit('Classroom not found.');
}

// Build standard token payload
$payload = $room['building'] . ' ' . $room['room_number'] . ' | TOKEN:' . $room['qr_token'];

$size   = min(12, max(3, (int)($_GET['size'] ?? 6)));
$margin = 2;

// Load bundled QR generation library
$oldLevel = error_reporting();
error_reporting($oldLevel & ~(E_DEPRECATED | E_NOTICE | E_WARNING));
require_once __DIR__ . '/lib/qrlib.php';

$cacheDir = defined('QR_CACHE_DIR') ? constant('QR_CACHE_DIR') : null;
if (is_string($cacheDir) && !is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

header('Cache-Control: private, max-age=0, no-cache');
$qrFilename = 'Classroom-QR-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$room['room_number']);
$isAttachment = isset($_GET['download']) ? 'attachment' : 'inline';

// Stream PNG if GD library is available, otherwise stream vector SVG
if (function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/png');
    header('Content-Disposition: ' . $isAttachment . '; filename="' . $qrFilename . '.png"');
    QRcode::png($payload, false, QR_ECLEVEL_M, $size, $margin);
} else {
    header('Content-Type: image/svg+xml');
    header('Content-Disposition: ' . $isAttachment . '; filename="' . $qrFilename . '.svg"');
    QRcode::svg($payload, false, QR_ECLEVEL_M, $size, $margin);
}
error_reporting($oldLevel);
