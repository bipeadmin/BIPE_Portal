<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'teacher') {
    redirect_to('faculty/dashboard.php');
}

$pendingTakeover = login_takeover_request('teacher');

if (is_post()) {
    $mode = (string) post('mode', 'login');

    if ($mode === 'force_login') {
        $takeover = login_takeover_request('teacher');
        if ($takeover && force_login_from_pending_request('teacher')) {
            $identifier = strtolower((string) ($takeover['reference'] ?? ''));
            if ($identifier !== '') {
                clear_rate_limit('login_teacher:' . $identifier);
            }
            audit_log('teacher', (string) ($takeover['reference'] ?? 'teacher'), 'LOGIN_TAKEOVER', 'Faculty forced a new login and replaced the previous active session.');
            flash('success', 'Previous faculty session closed. You are now logged in on this system.');
            redirect_to('faculty/dashboard.php');
        }

        flash('error', 'The force login request expired. Please enter the faculty credentials again.');
    } else {
        $teacherCode = trim((string) post('teacher_code'));
        $password = (string) post('password');
        $rateKey = 'login_teacher:' . strtolower($teacherCode);

        try {
            rate_limit_or_fail($rateKey, (int) config('security.login_rate_limit', 10), 900);
        } catch (Throwable $exception) {
            flash_exception($exception, 'Too many faculty login attempts. Please wait a few minutes and try again.');
            redirect_to('faculty/login.php');
        }

        $result = teacher_login($teacherCode, $password);

        if ($result === true) {
            clear_login_takeover_request('teacher');
            clear_rate_limit($rateKey);
            audit_log('teacher', $teacherCode, 'LOGIN', 'Faculty login successful');
            flash('success', 'Faculty login successful.');
            redirect_to('faculty/dashboard.php');
        }

        if ($result === 'pending') {
            clear_login_takeover_request('teacher');
            flash('info', 'Your faculty registration is still pending administrator approval.');
        } elseif ($result === 'already_logged_in') {
            audit_log('teacher', $teacherCode, 'LOGIN_BLOCKED', 'Duplicate login blocked because the faculty account is already active on another system.');
            flash('warning', 'This faculty account is already logged in on another system. Use Force Login if you want to take over that session.');
        } elseif ($result === 'rejected') {
            clear_login_takeover_request('teacher');
            flash('error', 'Your faculty registration was rejected. Please contact the administrator.');
        } elseif ($result === 'archived') {
            clear_login_takeover_request('teacher');
            flash('info', 'This faculty account has been archived by the administrator. Historical records are preserved, but login access is disabled.');
        } else {
            clear_login_takeover_request('teacher');
            flash('error', 'Invalid faculty ID or password.');
        }
    }

    $pendingTakeover = login_takeover_request('teacher');
}

render_auth_layout('Faculty Login', 'Approved faculty members can mark attendance, upload marks, and manage assignment tracking.', 'faculty/login.css', 'faculty/login.js', function () use ($pendingTakeover): void {
    ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="login">
        <div class="form-group">
            <label class="form-label" for="teacher-code">Faculty ID</label>
            <input class="form-input" id="teacher-code" name="teacher_code" placeholder="Example: rk.cse" autocomplete="username" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="faculty-password">Password</label>
            <input class="form-input" id="faculty-password" name="password" type="password" placeholder="Enter password" autocomplete="current-password" required>
        </div>
        <?php if (show_seed_hints()): ?>
            <div class="notice-box">Seeded faculty accounts use the password <span class="mono">Teach@1234</span>.</div>
        <?php endif; ?>
        <div class="form-actions">
            <button class="btn-primary" type="submit">Login</button>
            <a class="btn-secondary" href="<?= e(url('faculty/register.php')) ?>">Create Faculty Account</a>
            <a class="btn-secondary" href="<?= e(url('faculty/forgot.php')) ?>">Forgot Password</a>
        </div>
    </form>
    <?php if ($pendingTakeover): ?>
        <div class="notice-box warning" style="margin-top: 14px;">
            <strong>Active faculty session detected.</strong>
            <p class="muted" style="margin-top: 6px;">Account: <?= e((string) $pendingTakeover['display_name']) ?><?= ($pendingTakeover['reference'] ?? '') !== '' ? ' (' . e((string) $pendingTakeover['reference']) . ')' : '' ?>. Force Login will end the old session and continue on this system.</p>
            <form method="post" class="inline-actions" style="margin-top: 12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="force_login">
                <button class="btn-danger" type="submit" data-confirm="Force login on this faculty account? The currently active session will be signed out.">Force Login</button>
            </form>
        </div>
    <?php endif; ?>
    <?php
});
