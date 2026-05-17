<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$viewOptions = [
    'overview' => 'Overview',
    'attendance' => 'Attendance Wise',
    'assignment' => 'Assignment Wise',
    'subject' => 'Subject Wise',
];
$reportView = strtolower(trim((string) ($_GET['report_view'] ?? 'overview')));
if (!array_key_exists($reportView, $viewOptions)) {
    $reportView = 'overview';
}

$departmentRows = departments();
$departmentMap = [];
foreach ($departmentRows as $departmentRow) {
    $departmentMap[(int) $departmentRow['id']] = $departmentRow;
}

$departmentId = (int) ($_GET['department_id'] ?? 0);
if ($departmentId > 0 && !isset($departmentMap[$departmentId])) {
    $departmentId = 0;
}

$semesterRows = query_all(
    'SELECT department_id, semester_no
     FROM (
         SELECT DISTINCT department_id, semester_no FROM students
         UNION
         SELECT DISTINCT department_id, semester_no FROM subjects
     ) class_filters
     ORDER BY department_id ASC, semester_no ASC'
);
$semestersByDepartment = [];
foreach ($semesterRows as $row) {
    $rowDepartmentId = (int) ($row['department_id'] ?? 0);
    $semesterNoValue = (int) ($row['semester_no'] ?? 0);
    if ($rowDepartmentId <= 0 || $semesterNoValue <= 0) {
        continue;
    }

    $semestersByDepartment[$rowDepartmentId][] = $semesterNoValue;
}

$allSemesterOptions = semester_numbers();
$semesterOptions = $departmentId > 0 ? ($semestersByDepartment[$departmentId] ?? []) : $allSemesterOptions;
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
if (!in_array($semesterNo, $semesterOptions, true)) {
    $semesterNo = 0;
}

$subjectRows = query_all(
    'SELECT id, department_id, semester_no, subject_name
     FROM subjects
     ORDER BY department_id ASC, semester_no ASC, subject_name ASC'
);
$subjectsByClass = [];
foreach ($subjectRows as $subjectRow) {
    $classKey = (int) ($subjectRow['department_id'] ?? 0) . ':' . (int) ($subjectRow['semester_no'] ?? 0);
    $subjectsByClass[$classKey][] = $subjectRow;
}

$classKey = $departmentId . ':' . $semesterNo;
$subjectOptions = $departmentId > 0 && $semesterNo > 0 ? ($subjectsByClass[$classKey] ?? []) : [];
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$subjectOptionIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $subjectOptions);
if ($subjectId > 0 && !in_array($subjectId, $subjectOptionIds, true)) {
    $subjectId = 0;
}

$assignmentRows = query_all(
    'SELECT DISTINCT department_id, semester_no, subject_id, assignment_label
     FROM assignments
     WHERE academic_year_id = :academic_year_id
     ORDER BY assignment_label ASC',
    ['academic_year_id' => current_academic_year_id()]
);
$assignmentsByClassSubject = [];
foreach ($assignmentRows as $assignmentRow) {
    $rowDepartmentId = (int) ($assignmentRow['department_id'] ?? 0);
    $rowSemesterNo = (int) ($assignmentRow['semester_no'] ?? 0);
    $rowSubjectId = (int) ($assignmentRow['subject_id'] ?? 0);
    $label = trim((string) ($assignmentRow['assignment_label'] ?? ''));
    if ($rowDepartmentId <= 0 || $rowSemesterNo <= 0 || $rowSubjectId <= 0 || $label === '') {
        continue;
    }

    $allKey = $rowDepartmentId . ':' . $rowSemesterNo . ':0';
    $subjectKey = $rowDepartmentId . ':' . $rowSemesterNo . ':' . $rowSubjectId;
    $assignmentsByClassSubject[$allKey][$label] = $label;
    $assignmentsByClassSubject[$subjectKey][$label] = $label;
}

$assignmentKey = $departmentId . ':' . $semesterNo . ':' . $subjectId;
$assignmentOptions = $departmentId > 0 && $semesterNo > 0
    ? array_values($assignmentsByClassSubject[$assignmentKey] ?? [])
    : [];
