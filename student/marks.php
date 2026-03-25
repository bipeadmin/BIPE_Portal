<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('student');

$student = student_by_id((int) current_user()['id']);
$rows = query_all(
    'SELECT
        s.subject_name,
        mu.exam_type,
        mu.max_marks,
        mr.marks_obtained,
        mr.is_absent
     FROM mark_records mr
     INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
     INNER JOIN subjects s ON s.id = mu.subject_id
     WHERE mr.student_id = :student_id
     ORDER BY s.subject_name, mu.exam_type',
    ['student_id' => $student['id']]
);

render_dashboard_layout('My Marks', 'student', 'marks', 'student/marks.css', 'student/marks.js', function () use ($rows): void {
    ?>
    <article class="data-card">
        <div class="card-head"><div><p class="eyebrow">Published Marks</p><h3 class="card-title">Subject-wise marks and grade bands</h3></div></div>
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Subject</th><th>Exam Type</th><th>Marks</th><th>Max Marks</th><th>Grade</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['subject_name']) ?></td>
                            <td><?= e($row['exam_type']) ?></td>
                            <td><?= (int) ($row['is_absent'] ?? 0) === 1 ? 'AB' : e((string) $row['marks_obtained']) ?></td>
                            <td><?= e((string) $row['max_marks']) ?></td>
                            <td>
                                <?php if ((int) ($row['is_absent'] ?? 0) === 1): ?>
                                    <span class="badge warning">AB</span>
                                <?php else: ?>
                                    <span class="badge info"><?= e(grade_from_marks((float) $row['marks_obtained'], (float) $row['max_marks'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No marks have been uploaded for your account yet.</div>
        <?php endif; ?>
    </article>
    <?php
});


