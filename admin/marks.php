<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? ($departments[0]['id'] ?? 0));
$semesterNo = (int) ($_GET['semester_no'] ?? 2);
$markTypes = mark_type_rows();
$validMarkTypeIds = array_values(array_map(static fn (array $markType): int => (int) $markType['id'], $markTypes));
$markTypeId = (int) ($_GET['mark_type_id'] ?? 0);
if ($markTypeId > 0 && !in_array($markTypeId, $validMarkTypeIds, true)) {
    $markTypeId = 0;
}
$selectedMarkType = $markTypeId > 0 ? mark_type_by_id($markTypeId) : null;
$assessmentLabel = $selectedMarkType['label'] ?? 'All Internal Marks';

$sanitizeFileSegment = static function (string $value): string {
    $value = preg_replace('/[^A-Za-z0-9._ -]+/', '', $value) ?? '';
    $value = trim((string) preg_replace('/\s+/', '_', $value));

    return $value !== '' ? $value : 'item';
};

$csvStringFromRows = static function (array $header, array $rows): string {
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('Unable to create the CSV export buffer.');
    }

    fputcsv($handle, $header);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return $csv === false ? '' : $csv;
};

$buildDisplayRows = static function (int $departmentId, int $semesterNo, int $markTypeId): array {
    if ($markTypeId > 0) {
        $rows = class_mark_type_overview_rows($departmentId, $semesterNo, $markTypeId);
        foreach ($rows as &$row) {
            $row['score'] = (float) ($row['marks_total'] ?? 0);
            $row['max'] = (float) ($row['marks_max'] ?? 0);
            $row['percentage'] = $row['max'] > 0 ? round(($row['score'] / $row['max']) * 100, 2) : 0.0;
            $row['coverage'] = (int) ($row['recorded_subjects'] ?? 0) . '/' . (int) ($row['published_subjects'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    $rows = class_marks_overview_rows($departmentId, $semesterNo);
    foreach ($rows as &$row) {
        $row['score'] = (float) ($row['grand_total'] ?? 0);
        $row['max'] = (float) ($row['grand_max'] ?? 0);
        $row['percentage'] = $row['max'] > 0 ? round(($row['score'] / $row['max']) * 100, 2) : 0.0;
        $row['result'] = $row['max'] > 0 ? pass_fail_from_marks($row['score'], $row['max']) : 'Pending';
        $row['coverage'] = '-';
    }
    unset($row);

    return $rows;
};

if (is_post()) {
    try {
        if ((string) post('action') === 'toggle_lock') {
            $departmentId = (int) post('department_id');
            $semesterNo = (int) post('semester_no');
            $markTypeId = (int) post('mark_type_id');
            $lock = ((string) post('mode')) === 'lock';
            set_mark_section_lock($departmentId, $semesterNo, $lock);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), $lock ? 'MARKS_LOCK' : 'MARKS_UNLOCK', 'Department ' . $departmentId . ' semester ' . $semesterNo);
            flash('success', $lock ? 'Marks section locked successfully.' : 'Marks section unlocked successfully.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('admin/marks.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&mark_type_id=' . $markTypeId);
}

if (isset($_GET['export']) && $departmentId > 0) {
    $exportType = (string) $_GET['export'];

    if ($exportType === 'current_csv') {
        $rows = $buildDisplayRows($departmentId, $semesterNo, $markTypeId);
        $department = department_by_id($departmentId);
        $fileLabel = $markTypeId > 0 ? ('assessment_' . $markTypeId) : 'all_assessments';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="marks_view_' . $sanitizeFileSegment((string) ($department['short_name'] ?? $department['name'] ?? ('department_' . $departmentId))) . '_sem' . $semesterNo . '_' . $fileLabel . '.csv"');

        echo $csvStringFromRows(
            ['Enrollment', 'Name', 'Department', 'Semester', 'Assessment', $markTypeId > 0 ? 'Average Score' : 'Score', 'Max', 'Percentage', 'Grade', 'Result', 'Attendance', 'Coverage'],
            array_map(static function (array $row) use ($semesterNo, $assessmentLabel): array {
                $student = $row['student'];

                return [
                    (string) ($student['enrollment_no'] ?? ''),
                    (string) ($student['full_name'] ?? ''),
                    (string) ($student['department_name'] ?? ''),
                    semester_label($semesterNo),
                    $assessmentLabel,
                    (string) ($row['score'] ?? 0),
                    (string) ($row['max'] ?? 0),
                    (string) ($row['percentage'] ?? 0),
                    (string) ($row['grade'] ?? '-'),
                    (string) ($row['result'] ?? 'Pending'),
                    (string) (($row['attendance']['percentage'] ?? 0) . '%'),
                    (string) ($row['coverage'] ?? '-'),
                ];
            }, $rows)
        );
        exit;
    }

    if ($exportType === 'all_csv_pack') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available on this server. Install the PHP zip extension to export the organized CSV pack.');
        }

        $archivePath = tempnam(sys_get_temp_dir(), 'bipe_marks_pack_');
        if ($archivePath === false) {
            throw new RuntimeException('Unable to create a temporary archive file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::OVERWRITE) !== true) {
            @unlink($archivePath);
            throw new RuntimeException('Unable to create the organized marks archive.');
        }

        $filesAdded = 0;
        foreach ($departments as $department) {
            $exportDepartmentId = (int) $department['id'];
            foreach (semester_numbers() as $exportSemesterNo) {
                $subjects = subjects_for($exportDepartmentId, $exportSemesterNo);
                foreach ($subjects as $subject) {
                    $rows = subject_marks_export_rows($exportDepartmentId, $exportSemesterNo, (int) $subject['id']);
                    if ($rows === []) {
                        continue;
                    }

                    $sampleStudent = $rows[0]['student'] ?? [];
                    $yearLevel = (int) ($sampleStudent['year_level'] ?? max(1, (int) ceil($exportSemesterNo / 2)));
                    $header = ['Department', 'Year', 'Semester', 'Subject', 'Enrollment', 'Student Name'];
                    foreach ($markTypes as $markType) {
                        $header[] = (string) $markType['label'] . ' (/'. (string) $markType['max_marks'] . ')';
                    }
                    $header = array_merge($header, ['Total', 'Max', 'Grade', 'Result']);

                    $csvRows = [];
                    foreach ($rows as $row) {
                        $student = $row['student'];
                        $csvRow = [
                            (string) ($department['name'] ?? ''),
                            year_label($yearLevel),
                            semester_label($exportSemesterNo),
                            (string) ($subject['subject_name'] ?? ''),
                            (string) ($student['enrollment_no'] ?? ''),
                            (string) ($student['full_name'] ?? ''),
                        ];
                        foreach ((array) ($row['cells'] ?? []) as $cell) {
                            $csvRow[] = (string) ($cell['display'] ?? '--');
                        }
                        $csvRow[] = (string) ($row['total'] ?? 0);
                        $csvRow[] = (string) ($row['max'] ?? 0);
                        $csvRow[] = (string) ($row['grade'] ?? '-');
                        $csvRow[] = (string) ($row['result'] ?? 'Pending');
                        $csvRows[] = $csvRow;
                    }

                    $archiveEntry = $sanitizeFileSegment((string) ($department['name'] ?? 'Department'))
                        . '/'
                        . $sanitizeFileSegment(year_label($yearLevel) . ' ' . semester_label($exportSemesterNo))
                        . '/'
                        . $sanitizeFileSegment((string) ($subject['subject_name'] ?? 'Subject'))
                        . '.csv';

                    $zip->addFromString($archiveEntry, $csvStringFromRows($header, $csvRows));
                    $filesAdded++;
                }
            }
        }

        if ($filesAdded === 0) {
            $zip->addFromString('README.txt', "No subject-wise student marks were available to export at the time of generation.\n");
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="organized_marks_csv_pack.zip"');
        header('Content-Length: ' . (string) filesize($archivePath));
        readfile($archivePath);
        @unlink($archivePath);
        exit;
    }
}

$rows = $departmentId > 0 ? $buildDisplayRows($departmentId, $semesterNo, $markTypeId) : [];
$isLocked = $departmentId > 0 ? mark_section_locked($departmentId, $semesterNo) : false;
$avgPercent = 0;
$passCount = 0;
$failCount = 0;
foreach ($rows as $row) {
    $avgPercent += (float) ($row['percentage'] ?? 0);
    if (($row['result'] ?? '') === 'Pass') {
        $passCount++;
    }
    if (($row['result'] ?? '') === 'Fail') {
        $failCount++;
    }
}
if ($rows) {
    $avgPercent = (int) round($avgPercent / count($rows));
}

render_dashboard_layout('Marks Management', 'admin', 'marks', 'admin/marks.css', 'admin/marks.js', function () use ($departments, $departmentId, $semesterNo, $markTypes, $markTypeId, $selectedMarkType, $assessmentLabel, $rows, $isLocked, $avgPercent, $passCount, $failCount): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card"><p class="eyebrow">Class Students</p><h3 class="stat-value"><?= e((string) count($rows)) ?></h3><p class="stat-label">Students in the selected class</p></article>
        <article class="stat-card"><p class="eyebrow">Average Score</p><h3 class="stat-value"><?= e((string) $avgPercent) ?>%</h3><p class="stat-label">Average for <?= e($assessmentLabel) ?></p></article>
        <article class="stat-card"><p class="eyebrow">Pass / Fail</p><h3 class="stat-value"><?= e((string) $passCount) ?> / <?= e((string) $failCount) ?></h3><p class="stat-label">Result split in the active view</p></article>
        <article class="stat-card"><p class="eyebrow">Mark Entry</p><h3 class="stat-value"><?= e($isLocked ? 'Locked' : 'Open') ?></h3><p class="stat-label">Current admin control for this section</p></article>
    </section>

    <article class="data-card">
        <div class="card-head admin-marks-head">
            <div>
                <p class="eyebrow">Class Filters</p>
                <h3 class="card-title">Aggregate marks by department, semester, and assessment</h3>
                <p class="card-subtitle">
                    Choose <strong><?= e($selectedMarkType ? $assessmentLabel : 'All Assessments') ?></strong>
                    <?= $markTypeId > 0
                        ? ' to review average subject performance against the selected assessment max.'
                        : ' to review the current result status.' ?>
                </p>
            </div>
            <div class="admin-marks-export-actions">
                <a class="btn-secondary" href="<?= e(url('admin/marks.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&mark_type_id=' . $markTypeId . '&export=current_csv')) ?>">Download Current View</a>
                <a class="btn-secondary" href="<?= e(url('admin/marks.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&mark_type_id=' . $markTypeId . '&export=all_csv_pack')) ?>">Download Organized CSV Pack</a>
            </div>
        </div>
        <form method="get" class="filters admin-marks-filter-form" style="margin-bottom:14px">
            <div class="form-group">
                <label class="form-label" for="marks-admin-department">Department</label>
                <select class="form-select" id="marks-admin-department" name="department_id">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="marks-admin-semester">Semester</label>
                <select class="form-select" id="marks-admin-semester" name="semester_no">
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="marks-admin-type">Assessment</label>
                <select class="form-select" id="marks-admin-type" name="mark_type_id">
                    <option value="0" <?= $markTypeId === 0 ? 'selected' : '' ?>>All Assessments</option>
                    <?php foreach ($markTypes as $markType): ?>
                        <option value="<?= e((string) $markType['id']) ?>" <?= $markTypeId === (int) $markType['id'] ? 'selected' : '' ?>><?= e($markType['label']) ?> (/<?= e((string) $markType['max_marks']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-primary" type="submit">Load</button>
        </form>

        <form method="post" class="inline-actions" style="margin-bottom:16px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_lock">
            <input type="hidden" name="department_id" value="<?= e((string) $departmentId) ?>">
            <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
            <input type="hidden" name="mark_type_id" value="<?= e((string) $markTypeId) ?>">
            <input type="hidden" name="mode" value="<?= e($isLocked ? 'unlock' : 'lock') ?>">
            <button class="<?= $isLocked ? 'btn-success' : 'btn-danger' ?>" type="submit">
                <?= e($isLocked ? 'Unlock Marks Entry' : 'Lock Marks Entry') ?>
            </button>
            <span class="badge <?= $isLocked ? 'danger' : 'success' ?>"><?= e($isLocked ? 'Locked by admin' : 'Faculty can enter marks') ?></span>
        </form>

        <?php if ($rows): ?>
            <div class="table-wrap admin-marks-table-wrap">
                <table class="admin-marks-table">
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Name</th>
                        <th><?= e($markTypeId > 0 ? 'Average' : 'Score') ?></th>
                        <th>Max</th>
                        <th>Performance</th>
                        <th>Grade</th>
                        <th>Result</th>
                        <th>Attendance</th>
                        <th><?= e($markTypeId > 0 ? 'Coverage' : 'Scope') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $student = $row['student'];
                        $gradeTone = ($row['grade'] ?? '-') === 'F' ? 'danger' : (($row['grade'] ?? '-') === 'O' ? 'info' : 'success');
                        $result = (string) ($row['result'] ?? 'Pending');
                        $resultTone = $result === 'Pass' ? 'success' : ($result === 'Fail' ? 'danger' : ($result === 'Absent' ? 'warning' : 'info'));
                        $percent = (float) ($row['percentage'] ?? 0);
                        ?>
                        <tr>
                            <td class="mono"><?= e((string) $student['enrollment_no']) ?></td>
                            <td><?= e((string) $student['full_name']) ?></td>
                            <td><?= e((string) ($row['score'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['max'] ?? 0)) ?></td>
                            <td>
                                <div class="metric-row">
                                    <div class="split"><span class="muted"><?= e($markTypeId > 0 ? 'Average' : 'Score') ?></span><strong><?= e((string) round($percent, 2)) ?>%</strong></div>
                                    <div class="progress-bar"><div class="progress-fill" style="width: <?= e((string) min(100, (int) round($percent))) ?>%"></div></div>
                                </div>
                            </td>
                            <td><span class="badge <?= e($gradeTone) ?>"><?= e((string) ($row['grade'] ?? '-')) ?></span></td>
                            <td><span class="badge <?= e($resultTone) ?>"><?= e($result) ?></span></td>
                            <td><span class="badge <?= ($row['attendance']['percentage'] ?? 0) >= 75 ? 'success' : (($row['attendance']['percentage'] ?? 0) >= 60 ? 'warning' : 'danger') ?>"><?= e((string) ($row['attendance']['percentage'] ?? 0)) ?>%</span></td>
                            <td><?= e((string) ($row['coverage'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No marks data is available for the selected class yet.</div>
        <?php endif; ?>
    </article>
    <?php
});
