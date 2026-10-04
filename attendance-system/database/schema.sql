-- ============================================================
-- QR CODE LABORATORY ATTENDANCE MANAGEMENT SYSTEM
-- Database Schema — v1 base tables
--
-- THIS FILE ALONE IS NOT ENOUGH. After importing it, run these four
-- migration files IN ORDER (phpMyAdmin > SQL tab > paste each, Go) —
-- yes, even on a brand-new install, not just upgrades:
--   1. database/migration_v2_gps_qr_enrollment.sql
--   2. database/migration_v3_admin_enrollment_approval.sql
--   3. database/migration_v4_static_lab_qrcodes.sql
--   4. database/migration_v5_institution_hierarchy.sql
-- Each one is additive (CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD
-- COLUMN) and safe to run exactly once. See README.md for the full
-- setup walkthrough.
--
-- BEFORE RUNNING THIS FILE: create the database yourself first —
--   phpMyAdmin -> "New" (left sidebar) -> name it qr_attendance_system
--   -> Collation: utf8mb4_unicode_ci -> Create.
-- Then select that database in phpMyAdmin and run this file from its
-- SQL tab. This script only DROPs/CREATEs individual TABLEs inside the
-- database you selected — it deliberately never runs DROP DATABASE or
-- CREATE DATABASE, because many hosting panels and managed phpMyAdmin
-- setups (shared hosting, school/lab servers, etc.) disable those two
-- statements for safety even when the account can still freely create
-- and drop tables. If you're on your own local XAMPP install and DROP
-- DATABASE works fine there, you can still just delete the database
-- from phpMyAdmin's UI and recreate it instead — either approach ends
-- up at the same clean state.
--
-- Import this file in phpMyAdmin (with qr_attendance_system selected),
-- or run:
--   mysql -u root -p qr_attendance_system < schema.sql
-- ============================================================

USE qr_attendance_system;

-- Drop any existing tables from a previous install so this script can
-- be re-run cleanly. FK checks are disabled for the drop so table
-- order doesn't matter, then re-enabled before anything is created.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS enrollment_requests;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS teacher_subjects;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS laboratories;
DROP TABLE IF EXISTS teachers;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS programs;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS institutions;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- TABLE: users  (central auth table for Admin / Teacher / Student)
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,          -- bcrypt hashed (PHP password_hash)
    role ENUM('admin','teacher','student') NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: settings  (configurable system-wide values — Admin > Settings)
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: programs (academic programs, e.g. BSCS, BSIT)
-- ------------------------------------------------------------
CREATE TABLE programs (
    program_id INT AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20) NOT NULL UNIQUE,
    program_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: students (profile linked to users)
-- ------------------------------------------------------------
CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_number VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    program_id INT NULL,
    year_level TINYINT NOT NULL DEFAULT 1,
    contact_number VARCHAR(20),
    photo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL,
    INDEX idx_student_number (student_number)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: teachers (profile linked to users)
