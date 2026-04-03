<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = teacher_by_id((int) current_user()['id']);
$departments = departments();
$departmentLookup = [];
foreach ($departments as $department) {
    $departmentLookup[(int) $department['id']] = $department;
}

$defaultDepartmentId = (int) ($teacher['department_id'] ?? 0);
if (!isset($departmentLookup[$defaultDepartmentId]) && $departments) {
    $defaultDepartmentId = (int) $departments[0]['id'];
}

$departmentFilter = (string) (post('department_id') ?: ($_GET['department_id'] ?? (string) $defaultDepartmentId));
$allDepartmentsMode = strtolower(trim($departmentFilter)) === 'all';
$departmentId = $allDepartmentsMode ? null : (int) $departmentFilter;
if (!$allDepartmentsMode && !isset($departmentLookup[(int) $departmentId])) {
    $departmentId = $defaultDepartmentId;
}

$selectedDepartment = $allDepartmentsMode
    ? ['name' => 'All Students']
    : ($departmentLookup[(int) $departmentId] ?? ['name' => ($teacher['department_name'] ?? 'Department')]);
$departmentQueryValue = $allDepartmentsMode ? 'all' : (string) $departmentId;

$yearLevel = (int) (post('year_level') ?: ($_GET['year_level'] ?? 1));
$semesterNo = (int) (post('semester_no') ?: ($_GET['semester_no'] ?? ($yearLevel * 2)));
$attendanceDate = (string) (post('attendance_date') ?: ($_GET['attendance_date'] ?? date('Y-m-d')));

