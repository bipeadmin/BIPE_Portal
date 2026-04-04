<?php
declare(strict_types=1);

function current_academic_year_id(): int
{
    $year = current_academic_year();
    if (!$year) {
        throw new RuntimeException('No academic year is configured. Import the seed files first.');
    }

    return (int) $year['id'];
}

function department_by_id(int $id): ?array
{
    return query_one('SELECT * FROM departments WHERE id = :id', ['id' => $id]);
}

function admin_by_id(int $id): ?array
{
    return query_one('SELECT * FROM admins WHERE id = :id', ['id' => $id]);
}

function teacher_by_id(int $id): ?array
{
    return query_one(
        'SELECT t.*, d.name AS department_name, d.short_name
         FROM teachers t
         INNER JOIN departments d ON d.id = t.department_id
         WHERE t.id = :id',
        ['id' => $id]
    );
}

function teacher_by_code(string $teacherCode): ?array
{
    $teacherCode = strtolower(trim($teacherCode));
    if ($teacherCode === '') {
        return null;
    }

    return query_one(
        'SELECT t.*, d.name AS department_name, d.short_name
         FROM teachers t
         INNER JOIN departments d ON d.id = t.department_id
         WHERE t.teacher_code = :teacher_code
         LIMIT 1',
        ['teacher_code' => $teacherCode]
    );
}

function student_by_id(int $id): ?array
{
    return query_one(
        'SELECT s.*, d.name AS department_name, d.short_name
         FROM students s
         INNER JOIN departments d ON d.id = s.department_id
         WHERE s.id = :id',
        ['id' => $id]
    );
}

function require_current_admin(): array
{
    $admin = admin_by_id((int) (current_user()['id'] ?? 0));
    if (!$admin) {
        logout_session();
        flash('error', 'Your administrator account is no longer available. Please login again.');
        redirect_to('admin/login.php');
    }

    return $admin;
}

function require_current_teacher(): array
{
    $teacher = teacher_by_id((int) (current_user()['id'] ?? 0));
    if (!$teacher) {
        logout_session();
        flash('error', 'Your faculty account is no longer available. Please login again.');
        redirect_to('faculty/login.php');
    }

    return $teacher;
}

function require_current_student(): array
{
    $student = student_by_id((int) (current_user()['id'] ?? 0));
    if (!$student) {
        logout_session();
        flash('error', 'Your student account is no longer available. Please login again.');
        redirect_to('student/login.php');
    }

    return $student;
}

function parse_year_level(string $value): int
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    $level = (int) $digits;

    return $level > 0 ? $level : 1;
}

function parse_semester_no(string $value, ?int $yearLevel = null): int
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    $semester = (int) $digits;
    if ($semester > 0) {
        return $semester;
    }

    return match ($yearLevel) {
        1 => 2,
        2 => 4,
        3 => 6,
        default => 2,
    };
}

function add_or_update_student(string $name, string $enrollmentNo, int $departmentId, int $yearLevel, int $semesterNo, ?string $phoneNumber = null): void
{
    $phoneNumber = normalize_phone_number($phoneNumber);
    $existing = query_one('SELECT id FROM students WHERE enrollment_no = :enrollment_no', ['enrollment_no' => strtoupper($enrollmentNo)]);

    if ($existing) {
        execute_sql(
            'UPDATE students
             SET full_name = :full_name,
                 department_id = :department_id,
                 year_level = :year_level,
                 semester_no = :semester_no,
                 phone_number = :phone_number,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'full_name' => strtoupper(trim($name)),
                'department_id' => $departmentId,
                'year_level' => $yearLevel,
                'semester_no' => $semesterNo,
                'phone_number' => $phoneNumber,
                'id' => $existing['id'],
            ]
        );

        return;
    }

    execute_sql(
        'INSERT INTO students (department_id, enrollment_no, full_name, phone_number, year_level, semester_no)
         VALUES (:department_id, :enrollment_no, :full_name, :phone_number, :year_level, :semester_no)',
        [
            'department_id' => $departmentId,
            'enrollment_no' => strtoupper($enrollmentNo),
            'full_name' => strtoupper(trim($name)),
            'phone_number' => $phoneNumber,
            'year_level' => $yearLevel,
            'semester_no' => $semesterNo,
        ]
    );
}

function bulk_import_students_csv(string $tmpName): array
{
    $handle = fopen($tmpName, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded CSV file.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('The CSV file is empty.');
    }

    $normalized = [];
    foreach ($header as $index => $column) {
        $key = strtolower(trim((string) $column));
        $normalized[$key] = $index;
    }

    $required = [
        'name' => ['name', 'student name', 'full name'],
        'enrollment' => ['enrollment', 'enrollment no', 'enrollment number'],
        'department' => ['department', 'dept'],
        'year' => ['year', 'year level'],
    ];

    $mapped = [];
    foreach ($required as $target => $choices) {
        $found = null;
        foreach ($choices as $choice) {
            if (array_key_exists($choice, $normalized)) {
                $found = $normalized[$choice];
                break;
            }
        }
        if ($found === null) {
            fclose($handle);
            throw new RuntimeException('CSV must contain Name, Enrollment, Department and Year columns.');
        }
        $mapped[$target] = $found;
    }

    $semesterIndex = null;
    foreach (['semester', 'semester no', 'semester number'] as $choice) {
        if (array_key_exists($choice, $normalized)) {
            $semesterIndex = $normalized[$choice];
            break;
        }
    }

    $phoneIndex = null;
    foreach (['phone', 'phone number', 'mobile', 'mobile number'] as $choice) {
        if (array_key_exists($choice, $normalized)) {
            $phoneIndex = $normalized[$choice];
            break;
        }
    }

    $departmentLookup = [];
    foreach (departments() as $department) {
        $departmentLookup[strtolower($department['name'])] = $department;
        $departmentLookup[strtolower($department['short_name'])] = $department;
        $departmentLookup[strtolower($department['code'])] = $department;
    }

    $inserted = 0;
    $updated = 0;

    db()->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $name = trim((string) ($row[$mapped['name']] ?? ''));
            $enrollment = strtoupper(trim((string) ($row[$mapped['enrollment']] ?? '')));
            $departmentKey = strtolower(trim((string) ($row[$mapped['department']] ?? '')));
            $yearLevel = parse_year_level((string) ($row[$mapped['year']] ?? ''));
            $semesterNo = parse_semester_no((string) ($semesterIndex !== null ? ($row[$semesterIndex] ?? '') : ''), $yearLevel);
            $phoneNumber = $phoneIndex !== null ? trim((string) ($row[$phoneIndex] ?? '')) : '';

            if ($name === '' || $enrollment === '' || $departmentKey === '') {
                continue;
            }

            $department = $departmentLookup[$departmentKey] ?? null;
            if (!$department) {
                throw new RuntimeException('Unknown department in CSV: ' . $departmentKey);
            }

            $existing = query_one('SELECT id FROM students WHERE enrollment_no = :enrollment_no', ['enrollment_no' => $enrollment]);
            add_or_update_student($name, $enrollment, (int) $department['id'], $yearLevel, $semesterNo, $phoneNumber);
            $existing ? $updated++ : $inserted++;
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        fclose($handle);
        throw $exception;
    }

    fclose($handle);

    return ['inserted' => $inserted, 'updated' => $updated];
}

