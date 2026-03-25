<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'student') {
    redirect_to('student/dashboard.php');
}

if (is_post()) {
    $enrollment = trim((string) post('enrollment_no'));
    $rateKey = 'login_student:' . strtoupper($enrollment);

    try {
        rate_limit_or_fail($rateKey, (int) config('security.login_rate_limit', 10), 900);
    } catch (Throwable $exception) {
        flash_exception($exception, 'Too many student login attempts. Please wait a few minutes and try again.');
        redirect_to('student/login.php');
    }

    if (student_login($enrollment, (string) post('password'))) {
        clear_rate_limit($rateKey);
        audit_log('student', strtoupper($enrollment), 'LOGIN', 'Student login successful');
        flash('success', 'Student login successful.');
        redirect_to('student/dashboard.php');
    }

    flash('error', 'Invalid enrollment number or password. Register first if you have not activated your account yet.');
}

render_auth_layout('Student Login', 'Students can access personal attendance, marks, assignments, and profile details after registration.', 'student/login.css', 'student/login.js', function (): void {
    ?>
    <form method="post" class="form-grid">
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
            <a class="btn-secondary" href="<?= e(url('student/forgot.php')) ?>">Forgot Password</a>
        </div>
    </form>
    <?php
});
