-- Indexes on the foreign keys the app filters by (flagged by the Supabase performance advisor).
-- Only the columns pages actually query: the other unindexed FKs are small lookup tables or audit
-- columns nothing filters on. Already in supabase_schema.sql for a fresh install.
-- Safe to run more than once. Run in the Supabase SQL Editor.

-- Student history, "Attendance by Class", teacher watchlist, overrides
CREATE INDEX IF NOT EXISTS idx_ar_student ON attendance_records (student_id);

-- Finding a meeting's session (every QR page load and scan) and teacher history
CREATE INDEX IF NOT EXISTS idx_sessions_class_date ON attendance_sessions (teacher_subject_id, session_date);
-- Absence marker's date-range query
CREATE INDEX IF NOT EXISTS idx_sessions_date ON attendance_sessions (session_date);

-- Class rosters and absence marking (student lookups use UNIQUE (student_id, teacher_subject_id))
CREATE INDEX IF NOT EXISTS idx_enrollments_class ON enrollments (teacher_subject_id);

-- Login and profile lookups
CREATE INDEX IF NOT EXISTS idx_students_user ON students (user_id);
CREATE INDEX IF NOT EXISTS idx_teachers_user ON teachers (user_id);
