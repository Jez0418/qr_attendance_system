-- ============================================================
-- QR LABORATORY ATTENDANCE SYSTEM — PostgreSQL / Supabase schema
-- ------------------------------------------------------------
-- This is the FINAL state of the app's database (the original
-- schema.sql plus migrations v2-v5 already merged in), so you run
-- ONLY this one file on a fresh Supabase project:
--   Supabase dashboard -> SQL Editor -> New query -> paste -> Run.
-- It is safe to re-run: it drops and recreates the app's tables.
-- ============================================================

DROP TABLE IF EXISTS php_sessions, activity_logs, notifications, attendance_records,
    attendance_sessions, enrollment_requests, enrollments, schedule_exceptions, class_schedules, teacher_subjects, subjects,
    laboratories, teachers, students, programs, departments, institutions, settings, users CASCADE;

CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(10) NOT NULL CHECK (role IN ('admin','teacher','student')),
    email VARCHAR(100) NOT NULL UNIQUE,
    status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_users_role ON users (role);

CREATE TABLE settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE institutions (
    institution_id SERIAL PRIMARY KEY,
    institution_code VARCHAR(20) NOT NULL UNIQUE,
    institution_name VARCHAR(150) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE departments (
    department_id SERIAL PRIMARY KEY,
    institution_id INT NOT NULL REFERENCES institutions(institution_id) ON DELETE CASCADE,
    department_code VARCHAR(30) NOT NULL,
    department_name VARCHAR(150) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (institution_id, department_code)
);

CREATE TABLE programs (
    program_id SERIAL PRIMARY KEY,
    institution_id INT NULL REFERENCES institutions(institution_id) ON DELETE SET NULL,
    department_id INT NULL REFERENCES departments(department_id) ON DELETE SET NULL,
    program_code VARCHAR(20) NOT NULL UNIQUE,
    program_name VARCHAR(100) NOT NULL,
    program_type VARCHAR(20) NOT NULL DEFAULT 'Bachelor''s Degree' CHECK (program_type IN ('Bachelor''s Degree','Diploma')),
    duration_years DECIMAL(2,1) NOT NULL DEFAULT 4.0,
    status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive'))
);

CREATE TABLE students (
    student_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    student_number VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    program_id INT NULL REFERENCES programs(program_id) ON DELETE SET NULL,
    institution_id INT NULL REFERENCES institutions(institution_id) ON DELETE SET NULL,
    department_id INT NULL REFERENCES departments(department_id) ON DELETE SET NULL,
    student_type VARCHAR(10) NOT NULL DEFAULT 'regular' CHECK (student_type IN ('regular','irregular')),
    year_level SMALLINT NOT NULL DEFAULT 1,
    section VARCHAR(50) NULL,
    contact_number VARCHAR(20),
    photo TEXT DEFAULT NULL,  -- data URI (see supabase_profile_photos.sql)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE teachers (
    teacher_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    employee_number VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    department VARCHAR(100),
    contact_number VARCHAR(20),
    photo TEXT DEFAULT NULL,  -- data URI (see supabase_profile_photos.sql)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE laboratories (
    lab_id SERIAL PRIMARY KEY,
    lab_name VARCHAR(100) NOT NULL,
    lab_code VARCHAR(20) NOT NULL UNIQUE,
    location VARCHAR(150),
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    allowed_radius_meters INT NOT NULL DEFAULT 50,
    capacity INT DEFAULT 40,
    status VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active','inactive'))
);

CREATE TABLE subjects (
    subject_id SERIAL PRIMARY KEY,
    subject_code VARCHAR(20) NOT NULL UNIQUE,
    subject_name VARCHAR(150) NOT NULL,
    units DECIMAL(3,1) DEFAULT 3.0,
    status VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active','inactive'))
);

CREATE TABLE teacher_subjects (
    teacher_subject_id SERIAL PRIMARY KEY,
    teacher_id INT NOT NULL REFERENCES teachers(teacher_id) ON DELETE CASCADE,
    institution_id INT NULL REFERENCES institutions(institution_id) ON DELETE SET NULL,
    department_id INT NULL REFERENCES departments(department_id) ON DELETE SET NULL,
    subject_id INT NOT NULL REFERENCES subjects(subject_id) ON DELETE CASCADE,
    program_id INT NULL REFERENCES programs(program_id) ON DELETE SET NULL,
    year_level SMALLINT NULL,
    lab_id INT NOT NULL REFERENCES laboratories(lab_id) ON DELETE CASCADE,
    section VARCHAR(50) NOT NULL,
    max_students INT NOT NULL DEFAULT 40,
    schedule_day VARCHAR(30) NULL,   -- display summary only; see class_schedules
    meeting_date DATE NULL,          -- deprecated (replaced by class_schedules)
    start_time TIME NULL,            -- display summary only (first slot)
    end_time TIME NULL,              -- display summary only (first slot)
    school_year VARCHAR(20) DEFAULT '2025-2026',
    semester VARCHAR(10) DEFAULT '1st' CHECK (semester IN ('1st','2nd','Summer')),
    status VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ts_teacher ON teacher_subjects (teacher_id);
CREATE INDEX idx_ts_subject ON teacher_subjects (subject_id);

-- Weekly rules of a class assignment. day_of_week: 1 = Monday ... 7 = Sunday (ISO).
-- Meetings are generated from these + schedule_exceptions by includes/schedule.php.
CREATE TABLE class_schedules (
    schedule_id SERIAL PRIMARY KEY,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    day_of_week SMALLINT NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    effective_start_date DATE NULL,
    effective_end_date DATE NULL,
    CHECK (end_time > start_time),
    CONSTRAINT class_schedules_effective_range_chk
        CHECK (effective_start_date IS NULL OR effective_end_date IS NULL OR effective_end_date >= effective_start_date)
);
CREATE INDEX idx_cs_class ON class_schedules (teacher_subject_id);
CREATE INDEX idx_cs_day ON class_schedules (day_of_week);
CREATE INDEX idx_cs_effective ON class_schedules (effective_start_date, effective_end_date);

-- Changes to one meeting date of a class: CANCELLED, or RESCHEDULED (NULL new_* = unchanged).
CREATE TABLE schedule_exceptions (
    exception_id SERIAL PRIMARY KEY,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    original_date DATE NOT NULL,
    exception_type VARCHAR(12) NOT NULL CHECK (exception_type IN ('CANCELLED','RESCHEDULED')),
    new_date DATE NULL,
    new_start_time TIME NULL,
    new_end_time TIME NULL,
    new_lab_id INT NULL REFERENCES laboratories(lab_id) ON DELETE SET NULL,
    new_teacher_id INT NULL REFERENCES teachers(teacher_id) ON DELETE SET NULL,
    reason VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT schedule_exceptions_class_date_key UNIQUE (teacher_subject_id, original_date),
    CONSTRAINT schedule_exceptions_times_chk CHECK ((new_start_time IS NULL) = (new_end_time IS NULL)
        AND (new_start_time IS NULL OR new_end_time > new_start_time))
);
CREATE INDEX idx_se_original_date ON schedule_exceptions (original_date);
CREATE INDEX idx_se_new_date ON schedule_exceptions (new_date);
CREATE INDEX idx_se_new_teacher ON schedule_exceptions (new_teacher_id);
CREATE INDEX idx_se_new_lab ON schedule_exceptions (new_lab_id);

CREATE TABLE enrollments (
    enrollment_id SERIAL PRIMARY KEY,
    student_id INT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(10) DEFAULT 'enrolled' CHECK (status IN ('enrolled','dropped')),
    UNIQUE (student_id, teacher_subject_id)
);

CREATE TABLE enrollment_requests (
    request_id SERIAL PRIMARY KEY,
    student_id INT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    status VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
    remarks VARCHAR(500) NULL,
    rejection_reason VARCHAR(500) NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL REFERENCES teachers(teacher_id) ON DELETE SET NULL,
    reviewed_by_role VARCHAR(10) NULL CHECK (reviewed_by_role IN ('teacher','admin')),
    reviewed_by_user_id INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    reviewed_at TIMESTAMP NULL
);
CREATE INDEX idx_er_status ON enrollment_requests (status);
CREATE INDEX idx_er_student ON enrollment_requests (student_id);
CREATE INDEX idx_er_class ON enrollment_requests (teacher_subject_id);

CREATE TABLE attendance_sessions (
    session_id SERIAL PRIMARY KEY,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    session_date DATE NOT NULL,
    qr_token VARCHAR(64) NULL UNIQUE,
    scheduled_start TIMESTAMP NOT NULL,
    session_end TIMESTAMP NULL,
    late_threshold_minutes INT NOT NULL DEFAULT 15,
    allowed_radius_meters INT NOT NULL DEFAULT 50,
    is_active SMALLINT NOT NULL DEFAULT 1,
    activated_by INT NOT NULL,
    created_by_role VARCHAR(10) NOT NULL DEFAULT 'teacher' CHECK (created_by_role IN ('teacher','admin')),
    created_by_user_id INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP NULL DEFAULT NULL
);
CREATE INDEX idx_sessions_active ON attendance_sessions (is_active);

CREATE TABLE attendance_records (
    record_id SERIAL PRIMARY KEY,
    session_id INT NOT NULL REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    student_id INT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    time_in TIMESTAMP NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Present' CHECK (status IN ('Present','Late','Absent')),
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_accuracy DECIMAL(7,2) NULL,
    distance_from_location DECIMAL(8,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (session_id, student_id)
);
CREATE INDEX idx_ar_status ON attendance_records (status);

CREATE TABLE notifications (
    notification_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_notif_user_read ON notifications (user_id, is_read);

CREATE TABLE activity_logs (
    log_id SERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Server-side PHP sessions (used on Vercel; see includes/session_db.php)
CREATE TABLE php_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data TEXT NOT NULL,
    updated_at INT NOT NULL
);

-- Supabase exposes tables through its REST API; this app talks to the
-- database directly over PDO, so lock the REST API out of every table.
DO $$ DECLARE t record; BEGIN
  FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
    EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t.tablename);
  END LOOP;
END $$;

-- ============================================================
-- SEED DATA
-- ============================================================
INSERT INTO settings (setting_key, setting_value) VALUES
    ('max_gps_accuracy_meters', '100'),
    ('default_allowed_radius_meters', '50'),
    ('late_grace_minutes', '15'),
    ('qr_mode', 'session');

INSERT INTO institutions (institution_code, institution_name) VALUES
    ('MCNP', 'Medical Colleges of Northern Philippines'),
    ('ISAP', 'International School of Asia and the Pacific');

INSERT INTO departments (institution_id, department_code, department_name)
SELECT institution_id, 'GEN', 'General / Unassigned Department' FROM institutions;

INSERT INTO programs (program_code, program_name, program_type, duration_years, institution_id, department_id)
SELECT v.code, v.name, v.ptype, v.dur, i.institution_id, d.department_id
FROM institutions i
JOIN departments d ON d.institution_id = i.institution_id AND d.department_code = 'GEN'
JOIN (VALUES
    ('MCNP', 'BSN', 'BS in Nursing', 'Bachelor''s Degree', 4.0),
    ('MCNP', 'BSMLS', 'BS in Medical Laboratory Science', 'Bachelor''s Degree', 4.0),
    ('MCNP', 'BSPh', 'BS in Pharmacy', 'Bachelor''s Degree', 4.0),
    ('MCNP', 'BSRT', 'BS in Radiologic Technology', 'Bachelor''s Degree', 4.0),
    ('MCNP', 'BSPT', 'BS in Physical Therapy', 'Bachelor''s Degree', 4.0),
    ('MCNP', 'MIDWIFERY', 'Diploma in Midwifery', 'Diploma', 2.0),
    ('MCNP', 'DENTAL_TECH', 'Diploma in Dental Technology', 'Diploma', 2.0),
    ('MCNP', 'PHARMACY_AIDE', 'Diploma in Pharmacy Aide', 'Diploma', 2.0),
    ('ISAP', 'BSCRIM', 'BS in Criminology', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSCA', 'BS in Customs Administration', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSCPE', 'BS in Computer Engineering', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSIT', 'BS in Information Technology', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSA', 'BS in Accountancy', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSSW', 'BS in Social Work', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSPSY', 'BS in Psychology', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSHM', 'BS in Hospitality Management', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSTM', 'BS in Tourism Management', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSBA', 'BS in Business Administration', 'Bachelor''s Degree', 4.0),
    ('ISAP', 'BSCS', 'Bachelor of Science in Computer Science', 'Bachelor''s Degree', 4.0)
) AS v(inst, code, name, ptype, dur) ON v.inst = i.institution_code;

-- !! Replace these sample coordinates with your real lab locations
-- !! (Admin > Laboratories > Edit) before relying on geofencing.
INSERT INTO laboratories (lab_name, lab_code, location, latitude, longitude, allowed_radius_meters, capacity) VALUES
('Computer Laboratory 1', 'LAB-1', 'Building A, 2nd Floor', 17.6132000, 121.7270000, 50, 40),
('Computer Laboratory 2', 'LAB-2', 'Building A, 2nd Floor', 17.6135000, 121.7273000, 50, 40),
('Computer Laboratory 3', 'LAB-3', 'Building A, 3rd Floor', 17.6129000, 121.7266000, 60, 35),
('Networking Laboratory', 'LAB-4', 'Building B, 1st Floor', 17.6138000, 121.7278000, 50, 30),
('Multimedia Laboratory', 'LAB-5', 'Building B, 2nd Floor', 17.6141000, 121.7282000, 50, 30),
('Hardware Laboratory', 'LAB-6', 'Building B, 2nd Floor', 17.6126000, 121.7262000, 60, 25),
('Research & Innovation Laboratory', 'LAB-7', 'Building C, 1st Floor', 17.6145000, 121.7286000, 75, 20);

INSERT INTO subjects (subject_code, subject_name, units) VALUES
('IT101', 'Introduction to Computing', 3.0),
('IT201', 'Data Structures and Algorithms', 3.0),
('IT301', 'Web Systems and Technologies', 3.0),
('IT302', 'Database Management Systems', 3.0),
('IT401', 'System Integration and Architecture', 3.0);

-- NOTE: rows seeded here use the database's clock (UTC on Supabase); everything
-- the app writes uses Asia/Manila (set per connection in includes/db_connect.php).

-- Demo accounts. Placeholder password hash ("password"); after importing,
-- set real passwords (the app's convention is: password = the account's ID
-- number). The hash below is for the literal word "password".
INSERT INTO users (username, password, role, email) VALUES
('admin',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',   'admin@school.edu'),
('tcruz',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'tcruz@school.edu'),
('jsantos',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'jsantos@school.edu'),
('s2023001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023001@school.edu'),
('s2023002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023002@school.edu'),
('s2023003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023003@school.edu'),
('s2023004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 's2023004@school.edu');

INSERT INTO teachers (user_id, employee_number, full_name, department, contact_number) VALUES
((SELECT user_id FROM users WHERE username='tcruz'),   'EMP-001', 'Teresa Cruz', 'College of Computing', '09171234567'),
((SELECT user_id FROM users WHERE username='jsantos'), 'EMP-002', 'Juan Santos', 'College of Computing', '09179876543');

-- Rows are inserted one by one so student_id 1..4 match the enrollments below.
INSERT INTO students (user_id, student_number, full_name, program_id, institution_id, department_id, year_level, section, contact_number)
SELECT u.user_id, '2023-0001', 'Maria Dela Cruz', p.program_id, p.institution_id, p.department_id, 3, 'BSCS-3A', '09051112222'
FROM users u, programs p WHERE u.username='s2023001' AND p.program_code='BSCS';
INSERT INTO students (user_id, student_number, full_name, program_id, institution_id, department_id, year_level, section, contact_number)
SELECT u.user_id, '2023-0002', 'Jose Rizal Jr.', p.program_id, p.institution_id, p.department_id, 3, 'BSCS-3A', '09053334444'
FROM users u, programs p WHERE u.username='s2023002' AND p.program_code='BSCS';
INSERT INTO students (user_id, student_number, full_name, program_id, institution_id, department_id, year_level, section, contact_number)
SELECT u.user_id, '2023-0003', 'Ana Lopez', p.program_id, p.institution_id, p.department_id, 2, 'BSIT-2A', '09055556666'
FROM users u, programs p WHERE u.username='s2023003' AND p.program_code='BSIT';
INSERT INTO students (user_id, student_number, full_name, program_id, institution_id, department_id, year_level, section, contact_number)
SELECT u.user_id, '2023-0004', 'Mark Villanueva', p.program_id, p.institution_id, p.department_id, 1, 'BSIT-1A', '09057778888'
FROM users u, programs p WHERE u.username='s2023004' AND p.program_code='BSIT';

-- Sample classes (meeting_date left NULL — set one in Admin > Assignments)
INSERT INTO teacher_subjects (teacher_id, subject_id, program_id, year_level, lab_id, section, max_students, schedule_day, start_time, end_time) VALUES
(1, 3, (SELECT program_id FROM programs WHERE program_code='BSCS'), 3, 1, 'BSCS-3A', 40, 'Monday', '08:00:00', '11:00:00'),
(1, 4, (SELECT program_id FROM programs WHERE program_code='BSCS'), 3, 2, 'BSCS-3A', 40, 'Wednesday', '13:00:00', '16:00:00'),
(2, 1, (SELECT program_id FROM programs WHERE program_code='BSIT'), 1, 3, 'BSIT-1A', 35, 'Tuesday', '09:00:00', '12:00:00');

INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time) VALUES
(1, 1, '08:00:00', '11:00:00'),
(2, 3, '13:00:00', '16:00:00'),
(3, 2, '09:00:00', '12:00:00');

INSERT INTO enrollments (student_id, teacher_subject_id) VALUES (1,1),(1,2),(2,1),(2,2),(3,3);

INSERT INTO enrollment_requests (student_id, teacher_subject_id, status, remarks)
VALUES (4, 3, 'pending', 'This subject is part of my current semester schedule.');