function student_directory_rows(?int $departmentId = null, ?int $yearLevel = null, ?int $semesterNo = null, string $search = ''): array
{
    $sql = 'SELECT s.*, d.name AS department_name, d.short_name
            FROM students s
            INNER JOIN departments d ON d.id = s.department_id
            WHERE 1 = 1';
    $params = [];

    if ($departmentId !== null && $departmentId > 0) {
        $sql .= ' AND s.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }
    if ($yearLevel !== null && $yearLevel > 0) {
        $sql .= ' AND s.year_level = :year_level';
        $params['year_level'] = $yearLevel;
    }
    if ($semesterNo !== null && $semesterNo > 0) {
        $sql .= ' AND s.semester_no = :semester_no';
        $params['semester_no'] = $semesterNo;
    }
    if (trim($search) !== '') {
        $sql .= ' AND (
            s.full_name LIKE :search OR
            s.enrollment_no LIKE :search OR
            COALESCE(s.email, "") LIKE :search OR
            COALESCE(s.phone_number, "") LIKE :search
        )';
        $params['search'] = '%' . trim($search) . '%';
    }

    $sql .= ' ORDER BY d.name ASC, s.year_level DESC, s.semester_no DESC, s.full_name ASC';

    return query_all($sql, $params);
}

function add_or_update_subject(string $subjectCode, string $subjectName, int $departmentId, int $semesterNo): string
{
    $subjectCode = strtoupper(trim($subjectCode));
    $subjectName = trim((string) preg_replace('/\s+/', ' ', $subjectName));

    if ($departmentId <= 0) {
        throw new RuntimeException('Choose a department before saving subjects.');
    }
    if (!in_array($semesterNo, semester_numbers(), true)) {
        throw new RuntimeException('Choose a valid semester before saving subjects.');
    }
    if ($subjectCode === '' || $subjectName === '') {
        throw new RuntimeException('Subject code and subject name are both required.');
    }

    $matches = query_all(
        'SELECT id, subject_code, subject_name
         FROM subjects
         WHERE department_id = :department_id AND semester_no = :semester_no
           AND (subject_code = :subject_code OR subject_name = :subject_name)',
        [
            'department_id' => $departmentId,
            'semester_no' => $semesterNo,
            'subject_code' => $subjectCode,
            'subject_name' => $subjectName,
        ]
    );

    $matchedIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $matches)));
    if (count($matchedIds) > 1) {
        throw new RuntimeException('Conflicting subject code/name found for ' . $subjectCode . '. Review the existing subject list before importing again.');
    }

    if ($matchedIds) {
        execute_sql(
            'UPDATE subjects
             SET subject_code = :subject_code,
                 subject_name = :subject_name
             WHERE id = :id',
            [
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'id' => $matchedIds[0],
            ]
        );

        return 'updated';
    }

    execute_sql(
        'INSERT INTO subjects (department_id, semester_no, subject_code, subject_name)
         VALUES (:department_id, :semester_no, :subject_code, :subject_name)',
        [
            'department_id' => $departmentId,
            'semester_no' => $semesterNo,
            'subject_code' => $subjectCode,
            'subject_name' => $subjectName,
        ]
    );

    return 'inserted';
}

function bulk_import_subjects_csv(string $tmpName, int $departmentId, int $yearLevel, int $semesterNo): array
{
    if ($departmentId <= 0) {
        throw new RuntimeException('Select a department before importing subjects.');
    }
    if ($yearLevel <= 0) {
        throw new RuntimeException('Select a year before importing subjects.');
    }
    if (!in_array($semesterNo, semester_numbers(), true)) {
        throw new RuntimeException('Select a valid semester before importing subjects.');
    }
    if ($semesterNo !== ($yearLevel * 2)) {
        throw new RuntimeException('Selected year and semester do not match. Please review the class filters and try again.');
    }

    $handle = fopen($tmpName, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded subject CSV file.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('The subject CSV file is empty.');
    }

    $normalized = [];
    foreach ($header as $index => $column) {
        $key = strtolower(trim((string) $column));
        $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
        $normalized[$key] = $index;
    }

    $required = [
        'subject code' => ['subject code', 'code', 'subject_code'],
        'subject name' => ['subject name', 'name', 'subject', 'subject_name'],
    ];

    $mapped = [];
    foreach ($required as $target => $choices) {
        $found = null;
        foreach ($choices as $choice) {
            if (array_key_exists($choice, $normalized)) {
                $found = $normalized[$choice];
                break;
            }
        }

        if ($found === null) {
            fclose($handle);
            throw new RuntimeException('CSV must contain Subject Code and Subject Name columns.');
        }

        $mapped[$target] = $found;
    }

    $inserted = 0;
    $updated = 0;

    db()->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $subjectCode = trim((string) ($row[$mapped['subject code']] ?? ''));
            $subjectName = trim((string) ($row[$mapped['subject name']] ?? ''));
            if ($subjectCode === '' && $subjectName === '') {
                continue;
            }
            if ($subjectCode === '' || $subjectName === '') {
                throw new RuntimeException('Each CSV row must include both subject code and subject name.');
            }

            $result = add_or_update_subject($subjectCode, $subjectName, $departmentId, $semesterNo);
            if ($result === 'inserted') {
                $inserted++;
            } else {
                $updated++;
            }
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        fclose($handle);
        throw $exception;
    }

    fclose($handle);

    return ['inserted' => $inserted, 'updated' => $updated];
}

