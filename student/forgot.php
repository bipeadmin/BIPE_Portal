<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'student') {
    redirect_to('student/dashboard.php');
}

if (is_post()) {
    $action = (string) post('action');
    $email = trim((string) post('email'));

    try {
        if ($action === 'request') {
            rate_limit_or_fail('otp_request_student:' . strtolower($email), (int) config('security.otp_rate_limit', 5), 600);
            $result = request_password_reset_delivery('student', $email);
            flash('success', password_reset_request_success_message($result['preview_otp'] ?? null));
            redirect_to('student/forgot.php');
        }

        if ($action === 'reset') {
            rate_limit_or_fail('otp_reset_student:' . strtolower($email), (int) config('security.otp_rate_limit', 5), 600);
            $newPassword = (string) post('new_password');
            $confirmPassword = (string) post('confirm_password');
            if ($newPassword !== $confirmPassword) {
                flash('error', 'Password confirmation does not match.');
                redirect_to('student/forgot.php');
            }

            if (reset_password_with_otp('student', $email, trim((string) post('otp')), $newPassword)) {
                flash('success', 'Student password updated successfully. Please login again.');
                redirect_to('student/login.php');
            }

            flash('error', 'Invalid or expired OTP. Request a new code and try again.');
            redirect_to('student/forgot.php');
        }
    } catch (Throwable $exception) {
        flash_exception($exception, 'Password reset could not be completed right now. Please try again later.');
        redirect_to('student/forgot.php');
    }
}

render_auth_layout('Student Password Reset', 'Use the student email saved during registration to request a 6-digit OTP and set a new password.', 'student/forgot.css', 'student/forgot.js', function (): void {
    ?>
    <div class="stack">
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="request">
            <div class="form-group">
                <label class="form-label" for="student-reset-email-request">Student Email</label>
                <input class="form-input" id="student-reset-email-request" name="email" type="email" autocomplete="email" placeholder="student@example.com" required>
            </div>
            <button class="btn-primary" type="submit">Send OTP</button>
        </form>
        <div class="notice-box warning">Only student accounts with a registered email can use OTP recovery. The reset code expires in 10 minutes.</div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="reset">
            <div class="form-grid two">
                <div class="form-group">
                    <label class="form-label" for="student-reset-email">Student Email</label>
                    <input class="form-input" id="student-reset-email" name="email" type="email" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="student-reset-otp">OTP</label>
                    <input class="form-input mono" id="student-reset-otp" name="otp" maxlength="6" placeholder="6-digit code" inputmode="numeric" required>
                </div>
            </div>
            <div class="form-grid two">
                <div class="form-group">
                    <label class="form-label" for="student-reset-password">New Password</label>
                    <input class="form-input" id="student-reset-password" name="new_password" type="password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="student-reset-confirm">Confirm Password</label>
                    <input class="form-input" id="student-reset-confirm" name="confirm_password" type="password" autocomplete="new-password" required>
                </div>
            </div>
            <div class="notice-box">Use a strong password with uppercase, lowercase, number, and special character.</div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Reset Password</button>
                <a class="btn-secondary" href="<?= e(url('student/login.php')) ?>">Back to Login</a>
            </div>
        </form>
    </div>
    <?php
});
