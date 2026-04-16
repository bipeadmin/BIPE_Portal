<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$editId = (int) ($_GET['edit_id'] ?? 0);
$departments = departments();

if (is_post()) {
    $action = (string) post('action');
    $teacherId = (int) post('teacher_id');

    try {
        if ($action === 'approve' && $teacherId > 0) {
            approve_teacher_account($teacherId);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_APPROVED', 'Teacher ID ' . $teacherId);
            flash('success', 'Faculty request approved successfully.');
        }
        if ($action === 'reject' && $teacherId > 0) {
            reject_teacher_account($teacherId);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_REJECTED', 'Teacher ID ' . $teacherId);
            flash('info', 'Faculty request rejected.');
        }
        if ($action === 'archive' && $teacherId > 0) {
            archive_teacher_account($teacherId);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_ARCHIVED', 'Teacher ID ' . $teacherId);
            flash('success', 'Faculty profile removed from the active panel. Existing attendance, marks, assignments, and audit history are preserved.');
        }
        if ($action === 'restore' && $teacherId > 0) {
            restore_teacher_account($teacherId);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_RESTORED', 'Teacher ID ' . $teacherId);
            flash('success', 'Faculty account restored to the active panel.');
        }
        if ($action === 'approve_all') {
            $count = approve_all_pending_teachers();
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_APPROVED', 'Approved all pending faculty (' . $count . ')');
            flash('success', 'Approved ' . $count . ' pending faculty request(s).');
        }
        if ($action === 'reject_all') {
            $count = reject_all_pending_teachers();
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_REJECTED', 'Rejected all pending faculty (' . $count . ')');
            flash('info', 'Rejected ' . $count . ' pending faculty request(s).');
        }
        if ($action === 'purge_rejected') {
            $count = purge_rejected_teachers();
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_DELETE', 'Purged rejected faculty (' . $count . ')');
            flash('success', 'Deleted ' . $count . ' rejected faculty record(s).');
        }
        if ($action === 'edit_teacher' && $teacherId > 0) {
            update_teacher_account(
                $teacherId,
                trim((string) post('full_name')),
                trim((string) post('email')),
                (int) post('department_id'),
                trim((string) post('new_password')) !== '' ? (string) post('new_password') : null
            );
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'FACULTY_UPDATE', 'Teacher ID ' . $teacherId . ' updated');
            flash('success', 'Faculty record updated successfully.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('admin/faculty.php');
}

$groups = faculty_groups();
$editingTeacher = $editId > 0 ? teacher_by_id($editId) : null;

render_dashboard_layout('Faculty Approval & Records', 'admin', 'faculty', 'admin/faculty.css', 'admin/faculty.js', function () use ($groups, $editingTeacher, $departments): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card">
            <p class="eyebrow">Pending</p>
            <h3 class="stat-value"><?= e((string) count($groups['pending'])) ?></h3>
            <p class="stat-label">Faculty requests awaiting review</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Approved</p>
            <h3 class="stat-value"><?= e((string) count($groups['approved'])) ?></h3>
            <p class="stat-label">Active faculty accounts</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Rejected</p>
            <h3 class="stat-value"><?= e((string) count($groups['rejected'])) ?></h3>
            <p class="stat-label">Rejected requests kept for audit</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Archived</p>
            <h3 class="stat-value"><?= e((string) count($groups['archived'])) ?></h3>
            <p class="stat-label">Removed from active use while keeping activity history</p>
        </article>
    </section>

    <?php if ($editingTeacher): ?>
        <article class="data-card">
            <div class="card-head">
                <div><p class="eyebrow">Edit Faculty</p><h3 class="card-title">Update account details for <?= e($editingTeacher['teacher_code']) ?></h3></div>
                <a class="btn-secondary" href="<?= e(url('admin/faculty.php')) ?>">Cancel</a>
            </div>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="edit_teacher">
                <input type="hidden" name="teacher_id" value="<?= e((string) $editingTeacher['id']) ?>">
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input class="form-input" name="full_name" value="<?= e($editingTeacher['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-input" name="email" type="email" value="<?= e($editingTeacher['email']) ?>" required>
                    </div>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department_id" required>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e((string) $department['id']) ?>" <?= (int) $editingTeacher['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input class="form-input" name="new_password" type="password" placeholder="Leave blank to keep current password">
                    </div>
                </div>
                <button class="btn-primary" type="submit">Save Faculty</button>
            </form>
        </article>
    <?php endif; ?>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Approval Queue</p>
                <h3 class="card-title">Pending faculty registrations</h3>
            </div>
            <div class="inline-actions">
                <form method="post">
                    <input type="hidden" name="action" value="approve_all">
                    <button class="btn-primary" type="submit">Approve All</button>
                </form>
                <form method="post">
                    <input type="hidden" name="action" value="reject_all">
                    <button class="btn-danger" type="submit" data-confirm="Reject every pending faculty request?">Reject All</button>
                </form>
            </div>
        </div>
        <?php if ($groups['pending']): ?>
            <div class="table-wrap faculty-table-wrap faculty-card-table-wrap">
                <table class="faculty-table faculty-card-table faculty-requests-table">
                    <thead>
                    <tr>
                        <th>Faculty ID</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups['pending'] as $teacher): ?>
                        <tr>
                            <td class="mono" data-label="Faculty ID"><?= e($teacher['teacher_code']) ?></td>
                            <td data-label="Name"><?= e($teacher['full_name']) ?></td>
                            <td data-label="Department"><?= e($teacher['department_name']) ?></td>
                            <td class="faculty-email-cell" data-label="Email"><?= e($teacher['email']) ?></td>
                            <td data-label="Registered"><?= e((string) $teacher['registered_at']) ?></td>
                            <td data-label="Actions">
                                <div class="actions-cell">
                                    <a class="btn-secondary" href="<?= e(url('admin/faculty.php?edit_id=' . $teacher['id'])) ?>">Edit</a>
                                    <form method="post">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="teacher_id" value="<?= e((string) $teacher['id']) ?>">
                                        <button class="btn-primary" type="submit">Approve</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="teacher_id" value="<?= e((string) $teacher['id']) ?>">
                                        <button class="btn-danger" type="submit">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No faculty requests are waiting for approval.</div>
        <?php endif; ?>
    </article>

    <section class="faculty-records-stack">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Active Faculty</p>
                    <h3 class="card-title">Approved accounts</h3>
                </div>
            </div>
            <?php if ($groups['approved']): ?>
                <div class="table-wrap faculty-table-wrap active-faculty-table-wrap">
                    <table class="faculty-table active-faculty-table">
                        <thead>
                        <tr>
                            <th>Faculty ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groups['approved'] as $teacher): ?>
                            <tr>
                                <td class="mono" data-label="Faculty ID"><?= e($teacher['teacher_code']) ?></td>
                                <td data-label="Name"><?= e($teacher['full_name']) ?></td>
                                <td data-label="Department"><?= e($teacher['department_name']) ?></td>
                                <td class="faculty-email-cell" data-label="Email"><?= e($teacher['email']) ?></td>
                                <td data-label="Actions">
                                    <div class="actions-cell">
                                        <a class="btn-secondary" href="<?= e(url('admin/faculty.php?edit_id=' . $teacher['id'])) ?>">Edit</a>
                                        <form method="post">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="teacher_id" value="<?= e((string) $teacher['id']) ?>">
                                            <button class="btn-danger" type="submit" data-confirm="Remove this faculty account from the active panel? Attendance, marks, assignments, and audit history will stay available.">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No approved faculty accounts yet.</div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Archived Faculty</p>
                    <h3 class="card-title">Removed from active use, history preserved</h3>
                </div>
            </div>
            <?php if ($groups['archived']): ?>
                <div class="table-wrap faculty-table-wrap faculty-card-table-wrap faculty-archived-table-wrap">
                    <table class="faculty-table faculty-card-table faculty-archived-table">
                        <thead>
                        <tr>
                            <th>Faculty ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Email</th>
                            <th>Archived</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groups['archived'] as $teacher): ?>
                            <tr>
                                <td class="mono" data-label="Faculty ID"><?= e($teacher['teacher_code']) ?></td>
                                <td data-label="Name"><?= e($teacher['full_name']) ?></td>
                                <td data-label="Department"><?= e($teacher['department_name']) ?></td>
                                <td class="faculty-email-cell" data-label="Email"><?= e($teacher['email']) ?></td>
                                <td data-label="Archived"><?= e((string) ($teacher['archived_at'] ?? '-')) ?></td>
                                <td data-label="Action">
                                    <div class="actions-cell">
                                        <form method="post">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="teacher_id" value="<?= e((string) $teacher['id']) ?>">
                                            <button class="btn-primary" type="submit">Restore</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No faculty accounts have been archived yet.</div>
            <?php endif; ?>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Rejected</p>
                    <h3 class="card-title">Rejected requests</h3>
                </div>
                <?php if ($groups['rejected']): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="purge_rejected">
                        <button class="btn-danger" type="submit" data-confirm="Delete all rejected faculty records permanently?">Purge Rejected</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($groups['rejected']): ?>
                <div class="table-wrap faculty-table-wrap faculty-card-table-wrap faculty-rejected-table-wrap">
                    <table class="faculty-table faculty-card-table faculty-rejected-table">
                        <thead>
                        <tr>
                            <th>Faculty ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Email</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groups['rejected'] as $teacher): ?>
                            <tr>
                                <td class="mono" data-label="Faculty ID"><?= e($teacher['teacher_code']) ?></td>
                                <td data-label="Name"><?= e($teacher['full_name']) ?></td>
                                <td data-label="Department"><?= e($teacher['department_name']) ?></td>
                                <td class="faculty-email-cell" data-label="Email"><?= e($teacher['email']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No rejected faculty requests to review.</div>
            <?php endif; ?>
        </article>
    </section>
    <?php
});