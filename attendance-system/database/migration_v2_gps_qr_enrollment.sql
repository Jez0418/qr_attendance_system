-- ============================================================
-- MIGRATION v2: GPS Geofencing + Admin QR Activation + Enrollment Requests
-- ============================================================
-- Run this ONCE against an EXISTING qr_attendance_system database
-- (one you already set up from the original schema.sql) to add the
-- new functionality without losing your current data.
--
-- How to run it:
--   phpMyAdmin -> select qr_attendance_system -> SQL tab -> paste
--   this whole file -> Go.
--   (or)  mysql -u root -p qr_attendance_system < migration_v2_gps_qr_enrollment.sql
--
-- If you are doing a FRESH install instead, just import schema.sql —
-- it already contains everything in this file baked in, so you do
-- NOT need to run this migration on a fresh install.
-- ============================================================

USE qr_attendance_system;

-- ------------------------------------------------------------
-- 1. SETTINGS  (new) — configurable system-wide values, editable
--    from Admin > Settings instead of being hardcoded.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('max_gps_accuracy_meters', '100'),
    ('default_allowed_radius_meters', '50'),
    ('qr_token_rotation_seconds', '20');

-- ------------------------------------------------------------
-- 2. LABORATORIES — add geofencing coordinates + radius
-- ------------------------------------------------------------
ALTER TABLE laboratories
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN allowed_radius_meters INT NOT NULL DEFAULT 50 AFTER longitude;

-- ------------------------------------------------------------
-- 3. TEACHER_SUBJECTS — add course/year/capacity so "Available
--    Subjects" and enrollment requests can show slots + restrictions
-- ------------------------------------------------------------
ALTER TABLE teacher_subjects
    ADD COLUMN program_id INT NULL AFTER subject_id,
    ADD COLUMN year_level TINYINT NULL AFTER program_id,
    ADD COLUMN max_students INT NOT NULL DEFAULT 40 AFTER section,
    ADD CONSTRAINT fk_ts_program FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 4. ATTENDANCE_SESSIONS — admin activation support, session end
--    time (auto-expiration), configurable geofence radius, and
--    QR token rotation timestamp (anti-screenshot protection)
-- ------------------------------------------------------------
ALTER TABLE attendance_sessions
    MODIFY COLUMN activated_by INT NOT NULL COMMENT 'teacher_id owning the class (not necessarily who clicked activate)',
    ADD COLUMN created_by_role ENUM('teacher','admin') NOT NULL DEFAULT 'teacher' AFTER activated_by,
    ADD COLUMN created_by_user_id INT NULL AFTER created_by_role,
    ADD COLUMN session_end DATETIME NULL AFTER scheduled_start,
    ADD COLUMN allowed_radius_meters INT NOT NULL DEFAULT 50 AFTER late_threshold_minutes,
    ADD COLUMN qr_token_rotated_at DATETIME NULL AFTER qr_token,
    ADD CONSTRAINT fk_session_creator FOREIGN KEY (created_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL;

-- Backfill session_end for any existing rows so old data doesn't break
-- the new "expired" logic (default: 3 hours after scheduled_start).
UPDATE attendance_sessions
SET session_end = DATE_ADD(scheduled_start, INTERVAL 3 HOUR)
WHERE session_end IS NULL;

-- ------------------------------------------------------------
-- 5. ATTENDANCE_RECORDS — store the GPS evidence used to verify
--    each scan (only what's needed for verification, per spec)
-- ------------------------------------------------------------
ALTER TABLE attendance_records
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER status,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN location_accuracy DECIMAL(7,2) NULL COMMENT 'meters' AFTER longitude,
    ADD COLUMN distance_from_location DECIMAL(8,2) NULL COMMENT 'meters from lab' AFTER location_accuracy;

-- ------------------------------------------------------------
-- 6. ENROLLMENT_REQUESTS  (new) — student-initiated, teacher-approved
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollment_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    remarks VARCHAR(500) NULL COMMENT 'student''s optional reason for requesting',
    rejection_reason VARCHAR(500) NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL COMMENT 'teacher_id who approved/rejected',
    reviewed_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES teachers(teacher_id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_student (student_id),
    INDEX idx_class (teacher_subject_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. Sample geofencing data so the feature is testable out of the box.
--    !! Replace these with your real laboratory coordinates before
--    !! relying on this for actual attendance enforcement.
-- ------------------------------------------------------------
UPDATE laboratories SET latitude = 17.6132000, longitude = 121.7270000, allowed_radius_meters = 50 WHERE lab_code = 'LAB-1';
UPDATE laboratories SET latitude = 17.6135000, longitude = 121.7273000, allowed_radius_meters = 50 WHERE lab_code = 'LAB-2';
UPDATE laboratories SET latitude = 17.6129000, longitude = 121.7266000, allowed_radius_meters = 60 WHERE lab_code = 'LAB-3';
UPDATE laboratories SET latitude = 17.6138000, longitude = 121.7278000, allowed_radius_meters = 50 WHERE lab_code = 'LAB-4';
UPDATE laboratories SET latitude = 17.6141000, longitude = 121.7282000, allowed_radius_meters = 50 WHERE lab_code = 'LAB-5';
UPDATE laboratories SET latitude = 17.6126000, longitude = 121.7262000, allowed_radius_meters = 60 WHERE lab_code = 'LAB-6';
UPDATE laboratories SET latitude = 17.6145000, longitude = 121.7286000, allowed_radius_meters = 75 WHERE lab_code = 'LAB-7';

-- Give the sample classes course/year/capacity so "Available Subjects" has real data to show
UPDATE teacher_subjects SET program_id = 1, year_level = 3, max_students = 40 WHERE teacher_subject_id = 1;
UPDATE teacher_subjects SET program_id = 1, year_level = 3, max_students = 40 WHERE teacher_subject_id = 2;
UPDATE teacher_subjects SET program_id = 2, year_level = 1, max_students = 35 WHERE teacher_subject_id = 3;

-- A 4th sample student left unenrolled, so "Available Subjects" has
-- something to request immediately after migrating.
INSERT IGNORE INTO users (username, password, role, email, status) VALUES
('s2023004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023004@school.edu', 'active');
INSERT IGNORE INTO students (user_id, student_number, full_name, program_id, year_level, contact_number)
SELECT user_id, '2023-0004', 'Mark Villanueva', 2, 1, '09057778888' FROM users WHERE username = 's2023004';

-- Done!
SELECT 'Migration v2 complete: GPS geofencing, admin QR activation, and enrollment requests are now available.' AS status;
