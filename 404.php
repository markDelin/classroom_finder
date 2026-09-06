<?php
declare(strict_types=1);

http_response_code(404);
require_once __DIR__ . '/config/helpers.php';

render_header('Page Not Found — 404', ['prefix' => '', 'wide' => true]);
?>
<div class="auth-wrap" style="text-align:center;padding:4rem 1rem;">
  <div class="card" style="max-width:32rem;margin:0 auto;padding:2.5rem 2rem;">
    <div style="font-size:4rem;font-weight:700;color:var(--color-primary,#2563eb);line-height:1;margin-bottom:1rem;">
      404
    </div>
    <h1 style="font-size:1.5rem;margin-bottom:0.75rem;">Page Not Found</h1>
    <p class="muted" style="margin-bottom:2rem;">
      The page you are looking for doesn't exist, has been removed, or is temporarily unavailable.
    </p>
    <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;">
      <a href="index.php" class="btn btn--primary">
        <?= icon('home') ?> Go to Home
      </a>
      <a href="javascript:history.back()" class="btn btn--secondary">
        <?= icon('arrow-left') ?> Go Back
      </a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
