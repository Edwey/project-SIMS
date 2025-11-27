-- University Management System Database Schema
-- FIXED VERSION - All Issues Resolved
-- Compatible with MySQL 8.0+ and MariaDB 10.5+

DROP DATABASE IF EXISTS university_management;

-- Create database
CREATE DATABASE IF NOT EXISTS university_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE university_management;

-- ============================================================================
-- CORE TABLES (Independent - No Foreign Keys)
-- ============================================================================

-- Users table (base table for authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
    role ENUM('admin', 'instructor', 'student', 'applicant') NOT NULL,
    mfa_email_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    reset_token VARCHAR(255) NULL,
    reset_expires TIMESTAMP NULL,
    login_attempts INT DEFAULT 0 CHECK (login_attempts >= 0),
    locked_until TIMESTAMP NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_email (email),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB;

-- Departments table
CREATE TABLE IF NOT EXISTS departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dept_code VARCHAR(10) UNIQUE NOT NULL,
    dept_name VARCHAR(100) NOT NULL,
    dept_head VARCHAR(100) NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Academic levels table (Year 1, Year 2, Year 3, Year 4)
CREATE TABLE IF NOT EXISTS levels (
    id INT PRIMARY KEY AUTO_INCREMENT,
    level_name VARCHAR(20) NOT NULL,
    level_order INT NOT NULL CHECK (level_order > 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_level_order (level_order)
) ENGINE=InnoDB;

-- Academic years table
CREATE TABLE IF NOT EXISTS academic_years (
    id INT PRIMARY KEY AUTO_INCREMENT,
    year_name VARCHAR(20) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (end_date > start_date)
) ENGINE=InnoDB;

-- ============================================================================
-- LEVEL 2 TABLES (Depend on Level 1)
-- ============================================================================

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
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_semester_per_year (academic_year_id, semester_name),
    CHECK (end_date > start_date),
    INDEX idx_semester_year (academic_year_id),
    INDEX idx_semester_current (is_current)
) ENGINE=InnoDB;

-- Programs table (MOVED BEFORE STUDENTS - CRITICAL FIX)
CREATE TABLE IF NOT EXISTS programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_code VARCHAR(20) UNIQUE NOT NULL,
    program_name VARCHAR(200) NOT NULL,
    department_id INT NOT NULL,
    total_credits INT NOT NULL CHECK (total_credits > 0),
    cutoff_aggregate INT NULL CHECK (cutoff_aggregate >= 0 AND cutoff_aggregate <= 100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    INDEX idx_program_dept (department_id)
) ENGINE=InnoDB;

-- Courses table
CREATE TABLE IF NOT EXISTS courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_code VARCHAR(20) UNIQUE NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    department_id INT NOT NULL,
    level_id INT NOT NULL,
    credits INT NOT NULL CHECK (credits > 0),
    description TEXT,
    prerequisites TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE RESTRICT,
    INDEX idx_courses_dept (department_id),
    INDEX idx_courses_level (level_id),
    INDEX idx_courses_code (course_code)
) ENGINE=InnoDB;

-- Instructors table
CREATE TABLE IF NOT EXISTS instructors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    department_id INT NOT NULL,
    user_id INT UNIQUE NOT NULL,
    hire_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_instructor_dept (department_id),
    INDEX idx_instructor_user (user_id)
) ENGINE=InnoDB;

-- Students table (NOW SAFE - programs exists)
CREATE TABLE IF NOT EXISTS students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    date_of_birth DATE,
    address TEXT,
    current_level_id INT NOT NULL,
    user_id INT UNIQUE NOT NULL,
    department_id INT NOT NULL,
    program_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    status ENUM('active', 'graduated', 'withdrawn', 'suspended') DEFAULT 'active',
    gpa DECIMAL(4,2) DEFAULT 0.00 CHECK (gpa >= 0.00 AND gpa <= 4.00),
    graduation_lock_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (current_level_id) REFERENCES levels(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
    INDEX idx_students_dept (department_id),
    INDEX idx_students_level (current_level_id),
    INDEX idx_students_program (program_id),
    INDEX idx_students_status (status),
    INDEX idx_students_user (user_id)
) ENGINE=InnoDB;

-- ============================================================================
-- LEVEL 3 TABLES (Depend on Level 2)
-- ============================================================================

