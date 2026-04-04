<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = require_current_student();
$records = query_all(
    'SELECT
        mu.id AS upload_id,
        mu.subject_id,
        s.subject_name,
        mu.exam_type,
        mu.max_marks,
        mr.marks_obtained,
        mr.is_absent
     FROM mark_records mr
     INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
     INNER JOIN subjects s ON s.id = mu.subject_id
     WHERE mr.student_id = :student_id
     ORDER BY mu.id DESC',
    ['student_id' => $student['id']]
);

$markTypeMap = [];
foreach (mark_type_rows() as $markType) {
    $label = trim((string) ($markType['label'] ?? ''));
    if ($label === '') {
        continue;
    }

    $markTypeMap[$label] = [
        'label' => $label,
        'max_marks' => (float) ($markType['max_marks'] ?? 0),
    ];
}

foreach ($records as $record) {
    $label = trim((string) ($record['exam_type'] ?? ''));
    if ($label === '' || isset($markTypeMap[$label])) {
        continue;
    }

    $markTypeMap[$label] = [
        'label' => $label,
        'max_marks' => (float) ($record['max_marks'] ?? 0),
    ];
}

$markTypeColumns = array_values($markTypeMap);
$totalPossiblePerSubject = 0.0;
foreach ($markTypeColumns as $column) {
    $totalPossiblePerSubject += (float) ($column['max_marks'] ?? 0);
}

$initializeSubject = static function (int $subjectId, string $subjectName) use ($markTypeColumns, $totalPossiblePerSubject): array {
    $cells = [];
    foreach ($markTypeColumns as $column) {
        $label = (string) ($column['label'] ?? '');
        $cells[$label] = [
            'label' => $label,
            'max_marks' => (float) ($column['max_marks'] ?? 0),
            'display' => '--',
            'numeric_value' => 0.0,
            'is_absent' => false,
            'has_record' => false,
        ];
    }

    return [
        'subject_id' => $subjectId,
        'subject_name' => $subjectName,
        'cells' => $cells,
        'published_count' => 0,
        'total_obtained' => 0.0,
        'total_max' => $totalPossiblePerSubject,
        'grade' => '-',
    ];
};

$subjectReports = [];
foreach (subjects_for((int) $student['department_id'], (int) $student['semester_no']) as $subject) {
    $subjectReports[(int) $subject['id']] = $initializeSubject((int) $subject['id'], (string) $subject['subject_name']);
}

foreach ($records as $record) {
    $subjectId = (int) ($record['subject_id'] ?? 0);
    if ($subjectId <= 0) {
        continue;
    }

    if (!isset($subjectReports[$subjectId])) {
        $subjectReports[$subjectId] = $initializeSubject($subjectId, (string) ($record['subject_name'] ?? 'Subject'));
    }
}

$formatMarks = static function (float $value): string {
    $rounded = round($value, 2);
    if (abs($rounded - round($rounded)) < 0.01) {
        return number_format((float) round($rounded), 0, '.', '');
    }

    return number_format($rounded, 2, '.', '');
};

foreach ($records as $record) {
    $subjectId = (int) ($record['subject_id'] ?? 0);
    $examType = trim((string) ($record['exam_type'] ?? ''));
    if ($subjectId <= 0 || $examType === '' || !isset($subjectReports[$subjectId]['cells'][$examType])) {
        continue;
    }

    if (($subjectReports[$subjectId]['cells'][$examType]['has_record'] ?? false) === true) {
        continue;
    }

    $isAbsent = (int) ($record['is_absent'] ?? 0) === 1;
    $numericValue = $isAbsent ? 0.0 : (float) ($record['marks_obtained'] ?? 0);

    $subjectReports[$subjectId]['cells'][$examType] = [
        'label' => $examType,
        'max_marks' => (float) ($record['max_marks'] ?? $subjectReports[$subjectId]['cells'][$examType]['max_marks']),
        'display' => $isAbsent ? 'AB' : $formatMarks($numericValue),
        'numeric_value' => $numericValue,
        'is_absent' => $isAbsent,
        'has_record' => true,
    ];
}

$overallObtained = 0.0;
$overallMax = 0.0;
$publishedCells = 0;
foreach ($subjectReports as &$report) {
    $totalObtained = 0.0;
    $publishedCount = 0;

    foreach ($markTypeColumns as $column) {
        $label = (string) ($column['label'] ?? '');
        if (!isset($report['cells'][$label])) {
            continue;
        }

        $cell = $report['cells'][$label];
        if ((bool) ($cell['has_record'] ?? false)) {
            $publishedCount++;
        }
        if ((bool) ($cell['has_record'] ?? false) && !(bool) ($cell['is_absent'] ?? false)) {
            $totalObtained += (float) ($cell['numeric_value'] ?? 0);
        }
    }

    $report['published_count'] = $publishedCount;
    $report['total_obtained'] = $totalObtained;
    $report['grade'] = grade_from_marks($totalObtained, (float) ($report['total_max'] ?? 0));

    $overallObtained += $totalObtained;
    $overallMax += (float) ($report['total_max'] ?? 0);
    $publishedCells += $publishedCount;
}
unset($report);

