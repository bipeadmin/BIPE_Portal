<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (is_post()) {
    $action = (string) post('action');

    try {
        if ($action === 'change_password') {
            $newPassword = (string) post('new_password');
            $confirmPassword = (string) post('confirm_password');
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('Password confirmation does not match.');
            }
            if (!change_admin_password((int) current_user()['id'], (string) post('current_password'), $newPassword)) {
                throw new RuntimeException('Current password is incorrect.');
            }
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'PASSWORD_CHANGE', 'Administrator password updated');
            flash('success', 'Administrator password updated successfully.');
        }

        if ($action === 'new_year') {
            create_academic_year(trim((string) post('label')), (string) post('rollover_mode'));
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'ACADEMIC_YEAR', 'New academic year created');
            flash('success', 'Academic year changed successfully.');
        }

        if ($action === 'add_mark_type') {
            add_mark_type_row(trim((string) post('label')), (float) post('max_marks'));
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'MARK_TYPE_ADD', trim((string) post('label')));
            flash('success', 'Mark type added successfully.');
        }

        if ($action === 'delete_mark_type') {
            delete_mark_type_row((int) post('mark_type_id'));
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'MARK_TYPE_DELETE', 'Mark type ID ' . (int) post('mark_type_id'));
            flash('success', 'Mark type deleted successfully.');
        }

        if ($action === 'toggle_lock') {
            $departmentId = (int) post('department_id');
            $semesterNo = (int) post('semester_no');
            $lock = ((string) post('mode')) === 'lock';
            set_mark_section_lock($departmentId, $semesterNo, $lock);
            flash('success', $lock ? 'Section locked successfully.' : 'Section unlocked successfully.');
        }

        if ($action === 'lock_all') {
            set_all_mark_sections_lock(true);
            flash('success', 'All department-semester sections locked.');
        }

        if ($action === 'unlock_all') {
            set_all_mark_sections_lock(false);
            flash('success', 'All department-semester sections unlocked.');
        }

        if ($action === 'reset_portal') {
            if (trim((string) post('reset_phrase')) !== 'RESET') {
                throw new RuntimeException('Type RESET exactly before running the full portal reset.');
            }
            reset_portal();
            execute_sql('DELETE FROM password_reset_otps');
            execute_sql('DELETE FROM audit_logs');
            execute_sql('DELETE FROM mark_locks');
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'DATA_RESET', 'Portal reset complete');
            flash('success', 'Portal data reset complete. Administrators, departments, subjects, mark types, and academic years were preserved.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception);
    }

    redirect_to('admin/settings.php');
}

$markTypes = mark_type_rows();
$lockedKeys = array_flip(locked_mark_keys());
$departments = departments();

