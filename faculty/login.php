<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'teacher') {
    redirect_to('faculty/dashboard.php');
}

if (is_post()) {
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
        clear_rate_limit($rateKey);
        audit_log('teacher', $teacherCode, 'LOGIN', 'Faculty login successful');
        flash('success', 'Faculty login successful.');
        redirect_to('faculty/dashboard.php');
    }

    if ($result === 'pending') {
        flash('info', 'Your faculty registration is still pending administrator approval.');
    } elseif ($result === 'rejected') {
        flash('error', 'Your faculty registration was rejected. Please contact the administrator.');
    } elseif ($result === 'archived') {
        flash('info', 'This faculty account has been archived by the administrator. Historical records are preserved, but login access is disabled.');
    } else {
        flash('error', 'Invalid faculty ID or password.');
    }
}

render_auth_layout('Faculty Login', 'Approved faculty members can mark attendance, upload marks, and manage assignment tracking.', 'faculty/login.css', 'faculty/login.js', function (): void {
    ?>
    <form method="post" class="form-grid">
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
    <?php
});
