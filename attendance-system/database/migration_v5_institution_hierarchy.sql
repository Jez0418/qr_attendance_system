-- ============================================================
-- MIGRATION v5: Institution/Department hierarchy, explicit Meeting
-- Date per class assignment, and Regular/Irregular student type
-- ============================================================
-- Run this ONCE against an existing qr_attendance_system database
-- that already has migrations v2, v3, and v4 applied.
--
-- What changes:
--   1. New `institutions` table, seeded with MCNP and ISAP.
--   2. New `departments` table (admin-configurable, per institution).
--   3. `programs` gains institution_id + department_id + program_type
--      + duration_years, and is reseeded with the exact MCNP and ISAP
--      program lists (existing generic BSCS/BSIT/BSCpE rows are kept
--      so nothing referencing them breaks, but are re-tagged under ISAP
--      as a reasonable default — reassign in Admin > Programs if needed).
--   4. `teacher_subjects` (class assignments) gains meeting_date,
--      institution_id, department_id — a class assignment now
--      represents one dated meeting, not just a recurring weekday name.
--   5. `students` gains student_type (regular/irregular), institution_id,
--      department_id.
--
-- How to run it:
--   phpMyAdmin -> select qr_attendance_system -> SQL tab -> paste
--   this whole file -> Go.
-- ============================================================

USE qr_attendance_system;

-- ------------------------------------------------------------
-- 1. INSTITUTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS institutions (
    institution_id INT AUTO_INCREMENT PRIMARY KEY,
    institution_code VARCHAR(20) NOT NULL UNIQUE,
    institution_name VARCHAR(150) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO institutions (institution_code, institution_name) VALUES
('MCNP', 'Medical Colleges of Northern Philippines'),
('ISAP', 'International School of Asia and the Pacific');

-- ------------------------------------------------------------
-- 2. DEPARTMENTS (admin-configurable, scoped to an institution)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    department_code VARCHAR(30) NOT NULL,
    department_name VARCHAR(150) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institution_id) REFERENCES institutions(institution_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_dept_per_inst (institution_id, department_code)
) ENGINE=InnoDB;

-- One starter department per institution so programs have somewhere to
-- attach immediately; rename/add more freely in Admin > Departments.
INSERT IGNORE INTO departments (institution_id, department_code, department_name)
SELECT institution_id, 'GEN', 'General / Unassigned Department' FROM institutions;

-- ------------------------------------------------------------
-- 3. PROGRAMS — add hierarchy columns + type/duration
-- ------------------------------------------------------------
ALTER TABLE programs
    ADD COLUMN institution_id INT NULL AFTER program_id,
    ADD COLUMN department_id INT NULL AFTER institution_id,
    ADD COLUMN program_type ENUM('Bachelor''s Degree','Diploma') NOT NULL DEFAULT 'Bachelor''s Degree' AFTER program_name,
    ADD COLUMN duration_years DECIMAL(2,1) NOT NULL DEFAULT 4.0 AFTER program_type,
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER duration_years,
    ADD CONSTRAINT fk_program_institution FOREIGN KEY (institution_id) REFERENCES institutions(institution_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_program_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL;

-- Tag the existing generic programs under ISAP's starter department so
-- nothing that already references them (existing students/assignments)
-- breaks. Reassign properly from Admin > Programs afterwards if desired.
UPDATE programs p
JOIN institutions i ON i.institution_code = 'ISAP'
JOIN departments d ON d.institution_id = i.institution_id AND d.department_code = 'GEN'
SET p.institution_id = i.institution_id, p.department_id = d.department_id
WHERE p.institution_id IS NULL;

-- Seed the exact MCNP program list
INSERT INTO programs (program_code, program_name, program_type, duration_years, institution_id, department_id)
SELECT v.code, v.name, v.ptype, v.dur, i.institution_id, d.department_id
FROM institutions i
JOIN departments d ON d.institution_id = i.institution_id AND d.department_code = 'GEN'
JOIN (
    SELECT 'BSN' code, 'BS in Nursing' name, 'Bachelor''s Degree' ptype, 4.0 dur UNION ALL
    SELECT 'BSMLS', 'BS in Medical Laboratory Science', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSPh', 'BS in Pharmacy', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSRT', 'BS in Radiologic Technology', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSPT', 'BS in Physical Therapy', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'MIDWIFERY', 'Diploma in Midwifery', 'Diploma', 2.0 UNION ALL
    SELECT 'DENTAL_TECH', 'Diploma in Dental Technology', 'Diploma', 2.0 UNION ALL
    SELECT 'PHARMACY_AIDE', 'Diploma in Pharmacy Aide', 'Diploma', 2.0
) v ON i.institution_code = 'MCNP'
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE program_code = v.code);

