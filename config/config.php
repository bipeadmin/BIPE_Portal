<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);
$envBool = static function (string $key, bool $default = false): bool {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
};

return [
    'app' => [
        'name' => 'BIPE Academic Portal',
        'env' => getenv('BIPE_V2_APP_ENV') ?: 'production',
        'debug' => $envBool('BIPE_V2_APP_DEBUG', false),
        'base_url' => getenv('BIPE_V2_BASE_URL') ?: '',
        'timezone' => getenv('BIPE_V2_TIMEZONE') ?: 'Asia/Calcutta',
    ],
    'db' => [
        'host' => getenv('BIPE_V2_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('BIPE_V2_DB_PORT') ?: '3306',
        'name' => getenv('BIPE_V2_DB_NAME') ?: 'bipe_portal_v2',
        'user' => getenv('BIPE_V2_DB_USER') ?: 'root',
        'pass' => getenv('BIPE_V2_DB_PASS') ?: 'M4a1..,.,.,@',
    ],
    'security' => [
        'show_seed_hints' => $envBool('BIPE_V2_SHOW_SEED_HINTS', false),
        'expose_otp_in_flash' => $envBool('BIPE_V2_EXPOSE_OTP_IN_FLASH', false),
        'session_cookie_secure' => $envBool('BIPE_V2_SESSION_COOKIE_SECURE', false),
        'session_idle_timeout' => (int) (getenv('BIPE_V2_SESSION_IDLE_TIMEOUT') ?: 7200),
        'csrf_enabled' => $envBool('BIPE_V2_CSRF_ENABLED', true),
        'login_rate_limit' => (int) (getenv('BIPE_V2_LOGIN_RATE_LIMIT') ?: 10),
        'otp_rate_limit' => (int) (getenv('BIPE_V2_OTP_RATE_LIMIT') ?: 5),
    ],
    'uploads' => [
        'max_csv_bytes' => (int) (getenv('BIPE_V2_MAX_CSV_BYTES') ?: 2097152),
        'max_backup_bytes' => (int) (getenv('BIPE_V2_MAX_BACKUP_BYTES') ?: 5242880),
        'max_image_bytes' => (int) (getenv('BIPE_V2_MAX_IMAGE_BYTES') ?: 2097152),
    ],
    'mail' => [
        'enabled' => $envBool('BIPE_V2_MAIL_ENABLED', false),
        'from_address' => getenv('BIPE_V2_MAIL_FROM') ?: 'noreply@example.com',
        'from_name' => getenv('BIPE_V2_MAIL_FROM_NAME') ?: 'BIPE Academic Portal',
    ],
    'logging' => [
        'path' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log',
    ],
    'paths' => [
        'storage' => $rootPath . DIRECTORY_SEPARATOR . 'storage',
        'cache' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
    ],
];

