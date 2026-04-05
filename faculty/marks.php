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
$markTypeId = (int) (post('mark_type_id') ?: ($_GET['mark_type_id'] ?? 0));

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

$selectedMarkType = $markTypeId > 0 ? mark_type_by_id($markTypeId) : null;
$selectedMaxMarks = (float) ($selectedMarkType['max_marks'] ?? 0);
$sheetDepartmentId = $selectedSubject ? (int) $selectedSubject['department_id'] : ($allDepartmentsMode ? 0 : $selectedDepartmentId);
$students = ($subjectId > 0 && $sheetDepartmentId > 0) ? students_for_class($sheetDepartmentId, $semesterNo) : [];
$csvRestrictionIssueSessionKey = 'marks_csv_restriction_issues_' . (int) ($teacher['id'] ?? 0);

if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="marks_template.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to generate the CSV template.');
    }

    fputcsv($output, ['Enrollment No', 'Student Name', 'Marks', 'Absent (True/False)']);
    foreach ($students as $student) {
        fputcsv($output, [
            (string) ($student['enrollment_no'] ?? ''),
            (string) ($student['full_name'] ?? ''),
            '',
            '',
        ]);
    }
    fclose($output);
    exit;
}

$csvRestrictionIssues = $_SESSION[$csvRestrictionIssueSessionKey] ?? [];
unset($_SESSION[$csvRestrictionIssueSessionKey]);
if (!is_array($csvRestrictionIssues)) {
    $csvRestrictionIssues = [];
}

