<?php
declare(strict_types=1);

require_once __DIR__ . '/config/helpers.php';

$rawCode = $_GET['code'] ?? ($_SERVER['REDIRECT_STATUS'] ?? null);
$code = filter_var($rawCode, FILTER_VALIDATE_INT);
$code = ($code !== false && $code >= 400 && $code <= 599) ? $code : 500;

http_response_code($code);

$knownErrors = [
    400 => [
        'title'       => 'Bad Request',
        'description' => 'The server could not understand your request due to invalid syntax or parameters.',
        'color'       => 'var(--warn, #f59e0b)',
    ],
    401 => [
        'title'       => 'Unauthorized',
        'description' => 'Authentication is required to access this resource. Please sign in.',
        'color'       => 'var(--primary)',
    ],
    403 => [
        'title'       => 'Access Forbidden',
        'description' => 'You do not have permission to access this resource or page.',
        'color'       => 'var(--danger, #dc2626)',
    ],
    404 => [
        'title'       => 'Page Not Found',
        'description' => "The page you are looking for doesn't exist, has been removed, or is temporarily unavailable.",
        'color'       => 'var(--primary)',
    ],
    500 => [
        'title'       => 'Internal Server Error',
        'description' => 'Something went wrong on our servers. Please try again shortly or contact support if the issue persists.',
        'color'       => 'var(--danger, #dc2626)',
    ],
    503 => [
        'title'       => 'Service Unavailable',
        'description' => 'The server is temporarily unable to handle the request due to maintenance or capacity limits.',
        'color'       => 'var(--warn, #f59e0b)',
    ],
];

$err = $knownErrors[$code] ?? [
    'title'       => 'Error ' . $code,
    'description' => 'An unexpected error occurred while processing your request.',
    'color'       => 'var(--danger, #dc2626)',
];

$customMessage = isset($_GET['message']) && is_string($_GET['message']) ? trim($_GET['message']) : '';
$description = $customMessage !== '' ? $customMessage : $err['description'];

$user = current_user();

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$prefix = ($scriptDir !== '' && $scriptDir !== '/') ? "{$scriptDir}/" : '';
$homeUrl = "{$prefix}index.php";
$loginUrl = "{$prefix}login.php";

render_header($err['title'] . ' — ' . $code, ['prefix' => $prefix, 'wide' => true]);
?>
<div class="auth-wrap" style="text-align:center;padding:4rem 1rem;">
  <div class="card" style="max-width:32rem;margin:0 auto;padding:2.5rem 2rem;">
    <div style="font-size:4rem;font-weight:700;color:<?= e($err['color']) ?>;line-height:1;margin-bottom:1rem;font-family:var(--font-display);">
      <?= $code ?>
    </div>
    <h1 style="font-size:1.5rem;margin-bottom:0.75rem;"><?= e($err['title']) ?></h1>
    <p class="muted" style="margin-bottom:2rem;">
      <?= e($description) ?>
    </p>
    <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;">
      <a href="<?= e($homeUrl) ?>" class="btn btn--primary">
        <?= icon('home') ?> Go to Home
      </a>
      <?php if (($code === 401 || $code === 403) && !$user): ?>
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
