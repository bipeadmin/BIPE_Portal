<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$departments = departments();
$departmentIds = array_values(array_map(static fn (array $department): int => (int) $department['id'], $departments));

$departmentQueryValue = trim((string) ($_GET['department_id'] ?? 'all'));
$allDepartmentsMode = strtolower($departmentQueryValue) === 'all';
if ($allDepartmentsMode) {
    $selectedDepartmentId = 0;
    $departmentQueryValue = 'all';
} else {
    $selectedDepartmentId = (int) $departmentQueryValue;
    if (!in_array($selectedDepartmentId, $departmentIds, true)) {
        $selectedDepartmentId = 0;
        $departmentQueryValue = 'all';
        $allDepartmentsMode = true;
    }
}

$semesterFilter = trim((string) ($_GET['semester_no'] ?? 'all'));
$allSemestersMode = strtolower($semesterFilter) === 'all';
$semesterNo = $allSemestersMode ? 0 : (int) $semesterFilter;
if (!$allSemestersMode && !in_array($semesterNo, semester_numbers(), true)) {
    $semesterNo = 0;
    $semesterFilter = 'all';
    $allSemestersMode = true;
}

$teacherId = (int) $teacher['id'];
$currentAcademicYearId = current_academic_year_id();

$attendanceWhere = ' WHERE ats.academic_year_id = :academic_year_id AND ats.teacher_id = :teacher_id';
$attendanceParams = ['academic_year_id' => $currentAcademicYearId, 'teacher_id' => $teacherId];
if (!$allDepartmentsMode) {
    $attendanceWhere .= ' AND ats.department_id = :department_id';
    $attendanceParams['department_id'] = $selectedDepartmentId;
}
if (!$allSemestersMode) {
    $attendanceWhere .= ' AND ats.semester_no = :semester_no';
    $attendanceParams['semester_no'] = $semesterNo;
}

$marksWhere = ' WHERE mu.academic_year_id = :academic_year_id AND mu.teacher_id = :teacher_id';
$marksParams = ['academic_year_id' => $currentAcademicYearId, 'teacher_id' => $teacherId];
if (!$allDepartmentsMode) {
    $marksWhere .= ' AND mu.department_id = :department_id';
    $marksParams['department_id'] = $selectedDepartmentId;
}
if (!$allSemestersMode) {
    $marksWhere .= ' AND mu.semester_no = :semester_no';
    $marksParams['semester_no'] = $semesterNo;
}

$assignmentWhere = ' WHERE a.academic_year_id = :academic_year_id AND a.teacher_id = :teacher_id';
$assignmentParams = ['academic_year_id' => $currentAcademicYearId, 'teacher_id' => $teacherId];
if (!$allDepartmentsMode) {
    $assignmentWhere .= ' AND a.department_id = :department_id';
    $assignmentParams['department_id'] = $selectedDepartmentId;
}
if (!$allSemestersMode) {
    $assignmentWhere .= ' AND a.semester_no = :semester_no';
    $assignmentParams['semester_no'] = $semesterNo;
}

$attendanceHistory = query_all(
    'SELECT ats.attendance_date, ats.year_level, ats.semester_no, d.name AS department_name,
            SUM(CASE WHEN ar.status = "P" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = "A" THEN 1 ELSE 0 END) AS absent_count,
            COUNT(ar.id) AS total_count
     FROM attendance_sessions ats
     INNER JOIN departments d ON d.id = ats.department_id
     LEFT JOIN attendance_records ar ON ar.attendance_session_id = ats.id'
     . $attendanceWhere . '
     GROUP BY ats.id, ats.attendance_date, ats.year_level, ats.semester_no, d.name
     ORDER BY ats.attendance_date DESC, ats.id DESC',
    $attendanceParams
);

$markUploads = query_all(
    'SELECT mu.*, s.subject_name, d.name AS department_name,
            COUNT(mr.id) AS recorded_count,
            SUM(CASE WHEN mr.is_absent = 1 THEN 1 ELSE 0 END) AS absent_count
     FROM mark_uploads mu
     INNER JOIN subjects s ON s.id = mu.subject_id
     INNER JOIN departments d ON d.id = mu.department_id
     LEFT JOIN mark_records mr ON mr.mark_upload_id = mu.id'
     . $marksWhere . '
     GROUP BY mu.id, s.subject_name, d.name
     ORDER BY mu.uploaded_at DESC, mu.id DESC',
    $marksParams
);

