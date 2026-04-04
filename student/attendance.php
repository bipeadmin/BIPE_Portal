<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = require_current_student();
$summary = attendance_summary_for_student((int) $student['id']);
$rows = query_all(
    'SELECT ats.attendance_date, ar.status, ats.remarks, t.full_name AS teacher_name
     FROM attendance_records ar
     INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
     INNER JOIN teachers t ON t.id = ats.teacher_id
     WHERE ar.student_id = :student_id
     ORDER BY ats.attendance_date DESC, ar.id DESC',
    ['student_id' => $student['id']]
);

$percentage = max(0, min(100, (int) ($summary['percentage'] ?? 0)));
$statusTone = 'danger';
$statusLabel = 'Critical';
$statusNote = 'Regular attendance is needed to stay on track in every class.';

if ((int) ($summary['total'] ?? 0) === 0) {
    $statusTone = 'info';
    $statusLabel = 'Awaiting Records';
    $statusNote = 'Attendance will appear here once your faculty starts publishing class records.';
} elseif ($percentage >= 85) {
    $statusTone = 'success';
    $statusLabel = 'Excellent';
    $statusNote = 'Your attendance is in a strong position. Keep the same consistency.';
} elseif ($percentage >= 75) {
    $statusTone = 'info';
    $statusLabel = 'Stable';
    $statusNote = 'Your attendance is healthy, but staying regular will help maintain it.';
} elseif ($percentage >= 60) {
    $statusTone = 'warning';
    $statusLabel = 'Watchlist';
    $statusNote = 'A few more absences can pull your attendance down quickly. Stay careful.';
}

$recentRows = array_slice($rows, 0, 10);
$formatDate = static function (string $date): string {
    $timestamp = strtotime($date);

    return $timestamp !== false ? date('d M Y', $timestamp) : $date;
};

render_dashboard_layout('My Attendance', 'student', 'attendance', 'student/attendance.css', 'student/attendance.js', function () use ($student, $summary, $rows, $recentRows, $percentage, $statusTone, $statusLabel, $statusNote, $formatDate): void {
    $latestRow = $recentRows[0] ?? null;
    ?>
    <section class="student-attendance-shell">
        <article class="data-card attendance-hero-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Attendance Overview</p>
                    <h3 class="card-title">Overall attendance snapshot</h3>
                    <p class="card-subtitle"><?= e($student['department_name']) ?> · <?= e(semester_label((int) $student['semester_no'])) ?></p>
                </div>
                <span class="status-pill <?= e($statusTone) ?>"><?= e($statusLabel) ?></span>
            </div>

            <div class="attendance-hero-grid">
                <div class="attendance-ring-panel">
                    <div class="attendance-ring" style="--attendance-progress: <?= e((string) $percentage) ?>%;">
                        <div class="attendance-ring-inner">
                            <span class="attendance-ring-caption">Overall</span>
                            <strong><?= e((string) $percentage) ?>%</strong>
                        </div>
                    </div>

                    <div class="attendance-ring-summary">
                        <strong><?= e((string) $percentage) ?>%</strong>
                        <p>Overall Attendance</p>
                    </div>

                    <span class="attendance-status-pill <?= e($statusTone) ?>"><?= e($statusLabel) ?></span>
                </div>

                <div class="attendance-breakdown-panel">
                    <div class="attendance-breakdown-row">
                        <span>Present</span>
                        <strong><?= e((string) ($summary['present'] ?? 0)) ?></strong>
                    </div>
                    <div class="attendance-breakdown-row">
                        <span>Absent</span>
                        <strong><?= e((string) ($summary['absent'] ?? 0)) ?></strong>
                    </div>
                    <div class="attendance-breakdown-row">
                        <span>Total Days</span>
                        <strong><?= e((string) ($summary['total'] ?? 0)) ?></strong>
                    </div>
                    <div class="attendance-breakdown-note">
                        <p class="attendance-breakdown-heading">Status Note</p>
                        <p><?= e($statusNote) ?></p>
                    </div>
                </div>
            </div>
        </article>

        <article class="data-card attendance-insight-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Latest Update</p>
                    <h3 class="card-title">Most recent attendance record</h3>
                </div>
            </div>

            <?php if ($latestRow): ?>
                <div class="attendance-highlight-card <?= $latestRow['status'] === 'P' ? 'is-present' : 'is-absent' ?>">
                    <span class="badge <?= $latestRow['status'] === 'P' ? 'success' : 'danger' ?>"><?= e($latestRow['status'] === 'P' ? 'Present' : 'Absent') ?></span>
                    <h4><?= e($formatDate((string) $latestRow['attendance_date'])) ?></h4>
                    <p class="muted">Marked by <?= e((string) $latestRow['teacher_name']) ?></p>
                    <p class="attendance-highlight-text"><?= e(trim((string) ($latestRow['remarks'] ?? '')) !== '' ? (string) $latestRow['remarks'] : 'No remarks were added for the latest class record.') ?></p>
                </div>
            <?php else: ?>
                <div class="empty-state">No attendance has been published for your account yet.</div>
            <?php endif; ?>
        </article>
    </section>

    <article class="data-card attendance-history-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Attendance Timeline</p>
                <h3 class="card-title">Class-by-class attendance history</h3>
            </div>
            <span class="attendance-history-count"><?= e((string) count($rows)) ?> records</span>
        </div>

        <?php if ($rows): ?>
            <div class="attendance-history-list">
                <?php foreach ($rows as $row): ?>
                    <?php $remarks = trim((string) ($row['remarks'] ?? '')); ?>
                    <article class="attendance-history-item">
                        <div class="attendance-history-top">
                            <div>
                                <p class="attendance-history-date"><?= e($formatDate((string) $row['attendance_date'])) ?></p>
                                <p class="muted">Faculty: <?= e((string) $row['teacher_name']) ?></p>
                            </div>
                            <span class="badge <?= $row['status'] === 'P' ? 'success' : 'danger' ?>"><?= e($row['status'] === 'P' ? 'Present' : 'Absent') ?></span>
                        </div>
                        <p class="attendance-history-remarks <?= $remarks === '' ? 'is-empty' : '' ?>">
                            <?= e($remarks !== '' ? $remarks : 'No remarks added for this class.') ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No attendance history is available right now.</div>
        <?php endif; ?>
    </article>
    <?php
});
