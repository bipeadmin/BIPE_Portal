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

function audit_log(string $role, string $userIdentifier, string $actionCode, ?string $details = null): void
{
    execute_sql(
        'INSERT INTO audit_logs (role_name, user_identifier, ip_address, action_code, details)
         VALUES (:role_name, :user_identifier, :ip_address, :action_code, :details)',
        [
            'role_name' => $role,
            'user_identifier' => $userIdentifier,
            'ip_address' => client_ip(),
            'action_code' => strtoupper($actionCode),
            'details' => $details,
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

    if ($search !== '') {
        $sql .= ' AND (user_identifier LIKE :search OR COALESCE(ip_address, "") LIKE :search OR action_code LIKE :search OR COALESCE(details, "") LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY created_at DESC, id DESC';

    return query_all($sql, $params);
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

        audit_log('teacher', (string) $teacherId, 'MARKS_SAVE', 'Upload #' . $uploadId . ' saved');
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

function report_card_payload_for_student_id(int $studentId): ?array
{
    $student = student_by_id($studentId);
    if (!$student) {
        return null;
    }

    $departmentId = (int) $student['department_id'];
    $semesterNo = (int) $student['semester_no'];
    $subjects = subjects_for($departmentId, $semesterNo);
    $markTypes = mark_type_rows();
    $rows = [];
    $grandTotal = 0.0;
    $grandMax = 0.0;

    foreach ($subjects as $subject) {
        $row = [
            'subject_name' => $subject['subject_name'],
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
                    $display = (string) $entry['marks_obtained'];
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
        'attendance' => $attendance,
        'grand_total' => $grandTotal,
        'grand_max' => $grandMax,
        'grand_grade' => $grandMax > 0 ? grade_from_marks($grandTotal, $grandMax) : '--',
    ];
}

function class_report_card_payloads(int $departmentId, int $semesterNo, ?int $studentId = null): array
{
    $students = $studentId !== null
        ? array_values(array_filter(students_for_class($departmentId, $semesterNo), static fn (array $row): bool => (int) $row['id'] === $studentId))
        : students_for_class($departmentId, $semesterNo);

    $payloads = [];
    foreach ($students as $student) {
        $payload = report_card_payload_for_student_id((int) $student['id']);
        if ($payload) {
            $payloads[] = $payload;
        }
    }

    return $payloads;
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

