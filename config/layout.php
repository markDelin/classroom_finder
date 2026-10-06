<?php
declare(strict_types=1);

// Layout: HTML shell templates (header, sidebar navigation, footer, room cards) and status presentation helpers.
require_once __DIR__ . '/helpers.php';

// Maps room status slug to badge label, icon, and styling class
function room_status_meta(string $status): array
{
    switch ($status) {
        case 'occupied':    return ['OCCUPIED',    'clock',        'danger'];
        case 'unavailable': return ['UNAVAILABLE', 'ban',          'off'];
        default:            return ['AVAILABLE',   'circle-check', 'ok'];
    }
}

// Emits HTML document head, responsive topbar, role-based sidebar, and flash notifications
function render_header(string $title, array $opts = []): void
{
    $prefix  = $opts['prefix'] ?? '';
    $GLOBALS['cf_prefix'] = $prefix;
    $nav     = $opts['nav'] ?? null;
    $active  = $opts['active'] ?? '';
    $user    = function_exists('current_user') ? current_user() : null;
    $logoFile = is_file(__DIR__ . '/../assets/img/logo.png')
        ? 'assets/img/logo.png'
        : (is_file(__DIR__ . '/../assets/uploads/logo-3146dc738a66.png') ? 'assets/uploads/logo-3146dc738a66.png' : '');
    $logoPath = $logoFile !== '' ? ($prefix . $logoFile) : '';
    $isPublic = !empty($opts['public']) || $active === 'landing' || $active === 'public-view' || ($prefix === '' && empty($nav) && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php');
    $flashes = take_flashes();

    if ($nav === null && $user && isset($user['role'])) {
        $nav = $user['role'];
        if ($active === '') {
            $active = 'finder';
        }
    }

    $adminHref = function (string $file) use ($prefix): string {
        return ($prefix === '' ? 'admin/' : '') . $file;
    };
    $lecturerHref = function (string $file) use ($prefix): string {
        return ($prefix === '' ? 'lecturer/' : '') . $file;
    };

    $menus = [
        'admin' => [
            ['key' => 'dashboard',    'label' => 'Dashboard',        'href' => $adminHref('dashboard.php'),    'icon' => 'layout-dashboard'],
            ['key' => 'users',        'label' => 'Users',            'href' => $adminHref('users.php'),        'icon' => 'users'],
            ['key' => 'classrooms',   'label' => 'Classrooms',       'href' => $adminHref('classrooms.php'),   'icon' => 'door-open'],
            ['key' => 'qr',           'label' => 'QR Codes',         'href' => $adminHref('qr_codes.php'),     'icon' => 'qr-code'],
            ['key' => 'sessions',     'label' => 'Active Sessions',  'href' => $adminHref('sessions.php'),     'icon' => 'clock'],
            ['key' => 'schedules',    'label' => 'Print Schedules',  'href' => $adminHref('schedules.php'),    'icon' => 'calendar-days'],
            ['key' => 'history',      'label' => 'Usage History',    'href' => $adminHref('history.php'),      'icon' => 'history'],
            ['key' => 'reports',      'label' => 'Reports',          'href' => $adminHref('reports.php'),      'icon' => 'scroll-text'],
            ['key' => 'logs',         'label' => 'Activity Logs',    'href' => $adminHref('logs.php'),         'icon' => 'file-text'],
            ['key' => 'settings',     'label' => 'Settings',         'href' => $adminHref('settings.php'),     'icon' => 'settings'],
            ['key' => 'finder',       'label' => 'Find Rooms',       'href' => $prefix . 'index.php',          'icon' => 'search'],
        ],
        'lecturer' => [
            ['key' => 'scanner',         'label' => 'Scan QR Code',     'href' => $lecturerHref('scanner.php'),   'icon' => 'scan-line'],
            ['key' => 'dashboard',       'label' => 'My Dashboard',     'href' => $lecturerHref('dashboard.php'), 'icon' => 'layout-dashboard'],
            ['key' => 'history',         'label' => 'My History',       'href' => $lecturerHref('history.php'),   'icon' => 'history'],
            ['key' => 'change_password', 'label' => 'Change Password',  'href' => $prefix . 'change_password.php', 'icon' => 'key-round'],
            ['key' => 'finder',          'label' => 'Find Rooms',       'href' => $prefix . 'index.php',          'icon' => 'search'],
        ],
    ];

    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e(app_name()) ?></title>
<link rel="stylesheet" href="<?= $prefix ?>assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="manifest" href="<?= $prefix ?>manifest.json">
<meta name="theme-color" content="#ffffff">
</head>
<body class="<?= $nav ? 'has-sidebar' : '' ?><?= $active !== '' ? ' page--' . e($active) : '' ?><?= $isPublic ? ' page--public-view' : '' ?>" data-prefix="<?= $prefix ?>">
<!-- Loading spinner commented out
<noscript><style>#pageLoader{display:none!important}</style></noscript>
<div id="pageLoader" class="page-loader" aria-hidden="true">
  <div class="page-loader__spinner"></div>
</div>
-->
<header class="topbar">
  <?php if ($nav && isset($menus[$nav])): ?>
  <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="cfSidebar" aria-label="Menu">
    <span class="nav-toggle__icon nav-toggle__icon--open"><?= icon('menu') ?></span>
    <span class="nav-toggle__icon nav-toggle__icon--close"><?= icon('x') ?></span>
  </button>
  <?php endif; ?>
  <a class="brand" href="<?= $prefix ?>index.php"><?php if ($logoPath !== ''): ?>
    <img class="brand__logo" src="<?= e($logoPath) ?>" alt="<?= e(school_name() ?: app_name()) ?> logo">
  <?php else: ?><span class="brand__dot"></span><?php endif; ?> <?= e(app_name()) ?></a>
  <div class="topbar__right">
    <?php if ($user): ?>
      <?php
        $userChipHref = ($user['role'] === 'admin') ? $adminHref('users.php') : ($prefix . 'change_password.php');
        $userChipTitle = ($user['role'] === 'admin') ? ('Users (' . e($user['username']) . ')') : ('Change Password (' . e($user['username']) . ')');
      ?>
      <a href="<?= $userChipHref ?>" class="user-chip" title="<?= $userChipTitle ?>">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
        <span class="user-chip__name"><?= e($user['full_name']) ?>
          <small><?= e(ucfirst($user['role'])) ?></small></span>
      </a>
    <?php else: ?>
      <a class="btn btn--ghost btn--sm" href="<?= $prefix ?>login.php"><?= icon('log-in') ?> <span class="btn__txt">Log in</span></a>
    <?php endif; ?>
  </div>
</header>
<?php if ($nav && isset($menus[$nav])): ?>
<nav class="sidebar" id="cfSidebar">
  <ul>
    <?php foreach ($menus[$nav] as $item): ?>
      <li><a class="<?= $item['key'] === $active ? 'is-active' : '' ?>"
             href="<?= $item['href'] ?>"><?= !empty($item['icon']) ? icon($item['icon']) : '' ?> <span><?= e($item['label']) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($user): ?>
  <div class="sidebar__footer">
    <form method="post" action="<?= $prefix ?>logout.php" class="sidebar__logout-form">
      <?= csrf_field() ?>
      <button class="sidebar__logout-btn" type="submit"
              title="Log out" aria-label="Log out"><?= icon('log-out') ?> <span>Log out</span></button>
    </form>
  </div>
  <?php endif; ?>
</nav>
<main class="main main--with-nav">
<?php else: ?>
<main class="main">
<?php endif; ?>
<?php if ($flashes): ?>
<div class="flashes">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash--<?= e($f['t']) ?>">
      <?= icon($f['t'] === 'success' ? 'circle-check' : ($f['t'] === 'error' ? 'triangle-alert' : 'info')) ?>
      <span><?= e($f['m']) ?></span>
      <button type="button" class="flash__close" aria-label="Dismiss">&times;</button>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
}

// Emits closing main tag, SweetAlert2 / UI scripts, and page-specific script tags
function render_footer(array $scripts = []): void
{
    $prefix = $GLOBALS['cf_prefix'] ?? '';
    ?></main>
<script src="<?= $prefix ?>assets/js/vendor/sweetalert2.all.min.js"></script>
<script src="<?= $prefix ?>assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<?php foreach ($scripts as $src): ?>
<?php $cf_js = __DIR__ . '/../assets/js/' . basename($src); ?>
<script src="<?= $prefix . e($src) ?>?v=<?= is_file($cf_js) ? filemtime($cf_js) : 0 ?>"></script>
<?php endforeach; ?>
</body>
</html><?php
}

// Generates HTML markup for an individual classroom status card
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

  <div class="room-card__foot">
    <?php if ($r['computed'] === 'occupied'): ?>
      <?php if (!empty($r['session_id'])): ?>
      <div class="room-card__occupied-info">
        <p class="room-card__who"><?= icon('user') ?> <?= e($r['session_lecturer'] ?? 'Lecturer') ?></p>
        <div class="room-card__timing">
          <span class="room-card__when" title="<?= e(fmt_range($r['session_start'], $r['session_end'])) ?>">Ends <?= fmt_time($r['session_end']) ?></span>
          <span class="room-card__free" data-free-at="<?= e(fmt_iso($r['available_at'])) ?>">Free soon…</span>
        </div>
        <?php
          $totalMins = minutes_between($r['session_start'], $r['session_end']);
          $elapsedMins = minutes_between($r['session_start'], date('Y-m-d H:i:s'));
          $pct = $totalMins > 0 ? min(100, max(0, (int)round(($elapsedMins / $totalMins) * 100))) : 0;
        ?>
        <div class="room-card__progress-track" title="Session progress: <?= $pct ?>%">
          <div class="room-card__progress-bar" style="width: <?= $pct ?>%;"></div>
        </div>
      </div>
      <?php else: ?>
      <div class="room-card__occupied-info">
        <p class="room-card__who"><?= icon('book-open') ?> <?= e($r['sched_subject']) ?><?= !empty($r['sched_section']) ? ' · ' . e($r['sched_section']) : '' ?></p>
        <div class="room-card__timing">
          <span class="room-card__when" title="<?= e(fmt_range($r['sched_start'], $r['sched_end'])) ?>">Ends <?= fmt_time($r['sched_end']) ?></span>
          <span class="room-card__free" data-free-at="<?= e(fmt_iso($r['available_at'])) ?>">Free soon…</span>
        </div>
      </div>
      <?php endif; ?>
    <?php elseif ($r['computed'] === 'unavailable'): ?>
      <p class="room-card__off-note"><?= icon('ban') ?> <?= e($r['note'] ?: ($r['status'] === 'maintenance' ? 'Under maintenance' : 'Temporarily disabled')) ?></p>
    <?php endif; ?>
  </div>

  <div class="room-card__meta">
    <span class="room-card__type"><?= e($r['room_type']) ?></span>
    <span class="room-card__cap" title="Capacity"><?= icon('users') ?> <?= (int)$r['capacity'] ?> seats</span>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

// Renders grid of room cards or empty state illustration
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

// Sorts rooms with available first, followed by building and room number
function sort_rooms_available_first(array $rooms): array
{
    $band = ['available' => 0, 'occupied' => 1, 'unavailable' => 2];
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

function room_pager_html(int $total, int $page, ?callable $hrefFor = null): string
{
    $pages = max(1, (int)ceil($total / ROOMS_PER_PAGE));
    if ($total <= ROOMS_PER_PAGE) {
        return '<div id="roomPager" class="load-more-wrap"></div>';
    }

    $loaded = min($total, $page * ROOMS_PER_PAGE);
    $remaining = max(0, $total - $loaded);

    if ($page >= $pages) {
        return '<div id="roomPager" class="load-more-wrap">'
            . '<p class="load-more-meta muted small">All ' . $total . ' classrooms loaded</p>'
            . '</div>';
    }

    $nextPage = $page + 1;
    $targetHref = $hrefFor ? $hrefFor($nextPage) : '#';

    return '<div id="roomPager" class="load-more-wrap">'
        . '<a href="' . e($targetHref) . '" class="btn btn--outline btn--lg load-more-btn" id="loadMoreBtn" data-next-page="' . $nextPage . '" role="button">'
        . icon('arrow-down') . ' '
        . '<span class="load-more-text">Load more classrooms</span> '
        . '<span class="load-more-count muted small">(' . $remaining . ' remaining)</span>'
        . '</a>'
        . '<p class="load-more-meta muted small">Showing ' . $loaded . ' of ' . $total . ' classrooms</p>'
        . '</div>';
}

const ADMIN_PER_PAGE = 10;

function admin_per_page(int $default = ADMIN_PER_PAGE, string $param = 'per_page'): int
{
    $val = (int)($_GET[$param] ?? 0);
    return in_array($val, [10, 25, 50, 100], true) ? $val : $default;
}

function page_params(int $total, int $page, int $perPage = ADMIN_PER_PAGE): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = max(1, min($page, $pages));
    return [
        'page'   => $page,
        'pages'  => $pages,
        'offset' => ($page - 1) * $perPage,
        'limit'  => $perPage,
    ];
}

function page_nav(int $total, int $page, int $perPage = ADMIN_PER_PAGE, string $param = 'page', string $itemLabel = 'entries'): string
{
    if ($total <= 0) {
        return '';
    }

    $pp   = page_params($total, $page, $perPage);
    $from = $pp['offset'] + 1;
    $to   = min($total, $pp['offset'] + $pp['limit']);

    $qs = $_GET;
    unset($qs[$param]);
    $pageUrl = static function (int $p) use ($qs, $param): string {
        $params = array_merge($qs, [$param => $p]);
        return '?' . e(http_build_query($params));
    };

    $perPageParam = ($param === 'page') ? 'per_page' : ($param === 'up_page' ? 'up_per_page' : ($param === 'past_page' ? 'past_per_page' : ($param . '_per_page')));
    $qsNoPerPage = $qs;
    unset($qsNoPerPage[$perPageParam]);
    $perPageUrl = static function (int $ppVal) use ($qsNoPerPage, $perPageParam, $param): string {
        $params = array_merge($qsNoPerPage, [$perPageParam => $ppVal, $param => 1]);
        return '?' . e(http_build_query($params));
    };

    $html = '<div class="pagination-footer">';
    $html .= '<div class="pagination-info">';
    $html .= 'Showing <strong>' . $from . '</strong> to <strong>' . $to . '</strong> of <strong>' . $total . '</strong> ' . e($itemLabel);
    $html .= '</div>';
    $html .= '<div class="pagination-controls">';
    $html .= '<div class="pagination-per-page">';
    $html .= '<label for="pp_' . e($param) . '">Show</label>';
    $html .= '<select id="pp_' . e($param) . '" onchange="window.location.href=this.value">';
    foreach ([10, 25, 50, 100] as $opt) {
        $sel = ($opt === $perPage) ? ' selected' : '';
        $html .= '<option value="' . $perPageUrl($opt) . '"' . $sel . '>' . $opt . '</option>';
    }
    $html .= '</select>';
    $html .= '</div>';

    if ($pp['pages'] > 1) {
        $link = static fn(int $p, string $label, bool $current = false): string =>
            '<a class="' . ($current ? 'is-active' : '') . '" href="' . $pageUrl($p) . '"' . ($current ? ' aria-current="page"' : '') . '>' . $label . '</a>';

        $start = max(1, min($pp['page'] - 2, $pp['pages'] - 4));
        $end   = min($pp['pages'], $start + 4);

        $html .= '<nav class="pager" aria-label="Pagination">';
        $html .= $pp['page'] > 1
            ? $link($pp['page'] - 1, '&lsaquo; Prev')
            : '<span class="is-off" aria-disabled="true">&lsaquo; Prev</span>';

        if ($start > 1) {
            $html .= $link(1, '1');
            if ($start > 2) {
                $html .= '<span class="dots" aria-hidden="true">&hellip;</span>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            $html .= $link($p, (string)$p, $p === $pp['page']);
        }
        if ($end < $pp['pages']) {
            if ($end < $pp['pages'] - 1) {
                $html .= '<span class="dots" aria-hidden="true">&hellip;</span>';
            }
            $html .= $link($pp['pages'], (string)$pp['pages']);
        }
        $html .= $pp['page'] < $pp['pages']
            ? $link($pp['page'] + 1, 'Next &rsaquo;')
            : '<span class="is-off" aria-disabled="true">Next &rsaquo;</span>';
        $html .= '</nav>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
