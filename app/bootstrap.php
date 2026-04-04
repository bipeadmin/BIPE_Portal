<?php

declare(strict_types=1);

$GLOBALS['bipe_v2_config'] = require __DIR__ . '/../config/config.php';

error_reporting(E_ALL);
ini_set('display_errors', !empty($GLOBALS['bipe_v2_config']['app']['debug']) ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set($GLOBALS['bipe_v2_config']['app']['timezone'] ?? 'Asia/Calcutta');

$httpsDetected = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$secureCookie = $httpsDetected || !empty($GLOBALS['bipe_v2_config']['security']['session_cookie_secure']);
$sessionTimeout = (int) ($GLOBALS['bipe_v2_config']['security']['session_idle_timeout'] ?? 7200);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bipe_portal_v2_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) max($sessionTimeout, 1800));
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $secureCookie,
        'use_strict_mode' => true,
    ]);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/portal_features.php';
require_once __DIR__ . '/error_renderer.php';

ensure_runtime_schema_support();

apply_security_headers();
enforce_session_timeout();

if (is_post() && (bool) config('security.csrf_enabled', true)) {
    verify_csrf_request();
}

set_exception_handler(static function (Throwable $exception): void {
    app_log('critical', $exception->getMessage(), [
        'type' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'trace' => $exception->getTraceAsString(),
    ]);

    $message = app_debug()
        ? $exception->getMessage()
        : safe_exception_message($exception, 'The request could not be completed right now. Please try again in a moment or contact the administrator if the problem continues.');

    render_error_response(500, get_class($exception), $message);
});
