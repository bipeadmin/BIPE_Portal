<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = student_by_id((int) current_user()['id']);
$summary = attendance_summary_for_student((int) $student['id']);
$rows = query_all(
    'SELECT ats.attendance_date, ar.status, ats.remarks, t.full_name AS teacher_name
     FROM attendance_records ar
     INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
     INNER JOIN teachers t ON t.id = ats.teacher_id
     WHERE ar.student_id = :student_id
     ORDER BY ats.attendance_date DESC',
    ['student_id' => $student['id']]
);

render_dashboard_layout('My Attendance', 'student', 'attendance', 'student/attendance.css', 'student/attendance.js', function () use ($summary, $rows): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card"><p class="eyebrow">Attendance Percentage</p><h3 class="stat-value"><?= e((string) $summary['percentage']) ?>%</h3><p class="stat-label">Calculated from recorded attendance sessions</p></article>
        <article class="stat-card"><p class="eyebrow">Present</p><h3 class="stat-value"><?= e((string) $summary['present']) ?></h3><p class="stat-label">Classes marked present</p></article>
        <article class="stat-card"><p class="eyebrow">Absent</p><h3 class="stat-value"><?= e((string) $summary['absent']) ?></h3><p class="stat-label">Classes marked absent</p></article>
    </section>

    <article class="data-card">
        <div class="card-head"><div><p class="eyebrow">Attendance Register</p><h3 class="card-title">Class-by-class attendance history</h3></div></div>
        <?php if ($rows): ?>
            <div class="table-wrap"><table><thead><tr><th>Date</th><th>Status</th><th>Teacher</th><th>Remarks</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= e($row['attendance_date']) ?></td><td><span class="badge <?= $row['status'] === 'P' ? 'success' : 'danger' ?>"><?= e($row['status'] === 'P' ? 'Present' : 'Absent') ?></span></td><td><?= e($row['teacher_name']) ?></td><td><?= e((string) ($row['remarks'] ?? '-')) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <div class="empty-state">No attendance has been published for your account yet.</div>
        <?php endif; ?>
    </article>
    <?php
});




