-- University Management System Database Schema
-- Fixed version with proper PDO compatibility and missing columns

DROP DATABASE IF EXISTS university_management;

-- Create database
CREATE DATABASE IF NOT EXISTS university_management;
USE university_management;

-- Users table (base table for authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('admin', 'instructor', 'student', 'applicant') NOT NULL,
    mfa_email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    reset_token VARCHAR(255) NULL,
    reset_expires TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    graduation_lock_at DATETIME NULL
);

-- OTP codes for MFA, passwordless login, and email verification
CREATE TABLE IF NOT EXISTS otp_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    code_hash CHAR(64) NOT NULL,
    channel ENUM('email') NOT NULL DEFAULT 'email',
    purpose ENUM('mfa','passwordless','verify_email') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_purpose (user_id, purpose, expires_at)
);

-- Academic years table
CREATE TABLE IF NOT EXISTS academic_years (
    id INT PRIMARY KEY AUTO_INCREMENT,
    year_name VARCHAR(20) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Semesters table
CREATE TABLE IF NOT EXISTS semesters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    semester_name ENUM('First Semester', 'Second Semester') NOT NULL,
    academic_year_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN DEFAULT FALSE,
    registration_deadline DATE NULL,
    exam_period_start DATE NULL,
    exam_period_end DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    UNIQUE KEY uniq_semester_per_year (academic_year_id, semester_name)
);

-- Departments table
CREATE TABLE IF NOT EXISTS departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dept_code VARCHAR(10) UNIQUE NOT NULL,
    dept_name VARCHAR(100) NOT NULL,
    dept_head VARCHAR(100) NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Academic levels table (Year 1, Year 2, Year 3, Year 4)
CREATE TABLE IF NOT EXISTS levels (
    id INT PRIMARY KEY AUTO_INCREMENT,
    level_name VARCHAR(20) NOT NULL,
    level_order INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Courses table
CREATE TABLE IF NOT EXISTS courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_code VARCHAR(20) UNIQUE NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    department_id INT NOT NULL,
    level_id INT NOT NULL,
    credits INT NOT NULL,
    description TEXT,
    prerequisites TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (level_id) REFERENCES levels(id)
);

-- Students table
CREATE TABLE IF NOT EXISTS students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    date_of_birth DATE,
    address TEXT,
    current_level_id INT NOT NULL,
    user_id INT UNIQUE NOT NULL,
    department_id INT NOT NULL,
    program_id INT NULL,
    enrollment_date DATE NOT NULL,
    status ENUM('active', 'graduated', 'withdrawn', 'suspended') DEFAULT 'active',
    gpa DECIMAL(3,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (current_level_id) REFERENCES levels(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Instructors table
CREATE TABLE IF NOT EXISTS instructors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    department_id INT NOT NULL,
    user_id INT UNIQUE NOT NULL,
    hire_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Course sections table
CREATE TABLE IF NOT EXISTS course_sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    section_name VARCHAR(10) NOT NULL,
    instructor_id INT NOT NULL,
    schedule VARCHAR(100),
    room VARCHAR(50),
    capacity INT DEFAULT 30,
    enrolled_count INT DEFAULT 0,
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id),
    FOREIGN KEY (semester_id) REFERENCES semesters(id)
);

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_user_token (user_id, token_hash)
);

-- Enrollments table
CREATE TABLE IF NOT EXISTS enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_section_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    status ENUM('enrolled', 'dropped', 'completed', 'failed') DEFAULT 'enrolled',
    final_grade VARCHAR(5),
    grade_points DECIMAL(3,2) DEFAULT 0.00,
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (course_section_id) REFERENCES course_sections(id),
    FOREIGN KEY (semester_id) REFERENCES semesters(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id)
);

-- Grades table (for individual assessments)
CREATE TABLE IF NOT EXISTS grades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enrollment_id INT NOT NULL,
    assessment_type ENUM('quiz', 'exam', 'assignment', 'project', 'participation', 'final') NOT NULL,
    assessment_name VARCHAR(100) NOT NULL,
    score DECIMAL(5,2),
    max_score DECIMAL(5,2),
    weight DECIMAL(5,2) NOT NULL,
    grade_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id)
);