$assignmentRows = query_all(
    'SELECT a.*, s.subject_name, d.name AS department_name,
            SUM(CASE WHEN asb.submission_status = "submitted" THEN 1 ELSE 0 END) AS submitted_count,
            COUNT(asb.id) AS tracked_count
     FROM assignments a
     INNER JOIN subjects s ON s.id = a.subject_id
     INNER JOIN departments d ON d.id = a.department_id
     LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id'
     . $assignmentWhere . '
     GROUP BY a.id, s.subject_name, d.name
     ORDER BY COALESCE(a.updated_at, a.created_at) DESC, a.id DESC',
    $assignmentParams
);

$departmentLabels = [];
foreach ($attendanceHistory as $row) {
    $label = trim((string) ($row['department_name'] ?? ''));
    if ($label !== '') {
        $departmentLabels[$label] = true;
    }
}
foreach ($markUploads as $row) {
    $label = trim((string) ($row['department_name'] ?? ''));
    if ($label !== '') {
        $departmentLabels[$label] = true;
    }
}
foreach ($assignmentRows as $row) {
    $label = trim((string) ($row['department_name'] ?? ''));
    if ($label !== '') {
        $departmentLabels[$label] = true;
    }
}

$summary = [
    'departments' => count($departmentLabels),
    'attendance_sessions' => count($attendanceHistory),
    'assignment_sets' => count($assignmentRows),
    'marks_uploads' => count($markUploads),
];

