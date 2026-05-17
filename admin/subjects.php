<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subject_upload_template.csv"');
    echo "Subject Code,Subject Short Name,Subject Name\n";
    echo "CSE-S2-01,EM-II,Engineering Mathematics-II\n";
    echo "CSE-S2-02,CP,Programming in C\n";
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
$uploadMode = is_post()
    ? trim((string) post('upload_mode'))
    : trim((string) ($_GET['upload_mode'] ?? 'bulk'));
if (!in_array($uploadMode, ['single', 'bulk'], true)) {
    $uploadMode = 'bulk';
}

$uploadRedirectUrl = 'admin/subjects.php?department_id=' . $uploadDepartmentId . '&year_level=' . $uploadYearLevel . '&semester_no=' . $uploadSemesterNo . '&upload_mode=' . urlencode($uploadMode);
if (trim($search) !== '') {
    $uploadRedirectUrl .= '&search=' . urlencode($search);
}

$filterRedirectUrl = 'admin/subjects.php?department_id=' . $filterDepartmentId . '&year_level=' . $filterYearLevel . '&semester_no=' . $filterSemesterNo . '&upload_mode=' . urlencode($uploadMode);
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

        if ($action === 'single_upload_subject') {
            if ($uploadSemesterNo !== ($uploadYearLevel * 2)) {
                throw new RuntimeException('Selected year and semester do not match. Please review the class filters and try again.');
            }

            $operation = add_or_update_subject((string) post('subject_code'), (string) post('subject_short_name'), (string) post('subject_name'), $uploadDepartmentId, $uploadSemesterNo);
            $department = department_by_id($uploadDepartmentId);
            $departmentLabel = $department['short_name'] ?? ('Department ' . $uploadDepartmentId);
            $subjectCode = strtoupper(trim((string) post('subject_code')));
            audit_log(
                'admin',
                (string) (current_user()['username'] ?? 'admin'),
                'SUBJECT_SAVE',
                $departmentLabel . ' ' . semester_label($uploadSemesterNo) . ' ' . $subjectCode . ' ' . $operation
            );
            flash('success', $operation === 'updated' ? 'Subject updated successfully.' : 'Subject added successfully.');
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
        $message = match ($action) {
            'delete_subject' => 'Subject could not be deleted right now. Please review its existing usage and try again.',
            'single_upload_subject' => 'Subject could not be saved right now. Please review the selected class, subject code, and subject name, then try again.',
            default => 'Subject import could not be completed right now. Please review the selected class and CSV file, then try again.',
        };
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

render_dashboard_layout('Subject Management', 'admin', 'subjects', 'admin/subjects.css', 'admin/subjects.js', function () use ($departments, $uploadDepartmentId, $uploadYearLevel, $uploadSemesterNo, $uploadMode, $filterDepartmentId, $filterYearLevel, $filterSemesterNo, $search, $subjectRows, $classesCovered, $selectedClassStudents, $currentClassLabel): void {
    ?>
    <section class="grid-2 subject-admin-grid">
        <article class="data-card subject-upload-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Subject Upload</p>
                    <h3 class="card-title">Add one subject or import a full class list</h3>
                </div>
            </div>
            <?php if ($departments): ?>
                <div class="subject-upload-mode-switch">
                    <label class="form-label" for="subject-upload-mode">Upload Mode</label>
                    <select class="form-select" id="subject-upload-mode" data-subject-upload-mode>
                        <option value="single" <?= $uploadMode === 'single' ? 'selected' : '' ?>>Single Upload</option>
                        <option value="bulk" <?= $uploadMode === 'bulk' ? 'selected' : '' ?>>Bulk Upload</option>
                    </select>
                </div>

                <div class="subject-upload-panel-stack">
                    <form method="post" class="form-grid subject-upload-form subject-upload-panel" data-subject-upload-panel="single" <?= $uploadMode !== 'single' ? 'hidden' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="single_upload_subject">
                        <input type="hidden" name="upload_mode" value="single">
                        <div class="form-grid two">
                            <div class="form-group">
                                <label class="form-label" for="subject-single-upload-department">Department</label>
                                <select class="form-select" id="subject-single-upload-department" name="department_id" required>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= e((string) $department['id']) ?>" <?= $uploadDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="subject-single-upload-year">Year</label>
                                <select class="form-select" id="subject-single-upload-year" name="year_level" required>
                                    <option value="1" <?= $uploadYearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                                    <option value="2" <?= $uploadYearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                                    <option value="3" <?= $uploadYearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid two">
                            <div class="form-group">
                                <label class="form-label" for="subject-single-upload-semester">Semester</label>
                                <select class="form-select" id="subject-single-upload-semester" name="semester_no" required>
                                    <?php foreach (semester_numbers() as $semester): ?>
                                        <option value="<?= e((string) $semester) ?>" <?= $uploadSemesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="subject-single-code">Subject Code</label>
                                <input class="form-input" id="subject-single-code" type="text" name="subject_code" placeholder="CSE-S4-03" required>
                            </div>
                        </div>
                        <div class="form-grid two">
                            <div class="form-group">
                                <label class="form-label" for="subject-single-short-name">Subject Short Name</label>
                                <input class="form-input" id="subject-single-short-name" type="text" name="subject_short_name" placeholder="DSUC" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="subject-single-name">Subject Name</label>
                                <input class="form-input" id="subject-single-name" type="text" name="subject_name" placeholder="Data Structures using C" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <p class="muted">Short name report cards aur compact tables me show hoga. Bulk CSV me bhi Subject Short Name column use kar sakte ho.</p>
                        </div>
                        <button class="btn-primary" type="submit">Save Subject</button>
                    </form>

                    <form method="post" enctype="multipart/form-data" class="form-grid subject-upload-form subject-upload-panel" data-subject-upload-panel="bulk" <?= $uploadMode !== 'bulk' ? 'hidden' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="bulk_import_subjects">
                        <input type="hidden" name="upload_mode" value="bulk">
                        <div class="form-grid two">
                            <div class="form-group">
                                <label class="form-label" for="subject-bulk-upload-department">Department</label>
                                <select class="form-select" id="subject-bulk-upload-department" name="department_id" required>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= e((string) $department['id']) ?>" <?= $uploadDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="subject-bulk-upload-year">Year</label>
                                <select class="form-select" id="subject-bulk-upload-year" name="year_level" required>
                                    <option value="1" <?= $uploadYearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                                    <option value="2" <?= $uploadYearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                                    <option value="3" <?= $uploadYearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid two">
                            <div class="form-group">
                                <label class="form-label" for="subject-bulk-upload-semester">Semester</label>
                                <select class="form-select" id="subject-bulk-upload-semester" name="semester_no" required>
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
                        <div class="subject-upload-actions">
                            <a class="btn-secondary" href="<?= e(url('admin/subjects.php?template=1&department_id=' . $uploadDepartmentId . '&year_level=' . $uploadYearLevel . '&semester_no=' . $uploadSemesterNo . '&upload_mode=bulk')) ?>">Download Template</a>
                            <button class="btn-primary" type="submit">Import Subjects</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="empty-state">No departments are available yet. Add departments first, then upload subjects.</div>
            <?php endif; ?>
        </article>
        <article class="data-card subject-filter-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Browse Subjects</p>
                    <h3 class="card-title">Filter the stored subject register</h3>

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
                    <input class="search-input" id="subject-filter-search" name="search" value="<?= e($search) ?>" placeholder="Subject code, short name, name, or department">
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
                        <th>Short Name</th>
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
                            <td data-label="Short Name" class="mono"><?= e((string) ($row['subject_short_name'] ?? subject_short_label($row))) ?></td>
                            <td data-label="Subject Name"><?= e($row['subject_name']) ?></td>
                            <td data-label="Students"><?= e((string) $row['student_count']) ?></td>
                            <td class="subject-action-cell" data-label="Action">
                                <form method="post">
                                    <?= csrf_field() ?>
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