render_dashboard_layout('Portal Settings', 'admin', 'settings', 'admin/settings.css', 'admin/settings.js', function () use ($markTypes, $lockedKeys, $departments): void {
    ?>
    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Password</p>
                    <h3 class="card-title">Change administrator password</h3>
                </div>
                <a class="btn-secondary" href="<?= e(url('admin/forgot.php')) ?>">OTP Reset</a>
            </div>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label" for="current-password-settings">Current Password</label>
                    <input class="form-input" id="current-password-settings" name="current_password" type="password" required>
                </div>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="new-password-settings">New Password</label>
                        <input class="form-input" id="new-password-settings" name="new_password" type="password" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirm-password-settings">Confirm Password</label>
                        <input class="form-input" id="confirm-password-settings" name="confirm_password" type="password" required>
                    </div>
                </div>
                <button class="btn-primary" type="submit">Update Password</button>
            </form>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Academic Year</p>
                    <h3 class="card-title">Start a new academic cycle</h3>
                </div>
            </div>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="new_year">
                <div class="form-group">
                    <label class="form-label" for="academic-label">Academic Year Label</label>
                    <input class="form-input" id="academic-label" name="label" placeholder="Example: 2026-2027" required>
                </div>
                <div class="radio-list">
                    <div class="option-card"><label><input type="radio" name="rollover_mode" value="keep_all" checked> <span><strong>Keep all data</strong><br><span class="muted">Preserve students, attendance, marks, assignments, and faculty records.</span></span></label></div>
                    <div class="option-card"><label><input type="radio" name="rollover_mode" value="clear_academic"> <span><strong>Keep students, clear academic activity</strong><br><span class="muted">Retain roster and accounts but clear attendance, marks, assignments, and holiday events.</span></span></label></div>
                    <div class="option-card"><label><input type="radio" name="rollover_mode" value="clear_all"> <span><strong>Clear all student and academic data</strong><br><span class="muted">Create the new year and remove students plus all related activity data.</span></span></label></div>
                </div>
                <button class="btn-primary" type="submit">Create New Academic Year</button>
            </form>
        </article>
    </section>

    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Mark Types</p>
                    <h3 class="card-title">Manage internal assessment categories</h3>
                </div>
            </div>
            <form method="post" class="form-grid" style="margin-bottom:16px">
                <input type="hidden" name="action" value="add_mark_type">
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="mark-type-label">Label</label>
                        <input class="form-input" id="mark-type-label" name="label" placeholder="Example: Unit Test" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mark-type-max">Max Marks</label>
                        <input class="form-input" id="mark-type-max" name="max_marks" type="number" min="1" step="0.01" required>
                    </div>
                </div>
                <button class="btn-primary" type="submit">Add Mark Type</button>
            </form>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Label</th><th>Max Marks</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($markTypes as $markType): ?>
                        <tr>
                            <td><?= e($markType['label']) ?></td>
                            <td><?= e((string) $markType['max_marks']) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="delete_mark_type">
                                    <input type="hidden" name="mark_type_id" value="<?= e((string) $markType['id']) ?>">
                                    <button class="btn-danger" type="submit" data-confirm="Delete this mark type? Existing mark uploads will remain stored by exam label.">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <!-- <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">OTP Delivery</p>
                    <h3 class="card-title">Password reset behavior</h3>
                </div>
            </div>
            <div class="stack">
                <div class="data-list-item"><strong>Administrator email</strong><p class="muted">bipevns@gmail.com</p></div>
            </div>
        </article> -->
    </section>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Marks Lock Matrix</p>
                <h3 class="card-title">Lock or unlock mark entry by class</h3>
            </div>
            <div class="inline-actions">
                <form method="post"><input type="hidden" name="action" value="lock_all"><button class="btn-danger" type="submit">Lock All</button></form>
                <form method="post"><input type="hidden" name="action" value="unlock_all"><button class="btn-success" type="submit">Unlock All</button></form>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Department</th><th>Semester 2</th><th>Semester 4</th><th>Semester 6</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $department): ?>
                    <tr>
                        <td><?= e($department['name']) ?></td>
                        <?php foreach (semester_numbers() as $semester):
                            $key = (int) $department['id'] . '|' . $semester;
                            $locked = isset($lockedKeys[$key]);
                            ?>
                            <td>
                                <form method="post" class="inline-actions">
                                    <input type="hidden" name="action" value="toggle_lock">
                                    <input type="hidden" name="department_id" value="<?= e((string) $department['id']) ?>">
                                    <input type="hidden" name="semester_no" value="<?= e((string) $semester) ?>">
                                    <input type="hidden" name="mode" value="<?= e($locked ? 'unlock' : 'lock') ?>">
                                    <span class="badge <?= $locked ? 'danger' : 'success' ?>"><?= $locked ? 'Locked' : 'Open' ?></span>
                                    <button class="btn-secondary" type="submit"><?= $locked ? 'Unlock' : 'Lock' ?></button>
                                </form>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Danger Zone</p>
                <h3 class="card-title">Reset the portal database</h3>
            </div>
        </div>
        <div class="notice-box danger" style="margin-bottom:14px">
            This action removes students, faculty accounts, OTP requests, attendance, marks, assignments, holiday records, and audit logs. Administrators, departments, subjects, mark types, and academic years are kept.
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="reset_portal">
            <div class="form-group">
                <label class="form-label" for="reset-phrase">Type RESET to continue</label>
                <input class="form-input mono" id="reset-phrase" name="reset_phrase" placeholder="RESET" required>
            </div>
            <button class="btn-danger" type="submit" data-confirm="This will permanently clear the new portal data. Continue?">Run Full Reset</button>
        </form>
    </article>
    <?php
});



