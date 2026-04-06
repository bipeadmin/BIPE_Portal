<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$inputValue = '';
$passwordHash = null;
$verifyPassword = '';
$storedHash = '';
$verificationResult = null;

if (is_post()) {
    $formAction = (string) post('form_action', 'generate');

    try {
        if ($formAction === 'verify') {
            $verifyPassword = trim((string) post('verify_password'));
            $storedHash = trim((string) post('stored_hash'));

            if ($verifyPassword === '' || $storedHash === '') {
                throw new RuntimeException('Enter both the password and the stored hash first.');
            }

            $verificationResult = password_verify($verifyPassword, $storedHash);
        } else {
            $inputValue = trim((string) post('plain_text'));

            if ($inputValue === '') {
                throw new RuntimeException('Enter a password first.');
            }

            $passwordHash = password_hash($inputValue, PASSWORD_DEFAULT);
        }
    } catch (Throwable $exception) {
        flash_exception($exception, 'The password utility request could not be completed. Please try again.');
        redirect_to('change-password.php');
    }
}

render_head('Change Password', 'admin/change_password.css', 'change-password-body');
?>
<main class="change-password-shell">
    <?php render_flashes(); ?>
    <section class="change-password-card">
        <div class="change-password-card-head">
            <p class="eyebrow">Password Utility</p>
            <h1>Change Password</h1>
            <p class="change-password-copy-text">Enter a new password and generate the secure hash that can be stored in the database.</p>
        </div>

        <form method="post" class="form-grid change-password-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="generate">
            <div class="form-group">
                <label class="form-label" for="change-password-input">New Password</label>
                <input class="form-input change-password-input" id="change-password-input" name="plain_text" type="password" placeholder="Type the new password" autocomplete="new-password" required value="<?= e($inputValue) ?>">
            </div>
            <button class="btn-primary" type="submit">Generate Password Hash</button>
        </form>

        <?php if ($passwordHash): ?>
            <div class="change-password-output-block">
                <div class="change-password-output-head">
                    <strong>Generated password_hash()</strong>
                    <button class="btn-secondary change-password-copy" type="button" data-copy-value="<?= e($passwordHash) ?>">Copy</button>
                </div>
                <div class="code-block mono"><?= e($passwordHash) ?></div>
            </div>
        <?php endif; ?>

        <div class="change-password-divider"></div>

        <div class="change-password-card-head">
            <p class="eyebrow">Verification</p>
            <h2>Check Existing Hash</h2>
            <p class="change-password-copy-text">Paste the password and stored database hash to confirm whether they match.</p>
        </div>

        <form method="post" class="form-grid change-password-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="verify">
            <div class="form-group">
                <label class="form-label" for="verify-password-input">Password</label>
                <input class="form-input change-password-input" id="verify-password-input" name="verify_password" type="password" placeholder="Type the password to test" autocomplete="current-password" required value="<?= e($verifyPassword) ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="stored-hash-input">Stored Hash</label>
                <textarea class="form-textarea change-password-hash-input" id="stored-hash-input" name="stored_hash" placeholder="Paste the password_hash value from the database" required><?= e($storedHash) ?></textarea>
            </div>
            <button class="btn-primary" type="submit">Verify Password</button>
        </form>

        <?php if ($verificationResult !== null): ?>
            <div class="change-password-output-block <?= $verificationResult ? 'is-success' : 'is-danger' ?>">
                <div class="change-password-output-head">
                    <strong>Verification Result</strong>
                </div>
                <div class="code-block mono"><?= $verificationResult ? 'MATCH: This password belongs to the pasted hash.' : 'NOT MATCH: This password does not belong to the pasted hash.' ?></div>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php render_foot('admin/change_password.js'); ?>