function delete_subject_row(int $subjectId): array
{
    $subject = query_one(
        'SELECT s.*, d.name AS department_name, d.short_name
         FROM subjects s
         INNER JOIN departments d ON d.id = s.department_id
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $subjectId]
    );

    if (!$subject) {
        throw new RuntimeException('Subject record not found.');
    }

    $markUploads = (int) query_value('SELECT COUNT(*) FROM mark_uploads WHERE subject_id = :subject_id', ['subject_id' => $subjectId]);
    $assignments = (int) query_value('SELECT COUNT(*) FROM assignments WHERE subject_id = :subject_id', ['subject_id' => $subjectId]);

    if ($markUploads > 0 || $assignments > 0) {
        $linkedItems = [];
        if ($markUploads > 0) {
            $linkedItems[] = $markUploads . ' marks upload' . ($markUploads === 1 ? '' : 's');
        }
        if ($assignments > 0) {
            $linkedItems[] = $assignments . ' assignment' . ($assignments === 1 ? '' : 's');
        }

        throw new RuntimeException('This subject cannot be deleted because it is already linked to ' . implode(' and ', $linkedItems) . '.');
    }

    execute_sql('DELETE FROM subjects WHERE id = :id', ['id' => $subjectId]);

    return $subject;
}

function subject_directory_rows(?int $departmentId = null, ?int $yearLevel = null, ?int $semesterNo = null, string $search = ''): array
{
    $sql = 'SELECT s.*, d.name AS department_name, d.short_name,
                   (s.semester_no DIV 2) AS year_level,
                   COALESCE(sc.total_students, 0) AS student_count
            FROM subjects s
            INNER JOIN departments d ON d.id = s.department_id
            LEFT JOIN (
                SELECT department_id, semester_no, COUNT(*) AS total_students
                FROM students
                GROUP BY department_id, semester_no
            ) sc ON sc.department_id = s.department_id AND sc.semester_no = s.semester_no
            WHERE 1 = 1';
    $params = [];

    if ($departmentId !== null && $departmentId > 0) {
        $sql .= ' AND s.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }
    if ($semesterNo !== null && $semesterNo > 0) {
        $sql .= ' AND s.semester_no = :semester_no';
        $params['semester_no'] = $semesterNo;
    } elseif ($yearLevel !== null && $yearLevel > 0) {
        $sql .= ' AND s.semester_no = :year_semester';
        $params['year_semester'] = $yearLevel * 2;
    }
    if (trim($search) !== '') {
        $sql .= ' AND (
            s.subject_code LIKE :search OR
            s.subject_name LIKE :search OR
            d.name LIKE :search OR
            d.short_name LIKE :search
        )';
        $params['search'] = '%' . trim($search) . '%';
    }

    $sql .= ' ORDER BY d.name ASC, s.semester_no ASC, s.subject_code ASC, s.subject_name ASC';

    return query_all($sql, $params);
}

function faculty_groups(): array
{
    $rows = query_all(
        'SELECT t.*, d.name AS department_name, d.short_name
         FROM teachers t
         INNER JOIN departments d ON d.id = t.department_id
         ORDER BY FIELD(t.status, "pending", "approved", "rejected"), t.full_name'
    );

    return [
        'pending' => array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'pending')),
        'approved' => array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'approved')),
        'rejected' => array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'rejected')),
    ];
}

function approve_teacher_account(int $teacherId): void
{
    execute_sql(
        'UPDATE teachers
         SET status = "approved", approved_at = NOW(), rejected_at = NULL, updated_at = NOW()
         WHERE id = :id',
        ['id' => $teacherId]
    );
}

function reject_teacher_account(int $teacherId): void
{
    execute_sql(
        'UPDATE teachers
         SET status = "rejected", rejected_at = NOW(), updated_at = NOW()
         WHERE id = :id',
        ['id' => $teacherId]
    );
}

function approve_all_pending_teachers(): int
{
    $count = (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "pending"');
    execute_sql(
        'UPDATE teachers
         SET status = "approved", approved_at = NOW(), rejected_at = NULL, updated_at = NOW()
         WHERE status = "pending"'
    );

    return $count;
}

function reject_all_pending_teachers(): int
{
    $count = (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "pending"');
    execute_sql(
        'UPDATE teachers
         SET status = "rejected", rejected_at = NOW(), updated_at = NOW()
         WHERE status = "pending"'
    );

    return $count;
}

function delete_teacher_account(int $teacherId): void
{
    execute_sql('DELETE FROM teachers WHERE id = :id', ['id' => $teacherId]);
}

function purge_rejected_teachers(): int
{
    $count = (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "rejected"');
    execute_sql('DELETE FROM teachers WHERE status = "rejected"');

    return $count;
}

function support_request_type_label(?string $requestType): string
{
    return match (trim((string) $requestType)) {
        'forgot_password' => 'Forgot Password',
        'forgot_faculty_id' => 'Forgot Faculty ID',
        'feedback' => 'Feedback',
        'issue' => 'Issue',
        default => trim((string) $requestType) !== ''
            ? ucwords(str_replace(['_', '-'], ' ', (string) $requestType))
            : 'General Request',
    };
}

function support_request_status_label(string $status): string
{
    return match (trim($status)) {
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        default => 'Pending',
    };
}

function support_request_status_tone(string $status): string
{
    return match (trim($status)) {
        'approved' => 'success',
        'rejected' => 'danger',
        default => 'warning',
    };
}

function support_request_by_id(int $requestId): ?array
{
    return query_one(
        'SELECT sr.*, a.full_name AS reviewed_by_name
         FROM support_requests sr
         LEFT JOIN admins a ON a.id = sr.reviewed_by_admin_id
         WHERE sr.id = :id
         LIMIT 1',
        ['id' => $requestId]
    );
}

function support_request_rows(?string $category = null): array
{
    $params = [];
    $sql = 'SELECT sr.*, a.full_name AS reviewed_by_name
            FROM support_requests sr
            LEFT JOIN admins a ON a.id = sr.reviewed_by_admin_id
            WHERE 1 = 1';

    if ($category !== null && trim($category) !== '') {
        $sql .= ' AND sr.category = :category';
        $params['category'] = trim($category);
    }

    $sql .= ' ORDER BY FIELD(sr.status, "pending", "approved", "rejected"), sr.created_at DESC, sr.id DESC';

    return query_all($sql, $params);
}

function support_request_summary(): array
{
    $summary = [
        'all' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0],
        'request' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0],
        'feedback' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0],
        'issue' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0],
    ];

    $rows = query_all(
        'SELECT category, status, COUNT(*) AS total
         FROM support_requests
         GROUP BY category, status'
    );

    foreach ($rows as $row) {
        $category = (string) ($row['category'] ?? 'request');
        $status = (string) ($row['status'] ?? 'pending');
        $count = (int) ($row['total'] ?? 0);

        if (!isset($summary[$category])) {
            continue;
        }

        $summary[$category]['total'] += $count;
        $summary[$category][$status] = ($summary[$category][$status] ?? 0) + $count;
        $summary['all']['total'] += $count;
        $summary['all'][$status] = ($summary['all'][$status] ?? 0) + $count;
    }

    return $summary;
}