-- Course sections table
CREATE TABLE IF NOT EXISTS course_sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    section_name VARCHAR(10) NOT NULL,
    instructor_id INT NOT NULL,
    schedule VARCHAR(100),
    room VARCHAR(50),
    capacity INT DEFAULT 30 CHECK (capacity > 0),
    enrolled_count INT DEFAULT 0 CHECK (enrolled_count >= 0),
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE RESTRICT,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_section_idx (course_id, section_name, semester_id),
    CHECK (enrolled_count <= capacity),
    INDEX idx_section_course (course_id),
    INDEX idx_section_instructor (instructor_id),
    INDEX idx_section_semester (semester_id),
    INDEX idx_section_year (academic_year_id)
) ENGINE=InnoDB;

-- Program courses (curriculum mapping)
CREATE TABLE IF NOT EXISTS program_courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_id INT NOT NULL,
    course_id INT NOT NULL,
    term_number INT NULL CHECK (term_number > 0),
    required BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_program_course (program_id, course_id),
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_program_courses_program (program_id),
    INDEX idx_program_courses_course (course_id)
) ENGINE=InnoDB;

-- Course prerequisites (self-referencing)
CREATE TABLE IF NOT EXISTS course_prerequisites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    prereq_course_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_course_prereq (course_id, prereq_course_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (prereq_course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CHECK (course_id != prereq_course_id),
    INDEX idx_prereq_course (course_id),
    INDEX idx_prereq_required (prereq_course_id)
) ENGINE=InnoDB;

-- Enrollments table
CREATE TABLE IF NOT EXISTS enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_section_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    status ENUM('enrolled', 'dropped', 'completed', 'failed') DEFAULT 'enrolled',
    final_grade VARCHAR(5),
    grade_points DECIMAL(4,2) DEFAULT 0.00 CHECK (grade_points >= 0.00 AND grade_points <= 4.00),
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_enrollment_idx (student_id, course_section_id, semester_id),
    INDEX idx_enrollments_student (student_id),
    INDEX idx_enrollments_course (course_section_id),
    INDEX idx_enrollments_semester (semester_id),
    INDEX idx_enrollments_student_semester (student_id, semester_id),
    INDEX idx_enrollments_status (status)
) ENGINE=InnoDB;

-- Waitlists table
CREATE TABLE IF NOT EXISTS waitlists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_section_id INT NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_section (student_id, course_section_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE,
    INDEX idx_waitlist_student (student_id),
    INDEX idx_waitlist_section (course_section_id)
) ENGINE=InnoDB;

-- Student advisors table
CREATE TABLE IF NOT EXISTS student_advisors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    instructor_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    INDEX idx_advisor_student (student_id),
    INDEX idx_advisor_instructor (instructor_id),
    INDEX idx_advisor_active (is_active)
) ENGINE=InnoDB;

-- Applications table
CREATE TABLE IF NOT EXISTS applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_email VARCHAR(100) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NULL,
    program_id INT NOT NULL,
    wasse_aggregate INT NULL CHECK (wasse_aggregate >= 0 AND wasse_aggregate <= 100),
    user_id INT NULL,
    application_key VARCHAR(64) UNIQUE NOT NULL,
    status ENUM('applied','under_review','offered','accepted','rejected') DEFAULT 'applied',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL,
    offer_notes TEXT NULL,
    decided_reason TEXT NULL,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_application_program (program_id),
    INDEX idx_application_user (user_id),
    INDEX idx_application_status (status),
    INDEX idx_application_email (prospect_email)
) ENGINE=InnoDB;

-- ============================================================================
-- LEVEL 4 TABLES (Depend on Level 3)
-- ============================================================================

-- Grades table (for individual assessments)
CREATE TABLE IF NOT EXISTS grades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enrollment_id INT NOT NULL,
    assessment_type ENUM('quiz', 'exam', 'assignment', 'project', 'participation', 'final') NOT NULL,
    assessment_name VARCHAR(100) NOT NULL,
    score DECIMAL(6,2) CHECK (score >= 0),
    max_score DECIMAL(6,2) CHECK (max_score > 0),
    weight DECIMAL(5,2) NOT NULL CHECK (weight >= 0 AND weight <= 100),
    grade_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
    CHECK (score <= max_score),
    INDEX idx_grades_enrollment (enrollment_id),
    INDEX idx_grades_type (assessment_type),
    INDEX idx_grades_date (grade_date)
) ENGINE=InnoDB;

-- Attendance table
CREATE TABLE IF NOT EXISTS attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enrollment_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
    notes TEXT,
    marked_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES instructors(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_attendance_idx (enrollment_id, attendance_date),
    INDEX idx_attendance_enrollment (enrollment_id),
    INDEX idx_attendance_date (attendance_date),
    INDEX idx_attendance_marker (marked_by),
    INDEX idx_attendance_status (status)
) ENGINE=InnoDB;

