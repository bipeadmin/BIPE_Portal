<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$admin = require_current_admin();

if (is_post()) {
    $action = (string) post('action');
    $requestId = (int) post('request_id');

    try {
        if ($action === 'respond_feedback' && $requestId > 0) {
            $request = respond_support_feedback($requestId, (int) $admin['id'], (string) post('admin_response'));
            audit_log(
                'admin',
                (string) ($admin['username'] ?? 'admin'),
                'FEEDBACK_RESPONDED',
                support_request_type_label((string) ($request['request_type'] ?? 'feedback')) . ' #' . $requestId . ' responded for ' . (string) ($request['requester_identifier'] ?? 'requester')
            );
            flash('success', 'Admin response saved. The sender will see the confirmation in their feedback panel.');
        }

        if ($action === 'close_feedback' && $requestId > 0) {
            $request = reject_support_request($requestId, (int) $admin['id']);
            audit_log(
                'admin',
                (string) ($admin['username'] ?? 'admin'),
                'FEEDBACK_CLOSED',
                support_request_type_label((string) ($request['request_type'] ?? 'feedback')) . ' #' . $requestId . ' closed for ' . (string) ($request['requester_identifier'] ?? 'requester')
            );
            flash('info', 'Message closed. The sender will see that admin reviewed and closed it.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception, 'The feedback decision could not be saved right now. Please try again.');
    }

    redirect_to('admin/feedback.php');
}

$summary = support_request_summary();
$rows = array_values(array_filter(
    support_request_rows(),
    static fn (array $row): bool => in_array((string) ($row['category'] ?? ''), ['feedback', 'issue'], true)
));

render_dashboard_layout('Feedback & Issues', 'admin', 'feedback', 'admin/feedback.css', 'admin/feedback.js', function () use ($summary, $rows): void {
    ?>
    <section class="stats-grid feedback-admin-stats">
        <article class="stat-card">
            <p class="eyebrow">Pending Feedback</p>
            <h3 class="stat-value"><?= e((string) ($summary['feedback']['pending'] ?? 0)) ?></h3>
            <p class="stat-label">Feedback waiting for admin response</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Pending Issues</p>
            <h3 class="stat-value"><?= e((string) ($summary['issue']['pending'] ?? 0)) ?></h3>
            <p class="stat-label">Issue reports waiting for review</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Responded</p>
            <h3 class="stat-value"><?= e((string) (($summary['feedback']['approved'] ?? 0) + ($summary['issue']['approved'] ?? 0))) ?></h3>
            <p class="stat-label">Messages already answered by admin</p>
        </article>
        <article class="stat-card">
            <p class="eyebrow">Closed</p>
            <h3 class="stat-value"><?= e((string) (($summary['feedback']['rejected'] ?? 0) + ($summary['issue']['rejected'] ?? 0))) ?></h3>
            <p class="stat-label">Messages reviewed and closed</p>
        </article>
    </section>

    <article class="data-card feedback-admin-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Feedback Queue</p>
                <h3 class="card-title">Review feedback and issue messages</h3>
            </div>
            <span class="badge info"><?= e((string) count($rows)) ?> Record<?= count($rows) === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($rows): ?>
            <div class="feedback-admin-list">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = (string) ($row['status'] ?? 'pending');
                    $isPending = $status === 'pending';
                    ?>
                    <section class="feedback-admin-item">
                        <div class="feedback-admin-item-head">
                            <div>
                                <span class="badge <?= e($row['category'] === 'issue' ? 'danger' : 'info') ?>"><?= e(support_request_type_label((string) $row['request_type'])) ?></span>
                                <h4><?= e((string) ($row['subject_line'] ?? support_request_type_label((string) $row['request_type']))) ?></h4>
                            </div>
                            <span class="badge <?= e(support_request_status_tone($status)) ?>"><?= e(support_request_workflow_status_label($row)) ?></span>
                        </div>
                        <div class="feedback-admin-grid">
                            <div>
                                <strong>From</strong>
                                <p class="muted"><?= e((string) ($row['requester_name'] ?? 'Unknown')) ?> · <?= e(ucfirst((string) ($row['requester_role'] ?? 'user'))) ?></p>
                                <p class="mono"><?= e((string) ($row['requester_identifier'] ?? '-')) ?></p>
                            </div>
                            <div>
                                <strong>To</strong>
                                <p class="muted"><?= e((string) ($row['target_name'] ?? 'Admin')) ?><?= ($row['target_identifier'] ?? '') !== '' ? ' (' . e((string) $row['target_identifier']) . ')' : '' ?></p>
                            </div>
                            <div>
                                <strong>Submitted</strong>
                                <p class="muted"><?= e((string) ($row['created_at'] ?? '-')) ?></p>
                            </div>
                            <div>
                                <strong>Message</strong>
                                <p class="muted feedback-admin-message"><?= e((string) ($row['message_body'] ?? '-')) ?></p>
                            </div>
                        </div>
                        <?php if ($isPending): ?>
                            <form method="post" class="feedback-admin-response-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="respond_feedback">
                                <input type="hidden" name="request_id" value="<?= e((string) $row['id']) ?>">
                                <div class="form-group">
                                    <label class="form-label" for="admin-response-<?= e((string) $row['id']) ?>">Admin Response</label>
                                    <textarea class="form-input" id="admin-response-<?= e((string) $row['id']) ?>" name="admin_response" rows="4" placeholder="Write the response that the sender should see."></textarea>
                                </div>
                                <div class="inline-actions">
                                    <button class="btn-primary" type="submit">Confirm Response</button>
                                    <button class="btn-danger" type="submit" name="action" value="close_feedback" data-confirm="Close this message without a detailed response?">Close</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="feedback-admin-response">
                                <strong>Admin Response</strong>
                                <p class="muted"><?= e(support_feedback_response_text($row)) ?></p>
                                <?php if (!empty($row['reviewed_by_name'])): ?>
                                    <p class="muted">Reviewed by <?= e((string) $row['reviewed_by_name']) ?> on <?= e((string) ($row['reviewed_at'] ?? '-')) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No feedback or issue messages have been submitted yet.</div>
        <?php endif; ?>
    </article>
    <?php
});