$assignmentLabel = trim((string) ($_GET['assignment_label'] ?? ''));
if ($reportView !== 'assignment' || !in_array($assignmentLabel, $assignmentOptions, true)) {
    $assignmentLabel = '';
}

$reportRows = admin_department_report_rows($departmentId, $semesterNo, $subjectId, $assignmentLabel);
usort($reportRows, static function (array $left, array $right) use ($reportView): int {
    $leftMetric = match ($reportView) {
        'attendance' => (int) ($left['attendance']['percentage'] ?? 0),
        'assignment' => (int) ($left['assignment']['percentage'] ?? 0),
        'subject' => (float) ($left['subject']['average_percentage'] ?? -1),
        default => (float) ($left['overall_marks']['average_percentage'] ?? -1),
    };
    $rightMetric = match ($reportView) {
        'attendance' => (int) ($right['attendance']['percentage'] ?? 0),
        'assignment' => (int) ($right['assignment']['percentage'] ?? 0),
        'subject' => (float) ($right['subject']['average_percentage'] ?? -1),
        default => (float) ($right['overall_marks']['average_percentage'] ?? -1),
    };

    return $rightMetric <=> $leftMetric;
});

$summary = admin_department_report_summary($reportRows);
$selectedDepartment = $departmentId > 0 ? ($departmentMap[$departmentId] ?? null) : null;
$selectedSubject = null;
foreach ($subjectOptions as $subjectOption) {
    if ((int) ($subjectOption['id'] ?? 0) === $subjectId) {
        $selectedSubject = $subjectOption;
        break;
    }
}

$metricBadgeClass = static function (int|float $value, int $success = 75, int $warning = 50): string {
    if ($value >= $success) {
        return 'success';
    }

    return $value >= $warning ? 'warning' : 'danger';
};

$statusBadgeClass = static function (string $status): string {
    return match (strtolower(trim($status))) {
        'pass' => 'success',
        'fail', 'absent' => 'danger',
        default => 'warning',
    };
};

$semesterLabel = $semesterNo > 0 ? semester_label($semesterNo) : 'All Semesters';
$selectedSubjectLabel = $selectedSubject['subject_name'] ?? 'All Subjects';
$selectedAssignmentLabel = $assignmentLabel !== '' ? $assignmentLabel : 'All Assignments';
$reportContextText = ($selectedDepartment ? $selectedDepartment['name'] : 'All Departments')
    . ' | ' . $semesterLabel
    . ' | ' . $selectedSubjectLabel
    . ($reportView === 'assignment' ? ' | ' . $selectedAssignmentLabel : '');

$filterData = [
    'allSemesters' => array_map(
        static fn (int $value): array => ['value' => (string) $value, 'label' => semester_label($value)],
        $allSemesterOptions
    ),
    'semestersByDepartment' => [],
    'subjectsByClass' => [],
    'assignmentsByClassSubject' => [],
];

foreach ($semestersByDepartment as $rowDepartmentId => $semesters) {
    $filterData['semestersByDepartment'][(string) $rowDepartmentId] = array_map(
        static fn (int $value): array => ['value' => (string) $value, 'label' => semester_label($value)],
        $semesters
    );
}

foreach ($subjectsByClass as $key => $rows) {
    $options = [['value' => '0', 'label' => 'All Subjects']];
    foreach ($rows as $row) {
        $options[] = [
            'value' => (string) ($row['id'] ?? 0),
            'label' => (string) ($row['subject_name'] ?? 'Subject'),
        ];
    }
    $filterData['subjectsByClass'][$key] = $options;
}

foreach ($assignmentsByClassSubject as $key => $labels) {
    $options = [['value' => '', 'label' => 'All Assignments']];
    foreach (array_values($labels) as $label) {
        $options[] = ['value' => (string) $label, 'label' => (string) $label];
    }
    $filterData['assignmentsByClassSubject'][$key] = $options;
}

