<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (!is_post()) {
    redirect_to('');
}

$user = current_user();
if ($user) {
    audit_log((string) ($user['role'] ?? 'system'), (string) ($user['username'] ?? $user['teacher_code'] ?? $user['enrollment_no'] ?? $user['name'] ?? 'user'), 'LOGOUT', 'User logged out');
}
logout_session();
flash('success', 'You have been logged out.');
redirect_to('');
