<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = teacher_by_id((int) current_user()['id']);
$departmentId = (int) ($teacher['department_id'] ?? 0);
$yearLevel = (int) (post('year_level') ?: ($_GET['year_level'] ?? 1));
$semesterNo = (int) (post('semester_no') ?: ($_GET['semester_no'] ?? ($yearLevel * 2)));
$attendanceDate = (string) (post('attendance_date') ?: ($_GET['attendance_date'] ?? date('Y-m-d')));

if (is_post() && post('action') === 'save_attendance') {
    try {
        $statuses = [];
        foreach ((array) post('status', []) as $studentId => $status) {
            $statuses[(int) $studentId] = $status === 'P' ? 'P' : 'A';
        }
        upsert_attendance_sheet((int) $teacher['id'], $departmentId, $yearLevel, $semesterNo, $attendanceDate, $statuses, trim((string) post('remarks')) ?: null);
        audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'ATTENDANCE_SAVED', 'Saved attendance for semester ' . $semesterNo . ' on ' . $attendanceDate);
        flash('success', 'Attendance saved successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('faculty/attendance.php?year_level=' . $yearLevel . '&semester_no=' . $semesterNo . '&attendance_date=' . urlencode($attendanceDate));
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
$holiday = holiday_event_for_date($departmentId, $attendanceDate);

render_dashboard_layout('Mark Attendance', 'teacher', 'attendance', 'faculty/attendance.css', 'faculty/attendance.js', function () use ($teacher, $yearLevel, $semesterNo, $attendanceDate, $students, $existingMap, $holiday, $existing): void {
    ?>
    <section class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Class Filters</p>
                <h3 class="card-title"><?= e($teacher['department_name'] ?? 'Department') ?> attendance sheet</h3>
            </div>
        </div>
        <form method="get" class="filters">
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

    <section class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Attendance Sheet</p>
                <h3 class="card-title"><?= e(year_label($yearLevel)) ?> · <?= e(semester_label($semesterNo)) ?> · <?= e($attendanceDate) ?></h3>
                <p class="card-subtitle"><?= $existing ? 'Editing an existing attendance session.' : 'Create a new attendance session for this class.' ?></p>
            </div>
        </div>
        <?php if ($students): ?>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="year_level" value="<?= e((string) $yearLevel) ?>">
                <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                <div class="inline-actions">
                    <button class="btn-secondary" type="button" data-mark-all="P">Mark All Present</button>
                    <button class="btn-secondary" type="button" data-mark-all="A">Mark All Absent</button>
                </div>
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
                        <?php foreach ($students as $student):
                            $status = $existingMap[(int) $student['id']] ?? 'P';
                            ?>
                            <tr>
                                <td class="mono"><?= e($student['enrollment_no']) ?></td>
                                <td><?= e($student['full_name']) ?></td>
                                <td>
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
            <div class="empty-state">No students found for the selected year and semester.</div>
        <?php endif; ?>
    </section>
    <?php
});