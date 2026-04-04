<?php
declare(strict_types=1);

function config(?string $path = null, mixed $default = null): mixed
{
    $config = $GLOBALS['bipe_v2_config'] ?? [];

    if ($path === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function url(string $path = ''): string
{
    $base = rtrim((string) config('app.base_url', ''), '/');

    if ($path === '') {
        return $base !== '' ? $base : '/';
    }

    return $base . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function query_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function execute_sql(string $sql, array $params = []): bool
{
    $stmt = db()->prepare($sql);

    return $stmt->execute($params);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function login_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = $user;
    $_SESSION['last_activity_at'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}

function logout_session(): void
{
    unset($_SESSION['auth'], $_SESSION['last_activity_at'], $_SESSION['_csrf']);
    session_regenerate_id(true);
}

function current_user(): ?array
{
    $user = $_SESSION['auth'] ?? null;

    return is_array($user) ? $user : null;
}

function update_current_user_session(array $updates): void
{
    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        return;
    }

    $_SESSION['auth'] = array_merge($_SESSION['auth'], $updates);
}

function current_role(): ?string
{
    return current_user()['role'] ?? null;
}

function require_role(string $role): void
{
    if (current_role() !== $role) {
        flash('error', 'Please login first.');
        redirect_to('');
    }
}

function year_label(int $level): string
{
    return match ($level) {
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        default => $level . 'th Year',
    };
}

function semester_label(int $semester): string
{
    return 'Semester ' . $semester;
}

function assignment_labels(): array
{
    return ['Assignment-I', 'Assignment-II', 'Assignment-III', 'Assignment-IV', 'Assignment-V'];
}

function current_academic_year(): ?array
{
    return query_one('SELECT * FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
}

function departments(): array
{
    return query_all('SELECT * FROM departments ORDER BY name');
}

function department_map(): array
{
    $map = [];
    foreach (departments() as $department) {
        $map[(int) $department['id']] = $department;
    }

    return $map;
}

function subjects_for(int $departmentId, int $semesterNo): array
{
    return query_all(
        'SELECT * FROM subjects WHERE department_id = :department_id AND semester_no = :semester_no ORDER BY subject_name',
        ['department_id' => $departmentId, 'semester_no' => $semesterNo]
    );
}

function attendance_percent_for_department(int $departmentId): int
{
    $percent = query_value(
        'SELECT ROUND(COALESCE(AVG(CASE WHEN ar.status = "P" THEN 100 ELSE 0 END), 0))
         FROM attendance_records ar
         INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
         WHERE ats.department_id = :department_id',
        ['department_id' => $departmentId]
    );

    return (int) $percent;
}

function attendance_summary_for_student(int $studentId): array
{
    $row = query_one(
        'SELECT
            SUM(CASE WHEN ar.status = "P" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = "A" THEN 1 ELSE 0 END) AS absent_count,
            COUNT(ar.id) AS total_count
         FROM attendance_records ar
         WHERE ar.student_id = :student_id',
        ['student_id' => $studentId]
    ) ?? ['present_count' => 0, 'absent_count' => 0, 'total_count' => 0];

    $present = (int) ($row['present_count'] ?? 0);
    $absent = (int) ($row['absent_count'] ?? 0);
    $total = (int) ($row['total_count'] ?? 0);

    return [
        'present' => $present,
        'absent' => $absent,
        'total' => $total,
        'percentage' => $total > 0 ? (int) round(($present / $total) * 100) : 0,
    ];
}

function marks_rows_for_student(int $studentId): array
{
    return query_all(
        'SELECT
            s.subject_name,
            mu.exam_type,
            mu.max_marks,
            mr.marks_obtained
         FROM mark_records mr
         INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
         INNER JOIN subjects s ON s.id = mu.subject_id
         WHERE mr.student_id = :student_id
         ORDER BY s.subject_name, mu.exam_type',
        ['student_id' => $studentId]
    );
}

function assignment_rows_for_student(int $studentId): array
{
    return query_all(
        'SELECT
            sb.subject_name,
            a.assignment_label,
            COALESCE(asb.submission_status, "pending") AS submission_status
         FROM assignments a
         INNER JOIN subjects sb ON sb.id = a.subject_id
         LEFT JOIN assignment_submissions asb
            ON asb.assignment_id = a.id AND asb.student_id = :student_id
         WHERE a.academic_year_id = (SELECT id FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1)
         ORDER BY sb.subject_name, a.assignment_label',
        ['student_id' => $studentId]
    );
}

function grade_from_marks(float $marks, float $maxMarks): string
{
    if ($maxMarks <= 0) {
        return '-';
    }

    $percent = ($marks / $maxMarks) * 100;

    return match (true) {
        $percent >= 90 => 'O',
        $percent >= 80 => 'A+',
        $percent >= 70 => 'A',
        $percent >= 60 => 'B+',
        $percent >= 50 => 'B',
        $percent >= 40 => 'C',
        default => 'F',
    };
}

function portal_stats(): array
{
    return [
        'students_total' => (int) query_value('SELECT COUNT(*) FROM students'),
        'registered_students' => (int) query_value('SELECT COUNT(*) FROM students WHERE password_hash IS NOT NULL'),
        'teachers_active' => (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "approved"'),
        'teachers_pending' => (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "pending"'),
    ];
}

function teacher_department_students(int $departmentId, ?int $yearLevel = null): array
{
    $sql = 'SELECT * FROM students WHERE department_id = :department_id';
    $params = ['department_id' => $departmentId];

    if ($yearLevel !== null) {
        $sql .= ' AND year_level = :year_level';
        $params['year_level'] = $yearLevel;
    }

    $sql .= ' ORDER BY year_level DESC, full_name ASC';

    return query_all($sql, $params);
}

function teacher_record_summary(int $teacherId): array
{
    $teacher = query_one('SELECT * FROM teachers WHERE id = :id', ['id' => $teacherId]);

    if (!$teacher) {
        return ['student_count' => 0, 'attendance_percent' => 0, 'assignment_count' => 0, 'marks_uploads' => 0];
    }

    $departmentId = (int) $teacher['department_id'];

    return [
        'student_count' => (int) query_value('SELECT COUNT(*) FROM students WHERE department_id = :department_id', ['department_id' => $departmentId]),
        'attendance_percent' => attendance_percent_for_department($departmentId),
        'assignment_count' => (int) query_value('SELECT COUNT(*) FROM assignments WHERE department_id = :department_id', ['department_id' => $departmentId]),
        'marks_uploads' => (int) query_value('SELECT COUNT(*) FROM mark_uploads WHERE department_id = :department_id', ['department_id' => $departmentId]),
    ];
}




function app_env(): string
{
    return strtolower((string) config('app.env', 'production'));
}

function app_debug(): bool
{
    return (bool) config('app.debug', false);
}

function is_production(): bool
{
    return app_env() === 'production';
}

function show_seed_hints(): bool
{
    return (bool) config('security.show_seed_hints', false);
}

function expose_otp_in_flash(): bool
{
    return (bool) config('security.expose_otp_in_flash', false);
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $forwardedProto === 'https';
}

function client_ip(): string
{
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create application directory.');
    }
}

function schema_column_exists(string $table, string $column): bool
{
    return (bool) query_value(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name',
        ['table_name' => $table, 'column_name' => $column]
    );
}

function ensure_runtime_schema_support(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columnDefinitions = [
        ['admins', 'phone_number', 'ALTER TABLE admins ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email'],
        ['admins', 'profile_image_path', 'ALTER TABLE admins ADD COLUMN profile_image_path VARCHAR(255) DEFAULT NULL AFTER phone_number'],
        ['teachers', 'phone_number', 'ALTER TABLE teachers ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email'],
        ['teachers', 'profile_image_path', 'ALTER TABLE teachers ADD COLUMN profile_image_path VARCHAR(255) DEFAULT NULL AFTER phone_number'],
        ['students', 'phone_number', 'ALTER TABLE students ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email'],
        ['students', 'profile_image_path', 'ALTER TABLE students ADD COLUMN profile_image_path VARCHAR(255) DEFAULT NULL AFTER phone_number'],
    ];

    foreach ($columnDefinitions as [$table, $column, $sql]) {
        if (!schema_column_exists($table, $column)) {
            execute_sql($sql);
        }
    }

    execute_sql(
        'CREATE TABLE IF NOT EXISTS support_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category ENUM("request", "feedback", "issue") NOT NULL DEFAULT "request",
            request_type VARCHAR(80) DEFAULT NULL,
            requester_role ENUM("teacher", "student", "admin", "guest") NOT NULL DEFAULT "teacher",
            requester_id INT UNSIGNED DEFAULT NULL,
            requester_name VARCHAR(190) DEFAULT NULL,
            requester_identifier VARCHAR(80) DEFAULT NULL,
            requester_email VARCHAR(190) DEFAULT NULL,
            subject_line VARCHAR(190) DEFAULT NULL,
            message_body TEXT DEFAULT NULL,
            requested_password_hash VARCHAR(255) DEFAULT NULL,
            status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
            reviewed_by_admin_id INT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_requests_category_status (category, status),
            INDEX idx_support_requests_requester (requester_role, requester_identifier),
            INDEX idx_support_requests_created_at (created_at),
            CONSTRAINT fk_support_requests_admin FOREIGN KEY (reviewed_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    foreach (['admins', 'teachers', 'students'] as $role) {
        profile_image_storage_directory($role);
    }
}
function app_log(string $level, string $message, array $context = []): void
{
    $logPath = (string) config('logging.path');
    if ($logPath === '') {
        return;
    }

    $directory = dirname($logPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $entry = [
        'timestamp' => date('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ];

    file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function safe_exception_message(Throwable $exception, string $fallback): string
{
    $message = trim($exception->getMessage());
    if ($message === '') {
        return $fallback;
    }

    foreach (['SQLSTATE', 'PDOException', 'Stack trace', 'Undefined', 'Call to', 'Failed opening', 'syntax error'] as $needle) {
        if (stripos($message, $needle) !== false) {
            return $fallback;
        }
    }

    return $message;
}

function flash_exception(Throwable $exception, string $fallback = 'We could not complete your request. Please review the submitted data and try again.'): void
{
    app_log('error', $exception->getMessage(), [
        'type' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ]);

    flash('error', app_debug() ? $exception->getMessage() : safe_exception_message($exception, $fallback));
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf_request(): void
{
    $submitted = (string) ($_POST['_csrf'] ?? '');
    $sessionToken = (string) ($_SESSION['_csrf'] ?? '');

    if ($submitted !== '' && $sessionToken !== '' && hash_equals($sessionToken, $submitted)) {
        return;
    }

    app_log('warning', 'CSRF validation failed', [
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'ip' => client_ip(),
    ]);

    render_error_response(419, 'Security Validation Error', 'Your session expired or the request token is invalid. Please go back and submit the form again.');
}

function enforce_session_timeout(): void
{
    $user = current_user();
    if (!$user) {
        return;
    }

    $timeout = (int) config('security.session_idle_timeout', 7200);
    if ($timeout <= 0) {
        $_SESSION['last_activity_at'] = time();
        return;
    }

    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? time());
    if ((time() - $lastActivity) > $timeout) {
        $identifier = (string) ($user['username'] ?? $user['teacher_code'] ?? $user['enrollment_no'] ?? $user['name'] ?? 'user');
        app_log('warning', 'Session timed out', ['user' => $identifier, 'role' => $user['role'] ?? null]);
        logout_session();
        flash('info', 'Your session expired. Please login again.');
        redirect_to('');
    }

    $_SESSION['last_activity_at'] = time();
}

function validate_email_address(string $email): string
{
    $normalized = strtolower(trim($email));
    if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    return $normalized;
}

function validate_password_strength(string $password): string
{
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters long.');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        throw new RuntimeException('Password must include at least one uppercase letter.');
    }
    if (!preg_match('/[a-z]/', $password)) {
        throw new RuntimeException('Password must include at least one lowercase letter.');
    }
    if (!preg_match('/\d/', $password)) {
        throw new RuntimeException('Password must include at least one number.');
    }
    if (!preg_match('/[^A-Za-z\d]/', $password)) {
        throw new RuntimeException('Password must include at least one special character.');
    }

    return $password;
}

function assert_uploaded_file(array $file, array $allowedExtensions, int $maxBytes): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please choose the file again.');
    }

    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('Uploaded file could not be verified.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded file is empty.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('The uploaded file is too large.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Invalid file type uploaded.');
    }

    return (string) $file['tmp_name'];
}

function normalize_phone_number(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalized = preg_replace('/[^\d+]/', '', $value) ?? '';
    $hasPlus = str_starts_with($normalized, '+');
    $digits = $hasPlus ? substr($normalized, 1) : $normalized;

    if ($digits === '' || preg_match('/\D/', $digits)) {
        throw new RuntimeException('Phone number must contain digits only.');
    }

    $length = strlen($digits);
    if ($length < 10 || $length > 15) {
        throw new RuntimeException('Phone number must be between 10 and 15 digits.');
    }

    return ($hasPlus ? '+' : '') . $digits;
}

function profile_image_initial(string $name): string
{
    $initial = strtoupper(substr(trim($name), 0, 1));

    return $initial !== '' ? $initial : 'U';
}

function profile_image_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    return url(str_replace('\\', '/', $path));
}

function profile_image_storage_directory(string $role): string
{
    $role = preg_replace('/[^a-z0-9_-]+/i', '', strtolower($role)) ?: 'users';
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR . $role;
    ensure_directory($directory);

    return $directory;
}

function profile_image_absolute_path(string $relativePath): string
{
    $relativePath = str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
}

function delete_profile_image(?string $relativePath): void
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));
    if ($relativePath === '' || !str_starts_with($relativePath, 'assets/uploads/profiles/')) {
        return;
    }

    $absolutePath = profile_image_absolute_path($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function store_profile_image_upload(array $file, string $role, ?string $currentPath = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }

    $tmpName = assert_uploaded_file($file, ['jpg', 'jpeg', 'png', 'webp'], (int) config('uploads.max_image_bytes', 2097152));
    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('Please upload a valid JPG, PNG, or WEBP image.');
    }

    $role = preg_replace('/[^a-z0-9_-]+/i', '', strtolower($role)) ?: 'users';
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $filename = $role . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $relativePath = 'assets/uploads/profiles/' . $role . '/' . $filename;
    $absolutePath = profile_image_absolute_path($relativePath);
    profile_image_storage_directory($role);

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Profile image could not be saved. Please try again.');
    }

    if ($currentPath !== null && trim($currentPath) !== '' && $currentPath !== $relativePath) {
        delete_profile_image($currentPath);
    }

    return $relativePath;
}

function read_uploaded_json_file(array $file, int $maxBytes): array
{
    $tmpName = assert_uploaded_file($file, ['json'], $maxBytes);
    $content = file_get_contents($tmpName);
    if ($content === false) {
        throw new RuntimeException('Unable to read the uploaded JSON file.');
    }

    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

function rate_limit_storage_path(string $bucket): string
{
    $cacheDir = (string) config('paths.cache');
    if ($cacheDir === '') {
        $cacheDir = dirname((string) config('logging.path')) . DIRECTORY_SEPARATOR . 'cache';
    }
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }

    return $cacheDir . DIRECTORY_SEPARATOR . 'rate_' . hash('sha256', $bucket . '|' . client_ip()) . '.json';
}

function rate_limit_or_fail(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    if ($maxAttempts <= 0 || $windowSeconds <= 0) {
        return;
    }

    $path = rate_limit_storage_path($bucket);
    $now = time();
    $attempts = [];

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $attempts = array_values(array_filter($decoded, static fn ($timestamp): bool => is_int($timestamp) && $timestamp >= ($now - $windowSeconds)));
        }
    }

    if (count($attempts) >= $maxAttempts) {
        throw new RuntimeException('Too many requests. Please wait a few minutes and try again.');
    }

    $attempts[] = $now;
    file_put_contents($path, json_encode($attempts), LOCK_EX);
}

function clear_rate_limit(string $bucket): void
{
    $path = rate_limit_storage_path($bucket);
    if (is_file($path)) {
        unlink($path);
    }
}

function mail_delivery_enabled(): bool
{
    return (bool) config('mail.enabled', false);
}

function send_portal_mail(string $to, string $subject, string $message): bool
{
    if (!mail_delivery_enabled()) {
        return false;
    }

    $fromAddress = (string) config('mail.from_address', 'noreply@example.com');
    $fromName = (string) config('mail.from_name', (string) config('app.name', 'BIPE Academic Portal'));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromAddress . '>',
    ];

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function dispatch_password_reset_otp(string $email, string $recipientName, string $otp, string $roleLabel): ?string
{
    $subject = (string) config('app.name', 'BIPE Academic Portal') . ' password reset code';
    $message = "Hello {$recipientName},\n\nYour {$roleLabel} password reset OTP is: {$otp}\n\nThis code expires in 10 minutes. If you did not request it, please contact the administrator immediately.\n";

    if (mail_delivery_enabled()) {
        if (!send_portal_mail($email, $subject, $message)) {
            throw new RuntimeException('Password reset delivery is temporarily unavailable.');
        }

        return null;
    }

    if (expose_otp_in_flash()) {
        return $otp;
    }

    if (is_production()) {
        throw new RuntimeException('Password reset delivery is not configured.');
    }

    app_log('info', 'OTP generated without mail delivery', ['email' => $email, 'role' => $roleLabel]);
    return null;
}

function password_reset_request_success_message(?string $otpPreview = null): string
{
    $message = 'If the account exists, a password reset code has been sent to the registered email address.';
    if ($otpPreview !== null && expose_otp_in_flash()) {
        $message .= ' Development OTP: ' . $otpPreview;
    }

    return $message;
}











