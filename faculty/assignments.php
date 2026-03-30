<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = teacher_by_id((int) current_user()['id']);
$departmentId = (int) ($teacher['department_id'] ?? 0);
$semesterNo = (int) (post('semester_no') ?: ($_GET['semester_no'] ?? 2));
$subjectId = (int) (post('subject_id') ?: ($_GET['subject_id'] ?? 0));
$assignmentLabel = trim((string) (post('assignment_label') ?: ($_GET['assignment_label'] ?? 'Assignment-I')));
$dueDate = (string) (post('due_date') ?: ($_GET['due_date'] ?? ''));

$subjectOptions = query_all(
    'SELECT * FROM subjects WHERE department_id = :department_id ORDER BY semester_no, subject_name',
    ['department_id' => $departmentId]
);
$subjectLookup = [];
foreach ($subjectOptions as $subject) {
    $subjectLookup[(int) $subject['id']] = $subject;
}

if ($subjectId > 0 && isset($subjectLookup[$subjectId])) {
    $semesterNo = (int) $subjectLookup[$subjectId]['semester_no'];
}

$students = query_all(
    'SELECT * FROM students WHERE department_id = :department_id AND semester_no = :semester_no ORDER BY full_name',
    ['department_id' => $departmentId, 'semester_no' => $semesterNo]
);

if (is_post() && post('action') === 'save_assignment') {
    try {
        $postedSubjectId = (int) post('subject_id');
        $selectedSubject = $subjectLookup[$postedSubjectId] ?? null;
        if (!$selectedSubject) {
            throw new RuntimeException('Choose a valid subject first.');
        }

        $semesterNo = (int) $selectedSubject['semester_no'];
        $students = query_all(
            'SELECT * FROM students WHERE department_id = :department_id AND semester_no = :semester_no ORDER BY full_name',
            ['department_id' => $departmentId, 'semester_no' => $semesterNo]
        );

        $statuses = [];
        $submitted = (array) post('submitted', []);
        foreach ($students as $student) {
            $statuses[(int) $student['id']] = isset($submitted[$student['id']]) ? 'submitted' : 'pending';
        }

        save_assignment_sheet((int) $teacher['id'], $departmentId, $semesterNo, $postedSubjectId, (string) post('assignment_label'), $statuses, (string) post('due_date') ?: null, trim((string) post('notes')) ?: null);
        audit_log('teacher', (string) ($teacher['teacher_code'] ?? ('teacher#' . $teacher['id'])), 'ASSIGNMENT_TRACKER_SAVED', 'Saved ' . (string) post('assignment_label') . ' tracker for ' . $selectedSubject['subject_name'] . ' (' . semester_label($semesterNo) . ')');
        flash('success', 'Assignment submission tracker saved successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('faculty/assignments.php?semester_no=' . $semesterNo . '&subject_id=' . $subjectId . '&assignment_label=' . urlencode($assignmentLabel));
}

$existingAssignment = null;
$submittedMap = [];
$selectedSubject = $subjectLookup[$subjectId] ?? null;
if ($selectedSubject) {
    $existingAssignment = query_one(
        'SELECT * FROM assignments WHERE academic_year_id = :academic_year_id AND department_id = :department_id AND semester_no = :semester_no AND subject_id = :subject_id AND assignment_label = :assignment_label',
        ['academic_year_id' => current_academic_year_id(), 'department_id' => $departmentId, 'semester_no' => $semesterNo, 'subject_id' => $subjectId, 'assignment_label' => $assignmentLabel]
    );
    if ($existingAssignment) {
        foreach (query_all('SELECT * FROM assignment_submissions WHERE assignment_id = :assignment_id', ['assignment_id' => $existingAssignment['id']]) as $submission) {
            $submittedMap[(int) $submission['student_id']] = $submission['submission_status'];
        }
        $dueDate = (string) ($existingAssignment['due_date'] ?? $dueDate);
    }
}

$assignmentRows = array_values(array_filter(
    assignment_rows_for_department($departmentId),
    static fn (array $row): bool => (int) $row['semester_no'] === $semesterNo
));

render_dashboard_layout('Assignment Tracking', 'teacher', 'assignments', 'faculty/assignments.css', 'faculty/assignments.js', function () use ($semesterNo, $subjectId, $assignmentLabel, $dueDate, $subjectOptions, $students, $submittedMap, $assignmentRows): void {
    ?>
    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Submission Matrix</p>
                    <h3 class="card-title">Track submitted and pending students</h3>
                </div>
            </div>
            <form method="get" class="filters" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="assignment-semester-faculty">Semester</label>
                    <select class="form-select" id="assignment-semester-faculty" name="semester_no">
                        <option value="2" <?= $semesterNo === 2 ? 'selected' : '' ?>>Semester 2</option>
                        <option value="4" <?= $semesterNo === 4 ? 'selected' : '' ?>>Semester 4</option>
                        <option value="6" <?= $semesterNo === 6 ? 'selected' : '' ?>>Semester 6</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="assignment-subject-faculty">Subject</label>
                    <select class="form-select" id="assignment-subject-faculty" name="subject_id">
                        <option value="0">Select subject</option>
                        <?php foreach ($subjectOptions as $subject): ?>
                            <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subject['subject_name']) ?> (<?= e(semester_label((int) $subject['semester_no'])) ?>)</option>
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
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="save_assignment">
                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                    <input type="hidden" name="assignment_label" value="<?= e($assignmentLabel) ?>">
                    <div class="form-group">
                        <label class="form-label" for="assignment-due-date">Due Date</label>
                        <input class="form-input" id="assignment-due-date" type="date" name="due_date" value="<?= e($dueDate) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="assignment-notes">Notes</label>
                        <textarea class="form-textarea" id="assignment-notes" name="notes" placeholder="Optional assignment note or instructions"></textarea>
                    </div>
                    <div class="table-wrap">
                        <table>
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
                                    <td class="mono"><?= e($student['enrollment_no']) ?></td>
                                    <td><?= e($student['full_name']) ?></td>
                                    <td><input type="checkbox" name="submitted[<?= e((string) $student['id']) ?>]" <?= $submitted ? 'checked' : '' ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn-primary" type="submit">Save Submission Tracker</button>
                </form>
            <?php else: ?>
                <div class="empty-state">Select a subject to manage assignment submissions for the class.</div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Saved Trackers</p>
                    <h3 class="card-title">Existing assignments for Semester <?= e((string) $semesterNo) ?></h3>
                </div>
            </div>
            <?php if ($assignmentRows): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Assignment</th>
                            <th>Submitted</th>
                            <th>Tracked</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignmentRows as $row): ?>
                            <tr>
                                <td><?= e($row['subject_name']) ?></td>
                                <td><span class="badge info"><?= e($row['assignment_label']) ?></span></td>
                                <td><?= e((string) $row['submitted_count']) ?></td>
                                <td><?= e((string) $row['tracked_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No assignment tracker has been saved for this semester yet.</div>
            <?php endif; ?>
        </article>
    </section>
    <?php
});