function support_request_pending_count(?array $categories = null): int
{
    $params = [];
    $sql = 'SELECT COUNT(*) FROM support_requests WHERE status = "pending"';

    if ($categories !== null) {
        $normalizedCategories = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $categories
        ), static fn (string $value): bool => in_array($value, ['request', 'feedback', 'issue'], true)));

        if ($normalizedCategories === []) {
            return 0;
        }

        $placeholders = [];
        foreach ($normalizedCategories as $index => $category) {
            $key = 'category_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $category;
        }

        $sql .= ' AND category IN (' . implode(', ', $placeholders) . ')';
    }

    return (int) query_value($sql, $params);
}

function create_support_request(
    string $category,
    string $requestType,
    string $requesterRole,
    ?int $requesterId,
    string $requesterName,
    string $requesterIdentifier,
    ?string $requesterEmail = null,
    ?string $subjectLine = null,
    ?string $messageBody = null,
    ?string $requestedPasswordHash = null
): array {
    $category = strtolower(trim($category));
    $requestType = strtolower(trim($requestType));
    $requesterRole = strtolower(trim($requesterRole));
    $requesterName = trim($requesterName);
    $requesterIdentifier = trim($requesterIdentifier);
    $requesterEmail = trim((string) $requesterEmail) !== '' ? strtolower(trim((string) $requesterEmail)) : null;
    $subjectLine = trim((string) $subjectLine) !== '' ? trim((string) $subjectLine) : null;
    $messageBody = trim((string) $messageBody) !== '' ? trim((string) $messageBody) : null;
    $requestedPasswordHash = trim((string) $requestedPasswordHash) !== '' ? trim((string) $requestedPasswordHash) : null;

    if (!in_array($category, ['request', 'feedback', 'issue'], true)) {
        throw new RuntimeException('Unsupported support request category.');
    }
    if ($requestType === '') {
        throw new RuntimeException('Choose a valid request type.');
    }
    if ($requesterRole === '') {
        throw new RuntimeException('Requester role is required.');
    }
    if ($requesterName === '' || $requesterIdentifier === '') {
        throw new RuntimeException('Requester details are incomplete.');
    }

    $existingId = (int) (query_value(
        'SELECT id
         FROM support_requests
         WHERE category = :category
           AND request_type = :request_type
           AND requester_role = :requester_role
           AND requester_identifier = :requester_identifier
           AND status = "pending"
         ORDER BY id DESC
         LIMIT 1',
        [
            'category' => $category,
            'request_type' => $requestType,
            'requester_role' => $requesterRole,
            'requester_identifier' => $requesterIdentifier,
        ]
    ) ?: 0);

    if ($existingId > 0) {
        execute_sql(
            'UPDATE support_requests
             SET requester_id = :requester_id,
                 requester_name = :requester_name,
                 requester_email = :requester_email,
                 subject_line = :subject_line,
                 message_body = :message_body,
                 requested_password_hash = :requested_password_hash,
                 reviewed_by_admin_id = NULL,
                 reviewed_at = NULL,
                 status = "pending",
                 created_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id',
            [
                'requester_id' => $requesterId,
                'requester_name' => $requesterName,
                'requester_email' => $requesterEmail,
                'subject_line' => $subjectLine,
                'message_body' => $messageBody,
                'requested_password_hash' => $requestedPasswordHash,
                'id' => $existingId,
            ]
        );

        return support_request_by_id($existingId) ?? throw new RuntimeException('Support request could not be refreshed.');
    }

    execute_sql(
        'INSERT INTO support_requests (
            category,
            request_type,
            requester_role,
            requester_id,
            requester_name,
            requester_identifier,
            requester_email,
            subject_line,
            message_body,
            requested_password_hash
         ) VALUES (
            :category,
            :request_type,
            :requester_role,
            :requester_id,
            :requester_name,
            :requester_identifier,
            :requester_email,
            :subject_line,
            :message_body,
            :requested_password_hash
         )',
        [
            'category' => $category,
            'request_type' => $requestType,
            'requester_role' => $requesterRole,
            'requester_id' => $requesterId,
            'requester_name' => $requesterName,
            'requester_identifier' => $requesterIdentifier,
            'requester_email' => $requesterEmail,
            'subject_line' => $subjectLine,
            'message_body' => $messageBody,
            'requested_password_hash' => $requestedPasswordHash,
        ]
    );

    return support_request_by_id((int) db()->lastInsertId()) ?? throw new RuntimeException('Support request could not be created.');
}

