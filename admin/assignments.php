<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$redirectQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
$redirectUrl = 'admin/assignments.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

if (is_post() && (string) post('action') === 'delete_assignment') {
    try {
        execute_sql('DELETE FROM assignments WHERE id = :id', ['id' => (int) post('assignment_id')]);
        audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'ASSIGNMENT_DELETE', 'Assignment ID ' . (int) post('assignment_id'));
        flash('success', 'Assignment removed successfully.');
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to($redirectUrl);
}

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? 0);
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$assignmentLabel = trim((string) ($_GET['assignment_label'] ?? ''));
$submissionStatus = trim((string) ($_GET['submission_status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

if (!in_array($submissionStatus, ['', 'submitted', 'pending'], true)) {
    $submissionStatus = '';
}

$subjectOptionsSql = 'SELECT s.*, d.short_name AS department_short_name
                      FROM subjects s
                      INNER JOIN departments d ON d.id = s.department_id
                      WHERE 1 = 1';
$subjectOptionsParams = [];
if ($departmentId > 0) {
    $subjectOptionsSql .= ' AND s.department_id = :department_id';
    $subjectOptionsParams['department_id'] = $departmentId;
}
if ($semesterNo > 0) {
    $subjectOptionsSql .= ' AND s.semester_no = :semester_no';
    $subjectOptionsParams['semester_no'] = $semesterNo;
}
$subjectOptionsSql .= $departmentId > 0
    ? ' ORDER BY s.subject_name'
    : ' ORDER BY d.name, s.semester_no, s.subject_name';
$subjectOptions = query_all($subjectOptionsSql, $subjectOptionsParams);
$validSubjectIds = array_values(array_map(static fn (array $subject): int => (int) $subject['id'], $subjectOptions));
if ($subjectId > 0 && !in_array($subjectId, $validSubjectIds, true)) {
    $subjectId = 0;
}

$summarySql = 'SELECT a.*, d.name AS department_name, s.subject_name, t.full_name AS teacher_name,
                      COALESCE(sc.total_students, 0) AS total_students,
                      SUM(CASE WHEN asb.submission_status = "submitted" THEN 1 ELSE 0 END) AS submitted_count
               FROM assignments a
               INNER JOIN departments d ON d.id = a.department_id
               INNER JOIN subjects s ON s.id = a.subject_id
               INNER JOIN teachers t ON t.id = a.teacher_id
               LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id
               LEFT JOIN (
                    SELECT department_id, semester_no, COUNT(*) AS total_students
                    FROM students
                    GROUP BY department_id, semester_no
               ) sc ON sc.department_id = a.department_id AND sc.semester_no = a.semester_no
               WHERE a.academic_year_id = :academic_year_id';
$summaryParams = ['academic_year_id' => current_academic_year_id()];

if ($departmentId > 0) {
    $summarySql .= ' AND a.department_id = :department_id';
    $summaryParams['department_id'] = $departmentId;
}
if ($semesterNo > 0) {
    $summarySql .= ' AND a.semester_no = :semester_no';
    $summaryParams['semester_no'] = $semesterNo;
}
if ($subjectId > 0) {
    $summarySql .= ' AND a.subject_id = :subject_id';
    $summaryParams['subject_id'] = $subjectId;
}
if ($assignmentLabel !== '') {
    $summarySql .= ' AND a.assignment_label = :assignment_label';
    $summaryParams['assignment_label'] = $assignmentLabel;
}

$summarySql .= ' GROUP BY a.id, d.name, s.subject_name, t.full_name, sc.total_students
                 ORDER BY d.name, a.semester_no, s.subject_name, a.assignment_label';
$assignmentRows = query_all($summarySql, $summaryParams);

$detailSql = 'SELECT a.id AS assignment_id,
                     a.assignment_label,
                     a.due_date,
                     a.notes,
                     a.semester_no,
                     d.name AS department_name,
                     s.subject_name,
                     t.full_name AS teacher_name,
                     st.id AS student_id,
                     st.full_name AS student_name,
                     st.enrollment_no,
                     st.year_level,
                     st.email,
                     COALESCE(asb.submission_status, "pending") AS submission_status,
                     asb.submitted_at
              FROM assignments a
              INNER JOIN departments d ON d.id = a.department_id
              INNER JOIN subjects s ON s.id = a.subject_id
              INNER JOIN teachers t ON t.id = a.teacher_id
              INNER JOIN students st ON st.department_id = a.department_id AND st.semester_no = a.semester_no
              LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id AND asb.student_id = st.id
              WHERE a.academic_year_id = :academic_year_id';
$detailParams = ['academic_year_id' => current_academic_year_id()];

if ($departmentId > 0) {
    $detailSql .= ' AND a.department_id = :department_id';
    $detailParams['department_id'] = $departmentId;
}
if ($semesterNo > 0) {
    $detailSql .= ' AND a.semester_no = :semester_no';
    $detailParams['semester_no'] = $semesterNo;
}
if ($subjectId > 0) {
    $detailSql .= ' AND a.subject_id = :subject_id';
    $detailParams['subject_id'] = $subjectId;
}
if ($assignmentLabel !== '') {
    $detailSql .= ' AND a.assignment_label = :assignment_label';
    $detailParams['assignment_label'] = $assignmentLabel;
}
if ($submissionStatus !== '') {
    $detailSql .= ' AND COALESCE(asb.submission_status, "pending") = :submission_status';
    $detailParams['submission_status'] = $submissionStatus;
}
if ($search !== '') {
    $detailSql .= ' AND (st.full_name LIKE :search OR st.enrollment_no LIKE :search OR COALESCE(st.email, "") LIKE :search)';
    $detailParams['search'] = '%' . $search . '%';
}

$detailSql .= ' ORDER BY d.name, a.semester_no, s.subject_name, a.assignment_label, st.full_name';
$submissionRows = query_all($detailSql, $detailParams);

$submittedTotal = 0;
foreach ($submissionRows as $row) {
    if (($row['submission_status'] ?? 'pending') === 'submitted') {
        $submittedTotal++;
    }
}
$pendingTotal = max(count($submissionRows) - $submittedTotal, 0);

render_dashboard_layout('Assignment Oversight', 'admin', 'assignments', 'admin/assignments.css', 'admin/assignments.js', function () use ($departments, $departmentId, $semesterNo, $subjectId, $assignmentLabel, $submissionStatus, $search, $subjectOptions, $assignmentRows, $submissionRows, $submittedTotal, $pendingTotal): void {
    ?>
    <article class="data-card assignment-filters-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Submission Filters</p>
                <h3 class="card-title">Review assignment completion student-wise</h3>
            </div>
        </div>
        <form method="get" class="filters assignment-filters">
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
                    <?php foreach ($subjectOptions as $subject):
                        $subjectLabel = $departmentId > 0
                            ? $subject['subject_name']
                            : ($subject['department_short_name'] . ' - ' . $subject['subject_name'] . ' (' . semester_label((int) $subject['semester_no']) . ')');
                        ?>
                        <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subjectLabel) ?></option>
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
            <div class="form-group">
                <label class="form-label" for="submission-status">Submission Status</label>
                <select class="form-select" id="submission-status" name="submission_status">
                    <option value="">All students</option>
                    <option value="submitted" <?= $submissionStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="pending" <?= $submissionStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="assignment-search">Student Search</label>
                <input class="search-input" id="assignment-search" name="search" value="<?= e($search) ?>" placeholder="Name, enrollment, or email">
            </div>
            <button class="btn-primary" type="submit">Apply Filters</button>
        </form>
    </article>

    <section class="stats-grid">
        <article class="stat-card">
            <p class="eyebrow">Student Rows</p>
            <h3 class="stat-value"><?= e((string) count($submissionRows)) ?></h3>
            <p class="stat-label">Assignment-student records in the current filter</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Submitted</p>
            <h3 class="stat-value"><?= e((string) $submittedTotal) ?></h3>
            <p class="stat-label">Students who submitted the selected assignments</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Pending</p>
            <h3 class="stat-value"><?= e((string) $pendingTotal) ?></h3>
            <p class="stat-label">Students still pending submission</p>
        </article>
    </section>

    <article class="data-card assignment-summary-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Assignment Register</p>
                <h3 class="card-title">Assignment-level summary</h3>
            </div>
        </div>
        <?php if ($assignmentRows): ?>
            <div class="table-wrap assignment-summary-wrap">
                <table class="assignment-summary-table">
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
                    <?php foreach ($assignmentRows as $row):
                        $submitted = (int) $row['submitted_count'];
                        $pending = max((int) $row['total_students'] - $submitted, 0);
                        ?>
                        <tr>
                            <td data-label="Department"><?= e($row['department_name']) ?></td>
                            <td data-label="Semester"><?= e(semester_label((int) $row['semester_no'])) ?></td>
                            <td data-label="Subject"><?= e($row['subject_name']) ?></td>
                            <td data-label="Assignment"><span class="badge info"><?= e($row['assignment_label']) ?></span></td>
                            <td data-label="Faculty"><?= e($row['teacher_name']) ?></td>
                            <td data-label="Submitted"><?= e((string) $submitted) ?></td>
                            <td data-label="Pending"><?= e((string) $pending) ?></td>
                            <td data-label="Action" class="action-cell">
                                <form method="post">
                                    <?= csrf_field() ?>
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
            <div class="empty-state">No assignment tracking records match the selected class filters.</div>
        <?php endif; ?>
    </article>

    <article class="data-card assignment-student-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Student Submission Register</p>
                <h3 class="card-title">All students with assignment submission status</h3>
            </div>
        </div>
        <?php if ($submissionRows): ?>
            <div class="table-wrap assignment-student-wrap">
                <table class="assignment-student-table">
                    <colgroup>
                        <col class="assignment-student-col-department">
                        <col class="assignment-student-col-semester">
                        <col class="assignment-student-col-subject">
                        <col class="assignment-student-col-assignment">
                        <col class="assignment-student-col-student">
                        <col class="assignment-student-col-enrollment">
                        <col class="assignment-student-col-year">
                        <col class="assignment-student-col-email">
                        <col class="assignment-student-col-faculty">
                        <col class="assignment-student-col-status">
                        <col class="assignment-student-col-submitted-at">
                    </colgroup>
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Semester</th>
                        <th>Subject</th>
                        <th>Assignment</th>
                        <th>Student</th>
                        <th>Enrollment</th>
                        <th>Year</th>
                        <th>Email</th>
                        <th>Faculty</th>
                        <th>Status</th>
                        <th>Submitted At</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($submissionRows as $row):
                        $isSubmitted = ($row['submission_status'] ?? 'pending') === 'submitted';
                        ?>
                        <tr>
                            <td data-label="Department"><?= e($row['department_name']) ?></td>
                            <td data-label="Semester"><?= e(semester_label((int) $row['semester_no'])) ?></td>
                            <td data-label="Subject"><?= e($row['subject_name']) ?></td>
                            <td data-label="Assignment"><span class="badge info"><?= e($row['assignment_label']) ?></span></td>
                            <td data-label="Student"><?= e($row['student_name']) ?></td>
                            <td data-label="Enrollment" class="mono"><?= e($row['enrollment_no']) ?></td>
                            <td data-label="Year"><?= e(year_label((int) $row['year_level'])) ?></td>
                            <td data-label="Email"><?= e((string) ($row['email'] ?: '-')) ?></td>
                            <td data-label="Faculty"><?= e($row['teacher_name']) ?></td>
                            <td data-label="Status"><span class="badge <?= $isSubmitted ? 'success' : 'warning' ?>"><?= e($isSubmitted ? 'Submitted' : 'Pending') ?></span></td>
                            <td data-label="Submitted At"><?= e((string) ($row['submitted_at'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No student submission records match the selected filters.</div>
        <?php endif; ?>
    </article>
    <?php
});

