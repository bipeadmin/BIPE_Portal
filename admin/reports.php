<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$departmentReports = [];
foreach (departments() as $department) {
    $departmentId = (int) $department['id'];
    $departmentReports[] = [
        'name' => $department['name'],
        'short_name' => $department['short_name'],
        'students_total' => (int) query_value('SELECT COUNT(*) FROM students WHERE department_id = :department_id', ['department_id' => $departmentId]),
        'faculty_total' => (int) query_value('SELECT COUNT(*) FROM teachers WHERE department_id = :department_id AND status = "approved"', ['department_id' => $departmentId]),
        'attendance_pct' => attendance_percent_for_department($departmentId),
        'marks_pct' => (int) round((float) query_value(
            'SELECT COALESCE(AVG((mr.marks_obtained / NULLIF(mu.max_marks, 0)) * 100), 0)
             FROM mark_records mr
             INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
             WHERE mu.department_id = :department_id',
            ['department_id' => $departmentId]
        )),
        'assignment_pct' => (int) round((float) query_value(
            'SELECT COALESCE(AVG(CASE WHEN asb.submission_status = "submitted" THEN 100 ELSE 0 END), 0)
             FROM assignment_submissions asb
             INNER JOIN assignments a ON a.id = asb.assignment_id
             WHERE a.department_id = :department_id',
            ['department_id' => $departmentId]
        )),
    ];
}

$totals = [
    'holidays' => (int) query_value('SELECT COUNT(*) FROM holiday_events WHERE academic_year_id = :academic_year_id', ['academic_year_id' => current_academic_year_id()]),
    'attendance_sessions' => (int) query_value('SELECT COUNT(*) FROM attendance_sessions WHERE academic_year_id = :academic_year_id', ['academic_year_id' => current_academic_year_id()]),
    'mark_uploads' => (int) query_value('SELECT COUNT(*) FROM mark_uploads WHERE academic_year_id = :academic_year_id', ['academic_year_id' => current_academic_year_id()]),
    'assignments' => (int) query_value('SELECT COUNT(*) FROM assignments WHERE academic_year_id = :academic_year_id', ['academic_year_id' => current_academic_year_id()]),
];

render_dashboard_layout('Analytics & Reports', 'admin', 'reports', 'admin/reports.css', 'admin/reports.js', function () use ($departmentReports, $totals): void {
    ?>
    <section class="stats-grid report-stats">
        <article class="stat-card"><p class="eyebrow">Holiday Events</p><h3 class="stat-value"><?= e((string) $totals['holidays']) ?></h3><p class="stat-label">Calendar overrides in current year</p></article>
        <article class="stat-card"><p class="eyebrow">Attendance Sessions</p><h3 class="stat-value"><?= e((string) $totals['attendance_sessions']) ?></h3><p class="stat-label">Recorded teaching days</p></article>
        <article class="stat-card"><p class="eyebrow">Mark Uploads</p><h3 class="stat-value"><?= e((string) $totals['mark_uploads']) ?></h3><p class="stat-label">Stored exam upload records</p></article>
        <article class="stat-card"><p class="eyebrow">Assignments</p><h3 class="stat-value"><?= e((string) $totals['assignments']) ?></h3><p class="stat-label">Tracked assignment sets</p></article>
    </section>

    <article class="data-card department-report-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Department Performance</p>
                <h3 class="card-title">Cross-department academic indicators</h3>
            </div>
        </div>
        <div class="table-wrap department-report-wrap">
            <table class="department-report-table">
                <thead>
                <tr>
                    <th>Department</th>
                    <th>Students</th>
                    <th>Faculty</th>
                    <th>Attendance %</th>
                    <th>Average Marks %</th>
                    <th>Assignment Submit %</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($departmentReports as $row): ?>
                    <tr>
                        <td data-label="Department"><strong><?= e($row['name']) ?></strong><span class="department-code"><?= e($row['short_name']) ?></span></td>
                        <td data-label="Students"><?= e((string) $row['students_total']) ?></td>
                        <td data-label="Faculty"><?= e((string) $row['faculty_total']) ?></td>
                        <td data-label="Attendance"><span class="badge <?= $row['attendance_pct'] >= 75 ? 'success' : ($row['attendance_pct'] >= 60 ? 'warning' : 'danger') ?>"><?= e((string) $row['attendance_pct']) ?>%</span></td>
                        <td data-label="Average Marks"><span class="badge <?= $row['marks_pct'] >= 60 ? 'success' : ($row['marks_pct'] >= 40 ? 'warning' : 'danger') ?>"><?= e((string) $row['marks_pct']) ?>%</span></td>
                        <td data-label="Assignment Submit"><span class="badge <?= $row['assignment_pct'] >= 60 ? 'success' : ($row['assignment_pct'] >= 40 ? 'warning' : 'danger') ?>"><?= e((string) $row['assignment_pct']) ?>%</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php
});
