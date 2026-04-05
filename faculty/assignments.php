<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$teacherDepartmentId = (int) ($teacher['department_id'] ?? 0);
$departments = departments();
$departmentIds = array_values(array_map(static fn (array $department): int => (int) $department['id'], $departments));

$departmentQueryValue = trim((string) (post('department_filter') ?: ($_GET['department_id'] ?? (string) $teacherDepartmentId)));
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

$semesterNo = (int) (post('semester_no') ?: ($_GET['semester_no'] ?? 2));
$subjectId = (int) (post('subject_id') ?: ($_GET['subject_id'] ?? 0));
$assignmentLabel = trim((string) (post('assignment_label') ?: ($_GET['assignment_label'] ?? 'Assignment-I')));
$dueDate = (string) (post('due_date') ?: ($_GET['due_date'] ?? ''));
$notes = '';

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

$subjectLookup = [];
foreach ($subjectOptions as $subject) {
    $subjectLookup[(int) $subject['id']] = $subject;
}

$validSubjectIds = array_keys($subjectLookup);
if (!$validSubjectIds) {
    $subjectId = 0;
} elseif (!in_array($subjectId, $validSubjectIds, true)) {
    $subjectId = (int) $subjectOptions[0]['id'];
}

$selectedSubject = $subjectLookup[$subjectId] ?? null;
$sheetDepartmentId = $selectedSubject ? (int) $selectedSubject['department_id'] : ($allDepartmentsMode ? 0 : $selectedDepartmentId);
$students = ($subjectId > 0 && $sheetDepartmentId > 0) ? students_for_class($sheetDepartmentId, $semesterNo) : [];