function create_teacher_access_request(string $teacherCode, string $requestType, ?string $newPassword = null): array
{
    $teacher = teacher_by_code($teacherCode);
    if (!$teacher) {
        throw new RuntimeException('Faculty ID not found. Enter the registered faculty ID.');
    }

    $requestType = strtolower(trim($requestType));
    $requestedPasswordHash = null;
    $subjectLine = null;
    $messageBody = null;

    if ($requestType === 'forgot_password') {
        $newPassword = validate_password_strength((string) $newPassword);
        $requestedPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $subjectLine = 'Faculty password reset approval';
        $messageBody = 'Faculty requested approval to replace the current login password with the newly submitted password.';
    } elseif ($requestType === 'forgot_faculty_id') {
        if (trim((string) ($teacher['email'] ?? '')) === '') {
            throw new RuntimeException('The faculty account does not have an email address on record. Contact the administrator directly.');
        }

        $subjectLine = 'Faculty ID reminder request';
        $messageBody = 'Faculty requested approval to receive the registered faculty ID through the default mail client.';
    } else {
        throw new RuntimeException('Select a valid request type.');
    }

    return create_support_request(
        'request',
        $requestType,
        'teacher',
        (int) $teacher['id'],
        (string) $teacher['full_name'],
        (string) $teacher['teacher_code'],
        (string) ($teacher['email'] ?? ''),
        $subjectLine,
        $messageBody,
        $requestedPasswordHash
    );
}

function support_request_mailto_link(array $request): ?string
{
    if (($request['category'] ?? null) !== 'request' || ($request['request_type'] ?? null) !== 'forgot_faculty_id') {
        return null;
    }

    $email = trim((string) ($request['requester_email'] ?? ''));
    $facultyId = trim((string) ($request['requester_identifier'] ?? ''));
    if ($email === '' || $facultyId === '') {
        return null;
    }

    $recipientName = trim((string) ($request['requester_name'] ?? 'Faculty'));
    $subject = rawurlencode('Your BIPE Faculty ID');
    $body = rawurlencode("Hello {$recipientName}\n\nYour BIPE faculty ID is: {$facultyId}\n\nPlease keep this ID safe for future login and support requests.\n\nRegards,\nBIPE Academic Portal Admin");

    return 'mailto:' . $email . '?subject=' . $subject . '&body=' . $body;
}

