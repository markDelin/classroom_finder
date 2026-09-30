<?php
declare(strict_types=1);

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
    $one   = room_get_with_status((int)$_GET['id']);
    if ($one) {
        $rooms = [$one];
    }
} else {
    $rooms = room_fetch_all($filters);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$cumulative = !empty($_GET['cumulative']);

if ($cumulative) {
    $roomsSorted = sort_rooms_available_first($rooms);
    $total = count($roomsSorted);
    $pages = max(1, (int)ceil($total / ROOMS_PER_PAGE));
    $page = max(1, min($page, $pages));
    $pg = [
        'rooms' => array_slice($roomsSorted, 0, $page * ROOMS_PER_PAGE),
        'page'  => $page,
        'pages' => $pages,
        'total' => $total,
    ];
} else {
    $pg = room_page($rooms, $page);
}

if (($_GET['format'] ?? '') === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<section id="roomGrid" class="room-grid" data-refresh="' . get_setting_int('landing_refresh_seconds', 15) . '" data-page="' . $pg['page'] . '">'
       . room_cards_html($pg['rooms'])
       . '</section>'
       . room_pager_html($pg['total'], $pg['page']);
    exit;
}

$stats = ['available' => 0, 'occupied' => 0, 'unavailable' => 0];
foreach (room_fetch_all(array_diff_key($filters, ['status' => ''])) as $r) {
    $stats[$r['computed']]++;
}

json_response([
    'ok'         => true,
    'server_now' => date('c'),
    'count'      => $pg['total'],
    'page'       => $pg['page'],
    'pages'      => $pg['pages'],
    'has_more'   => $pg['page'] < $pg['pages'],
    'cards_html' => room_cards_html($pg['rooms']),
    'pager_html' => room_pager_html($pg['total'], $pg['page']),
    'html'  => ($_GET['with_html'] ?? '') === '1'
        ? '<section id="roomGrid" class="room-grid" data-refresh="' . get_setting_int('landing_refresh_seconds', 15) . '" data-page="' . $pg['page'] . '">'
        . room_cards_html($pg['rooms'])
        . '</section>'
        . room_pager_html($pg['total'], $pg['page'])
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
            'status'      => $r['computed'],
            'note'        => $r['note'],
            'available_at'=> $r['available_at'],
            'session'     => empty($r['session_id']) ? null : [
                'lecturer' => $r['session_lecturer'],
                'start'    => $r['session_start'],
                'end'      => $r['session_end'],
            ],
        ];
    }, $rooms),
]);