if (is_post() && post('action') === 'save_assignment') {
    $requestDepartmentFilter = trim((string) post('department_filter', $departmentQueryValue));
    $requestAllDepartmentsMode = strtolower($requestDepartmentFilter) === 'all';
    if ($requestAllDepartmentsMode) {
        $requestFilterDepartmentId = 0;
        $requestDepartmentFilter = 'all';
    } else {
        $requestFilterDepartmentId = (int) $requestDepartmentFilter;
        if (!in_array($requestFilterDepartmentId, $departmentIds, true)) {
            $requestFilterDepartmentId = $teacherDepartmentId;
        }
        $requestDepartmentFilter = (string) $requestFilterDepartmentId;
    }

    $requestSemesterNo = (int) post('semester_no', $semesterNo);
    $requestSubjectId = (int) post('subject_id', $subjectId);
    $requestAssignmentLabel = trim((string) post('assignment_label', $assignmentLabel));
    $redirectUrl = 'faculty/assignments.php?department_id=' . urlencode($requestDepartmentFilter) . '&semester_no=' . $requestSemesterNo . '&subject_id=' . $requestSubjectId . '&assignment_label=' . urlencode($requestAssignmentLabel);

    try {
        $subjectRowSql = 'SELECT s.id, s.department_id, s.semester_no, s.subject_name
                          FROM subjects s
                          WHERE s.id = :id AND s.semester_no = :semester_no';
        $subjectRowParams = ['id' => $requestSubjectId, 'semester_no' => $requestSemesterNo];
        if (!$requestAllDepartmentsMode) {
            $subjectRowSql .= ' AND s.department_id = :department_id';
            $subjectRowParams['department_id'] = $requestFilterDepartmentId;
        }
        $subjectRowSql .= ' LIMIT 1';

        $subjectRow = query_one($subjectRowSql, $subjectRowParams);
        if (!$subjectRow) {
            throw new RuntimeException(
                $requestAllDepartmentsMode
                    ? 'Selected subject does not belong to a valid department for this semester.'
                    : 'Selected subject does not belong to the chosen department and semester.'
            );
        }

        $requestDepartmentId = (int) $subjectRow['department_id'];
        $requestStudents = students_for_class($requestDepartmentId, $requestSemesterNo);
        $statuses = [];
        $submitted = (array) post('submitted', []);
        foreach ($requestStudents as $student) {
            $statuses[(int) $student['id']] = isset($submitted[$student['id']]) ? 'submitted' : 'pending';
        }

        save_assignment_sheet((int) $teacher['id'], $requestDepartmentId, $requestSemesterNo, $requestSubjectId, $requestAssignmentLabel, $statuses, (string) post('due_date') ?: null, trim((string) post('notes')) ?: null);
        audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'ASSIGNMENT_TRACKER_SAVED', 'Saved ' . $requestAssignmentLabel . ' tracker for ' . (string) $subjectRow['subject_name'] . ' (' . semester_label($requestSemesterNo) . ')');
        flash('success', 'Assignment submission tracker saved successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to($redirectUrl);
}

$existingAssignment = null;
$submittedMap = [];
if ($selectedSubject && $sheetDepartmentId > 0) {
    $existingAssignment = query_one(
        'SELECT * FROM assignments
         WHERE academic_year_id = :academic_year_id AND department_id = :department_id AND semester_no = :semester_no AND subject_id = :subject_id AND assignment_label = :assignment_label',
        [
            'academic_year_id' => current_academic_year_id(),
            'department_id' => $sheetDepartmentId,
            'semester_no' => $semesterNo,
            'subject_id' => $subjectId,
            'assignment_label' => $assignmentLabel,
        ]
    );
    if ($existingAssignment) {
        foreach (query_all('SELECT * FROM assignment_submissions WHERE assignment_id = :assignment_id', ['assignment_id' => $existingAssignment['id']]) as $submission) {
            $submittedMap[(int) $submission['student_id']] = $submission['submission_status'];
        }
        $dueDate = (string) ($existingAssignment['due_date'] ?? $dueDate);
        $notes = (string) ($existingAssignment['notes'] ?? '');
    }
}

$assignmentRowsSql = 'SELECT a.*, s.subject_name, d.name AS department_name, d.short_name AS department_short_name,
                             SUM(CASE WHEN asb.submission_status = "submitted" THEN 1 ELSE 0 END) AS submitted_count,
                             COUNT(asb.id) AS tracked_count
                      FROM assignments a
                      INNER JOIN subjects s ON s.id = a.subject_id
                      INNER JOIN departments d ON d.id = a.department_id
                      LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id
                      WHERE a.academic_year_id = :academic_year_id AND a.semester_no = :semester_no';
$assignmentRowsParams = ['academic_year_id' => current_academic_year_id(), 'semester_no' => $semesterNo];
if (!$allDepartmentsMode) {
    $assignmentRowsSql .= ' AND a.department_id = :department_id';
    $assignmentRowsParams['department_id'] = $selectedDepartmentId;
}
$assignmentRowsSql .= ' GROUP BY a.id, s.subject_name, d.name, d.short_name ORDER BY COALESCE(a.updated_at, a.created_at) DESC, a.id DESC';
$assignmentRows = query_all($assignmentRowsSql, $assignmentRowsParams);

$totalStudents = count($students);
$submittedCount = 0;
foreach ($students as $student) {
    if (($submittedMap[(int) $student['id']] ?? 'pending') === 'submitted') {
        $submittedCount++;
    }
}
$pendingCount = max(0, $totalStudents - $submittedCount);

render_dashboard_layout('Assignment Tracking', 'teacher', 'assignments', 'faculty/assignments.css', 'faculty/assignments.js', function () use ($departments, $selectedDepartmentId, $departmentQueryValue, $allDepartmentsMode, $semesterNo, $subjectId, $assignmentLabel, $dueDate, $notes, $subjectOptions, $students, $submittedMap, $assignmentRows, $totalStudents, $submittedCount, $pendingCount): void {
    ?>
    <section class="grid-2 faculty-assignments-grid">
        <article class="data-card assignment-entry-card">
            <div class="card-head assignment-head">
                <div>
                    <p class="eyebrow">Submission Matrix</p>
                    <h3 class="card-title">Track submitted and pending students</h3>

                </div>
            </div>
            <form method="get" class="filters assignment-filter-form" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="assignment-department-faculty">Department</label>
                    <select class="form-select" id="assignment-department-faculty" name="department_id">
                        <option value="all" <?= $allDepartmentsMode ? 'selected' : '' ?>>All Departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" <?= $selectedDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="assignment-semester-faculty">Semester</label>
                    <select class="form-select" id="assignment-semester-faculty" name="semester_no">
                        <?php foreach (semester_numbers() as $semester): ?>
                            <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="assignment-subject-faculty">Subject</label>
                    <select class="form-select" id="assignment-subject-faculty" name="subject_id">
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
                    <label class="form-label" for="assignment-label-faculty">Assignment</label>
                    <select class="form-select" id="assignment-label-faculty" name="assignment_label">
                        <?php foreach (assignment_labels() as $label): ?>
                            <option value="<?= e($label) ?>" <?= $assignmentLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-primary" type="submit">Load Tracker</button>
            </form>

            <?php if ($subjectId > 0 && $students): ?>
                <section class="stats-grid assignment-summary-grid">
                    <article class="stat-card"><p class="eyebrow">Total Students</p><h3 class="stat-value"><?= e((string) $totalStudents) ?></h3><p class="stat-label">Roster loaded for this tracker</p></article>
                    <article class="stat-card"><p class="eyebrow">Submitted</p><h3 class="stat-value"><?= e((string) $submittedCount) ?></h3><p class="stat-label">Students marked as submitted</p></article>
                    <article class="stat-card"><p class="eyebrow">Pending</p><h3 class="stat-value"><?= e((string) $pendingCount) ?></h3><p class="stat-label">Students still pending</p></article>
                </section>

                <form method="post" class="stack assignment-entry-form">
                    <input type="hidden" name="action" value="save_assignment">
                    <input type="hidden" name="department_filter" value="<?= e($departmentQueryValue) ?>">
                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                    <input type="hidden" name="assignment_label" value="<?= e($assignmentLabel) ?>">
                    <div class="form-group">
                        <label class="form-label" for="assignment-due-date">Due Date</label>
                        <input class="form-input" id="assignment-due-date" type="date" name="due_date" value="<?= e($dueDate) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="assignment-notes">Notes</label>
                        <textarea class="form-textarea" id="assignment-notes" name="notes" placeholder="Optional assignment note or instructions"><?= e($notes) ?></textarea>
                    </div>
                    <div class="table-wrap assignment-sheet-wrap">
                        <table class="assignment-sheet-table">
                            <thead>
                            <tr>
                                <th>Enrollment</th>
                                <th>Student Name</th>
                                <th>Submitted</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student):
                                $submitted = ($submittedMap[(int) $student['id']] ?? 'pending') === 'submitted';
                                ?>
                                <tr>
                                    <td class="mono" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                                    <td data-label="Student Name"><?= e($student['full_name']) ?></td>
                                    <td class="assignment-checkbox-cell" data-label="Submitted"><input type="checkbox" name="submitted[<?= e((string) $student['id']) ?>]" <?= $submitted ? 'checked' : '' ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn-primary" type="submit">Save Submission Tracker</button>
                </form>
            <?php else: ?>
                <div class="empty-state">Choose a department and subject to manage assignment submissions for the class.</div>
            <?php endif; ?>
        </article>

        <article class="data-card assignment-history-card">
            <div class="card-head assignment-head">
                <div>
                    <p class="eyebrow">Saved Trackers</p>
                    <h3 class="card-title">Existing assignments for <?= e(semester_label($semesterNo)) ?></h3>

                </div>
            </div>
            <?php if ($assignmentRows): ?>
                <div class="table-wrap assignment-history-wrap">
                    <table class="assignment-history-table">
                        <thead>
                        <tr>
                            <th>Department</th>
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
                                <td data-label="Department"><?= e((string) ($row['department_name'] ?? '')) ?></td>
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
                <div class="empty-state">No assignment tracker has been saved for this filter yet.</div>
            <?php endif; ?>
        </article>
    </section>
    <?php
});

