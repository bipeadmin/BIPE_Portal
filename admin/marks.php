<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? ($departments[0]['id'] ?? 0));
$semesterNo = (int) ($_GET['semester_no'] ?? 2);

if (is_post()) {
    try {
        if ((string) post('action') === 'toggle_lock') {
            $departmentId = (int) post('department_id');
            $semesterNo = (int) post('semester_no');
            $lock = ((string) post('mode')) === 'lock';
            set_mark_section_lock($departmentId, $semesterNo, $lock);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), $lock ? 'MARKS_LOCK' : 'MARKS_UNLOCK', 'Department ' . $departmentId . ' semester ' . $semesterNo);
            flash('success', $lock ? 'Marks section locked successfully.' : 'Marks section unlocked successfully.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('admin/marks.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $departmentId > 0) {
    $rows = class_marks_overview_rows($departmentId, $semesterNo);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="marks_overview_' . $departmentId . '_sem' . $semesterNo . '.csv"');
    echo "Enrollment,Name,Department,Semester,Grand Total,Grand Max,Percentage,Grade,Attendance\n";
    foreach ($rows as $row) {
        $student = $row['student'];
        $pct = $row['grand_max'] > 0 ? round(($row['grand_total'] / $row['grand_max']) * 100, 2) : 0;
        echo implode(',', [
            '"' . str_replace('"', '""', (string) $student['enrollment_no']) . '"',
            '"' . str_replace('"', '""', (string) $student['full_name']) . '"',
            '"' . str_replace('"', '""', (string) $student['department_name']) . '"',
            '"Semester ' . $student['semester_no'] . '"',
            (string) $row['grand_total'],
            (string) $row['grand_max'],
            (string) $pct,
            (string) $row['grade'],
            (string) $row['attendance']['percentage'],
        ]) . "\n";
    }
    exit;
}

$rows = $departmentId > 0 ? class_marks_overview_rows($departmentId, $semesterNo) : [];
$isLocked = $departmentId > 0 ? mark_section_locked($departmentId, $semesterNo) : false;
$avgPercent = 0;
if ($rows) {
    $avgPercent = (int) round(array_sum(array_map(static fn (array $row): float => $row['grand_max'] > 0 ? ($row['grand_total'] / $row['grand_max']) * 100 : 0, $rows)) / count($rows));
}

render_dashboard_layout('Marks Management', 'admin', 'marks', 'admin/marks.css', 'admin/marks.js', function () use ($departments, $departmentId, $semesterNo, $rows, $isLocked, $avgPercent): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card"><p class="eyebrow">Class Students</p><h3 class="stat-value"><?= e((string) count($rows)) ?></h3><p class="stat-label">Students in the selected class</p></article>
        <article class="stat-card"><p class="eyebrow">Average Score</p><h3 class="stat-value"><?= e((string) $avgPercent) ?>%</h3><p class="stat-label">Class average across stored mark uploads</p></article>
        <article class="stat-card"><p class="eyebrow">Mark Entry</p><h3 class="stat-value"><?= e($isLocked ? 'Locked' : 'Open') ?></h3><p class="stat-label">Current admin control for this section</p></article>
    </section>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Class Filters</p>
                <h3 class="card-title">Aggregate marks by department and semester</h3>
            </div>
            <?php if ($departmentId > 0): ?>
                <a class="btn-secondary" href="<?= e(url('admin/marks.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo . '&export=csv')) ?>">Export CSV</a>
            <?php endif; ?>
        </div>
        <form method="get" class="filters" style="margin-bottom:14px">
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
            <button class="btn-primary" type="submit">Load</button>
        </form>

        <form method="post" class="inline-actions" style="margin-bottom:16px">
            <input type="hidden" name="action" value="toggle_lock">
            <input type="hidden" name="department_id" value="<?= e((string) $departmentId) ?>">
            <input type="hidden" name="semester_no" value="<?= e((string) $semesterNo) ?>">
            <input type="hidden" name="mode" value="<?= e($isLocked ? 'unlock' : 'lock') ?>">
            <button class="<?= $isLocked ? 'btn-success' : 'btn-danger' ?>" type="submit">
                <?= e($isLocked ? 'Unlock Marks Entry' : 'Lock Marks Entry') ?>
            </button>
            <span class="badge <?= $isLocked ? 'danger' : 'success' ?>"><?= e($isLocked ? 'Locked by admin' : 'Faculty can enter marks') ?></span>
        </form>

        <?php if ($rows): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Name</th>
                        <th>Total</th>
                        <th>Max</th>
                        <th>Performance</th>
                        <th>Grade</th>
                        <th>Attendance</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $student = $row['student'];
                        $percent = $row['grand_max'] > 0 ? (int) round(($row['grand_total'] / $row['grand_max']) * 100) : 0;
                        $gradeTone = $row['grade'] === 'F' ? 'danger' : ($row['grade'] === 'O' ? 'info' : 'success');
                        ?>
                        <tr>
                            <td class="mono"><?= e($student['enrollment_no']) ?></td>
                            <td><?= e($student['full_name']) ?></td>
                            <td><?= e((string) $row['grand_total']) ?></td>
                            <td><?= e((string) $row['grand_max']) ?></td>
                            <td>
                                <div class="metric-row">
                                    <div class="split"><span class="muted">Score</span><strong><?= e((string) $percent) ?>%</strong></div>
                                    <div class="progress-bar"><div class="progress-fill" style="width: <?= e((string) min(100, $percent)) ?>%"></div></div>
                                </div>
                            </td>
                            <td><span class="badge <?= e($gradeTone) ?>"><?= e($row['grade']) ?></span></td>
                            <td><span class="badge <?= $row['attendance']['percentage'] >= 75 ? 'success' : ($row['attendance']['percentage'] >= 60 ? 'warning' : 'danger') ?>"><?= e((string) $row['attendance']['percentage']) ?>%</span></td>
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


