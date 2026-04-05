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

if (is_post()) {
    $action = (string) post('action');

    try {
        if ($action === 'save_attendance') {
            $statuses = [];
            foreach ((array) post('status', []) as $studentId => $status) {
                $statuses[(int) $studentId] = $status === 'P' ? 'P' : 'A';
            }
            upsert_attendance_scope((int) $teacher['id'], $departmentId, $yearLevel, $semesterNo, $attendanceDate, $statuses, trim((string) post('remarks')) ?: null);
            audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'ATTENDANCE_UPDATED', 'Updated attendance for ' . ($selectedDepartment['name'] ?? 'selected scope') . ', semester ' . $semesterNo . ' on ' . $attendanceDate);
            flash('success', 'Attendance record updated successfully.');
        }

        if ($action === 'delete_holiday') {
            delete_holiday_event((int) post('holiday_id'));
            audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'HOLIDAY_DELETED', 'Deleted holiday record #' . (string) post('holiday_id'));
            flash('success', 'Holiday record removed successfully.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('faculty/records.php?department_id=' . urlencode($departmentQueryValue) . '&year_level=' . $yearLevel . '&semester_no=' . $semesterNo . '&attendance_date=' . urlencode($attendanceDate));
}

$students = attendance_scope_student_rows($departmentId, $yearLevel, $semesterNo);
$existing = attendance_scope_detail($departmentId, $yearLevel, $semesterNo, $attendanceDate);
$existingMap = [];
foreach (($existing['records'] ?? []) as $record) {
    $existingMap[(int) $record['student_id']] = $record['status'];
}
$history = array_values(array_filter(
    attendance_history_rows($departmentId),
    static fn (array $row): bool => (int) $row['year_level'] === $yearLevel && (int) $row['semester_no'] === $semesterNo
));
$holidays = holiday_rows($departmentId);

render_dashboard_layout('Attendance Records', 'teacher', 'records', 'faculty/records.css', 'faculty/records.js', function () use ($departments, $selectedDepartment, $departmentId, $departmentQueryValue, $allDepartmentsMode, $yearLevel, $semesterNo, $attendanceDate, $students, $existing, $existingMap, $history, $holidays): void {
    ?>
    <section class="grid-2 faculty-records-grid">
        <article class="data-card faculty-records-card faculty-records-edit-card">
            <div class="card-head faculty-records-head">
                <div>
                    <p class="eyebrow">Edit Attendance</p>
                    <h3 class="card-title">Update a saved class record</h3>
                </div>
            </div>
            <form method="get" class="filters faculty-records-filters" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="records-department">Department</label>
                    <select class="form-select" id="records-department" name="department_id">
                        <option value="all" <?= $allDepartmentsMode ? 'selected' : '' ?>>All Students</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" <?= !$allDepartmentsMode && (int) $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="records-year">Year</label>
                    <select class="form-select" id="records-year" name="year_level">
                        <option value="1" <?= $yearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                        <option value="2" <?= $yearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                        <option value="3" <?= $yearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="records-semester">Semester</label>
                    <select class="form-select" id="records-semester" name="semester_no">
                        <option value="2" <?= $semesterNo === 2 ? 'selected' : '' ?>>Semester 2</option>
                        <option value="4" <?= $semesterNo === 4 ? 'selected' : '' ?>>Semester 4</option>
                        <option value="6" <?= $semesterNo === 6 ? 'selected' : '' ?>>Semester 6</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="records-date">Date</label>
                    <input class="form-input" id="records-date" type="date" name="attendance_date" value="<?= e($attendanceDate) ?>">
                </div>
                <button class="btn-primary" type="submit">Load</button>
            </form>

            <?php if ($existing && $students): ?>
                <form method="post" class="stack faculty-records-save-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_attendance">
                    <input type="hidden" name="department_id" value="<?= e($departmentQueryValue) ?>">
                    <input type="hidden" name="year_level" value="<?= e((string) $yearLevel) ?>">
                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                    <div class="table-wrap faculty-records-table-wrap faculty-records-edit-wrap">
                        <table class="faculty-records-table faculty-records-edit-table">
                            <thead>
                            <tr>
                                <?php if ($allDepartmentsMode): ?><th>Department</th><?php endif; ?>
                                <th>Enrollment</th>
                                <th>Student Name</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <?php if ($allDepartmentsMode): ?><td data-label="Department"><?= e($student['department_name'] ?? '-') ?></td><?php endif; ?>
                                    <td class="mono" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                                    <td data-label="Student Name"><?= e($student['full_name']) ?></td>
                                    <td class="faculty-records-status-cell" data-label="Status">
                                        <select class="form-select" name="status[<?= e((string) $student['id']) ?>]">
                                            <option value="P" <?= ($existingMap[(int) $student['id']] ?? 'P') === 'P' ? 'selected' : '' ?>>Present</option>
                                            <option value="A" <?= ($existingMap[(int) $student['id']] ?? 'P') === 'A' ? 'selected' : '' ?>>Absent</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="records-remarks">Remarks</label>
                        <textarea class="form-textarea" id="records-remarks" name="remarks"><?= e((string) ($existing['remarks'] ?? '')) ?></textarea>
                    </div>
                    <button class="btn-primary" type="submit">Update Attendance</button>
                </form>
            <?php else: ?>
                <div class="empty-state">No attendance session exists for the selected filter, class, and date.</div>
            <?php endif; ?>
        </article>

        <article class="data-card faculty-records-card faculty-records-history-card">
            <div class="card-head faculty-records-head">
                <div>
                    <p class="eyebrow">History</p>
                    <h3 class="card-title"><?= e($selectedDepartment['name'] ?? 'Department') ?> attendance log</h3>
                </div>
            </div>
            <?php if ($history): ?>
                <div class="table-wrap faculty-records-table-wrap faculty-records-history-wrap">
                    <table class="faculty-records-table faculty-records-history-table">
                        <thead>
                        <tr>
                            <?php if ($allDepartmentsMode): ?><th>Department</th><?php endif; ?>
                            <th>Date</th>
                            <th>Teacher</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <?php if ($allDepartmentsMode): ?><td data-label="Department"><?= e($row['department_name'] ?? '-') ?></td><?php endif; ?>
                                <td data-label="Date"><?= e($row['attendance_date']) ?></td>
                                <td data-label="Teacher"><?= e($row['teacher_name']) ?></td>
                                <td data-label="Present"><?= e((string) $row['present_count']) ?></td>
                                <td data-label="Absent"><?= e((string) $row['absent_count']) ?></td>
                                <td data-label="Total"><?= e((string) $row['total_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No saved attendance history yet for this selected filter.</div>
            <?php endif; ?>
        </article>
    </section>

    <article class="data-card faculty-records-card faculty-records-holiday-card">
        <div class="card-head faculty-records-head">
            <div>
                <p class="eyebrow">Department Holidays</p>
                <h3 class="card-title">Holiday and event records affecting the selected filter</h3>
            </div>
        </div>
        <?php if ($holidays): ?>
            <div class="table-wrap faculty-records-table-wrap faculty-records-holiday-wrap">
                <table class="faculty-records-table faculty-records-holiday-table">
                    <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Scope</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($holidays as $holiday): ?>
                        <tr>
                            <td data-label="From"><?= e($holiday['event_date']) ?></td>
                            <td data-label="To"><?= e((string) ($holiday['end_date'] ?? '-')) ?></td>
                            <td data-label="Days"><?= e((string) holiday_event_total_days($holiday)) ?></td>
                            <td data-label="Type"><span class="badge warning"><?= e($holiday['event_type']) ?></span></td>
                            <td data-label="Title"><?= e($holiday['title']) ?></td>
                            <td data-label="Scope"><?= e($holiday['scope_type'] === 'all' ? 'All departments' : ($holiday['department_name'] ?? '-')) ?></td>
                            <td class="faculty-records-action-cell" data-label="Action">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= e((string) $holiday['id']) ?>">
                                    <input type="hidden" name="department_id" value="<?= e($departmentQueryValue) ?>">
                                    <input type="hidden" name="year_level" value="<?= e((string) $yearLevel) ?>">
                                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                                    <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this holiday record?">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No holiday records affect the selected filter right now.</div>
        <?php endif; ?>
    </article>
    <?php
});
