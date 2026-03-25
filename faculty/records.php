<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = teacher_by_id((int) current_user()['id']);
$departmentId = (int) ($teacher['department_id'] ?? 0);
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
            upsert_attendance_sheet((int) $teacher['id'], $departmentId, $yearLevel, $semesterNo, $attendanceDate, $statuses, trim((string) post('remarks')) ?: null);
            audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'ATTENDANCE_UPDATED', 'Updated attendance for semester ' . $semesterNo . ' on ' . $attendanceDate);
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

    redirect_to('faculty/records.php?year_level=' . $yearLevel . '&semester_no=' . $semesterNo . '&attendance_date=' . urlencode($attendanceDate));
}

$students = query_all(
    'SELECT * FROM students WHERE department_id = :department_id AND year_level = :year_level AND semester_no = :semester_no ORDER BY full_name',
    ['department_id' => $departmentId, 'year_level' => $yearLevel, 'semester_no' => $semesterNo]
);
$existing = attendance_session_detail($departmentId, $yearLevel, $semesterNo, $attendanceDate);
$existingMap = [];
foreach (($existing['records'] ?? []) as $record) {
    $existingMap[(int) $record['student_id']] = $record['status'];
}
$history = array_values(array_filter(
    attendance_history_for_department($departmentId),
    static fn (array $row): bool => (int) $row['year_level'] === $yearLevel && (int) $row['semester_no'] === $semesterNo
));
$holidays = holiday_rows($departmentId);

render_dashboard_layout('Attendance Records', 'teacher', 'records', 'faculty/records.css', 'faculty/records.js', function () use ($teacher, $yearLevel, $semesterNo, $attendanceDate, $students, $existing, $existingMap, $history, $holidays): void {
    ?>
    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Edit Attendance</p>
                    <h3 class="card-title">Update a saved class record</h3>
                </div>
            </div>
            <form method="get" class="filters" style="margin-bottom:14px">
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
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="save_attendance">
                    <input type="hidden" name="year_level" value="<?= e((string) $yearLevel) ?>">
                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Enrollment</th>
                                <th>Student Name</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td class="mono"><?= e($student['enrollment_no']) ?></td>
                                    <td><?= e($student['full_name']) ?></td>
                                    <td>
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
                <div class="empty-state">No attendance session exists for the selected class and date.</div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">History</p>
                    <h3 class="card-title"><?= e($teacher['department_name'] ?? 'Department') ?> attendance log</h3>
                </div>
            </div>
            <?php if ($history): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
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
                                <td><?= e($row['attendance_date']) ?></td>
                                <td><?= e($row['teacher_name']) ?></td>
                                <td><?= e((string) $row['present_count']) ?></td>
                                <td><?= e((string) $row['absent_count']) ?></td>
                                <td><?= e((string) $row['total_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No saved attendance history yet for this class.</div>
            <?php endif; ?>
        </article>
    </section>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Department Holidays</p>
                <h3 class="card-title">Holiday and event records affecting your department</h3>
            </div>
        </div>
        <?php if ($holidays): ?>
            <div class="table-wrap">
                <table>
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
                            <td><?= e($holiday['event_date']) ?></td>
                            <td><?= e((string) ($holiday['end_date'] ?? '-')) ?></td>
                            <td><?= e((string) holiday_event_total_days($holiday)) ?></td>
                            <td><span class="badge warning"><?= e($holiday['event_type']) ?></span></td>
                            <td><?= e($holiday['title']) ?></td>
                            <td><?= e($holiday['scope_type'] === 'all' ? 'All departments' : ($holiday['department_name'] ?? '-')) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= e((string) $holiday['id']) ?>">
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
            <div class="empty-state">No holiday records affect this department right now.</div>
        <?php endif; ?>
    </article>
    <?php
});