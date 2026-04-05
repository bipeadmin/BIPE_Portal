<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$summary = teacher_record_summary((int) $teacher['id']);
$attendanceHistory = array_slice(attendance_history_for_department((int) $teacher['department_id']), 0, 6);
$markUploads = array_slice(mark_upload_rows_for_department((int) $teacher['department_id']), 0, 6);
$assignmentRows = array_slice(assignment_rows_for_department((int) $teacher['department_id']), 0, 6);

render_dashboard_layout('Faculty Dashboard', 'teacher', 'dashboard', 'faculty/dashboard.css', 'faculty/dashboard.js', function () use ($teacher, $summary, $attendanceHistory, $markUploads, $assignmentRows): void {
    ?>
    <section class="stats-grid faculty-dashboard-stats">
        <article class="stat-card"><p class="eyebrow">Department Students</p><h3 class="stat-value"><?= e((string) $summary['student_count']) ?></h3><p class="stat-label"><?= e($teacher['department_name'] ?? '') ?> roster count</p></article>
        <article class="stat-card"><p class="eyebrow">Attendance Average</p><h3 class="stat-value"><?= e((string) $summary['attendance_percent']) ?>%</h3><p class="stat-label">Department-wide recorded attendance</p></article>
        <article class="stat-card"><p class="eyebrow">Assignments</p><h3 class="stat-value"><?= e((string) $summary['assignment_count']) ?></h3><p class="stat-label">Tracked assignment sets</p></article>
        <article class="stat-card"><p class="eyebrow">Marks Uploads</p><h3 class="stat-value"><?= e((string) $summary['marks_uploads']) ?></h3><p class="stat-label">Saved exam uploads</p></article>
    </section>

    <section class="grid-2 faculty-dashboard-grid">
        <article class="data-card faculty-dashboard-card">
            <div class="card-head"><div><p class="eyebrow">Quick Links</p><h3 class="card-title">Common faculty tasks</h3></div></div>
            <div class="data-list faculty-dashboard-links">
                <a class="data-list-item" href="<?= e(url('faculty/attendance.php')) ?>"><strong>Mark attendance</strong><p class="muted">Create or update the daily attendance sheet.</p></a>
                <a class="data-list-item" href="<?= e(url('faculty/students.php')) ?>"><strong>Browse students</strong><p class="muted">View student details across all departments with filters.</p></a>
                <a class="data-list-item" href="<?= e(url('faculty/marks.php')) ?>"><strong>Upload marks</strong><p class="muted">Enter internal marks by subject and mark type.</p></a>
                <a class="data-list-item" href="<?= e(url('faculty/assignments.php')) ?>"><strong>Track assignments</strong><p class="muted">Update submitted and pending assignment status.</p></a>
                <a class="data-list-item" href="<?= e(url('faculty/profile.php')) ?>"><strong>My profile</strong><p class="muted">Update your photo, phone, email, and password.</p></a>
            </div>
        </article>

        <article class="data-card faculty-dashboard-card">
            <div class="card-head"><div><p class="eyebrow">Recent Marks</p><h3 class="card-title">Latest uploads</h3></div></div>
            <?php if ($markUploads): ?>
                <div class="table-wrap faculty-dashboard-table-wrap">
                    <table class="faculty-dashboard-table">
                        <thead><tr><th>Subject</th><th>Exam</th><th>Max Marks</th><th>Uploaded</th></tr></thead>
                        <tbody>
                        <?php foreach ($markUploads as $row): ?>
                            <tr>
                                <td data-label="Subject"><?= e((string) $row['subject_name']) ?></td>
                                <td data-label="Exam"><?= e((string) $row['exam_type']) ?></td>
                                <td data-label="Max Marks"><?= e((string) $row['max_marks']) ?></td>
                                <td data-label="Uploaded"><?= e((string) $row['uploaded_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No marks uploads are available yet.</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="grid-2 faculty-dashboard-grid">
        <article class="data-card faculty-dashboard-card">
            <div class="card-head"><div><p class="eyebrow">Recent Attendance</p><h3 class="card-title">Latest department attendance sessions</h3></div></div>
            <?php if ($attendanceHistory): ?>
                <div class="table-wrap faculty-dashboard-table-wrap">
                    <table class="faculty-dashboard-table">
                        <thead><tr><th>Date</th><th>Present</th><th>Absent</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($attendanceHistory as $row): ?>
                            <tr>
                                <td data-label="Date"><?= e((string) $row['attendance_date']) ?></td>
                                <td data-label="Present"><?= e((string) $row['present_count']) ?></td>
                                <td data-label="Absent"><?= e((string) $row['absent_count']) ?></td>
                                <td data-label="Total"><?= e((string) $row['total_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No attendance sessions have been recorded yet.</div>
            <?php endif; ?>
        </article>

        <article class="data-card faculty-dashboard-card">
            <div class="card-head"><div><p class="eyebrow">Assignments</p><h3 class="card-title">Recently tracked submission sets</h3></div></div>
            <?php if ($assignmentRows): ?>
                <div class="table-wrap faculty-dashboard-table-wrap">
                    <table class="faculty-dashboard-table">
                        <thead><tr><th>Subject</th><th>Assignment</th><th>Submitted</th><th>Tracked</th></tr></thead>
                        <tbody>
                        <?php foreach ($assignmentRows as $row): ?>
                            <tr>
                                <td data-label="Subject"><?= e((string) $row['subject_name']) ?></td>
                                <td data-label="Assignment"><?= e((string) $row['assignment_label']) ?></td>
                                <td data-label="Submitted"><?= e((string) $row['submitted_count']) ?></td>
                                <td data-label="Tracked"><?= e((string) $row['tracked_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No assignment tracking records are available yet.</div>
            <?php endif; ?>
        </article>
    </section>
    <?php
});