render_dashboard_layout('Faculty Reports', 'teacher', 'reports', 'faculty/reports.css', 'faculty/reports.js', function () use ($teacher, $departments, $selectedDepartmentId, $allDepartmentsMode, $semesterNo, $allSemestersMode, $summary, $attendanceHistory, $markUploads, $assignmentRows): void {
    ?>
    <section class="data-card faculty-report-filter-card">
        <div class="card-head faculty-report-head">
            <div>
                <p class="eyebrow">Faculty Activity Report</p>
                <h3 class="card-title">All records saved by <?= e((string) ($teacher['full_name'] ?? 'the teacher')) ?></h3>
                <p class="card-subtitle">Review attendance, marks uploads, and assignment trackers created from this faculty account across departments and semesters.</p>
            </div>
        </div>
        <form method="get" class="filters faculty-report-filters">
            <div class="form-group">
                <label class="form-label" for="faculty-report-department">Department</label>
                <select class="form-select" id="faculty-report-department" name="department_id">
                    <option value="all" <?= $allDepartmentsMode ? 'selected' : '' ?>>All Departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $selectedDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-report-semester">Semester</label>
                <select class="form-select" id="faculty-report-semester" name="semester_no">
                    <option value="all" <?= $allSemestersMode ? 'selected' : '' ?>>All Semesters</option>
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-primary" type="submit">Load Report</button>
        </form>
    </section>

    <section class="stats-grid faculty-report-stats">
        <article class="stat-card"><p class="eyebrow">Departments</p><h3 class="stat-value"><?= e((string) $summary['departments']) ?></h3><p class="stat-label">Departments covered by this report</p></article>
        <article class="stat-card"><p class="eyebrow">Attendance Sessions</p><h3 class="stat-value"><?= e((string) $summary['attendance_sessions']) ?></h3><p class="stat-label">Saved attendance sheets</p></article>
        <article class="stat-card"><p class="eyebrow">Assignments</p><h3 class="stat-value"><?= e((string) $summary['assignment_sets']) ?></h3><p class="stat-label">Tracked assignment sets</p></article>
        <article class="stat-card"><p class="eyebrow">Marks Uploads</p><h3 class="stat-value"><?= e((string) $summary['marks_uploads']) ?></h3><p class="stat-label">Saved marks uploads</p></article>
    </section>

    <section class="grid-2 faculty-report-grid">
        <article class="data-card faculty-report-card">
            <div class="card-head faculty-report-head"><div><p class="eyebrow">Attendance History</p><h3 class="card-title">Attendance sessions saved by this teacher</h3></div></div>
            <?php if ($attendanceHistory): ?>
                <div class="table-wrap faculty-report-table-wrap">
                    <table class="faculty-report-table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Department</th>
                            <th>Semester</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($attendanceHistory as $row): ?>
                            <tr>
                                <td data-label="Date"><?= e((string) $row['attendance_date']) ?></td>
                                <td data-label="Department"><?= e((string) $row['department_name']) ?></td>
                                <td data-label="Semester"><?= e(semester_label((int) $row['semester_no'])) ?></td>
                                <td data-label="Present"><?= e((string) $row['present_count']) ?></td>
                                <td data-label="Absent"><?= e((string) $row['absent_count']) ?></td>
                                <td data-label="Total"><?= e((string) $row['total_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No attendance sessions match this report filter yet.</div>
            <?php endif; ?>
        </article>

        <article class="data-card faculty-report-card">
            <div class="card-head faculty-report-head"><div><p class="eyebrow">Marks Upload Report</p><h3 class="card-title">Marks uploaded by this teacher</h3></div></div>
            <?php if ($markUploads): ?>
                <div class="faculty-marks-report-list">
                    <?php foreach ($markUploads as $row): ?>
                        <article class="faculty-marks-report-item">
                            <div class="faculty-marks-report-top">
                                <div>
                                    <p class="eyebrow">Department</p>
                                    <h4 class="faculty-marks-report-title"><?= e((string) $row['department_name']) ?></h4>
                                </div>
                                <span class="badge info faculty-report-exam-pill"><?= e((string) $row['exam_type']) ?></span>
                            </div>
                            <div class="faculty-marks-report-subject-block">
                                <p class="faculty-marks-report-label">Subject</p>
                                <p class="faculty-report-subject-name"><?= e((string) $row['subject_name']) ?></p>
                            </div>
                            <div class="faculty-marks-report-meta">
                                <div class="faculty-marks-report-field">
                                    <span class="faculty-marks-report-label">Semester</span>
                                    <span class="faculty-report-semester-pill"><?= e(semester_label((int) $row['semester_no'])) ?></span>
                                </div>
                                <div class="faculty-marks-report-field">
                                    <span class="faculty-marks-report-label">Max Marks</span>
                                    <span class="faculty-report-metric"><?= e((string) $row['max_marks']) ?></span>
                                </div>
                                <div class="faculty-marks-report-field">
                                    <span class="faculty-marks-report-label">Recorded</span>
                                    <span class="faculty-report-metric"><?= e((string) $row['recorded_count']) ?></span>
                                </div>
                                <div class="faculty-marks-report-field faculty-marks-report-field-wide">
                                    <span class="faculty-marks-report-label">Uploaded</span>
                                    <span class="faculty-report-time"><?= e((string) $row['uploaded_at']) ?></span>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No marks uploads match this report filter yet.</div>
            <?php endif; ?>
        </article>
    </section>

    <article class="data-card faculty-report-card">
        <div class="card-head faculty-report-head"><div><p class="eyebrow">Assignment Report</p><h3 class="card-title">Assignment trackers saved by this teacher</h3></div></div>
        <?php if ($assignmentRows): ?>
            <div class="table-wrap faculty-report-table-wrap">
                <table class="faculty-report-table faculty-assignment-report-table">
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Semester</th>
                        <th>Subject</th>
                        <th>Assignment</th>
                        <th>Submitted</th>
                        <th>Tracked</th>
                        <th>Due Date</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assignmentRows as $row): ?>
                        <tr>
                            <td data-label="Department"><?= e((string) $row['department_name']) ?></td>
                            <td data-label="Semester"><?= e(semester_label((int) $row['semester_no'])) ?></td>
                            <td data-label="Subject"><?= e((string) $row['subject_name']) ?></td>
                            <td data-label="Assignment"><span class="badge info"><?= e((string) $row['assignment_label']) ?></span></td>
                            <td data-label="Submitted"><?= e((string) $row['submitted_count']) ?></td>
                            <td data-label="Tracked"><?= e((string) $row['tracked_count']) ?></td>
                            <td data-label="Due Date"><?= e((string) ($row['due_date'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No assignment trackers match this report filter yet.</div>
        <?php endif; ?>
    </article>
    <?php
});


