<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'admin') {
    redirect_to('admin/dashboard.php');
}

if (is_post()) {
    $action = (string) post('action');
    $username = trim((string) post('username'));

    try {
        if ($action === 'request') {
            rate_limit_or_fail('otp_request_admin:' . strtolower($username), (int) config('security.otp_rate_limit', 5), 600);
            $result = request_admin_password_reset_delivery($username);
            flash('success', password_reset_request_success_message($result['preview_otp'] ?? null));
            redirect_to('admin/forgot.php');
        }

        if ($action === 'reset') {
            rate_limit_or_fail('otp_reset_admin:' . strtolower($username), (int) config('security.otp_rate_limit', 5), 600);
            $otp = trim((string) post('otp'));
            $newPassword = (string) post('new_password');
            $confirmPassword = (string) post('confirm_password');

            if ($newPassword !== $confirmPassword) {
                flash('error', 'Password confirmation does not match.');
                redirect_to('admin/forgot.php');
            }

            if (reset_admin_password_with_otp($username, $otp, $newPassword)) {
                flash('success', 'Password updated successfully. Please login with the new password.');
                redirect_to('admin/login.php');
            }

            flash('error', 'Invalid or expired OTP. Please request a new code.');
            redirect_to('admin/forgot.php');
        }
    } catch (Throwable $exception) {
        flash_exception($exception, 'Password reset could not be completed right now. Please try again later.');
        redirect_to('admin/forgot.php');
    }
}

render_auth_layout('Admin Password Reset', 'Request a one-time code on the administrator email address and use it to reset the password securely.', 'admin/forgot.css', 'admin/forgot.js', function (): void {
    ?>
    <div class="stack">
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="request">
            <div class="form-group">
                <label class="form-label" for="request-username">Admin Username</label>
                <input class="form-input" id="request-username" name="username" placeholder="Enter admin username" autocomplete="username" required>
            </div>
            <button class="btn-primary" type="submit">Send OTP</button>
        </form>
        <div class="notice-box warning">
            Password reset codes are delivered to the email configured on the administrator account and expire in 10 minutes.
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="reset">
            <div class="form-grid two">
                <div class="form-group">
                    <label class="form-label" for="reset-username">Admin Username</label>
                    <input class="form-input" id="reset-username" name="username" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="otp">OTP</label>
                    <input class="form-input mono" id="otp" name="otp" maxlength="6" placeholder="6-digit code" inputmode="numeric" required>
                </div>
            </div>
            <div class="form-grid two">
                <div class="form-group">
                    <label class="form-label" for="new-password">New Password</label>
                    <input class="form-input" id="new-password" name="new_password" type="password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm-password">Confirm Password</label>
                    <input class="form-input" id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" required>
                </div>
            </div>
            <div class="notice-box">Use a strong password with uppercase, lowercase, number, and special character.</div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Reset Password</button>
                <a class="btn-secondary" href="<?= e(url('admin/login.php')) ?>">Back to Login</a>
            </div>
        </form>
    </div>
    <?php
});
