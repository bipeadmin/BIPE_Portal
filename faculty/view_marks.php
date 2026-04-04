<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$teacherDepartmentId = (int) ($teacher['department_id'] ?? 0);
$departments = departments();
$departmentIds = array_values(array_map(static fn (array $department): int => (int) $department['id'], $departments));
$departmentMap = [];
foreach ($departments as $department) {
    $departmentMap[(int) $department['id']] = $department;
}

$departmentQueryValue = trim((string) ($_GET['department_id'] ?? (string) $teacherDepartmentId));
$allDepartmentsMode = strtolower($departmentQueryValue) === 'all';
if ($allDepartmentsMode) {
    $selectedDepartmentId = 0;
    $departmentQueryValue = 'all';
} else {
    $selectedDepartmentId = (int) $departmentQueryValue;
    if (!in_array($selectedDepartmentId, $departmentIds, true)) {
        $selectedDepartmentId = $teacherDepartmentId;
    }
    $departmentQueryValue = (string) $selectedDepartmentId;
}

$semesterNo = (int) ($_GET['semester_no'] ?? 2);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$markTypeId = (int) ($_GET['mark_type_id'] ?? 0);

$subjectOptionsSql = 'SELECT s.*, d.name AS department_name, d.short_name AS department_short_name
                      FROM subjects s
                      INNER JOIN departments d ON d.id = s.department_id
                      WHERE s.semester_no = :semester_no';
$subjectOptionsParams = ['semester_no' => $semesterNo];
if (!$allDepartmentsMode) {
    $subjectOptionsSql .= ' AND s.department_id = :department_id';
    $subjectOptionsParams['department_id'] = $selectedDepartmentId;
}
$subjectOptionsSql .= $allDepartmentsMode
    ? ' ORDER BY d.name, s.subject_name'
    : ' ORDER BY s.subject_name';
$subjectOptions = query_all($subjectOptionsSql, $subjectOptionsParams);

$validSubjectIds = array_values(array_map(static fn (array $subject): int => (int) $subject['id'], $subjectOptions));
if (!$validSubjectIds) {
    $subjectId = 0;
} elseif (!in_array($subjectId, $validSubjectIds, true)) {
    $subjectId = (int) $subjectOptions[0]['id'];
}

$selectedSubject = null;
foreach ($subjectOptions as $subjectOption) {
    if ((int) $subjectOption['id'] === $subjectId) {
        $selectedSubject = $subjectOption;
        break;
    }
}

$markTypes = mark_type_rows();
$validMarkTypeIds = array_values(array_map(static fn (array $markType): int => (int) $markType['id'], $markTypes));
if (!$validMarkTypeIds) {
    $markTypeId = 0;
} elseif (!in_array($markTypeId, $validMarkTypeIds, true)) {
    $markTypeId = (int) $markTypes[0]['id'];
}

$sheetDepartmentId = $selectedSubject ? (int) $selectedSubject['department_id'] : ($allDepartmentsMode ? 0 : $selectedDepartmentId);
$students = ($subjectId > 0 && $sheetDepartmentId > 0) ? students_for_class($sheetDepartmentId, $semesterNo) : [];
$upload = ($subjectId > 0 && $markTypeId > 0 && $sheetDepartmentId > 0) ? mark_upload_detail_by_type($sheetDepartmentId, $semesterNo, $subjectId, $markTypeId) : null;
$recordsMap = $upload ? mark_records_map_for_upload((int) $upload['id']) : [];

$recordedCount = 0;
$absentCount = 0;
foreach ($students as $student) {
    $entry = $recordsMap[(int) $student['id']] ?? null;
    if ($entry) {
        $recordedCount++;
        if ((int) ($entry['is_absent'] ?? 0) === 1) {
            $absentCount++;
        }
    }
}
$totalStudents = count($students);
$missingCount = max(0, $totalStudents - $recordedCount);
$selectedDepartmentLabel = $selectedSubject['department_name'] ?? ($departmentMap[$selectedDepartmentId]['name'] ?? ($teacher['department_name'] ?? 'Department'));
$sheetSubtitle = $upload
    ? 'Uploaded on ' . (string) $upload['uploaded_at'] . ' with max marks ' . (string) $upload['max_marks'] . '.'
    : 'No saved marks upload exists yet. The current class roster is shown below for review.';

