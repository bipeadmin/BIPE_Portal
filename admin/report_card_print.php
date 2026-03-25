<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$departmentId = (int) ($_GET['department_id'] ?? 0);
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
$studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;
$payloads = $departmentId > 0 && $semesterNo > 0 ? class_report_card_payloads($departmentId, $semesterNo, $studentId) : [];
$department = $departmentId > 0 ? department_by_id($departmentId) : null;
$year = current_academic_year();
if ($payloads) {
    audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'REPORT_CARD', $studentId ? 'Student ' . $studentId : 'Department ' . $departmentId . ' semester ' . $semesterNo);
}

render_head('Printable Report Cards', 'admin/report_card_print.css', 'print-body');
?>
<main class="print-shell">
    <div class="print-toolbar no-print">
        <a class="btn-secondary" href="<?= e(url('admin/report_cards.php?department_id=' . $departmentId . '&semester_no=' . $semesterNo)) ?>">Back</a>
        <button class="btn-primary" type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <?php if ($payloads): ?>
        <?php foreach ($payloads as $payload):
            $student = $payload['student'];
            $attendance = $payload['attendance'];
            ?>
            <section class="report-card-sheet">
                <header class="report-card-head">
                    <div class="report-card-brand">
                        <img src="<?= e(url('assets/images/bipe-logo.jpeg')) ?>" alt="BIPE logo">
                        <div>
                            <h1>Banaras Institute of Polytechnic &amp; Engineering</h1>
                            <p>Internal Assessment Report Card</p>
                        </div>
                    </div>
                    <div class="report-card-meta">
                        <div><strong>Academic Year:</strong> <?= e((string) ($year['label'] ?? '-')) ?></div>
                        <div><strong>Department:</strong> <?= e((string) ($department['name'] ?? '-')) ?></div>
                        <div><strong>Semester:</strong> <?= e(semester_label($semesterNo)) ?></div>
                    </div>
                </header>

                <div class="report-card-grid">
                    <div><strong>Student Name:</strong> <?= e($student['full_name']) ?></div>
                    <div><strong>Enrollment No:</strong> <?= e($student['enrollment_no']) ?></div>
                    <div><strong>Class:</strong> <?= e(year_label((int) $student['year_level'])) ?> · <?= e(semester_label((int) $student['semester_no'])) ?></div>
                    <div><strong>Generated:</strong> <?= e(date('d M Y')) ?></div>
                </div>

                <div class="table-wrap report-card-table-wrap">
                    <table class="report-card-table">
                        <thead>
                        <tr>
                            <th>Subject</th>
                            <?php foreach ($payload['mark_types'] as $markType): ?>
                                <th><?= e($markType['label']) ?><br><span class="table-note">/<?= e((string) $markType['max_marks']) ?></span></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                            <th>Max</th>
                            <th>Grade</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payload['rows'] as $row): ?>
                            <tr>
                                <td><?= e($row['subject_name']) ?></td>
                                <?php foreach ($row['marks'] as $mark): ?>
                                    <td class="<?= $mark['display'] === 'AB' ? 'is-absent' : '' ?>"><?= e((string) $mark['display']) ?></td>
                                <?php endforeach; ?>
                                <td><?= e((string) $row['total']) ?></td>
                                <td><?= e((string) $row['max']) ?></td>
                                <td><?= e($row['grade']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="grand-row">
                            <td colspan="<?= e((string) (count($payload['mark_types']) + 1)) ?>">Grand Total</td>
                            <td><?= e((string) $payload['grand_total']) ?></td>
                            <td><?= e((string) $payload['grand_max']) ?></td>
                            <td><?= e($payload['grand_grade']) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="report-card-summary">
                    <div class="summary-box">
                        <span class="summary-label">Attendance</span>
                        <strong><?= e((string) $attendance['percentage']) ?>%</strong>
                        <span class="summary-note"><?= e((string) $attendance['present']) ?> present / <?= e((string) $attendance['total']) ?> classes</span>
                    </div>
                    <div class="summary-box">
                        <span class="summary-label">Overall Grade</span>
                        <strong><?= e($payload['grand_grade']) ?></strong>
                        <span class="summary-note"><?= $payload['grand_grade'] === 'F' ? 'Needs improvement' : 'Academic status satisfactory' ?></span>
                    </div>
                </div>

                <footer class="report-card-signatures">
                    <div><span></span><p>Class Teacher</p></div>
                    <div><span></span><p>HOD / Faculty In-charge</p></div>
                    <div><span></span><p>Principal</p></div>
                </footer>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <section class="data-card">
            <div class="empty-state">No printable report card data is available for the selected filters.</div>
        </section>
    <?php endif; ?>
</main>
<?php render_foot('admin/report_card_print.js');


