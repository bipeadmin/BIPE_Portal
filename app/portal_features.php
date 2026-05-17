<?php
declare(strict_types=1);

function default_mark_type_blueprint(): array
{
    return [
        ['label' => 'Sessional Exam I', 'max_marks' => 50, 'sort_order' => 10],
        ['label' => 'Sessional Exam II', 'max_marks' => 50, 'sort_order' => 20],
        ['label' => 'Pre Board', 'max_marks' => 50, 'sort_order' => 30],
        ['label' => 'Assignment', 'max_marks' => 10, 'sort_order' => 40],
        ['label' => 'Attendance', 'max_marks' => 10, 'sort_order' => 50],
        ['label' => 'Practical / Lab', 'max_marks' => 25, 'sort_order' => 60],
    ];
}

function ensure_mark_types_seeded(): void
{
    $count = (int) query_value('SELECT COUNT(*) FROM mark_types');
    if ($count > 0) {
        return;
    }

    foreach (default_mark_type_blueprint() as $row) {
        execute_sql(
            'INSERT INTO mark_types (label, max_marks, sort_order) VALUES (:label, :max_marks, :sort_order)',
            $row
        );
    }
}

function mark_type_rows(): array
{
    ensure_mark_types_seeded();

    return query_all('SELECT * FROM mark_types ORDER BY sort_order ASC, id ASC');
}

function mark_type_by_id(int $id): ?array
{
    ensure_mark_types_seeded();

    return query_one('SELECT * FROM mark_types WHERE id = :id', ['id' => $id]);
}

function add_mark_type_row(string $label, float $maxMarks): void
{
    $label = trim($label);
    if ($label === '') {
        throw new RuntimeException('Mark type label is required.');
    }
    if ($maxMarks <= 0) {
        throw new RuntimeException('Max marks must be greater than zero.');
    }

    $exists = query_one('SELECT id FROM mark_types WHERE label = :label LIMIT 1', ['label' => $label]);
    if ($exists) {
        throw new RuntimeException('This mark type already exists.');
    }

    $nextSort = (int) query_value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM mark_types');
    execute_sql(
        'INSERT INTO mark_types (label, max_marks, sort_order) VALUES (:label, :max_marks, :sort_order)',
        ['label' => $label, 'max_marks' => $maxMarks, 'sort_order' => $nextSort]
    );
}

function delete_mark_type_row(int $id): void
{
    execute_sql('DELETE FROM mark_types WHERE id = :id', ['id' => $id]);
}

function locked_mark_keys(): array
{
    $keys = [];
    foreach (query_all('SELECT department_id, semester_no FROM mark_locks ORDER BY department_id, semester_no') as $row) {
        $keys[] = ((int) $row['department_id']) . '|' . ((int) $row['semester_no']);
    }

    return $keys;
}

function mark_section_locked(int $departmentId, int $semesterNo): bool
{
    return (bool) query_value(
        'SELECT COUNT(*) FROM mark_locks WHERE department_id = :department_id AND semester_no = :semester_no',
        ['department_id' => $departmentId, 'semester_no' => $semesterNo]
    );
}

function set_mark_section_lock(int $departmentId, int $semesterNo, bool $locked): void
{
    if ($locked) {
        execute_sql(
            'INSERT INTO mark_locks (department_id, semester_no) VALUES (:department_id, :semester_no)
             ON DUPLICATE KEY UPDATE locked_at = NOW()',
            ['department_id' => $departmentId, 'semester_no' => $semesterNo]
        );

        return;
    }

    execute_sql(
        'DELETE FROM mark_locks WHERE department_id = :department_id AND semester_no = :semester_no',
        ['department_id' => $departmentId, 'semester_no' => $semesterNo]
    );
}

function set_all_mark_sections_lock(bool $locked): void
{
    if (!$locked) {
        execute_sql('DELETE FROM mark_locks');
        return;
    }

    foreach (departments() as $department) {
        foreach ([2, 4, 6] as $semesterNo) {
            set_mark_section_lock((int) $department['id'], $semesterNo, true);
        }
    }
}

function semester_numbers(): array
{
    return [2, 4, 6];
}

function students_for_class(int $departmentId, int $semesterNo): array
{
    return query_all(
        'SELECT * FROM students WHERE department_id = :department_id AND semester_no = :semester_no ORDER BY full_name',
        ['department_id' => $departmentId, 'semester_no' => $semesterNo]
    );
}

function delete_student_record(int $studentId): void
{
    execute_sql('DELETE FROM students WHERE id = :id', ['id' => $studentId]);
}

function delete_student_records(array $studentIds): int
{
    $studentIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): int => (int) $value,
        $studentIds
    ), static fn (int $value): bool => $value > 0)));

    if ($studentIds === []) {
        return 0;
    }

    db()->beginTransaction();
    try {
        foreach ($studentIds as $studentId) {
            execute_sql('DELETE FROM students WHERE id = :id', ['id' => $studentId]);
        }
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return count($studentIds);
}

function update_teacher_account(int $teacherId, string $fullName, string $email, int $departmentId, ?string $password = null): void
{
    $teacher = query_one('SELECT * FROM teachers WHERE id = :id LIMIT 1', ['id' => $teacherId]);
    if (!$teacher) {
        throw new RuntimeException('Faculty account not found.');
    }

    $fullName = trim($fullName);
    if ($fullName === '') {
        throw new RuntimeException('Faculty name is required.');
    }

    $email = validate_email_address($email);
    $emailExists = query_one('SELECT id FROM teachers WHERE email = :email AND id != :id LIMIT 1', ['email' => $email, 'id' => $teacherId]);
    if ($emailExists) {
        throw new RuntimeException('Another faculty account already uses this email address.');
    }

    $params = [
        'id' => $teacherId,
        'department_id' => $departmentId,
        'full_name' => $fullName,
        'email' => $email,
    ];

    $sql = 'UPDATE teachers
            SET department_id = :department_id,
                full_name = :full_name,
                email = :email,
                updated_at = NOW()';

    if ($password !== null && trim($password) !== '') {
        validate_password_strength($password);
        $sql .= ', password_hash = :password_hash';
        $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE id = :id';
    execute_sql($sql, $params);
}

function update_admin_profile(int $adminId, string $fullName, string $email, ?string $phoneNumber = null, ?string $profileImagePath = null): void
{
    $admin = query_one('SELECT * FROM admins WHERE id = :id LIMIT 1', ['id' => $adminId]);
    if (!$admin) {
        throw new RuntimeException('Administrator account not found.');
    }

    $fullName = trim($fullName);
    if ($fullName === '') {
        throw new RuntimeException('Administrator name is required.');
    }

    $email = validate_email_address($email);
    $emailExists = query_one('SELECT id FROM admins WHERE email = :email AND id != :id LIMIT 1', ['email' => $email, 'id' => $adminId]);
    if ($emailExists) {
        throw new RuntimeException('Another administrator account already uses this email address.');
    }

    execute_sql(
        'UPDATE admins
         SET full_name = :full_name,
             email = :email,
             phone_number = :phone_number,
             profile_image_path = :profile_image_path,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $adminId,
            'full_name' => $fullName,
            'email' => $email,
            'phone_number' => normalize_phone_number($phoneNumber),
            'profile_image_path' => $profileImagePath,
        ]
    );
}

function update_teacher_profile(int $teacherId, string $fullName, string $email, ?string $phoneNumber = null, ?string $profileImagePath = null, ?string $currentPassword = null, ?string $newPassword = null): void
{
    $teacher = query_one('SELECT * FROM teachers WHERE id = :id LIMIT 1', ['id' => $teacherId]);
    if (!$teacher) {
        throw new RuntimeException('Faculty account not found.');
    }

    $fullName = trim($fullName);
    if ($fullName === '') {
        throw new RuntimeException('Faculty name is required.');
    }

    $email = validate_email_address($email);
    $emailExists = query_one('SELECT id FROM teachers WHERE email = :email AND id != :id LIMIT 1', ['email' => $email, 'id' => $teacherId]);
    if ($emailExists) {
        throw new RuntimeException('Another faculty account already uses this email address.');
    }

    $params = [
        'id' => $teacherId,
        'full_name' => $fullName,
        'email' => $email,
        'phone_number' => normalize_phone_number($phoneNumber),
        'profile_image_path' => $profileImagePath,
    ];
    $sql = 'UPDATE teachers
            SET full_name = :full_name,
                email = :email,
                phone_number = :phone_number,
                profile_image_path = :profile_image_path,
                updated_at = NOW()';

    if ($newPassword !== null && trim($newPassword) !== '') {
        if ($currentPassword === null || !password_verify($currentPassword, $teacher['password_hash'])) {
            throw new RuntimeException('Current password is incorrect.');
        }

        validate_password_strength($newPassword);
        $sql .= ', password_hash = :password_hash';
        $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE id = :id';
    execute_sql($sql, $params);
}

function update_student_profile(int $studentId, string $email, ?string $phoneNumber = null, ?string $profileImagePath = null): void
{
    $student = query_one('SELECT * FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    if (!$student) {
        throw new RuntimeException('Student account not found.');
    }

    $email = validate_email_address($email);
    $emailExists = query_one('SELECT id FROM students WHERE email = :email AND id != :id LIMIT 1', ['email' => $email, 'id' => $studentId]);
    if ($emailExists) {
        throw new RuntimeException('This email is already used by another student account.');
    }

    execute_sql(
        'UPDATE students
         SET email = :email,
             phone_number = :phone_number,
             profile_image_path = :profile_image_path,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $studentId,
            'email' => $email,
            'phone_number' => normalize_phone_number($phoneNumber),
            'profile_image_path' => $profileImagePath,
        ]
    );
}

