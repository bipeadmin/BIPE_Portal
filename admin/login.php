<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'admin') {
    redirect_to('admin/dashboard.php');
}

if (is_post()) {
    $username = trim((string) post('username'));
    $password = (string) post('password');
    $rateKey = 'login_admin:' . strtolower($username);

    try {
        rate_limit_or_fail($rateKey, (int) config('security.login_rate_limit', 10), 900);
    } catch (Throwable $exception) {
        flash_exception($exception, 'Too many administrator login attempts. Please wait a few minutes and try again.');
        redirect_to('admin/login.php');
    }

    if (admin_login($username, $password)) {
        clear_rate_limit($rateKey);
        audit_log('admin', $username, 'LOGIN', 'Administrator login successful');
        flash('success', 'Welcome back to the administrator portal.');
        redirect_to('admin/dashboard.php');
    }

    flash('error', 'Invalid administrator username or password.');
}

render_auth_layout('Administrator Login', 'Use your administrator account to manage the entire BIPE portal.', 'admin/login.css', 'admin/login.js', function (): void {
    ?>
    <form method="post" class="form-grid">
        <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <input class="form-input" id="username" name="username" placeholder="Enter admin username" autocomplete="username" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input class="form-input" id="password" name="password" type="password" placeholder="Enter password" autocomplete="current-password" required>
        </div>
        <?php if (show_seed_hints()): ?>
            <div class="notice-box">
                Seeded credentials: <span class="mono">bipe</span> / <span class="mono">Bipe@4455</span>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <button class="btn-primary" type="submit">Login</button>
            <a class="btn-secondary" href="<?= e(url('admin/forgot.php')) ?>">Forgot Password</a>
        </div>
    </form>
    <?php
});