-- Attendance table
CREATE TABLE IF NOT EXISTS attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enrollment_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
    notes TEXT,
    marked_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id),
    FOREIGN KEY (marked_by) REFERENCES instructors(id)
);

-- Fee payments table
CREATE TABLE IF NOT EXISTS fee_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    status ENUM('paid', 'pending', 'overdue', 'cancelled') DEFAULT 'pending',
    payment_method VARCHAR(50),
    transaction_id VARCHAR(100),
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    scholarship_amount DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (semester_id) REFERENCES semesters(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id)
);

-- Student advisors table
CREATE TABLE IF NOT EXISTS student_advisors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    instructor_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id)
);

-- System logs table (for audit trail)
CREATE TABLE IF NOT EXISTS system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sender_user_id INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (sender_user_id) REFERENCES users(id)
);

-- Create indexes for better performance (only if they don't exist)
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_students_dept ON students(department_id);
CREATE INDEX IF NOT EXISTS idx_students_level ON students(current_level_id);
CREATE INDEX IF NOT EXISTS idx_courses_dept ON courses(department_id);
CREATE INDEX IF NOT EXISTS idx_courses_level ON courses(level_id);
CREATE INDEX IF NOT EXISTS idx_enrollments_student ON enrollments(student_id);
CREATE INDEX IF NOT EXISTS idx_enrollments_course ON enrollments(course_section_id);
CREATE INDEX IF NOT EXISTS idx_enrollments_semester ON enrollments(semester_id);
CREATE INDEX IF NOT EXISTS idx_grades_enrollment ON grades(enrollment_id);
CREATE INDEX IF NOT EXISTS idx_attendance_enrollment ON attendance(enrollment_id);
CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance(attendance_date);
CREATE INDEX IF NOT EXISTS idx_fee_payments_student ON fee_payments(student_id);
CREATE INDEX IF NOT EXISTS idx_fee_payments_status ON fee_payments(status);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_user_id);
CREATE INDEX IF NOT EXISTS idx_logs_user ON system_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_logs_created ON system_logs(created_at);

-- Add unique indexes that might be missing
CREATE UNIQUE INDEX IF NOT EXISTS unique_section_idx ON course_sections (course_id, section_name, semester_id);
CREATE UNIQUE INDEX IF NOT EXISTS unique_enrollment_idx ON enrollments (student_id, course_section_id, semester_id);
CREATE UNIQUE INDEX IF NOT EXISTS unique_attendance_idx ON attendance (enrollment_id, attendance_date);
CREATE UNIQUE INDEX IF NOT EXISTS unique_advisor_idx ON student_advisors (student_id, instructor_id);

-- Waitlists table
CREATE TABLE IF NOT EXISTS waitlists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_section_id INT NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_section (student_id, course_section_id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (course_section_id) REFERENCES course_sections(id)
);

-- Programs and catalog (Phase 1)
CREATE TABLE IF NOT EXISTS programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_code VARCHAR(20) UNIQUE NOT NULL,
    program_name VARCHAR(200) NOT NULL,
    department_id INT NOT NULL,
    total_credits INT NOT NULL,
    cutoff_aggregate INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Ensure students.program_id references programs (run after both tables exist)
ALTER TABLE students
  ADD CONSTRAINT fk_students_program FOREIGN KEY (program_id) REFERENCES programs(id);

CREATE TABLE IF NOT EXISTS program_courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_id INT NOT NULL,
    course_id INT NOT NULL,
    term_number INT NULL,
    required BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_program_course (program_id, course_id),
    FOREIGN KEY (program_id) REFERENCES programs(id),
    FOREIGN KEY (course_id) REFERENCES courses(id)
);

CREATE TABLE IF NOT EXISTS course_prerequisites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    prereq_course_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_course_prereq (course_id, prereq_course_id),
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (prereq_course_id) REFERENCES courses(id)
);