if (is_post()) {
    $action = (string) post('action');
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

    $requestDepartmentId = (int) post('department_id', $requestFilterDepartmentId > 0 ? $requestFilterDepartmentId : $sheetDepartmentId);
    $requestSemesterNo = (int) post('semester_no', $semesterNo);
    $requestSubjectId = (int) post('subject_id', $subjectId);
    $requestMarkTypeId = (int) post('mark_type_id', $markTypeId);
    $redirectUrl = 'faculty/marks.php?department_id=' . urlencode($requestDepartmentFilter) . '&semester_no=' . $requestSemesterNo . '&subject_id=' . $requestSubjectId . '&mark_type_id=' . $requestMarkTypeId;

    try {
        if (!$requestAllDepartmentsMode && !in_array($requestFilterDepartmentId, $departmentIds, true)) {
            throw new RuntimeException('Choose a valid department first.');
        }

        if (in_array($action, ['save_manual', 'save_csv'], true)) {
            if ($requestSubjectId <= 0 || $requestMarkTypeId <= 0) {
                throw new RuntimeException('Choose a department, subject, and mark type first.');
            }

            $subjectRowSql = 'SELECT id, department_id FROM subjects WHERE id = :id AND semester_no = :semester_no';
            $subjectRowParams = ['id' => $requestSubjectId, 'semester_no' => $requestSemesterNo];
            if (!$requestAllDepartmentsMode) {
                $subjectRowSql .= ' AND department_id = :department_id';
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
        } elseif ($requestDepartmentId > 0 && !in_array($requestDepartmentId, $departmentIds, true)) {
            throw new RuntimeException('Choose a valid department first.');
        }

        if ($action === 'delete_upload') {
            unset($_SESSION[$csvRestrictionIssueSessionKey]);
            delete_mark_upload((int) post('upload_id'));
            audit_log('teacher', (string) ($teacher['teacher_code'] ?? $teacher['id']), 'MARKS_DELETE', 'Upload ' . (int) post('upload_id'));
            flash('success', 'Marks upload deleted successfully.');
        }

        if ($action === 'save_manual') {
            unset($_SESSION[$csvRestrictionIssueSessionKey]);
            $requestStudents = students_for_class($requestDepartmentId, $requestSemesterNo);
            $requestMarkType = mark_type_by_id($requestMarkTypeId);
            $requestMaxMarks = (float) ($requestMarkType['max_marks'] ?? 0);
            $records = [];
            $absentRows = (array) post('absent', []);
            foreach ($requestStudents as $student) {
                $studentId = (int) $student['id'];
                $value = trim((string) ((array) post('marks', []))[$studentId] ?? '');
                $absent = isset($absentRows[$studentId]);
                if ($value === '' && !$absent) {
                    continue;
                }
                if (!$absent && !is_numeric($value)) {
                    throw new RuntimeException('Marks must be numeric values.');
                }
                if (!$absent && $value !== '') {
                    $numericValue = (float) $value;
                    if ($numericValue < 0 || $numericValue > $requestMaxMarks) {
                        throw new RuntimeException('Marks for ' . $student['full_name'] . ' must be between 0 and ' . $requestMaxMarks . '.');
                    }
                }
                $records[$studentId] = ['marks' => $absent ? null : (float) $value, 'absent' => $absent];
            }
            save_mark_upload_sheet((int) $teacher['id'], $requestDepartmentId, $requestSemesterNo, $requestSubjectId, $requestMarkTypeId, $records);
            flash('success', 'Marks saved successfully.');
        }

        if ($action === 'save_csv') {
            $requestStudents = students_for_class($requestDepartmentId, $requestSemesterNo);
            $requestStudentsByEnrollment = [];
            foreach ($requestStudents as $student) {
                $requestStudentsByEnrollment[strtoupper((string) $student['enrollment_no'])] = [
                    'id' => (int) $student['id'],
                    'enrollment_no' => (string) ($student['enrollment_no'] ?? ''),
                    'full_name' => (string) ($student['full_name'] ?? ''),
                ];
            }

            $requestMarkType = mark_type_by_id($requestMarkTypeId);
            $requestMaxMarks = (float) ($requestMarkType['max_marks'] ?? 0);
            $csvPath = assert_uploaded_file((array) ($_FILES['marks_file'] ?? []), ['csv'], (int) config('uploads.max_csv_bytes', 2097152));
            $csvParseResult = parse_marks_csv_records_with_absent($csvPath, $requestStudentsByEnrollment, $requestMaxMarks);
            $records = (array) ($csvParseResult['records'] ?? []);
            $csvViolations = array_values(array_filter((array) ($csvParseResult['violations'] ?? []), 'is_array'));

            save_mark_upload_sheet((int) $teacher['id'], $requestDepartmentId, $requestSemesterNo, $requestSubjectId, $requestMarkTypeId, $records);

            $_SESSION[$csvRestrictionIssueSessionKey] = $csvViolations;
            $csvViolationCount = count($csvViolations);
            if ($csvViolationCount > 0) {
                flash('success', $csvViolationCount . ' CSV row(s) exceeded the max marks and were saved as 0. Review them below or correct them in manual entry.');
            } else {
                flash('success', 'CSV marks uploaded successfully.');
            }
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to($redirectUrl);
}

$existingUpload = ($subjectId > 0 && $markTypeId > 0 && $sheetDepartmentId > 0)
    ? mark_upload_detail_by_type($sheetDepartmentId, $semesterNo, $subjectId, $markTypeId)
    : null;
$existingRecords = $existingUpload ? mark_records_map_for_upload((int) $existingUpload['id']) : [];

$uploadsSql = 'SELECT mu.*, s.subject_name, t.full_name AS teacher_name, d.name AS department_name, d.short_name AS department_short_name
               FROM mark_uploads mu
               INNER JOIN subjects s ON s.id = mu.subject_id
               INNER JOIN teachers t ON t.id = mu.teacher_id
               INNER JOIN departments d ON d.id = mu.department_id
               WHERE mu.academic_year_id = :academic_year_id AND mu.semester_no = :semester_no';
$uploadsParams = ['academic_year_id' => current_academic_year_id(), 'semester_no' => $semesterNo];
if (!$allDepartmentsMode) {
    $uploadsSql .= ' AND mu.department_id = :department_id';
    $uploadsParams['department_id'] = $selectedDepartmentId;
}
$uploadsSql .= ' ORDER BY mu.uploaded_at DESC';
$uploads = query_all($uploadsSql, $uploadsParams);

$isLocked = $sheetDepartmentId > 0 ? mark_section_locked($sheetDepartmentId, $semesterNo) : false;
$lockMessage = $allDepartmentsMode
    ? 'This semester is locked by the administrator for the selected subject department. You can review marks, but you cannot modify uploads until the section is unlocked.'
    : 'This semester is locked by the administrator for the selected department. You can review marks, but you cannot modify uploads until the section is unlocked.';

$emptyStateMessage = $allDepartmentsMode
    ? 'Choose a subject to open the marks sheet for the selected semester.'
    : 'Choose a department and subject to open the marks sheet for this semester.';
$templateDownloadUrl = url('faculty/marks.php?template=1&department_id=' . urlencode($departmentQueryValue) . '&semester_no=' . $semesterNo . '&subject_id=' . $subjectId . '&mark_type_id=' . $markTypeId);
$templateBaseUrl = url('faculty/marks.php');
$csvIssueCount = count($csvRestrictionIssues);
$csvIssuesSummary = 'These rows crossed the selected maximum of ' . $selectedMaxMarks . '. They were saved as 0. Update them in manual entry to clear this list.';
$csvIssuesResolvedMessage = 'All CSV restriction issues are resolved in manual entry.';
$uploadReportCount = count($uploads);


render_dashboard_layout('Upload Marks', 'teacher', 'marks', 'faculty/marks.css', 'faculty/marks.js', function () use ($departments, $selectedDepartmentId, $departmentQueryValue, $allDepartmentsMode, $semesterNo, $subjectId, $markTypeId, $subjectOptions, $markTypes, $students, $existingRecords, $uploads, $isLocked, $selectedMaxMarks, $sheetDepartmentId, $lockMessage, $emptyStateMessage, $templateDownloadUrl, $templateBaseUrl, $csvRestrictionIssues, $csvIssueCount, $csvIssuesSummary, $csvIssuesResolvedMessage, $uploadReportCount): void {
    ?>
    <?php if ($isLocked): ?>
        <div class="notice-box danger"><?= e($lockMessage) ?></div>
    <?php endif; ?>

    <section class="grid-2 marks-entry-grid">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Manual Entry</p>
                    <h3 class="card-title">Enter or edit marks for a subject</h3>
                </div>
                <a class="btn-secondary" href="<?= e($templateDownloadUrl) ?>" data-template-download data-template-base="<?= e($templateBaseUrl) ?>">Download CSV Template</a>
            </div>
            <form method="get" class="filters marks-filter-form" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="marks-department">Department</label>
                    <select class="form-select" id="marks-department" name="department_id">
                        <option value="all" <?= $allDepartmentsMode ? 'selected' : '' ?>>All Departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" <?= $selectedDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="marks-semester">Semester</label>
                    <select class="form-select" id="marks-semester" name="semester_no">
                        <?php foreach (semester_numbers() as $semester): ?>
                            <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="marks-subject">Subject</label>
                    <select class="form-select" id="marks-subject" name="subject_id">
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
                    <label class="form-label" for="marks-type">Mark Type</label>
                    <select class="form-select" id="marks-type" name="mark_type_id">
                        <?php foreach ($markTypes as $markType): ?>
                            <option value="<?= e((string) $markType['id']) ?>" data-max-marks="<?= e((string) $markType['max_marks']) ?>" <?= $markTypeId === (int) $markType['id'] ? 'selected' : '' ?>><?= e($markType['label']) ?> (/<?= e((string) $markType['max_marks']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-primary" type="submit">Load Sheet</button>
            </form>

            <?php if ($subjectId > 0 && $students): ?>
                <form method="post" class="stack marks-manual-form">
                    <input type="hidden" name="action" value="save_manual">
                    <input type="hidden" name="department_filter" value="<?= e($departmentQueryValue) ?>">
                    <input type="hidden" name="department_id" value="<?= e((string) $sheetDepartmentId) ?>">
                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                    <input type="hidden" name="mark_type_id" value="<?= e((string) $markTypeId) ?>">
                    <div class="table-wrap marks-sheet-wrap">
                        <table class="marks-sheet-table">
                            <thead>
                            <tr>
                                <th>Enrollment</th>
                                <th>Student Name</th>
                                <th>Marks</th>
                                <th>Absent</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student):
                                $entry = $existingRecords[(int) $student['id']] ?? null;
                                $isAbsentRow = $entry && (int) ($entry['is_absent'] ?? 0) === 1;
                                ?>
                                <tr class="marks-row <?= $isAbsentRow ? 'is-absent' : '' ?>" data-locked="<?= $isLocked ? '1' : '0' ?>" data-student-id="<?= e((string) $student['id']) ?>">
                                    <td class="mono" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                                    <td class="marks-name-cell" data-label="Student Name"><?= e($student['full_name']) ?></td>
                                    <td class="marks-value-cell" data-label="Marks">
                                        <div class="marks-input-wrap">
                                            <input class="form-input mono" type="number" inputmode="decimal" step="0.01" min="0" max="<?= e((string) $selectedMaxMarks) ?>" data-max-marks="<?= e((string) $selectedMaxMarks) ?>" name="marks[<?= e((string) $student['id']) ?>]" value="<?= e((string) ($entry['marks_obtained'] ?? '')) ?>" placeholder="0" <?= ($isLocked || $isAbsentRow) ? 'disabled' : '' ?>>
                                        </div>
                                        <span class="marks-absent-flag">Absent</span>
                                        <div class="marks-inline-warning" aria-live="polite"></div>
                                    </td>
                                    <td class="marks-absent-cell" data-label="Absent"><input type="checkbox" name="absent[<?= e((string) $student['id']) ?>]" <?= $isAbsentRow ? 'checked' : '' ?> <?= $isLocked ? 'disabled' : '' ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn-primary" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save Marks</button>
                </form>
            <?php else: ?>
                <div class="empty-state"><?= e($emptyStateMessage) ?></div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">CSV Upload</p>
                    <h3 class="card-title">Import marks from spreadsheet export</h3>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="form-grid marks-csv-form">
                <input type="hidden" name="action" value="save_csv">
                <input type="hidden" name="department_filter" value="<?= e($departmentQueryValue) ?>">
                <input type="hidden" name="department_id" value="<?= e((string) $sheetDepartmentId) ?>">
                <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                <input type="hidden" name="mark_type_id" value="<?= e((string) $markTypeId) ?>">
                <div class="form-group">
                    <label class="form-label" for="marks-file">CSV File</label>
                    <input class="form-input" id="marks-file" type="file" name="marks_file" accept=".csv" data-file-input data-file-target="#marks-file-name" required <?= $isLocked ? 'disabled' : '' ?>>
                    <div class="file-hint" id="marks-file-name">No file selected</div>
                </div>

                <button class="btn-primary" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Upload CSV</button>
            </form>

            <?php if ($csvRestrictionIssues): ?>
                <section class="marks-csv-issues-panel" data-csv-issues>
                    <div class="card-head marks-csv-issues-head">
                        <div>
                            <p class="eyebrow">CSV Review</p>
                            <h4 class="card-title">Rows Reset to 0</h4>
                        </div>
                        <span class="marks-csv-issues-count"><?= e((string) $csvIssueCount) ?> Issue<?= $csvIssueCount === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="notice-box danger marks-csv-issues-note"><?= e($csvIssuesSummary) ?></div>
                    <div class="table-wrap marks-csv-issues-wrap" data-csv-issues-table-wrap>
                        <table class="marks-upload-list-table marks-csv-issues-table">
                            <thead>
                            <tr>
                                <th>Enrollment</th>
                                <th>Student Name</th>
                                <th>Entered Marks</th>
                                <th>Allowed Max</th>
                                <th>Saved As</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($csvRestrictionIssues as $issue): ?>
                                <tr data-csv-issue-row data-student-id="<?= e((string) ($issue['student_id'] ?? 0)) ?>">
                                    <td class="mono" data-label="Enrollment"><?= e((string) ($issue['enrollment_no'] ?? '')) ?></td>
                                    <td data-label="Student Name"><?= e((string) ($issue['full_name'] ?? '')) ?></td>
                                    <td class="mono" data-label="Entered Marks" data-csv-entered-marks><?= e((string) ($issue['entered_marks'] ?? '')) ?></td>
                                    <td class="mono" data-label="Allowed Max"><?= e((string) ($issue['allowed_max'] ?? '')) ?></td>
                                    <td class="mono" data-label="Saved As"><?= e((string) ($issue['saved_marks'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="empty-state marks-csv-issues-empty" data-csv-issues-empty hidden><?= e($csvIssuesResolvedMessage) ?></div>
                </section>
            <?php endif; ?>
        </article>
    </section>

    <article class="data-card marks-upload-report-card">
        <div class="card-head marks-upload-report-head">
            <div>
                <p class="eyebrow">Marks Upload Report</p>
                <h3 class="card-title">Saved mark sheets for <?= e(semester_label($semesterNo)) ?></h3>

            </div>
            <?php if ($uploads): ?>
                <span class="marks-upload-report-count"><?= e((string) $uploadReportCount) ?> Upload<?= $uploadReportCount === 1 ? '' : 's' ?></span>
            <?php endif; ?>
        </div>
        <?php if ($uploads): ?>
            <div class="table-wrap marks-upload-list-wrap">
                <table class="marks-upload-list-table marks-upload-report-table">
                    <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Exam</th>
                        <th>Max Marks</th>
                        <th>Uploaded</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($uploads as $upload):
                        $uploadSubjectLabel = $allDepartmentsMode
                            ? trim((string) (($upload['department_short_name'] ?? $upload['department_name'] ?? 'Department') . ' - ' . $upload['subject_name']))
                            : $upload['subject_name'];
                        $uploadLocked = $allDepartmentsMode
                            ? mark_section_locked((int) $upload['department_id'], (int) $upload['semester_no'])
                            : $isLocked;
                        ?>
                        <tr>
                            <td data-label="Subject"><span class="marks-upload-subject"><?= e($uploadSubjectLabel) ?></span></td>
                            <td data-label="Exam"><span class="badge info marks-upload-exam"><?= e((string) $upload['exam_type']) ?></span></td>
                            <td data-label="Max Marks"><span class="marks-upload-max"><?= e((string) $upload['max_marks']) ?></span></td>
                            <td data-label="Uploaded"><span class="marks-upload-time"><?= e((string) $upload['uploaded_at']) ?></span></td>
                            <td class="marks-upload-action-cell" data-label="Action">
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_upload">
                                    <input type="hidden" name="department_filter" value="<?= e($departmentQueryValue) ?>">
                                    <input type="hidden" name="department_id" value="<?= e((string) $upload['department_id']) ?>">
                                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                                    <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                                    <input type="hidden" name="mark_type_id" value="<?= e((string) $markTypeId) ?>">
                                    <input type="hidden" name="upload_id" value="<?= e((string) $upload['id']) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this marks upload?" <?= $uploadLocked ? 'disabled' : '' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No marks uploads saved for this semester yet.</div>
        <?php endif; ?>
    </article>
    <?php
});