render_dashboard_layout('View Marks', 'teacher', 'view_marks', 'faculty/view_marks.css', 'faculty/view_marks.js', function () use ($departments, $selectedDepartmentId, $departmentQueryValue, $allDepartmentsMode, $semesterNo, $subjectId, $markTypeId, $subjectOptions, $markTypes, $students, $recordsMap, $upload, $selectedDepartmentLabel, $sheetSubtitle, $totalStudents, $recordedCount, $absentCount, $missingCount): void {
    ?>
    <article class="data-card view-marks-card">
        <div class="card-head view-marks-head">
            <div>
                <p class="eyebrow">Marks Viewer</p>
                <h3 class="card-title">Review saved marks by subject and mark type</h3>
                <p class="card-subtitle">Open any department roster, check saved marks, and spot missing entries quickly.</p>
            </div>
        </div>
        <form method="get" class="filters view-marks-filters" style="margin-bottom:14px">
            <div class="form-group">
                <label class="form-label" for="view-marks-department">Department</label>
                <select class="form-select" id="view-marks-department" name="department_id">
                    <option value="all" <?= $allDepartmentsMode ? 'selected' : '' ?>>All Departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $selectedDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="view-marks-semester">Semester</label>
                <select class="form-select" id="view-marks-semester" name="semester_no">
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="view-marks-subject">Subject</label>
                <select class="form-select" id="view-marks-subject" name="subject_id">
                    <option value="0">Select subject</option>
                    <?php foreach ($subjectOptions as $subject):
                        $subjectLabel = $allDepartmentsMode
                            ? trim((string) (($subject['department_short_name'] ?? $subject['department_name'] ?? 'Department') . ' - ' . $subject['subject_name']))
                            : $subject['subject_name'];
                        ?>
                        <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subjectLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="view-marks-type">Mark Type</label>
                <select class="form-select" id="view-marks-type" name="mark_type_id">
                    <?php foreach ($markTypes as $markType): ?>
                        <option value="<?= e((string) $markType['id']) ?>" <?= $markTypeId === (int) $markType['id'] ? 'selected' : '' ?>><?= e($markType['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-primary" type="submit">Load Marks</button>
        </form>

        <?php if ($students): ?>
            <div class="notice-box view-marks-notice"><?= e($sheetSubtitle) ?></div>
            <section class="stats-grid view-marks-summary">
                <article class="stat-card"><p class="eyebrow">Department</p><h3 class="stat-value view-marks-stat-text"><?= e($selectedDepartmentLabel) ?></h3><p class="stat-label"><?= e(semester_label($semesterNo)) ?> roster</p></article>
                <article class="stat-card"><p class="eyebrow">Total Students</p><h3 class="stat-value"><?= e((string) $totalStudents) ?></h3><p class="stat-label">Students in the selected class</p></article>
                <article class="stat-card"><p class="eyebrow">Recorded</p><h3 class="stat-value"><?= e((string) $recordedCount) ?></h3><p class="stat-label">Marks already saved</p></article>
                <article class="stat-card"><p class="eyebrow">Missing</p><h3 class="stat-value"><?= e((string) $missingCount) ?></h3><p class="stat-label">Students without a saved record</p></article>
            </section>

            <div class="table-wrap view-marks-table-wrap">
                <table class="view-marks-table">
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Name</th>
                        <th>Marks</th>
                        <th>Status</th>
                        <th>Grade</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $student):
                        $entry = $recordsMap[(int) $student['id']] ?? null;
                        $isAbsent = $entry && (int) ($entry['is_absent'] ?? 0) === 1;
                        $marks = $entry['marks_obtained'] ?? null;
                        $statusLabel = $isAbsent ? 'Absent' : ($entry ? 'Recorded' : 'Missing');
                        $statusClass = $isAbsent ? 'warning' : ($entry ? 'success' : 'danger');
                        ?>
                        <tr>
                            <td class="mono" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                            <td data-label="Student Name"><?= e($student['full_name']) ?></td>
                            <td data-label="Marks"><?= $isAbsent ? 'AB' : e((string) ($marks ?? '--')) ?></td>
                            <td data-label="Status"><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                            <td data-label="Grade"><?= (!$isAbsent && $marks !== null && $upload) ? '<span class="badge info">' . e(grade_from_marks((float) $marks, (float) $upload['max_marks'])) . '</span>' : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">Choose a department and subject to review the class marks sheet.</div>
        <?php endif; ?>
    </article>
    <?php
});