if (is_post() && post('action') === 'save_attendance') {
    try {
        $statuses = [];
        foreach ((array) post('status', []) as $studentId => $status) {
            $statuses[(int) $studentId] = $status === 'P' ? 'P' : 'A';
        }

        upsert_attendance_scope((int) $teacher['id'], $departmentId, $yearLevel, $semesterNo, $attendanceDate, $statuses, trim((string) post('remarks')) ?: null);
        audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'ATTENDANCE_SAVED', 'Saved attendance for ' . ($selectedDepartment['name'] ?? 'selected scope') . ', semester ' . $semesterNo . ' on ' . $attendanceDate);
        flash('success', 'Attendance saved successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('faculty/attendance.php?department_id=' . urlencode($departmentQueryValue) . '&year_level=' . $yearLevel . '&semester_no=' . $semesterNo . '&attendance_date=' . urlencode($attendanceDate));
}

$students = attendance_scope_student_rows($departmentId, $yearLevel, $semesterNo);
$existing = attendance_scope_detail($departmentId, $yearLevel, $semesterNo, $attendanceDate);
$existingMap = [];
foreach (($existing['records'] ?? []) as $record) {
    $existingMap[(int) $record['student_id']] = $record['status'];
}
$holiday = holiday_event_for_date($allDepartmentsMode ? null : $departmentId, $attendanceDate);
$cardSubtitle = $existing
    ? ($allDepartmentsMode ? 'Editing existing attendance sessions for the selected scope.' : 'Editing an existing attendance session.')
    : 'Create a new attendance session for this class.';
$totalStudents = count($students);
$absentStudents = 0;
foreach ($students as $student) {
    $status = $existingMap[(int) $student['id']] ?? 'P';
    if ($status === 'A') {
        $absentStudents++;
    }
}
$presentStudents = $totalStudents - $absentStudents;

render_dashboard_layout('Mark Attendance', 'teacher', 'attendance', 'faculty/attendance.css', 'faculty/attendance.js', function () use ($departments, $selectedDepartment, $departmentId, $departmentQueryValue, $allDepartmentsMode, $yearLevel, $semesterNo, $attendanceDate, $students, $existingMap, $holiday, $existing, $cardSubtitle, $totalStudents, $presentStudents, $absentStudents): void {
    ?>
    <section class="data-card attendance-filters-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Class Filters</p>
                <h3 class="card-title"><?= e($selectedDepartment['name'] ?? 'Department') ?> attendance sheet</h3>
            </div>
        </div>
        <form method="get" class="filters attendance-filters-form">
            <div class="form-group">
                <label class="form-label" for="department-level">Department</label>
                <select class="form-select" id="department-level" name="department_id">
                    <option value="all" <?= $allDepartmentsMode ? 'selected' : '' ?>>All Students</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= !$allDepartmentsMode && (int) $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="year-level">Year</label>
                <select class="form-select" id="year-level" name="year_level">
                    <option value="1" <?= $yearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                    <option value="2" <?= $yearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3" <?= $yearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="semester-level">Semester</label>
                <select class="form-select" id="semester-level" name="semester_no">
                    <option value="2" <?= $semesterNo === 2 ? 'selected' : '' ?>>Semester 2</option>
                    <option value="4" <?= $semesterNo === 4 ? 'selected' : '' ?>>Semester 4</option>
                    <option value="6" <?= $semesterNo === 6 ? 'selected' : '' ?>>Semester 6</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="attendance-date-picker">Date</label>
                <input class="form-input" id="attendance-date-picker" type="date" name="attendance_date" value="<?= e($attendanceDate) ?>">
            </div>
            <button class="btn-primary" type="submit">Load Class</button>
        </form>
    </section>

    <?php if ($holiday):
        $holidayDays = holiday_event_total_days($holiday);
        ?>
        <div class="notice-box warning">This date falls under <strong><?= e($holiday['event_type']) ?></strong>: <?= e($holiday['title']) ?> (<?= e(holiday_event_date_label($holiday)) ?>, <?= e((string) $holidayDays) ?> day<?= $holidayDays === 1 ? '' : 's' ?>). Attendance can still be saved if you want to override it.</div>
    <?php endif; ?>

    <section class="data-card attendance-sheet-card">
        <div class="card-head attendance-sheet-head">
            <div class="attendance-sheet-copy">
                <p class="eyebrow">Attendance Sheet</p>
                <h3 class="card-title"><?= e($selectedDepartment['name'] ?? 'Department') ?></h3>
                <div class="attendance-sheet-meta" aria-label="Selected class details">
                    <span class="attendance-sheet-chip"><?= e(year_label($yearLevel)) ?></span>
                    <span class="attendance-sheet-chip"><?= e(semester_label($semesterNo)) ?></span>
                    <span class="attendance-sheet-chip"><?= e($attendanceDate) ?></span>
                </div>
                <p class="card-subtitle"><?= e($cardSubtitle) ?></p>
            </div>
            <div class="attendance-summary" aria-live="polite">
                <div class="attendance-summary-item attendance-summary-total">
                    <span class="attendance-summary-label">Total Students</span>
                    <strong class="attendance-summary-value" data-attendance-total><?= e((string) $totalStudents) ?></strong>
                </div>
                <div class="attendance-summary-item attendance-summary-present">
                    <span class="attendance-summary-label">Present</span>
                    <strong class="attendance-summary-value" data-attendance-present><?= e((string) $presentStudents) ?></strong>
                </div>
                <div class="attendance-summary-item attendance-summary-absent">
                    <span class="attendance-summary-label">Absent</span>
                    <strong class="attendance-summary-value" data-attendance-absent><?= e((string) $absentStudents) ?></strong>
                </div>
            </div>
        </div>
        <?php if ($students): ?>
            <form method="post" class="stack attendance-sheet-form">
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="department_id" value="<?= e($departmentQueryValue) ?>">
                <input type="hidden" name="year_level" value="<?= e((string) $yearLevel) ?>">
                <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                <div class="inline-actions attendance-actions">
                    <button class="btn-secondary" type="button" data-mark-all="P">Mark All Present</button>
                    <button class="btn-secondary" type="button" data-mark-all="A">Mark All Absent</button>
                </div>
                <div class="table-wrap attendance-sheet-wrap">
                    <table class="attendance-sheet-table">
                        <thead>
                        <tr>
                            <?php if ($allDepartmentsMode): ?><th>Department</th><?php endif; ?>
                            <th>Enrollment</th>
                            <th>Student Name</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $student):
                            $status = $existingMap[(int) $student['id']] ?? 'P';
                            ?>
                            <tr class="attendance-row">
                                <?php if ($allDepartmentsMode): ?><td class="attendance-department-cell" data-label="Department"><?= e($student['department_name'] ?? '-') ?></td><?php endif; ?>
                                <td class="mono attendance-enrollment-cell" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                                <td class="attendance-name-cell" data-label="Student Name"><?= e($student['full_name']) ?></td>
                                <td class="attendance-status-cell" data-label="Status">
                                    <select class="form-select attendance-select" name="status[<?= e((string) $student['id']) ?>]">
                                        <option value="P" <?= $status === 'P' ? 'selected' : '' ?>>Present</option>
                                        <option value="A" <?= $status === 'A' ? 'selected' : '' ?>>Absent</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-group">
                    <label class="form-label" for="attendance-remarks">Remarks</label>
                    <textarea class="form-textarea" id="attendance-remarks" name="remarks" placeholder="Optional note for this attendance sheet"><?= e((string) ($existing['remarks'] ?? '')) ?></textarea>
                </div>
                <button class="btn-primary" type="submit">Save Attendance</button>
            </form>
        <?php else: ?>
            <div class="empty-state">No students found for the selected filter, year, and semester.</div>
        <?php endif; ?>
    </section>
    <?php
});