usort($subjectReports, static fn (array $left, array $right): int => strcmp((string) $left['subject_name'], (string) $right['subject_name']));

$subjectCount = count($subjectReports);
$overallPercentage = $overallMax > 0 ? (int) round(($overallObtained / $overallMax) * 100) : 0;
$gradeTone = static function (string $grade): string {
    return $grade === 'F' ? 'danger' : 'info';
};

render_dashboard_layout('My Marks', 'student', 'marks', 'student/marks.css', 'student/marks.js', function () use ($student, $subjectReports, $markTypeColumns, $subjectCount, $overallObtained, $overallMax, $overallPercentage, $publishedCells, $formatMarks, $gradeTone): void {
    ?>
    <section class="stats-grid student-marks-summary">
        <article class="stat-card">
            <p class="eyebrow">Subjects</p>
            <h3 class="stat-value"><?= e((string) $subjectCount) ?></h3>
            <p class="stat-label">Current semester subjects in your report</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Recorded Components</p>
            <h3 class="stat-value"><?= e((string) $publishedCells) ?></h3>
            <p class="stat-label">Published exam components across your subjects</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Overall Score</p>
            <h3 class="stat-value"><?= e($formatMarks($overallObtained)) ?></h3>
            <p class="stat-label">Out of <?= e($formatMarks($overallMax)) ?> · <?= e((string) $overallPercentage) ?>%</p>
        </article>
    </section>

    <article class="data-card student-marks-report">
        <div class="card-head">
            <div>
                <p class="eyebrow">My Internal Marks</p>
                <h3 class="card-title">Subject-wise performance report</h3>
                <p class="card-subtitle"><?= e($student['department_name']) ?> · <?= e(semester_label((int) $student['semester_no'])) ?></p>
            </div>
            <span class="student-marks-count"><?= e((string) $subjectCount) ?> subjects</span>
        </div>

        <?php if ($subjectReports): ?>
            <div class="student-marks-table-wrap">
                <table class="student-marks-matrix">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <?php foreach ($markTypeColumns as $column): ?>
                                <th>
                                    <span><?= e((string) $column['label']) ?></span>
                                    <small>(Max: <?= e($formatMarks((float) ($column['max_marks'] ?? 0))) ?>)</small>
                                </th>
                            <?php endforeach; ?>
                            <th>Total</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjectReports as $report): ?>
                            <tr>
                                <td class="student-marks-subject-cell"><?= e((string) $report['subject_name']) ?></td>
                                <?php foreach ($markTypeColumns as $column): ?>
                                    <?php $cell = $report['cells'][(string) $column['label']] ?? ['display' => '--', 'is_absent' => false, 'has_record' => false]; ?>
                                    <td>
                                        <span class="student-mark-value <?= !(bool) ($cell['has_record'] ?? false) ? 'is-empty' : ((bool) ($cell['is_absent'] ?? false) ? 'is-absent' : '') ?>"><?= e((string) ($cell['display'] ?? '--')) ?></span>
                                    </td>
                                <?php endforeach; ?>
                                <td class="student-mark-total-cell"><?= e($formatMarks((float) ($report['total_obtained'] ?? 0))) ?></td>
                                <td>
                                    <span class="badge <?= e($gradeTone((string) ($report['grade'] ?? '-'))) ?>"><?= e((string) ($report['grade'] ?? '-')) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="student-marks-card-list">
                <?php foreach ($subjectReports as $report): ?>
                    <article class="student-mark-card">
                        <div class="student-mark-card-head">
                            <div>
                                <p class="eyebrow">Subject</p>
                                <h4><?= e((string) $report['subject_name']) ?></h4>
                            </div>
                            <span class="badge <?= e($gradeTone((string) ($report['grade'] ?? '-'))) ?>">Grade <?= e((string) ($report['grade'] ?? '-')) ?></span>
                        </div>

                        <div class="student-mark-card-grid">
                            <?php foreach ($markTypeColumns as $column): ?>
                                <?php $cell = $report['cells'][(string) $column['label']] ?? ['display' => '--', 'is_absent' => false, 'has_record' => false]; ?>
                                <div class="student-mark-metric-card">
                                    <span class="student-mark-metric-label"><?= e((string) $column['label']) ?></span>
                                    <small>Max <?= e($formatMarks((float) ($column['max_marks'] ?? 0))) ?></small>
                                    <strong class="student-mark-value <?= !(bool) ($cell['has_record'] ?? false) ? 'is-empty' : ((bool) ($cell['is_absent'] ?? false) ? 'is-absent' : '') ?>"><?= e((string) ($cell['display'] ?? '--')) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="student-mark-card-footer">
                            <div class="student-mark-footer-item">
                                <span>Total</span>
                                <strong><?= e($formatMarks((float) ($report['total_obtained'] ?? 0))) ?> / <?= e($formatMarks((float) ($report['total_max'] ?? 0))) ?></strong>
                            </div>
                            <div class="student-mark-footer-item">
                                <span>Recorded</span>
                                <strong><?= e((string) ($report['published_count'] ?? 0)) ?> components</strong>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No subjects are configured for your current class yet.</div>
        <?php endif; ?>
    </article>
    <?php
});
