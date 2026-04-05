<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_upload_template.csv"');
    echo "Name,Enrollment,Department,Year,Semester,Mobile\n";
    echo "RAHUL SHARMA,E25445535500099,CS Engineering,1st Year,Semester 2,9876543210\n";
    exit;
}

if (is_post()) {
    $action = (string) post('action');
    $redirectDepartmentId = (int) post('filter_department_id');
    $redirectYearLevel = (int) post('filter_year_level');
    $redirectSemesterNo = (int) post('filter_semester_no');
    $redirectSearch = trim((string) post('filter_search'));
    $redirectParams = [];

    if ($redirectDepartmentId > 0) {
        $redirectParams['department_id'] = $redirectDepartmentId;
    }
    if ($redirectYearLevel > 0) {
        $redirectParams['year_level'] = $redirectYearLevel;
    }
    if ($redirectSemesterNo > 0) {
        $redirectParams['semester_no'] = $redirectSemesterNo;
    }
    if ($redirectSearch !== '') {
        $redirectParams['search'] = $redirectSearch;
    }

    $redirectUrl = 'admin/students.php' . ($redirectParams !== [] ? '?' . http_build_query($redirectParams) : '');

    try {
        if ($action === 'add_student') {
            add_or_update_student(
                trim((string) post('full_name')),
                trim((string) post('enrollment_no')),
                (int) post('department_id'),
                (int) post('year_level'),
                (int) post('semester_no'),
                trim((string) post('phone_number'))
            );
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'STUDENT_SAVE', 'Enrollment ' . trim((string) post('enrollment_no')));
            flash('success', 'Student record saved successfully.');
        }

        if ($action === 'bulk_import') {
            $csvPath = assert_uploaded_file((array) ($_FILES['student_file'] ?? []), ['csv'], (int) config('uploads.max_csv_bytes', 2097152));
            $result = bulk_import_students_csv($csvPath);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'STUDENT_IMPORT', 'Inserted ' . $result['inserted'] . ', updated ' . $result['updated']);
            flash('success', 'Bulk import complete. Inserted ' . $result['inserted'] . ' and updated ' . $result['updated'] . ' students.');
        }

        if ($action === 'delete_student') {
            delete_student_record((int) post('student_id'));
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'STUDENT_DELETE', 'Student ID ' . (int) post('student_id'));
            flash('success', 'Student record deleted successfully.');
        }

        if ($action === 'delete_filtered_students') {
            $filteredStudents = student_directory_rows(
                $redirectDepartmentId > 0 ? $redirectDepartmentId : null,
                $redirectYearLevel > 0 ? $redirectYearLevel : null,
                $redirectSemesterNo > 0 ? $redirectSemesterNo : null,
                $redirectSearch
            );
            $studentIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $filteredStudents));
            $deletedCount = delete_student_records($studentIds);

            if ($deletedCount > 0) {
                $filterSummaryParts = [];
                if ($redirectDepartmentId > 0) {
                    $filterSummaryParts[] = 'department_id=' . $redirectDepartmentId;
                }
                if ($redirectYearLevel > 0) {
                    $filterSummaryParts[] = 'year_level=' . $redirectYearLevel;
                }
                if ($redirectSemesterNo > 0) {
                    $filterSummaryParts[] = 'semester_no=' . $redirectSemesterNo;
                }
                if ($redirectSearch !== '') {
                    $filterSummaryParts[] = 'search=' . $redirectSearch;
                }

                audit_log(
                    'admin',
                    (string) (current_user()['username'] ?? 'admin'),
                    'STUDENT_DELETE_BULK',
                    'Deleted ' . $deletedCount . ' student(s)' . ($filterSummaryParts !== [] ? ' using filters: ' . implode(', ', $filterSummaryParts) : ' using all-student filter')
                );
                flash('success', 'Deleted ' . $deletedCount . ' student record(s) from the current filter.');
            } else {
                flash('info', 'No student records matched the current filter for deletion.');
            }
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to($redirectUrl);
}

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? 0);
$yearLevel = (int) ($_GET['year_level'] ?? 0);
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$students = student_directory_rows($departmentId > 0 ? $departmentId : null, $yearLevel > 0 ? $yearLevel : null, $semesterNo > 0 ? $semesterNo : null, $search);
$studentCount = count($students);
$deleteScopeLabelParts = [];

if ($departmentId > 0) {
    foreach ($departments as $department) {
        if ((int) $department['id'] === $departmentId) {
            $deleteScopeLabelParts[] = $department['name'];
            break;
        }
    }
}
if ($yearLevel > 0) {
    $deleteScopeLabelParts[] = year_label($yearLevel);
}
if ($semesterNo > 0) {
    $deleteScopeLabelParts[] = semester_label($semesterNo);
}
if ($search !== '') {
    $deleteScopeLabelParts[] = 'Search: ' . $search;
}

$deleteScopeLabel = $deleteScopeLabelParts !== [] ? implode(' / ', $deleteScopeLabelParts) : 'All students';

