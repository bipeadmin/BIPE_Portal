<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (is_post() && (string) post('action') === 'clear_audit') {
    clear_audit_logs();
    flash('success', 'Audit log cleared successfully.');
    redirect_to('admin/audit_log.php');
}

$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$search = trim((string) ($_GET['search'] ?? ''));
$rows = audit_log_rows($roleFilter, $search);

render_dashboard_layout('Audit Log', 'admin', 'audit_log', 'admin/audit_log.css', 'admin/audit_log.js', function () use ($roleFilter, $search, $rows): void {
    ?>
    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Activity Trail</p>
                <h3 class="card-title">Administrator, faculty, and student actions with IP logs</h3>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="clear_audit">
                <button class="btn-danger" type="submit" data-confirm="Clear the complete audit log?">Clear Log</button>
            </form>
        </div>
        <form method="get" class="filters" style="margin-bottom:14px">
            <div class="form-group">
                <label class="form-label" for="audit-role">Role</label>
                <select class="form-select" id="audit-role" name="role">
                    <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All roles</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Faculty</option>
                    <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="audit-search">Search</label>
                <input class="search-input" id="audit-search" name="search" value="<?= e($search) ?>" placeholder="User, IP, action, or details">
            </div>
            <button class="btn-primary" type="submit">Apply Filters</button>
        </form>

        <?php if ($rows): ?>
            <div class="table-wrap audit-log-wrap">
                <table class="audit-log-table">
                    <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Role</th>
                        <th>User</th>
                        <th>IP Address</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr class="audit-log-row">
                            <td data-label="Timestamp"><?= e((string) $row['created_at']) ?></td>
                            <td data-label="Role"><span class="badge <?= $row['role_name'] === 'admin' ? 'danger' : ($row['role_name'] === 'teacher' ? 'info' : 'warning') ?>"><?= e($row['role_name']) ?></span></td>
                            <td class="mono" data-label="User"><?= e($row['user_identifier']) ?></td>
                            <td class="mono" data-label="IP Address"><?= e((string) ($row['ip_address'] ?? '-')) ?></td>
                            <td data-label="Action"><?= e($row['action_code']) ?></td>
                            <td class="audit-details-cell" data-label="Details"><?= e((string) ($row['details'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No audit entries match the selected filters.</div>
        <?php endif; ?>
    </article>
    <?php
});

