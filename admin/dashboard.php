<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$stats = portal_stats();
$departmentCards = [];
foreach (departments() as $department) {
    $departmentId = (int) $department['id'];
    $departmentCards[] = [
        'name' => $department['name'],
        'short_name' => $department['short_name'],
        'theme_color' => $department['theme_color'],
        'student_total' => (int) query_value('SELECT COUNT(*) FROM students WHERE department_id = :department_id', ['department_id' => $departmentId]),
        'teacher_total' => (int) query_value('SELECT COUNT(*) FROM teachers WHERE department_id = :department_id AND status = "approved"', ['department_id' => $departmentId]),
        'attendance_pct' => attendance_percent_for_department($departmentId),
    ];
}

$pendingFaculty = query_all(
    'SELECT t.full_name, t.teacher_code, d.name AS department_name
     FROM teachers t
     INNER JOIN departments d ON d.id = t.department_id
     WHERE t.status = "pending"
     ORDER BY t.registered_at DESC
     LIMIT 5'
);

$recentHolidays = query_all(
    'SELECT h.event_date, h.end_date, h.event_type, h.title,
            COALESCE(d.short_name, "ALL") AS scope_label,
            DATEDIFF(COALESCE(h.end_date, h.event_date), h.event_date) + 1 AS total_days
     FROM holiday_events h
     LEFT JOIN departments d ON d.id = h.department_id
     WHERE h.academic_year_id = :academic_year_id
     ORDER BY h.event_date DESC, COALESCE(h.end_date, h.event_date) DESC
     LIMIT 6',
    ['academic_year_id' => current_academic_year_id()]
);

render_dashboard_layout('Administrator Overview', 'admin', 'dashboard', 'admin/dashboard.css', 'admin/dashboard.js', function () use ($stats, $departmentCards, $pendingFaculty, $recentHolidays): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card">
            <p class="eyebrow">Students</p>
            <h3 class="stat-value"><?= e((string) $stats['students_total']) ?></h3>
            <p class="stat-label">Total enrolled students</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Activated Students</p>
            <h3 class="stat-value"><?= e((string) $stats['registered_students']) ?></h3>
            <p class="stat-label">Students who completed registration</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Active Faculty</p>
            <h3 class="stat-value"><?= e((string) $stats['teachers_active']) ?></h3>
            <p class="stat-label">Approved faculty accounts</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Pending Faculty</p>
            <h3 class="stat-value"><?= e((string) $stats['teachers_pending']) ?></h3>
            <p class="stat-label">Requests waiting for approval</p>
        </article>
    </section>

    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Department Snapshot</p>
                    <h3 class="card-title">BIPE departments at a glance</h3>
                </div>
            </div>
            <div class="stack">
                <?php foreach ($departmentCards as $card): ?>
                    <div class="data-list-item">
                        <div class="split">
                            <div>
                                <strong><?= e($card['name']) ?></strong>
                                <p class="muted"><?= e($card['student_total']) ?> students, <?= e($card['teacher_total']) ?> approved faculty</p>
                            </div>
                            <span class="badge info"><?= e($card['short_name']) ?></span>
                        </div>
                        <div class="metric-row" style="margin-top:10px">
                            <div class="split"><span class="muted">Average attendance</span><strong><?= e((string) $card['attendance_pct']) ?>%</strong></div>
                            <div class="progress-bar"><div class="progress-fill" style="width: <?= e((string) min(100, $card['attendance_pct'])) ?>%; background: <?= e($card['theme_color']) ?>"></div></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <div class="stack">
            <article class="data-card">
                <div class="card-head">
                    <div>
                        <p class="eyebrow">Approval Queue</p>
                        <h3 class="card-title">Pending faculty requests</h3>
                    </div>
                    <a class="btn-secondary" href="<?= e(url('admin/faculty.php')) ?>">Manage</a>
                </div>
                <?php if ($pendingFaculty): ?>
                    <div class="data-list">
                        <?php foreach ($pendingFaculty as $faculty): ?>
                            <div class="data-list-item">
                                <strong><?= e($faculty['full_name']) ?></strong>
                                <p class="muted"><?= e($faculty['teacher_code']) ?> · <?= e($faculty['department_name']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No pending faculty approvals right now.</div>
                <?php endif; ?>
            </article>

            <article class="data-card">
                <div class="card-head">
                    <div>
                        <p class="eyebrow">Calendar</p>
                        <h3 class="card-title">Recent holidays and events</h3>
                    </div>
                    <a class="btn-secondary" href="<?= e(url('admin/attendance.php')) ?>">Open Attendance</a>
                </div>
                <?php if ($recentHolidays): ?>
                    <div class="data-list">
                        <?php foreach ($recentHolidays as $holiday):
                            $days = holiday_event_total_days($holiday);
                            ?>
                            <div class="data-list-item">
                                <div class="split">
                                    <strong><?= e($holiday['title']) ?></strong>
                                    <span class="badge warning"><?= e($holiday['event_type']) ?></span>
                                </div>
                                <p class="muted"><?= e(holiday_event_date_label($holiday)) ?> · <?= e((string) $days) ?> day<?= $days === 1 ? '' : 's' ?> · Scope <?= e($holiday['scope_label']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No holiday records have been added for the current academic year.</div>
                <?php endif; ?>
            </article>
        </div>
    </section>
    <?php
});