function approve_support_request(int $requestId, int $adminId): array
{
    $request = support_request_by_id($requestId);
    if (!$request) {
        throw new RuntimeException('Selected request was not found.');
    }
    if (($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This request has already been reviewed.');
    }

    db()->beginTransaction();
    try {
        if (($request['category'] ?? '') === 'request' && ($request['request_type'] ?? '') === 'forgot_password') {
            $teacherId = (int) ($request['requester_id'] ?? 0);
            $passwordHash = trim((string) ($request['requested_password_hash'] ?? ''));
            if ($teacherId <= 0 || $passwordHash === '') {
                throw new RuntimeException('Requested password data is missing for this approval.');
            }
            if (!teacher_by_id($teacherId)) {
                throw new RuntimeException('The faculty account linked to this request no longer exists.');
            }

            execute_sql(
                'UPDATE teachers
                 SET password_hash = :password_hash, updated_at = NOW()
                 WHERE id = :id',
                ['password_hash' => $passwordHash, 'id' => $teacherId]
            );
        }

        execute_sql(
            'UPDATE support_requests
             SET status = "approved",
                 reviewed_by_admin_id = :admin_id,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id',
            ['admin_id' => $adminId, 'id' => $requestId]
        );
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    $approved = support_request_by_id($requestId) ?? $request;

    return [
        'request' => $approved,
        'mailto' => support_request_mailto_link($approved),
    ];
}

function reject_support_request(int $requestId, int $adminId): array
{
    $request = support_request_by_id($requestId);
    if (!$request) {
        throw new RuntimeException('Selected request was not found.');
    }
    if (($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This request has already been reviewed.');
    }

    execute_sql(
        'UPDATE support_requests
         SET status = "rejected",
             reviewed_by_admin_id = :admin_id,
             reviewed_at = NOW(),
             updated_at = NOW()
         WHERE id = :id',
        ['admin_id' => $adminId, 'id' => $requestId]
    );

    return support_request_by_id($requestId) ?? $request;
}

function holiday_rows(?int $departmentId = null): array
{
    $params = ['academic_year_id' => current_academic_year_id()];
    $sql = 'SELECT h.*, d.name AS department_name,
                   COALESCE(h.end_date, h.event_date) AS event_to_date,
                   DATEDIFF(COALESCE(h.end_date, h.event_date), h.event_date) + 1 AS total_days
            FROM holiday_events h
            LEFT JOIN departments d ON d.id = h.department_id
            WHERE h.academic_year_id = :academic_year_id';

    if ($departmentId !== null) {
        $sql .= ' AND (h.scope_type = "all" OR h.department_id = :department_id)';
        $params['department_id'] = $departmentId;
    }

    $sql .= ' ORDER BY h.event_date DESC, COALESCE(h.end_date, h.event_date) DESC, h.id DESC';

    return query_all($sql, $params);
}

function holiday_event_total_days(array $holiday): int
{
    if (isset($holiday['total_days'])) {
        return max(1, (int) $holiday['total_days']);
    }

    $from = new DateTimeImmutable((string) $holiday['event_date']);
    $toValue = $holiday['end_date'] ?? $holiday['event_to_date'] ?? $holiday['event_date'];
    $to = new DateTimeImmutable((string) $toValue);

    return max(1, (int) $from->diff($to)->days + 1);
}

function holiday_event_date_label(array $holiday): string
{
    $fromDate = (string) ($holiday['event_date'] ?? '');
    $toDate = (string) ($holiday['end_date'] ?? ($holiday['event_to_date'] ?? ''));

    if ($toDate === '' || $toDate === $fromDate) {
        return $fromDate;
    }

    return $fromDate . ' to ' . $toDate;
}

function holiday_event_for_date(?int $departmentId, string $date): ?array
{
    return query_one(
        'SELECT h.*, d.name AS department_name,
                COALESCE(h.end_date, h.event_date) AS event_to_date,
                DATEDIFF(COALESCE(h.end_date, h.event_date), h.event_date) + 1 AS total_days
         FROM holiday_events h
         LEFT JOIN departments d ON d.id = h.department_id
         WHERE h.academic_year_id = :academic_year_id
           AND h.event_date <= :event_date
           AND COALESCE(h.end_date, h.event_date) >= :event_date
           AND (h.scope_type = "all" OR h.department_id = :department_id)
         ORDER BY COALESCE(h.end_date, h.event_date) DESC, h.id DESC
         LIMIT 1',
        [
            'academic_year_id' => current_academic_year_id(),
            'event_date' => $date,
            'department_id' => $departmentId,
        ]
    );
}

function add_holiday_event(string $scopeType, ?int $departmentId, string $fromDate, ?string $toDate, string $eventType, string $title, ?int $teacherId = null, ?int $adminId = null, ?string $notes = null): void
{
    $scopeType = trim($scopeType);
    if (!in_array($scopeType, ['all', 'department'], true)) {
        throw new RuntimeException('Invalid event scope selected.');
    }

    $fromDate = trim($fromDate);
    $toDate = $toDate !== null ? trim($toDate) : null;
    $title = trim($title);
    $notes = $notes !== null ? trim($notes) : null;

    if ($fromDate === '') {
        throw new RuntimeException('From date is required.');
    }
    if ($title === '') {
        throw new RuntimeException('Event title is required.');
    }
    if ($scopeType === 'department' && ($departmentId === null || $departmentId <= 0)) {
        throw new RuntimeException('Department is required for a department event.');
    }

    $from = new DateTimeImmutable($fromDate);
    $to = $toDate !== null && $toDate !== '' ? new DateTimeImmutable($toDate) : $from;
    if ($to < $from) {
        throw new RuntimeException('To date cannot be earlier than from date.');
    }

    $normalizedEndDate = $to->format('Y-m-d') === $from->format('Y-m-d') ? null : $to->format('Y-m-d');

    execute_sql(
        'INSERT INTO holiday_events (academic_year_id, scope_type, department_id, event_date, end_date, event_type, title, notes, created_by_teacher_id, created_by_admin_id)
         VALUES (:academic_year_id, :scope_type, :department_id, :event_date, :end_date, :event_type, :title, :notes, :created_by_teacher_id, :created_by_admin_id)',
        [
            'academic_year_id' => current_academic_year_id(),
            'scope_type' => $scopeType,
            'department_id' => $scopeType === 'department' ? $departmentId : null,
            'event_date' => $from->format('Y-m-d'),
            'end_date' => $normalizedEndDate,
            'event_type' => $eventType,
            'title' => $title,
            'notes' => $notes !== '' ? $notes : null,
            'created_by_teacher_id' => $teacherId,
            'created_by_admin_id' => $adminId,
        ]
    );
}

function delete_holiday_event(int $holidayId): void
{
    execute_sql('DELETE FROM holiday_events WHERE id = :id', ['id' => $holidayId]);
}

function upsert_attendance_sheet(int $teacherId, int $departmentId, int $yearLevel, int $semesterNo, string $date, array $statuses, ?string $remarks = null): void
{
    $academicYearId = current_academic_year_id();

    db()->beginTransaction();
    try {
        $session = query_one(
            'SELECT id FROM attendance_sessions
             WHERE academic_year_id = :academic_year_id AND department_id = :department_id AND year_level = :year_level AND semester_no = :semester_no AND attendance_date = :attendance_date',
            [
                'academic_year_id' => $academicYearId,
                'department_id' => $departmentId,
                'year_level' => $yearLevel,
                'semester_no' => $semesterNo,
                'attendance_date' => $date,
            ]
        );

        if ($session) {
            execute_sql(
                'UPDATE attendance_sessions
                 SET teacher_id = :teacher_id, remarks = :remarks, updated_at = NOW()
                 WHERE id = :id',
                ['teacher_id' => $teacherId, 'remarks' => $remarks, 'id' => $session['id']]
            );
            $sessionId = (int) $session['id'];
        } else {
            execute_sql(
                'INSERT INTO attendance_sessions (academic_year_id, department_id, year_level, semester_no, teacher_id, attendance_date, remarks)
                 VALUES (:academic_year_id, :department_id, :year_level, :semester_no, :teacher_id, :attendance_date, :remarks)',
                [
                    'academic_year_id' => $academicYearId,
                    'department_id' => $departmentId,
                    'year_level' => $yearLevel,
                    'semester_no' => $semesterNo,
                    'teacher_id' => $teacherId,
                    'attendance_date' => $date,
                    'remarks' => $remarks,
                ]
            );
            $sessionId = (int) db()->lastInsertId();
        }

        foreach ($statuses as $studentId => $status) {
            execute_sql(
                'INSERT INTO attendance_records (attendance_session_id, student_id, status)
                 VALUES (:attendance_session_id, :student_id, :status)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()',
                [
                    'attendance_session_id' => $sessionId,
                    'student_id' => (int) $studentId,
                    'status' => $status === 'P' ? 'P' : 'A',
                ]
            );
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function attendance_scope_student_rows(?int $departmentId, int $yearLevel, int $semesterNo): array
{
    $params = ['year_level' => $yearLevel, 'semester_no' => $semesterNo];
    $sql = 'SELECT s.*, d.name AS department_name, d.short_name AS department_short_name
            FROM students s
            INNER JOIN departments d ON d.id = s.department_id
            WHERE s.year_level = :year_level AND s.semester_no = :semester_no';

    if ($departmentId !== null) {
        $sql .= ' AND s.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }

    $sql .= ' ORDER BY d.name ASC, s.full_name ASC';

    return query_all($sql, $params);
}

function upsert_attendance_scope(int $teacherId, ?int $departmentId, int $yearLevel, int $semesterNo, string $date, array $statuses, ?string $remarks = null): void
{
    if ($departmentId !== null) {
        upsert_attendance_sheet($teacherId, $departmentId, $yearLevel, $semesterNo, $date, $statuses, $remarks);
        return;
    }

    if ($statuses === []) {
        return;
    }

    $groupedStatuses = [];
    foreach (attendance_scope_student_rows(null, $yearLevel, $semesterNo) as $student) {
        $studentId = (int) $student['id'];
        if (!array_key_exists($studentId, $statuses)) {
            continue;
        }

        $groupedStatuses[(int) $student['department_id']][$studentId] = $statuses[$studentId] === 'P' ? 'P' : 'A';
    }

    foreach ($groupedStatuses as $groupDepartmentId => $departmentStatuses) {
        upsert_attendance_sheet($teacherId, (int) $groupDepartmentId, $yearLevel, $semesterNo, $date, $departmentStatuses, $remarks);
    }
}

function attendance_scope_detail(?int $departmentId, int $yearLevel, int $semesterNo, string $date): ?array
{
    $params = [
        'academic_year_id' => current_academic_year_id(),
        'year_level' => $yearLevel,
        'semester_no' => $semesterNo,
        'attendance_date' => $date,
    ];

    $sessionSql = 'SELECT ats.*, t.full_name AS teacher_name, d.name AS department_name
                   FROM attendance_sessions ats
                   INNER JOIN teachers t ON t.id = ats.teacher_id
                   INNER JOIN departments d ON d.id = ats.department_id
                   WHERE ats.academic_year_id = :academic_year_id AND ats.year_level = :year_level AND ats.semester_no = :semester_no AND ats.attendance_date = :attendance_date';

    if ($departmentId !== null) {
        $sessionSql .= ' AND ats.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }

    $sessionSql .= ' ORDER BY d.name ASC, ats.id ASC';
    $sessions = query_all($sessionSql, $params);

    if (!$sessions) {
        return null;
    }

    $recordSql = 'SELECT ar.student_id, ar.status, s.full_name, s.enrollment_no, s.department_id, d.name AS department_name
                  FROM attendance_records ar
                  INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
                  INNER JOIN students s ON s.id = ar.student_id
                  INNER JOIN departments d ON d.id = s.department_id
                  WHERE ats.academic_year_id = :academic_year_id AND ats.year_level = :year_level AND ats.semester_no = :semester_no AND ats.attendance_date = :attendance_date';

    if ($departmentId !== null) {
        $recordSql .= ' AND ats.department_id = :department_id';
    }

    $recordSql .= ' ORDER BY d.name ASC, s.full_name ASC';
    $records = query_all($recordSql, $params);

    $remarks = array_values(array_filter(array_unique(array_map(
        static fn (array $row): string => trim((string) ($row['remarks'] ?? '')),
        $sessions
    ))));

    return [
        'sessions' => $sessions,
        'records' => $records,
        'remarks' => count($remarks) === 1 ? $remarks[0] : '',
        'session_count' => count($sessions),
    ];
}

function attendance_session_detail(int $departmentId, int $yearLevel, int $semesterNo, string $date): ?array
{
    return attendance_scope_detail($departmentId, $yearLevel, $semesterNo, $date);
}

function attendance_history_rows(?int $departmentId = null): array
{
    $params = ['academic_year_id' => current_academic_year_id()];
    $sql = 'SELECT ats.*, t.full_name AS teacher_name, d.name AS department_name,
                   SUM(CASE WHEN ar.status = "P" THEN 1 ELSE 0 END) AS present_count,
                   SUM(CASE WHEN ar.status = "A" THEN 1 ELSE 0 END) AS absent_count,
                   COUNT(ar.id) AS total_count
            FROM attendance_sessions ats
            INNER JOIN teachers t ON t.id = ats.teacher_id
            INNER JOIN departments d ON d.id = ats.department_id
            LEFT JOIN attendance_records ar ON ar.attendance_session_id = ats.id
            WHERE ats.academic_year_id = :academic_year_id';

    if ($departmentId !== null) {
        $sql .= ' AND ats.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }

    $sql .= ' GROUP BY ats.id, t.full_name, d.name ORDER BY ats.attendance_date DESC, d.name ASC';

    return query_all($sql, $params);
}

function attendance_history_for_department(int $departmentId): array
{
    return attendance_history_rows($departmentId);
}

function save_mark_upload(int $teacherId, int $departmentId, int $semesterNo, int $subjectId, string $examType, float $maxMarks, array $records): void
{
    $academicYearId = current_academic_year_id();

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
                'UPDATE mark_uploads
                 SET max_marks = :max_marks, teacher_id = :teacher_id, uploaded_at = NOW()
                 WHERE id = :id',
                ['max_marks' => $maxMarks, 'teacher_id' => $teacherId, 'id' => $upload['id']]
            );
            $uploadId = (int) $upload['id'];
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

        foreach ($records as $studentId => $marks) {
            execute_sql(
                'INSERT INTO mark_records (mark_upload_id, student_id, marks_obtained)
                 VALUES (:mark_upload_id, :student_id, :marks_obtained)
                 ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained), updated_at = NOW()',
                [
                    'mark_upload_id' => $uploadId,
                    'student_id' => (int) $studentId,
                    'marks_obtained' => $marks,
                ]
            );
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function delete_mark_upload(int $uploadId): void
{
    execute_sql('DELETE FROM mark_uploads WHERE id = :id', ['id' => $uploadId]);
}

function mark_upload_rows_for_department(int $departmentId): array
{
    return query_all(
        'SELECT mu.*, s.subject_name, t.full_name AS teacher_name
         FROM mark_uploads mu
         INNER JOIN subjects s ON s.id = mu.subject_id
         INNER JOIN teachers t ON t.id = mu.teacher_id
         WHERE mu.academic_year_id = :academic_year_id AND mu.department_id = :department_id
         ORDER BY mu.uploaded_at DESC',
        ['academic_year_id' => current_academic_year_id(), 'department_id' => $departmentId]
    );
}

function save_assignment_sheet(int $teacherId, int $departmentId, int $semesterNo, int $subjectId, string $label, array $statuses, ?string $dueDate = null, ?string $notes = null): void
{
    $academicYearId = current_academic_year_id();

    db()->beginTransaction();
    try {
        $assignment = query_one(
            'SELECT id FROM assignments
             WHERE academic_year_id = :academic_year_id AND department_id = :department_id AND semester_no = :semester_no AND subject_id = :subject_id AND assignment_label = :assignment_label',
            [
                'academic_year_id' => $academicYearId,
                'department_id' => $departmentId,
                'semester_no' => $semesterNo,
                'subject_id' => $subjectId,
                'assignment_label' => $label,
            ]
        );

        if ($assignment) {
            execute_sql(
                'UPDATE assignments
                 SET teacher_id = :teacher_id, due_date = :due_date, notes = :notes, updated_at = NOW()
                 WHERE id = :id',
                ['teacher_id' => $teacherId, 'due_date' => $dueDate, 'notes' => $notes, 'id' => $assignment['id']]
            );
            $assignmentId = (int) $assignment['id'];
        } else {
            execute_sql(
                'INSERT INTO assignments (academic_year_id, department_id, semester_no, subject_id, teacher_id, assignment_label, due_date, notes)
                 VALUES (:academic_year_id, :department_id, :semester_no, :subject_id, :teacher_id, :assignment_label, :due_date, :notes)',
                [
                    'academic_year_id' => $academicYearId,
                    'department_id' => $departmentId,
                    'semester_no' => $semesterNo,
                    'subject_id' => $subjectId,
                    'teacher_id' => $teacherId,
                    'assignment_label' => $label,
                    'due_date' => $dueDate,
                    'notes' => $notes,
                ]
            );
            $assignmentId = (int) db()->lastInsertId();
        }

        foreach ($statuses as $studentId => $status) {
            $submitted = $status === 'submitted';
            execute_sql(
                'INSERT INTO assignment_submissions (assignment_id, student_id, submission_status, submitted_at)
                 VALUES (:assignment_id, :student_id, :submission_status, :submitted_at)
                 ON DUPLICATE KEY UPDATE submission_status = VALUES(submission_status), submitted_at = VALUES(submitted_at), updated_at = NOW()',
                [
                    'assignment_id' => $assignmentId,
                    'student_id' => (int) $studentId,
                    'submission_status' => $submitted ? 'submitted' : 'pending',
                    'submitted_at' => $submitted ? date('Y-m-d H:i:s') : null,
                ]
            );
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function assignment_rows_for_department(int $departmentId): array
{
    return query_all(
        'SELECT a.*, s.subject_name, t.full_name AS teacher_name,
                SUM(CASE WHEN asb.submission_status = "submitted" THEN 1 ELSE 0 END) AS submitted_count,
                COUNT(asb.id) AS tracked_count
         FROM assignments a
         INNER JOIN subjects s ON s.id = a.subject_id
         INNER JOIN teachers t ON t.id = a.teacher_id
         LEFT JOIN assignment_submissions asb ON asb.assignment_id = a.id
         WHERE a.academic_year_id = :academic_year_id AND a.department_id = :department_id
         GROUP BY a.id, s.subject_name, t.full_name
         ORDER BY a.created_at DESC',
        ['academic_year_id' => current_academic_year_id(), 'department_id' => $departmentId]
    );
}

function create_admin_otp_request(string $username): ?array
{
    $admin = query_one('SELECT * FROM admins WHERE username = :username LIMIT 1', ['username' => strtolower(trim($username))]);
    if (!$admin) {
        return null;
    }

    $otp = (string) random_int(100000, 999999);
    execute_sql(
        'INSERT INTO admin_otp_requests (admin_id, otp_code, purpose, expires_at)
         VALUES (:admin_id, :otp_code, "password_reset", DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
        ['admin_id' => $admin['id'], 'otp_code' => $otp]
    );

    return ['otp' => $otp, 'admin' => $admin];
}

function reset_admin_password_with_otp(string $username, string $otp, string $newPassword): bool
{
    $admin = query_one('SELECT * FROM admins WHERE username = :username LIMIT 1', ['username' => strtolower(trim($username))]);
    if (!$admin) {
        return false;
    }

    $request = query_one(
        'SELECT * FROM admin_otp_requests
         WHERE admin_id = :admin_id AND otp_code = :otp_code AND consumed_at IS NULL AND expires_at >= NOW()
         ORDER BY id DESC LIMIT 1',
        ['admin_id' => $admin['id'], 'otp_code' => trim($otp)]
    );

    if (!$request) {
        return false;
    }

    validate_password_strength($newPassword);

    db()->beginTransaction();
    try {
        execute_sql(
            'UPDATE admins SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $admin['id']]
        );
        execute_sql(
            'UPDATE admin_otp_requests SET consumed_at = NOW() WHERE id = :id',
            ['id' => $request['id']]
        );
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return true;
}

function create_academic_year(string $label, string $mode): void
{
    db()->beginTransaction();
    try {
        execute_sql('UPDATE academic_years SET is_current = 0');
        execute_sql(
            'INSERT INTO academic_years (label, is_current) VALUES (:label, 1)',
            ['label' => $label]
        );

        if ($mode === 'clear_academic') {
            execute_sql('DELETE FROM assignment_submissions');
            execute_sql('DELETE FROM assignments');
            execute_sql('DELETE FROM mark_records');
            execute_sql('DELETE FROM mark_uploads');
            execute_sql('DELETE FROM attendance_records');
            execute_sql('DELETE FROM attendance_sessions');
            execute_sql('DELETE FROM holiday_events');
        }

        if ($mode === 'clear_all') {
            execute_sql('DELETE FROM assignment_submissions');
            execute_sql('DELETE FROM assignments');
            execute_sql('DELETE FROM mark_records');
            execute_sql('DELETE FROM mark_uploads');
            execute_sql('DELETE FROM attendance_records');
            execute_sql('DELETE FROM attendance_sessions');
            execute_sql('DELETE FROM holiday_events');
            execute_sql('DELETE FROM students');
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function reset_portal(): void
{
    db()->beginTransaction();
    try {
        execute_sql('DELETE FROM assignment_submissions');
        execute_sql('DELETE FROM assignments');
        execute_sql('DELETE FROM mark_records');
        execute_sql('DELETE FROM mark_uploads');
        execute_sql('DELETE FROM attendance_records');
        execute_sql('DELETE FROM attendance_sessions');
        execute_sql('DELETE FROM holiday_events');
        execute_sql('DELETE FROM admin_otp_requests');
        execute_sql('DELETE FROM support_requests');
        execute_sql('DELETE FROM teachers');
        execute_sql('DELETE FROM students');
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}













