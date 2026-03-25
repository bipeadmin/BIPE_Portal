<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (is_post() && (string) post('action') === 'delete_assignment') {
    try {
        execute_sql('DELETE FROM assignments WHERE id = :id', ['id' => (int) post('assignment_id')]);
        audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'ASSIGNMENT_DELETE', 'Assignment ID ' . (int) post('assignment_id'));
        flash('success', 'Assignment removed successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('admin/assignments.php');
}

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? 0);
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$assignmentLabel = trim((string) ($_GET['assignment_label'] ?? ''));

$subjectOptions = $departmentId > 0
    ? query_all('SELECT * FROM subjects WHERE department_id = :department_id ORDER BY semester_no, subject_name', ['department_id' => $departmentId])
    : [];

$sql = 'SELECT a.*, d.name AS department_name, s.subject_name, t.full_name AS teacher_name,
               SUM(CASE WHEN asb.submission_status = "submitted" THEN 1 ELSE 0 END) AS submitted_count
        FROM assignments a
        INNER JOIN departments d ON d.id = a.department_id
        INNER JOIN subjects s ON s.id = a.subject_id
        INNER JOIN teachers t ON t.id = a.teacher_id
        LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id
        WHERE a.academic_year_id = :academic_year_id';
$params = ['academic_year_id' => current_academic_year_id()];

if ($departmentId > 0) {
    $sql .= ' AND a.department_id = :department_id';
    $params['department_id'] = $departmentId;
}
if ($semesterNo > 0) {
    $sql .= ' AND a.semester_no = :semester_no';
    $params['semester_no'] = $semesterNo;
}
if ($subjectId > 0) {
    $sql .= ' AND a.subject_id = :subject_id';
    $params['subject_id'] = $subjectId;
}
if ($assignmentLabel !== '') {
    $sql .= ' AND a.assignment_label = :assignment_label';
    $params['assignment_label'] = $assignmentLabel;
}

$sql .= ' GROUP BY a.id, d.name, s.subject_name, t.full_name ORDER BY d.name, a.semester_no, s.subject_name';
$rows = query_all($sql, $params);

render_dashboard_layout('Assignment Oversight', 'admin', 'assignments', 'admin/assignments.css', 'admin/assignments.js', function () use ($departments, $departmentId, $semesterNo, $subjectId, $assignmentLabel, $subjectOptions, $rows): void {
    ?>
    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Submission Filters</p>
                <h3 class="card-title">Review assignment completion by class</h3>
            </div>
        </div>
        <form method="get" class="filters">
            <div class="form-group">
                <label class="form-label" for="assignment-department">Department</label>
                <select class="form-select" id="assignment-department" name="department_id">
                    <option value="0">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="assignment-semester">Semester</label>
                <select class="form-select" id="assignment-semester" name="semester_no">
                    <option value="0">All semesters</option>
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="assignment-subject">Subject</label>
                <select class="form-select" id="assignment-subject" name="subject_id">
                    <option value="0">All subjects</option>
                    <?php foreach ($subjectOptions as $subject): ?>
                        <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subject['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="assignment-label">Assignment</label>
                <select class="form-select" id="assignment-label" name="assignment_label">
                    <option value="">All labels</option>
                    <?php foreach (assignment_labels() as $label): ?>
                        <option value="<?= e($label) ?>" <?= $assignmentLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-primary" type="submit">Apply Filters</button>
        </form>
    </article>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Assignment Register</p>
                <h3 class="card-title">Submission status by assignment</h3>
            </div>
        </div>
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Semester</th>
                        <th>Subject</th>
                        <th>Assignment</th>
                        <th>Faculty</th>
                        <th>Submitted</th>
                        <th>Pending</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $totalStudents = (int) query_value(
                            'SELECT COUNT(*) FROM students WHERE department_id = :department_id AND semester_no = :semester_no',
                            ['department_id' => $row['department_id'], 'semester_no' => $row['semester_no']]
                        );
                        $submitted = (int) $row['submitted_count'];
                        $pending = max($totalStudents - $submitted, 0);
                        ?>
                        <tr>
                            <td><?= e($row['department_name']) ?></td>
                            <td><?= e(semester_label((int) $row['semester_no'])) ?></td>
                            <td><?= e($row['subject_name']) ?></td>
                            <td><span class="badge info"><?= e($row['assignment_label']) ?></span></td>
                            <td><?= e($row['teacher_name']) ?></td>
                            <td><?= e((string) $submitted) ?></td>
                            <td><?= e((string) $pending) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_assignment">
                                    <input type="hidden" name="assignment_id" value="<?= e((string) $row['id']) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this assignment and its submission tracker?">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No assignment tracking records match the selected filters.</div>
        <?php endif; ?>
    </article>
    <?php
});


