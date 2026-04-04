<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$admin = require_current_admin();

if (is_post()) {
    $action = (string) post('action');
    $requestId = (int) post('request_id');

    try {
        if ($action === 'approve_request' && $requestId > 0) {
            $result = approve_support_request($requestId, (int) $admin['id']);
            $request = $result['request'];
            audit_log(
                'admin',
                (string) ($admin['username'] ?? 'admin'),
                'SUPPORT_REQUEST_APPROVED',
                support_request_type_label((string) ($request['request_type'] ?? 'request')) . ' for ' . (string) ($request['requester_identifier'] ?? ('request#' . $requestId))
            );

            if (!empty($result['mailto'])) {
                $_SESSION['support_request_mailto'] = (string) $result['mailto'];
                flash('info', 'Mail composer is opening with the faculty ID details.');
            }

            $requestType = (string) ($request['request_type'] ?? '');
            if ($requestType === 'forgot_password') {
                flash('success', 'Password request approved and faculty password updated.');
            } elseif ($requestType === 'forgot_faculty_id') {
                flash('success', 'Faculty ID request approved.');
            } else {
                flash('success', support_request_type_label($requestType) . ' approved successfully.');
            }
        }

        if ($action === 'reject_request' && $requestId > 0) {
            $request = reject_support_request($requestId, (int) $admin['id']);
            audit_log(
                'admin',
                (string) ($admin['username'] ?? 'admin'),
                'SUPPORT_REQUEST_REJECTED',
                support_request_type_label((string) ($request['request_type'] ?? 'request')) . ' for ' . (string) ($request['requester_identifier'] ?? ('request#' . $requestId))
            );
            flash('info', support_request_type_label((string) ($request['request_type'] ?? 'request')) . ' rejected.');
        }
    } catch (Throwable $exception) {
        flash_exception($exception, 'The request decision could not be saved right now. Please try again.');
    }

    redirect_to('admin/requests.php');
}

$summary = support_request_summary();
$allRows = support_request_rows();
$requestRows = array_values(array_filter($allRows, static fn (array $row): bool => ($row['category'] ?? '') === 'request'));
$feedbackRows = array_values(array_filter($allRows, static fn (array $row): bool => ($row['category'] ?? '') === 'feedback'));
$issueRows = array_values(array_filter($allRows, static fn (array $row): bool => ($row['category'] ?? '') === 'issue'));
$mailtoLink = trim((string) ($_SESSION['support_request_mailto'] ?? ''));
unset($_SESSION['support_request_mailto']);

$detailText = static function (array $row): string {
    $message = trim((string) ($row['message_body'] ?? ''));
    if ($message !== '') {
        return $message;
    }

    return match ((string) ($row['request_type'] ?? '')) {
        'forgot_password' => 'Requested password is stored securely and will be applied immediately after approval.',
        'forgot_faculty_id' => 'Approval opens the default mail client with a prefilled faculty ID message for the registered email address.',
        default => 'Submitted from the portal support workflow.',
    };
};

