<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = student_by_id((int) current_user()['id']);
$attendance = attendance_summary_for_student((int) $student['id']);
$marks = marks_rows_for_student((int) $student['id']);
$assignments = assignment_rows_for_student((int) $student['id']);
$submittedAssignments = count(array_filter($assignments, static fn (array $row): bool => ($row['submission_status'] ?? 'pending') === 'submitted'));

render_dashboard_layout('Student Profile', 'student', 'dashboard', 'student/dashboard.css', 'student/dashboard.js', function () use ($student, $attendance, $marks, $assignments, $submittedAssignments): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card"><p class="eyebrow">Attendance</p><h3 class="stat-value"><?= e((string) $attendance['percentage']) ?>%</h3><p class="stat-label"><?= e((string) $attendance['present']) ?> present of <?= e((string) $attendance['total']) ?> marked classes</p></article>
        <article class="stat-card"><p class="eyebrow">Marks</p><h3 class="stat-value"><?= e((string) count($marks)) ?></h3><p class="stat-label">Published marks entries for this account</p></article>
        <article class="stat-card"><p class="eyebrow">Assignments</p><h3 class="stat-value"><?= e((string) $submittedAssignments) ?></h3><p class="stat-label">Submitted out of <?= e((string) count($assignments)) ?> tracked assignments</p></article>
    </section>

    <section class="grid-2">
        <article class="data-card">
            <div class="card-head"><div><p class="eyebrow">Personal Profile</p><h3 class="card-title">Student record from the portal database</h3></div></div>
            <div class="stack">
                <div class="data-list-item"><strong>Name</strong><p class="muted"><?= e($student['full_name']) ?></p></div>
                <div class="data-list-item"><strong>Enrollment</strong><p class="muted mono"><?= e($student['enrollment_no']) ?></p></div>
                <div class="data-list-item"><strong>Department</strong><p class="muted"><?= e($student['department_name']) ?></p></div>
                <div class="data-list-item"><strong>Current Class</strong><p class="muted"><?= e(year_label((int) $student['year_level'])) ?> · <?= e(semester_label((int) $student['semester_no'])) ?></p></div>
            </div>
        </article>

        <article class="data-card">
            <div class="card-head"><div><p class="eyebrow">Quick Summary</p><h3 class="card-title">Academic snapshot</h3></div></div>
            <div class="stack">
                <div class="data-list-item"><strong>Attendance</strong><p class="muted"><?= e((string) $attendance['present']) ?> present · <?= e((string) $attendance['absent']) ?> absent</p></div>
                <div class="data-list-item"><strong>Marks Published</strong><p class="muted"><?= e((string) count($marks)) ?> line items across your subjects and exam types</p></div>
                <div class="data-list-item"><strong>Assignments</strong><p class="muted"><?= e((string) $submittedAssignments) ?> submitted · <?= e((string) (count($assignments) - $submittedAssignments)) ?> pending</p></div>
            </div>
        </article>
    </section>
    <?php
});




