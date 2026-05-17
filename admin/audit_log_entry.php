<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$logId = (int) ($_GET['id'] ?? 0);
$entry = $logId > 0 ? audit_log_entry($logId) : null;
if (!$entry) {
    flash('error', 'Audit entry not found.');
    redirect_to('admin/audit_log.php');
}

render_dashboard_layout('Audit Entry', 'admin', 'audit_log', 'admin/audit_log.css', 'admin/audit_log.js', function () use ($entry): void {
    $timestamp = strtotime((string) ($entry['created_at'] ?? ''));
    $timestampLabel = $timestamp ? date('Y-m-d H:i:s', $timestamp) : (string) ($entry['created_at'] ?? '-');
    $roleLabel = match ((string) ($entry['role_name'] ?? '')) {
        'admin' => 'Admin',
        'teacher' => 'Faculty',
        'student' => 'Student',
        default => 'System',
    };
    $roleBadgeClass = ($entry['role_name'] ?? '') === 'admin'
        ? 'danger'
        : (($entry['role_name'] ?? '') === 'teacher'
            ? 'info'
            : (($entry['role_name'] ?? '') === 'student' ? 'warning' : 'success'));
    $requestPathLabel = trim(((string) ($entry['request_method'] ?? 'REQUEST')) . ' ' . (string) ($entry['request_path'] ?? '-'));
    $actorReference = (string) (($entry['actor_secondary'] ?? '') !== '' ? $entry['actor_secondary'] : ($entry['user_identifier'] ?? '-'));
    $deviceSummary = trim((string) (($entry['browser_name'] ?? 'Unknown') . ' ' . ($entry['browser_version'] ?? '')) . ' • ' . (string) ($entry['os_name'] ?? 'Unknown'));
    ?>
    <section class="audit-entry-grid">
        <article class="data-card audit-entry-hero">
            <div class="audit-entry-header">
                <div>
                    <p class="eyebrow">Audit Entry #<?= e((string) $entry['id']) ?></p>
                    <h3 class="card-title"><?= e((string) ($entry['action_label'] ?? $entry['action_code'] ?? 'Audit Entry')) ?></h3>
                </div>
                <a class="btn-secondary audit-back-link" href="<?= e(url('admin/audit_log.php')) ?>">Back to Log</a>
            </div>
            <p class="muted audit-entry-summary"><?= e((string) ($entry['details_summary'] ?? $entry['details'] ?? 'No additional note recorded.')) ?></p>
            <div class="audit-badge-row">
                <span class="badge <?= e($roleBadgeClass) ?>"><?= e($roleLabel) ?></span>
                <span class="badge info">IP <?= e((string) ($entry['ip_address'] ?? '-')) ?></span>
                <span class="badge success"><?= e((string) ($entry['device_name'] ?? 'Unknown Device')) ?></span>
            </div>
            <div class="audit-kpi-grid">
                <div class="audit-kpi">
                    <span class="audit-kpi-label">Timestamp</span>
                    <strong class="mono"><?= e($timestampLabel) ?></strong>
                </div>
                <div class="audit-kpi">
                    <span class="audit-kpi-label">Actor</span>
                    <strong><?= e((string) ($entry['actor_display_name'] ?? $entry['user_identifier'] ?? '-')) ?></strong>
                    <span class="audit-kpi-meta mono"><?= e($actorReference) ?></span>
                </div>
                <div class="audit-kpi">
                    <span class="audit-kpi-label">Request Path</span>
                    <strong class="mono"><?= e($requestPathLabel) ?></strong>
                </div>
            </div>
        </article>

        <section class="audit-meta-grid">
            <article class="data-card audit-detail-card">
                <h4 class="audit-section-title">Actor Snapshot</h4>
                <div class="audit-detail-list">
                    <div class="audit-detail-item"><strong>Actor Name</strong><p class="muted"><?= e((string) ($entry['actor_display_name'] ?? $entry['user_identifier'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Role</strong><p class="muted"><?= e($roleLabel) ?></p></div>
                    <div class="audit-detail-item"><strong>Actor Reference</strong><p class="muted mono"><?= e($actorReference) ?></p></div>
                    <div class="audit-detail-item"><strong>Resolved ID</strong><p class="muted mono"><?= e((string) (($entry['actor_id_resolved'] ?? null) !== null ? $entry['actor_id_resolved'] : '-')) ?></p></div>
                </div>
            </article>

            <article class="data-card audit-detail-card">
                <h4 class="audit-section-title">Request Context</h4>
                <div class="audit-detail-list">
                    <div class="audit-detail-item"><strong>Action Code</strong><p class="muted mono"><?= e((string) ($entry['action_code'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Request Method</strong><p class="muted mono"><?= e((string) ($entry['request_method'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Request Path</strong><p class="muted mono"><?= e((string) ($entry['request_path'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Referrer</strong><p class="muted mono"><?= e((string) ($entry['referer'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Accept-Language</strong><p class="muted mono"><?= e((string) ($entry['accept_language'] ?? '-')) ?></p></div>
                </div>
            </article>

            <article class="data-card audit-detail-card">
                <h4 class="audit-section-title">Network & Session</h4>
                <div class="audit-detail-list">
                    <div class="audit-detail-item"><strong>Client IP</strong><p class="muted mono"><?= e((string) ($entry['ip_address'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Forwarded Chain</strong><p class="muted mono"><?= e((string) ($entry['forwarded_for'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Session Fingerprint</strong><p class="muted mono"><?= e((string) ($entry['session_fingerprint'] ?? '-')) ?></p></div>
                    <div class="audit-detail-item"><strong>Stored Summary</strong><p class="muted"><?= e((string) ($entry['details_summary'] ?? '-')) ?></p></div>
                </div>
            </article>

            <article class="data-card audit-detail-card">
                <h4 class="audit-section-title">Device Snapshot</h4>
                <div class="audit-detail-list">
                    <div class="audit-detail-item"><strong>Device Name</strong><p class="muted"><?= e((string) ($entry['device_name'] ?? 'Unknown Device')) ?></p></div>
                    <div class="audit-detail-item"><strong>Device Type</strong><p class="muted"><?= e((string) ($entry['device_type'] ?? 'Unknown')) ?></p></div>
                    <div class="audit-detail-item"><strong>Browser</strong><p class="muted"><?= e(trim((string) (($entry['browser_name'] ?? 'Unknown') . ' ' . ($entry['browser_version'] ?? '')))) ?></p></div>
                    <div class="audit-detail-item"><strong>Operating System</strong><p class="muted"><?= e((string) ($entry['os_name'] ?? 'Unknown')) ?></p></div>
                    <div class="audit-detail-item"><strong>Device Summary</strong><p class="muted"><?= e($deviceSummary) ?></p></div>
                </div>
            </article>
        </section>

        <article class="data-card audit-detail-card audit-raw-card">
            <h4 class="audit-section-title">Sensitive Raw Details</h4>
            <div class="audit-raw-grid">
                <div>
                    <p class="audit-raw-label">Stored Details</p>
                    <pre class="audit-raw-block"><?= e((string) ($entry['details'] ?? '-')) ?></pre>
                </div>
                <div>
                    <p class="audit-raw-label">User Agent</p>
                    <pre class="audit-raw-block"><?= e((string) ($entry['user_agent'] ?? '-')) ?></pre>
                </div>
            </div>
        </article>
    </section>
    <?php
});