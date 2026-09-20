<?php
declare(strict_types=1);

http_response_code(403);
require_once __DIR__ . '/config/helpers.php';

$user = current_user();
$customMessage = isset($_GET['message']) && is_string($_GET['message']) ? trim($_GET['message']) : '';

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$prefix = ($scriptDir !== '' && $scriptDir !== '/') ? "{$scriptDir}/" : '';
$homeUrl = "{$prefix}index.php";
$loginUrl = "{$prefix}login.php";

render_header('Access Forbidden — 403', ['prefix' => $prefix, 'wide' => true]);
?>
<div class="auth-wrap" style="text-align:center;padding:4rem 1rem;">
  <div class="card" style="max-width:32rem;margin:0 auto;padding:2.5rem 2rem;">
    <div style="font-size:4rem;font-weight:700;color:var(--danger, #dc2626);line-height:1;margin-bottom:1rem;font-family:var(--font-display);">
      403
    </div>
    <h1 style="font-size:1.5rem;margin-bottom:0.75rem;">Access Forbidden</h1>
    <p class="muted" style="margin-bottom:2rem;">
      <?= $customMessage !== '' ? e($customMessage) : 'You do not have permission to access this resource or page.' ?>
    </p>
    <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;">
      <a href="<?= e($homeUrl) ?>" class="btn btn--primary">
        <?= icon('home') ?> Go to Home
      </a>
      <?php if (!$user): ?>
        <a href="<?= e($loginUrl) ?>" class="btn btn--secondary">
          <?= icon('lock') ?> Log In
        </a>
      <?php endif; ?>
      <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '<?= e($homeUrl) ?>')" class="btn btn--ghost">
        <?= icon('arrow-left') ?> Go back
      </button>
    </div>
  </div>
</div>
<?php render_footer(); ?>
