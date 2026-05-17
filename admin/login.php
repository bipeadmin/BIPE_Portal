<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'admin') {
    redirect_to('admin/dashboard.php');
}

$pendingTakeover = login_takeover_request('admin');

if (is_post()) {
    $mode = (string) post('mode', 'login');

    if ($mode === 'force_login') {
        $takeover = login_takeover_request('admin');
        if ($takeover && force_login_from_pending_request('admin')) {
            $identifier = strtolower((string) ($takeover['reference'] ?? ''));
            if ($identifier !== '') {
                clear_rate_limit('login_admin:' . $identifier);
            }
            audit_log('admin', (string) ($takeover['reference'] ?? 'admin'), 'LOGIN_TAKEOVER', 'Administrator forced a new login and replaced the previous active session.');
            flash('success', 'Previous administrator session closed. You are now logged in on this system.');
            redirect_to('admin/dashboard.php');
        }

        flash('error', 'The force login request expired. Please enter the administrator credentials again.');
    } else {
        $username = trim((string) post('username'));
        $password = (string) post('password');
        $rateKey = 'login_admin:' . strtolower($username);

        try {
            rate_limit_or_fail($rateKey, (int) config('security.login_rate_limit', 10), 900);
        } catch (Throwable $exception) {
            flash_exception($exception, 'Too many administrator login attempts. Please wait a few minutes and try again.');
            redirect_to('admin/login.php');
        }

        $result = admin_login($username, $password);

        if ($result === true) {
            clear_login_takeover_request('admin');
            clear_rate_limit($rateKey);
            audit_log('admin', $username, 'LOGIN', 'Administrator login successful');
            flash('success', 'Welcome back to the administrator portal.');
            redirect_to('admin/dashboard.php');
        }

        if ($result === 'already_logged_in') {
            audit_log('admin', $username, 'LOGIN_BLOCKED', 'Duplicate login blocked because the administrator account is already active on another system.');
            flash('warning', 'This administrator account is already logged in on another system. Use Force Login if you want to take over that session.');
        } else {
            clear_login_takeover_request('admin');
            flash('error', 'Invalid administrator username or password.');
        }
    }

    $pendingTakeover = login_takeover_request('admin');
}

render_auth_layout('Administrator Login', 'Use your administrator account to manage the entire BIPE portal.', 'admin/login.css', 'admin/login.js', function () use ($pendingTakeover): void {
    ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="login">
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
    <?php if ($pendingTakeover): ?>
        <div class="notice-box warning" style="margin-top: 14px;">
            <strong>Active administrator session detected.</strong>
            <p class="muted" style="margin-top: 6px;">Account: <?= e((string) $pendingTakeover['display_name']) ?><?= ($pendingTakeover['reference'] ?? '') !== '' ? ' (' . e((string) $pendingTakeover['reference']) . ')' : '' ?>. Force Login will end the old session and continue on this system.</p>
            <form method="post" class="inline-actions" style="margin-top: 12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="force_login">
                <button class="btn-danger" type="submit" data-confirm="Force login on this administrator account? The currently active session will be signed out.">Force Login</button>
            </form>
        </div>
    <?php endif; ?>
    <?php
});
