<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$admin = require_admin();

render_header('Classroom History', ['prefix' => '../', 'nav' => 'admin', 'active' => 'classroom_history']);
?>

<div class="page-head">
  <h1><?= icon('calendar-clock') ?> Classroom History</h1>
  <p class="muted">Track classrooms used, lecturers, and occupancy times.</p>
</div>

<div class="card empty-state" style="margin-top: 2rem; padding: 4.5rem 1.5rem; text-align: center;">
  <p style="font-size: 1.15rem; font-weight: 600; color: var(--text); margin-bottom: .3rem;">Coming Soon</p>
  <p class="muted" style="font-family: inherit;">This feature is currently under development.</p>
</div>

<?php render_footer(); ?>