$renderSection = static function (string $eyebrow, string $title, string $subtitle, array $rows, string $emptyMessage, callable $detailText): void {
    ?>
    <article class="data-card request-section-card">
        <div class="card-head">
            <div>
                <p class="eyebrow"><?= e($eyebrow) ?></p>
                <h3 class="card-title"><?= e($title) ?></h3>
                <p class="card-subtitle"><?= e($subtitle) ?></p>
            </div>
            <span class="badge info"><?= e((string) count($rows)) ?> Record<?= count($rows) === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($rows): ?>
            <div class="table-wrap request-table-wrap">
                <table class="request-table">
                    <thead>
                    <tr>
                        <th>Request Type</th>
                        <th>Submitted By</th>
                        <th>Date &amp; Time</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = (string) ($row['status'] ?? 'pending');
                        $isPending = $status === 'pending';
                        ?>
                        <tr>
                            <td data-label="Request Type">
                                <div class="request-type-stack">
                                    <strong><?= e(support_request_type_label((string) ($row['request_type'] ?? ''))) ?></strong>
                                    <span class="muted request-category-note"><?= e(ucfirst((string) ($row['category'] ?? 'request'))) ?> Desk</span>
                                </div>
                            </td>
                            <td data-label="Submitted By">
                                <div class="request-meta-stack">
                                    <strong><?= e((string) ($row['requester_name'] ?? 'Unknown requester')) ?></strong>
                                    <span class="mono"><?= e((string) ($row['requester_identifier'] ?? '-')) ?></span>
                                    <span class="muted"><?= e((string) (($row['requester_email'] ?? '') !== '' ? $row['requester_email'] : 'No email on record')) ?></span>
                                </div>
                            </td>
                            <td data-label="Date & Time">
                                <div class="request-meta-stack">
                                    <strong><?= e((string) ($row['created_at'] ?? '-')) ?></strong>
                                    <?php if (!empty($row['reviewed_at'])): ?>
                                        <span class="muted">Reviewed <?= e((string) $row['reviewed_at']) ?></span>
                                    <?php else: ?>
                                        <span class="muted">Waiting for admin review</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Details">
                                <div class="request-detail-stack">
                                    <strong><?= e((string) ($row['subject_line'] ?? support_request_type_label((string) ($row['request_type'] ?? '')))) ?></strong>
                                    <span class="muted"><?= e($detailText($row)) ?></span>
                                </div>
                            </td>
                            <td data-label="Status">
                                <div class="request-review-stack">
                                    <span class="badge <?= e(support_request_status_tone($status)) ?>"><?= e(support_request_status_label($status)) ?></span>
                                    <?php if (!empty($row['reviewed_by_name'])): ?>
                                        <span class="muted">By <?= e((string) $row['reviewed_by_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Action">
                                <?php if ($isPending): ?>
                                    <div class="request-actions">
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="approve_request">
                                            <input type="hidden" name="request_id" value="<?= e((string) $row['id']) ?>">
                                            <button class="btn-primary" type="submit" data-confirm="Approve this request now?">Approve</button>
                                        </form>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reject_request">
                                            <input type="hidden" name="request_id" value="<?= e((string) $row['id']) ?>">
                                            <button class="btn-danger" type="submit" data-confirm="Reject this request?">Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="request-review-stack request-review-compact">
                                        <strong><?= e(support_request_status_label($status)) ?></strong>
                                        <span class="muted">Decision saved in admin record.</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><?= e($emptyMessage) ?></div>
        <?php endif; ?>
    </article>
    <?php
};

render_dashboard_layout('Request Approval / Feedback / Issues', 'admin', 'requests', 'admin/requests.css', 'admin/requests.js', function () use ($summary, $requestRows, $feedbackRows, $issueRows, $mailtoLink, $detailText, $renderSection): void {
    ?>
    <div class="request-admin-page"<?= $mailtoLink !== '' ? ' data-request-mailto="' . e($mailtoLink) . '"' : '' ?>>
        <section class="stats-grid request-stats-grid">
            <article class="stat-card">
                <p class="eyebrow">Pending Requests</p>
                <h3 class="stat-value"><?= e((string) ($summary['request']['pending'] ?? 0)) ?></h3>
                <p class="stat-label">Account-related approvals waiting for the administrator</p>
            </article>
            <article class="stat-card">
                <p class="eyebrow">Feedback Entries</p>
                <h3 class="stat-value"><?= e((string) ($summary['feedback']['total'] ?? 0)) ?></h3>
                <p class="stat-label">Feedback items currently available in the shared queue</p>
            </article>
            <article class="stat-card">
                <p class="eyebrow">Issue Tickets</p>
                <h3 class="stat-value"><?= e((string) ($summary['issue']['total'] ?? 0)) ?></h3>
                <p class="stat-label">Issue records captured for admin review and action</p>
            </article>
            <article class="stat-card">
                <p class="eyebrow">Reviewed Decisions</p>
                <h3 class="stat-value"><?= e((string) (($summary['all']['approved'] ?? 0) + ($summary['all']['rejected'] ?? 0))) ?></h3>
                <p class="stat-label">Requests, feedback, and issues already reviewed by an administrator</p>
            </article>
        </section>

        <div class="notice-box request-approval-note">Password reset approvals update the requested faculty password instantly. Faculty ID approvals open the default mail client with a prefilled message so the admin can send the ID quickly.</div>

        <?php $renderSection(
            'Request Approval',
            'Faculty access requests awaiting review',
            'See who submitted the request, when it arrived, and complete the approval or rejection directly from this queue.',
            $requestRows,
            'No faculty access requests are waiting right now.',
            $detailText
        ); ?>

        <?php $renderSection(
            'Feedback Desk',
            'Feedback records from connected portal workflows',
            'This area will hold submitted feedback so the admin can review, approve, or reject each entry from one place.',
            $feedbackRows,
            'No feedback entries are available yet.',
            $detailText
        ); ?>

        <?php $renderSection(
            'Issues Desk',
            'Issue reports queued for administrator action',
            'Operational issues and portal complaints can be reviewed here without needing a separate approval screen.',
            $issueRows,
            'No issue reports are available yet.',
            $detailText
        ); ?>
    </div>
    <?php
});