function register_student_account_with_email(string $enrollmentNo, int $departmentId, string $email, string $password, ?string $phoneNumber = null, ?string $profileImagePath = null): bool
{
    $student = query_one(
        'SELECT * FROM students WHERE enrollment_no = :enrollment_no AND department_id = :department_id LIMIT 1',
        ['enrollment_no' => strtoupper(trim($enrollmentNo)), 'department_id' => $departmentId]
    );

    if (!$student) {
        return false;
    }

    if (!empty($student['password_hash'])) {
        throw new RuntimeException('This student is already registered.');
    }

    $email = validate_email_address($email);
    validate_password_strength($password);

    $emailExists = query_one('SELECT id FROM students WHERE email = :email AND id != :id LIMIT 1', ['email' => $email, 'id' => $student['id']]);
    if ($emailExists) {
        throw new RuntimeException('This email is already used by another student account.');
    }

    execute_sql(
        'UPDATE students
         SET email = :email,
             phone_number = :phone_number,
             profile_image_path = :profile_image_path,
             password_hash = :password_hash,
             registered_at = NOW(),
             updated_at = NOW()
         WHERE id = :id',
        [
            'email' => $email,
            'phone_number' => normalize_phone_number($phoneNumber),
            'profile_image_path' => $profileImagePath,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $student['id'],
        ]
    );

    return true;
}

