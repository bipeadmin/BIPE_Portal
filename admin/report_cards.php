<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$departments = departments();
$markTypes = mark_type_rows();
$departmentId = (int) ($_GET['department_id'] ?? ($departments[0]['id'] ?? 0));
$semesterNo = (int) ($_GET['semester_no'] ?? 2);
$validMarkTypeIds = array_values(array_map(static fn (array $markType): int => (int) $markType['id'], $markTypes));
$markTypeId = (int) ($_GET['mark_type_id'] ?? ($validMarkTypeIds[0] ?? 0));
if (!in_array($markTypeId, $validMarkTypeIds, true)) {
    $markTypeId = $validMarkTypeIds[0] ?? 0;
}
$reportMatrix = ($departmentId > 0 && $semesterNo > 0 && $markTypeId > 0)
    ? class_report_card_matrix_rows($departmentId, $semesterNo, $markTypeId)
    : ['subjects' => [], 'rows' => [], 'mark_type' => null];
$subjects = (array) ($reportMatrix['subjects'] ?? []);
$rows = (array) ($reportMatrix['rows'] ?? []);
$selectedMarkType = $reportMatrix['mark_type'] ?? null;

render_dashboard_layout('Report Cards', 'admin', 'report_cards', 'admin/report_cards.css', 'admin/report_cards.js', function () use ($departments, $departmentId, $semesterNo, $markTypes, $markTypeId, $rows, $subjects, $selectedMarkType): void {
    ?>
    <article class="data-card report-card-shell">
        <div class="card-head report-card-head">
            <div>
                <p class="eyebrow">Printable Results</p>
                <h3 class="card-title">Generate class-wise report cards</h3>
                <p class="card-subtitle">Showing subject-wise averages for <?= e((string) ($selectedMarkType['label'] ?? 'the selected assessment')) ?>, followed by a combined total across the visible subjects.</p>
            </div>
            <div class="report-card-actions">
                <a class="btn-secondary" href="<?= e(url('admin/report_cards_export.php?mode=full')) ?>">Download Total Marks ZIP</a>
                <?php if ($departmentId > 0 && $markTypeId > 0): ?>
                    <a class="btn-secondary" href="<?= e(url('admin/report_cards_export.php?mode=current&department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&mark_type_id=' . $markTypeId)) ?>">Download Current Excel</a>
                    <a class="btn-primary report-card-print-button" href="<?= e(url('admin/report_card_print.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&mark_type_id=' . $markTypeId)) ?>" target="_blank" rel="noreferrer">Open Print View</a>
                <?php endif; ?>
            </div>
        </div>
        <form method="get" class="filters report-card-filters">
            <div class="form-group">
                <label class="form-label" for="report-cards-dept">Department</label>
                <select class="form-select" id="report-cards-dept" name="department_id">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="report-cards-sem">Semester</label>
                <select class="form-select" id="report-cards-sem" name="semester_no">
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="report-cards-type">Assessment</label>
                <select class="form-select" id="report-cards-type" name="mark_type_id">
                    <?php foreach ($markTypes as $markType): ?>
                        <option value="<?= e((string) $markType['id']) ?>" <?= $markTypeId === (int) $markType['id'] ? 'selected' : '' ?>><?= e((string) $markType['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-primary" type="submit">Load Students</button>
        </form>

        <?php if ($rows): ?>
            <div class="table-wrap report-card-table-wrap">
                <table class="report-card-table">
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Name</th>
                        <?php foreach ($subjects as $subject): ?>
                            <?php
                            $subjectShortLabel = subject_short_label($subject);
                            $subjectFullLabel = (string) ($subject['subject_name'] ?? $subjectShortLabel);
                            ?>
                            <th class="subject-column-head" title="<?= e($subjectFullLabel) ?>"><?= e($subjectShortLabel) ?></th>
                        <?php endforeach; ?>
                        <th>Total Marks</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $student = $row['student']; ?>
                        <tr>
                            <td data-label="Enrollment" class="mono"><?= e($student['enrollment_no']) ?></td>
                            <td data-label="Name"><?= e($student['full_name']) ?></td>
                            <?php foreach ((array) ($row['subject_cells'] ?? []) as $cell): ?>
                                <?php
                                $cellShortLabel = (string) ($cell['subject_short_name'] ?? subject_short_label($cell));
                                $cellFullLabel = (string) ($cell['subject_name'] ?? $cellShortLabel);
                                ?>
                                <td data-label="<?= e($cellShortLabel) ?>" title="<?= e($cellFullLabel) ?>" class="subject-score-cell<?= !empty($cell['is_absent']) ? ' is-absent' : ($cell['display'] === '--' ? ' is-empty' : '') ?>">
                                    <?= e((string) ($cell['display'] ?? '--')) ?>
                                </td>
                            <?php endforeach; ?>
                            <td data-label="Total Marks" class="total-marks-cell"><?= e((string) ($row['total_display'] ?? '--')) ?></td>
                            <td data-label="Action" class="action-cell"><a class="btn-secondary report-card-print-link" href="<?= e(url('admin/report_card_print.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&mark_type_id=' . $markTypeId . '&student_id=' . $student['id'])) ?>" target="_blank" rel="noreferrer">Print</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No students, subjects, or assessment records are available for the selected class yet.</div>
        <?php endif; ?>
    </article>
    <?php
});