-- ------------------------------------------------------------
CREATE TABLE teachers (
    teacher_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employee_number VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    department VARCHAR(100),
    contact_number VARCHAR(20),
    photo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: laboratories (7 laboratories) — now with geofencing
-- ------------------------------------------------------------
CREATE TABLE laboratories (
    lab_id INT AUTO_INCREMENT PRIMARY KEY,
    lab_name VARCHAR(100) NOT NULL,
    lab_code VARCHAR(20) NOT NULL UNIQUE,
    location VARCHAR(150),
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    allowed_radius_meters INT NOT NULL DEFAULT 50,
    capacity INT DEFAULT 40,
    status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: subjects
-- ------------------------------------------------------------
CREATE TABLE subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(20) NOT NULL UNIQUE,
    subject_name VARCHAR(150) NOT NULL,
    units DECIMAL(3,1) DEFAULT 3.0,
    status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: teacher_subjects
-- A teacher handling a subject in a lab with a schedule (a "class").
-- Now carries course/year/capacity so enrollment requests and
-- "Available Subjects" browsing have something to filter/display.
-- ------------------------------------------------------------
CREATE TABLE teacher_subjects (
    teacher_subject_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    program_id INT NULL,
    year_level TINYINT NULL,
    lab_id INT NOT NULL,
    section VARCHAR(50) NOT NULL,
    max_students INT NOT NULL DEFAULT 40,
    schedule_day VARCHAR(30) NOT NULL,       -- e.g. 'Monday' or 'Mon/Wed'
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    school_year VARCHAR(20) DEFAULT '2025-2026',
    semester ENUM('1st','2nd','Summer') DEFAULT '1st',
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL,
    FOREIGN KEY (lab_id) REFERENCES laboratories(lab_id) ON DELETE CASCADE,
    INDEX idx_teacher (teacher_id),
    INDEX idx_subject (subject_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: enrollments
-- Students OFFICIALLY enrolled into a specific teacher_subject (class).
-- Rows here are only ever created via direct teacher enrollment or
-- via an APPROVED enrollment_requests row — never created directly
-- by a student.
-- ------------------------------------------------------------
CREATE TABLE enrollments (
    enrollment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('enrolled','dropped') DEFAULT 'enrolled',
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_enrollment (student_id, teacher_subject_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: enrollment_requests
-- Student-initiated request to join a class; a teacher (who owns
-- that class) must approve or reject it. Approval creates the
-- matching `enrollments` row automatically (see application logic).
-- ------------------------------------------------------------
CREATE TABLE enrollment_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    remarks VARCHAR(500) NULL COMMENT 'student''s optional reason for requesting',
    rejection_reason VARCHAR(500) NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL COMMENT 'teacher_id, set only when a teacher reviewed it',
    reviewed_by_role ENUM('teacher','admin') NULL,
    reviewed_by_user_id INT NULL COMMENT 'users.user_id of whoever actually reviewed it',
    reviewed_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES teachers(teacher_id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_student (student_id),
    INDEX idx_class (teacher_subject_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: attendance_sessions
-- Created when EITHER a teacher OR an administrator activates
-- attendance for a class meeting. `activated_by` always stores the
-- CLASS's teacher_id (for backward-compatible "my sessions" queries);
-- `created_by_role`/`created_by_user_id` record who actually clicked
-- Activate, which may be the teacher themself or an administrator.
--
-- Note: there is NO QR token here. Every laboratory has one fixed,
-- non-changing QR code (see qr/qr_helper.php + admin/lab_qrcodes.php).
-- A session just opens a time window during which scans of that
-- room's permanent code get recorded against this class.
-- ------------------------------------------------------------
CREATE TABLE attendance_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_subject_id INT NOT NULL,
    session_date DATE NOT NULL,
    scheduled_start DATETIME NOT NULL,       -- used to compute Late status
    session_end DATETIME NULL,               -- session auto-expires after this
    late_threshold_minutes INT NOT NULL DEFAULT 15,
    allowed_radius_meters INT NOT NULL DEFAULT 50,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    activated_by INT NOT NULL,               -- teacher_id who owns the class
    created_by_role ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
    created_by_user_id INT NULL,             -- users.user_id of whoever clicked Activate
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: attendance_records
-- Stores the GPS evidence used to verify each scan (only what's
-- needed for verification — not a full location history).
-- ------------------------------------------------------------
CREATE TABLE attendance_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    time_in DATETIME NOT NULL,
    status ENUM('Present','Late','Absent') NOT NULL DEFAULT 'Present',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_accuracy DECIMAL(7,2) NULL COMMENT 'meters, from device GPS',
    distance_from_location DECIMAL(8,2) NULL COMMENT 'meters from the laboratory',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_scan (session_id, student_id), -- prevents duplicate scans
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: notifications
-- ------------------------------------------------------------
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE: activity_logs (simple audit trail)
-- ------------------------------------------------------------
CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Configurable system settings (Admin > Settings can change these)
INSERT INTO settings (setting_key, setting_value) VALUES
    ('max_gps_accuracy_meters', '100'),
    ('default_allowed_radius_meters', '50');

INSERT INTO programs (program_code, program_name) VALUES
('BSCS', 'Bachelor of Science in Computer Science'),
('BSIT', 'Bachelor of Science in Information Technology'),
('BSCpE', 'Bachelor of Science in Computer Engineering');

-- 7 Laboratories, with sample GPS coordinates + geofence radius already
-- filled in so the geofencing feature is testable immediately.
-- !! Replace these with your real laboratory coordinates before relying
-- !! on this for actual attendance enforcement (Admin > Laboratories > Edit).
INSERT INTO laboratories (lab_name, lab_code, location, latitude, longitude, allowed_radius_meters, capacity) VALUES
('Computer Laboratory 1', 'LAB-1', 'Building A, 2nd Floor', 17.6132000, 121.7270000, 50, 40),
('Computer Laboratory 2', 'LAB-2', 'Building A, 2nd Floor', 17.6135000, 121.7273000, 50, 40),
('Computer Laboratory 3', 'LAB-3', 'Building A, 3rd Floor', 17.6129000, 121.7266000, 60, 35),
('Networking Laboratory', 'LAB-4', 'Building B, 1st Floor', 17.6138000, 121.7278000, 50, 30),
('Multimedia Laboratory', 'LAB-5', 'Building B, 2nd Floor', 17.6141000, 121.7282000, 50, 30),
('Hardware Laboratory', 'LAB-6', 'Building B, 2nd Floor', 17.6126000, 121.7262000, 60, 25),
('Research & Innovation Laboratory', 'LAB-7', 'Building C, 1st Floor', 17.6145000, 121.7286000, 75, 20);

-- Subjects
INSERT INTO subjects (subject_code, subject_name, units) VALUES
('IT101', 'Introduction to Computing', 3.0),
('IT201', 'Data Structures and Algorithms', 3.0),
('IT301', 'Web Systems and Technologies', 3.0),
('IT302', 'Database Management Systems', 3.0),
('IT401', 'System Integration and Architecture', 3.0);

-- ------------------------------------------------------------
-- Demo accounts. These are seeded with a placeholder password hash.
-- IMPORTANT: after importing, open database/reset_passwords_to_id.php
-- in your browser once — it sets every account's real password to its
-- own ID number (Admin ID / Employee No. / Student No.), which is the
-- convention this app's login screen expects. See README.md.
-- ------------------------------------------------------------
INSERT INTO users (username, password, role, email, status) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'admin@school.edu', 'active');

INSERT INTO users (username, password, role, email, status) VALUES
('tcruz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'tcruz@school.edu', 'active'),
('jsantos', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'jsantos@school.edu', 'active');

INSERT INTO teachers (user_id, employee_number, full_name, department, contact_number) VALUES
(2, 'EMP-001', 'Teresa Cruz', 'College of Computing', '09171234567'),
(3, 'EMP-002', 'Juan Santos', 'College of Computing', '09179876543');

-- Note: s2023004 is intentionally left with NO enrollments below, so you
-- can immediately test "Available Subjects -> Request Enrollment" with it.
INSERT INTO users (username, password, role, email, status) VALUES
('s2023001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023001@school.edu', 'active'),
('s2023002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023002@school.edu', 'active'),
('s2023003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023003@school.edu', 'active'),
('s2023004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023004@school.edu', 'active');

INSERT INTO students (user_id, student_number, full_name, program_id, year_level, contact_number) VALUES
(4, '2023-0001', 'Maria Dela Cruz', 1, 3, '09051112222'),
(5, '2023-0002', 'Jose Rizal Jr.', 1, 3, '09053334444'),
(6, '2023-0003', 'Ana Lopez', 2, 2, '09055556666'),
(7, '2023-0004', 'Mark Villanueva', 2, 1, '09057778888');

-- Teacher-Subject assignments (classes) — now with course/year/capacity
INSERT INTO teacher_subjects (teacher_id, subject_id, program_id, year_level, lab_id, section, max_students, schedule_day, start_time, end_time) VALUES
(1, 3, 1, 3, 1, 'BSCS-3A', 40, 'Monday', '08:00:00', '11:00:00'),
(1, 4, 1, 3, 2, 'BSCS-3A', 40, 'Wednesday', '13:00:00', '16:00:00'),
(2, 1, 2, 1, 3, 'BSIT-1A', 35, 'Tuesday', '09:00:00', '12:00:00');

-- Enrollments (official) — s2023004 stays unenrolled on purpose
INSERT INTO enrollments (student_id, teacher_subject_id) VALUES
(1, 1), (1, 2), (2, 1), (2, 2), (3, 3);

-- A sample PENDING enrollment request, so the teacher's "Enrollment
-- Requests" page has something to approve/reject on first login.
INSERT INTO enrollment_requests (student_id, teacher_subject_id, status, remarks) VALUES
(4, 3, 'pending', 'This subject is part of my current semester schedule.');
