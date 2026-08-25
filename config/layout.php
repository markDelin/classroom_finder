<?php
declare(strict_types=1);

/**
 * Classroom Finder — shared page chrome.
 *
 * render_header() / render_footer() wrap every page so admin, lecturer and
 * public pages share one design. room_card()/room_cards_html() render the
 * landing-page cards; api/classroom_status.php re-uses them for live refresh.
 */

require_once __DIR__ . '/helpers.php';

/** Label / Lucide icon / CSS class per computed status. */
function room_status_meta(string $status): array
{
    return match ($status) {
        'occupied'    => ['OCCUPIED',    'clock',          'danger'],
        'reserved'    => ['RESERVED',    'calendar-clock', 'warn'],
        'unavailable' => ['UNAVAILABLE', 'ban',            'off'],
        default       => ['AVAILABLE',   'circle-check',   'ok'],
    };
}

/**
 * Open a full HTML document.
 * $opts: nav      'admin'|'lecturer'|null  (shows sidebar)
 *        active   key of the current nav item
 *        prefix   '../' for pages inside subfolders, '' at the root
 *        wide     true = no sidebar constraint (landing page)
 */
function render_header(string $title, array $opts = []): void
{
    $prefix  = $opts['prefix'] ?? '';
    $GLOBALS['cf_prefix'] = $prefix; // render_footer() reuses it for script paths
    $nav     = $opts['nav'] ?? null;
    $active  = $opts['active'] ?? '';
    $user    = function_exists('current_user') ? current_user() : null;
    $school  = get_setting('school_name', APP_NAME);
    $logo    = get_setting('school_logo', '');
    $logoPath = $prefix . 'assets/uploads/' . $logo;
    $flashes = take_flashes();

    $menus = [
        'admin' => [
            ['key' => 'dashboard',    'label' => 'Dashboard',        'href' => 'dashboard.php',    'icon' => 'layout-dashboard'],
            ['key' => 'users',        'label' => 'Users',            'href' => 'users.php',        'icon' => 'users'],
            ['key' => 'classrooms',   'label' => 'Classrooms',       'href' => 'classrooms.php',   'icon' => 'door-open'],
            ['key' => 'qr',           'label' => 'QR Codes',         'href' => 'qr_codes.php',     'icon' => 'qr-code'],
            ['key' => 'sessions',     'label' => 'Active Sessions',  'href' => 'sessions.php',     'icon' => 'clock'],
            ['key' => 'reservations', 'label' => 'Reservations',     'href' => 'reservations.php', 'icon' => 'calendar-clock'],
            ['key' => 'history',      'label' => 'Usage History',    'href' => 'history.php',      'icon' => 'history'],
            ['key' => 'logs',         'label' => 'Activity Logs',    'href' => 'logs.php',         'icon' => 'file-text'],
            ['key' => 'settings',     'label' => 'Settings',         'href' => 'settings.php',     'icon' => 'settings'],
        ],
        'lecturer' => [
            ['key' => 'scanner',   'label' => 'Scan QR Code', 'href' => 'scanner.php',   'icon' => 'scan-line'],
            ['key' => 'dashboard', 'label' => 'My Dashboard', 'href' => 'dashboard.php', 'icon' => 'layout-dashboard'],
            ['key' => 'history',   'label' => 'My History',   'href' => 'history.php',   'icon' => 'history'],
            ['key' => 'finder',    'label' => 'Find Rooms',   'href' => $prefix . 'index.php', 'icon' => 'search'],
        ],
    ];

    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= $prefix ?>assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="<?= $nav ? 'has-sidebar' : '' ?>" data-prefix="<?= $prefix ?>">
<header class="topbar">
  <?php if ($nav && isset($menus[$nav])): ?>
  <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="cfSidebar" aria-label="Menu">
    <span class="nav-toggle__icon nav-toggle__icon--open"><?= icon('menu') ?></span>
    <span class="nav-toggle__icon nav-toggle__icon--close"><?= icon('x') ?></span>
  </button>
  <?php endif; ?>
  <a class="brand" href="<?= $prefix ?>index.php"><?php if ($logo !== '' && is_file(__DIR__ . '/../assets/uploads/' . $logo)): ?>
    <img class="brand__logo" src="<?= e($logoPath) ?>" alt="">
  <?php else: ?><span class="brand__dot"></span><?php endif; ?> <?= e(APP_NAME) ?></a>
  <div class="topbar__right">
    <?php if ($user): ?>
      <span class="user-chip" title="<?= e($user['username']) ?>">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
        <span class="user-chip__name"><?= e($user['full_name']) ?>
          <small><?= e(ucfirst($user['role'])) ?></small></span>
      </span>
      <form method="post" action="<?= $prefix ?>logout.php" class="inline-form">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--sm" type="submit"
                title="Log out" aria-label="Log out"><span class="btn__txt">Log out</span> <?= icon('log-out') ?></button>
      </form>
    <?php else: ?>
      <a class="btn btn--ghost btn--sm" href="<?= $prefix ?>login.php"><?= icon('log-in') ?> <span class="btn__txt">Log in</span></a>
    <?php endif; ?>
  </div>
</header>
<?php if ($flashes): ?>
<div class="flashes">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash--<?= e($f['t']) ?>">
      <?= icon($f['t'] === 'success' ? 'circle-check' : ($f['t'] === 'error' ? 'triangle-alert' : 'info')) ?>
      <span><?= e($f['m']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($nav && isset($menus[$nav])): ?>
<nav class="sidebar" id="cfSidebar">
  <ul>
    <?php foreach ($menus[$nav] as $item): ?>
      <li><a class="<?= $item['key'] === $active ? 'is-active' : '' ?>"
             href="<?= $item['href'] ?>"><?= !empty($item['icon']) ? icon($item['icon']) : '' ?> <span><?= e($item['label']) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<main class="main main--with-nav">
<?php else: ?>
<main class="main">
<?php endif; ?>
<?php
}

/** Close the document; $scripts are extra JS files appended with the same prefix. */
function render_footer(array $scripts = []): void
{
    $prefix = $GLOBALS['cf_prefix'] ?? '';
    ?></main>
<script src="<?= $prefix ?>assets/js/vendor/sweetalert2.all.min.js"></script>
<script src="<?= $prefix ?>assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<?php foreach ($scripts as $src): ?>
<?php $cf_js = __DIR__ . '/../assets/js/' . basename($src); // pages pass "assets/js/x.js" ?>
<script src="<?= $prefix . e($src) ?>?v=<?= is_file($cf_js) ? filemtime($cf_js) : 0 ?>"></script>
<?php endforeach; ?>
</body>
</html><?php
}

/* ==========================================================================
 * Landing-page room cards (§12)
 * ========================================================================*/

function room_card(array $r, int $i = 0): string
{
    [$label, $statusIcon, $cls] = room_status_meta($r['computed']);
    ob_start(); ?>
<article class="room-card st-<?= e($r['computed']) ?>" data-room-id="<?= (int)$r['id'] ?>" style="--i: <?= $i % 12 ?>">
  <div class="room-card__top">
    <div class="room-card__head">
      <h3 class="room-card__no"><?= e($r['room_number']) ?></h3>
      <p class="room-card__loc"><?= e($r['building']) ?> · Floor <?= (int)$r['floor'] ?></p>
    </div>
    <span class="pill pill--<?= $cls ?>"><?= icon($statusIcon) ?> <?= $label ?></span>
  </div>

  <div class="room-card__meta">
    <span class="room-card__type"><?= e($r['room_type']) ?></span>
    <span class="room-card__cap" title="Capacity"><?= icon('users') ?> <?= (int)$r['capacity'] ?> seats</span>
  </div>

  <div class="room-card__foot">
    <?php if ($r['computed'] === 'occupied'): ?>
      <div class="room-card__occupied-info">
        <p class="room-card__who"><?= icon('user') ?> <?= e($r['session_lecturer'] ?? 'Lecturer') ?></p>
        <div class="room-card__timing">
          <span class="room-card__when"><?= fmt_range($r['session_start'], $r['session_end']) ?></span>
          <span class="room-card__free" data-free-at="<?= e(fmt_iso($r['available_at'])) ?>">Free soon…</span>
        </div>
      </div>
    <?php elseif ($r['computed'] === 'reserved'): ?>
      <div class="room-card__reserved-info">
        <p class="room-card__who"><?= icon('calendar-days') ?> <?= !empty($r['reservation_purpose']) ? e($r['reservation_purpose']) : 'Reserved' ?></p>
        <div class="room-card__timing">
          <span class="room-card__when"><?= fmt_range($r['reservation_start'], $r['reservation_end']) ?></span>
          <span class="room-card__free" data-free-at="<?= e(fmt_iso($r['reservation_start'])) ?>">Starts soon…</span>
        </div>
      </div>
    <?php elseif ($r['computed'] === 'unavailable'): ?>
      <p class="room-card__off-note"><?= icon('ban') ?> <?= e($r['note'] ?: ($r['status'] === 'maintenance' ? 'Under maintenance' : 'Temporarily disabled')) ?></p>
    <?php else: ?>
      <p class="room-card__open"><?= icon('circle-check') ?> Available to use</p>
    <?php endif; ?>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

function room_cards_html(array $rooms): string
{
    if (!$rooms) {
        return '<div class="empty-state"><p>' . icon('search-x') . ' No classrooms match your search.</p>'
             . '<small>Try clearing a filter or a different keyword.</small></div>';
    }
    $html = '';
    foreach ($rooms as $i => $r) {
        $html .= room_card($r, $i);
    }
    return $html;
}

/**
 * Landing-list order: vacant rooms first — that's what people scan for —
 * then soon-reserved, busy, and finally maintenance/disabled. Building +
 * room number break ties inside each band.
 */
function sort_rooms_available_first(array $rooms): array
{
    $band = ['available' => 0, 'reserved' => 1, 'occupied' => 2, 'unavailable' => 3];
    usort($rooms, static function (array $a, array $b) use ($band): int {
        return [
            $band[$a['computed']] ?? 9,
            $a['building'],
            $a['room_number'],
        ] <=> [
            $band[$b['computed']] ?? 9,
            $b['building'],
            $b['room_number'],
        ];
    });
    return $rooms;
}

const ROOMS_PER_PAGE = 10;

/**
 * Slice the sorted landing list into pages of ROOMS_PER_PAGE.
 * Returns the page's rooms plus the metadata the pager and counters need.
 *
 * @return array{rooms:array, page:int, pages:int, total:int}
 */
function room_page(array $rooms, int $page): array
{
    $rooms = sort_rooms_available_first($rooms);
    $pages = max(1, (int)ceil(count($rooms) / ROOMS_PER_PAGE));
    $page  = max(1, min($page, $pages));
    return [
        'rooms' => array_slice($rooms, ($page - 1) * ROOMS_PER_PAGE, ROOMS_PER_PAGE),
        'page'  => $page,
        'pages' => $pages,
        'total' => count($rooms),
    ];
}

/**
 * ‹ Prev · Page x of y · Next › under the landing grid.
 * $hrefFor maps a page number to a URL; pass null to render JS-only buttons
 * (the live-refresh path, where filters live in the form, not the URL).
 */
function room_pager_html(int $total, int $page, ?callable $hrefFor = null): string
{
    $pages = max(1, (int)ceil($total / ROOMS_PER_PAGE));
    if ($pages <= 1) {
        return '';
    }
    $ctrl = static function (string $label, int $target, bool $off) use ($hrefFor): string {
        if ($off) {
            return '<span class="pager-btn is-off" aria-disabled="true">' . $label . '</span>';
        }
        $go = ' data-page-go="' . $target . '"';
        return $hrefFor === null
            ? '<button class="pager-btn" type="button"' . $go . '>' . $label . '</button>'
            : '<a class="pager-btn" href="' . e($hrefFor($target)) . '"' . $go . '>' . $label . '</a>';
    };
    return '<nav class="room-pager" aria-label="Room pages">'
        . $ctrl('‹ Prev', max(1, $page - 1), $page <= 1)
        . '<span class="muted small">Page ' . $page . ' of ' . $pages . '</span>'
        . $ctrl('Next ›', min($pages, $page + 1), $page >= $pages)
        . '</nav>';
}

/* ==========================================================================
 * Admin-list pagination (shared by every admin section)
 * ========================================================================*/

const ADMIN_PER_PAGE = 10;

/**
 * Clamp+slice parameters for an admin listing.
 *
 * @return array{page:int, pages:int, offset:int, limit:int}
 */
function page_params(int $total, int $page, int $perPage = ADMIN_PER_PAGE): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    return [
        'page'   => $page,
        'pages'  => $pages,
        'offset' => ($page - 1) * $perPage,
        'limit'  => $perPage,
    ];
}

/**
 * Numbered ‹ Prev … Next › pager for admin tables. Preserves every current
 * query-string value except the page itself, so filters survive navigation.
 * Renders nothing while everything fits on one page.
 */
function page_nav(int $total, int $page, int $perPage = ADMIN_PER_PAGE, string $param = 'page'): string
{
    $pp   = page_params($total, $page, $perPage);
    if ($pp['pages'] <= 1) {
        return '';
    }
    $qs   = $_GET;
    unset($qs[$param]);
    $url  = static fn(int $p): string => '?' . e(http_build_query(array_merge($qs, [$param => $p])));
    $link = static fn(int $p, string $label, bool $current = false): string =>
        '<a class="' . ($current ? 'is-active' : '') . '" href="' . $url($p) . '">' . $label . '</a>';

    $start = max(1, min($pp['page'] - 2, $pp['pages'] - 4));
    $end   = min($pp['pages'], $start + 4);

    $html = '<nav class="pager">';
    $html .= $pp['page'] > 1
        ? $link($pp['page'] - 1, '‹ Prev')
        : '<span class="is-off">‹ Prev</span>';
    if ($start > 1) {
        $html .= $link(1, '1');
        if ($start > 2) {
            $html .= '<span class="dots">…</span>';
        }
    }
    for ($p = $start; $p <= $end; $p++) {
        $html .= $link($p, (string)$p, $p === $pp['page']);
    }
    if ($end < $pp['pages']) {
        if ($end < $pp['pages'] - 1) {
            $html .= '<span class="dots">…</span>';
        }
        $html .= $link($pp['pages'], (string)$pp['pages']);
    }
    $html .= $pp['page'] < $pp['pages']
        ? $link($pp['page'] + 1, 'Next ›')
        : '<span class="is-off">Next ›</span>';
    return $html . '</nav>';
}
