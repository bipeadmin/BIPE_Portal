<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'student') {
    redirect_to('student/dashboard.php');
}

$departments = departments();

if (is_post()) {
    $password = (string) post('password');
    $confirmPassword = (string) post('confirm_password');

    if ($password !== $confirmPassword) {
        flash('error', 'Password confirmation does not match.');
        redirect_to('student/register.php');
    }

    try {
        rate_limit_or_fail('register_student:' . strtolower(trim((string) post('email'))), 5, 900);
        $registered = register_student_account_with_email(
            trim((string) post('enrollment_no')),
            (int) post('department_id'),
            trim((string) post('email')),
            $password
        );

        if ($registered) {
            flash('success', 'Student account activated successfully. Please login with your new password.');
            redirect_to('student/login.php');
        }

        flash('error', 'Student record not found in the seeded roster. Check enrollment number and department.');
        redirect_to('student/register.php');
    } catch (Throwable $exception) {
        flash_exception($exception, 'Student registration could not be completed right now. Please review your details and try again.');
        redirect_to('student/register.php');
    }
}

render_auth_layout('Student Registration', 'Activate a student account using the enrollment number already stored in the portal database.', 'student/register.css', 'student/register.js', function () use ($departments): void {
    ?>
    <form method="post" class="form-grid">
        <div class="form-grid two">
            <div class="form-group">
                <label class="form-label" for="student-enrollment">Enrollment Number</label>
                <input class="form-input mono" id="student-enrollment" name="enrollment_no" placeholder="Enrollment number" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="student-department">Department</label>
                <select class="form-select" id="student-department" name="department_id" required>
                    <option value="">Select department</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>"><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="student-email-register">Email</label>
            <input class="form-input" id="student-email-register" name="email" type="email" autocomplete="email" placeholder="student@example.com" required>
        </div>
        <div class="form-grid two">
            <div class="form-group">
                <label class="form-label" for="student-register-password">Password</label>
                <input class="form-input" id="student-register-password" name="password" type="password" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="student-register-password-confirm">Confirm Password</label>
                <input class="form-input" id="student-register-password-confirm" name="confirm_password" type="password" autocomplete="new-password" required>
            </div>
        </div>
        <div class="notice-box">Only roster entries imported into MySQL can register. The email saved here will be used for OTP-based password reset.</div>
        <div class="form-actions">
            <button class="btn-primary" type="submit">Activate Account</button>
            <a class="btn-secondary" href="<?= e(url('student/login.php')) ?>">Back to Login</a>
        </div>
    </form>
    <?php
});
