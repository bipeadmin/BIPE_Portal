<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subject_upload_template.csv"');
    echo "Subject Code,Subject Name\n";
    echo "CSE-S2-01,Engineering Mathematics-II\n";
    echo "CSE-S2-02,Programming in C\n";
    exit;
}

$departments = departments();
$normalizeClassSelection = static function (int $yearLevel, int $semesterNo): array {
    if ($semesterNo > 0) {
        $yearLevel = max(1, (int) floor($semesterNo / 2));
    } elseif ($yearLevel > 0) {
        $semesterNo = $yearLevel * 2;
    }

    return [$yearLevel, $semesterNo];
};

$filterDepartmentId = (int) ($_GET['department_id'] ?? 0);
$filterYearLevel = (int) ($_GET['year_level'] ?? 0);
$filterSemesterNo = (int) ($_GET['semester_no'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
[$filterYearLevel, $filterSemesterNo] = $normalizeClassSelection($filterYearLevel, $filterSemesterNo);

$uploadDepartmentId = is_post()
    ? (int) post('department_id')
    : ($filterDepartmentId > 0 ? $filterDepartmentId : (int) ($departments[0]['id'] ?? 0));
$uploadYearLevel = is_post()
    ? (int) post('year_level')
    : ($filterYearLevel > 0 ? $filterYearLevel : 1);
$uploadSemesterNo = is_post()
    ? (int) post('semester_no')
    : ($filterSemesterNo > 0 ? $filterSemesterNo : ($uploadYearLevel > 0 ? $uploadYearLevel * 2 : 2));
[$uploadYearLevel, $uploadSemesterNo] = $normalizeClassSelection($uploadYearLevel, $uploadSemesterNo);

$uploadRedirectUrl = 'admin/subjects.php?department_id=' . $uploadDepartmentId . '&year_level=' . $uploadYearLevel . '&semester_no=' . $uploadSemesterNo;
if (trim($search) !== '') {
    $uploadRedirectUrl .= '&search=' . urlencode($search);
}

$filterRedirectUrl = 'admin/subjects.php?department_id=' . $filterDepartmentId . '&year_level=' . $filterYearLevel . '&semester_no=' . $filterSemesterNo;
if (trim($search) !== '') {
    $filterRedirectUrl .= '&search=' . urlencode($search);
}

if (is_post()) {
    $action = (string) post('action');
    $redirectUrl = $action === 'delete_subject' ? $filterRedirectUrl : $uploadRedirectUrl;

    try {
        if ($action === 'bulk_import_subjects') {
            $csvPath = assert_uploaded_file((array) ($_FILES['subject_file'] ?? []), ['csv'], (int) config('uploads.max_csv_bytes', 2097152));
            $result = bulk_import_subjects_csv($csvPath, $uploadDepartmentId, $uploadYearLevel, $uploadSemesterNo);
            $department = department_by_id($uploadDepartmentId);
            $departmentLabel = $department['short_name'] ?? ('Department ' . $uploadDepartmentId);
            audit_log(
                'admin',
                (string) (current_user()['username'] ?? 'admin'),
                'SUBJECT_IMPORT',
                $departmentLabel . ' ' . semester_label($uploadSemesterNo) . ' inserted ' . $result['inserted'] . ', updated ' . $result['updated']
            );
            flash('success', 'Bulk subject import complete. Inserted ' . $result['inserted'] . ' and updated ' . $result['updated'] . ' subjects.');
        }

        if ($action === 'delete_subject') {
            $subject = delete_subject_row((int) post('subject_id'));
            $departmentLabel = $subject['short_name'] ?? ($subject['department_name'] ?? ('Department ' . (int) $subject['department_id']));
            audit_log(
                'admin',
                (string) (current_user()['username'] ?? 'admin'),
                'SUBJECT_DELETE',
                $departmentLabel . ' ' . semester_label((int) $subject['semester_no']) . ' ' . $subject['subject_code'] . ' deleted'
            );
            flash('success', 'Subject deleted successfully.');
        }
    } catch (Throwable $exception) {
        $message = $action === 'delete_subject'
            ? 'Subject could not be deleted right now. Please review its existing usage and try again.'
            : 'Subject import could not be completed right now. Please review the selected class and CSV file, then try again.';
        flash_exception($exception, $message);
    }

    redirect_to($redirectUrl);
}

$subjectRows = subject_directory_rows(
    $filterDepartmentId > 0 ? $filterDepartmentId : null,
    $filterYearLevel > 0 ? $filterYearLevel : null,
    $filterSemesterNo > 0 ? $filterSemesterNo : null,
    $search
);
$classesCovered = count(array_unique(array_map(static fn (array $row): string => ((int) $row['department_id']) . '|' . ((int) $row['semester_no']), $subjectRows)));
$selectedClassStudents = ($filterDepartmentId > 0 && $filterSemesterNo > 0)
    ? (int) query_value(
        'SELECT COUNT(*) FROM students WHERE department_id = :department_id AND semester_no = :semester_no',
        ['department_id' => $filterDepartmentId, 'semester_no' => $filterSemesterNo]
    )
    : 0;
$selectedDepartment = $filterDepartmentId > 0 ? department_by_id($filterDepartmentId) : null;
$currentClassLabel = ($selectedDepartment && $filterYearLevel > 0 && $filterSemesterNo > 0)
    ? ($selectedDepartment['short_name'] . ' · ' . year_label($filterYearLevel) . ' · ' . semester_label($filterSemesterNo))
    : 'All departments / classes';

render_dashboard_layout('Subject Management', 'admin', 'subjects', 'admin/subjects.css', 'admin/subjects.js', function () use ($departments, $uploadDepartmentId, $uploadYearLevel, $uploadSemesterNo, $filterDepartmentId, $filterYearLevel, $filterSemesterNo, $search, $subjectRows, $classesCovered, $selectedClassStudents, $currentClassLabel): void {
    ?>
    <section class="grid-2 subject-admin-grid">
        <article class="data-card subject-upload-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Bulk Upload</p>
                    <h3 class="card-title">Import subjects class-wise</h3>
                    <p class="card-subtitle">Choose department, year, and semester first. The CSV only needs subject code and subject name.</p>
                </div>
                <a class="btn-secondary" href="<?= e(url('admin/subjects.php?template=1&department_id=' . $uploadDepartmentId . '&year_level=' . $uploadYearLevel . '&semester_no=' . $uploadSemesterNo)) ?>">Download Template</a>
            </div>
            <?php if ($departments): ?>
                <form method="post" enctype="multipart/form-data" class="form-grid subject-upload-form">
                    <input type="hidden" name="action" value="bulk_import_subjects">
                    <div class="form-grid two">
                        <div class="form-group">
                            <label class="form-label" for="subject-upload-department">Department</label>
                            <select class="form-select" id="subject-upload-department" name="department_id" required>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e((string) $department['id']) ?>" <?= $uploadDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="subject-upload-year">Year</label>
                            <select class="form-select" id="subject-upload-year" name="year_level" required>
                                <option value="1" <?= $uploadYearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                                <option value="2" <?= $uploadYearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                                <option value="3" <?= $uploadYearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid two">
                        <div class="form-group">
                            <label class="form-label" for="subject-upload-semester">Semester</label>
                            <select class="form-select" id="subject-upload-semester" name="semester_no" required>
                                <?php foreach (semester_numbers() as $semester): ?>
                                    <option value="<?= e((string) $semester) ?>" <?= $uploadSemesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="subject-file">CSV File</label>
                            <input class="form-input" id="subject-file" type="file" name="subject_file" accept=".csv" data-file-input data-file-target="#subject-file-name" required>
                            <div class="file-hint" id="subject-file-name">No file selected</div>
                        </div>
                    </div>
                    <div class="notice-box">Use one CSV per class. Department, year, and semester are taken from the form, so the file only needs <span class="mono">Subject Code</span> and <span class="mono">Subject Name</span> columns.</div>
                    <button class="btn-primary" type="submit">Import Subjects</button>
                </form>
            <?php else: ?>
                <div class="empty-state">No departments are available yet. Add departments first, then import subjects.</div>
            <?php endif; ?>
        </article>

        <article class="data-card subject-filter-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Browse Subjects</p>
                    <h3 class="card-title">Filter the stored subject register</h3>
                    <p class="card-subtitle">Use department, year, semester, and search together to review exactly one class or the whole catalog.</p>
                </div>
            </div>
            <form method="get" class="filters subject-filter-form">
                <div class="form-group">
                    <label class="form-label" for="subject-filter-department">Department</label>
                    <select class="form-select" id="subject-filter-department" name="department_id">
                        <option value="0">All departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" <?= $filterDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="subject-filter-year">Year</label>
                    <select class="form-select" id="subject-filter-year" name="year_level">
                        <option value="0">All years</option>
                        <option value="1" <?= $filterYearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                        <option value="2" <?= $filterYearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                        <option value="3" <?= $filterYearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="subject-filter-semester">Semester</label>
                    <select class="form-select" id="subject-filter-semester" name="semester_no">
                        <option value="0">All semesters</option>
                        <?php foreach (semester_numbers() as $semester): ?>
                            <option value="<?= e((string) $semester) ?>" <?= $filterSemesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="subject-filter-search">Search</label>
                    <input class="search-input" id="subject-filter-search" name="search" value="<?= e($search) ?>" placeholder="Subject code, name, or department">
                </div>
                <button class="btn-primary" type="submit">Apply Filters</button>
            </form>
            <div class="stack subject-filter-meta">
                <div class="data-list-item"><strong>Current Class</strong><p class="muted"><?= e($currentClassLabel) ?></p></div>
                <div class="data-list-item"><strong>Students In Selected Class</strong><p class="muted"><?= ($filterDepartmentId > 0 && $filterSemesterNo > 0) ? e((string) $selectedClassStudents) . ' students currently mapped to this class.' : 'Choose one department and semester to see class strength.' ?></p></div>
            </div>
        </article>
    </section>

    <section class="stats-grid subject-stats-grid">
        <article class="stat-card"><p class="eyebrow">Filtered Subjects</p><h3 class="stat-value"><?= e((string) count($subjectRows)) ?></h3><p class="stat-label">Subject rows visible in the current filter set</p></article>
        <article class="stat-card"><p class="eyebrow">Classes Covered</p><h3 class="stat-value"><?= e((string) $classesCovered) ?></h3><p class="stat-label">Distinct department-semester groups in the filtered subject list</p></article>
        <article class="stat-card"><p class="eyebrow">Class Strength</p><h3 class="stat-value"><?= e((string) $selectedClassStudents) ?></h3><p class="stat-label">Students currently enrolled in the selected class</p></article>
    </section>

    <article class="data-card subject-directory-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Subject Register</p>
                <h3 class="card-title">Stored subjects by department and semester</h3>
                <p class="card-subtitle">This list updates with the selected filters and stays readable on mobile without horizontal scrolling.</p>
            </div>
        </div>
        <?php if ($subjectRows): ?>
            <div class="table-wrap subject-directory-wrap">
                <table class="subject-directory-table">
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Students</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($subjectRows as $row): ?>
                        <tr>
                            <td data-label="Department"><strong><?= e($row['department_name']) ?></strong><span class="department-code"><?= e($row['short_name']) ?></span></td>
                            <td data-label="Year"><?= e(year_label((int) $row['year_level'])) ?></td>
                            <td data-label="Semester"><?= e(semester_label((int) $row['semester_no'])) ?></td>
                            <td data-label="Subject Code" class="mono"><?= e($row['subject_code']) ?></td>
                            <td data-label="Subject Name"><?= e($row['subject_name']) ?></td>
                            <td data-label="Students"><?= e((string) $row['student_count']) ?></td>
                            <td class="subject-action-cell" data-label="Action">
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_subject">
                                    <input type="hidden" name="subject_id" value="<?= e((string) $row['id']) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this subject? If marks or assignments already use it, deletion will be stopped.">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No subjects match the selected filters yet.</div>
        <?php endif; ?>
    </article>
    <?php
});


