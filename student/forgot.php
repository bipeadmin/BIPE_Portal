<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

flash('info', 'OTP-based password reset has been removed from the portal. Please contact the administrator for password help.');
redirect_to('student/login.php');