-- Seed the exact ISAP program list
INSERT INTO programs (program_code, program_name, program_type, duration_years, institution_id, department_id)
SELECT v.code, v.name, v.ptype, v.dur, i.institution_id, d.department_id
FROM institutions i
JOIN departments d ON d.institution_id = i.institution_id AND d.department_code = 'GEN'
JOIN (
    SELECT 'BSCRIM' code, 'BS in Criminology' name, 'Bachelor''s Degree' ptype, 4.0 dur UNION ALL
    SELECT 'BSCA', 'BS in Customs Administration', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSCPE', 'BS in Computer Engineering', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSIT', 'BS in Information Technology', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSA', 'BS in Accountancy', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSSW', 'BS in Social Work', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSPSY', 'BS in Psychology', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSHM', 'BS in Hospitality Management', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSTM', 'BS in Tourism Management', 'Bachelor''s Degree', 4.0 UNION ALL
    SELECT 'BSBA', 'BS in Business Administration', 'Bachelor''s Degree', 4.0
) v ON i.institution_code = 'ISAP'
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE program_code = v.code);

-- ------------------------------------------------------------
-- 4. TEACHER_SUBJECTS (class assignments) — explicit meeting date
--    + institution/department context
-- ------------------------------------------------------------
ALTER TABLE teacher_subjects
    ADD COLUMN institution_id INT NULL AFTER teacher_id,
    ADD COLUMN department_id INT NULL AFTER institution_id,
    ADD COLUMN meeting_date DATE NULL COMMENT 'the specific calendar date this class assignment meets' AFTER schedule_day,
    ADD CONSTRAINT fk_ts_institution FOREIGN KEY (institution_id) REFERENCES institutions(institution_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_ts_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 5. STUDENTS — student type + institution/department
-- ------------------------------------------------------------
ALTER TABLE students
    ADD COLUMN institution_id INT NULL AFTER program_id,
    ADD COLUMN department_id INT NULL AFTER institution_id,
    ADD COLUMN student_type ENUM('regular','irregular') NOT NULL DEFAULT 'regular' AFTER department_id,
    ADD CONSTRAINT fk_student_institution FOREIGN KEY (institution_id) REFERENCES institutions(institution_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_student_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL;

-- Backfill existing students' institution/department from their program
UPDATE students s
JOIN programs p ON p.program_id = s.program_id
SET s.institution_id = p.institution_id, s.department_id = p.department_id
WHERE s.institution_id IS NULL AND p.institution_id IS NOT NULL;

-- ------------------------------------------------------------
-- 6. STUDENTS — section (needed for regular-student eligibility)
-- ------------------------------------------------------------
ALTER TABLE students ADD COLUMN section VARCHAR(50) NULL AFTER year_level;
UPDATE students SET section = NULL WHERE section = '';

-- ------------------------------------------------------------
-- 7. ATTENDANCE_SESSIONS — temporary per-session QR token.
--    Generated fresh at every activation, never reused, expires with
--    the session. (Fixed per-lab codes remain available only when
--    Admin > Settings > QR mode is set to "lab".)
-- ------------------------------------------------------------
ALTER TABLE attendance_sessions
    ADD COLUMN qr_token VARCHAR(64) NULL AFTER session_date,
    ADD UNIQUE KEY uniq_qr_token (qr_token);

-- ------------------------------------------------------------
-- 8. ENROLLMENT_REQUESTS — add CANCELLED status
-- ------------------------------------------------------------
ALTER TABLE enrollment_requests
    MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';

-- ------------------------------------------------------------
-- 9. SETTINGS
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('qr_mode', 'session');

SELECT 'Migration v5 complete: institution/department/program hierarchy, meeting dates, and student types are now available.' AS status;
