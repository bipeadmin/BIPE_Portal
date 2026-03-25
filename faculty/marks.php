<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$departmentId = (int) ($teacher['department_id'] ?? 0);
$semesterNo = (int) (post('semester_no') ?: ($_GET['semester_no'] ?? 2));
$subjectId = (int) (post('subject_id') ?: ($_GET['subject_id'] ?? 0));
$markTypeId = (int) (post('mark_type_id') ?: ($_GET['mark_type_id'] ?? 0));
$subjectOptions = subjects_for($departmentId, $semesterNo);
$markTypes = mark_type_rows();
if ($subjectId === 0 && $subjectOptions) {
    $subjectId = (int) $subjectOptions[0]['id'];
}
if ($markTypeId === 0 && $markTypes) {
    $markTypeId = (int) $markTypes[0]['id'];
}
$students = students_for_class($departmentId, $semesterNo);
$studentMap = [];
foreach ($students as $student) {
    $studentMap[strtoupper($student['enrollment_no'])] = (int) $student['id'];
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="marks_template.csv"');
    echo "Enrollment No,Student Name,Marks,Absent\n";
    foreach ($students as $student) {
        echo $student['enrollment_no'] . ',' . $student['full_name'] . ',,No\n';
    }
    exit;
}

if (is_post()) {
    $action = (string) post('action');

    try {
        if ($action === 'delete_upload') {
            delete_mark_upload((int) post('upload_id'));
            audit_log('teacher', (string) ($teacher['teacher_code'] ?? $teacher['id']), 'MARKS_DELETE', 'Upload ' . (int) post('upload_id'));
            flash('success', 'Marks upload deleted successfully.');
        }

        if ($action === 'save_manual') {
            if ((int) post('subject_id') <= 0 || (int) post('mark_type_id') <= 0) {
                throw new RuntimeException('Choose a subject and mark type first.');
            }
            $records = [];
            $absentRows = (array) post('absent', []);
            foreach ($students as $student) {
                $studentId = (int) $student['id'];
                $value = trim((string) ((array) post('marks', []))[$studentId] ?? '');
                $absent = isset($absentRows[$studentId]);
                if ($value === '' && !$absent) {
                    continue;
                }
                if (!$absent && !is_numeric($value)) {
                    throw new RuntimeException('Marks must be numeric values.');
                }
                $records[$studentId] = ['marks' => $absent ? null : (float) $value, 'absent' => $absent];
            }
            save_mark_upload_sheet((int) $teacher['id'], $departmentId, $semesterNo, (int) post('subject_id'), (int) post('mark_type_id'), $records);
            flash('success', 'Marks saved successfully.');
        }

        if ($action === 'save_csv') {
            if ((int) post('subject_id') <= 0 || (int) post('mark_type_id') <= 0) {
                throw new RuntimeException('Choose a subject and mark type first.');
            }
            $csvPath = assert_uploaded_file((array) ($_FILES['marks_file'] ?? []), ['csv'], (int) config('uploads.max_csv_bytes', 2097152));
            $records = parse_marks_csv_records_with_absent($csvPath, $studentMap);
            save_mark_upload_sheet((int) $teacher['id'], $departmentId, $semesterNo, (int) post('subject_id'), (int) post('mark_type_id'), $records);
            flash('success', 'CSV marks uploaded successfully.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('faculty/marks.php?semester_no=' . $semesterNo . '&subject_id=' . $subjectId . '&mark_type_id=' . $markTypeId);
}

$existingUpload = ($subjectId > 0 && $markTypeId > 0) ? mark_upload_detail_by_type($departmentId, $semesterNo, $subjectId, $markTypeId) : null;
$existingRecords = $existingUpload ? mark_records_map_for_upload((int) $existingUpload['id']) : [];
$uploads = array_values(array_filter(
    mark_upload_rows_for_department($departmentId),
    static fn (array $row): bool => (int) $row['semester_no'] === $semesterNo
));
$isLocked = mark_section_locked($departmentId, $semesterNo);

render_dashboard_layout('Upload Marks', 'teacher', 'marks', 'faculty/marks.css', 'faculty/marks.js', function () use ($semesterNo, $subjectId, $markTypeId, $subjectOptions, $markTypes, $students, $existingRecords, $uploads, $isLocked): void {
    ?>
    <?php if ($isLocked): ?>
        <div class="notice-box danger">This semester is locked by the administrator. You can review marks, but you cannot modify uploads until the section is unlocked.</div>
    <?php endif; ?>

    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Manual Entry</p>
                    <h3 class="card-title">Enter or edit marks for a subject</h3>
                </div>
                <a class="btn-secondary" href="<?= e(url('faculty/marks.php?template=1&semester_no=' . $semesterNo)) ?>">Download CSV Template</a>
            </div>
            <form method="get" class="filters" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="marks-semester">Semester</label>
                    <select class="form-select" id="marks-semester" name="semester_no">
                        <?php foreach (semester_numbers() as $semester): ?>
                            <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="marks-subject">Subject</label>
                    <select class="form-select" id="marks-subject" name="subject_id">
                        <option value="0">Select subject</option>
                        <?php foreach ($subjectOptions as $subject): ?>
                            <option value="<?= e((string) $subject['id']) ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subject['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="marks-type">Mark Type</label>
                    <select class="form-select" id="marks-type" name="mark_type_id">
                        <?php foreach ($markTypes as $markType): ?>
                            <option value="<?= e((string) $markType['id']) ?>" <?= $markTypeId === (int) $markType['id'] ? 'selected' : '' ?>><?= e($markType['label']) ?> (/<?= e((string) $markType['max_marks']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-primary" type="submit">Load Sheet</button>
            </form>

            <?php if ($subjectId > 0 && $students): ?>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="save_manual">
                    <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                    <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                    <input type="hidden" name="mark_type_id" value="<?= e((string) $markTypeId) ?>">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Enrollment</th>
                                <th>Student Name</th>
                                <th>Marks</th>
                                <th>Absent</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student):
                                $entry = $existingRecords[(int) $student['id']] ?? null;
                                $isAbsentRow = $entry && (int) ($entry['is_absent'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td class="mono"><?= e($student['enrollment_no']) ?></td>
                                    <td><?= e($student['full_name']) ?></td>
                                    <td><input class="form-input mono" name="marks[<?= e((string) $student['id']) ?>]" value="<?= e((string) ($entry['marks_obtained'] ?? '')) ?>" placeholder="0" <?= $isLocked ? 'disabled' : '' ?>></td>
                                    <td><input type="checkbox" name="absent[<?= e((string) $student['id']) ?>]" <?= $isAbsentRow ? 'checked' : '' ?> <?= $isLocked ? 'disabled' : '' ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn-primary" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save Marks</button>
                </form>
            <?php else: ?>
                <div class="empty-state">Choose a subject to open the marks sheet for this semester.</div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">CSV Upload</p>
                    <h3 class="card-title">Import marks from spreadsheet export</h3>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="save_csv">
                <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
                <input type="hidden" name="subject_id" value="<?= e((string) $subjectId) ?>">
                <input type="hidden" name="mark_type_id" value="<?= e((string) $markTypeId) ?>">
                <div class="form-group">
                    <label class="form-label" for="marks-file">CSV File</label>
                    <input class="form-input" id="marks-file" type="file" name="marks_file" accept=".csv" data-file-input data-file-target="#marks-file-name" required <?= $isLocked ? 'disabled' : '' ?>>
                    <div class="file-hint" id="marks-file-name">No file selected</div>
                </div>
                <div class="notice-box">The upload expects <span class="mono">Enrollment</span>, <span class="mono">Marks</span>, and optional <span class="mono">Absent</span> columns.</div>
                <button class="btn-primary" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Upload CSV</button>
            </form>
        </article>
    </section>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Existing Uploads</p>
                <h3 class="card-title">Saved mark sheets for <?= e(semester_label($semesterNo)) ?></h3>
            </div>
        </div>
        <?php if ($uploads): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Exam</th>
                        <th>Max Marks</th>
                        <th>Uploaded</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($uploads as $upload): ?>
                        <tr>
                            <td><?= e($upload['subject_name']) ?></td>
                            <td><?= e($upload['exam_type']) ?></td>
                            <td><?= e((string) $upload['max_marks']) ?></td>
                            <td><?= e((string) $upload['uploaded_at']) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_upload">
                                    <input type="hidden" name="upload_id" value="<?= e((string) $upload['id']) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this marks upload?" <?= $isLocked ? 'disabled' : '' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No marks uploads saved for this semester yet.</div>
        <?php endif; ?>
    </article>
    <?php
});



