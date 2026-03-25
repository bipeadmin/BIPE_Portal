SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS assignment_submissions;
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS mark_records;
DROP TABLE IF EXISTS mark_uploads;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS holiday_events;
DROP TABLE IF EXISTS password_reset_otps;
DROP TABLE IF EXISTS admin_otp_requests;
DROP TABLE IF EXISTS mark_locks;
DROP TABLE IF EXISTS mark_types;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS teachers;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS academic_years;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE academic_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(40) NOT NULL UNIQUE,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    code VARCHAR(12) NOT NULL UNIQUE,
    short_name VARCHAR(20) NOT NULL,
    icon VARCHAR(16) NOT NULL,
    theme_color VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    teacher_code VARCHAR(60) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_teachers_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    enrollment_no VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    year_level TINYINT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    registered_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_students_email (email),
    CONSTRAINT fk_students_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    subject_code VARCHAR(40) NOT NULL,
    subject_name VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subject_name (department_id, semester_no, subject_name),
    UNIQUE KEY uq_subject_code (department_id, semester_no, subject_code),
    CONSTRAINT fk_subjects_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE mark_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL UNIQUE,
    max_marks DECIMAL(6,2) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE mark_locks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mark_lock (department_id, semester_no),
    CONSTRAINT fk_mark_locks_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admin_otp_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    otp_code VARCHAR(12) NOT NULL,
    purpose VARCHAR(50) NOT NULL DEFAULT 'password_reset',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_otps_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_reset_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name ENUM('admin', 'teacher', 'student') NOT NULL,
    user_ref_id INT UNSIGNED DEFAULT NULL,
    email VARCHAR(150) NOT NULL,
    otp_code VARCHAR(12) NOT NULL,
    purpose VARCHAR(50) NOT NULL DEFAULT 'password_reset',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_lookup (role_name, email, otp_code, consumed_at, expires_at)
) ENGINE=InnoDB;

CREATE TABLE holiday_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT UNSIGNED NOT NULL,
    scope_type ENUM('all', 'department') NOT NULL DEFAULT 'all',
    department_id INT UNSIGNED DEFAULT NULL,
    event_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    event_type ENUM('Holiday', 'Leave', 'Festival') NOT NULL,
    title VARCHAR(150) NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_by_teacher_id INT UNSIGNED DEFAULT NULL,
    created_by_admin_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_holiday_range (academic_year_id, event_date, end_date),
    CONSTRAINT fk_holidays_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    CONSTRAINT fk_holidays_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_holidays_teacher FOREIGN KEY (created_by_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    CONSTRAINT fk_holidays_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE attendance_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    year_level TINYINT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_attendance_day (academic_year_id, department_id, year_level, semester_no, attendance_date),
    CONSTRAINT fk_attendance_sessions_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    CONSTRAINT fk_attendance_sessions_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_attendance_sessions_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

CREATE TABLE attendance_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_session_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status ENUM('P', 'A') NOT NULL DEFAULT 'A',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_attendance_student (attendance_session_id, student_id),
    CONSTRAINT fk_attendance_records_session FOREIGN KEY (attendance_session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_records_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE mark_uploads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    exam_type VARCHAR(60) NOT NULL,
    max_marks DECIMAL(6,2) NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mark_upload (academic_year_id, department_id, semester_no, subject_id, exam_type),
    CONSTRAINT fk_mark_uploads_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    CONSTRAINT fk_mark_uploads_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_mark_uploads_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_mark_uploads_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

CREATE TABLE mark_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mark_upload_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    marks_obtained DECIMAL(6,2) DEFAULT NULL,
    is_absent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_mark_record (mark_upload_id, student_id),
    CONSTRAINT fk_mark_records_upload FOREIGN KEY (mark_upload_id) REFERENCES mark_uploads(id) ON DELETE CASCADE,
    CONSTRAINT fk_mark_records_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    assignment_label ENUM('Assignment-I', 'Assignment-II', 'Assignment-III') NOT NULL,
    due_date DATE DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_assignment (academic_year_id, department_id, semester_no, subject_id, assignment_label),
    CONSTRAINT fk_assignments_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    CONSTRAINT fk_assignments_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_assignments_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_assignments_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

CREATE TABLE assignment_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    submission_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
    submitted_at DATETIME DEFAULT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_assignment_submission (assignment_id, student_id),
    CONSTRAINT fk_assignment_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_submissions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name ENUM('admin', 'teacher', 'student', 'system') NOT NULL,
    user_identifier VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    action_code VARCHAR(80) NOT NULL,
    details VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_role_time (role_name, created_at),
    INDEX idx_audit_ip_time (ip_address, created_at),
    INDEX idx_audit_action_time (action_code, created_at)
) ENGINE=InnoDB;