-- Fee payments table (legacy)
CREATE TABLE IF NOT EXISTS fee_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL CHECK (amount >= 0),
    payment_date DATE NOT NULL,
    status ENUM('paid', 'pending', 'overdue', 'cancelled') DEFAULT 'pending',
    payment_method VARCHAR(50),
    transaction_id VARCHAR(100),
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    scholarship_amount DECIMAL(10,2) DEFAULT 0.00 CHECK (scholarship_amount >= 0),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    INDEX idx_fee_payments_student (student_id),
    INDEX idx_fee_payments_status (status),
    INDEX idx_fee_payments_semester (semester_id),
    INDEX idx_fee_payments_date (payment_date)
) ENGINE=InnoDB;

-- Billing invoices (modern billing)
CREATE TABLE IF NOT EXISTS billing_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL CHECK (amount >= 0),
    balance DECIMAL(10,2) NOT NULL CHECK (balance >= 0),
    status ENUM('open','paid','void') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    CHECK (balance <= amount),
    INDEX idx_billing_student (student_id),
    INDEX idx_billing_semester (semester_id),
    INDEX idx_billing_status (status)
) ENGINE=InnoDB;

-- Billing payments
CREATE TABLE IF NOT EXISTS billing_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL CHECK (amount > 0),
    method VARCHAR(50) NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    receipt_no VARCHAR(50) UNIQUE,
    notes TEXT,
    FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE,
    INDEX idx_payment_invoice (invoice_id),
    INDEX idx_payment_receipt (receipt_no)
) ENGINE=InnoDB;

-- Documents table
CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    owner_type ENUM('application','student','instructor') NOT NULL,
    owner_id INT NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL CHECK (size_bytes > 0),
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_doc_owner (owner_type, owner_id),
    INDEX idx_doc_type (doc_type),
    INDEX idx_doc_uploader (uploaded_by)
) ENGINE=InnoDB;

-- ============================================================================
-- SECURITY & AUTHENTICATION TABLES
-- ============================================================================

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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_otp_user_purpose (user_id, purpose, expires_at),
    INDEX idx_otp_expires (expires_at)
) ENGINE=InnoDB;

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_token (user_id, token_hash),
    INDEX idx_reset_expires (expires_at)
) ENGINE=InnoDB;

-- ============================================================================
-- SYSTEM TABLES (Notifications, Logs, Email)
-- ============================================================================

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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_sender (sender_user_id),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB;

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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_logs_user (user_id),
    INDEX idx_logs_created (created_at),
    INDEX idx_logs_entity (entity_type, entity_id),
    INDEX idx_logs_action (action)
) ENGINE=InnoDB;

-- Email outbox (simulate email/SMS delivery offline)
CREATE TABLE IF NOT EXISTS email_outbox (
    id INT PRIMARY KEY AUTO_INCREMENT,
    to_user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    template VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_email_user (to_user_id),
    INDEX idx_email_sent (sent_at)
) ENGINE=InnoDB;

-- ============================================================================
-- SEED DATA (Initial Setup)
-- ============================================================================

-- Seed initial programs (idempotent)
INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits)
SELECT 'BSC-CS', 'B.Sc. Computer Science', d.id, 120 FROM departments d WHERE d.dept_code = 'CS'
UNION ALL
SELECT 'BSC-IT', 'B.Sc. Information Technology', d.id, 120 FROM departments d WHERE d.dept_code = 'IT'
UNION ALL
SELECT 'BA-ENG', 'B.A. English', d.id, 120 FROM departments d WHERE d.dept_code = 'ENG';

-- ============================================================================
-- DATABASE OPTIMIZATION NOTES
-- ============================================================================
-- 1. All tables use InnoDB engine for transaction support and foreign key constraints
-- 2. All foreign keys have corresponding indexes for query performance
-- 3. CHECK constraints ensure data integrity (MySQL 8.0.16+)
-- 4. Proper ON DELETE behaviors set for cascading/restricting deletes
-- 5. utf8mb4 character set for full Unicode support (emojis, international characters)
-- 6. All boolean fields standardized to BOOLEAN type
-- 7. All numeric fields have range validation via CHECK constraints
-- 8. Composite unique indexes prevent duplicate combinations
-- 9. Tables ordered by dependency to avoid foreign key creation errors
-- 10. All critical queries have supporting indexes