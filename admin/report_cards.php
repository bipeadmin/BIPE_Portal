<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? ($departments[0]['id'] ?? 0));
$semesterNo = (int) ($_GET['semester_no'] ?? 2);
$rows = $departmentId > 0 ? class_marks_overview_rows($departmentId, $semesterNo) : [];

render_dashboard_layout('Report Cards', 'admin', 'report_cards', 'admin/report_cards.css', 'admin/report_cards.js', function () use ($departments, $departmentId, $semesterNo, $rows): void {
    ?>
    <article class="data-card report-card-shell">
        <div class="card-head report-card-head">
            <div>
                <p class="eyebrow">Printable Results</p>
                <h3 class="card-title">Generate class-wise report cards</h3>
            </div>
            <?php if ($departmentId > 0): ?>
                <a class="btn-primary report-card-print-button" href="<?= e(url('admin/report_card_print.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo)) ?>" target="_blank" rel="noreferrer">Open Print View</a>
            <?php endif; ?>
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
            <button class="btn-primary" type="submit">Load Students</button>
        </form>

        <?php if ($rows): ?>
            <div class="table-wrap report-card-table-wrap">
                <table class="report-card-table">
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Name</th>
                        <th>Total</th>
                        <th>Attendance</th>
                        <th>Grade</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $student = $row['student'];
                        ?>
                        <tr>
                            <td data-label="Enrollment" class="mono"><?= e($student['enrollment_no']) ?></td>
                            <td data-label="Name"><?= e($student['full_name']) ?></td>
                            <td data-label="Total"><?= e((string) $row['grand_total']) ?> / <?= e((string) $row['grand_max']) ?></td>
                            <td data-label="Attendance"><span class="badge <?= $row['attendance']['percentage'] >= 75 ? 'success' : ($row['attendance']['percentage'] >= 60 ? 'warning' : 'danger') ?>"><?= e((string) $row['attendance']['percentage']) ?>%</span></td>
                            <td data-label="Grade"><span class="badge <?= $row['grade'] === 'F' ? 'danger' : ($row['grade'] === 'O' ? 'info' : 'success') ?>"><?= e($row['grade']) ?></span></td>
                            <td data-label="Action" class="action-cell"><a class="btn-secondary report-card-print-link" href="<?= e(url('admin/report_card_print.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&student_id=' . $student['id'])) ?>" target="_blank" rel="noreferrer">Print</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No students or marks are available for this class yet.</div>
        <?php endif; ?>
    </article>
    <?php
});
