<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'student') {
    redirect_to('student/dashboard.php');
}

$pendingTakeover = login_takeover_request('student');

if (is_post()) {
    $mode = (string) post('mode', 'login');

    if ($mode === 'force_login') {
        $takeover = login_takeover_request('student');
        if ($takeover && force_login_from_pending_request('student')) {
            $identifier = strtoupper((string) ($takeover['reference'] ?? ''));
            if ($identifier !== '') {
                clear_rate_limit('login_student:' . $identifier);
            }
            audit_log('student', (string) ($takeover['reference'] ?? 'student'), 'LOGIN_TAKEOVER', 'Student forced a new login and replaced the previous active session.');
            flash('success', 'Previous student session closed. You are now logged in on this system.');
            redirect_to('student/dashboard.php');
        }

        flash('error', 'The force login request expired. Please enter the student credentials again.');
    } else {
        $enrollment = trim((string) post('enrollment_no'));
        $rateKey = 'login_student:' . strtoupper($enrollment);

        try {
            rate_limit_or_fail($rateKey, (int) config('security.login_rate_limit', 10), 900);
        } catch (Throwable $exception) {
            flash_exception($exception, 'Too many student login attempts. Please wait a few minutes and try again.');
            redirect_to('student/login.php');
        }

        $result = student_login($enrollment, (string) post('password'));

        if ($result === true) {
            clear_login_takeover_request('student');
            clear_rate_limit($rateKey);
            audit_log('student', strtoupper($enrollment), 'LOGIN', 'Student login successful');
            flash('success', 'Student login successful.');
            redirect_to('student/dashboard.php');
        }

        if ($result === 'already_logged_in') {
            audit_log('student', strtoupper($enrollment), 'LOGIN_BLOCKED', 'Duplicate login blocked because the student account is already active on another system.');
            flash('warning', 'This student account is already logged in on another system. Use Force Login if you want to take over that session.');
        } else {
            clear_login_takeover_request('student');
            flash('error', 'Invalid enrollment number or password. Register first if you have not activated your account yet.');
        }
    }

    $pendingTakeover = login_takeover_request('student');
}

render_auth_layout('Student Login', 'Students can access personal attendance, marks, assignments, and profile details after registration.', 'student/login.css', 'student/login.js', function () use ($pendingTakeover): void {
    ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="login">
        <div class="form-group">
            <label class="form-label" for="enrollment-login">Enrollment Number</label>
            <input class="form-input mono" id="enrollment-login" name="enrollment_no" placeholder="Enter enrollment number" autocomplete="username" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="student-password">Password</label>
            <input class="form-input" id="student-password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <div class="form-actions">
            <button class="btn-primary" type="submit">Login</button>
            <a class="btn-secondary" href="<?= e(url('student/register.php')) ?>">Student Registration</a>
        </div>
    </form>
    <?php if ($pendingTakeover): ?>
        <div class="notice-box warning" style="margin-top: 14px;">
            <strong>Active student session detected.</strong>
            <p class="muted" style="margin-top: 6px;">Account: <?= e((string) $pendingTakeover['display_name']) ?><?= ($pendingTakeover['reference'] ?? '') !== '' ? ' (' . e((string) $pendingTakeover['reference']) . ')' : '' ?>. Force Login will end the old session and continue on this system.</p>
            <form method="post" class="inline-actions" style="margin-top: 12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="force_login">
                <button class="btn-danger" type="submit" data-confirm="Force login on this student account? The currently active session will be signed out.">Force Login</button>
            </form>
        </div>
    <?php endif; ?>
    <?php
});
