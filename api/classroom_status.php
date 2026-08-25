<?php
declare(strict_types=1);

/**
 * Classroom Finder — live classroom status feed (§12, landing auto-refresh).
 *
 * GET api/classroom_status.php
 *   Optional filters: q, status, building, floor, type, mincap, id
 *   format=html -> ready-to-insert card fragment (used by landing.js)
 *   default     -> JSON: { ok, server_now, count, rooms:[...] }
 */

define('CF_WANTS_JSON', true);
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/layout.php';

$filters = [
    'q'        => trim((string)($_GET['q'] ?? '')),
    'status'   => (string)($_GET['status'] ?? ''),
    'building' => (string)($_GET['building'] ?? ''),
    'floor'    => (string)($_GET['floor'] ?? ''),
    'type'     => (string)($_GET['type'] ?? ''),
    'mincap'   => (string)($_GET['mincap'] ?? ''),
];

if (!empty($_GET['id'])) {
    $rooms = [];
    $one   = get_room((int)$_GET['id']);
    if ($one) {
        $rooms = [$one];
    }
} else {
    $rooms = fetch_classrooms($filters);
}

// available-first ordering + ?page slicing (same as the initial page render)
$pg = room_page($rooms, (int)($_GET['page'] ?? 1));

if (($_GET['format'] ?? '') === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo room_cards_html($pg['rooms']), room_pager_html($pg['total'], $pg['page']);
    exit;
}

// Whole-campus counters honour the search/attribute filters but not the
// status chip, so students always see how many rooms exist per status.
$stats = ['available' => 0, 'occupied' => 0, 'reserved' => 0, 'unavailable' => 0];
foreach (fetch_classrooms(array_diff_key($filters, ['status' => ''])) as $r) {
    $stats[$r['computed']]++;
}

json_response([
    'ok'         => true,
    'server_now' => date('c'),
    'count'      => $pg['total'],
    'page'       => $pg['page'],
    'pages'      => $pg['pages'],

    // Pre-rendered card markup so the landing page needs one request per refresh.
    'html'  => ($_GET['with_html'] ?? '') === '1'
        ? room_cards_html($pg['rooms']) . room_pager_html($pg['total'], $pg['page'])
        : null,
    'stats' => $stats,

    'rooms'      => array_map(function (array $r): array {
        return [
            'id'          => (int)$r['id'],
            'room_number' => $r['room_number'],
            'building'    => $r['building'],
            'floor'       => (int)$r['floor'],
            'capacity'    => (int)$r['capacity'],
            'room_type'   => $r['room_type'],
            'status'      => $r['computed'],          // available|occupied|reserved|unavailable
            'note'        => $r['note'],
            'available_at'=> $r['available_at'],
            'session'     => empty($r['session_id']) ? null : [
                'lecturer' => $r['session_lecturer'],
                'start'    => $r['session_start'],
                'end'      => $r['session_end'],
            ],
            'reservation' => empty($r['reservation_id']) ? null : [
                'purpose' => $r['reservation_purpose'],
                'by'      => $r['reservation_by'],
                'start'   => $r['reservation_start'],
                'end'     => $r['reservation_end'],
            ],
        ];
    }, $rooms),
]);
