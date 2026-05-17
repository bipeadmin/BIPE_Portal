<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$teacher = require_current_teacher();
$targetOptions = support_feedback_target_options('teacher', (int) $teacher['id']);
$targetRoleLabels = [
    'admin' => 'Admin',
    'student' => 'Student',
    'teacher' => 'Faculty',
];
$availableTargetRoles = [];
foreach ($targetOptions as $option) {
    $role = (string) ($option['role'] ?? '');
    if ($role !== '' && isset($targetRoleLabels[$role])) {
        $availableTargetRoles[$role] = $targetRoleLabels[$role];
    }
}

if (is_post()) {
    try {
        $request = submit_portal_feedback(
            (string) post('category'),
            'teacher',
            $teacher,
            (string) post('target'),
            (string) post('subject_line'),
            (string) post('message_body')
        );
        audit_log('teacher', (string) $teacher['teacher_code'], 'FEEDBACK_SUBMITTED', ucfirst((string) $request['category']) . ' #' . (int) $request['id'] . ' submitted');
        flash('success', support_request_type_label((string) $request['request_type']) . ' sent successfully. Admin will review it from the feedback panel.');
    } catch (Throwable $exception) {
        flash_exception($exception, 'Your feedback or issue could not be submitted right now. Please review the form and try again.');
    }

    redirect_to('faculty/feedback.php');
}

$unseenResponses = support_request_unseen_response_rows('teacher', (int) $teacher['id']);
foreach ($unseenResponses as $responseRow) {
    flash('info', 'Admin responded to "' . (string) ($responseRow['subject_line'] ?? 'your message') . '". Check the response in your feedback history.');
}
if ($unseenResponses) {
    mark_support_request_responses_seen('teacher', (int) $teacher['id']);
}

$historyRows = support_request_sender_rows('teacher', (int) $teacher['id']);

render_dashboard_layout('Feedback & Query', 'teacher', 'feedback', 'faculty/feedback.css', 'faculty/feedback.js', function () use ($targetOptions, $historyRows, $availableTargetRoles): void {
    ?>
    <section class="feedback-page">
        <article class="data-card feedback-form-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Feedback Desk</p>
                    <h3 class="card-title">Send feedback or report an issue</h3>
                </div>
            </div>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <div class="form-grid two">
                    <div class="form-group">
                        <label class="form-label" for="feedback-category">Message Type</label>
                        <select class="form-select" id="feedback-category" name="category" required>
                            <option value="feedback">Feedback</option>
                            <option value="query">Query</option>
                            <option value="issue">Issue</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="feedback-target-role">Recipient Category</label>
                        <select class="form-select" id="feedback-target-role" data-feedback-role-filter>
                            <option value="">Choose a category</option>
                            <?php foreach ($availableTargetRoles as $role => $label): ?>
                                <option value="<?= e($role) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="feedback-recipient-panel" data-feedback-picker>
                    <div class="form-grid two">
                        <div class="form-group">
                            <label class="form-label" for="feedback-target-search">Search Recipient</label>
                            <input
                                class="form-input"
                                id="feedback-target-search"
                                type="search"
                                placeholder="Select a category first"
                                autocomplete="off"
                                data-feedback-search
                                disabled
                            >
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="feedback-target">Send To</label>
                            <select class="form-select" id="feedback-target" name="target" required data-feedback-target disabled>
                                <option value="">Select a category first</option>
                                <?php foreach ($targetOptions as $option): ?>
                                    <option
                                        value="<?= e((string) $option['value']) ?>"
                                        data-role="<?= e((string) $option['role']) ?>"
                                        data-label="<?= e((string) $option['label']) ?>"
                                        data-name="<?= e((string) $option['name']) ?>"
                                        data-identifier="<?= e((string) ($option['identifier'] ?? '')) ?>"
                                    >
                                        <?= e((string) $option['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="feedback-empty-state" data-feedback-empty hidden>
                    No matching recipients found for the current search.
                </div>
                <?php if (!$targetOptions): ?>
                    <div class="feedback-empty-state">
                        No recipients are available right now. Please contact the administrator directly.
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label" for="feedback-subject">Subject</label>
                    <input class="form-input" id="feedback-subject" name="subject_line" maxlength="190" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="feedback-message">Details</label>
                    <textarea class="form-input" id="feedback-message" name="message_body" rows="7" required></textarea>
                </div>
                <button class="btn-primary" type="submit"<?= $targetOptions ? '' : ' disabled' ?>>Send Message</button>
            </form>
        </article>

        <article class="data-card feedback-history-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">My Messages</p>
                    <h3 class="card-title">Feedback and issue history</h3>
                </div>
            </div>
            <?php if ($historyRows): ?>
                <div class="feedback-ticket-list">
                    <?php foreach ($historyRows as $row): ?>
                        <?php $status = (string) ($row['status'] ?? 'pending'); ?>
                        <section class="feedback-ticket">
                            <div class="feedback-ticket-head">
                                <div>
                                    <span class="badge <?= e($row['category'] === 'issue' ? 'danger' : 'info') ?>"><?= e(support_request_type_label((string) $row['request_type'])) ?></span>
                                    <h4><?= e((string) ($row['subject_line'] ?? support_request_type_label((string) $row['request_type']))) ?></h4>
                                </div>
                                <span class="badge <?= e(support_request_status_tone($status)) ?>"><?= e(support_request_workflow_status_label($row)) ?></span>
                            </div>
                            <div class="feedback-ticket-grid">
                                <div><strong>To</strong><p class="muted"><?= e((string) ($row['target_name'] ?? 'Admin')) ?><?= ($row['target_identifier'] ?? '') !== '' ? ' (' . e((string) $row['target_identifier']) . ')' : '' ?></p></div>
                                <div><strong>Submitted</strong><p class="muted"><?= e((string) ($row['created_at'] ?? '-')) ?></p></div>
                                <div><strong>Message</strong><p class="muted"><?= e((string) ($row['message_body'] ?? '-')) ?></p></div>
                                <div><strong>Admin Response</strong><p class="muted"><?= $status === 'pending' ? 'Waiting for admin response.' : e(support_feedback_response_text($row)) ?></p></div>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No feedback or issue messages have been sent yet.</div>
            <?php endif; ?>
        </article>
    </section>
    <?php
});
