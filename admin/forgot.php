<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (current_role() === 'admin') {
    redirect_to('admin/dashboard.php');
}

render_auth_layout('Administrator Password Help', 'Password recovery for administrators is handled manually.', 'admin/forgot.css', 'admin/forgot.js', function (): void {
    ?>
    <div class="stack">
        <div class="notice-box warning">Connect to Database Administrator</div>
        <div class="notice-box">If you need access restored, please connect with the database administrator for assistance.</div>
        <div class="form-actions">
            <a class="btn-secondary" href="<?= e(url('admin/login.php')) ?>">Back to Login</a>
        </div>
    </div>
    <?php
});