render_dashboard_layout('Student Management', 'admin', 'students', 'admin/students.css', 'admin/students.js', function () use ($departments, $departmentId, $yearLevel, $semesterNo, $search, $students, $studentCount, $deleteScopeLabel): void {
    ?>
    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Add Student</p>
                    <h3 class="card-title">Create or update a roster entry</h3>
                </div>
            </div>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_student">
                <div class="form-group">
                    <label class="form-label" for="student-name">Full Name</label>
                    <input class="form-input" id="student-name" name="full_name" required>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="student-enrollment-admin">Enrollment Number</label>
                        <input class="form-input mono" id="student-enrollment-admin" name="enrollment_no" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="student-phone-admin">Mobile Number</label>
                        <input class="form-input" id="student-phone-admin" name="phone_number" type="tel" placeholder="Optional mobile number">
                    </div>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="student-dept-admin">Department</label>
                        <select class="form-select" id="student-dept-admin" name="department_id" required>
                            <option value="">Select department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e((string) $department['id']) ?>"><?= e($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="student-year-admin">Year</label>
                        <select class="form-select" id="student-year-admin" name="year_level" required>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="student-semester-admin">Semester</label>
                    <select class="form-select" id="student-semester-admin" name="semester_no" required>
                        <?php foreach (semester_numbers() as $semester): ?>
                            <option value="<?= e((string) $semester) ?>"><?= e(semester_label($semester)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-primary" type="submit">Save Student</button>
            </form>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Bulk Import</p>
                    <h3 class="card-title">Upload students from CSV</h3>
                </div>
                <a class="btn-secondary" href="<?= e(url('admin/students.php?template=1')) ?>">Download Template</a>
            </div>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_import">
                <div class="form-group">
                    <label class="form-label" for="student-file">CSV File</label>
                    <input class="form-input" id="student-file" type="file" name="student_file" accept=".csv" data-file-input data-file-target="#student-file-name" required>
                    <div class="file-hint" id="student-file-name">No file selected</div>
                </div>
                <div class="notice-box">Use the provided CSV template. Department names can be full names like <span class="mono">CS Engineering</span> or short codes like <span class="mono">CSE</span>. Mobile column is optional.</div>
                <button class="btn-primary" type="submit">Import CSV</button>
            </form>
        </article>
    </section>

    <article class="data-card student-directory-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Roster</p>
                <h3 class="card-title">Stored student records</h3>
                <p class="card-subtitle">Filter the roster by department, year, semester, or a name/enrollment/email/mobile search.</p>
            </div>
        </div>
        <form method="get" class="filters student-directory-filters">
            <div class="form-group">
                <label class="form-label" for="filter-department">Department</label>
                <select class="form-select" id="filter-department" name="department_id">
                    <option value="0">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="filter-year">Year</label>
                <select class="form-select" id="filter-year" name="year_level">
                    <option value="0">All years</option>
                    <option value="1" <?= $yearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                    <option value="2" <?= $yearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3" <?= $yearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="filter-semester">Semester</label>
                <select class="form-select" id="filter-semester" name="semester_no">
                    <option value="0">All semesters</option>
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="filter-search">Search</label>
                <input class="search-input" id="filter-search" name="search" value="<?= e($search) ?>" placeholder="Name, enrollment, email, or mobile">
            </div>
            <button class="btn-primary" type="submit">Apply Filters</button>
        </form>

        <?php if ($students): ?>
            <div class="inline-actions student-directory-tools">
                <span class="muted"><?= e((string) $studentCount) ?> student<?= $studentCount === 1 ? '' : 's' ?> in current filter: <?= e($deleteScopeLabel) ?></span>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_filtered_students">
                    <input type="hidden" name="filter_department_id" value="<?= e((string) $departmentId) ?>">
                    <input type="hidden" name="filter_year_level" value="<?= e((string) $yearLevel) ?>">
                    <input type="hidden" name="filter_semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="filter_search" value="<?= e($search) ?>">
                    <button class="btn-danger" type="submit" data-confirm="Delete all <?= e((string) $studentCount) ?> student record(s) from the current filter (<?= e($deleteScopeLabel) ?>)?">Delete All</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($students): ?>
            <div class="table-wrap student-directory-wrap">
                <table class="student-directory-table">
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Account</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="mono" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                            <td class="student-name-cell" data-label="Student">
                                <strong><?= e($student['full_name']) ?></strong>
                            </td>
                            <td data-label="Department"><?= e($student['department_name']) ?></td>
                            <td data-label="Year"><?= e(year_label((int) $student['year_level'])) ?></td>
                            <td data-label="Semester"><?= e(semester_label((int) $student['semester_no'])) ?></td>
                            <td data-label="Email"><?= e((string) ($student['email'] ?: '-')) ?></td>
                            <td data-label="Mobile"><?= e((string) ($student['phone_number'] ?: '-')) ?></td>
                            <td data-label="Account">
                                <?php if (!empty($student['password_hash'])): ?>
                                    <span class="badge success">Registered</span>
                                <?php else: ?>
                                    <span class="badge warning">Pending activation</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?= e((string) $student['id']) ?>">
                                    <input type="hidden" name="filter_department_id" value="<?= e((string) $departmentId) ?>">
                                    <input type="hidden" name="filter_year_level" value="<?= e((string) $yearLevel) ?>">
                                    <input type="hidden" name="filter_semester_no" value="<?= e((string) $semesterNo) ?>">
                                    <input type="hidden" name="filter_search" value="<?= e($search) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this student record?">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No students match the selected filters.</div>
        <?php endif; ?>
    </article>
    <?php
});
