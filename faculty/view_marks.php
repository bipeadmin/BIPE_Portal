<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$departmentId = (int) $teacher['department_id'];
$semesterNo = (int) ($_GET['semester_no'] ?? 2);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$markTypeId = (int) ($_GET['mark_type_id'] ?? 0);
$subjectOptions = subjects_for($departmentId, $semesterNo);
$markTypes = mark_type_rows();
if ($subjectId === 0 && $subjectOptions) {
    $subjectId = (int) $subjectOptions[0]['id'];
}
if ($markTypeId === 0 && $markTypes) {
    $markTypeId = (int) $markTypes[0]['id'];
}
$students = students_for_class($departmentId, $semesterNo);
$upload = ($subjectId > 0 && $markTypeId > 0) ? mark_upload_detail_by_type($departmentId, $semesterNo, $subjectId, $markTypeId) : null;
$recordsMap = $upload ? mark_records_map_for_upload((int) $upload['id']) : [];

render_dashboard_layout('View Marks', 'teacher', 'view_marks', 'faculty/view_marks.css', 'faculty/view_marks.js', function () use ($semesterNo, $subjectId, $markTypeId, $subjectOptions, $markTypes, $students, $recordsMap, $upload): void {
    ?>
    <article class="data-card">
        <div class="card-head"><div><p class="eyebrow">Marks Viewer</p><h3 class="card-title">Review saved marks by subject and mark type</h3></div></div>
        <form method="get" class="filters" style="margin-bottom:14px">
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
                    <?php foreach ($subjectOptions as $subject): ?>
                        <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subject['subject_name']) ?></option>
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

        <?php if ($upload && $students): ?>
            <div class="notice-box" style="margin-bottom:14px">Uploaded on <?= e((string) $upload['uploaded_at']) ?> · Max marks <?= e((string) $upload['max_marks']) ?></div>
            <div class="table-wrap">
                <table>
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
                        ?>
                        <tr>
                            <td class="mono"><?= e($student['enrollment_no']) ?></td>
                            <td><?= e($student['full_name']) ?></td>
                            <td><?= $isAbsent ? 'AB' : e((string) ($marks ?? '--')) ?></td>
                            <td><span class="badge <?= $isAbsent ? 'warning' : ($entry ? 'success' : 'danger') ?>"><?= $isAbsent ? 'Absent' : ($entry ? 'Recorded' : 'Missing') ?></span></td>
                            <td><?= (!$isAbsent && $marks !== null) ? '<span class="badge info">' . e(grade_from_marks((float) $marks, (float) $upload['max_marks'])) . '</span>' : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No saved marks upload exists for the selected subject and mark type yet.</div>
        <?php endif; ?>
    </article>
    <?php
});


