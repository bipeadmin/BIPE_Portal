<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();

if (is_post()) {
    $newPassword = trim((string) post('new_password'));
    $confirmPassword = trim((string) post('confirm_password'));

    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        flash('error', 'Password confirmation does not match.');
        redirect_to('faculty/profile.php');
    }

    try {
        $profileImagePath = store_profile_image_upload((array) ($_FILES['profile_image'] ?? []), 'teachers', (string) ($teacher['profile_image_path'] ?? ''));
        update_teacher_profile(
            (int) $teacher['id'],
            trim((string) post('full_name')),
            trim((string) post('email')),
            trim((string) post('phone_number')),
            $profileImagePath,
            trim((string) post('current_password')) !== '' ? (string) post('current_password') : null,
            $newPassword !== '' ? $newPassword : null
        );
        update_current_user_session(['name' => trim((string) post('full_name'))]);
        audit_log('teacher', (string) $teacher['teacher_code'], 'PROFILE_UPDATE', 'Faculty profile updated');
        flash('success', 'Faculty profile updated successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception, 'Profile update could not be completed right now. Please review your details and try again.');
    }

    redirect_to('faculty/profile.php');
}

$teacher = require_current_teacher();
$profileImageUrl = profile_image_url($teacher['profile_image_path'] ?? null);

render_dashboard_layout('My Profile', 'teacher', 'profile', 'faculty/profile.css', 'faculty/profile.js', function () use ($teacher, $profileImageUrl): void {
    ?>
    <section class="grid-2 profile-grid">
        <article class="data-card">
            <div class="profile-panel">
                <div class="profile-avatar <?= $profileImageUrl ? 'has-image' : '' ?>">
                    <?php if ($profileImageUrl): ?>
                        <img src="<?= e($profileImageUrl) ?>" alt="<?= e($teacher['full_name']) ?>">
                    <?php else: ?>
                        <?= e(profile_image_initial((string) $teacher['full_name'])) ?>
                    <?php endif; ?>
                </div>
                <h3 class="card-title"><?= e($teacher['full_name']) ?></h3>
                <p class="muted"><?= e($teacher['department_name']) ?></p>
                <p class="mono profile-code"><?= e($teacher['teacher_code']) ?></p>
                <div class="profile-meta-list">
                    <div class="data-list-item"><strong>Email</strong><p class="muted"><?= e((string) $teacher['email']) ?></p></div>
                    <div class="data-list-item"><strong>Phone</strong><p class="muted"><?= e((string) ($teacher['phone_number'] ?: 'Not added yet')) ?></p></div>
                </div>
            </div>
        </article>

        <article class="data-card">
            <div class="card-head"><div><p class="eyebrow">Edit Profile</p><h3 class="card-title">Update account details</h3></div></div>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="teacher-name-profile">Full Name</label>
                        <input class="form-input" id="teacher-name-profile" name="full_name" value="<?= e($teacher['full_name']) ?>" autocomplete="name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="teacher-email-profile">Email</label>
                        <input class="form-input" id="teacher-email-profile" name="email" type="email" value="<?= e($teacher['email']) ?>" autocomplete="email" required>
                    </div>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="teacher-phone-profile">Phone Number</label>
                        <input class="form-input" id="teacher-phone-profile" name="phone_number" type="tel" value="<?= e((string) ($teacher['phone_number'] ?? '')) ?>" autocomplete="tel" placeholder="Add contact number">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="teacher-profile-image">Profile Image</label>
                        <input class="form-input" id="teacher-profile-image" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp" data-file-input data-file-target="#teacher-profile-file-name">
                        <div class="file-hint" id="teacher-profile-file-name"><?= $profileImageUrl ? 'Current image available' : 'No image selected' ?></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Department</label>
                    <input class="form-input" value="<?= e($teacher['department_name']) ?>" disabled>
                </div>
                <div class="notice-box">Leave the password fields blank if you only want to update photo, phone number, name, or email.</div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="teacher-current-password">Current Password</label>
                        <input class="form-input" id="teacher-current-password" name="current_password" type="password" autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="teacher-new-password">New Password</label>
                        <input class="form-input" id="teacher-new-password" name="new_password" type="password" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="teacher-confirm-password">Confirm New Password</label>
                    <input class="form-input" id="teacher-confirm-password" name="confirm_password" type="password" autocomplete="new-password">
                </div>
                <button class="btn-primary" type="submit">Save Changes</button>
            </form>
        </article>
    </section>
    <?php
});
