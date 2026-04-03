<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = require_current_student();

if (is_post()) {
    try {
        $profileImagePath = store_profile_image_upload((array) ($_FILES['profile_image'] ?? []), 'students', (string) ($student['profile_image_path'] ?? ''));
        update_student_profile(
            (int) $student['id'],
            trim((string) post('email')),
            trim((string) post('phone_number')),
            $profileImagePath
        );
        flash('success', 'Profile updated successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception, 'Profile update could not be completed right now. Please review your details and try again.');
    }

    redirect_to('student/dashboard.php');
}

$student = require_current_student();
$attendance = attendance_summary_for_student((int) $student['id']);
$marks = marks_rows_for_student((int) $student['id']);
$assignments = assignment_rows_for_student((int) $student['id']);
$submittedAssignments = count(array_filter($assignments, static fn (array $row): bool => ($row['submission_status'] ?? 'pending') === 'submitted'));
$profileImageUrl = profile_image_url($student['profile_image_path'] ?? null);

render_dashboard_layout('Student Profile', 'student', 'dashboard', 'student/dashboard.css', 'student/dashboard.js', function () use ($student, $attendance, $marks, $assignments, $submittedAssignments, $profileImageUrl): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card"><p class="eyebrow">Attendance</p><h3 class="stat-value"><?= e((string) $attendance['percentage']) ?>%</h3><p class="stat-label"><?= e((string) $attendance['present']) ?> present of <?= e((string) $attendance['total']) ?> marked classes</p></article>
        <article class="stat-card"><p class="eyebrow">Marks</p><h3 class="stat-value"><?= e((string) count($marks)) ?></h3><p class="stat-label">Published marks entries for this account</p></article>
        <article class="stat-card"><p class="eyebrow">Assignments</p><h3 class="stat-value"><?= e((string) $submittedAssignments) ?></h3><p class="stat-label">Submitted out of <?= e((string) count($assignments)) ?> tracked assignments</p></article>
    </section>

    <section class="grid-2 student-profile-grid">
        <article class="data-card">
            <div class="student-profile-panel">
                <div class="student-profile-avatar <?= $profileImageUrl ? 'has-image' : '' ?>">
                    <?php if ($profileImageUrl): ?>
                        <img src="<?= e($profileImageUrl) ?>" alt="<?= e($student['full_name']) ?>">
                    <?php else: ?>
                        <?= e(profile_image_initial((string) $student['full_name'])) ?>
                    <?php endif; ?>
                </div>
                <h3 class="card-title"><?= e($student['full_name']) ?></h3>
                <p class="muted mono student-profile-enrollment"><?= e($student['enrollment_no']) ?></p>
                <div class="student-profile-meta">
                    <div class="data-list-item"><strong>Department</strong><p class="muted"><?= e($student['department_name']) ?></p></div>
                    <div class="data-list-item"><strong>Current Class</strong><p class="muted"><?= e(year_label((int) $student['year_level'])) ?> · <?= e(semester_label((int) $student['semester_no'])) ?></p></div>
                    <div class="data-list-item"><strong>Email</strong><p class="muted"><?= e((string) ($student['email'] ?: '-')) ?></p></div>
                    <div class="data-list-item"><strong>Mobile Number</strong><p class="muted"><?= e((string) ($student['phone_number'] ?: 'Not added yet')) ?></p></div>
                </div>
            </div>
        </article>

        <article class="data-card">
            <div class="card-head"><div><p class="eyebrow">Edit Contact</p><h3 class="card-title">Update your photo and contact details</h3></div></div>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="student-email-profile">Email</label>
                        <input class="form-input" id="student-email-profile" name="email" type="email" value="<?= e((string) ($student['email'] ?? '')) ?>" autocomplete="email" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="student-phone-profile">Mobile Number</label>
                        <input class="form-input" id="student-phone-profile" name="phone_number" type="tel" value="<?= e((string) ($student['phone_number'] ?? '')) ?>" autocomplete="tel" placeholder="Add mobile number">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="student-profile-image">Profile Image</label>
                    <input class="form-input" id="student-profile-image" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp" data-file-input data-file-target="#student-profile-image-name">
                    <div class="file-hint" id="student-profile-image-name"><?= $profileImageUrl ? 'Current image available' : 'No image selected' ?></div>
                </div>
                <div class="notice-box">Image aur mobile number optional hain. Yahan jo mobile number save hoga wahi admin portal me bhi visible hoga.</div>
                <button class="btn-primary" type="submit">Save Profile</button>
            </form>
        </article>
    </section>

    <section class="grid-2">
        <article class="data-card">
            <div class="card-head"><div><p class="eyebrow">Quick Summary</p><h3 class="card-title">Academic snapshot</h3></div></div>
            <div class="stack">
                <div class="data-list-item"><strong>Attendance</strong><p class="muted"><?= e((string) $attendance['present']) ?> present · <?= e((string) $attendance['absent']) ?> absent</p></div>
                <div class="data-list-item"><strong>Marks Published</strong><p class="muted"><?= e((string) count($marks)) ?> line items across your subjects and exam types</p></div>
                <div class="data-list-item"><strong>Assignments</strong><p class="muted"><?= e((string) $submittedAssignments) ?> submitted · <?= e((string) (count($assignments) - $submittedAssignments)) ?> pending</p></div>
            </div>
        </article>

        <article class="data-card">
            <div class="card-head"><div><p class="eyebrow">Portal Note</p><h3 class="card-title">Your account data</h3></div></div>
            <div class="stack">
                <div class="data-list-item"><strong>Enrollment</strong><p class="muted mono"><?= e($student['enrollment_no']) ?></p></div>
                <div class="data-list-item"><strong>Registered Email</strong><p class="muted"><?= e((string) ($student['email'] ?: '-')) ?></p></div>
                <div class="data-list-item"><strong>Registered Mobile</strong><p class="muted"><?= e((string) ($student['phone_number'] ?: 'Not added yet')) ?></p></div>
            </div>
        </article>
    </section>
    <?php
});
