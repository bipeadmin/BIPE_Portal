<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$statusCode = (int) ($_GET['code'] ?? 500);
$errorType = trim((string) ($_GET['type'] ?? 'Application Error'));
$message = trim((string) ($_GET['message'] ?? 'The request could not be completed right now.'));

render_error_response($statusCode, $errorType !== '' ? $errorType : 'Application Error', $message !== '' ? $message : 'The request could not be completed right now.');