render_dashboard_layout('Analytics & Reports', 'admin', 'reports', 'admin/reports.css', 'admin/reports.js', function () use ($departmentRows, $departmentId, $semesterOptions, $semesterNo, $subjectOptions, $subjectId, $assignmentOptions, $assignmentLabel, $viewOptions, $reportView, $reportRows, $summary, $selectedSubjectLabel, $reportContextText, $metricBadgeClass, $statusBadgeClass, $filterData): void {
    ?>
    <section class="stats-grid report-stats">
        <article class="stat-card"><p class="eyebrow">Departments In View</p><h3 class="stat-value"><?= e((string) $summary['department_count']) ?></h3><p class="stat-label">Filtered departments in current report</p></article>
        <article class="stat-card"><p class="eyebrow">Students Covered</p><h3 class="stat-value"><?= e((string) $summary['student_count']) ?></h3><p class="stat-label">Students included in department aggregates</p></article>
        <article class="stat-card"><p class="eyebrow">Average Attendance</p><h3 class="stat-value"><?= e((string) $summary['attendance_avg']) ?>%</h3><p class="stat-label">Department-wise attendance average</p></article>
        <article class="stat-card"><p class="eyebrow">Assignment Completion</p><h3 class="stat-value"><?= e((string) $summary['assignment_avg']) ?>%</h3><p class="stat-label">Department-wise submission average</p></article>
        <article class="stat-card"><p class="eyebrow">Overall Marks Avg</p><h3 class="stat-value"><?= e($summary['marks_display']) ?></h3><p class="stat-label">All marks shown only as averages</p></article>
    </section>

    <article class="data-card report-filter-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Smart Filters</p>
                <h3 class="card-title">Department-wise academic analytics</h3>
            </div>
        </div>
        <form method="get" class="filters admin-reports-filter-form">
            <div class="form-field">
                <label class="form-label" for="report-view">Report Mode</label>
                <select class="form-select" id="report-view" name="report_view" data-report-filter="view">
                    <?php foreach ($viewOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $reportView === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="report-department">Department</label>
                <select class="form-select" id="report-department" name="department_id" data-report-filter="department">
                    <option value="0">All Departments</option>
                    <?php foreach ($departmentRows as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="report-semester">Semester</label>
                <select class="form-select" id="report-semester" name="semester_no" data-report-filter="semester">
                    <option value="0">All Semesters</option>
                    <?php foreach ($semesterOptions as $value): ?>
                        <option value="<?= e((string) $value) ?>" <?= $semesterNo === (int) $value ? 'selected' : '' ?>><?= e(semester_label((int) $value)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field" data-report-filter-wrap="subject">
                <label class="form-label" for="report-subject">Subject</label>
                <select class="form-select" id="report-subject" name="subject_id" data-report-filter="subject" <?= $departmentId <= 0 || $semesterNo <= 0 ? 'disabled' : '' ?>>
                    <option value="0">All Subjects</option>
                    <?php foreach ($subjectOptions as $subject): ?>
                        <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e((string) $subject['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field" data-report-filter-wrap="assignment">
                <label class="form-label" for="report-assignment">Assignment</label>
                <select class="form-select" id="report-assignment" name="assignment_label" data-report-filter="assignment" <?= $reportView !== 'assignment' || $departmentId <= 0 || $semesterNo <= 0 ? 'disabled' : '' ?>>
                    <option value="">All Assignments</option>
                    <?php foreach ($assignmentOptions as $label): ?>
                        <option value="<?= e($label) ?>" <?= $assignmentLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions report-filter-actions">
                <button class="btn-primary" type="submit">Apply Report</button>
            </div>
            <script type="application/json" data-report-filter-data><?= (string) json_encode($filterData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
        </form>
        <p class="muted report-filter-note">This section now shows only department-wise analytics. Student-wise data remains in the report card section.</p>
    </article>

    <article class="data-card department-report-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Department Report</p>
                <h3 class="card-title"><?= e($viewOptions[$reportView]) ?> department summary</h3>
                <p class="muted"><?= e($reportContextText) ?></p>
            </div>
        </div>
        <?php if ($reportRows): ?>
            <div class="table-wrap department-report-wrap">
                <table class="department-report-table">
                    <thead>
                    <?php if ($reportView === 'attendance'): ?>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Faculty</th>
                            <th>Semester</th>
                            <th>Avg Present / Student</th>
                            <th>Avg Absent / Student</th>
                            <th>Avg Sessions / Student</th>
                            <th>Attendance Avg %</th>
                            <th>Overall Marks Avg %</th>
                        </tr>
                    <?php elseif ($reportView === 'assignment'): ?>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Semester</th>
                            <th>Assignment Scope</th>
                            <th>Assignment Sets</th>
                            <th>Avg Submitted / Student</th>
                            <th>Avg Pending / Student</th>
                            <th>Submit Avg %</th>
                            <th>Overall Marks Avg %</th>
                        </tr>
                    <?php elseif ($reportView === 'subject'): ?>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Semester</th>
                            <th>Subject</th>
                            <th>Assessments Recorded</th>
                            <th>Subject Avg</th>
                            <th>Subject Avg %</th>
                            <th>Overall Avg %</th>
                            <th>Result Trend</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Faculty</th>
                            <th>Semester</th>
                            <th>Attendance Avg %</th>
                            <th>Assignment Avg %</th>
                            <th><?= e($selectedSubjectLabel) ?> Avg</th>
                            <th><?= e($selectedSubjectLabel) ?> Avg %</th>
                            <th>Overall Avg %</th>
                            <th>Result Trend</th>
                        </tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach ($reportRows as $row):
                        $department = (array) ($row['department'] ?? []);
                        $attendance = (array) ($row['attendance'] ?? []);
                        $assignment = (array) ($row['assignment'] ?? []);
                        $subject = (array) ($row['subject'] ?? []);
                        $overallMarks = (array) ($row['overall_marks'] ?? []);
                        $semesterLabel = (int) ($row['semester_no'] ?? 0) > 0 ? semester_label((int) $row['semester_no']) : 'All Semesters';
                        ?>
                        <tr>
                            <?php if ($reportView === 'attendance'): ?>
                                <td data-label="Department"><strong><?= e((string) ($department['name'] ?? '-')) ?></strong><span class="department-code"><?= e((string) ($department['short_name'] ?? '')) ?></span></td>
                                <td data-label="Students"><?= e((string) ($row['students_total'] ?? 0)) ?></td>
                                <td data-label="Faculty"><?= e((string) ($row['faculty_total'] ?? 0)) ?></td>
                                <td data-label="Semester"><?= e($semesterLabel) ?></td>
                                <td data-label="Avg Present / Student"><?= e((string) format_marks_value((float) ($attendance['present_average'] ?? 0))) ?></td>
                                <td data-label="Avg Absent / Student"><?= e((string) format_marks_value((float) ($attendance['absent_average'] ?? 0))) ?></td>
                                <td data-label="Avg Sessions / Student"><?= e((string) format_marks_value((float) ($attendance['session_average'] ?? 0))) ?></td>
                                <td data-label="Attendance Avg %"><span class="badge <?= $metricBadgeClass((int) ($attendance['percentage'] ?? 0), 75, 60) ?>"><?= e((string) ($attendance['percentage'] ?? 0)) ?>%</span></td>
                                <td data-label="Overall Marks Avg %"><span class="badge <?= $metricBadgeClass((float) ($overallMarks['average_percentage'] ?? 0), 60, 40) ?>"><?= e((string) ($overallMarks['average_percentage_display'] ?? '--')) ?></span></td>
                            <?php elseif ($reportView === 'assignment'): ?>
                                <td data-label="Department"><strong><?= e((string) ($department['name'] ?? '-')) ?></strong><span class="department-code"><?= e((string) ($department['short_name'] ?? '')) ?></span></td>
                                <td data-label="Students"><?= e((string) ($row['students_total'] ?? 0)) ?></td>
                                <td data-label="Semester"><?= e($semesterLabel) ?></td>
                                <td data-label="Assignment Scope"><?= e((string) ($assignment['label'] ?? 'All Assignments')) ?></td>
                                <td data-label="Assignment Sets"><?= e((string) ($assignment['count'] ?? 0)) ?></td>
                                <td data-label="Avg Submitted / Student"><?= e((string) format_marks_value((float) ($assignment['submitted_average'] ?? 0))) ?></td>
                                <td data-label="Avg Pending / Student"><?= e((string) format_marks_value((float) ($assignment['pending_average'] ?? 0))) ?></td>
                                <td data-label="Submit Avg %"><span class="badge <?= $metricBadgeClass((int) ($assignment['percentage'] ?? 0), 75, 50) ?>"><?= e((string) ($assignment['percentage'] ?? 0)) ?>%</span></td>
                                <td data-label="Overall Marks Avg %"><span class="badge <?= $metricBadgeClass((float) ($overallMarks['average_percentage'] ?? 0), 60, 40) ?>"><?= e((string) ($overallMarks['average_percentage_display'] ?? '--')) ?></span></td>
                            <?php elseif ($reportView === 'subject'): ?>
                                <td data-label="Department"><strong><?= e((string) ($department['name'] ?? '-')) ?></strong><span class="department-code"><?= e((string) ($department['short_name'] ?? '')) ?></span></td>
                                <td data-label="Students"><?= e((string) ($row['students_total'] ?? 0)) ?></td>
                                <td data-label="Semester"><?= e($semesterLabel) ?></td>
                                <td data-label="Subject"><?= e((string) ($subject['name'] ?? 'All Subjects')) ?></td>
                                <td data-label="Assessments Recorded"><?= e((string) ($subject['recorded_count'] ?? 0)) ?></td>
                                <td data-label="Subject Avg"><?= e((string) ($subject['average_display'] ?? '--')) ?></td>
                                <td data-label="Subject Avg %"><span class="badge <?= $metricBadgeClass((float) ($subject['average_percentage'] ?? 0), 60, 40) ?>"><?= e((string) ($subject['average_percentage_display'] ?? '--')) ?></span></td>
                                <td data-label="Overall Avg %"><span class="badge <?= $metricBadgeClass((float) ($overallMarks['average_percentage'] ?? 0), 60, 40) ?>"><?= e((string) ($overallMarks['average_percentage_display'] ?? '--')) ?></span></td>
                                <td data-label="Result Trend"><span class="badge <?= $statusBadgeClass((string) ($overallMarks['result'] ?? 'Pending')) ?>"><?= e((string) ($overallMarks['result'] ?? 'Pending')) ?></span></td>
                            <?php else: ?>
                                <td data-label="Department"><strong><?= e((string) ($department['name'] ?? '-')) ?></strong><span class="department-code"><?= e((string) ($department['short_name'] ?? '')) ?></span></td>
                                <td data-label="Students"><?= e((string) ($row['students_total'] ?? 0)) ?></td>
                                <td data-label="Faculty"><?= e((string) ($row['faculty_total'] ?? 0)) ?></td>
                                <td data-label="Semester"><?= e($semesterLabel) ?></td>
                                <td data-label="Attendance Avg %"><span class="badge <?= $metricBadgeClass((int) ($attendance['percentage'] ?? 0), 75, 60) ?>"><?= e((string) ($attendance['percentage'] ?? 0)) ?>%</span></td>
                                <td data-label="Assignment Avg %"><span class="badge <?= $metricBadgeClass((int) ($assignment['percentage'] ?? 0), 75, 50) ?>"><?= e((string) ($assignment['percentage'] ?? 0)) ?>%</span></td>
                                <td data-label="<?= e($selectedSubjectLabel) ?> Avg"><?= e((string) ($subject['average_display'] ?? '--')) ?></td>
                                <td data-label="<?= e($selectedSubjectLabel) ?> Avg %"><span class="badge <?= $metricBadgeClass((float) ($subject['average_percentage'] ?? 0), 60, 40) ?>"><?= e((string) ($subject['average_percentage_display'] ?? '--')) ?></span></td>
                                <td data-label="Overall Avg %"><span class="badge <?= $metricBadgeClass((float) ($overallMarks['average_percentage'] ?? 0), 60, 40) ?>"><?= e((string) ($overallMarks['average_percentage_display'] ?? '--')) ?></span></td>
                                <td data-label="Result Trend"><span class="badge <?= $statusBadgeClass((string) ($overallMarks['result'] ?? 'Pending')) ?>"><?= e((string) ($overallMarks['result'] ?? 'Pending')) ?></span></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No department-wise report data is available for the selected filters yet.</div>
        <?php endif; ?>
    </article>
    <?php
});
