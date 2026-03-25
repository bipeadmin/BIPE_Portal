<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (is_post()) {
    $action = (string) post('action');

    try {
        if ($action === 'add_holiday') {
            $scopeType = (string) post('scope_type');
            $departmentId = $scopeType === 'department' ? (int) post('department_id') : null;
            $eventTo = trim((string) post('event_to'));
            add_holiday_event(
                $scopeType,
                $departmentId,
                (string) post('event_from'),
                $eventTo !== '' ? $eventTo : null,
                (string) post('event_type'),
                trim((string) post('title')),
                null,
                (int) current_user()['id']
            );
            flash('success', 'Holiday or event saved successfully.');
        }

        if ($action === 'delete_holiday') {
            delete_holiday_event((int) post('holiday_id'));
            flash('success', 'Holiday record deleted successfully.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('admin/attendance.php');
}

$departments = departments();
$selectedDepartment = (int) ($_GET['department_id'] ?? 0);
$selectedDate = (string) ($_GET['attendance_date'] ?? date('Y-m-d'));

$summaryRows = query_all(
    'SELECT d.id, d.name, d.short_name,
            SUM(CASE WHEN ar.status = "P" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = "A" THEN 1 ELSE 0 END) AS absent_count,
            COUNT(ar.id) AS total_count
     FROM departments d
     LEFT JOIN attendance_sessions ats
        ON ats.department_id = d.id
        AND ats.academic_year_id = :academic_year_id
        AND ats.attendance_date = :attendance_date
     LEFT JOIN attendance_records ar ON ar.attendance_session_id = ats.id
     GROUP BY d.id, d.name, d.short_name
     ORDER BY d.name',
    ['academic_year_id' => current_academic_year_id(), 'attendance_date' => $selectedDate]
);

if ($selectedDepartment > 0) {
    $summaryRows = array_values(array_filter($summaryRows, static fn (array $row): bool => (int) $row['id'] === $selectedDepartment));
}

$holidays = holiday_rows($selectedDepartment > 0 ? $selectedDepartment : null);

render_dashboard_layout('Attendance & Holiday Desk', 'admin', 'attendance', 'admin/attendance.css', 'admin/attendance.js', function () use ($departments, $selectedDepartment, $selectedDate, $summaryRows, $holidays): void {
    ?>
    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Attendance Snapshot</p>
                    <h3 class="card-title">Department attendance for <?= e($selectedDate) ?></h3>
                </div>
            </div>
            <form method="get" class="filters" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="attendance-date">Date</label>
                    <input class="form-input" id="attendance-date" type="date" name="attendance_date" value="<?= e($selectedDate) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="attendance-department">Department</label>
                    <select class="form-select" id="attendance-department" name="department_id">
                        <option value="0">All departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" <?= $selectedDepartment === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-primary" type="submit">Load</button>
            </form>

            <?php if ($summaryRows): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Department</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Total Marked</th>
                            <th>Attendance %</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($summaryRows as $row):
                            $total = (int) $row['total_count'];
                            $present = (int) $row['present_count'];
                            $percentage = $total > 0 ? (int) round(($present / $total) * 100) : 0;
                            ?>
                            <tr>
                                <td><?= e($row['name']) ?></td>
                                <td><?= e((string) $present) ?></td>
                                <td><?= e((string) ((int) $row['absent_count'])) ?></td>
                                <td><?= e((string) $total) ?></td>
                                <td><?= e((string) $percentage) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No attendance records available for the selected date.</div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Holiday / Leave / Festival</p>
                    <h3 class="card-title">Create a non-working day entry</h3>
                </div>
            </div>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="add_holiday">
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="event-from">From</label>
                        <input class="form-input" id="event-from" type="date" name="event_from" value="<?= e($selectedDate) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="event-to">To</label>
                        <input class="form-input" id="event-to" type="date" name="event_to">
                        <p class="form-note">Optional. Leave blank for a one-day event.</p>
                    </div>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="event-type">Type</label>
                        <select class="form-select" id="event-type" name="event_type" required>
                            <option value="Holiday">Holiday</option>
                            <option value="Leave">Leave</option>
                            <option value="Festival">Festival</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="scope-type">Scope</label>
                        <select class="form-select" id="scope-type" name="scope_type">
                            <option value="all">All departments</option>
                            <option value="department">Specific department</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="scope-department">Department</label>
                        <select class="form-select" id="scope-department" name="department_id">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e((string) $department['id']) ?>"><?= e($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="event-title">Title</label>
                        <input class="form-input" id="event-title" name="title" placeholder="Example: Holi Festival" required>
                    </div>
                </div>
                <button class="btn-primary" type="submit">Save Event</button>
            </form>
        </article>
    </section>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Holiday Register</p>
                <h3 class="card-title">Current academic year events</h3>
            </div>
        </div>
        <?php if ($holidays): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Scope</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($holidays as $holiday): ?>
                        <tr>
                            <td><?= e($holiday['event_date']) ?></td>
                            <td><?= e((string) ($holiday['end_date'] ?? '-')) ?></td>
                            <td><?= e((string) holiday_event_total_days($holiday)) ?></td>
                            <td><span class="badge warning"><?= e($holiday['event_type']) ?></span></td>
                            <td><?= e($holiday['title']) ?></td>
                            <td><?= e($holiday['scope_type'] === 'all' ? 'All departments' : ($holiday['department_name'] ?? '-')) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= e((string) $holiday['id']) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this holiday event?">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No holiday, leave, or festival entries exist yet.</div>
        <?php endif; ?>
    </article>
    <?php
});