function create_password_reset_otp(string $role, string $email): ?array
{
    $email = validate_email_address($email);

    $target = match ($role) {
        'admin' => query_one('SELECT id, full_name AS name, email FROM admins WHERE email = :email LIMIT 1', ['email' => $email]),
        'teacher' => query_one('SELECT id, full_name AS name, email FROM teachers WHERE email = :email LIMIT 1', ['email' => $email]),
        'student' => query_one('SELECT id, full_name AS name, email FROM students WHERE email = :email LIMIT 1', ['email' => $email]),
        default => null,
    };

    if (!$target) {
        return null;
    }

    $otp = (string) random_int(100000, 999999);
    execute_sql(
        'INSERT INTO password_reset_otps (role_name, user_ref_id, email, otp_code, purpose, expires_at)
         VALUES (:role_name, :user_ref_id, :email, :otp_code, :purpose, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
        [
            'role_name' => $role,
            'user_ref_id' => (int) $target['id'],
            'email' => $email,
            'otp_code' => $otp,
            'purpose' => 'password_reset',
        ]
    );

    return ['otp' => $otp, 'target' => $target];
}

function reset_password_with_otp(string $role, string $email, string $otp, string $newPassword): bool
{
    $email = validate_email_address($email);
    $target = match ($role) {
        'admin' => query_one('SELECT id FROM admins WHERE email = :email LIMIT 1', ['email' => $email]),
        'teacher' => query_one('SELECT id FROM teachers WHERE email = :email LIMIT 1', ['email' => $email]),
        'student' => query_one('SELECT id FROM students WHERE email = :email LIMIT 1', ['email' => $email]),
        default => null,
    };

    if (!$target) {
        return false;
    }

    $otpRow = query_one(
        'SELECT * FROM password_reset_otps
         WHERE role_name = :role_name AND user_ref_id = :user_ref_id AND email = :email AND otp_code = :otp_code
           AND purpose = "password_reset" AND consumed_at IS NULL AND expires_at >= NOW()
         ORDER BY id DESC LIMIT 1',
        [
            'role_name' => $role,
            'user_ref_id' => (int) $target['id'],
            'email' => $email,
            'otp_code' => trim($otp),
        ]
    );

    if (!$otpRow) {
        return false;
    }

    $table = match ($role) {
        'admin' => 'admins',
        'teacher' => 'teachers',
        'student' => 'students',
        default => null,
    };

    if ($table === null) {
        return false;
    }

    validate_password_strength($newPassword);

    db()->beginTransaction();
    try {
        execute_sql(
            "UPDATE {$table} SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id",
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => (int) $target['id']]
        );
        execute_sql('UPDATE password_reset_otps SET consumed_at = NOW() WHERE id = :id', ['id' => $otpRow['id']]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return true;
}

function audit_trimmed_value(?string $value, int $maxLength): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function audit_actor_snapshot(string $role, string $userIdentifier): array
{
    static $cache = [];

    $identifier = trim($userIdentifier);
    $cacheKey = strtolower($role) . '|' . $identifier;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $snapshot = ['actor_id' => null, 'actor_name' => null, 'actor_reference' => null];
    if ($identifier === '') {
        return $cache[$cacheKey] = $snapshot;
    }

    $row = null;
    if ($role === 'admin') {
        $row = ctype_digit($identifier)
            ? query_one('SELECT id, full_name, username FROM admins WHERE id = :id LIMIT 1', ['id' => (int) $identifier])
            : null;
        if (!$row) {
            $row = query_one('SELECT id, full_name, username FROM admins WHERE username = :identifier OR email = :identifier LIMIT 1', ['identifier' => $identifier]);
        }
        if ($row) {
            $snapshot = ['actor_id' => (int) $row['id'], 'actor_name' => (string) $row['full_name'], 'actor_reference' => (string) ($row['username'] ?? '')];
        }
    } elseif ($role === 'teacher') {
        $teacherId = null;
        if (preg_match('/teacher#(\d+)/i', $identifier, $matches)) {
            $teacherId = (int) $matches[1];
        } elseif (ctype_digit($identifier)) {
            $teacherId = (int) $identifier;
        }
        if ($teacherId !== null && $teacherId > 0) {
            $row = query_one('SELECT id, full_name, teacher_code FROM teachers WHERE id = :id LIMIT 1', ['id' => $teacherId]);
        }
        if (!$row) {
            $row = query_one('SELECT id, full_name, teacher_code FROM teachers WHERE teacher_code = :identifier OR email = :identifier LIMIT 1', ['identifier' => $identifier]);
        }
        if ($row) {
            $snapshot = ['actor_id' => (int) $row['id'], 'actor_name' => (string) $row['full_name'], 'actor_reference' => (string) ($row['teacher_code'] ?? '')];
        }
    } elseif ($role === 'student') {
        $row = ctype_digit($identifier)
            ? query_one('SELECT id, full_name, enrollment_no FROM students WHERE id = :id LIMIT 1', ['id' => (int) $identifier])
            : null;
        if (!$row) {
            $row = query_one('SELECT id, full_name, enrollment_no FROM students WHERE enrollment_no = :identifier OR email = :identifier LIMIT 1', ['identifier' => strtoupper($identifier)]);
        }
        if ($row) {
            $snapshot = ['actor_id' => (int) $row['id'], 'actor_name' => (string) $row['full_name'], 'actor_reference' => (string) ($row['enrollment_no'] ?? '')];
        }
    }

    return $cache[$cacheKey] = $snapshot;
}

function audit_request_snapshot(): array
{
    $fingerprintSource = implode('|', [session_status() === PHP_SESSION_ACTIVE ? session_id() : '', client_ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')]);

    return [
        'ip_address' => client_ip(),
        'user_agent' => audit_trimmed_value((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 2000),
        'request_method' => audit_trimmed_value((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 10),
        'request_path' => audit_trimmed_value((string) ($_SERVER['REQUEST_URI'] ?? ''), 255),
        'referer' => audit_trimmed_value((string) ($_SERVER['HTTP_REFERER'] ?? ''), 255),
        'forwarded_for' => audit_trimmed_value((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 255),
        'accept_language' => audit_trimmed_value((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 120),
        'session_fingerprint' => $fingerprintSource !== '||' ? hash('sha256', $fingerprintSource) : null,
    ];
}

function audit_user_agent_profile(?string $userAgent): array
{
    $userAgent = trim((string) $userAgent);
    $deviceType = preg_match('/ipad|tablet|sm-t|nexus 7|nexus 10|lenovo tab/i', $userAgent) ? 'Tablet' : (preg_match('/mobile|iphone|ipod|android/i', $userAgent) ? 'Mobile' : 'Desktop');
    $osName = 'Unknown OS';
    if (preg_match('/Android\s+([\d.]+)/i', $userAgent, $matches)) {
        $osName = 'Android ' . $matches[1];
    } elseif (preg_match('/iPhone OS ([\d_]+)/i', $userAgent, $matches)) {
        $osName = 'iOS ' . str_replace('_', '.', $matches[1]);
    } elseif (preg_match('/CPU OS ([\d_]+)/i', $userAgent, $matches)) {
        $osName = 'iPadOS ' . str_replace('_', '.', $matches[1]);
    } elseif (preg_match('/Windows NT 10\.0/i', $userAgent)) {
        $osName = 'Windows 10/11';
    } elseif (preg_match('/Mac OS X ([\d_]+)/i', $userAgent, $matches)) {
        $osName = 'macOS ' . str_replace('_', '.', $matches[1]);
    } elseif (preg_match('/Ubuntu/i', $userAgent)) {
        $osName = 'Ubuntu';
    } elseif (preg_match('/Linux/i', $userAgent)) {
        $osName = 'Linux';
    }

    $browserName = 'Unknown Browser';
    $browserVersion = '';
    foreach (['Edge' => '/Edg\/([0-9.]+)/i', 'Opera' => '/OPR\/([0-9.]+)/i', 'Chrome' => '/Chrome\/([0-9.]+)/i', 'Firefox' => '/Firefox\/([0-9.]+)/i', 'Safari' => '/Version\/([0-9.]+).*Safari/i'] as $name => $pattern) {
        if (preg_match($pattern, $userAgent, $matches)) {
            $browserName = $name;
            $browserVersion = $matches[1] ?? '';
            break;
        }
    }

    $deviceName = 'Unknown Device';
    if (preg_match('/iPhone/i', $userAgent)) {
        $deviceName = 'iPhone';
    } elseif (preg_match('/iPad/i', $userAgent)) {
        $deviceName = 'iPad';
    } elseif (preg_match('/Pixel\s+[A-Za-z0-9 ]+/i', $userAgent, $matches)) {
        $deviceName = trim($matches[0]);
    } elseif (preg_match('/SM-[A-Z0-9-]+/i', $userAgent, $matches)) {
        $deviceName = strtoupper($matches[0]);
    } elseif (preg_match('/Android [^;]+; ([^;)\]]+)/i', $userAgent, $matches)) {
        $candidate = trim((string) preg_replace('/\s+Build\/.+$/i', '', $matches[1]));
        $deviceName = $candidate !== '' ? $candidate : $deviceName;
    } elseif (str_contains($osName, 'Windows')) {
        $deviceName = 'Windows PC';
    } elseif (str_starts_with($osName, 'macOS')) {
        $deviceName = 'Mac';
    } elseif ($osName === 'Ubuntu') {
        $deviceName = 'Ubuntu PC';
    } elseif ($osName === 'Linux') {
        $deviceName = 'Linux PC';
    }

    return ['device_type' => $deviceType, 'device_name' => $deviceName, 'browser_name' => $browserName, 'browser_version' => $browserVersion, 'os_name' => $osName];
}

function audit_action_label(string $actionCode): string
{
    $label = ucwords(strtolower(str_replace('_', ' ', trim($actionCode))));
    $label = preg_replace('/\bId\b/', 'ID', $label) ?? $label;
    $label = preg_replace('/\bIp\b/', 'IP', $label) ?? $label;
    $label = preg_replace('/\bCsv\b/', 'CSV', $label) ?? $label;
    $label = preg_replace('/\bOtp\b/', 'OTP', $label) ?? $label;

    return match (strtoupper(trim($actionCode))) {
        'MARKS_SAVE' => 'Marks Upload Saved',
        'MARKS_DELETE' => 'Marks Upload Deleted',
        'ATTENDANCE_SAVED' => 'Attendance Saved',
        'ASSIGNMENT_TRACKER_SAVED' => 'Assignment Tracker Saved',
        default => $label,
    };
}

function audit_details_summary(array $row): string
{
    $details = trim((string) ($row['details'] ?? ''));
    $actionCode = strtoupper(trim((string) ($row['action_code'] ?? '')));
    $uploadId = preg_match('/upload\s*#?\s*(\d+)/i', $details, $matches) ? (int) ($matches[1] ?? 0) : 0;
    if (in_array($actionCode, ['MARKS_SAVE', 'MARKS_DELETE'], true) && $uploadId > 0) {
        $context = query_one(
            'SELECT mu.exam_type, mu.max_marks, mu.semester_no, d.name AS department_name, d.short_name AS department_short_name, s.subject_name, COUNT(mr.id) AS record_count
             FROM mark_uploads mu
             LEFT JOIN departments d ON d.id = mu.department_id
             LEFT JOIN subjects s ON s.id = mu.subject_id
             LEFT JOIN mark_records mr ON mr.mark_upload_id = mu.id
             WHERE mu.id = :id
             GROUP BY mu.id, mu.exam_type, mu.max_marks, mu.semester_no, d.name, d.short_name, s.subject_name',
            ['id' => $uploadId]
        );
        if ($context) {
            $maxMarks = (float) ($context['max_marks'] ?? 0);
            $maxLabel = abs($maxMarks - round($maxMarks)) < 0.00001 ? (string) (int) round($maxMarks) : rtrim(rtrim(number_format($maxMarks, 2, '.', ''), '0'), '.');
            return ($actionCode === 'MARKS_DELETE' ? 'Deleted ' : 'Saved ')
                . (string) ($context['exam_type'] ?? 'assessment')
                . ' for ' . trim((string) ($context['department_short_name'] ?? $context['department_name'] ?? 'Department'))
                . ' / ' . semester_label((int) ($context['semester_no'] ?? 0))
                . ' / ' . trim((string) ($context['subject_name'] ?? 'Subject'))
                . ' (' . (int) ($context['record_count'] ?? 0) . ' records, max ' . $maxLabel . ')';
        }
    }

    return $details !== '' ? $details : 'No additional note recorded.';
}

function audit_hydrate_row(array $row): array
{
    $snapshot = audit_actor_snapshot((string) ($row['role_name'] ?? ''), (string) ($row['user_identifier'] ?? ''));
    $profile = audit_user_agent_profile((string) ($row['user_agent'] ?? ''));
    $userIdentifier = trim((string) ($row['user_identifier'] ?? ''));
    $displayName = trim((string) ($row['actor_name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string) ($snapshot['actor_name'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = $userIdentifier !== '' ? $userIdentifier : 'Unknown Actor';
    }

    $secondary = trim((string) ($row['actor_reference'] ?? ''));
    if ($secondary === '') {
        $secondary = trim((string) ($snapshot['actor_reference'] ?? ''));
    }
    if ($secondary === '' && $userIdentifier !== '' && $userIdentifier !== $displayName) {
        $secondary = $userIdentifier;
    }
    if ($secondary === $displayName) {
        $secondary = '';
    }

    $row['actor_id_resolved'] = $row['actor_id'] !== null ? (int) $row['actor_id'] : (($snapshot['actor_id'] ?? null) !== null ? (int) $snapshot['actor_id'] : null);
    $row['actor_display_name'] = $displayName;
    $row['actor_secondary'] = $secondary;
    $row['action_label'] = audit_action_label((string) ($row['action_code'] ?? ''));
    $row['details_summary'] = audit_details_summary($row);
    $row['device_type'] = $profile['device_type'];
    $row['device_name'] = $profile['device_name'];
    $row['browser_name'] = $profile['browser_name'];
    $row['browser_version'] = $profile['browser_version'];
    $row['os_name'] = $profile['os_name'];

    return $row;
}

function audit_log(string $role, string $userIdentifier, string $actionCode, ?string $details = null): void
{
    $actor = audit_actor_snapshot($role, $userIdentifier);
    $request = audit_request_snapshot();

    execute_sql(
        'INSERT INTO audit_logs (role_name, user_identifier, actor_id, actor_name, actor_reference, ip_address, action_code, details, user_agent, request_method, request_path, referer, forwarded_for, accept_language, session_fingerprint)
         VALUES (:role_name, :user_identifier, :actor_id, :actor_name, :actor_reference, :ip_address, :action_code, :details, :user_agent, :request_method, :request_path, :referer, :forwarded_for, :accept_language, :session_fingerprint)',
        [
            'role_name' => $role,
            'user_identifier' => $userIdentifier,
            'actor_id' => $actor['actor_id'],
            'actor_name' => $actor['actor_name'],
            'actor_reference' => $actor['actor_reference'],
            'ip_address' => $request['ip_address'],
            'action_code' => strtoupper($actionCode),
            'details' => audit_trimmed_value($details, 5000),
            'user_agent' => $request['user_agent'],
            'request_method' => $request['request_method'],
            'request_path' => $request['request_path'],
            'referer' => $request['referer'],
            'forwarded_for' => $request['forwarded_for'],
            'accept_language' => $request['accept_language'],
            'session_fingerprint' => $request['session_fingerprint'],
        ]
    );
}

function audit_log_rows(?string $role = null, string $search = ''): array
{
    $sql = 'SELECT * FROM audit_logs WHERE 1 = 1';
    $params = [];
    if ($role !== null && $role !== '' && $role !== 'all') {
        $sql .= ' AND role_name = :role_name';
        $params['role_name'] = $role;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';

    $rows = array_map('audit_hydrate_row', query_all($sql, $params));
    if ($search === '') {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row) use ($search): bool {
        foreach ([(string) ($row['actor_display_name'] ?? ''), (string) ($row['actor_secondary'] ?? ''), (string) ($row['role_name'] ?? ''), (string) ($row['ip_address'] ?? ''), (string) ($row['action_code'] ?? ''), (string) ($row['action_label'] ?? ''), (string) ($row['details'] ?? ''), (string) ($row['details_summary'] ?? ''), (string) ($row['request_path'] ?? ''), (string) ($row['device_name'] ?? ''), (string) ($row['browser_name'] ?? ''), (string) ($row['os_name'] ?? ''), (string) ($row['user_agent'] ?? '')] as $value) {
            if ($value !== '' && stripos($value, $search) !== false) {
                return true;
            }
        }

        return false;
    }));
}

function audit_log_entry(int $id): ?array
{
    $row = query_one('SELECT * FROM audit_logs WHERE id = :id LIMIT 1', ['id' => $id]);

    return $row ? audit_hydrate_row($row) : null;
}

function clear_audit_logs(): void
{
    execute_sql('DELETE FROM audit_logs');
}
function parse_marks_csv_records_with_absent(string $tmpName, array $studentMap, float $maxMarks): array
{
    $handle = fopen($tmpName, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded CSV file.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('The uploaded CSV file is empty.');
    }

    $normalized = [];
    foreach ($header as $index => $column) {
        $columnName = strtolower(trim((string) $column));
        $columnName = (string) preg_replace('/^\xEF\xBB\xBF/', '', $columnName);
        $columnName = (string) preg_replace('/\s+/', ' ', $columnName);
        $normalized[$columnName] = $index;
    }

    $enrollmentIndex = $normalized['enrollment no'] ?? $normalized['enrollment'] ?? $normalized['enrollment number'] ?? null;
    $marksIndex = $normalized['marks'] ?? $normalized['score'] ?? null;
    $absentIndex = $normalized['absent (true/false)']
        ?? $normalized['absent(true/false)']
        ?? $normalized['absent']
        ?? $normalized['is absent']
        ?? null;

    if ($enrollmentIndex === null || $marksIndex === null) {
        fclose($handle);
        throw new RuntimeException('CSV must contain Enrollment and Marks columns.');
    }

    $records = [];
    $violations = [];
    while (($row = fgetcsv($handle)) !== false) {
        $enrollment = strtoupper(trim((string) ($row[$enrollmentIndex] ?? '')));
        if ($enrollment === '' || !isset($studentMap[$enrollment])) {
            continue;
        }

        $student = $studentMap[$enrollment];
        $studentId = (int) ($student['id'] ?? 0);
        if ($studentId <= 0) {
            continue;
        }

        $absent = false;
        if ($absentIndex !== null) {
            $absentValue = strtolower(trim((string) ($row[$absentIndex] ?? '')));
            if ($absentValue !== '') {
                if (in_array($absentValue, ['yes', 'y', 'true', 't', '1', 'ab', 'absent'], true)) {
                    $absent = true;
                } elseif (in_array($absentValue, ['no', 'n', 'false', 'f', '0', 'present', 'p'], true)) {
                    $absent = false;
                } else {
                    fclose($handle);
                    throw new RuntimeException('Absent value for enrollment ' . $enrollment . ' must be True or False.');
                }
            }
        }

        $marksValue = trim((string) ($row[$marksIndex] ?? ''));
        if ($marksValue === '' && !$absent) {
            continue;
        }
        if ($absent) {
            $records[$studentId] = [
                'marks' => null,
                'absent' => true,
            ];
            unset($violations[$studentId]);
            continue;
        }
        if (!is_numeric($marksValue)) {
            fclose($handle);
            throw new RuntimeException('Invalid marks value found for enrollment ' . $enrollment . '.');
        }

        $numericMarks = (float) $marksValue;
        if ($numericMarks < 0) {
            fclose($handle);
            throw new RuntimeException('Marks cannot be less than 0 for enrollment ' . $enrollment . '.');
        }

        $savedMarks = $numericMarks;
        if ($numericMarks > $maxMarks) {
            $savedMarks = 0.0;
            $violations[$studentId] = [
                'student_id' => $studentId,
                'enrollment_no' => (string) ($student['enrollment_no'] ?? $enrollment),
                'full_name' => (string) ($student['full_name'] ?? ''),
                'entered_marks' => $marksValue,
                'allowed_max' => $maxMarks,
                'saved_marks' => $savedMarks,
            ];
        } else {
            unset($violations[$studentId]);
        }

        $records[$studentId] = [
            'marks' => $savedMarks,
            'absent' => false,
        ];
    }

    fclose($handle);

    return [
        'records' => $records,
        'violations' => array_values($violations),
    ];
}

function save_mark_upload_sheet(int $teacherId, int $departmentId, int $semesterNo, int $subjectId, int $markTypeId, array $records): void
{
    if (mark_section_locked($departmentId, $semesterNo)) {
        throw new RuntimeException('Marks are locked for this department and semester.');
    }

    $markType = mark_type_by_id($markTypeId);
    if (!$markType) {
        throw new RuntimeException('Selected mark type was not found.');
    }

    $academicYearId = current_academic_year_id();
    $examType = (string) $markType['label'];
    $maxMarks = (float) $markType['max_marks'];

    db()->beginTransaction();
    try {
        $upload = query_one(
            'SELECT id FROM mark_uploads
             WHERE academic_year_id = :academic_year_id AND department_id = :department_id AND semester_no = :semester_no AND subject_id = :subject_id AND exam_type = :exam_type',
            [
                'academic_year_id' => $academicYearId,
                'department_id' => $departmentId,
                'semester_no' => $semesterNo,
                'subject_id' => $subjectId,
                'exam_type' => $examType,
            ]
        );

        if ($upload) {
            execute_sql(
                'UPDATE mark_uploads SET max_marks = :max_marks, teacher_id = :teacher_id, uploaded_at = NOW() WHERE id = :id',
                ['max_marks' => $maxMarks, 'teacher_id' => $teacherId, 'id' => $upload['id']]
            );
            $uploadId = (int) $upload['id'];
            execute_sql('DELETE FROM mark_records WHERE mark_upload_id = :mark_upload_id', ['mark_upload_id' => $uploadId]);
        } else {
            execute_sql(
                'INSERT INTO mark_uploads (academic_year_id, department_id, semester_no, subject_id, exam_type, max_marks, teacher_id)
                 VALUES (:academic_year_id, :department_id, :semester_no, :subject_id, :exam_type, :max_marks, :teacher_id)',
                [
                    'academic_year_id' => $academicYearId,
                    'department_id' => $departmentId,
                    'semester_no' => $semesterNo,
                    'subject_id' => $subjectId,
                    'exam_type' => $examType,
                    'max_marks' => $maxMarks,
                    'teacher_id' => $teacherId,
                ]
            );
            $uploadId = (int) db()->lastInsertId();
        }

        foreach ($records as $studentId => $record) {
            $isAbsent = (bool) ($record['absent'] ?? false);
            $marksValue = $record['marks'] ?? null;
            if (!$isAbsent && $marksValue === null) {
                continue;
            }

            if (!$isAbsent) {
                $numericMarks = (float) $marksValue;
                if ($numericMarks < 0 || $numericMarks > $maxMarks) {
                    throw new RuntimeException('Marks cannot be less than 0 or greater than ' . $maxMarks . ' for ' . $examType . '.');
                }
                $marksValue = $numericMarks;
            }

            execute_sql(
                'INSERT INTO mark_records (mark_upload_id, student_id, marks_obtained, is_absent)
                 VALUES (:mark_upload_id, :student_id, :marks_obtained, :is_absent)',
                [
                    'mark_upload_id' => $uploadId,
                    'student_id' => (int) $studentId,
                    'marks_obtained' => $isAbsent ? null : $marksValue,
                    'is_absent' => $isAbsent ? 1 : 0,
                ]
            );
        }

        $departmentRow = query_one('SELECT name, short_name FROM departments WHERE id = :id LIMIT 1', ['id' => $departmentId]);
        $subjectRow = query_one('SELECT subject_name FROM subjects WHERE id = :id LIMIT 1', ['id' => $subjectId]);
        $departmentLabel = trim((string) ($departmentRow['short_name'] ?? $departmentRow['name'] ?? ('Department ' . $departmentId)));
        $subjectLabel = trim((string) ($subjectRow['subject_name'] ?? ('Subject ' . $subjectId)));
        $storedRecordCount = count($records);
        $maxMarksLabel = abs($maxMarks - round($maxMarks)) < 0.00001
            ? (string) (int) round($maxMarks)
            : rtrim(rtrim(number_format($maxMarks, 2, '.', ''), '0'), '.');
        audit_log(
            'teacher',
            (string) $teacherId,
            'MARKS_SAVE',
            'Upload #' . $uploadId . ' saved for ' . $departmentLabel . ' / ' . semester_label($semesterNo) . ' / ' . $subjectLabel . ' / ' . $examType . ' (' . $storedRecordCount . ' records, max ' . $maxMarksLabel . ')'
        );
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function mark_upload_detail_by_type(int $departmentId, int $semesterNo, int $subjectId, int $markTypeId): ?array
{
    $markType = mark_type_by_id($markTypeId);
    if (!$markType) {
        return null;
    }

    return query_one(
        'SELECT * FROM mark_uploads
         WHERE academic_year_id = :academic_year_id AND department_id = :department_id AND semester_no = :semester_no AND subject_id = :subject_id AND exam_type = :exam_type
         LIMIT 1',
        [
            'academic_year_id' => current_academic_year_id(),
            'department_id' => $departmentId,
            'semester_no' => $semesterNo,
            'subject_id' => $subjectId,
            'exam_type' => $markType['label'],
        ]
    );
}

function mark_records_map_for_upload(int $uploadId): array
{
    $map = [];
    foreach (query_all('SELECT * FROM mark_records WHERE mark_upload_id = :mark_upload_id', ['mark_upload_id' => $uploadId]) as $row) {
        $map[(int) $row['student_id']] = $row;
    }

    return $map;
}

function format_marks_value(float $value): string
{
    $rounded = round($value, 2);
    if (abs($rounded - round($rounded)) < 0.01) {
        return number_format((float) round($rounded), 0, '.', '');
    }

    return number_format($rounded, 2, '.', '');
}

function mark_type_short_label(array|string $markType): string
{
    $label = strtolower(trim((string) (is_array($markType) ? ($markType['label'] ?? '') : $markType)));

    return match ($label) {
        'sessional exam i' => 'SE-I',
        'sessional exam ii' => 'SE-II',
        'pre board' => 'PB',
        'assignment' => 'ASSG',
        'attendance' => 'ATTD',
        'practical / lab' => 'LAB',
        default => strtoupper(substr(preg_replace('/[^a-z0-9]+/i', '', $label) ?? 'MT', 0, 8)),
    };
}

function report_card_payload_for_student_id(int $studentId, ?int $markTypeId = null): ?array
{
    $student = student_by_id($studentId);
    if (!$student) {
        return null;
    }

    $departmentId = (int) $student['department_id'];
    $semesterNo = (int) $student['semester_no'];
    $subjects = subjects_for($departmentId, $semesterNo);
    if ($markTypeId !== null && $markTypeId > 0) {
        $selectedMarkType = mark_type_by_id($markTypeId);
        if (!$selectedMarkType) {
            return null;
        }
        $markTypes = [$selectedMarkType];
    } else {
        $markTypes = mark_type_rows();
    }
    $rows = [];
    $grandTotal = 0.0;
    $grandMax = 0.0;

    foreach ($subjects as $subject) {
        $row = [
            'subject_name' => $subject['subject_name'],
            'subject_code' => (string) ($subject['subject_code'] ?? ''),
            'subject_short_name' => subject_short_label($subject),
            'marks' => [],
            'total' => 0.0,
            'max' => 0.0,
            'grade' => '--',
        ];

        foreach ($markTypes as $markType) {
            $upload = mark_upload_detail_by_type($departmentId, $semesterNo, (int) $subject['id'], (int) $markType['id']);
            $entry = null;
            if ($upload) {
                $entry = query_one(
                    'SELECT * FROM mark_records WHERE mark_upload_id = :mark_upload_id AND student_id = :student_id LIMIT 1',
                    ['mark_upload_id' => $upload['id'], 'student_id' => $studentId]
                );
            }

            $display = '--';
            if ($entry) {
                if ((int) ($entry['is_absent'] ?? 0) === 1) {
                    $display = 'AB';
                } elseif ($entry['marks_obtained'] !== null) {
                    $display = format_marks_value((float) $entry['marks_obtained']);
                    $row['total'] += (float) $entry['marks_obtained'];
                    $grandTotal += (float) $entry['marks_obtained'];
                }
            }

            $row['marks'][] = [
                'label' => $markType['label'],
                'max_marks' => (float) $markType['max_marks'],
                'display' => $display,
            ];
            $row['max'] += (float) $markType['max_marks'];
            $grandMax += (float) $markType['max_marks'];
        }

        $row['grade'] = $row['max'] > 0 ? grade_from_marks((float) $row['total'], (float) $row['max']) : '--';
        $rows[] = $row;
    }

    $attendance = attendance_summary_for_student($studentId);

    return [
        'student' => $student,
        'rows' => $rows,
        'mark_types' => $markTypes,
        'selected_mark_type' => $markTypes[0] ?? null,
        'attendance' => $attendance,
        'grand_total' => $grandTotal,
        'grand_max' => $grandMax,
        'grand_grade' => $grandMax > 0 ? grade_from_marks($grandTotal, $grandMax) : '--',
    ];
}

function class_report_card_payloads(int $departmentId, int $semesterNo, ?int $studentId = null, ?int $markTypeId = null): array
{
    $students = $studentId !== null
        ? array_values(array_filter(students_for_class($departmentId, $semesterNo), static fn (array $row): bool => (int) $row['id'] === $studentId))
        : students_for_class($departmentId, $semesterNo);

    $payloads = [];
    foreach ($students as $student) {
        $payload = report_card_payload_for_student_id((int) $student['id'], $markTypeId);
        if ($payload) {
            $payloads[] = $payload;
        }
    }

    return $payloads;
}

function class_report_card_matrix_rows(int $departmentId, int $semesterNo, int $markTypeId): array
{
    $markType = mark_type_by_id($markTypeId);
    if (!$markType) {
        return ['subjects' => [], 'rows' => [], 'mark_type' => null];
    }

    $subjects = subjects_for($departmentId, $semesterNo);
    $students = students_for_class($departmentId, $semesterNo);
    if ($subjects === [] || $students === []) {
        return ['subjects' => $subjects, 'rows' => [], 'mark_type' => $markType];
    }

    $uploads = query_all(
        'SELECT id, subject_id, max_marks
         FROM mark_uploads
         WHERE academic_year_id = :academic_year_id
           AND department_id = :department_id
           AND semester_no = :semester_no
           AND exam_type = :exam_type',
        [
            'academic_year_id' => current_academic_year_id(),
            'department_id' => $departmentId,
            'semester_no' => $semesterNo,
            'exam_type' => (string) $markType['label'],
        ]
    );

    $uploadsBySubject = [];
    foreach ($uploads as $upload) {
        $uploadsBySubject[(int) $upload['subject_id']][] = $upload;
    }

    $recordsByUpload = mark_records_grouped_by_upload_ids(array_map(static fn (array $row): int => (int) $row['id'], $uploads));
    $rows = [];

    foreach ($students as $student) {
        $subjectCells = [];
        $totalMarks = 0.0;
        $scoredSubjects = 0;
        $recordedSubjects = 0;

        foreach ($subjects as $subject) {
            $subjectUploads = $uploadsBySubject[(int) $subject['id']] ?? [];
            $subjectSum = 0.0;
            $subjectScoreCount = 0;
            $hasAbsentRecord = false;
            $hasAnyRecord = false;

            foreach ($subjectUploads as $upload) {
                $record = $recordsByUpload[(int) $upload['id']][(int) $student['id']] ?? null;
                if (!$record) {
                    continue;
                }

                $hasAnyRecord = true;
                if ((int) ($record['is_absent'] ?? 0) === 1) {
                    $hasAbsentRecord = true;
                    continue;
                }

                if ($record['marks_obtained'] !== null) {
                    $subjectSum += (float) $record['marks_obtained'];
                    $subjectScoreCount++;
                }
            }

            $display = '--';
            $averageMarks = null;
            if ($subjectScoreCount > 0) {
                $averageMarks = round($subjectSum / $subjectScoreCount, 2);
                $display = format_marks_value($averageMarks);
                $totalMarks += $averageMarks;
                $scoredSubjects++;
                $recordedSubjects++;
            } elseif ($hasAnyRecord) {
                $display = $hasAbsentRecord ? 'AB' : '--';
                $recordedSubjects++;
            }

            $subjectCells[] = [
                'subject_id' => (int) $subject['id'],
                'subject_name' => (string) $subject['subject_name'],
                'subject_code' => (string) ($subject['subject_code'] ?? ''),
                'subject_short_name' => subject_short_label($subject),
                'display' => $display,
                'average_marks' => $averageMarks,
                'has_record' => $hasAnyRecord,
                'is_absent' => $hasAnyRecord && $hasAbsentRecord && $subjectScoreCount === 0,
            ];
        }

        $rows[] = [
            'student' => $student,
            'subject_cells' => $subjectCells,
            'total_marks' => $scoredSubjects > 0 ? round($totalMarks, 2) : null,
            'total_display' => $scoredSubjects > 0 ? format_marks_value($totalMarks) : ($recordedSubjects > 0 ? 'AB' : '--'),
        ];
    }

    return [
        'subjects' => $subjects,
        'rows' => $rows,
        'mark_type' => $markType,
    ];
}

function class_report_card_full_assessment_matrix(int $departmentId, int $semesterNo): array
{
    $department = department_by_id($departmentId);
    $subjects = subjects_for($departmentId, $semesterNo);
    $students = students_for_class($departmentId, $semesterNo);
    $markTypes = mark_type_rows();
    $subjectRowsByStudent = [];

    foreach ($subjects as $subject) {
        foreach (subject_marks_export_rows($departmentId, $semesterNo, (int) $subject['id']) as $subjectRow) {
            $studentId = (int) (($subjectRow['student']['id'] ?? 0));
            if ($studentId <= 0) {
                continue;
            }

            $subjectRowsByStudent[$studentId][(int) $subject['id']] = $subjectRow;
        }
    }

    $rows = [];
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $cells = [];
        $numericMarks = [];
        $numericPercentages = [];

        foreach ($subjects as $subject) {
            $subjectId = (int) $subject['id'];
            $subjectRow = $subjectRowsByStudent[$studentId][$subjectId] ?? null;

            foreach ($markTypes as $index => $markType) {
                $sourceCell = (array) ($subjectRow['cells'][$index] ?? []);
                $display = (string) ($sourceCell['display'] ?? '--');
                $numericMarksValue = is_numeric($display) ? (float) $display : null;
                $maxMarksValue = (float) ($sourceCell['max_marks'] ?? $markType['max_marks'] ?? 0);
                if ($numericMarksValue !== null) {
                    $numericMarks[] = $numericMarksValue;
                    if ($maxMarksValue > 0) {
                        $numericPercentages[] = ($numericMarksValue / $maxMarksValue) * 100;
                    }
                    $display = format_marks_value($numericMarksValue);
                }

                $cells[] = [
                    'subject_id' => $subjectId,
                    'subject_name' => (string) ($subject['subject_name'] ?? ''),
                    'subject_short_name' => subject_short_label($subject),
                    'mark_type_id' => (int) ($markType['id'] ?? 0),
                    'mark_type_label' => (string) ($markType['label'] ?? ''),
                    'mark_type_short_label' => mark_type_short_label($markType),
                    'max_marks' => $maxMarksValue,
                    'display' => $display,
                    'numeric_marks' => $numericMarksValue,
                ];
            }
        }

        $averageMarks = $numericMarks !== [] ? round(array_sum($numericMarks) / count($numericMarks), 2) : null;
        $averagePercentage = $numericPercentages !== [] ? round(array_sum($numericPercentages) / count($numericPercentages), 2) : null;
        $attendance = attendance_summary_for_student($studentId);

        $rows[] = [
            'student' => $student,
            'cells' => $cells,
            'recorded_count' => count($numericMarks),
            'average_marks' => $averageMarks,
            'average_display' => $averageMarks !== null ? format_marks_value($averageMarks) : '--',
            'average_percentage' => $averagePercentage,
            'average_percentage_display' => $averagePercentage !== null ? format_marks_value($averagePercentage) . '%' : '--',
            'attendance' => $attendance,
            'result' => $averagePercentage !== null ? pass_fail_from_marks($averagePercentage, 100.0) : 'Pending',
        ];
    }

    return [
        'department' => $department,
        'semester_no' => $semesterNo,
        'year_level' => max(1, (int) ceil($semesterNo / 2)),
        'subjects' => $subjects,
        'mark_types' => $markTypes,
        'rows' => $rows,
    ];
}

function report_card_subject_average_export_rows(): array
{
    $rows = [];

    foreach (departments() as $department) {
        $departmentId = (int) $department['id'];
        foreach (semester_numbers() as $semesterNo) {
            $matrix = class_report_card_full_assessment_matrix($departmentId, $semesterNo);
            $subjects = (array) ($matrix['subjects'] ?? []);
            $markTypes = (array) ($matrix['mark_types'] ?? []);
            if ($subjects === []) {
                continue;
            }

            foreach ((array) ($matrix['rows'] ?? []) as $matrixRow) {
                $student = (array) ($matrixRow['student'] ?? []);
                $cells = (array) ($matrixRow['cells'] ?? []);
                $attendance = (array) ($matrixRow['attendance'] ?? []);
                $offset = 0;

                foreach ($subjects as $subject) {
                    $subjectMarks = [];
                    $subjectPercentages = [];
                    $absentCount = 0;

                    foreach ($markTypes as $markType) {
                        $cell = (array) ($cells[$offset] ?? []);
                        $offset++;

                        if (($cell['numeric_marks'] ?? null) !== null) {
                            $marksValue = (float) $cell['numeric_marks'];
                            $subjectMarks[] = $marksValue;

                            $maxMarks = (float) ($cell['max_marks'] ?? 0);
                            if ($maxMarks > 0) {
                                $subjectPercentages[] = ($marksValue / $maxMarks) * 100;
                            }
                        } elseif ((string) ($cell['display'] ?? '--') === 'AB') {
                            $absentCount++;
                        }
                    }

                    $averageMarks = $subjectMarks !== [] ? round(array_sum($subjectMarks) / count($subjectMarks), 2) : null;
                    $averagePercentage = $subjectPercentages !== [] ? round(array_sum($subjectPercentages) / count($subjectPercentages), 2) : null;

                    $rows[] = [
                        'department_name' => (string) ($department['name'] ?? ''),
                        'department_short_name' => (string) ($department['short_name'] ?? ''),
                        'year_level' => max(1, (int) ceil($semesterNo / 2)),
                        'semester_no' => $semesterNo,
                        'student' => $student,
                        'subject_id' => (int) ($subject['id'] ?? 0),
                        'subject_name' => (string) ($subject['subject_name'] ?? ''),
                        'subject_short_name' => subject_short_label($subject),
                        'assessment_count' => count($subjectMarks),
                        'absent_count' => $absentCount,
                        'average_marks' => $averageMarks,
                        'average_display' => $averageMarks !== null ? format_marks_value($averageMarks) : ($absentCount > 0 ? 'AB' : '--'),
                        'average_percentage' => $averagePercentage,
                        'percentage_display' => $averagePercentage !== null ? format_marks_value($averagePercentage) . '%' : '--',
                        'attendance_percentage' => (int) ($attendance['percentage'] ?? 0),
                        'result' => $averagePercentage !== null ? pass_fail_from_marks($averagePercentage, 100.0) : 'Pending',
                    ];
                }
            }
        }
    }

    return $rows;
}

function class_marks_overview_rows(int $departmentId, int $semesterNo): array
{
    $rows = [];
    foreach (students_for_class($departmentId, $semesterNo) as $student) {
        $payload = report_card_payload_for_student_id((int) $student['id']);
        if (!$payload) {
            continue;
        }

        $rows[] = [
            'student' => $payload['student'],
            'grand_total' => $payload['grand_total'],
            'grand_max' => $payload['grand_max'],
            'grade' => $payload['grand_grade'],
            'attendance' => $payload['attendance'],
        ];
    }

    return $rows;
}

function mark_records_grouped_by_upload_ids(array $uploadIds): array
{
    $normalizedIds = array_values(array_filter(array_map(static fn (mixed $value): int => (int) $value, $uploadIds), static fn (int $value): bool => $value > 0));
    if ($normalizedIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($normalizedIds as $index => $uploadId) {
        $key = 'upload_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $uploadId;
    }

    $records = query_all(
        'SELECT * FROM mark_records WHERE mark_upload_id IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    $grouped = [];
    foreach ($records as $record) {
        $grouped[(int) $record['mark_upload_id']][(int) $record['student_id']] = $record;
    }

    return $grouped;
}

function class_mark_type_overview_rows(int $departmentId, int $semesterNo, int $markTypeId): array
{
    $markType = mark_type_by_id($markTypeId);
    if (!$markType) {
        return [];
    }

    $students = students_for_class($departmentId, $semesterNo);
    if ($students === []) {
        return [];
    }

    $assessmentMax = (float) ($markType['max_marks'] ?? 0);

    $uploads = query_all(
        'SELECT mu.id, mu.subject_id, mu.max_marks, s.subject_name
         FROM mark_uploads mu
         INNER JOIN subjects s ON s.id = mu.subject_id
         WHERE mu.academic_year_id = :academic_year_id
           AND mu.department_id = :department_id
           AND mu.semester_no = :semester_no
           AND mu.exam_type = :exam_type
         ORDER BY s.subject_name ASC',
        [
            'academic_year_id' => current_academic_year_id(),
            'department_id' => $departmentId,
            'semester_no' => $semesterNo,
            'exam_type' => (string) $markType['label'],
        ]
    );

    if ($uploads === []) {
        $rows = [];
        foreach ($students as $student) {
            $rows[] = [
                'student' => $student,
                'marks_total' => 0.0,
                'marks_max' => $assessmentMax,
                'grade' => '-',
                'result' => 'Pending',
                'recorded_subjects' => 0,
                'published_subjects' => 0,
                'attendance' => attendance_summary_for_student((int) $student['id']),
            ];
        }

        return $rows;
    }

    $recordsByUpload = mark_records_grouped_by_upload_ids(array_map(static fn (array $row): int => (int) $row['id'], $uploads));
    $publishedSubjects = count($uploads);
    $rows = [];

    foreach ($students as $student) {
        $marksTotal = 0.0;
        $recordedSubjects = 0;
        $scoredSubjects = 0;
        $absentSubjects = 0;

        foreach ($uploads as $upload) {
            $record = $recordsByUpload[(int) $upload['id']][(int) $student['id']] ?? null;
            if (!$record) {
                continue;
            }

            $recordedSubjects++;
            $isAbsent = (int) ($record['is_absent'] ?? 0) === 1;
            if ($isAbsent) {
                $absentSubjects++;
                continue;
            }

            $scoredSubjects++;
            $marksTotal += (float) ($record['marks_obtained'] ?? 0);
        }

        $result = 'Pending';
        if ($recordedSubjects > 0) {
            $result = $recordedSubjects === $absentSubjects ? 'Absent' : pass_fail_from_marks(
                $scoredSubjects > 0 ? ($marksTotal / $scoredSubjects) : 0.0,
                $assessmentMax
            );
        }

        $averageScore = $scoredSubjects > 0 ? round($marksTotal / $scoredSubjects, 2) : 0.0;
        $rows[] = [
            'student' => $student,
            'marks_total' => $averageScore,
            'marks_max' => $assessmentMax,
            'grade' => $scoredSubjects > 0 && $assessmentMax > 0 ? grade_from_marks($averageScore, $assessmentMax) : '-',
            'result' => $result,
            'recorded_subjects' => $recordedSubjects,
            'published_subjects' => $publishedSubjects,
            'scored_subjects' => $scoredSubjects,
            'absent_subjects' => $absentSubjects,
            'attendance' => attendance_summary_for_student((int) $student['id']),
        ];
    }

    return $rows;
}

function class_student_attendance_map(int $departmentId, int $semesterNo): array
{
    $rows = query_all(
        'SELECT st.id AS student_id,
                SUM(CASE WHEN attendance.status = "P" THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN attendance.status = "A" THEN 1 ELSE 0 END) AS absent_count,
                COUNT(attendance.status) AS total_count
         FROM students st
         LEFT JOIN (
             SELECT ar.student_id, ar.status
             FROM attendance_records ar
             INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
             WHERE ats.academic_year_id = :attendance_academic_year_id
               AND ats.department_id = :attendance_department_id
               AND ats.semester_no = :attendance_semester_no
         ) attendance ON attendance.student_id = st.id
         WHERE st.department_id = :student_department_id
           AND st.semester_no = :student_semester_no
         GROUP BY st.id',
        [
            'attendance_academic_year_id' => current_academic_year_id(),
            'attendance_department_id' => $departmentId,
            'attendance_semester_no' => $semesterNo,
            'student_department_id' => $departmentId,
            'student_semester_no' => $semesterNo,
        ]
    );

    $map = [];
    foreach ($rows as $row) {
        $present = (int) ($row['present_count'] ?? 0);
        $absent = (int) ($row['absent_count'] ?? 0);
        $total = (int) ($row['total_count'] ?? 0);
        $map[(int) $row['student_id']] = [
            'present' => $present,
            'absent' => $absent,
            'total' => $total,
            'percentage' => $total > 0 ? (int) round(($present / $total) * 100) : 0,
        ];
    }

    return $map;
}

function class_student_mark_average_map(int $departmentId, int $semesterNo, int $subjectId = 0): array
{
    $subjectClause = '';
    $params = [
        'marks_academic_year_id' => current_academic_year_id(),
        'marks_department_id' => $departmentId,
        'marks_semester_no' => $semesterNo,
        'student_department_id' => $departmentId,
        'student_semester_no' => $semesterNo,
    ];

    if ($subjectId > 0) {
        $subjectClause = ' AND mu.subject_id = :marks_subject_id';
        $params['marks_subject_id'] = $subjectId;
    }

    $rows = query_all(
        'SELECT st.id AS student_id,
                AVG(CASE WHEN marks.is_absent = 0 THEN marks.marks_obtained END) AS average_marks,
                AVG(CASE WHEN marks.is_absent = 0 THEN (marks.marks_obtained / NULLIF(marks.max_marks, 0)) * 100 END) AS average_percentage,
                SUM(CASE WHEN marks.is_absent = 0 AND marks.marks_obtained IS NOT NULL THEN 1 ELSE 0 END) AS recorded_count,
                SUM(CASE WHEN marks.is_absent = 1 THEN 1 ELSE 0 END) AS absent_count
         FROM students st
         LEFT JOIN (
             SELECT mr.student_id, mr.marks_obtained, mr.is_absent, mu.max_marks
             FROM mark_records mr
             INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
             WHERE mu.academic_year_id = :marks_academic_year_id
               AND mu.department_id = :marks_department_id
               AND mu.semester_no = :marks_semester_no'
               . $subjectClause .
        '
         ) marks ON marks.student_id = st.id
         WHERE st.department_id = :student_department_id
           AND st.semester_no = :student_semester_no
         GROUP BY st.id',
        $params
    );

    $map = [];
    foreach ($rows as $row) {
        $averageMarks = $row['average_marks'] !== null ? round((float) $row['average_marks'], 2) : null;
        $averagePercentage = $row['average_percentage'] !== null ? round((float) $row['average_percentage'], 2) : null;
        $recordedCount = (int) ($row['recorded_count'] ?? 0);
        $absentCount = (int) ($row['absent_count'] ?? 0);
        $result = 'Pending';
        if ($averagePercentage !== null) {
            $result = pass_fail_from_marks($averagePercentage, 100.0);
        } elseif ($absentCount > 0) {
            $result = 'Absent';
        }

        $map[(int) $row['student_id']] = [
            'average_marks' => $averageMarks,
            'average_display' => $averageMarks !== null ? format_marks_value($averageMarks) : ($absentCount > 0 ? 'AB' : '--'),
            'average_percentage' => $averagePercentage,
            'average_percentage_display' => $averagePercentage !== null ? format_marks_value($averagePercentage) . '%' : '--',
            'recorded_count' => $recordedCount,
            'absent_count' => $absentCount,
            'result' => $result,
        ];
    }

    return $map;
}

function class_student_assignment_progress_map(int $departmentId, int $semesterNo, int $subjectId = 0, string $assignmentLabel = ''): array
{
    $subjectClause = '';
    $assignmentClause = '';
    $params = [
        'assignment_academic_year_id' => current_academic_year_id(),
        'assignment_department_id' => $departmentId,
        'assignment_semester_no' => $semesterNo,
        'student_department_id' => $departmentId,
        'student_semester_no' => $semesterNo,
    ];

    if ($subjectId > 0) {
        $subjectClause = ' AND a.subject_id = :assignment_subject_id';
        $params['assignment_subject_id'] = $subjectId;
    }

    $assignmentLabel = trim($assignmentLabel);
    if ($assignmentLabel !== '') {
        $assignmentClause = ' AND a.assignment_label = :assignment_label';
        $params['assignment_label'] = $assignmentLabel;
    }

    $rows = query_all(
        'SELECT st.id AS student_id,
                COUNT(a.id) AS total_assignments,
                SUM(CASE WHEN COALESCE(asb.submission_status, "pending") = "submitted" THEN 1 ELSE 0 END) AS submitted_count
         FROM students st
         LEFT JOIN assignments a
           ON a.academic_year_id = :assignment_academic_year_id
          AND a.department_id = :assignment_department_id
          AND a.semester_no = :assignment_semester_no'
          . $subjectClause
          . $assignmentClause .
        '
         LEFT JOIN assignment_submissions asb
           ON asb.assignment_id = a.id
          AND asb.student_id = st.id
         WHERE st.department_id = :student_department_id
           AND st.semester_no = :student_semester_no
         GROUP BY st.id',
        $params
    );

    $map = [];
    foreach ($rows as $row) {
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted_count'] ?? 0);
        $pending = max(0, $total - $submitted);
        $percentage = $total > 0 ? (int) round(($submitted / $total) * 100) : 0;

        $status = 'No Assignment';
        if ($total > 0) {
            if ($assignmentLabel !== '') {
                $status = $submitted > 0 ? 'Submitted' : 'Pending';
            } elseif ($submitted === $total) {
                $status = 'Submitted';
            } elseif ($submitted > 0) {
                $status = 'Partially Submitted';
            } else {
                $status = 'Pending';
            }
        }

        $map[(int) $row['student_id']] = [
            'total' => $total,
            'submitted' => $submitted,
            'pending' => $pending,
            'percentage' => $percentage,
            'percentage_display' => $percentage . '%',
            'status' => $status,
        ];
    }

    return $map;
}

function admin_filtered_report_rows(int $departmentId, int $semesterNo, int $subjectId = 0, string $assignmentLabel = ''): array
{
    $students = students_for_class($departmentId, $semesterNo);
    if ($students === []) {
        return [];
    }

    $attendanceMap = class_student_attendance_map($departmentId, $semesterNo);
    $overallMarksMap = class_student_mark_average_map($departmentId, $semesterNo);
    $subjectMarksMap = class_student_mark_average_map($departmentId, $semesterNo, $subjectId);
    $assignmentMap = class_student_assignment_progress_map($departmentId, $semesterNo, $subjectId, $assignmentLabel);

    $rows = [];
    foreach ($students as $student) {
        $studentId = (int) ($student['id'] ?? 0);
        $attendance = $attendanceMap[$studentId] ?? ['present' => 0, 'absent' => 0, 'total' => 0, 'percentage' => 0];
        $overallMarks = $overallMarksMap[$studentId] ?? [
            'average_marks' => null,
            'average_display' => '--',
            'average_percentage' => null,
            'average_percentage_display' => '--',
            'recorded_count' => 0,
            'absent_count' => 0,
            'result' => 'Pending',
        ];
        $subjectMarks = $subjectMarksMap[$studentId] ?? $overallMarks;
        $assignment = $assignmentMap[$studentId] ?? [
            'total' => 0,
            'submitted' => 0,
            'pending' => 0,
            'percentage' => 0,
            'percentage_display' => '0%',
            'status' => 'No Assignment',
        ];

        $resultSource = $subjectId > 0 ? $subjectMarks : $overallMarks;
        $rows[] = [
            'student' => $student,
            'attendance' => $attendance,
            'overall_marks' => $overallMarks,
            'subject_marks' => $subjectMarks,
            'assignment' => $assignment,
            'result' => (string) ($resultSource['result'] ?? 'Pending'),
        ];
    }

    return $rows;
}

function admin_filtered_report_summary(array $rows): array
{
    if ($rows === []) {
        return [
            'student_count' => 0,
            'attendance_avg' => 0,
            'assignment_avg' => 0,
            'marks_avg' => 0,
            'marks_display' => '--',
        ];
    }

    $attendanceTotal = 0;
    $assignmentTotal = 0;
    $marksTotal = 0.0;
    $marksCount = 0;

    foreach ($rows as $row) {
        $attendanceTotal += (int) (($row['attendance']['percentage'] ?? 0));
        $assignmentTotal += (int) (($row['assignment']['percentage'] ?? 0));

        if (($row['overall_marks']['average_percentage'] ?? null) !== null) {
            $marksTotal += (float) $row['overall_marks']['average_percentage'];
            $marksCount++;
        }
    }

    $studentCount = count($rows);
    $marksAverage = $marksCount > 0 ? round($marksTotal / $marksCount, 2) : 0.0;

    return [
        'student_count' => $studentCount,
        'attendance_avg' => (int) round($attendanceTotal / max(1, $studentCount)),
        'assignment_avg' => (int) round($assignmentTotal / max(1, $studentCount)),
        'marks_avg' => $marksAverage,
        'marks_display' => $marksCount > 0 ? format_marks_value($marksAverage) . '%' : '--',
    ];
}

function admin_department_report_row(int $departmentId, int $semesterNo = 0, int $subjectId = 0, string $assignmentLabel = ''): ?array
{
    $department = department_by_id($departmentId);
    if (!$department) {
        return null;
    }

    $academicYearId = current_academic_year_id();

    $studentSql = 'SELECT COUNT(*) FROM students st WHERE st.department_id = :student_department_id';
    $studentParams = ['student_department_id' => $departmentId];
    if ($semesterNo > 0) {
        $studentSql .= ' AND st.semester_no = :student_semester_no';
        $studentParams['student_semester_no'] = $semesterNo;
    }

    $studentsTotal = (int) query_value($studentSql, $studentParams);

    $facultyTotal = (int) query_value(
        'SELECT COUNT(*) FROM teachers WHERE department_id = :department_id AND status = "approved"',
        ['department_id' => $departmentId]
    );

    $attendanceSql = 'SELECT COALESCE(SUM(CASE WHEN ar.status = "P" THEN 1 ELSE 0 END), 0) AS present_total,
                             COALESCE(SUM(CASE WHEN ar.status = "A" THEN 1 ELSE 0 END), 0) AS absent_total,
                             COUNT(ar.id) AS attendance_records,
                             COALESCE(AVG(CASE WHEN ar.status = "P" THEN 100 ELSE 0 END), 0) AS attendance_pct
                      FROM attendance_records ar
                      INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
                      INNER JOIN students st ON st.id = ar.student_id
                      WHERE ats.academic_year_id = :attendance_academic_year_id
                        AND ats.department_id = :attendance_department_id';
    $attendanceParams = [
        'attendance_academic_year_id' => $academicYearId,
        'attendance_department_id' => $departmentId,
    ];
    if ($semesterNo > 0) {
        $attendanceSql .= ' AND ats.semester_no = :attendance_semester_no';
        $attendanceParams['attendance_semester_no'] = $semesterNo;
    }

    $attendance = query_one(
        $attendanceSql,
        $attendanceParams
    ) ?: ['present_total' => 0, 'absent_total' => 0, 'attendance_records' => 0, 'attendance_pct' => 0];

    $marksSql = 'SELECT AVG(CASE WHEN mr.is_absent = 0 THEN mr.marks_obtained END) AS average_marks,
                        AVG(CASE WHEN mr.is_absent = 0 THEN (mr.marks_obtained / NULLIF(mu.max_marks, 0)) * 100 END) AS average_percentage,
                        SUM(CASE WHEN mr.is_absent = 0 AND mr.marks_obtained IS NOT NULL THEN 1 ELSE 0 END) AS recorded_count
                 FROM mark_records mr
                 INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
                 INNER JOIN students st ON st.id = mr.student_id
                 WHERE mu.academic_year_id = :marks_academic_year_id
                   AND mu.department_id = :marks_department_id
                   AND st.department_id = :marks_student_department_id';
    $marksParams = [
        'marks_academic_year_id' => $academicYearId,
        'marks_department_id' => $departmentId,
        'marks_student_department_id' => $departmentId,
    ];
    if ($semesterNo > 0) {
        $marksSql .= ' AND mu.semester_no = :marks_upload_semester_no AND st.semester_no = :marks_student_semester_no';
        $marksParams['marks_upload_semester_no'] = $semesterNo;
        $marksParams['marks_student_semester_no'] = $semesterNo;
    }

    $overallMarks = query_one(
        $marksSql,
        $marksParams
    ) ?: ['average_marks' => null, 'average_percentage' => null, 'recorded_count' => 0];

    $subjectName = 'All Subjects';
    $subjectMarks = $overallMarks;
    if ($subjectId > 0) {
        $subjectLookupSql = 'SELECT subject_name
                             FROM subjects
                             WHERE id = :subject_lookup_id
                               AND department_id = :subject_lookup_department_id';
        $subjectLookupParams = [
            'subject_lookup_id' => $subjectId,
            'subject_lookup_department_id' => $departmentId,
        ];
        if ($semesterNo > 0) {
            $subjectLookupSql .= ' AND semester_no = :subject_lookup_semester_no';
            $subjectLookupParams['subject_lookup_semester_no'] = $semesterNo;
        }

        $subject = query_one(
            $subjectLookupSql . ' LIMIT 1',
            $subjectLookupParams
        );
        if ($subject) {
            $subjectName = (string) ($subject['subject_name'] ?? 'Subject');
            $subjectMarksSql = $marksSql . ' AND mu.subject_id = :subject_marks_subject_id';
            $subjectMarksParams = $marksParams;
            $subjectMarksParams['subject_marks_subject_id'] = $subjectId;
            $subjectMarks = query_one(
                $subjectMarksSql,
                $subjectMarksParams
            ) ?: ['average_marks' => null, 'average_percentage' => null, 'recorded_count' => 0];
        }
    }

    $assignmentSql = 'SELECT COUNT(DISTINCT a.id) AS assignment_count,
                             COUNT(a.id) AS assignment_slots,
                             COALESCE(SUM(CASE WHEN COALESCE(asb.submission_status, "pending") = "submitted" THEN 1 ELSE 0 END), 0) AS submitted_count
                      FROM students st
                      LEFT JOIN assignments a
                        ON a.academic_year_id = :assignment_academic_year_id
                       AND a.department_id = :assignment_join_department_id';
    $assignmentParams = [
        'assignment_academic_year_id' => $academicYearId,
        'assignment_join_department_id' => $departmentId,
        'assignment_student_department_id' => $departmentId,
    ];
    if ($semesterNo > 0) {
        $assignmentSql .= ' AND a.semester_no = :assignment_join_semester_no';
        $assignmentParams['assignment_join_semester_no'] = $semesterNo;
    }
    if ($subjectId > 0) {
        $assignmentSql .= ' AND a.subject_id = :assignment_join_subject_id';
        $assignmentParams['assignment_join_subject_id'] = $subjectId;
    }

    $assignmentLabel = trim($assignmentLabel);
    if ($assignmentLabel !== '') {
        $assignmentSql .= ' AND a.assignment_label = :assignment_join_label';
        $assignmentParams['assignment_join_label'] = $assignmentLabel;
    }
    $assignmentSql .= '
                      LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id AND asb.student_id = st.id
                      WHERE st.department_id = :assignment_student_department_id';
    if ($semesterNo > 0) {
        $assignmentSql .= ' AND st.semester_no = :assignment_student_semester_no';
        $assignmentParams['assignment_student_semester_no'] = $semesterNo;
    }

    $assignment = query_one(
        $assignmentSql,
        $assignmentParams
    ) ?: ['assignment_count' => 0, 'assignment_slots' => 0, 'submitted_count' => 0];

    $presentTotal = (int) ($attendance['present_total'] ?? 0);
    $absentTotal = (int) ($attendance['absent_total'] ?? 0);
    $attendanceRecords = (int) ($attendance['attendance_records'] ?? 0);
    $attendancePct = (float) ($attendance['attendance_pct'] ?? 0);

    $overallAverageMarks = $overallMarks['average_marks'] !== null ? round((float) $overallMarks['average_marks'], 2) : null;
    $overallAveragePercentage = $overallMarks['average_percentage'] !== null ? round((float) $overallMarks['average_percentage'], 2) : null;
    $subjectAverageMarks = $subjectMarks['average_marks'] !== null ? round((float) $subjectMarks['average_marks'], 2) : null;
    $subjectAveragePercentage = $subjectMarks['average_percentage'] !== null ? round((float) $subjectMarks['average_percentage'], 2) : null;
    $assignmentCount = (int) ($assignment['assignment_count'] ?? 0);
    $assignmentSlots = (int) ($assignment['assignment_slots'] ?? 0);
    $submittedCount = (int) ($assignment['submitted_count'] ?? 0);
    $pendingCount = max(0, $assignmentSlots - $submittedCount);
    $assignmentPct = $assignmentSlots > 0 ? round(($submittedCount / $assignmentSlots) * 100, 2) : 0.0;

    return [
        'department' => $department,
        'students_total' => $studentsTotal,
        'faculty_total' => $facultyTotal,
        'semester_no' => $semesterNo,
        'attendance' => [
            'percentage' => (int) round($attendancePct),
            'present_average' => $studentsTotal > 0 ? round($presentTotal / $studentsTotal, 2) : 0.0,
            'absent_average' => $studentsTotal > 0 ? round($absentTotal / $studentsTotal, 2) : 0.0,
            'session_average' => $studentsTotal > 0 ? round($attendanceRecords / $studentsTotal, 2) : 0.0,
        ],
        'overall_marks' => [
            'average_marks' => $overallAverageMarks,
            'average_display' => $overallAverageMarks !== null ? format_marks_value($overallAverageMarks) : '--',
            'average_percentage' => $overallAveragePercentage,
            'average_percentage_display' => $overallAveragePercentage !== null ? format_marks_value($overallAveragePercentage) . '%' : '--',
            'recorded_count' => (int) ($overallMarks['recorded_count'] ?? 0),
            'result' => $overallAveragePercentage !== null ? pass_fail_from_marks($overallAveragePercentage, 100.0) : 'Pending',
        ],
        'subject' => [
            'name' => $subjectName,
            'average_marks' => $subjectAverageMarks,
            'average_display' => $subjectAverageMarks !== null ? format_marks_value($subjectAverageMarks) : '--',
            'average_percentage' => $subjectAveragePercentage,
            'average_percentage_display' => $subjectAveragePercentage !== null ? format_marks_value($subjectAveragePercentage) . '%' : '--',
            'recorded_count' => (int) ($subjectMarks['recorded_count'] ?? 0),
        ],
        'assignment' => [
            'label' => $assignmentLabel !== '' ? $assignmentLabel : 'All Assignments',
            'count' => $assignmentCount,
            'submitted_average' => $studentsTotal > 0 ? round($submittedCount / $studentsTotal, 2) : 0.0,
            'pending_average' => $studentsTotal > 0 ? round($pendingCount / $studentsTotal, 2) : 0.0,
            'percentage' => (int) round($assignmentPct),
            'percentage_display' => (int) round($assignmentPct) . '%',
        ],
    ];
}

function admin_department_report_rows(int $departmentId = 0, int $semesterNo = 0, int $subjectId = 0, string $assignmentLabel = ''): array
{
    $rows = [];
    foreach (departments() as $department) {
        $rowDepartmentId = (int) ($department['id'] ?? 0);
        if ($departmentId > 0 && $rowDepartmentId !== $departmentId) {
            continue;
        }

        $row = admin_department_report_row($rowDepartmentId, $semesterNo, $subjectId, $assignmentLabel);
        if ($row !== null) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_department_report_summary(array $rows): array
{
    if ($rows === []) {
        return [
            'department_count' => 0,
            'student_count' => 0,
            'attendance_avg' => 0,
            'assignment_avg' => 0,
            'marks_display' => '--',
        ];
    }

    $departmentCount = count($rows);
    $studentCount = 0;
    $attendanceTotal = 0;
    $assignmentTotal = 0;
    $marksTotal = 0.0;
    $marksCount = 0;

    foreach ($rows as $row) {
        $studentCount += (int) ($row['students_total'] ?? 0);
        $attendanceTotal += (int) (($row['attendance']['percentage'] ?? 0));
        $assignmentTotal += (int) (($row['assignment']['percentage'] ?? 0));
        if (($row['overall_marks']['average_percentage'] ?? null) !== null) {
            $marksTotal += (float) $row['overall_marks']['average_percentage'];
            $marksCount++;
        }
    }

    $marksAverage = $marksCount > 0 ? round($marksTotal / $marksCount, 2) : 0.0;

    return [
        'department_count' => $departmentCount,
        'student_count' => $studentCount,
        'attendance_avg' => (int) round($attendanceTotal / max(1, $departmentCount)),
        'assignment_avg' => (int) round($assignmentTotal / max(1, $departmentCount)),
        'marks_display' => $marksCount > 0 ? format_marks_value($marksAverage) . '%' : '--',
    ];
}

function subject_marks_export_rows(int $departmentId, int $semesterNo, int $subjectId): array
{
    $subject = query_one(
        'SELECT * FROM subjects WHERE id = :id AND department_id = :department_id AND semester_no = :semester_no LIMIT 1',
        ['id' => $subjectId, 'department_id' => $departmentId, 'semester_no' => $semesterNo]
    );
    if (!$subject) {
        return [];
    }

    $students = students_for_class($departmentId, $semesterNo);
    if ($students === []) {
        return [];
    }

    $markTypes = mark_type_rows();
    $uploads = query_all(
        'SELECT id, exam_type, max_marks
         FROM mark_uploads
         WHERE academic_year_id = :academic_year_id
           AND department_id = :department_id
           AND semester_no = :semester_no
           AND subject_id = :subject_id',
        [
            'academic_year_id' => current_academic_year_id(),
            'department_id' => $departmentId,
            'semester_no' => $semesterNo,
            'subject_id' => $subjectId,
        ]
    );

    $uploadsByExamType = [];
    foreach ($uploads as $upload) {
        $uploadsByExamType[(string) $upload['exam_type']] = $upload;
    }

    $recordsByUpload = mark_records_grouped_by_upload_ids(array_map(static fn (array $row): int => (int) $row['id'], $uploads));
    $rows = [];

    foreach ($students as $student) {
        $cells = [];
        $total = 0.0;
        $max = 0.0;
        $recordedCount = 0;

        foreach ($markTypes as $markType) {
            $label = (string) ($markType['label'] ?? '');
            $upload = $uploadsByExamType[$label] ?? null;
            $cellMax = (float) ($upload['max_marks'] ?? ($markType['max_marks'] ?? 0));
            $max += $cellMax;
            $display = '--';

            if ($upload) {
                $record = $recordsByUpload[(int) $upload['id']][(int) $student['id']] ?? null;
                if ($record) {
                    $recordedCount++;
                    if ((int) ($record['is_absent'] ?? 0) === 1) {
                        $display = 'AB';
                    } elseif ($record['marks_obtained'] !== null) {
                        $display = (string) $record['marks_obtained'];
                        $total += (float) $record['marks_obtained'];
                    }
                }
            }

            $cells[] = [
                'label' => $label,
                'max_marks' => $cellMax,
                'display' => $display,
            ];
        }

        $rows[] = [
            'student' => $student,
            'cells' => $cells,
            'total' => $total,
            'max' => $max,
            'grade' => $max > 0 ? grade_from_marks($total, $max) : '-',
            'result' => $recordedCount > 0 ? pass_fail_from_marks($total, $max) : 'Pending',
            'recorded_count' => $recordedCount,
            'subject' => $subject,
        ];
    }

    return $rows;
}
function backup_table_names(): array
{
    return [
        'academic_years',
        'admins',
        'departments',
        'teachers',
        'students',
        'subjects',
        'mark_types',
        'mark_locks',
        'password_reset_otps',
        'admin_otp_requests',
        'holiday_events',
        'attendance_sessions',
        'attendance_records',
        'mark_uploads',
        'mark_records',
        'assignments',
        'assignment_submissions',
        'audit_logs',
    ];
}

function export_backup_payload(): array
{
    $tables = [];
    foreach (backup_table_names() as $table) {
        $tables[$table] = query_all("SELECT * FROM `{$table}`");
    }

    return [
        'generated_at' => date('c'),
        'app' => 'BIPE Academic Portal',
        'tables' => $tables,
    ];
}

function restore_backup_payload(array $payload): void
{
    if (!isset($payload['tables']) || !is_array($payload['tables'])) {
        throw new RuntimeException('Invalid backup file.');
    }

    $tables = backup_table_names();

    db()->beginTransaction();
    try {
        db()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($tables) as $table) {
            db()->exec("TRUNCATE TABLE `{$table}`");
        }

        foreach ($tables as $table) {
            $rows = $payload['tables'][$table] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || $row === []) {
                    continue;
                }

                $columns = array_keys($row);
                $quotedColumns = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
                $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
                $stmt = db()->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");
                $stmt->execute($row);
            }
        }

        db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        try {
            db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable) {
        }
        throw $exception;
    }
}

function request_admin_password_reset_delivery(string $username): ?array
{
    return null;
}

function request_password_reset_delivery(string $role, string $email): ?array
{
    return null;
}



