<?php
declare(strict_types=1);

function admin_login(string $username, string $password): bool
{
    $admin = query_one('SELECT * FROM admins WHERE username = :username LIMIT 1', ['username' => strtolower(trim($username))]);

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return false;
    }

    login_session([
        'role' => 'admin',
        'id' => (int) $admin['id'],
        'name' => $admin['full_name'],
        'username' => $admin['username'],
    ]);

    return true;
}

function teacher_login(string $teacherCode, string $password): string|bool
{
    $teacher = query_one('SELECT * FROM teachers WHERE teacher_code = :teacher_code LIMIT 1', ['teacher_code' => strtolower(trim($teacherCode))]);

    if (!$teacher || !password_verify($password, $teacher['password_hash'])) {
        return false;
    }

    if ($teacher['status'] === 'pending') {
        return 'pending';
    }

    if ($teacher['status'] === 'rejected') {
        return 'rejected';
    }

    if ($teacher['status'] === 'archived') {
        return 'archived';
    }

    login_session([
        'role' => 'teacher',
        'id' => (int) $teacher['id'],
        'name' => $teacher['full_name'],
        'teacher_code' => $teacher['teacher_code'],
        'department_id' => (int) $teacher['department_id'],
    ]);

    return true;
}

function student_login(string $enrollment, string $password): bool
{
    $student = query_one('SELECT * FROM students WHERE enrollment_no = :enrollment_no LIMIT 1', ['enrollment_no' => strtoupper(trim($enrollment))]);

    if (!$student || empty($student['password_hash']) || !password_verify($password, $student['password_hash'])) {
        return false;
    }

    login_session([
        'role' => 'student',
        'id' => (int) $student['id'],
        'name' => $student['full_name'],
        'enrollment_no' => $student['enrollment_no'],
        'department_id' => (int) $student['department_id'],
    ]);

    return true;
}

function build_teacher_code(string $fullName, string $departmentCode): string
{
    $name = preg_replace('/^(dr\.|prof\.|mr\.|ms\.|mrs\.)\s*/i', '', trim($fullName)) ?? trim($fullName);
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = strtolower((string) ($parts[0] ?? 'faculty'));
    $first = preg_replace('/[^a-z]/', '', $first) ?? 'faculty';
    $departmentCode = strtolower(trim($departmentCode));

    return $first . '.' . $departmentCode;
}

function next_teacher_code(string $fullName, string $departmentCode): string
{
    $baseCode = build_teacher_code($fullName, $departmentCode);
    $candidate = $baseCode;
    $counter = 2;

    while (query_one('SELECT id FROM teachers WHERE teacher_code = :teacher_code LIMIT 1', ['teacher_code' => $candidate])) {
        $candidate = $baseCode . $counter;
        $counter++;
    }

    return $candidate;
}

function register_teacher_account(string $name, int $departmentId, string $email, string $password, ?string $profileImagePath = null): array
{
    $department = query_one('SELECT * FROM departments WHERE id = :id', ['id' => $departmentId]);
    if (!$department) {
        throw new RuntimeException('Department not found.');
    }

    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Faculty name is required.');
    }

    $email = validate_email_address($email);
    validate_password_strength($password);

    $emailExists = query_one('SELECT id FROM teachers WHERE email = :email LIMIT 1', ['email' => $email]);
    if ($emailExists) {
        throw new RuntimeException('A faculty account already exists with this email address.');
    }

    $teacherCode = next_teacher_code($name, (string) $department['code']);

    execute_sql(
        'INSERT INTO teachers (department_id, teacher_code, full_name, email, profile_image_path, password_hash, status, registered_at)
         VALUES (:department_id, :teacher_code, :full_name, :email, :profile_image_path, :password_hash, "pending", NOW())',
        [
            'department_id' => $departmentId,
            'teacher_code' => $teacherCode,
            'full_name' => $name,
            'email' => $email,
            'profile_image_path' => $profileImagePath,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]
    );

    return ['teacher_code' => $teacherCode, 'department' => $department, 'teacher_id' => (int) db()->lastInsertId()];
}

function register_student_account(string $enrollmentNo, int $departmentId, string $password): bool
{
    validate_password_strength($password);

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

    execute_sql(
        'UPDATE students
         SET password_hash = :password_hash, registered_at = NOW(), updated_at = NOW()
         WHERE id = :id',
        [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $student['id'],
        ]
    );

    return true;
}

function change_admin_password(int $adminId, string $currentPassword, string $newPassword): bool
{
    $admin = query_one('SELECT * FROM admins WHERE id = :id LIMIT 1', ['id' => $adminId]);

    if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
        return false;
    }

    validate_password_strength($newPassword);

    execute_sql(
        'UPDATE admins SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
        [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $adminId,
        ]
    );

    return true;
}

