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
    <article class="data-card audit-log-card">
        <div class="card-head audit-log-head">
            <div>
                <p class="eyebrow">Security Timeline</p>
                <h3 class="card-title">Resolved actors, request context, and direct drilldown access</h3>
                <p class="muted audit-log-intro">Open any entry from its role badge to inspect request metadata, device signals, and the stored activity note in one place.</p>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_audit">
                <button class="btn-danger" type="submit" data-confirm="Clear the complete audit log?">Clear Log</button>
            </form>
        </div>
        <form method="get" class="filters audit-log-filters">
            <div class="form-group">
                <label class="form-label" for="audit-role">Role</label>
                <select class="form-select" id="audit-role" name="role">
                    <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All roles</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Faculty</option>
                    <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="system" <?= $roleFilter === 'system' ? 'selected' : '' ?>>System</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="audit-search">Search</label>
                <input class="search-input" id="audit-search" name="search" value="<?= e($search) ?>" placeholder="Actor, IP, action, device, browser, or path">
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
                        <th>Actor</th>
                        <th>IP / Device</th>
                        <th>Action</th>
                        <th>Summary</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $timestamp = strtotime((string) ($row['created_at'] ?? ''));
                        $dateLabel = $timestamp ? date('Y-m-d', $timestamp) : (string) ($row['created_at'] ?? '-');
                        $timeLabel = $timestamp ? date('H:i:s', $timestamp) : '';
                        $roleLabel = match ((string) ($row['role_name'] ?? '')) {
                            'admin' => 'Admin',
                            'teacher' => 'Faculty',
                            'student' => 'Student',
                            default => 'System',
                        };
                        $roleBadgeClass = ($row['role_name'] ?? '') === 'admin'
                            ? 'danger'
                            : (($row['role_name'] ?? '') === 'teacher'
                                ? 'info'
                                : (($row['role_name'] ?? '') === 'student' ? 'warning' : 'success'));
                        $entryUrl = url('admin/audit_log_entry.php?id=' . (int) $row['id']);
                        ?>
                        <tr class="audit-log-row">
                            <td data-label="Timestamp">
                                <strong><?= e($dateLabel) ?></strong>
                                <?php if ($timeLabel !== ''): ?>
                                    <span class="audit-meta-line mono"><?= e($timeLabel) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Role">
                                <a class="audit-role-link" href="<?= e($entryUrl) ?>" aria-label="Open audit entry for <?= e($roleLabel) ?>">
                                    <span class="badge <?= e($roleBadgeClass) ?>"><?= e($roleLabel) ?></span>
                                </a>
                            </td>
                            <td class="audit-actor-cell" data-label="Actor">
                                <strong><?= e((string) ($row['actor_display_name'] ?? $row['user_identifier'] ?? '-')) ?></strong>
                                <?php if (trim((string) ($row['actor_secondary'] ?? '')) !== ''): ?>
                                    <span class="audit-meta-line mono"><?= e((string) $row['actor_secondary']) ?></span>
                                <?php endif; ?>
                                <?php if (($row['actor_id_resolved'] ?? null) !== null): ?>
                                    <span class="audit-meta-line">ID <?= e((string) $row['actor_id_resolved']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="IP / Device">
                                <strong class="mono"><?= e((string) ($row['ip_address'] ?? '-')) ?></strong>
                                <span class="audit-meta-line"><?= e((string) ($row['device_name'] ?? 'Unknown Device')) ?></span>
                                <span class="audit-meta-line"><?= e(trim((string) (($row['browser_name'] ?? 'Unknown') . ' ' . ($row['browser_version'] ?? '')) . ' • ' . (string) ($row['os_name'] ?? 'Unknown'))) ?></span>
                            </td>
                            <td class="audit-action-cell" data-label="Action">
                                <strong><?= e((string) ($row['action_label'] ?? $row['action_code'] ?? '-')) ?></strong>
                                <span class="audit-meta-line mono"><?= e((string) ($row['action_code'] ?? '-')) ?></span>
                            </td>
                            <td class="audit-details-cell" data-label="Summary">
                                <strong><?= e((string) ($row['details_summary'] ?? $row['details'] ?? '-')) ?></strong>
                                <?php if (trim((string) ($row['request_path'] ?? '')) !== ''): ?>
                                    <span class="audit-meta-line mono"><?= e(trim(((string) ($row['request_method'] ?? 'REQUEST')) . ' ' . (string) $row['request_path'])) ?></span>
                                <?php endif; ?>
                            </td>
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
