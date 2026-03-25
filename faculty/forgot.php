<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'teacher') {
    redirect_to('faculty/dashboard.php');
}

if (is_post()) {
    $action = (string) post('action');
    $email = trim((string) post('email'));

    try {
        if ($action === 'request') {
            rate_limit_or_fail('otp_request_teacher:' . strtolower($email), (int) config('security.otp_rate_limit', 5), 600);
            $result = request_password_reset_delivery('teacher', $email);
            flash('success', password_reset_request_success_message($result['preview_otp'] ?? null));
            redirect_to('faculty/forgot.php');
        }

        if ($action === 'reset') {
            rate_limit_or_fail('otp_reset_teacher:' . strtolower($email), (int) config('security.otp_rate_limit', 5), 600);
            $newPassword = (string) post('new_password');
            $confirmPassword = (string) post('confirm_password');
            if ($newPassword !== $confirmPassword) {
                flash('error', 'Password confirmation does not match.');
                redirect_to('faculty/forgot.php');
            }

            if (reset_password_with_otp('teacher', $email, trim((string) post('otp')), $newPassword)) {
                flash('success', 'Faculty password updated successfully. Please login again.');
                redirect_to('faculty/login.php');
            }

            flash('error', 'Invalid or expired OTP. Request a new code and try again.');
            redirect_to('faculty/forgot.php');
        }
    } catch (Throwable $exception) {
        flash_exception($exception, 'Password reset could not be completed right now. Please try again later.');
        redirect_to('faculty/forgot.php');
    }
}

render_auth_layout('Faculty Password Reset', 'Use your registered faculty email to request a 6-digit OTP and reset your password.', 'faculty/forgot.css', 'faculty/forgot.js', function (): void {
    ?>
    <div class="stack">
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="request">
            <div class="form-group">
                <label class="form-label" for="faculty-reset-email-request">Faculty Email</label>
                <input class="form-input" id="faculty-reset-email-request" name="email" type="email" autocomplete="email" placeholder="name@example.com" required>
            </div>
            <button class="btn-primary" type="submit">Send OTP</button>
        </form>
        <div class="notice-box warning">Password reset codes are delivered to the registered faculty email address and expire in 10 minutes.</div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="reset">
            <div class="form-grid two">
                <div class="form-group">
                    <label class="form-label" for="faculty-reset-email">Faculty Email</label>
                    <input class="form-input" id="faculty-reset-email" name="email" type="email" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="faculty-reset-otp">OTP</label>
                    <input class="form-input mono" id="faculty-reset-otp" name="otp" maxlength="6" placeholder="6-digit code" inputmode="numeric" required>
                </div>
            </div>
            <div class="form-grid two">
                <div class="form-group">
                    <label class="form-label" for="faculty-reset-password">New Password</label>
                    <input class="form-input" id="faculty-reset-password" name="new_password" type="password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="faculty-reset-confirm">Confirm Password</label>
                    <input class="form-input" id="faculty-reset-confirm" name="confirm_password" type="password" autocomplete="new-password" required>
                </div>
            </div>
            <div class="notice-box">Use a strong password with uppercase, lowercase, number, and special character.</div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Reset Password</button>
                <a class="btn-secondary" href="<?= e(url('faculty/login.php')) ?>">Back to Login</a>
            </div>
        </form>
    </div>
    <?php
});
