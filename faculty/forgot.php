<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'teacher') {
    redirect_to('faculty/dashboard.php');
}

if (is_post()) {
    $facultyId = trim((string) post('teacher_code'));
    $requestType = trim((string) post('request_type'));
    $newPassword = (string) post('new_password');

    try {
        if ($facultyId === '') {
            throw new RuntimeException('Faculty ID is required.');
        }

        if (!in_array($requestType, ['forgot_password', 'forgot_faculty_id'], true)) {
            throw new RuntimeException('Select a valid request type.');
        }

        if ($requestType === 'forgot_password' && trim($newPassword) === '') {
            throw new RuntimeException('Enter a new password request value.');
        }

        $request = create_teacher_access_request($facultyId, $requestType, $newPassword);
        audit_log(
            'teacher',
            (string) ($request['requester_identifier'] ?? $facultyId),
            'SUPPORT_REQUEST_SUBMITTED',
            support_request_type_label((string) ($request['request_type'] ?? $requestType))
        );
        flash('info', 'Wait for admin approval.');
    } catch (Throwable $exception) {
        flash_exception($exception, 'Your request could not be submitted right now. Please review the form details and try again.');
    }

    redirect_to('faculty/forgot.php');
}

render_auth_layout('Faculty Password Help', 'Raise a faculty access request for password or faculty ID support.', 'faculty/forgot.css', 'faculty/forgot.js', function (): void {
    ?>
    <div class="stack faculty-forgot-shell">
        <div class="notice-box warning">Submit your faculty recovery request below. The request will wait in the admin approval queue until it is reviewed.</div>
        <form method="post" class="form-grid faculty-forgot-form" data-faculty-forgot-form>
            <div class="form-group">
                <label class="form-label" for="faculty-forgot-id">Faculty ID</label>
                <input class="form-input" id="faculty-forgot-id" name="teacher_code" placeholder="Enter faculty ID" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-request-type">Request Type</label>
                <select class="form-select" id="faculty-request-type" name="request_type" data-forgot-request-type required>
                    <option value="">Select request</option>
                    <option value="forgot_password">Forget Password</option>
                    <option value="forgot_faculty_id">Forget Faculty ID</option>
                </select>
            </div>
            <div class="form-group faculty-forgot-password-group is-hidden" data-forgot-password-group>
                <label class="form-label" for="faculty-new-password">New Password</label>
                <input class="form-input" id="faculty-new-password" name="new_password" type="password" autocomplete="new-password" placeholder="Enter new password request">
            </div>
            <div class="notice-box">If you select <strong>Forget Password</strong>, enter the new password you want to request. If you select <strong>Forget Faculty ID</strong>, no extra field is needed.</div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Submit Request</button>
                <a class="btn-secondary" href="<?= e(url('faculty/login.php')) ?>">Back to Login</a>
            </div>
        </form>
    </div>
    <?php
});
