<?php

$rootPath = dirname(__DIR__);
$envPath = $rootPath . DIRECTORY_SEPARATOR . '.env';

if (is_file($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        $quoted = strlen($value) >= 2
            && (($value[0] === '"' && $value[strlen($value) - 1] === '"') || ($value[0] === "'" && $value[strlen($value) - 1] === "'"));
        if ($quoted) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
        $_ENV[$key] ??= $value;
        $_SERVER[$key] ??= $value;
    }
}

$envValue = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    return ($value === null || $value === '') ? $default : (string) $value;
};

$envBool = static function (string $key, bool $default = false) use ($envValue): bool {
    $value = $envValue($key);
    if ($value === null || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
};

$requestBaseUrl = static function (): string {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');

    return ($isHttps ? 'https://' : 'http://') . $host;
};

return [
    'app' => [
        'name' => 'BIPE Academic Portal',
        'env' => $envValue('BIPE_V2_APP_ENV', 'production'),
        'debug' => $envBool('BIPE_V2_APP_DEBUG', false),
        'base_url' => $envValue('BIPE_V2_BASE_URL', $requestBaseUrl()),
        'timezone' => $envValue('BIPE_V2_TIMEZONE', 'Asia/Kolkata'),
    ],
    'db' => [
        'host' => $envValue('BIPE_V2_DB_HOST', '127.0.0.1'),
        'port' => $envValue('BIPE_V2_DB_PORT', '3306'),
        'name' => $envValue('BIPE_V2_DB_NAME', 'bipe_portal_v2'),
        'user' => $envValue('BIPE_V2_DB_USER', 'root'),
        'pass' => $envValue('BIPE_V2_DB_PASS', ''),
    ],
    'security' => [
        'show_seed_hints' => $envBool('BIPE_V2_SHOW_SEED_HINTS', false),
        'expose_otp_in_flash' => $envBool('BIPE_V2_EXPOSE_OTP_IN_FLASH', false),
        'session_cookie_secure' => $envBool('BIPE_V2_SESSION_COOKIE_SECURE', false),
        'session_idle_timeout' => (int) ($envValue('BIPE_V2_SESSION_IDLE_TIMEOUT', '7200') ?: '7200'),
        'csrf_enabled' => $envBool('BIPE_V2_CSRF_ENABLED', true),
        'login_rate_limit' => (int) ($envValue('BIPE_V2_LOGIN_RATE_LIMIT', '10') ?: '10'),
        'otp_rate_limit' => (int) ($envValue('BIPE_V2_OTP_RATE_LIMIT', '5') ?: '5'),
    ],
    'uploads' => [
        'max_csv_bytes' => (int) ($envValue('BIPE_V2_MAX_CSV_BYTES', '2097152') ?: '2097152'),
        'max_backup_bytes' => (int) ($envValue('BIPE_V2_MAX_BACKUP_BYTES', '5242880') ?: '5242880'),
        'max_image_bytes' => (int) ($envValue('BIPE_V2_MAX_IMAGE_BYTES', '2097152') ?: '2097152'),
    ],
    'mail' => [
        'enabled' => $envBool('BIPE_V2_MAIL_ENABLED', false),
        'from_address' => $envValue('BIPE_V2_MAIL_FROM', 'noreply@example.com'),
        'from_name' => $envValue('BIPE_V2_MAIL_FROM_NAME', 'BIPE Academic Portal'),
    ],
    'logging' => [
        'path' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log',
    ],
    'paths' => [
        'storage' => $rootPath . DIRECTORY_SEPARATOR . 'storage',
        'cache' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
    ],
];
