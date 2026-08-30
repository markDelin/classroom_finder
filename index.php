<?php
declare(strict_types=1);

/**
 * Classroom Finder — public landing page.
 *
 * Open to everyone: search + filter classrooms and see live availability.
 * assets/js/landing.js keeps it fresh by polling api/classroom_status.php
 * and swapping the grid HTML (same renderer as this page uses).
 */

require_once __DIR__ . '/config/layout.php';

$filters = [
    'q'        => trim((string)($_GET['q'] ?? '')),
    'status'   => (string)($_GET['status'] ?? ''),
    'building' => (string)($_GET['building'] ?? ''),
    'floor'    => (string)($_GET['floor'] ?? ''),
    'type'     => (string)($_GET['type'] ?? ''),
    'mincap'   => (string)($_GET['mincap'] ?? ''),
];

$allRooms = fetch_classrooms();                       // unfiltered, for select options + counters
$pg       = room_page(fetch_classrooms($filters), (int)($_GET['page'] ?? 1));
$rooms    = $pg['rooms'];                             // this page's slice, available rooms first

$buildings = array_values(array_unique(array_column($allRooms, 'building')));
sort($buildings);
$floors = array_values(array_unique(array_map('intval', array_column($allRooms, 'floor'))));
sort($floors);
$types  = array_values(array_unique(array_column($allRooms, 'room_type')));
sort($types);

$count = ['available' => 0, 'occupied' => 0, 'reserved' => 0, 'unavailable' => 0];
foreach ($allRooms as $r) {
    $count[$r['computed']]++;
}

$needsSetup = false;
try {
    $needsSetup = (int)db()->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'] === 0;
} catch (Throwable) {
}

$school    = school_name();
$heroTitle = strtoupper($school !== '' ? $school : app_name());
render_header('Find a Classroom', ['prefix' => '', 'wide' => true]);
?>

<?php if ($needsSetup): ?>
<div class="flash flash--warn" style="max-width:60rem;margin:1rem auto">
  <?= icon('settings') ?> <span>First-time setup: no administrator exists yet. <a href="setup.php"><strong>Create the initial admin account</strong></a></span>
</div>
<?php endif; ?>

<section class="hero">
  <h1><?= icon('map-pin') ?> <?= e($heroTitle) ?></h1>
  <p class="hero__sub">Live availability for every classroom — see what&rsquo;s free before you walk there.</p>

  <form id="finderForm" class="finder" method="get" action="index.php">
    <div class="finder__bar">
      <div class="finder__search input-icon">
        <?= icon('search', 'input-icon__lead') ?>
        <input type="search" name="q" id="searchBox" placeholder="Search room number, building or type…"
               value="<?= e($filters['q']) ?>" autocomplete="off">
      </div>
      <details class="finder__more" <?= ($filters['building'] || $filters['floor'] !== '' || $filters['type'] || $filters['mincap']) ? 'open' : '' ?>>
        <summary title="Filters"><?= icon('sliders-horizontal') ?></summary>
        <div class="finder__dropdown">
          <div class="finder__dropdown-grid">
            <label>Building
              <select name="building">
                <option value="">All buildings</option>
                <?php foreach ($buildings as $b): ?>
                  <option value="<?= e($b) ?>" <?= $filters['building'] === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Floor
              <select name="floor">
                <option value="">All floors</option>
                <?php foreach ($floors as $fl): ?>
                  <option value="<?= (int)$fl ?>" <?= $filters['floor'] !== '' && (int)$filters['floor'] === $fl ? 'selected' : '' ?>>
                    Floor <?= (int)$fl ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Type
              <select name="type">
                <option value="">All types</option>
                <?php foreach ($types as $t): ?>
                  <option value="<?= e($t) ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Min seats
              <input type="number" name="mincap" min="1" max="999" placeholder="Any" value="<?= e($filters['mincap']) ?>">
            </label>
          </div>
          <div class="finder__dropdown-actions">
            <button class="btn btn--primary btn--sm" type="submit" id="applyFilters">Filter</button>
            <button class="btn btn--ghost btn--sm" type="button" id="clearFilters">Reset filters</button>
          </div>
        </div>
      </details>
    </div>

    <div class="chips" role="tablist" aria-label="Status filter">
      <?php
      $chip = function (string $val, string $label, string $iconName = '') use ($filters) {
          $on = $filters['status'] === $val || ($val === '' && $filters['status'] === '');
          printf(
              '<button type="button" class="chip-btn%s" data-status="%s">%s %s</button>',
              $on ? ' is-active' : '',
              e($val),
              $iconName ? icon($iconName) : '',
              e($label)
          );
      };
      $chip('', 'All');
      $chip('available', 'Available', 'circle-check');
      $chip('occupied', 'Occupied', 'clock');
      $chip('reserved', 'Reserved', 'calendar-clock');
      $chip('unavailable', 'Unavailable', 'ban');
      ?>
    </div>
  </form>

  <p class="results-line">
    <strong id="resultCount"><?= $pg['total'] ?></strong> room(s)
    <span class="muted stat-dots">
      <span class="dot dot--ok"></span><?= $count['available'] ?> available ·
      <span class="dot dot--danger"></span><?= $count['occupied'] ?> occupied ·
      <span class="dot dot--warn"></span><?= $count['reserved'] ?> reserved ·
      <span class="dot dot--off"></span><?= $count['unavailable'] ?> unavailable
    </span>
    <span class="muted small" id="updatedAt"></span>
  </p>
</section>

<div id="roomResults" aria-live="polite">
  <section id="roomGrid" class="room-grid" data-refresh="<?= get_setting_int('landing_refresh_seconds', 15) ?>" data-page="<?= $pg['page'] ?>">
    <?= room_cards_html($rooms) ?>
  </section>
  <div id="roomPager"><?= room_pager_html(
      $pg['total'],
      $pg['page'],
      static fn(int $t): string => 'index.php?' . http_build_query(array_merge($_GET, ['page' => $t]))
  ) ?></div>
</div>

<footer class="site-footer">
  <span><?= e($school !== '' ? $school . ' · ' . app_name() : app_name()) ?></span>
  <span>Lecturer? <a href="login.php">Log in to occupy a room via QR</a></span>
</footer>

<?php render_footer(['assets/js/landing.js']); ?>