-- Admissions (applications) and documents (Phase 1)
CREATE TABLE IF NOT EXISTS applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_email VARCHAR(100) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NULL,
    program_id INT NOT NULL,
    wasse_aggregate INT NULL,
    user_id INT NULL,
    application_key VARCHAR(64) UNIQUE NOT NULL,
    status ENUM('applied','under_review','offered','accepted','rejected') DEFAULT 'applied',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL,
    offer_notes TEXT NULL,
    decided_reason TEXT NULL,
    FOREIGN KEY (program_id) REFERENCES programs(id)
    ,FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    owner_type ENUM('application','student','instructor') NOT NULL,
    owner_id INT NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- Billing (Phase 1) - separate from legacy fee_payments
CREATE TABLE IF NOT EXISTS billing_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance DECIMAL(10,2) NOT NULL,
    status ENUM('open','paid','void') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (semester_id) REFERENCES semesters(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id)
);

CREATE TABLE IF NOT EXISTS billing_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    method VARCHAR(50) NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    receipt_no VARCHAR(50) UNIQUE,
    notes TEXT,
    FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id)
);

-- Messaging outbox (simulate email/SMS delivery offline)
CREATE TABLE IF NOT EXISTS email_outbox (
    id INT PRIMARY KEY AUTO_INCREMENT,
    to_user_id INT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    template VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    FOREIGN KEY (to_user_id) REFERENCES users(id)
);

-- RBAC (optional extended permissions)
CREATE TABLE IF NOT EXISTS roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    perm_key VARCHAR(100) UNIQUE NOT NULL,
    description VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Seed initial programs (idempotent)
INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits)
SELECT 'BSC-CS', 'B.Sc. Computer Science', d.id, 120 FROM departments d WHERE d.dept_code = 'CS'
UNION ALL
SELECT 'BSC-IT', 'B.Sc. Information Technology', d.id, 120 FROM departments d WHERE d.dept_code = 'IT'
UNION ALL
SELECT 'BA-ENG', 'B.A. English', d.id, 120 FROM departments d WHERE d.dept_code = 'ENG';

-- Materialized summary tables (hosting environment lacks CREATE VIEW privilege)

CREATE TABLE IF NOT EXISTS student_enrollments_view (
    id INT PRIMARY KEY,
    student_id VARCHAR(50),
    student_name VARCHAR(150),
    course_code VARCHAR(30),
    course_name VARCHAR(255),
    section_name VARCHAR(30),
    instructor_name VARCHAR(150),
    dept_name VARCHAR(150),
    level_name VARCHAR(50),
    semester_name VARCHAR(100),
    year_name VARCHAR(20),
    status VARCHAR(20),
    final_grade VARCHAR(10),
    grade_points DECIMAL(5,2),
    KEY idx_student_enrollments_view_student (student_id),
    KEY idx_student_enrollments_view_course (course_code),
    KEY idx_student_enrollments_view_term (semester_name, year_name)
);

CREATE TABLE IF NOT EXISTS course_enrollment_summary (
    id INT PRIMARY KEY,
    course_code VARCHAR(30),
    course_name VARCHAR(255),
    section_name VARCHAR(30),
    instructor_name VARCHAR(150),
    capacity INT,
    enrolled_count INT,
    available_spots INT,
    semester_name VARCHAR(100),
    year_name VARCHAR(20),
    room VARCHAR(100),
    schedule VARCHAR(150),
    KEY idx_course_enrollment_summary_course (course_code),
    KEY idx_course_enrollment_summary_term (semester_name, year_name)
);

CREATE TABLE IF NOT EXISTS student_gpa_view (
    id INT PRIMARY KEY,
    student_id VARCHAR(50),
    student_name VARCHAR(150),
    department_id INT,
    dept_name VARCHAR(150),
    total_courses INT,
    total_credits INT,
    total_grade_points DECIMAL(12,2),
    calculated_gpa DECIMAL(5,2),
    KEY idx_student_gpa_view_student (student_id)
);
