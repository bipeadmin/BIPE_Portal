<?php
declare(strict_types=1);

function render_error_response(int $statusCode, string $errorType, string $message): never
{
    $statusCode = max(100, min(599, $statusCode));
    $title = match ($statusCode) {
        403 => 'Access Denied',
        404 => 'Page Not Found',
        419 => 'Session Expired',
        default => 'Application Error',
    };

    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=UTF-8');
    }

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> | <?= e((string) config('app.name', 'BIPE Academic Portal')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/common.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/error.css')) ?>">
</head>
<body class="error-body">
    <main class="error-shell">
        <section class="error-card">
            <div class="error-logo-wrap">
                <img class="error-logo" src="<?= e(url('assets/images/bipe-logo.jpeg')) ?>" alt="BIPE logo">
            </div>
            <p class="eyebrow">System Error Handler</p>
            <h1><?= e($title) ?></h1>
            <p class="error-lead">The portal could not finish this request. The details below can help you understand what happened.</p>

            <div class="error-grid">
                <article class="error-info-box">
                    <span class="error-label">Error Code</span>
                    <strong class="mono"><?= e((string) $statusCode) ?></strong>
                </article>
                <article class="error-info-box">
                    <span class="error-label">Error Type</span>
                    <strong><?= e($errorType) ?></strong>
                </article>
            </div>

            <article class="error-message-box">
                <span class="error-label">What This Error Says</span>
                <p><?= e($message) ?></p>
            </article>

            <div class="error-actions">
                <a class="btn-primary" href="<?= e(url('')) ?>">Back to Home</a>
                <button class="btn-secondary" type="button" data-error-reload>Try Again</button>
            </div>
        </section>
    </main>
    <script src="<?= e(url('assets/js/error.js')) ?>"></script>
</body>
</html>
    <?php
    exit;
}
