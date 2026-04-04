<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = require_current_student();
$rows = assignment_rows_for_student((int) $student['id']);

$assignmentGroups = [];
$totalSubmitted = 0;
foreach ($rows as $row) {
    $label = trim((string) ($row['assignment_label'] ?? ''));
    if ($label === '') {
        $label = 'Assignment';
    }

    $status = strtolower((string) ($row['submission_status'] ?? 'pending')) === 'submitted' ? 'submitted' : 'pending';
    if ($status === 'submitted') {
        $totalSubmitted++;
    }

    if (!isset($assignmentGroups[$label])) {
        $assignmentGroups[$label] = [
            'assignment_label' => $label,
            'tracked_count' => 0,
            'submitted_count' => 0,
            'subjects' => [],
        ];
    }

    $assignmentGroups[$label]['tracked_count']++;
    if ($status === 'submitted') {
        $assignmentGroups[$label]['submitted_count']++;
    }
    $assignmentGroups[$label]['subjects'][] = [
        'subject_name' => (string) ($row['subject_name'] ?? 'Subject'),
        'status' => $status,
    ];
}

uksort($assignmentGroups, 'strnatcasecmp');
$assignmentGroups = array_values($assignmentGroups);

$totalTrackers = count($rows);
$totalPending = max(0, $totalTrackers - $totalSubmitted);
$summaryStatus = static function (array $group): array {
    $tracked = (int) ($group['tracked_count'] ?? 0);
    $submitted = (int) ($group['submitted_count'] ?? 0);

    if ($tracked > 0 && $submitted >= $tracked) {
        return ['label' => 'Submitted', 'class' => 'success'];
    }
    if ($submitted > 0) {
        return ['label' => 'In Progress', 'class' => 'info'];
    }

    return ['label' => 'Pending', 'class' => 'warning'];
};

render_dashboard_layout('My Assignments', 'student', 'assignments', 'student/assignments.css', 'student/assignments.js', function () use ($assignmentGroups, $rows, $totalSubmitted, $totalPending, $totalTrackers, $summaryStatus): void {
    ?>
    <?php if ($assignmentGroups): ?>
        <section class="student-assignment-overview">
            <?php foreach ($assignmentGroups as $group): ?>
                <?php $statusMeta = $summaryStatus($group); ?>
                <article class="assignment-overview-card">
                    <span class="assignment-overview-icon"><?= e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $group['assignment_label']) ?: 'A', 0, 1))) ?></span>
                    <h3><?= e((string) $group['assignment_label']) ?></h3>
                    <p class="assignment-overview-count"><?= e((string) ($group['submitted_count'] ?? 0)) ?>/<?= e((string) ($group['tracked_count'] ?? 0)) ?> submitted</p>
                    <span class="status-pill <?= e($statusMeta['class']) ?>"><?= e($statusMeta['label']) ?></span>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <article class="data-card student-assignment-summary-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Submission Summary</p>
                <h3 class="card-title">Track assignment submission status</h3>
            </div>
            <span class="assignment-summary-total"><?= e((string) $totalSubmitted) ?>/<?= e((string) $totalTrackers) ?> Submitted</span>
        </div>

        <?php if ($assignmentGroups): ?>
            <div class="assignment-summary-list">
                <?php foreach ($assignmentGroups as $group): ?>
                    <?php $statusMeta = $summaryStatus($group); ?>
                    <div class="assignment-summary-row">
                        <div class="assignment-summary-main">
                            <h4><?= e((string) $group['assignment_label']) ?></h4>
                            <p class="muted"><?= e((string) ($group['submitted_count'] ?? 0)) ?> of <?= e((string) ($group['tracked_count'] ?? 0)) ?> subjects submitted</p>
                        </div>
                        <span class="status-pill <?= e($statusMeta['class']) ?>"><?= e($statusMeta['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="assignment-summary-metrics">
                <div class="assignment-summary-metric">
                    <span>Submitted</span>
                    <strong><?= e((string) $totalSubmitted) ?></strong>
                </div>
                <div class="assignment-summary-metric">
                    <span>Pending</span>
                    <strong><?= e((string) $totalPending) ?></strong>
                </div>
                <div class="assignment-summary-metric">
                    <span>Total Tracked</span>
                    <strong><?= e((string) $totalTrackers) ?></strong>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">No assignment trackers are available for your class yet.</div>
        <?php endif; ?>
    </article>

    <?php if ($rows): ?>
        <article class="data-card student-assignment-detail-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Course Tracker</p>
                    <h3 class="card-title">Subject-wise assignment details</h3>
                </div>
            </div>

            <div class="assignment-detail-grid">
                <?php foreach ($rows as $row): ?>
                    <?php $isSubmitted = strtolower((string) ($row['submission_status'] ?? 'pending')) === 'submitted'; ?>
                    <article class="assignment-detail-item">
                        <div class="assignment-detail-meta">
                            <p class="eyebrow">Subject</p>
                            <h4><?= e((string) $row['subject_name']) ?></h4>
                        </div>
                        <div class="assignment-detail-meta">
                            <p class="eyebrow">Assignment</p>
                            <p><?= e((string) $row['assignment_label']) ?></p>
                        </div>
                        <span class="status-pill <?= $isSubmitted ? 'success' : 'warning' ?>"><?= e($isSubmitted ? 'Submitted' : 'Pending') ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>
    <?php
});
