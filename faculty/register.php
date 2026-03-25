<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'teacher') {
    redirect_to('faculty/dashboard.php');
}

$departments = departments();

if (is_post()) {
    $password = (string) post('password');
    $confirmPassword = (string) post('confirm_password');

    if ($password !== $confirmPassword) {
        flash('error', 'Password confirmation does not match.');
        redirect_to('faculty/register.php');
    }

    try {
        rate_limit_or_fail('register_teacher:' . strtolower(trim((string) post('email'))), 5, 900);
        $result = register_teacher_account(
            trim((string) post('full_name')),
            (int) post('department_id'),
            trim((string) post('email')),
            $password
        );
        flash('success', 'Faculty registration submitted. Your faculty ID is ' . $result['teacher_code'] . '. Wait for admin approval before login.');
        redirect_to('faculty/login.php');
    } catch (Throwable $exception) {
        flash_exception($exception, 'Faculty registration could not be completed right now. Please review your details and try again.');
        redirect_to('faculty/register.php');
    }
}

render_auth_layout('Faculty Registration', 'Register a new faculty account. The administrator must approve it before access is granted.', 'faculty/register.css', 'faculty/register.js', function () use ($departments): void {
    ?>
    <form method="post" class="form-grid">
        <div class="form-group">
            <label class="form-label" for="faculty-name">Full Name</label>
            <input class="form-input" id="faculty-name" name="full_name" placeholder="Prof. Example Name" autocomplete="name" required>
        </div>
        <div class="form-grid two">
            <div class="form-group">
                <label class="form-label" for="faculty-department">Department</label>
                <select class="form-select" id="faculty-department" name="department_id" required>
                    <option value="">Select department</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>"><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-email">Email</label>
                <input class="form-input" id="faculty-email" name="email" type="email" autocomplete="email" placeholder="name@example.com" required>
            </div>
        </div>
        <div class="form-grid two">
            <div class="form-group">
                <label class="form-label" for="faculty-register-password">Password</label>
                <input class="form-input" id="faculty-register-password" name="password" type="password" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-register-password-confirm">Confirm Password</label>
                <input class="form-input" id="faculty-register-password-confirm" name="confirm_password" type="password" autocomplete="new-password" required>
            </div>
        </div>
        <div class="notice-box">Use a strong password with uppercase, lowercase, number, and special character. The system generates a unique faculty ID automatically.</div>
        <div class="form-actions">
            <button class="btn-primary" type="submit">Submit Registration</button>
            <a class="btn-secondary" href="<?= e(url('faculty/login.php')) ?>">Back to Login</a>
        </div>
    </form>
    <?php
});
