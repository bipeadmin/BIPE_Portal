<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = student_by_id((int) current_user()['id']);
$rows = assignment_rows_for_student((int) $student['id']);

render_dashboard_layout('My Assignments', 'student', 'assignments', 'student/assignments.css', 'student/assignments.js', function () use ($rows): void {
    ?>
    <article class="data-card">
        <div class="card-head"><div><p class="eyebrow">Assignment Status</p><h3 class="card-title">Subject-wise submission tracker</h3></div></div>
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Subject</th><th>Assignment</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['subject_name']) ?></td>
                            <td><?= e($row['assignment_label']) ?></td>
                            <td><span class="badge <?= ($row['submission_status'] ?? 'pending') === 'submitted' ? 'success' : 'warning' ?>"><?= e(($row['submission_status'] ?? 'pending') === 'submitted' ? 'Submitted' : 'Pending') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No assignment trackers are available for your class yet.</div>
        <?php endif; ?>
    </article>
    <?php
});




